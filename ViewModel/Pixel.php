<?php
/**
 * Page-level events (ViewContent, ViewCategory, Search). They live in the FPC
 * copy of the page, so they are browser-only - no visitor context to send
 * server-side at render time.
 */

declare(strict_types=1);

namespace Kkkonrad\FacebookPixel\ViewModel;

use Kkkonrad\FacebookPixel\Model\Tracker;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\App\Request\Http as Request;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

class Pixel implements ArgumentInterface
{
    public function __construct(
        private readonly Tracker $tracker,
        private readonly Request $request,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly CookieHelper $cookieHelper,
    ) {
    }

    /**
     * @param bool $withQueue true on uncacheable pages only. Checkout drops Alpine (Fastcheckout), so
     *                        Hyvä never loads customer sections there - the queue is printed inline instead.
     */
    public function getConfig(bool $withQueue): array
    {
        return [
            'pixelId' => $this->tracker->config('pixel_id'),
            // The helper returns the raw config string; "0" is truthy in JS.
            'restricted' => (bool)$this->cookieHelper->isCookieRestrictionModeEnabled(),
            'cookieName' => CookieHelper::IS_USER_ALLOWED_SAVE_COOKIE,
            'debug' => $this->tracker->isTestMode(),
            'websiteId' => (int)$this->storeManager->getWebsite()->getId(),
            'page' => array_merge($this->getPageEvents(), $withQueue ? $this->tracker->popQueue() : []),
        ];
    }

    private function getPageEvents(): array
    {
        $currency = $this->storeManager->getStore()->getCurrentCurrencyCode();

        switch ($this->request->getFullActionName()) {
            case 'catalog_product_view':
                $product = $this->registry->registry('current_product');
                if (!$product) {
                    return [];
                }
                $price = $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
                return [['name' => 'ViewContent', 'data' => [
                    'content_ids' => [$product->getSku()],
                    'content_name' => $product->getName(),
                    // Configurable/grouped parents map to item_group_id in the Meta catalog.
                    'content_type' => $product->getTypeInstance()->isComposite($product) ? 'product_group' : 'product',
                    'value' => round((float)$price, 2),
                    'currency' => $currency,
                ]]];

            case 'catalog_category_view':
                $category = $this->registry->registry('current_category');
                return $category ? [['name' => 'ViewCategory', 'custom' => true,
                    'data' => ['content_category' => $category->getName()]]] : [];

            case 'catalogsearch_result_index':
                return [['name' => 'Search', 'data' => [
                    'search_string' => (string)$this->request->getParam('q'),
                ]]];
        }
        return [];
    }
}

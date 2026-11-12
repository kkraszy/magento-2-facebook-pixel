<?php
/**
 * One observer for every server-side event; the event name picks the payload.
 */

declare(strict_types=1);

namespace Kkkonrad\FacebookPixel\Observer;

use Kkkonrad\FacebookPixel\Model\Tracker;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;

class Track implements ObserverInterface
{
    public function __construct(
        private readonly Tracker $tracker,
        private readonly CheckoutSession $checkoutSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->tracker->isActive()) {
            return;
        }
        $event = $observer->getEvent();

        switch ($event->getName()) {
            case 'checkout_cart_product_add_after':
                $item = $event->getData('quote_item');
                $qty = max(1, (float)$this->request->getParam('qty', 1));
                $price = (float)$item->getProduct()->getFinalPrice();
                $this->tracker->track('AddToCart', [
                    'content_ids' => [$item->getSku()],
                    'content_name' => $item->getName(),
                    'content_type' => 'product',
                    'contents' => [['id' => $item->getSku(), 'quantity' => $qty, 'item_price' => $price]],
                    'value' => round($price * $qty, 2),
                    'currency' => $this->currency(),
                ]);
                break;

            case 'controller_action_predispatch_checkout_index_index':
                $quote = $this->checkoutSession->getQuote();
                if (!$quote->getItemsCount()) {
                    return;
                }
                $items = $quote->getAllVisibleItems();
                $this->tracker->track('InitiateCheckout', [
                    'content_ids' => array_map(fn($i) => $i->getSku(), $items),
                    'content_type' => 'product',
                    'contents' => array_map(fn($i) => ['id' => $i->getSku(), 'quantity' => (float)$i->getQty(),
                        'item_price' => (float)$i->getPrice()], $items),
                    'num_items' => (int)$quote->getItemsQty(),
                    'value' => round((float)$quote->getGrandTotal(), 2),
                    'currency' => $quote->getQuoteCurrencyCode() ?: $this->currency(),
                ]);
                break;

            case 'checkout_onepage_controller_success_action':
                /** @var Order $order */
                $order = $event->getData('order');
                if (!$order) {
                    return;
                }
                $items = $order->getAllVisibleItems();
                $address = $order->getBillingAddress();
                $this->tracker->track('Purchase', [
                    'content_ids' => array_map(fn($i) => $i->getSku(), $items),
                    'content_type' => 'product',
                    'contents' => array_map(fn($i) => ['id' => $i->getSku(), 'quantity' => (float)$i->getQtyOrdered(),
                        'item_price' => (float)$i->getPrice()], $items),
                    'num_items' => (int)$order->getTotalQtyOrdered(),
                    'value' => round((float)$order->getGrandTotal(), 2),
                    'currency' => $order->getOrderCurrencyCode(),
                    'order_id' => $order->getIncrementId(),
                ], array_filter([
                    'em' => $order->getCustomerEmail(),
                    'fn' => $address?->getFirstname(),
                    'ln' => $address?->getLastname(),
                    'ph' => $address?->getTelephone(),
                    'ct' => $address?->getCity(),
                    'zp' => $address?->getPostcode(),
                    'country' => $address?->getCountryId(),
                    'external_id' => $order->getCustomerId(),
                ]));
                break;

            case 'customer_register_success':
                $customer = $event->getData('customer');
                $this->tracker->track('CompleteRegistration', ['status' => true], [
                    'em' => $customer->getEmail(),
                    'fn' => $customer->getFirstname(),
                    'ln' => $customer->getLastname(),
                    'external_id' => $customer->getId(),
                ]);
                break;

            case 'wishlist_add_product':
                $product = $event->getData('product');
                $this->tracker->track('AddToWishlist', [
                    'content_ids' => [$product->getSku()],
                    'content_name' => $product->getName(),
                    'content_type' => 'product',
                    'value' => round((float)$product->getFinalPrice(), 2),
                    'currency' => $this->currency(),
                ]);
                break;
        }
    }

    private function currency(): string
    {
        return $this->storeManager->getStore()->getCurrentCurrencyCode();
    }
}

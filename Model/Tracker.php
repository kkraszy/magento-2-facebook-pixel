<?php
/**
 * Every server-side event goes through track(): it is sent to the Conversions
 * API and queued in the session for the browser pixel, both under one event_id
 * so Meta deduplicates the pair. The queue reaches the browser through the
 * `fbpixel` customer-data section; deleting `mage-cache-sessid` makes Hyvä
 * fetch sections on the very next page (or the AJAX reload), even on FPC hits.
 */

declare(strict_types=1);

namespace Kkkonrad\FacebookPixel\Model;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as Request;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Tracker
{
    // Unversioned Graph URL resolves to the oldest live version, so it never expires.
    private const ENDPOINT = 'https://graph.facebook.com/%s/events';
    private const QUEUE_KEY = 'fb_pixel_queue';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly EncryptorInterface $encryptor,
        private readonly Session $session,
        private readonly CookieHelper $cookieHelper,
        private readonly CookieManagerInterface $cookies,
        private readonly CookieMetadataFactory $cookieMetadata,
        private readonly Request $request,
        private readonly RemoteAddress $remoteAddress,
        private readonly UrlInterface $url,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function config(string $field): string
    {
        return (string)$this->config->getValue('kkkonrad_fbpixel/general/' . $field, ScopeInterface::SCOPE_STORE);
    }

    public function isActive(): bool
    {
        return $this->config('enabled') && $this->config('pixel_id')
            && !$this->cookieHelper->isUserNotAllowSaveCookie();
    }

    /**
     * @param array $user raw (unhashed) Meta user_data keys: em, ph, fn, ln, ct, zp, country, external_id
     */
    public function track(string $name, array $custom, array $user = []): void
    {
        if (!$this->isActive()) {
            return;
        }
        $id = $name . '.' . bin2hex(random_bytes(8));

        try {
            $capi = $this->sendServerEvent($name, $id, $custom, $user);
        } catch (\Throwable $e) {
            // Tracking must never break add-to-cart or checkout.
            $this->logger->warning('Meta CAPI: ' . $e->getMessage());
            $capi = 'error: ' . $e->getMessage();
        }

        $entry = ['name' => $name, 'id' => $id, 'data' => $custom];
        if ($this->isTestMode()) {
            $entry['capi'] = $capi; // shown in the browser console only in test mode
        }
        $queue = (array)$this->session->getData(self::QUEUE_KEY);
        $queue[] = $entry;
        $this->session->setData(self::QUEUE_KEY, array_slice($queue, -20));
        $this->invalidateSections();
    }

    /**
     * A test event code routes CAPI events to Events Manager → Test events; the storefront also logs to the console.
     */
    public function isTestMode(): bool
    {
        return $this->config('test_event_code') !== '';
    }

    public function popQueue(): array
    {
        return (array)$this->session->getData(self::QUEUE_KEY, true);
    }

    private function invalidateSections(): void
    {
        if ($this->cookies->getCookie('mage-cache-sessid')) {
            $this->cookies->deleteCookie('mage-cache-sessid', $this->cookieMetadata->createCookieMetadata()->setPath('/'));
        }
    }

    // ponytail: synchronous call with a 2 s timeout; move to a message queue if Meta latency shows up in add-to-cart.
    /**
     * @return string outcome, for the test-mode console log
     */
    private function sendServerEvent(string $name, string $id, array $custom, array $user): string
    {
        $token = $this->encryptor->decrypt($this->config('access_token'));
        if ($token === '') {
            return 'skipped: no access token';
        }
        if ($this->session->isLoggedIn()) {
            $customer = $this->session->getCustomer();
            $user += ['em' => $customer->getEmail(), 'fn' => $customer->getFirstname(),
                'ln' => $customer->getLastname(), 'external_id' => $customer->getId()];
        }

        $event = [
            'event_name' => $name,
            'event_time' => time(),
            'event_id' => $id,
            'action_source' => 'website',
            // AJAX/POST requests report the page the visitor was on, not the controller URL.
            'event_source_url' => $this->request->isPost()
                ? ($this->request->getServer('HTTP_REFERER') ?: $this->url->getCurrentUrl())
                : $this->url->getCurrentUrl(),
            'user_data' => self::hashUserData($user) + array_filter([
                'client_ip_address' => $this->remoteAddress->getRemoteAddress(),
                'client_user_agent' => (string)$this->request->getHeader('User-Agent'),
                'fbp' => $this->cookies->getCookie('_fbp'),
                'fbc' => $this->cookies->getCookie('_fbc'),
            ]),
            'custom_data' => $custom,
        ];

        $curl = $this->curlFactory->create();
        $curl->setTimeout(2);
        $curl->post(
            sprintf(self::ENDPOINT, rawurlencode($this->config('pixel_id'))),
            array_filter([
                'data' => json_encode([$event], JSON_THROW_ON_ERROR),
                'access_token' => $token,
                'test_event_code' => $this->config('test_event_code'),
            ])
        );
        if ($curl->getStatus() !== 200) {
            $this->logger->warning(sprintf('Meta CAPI %s: HTTP %d %s', $name, $curl->getStatus(), $curl->getBody()));
        }
        return sprintf('HTTP %d %s', $curl->getStatus(), mb_substr($curl->getBody(), 0, 300));
    }

    /**
     * Normalises per Meta's customer-information rules, then SHA-256.
     */
    public static function hashUserData(array $user): array
    {
        $out = [];
        foreach ($user as $key => $value) {
            $value = mb_strtolower(trim((string)$value));
            $value = match ($key) {
                'ph' => preg_replace('/\D/', '', $value),
                'ct', 'zp', 'fn', 'ln' => preg_replace('/\s+/', '', $value),
                default => $value,
            };
            if ($value !== '') {
                $out[$key] = hash('sha256', $value);
            }
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Configuration helper — provides typed access to all module settings.
 */
class Config extends AbstractHelper
{
    private const XML_PREFIX = 'ecom360_analytics/';

    private EncryptorInterface $encryptor;
    protected LoggerInterface $logger;

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
        $this->logger = $logger;
    }

    /* ──────────────────── Generic getter ──────────────────── */

    public function getValue(string $path, ?int $storeId = null): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PREFIX . $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isFlag(string $path, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /* ──────────────────── Connection ──────────────────── */

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('connection/enabled', $storeId);
    }

    public function getServerUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->getValue('connection/server_url', $storeId), '/');
    }

    public function getApiKey(?int $storeId = null): string
    {
        $encrypted = (string) $this->getValue('connection/api_key', $storeId);
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function getSecretKey(?int $storeId = null): string
    {
        $encrypted = (string) $this->getValue('connection/secret_key', $storeId);
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function getCollectEndpoint(?int $storeId = null): string
    {
        return $this->getServerUrl($storeId) . '/api/v1/collect';
    }

    public function getBatchEndpoint(?int $storeId = null): string
    {
        return $this->getServerUrl($storeId) . '/api/v1/collect/batch';
    }

    public function getIngestEndpoint(?int $storeId = null): string
    {
        return $this->getServerUrl($storeId) . '/api/v1/analytics/ingest';
    }

    /* ──────────────────── Sync ──────────────────── */

    public function isSyncProducts(?int $storeId = null): bool
    {
        return $this->isFlag('sync/sync_products', $storeId);
    }

    public function isSyncCategories(?int $storeId = null): bool
    {
        return $this->isFlag('sync/sync_categories', $storeId);
    }

    public function isSyncOrders(?int $storeId = null): bool
    {
        return $this->isFlag('sync/sync_orders', $storeId);
    }

    public function isSyncCustomers(?int $storeId = null): bool
    {
        return $this->isFlag('sync/sync_customers', $storeId);
    }

    public function isSyncSalesData(?int $storeId = null): bool
    {
        return $this->isFlag('sync/sync_sales_data', $storeId);
    }

    public function getSyncInterval(?int $storeId = null): string
    {
        return (string) $this->getValue('sync/sync_interval', $storeId);
    }

    public function getSyncBatchSize(?int $storeId = null): int
    {
        return (int) ($this->getValue('sync/batch_size', $storeId) ?: 50);
    }

    /* ──────────────────── Tracking toggles ──────────────────── */

    public function isTrackPageViews(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_page_views', $storeId);
    }

    public function isTrackProductViews(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_product_views', $storeId);
    }

    public function isTrackCart(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_cart', $storeId);
    }

    public function isTrackCheckout(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_checkout', $storeId);
    }

    public function isTrackPurchases(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_purchases', $storeId);
    }

    public function isTrackSearch(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_search', $storeId);
    }

    public function isTrackCustomerLogin(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_customer_login', $storeId);
    }

    public function isTrackCustomerRegister(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_customer_register', $storeId);
    }

    public function isTrackReviews(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_reviews', $storeId);
    }

    public function isTrackWishlist(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_wishlist', $storeId);
    }

    public function isTrackCompare(?int $storeId = null): bool
    {
        return $this->isFlag('tracking/track_compare', $storeId);
    }

    /* ──────────────────── Behavior ──────────────────── */

    public function isBatchEvents(?int $storeId = null): bool
    {
        return $this->isFlag('behavior/batch_events', $storeId);
    }

    public function getBatchSize(?int $storeId = null): int
    {
        return (int) ($this->getValue('behavior/batch_size', $storeId) ?: 10);
    }

    public function getFlushInterval(?int $storeId = null): int
    {
        return (int) ($this->getValue('behavior/flush_interval', $storeId) ?: 5000);
    }

    public function getSessionTimeout(?int $storeId = null): int
    {
        return (int) ($this->getValue('behavior/session_timeout', $storeId) ?: 30);
    }

    public function isFingerprint(?int $storeId = null): bool
    {
        return $this->isFlag('behavior/enable_fingerprint', $storeId);
    }

    public function isCaptureUtm(?int $storeId = null): bool
    {
        return $this->isFlag('behavior/capture_utm', $storeId);
    }

    public function isCaptureReferrer(?int $storeId = null): bool
    {
        return $this->isFlag('behavior/capture_referrer', $storeId);
    }

    public function isExcludeAdminUsers(?int $storeId = null): bool
    {
        return $this->isFlag('behavior/exclude_admin_users', $storeId);
    }

    /* ──────────────────── Abandoned Cart ──────────────────── */

    public function isAbandonedCartEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('abandoned_cart/enabled', $storeId);
    }

    public function getAbandonedCartTimeout(?int $storeId = null): int
    {
        return (int) ($this->getValue('abandoned_cart/timeout_minutes', $storeId) ?: 60);
    }

    public function isAbandonedCartEmail(?int $storeId = null): bool
    {
        return $this->isFlag('abandoned_cart/send_email', $storeId);
    }

    public function getAbandonedCartEmailDelay(?int $storeId = null): int
    {
        return (int) ($this->getValue('abandoned_cart/email_delay_minutes', $storeId) ?: 30);
    }

    public function getAbandonedCartEmailTemplate(?int $storeId = null): string
    {
        return (string) $this->getValue('abandoned_cart/email_template', $storeId);
    }

    public function isAbandonedCartCoupon(?int $storeId = null): bool
    {
        return $this->isFlag('abandoned_cart/include_coupon', $storeId);
    }

    public function getAbandonedCartCouponRuleId(?int $storeId = null): int
    {
        return (int) $this->getValue('abandoned_cart/coupon_rule_id', $storeId);
    }

    /* ──────────────────── Push ──────────────────── */

    public function isPushEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('push/enabled', $storeId);
    }

    public function getPushProvider(?int $storeId = null): string
    {
        return (string) ($this->getValue('push/provider', $storeId) ?: 'firebase');
    }

    public function getFirebaseApiKey(?int $storeId = null): string
    {
        $enc = (string) $this->getValue('push/firebase_api_key', $storeId);
        return $enc ? $this->encryptor->decrypt($enc) : '';
    }

    public function getFirebaseSenderId(?int $storeId = null): string
    {
        return (string) $this->getValue('push/firebase_sender_id', $storeId);
    }

    public function getOneSignalAppId(?int $storeId = null): string
    {
        return (string) $this->getValue('push/onesignal_app_id', $storeId);
    }

    public function getOneSignalApiKey(?int $storeId = null): string
    {
        $enc = (string) $this->getValue('push/onesignal_api_key', $storeId);
        return $enc ? $this->encryptor->decrypt($enc) : '';
    }

    public function getPushPromptDelay(?int $storeId = null): int
    {
        return (int) ($this->getValue('push/prompt_delay', $storeId) ?: 10);
    }

    /* ──────────────────── Popup ──────────────────── */

    public function isPopupEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('popup/enabled', $storeId);
    }

    public function getPopupTrigger(?int $storeId = null): string
    {
        return (string) ($this->getValue('popup/trigger', $storeId) ?: 'time_delay');
    }

    public function getPopupDelay(?int $storeId = null): int
    {
        return (int) ($this->getValue('popup/delay_seconds', $storeId) ?: 15);
    }

    public function getPopupScrollPercent(?int $storeId = null): int
    {
        return (int) ($this->getValue('popup/scroll_percent', $storeId) ?: 50);
    }

    public function getPopupTitle(?int $storeId = null): string
    {
        return (string) $this->getValue('popup/title', $storeId);
    }

    public function getPopupDescription(?int $storeId = null): string
    {
        return (string) $this->getValue('popup/description', $storeId);
    }

    public function isPopupCollectName(?int $storeId = null): bool
    {
        return $this->isFlag('popup/collect_name', $storeId);
    }

    public function isPopupCollectEmail(?int $storeId = null): bool
    {
        return $this->isFlag('popup/collect_email', $storeId);
    }

    public function isPopupCollectPhone(?int $storeId = null): bool
    {
        return $this->isFlag('popup/collect_phone', $storeId);
    }

    public function isPopupCollectDob(?int $storeId = null): bool
    {
        return $this->isFlag('popup/collect_dob', $storeId);
    }

    public function getPopupShowOn(?int $storeId = null): string
    {
        return (string) ($this->getValue('popup/show_on', $storeId) ?: 'all_pages');
    }

    public function getPopupFrequency(?int $storeId = null): string
    {
        return (string) ($this->getValue('popup/show_frequency', $storeId) ?: 'once_per_session');
    }

    /* ──────────────────── Build JS config ──────────────────── */

    /* ──────────────────── Advanced Features ──────────────────── */

    public function isExitIntentEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('features/exit_intent_enabled', $storeId);
    }

    public function isRageClickEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('features/rage_click_enabled', $storeId);
    }

    public function isFreeShippingBarEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('features/free_shipping_bar_enabled', $storeId);
    }

    public function getFreeShippingThreshold(?int $storeId = null): float
    {
        return (float) ($this->getValue('features/free_shipping_threshold', $storeId) ?: 50);
    }

    public function getFreeShippingCurrency(?int $storeId = null): string
    {
        return (string) ($this->getValue('features/free_shipping_currency', $storeId) ?: 'USD');
    }

    public function isInterventionsEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('features/interventions_enabled', $storeId);
    }

    public function getInterventionsPollInterval(?int $storeId = null): int
    {
        return (int) ($this->getValue('features/interventions_poll_interval', $storeId) ?: 15);
    }

    public function isChatbotEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('features/chatbot_enabled', $storeId);
    }

    public function getChatbotPosition(?int $storeId = null): string
    {
        return (string) ($this->getValue('features/chatbot_position', $storeId) ?: 'bottom-right');
    }

    public function getChatbotGreeting(?int $storeId = null): string
    {
        return (string) ($this->getValue('features/chatbot_greeting', $storeId) ?: 'Hi! How can I help you today?');
    }

    public function isAiSearchEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('features/ai_search_enabled', $storeId);
    }

    public function isAiSearchVisualEnabled(?int $storeId = null): bool
    {
        return $this->isFlag('features/ai_search_visual_enabled', $storeId);
    }

    public function isSyncInventory(?int $storeId = null): bool
    {
        return $this->isFlag('features/sync_inventory', $storeId);
    }

    /* ──────────────────── Build JS config (full) ──────────────────── */

    /**
     * Build the complete JS tracker configuration array.
     */
    public function getJsConfig(?int $storeId = null): array
    {
        return [
            'config' => [
                'endpoint'         => $this->getServerUrl($storeId),
                'apiKey'           => $this->getApiKey($storeId),
                'batchEvents'      => $this->isBatchEvents($storeId),
                'batchSize'        => $this->getBatchSize($storeId),
                'flushInterval'    => $this->getFlushInterval($storeId),
                'sessionTimeout'   => $this->getSessionTimeout($storeId),
                'captureUtm'       => $this->isCaptureUtm($storeId),
                'captureReferrer'  => $this->isCaptureReferrer($storeId),
                'enableFingerprint' => $this->isFingerprint($storeId),
            ],
            'events' => [
                'pageViews'   => $this->isTrackPageViews($storeId),
                'products'    => $this->isTrackProductViews($storeId),
                'cart'        => $this->isTrackCart($storeId),
                'checkout'    => $this->isTrackCheckout($storeId),
                'purchases'   => $this->isTrackPurchases($storeId),
                'search'      => $this->isTrackSearch($storeId),
                'login'       => $this->isTrackCustomerLogin($storeId),
                'register'    => $this->isTrackCustomerRegister($storeId),
                'reviews'     => $this->isTrackReviews($storeId),
                'wishlist'    => $this->isTrackWishlist($storeId),
                'compare'     => $this->isTrackCompare($storeId),
            ],
            'push' => [
                'enabled'     => $this->isPushEnabled($storeId),
                'provider'    => $this->getPushProvider($storeId),
                'senderId'    => $this->getFirebaseSenderId($storeId),
                'appId'       => $this->getOneSignalAppId($storeId),
                'promptDelay' => $this->getPushPromptDelay($storeId),
            ],
            'popup' => [
                'enabled'       => $this->isPopupEnabled($storeId),
                'trigger'       => $this->getPopupTrigger($storeId),
                'delay'         => $this->getPopupDelay($storeId),
                'scrollPercent' => $this->getPopupScrollPercent($storeId),
                'title'         => $this->getPopupTitle($storeId),
                'description'   => $this->getPopupDescription($storeId),
                'collectName'   => $this->isPopupCollectName($storeId),
                'collectEmail'  => $this->isPopupCollectEmail($storeId),
                'collectPhone'  => $this->isPopupCollectPhone($storeId),
                'collectDob'    => $this->isPopupCollectDob($storeId),
                'showOn'        => $this->getPopupShowOn($storeId),
                'frequency'     => $this->getPopupFrequency($storeId),
            ],
            'features' => [
                'exitIntent'           => $this->isExitIntentEnabled($storeId),
                'rageClick'            => $this->isRageClickEnabled($storeId),
                'freeShippingBar'      => $this->isFreeShippingBarEnabled($storeId),
                'freeShippingThreshold' => $this->getFreeShippingThreshold($storeId),
                'freeShippingCurrency' => $this->getFreeShippingCurrency($storeId),
                'interventions'        => $this->isInterventionsEnabled($storeId),
                'interventionsPollSec' => $this->getInterventionsPollInterval($storeId),
                'chatbot'              => $this->isChatbotEnabled($storeId),
                'chatbotPosition'      => $this->getChatbotPosition($storeId),
                'chatbotGreeting'      => $this->getChatbotGreeting($storeId),
                'aiSearch'             => $this->isAiSearchEnabled($storeId),
                'aiSearchVisual'       => $this->isAiSearchVisualEnabled($storeId),
            ],
        ];
    }
}

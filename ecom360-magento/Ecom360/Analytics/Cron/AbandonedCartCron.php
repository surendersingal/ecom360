<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Cron;

use Ecom360\Analytics\Helper\ApiClient;
use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\AbandonedCart;
use Ecom360\Analytics\Model\AbandonedCartFactory;
use Ecom360\Analytics\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Ecom360\Analytics\Model\ResourceModel\AbandonedCart\CollectionFactory;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes abandoned carts — marks carts as abandoned, sends recovery emails,
 * and syncs abandoned cart data to the Ecom360 platform.
 */
class AbandonedCartCron
{
    private Config $config;
    private ApiClient $apiClient;
    private CollectionFactory $collectionFactory;
    private AbandonedCartResource $abandonedCartResource;
    private TransportBuilder $transportBuilder;
    private StoreManagerInterface $storeManager;
    private CouponFactory $couponFactory;
    private RuleFactory $ruleFactory;
    private Json $json;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        ApiClient $apiClient,
        CollectionFactory $collectionFactory,
        AbandonedCartResource $abandonedCartResource,
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        CouponFactory $couponFactory,
        RuleFactory $ruleFactory,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->collectionFactory = $collectionFactory;
        $this->abandonedCartResource = $abandonedCartResource;
        $this->transportBuilder = $transportBuilder;
        $this->storeManager = $storeManager;
        $this->couponFactory = $couponFactory;
        $this->ruleFactory = $ruleFactory;
        $this->json = $json;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isAbandonedCartEnabled()) {
            return;
        }

        $this->logger->info('Ecom360: Processing abandoned carts');

        $this->markAbandonedCarts();
        $this->sendRecoveryEmails();
        $this->syncAbandonedCarts();
    }

    /**
     * Mark active carts as abandoned if they exceed the timeout.
     */
    private function markAbandonedCarts(): void
    {
        $timeout = $this->config->getAbandonedCartTimeout();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$timeout} minutes"));

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', AbandonedCart::STATUS_ACTIVE);
        $collection->addFieldToFilter('last_activity_at', ['lt' => $cutoff]);
        $collection->addFieldToFilter('grand_total', ['gt' => 0]);

        foreach ($collection as $cart) {
            $cart->setData('status', AbandonedCart::STATUS_ABANDONED);
            $cart->setData('abandoned_at', date('Y-m-d H:i:s'));
            $this->abandonedCartResource->save($cart);
        }

        $this->logger->info(sprintf('Ecom360: Marked %d carts as abandoned', $collection->getSize()));
    }

    /**
     * Send recovery emails for abandoned carts.
     */
    private function sendRecoveryEmails(): void
    {
        if (!$this->config->isAbandonedCartEmail()) {
            return;
        }

        $emailDelay = $this->config->getAbandonedCartEmailDelay();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$emailDelay} minutes"));

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', AbandonedCart::STATUS_ABANDONED);
        $collection->addFieldToFilter('email_sent', 0);
        $collection->addFieldToFilter('abandoned_at', ['lt' => $cutoff]);
        $collection->addFieldToFilter('customer_email', ['notnull' => true]);
        $collection->addFieldToFilter('customer_email', ['neq' => '']);

        foreach ($collection as $cart) {
            try {
                $recoveryToken = bin2hex(random_bytes(32));
                $cart->setData('recovery_token', $recoveryToken);

                // Generate coupon if configured
                $couponCode = null;
                if ($this->config->isAbandonedCartCoupon()) {
                    $couponCode = $this->generateCoupon($cart);
                    $cart->setData('coupon_code', $couponCode);
                }

                // Send recovery email
                $store = $this->storeManager->getStore($cart->getData('store_id'));
                $items = [];
                try {
                    $items = $this->json->unserialize($cart->getData('items_json') ?? '[]');
                } catch (\Exception $e) {
                    // ignore
                }

                $templateId = $this->config->getAbandonedCartEmailTemplate()
                    ?: 'ecom360_abandoned_cart_recovery';

                $transport = $this->transportBuilder
                    ->setTemplateIdentifier($templateId)
                    ->setTemplateOptions([
                        'area'  => \Magento\Framework\App\Area::AREA_FRONTEND,
                        'store' => $store->getId(),
                    ])
                    ->setTemplateVars([
                        'customer_name'  => $cart->getData('customer_name') ?: 'Valued Customer',
                        'items'          => $items,
                        'cart_total'     => number_format((float) $cart->getData('grand_total'), 2),
                        'recovery_url'   => $store->getBaseUrl() . 'ecom360/cart/recover/token/' . $recoveryToken,
                        'coupon_code'    => $couponCode,
                        'store_name'     => $store->getName(),
                    ])
                    ->setFromByScope('general', $store->getId())
                    ->addTo($cart->getData('customer_email'), $cart->getData('customer_name'))
                    ->getTransport();

                $transport->sendMessage();

                $cart->setData('email_sent', 1);
                $cart->setData('email_sent_at', date('Y-m-d H:i:s'));
                $cart->setData('status', AbandonedCart::STATUS_EMAIL_SENT);
                $this->abandonedCartResource->save($cart);

                $this->logger->info('Ecom360: Recovery email sent to ' . $cart->getData('customer_email'));
            } catch (\Exception $e) {
                $this->logger->error('Ecom360: Failed to send recovery email: ' . $e->getMessage());
            }
        }
    }

    /**
     * Sync abandoned cart data to Ecom360 platform.
     */
    private function syncAbandonedCarts(): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('synced_to_ecom360', 0);
        $collection->addFieldToFilter('status', ['in' => [
            AbandonedCart::STATUS_ABANDONED,
            AbandonedCart::STATUS_EMAIL_SENT,
            AbandonedCart::STATUS_CONVERTED,
            AbandonedCart::STATUS_RECOVERED,
        ]]);
        $collection->setPageSize(50);

        $batch = [];
        foreach ($collection as $cart) {
            $items = [];
            try {
                $items = $this->json->unserialize($cart->getData('items_json') ?? '[]');
            } catch (\Exception $e) {
                // ignore
            }

            $batch[] = [
                'quote_id'        => (int) $cart->getData('quote_id'),
                'customer_email'  => $cart->getData('customer_email'),
                'customer_name'   => $cart->getData('customer_name'),
                'customer_id'     => $cart->getData('customer_id'),
                'grand_total'     => (float) $cart->getData('grand_total'),
                'items_count'     => (int) $cart->getData('items_count'),
                'items'           => $items,
                'status'          => $cart->getData('status'),
                'email_sent'      => (bool) $cart->getData('email_sent'),
                'abandoned_at'    => $cart->getData('abandoned_at'),
                'last_activity_at' => $cart->getData('last_activity_at'),
            ];
        }

        if (!empty($batch)) {
            $result = $this->apiClient->syncData('/api/v1/sync/abandoned-carts', [
                'abandoned_carts' => $batch,
                'platform'        => 'magento2',
                'store_id'        => 0,
            ]);

            if ($result['success']) {
                foreach ($collection as $cart) {
                    $cart->setData('synced_to_ecom360', 1);
                    $this->abandonedCartResource->save($cart);
                }
                $this->logger->info(sprintf('Ecom360: Synced %d abandoned carts', count($batch)));
            }
        }
    }

    /**
     * Generate a unique coupon code from a cart price rule.
     */
    private function generateCoupon($cart): ?string
    {
        $ruleId = $this->config->getAbandonedCartCouponRuleId();
        if (!$ruleId) {
            return null;
        }

        try {
            $rule = $this->ruleFactory->create()->load($ruleId);
            if (!$rule->getId()) {
                return null;
            }

            $code = 'ECM360-' . strtoupper(bin2hex(random_bytes(4)));

            $coupon = $this->couponFactory->create();
            $coupon->setRuleId($ruleId);
            $coupon->setCode($code);
            $coupon->setUsageLimit(1);
            $coupon->setUsagePerCustomer(1);
            $coupon->setType(\Magento\SalesRule\Api\Data\CouponInterface::TYPE_GENERATED);
            $coupon->setExpirationDate(date('Y-m-d', strtotime('+7 days')));
            $coupon->save();

            return $code;
        } catch (\Exception $e) {
            $this->logger->error('Ecom360: Failed to generate coupon: ' . $e->getMessage());
            return null;
        }
    }
}

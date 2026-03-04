<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Block;

use Ecom360\Analytics\Helper\Config;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Frontend block that injects tracker JS config into every page.
 *
 * ★ FPC-safe: Customer-specific data is NOT embedded in server HTML.
 *   The JS tracker reads customer identity from Magento's private-content
 *   customer-data section (localStorage, loaded via AJAX after FPC hit).
 *
 * ★ ZERO PHP observers on frontend — ALL tracking events captured client-side
 *   via localStorage event buffer + async sendBeacon flush.
 */
class Tracker extends Template
{
    private Config $config;
    private Registry $registry;
    private CategoryRepositoryInterface $categoryRepository;
    private CheckoutSession $checkoutSession;
    private Json $json;

    public function __construct(
        Context $context,
        Config $config,
        Registry $registry,
        CategoryRepositoryInterface $categoryRepository,
        CheckoutSession $checkoutSession,
        Json $json,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->registry = $registry;
        $this->categoryRepository = $categoryRepository;
        $this->checkoutSession = $checkoutSession;
        $this->json = $json;
    }

    /**
     * Should we render the tracker?
     */
    public function isEnabled(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        if (empty($this->config->getApiKey()) || empty($this->config->getServerUrl())) {
            return false;
        }

        return true;
    }

    /**
     * Should admin users be excluded? (checked client-side via cookie)
     */
    public function isExcludeAdminUsers(): bool
    {
        return $this->config->isExcludeAdminUsers();
    }

    /**
     * Get full tracker configuration as FLAT JSON matching the JS tracker's expectations.
     *
     * ★ No customer PII is included — safe for FPC caching.
     * ★ On the checkout success page (not FPC-cached), order data is injected.
     */
    public function getTrackerConfigJson(): string
    {
        $config = [
            'api_key'         => $this->config->getApiKey(),
            'server_url'      => $this->config->getServerUrl(),
            'session_timeout' => $this->config->getSessionTimeout(),
            'tracking'        => [
                'page_views'     => $this->config->isTrackPageViews(),
                'product_views'  => $this->config->isTrackProductViews(),
                'cart'           => $this->config->isTrackCart(),
                'checkout'       => $this->config->isTrackCheckout(),
                'purchases'      => $this->config->isTrackPurchases(),
                'search'         => $this->config->isTrackSearch(),
                'login'          => $this->config->isTrackCustomerLogin(),
                'register'       => $this->config->isTrackCustomerRegister(),
                'reviews'        => $this->config->isTrackReviews(),
                'wishlist'       => $this->config->isTrackWishlist(),
                'batch_events'   => $this->config->isBatchEvents(),
                'batch_size'     => $this->config->getBatchSize(),
                'flush_interval' => $this->config->getFlushInterval(),
                'utm'            => $this->config->isCaptureUtm(),
                'referrer'       => $this->config->isCaptureReferrer(),
                'fingerprint'    => $this->config->isFingerprint(),
            ],
            'abandoned_cart' => [
                'enabled' => $this->config->isAbandonedCartEnabled(),
            ],
            'page' => $this->getPageData(),
        ];

        return $this->json->serialize($config);
    }

    /**
     * Build page context data for the tracker.
     * ★ Only page-level (public) data — no customer identity.
     * ★ On checkout success (session-gated, not FPC-cached), includes order details.
     */
    private function getPageData(): array
    {
        $data = [
            'url'   => $this->getUrl('*/*/*', ['_current' => true, '_use_rewrite' => true]),
            'title' => $this->getLayout()->getBlock('page.main.title')
                ? $this->getLayout()->getBlock('page.main.title')->getPageTitle()
                : '',
            'type'  => 'page',
        ];

        $fullActionName = $this->getRequest()->getFullActionName();

        switch ($fullActionName) {
            case 'cms_index_index':
                $data['type'] = 'homepage';
                break;

            case 'catalog_product_view':
                $data['type'] = 'product';
                $product = $this->registry->registry('current_product');
                if ($product) {
                    $data['product'] = [
                        'id'       => (string) $product->getId(),
                        'name'     => $product->getName(),
                        'price'    => (float) $product->getFinalPrice(),
                        'sku'      => $product->getSku(),
                        'category' => $this->getProductCategoryName($product),
                    ];
                }
                break;

            case 'catalog_category_view':
                $data['type'] = 'category';
                $category = $this->registry->registry('current_category');
                if ($category) {
                    $data['category'] = $category->getName();
                    $data['category_id'] = (string) $category->getId();
                }
                break;

            case 'checkout_cart_index':
                $data['type'] = 'cart';
                break;

            case 'checkout_index_index':
            case 'checkout_onepage_index':
                $data['type'] = 'checkout';
                break;

            case 'checkout_onepage_success':
                $data['type'] = 'order_confirmation';
                // ★ Success page is session-gated (not FPC-cached), safe to inject order data
                $data['order'] = $this->getOrderDataForSuccessPage();
                break;

            case 'customer_account_index':
            case 'customer_account_login':
            case 'customer_account_create':
                $data['type'] = 'account';
                break;

            case 'catalogsearch_result_index':
                $data['type'] = 'search';
                $data['search_query'] = $this->getRequest()->getParam('q', '');
                break;

            case 'wishlist_index_index':
                $data['type'] = 'wishlist';
                break;
        }

        return $data;
    }

    /**
     * Extract order data on the checkout success page.
     * This page is session-gated and never served from FPC.
     */
    private function getOrderDataForSuccessPage(): ?array
    {
        try {
            $order = $this->checkoutSession->getLastRealOrder();
            if (!$order || !$order->getIncrementId()) {
                return null;
            }

            $items = [];
            foreach ($order->getAllVisibleItems() as $item) {
                $items[] = [
                    'product_id' => (string) $item->getProductId(),
                    'sku'        => $item->getSku(),
                    'name'       => $item->getName(),
                    'qty'        => (int) $item->getQtyOrdered(),
                    'price'      => (float) $item->getPrice(),
                    'row_total'  => (float) $item->getRowTotal(),
                    'discount'   => (float) $item->getDiscountAmount(),
                ];
            }

            return [
                'order_id'        => (string) $order->getIncrementId(),
                'total'           => (float) $order->getGrandTotal(),
                'subtotal'        => (float) $order->getSubtotal(),
                'tax'             => (float) $order->getTaxAmount(),
                'shipping'        => (float) $order->getShippingAmount(),
                'discount'        => (float) $order->getDiscountAmount(),
                'payment_method'  => $order->getPayment() ? $order->getPayment()->getMethod() : '',
                'shipping_method' => $order->getShippingMethod() ?: '',
                'currency'        => $order->getOrderCurrencyCode(),
                'item_count'      => (int) $order->getTotalQtyOrdered(),
                'items'           => $items,
                'coupons'         => $order->getCouponCode() ? [$order->getCouponCode()] : [],
                'is_guest'        => (bool) $order->getCustomerIsGuest(),
                'customer_email'  => $order->getCustomerEmail() ?: '',
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getProductCategoryName($product): string
    {
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return 'Uncategorized';
        }

        try {
            $category = $this->categoryRepository->get($categoryIds[0]);
            return $category->getName();
        } catch (\Exception $e) {
            return 'Uncategorized';
        }
    }
}

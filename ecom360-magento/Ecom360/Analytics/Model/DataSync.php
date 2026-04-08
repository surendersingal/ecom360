<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model;

use Ecom360\Analytics\Helper\ApiClient;
use Ecom360\Analytics\Helper\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Data sync service — handles bulk synchronization of products, categories,
 * orders, customers, and sales data to the Ecom360 platform.
 */
class DataSync
{
    private Config $config;
    private ApiClient $apiClient;
    private ProductCollectionFactory $productCollectionFactory;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private OrderCollectionFactory $orderCollectionFactory;
    private CustomerCollectionFactory $customerCollectionFactory;
    private ProductRepositoryInterface $productRepository;
    private CategoryRepositoryInterface $categoryRepository;
    private OrderRepositoryInterface $orderRepository;
    private CustomerRepositoryInterface $customerRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private SyncLogFactory $syncLogFactory;
    private ResourceModel\SyncLog $syncLogResource;
    private StoreManagerInterface $storeManager;
    private EavConfig $eavConfig;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        ApiClient $apiClient,
        ProductCollectionFactory $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        OrderCollectionFactory $orderCollectionFactory,
        CustomerCollectionFactory $customerCollectionFactory,
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        OrderRepositoryInterface $orderRepository,
        CustomerRepositoryInterface $customerRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SyncLogFactory $syncLogFactory,
        ResourceModel\SyncLog $syncLogResource,
        StoreManagerInterface $storeManager,
        EavConfig $eavConfig,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->orderRepository = $orderRepository;
        $this->customerRepository = $customerRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->syncLogFactory = $syncLogFactory;
        $this->syncLogResource = $syncLogResource;
        $this->storeManager = $storeManager;
        $this->eavConfig = $eavConfig;
        $this->logger = $logger;
    }

    /* ══════════════════════════ Products ══════════════════════════════ */

    public function syncProducts(
        ?int $storeId = null,
        ?\Closure $progressCallback = null,
        ?int $batchSizeOverride = null,
        int $delaySec = 0,
        ?int $limit = null,
    ): array
    {
        if (!$this->config->isEnabled($storeId) || !$this->config->isSyncProducts($storeId)) {
            return ['synced' => 0, 'failed' => 0, 'message' => 'Product sync disabled'];
        }

        $log = $this->createLog(SyncLog::ENTITY_TYPE_PRODUCT, $storeId);
        $batchSize = $batchSizeOverride ?? $this->config->getSyncBatchSize($storeId);
        $brandAttrCode = $this->config->getBrandAttribute($storeId);
        $synced = 0;
        $failed = 0;

        try {
            $collection = $this->productCollectionFactory->create();
            $selectAttrs = ['name', 'sku', 'price', 'special_price', 'status', 'visibility',
                'description', 'short_description', 'image', 'weight', 'url_key', 'meta_title', 'meta_description'];

            // Dynamically include the configured brand attribute
            if ($brandAttrCode && !in_array($brandAttrCode, $selectAttrs, true)) {
                $selectAttrs[] = $brandAttrCode;
            }

            $collection->addAttributeToSelect($selectAttrs);
            $collection->addStoreFilter($storeId ?? 0);
            $effectiveBatch = $limit ? min($batchSize, $limit) : $batchSize;
            $collection->setPageSize($effectiveBatch);

            $lastPage = $collection->getLastPageNumber();
            if ($limit) {
                $maxPages = (int) ceil($limit / $effectiveBatch);
                $lastPage = min($lastPage, $maxPages);
            }

            for ($page = 1; $page <= $lastPage; $page++) {
                $collection->setCurPage($page);
                $batch = [];

                // ── Batch-load all category names for this page in ONE query ──
                $allCategoryIds = [];
                foreach ($collection as $product) {
                    foreach ($product->getCategoryIds() as $catId) {
                        $allCategoryIds[(int) $catId] = true;
                    }
                }
                $categoryNameMap = [];
                if (!empty($allCategoryIds)) {
                    $catCollection = $this->categoryCollectionFactory->create();
                    $catCollection->addAttributeToSelect('name')
                        ->addFieldToFilter('entity_id', ['in' => array_keys($allCategoryIds)]);
                    foreach ($catCollection as $cat) {
                        $categoryNameMap[(int) $cat->getId()] = $cat->getName();
                    }
                }
                $collection->rewind();
                // ─────────────────────────────────────────────────────────────

                foreach ($collection as $product) {
                    $categoryIds = $product->getCategoryIds();
                    $categoryNames = [];
                    foreach ($categoryIds as $catId) {
                        if (isset($categoryNameMap[(int) $catId])) {
                            $categoryNames[] = $categoryNameMap[(int) $catId];
                        }
                    }

                    $batch[] = [
                        'id'                => (string) $product->getId(),
                        'sku'               => $product->getSku(),
                        'name'              => $product->getName(),
                        'price'             => (float) $product->getPrice(),
                        'special_price'     => $product->getSpecialPrice() ? (float) $product->getSpecialPrice() : null,
                        'status'            => $product->getStatus() == 1 ? 'enabled' : 'disabled',
                        'visibility'        => (int) $product->getVisibility(),
                        'type'              => $product->getTypeId(),
                        'weight'            => $product->getWeight() ? (float) $product->getWeight() : null,
                        'url_key'           => $product->getUrlKey(),
                        'description'       => mb_substr((string) $product->getDescription(), 0, 500),
                        'short_description' => mb_substr((string) $product->getShortDescription(), 0, 300),
                        'categories'        => $categoryNames,
                        'category_ids'      => $categoryIds,
                        'image_url'         => $product->getImage() ? $product->getMediaConfig()->getMediaUrl($product->getImage()) : null,
                        'brand'             => $this->resolveBrandValue($product, $brandAttrCode, $storeId),
                        'created_at'        => $product->getCreatedAt(),
                        'updated_at'        => $product->getUpdatedAt(),
                    ];
                }

                if (!empty($batch)) {
                    $result = $this->apiClient->syncData('/api/v1/sync/products', [
                        'products'    => $batch,
                        'store_id'    => $storeId ?? 0,
                        'platform'    => 'magento2',
                        'sync_config' => $this->config->getSyncConfig($storeId),
                    ], $storeId);

                    if ($result['success']) {
                        $synced += count($batch);
                    } else {
                        $failed += count($batch);
                        $this->logger->warning('Product sync batch failed', $result);
                    }
                }

                $collection->clear();

                if ($progressCallback) {
                    $progressCallback("Page {$page}/{$lastPage} — synced: {$synced}, failed: {$failed}");
                }

                // Stop if limit reached
                if ($limit && ($synced + $failed) >= $limit) {
                    break;
                }
            }

            $this->completeLog($log, $synced, $failed);
        } catch (\Exception $e) {
            $this->failLog($log, $e->getMessage());
            $this->logger->error('Product sync error: ' . $e->getMessage());
        }

        return ['synced' => $synced, 'failed' => $failed, 'message' => 'Product sync completed'];
    }

    /* ══════════════════════════ Categories ════════════════════════════ */

    public function syncCategories(?int $storeId = null, ?\Closure $progressCallback = null): array
    {
        if (!$this->config->isEnabled($storeId) || !$this->config->isSyncCategories($storeId)) {
            return ['synced' => 0, 'failed' => 0, 'message' => 'Category sync disabled'];
        }

        $log = $this->createLog(SyncLog::ENTITY_TYPE_CATEGORY, $storeId);
        $synced = 0;
        $failed = 0;

        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect(['name', 'url_key', 'is_active', 'level', 'position',
                'description', 'image', 'meta_title', 'meta_description', 'include_in_menu']);
            $collection->setStoreId($storeId ?? 0);

            $batch = [];
            $count = 0;
            $total = $collection->getSize();

            foreach ($collection as $category) {
                $batch[] = [
                    'id'              => (string) $category->getId(),
                    'name'            => $category->getName(),
                    'url_key'         => $category->getUrlKey(),
                    'is_active'       => (bool) $category->getIsActive(),
                    'level'           => (int) $category->getLevel(),
                    'position'        => (int) $category->getPosition(),
                    'parent_id'       => (string) $category->getParentId(),
                    'path'            => $category->getPath(),
                    'description'     => mb_substr((string) $category->getDescription(), 0, 300),
                    'include_in_menu' => (bool) $category->getIncludeInMenu(),
                    'product_count'   => $category->getProductCount(),
                    'created_at'      => $category->getCreatedAt(),
                    'updated_at'      => $category->getUpdatedAt(),
                ];

                $count++;

                if (count($batch) >= $this->config->getSyncBatchSize($storeId)) {
                    $result = $this->apiClient->syncData('/api/v1/sync/categories', [
                        'categories' => $batch,
                        'store_id'   => $storeId ?? 0,
                        'platform'   => 'magento2',
                    ], $storeId);

                    $result['success'] ? ($synced += count($batch)) : ($failed += count($batch));
                    $batch = [];

                    if ($progressCallback) {
                        $progressCallback("Category {$count}/{$total} — synced: {$synced}, failed: {$failed}");
                    }
                }
            }

            // Remaining batch
            if (!empty($batch)) {
                $result = $this->apiClient->syncData('/api/v1/sync/categories', [
                    'categories' => $batch,
                    'store_id'   => $storeId ?? 0,
                    'platform'   => 'magento2',
                ], $storeId);
                $result['success'] ? ($synced += count($batch)) : ($failed += count($batch));
            }

            $this->completeLog($log, $synced, $failed);
        } catch (\Exception $e) {
            $this->failLog($log, $e->getMessage());
        }

        return ['synced' => $synced, 'failed' => $failed, 'message' => 'Category sync completed'];
    }

    /* ══════════════════════════ Orders ════════════════════════════════ */

    public function syncOrders(
        ?int $storeId = null,
        ?string $fromDate = null,
        ?\Closure $progressCallback = null,
        ?int $batchSizeOverride = null,
        int $delaySec = 0,
        ?int $limit = null,
    ): array {
        if (!$this->config->isEnabled($storeId) || !$this->config->isSyncOrders($storeId)) {
            return ['synced' => 0, 'failed' => 0, 'message' => 'Order sync disabled'];
        }

        $log = $this->createLog(SyncLog::ENTITY_TYPE_ORDER, $storeId);
        $batchSize = $batchSizeOverride ?? $this->config->getSyncBatchSize($storeId);
        $synced = 0;
        $failed = 0;

        try {
            $collection = $this->orderCollectionFactory->create();
            if ($storeId) {
                $collection->addFieldToFilter('store_id', $storeId);
            }
            if ($fromDate) {
                $collection->addFieldToFilter('created_at', ['gteq' => $fromDate]);
            }
            // If limit is set, fetch newest orders first
            $sortDir = $limit ? 'DESC' : 'ASC';
            $collection->setOrder('created_at', $sortDir);
            $effectiveBatch = $limit ? min($batchSize, $limit) : $batchSize;
            $collection->setPageSize($effectiveBatch);

            $lastPage = $collection->getLastPageNumber();
            if ($limit) {
                $maxPages = (int) ceil($limit / $effectiveBatch);
                $lastPage = min($lastPage, $maxPages);
            }

            for ($page = 1; $page <= $lastPage; $page++) {
                $collection->setCurPage($page);
                $batch = [];

                foreach ($collection as $order) {
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

                    $batch[] = [
                        'order_id'       => (string) $order->getIncrementId(),
                        'entity_id'      => (string) $order->getId(),
                        'status'         => $order->getStatus(),
                        'state'          => $order->getState(),
                        'grand_total'    => (float) $order->getGrandTotal(),
                        'subtotal'       => (float) $order->getSubtotal(),
                        'tax_amount'     => (float) $order->getTaxAmount(),
                        'shipping_amount' => (float) $order->getShippingAmount(),
                        'discount_amount' => (float) $order->getDiscountAmount(),
                        'total_qty'      => (int) $order->getTotalQtyOrdered(),
                        'currency'       => $order->getOrderCurrencyCode(),
                        'payment_method' => $order->getPayment() ? $order->getPayment()->getMethod() : null,
                        'shipping_method' => $order->getShippingMethod(),
                        'coupon_code'    => $order->getCouponCode(),
                        'customer_email' => $order->getCustomerEmail(),
                        'customer_id'    => $order->getCustomerId() ? (string) $order->getCustomerId() : null,
                        'is_guest'       => (bool) $order->getCustomerIsGuest(),
                        'items'          => $items,
                        'billing_address' => $this->formatAddress($order->getBillingAddress()),
                        'shipping_address' => $order->getShippingAddress() ? $this->formatAddress($order->getShippingAddress()) : null,
                        'created_at'     => $order->getCreatedAt(),
                        'updated_at'     => $order->getUpdatedAt(),
                    ];
                }

                if (!empty($batch)) {
                    $result = $this->apiClient->syncData('/api/v1/sync/orders', [
                        'orders'   => $batch,
                        'store_id' => $storeId ?? 0,
                        'platform' => 'magento2',
                    ], $storeId);

                    $result['success'] ? ($synced += count($batch)) : ($failed += count($batch));
                }

                $collection->clear();

                if ($progressCallback) {
                    $progressCallback("Page {$page}/{$lastPage} — synced: {$synced}, failed: {$failed}");
                }

                // Stop if limit reached
                if ($limit && ($synced + $failed) >= $limit) {
                    break;
                }

                // Throttle: sleep between batches to avoid overloading the server.
                if ($delaySec > 0 && $page < $lastPage) {
                    sleep($delaySec);
                }
            }

            $this->completeLog($log, $synced, $failed);
        } catch (\Exception $e) {
            $this->failLog($log, $e->getMessage());
        }

        return ['synced' => $synced, 'failed' => $failed, 'message' => 'Order sync completed'];
    }

    /* ══════════════════════════ Customers ═════════════════════════════ */

    public function syncCustomers(
        ?int $storeId = null,
        ?\Closure $progressCallback = null,
        ?int $limit = null,
    ): array
    {
        if (!$this->config->isEnabled($storeId) || !$this->config->isSyncCustomers($storeId)) {
            return ['synced' => 0, 'failed' => 0, 'message' => 'Customer sync disabled'];
        }

        $log = $this->createLog(SyncLog::ENTITY_TYPE_CUSTOMER, $storeId);
        $batchSize = $this->config->getSyncBatchSize($storeId);
        $synced = 0;
        $failed = 0;

        try {
            $collection = $this->customerCollectionFactory->create();
            $collection->addAttributeToSelect(['email', 'firstname', 'lastname', 'dob', 'gender',
                'group_id', 'created_at', 'updated_at']);
            if ($storeId) {
                $collection->addFieldToFilter('store_id', $storeId);
            }
            $effectiveBatch = $limit ? min($batchSize, $limit) : $batchSize;
            $collection->setPageSize($effectiveBatch);

            $lastPage = $collection->getLastPageNumber();
            if ($limit) {
                $maxPages = (int) ceil($limit / $effectiveBatch);
                $lastPage = min($lastPage, $maxPages);
            }

            for ($page = 1; $page <= $lastPage; $page++) {
                $collection->setCurPage($page);
                $batch = [];

                foreach ($collection as $customer) {
                    $batch[] = [
                        'id'         => (string) $customer->getId(),
                        'email'      => $customer->getEmail(),
                        'firstname'  => $customer->getFirstname(),
                        'lastname'   => $customer->getLastname(),
                        'name'       => $customer->getFirstname() . ' ' . $customer->getLastname(),
                        'dob'        => $customer->getDob(),
                        'gender'     => $customer->getGender(),
                        'group_id'   => (int) $customer->getGroupId(),
                        'created_at' => $customer->getCreatedAt(),
                        'updated_at' => $customer->getUpdatedAt(),
                    ];
                }

                if (!empty($batch)) {
                    $result = $this->apiClient->syncData('/api/v1/sync/customers', [
                        'customers' => $batch,
                        'store_id'  => $storeId ?? 0,
                        'platform'  => 'magento2',
                    ], $storeId);

                    $result['success'] ? ($synced += count($batch)) : ($failed += count($batch));
                }

                $collection->clear();

                if ($progressCallback) {
                    $progressCallback("Page {$page}/{$lastPage} — synced: {$synced}, failed: {$failed}");
                }

                // Stop if limit reached
                if ($limit && ($synced + $failed) >= $limit) {
                    break;
                }
            }

            $this->completeLog($log, $synced, $failed);
        } catch (\Exception $e) {
            $this->failLog($log, $e->getMessage());
        }

        return ['synced' => $synced, 'failed' => $failed, 'message' => 'Customer sync completed'];
    }

    /* ══════════════════════════ Sales Data ════════════════════════════ */

    public function syncSalesData(?int $storeId = null, ?string $fromDate = null): array
    {
        if (!$this->config->isEnabled($storeId) || !$this->config->isSyncSalesData($storeId)) {
            return ['synced' => 0, 'failed' => 0, 'message' => 'Sales data sync disabled'];
        }

        $log = $this->createLog(SyncLog::ENTITY_TYPE_SALES, $storeId);

        try {
            $collection = $this->orderCollectionFactory->create();
            if ($storeId) {
                $collection->addFieldToFilter('store_id', $storeId);
            }
            if ($fromDate) {
                $collection->addFieldToFilter('created_at', ['gteq' => $fromDate]);
            }

            // Aggregate sales by day
            $collection->getSelect()
                ->reset(\Magento\Framework\DB\Select::COLUMNS)
                ->columns([
                    'date'            => new \Magento\Framework\DB\Sql\Expression('DATE(created_at)'),
                    'total_orders'    => new \Magento\Framework\DB\Sql\Expression('COUNT(*)'),
                    'total_revenue'   => new \Magento\Framework\DB\Sql\Expression('SUM(grand_total)'),
                    'total_subtotal'  => new \Magento\Framework\DB\Sql\Expression('SUM(subtotal)'),
                    'total_tax'       => new \Magento\Framework\DB\Sql\Expression('SUM(tax_amount)'),
                    'total_shipping'  => new \Magento\Framework\DB\Sql\Expression('SUM(shipping_amount)'),
                    'total_discount'  => new \Magento\Framework\DB\Sql\Expression('SUM(discount_amount)'),
                    'total_refunded'  => new \Magento\Framework\DB\Sql\Expression('SUM(IFNULL(total_refunded, 0))'),
                    'avg_order_value' => new \Magento\Framework\DB\Sql\Expression('AVG(grand_total)'),
                    'total_items'     => new \Magento\Framework\DB\Sql\Expression('SUM(total_qty_ordered)'),
                ])
                ->group(new \Magento\Framework\DB\Sql\Expression('DATE(created_at)'))
                ->order('DATE(created_at) ASC');

            $salesData = [];
            foreach ($collection->getData() as $row) {
                $salesData[] = [
                    'date'            => $row['date'],
                    'total_orders'    => (int) $row['total_orders'],
                    'total_revenue'   => (float) $row['total_revenue'],
                    'total_subtotal'  => (float) $row['total_subtotal'],
                    'total_tax'       => (float) $row['total_tax'],
                    'total_shipping'  => (float) $row['total_shipping'],
                    'total_discount'  => (float) $row['total_discount'],
                    'total_refunded'  => (float) $row['total_refunded'],
                    'avg_order_value' => round((float) $row['avg_order_value'], 2),
                    'total_items'     => (int) $row['total_items'],
                ];
            }

            if (!empty($salesData)) {
                $result = $this->apiClient->syncData('/api/v1/sync/sales', [
                    'sales_data' => $salesData,
                    'store_id'   => $storeId ?? 0,
                    'platform'   => 'magento2',
                    'currency'   => $this->storeManager->getStore($storeId ?? 0)->getCurrentCurrencyCode(),
                ], $storeId);

                if ($result['success']) {
                    $this->completeLog($log, count($salesData), 0);
                    return ['synced' => count($salesData), 'failed' => 0, 'message' => 'Sales data sync completed'];
                } else {
                    $this->failLog($log, $result['data']['message'] ?? 'Unknown error');
                    return ['synced' => 0, 'failed' => count($salesData), 'message' => 'Sales data sync failed'];
                }
            }

            $this->completeLog($log, 0, 0);
            return ['synced' => 0, 'failed' => 0, 'message' => 'No sales data to sync'];
        } catch (\Exception $e) {
            $this->failLog($log, $e->getMessage());
            return ['synced' => 0, 'failed' => 0, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /* ══════════════════════════ Sync All ══════════════════════════════ */

    public function syncAll(?int $storeId = null, ?\Closure $progressCallback = null): array
    {
        $results = [];
        $results['products']   = $this->syncProducts($storeId, $progressCallback);
        $results['categories'] = $this->syncCategories($storeId, $progressCallback);
        $results['orders']     = $this->syncOrders($storeId, null, $progressCallback);
        $results['customers']  = $this->syncCustomers($storeId, $progressCallback);
        $results['sales_data'] = $this->syncSalesData($storeId);
        return $results;
    }

    /* ══════════════════════════ Helpers ═══════════════════════════════ */

    /**
     * Resolve the brand text from the configured attribute.
     * Handles select/multiselect (option ID → label) and text attributes.
     */
    private function resolveBrandValue($product, string $brandAttrCode, ?int $storeId): ?string
    {
        if (!$brandAttrCode) {
            return null;
        }

        try {
            $rawValue = $product->getData($brandAttrCode);
            if ($rawValue === null || $rawValue === '' || $rawValue === false) {
                return null;
            }

            // Check if this is a select/multiselect attribute (stores option IDs)
            $attribute = $this->eavConfig->getAttribute('catalog_product', $brandAttrCode);
            if ($attribute && $attribute->getId()) {
                $frontendInput = $attribute->getFrontendInput();
                if (in_array($frontendInput, ['select', 'multiselect'], true)) {
                    // Resolve option ID(s) to label(s)
                    $optionText = $product->getAttributeText($brandAttrCode);
                    if ($optionText) {
                        return is_array($optionText) ? implode(', ', $optionText) : (string) $optionText;
                    }
                    return null;
                }
            }

            // For text/textarea attributes, return the raw value
            return (string) $rawValue;
        } catch (\Exception $e) {
            $this->logger->debug("Brand resolve error for attr '{$brandAttrCode}': " . $e->getMessage());
            return null;
        }
    }

    private function createLog(string $entityType, ?int $storeId): SyncLog
    {
        /** @var SyncLog $log */
        $log = $this->syncLogFactory->create();
        $log->setData([
            'entity_type' => $entityType,
            'status'      => SyncLog::STATUS_RUNNING,
            'store_id'    => $storeId ?? 0,
            'started_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->syncLogResource->save($log);
        return $log;
    }

    private function completeLog(SyncLog $log, int $synced, int $failed): void
    {
        $log->setData('status', $failed > 0 && $synced === 0 ? SyncLog::STATUS_FAILED : SyncLog::STATUS_SUCCESS);
        $log->setData('records_synced', $synced);
        $log->setData('records_failed', $failed);
        $log->setData('completed_at', date('Y-m-d H:i:s'));
        $this->syncLogResource->save($log);
    }

    private function failLog(SyncLog $log, string $message): void
    {
        $log->setData('status', SyncLog::STATUS_FAILED);
        $log->setData('error_message', mb_substr($message, 0, 1000));
        $log->setData('completed_at', date('Y-m-d H:i:s'));
        $this->syncLogResource->save($log);
    }

    private function formatAddress($address): ?array
    {
        if (!$address) {
            return null;
        }

        return [
            'firstname'  => $address->getFirstname(),
            'lastname'   => $address->getLastname(),
            'street'     => implode(', ', $address->getStreet()),
            'city'       => $address->getCity(),
            'region'     => $address->getRegion(),
            'postcode'   => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone'  => $address->getTelephone(),
        ];
    }
}

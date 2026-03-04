<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Cron;

use Ecom360\Analytics\Helper\ApiClient;
use Ecom360\Analytics\Helper\Config;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Syncs inventory (stock qty + cost price) to the Ecom360 platform.
 * Supports: Dead-stock detection (UC7), replenishment prediction (UC16),
 *           margin analysis (UC17).
 *
 * Runs every 2 hours via crontab.xml.
 */
class SyncInventoryCron
{
    private Config $config;
    private ApiClient $apiClient;
    private ProductCollectionFactory $productCollectionFactory;
    private StockRegistryInterface $stockRegistry;
    private LoggerInterface $logger;

    private const BATCH_SIZE = 100;

    public function __construct(
        Config $config,
        ApiClient $apiClient,
        ProductCollectionFactory $productCollectionFactory,
        StockRegistryInterface $stockRegistry,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->stockRegistry = $stockRegistry;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $this->logger->info('Ecom360: Starting inventory sync cron');

        try {
            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToSelect(['sku', 'name', 'price', 'cost', 'special_price', 'status']);
            $collection->addFieldToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
            $collection->setPageSize(self::BATCH_SIZE);

            $pages = $collection->getLastPageNumber();
            $totalSynced = 0;

            for ($page = 1; $page <= $pages; $page++) {
                $collection->setCurPage($page);
                $batch = [];

                foreach ($collection as $product) {
                    try {
                        $stockItem = $this->stockRegistry->getStockItemBySku($product->getSku());
                    } catch (\Exception $e) {
                        continue;
                    }

                    $batch[] = [
                        'product_id'    => (string) $product->getId(),
                        'sku'           => $product->getSku(),
                        'name'          => $product->getName(),
                        'price'         => (float) $product->getPrice(),
                        'cost'          => $product->getCost() ? (float) $product->getCost() : null,
                        'special_price' => $product->getSpecialPrice() ? (float) $product->getSpecialPrice() : null,
                        'qty'           => (float) $stockItem->getQty(),
                        'is_in_stock'   => (bool) $stockItem->getIsInStock(),
                        'min_qty'       => (float) $stockItem->getMinQty(),
                        'low_stock'     => ($stockItem->getQty() <= $stockItem->getMinQty()),
                    ];
                }

                if (!empty($batch)) {
                    $result = $this->apiClient->syncData('/api/v1/sync/inventory', [
                        'items'    => $batch,
                        'total'    => count($batch),
                        'platform' => 'magento2',
                        'store_id' => 0,
                    ]);

                    if ($result['success']) {
                        $totalSynced += count($batch);
                    } else {
                        $this->logger->warning('Ecom360: Inventory sync batch failed', [
                            'page'   => $page,
                            'status' => $result['status_code'],
                        ]);
                    }
                }

                $collection->clear();
            }

            $this->logger->info('Ecom360: Inventory sync completed', [
                'synced' => $totalSynced,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360: Inventory sync error: ' . $e->getMessage());
        }
    }
}

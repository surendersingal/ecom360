<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires when a stock item is updated.
 * Tracks stock_changed events for dead-stock + replenishment (UC7, UC16).
 * ★ ASYNC — queues event in DB (<1ms).
 */
class InventoryObserver implements ObserverInterface
{
    private Config $config;
    private EventQueuePublisher $queue;
    private LoggerInterface $logger;

    public function __construct(Config $config, EventQueuePublisher $queue, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->queue  = $queue;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            /** @var \Magento\CatalogInventory\Model\Stock\Item $stockItem */
            $stockItem = $observer->getEvent()->getData('item');
            if (!$stockItem) {
                return;
            }

            // Only fire if qty or status actually changed
            $origQty    = (float) ($stockItem->getOrigData('qty') ?? 0);
            $newQty     = (float) $stockItem->getQty();
            $origStatus = (int)   ($stockItem->getOrigData('is_in_stock') ?? 1);
            $newStatus  = (int)   $stockItem->getIsInStock();

            if ($origQty === $newQty && $origStatus === $newStatus) {
                return; // no meaningful change
            }

            $product   = $stockItem->getProduct();
            $productId = $stockItem->getProductId();
            $sku       = $product ? $product->getSku() : (string) $productId;
            $name      = $product ? $product->getName() : '';

            $this->queue->publishEvent('stock_changed', [
                'product_id'   => $productId,
                'sku'          => $sku,
                'name'         => $name,
                'old_qty'      => $origQty,
                'new_qty'      => $newQty,
                'qty_change'   => $newQty - $origQty,
                'is_in_stock'  => $newStatus,
                'was_in_stock' => $origStatus,
                'low_stock'    => ($newQty <= (float) $stockItem->getMinQty()),
                'changed_at'   => date('c'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[Ecom360] InventoryObserver error: ' . $e->getMessage());
        }
    }
}

<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires when a credit memo (refund) is created.
 * Tracks order_refunded events for serial-returner detection (UC10).
 * ★ ASYNC — queues event in DB (<1ms).
 */
class RefundObserver implements ObserverInterface
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
        if (!$this->config->isEnabled() || !$this->config->isTrackPurchases()) {
            return;
        }

        try {
            /** @var \Magento\Sales\Model\Order\Creditmemo $creditmemo */
            $creditmemo = $observer->getEvent()->getData('creditmemo');
            if (!$creditmemo) {
                return;
            }

            $order = $creditmemo->getOrder();
            if (!$order) {
                return;
            }

            $items = [];
            foreach ($creditmemo->getAllItems() as $item) {
                if ($item->getQty() <= 0) {
                    continue;
                }
                $items[] = [
                    'product_id' => $item->getProductId(),
                    'sku'        => $item->getSku(),
                    'name'       => $item->getName(),
                    'qty'        => (int) $item->getQty(),
                    'row_total'  => (float) $item->getRowTotal(),
                ];
            }

            $this->queue->publishEvent('order_refunded', [
                'order_id'        => $order->getIncrementId(),
                'order_entity_id' => $order->getEntityId(),
                'creditmemo_id'   => $creditmemo->getIncrementId(),
                'customer_email'  => $order->getCustomerEmail(),
                'customer_id'     => $order->getCustomerId(),
                'customer_name'   => $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname(),
                'refund_amount'   => (float) $creditmemo->getGrandTotal(),
                'adjustment'      => (float) $creditmemo->getAdjustment(),
                'items'           => $items,
                'items_count'     => count($items),
                'reason'          => $creditmemo->getCustomerNote() ?: '',
                'refunded_at'     => date('c'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[Ecom360] RefundObserver error: ' . $e->getMessage());
        }
    }
}

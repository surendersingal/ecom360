<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires when a shipment is created for an order.
 * Tracks order_shipped events for post-purchase timing (UC5, UC9).
 * ★ ASYNC — queues event in DB (<1ms), never makes HTTP calls.
 */
class ShipmentObserver implements ObserverInterface
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
            /** @var \Magento\Sales\Model\Order\Shipment $shipment */
            $shipment = $observer->getEvent()->getData('shipment');
            if (!$shipment) {
                return;
            }

            $order = $shipment->getOrder();
            if (!$order) {
                return;
            }

            // Gather tracking info
            $tracks = $shipment->getAllTracks();
            $trackingData = [];
            foreach ($tracks as $track) {
                $trackingData[] = [
                    'carrier'         => $track->getCarrierCode() ?: ($track->getTitle() ?: 'unknown'),
                    'tracking_number' => $track->getTrackNumber() ?: '',
                    'title'           => $track->getTitle() ?: '',
                ];
            }

            // Get shipment items
            $items = [];
            foreach ($shipment->getAllItems() as $item) {
                $items[] = [
                    'product_id' => $item->getProductId(),
                    'sku'        => $item->getSku(),
                    'name'       => $item->getName(),
                    'qty'        => (int) $item->getQty(),
                ];
            }

            $this->queue->publishEvent('order_shipped', [
                'order_id'        => $order->getIncrementId(),
                'order_entity_id' => $order->getEntityId(),
                'shipment_id'     => $shipment->getIncrementId(),
                'customer_email'  => $order->getCustomerEmail(),
                'customer_name'   => $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname(),
                'tracking'        => $trackingData,
                'items'           => $items,
                'items_count'     => count($items),
                'total_qty'       => (int) $shipment->getTotalQty(),
                'shipped_at'      => date('c'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[Ecom360] ShipmentObserver error: ' . $e->getMessage());
        }
    }
}

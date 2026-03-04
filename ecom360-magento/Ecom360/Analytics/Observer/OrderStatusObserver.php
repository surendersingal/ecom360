<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires on order save — tracks status changes only (not initial creation).
 * ★ ASYNC — queues event in DB (<1ms), never makes HTTP calls.
 * ★ DEDUP — skips initial order creation (getOrigData == null) to avoid
 *   double-fire with OrderPlacedObserver.
 */
class OrderStatusObserver implements ObserverInterface
{
    private Config $config;
    private EventQueuePublisher $queue;
    private LoggerInterface $logger;

    public function __construct(Config $config, EventQueuePublisher $queue, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isTrackPurchases()) {
            return;
        }

        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $observer->getEvent()->getData('order');
            if (!$order || !$order->dataHasChangedFor('status')) {
                return;
            }

            // ★ DEDUP: Skip initial order creation (origData is null on first save)
            // This prevents double-fire alongside OrderPlacedObserver.
            if ($order->getOrigData('status') === null) {
                return;
            }

            $this->queue->publishEvent('order_status_changed', [
                'order_id'   => (string) $order->getIncrementId(),
                'old_status' => $order->getOrigData('status'),
                'new_status' => $order->getStatus(),
                'state'      => $order->getState(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 order_status observer error: ' . $e->getMessage());
        }
    }
}

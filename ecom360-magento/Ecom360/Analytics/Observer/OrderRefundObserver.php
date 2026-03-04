<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * ★ ASYNC — queues event in DB (<1ms), never makes HTTP calls.
 */
class OrderRefundObserver implements ObserverInterface
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
            /** @var \Magento\Sales\Model\Order\Creditmemo $creditmemo */
            $creditmemo = $observer->getEvent()->getData('creditmemo');
            if (!$creditmemo) {
                return;
            }

            $order = $creditmemo->getOrder();
            $customer = $order->getCustomerEmail()
                ? ['type' => 'email', 'value' => $order->getCustomerEmail()]
                : null;

            $this->queue->publishEvent('refund', [
                'order_id'      => (string) $order->getIncrementId(),
                'creditmemo_id' => (string) $creditmemo->getIncrementId(),
                'refund_amount' => (float) $creditmemo->getGrandTotal(),
                'currency'      => $order->getOrderCurrencyCode(),
            ], $customer);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 refund observer error: ' . $e->getMessage());
        }
    }
}

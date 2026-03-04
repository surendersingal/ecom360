<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires on successful order placement (purchase event).
 * ★ ASYNC — queues event in DB (<1ms), never makes HTTP calls.
 */
class OrderPlacedObserver implements ObserverInterface
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
            if (!$order) {
                return;
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

            $customer = null;
            $email = $order->getCustomerEmail();
            if ($email) {
                $customer = ['type' => 'email', 'value' => $email];
            }

            $this->queue->publishEvent('purchase', [
                'order_id'        => (string) $order->getIncrementId(),
                'total'           => (float) $order->getGrandTotal(),
                'subtotal'        => (float) $order->getSubtotal(),
                'tax'             => (float) $order->getTaxAmount(),
                'shipping'        => (float) $order->getShippingAmount(),
                'discount'        => (float) $order->getDiscountAmount(),
                'payment_method'  => $order->getPayment() ? $order->getPayment()->getMethod() : '',
                'shipping_method' => $order->getShippingMethod(),
                'currency'        => $order->getOrderCurrencyCode(),
                'item_count'      => $order->getTotalQtyOrdered(),
                'items'           => $items,
                'coupons'         => $order->getCouponCode() ? [$order->getCouponCode()] : [],
                'is_guest'        => (bool) $order->getCustomerIsGuest(),
            ], $customer);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 order_placed observer error: ' . $e->getMessage());
        }
    }
}

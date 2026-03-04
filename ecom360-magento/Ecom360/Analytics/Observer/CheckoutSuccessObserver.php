<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\AbandonedCart;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Ecom360\Analytics\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Ecom360\Analytics\Model\ResourceModel\AbandonedCart\CollectionFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires on checkout success — marks abandoned carts as converted.
 * ★ ASYNC — queues tracking event in DB (<1ms), never makes HTTP calls.
 */
class CheckoutSuccessObserver implements ObserverInterface
{
    private Config $config;
    private EventQueuePublisher $queue;
    private CollectionFactory $collectionFactory;
    private AbandonedCartResource $abandonedCartResource;
    private OrderRepositoryInterface $orderRepository;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        EventQueuePublisher $queue,
        CollectionFactory $collectionFactory,
        AbandonedCartResource $abandonedCartResource,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->queue = $queue;
        $this->collectionFactory = $collectionFactory;
        $this->abandonedCartResource = $abandonedCartResource;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $orderIds = $observer->getEvent()->getData('order_ids');
            if (empty($orderIds)) {
                return;
            }

            foreach ($orderIds as $orderId) {
                $order = $this->orderRepository->get($orderId);
                $quoteId = $order->getQuoteId();

                if ($quoteId) {
                    $collection = $this->collectionFactory->create();
                    $collection->addFieldToFilter('quote_id', $quoteId);
                    $abandonedCart = $collection->getFirstItem();

                    if ($abandonedCart->getId()) {
                        $abandonedCart->setData('status', AbandonedCart::STATUS_CONVERTED);
                        $this->abandonedCartResource->save($abandonedCart);
                    }
                }

                // Queue checkout success tracking event
                if ($this->config->isTrackCheckout()) {
                    $this->queue->publishEvent('checkout_success', [
                        'order_id' => (string) $order->getIncrementId(),
                        'total'    => (float) $order->getGrandTotal(),
                        'currency' => $order->getOrderCurrencyCode(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 checkout_success observer error: ' . $e->getMessage());
        }
    }
}

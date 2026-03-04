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
class RemoveFromCartObserver implements ObserverInterface
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
        if (!$this->config->isEnabled() || !$this->config->isTrackCart()) {
            return;
        }

        try {
            /** @var \Magento\Quote\Model\Quote\Item $quoteItem */
            $quoteItem = $observer->getEvent()->getData('quote_item');
            $product = $quoteItem->getProduct();

            $this->queue->publishEvent('remove_from_cart', [
                'product_id'   => (string) ($product ? $product->getId() : $quoteItem->getProductId()),
                'product_name' => $quoteItem->getName(),
                'sku'          => $quoteItem->getSku(),
                'price'        => (float) $quoteItem->getPrice(),
                'quantity'     => (int) $quoteItem->getQty(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 remove_from_cart observer error: ' . $e->getMessage());
        }
    }
}

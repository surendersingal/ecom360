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
 * ★ BATCHED — collects all wishlist items into a single queue entry
 *   instead of N separate HTTP calls (was O(n) blocking before).
 */
class WishlistAddObserver implements ObserverInterface
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
        if (!$this->config->isEnabled() || !$this->config->isTrackWishlist()) {
            return;
        }

        try {
            $items = $observer->getEvent()->getData('items');

            if ($items && is_array($items)) {
                // Batch all items into a single queue entry
                $products = [];
                foreach ($items as $item) {
                    $product = $item->getProduct();
                    $products[] = [
                        'product_id'   => (string) $product->getId(),
                        'product_name' => $product->getName(),
                        'sku'          => $product->getSku(),
                        'price'        => (float) $product->getFinalPrice(),
                    ];
                }

                $this->queue->publishEvent('add_to_wishlist', [
                    'products'    => $products,
                    'items_count' => count($products),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 wishlist_add observer error: ' . $e->getMessage());
        }
    }
}

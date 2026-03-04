<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Real-time product sync on save.
 * ★ ASYNC — queues sync call in DB (<1ms), never makes HTTP calls.
 */
class ProductSaveObserver implements ObserverInterface
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
        if (!$this->config->isEnabled() || !$this->config->isSyncProducts()) {
            return;
        }

        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $observer->getEvent()->getData('product');
            if (!$product) {
                return;
            }

            // Build full product data so upsert doesn't null-out fields from bulk sync
            $categoryIds = $product->getCategoryIds();
            $categoryNames = [];
            if (!empty($categoryIds)) {
                $categoryCollection = $product->getCategoryCollection()->addAttributeToSelect('name');
                foreach ($categoryCollection as $cat) {
                    $categoryNames[] = $cat->getName();
                }
            }

            $imageUrl = null;
            if ($product->getImage() && $product->getImage() !== 'no_selection') {
                try {
                    $imageUrl = $product->getMediaConfig()->getMediaUrl($product->getImage());
                } catch (\Exception $e) {
                    // ignore
                }
            }

            $this->queue->publishSync('/api/v1/sync/products', [
                'products' => [[
                    'id'                => (string) $product->getId(),
                    'sku'               => $product->getSku(),
                    'name'              => $product->getName(),
                    'price'             => (float) $product->getPrice(),
                    'special_price'     => $product->getSpecialPrice() ? (float) $product->getSpecialPrice() : null,
                    'status'            => $product->getStatus() == 1 ? 'enabled' : 'disabled',
                    'type'              => $product->getTypeId(),
                    'visibility'        => (int) $product->getVisibility(),
                    'weight'            => $product->getWeight() ? (float) $product->getWeight() : null,
                    'url_key'           => $product->getUrlKey(),
                    'image_url'         => $imageUrl,
                    'description'       => $product->getDescription() ? mb_substr(strip_tags($product->getDescription()), 0, 500) : null,
                    'short_description' => $product->getShortDescription() ? mb_substr(strip_tags($product->getShortDescription()), 0, 300) : null,
                    'categories'        => $categoryNames,
                    'category_ids'      => array_map('strval', $categoryIds),
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]],
                'platform' => 'magento2',
                'realtime' => true,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 product_save observer error: ' . $e->getMessage());
        }
    }
}

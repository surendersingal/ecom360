<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Observer;

use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueuePublisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * ★ ASYNC — queues sync call in DB (<1ms), never makes HTTP calls.
 */
class CategorySaveObserver implements ObserverInterface
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
        if (!$this->config->isEnabled() || !$this->config->isSyncCategories()) {
            return;
        }

        try {
            /** @var \Magento\Catalog\Model\Category $category */
            $category = $observer->getEvent()->getData('category');
            if (!$category) {
                return;
            }

            $this->queue->publishSync('/api/v1/sync/categories', [
                'categories' => [[
                    'id'        => (string) $category->getId(),
                    'name'      => $category->getName(),
                    'url_key'   => $category->getUrlKey(),
                    'is_active' => (bool) $category->getIsActive(),
                    'level'     => (int) $category->getLevel(),
                    'parent_id' => (string) $category->getParentId(),
                    'path'      => $category->getPath(),
                ]],
                'platform' => 'magento2',
                'realtime' => true,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 category_save observer error: ' . $e->getMessage());
        }
    }
}

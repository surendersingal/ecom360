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
class SearchObserver implements ObserverInterface
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
        if (!$this->config->isEnabled() || !$this->config->isTrackSearch()) {
            return;
        }

        try {
            $query = $observer->getEvent()->getData('catalogsearch_query')
                ?? $observer->getEvent()->getData('data_object');

            if (!$query) {
                return;
            }

            $this->queue->publishEvent('search', [
                'query'        => $query->getQueryText(),
                'num_results'  => (int) $query->getNumResults(),
                'popularity'   => (int) $query->getPopularity(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 search observer error: ' . $e->getMessage());
        }
    }
}

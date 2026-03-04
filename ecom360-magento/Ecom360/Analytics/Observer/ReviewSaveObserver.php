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
class ReviewSaveObserver implements ObserverInterface
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
        if (!$this->config->isEnabled() || !$this->config->isTrackReviews()) {
            return;
        }

        try {
            /** @var \Magento\Review\Model\Review $review */
            $review = $observer->getEvent()->getData('object');
            if (!$review || !($review instanceof \Magento\Review\Model\Review)) {
                return;
            }

            $this->queue->publishEvent('review', [
                'product_id'   => (string) $review->getEntityPkValue(),
                'review_id'    => (string) $review->getId(),
                'title'        => $review->getTitle(),
                'detail'       => mb_substr((string) $review->getDetail(), 0, 300),
                'nickname'     => $review->getNickname(),
                'status'       => (int) $review->getStatusId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Ecom360 review_save observer error: ' . $e->getMessage());
        }
    }
}

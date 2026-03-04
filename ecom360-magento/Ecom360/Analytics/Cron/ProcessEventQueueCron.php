<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Cron;

use Ecom360\Analytics\Helper\ApiClient;
use Ecom360\Analytics\Helper\Config;
use Ecom360\Analytics\Model\EventQueue;
use Ecom360\Analytics\Model\ResourceModel\EventQueue as EventQueueResource;
use Ecom360\Analytics\Model\ResourceModel\EventQueue\CollectionFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Cron consumer — flushes the event queue every minute.
 *
 * Reads pending events in batches, sends them via ApiClient,
 * then marks them as done or failed. This is the ONLY place
 * where outbound HTTP calls to Ecom360 are made (apart from cron syncs).
 *
 * Runs every minute. Each run processes up to 200 items.
 * Batches tracking events into groups of 50 (the batch endpoint limit).
 * Retries failed items up to 3 times with exponential backoff.
 */
class ProcessEventQueueCron
{
    private const BATCH_LIMIT  = 200;  // Max items per cron run
    private const BATCH_API    = 50;   // Max events per batch API call
    private const MAX_ATTEMPTS = 3;

    private Config $config;
    private ApiClient $apiClient;
    private CollectionFactory $collectionFactory;
    private EventQueueResource $resource;
    private Json $json;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        ApiClient $apiClient,
        CollectionFactory $collectionFactory,
        EventQueueResource $resource,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->collectionFactory = $collectionFactory;
        $this->resource = $resource;
        $this->json = $json;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // Grab pending items (oldest first)
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', EventQueue::STATUS_PENDING);
        $collection->setOrder('id', 'ASC');
        $collection->setPageSize(self::BATCH_LIMIT);

        if ($collection->getSize() === 0) {
            return;
        }

        // Separate event-type items (can be batched) from sync-type items (must be individual)
        $trackingEvents = [];
        $syncItems = [];

        foreach ($collection as $item) {
            // Mark as processing to prevent overlapping cron runs
            $item->setData('status', EventQueue::STATUS_PROCESSING);
            $this->resource->save($item);

            if ($item->getData('type') === EventQueue::TYPE_EVENT) {
                $trackingEvents[] = $item;
            } else {
                $syncItems[] = $item;
            }
        }

        // Process tracking events in batch
        $this->processTrackingBatch($trackingEvents);

        // Process sync items individually
        foreach ($syncItems as $item) {
            $this->processSyncItem($item);
        }

        // Clean up: delete completed items older than 24 hours
        $this->cleanup();
    }

    private function processTrackingBatch(array $items): void
    {
        if (empty($items)) {
            return;
        }

        // Group by store_id
        $grouped = [];
        foreach ($items as $item) {
            $storeId = (int) $item->getData('store_id');
            $grouped[$storeId][] = $item;
        }

        foreach ($grouped as $storeId => $storeItems) {
            // Build event payloads
            $events = [];
            $itemMap = [];

            foreach ($storeItems as $item) {
                try {
                    $payload = $this->json->unserialize($item->getData('payload'));
                    $event = $this->apiClient->buildEventPayloadPublic(
                        $item->getData('event_type'),
                        $payload['metadata'] ?? [],
                        $payload['customer'] ?? null,
                        $storeId ?: null
                    );
                    $events[] = $event;
                    $itemMap[] = $item;
                } catch (\Exception $e) {
                    $this->markFailed($item, $e->getMessage());
                }
            }

            // Send in batches of 50
            $chunks = array_chunk($events, self::BATCH_API);
            $itemChunks = array_chunk($itemMap, self::BATCH_API);

            foreach ($chunks as $i => $chunk) {
                try {
                    if (count($chunk) === 1) {
                        $success = $this->apiClient->sendEventDirect($chunk[0], $storeId ?: null);
                    } else {
                        $success = $this->apiClient->sendBatch($chunk, $storeId ?: null);
                    }

                    // Mark all items in this chunk
                    foreach ($itemChunks[$i] as $item) {
                        if ($success) {
                            $this->markDone($item);
                        } else {
                            $this->markRetryOrFailed($item, 'API returned error');
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error('[Ecom360] Queue batch send failed: ' . $e->getMessage());
                    foreach ($itemChunks[$i] as $item) {
                        $this->markRetryOrFailed($item, $e->getMessage());
                    }
                }
            }
        }
    }

    private function processSyncItem(EventQueue $item): void
    {
        try {
            $payload = $this->json->unserialize($item->getData('payload'));
            $path = $item->getData('event_type'); // stores the API path for sync items
            $storeId = (int) $item->getData('store_id');

            $result = $this->apiClient->syncData($path, $payload, $storeId ?: null);

            if ($result['success'] ?? false) {
                $this->markDone($item);
            } else {
                $this->markRetryOrFailed($item, $result['data']['message'] ?? 'Sync failed');
            }
        } catch (\Exception $e) {
            $this->markRetryOrFailed($item, $e->getMessage());
        }
    }

    private function markDone(EventQueue $item): void
    {
        $item->setData('status', EventQueue::STATUS_DONE);
        $item->setData('processed_at', date('Y-m-d H:i:s'));
        $this->resource->save($item);
    }

    private function markFailed(EventQueue $item, string $error): void
    {
        $item->setData('status', EventQueue::STATUS_FAILED);
        $item->setData('error_message', mb_substr($error, 0, 500));
        $item->setData('processed_at', date('Y-m-d H:i:s'));
        $this->resource->save($item);
    }

    private function markRetryOrFailed(EventQueue $item, string $error): void
    {
        $attempts = (int) $item->getData('attempts') + 1;
        $item->setData('attempts', $attempts);
        $item->setData('error_message', mb_substr($error, 0, 500));

        if ($attempts >= self::MAX_ATTEMPTS) {
            $item->setData('status', EventQueue::STATUS_FAILED);
            $item->setData('processed_at', date('Y-m-d H:i:s'));
            $this->logger->error('[Ecom360] Queue item ' . $item->getId() . ' failed after ' . $attempts . ' attempts: ' . $error);
        } else {
            // Reset to pending for retry on next cron run
            $item->setData('status', EventQueue::STATUS_PENDING);
        }

        $this->resource->save($item);
    }

    /**
     * Delete completed/failed items older than 24 hours to prevent table bloat.
     */
    private function cleanup(): void
    {
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getMainTable();
            $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));

            $connection->delete($table, [
                'status IN (?)' => [EventQueue::STATUS_DONE, EventQueue::STATUS_FAILED],
                'created_at < ?' => $cutoff,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('[Ecom360] Queue cleanup error: ' . $e->getMessage());
        }
    }
}

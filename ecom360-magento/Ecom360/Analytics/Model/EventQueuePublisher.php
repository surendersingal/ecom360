<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Model;

use Ecom360\Analytics\Model\ResourceModel\EventQueue as EventQueueResource;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Non-blocking event publisher — inserts into DB queue in <1ms.
 *
 * Observers should call this instead of ApiClient directly.
 * The cron consumer (ProcessEventQueueCron) reads and dispatches HTTP calls asynchronously.
 */
class EventQueuePublisher
{
    private EventQueueFactory $factory;
    private EventQueueResource $resource;
    private Json $json;
    private LoggerInterface $logger;

    public function __construct(
        EventQueueFactory $factory,
        EventQueueResource $resource,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->factory = $factory;
        $this->resource = $resource;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Queue a tracking event (will be sent to POST /api/v1/collect).
     *
     * @param string     $eventType  e.g. 'add_to_cart', 'purchase', 'login'
     * @param array      $metadata   Event metadata payload
     * @param array|null $customer   Customer identifier ['type'=>'email','value'=>'...']
     * @param int|null   $storeId    Store scope
     */
    public function publishEvent(string $eventType, array $metadata = [], ?array $customer = null, ?int $storeId = null): void
    {
        try {
            $item = $this->factory->create();
            $item->setData([
                'type'       => EventQueue::TYPE_EVENT,
                'event_type' => $eventType,
                'payload'    => $this->json->serialize([
                    'metadata' => $metadata,
                    'customer' => $customer,
                ]),
                'store_id'   => $storeId ?? 0,
                'status'     => EventQueue::STATUS_PENDING,
                'attempts'   => 0,
            ]);
            $this->resource->save($item);
        } catch (\Exception $e) {
            // NEVER let queue failures propagate to the storefront request
            $this->logger->warning('[Ecom360] Failed to queue event: ' . $e->getMessage());
        }
    }

    /**
     * Queue a server-to-server sync call (will be sent to POST /api/v1/sync/{entity}).
     *
     * @param string   $path     API path e.g. '/api/v1/sync/products'
     * @param array    $payload  Request body
     * @param int|null $storeId  Store scope
     */
    public function publishSync(string $path, array $payload, ?int $storeId = null): void
    {
        try {
            $item = $this->factory->create();
            $item->setData([
                'type'       => EventQueue::TYPE_SYNC,
                'event_type' => $path,
                'payload'    => $this->json->serialize($payload),
                'store_id'   => $storeId ?? 0,
                'status'     => EventQueue::STATUS_PENDING,
                'attempts'   => 0,
            ]);
            $this->resource->save($item);
        } catch (\Exception $e) {
            $this->logger->warning('[Ecom360] Failed to queue sync: ' . $e->getMessage());
        }
    }
}

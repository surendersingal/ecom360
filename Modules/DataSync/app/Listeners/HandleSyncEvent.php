<?php

declare(strict_types=1);

namespace Modules\DataSync\Listeners;

use App\Events\IntegrationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\DataSync\Jobs\ProcessSyncBatch;

/**
 * Listens on the cross-module IntegrationEvent bus for sync-related
 * events dispatched by other modules (or by external webhook handlers).
 *
 * Example: Another module could fire:
 *   IntegrationEvent::dispatch('datasync', 'ingest.products', $payload)
 */
final class HandleSyncEvent implements ShouldQueue
{
    public string $queue = 'datasync';

    public function handle(IntegrationEvent $event): void
    {
        // Only handle events targeting the datasync module.
        if ($event->moduleName !== 'datasync') {
            return;
        }

        // Route to the appropriate action based on event name.
        match (true) {
            str_starts_with($event->eventName, 'ingest.') => $this->handleIngest($event),
            default => Log::debug("DataSync: unhandled event {$event->eventName}"),
        };
    }

    /**
     * Handle ingest events dispatched by other modules.
     * e.g. eventName = 'ingest.products', payload has standard sync format.
     */
    private function handleIngest(IntegrationEvent $event): void
    {
        $entity   = str_replace('ingest.', '', $event->eventName);
        $tenantId = (int) ($event->payload['tenant_id'] ?? 0);

        if ($tenantId === 0) {
            Log::warning('DataSync: ingest event missing tenant_id', ['event' => $event->eventName]);
            return;
        }

        ProcessSyncBatch::dispatch($tenantId, $entity, $event->payload);
    }
}

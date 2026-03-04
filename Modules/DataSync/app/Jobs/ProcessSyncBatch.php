<?php

declare(strict_types=1);

namespace Modules\DataSync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\DataSync\Services\DataSyncService;

/**
 * Processes a sync batch asynchronously via Redis queue.
 *
 * This allows the sync API endpoint to return immediately while
 * the actual data processing happens in the background.
 */
final class ProcessSyncBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int    $tenantId,
        public readonly string $entity,
        public readonly array  $payload,
    ) {
        $this->onQueue('datasync');
    }

    public function handle(DataSyncService $service): void
    {
        try {
            $method = 'sync' . str_replace('_', '', ucwords($this->entity, '_'));

            if (!method_exists($service, $method)) {
                Log::warning("DataSync: unknown entity method {$method}");
                return;
            }

            $result = $service->{$method}($this->tenantId, $this->payload);

            Log::info("DataSync batch processed", [
                'tenant_id' => $this->tenantId,
                'entity'    => $this->entity,
                'created'   => $result['created'] ?? 0,
                'updated'   => $result['updated'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::error("DataSync batch failed", [
                'tenant_id' => $this->tenantId,
                'entity'    => $this->entity,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

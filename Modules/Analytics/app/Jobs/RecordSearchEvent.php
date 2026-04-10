<?php

declare(strict_types=1);

namespace Modules\Analytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\AiSearch\Models\SearchLog;

/**
 * Records a search event originating from the AiSearch module.
 *
 * Dispatched by EventBusRouter when AiSearch fires AiSearch::search.completed.
 *
 * Expected payload keys (all optional except tenant_id and query):
 *   tenant_id, query, query_type, session_id, visitor_id, customer_email,
 *   results_count, language, filters_applied, response_time_ms, metadata
 */
final class RecordSearchEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $tenantId = $this->payload['tenant_id'] ?? null;
        $query    = $this->payload['query'] ?? '';

        if (! $tenantId || $query === '') {
            Log::warning('[Analytics] RecordSearchEvent skipped — missing tenant_id or query.', $this->payload);
            return;
        }

        try {
            SearchLog::create([
                'tenant_id'        => $tenantId,
                'query'            => $query,
                'query_type'       => $this->payload['query_type'] ?? 'text',
                'session_id'       => $this->payload['session_id'] ?? null,
                'visitor_id'       => $this->payload['visitor_id'] ?? null,
                'customer_email'   => $this->payload['customer_email'] ?? null,
                'results_count'    => (int) ($this->payload['results_count'] ?? 0),
                'language'         => $this->payload['language'] ?? 'en',
                'filters_applied'  => $this->payload['filters_applied'] ?? [],
                'response_time_ms' => (int) ($this->payload['response_time_ms'] ?? 0),
                'metadata'         => $this->payload['metadata'] ?? null,
            ]);

            Log::info("[Analytics] Search event recorded for tenant {$tenantId}: \"{$query}\"");
        } catch (\Throwable $e) {
            Log::error('[Analytics] RecordSearchEvent failed to persist: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'query'     => $query,
            ]);

            // Re-throw so the queue will retry
            throw $e;
        }
    }
}

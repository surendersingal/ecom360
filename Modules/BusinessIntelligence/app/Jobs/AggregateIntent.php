<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Aggregates customer-intent data captured by the Chatbot module.
 *
 * Placeholder — replace the handle() body with real aggregation logic.
 */
final class AggregateIntent implements ShouldQueue
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
        $intent = $this->payload['intent'] ?? $this->payload['intent_name'] ?? 'unknown';
        $confidence = $this->payload['confidence'] ?? 0;

        if (!$tenantId) {
            Log::warning('[AggregateIntent] No tenant_id in payload.');
            return;
        }

        Log::info("[AggregateIntent] Tenant #{$tenantId}: intent={$intent}, confidence={$confidence}");

        try {
            \Illuminate\Support\Facades\DB::connection('mongodb')
                ->table('bi_intent_aggregates')
                ->insert([
                    'tenant_id' => (string) $tenantId,
                    'intent' => $intent,
                    'confidence' => (float) $confidence,
                    'session_id' => $this->payload['session_id'] ?? null,
                    'visitor_id' => $this->payload['visitor_id'] ?? null,
                    'metadata' => $this->payload,
                    'created_at' => now()->toIso8601String(),
                ]);
        } catch (\Throwable $e) {
            Log::error("[AggregateIntent] Failed to store: {$e->getMessage()}");
        }
    }
}

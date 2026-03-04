<?php

declare(strict_types=1);

namespace Modules\Marketing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Services\FlowExecutionService;

/**
 * Handles analytics events routed via the EventBus.
 *
 * Dispatched by the EventBusRouter when Analytics fires events like
 * rfm_segment_changed, audience_segment_entered/exited, report.generated.
 * Triggers flow enrollments and goal checks.
 */
final class HandleAnalyticsReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly array $payload,
    ) {
        $this->queue = 'event-bus';
    }

    public function handle(FlowExecutionService $flowService): void
    {
        $tenantId = $this->payload['tenant_id'] ?? null;
        $eventName = $this->payload['event_name'] ?? $this->payload['event'] ?? 'analytics_report';

        if (!$tenantId) {
            Log::warning('[HandleAnalyticsReport] No tenant_id in payload.');
            return;
        }

        Log::info("[HandleAnalyticsReport] Processing {$eventName} for tenant #{$tenantId}");

        // Trigger flow enrollments based on this analytics event
        $flowService->handleEventTrigger((string) $tenantId, $eventName, $this->payload);

        // Check if this event satisfies any active flow goals
        $email = $this->payload['email'] ?? $this->payload['customer_email'] ?? null;
        if ($email) {
            $contact = \Modules\Marketing\Models\Contact::where('tenant_id', $tenantId)
                ->where('email', $email)
                ->first();

            if ($contact) {
                $flowService->checkGoals($contact, $eventName, $this->payload);
            }
        }
    }
}

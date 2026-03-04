<?php

declare(strict_types=1);

namespace Modules\Analytics\Listeners;

use App\Events\IntegrationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Jobs\DispatchWebhookJob;
use Modules\Analytics\Models\TenantWebhook;
use Modules\Analytics\Services\IntentScoringService;
use Modules\Analytics\Services\LiveContextService;
use Modules\Analytics\Services\TrackingService;

/**
 * Listens for global IntegrationEvents destined for the Analytics module
 * and records them as tracking events in MongoDB via the TrackingService.
 *
 * Before persisting to MongoDB, it updates the Redis-backed Live Context
 * cache so the AI Search & Chatbot modules have microsecond access to the
 * shopper's current state.
 *
 * After persisting, it checks for active TenantWebhooks subscribed to
 * the event type and dispatches DispatchWebhookJob for each match.
 *
 * Runs on the Redis queue to keep ingestion fully asynchronous.
 */
final class RecordTrackingEvent implements ShouldQueue
{
    /**
     * The queue connection this listener runs on.
     */
    public string $connection = 'redis';

    /**
     * The queue this listener should be dispatched to.
     */
    public string $queue = 'analytics';

    public function __construct(
        private readonly TrackingService $trackingService,
        private readonly LiveContextService $liveContextService,
        private readonly IntentScoringService $intentScoringService,
    ) {}

    public function handle(IntegrationEvent $event): void
    {
        // Only process events targeted at the analytics module.
        if (strtolower($event->moduleName) !== 'analytics') {
            return;
        }

        $tenantId = $event->payload['tenant_id'] ?? null;

        if ($tenantId === null) {
            Log::warning('[Analytics] RecordTrackingEvent received payload without tenant_id.', $event->payload);
            return;
        }

        $payload   = $event->payload;
        $sessionId = $payload['session_id'] ?? null;
        $eventType = $payload['event_type'] ?? null;

        // ------------------------------------------------------------------
        //  Live Context — update Redis BEFORE the MongoDB write so the
        //  Chatbot / AI Search have near-instant context.
        // ------------------------------------------------------------------
        if ($sessionId !== null && $eventType !== null) {
            $this->updateLiveContext($sessionId, $eventType, $payload);
        }

        // ------------------------------------------------------------------
        //  Persist to MongoDB (long-term analytics store).
        // ------------------------------------------------------------------
        $trackingEvent = $this->trackingService->logEvent((string) $tenantId, $payload);

        // ------------------------------------------------------------------
        //  Intent Scoring — update the session's real-time intent score.
        // ------------------------------------------------------------------
        if ($sessionId !== null && $eventType !== null) {
            $this->intentScoringService->recordEvent($sessionId, $eventType);
        }

        // ------------------------------------------------------------------
        //  Webhook dispatch — fan out to subscribed tenant webhooks.
        // ------------------------------------------------------------------
        if ($eventType !== null) {
            $this->dispatchWebhooks((string) $tenantId, $eventType, array_merge(
                $payload,
                ['tracking_event_id' => (string) $trackingEvent->_id],
            ));
        }
    }

    // ------------------------------------------------------------------
    //  Webhook fan-out
    // ------------------------------------------------------------------

    /**
     * Find active TenantWebhooks subscribed to this event type and
     * dispatch a queued DispatchWebhookJob for each one.
     */
    private function dispatchWebhooks(string $tenantId, string $eventType, array $payload): void
    {
        $webhooks = TenantWebhook::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            /** @var TenantWebhook $webhook */
            $subscribedEvents = $webhook->subscribed_events ?? [];

            if (in_array($eventType, $subscribedEvents, true)) {
                DispatchWebhookJob::dispatch($webhook, $payload);
            }
        }
    }

    // ------------------------------------------------------------------
    //  Live Context helpers
    // ------------------------------------------------------------------

    private function updateLiveContext(string $sessionId, string $eventType, array $payload): void
    {
        match ($eventType) {
            'product_view' => $this->handleProductView($sessionId, $payload),
            'cart_update'  => $this->handleCartUpdate($sessionId, $payload),
            default        => null,
        };
    }

    private function handleProductView(string $sessionId, array $payload): void
    {
        $productId = $payload['metadata']['product_id']
            ?? $payload['custom_data']['product_id']
            ?? null;

        if ($productId !== null) {
            $this->liveContextService->updateCurrentPage($sessionId, (string) $productId);
        }
    }

    private function handleCartUpdate(string $sessionId, array $payload): void
    {
        $cartItems = $payload['metadata']['cart_items']
            ?? $payload['custom_data']['cart_items']
            ?? [];

        $cartTotal = (float) ($payload['metadata']['cart_total']
            ?? $payload['custom_data']['cart_total']
            ?? 0);

        $this->liveContextService->updateLiveCart($sessionId, $cartItems, $cartTotal);
    }
}

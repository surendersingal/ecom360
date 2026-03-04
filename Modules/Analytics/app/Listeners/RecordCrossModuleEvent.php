<?php

declare(strict_types=1);

namespace Modules\Analytics\Listeners;

use App\Events\IntegrationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\LiveContextService;

/**
 * Two-way Event Bus listener — Analytics tracks what the other modules do.
 *
 * Listened events:
 *   • AiSearch::ai_search_executed    → persist search_event to MongoDB
 *   • Chatbot::chat_session_ended     → persist chat_event to MongoDB
 *   • Marketing::campaign_message_sent → persist campaign_event to MongoDB
 *
 * This gives the BI module pre-aggregated data for dashboards like
 * "Zero Result Searches", "Chatbot ROI", and "Campaign Performance".
 */
final class RecordCrossModuleEvent implements ShouldQueue
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
        private readonly LiveContextService $liveContextService,
    ) {}

    public function handle(IntegrationEvent $event): void
    {
        $routeKey = "{$event->moduleName}::{$event->eventName}";

        match ($routeKey) {
            'AiSearch::ai_search_executed'       => $this->handleAiSearch($event),
            'Chatbot::chat_session_ended'        => $this->handleChatSessionEnded($event),
            'Marketing::campaign_message_sent'   => $this->handleCampaignSent($event),
            default                              => null, // Not our concern.
        };
    }

    // ------------------------------------------------------------------
    //  AI Search  →  Analytics
    // ------------------------------------------------------------------

    /**
     * Record a search event in MongoDB so the BI module can identify
     * "Zero Result Searches" (products customers want that the store
     * doesn't sell).
     *
     * Expected payload keys: tenant_id, session_id, search_query, results_count
     */
    private function handleAiSearch(IntegrationEvent $event): void
    {
        $payload = $event->payload;

        $tenantId = $payload['tenant_id'] ?? null;
        if ($tenantId === null) {
            Log::warning('[Analytics] ai_search_executed missing tenant_id.', $payload);
            return;
        }

        TrackingEvent::create([
            'tenant_id'   => (string) $tenantId,
            'session_id'  => $payload['session_id'] ?? 'unknown',
            'event_type'  => 'search_event',
            'url'         => $payload['url'] ?? '',
            'metadata'    => [
                'search_query'  => $payload['search_query'] ?? '',
                'results_count' => (int) ($payload['results_count'] ?? 0),
            ],
            'custom_data' => $payload['custom_data'] ?? [],
            'ip_address'  => $payload['ip_address'] ?? '',
            'user_agent'  => $payload['user_agent'] ?? '',
        ]);

        // Store attribution so purchases within 24h get credited to AI Search.
        $sessionId = $payload['session_id'] ?? null;
        if ($sessionId !== null) {
            $this->liveContextService->recordAttribution(
                $sessionId,
                'ai_search',
                $payload['search_query'] ?? '',
            );
        }

        Log::debug("[Analytics] Recorded ai_search_executed for tenant [{$tenantId}].");
    }

    // ------------------------------------------------------------------
    //  Chatbot  →  Analytics
    // ------------------------------------------------------------------

    /**
     * Record a chat session event in MongoDB to track Chatbot ROI.
     *
     * Expected payload keys: tenant_id, session_id, resolution_status (resolved|escalated|abandoned)
     */
    private function handleChatSessionEnded(IntegrationEvent $event): void
    {
        $payload = $event->payload;

        $tenantId = $payload['tenant_id'] ?? null;
        if ($tenantId === null) {
            Log::warning('[Analytics] chat_session_ended missing tenant_id.', $payload);
            return;
        }

        TrackingEvent::create([
            'tenant_id'   => (string) $tenantId,
            'session_id'  => $payload['session_id'] ?? 'unknown',
            'event_type'  => 'chat_event',
            'url'         => $payload['url'] ?? '',
            'metadata'    => [
                'resolution_status' => $payload['resolution_status'] ?? 'unknown',
                'duration_seconds'  => (int) ($payload['duration_seconds'] ?? 0),
            ],
            'custom_data' => $payload['custom_data'] ?? [],
            'ip_address'  => $payload['ip_address'] ?? '',
            'user_agent'  => $payload['user_agent'] ?? '',
        ]);

        Log::debug("[Analytics] Recorded chat_session_ended for tenant [{$tenantId}].");
    }

    // ------------------------------------------------------------------
    //  Marketing  →  Analytics
    // ------------------------------------------------------------------

    /**
     * Record a campaign message event in MongoDB.
     *
     * Expected payload keys: tenant_id, campaign_id, channel (email|sms|whatsapp)
     */
    private function handleCampaignSent(IntegrationEvent $event): void
    {
        $payload = $event->payload;

        $tenantId = $payload['tenant_id'] ?? null;
        if ($tenantId === null) {
            Log::warning('[Analytics] campaign_message_sent missing tenant_id.', $payload);
            return;
        }

        $sessionId = $payload['session_id'] ?? 'unknown';

        TrackingEvent::create([
            'tenant_id'   => (string) $tenantId,
            'session_id'  => $sessionId,
            'event_type'  => 'campaign_event',
            'url'         => $payload['url'] ?? '',
            'metadata'    => [
                'campaign_id' => $payload['campaign_id'] ?? '',
                'channel'     => $payload['channel'] ?? '',
            ],
            'custom_data' => $payload['custom_data'] ?? [],
            'ip_address'  => $payload['ip_address'] ?? '',
            'user_agent'  => $payload['user_agent'] ?? '',
        ]);

        // Store attribution for 24h so purchases can be credited to the campaign.
        if ($sessionId !== 'unknown') {
            $this->liveContextService->recordAttribution(
                $sessionId,
                'campaign',
                $payload['campaign_id'] ?? '',
            );
        }

        Log::debug("[Analytics] Recorded campaign_message_sent for tenant [{$tenantId}].");
    }
}

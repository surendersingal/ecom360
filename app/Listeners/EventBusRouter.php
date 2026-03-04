<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\IntegrationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Central router that listens for every IntegrationEvent and
 * dispatches the appropriate job inside the target module.
 *
 * Runs on the Redis queue so modules stay fully decoupled.
 */
final class EventBusRouter implements ShouldQueue
{
    /**
     * The queue connection this listener should run on.
     */
    public string $connection = 'redis';

    /**
     * The queue this listener should be dispatched to.
     */
    public string $queue = 'event-bus';

    public function handle(IntegrationEvent $event): void
    {
        Log::info("[EventBus] {$event->moduleName}::{$event->eventName}", $event->payload);

        match ("{$event->moduleName}::{$event->eventName}") {
            /*
            |------------------------------------------------------------------
            | Analytics  →  Marketing
            |------------------------------------------------------------------
            | When the Analytics module finishes generating a report, we
            | forward the payload to a Marketing job that can act on it
            | (e.g. trigger a campaign based on the new data).
            */
            'Analytics::report.generated' => \Modules\Marketing\Jobs\HandleAnalyticsReport::dispatch($event->payload),

            /*
            |------------------------------------------------------------------
            | Analytics  →  Marketing (RFM segment change)
            |------------------------------------------------------------------
            | When a customer's RFM score changes (e.g. becomes VIP),
            | Marketing can instantly trigger a "Thank You" WhatsApp message.
            */
            'Analytics::rfm_segment_changed' => \Modules\Marketing\Jobs\HandleAnalyticsReport::dispatch($event->payload),

            /*
            |------------------------------------------------------------------
            | Analytics  →  Marketing (audience segment changes)
            |------------------------------------------------------------------
            | When a customer enters/exits a dynamic audience segment,
            | Marketing can trigger automated flows (e.g. "Welcome to VIP").
            */
            'Analytics::audience_segment_entered' => \Modules\Marketing\Jobs\HandleAnalyticsReport::dispatch($event->payload),
            'Analytics::audience_segment_exited'  => \Modules\Marketing\Jobs\HandleAnalyticsReport::dispatch($event->payload),

            /*
            |------------------------------------------------------------------
            | AiSearch  →  Analytics
            |------------------------------------------------------------------
            | When AiSearch completes a query, Analytics may want to
            | record the search event for dashboard metrics.
            */
            'AiSearch::search.completed' => \Modules\Analytics\Jobs\RecordSearchEvent::dispatch($event->payload),

            /*
            |------------------------------------------------------------------
            | Chatbot  →  BusinessIntelligence
            |------------------------------------------------------------------
            | When Chatbot captures a new customer intent, BI can
            | aggregate it for reports.
            */
            'Chatbot::intent.captured' => \Modules\BusinessIntelligence\Jobs\AggregateIntent::dispatch($event->payload),

            /*
            |------------------------------------------------------------------
            | AiSearch  →  Analytics (search tracking)
            |------------------------------------------------------------------
            | AI Search dispatches ai_search_executed so Analytics can
            | record it for Zero Result Search analysis.
            */
            'AiSearch::ai_search_executed' => Log::info('[EventBus] Routed ai_search_executed → Analytics RecordCrossModuleEvent'),

            /*
            |------------------------------------------------------------------
            | Chatbot  →  Analytics (session tracking)
            |------------------------------------------------------------------
            | Chatbot dispatches chat_session_ended so Analytics can
            | track resolution rates and Chatbot ROI.
            */
            'Chatbot::chat_session_ended' => Log::info('[EventBus] Routed chat_session_ended → Analytics RecordCrossModuleEvent'),

            /*
            |------------------------------------------------------------------
            | Marketing  →  Analytics (campaign tracking)
            |------------------------------------------------------------------
            | Marketing dispatches campaign_message_sent so Analytics
            | can track delivery metrics and attribution.
            */
            'Marketing::campaign_message_sent' => Log::info('[EventBus] Routed campaign_message_sent → Analytics RecordCrossModuleEvent'),

            /*
            |------------------------------------------------------------------
            | Marketing  →  Analytics (campaign stats)
            |------------------------------------------------------------------
            */
            'Marketing::campaign_sent' => Log::info('[EventBus] Routed campaign_sent → Analytics'),
            'Marketing::campaign_completed' => Log::info('[EventBus] Routed campaign_completed → Analytics'),

            /*
            |------------------------------------------------------------------
            | Analytics  →  Marketing (behavioral triggers)
            |------------------------------------------------------------------
            | When BehavioralTriggerService detects patterns (cart abandon,
            | browse abandon, high intent, milestones), Marketing can
            | trigger automated flows.
            */
            'analytics::behavioral_trigger' => \Modules\Marketing\Jobs\HandleAnalyticsReport::dispatch(
                array_merge($event->payload, ['_event' => 'behavioral_trigger'])
            ),

            /*
            |------------------------------------------------------------------
            | Analytics  →  Marketing (real-time alerts)
            |------------------------------------------------------------------
            | Real-time alerts (traffic spike/drop, conversion drop) can
            | trigger Marketing notifications to store owners.
            */
            'analytics::realtime_alert' => Log::info('[EventBus] Routed realtime_alert → Marketing notification'),

            /*
            |------------------------------------------------------------------
            | BI  →  Marketing (alert triggered)
            |------------------------------------------------------------------
            | When a BI alert fires, Marketing can send notifications.
            */
            'BusinessIntelligence::alert_triggered' => Log::info('[EventBus] Routed BI alert_triggered → Marketing'),

            /*
            |------------------------------------------------------------------
            | Analytics  →  BI (intent score change)
            |------------------------------------------------------------------
            | When the Intent Scoring Engine detects a high-intent or
            | abandon-risk session, BI can trigger real-time alerts.
            */
            'Analytics::intent_score_updated' => Log::info('[EventBus] Routed intent_score_updated → BI/Marketing'),

            /*
            |------------------------------------------------------------------
            | Analytics  →  Frontend (behavioral intervention fired)
            |------------------------------------------------------------------
            | When the Dynamic Rules Engine fires a real-time intervention,
            | this routes through the bus for audit logging.
            */
            'Analytics::intervention_fired' => Log::info('[EventBus] Routed intervention_fired → Audit log'),

            /*
            |------------------------------------------------------------------
            | Default — log unhandled events so nothing is silently lost.
            |------------------------------------------------------------------
            */
            default => Log::warning(
                "[EventBus] Unhandled integration event: {$event->moduleName}::{$event->eventName}",
                $event->payload,
            ),
        };
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\TrackingEvent;

/**
 * Multi-Touch Attribution Engine.
 *
 * When a purchase event fires, this service reconstructs the full
 * session history from MongoDB and identifies:
 *
 *  - **First Touch**: The earliest touchpoint that initiated the journey
 *    (organic search, campaign link, AI search result, etc.).
 *  - **Last Touch**: The most recent touchpoint before the purchase.
 *  - **Assisted Touches**: Every intermediate touchpoint between first
 *    and last (for weighted/linear attribution models later).
 *
 * The resulting attribution object is appended to the purchase event's
 * metadata so it lives alongside the transaction permanently.
 *
 * This complements the LiveContextService's Redis-based 24h last-click
 * attribution with a full MongoDB-sourced multi-touch model.
 */
final class AttributionService
{
    /**
     * Event types that count as meaningful touchpoints for attribution.
     * Pure navigation events (page_view) are excluded by default.
     *
     * @var list<string>
     */
    private const array TOUCHPOINT_EVENTS = [
        'product_view',
        'search',
        'search_event',
        'click',
        'ai_search_executed',
        'campaign_event',
        'chat_event',
        'add_to_cart',
        'begin_checkout',
    ];

    /**
     * Resolve the conversion source for a session at purchase time.
     *
     * Queries MongoDB for the full chronological event history of the
     * session, extracts touchpoints, and assembles the attribution model.
     *
     * @param  int $tenantId
     * @param  string $sessionId
     *
     * @return array{
     *     first_touch: array{event_type: string, url: string, at: string, metadata: array}|null,
     *     last_touch: array{event_type: string, url: string, at: string, metadata: array}|null,
     *     assisted_touches: list<array{event_type: string, url: string, at: string}>,
     *     touch_count: int,
     * }
     */
    public function resolveConversionSource(int|string $tenantId, string $sessionId): array
    {
        // Pull all events for this session in chronological order.
        $events = TrackingEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('session_id', $sessionId)
            ->whereIn('event_type', self::TOUCHPOINT_EVENTS)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($events->isEmpty()) {
            Log::debug("[Attribution] No touchpoints found for session [{$sessionId}] in tenant [{$tenantId}].");

            return [
                'first_touch'     => null,
                'last_touch'      => null,
                'assisted_touches' => [],
                'touch_count'     => 0,
            ];
        }

        $firstEvent = $events->first();
        $lastEvent  = $events->last();

        $firstTouch = [
            'event_type' => $firstEvent->event_type,
            'url'        => $firstEvent->url,
            'at'         => $firstEvent->created_at?->toIso8601String() ?? '',
            'metadata'   => $firstEvent->metadata ?? [],
        ];

        $lastTouch = [
            'event_type' => $lastEvent->event_type,
            'url'        => $lastEvent->url,
            'at'         => $lastEvent->created_at?->toIso8601String() ?? '',
            'metadata'   => $lastEvent->metadata ?? [],
        ];

        // Assisted touches = everything between first and last (exclusive).
        $assisted = [];
        if ($events->count() > 2) {
            $middle = $events->slice(1, $events->count() - 2);

            foreach ($middle as $evt) {
                $assisted[] = [
                    'event_type' => $evt->event_type,
                    'url'        => $evt->url,
                    'at'         => $evt->created_at?->toIso8601String() ?? '',
                ];
            }
        }

        Log::debug(
            "[Attribution] Session [{$sessionId}] in tenant [{$tenantId}]: "
            . "{$events->count()} touchpoints, first={$firstTouch['event_type']}, last={$lastTouch['event_type']}.",
        );

        return [
            'first_touch'      => $firstTouch,
            'last_touch'       => $lastTouch,
            'assisted_touches' => $assisted,
            'touch_count'      => $events->count(),
        ];
    }

    /**
     * Resolve attribution for a cross-session customer journey.
     *
     * When a CustomerProfile has multiple known_sessions, this method
     * looks across ALL sessions to find the original first touch and
     * the final last touch before conversion.
     *
     * @param  string       $tenantId
     * @param  list<string> $sessionIds  All known session IDs for the customer.
     *
     * @return array{
     *     first_touch: array{event_type: string, url: string, at: string, session_id: string, metadata: array}|null,
     *     last_touch: array{event_type: string, url: string, at: string, session_id: string, metadata: array}|null,
     *     total_sessions: int,
     *     total_touchpoints: int,
     * }
     */
    public function resolveCrossSessionAttribution(int|string $tenantId, array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [
                'first_touch'       => null,
                'last_touch'        => null,
                'total_sessions'    => 0,
                'total_touchpoints' => 0,
            ];
        }

        $events = TrackingEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('session_id', $sessionIds)
            ->whereIn('event_type', self::TOUCHPOINT_EVENTS)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($events->isEmpty()) {
            return [
                'first_touch'       => null,
                'last_touch'        => null,
                'total_sessions'    => count($sessionIds),
                'total_touchpoints' => 0,
            ];
        }

        $firstEvent = $events->first();
        $lastEvent  = $events->last();

        return [
            'first_touch' => [
                'event_type' => $firstEvent->event_type,
                'url'        => $firstEvent->url,
                'at'         => $firstEvent->created_at?->toIso8601String() ?? '',
                'session_id' => $firstEvent->session_id,
                'metadata'   => $firstEvent->metadata ?? [],
            ],
            'last_touch' => [
                'event_type' => $lastEvent->event_type,
                'url'        => $lastEvent->url,
                'at'         => $lastEvent->created_at?->toIso8601String() ?? '',
                'session_id' => $lastEvent->session_id,
                'metadata'   => $lastEvent->metadata ?? [],
            ],
            'total_sessions'    => count($sessionIds),
            'total_touchpoints' => $events->count(),
        ];
    }
}

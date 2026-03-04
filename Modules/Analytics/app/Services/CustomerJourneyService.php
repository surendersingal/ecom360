<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Customer Journey Mapping Service.
 *
 * Reconstructs the full journey of a customer across all sessions,
 * touchpoints, channels, and devices — from first visit to latest activity.
 *
 * Provides:
 *   - Chronological timeline of every interaction
 *   - Journey phase detection (awareness → consideration → purchase → retention)
 *   - Cross-device stitching via identity resolution
 *   - Channel transition analysis
 *   - Time-to-conversion metrics
 *   - Drop-off point identification
 */
final class CustomerJourneyService
{
    /**
     * Get the complete journey for a single customer.
     */
    public function getJourney(int|string $tenantId, string $visitorId): array
    {
        $events = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('visitor_id', $visitorId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($e) => (array) $e)
            ->all();

        if (empty($events)) return ['visitor_id' => $visitorId, 'journey' => []];

        $profile = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tenantId)
            ->where('visitor_id', $visitorId)
            ->first();

        $sessions = $this->groupBySessions($events);
        $timeline = $this->buildTimeline($events);
        $phases = $this->detectPhases($sessions);
        $channels = $this->extractChannelTransitions($sessions);

        $firstEvent = $events[0];
        $lastEvent = end($events);

        return [
            'visitor_id' => $visitorId,
            'profile' => $profile ? (array) $profile : null,
            'total_events' => count($events),
            'total_sessions' => count($sessions),
            'first_seen' => $firstEvent['created_at'] ?? null,
            'last_seen' => $lastEvent['created_at'] ?? null,
            'journey_duration_days' => $this->daysBetween($firstEvent['created_at'] ?? '', $lastEvent['created_at'] ?? ''),
            'has_converted' => $this->hasConverted($events),
            'time_to_first_conversion' => $this->timeToFirstConversion($events),
            'phases' => $phases,
            'channel_transitions' => $channels,
            'sessions' => array_map(fn($s) => $this->summarizeSession($s), $sessions),
            'timeline' => $timeline,
            'devices' => $this->extractDevices($events),
        ];
    }

    /**
     * Get aggregated journey patterns across all customers.
     */
    public function getJourneyPatterns(int|string $tenantId, int $limit = 100): array
    {
        // Sample recent converting customers
        $converters = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'purchase')
            ->where('created_at', '>=', now()->subDays(30)->toIso8601String())
            ->distinct('visitor_id')
            ->limit($limit)
            ->pluck('visitor_id')
            ->all();

        $patterns = [
            'avg_sessions_to_convert' => 0,
            'avg_time_to_convert_hours' => 0,
            'common_first_touchpoints' => [],
            'common_last_touchpoints_before_purchase' => [],
            'avg_events_per_journey' => 0,
        ];

        if (empty($converters)) return $patterns;

        $totalSessions = 0;
        $totalTimeHours = 0;
        $totalEvents = 0;
        $firstTouchpoints = [];
        $lastTouchpoints = [];

        foreach ($converters as $vid) {
            $events = DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tenantId)
                ->where('visitor_id', $vid)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($e) => (array) $e)
                ->all();

            if (empty($events)) continue;

            $sessions = $this->groupBySessions($events);
            $totalSessions += count($sessions);
            $totalEvents += count($events);

            $ttc = $this->timeToFirstConversion($events);
            if ($ttc) $totalTimeHours += $ttc;

            // First event type
            $firstType = $events[0]['event_type'] ?? 'unknown';
            $firstTouchpoints[$firstType] = ($firstTouchpoints[$firstType] ?? 0) + 1;

            // Last event before purchase
            $purchaseIdx = null;
            foreach ($events as $idx => $e) {
                if ($e['event_type'] === 'purchase') {
                    $purchaseIdx = $idx;
                    break;
                }
            }
            if ($purchaseIdx && $purchaseIdx > 0) {
                $lastType = $events[$purchaseIdx - 1]['event_type'] ?? 'unknown';
                $lastTouchpoints[$lastType] = ($lastTouchpoints[$lastType] ?? 0) + 1;
            }
        }

        $n = count($converters);
        arsort($firstTouchpoints);
        arsort($lastTouchpoints);

        return [
            'sample_size' => $n,
            'avg_sessions_to_convert' => round($totalSessions / $n, 1),
            'avg_time_to_convert_hours' => round($totalTimeHours / $n, 1),
            'avg_events_per_journey' => round($totalEvents / $n, 1),
            'common_first_touchpoints' => array_slice($firstTouchpoints, 0, 5, true),
            'common_last_touchpoints_before_purchase' => array_slice($lastTouchpoints, 0, 5, true),
        ];
    }

    /**
     * Identify common drop-off points for non-converting visitors.
     */
    public function getDropOffPoints(int|string $tenantId): array
    {
        // Get visitors who had add_to_cart but no purchase (last 30 days)
        $cartSessions = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tenantId) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tenantId,
                        'event_type' => 'add_to_cart',
                        'created_at' => ['$gte' => now()->subDays(30)->toIso8601String()],
                    ]],
                    ['$group' => ['_id' => '$session_id']],
                    ['$limit' => 500],
                ])->toArray();
            });

        $cartSessionIds = array_column($cartSessions, '_id');

        if (empty($cartSessionIds)) return ['drop_off_points' => []];

        $purchaseSessions = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'purchase')
            ->whereIn('session_id', $cartSessionIds)
            ->distinct('session_id')
            ->pluck('session_id')
            ->all();

        $abandonedIds = array_diff($cartSessionIds, $purchaseSessions);
        if (empty($abandonedIds)) return ['drop_off_points' => []];

        // Find last event type for abandoned sessions
        $lastEvents = [];
        foreach (array_slice($abandonedIds, 0, 100) as $sid) {
            $lastEvent = DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tenantId)
                ->where('session_id', $sid)
                ->orderBy('created_at', 'desc')
                ->first();
            if ($lastEvent) {
                $type = ((array) $lastEvent)['event_type'] ?? 'unknown';
                $lastEvents[$type] = ($lastEvents[$type] ?? 0) + 1;
            }
        }

        arsort($lastEvents);

        return [
            'total_abandoned_sessions' => count($abandonedIds),
            'sample_size' => min(100, count($abandonedIds)),
            'drop_off_points' => array_map(fn($count, $type) => [
                'last_event_type' => $type,
                'count' => $count,
                'percent' => round(($count / min(100, count($abandonedIds))) * 100, 1),
            ], $lastEvents, array_keys($lastEvents)),
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function groupBySessions(array $events): array
    {
        $sessions = [];
        foreach ($events as $e) {
            $sid = $e['session_id'] ?? 'unknown';
            $sessions[$sid][] = $e;
        }
        return $sessions;
    }

    private function buildTimeline(array $events): array
    {
        return array_map(fn($e) => [
            'timestamp' => $e['created_at'] ?? null,
            'event_type' => $e['event_type'] ?? 'unknown',
            'session_id' => $e['session_id'] ?? null,
            'channel' => $e['metadata']['channel'] ?? $e['utm_source'] ?? null,
            'page' => $e['page_url'] ?? $e['metadata']['page'] ?? null,
            'product' => $e['metadata']['product_name'] ?? $e['metadata']['product_id'] ?? null,
            'revenue' => isset($e['metadata']['revenue']) ? (float) $e['metadata']['revenue'] : null,
        ], array_slice($events, 0, 500)); // Cap at 500 for response size
    }

    private function detectPhases(array $sessions): array
    {
        $phases = [];
        $seenPurchase = false;

        foreach ($sessions as $sid => $events) {
            $types = array_column($events, 'event_type');

            if (!$seenPurchase) {
                if (in_array('purchase', $types)) {
                    $phases[] = ['session' => $sid, 'phase' => 'conversion', 'events' => count($events)];
                    $seenPurchase = true;
                } elseif (in_array('add_to_cart', $types) || in_array('checkout', $types)) {
                    $phases[] = ['session' => $sid, 'phase' => 'consideration', 'events' => count($events)];
                } elseif (in_array('product_view', $types)) {
                    $phases[] = ['session' => $sid, 'phase' => 'interest', 'events' => count($events)];
                } else {
                    $phases[] = ['session' => $sid, 'phase' => 'awareness', 'events' => count($events)];
                }
            } else {
                if (in_array('purchase', $types)) {
                    $phases[] = ['session' => $sid, 'phase' => 'repeat_purchase', 'events' => count($events)];
                } else {
                    $phases[] = ['session' => $sid, 'phase' => 'retention', 'events' => count($events)];
                }
            }
        }

        return $phases;
    }

    private function extractChannelTransitions(array $sessions): array
    {
        $transitions = [];
        $prevChannel = null;

        foreach ($sessions as $events) {
            $firstEvent = $events[0] ?? [];
            $channel = $firstEvent['utm_source'] ?? $firstEvent['metadata']['channel'] ?? 'direct';

            if ($prevChannel !== null && $prevChannel !== $channel) {
                $key = "{$prevChannel} → {$channel}";
                $transitions[$key] = ($transitions[$key] ?? 0) + 1;
            }
            $prevChannel = $channel;
        }

        arsort($transitions);
        return $transitions;
    }

    private function summarizeSession(array $events): array
    {
        $first = $events[0] ?? [];
        $last = end($events);
        $types = array_count_values(array_column($events, 'event_type'));

        return [
            'session_id' => $first['session_id'] ?? null,
            'started_at' => $first['created_at'] ?? null,
            'ended_at' => $last['created_at'] ?? null,
            'event_count' => count($events),
            'event_types' => $types,
            'channel' => $first['utm_source'] ?? $first['metadata']['channel'] ?? 'direct',
            'device' => $first['device_type'] ?? 'unknown',
            'has_purchase' => in_array('purchase', array_column($events, 'event_type')),
        ];
    }

    private function extractDevices(array $events): array
    {
        $devices = [];
        foreach ($events as $e) {
            $d = $e['device_type'] ?? 'unknown';
            $devices[$d] = ($devices[$d] ?? 0) + 1;
        }
        arsort($devices);
        return $devices;
    }

    private function hasConverted(array $events): bool
    {
        return in_array('purchase', array_column($events, 'event_type'));
    }

    private function timeToFirstConversion(array $events): ?float
    {
        $firstEvent = $events[0] ?? null;
        if (!$firstEvent) return null;

        foreach ($events as $e) {
            if ($e['event_type'] === 'purchase') {
                return round($this->hoursBetween($firstEvent['created_at'] ?? '', $e['created_at'] ?? ''), 1);
            }
        }
        return null;
    }

    private function daysBetween(string $a, string $b): int
    {
        try { return (int) now()->parse($a)->diffInDays(now()->parse($b)); } catch (\Throwable) { return 0; }
    }

    private function hoursBetween(string $a, string $b): float
    {
        try { return now()->parse($a)->diffInMinutes(now()->parse($b)) / 60; } catch (\Throwable) { return 0; }
    }
}

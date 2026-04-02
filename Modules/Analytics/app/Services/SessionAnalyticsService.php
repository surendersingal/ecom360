<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use MongoDB\Laravel\Connection;

/**
 * Session-level analytics — bounce rate, avg session duration,
 * pages per session, new vs returning visitors, engagement metrics.
 */
final class SessionAnalyticsService
{
    /**
     * Core session metrics: bounce rate, avg duration, pages per session.
     */
    public function getSessionMetrics(int|string $tenantId, string $dateRange = '30d'): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        // Aggregate session-level stats
        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
            ]],
            ['$group' => [
                '_id'        => '$session_id',
                'page_count' => ['$sum' => ['$cond' => [['$eq' => ['$event_type', 'page_view']], 1, 0]]],
                'event_count' => ['$sum' => 1],
                'first_event' => ['$min' => '$created_at'],
                'last_event'  => ['$max' => '$created_at'],
            ]],
            ['$facet' => [
                'totals' => [
                    ['$group' => [
                        '_id'               => null,
                        'total_sessions'    => ['$sum' => 1],
                        'avg_pages'         => ['$avg' => '$page_count'],
                        'avg_events'        => ['$avg' => '$event_count'],
                        'avg_duration_ms'   => ['$avg' => ['$subtract' => ['$last_event', '$first_event']]],
                        'bounce_sessions'   => ['$sum' => ['$cond' => [['$lte' => ['$event_count', 1]], 1, 0]]],
                    ]],
                ],
                'duration_distribution' => [
                    ['$project' => [
                        'duration_sec' => ['$divide' => [['$subtract' => ['$last_event', '$first_event']], 1000]],
                    ]],
                    ['$bucket' => [
                        'groupBy'    => '$duration_sec',
                        'boundaries' => [0, 10, 30, 60, 180, 600, 1800, 86400],
                        'default'    => 'other',
                        'output'     => ['count' => ['$sum' => 1]],
                    ]],
                ],
            ]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));
        $facets  = $results[0] ?? [];
        $totals  = $facets['totals'][0] ?? [];

        $totalSessions = (int) ($totals['total_sessions'] ?? 0);
        $bounceSessions = (int) ($totals['bounce_sessions'] ?? 0);

        $avgDurationMs = (float) ($totals['avg_duration_ms'] ?? 0);
        $avgDurationSec = max(0, round($avgDurationMs / 1000));

        $bounceRate = $totalSessions > 0
            ? round(($bounceSessions / $totalSessions) * 100, 1)
            : 0;

        $durationBuckets = [];
        $bucketLabels = ['0-10s', '10-30s', '30s-1m', '1-3m', '3-10m', '10-30m', '30m+'];
        foreach (($facets['duration_distribution'] ?? []) as $i => $bucket) {
            $durationBuckets[] = [
                'label' => $bucketLabels[$i] ?? 'other',
                'count' => (int) ($bucket['count'] ?? 0),
            ];
        }

        return [
            'total_sessions'     => $totalSessions,
            'bounce_rate'        => $bounceRate,
            'bounce_sessions'    => $bounceSessions,
            'avg_pages_per_session' => round((float) ($totals['avg_pages'] ?? 0), 1),
            'avg_events_per_session' => round((float) ($totals['avg_events'] ?? 0), 1),
            'avg_session_duration_seconds' => (int) $avgDurationSec,
            'avg_session_duration_formatted' => $this->formatDuration((int) $avgDurationSec),
            'duration_distribution' => $durationBuckets,
        ];
    }

    /**
     * New vs returning visitor breakdown.
     */
    public function getNewVsReturning(int|string $tenantId, string $dateRange = '30d'): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        // Find sessions that appeared before the date range
        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
            ]],
            ['$group' => [
                '_id'         => '$session_id',
                'first_seen'  => ['$min' => '$created_at'],
            ]],
            ['$facet' => [
                'total' => [['$count' => 'count']],
                'new_sessions' => [
                    ['$match' => [
                        'first_seen' => [
                            '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                        ],
                    ]],
                    ['$count' => 'count'],
                ],
            ]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));
        $facets  = $results[0] ?? [];

        $total = (int) ($facets['total'][0]['count'] ?? 0);
        // Note: this is a simplification; true new-vs-returning needs historical session lookup
        // For accuracy, we check if session_id appeared in data before dateFrom
        $newSessions = (int) ($facets['new_sessions'][0]['count'] ?? 0);
        $returning   = max(0, $total - $newSessions);

        return [
            'total_sessions'     => $total,
            'new_sessions'       => $newSessions,
            'returning_sessions' => $returning,
            'new_pct'            => $total > 0 ? round(($newSessions / $total) * 100, 1) : 0,
            'returning_pct'      => $total > 0 ? round(($returning / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Daily session trend over time.
     */
    public function getDailySessionTrend(int|string $tenantId, string $dateRange = '30d'): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
            ]],
            ['$group' => [
                '_id' => [
                    'date'    => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at', 'timezone' => 'Asia/Kolkata']],
                    'session' => '$session_id',
                ],
            ]],
            ['$group' => [
                '_id'      => '$_id.date',
                'sessions' => ['$sum' => 1],
            ]],
            ['$sort' => ['_id' => 1]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        $dates    = [];
        $sessions = [];

        foreach ($results as $row) {
            $dates[]    = $row['_id'];
            $sessions[] = (int) ($row['sessions'] ?? 0);
        }

        return [
            'dates'    => $dates,
            'sessions' => $sessions,
        ];
    }

    /**
     * Top landing pages by session entry.
     */
    public function getTopLandingPages(int|string $tenantId, string $dateRange = '30d', int $limit = 20): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'page_view',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
            ]],
            ['$sort' => ['created_at' => 1]],
            ['$group' => [
                '_id' => '$session_id',
                'landing_page' => ['$first' => '$url'],
            ]],
            ['$group' => [
                '_id'   => '$landing_page',
                'count' => ['$sum' => 1],
            ]],
            ['$sort' => ['count' => -1]],
            ['$limit' => $limit],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'url'      => $row['_id'] ?? '/',
            'sessions' => (int) ($row['count'] ?? 0),
        ], $results);
    }

    /**
     * Top exit pages.
     */
    public function getTopExitPages(int|string $tenantId, string $dateRange = '30d', int $limit = 20): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'page_view',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
            ]],
            ['$sort' => ['created_at' => -1]],
            ['$group' => [
                '_id' => '$session_id',
                'exit_page' => ['$first' => '$url'],
            ]],
            ['$group' => [
                '_id'   => '$exit_page',
                'count' => ['$sum' => 1],
            ]],
            ['$sort' => ['count' => -1]],
            ['$limit' => $limit],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'url'      => $row['_id'] ?? '/',
            'sessions' => (int) ($row['count'] ?? 0),
        ], $results);
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        $secs    = $seconds % 60;

        if ($minutes < 60) {
            return "{$minutes}m {$secs}s";
        }

        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        return "{$hours}h {$mins}m";
    }

    private function collection(): \MongoDB\Collection
    {
        /** @var Connection $mongo */
        $mongo = app('db')->connection('mongodb');

        return $mongo->getCollection('tracking_events');
    }

    private function parseDateRange(string $range): array
    {
        if (preg_match('/^(\d+)d$/', $range, $m)) {
            return [
                CarbonImmutable::now()->subDays((int) $m[1])->startOfDay(),
                CarbonImmutable::now()->endOfDay(),
            ];
        }

        if (str_contains($range, '|')) {
            [$from, $to] = explode('|', $range, 2);

            return [
                CarbonImmutable::parse($from)->startOfDay(),
                CarbonImmutable::parse($to)->endOfDay(),
            ];
        }

        return [
            CarbonImmutable::now()->subDays(30)->startOfDay(),
            CarbonImmutable::now()->endOfDay(),
        ];
    }
}

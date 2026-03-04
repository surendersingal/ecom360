<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use MongoDB\Laravel\Connection;

/**
 * Campaign & UTM analytics — performance tracking for marketing campaigns,
 * UTM parameter analysis, channel attribution, and conversion tracking.
 */
final class CampaignAnalyticsService
{
    /**
     * Campaign performance breakdown from campaign_event tracking events.
     */
    public function getCampaignPerformance(int|string $tenantId, string $dateRange = '30d'): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'campaign_event',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
            ]],
            ['$group' => [
                '_id' => ['$ifNull' => ['$metadata.campaign_name', '$metadata.utm_campaign', 'Unknown']],
                'impressions' => ['$sum' => 1],
                'sessions'    => ['$addToSet' => '$session_id'],
                'source'      => ['$first' => ['$ifNull' => ['$metadata.utm_source', '$metadata.source', 'direct']]],
                'medium'      => ['$first' => ['$ifNull' => ['$metadata.utm_medium', '$metadata.medium', 'none']]],
            ]],
            ['$project' => [
                '_id'         => 1,
                'impressions' => 1,
                'sessions'    => ['$size' => '$sessions'],
                'source'      => 1,
                'medium'      => 1,
            ]],
            ['$sort' => ['sessions' => -1]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'campaign'    => $row['_id'] ?? 'Unknown',
            'source'      => $row['source'] ?? 'direct',
            'medium'      => $row['medium'] ?? 'none',
            'impressions' => (int) ($row['impressions'] ?? 0),
            'sessions'    => (int) ($row['sessions'] ?? 0),
        ], $results);
    }

    /**
     * UTM parameter breakdown — traffic by utm_source and utm_medium.
     */
    public function getUtmBreakdown(int|string $tenantId, string $dateRange = '30d'): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        // Parse UTM params from URLs
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
                '_id'       => '$session_id',
                'first_url' => ['$first' => '$url'],
            ]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        $sources = [];
        $mediums = [];
        $campaigns = [];

        foreach ($results as $row) {
            $url = $row['first_url'] ?? '';
            $parsed = $this->parseUtmParams($url);

            $src = $parsed['utm_source'] ?? 'direct';
            $med = $parsed['utm_medium'] ?? 'none';
            $cmp = $parsed['utm_campaign'] ?? 'none';

            $sources[$src] = ($sources[$src] ?? 0) + 1;
            $mediums[$med] = ($mediums[$med] ?? 0) + 1;

            if ($cmp !== 'none') {
                $campaigns[$cmp] = ($campaigns[$cmp] ?? 0) + 1;
            }
        }

        arsort($sources);
        arsort($mediums);
        arsort($campaigns);

        return [
            'sources'   => $this->mapToArray($sources),
            'mediums'   => $this->mapToArray($mediums),
            'campaigns' => $this->mapToArray($campaigns),
        ];
    }

    /**
     * Channel attribution — revenue attributed to different marketing channels.
     */
    public function getChannelAttribution(int|string $tenantId, string $dateRange = '30d'): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
            ]],
            ['$group' => [
                '_id' => [
                    '$ifNull' => [
                        '$metadata.attribution.source',
                        '$metadata.multi_touch_attribution.last_touch.source',
                        'direct',
                    ],
                ],
                'revenue'     => ['$sum' => '$metadata.order_total'],
                'conversions' => ['$sum' => 1],
            ]],
            ['$sort' => ['revenue' => -1]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        $totalRevenue = 0;
        $channels = [];

        foreach ($results as $row) {
            $rev = round((float) ($row['revenue'] ?? 0), 2);
            $totalRevenue += $rev;

            $channels[] = [
                'channel'     => $row['_id'] ?? 'direct',
                'revenue'     => $rev,
                'conversions' => (int) ($row['conversions'] ?? 0),
            ];
        }

        // Add percentage
        foreach ($channels as &$ch) {
            $ch['revenue_pct'] = $totalRevenue > 0
                ? round(($ch['revenue'] / $totalRevenue) * 100, 1)
                : 0;
        }
        unset($ch);

        return [
            'total_revenue' => round($totalRevenue, 2),
            'channels'      => $channels,
        ];
    }

    /**
     * Referrer traffic sources.
     */
    public function getReferrerSources(int|string $tenantId, string $dateRange = '30d', int $limit = 20): array
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
                'metadata.referrer' => ['$exists' => true, '$ne' => ''],
            ]],
            ['$group' => [
                '_id' => '$session_id',
                'referrer' => ['$first' => '$metadata.referrer'],
            ]],
            ['$group' => [
                '_id'      => '$referrer',
                'sessions' => ['$sum' => 1],
            ]],
            ['$sort' => ['sessions' => -1]],
            ['$limit' => $limit],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'referrer' => $row['_id'] ?? 'direct',
            'sessions' => (int) ($row['sessions'] ?? 0),
        ], $results);
    }

    private function parseUtmParams(string $url): array
    {
        $params = [];
        $query  = parse_url($url, PHP_URL_QUERY);

        if ($query) {
            parse_str($query, $parsed);
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
                if (isset($parsed[$key]) && $parsed[$key] !== '') {
                    $params[$key] = $parsed[$key];
                }
            }
        }

        return $params;
    }

    private function mapToArray(array $map): array
    {
        return array_map(
            fn ($key, $count) => ['name' => $key, 'count' => $count],
            array_keys($map),
            array_values($map),
        );
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

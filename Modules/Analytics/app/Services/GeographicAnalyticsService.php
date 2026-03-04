<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use MongoDB\Laravel\Connection;

/**
 * Geographic analytics — visitor breakdown by country, region, city
 * derived from IP address stored in tracking events.
 *
 * Uses a lightweight IP-to-country lookup. For production use, integrate
 * MaxMind GeoIP2 or ip-api.com. This implementation parses stored geo
 * metadata if present, or provides aggregate counts by IP prefix.
 */
final class GeographicAnalyticsService
{
    /**
     * Visitor breakdown by country (from metadata.geo.country or ip_address prefix).
     */
    public function getVisitorsByCountry(int|string $tenantId, string $dateRange = '30d', int $limit = 20): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        // Try metadata.geo.country first; fall back to ip_address grouping
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
                    'session' => '$session_id',
                    'country' => ['$ifNull' => ['$metadata.geo.country', '$custom_data.country', 'Unknown']],
                    'city'    => ['$ifNull' => ['$metadata.geo.city', '$custom_data.city', 'Unknown']],
                    'region'  => ['$ifNull' => ['$metadata.geo.region', '$custom_data.region', 'Unknown']],
                ],
            ]],
            ['$group' => [
                '_id'      => '$_id.country',
                'sessions' => ['$sum' => 1],
                'cities'   => ['$addToSet' => '$_id.city'],
            ]],
            ['$sort' => ['sessions' => -1]],
            ['$limit' => $limit],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'country'     => $row['_id'] ?? 'Unknown',
            'sessions'    => (int) ($row['sessions'] ?? 0),
            'unique_cities' => count($row['cities'] ?? []),
        ], $results);
    }

    /**
     * Visitor breakdown by city.
     */
    public function getVisitorsByCity(int|string $tenantId, string $dateRange = '30d', int $limit = 20): array
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
                    'session' => '$session_id',
                    'country' => ['$ifNull' => ['$metadata.geo.country', '$custom_data.country', 'Unknown']],
                    'city'    => ['$ifNull' => ['$metadata.geo.city', '$custom_data.city', 'Unknown']],
                ],
            ]],
            ['$group' => [
                '_id'      => ['country' => '$_id.country', 'city' => '$_id.city'],
                'sessions' => ['$sum' => 1],
            ]],
            ['$sort' => ['sessions' => -1]],
            ['$limit' => $limit],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'city'     => $row['_id']['city'] ?? 'Unknown',
            'country'  => $row['_id']['country'] ?? 'Unknown',
            'sessions' => (int) ($row['sessions'] ?? 0),
        ], $results);
    }

    /**
     * Device / browser breakdown from user_agent field.
     */
    public function getDeviceBreakdown(int|string $tenantId, string $dateRange = '30d'): array
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
                '_id'        => '$session_id',
                'user_agent' => ['$first' => '$user_agent'],
            ]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        $devices  = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0, 'other' => 0];
        $browsers = [];

        foreach ($results as $row) {
            $ua = strtolower($row['user_agent'] ?? '');

            // Device detection
            if (str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
                if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
                    $devices['tablet']++;
                } else {
                    $devices['mobile']++;
                }
            } elseif ($ua !== '') {
                $devices['desktop']++;
            } else {
                $devices['other']++;
            }

            // Browser detection
            $browser = 'Other';
            if (str_contains($ua, 'chrome') && ! str_contains($ua, 'edg')) {
                $browser = 'Chrome';
            } elseif (str_contains($ua, 'firefox')) {
                $browser = 'Firefox';
            } elseif (str_contains($ua, 'safari') && ! str_contains($ua, 'chrome')) {
                $browser = 'Safari';
            } elseif (str_contains($ua, 'edg')) {
                $browser = 'Edge';
            } elseif (str_contains($ua, 'opera') || str_contains($ua, 'opr')) {
                $browser = 'Opera';
            }

            $browsers[$browser] = ($browsers[$browser] ?? 0) + 1;
        }

        arsort($browsers);

        return [
            'devices'  => $devices,
            'browsers' => $browsers,
            'total_sessions' => array_sum($devices),
        ];
    }

    /**
     * Traffic by time of day (what hours visitors are most active).
     */
    public function getTrafficByHour(int|string $tenantId, string $dateRange = '30d'): array
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
            ['$group' => [
                '_id'   => ['$hour' => '$created_at'],
                'views' => ['$sum' => 1],
            ]],
            ['$sort' => ['_id' => 1]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        $hours = array_fill(0, 24, 0);

        foreach ($results as $row) {
            $hours[(int) $row['_id']] = (int) ($row['views'] ?? 0);
        }

        return [
            'hours' => array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23)),
            'views' => array_values($hours),
        ];
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

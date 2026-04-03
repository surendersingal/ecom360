<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Connection;

/**
 * Revenue analytics — time-series revenue data, AOV, revenue by source,
 * and comparison metrics for professional shopping analytics.
 */
final class RevenueAnalyticsService
{
    /**
     * Daily revenue time series for a given date range.
     *
     * @return array{dates: string[], revenues: float[], orders: int[], aov: float[], total_revenue: float, total_orders: int, average_order_value: float}
     */
    public function getDailyRevenue(int|string $tenantId, string $dateRange = '30d'): array
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
                '_id'     => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]],
                'revenue' => ['$sum' => '$metadata.order_total'],
                'orders'  => ['$sum' => 1],
            ]],
            ['$sort' => ['_id' => 1]],
        ];

        /** @var array $results */
        $results = iterator_to_array($collection->aggregate($pipeline, ['maxTimeMS' => 30000]));

        $dates    = [];
        $revenues = [];
        $orders   = [];
        $aov      = [];
        $totalRevenue = 0.0;
        $totalOrders  = 0;

        foreach ($results as $row) {
            $dates[]    = $row['_id'];
            $rev        = (float) ($row['revenue'] ?? 0);
            $ord        = (int) ($row['orders'] ?? 0);
            $revenues[] = round($rev, 2);
            $orders[]   = $ord;
            $aov[]      = $ord > 0 ? round($rev / $ord, 2) : 0;
            $totalRevenue += $rev;
            $totalOrders  += $ord;
        }

        return [
            'dates'               => $dates,
            'revenues'            => $revenues,
            'orders'              => $orders,
            'aov'                 => $aov,
            'total_revenue'       => round($totalRevenue, 2),
            'total_orders'        => $totalOrders,
            'average_order_value' => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0,
        ];
    }

    /**
     * Revenue breakdown by attribution source (first-touch).
     */
    public function getRevenueBySource(int|string $tenantId, string $dateRange = '30d'): array
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
                '_id'     => ['$ifNull' => ['$metadata.attribution.source', 'direct']],
                'revenue' => ['$sum' => '$metadata.order_total'],
                'orders'  => ['$sum' => 1],
            ]],
            ['$sort' => ['revenue' => -1]],
        ];

        /** @var array $results */
        $results = iterator_to_array($collection->aggregate($pipeline, ['maxTimeMS' => 30000]));

        return array_map(fn ($row) => [
            'source'  => $row['_id'] ?? 'direct',
            'revenue' => round((float) ($row['revenue'] ?? 0), 2),
            'orders'  => (int) ($row['orders'] ?? 0),
        ], $results);
    }

    /**
     * Hourly revenue distribution (average revenue per hour of day).
     */
    public function getHourlyRevenuePattern(int|string $tenantId, string $dateRange = '30d'): array
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
                '_id'     => ['$hour' => '$created_at'],
                'revenue' => ['$sum' => '$metadata.order_total'],
                'orders'  => ['$sum' => 1],
            ]],
            ['$sort' => ['_id' => 1]],
        ];

        /** @var array $results */
        $results = iterator_to_array($collection->aggregate($pipeline, ['maxTimeMS' => 30000]));

        $hours   = array_fill(0, 24, 0.0);
        $counts  = array_fill(0, 24, 0);

        foreach ($results as $row) {
            $h = (int) $row['_id'];
            $hours[$h]  = round((float) ($row['revenue'] ?? 0), 2);
            $counts[$h] = (int) ($row['orders'] ?? 0);
        }

        return [
            'hours'   => array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23)),
            'revenue' => array_values($hours),
            'orders'  => array_values($counts),
        ];
    }

    /**
     * Revenue comparison: current period vs previous period.
     */
    public function getRevenueComparison(int|string $tenantId, string $dateRange = '30d'): array
    {
        $days = (int) rtrim($dateRange, 'd') ?: 30;
        $currentFrom = CarbonImmutable::now()->subDays($days)->startOfDay();
        $currentTo   = CarbonImmutable::now()->endOfDay();
        $prevFrom    = CarbonImmutable::now()->subDays($days * 2)->startOfDay();
        $prevTo      = CarbonImmutable::now()->subDays($days)->startOfDay();

        $current  = $this->periodRevenue($tenantId, $currentFrom, $currentTo);
        $previous = $this->periodRevenue($tenantId, $prevFrom, $prevTo);

        $revenueChange = $previous['revenue'] > 0
            ? round((($current['revenue'] - $previous['revenue']) / $previous['revenue']) * 100, 1)
            : 0;

        $ordersChange = $previous['orders'] > 0
            ? round((($current['orders'] - $previous['orders']) / $previous['orders']) * 100, 1)
            : 0;

        return [
            'current'        => $current,
            'previous'       => $previous,
            'revenue_change' => $revenueChange,
            'orders_change'  => $ordersChange,
        ];
    }

    private function periodRevenue(int|string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $collection = $this->collection();

        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($from->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($to->getTimestamp() * 1000),
                ],
            ]],
            ['$group' => [
                '_id'     => null,
                'revenue' => ['$sum' => '$metadata.order_total'],
                'orders'  => ['$sum' => 1],
            ]],
        ];

        /** @var array $results */
        $results = iterator_to_array($collection->aggregate($pipeline, ['maxTimeMS' => 30000]));

        return [
            'revenue' => round((float) ($results[0]['revenue'] ?? 0), 2),
            'orders'  => (int) ($results[0]['orders'] ?? 0),
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

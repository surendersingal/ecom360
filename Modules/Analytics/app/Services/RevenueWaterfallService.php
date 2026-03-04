<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Revenue Attribution Waterfall.
 *
 * Breaks down total revenue into its contributing factors:
 *   - New vs returning customers
 *   - Acquisition channels (organic, paid, social, email, direct, referral)
 *   - Product categories
 *   - Geographic regions
 *   - Device types
 *   - Campaign attribution
 *
 * Provides period-over-period comparisons showing what drove revenue
 * changes (e.g., "Revenue grew 15% — 8% from new customers, 5% from
 * increased AOV, 2% from returning customer frequency").
 */
final class RevenueWaterfallService
{
    /**
     * Generate a complete revenue waterfall analysis.
     */
    public function analyze(int|string $tenantId, ?string $startDate = null, ?string $endDate = null): array
    {
        $end = $endDate ?? now()->toIso8601String();
        $start = $startDate ?? now()->subDays(30)->toIso8601String();

        // Calculate period length for comparison
        $periodDays = max(1, (int) now()->parse($start)->diffInDays(now()->parse($end)));
        $prevStart = now()->parse($start)->subDays($periodDays)->toIso8601String();
        $prevEnd = $start;

        $current = $this->getPeriodData($tenantId, $start, $end);
        $previous = $this->getPeriodData($tenantId, $prevStart, $prevEnd);

        return [
            'period' => ['start' => $start, 'end' => $end],
            'previous_period' => ['start' => $prevStart, 'end' => $prevEnd],
            'summary' => $this->buildSummary($current, $previous),
            'by_customer_type' => $this->byCustomerType($tenantId, $start, $end, $prevStart, $prevEnd),
            'by_channel' => $this->byChannel($tenantId, $start, $end),
            'by_category' => $this->byProductCategory($tenantId, $start, $end),
            'by_geography' => $this->byGeography($tenantId, $start, $end),
            'by_device' => $this->byDevice($tenantId, $start, $end),
            'waterfall_factors' => $this->decomposeChange($current, $previous),
        ];
    }

    private function getPeriodData(int|string $tenantId, string $start, string $end): array
    {
        $purchases = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'purchase')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end);

        $revenue = (float) $purchases->sum('metadata.revenue');
        $orders = $purchases->count();
        $customers = $purchases->distinct('visitor_id')->count('visitor_id');
        $sessions = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'page_view')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->distinct('session_id')
            ->count('session_id');

        return [
            'revenue' => round($revenue, 2),
            'orders' => $orders,
            'customers' => $customers,
            'sessions' => max(1, $sessions),
            'aov' => $orders > 0 ? round($revenue / $orders, 2) : 0,
            'conversion_rate' => $sessions > 0 ? round(($orders / $sessions) * 100, 2) : 0,
            'revenue_per_customer' => $customers > 0 ? round($revenue / $customers, 2) : 0,
        ];
    }

    private function buildSummary(array $current, array $previous): array
    {
        $change = fn(float $curr, float $prev) => $prev > 0 ? round((($curr - $prev) / $prev) * 100, 2) : 0;

        return [
            'revenue' => $current['revenue'],
            'revenue_change' => $change($current['revenue'], $previous['revenue']),
            'orders' => $current['orders'],
            'orders_change' => $change($current['orders'], $previous['orders']),
            'customers' => $current['customers'],
            'customers_change' => $change($current['customers'], $previous['customers']),
            'aov' => $current['aov'],
            'aov_change' => $change($current['aov'], $previous['aov']),
            'conversion_rate' => $current['conversion_rate'],
            'conversion_rate_change' => $change($current['conversion_rate'], $previous['conversion_rate']),
        ];
    }

    private function byCustomerType(int $tid, string $start, string $end, string $prevStart, string $prevEnd): array
    {
        // New customers: first_seen_at within period
        $newCustomerEmails = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tid)
            ->where('first_seen_at', '>=', $start)
            ->where('first_seen_at', '<=', $end)
            ->pluck('email')
            ->filter()
            ->all();

        $purchases = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)
            ->where('event_type', 'purchase')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->get();

        $newRevenue = 0;
        $returningRevenue = 0;

        foreach ($purchases as $p) {
            $p = (array) $p;
            $email = $p['metadata']['customer_email'] ?? $p['metadata']['email'] ?? '';
            $revenue = (float) ($p['metadata']['revenue'] ?? $p['metadata']['total'] ?? 0);

            if (in_array($email, $newCustomerEmails)) {
                $newRevenue += $revenue;
            } else {
                $returningRevenue += $revenue;
            }
        }

        $total = $newRevenue + $returningRevenue;
        return [
            'new_customers' => [
                'revenue' => round($newRevenue, 2),
                'percent' => $total > 0 ? round(($newRevenue / $total) * 100, 1) : 0,
            ],
            'returning_customers' => [
                'revenue' => round($returningRevenue, 2),
                'percent' => $total > 0 ? round(($returningRevenue / $total) * 100, 1) : 0,
            ],
        ];
    }

    private function byChannel(int $tid, string $start, string $end): array
    {
        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tid, $start, $end) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tid,
                        'event_type' => 'purchase',
                        'created_at' => ['$gte' => $start, '$lte' => $end],
                    ]],
                    ['$group' => [
                        '_id' => ['$ifNull' => ['$metadata.channel', 'direct']],
                        'revenue' => ['$sum' => '$metadata.revenue'],
                        'orders' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['revenue' => -1]],
                ])->toArray();
            });

        return array_map(fn($r) => [
            'channel' => $r['_id'] ?? 'direct',
            'revenue' => round((float) ($r['revenue'] ?? 0), 2),
            'orders' => (int) ($r['orders'] ?? 0),
        ], $results);
    }

    private function byProductCategory(int $tid, string $start, string $end): array
    {
        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tid, $start, $end) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tid,
                        'event_type' => 'purchase',
                        'created_at' => ['$gte' => $start, '$lte' => $end],
                    ]],
                    ['$group' => [
                        '_id' => ['$ifNull' => ['$metadata.category', 'uncategorized']],
                        'revenue' => ['$sum' => '$metadata.revenue'],
                        'orders' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => 10],
                ])->toArray();
            });

        return array_map(fn($r) => [
            'category' => $r['_id'] ?? 'uncategorized',
            'revenue' => round((float) ($r['revenue'] ?? 0), 2),
            'orders' => (int) ($r['orders'] ?? 0),
        ], $results);
    }

    private function byGeography(int $tid, string $start, string $end): array
    {
        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tid, $start, $end) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tid,
                        'event_type' => 'purchase',
                        'created_at' => ['$gte' => $start, '$lte' => $end],
                    ]],
                    ['$group' => [
                        '_id' => ['$ifNull' => ['$country', 'unknown']],
                        'revenue' => ['$sum' => '$metadata.revenue'],
                        'orders' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => 10],
                ])->toArray();
            });

        return array_map(fn($r) => [
            'country' => $r['_id'] ?? 'unknown',
            'revenue' => round((float) ($r['revenue'] ?? 0), 2),
            'orders' => (int) ($r['orders'] ?? 0),
        ], $results);
    }

    private function byDevice(int $tid, string $start, string $end): array
    {
        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tid, $start, $end) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tid,
                        'event_type' => 'purchase',
                        'created_at' => ['$gte' => $start, '$lte' => $end],
                    ]],
                    ['$group' => [
                        '_id' => ['$ifNull' => ['$device_type', 'unknown']],
                        'revenue' => ['$sum' => '$metadata.revenue'],
                        'orders' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['revenue' => -1]],
                ])->toArray();
            });

        return array_map(fn($r) => [
            'device' => $r['_id'] ?? 'unknown',
            'revenue' => round((float) ($r['revenue'] ?? 0), 2),
            'orders' => (int) ($r['orders'] ?? 0),
        ], $results);
    }

    private function decomposeChange(array $current, array $previous): array
    {
        $revenueChange = $current['revenue'] - $previous['revenue'];
        if (abs($revenueChange) < 0.01) return ['no_change' => true];

        // Decompose: Revenue = Customers × Orders/Customer × AOV
        $customerEffect = ($current['customers'] - $previous['customers']) * ($previous['revenue_per_customer'] ?: 1);
        $aovEffect = ($current['aov'] - $previous['aov']) * $previous['orders'];
        $frequencyEffect = $revenueChange - $customerEffect - $aovEffect;

        return [
            'total_change' => round($revenueChange, 2),
            'factors' => [
                ['name' => 'Customer Growth', 'value' => round($customerEffect, 2), 'percent' => $revenueChange != 0 ? round(($customerEffect / abs($revenueChange)) * 100, 1) : 0],
                ['name' => 'AOV Change', 'value' => round($aovEffect, 2), 'percent' => $revenueChange != 0 ? round(($aovEffect / abs($revenueChange)) * 100, 1) : 0],
                ['name' => 'Purchase Frequency', 'value' => round($frequencyEffect, 2), 'percent' => $revenueChange != 0 ? round(($frequencyEffect / abs($revenueChange)) * 100, 1) : 0],
            ],
        ];
    }
}

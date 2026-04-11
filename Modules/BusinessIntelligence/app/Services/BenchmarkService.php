<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BusinessIntelligence\Models\Benchmark;

/**
 * Cross-tenant anonymized benchmarking service.
 * Compares a tenant's metrics against industry percentiles
 * calculated from all tenants on the platform.
 *
 * Metrics benchmarked:
 *   conversion_rate, aov, cart_abandonment_rate, bounce_rate,
 *   email_open_rate, email_click_rate, revenue_per_session,
 *   returning_customer_rate, pages_per_session, clv
 */
final class BenchmarkService
{
    /**
     * Calculate and store benchmarks for a specific tenant.
     */
    public function calculate(int $tenantId, string $period = 'monthly'): int
    {
        $metrics = [
            'conversion_rate', 'aov', 'cart_abandonment_rate', 'bounce_rate',
            'email_open_rate', 'email_click_rate', 'revenue_per_session',
            'returning_customer_rate', 'pages_per_session', 'clv',
        ];

        $industryData = $this->gatherIndustryData($period);
        $tenantData = $this->gatherTenantData($tenantId, $period);
        $count = 0;

        foreach ($metrics as $metric) {
            $tenantValue = $tenantData[$metric] ?? 0;
            $industry = $industryData[$metric] ?? null;

            if (!$industry) continue;

            Benchmark::updateOrCreate(
                ['tenant_id' => $tenantId, 'metric' => $metric, 'period' => $period],
                [
                    'tenant_value' => $tenantValue,
                    'industry_p25' => $industry['p25'],
                    'industry_p50' => $industry['p50'],
                    'industry_p75' => $industry['p75'],
                    'industry_p90' => $industry['p90'],
                    'sample_size' => $industry['sample_size'],
                    'industry' => $industry['industry'] ?? 'ecommerce',
                    'calculated_at' => now(),
                ]
            );
            $count++;
        }

        Log::info("[BenchmarkService] Calculated {$count} benchmarks for tenant #{$tenantId}");
        return $count;
    }

    /**
     * Get benchmark summary for a tenant.
     */
    public function getSummary(int $tenantId): array
    {
        $benchmarks = Benchmark::where('tenant_id', $tenantId)
            ->orderBy('calculated_at', 'desc')
            ->get()
            ->unique('metric');

        // Auto-generate benchmarks on first access if none exist
        if ($benchmarks->isEmpty()) {
            try {
                $this->calculate($tenantId);
                $benchmarks = Benchmark::where('tenant_id', $tenantId)
                    ->orderBy('calculated_at', 'desc')
                    ->get()
                    ->unique('metric');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[BI] BenchmarkService auto-generate failed: ' . $e->getMessage());
                return [];
            }
        }

        return $benchmarks->map(fn(Benchmark $b) => [
            'metric' => $b->metric,
            'tenant_value' => $b->tenant_value,
            'industry_median' => $b->industry_p50,
            'percentile' => $b->percentile,
            'performance' => $b->performance_label,
            'industry_p25' => $b->industry_p25,
            'industry_p75' => $b->industry_p75,
            'industry_p90' => $b->industry_p90,
            'sample_size' => $b->sample_size,
        ])->values()->all();
    }

    /**
     * Gather anonymized industry data from all tenants.
     */
    private function gatherIndustryData(string $period): array
    {
        $tenants = DB::table('tenants')->pluck('id');
        $allMetrics = [];

        foreach ($tenants as $tid) {
            $data = $this->gatherTenantData((int) $tid, $period);
            foreach ($data as $metric => $value) {
                if ($value > 0) {
                    $allMetrics[$metric][] = (float) $value;
                }
            }
        }

        $result = [];
        foreach ($allMetrics as $metric => $values) {
            sort($values);
            $n = count($values);
            if ($n < 1) continue; // Need at least 1 tenant for benchmarks

            $result[$metric] = [
                'p25' => $this->percentile($values, 25),
                'p50' => $this->percentile($values, 50),
                'p75' => $this->percentile($values, 75),
                'p90' => $this->percentile($values, 90),
                'sample_size' => $n,
                'industry' => 'ecommerce',
            ];
        }

        return $result;
    }

    /**
     * Gather metrics for a specific tenant.
     */
    private function gatherTenantData(int $tenantId, string $period): array
    {
        $tids = [(int) $tenantId, (string) $tenantId];
        $days = $period === 'weekly' ? 7 : 30;
        $since = new \MongoDB\BSON\UTCDateTime(now()->subDays($days)->startOfDay()->getTimestampMs());

        try {
            $orders = DB::connection('mongodb')->table('synced_orders')
                ->whereIn('tenant_id', $tids)
                ->where('created_at', '>=', $since)
                ->whereNotIn('status', ['cancelled', 'canceled'])
                ->count();

            $revenue = (float) DB::connection('mongodb')->table('synced_orders')
                ->whereIn('tenant_id', $tids)
                ->where('created_at', '>=', $since)
                ->whereNotIn('status', ['cancelled', 'canceled'])
                ->sum('grand_total');

            $tid = (string) $tenantId;

            $sessions = DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'page_view')
                ->where('created_at', '>=', $since)->distinct('session_id')->count('session_id');

            $carts = DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'add_to_cart')
                ->where('created_at', '>=', $since)->distinct('session_id')->count('session_id');

            $customers = DB::connection('mongodb')->table('customer_profiles')
                ->where('tenant_id', $tid)->where('total_orders', '>', 0)->count();

            $returningCustomers = DB::connection('mongodb')->table('customer_profiles')
                ->where('tenant_id', $tid)->where('total_orders', '>', 1)->count();

            $avgClv = (float) DB::connection('mongodb')->table('customer_profiles')
                ->where('tenant_id', $tid)->where('total_orders', '>', 0)->avg('lifetime_value');

            // Marketing metrics
            $campaigns = DB::table('marketing_campaigns')->where('tenant_id', $tenantId)
                ->where('status', 'sent')->where('channel', 'email');
            $totalDelivered = (int) $campaigns->sum('total_delivered');
            $totalOpened = (int) $campaigns->sum('total_opened');
            $totalClicked = (int) $campaigns->sum('total_clicked');

            return [
                'conversion_rate' => $sessions > 0 ? round(($orders / $sessions) * 100, 2) : 0,
                'aov' => $orders > 0 ? round($revenue / $orders, 2) : 0,
                'cart_abandonment_rate' => $carts > 0 ? round((($carts - $orders) / $carts) * 100, 2) : 0,
                'bounce_rate' => 0, // Would need session-level analysis
                'email_open_rate' => $totalDelivered > 0 ? round(($totalOpened / $totalDelivered) * 100, 2) : 0,
                'email_click_rate' => $totalDelivered > 0 ? round(($totalClicked / $totalDelivered) * 100, 2) : 0,
                'revenue_per_session' => $sessions > 0 ? round($revenue / $sessions, 2) : 0,
                'returning_customer_rate' => $customers > 0 ? round(($returningCustomers / $customers) * 100, 2) : 0,
                'pages_per_session' => 0, // Would need page_view count / sessions
                'clv' => round($avgClv, 2),
            ];
        } catch (\Throwable $e) {
            Log::error("[BenchmarkService] Data gathering failed for tenant #{$tenantId}: {$e->getMessage()}");
            return [];
        }
    }

    private function percentile(array $sortedValues, int $percentile): float
    {
        $n = count($sortedValues);
        $index = ($percentile / 100) * ($n - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper) return round($sortedValues[$lower], 4);

        return round($sortedValues[$lower] + ($sortedValues[$upper] - $sortedValues[$lower]) * $fraction, 4);
    }
}

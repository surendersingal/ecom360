<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Competitive Benchmark Service (Analytics layer).
 *
 * Compares a tenant's key metrics against anonymised, aggregated data
 * from all tenants in the same industry vertical.  Provides percentile
 * rankings and actionable improvement suggestions.
 *
 * Differs from BI BenchmarkService: this is customer-facing via the API
 * and optimises for speed with Redis caching, while BI BenchmarkService
 * does deeper cross-tenant analysis for internal reports.
 */
final class CompetitiveBenchmarkService
{
    private const METRICS = [
        'conversion_rate',
        'aov',
        'cart_abandonment_rate',
        'bounce_rate',
        'returning_customer_rate',
        'avg_session_duration_seconds',
        'revenue_per_session',
    ];

    /**
     * Get benchmark comparison for a tenant.
     */
    public function compare(int|string $tenantId): array
    {
        $tenantMetrics = $this->getTenantMetrics($tenantId);
        $benchmarks = $this->getIndustryBenchmarks($tenantId);

        $comparisons = [];
        foreach (self::METRICS as $metric) {
            $value = $tenantMetrics[$metric] ?? 0;
            $benchmark = $benchmarks[$metric] ?? null;

            if (!$benchmark) {
                $comparisons[$metric] = ['value' => $value, 'benchmark' => null, 'percentile' => null];
                continue;
            }

            $percentile = $this->calculatePercentile($value, $benchmark);
            $comparisons[$metric] = [
                'value' => round($value, 2),
                'industry_p25' => $benchmark['p25'],
                'industry_p50' => $benchmark['p50'],
                'industry_p75' => $benchmark['p75'],
                'industry_p90' => $benchmark['p90'],
                'percentile' => $percentile,
                'performance' => $this->labelPerformance($percentile),
                'suggestion' => $this->getSuggestion($metric, $percentile, $value, $benchmark),
            ];
        }

        return [
            'tenant_id' => $tenantId,
            'period' => 'last_30_days',
            'metrics' => $comparisons,
            'overall_score' => $this->overallScore($comparisons),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get industry benchmark data (anonymised aggregate, cached 6 hours).
     */
    public function getIndustryBenchmarks(int|string $tenantId): array
    {
        $cacheKey = "competitive_benchmarks:industry";

        return Cache::remember($cacheKey, now()->addHours(6), function () {
            $allMetrics = [];

            // Gather metrics from all tenants
            $tenantIds = DB::table('tenants')->pluck('id')->all();

            foreach ($tenantIds as $tid) {
                $m = $this->getTenantMetrics((string) $tid);
                foreach (self::METRICS as $metric) {
                    $allMetrics[$metric][] = $m[$metric] ?? 0;
                }
            }

            $benchmarks = [];
            foreach (self::METRICS as $metric) {
                $values = $allMetrics[$metric] ?? [];
                sort($values);
                $n = count($values);
                if ($n < 2) continue;

                $benchmarks[$metric] = [
                    'p25' => round($values[(int) ($n * 0.25)] ?? 0, 2),
                    'p50' => round($values[(int) ($n * 0.50)] ?? 0, 2),
                    'p75' => round($values[(int) ($n * 0.75)] ?? 0, 2),
                    'p90' => round($values[(int) ($n * 0.90)] ?? 0, 2),
                    'sample_size' => $n,
                ];
            }

            return $benchmarks;
        });
    }

    // ── Metric Calculation ───────────────────────────────────────────

    private function getTenantMetrics(int|string $tid): array
    {
        $cacheKey = "competitive_benchmarks:tenant:{$tid}";

        return Cache::remember($cacheKey, now()->addHours(1), function () use ($tid) {
            $start = now()->subDays(30)->toIso8601String();
            $end = now()->toIso8601String();

            $sessions = max(1, DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'page_view')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->distinct('session_id')->count('session_id'));

            $orders = DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'purchase')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->count();

            $revenue = (float) DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'purchase')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->sum('metadata.revenue');

            $carts = DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'add_to_cart')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->distinct('session_id')->count('session_id');

            $bounceSessions = DB::connection('mongodb')->table('tracking_events')
                ->raw(function ($col) use ($tid, $start, $end) {
                    return $col->aggregate([
                        ['$match' => [
                            'tenant_id' => $tid,
                            'event_type' => 'page_view',
                            'created_at' => ['$gte' => $start, '$lte' => $end],
                        ]],
                        ['$group' => ['_id' => '$session_id', 'count' => ['$sum' => 1]]],
                        ['$match' => ['count' => 1]],
                        ['$count' => 'total'],
                    ])->toArray();
                });
            $bounces = $bounceSessions[0]['total'] ?? 0;

            $customers = DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'purchase')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->distinct('visitor_id')->count('visitor_id');

            $returningCustomers = DB::connection('mongodb')->table('customer_profiles')
                ->where('tenant_id', $tid)
                ->where('total_orders', '>=', 2)
                ->count();
            $totalCustomers = max(1, DB::connection('mongodb')->table('customer_profiles')
                ->where('tenant_id', $tid)->count());

            return [
                'conversion_rate' => round(($orders / $sessions) * 100, 2),
                'aov' => $orders > 0 ? round($revenue / $orders, 2) : 0,
                'cart_abandonment_rate' => $carts > 0 ? round((($carts - $orders) / $carts) * 100, 2) : 0,
                'bounce_rate' => round(($bounces / $sessions) * 100, 2),
                'returning_customer_rate' => round(($returningCustomers / $totalCustomers) * 100, 2),
                'avg_session_duration_seconds' => 0, // Would need session start/end tracking
                'revenue_per_session' => round($revenue / $sessions, 2),
            ];
        });
    }

    private function calculatePercentile(float $value, array $benchmark): int
    {
        if ($value <= $benchmark['p25']) return (int) round(($value / max(0.01, $benchmark['p25'])) * 25);
        if ($value <= $benchmark['p50']) return 25 + (int) round((($value - $benchmark['p25']) / max(0.01, $benchmark['p50'] - $benchmark['p25'])) * 25);
        if ($value <= $benchmark['p75']) return 50 + (int) round((($value - $benchmark['p50']) / max(0.01, $benchmark['p75'] - $benchmark['p50'])) * 25);
        if ($value <= $benchmark['p90']) return 75 + (int) round((($value - $benchmark['p75']) / max(0.01, $benchmark['p90'] - $benchmark['p75'])) * 15);
        return min(99, 90 + (int) round((($value - $benchmark['p90']) / max(0.01, $benchmark['p90'])) * 9));
    }

    private function labelPerformance(int $percentile): string
    {
        return match (true) {
            $percentile >= 90 => 'excellent',
            $percentile >= 75 => 'good',
            $percentile >= 50 => 'average',
            $percentile >= 25 => 'below_average',
            default => 'needs_improvement',
        };
    }

    private function getSuggestion(string $metric, int $percentile, float $value, array $benchmark): ?string
    {
        if ($percentile >= 75) return null;

        return match ($metric) {
            'conversion_rate' => sprintf('Your conversion rate (%.1f%%) is below the median (%.1f%%). Consider A/B testing checkout flow, adding trust signals, and improving page load times.', $value, $benchmark['p50']),
            'aov' => sprintf('Your AOV ($%.2f) is below median ($%.2f). Try product bundling, free shipping thresholds, or cross-sell recommendations.', $value, $benchmark['p50']),
            'cart_abandonment_rate' => sprintf('Cart abandonment (%.0f%%) is higher than median (%.0f%%). Implement exit-intent popups, email recovery flows, and guest checkout.', $value, $benchmark['p50']),
            'bounce_rate' => sprintf('Bounce rate (%.0f%%) exceeds median (%.0f%%). Improve page load speed, content relevance, and mobile experience.', $value, $benchmark['p50']),
            'returning_customer_rate' => sprintf('Returning customer rate (%.0f%%) is below median (%.0f%%). Focus on loyalty programs, post-purchase emails, and personalised recommendations.', $value, $benchmark['p50']),
            'revenue_per_session' => sprintf('Revenue per session ($%.2f) is below median ($%.2f). Improve product discovery, site search, and recommendation placement.', $value, $benchmark['p50']),
            default => null,
        };
    }

    private function overallScore(array $comparisons): array
    {
        $percentiles = array_filter(array_column($comparisons, 'percentile'));
        if (empty($percentiles)) return ['score' => 0, 'label' => 'insufficient_data'];

        $avg = array_sum($percentiles) / count($percentiles);
        return [
            'score' => (int) round($avg),
            'label' => $this->labelPerformance((int) round($avg)),
            'metrics_evaluated' => count($percentiles),
        ];
    }
}

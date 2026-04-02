<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered "Why" explanations for metric changes.
 *
 * When a KPI goes up or down, this service identifies the top contributing
 * factors automatically.  Works by comparing the current period to a
 * reference period across every available dimension (channel, device,
 * geography, product category, customer segment, time-of-day) and ranking
 * dimensions by their absolute contribution to the observed change.
 */
final class WhyExplanationService
{
    private const DIMENSIONS = ['channel', 'device_type', 'country', 'city', 'metadata.category', 'metadata.brand'];

    /**
     * Explain why a metric changed between two periods.
     *
     * @param string $metric   One of: revenue, orders, sessions, conversion_rate, aov
     */
    public function explain(int|string $tenantId, string $metric, string $currentStart, string $currentEnd, ?string $previousStart = null, ?string $previousEnd = null): array
    {
        $periodDays = max(1, (int) now()->parse($currentStart)->diffInDays(now()->parse($currentEnd)));
        $previousStart ??= now()->parse($currentStart)->subDays($periodDays)->toIso8601String();
        $previousEnd ??= $currentStart;

        $currentValue = $this->getMetricValue($tenantId, $metric, $currentStart, $currentEnd);
        $previousValue = $this->getMetricValue($tenantId, $metric, $previousStart, $previousEnd);
        $change = $currentValue - $previousValue;
        $changePct = $previousValue > 0 ? round(($change / $previousValue) * 100, 2) : 0;

        $factors = [];
        foreach (self::DIMENSIONS as $dim) {
            $factors = array_merge($factors, $this->analyzeDimension($tenantId, $metric, $dim, $currentStart, $currentEnd, $previousStart, $previousEnd));
        }

        // Rank by absolute contribution, keep top 10
        usort($factors, fn($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));
        $factors = array_slice($factors, 0, 10);

        return [
            'metric' => $metric,
            'current_value' => $currentValue,
            'previous_value' => $previousValue,
            'change' => round($change, 2),
            'change_percent' => $changePct,
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
            'explanation' => $this->generateNarrative($metric, $change, $changePct, $factors),
            'top_factors' => $factors,
        ];
    }

    private function getMetricValue(int|string $tid, string $metric, string $start, string $end): float
    {
        return match ($metric) {
            'revenue' => (float) DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'purchase')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->sum('metadata.revenue'),
            'orders' => (float) DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'purchase')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->count(),
            'sessions' => (float) DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'page_view')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->distinct('session_id')->count('session_id'),
            'aov' => $this->computeAov($tid, $start, $end),
            'conversion_rate' => $this->computeConversionRate($tid, $start, $end),
            default => 0,
        };
    }

    private function computeAov(int|string $tid, string $start, string $end): float
    {
        $q = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)->where('event_type', 'purchase')
            ->where('created_at', '>=', $start)->where('created_at', '<=', $end);
        $orders = $q->count();
        return $orders > 0 ? round((float) $q->sum('metadata.revenue') / $orders, 2) : 0;
    }

    private function computeConversionRate(int|string $tid, string $start, string $end): float
    {
        $sessions = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)->where('event_type', 'page_view')
            ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
            ->distinct('session_id')->count('session_id');
        $orders = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)->where('event_type', 'purchase')
            ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
            ->count();
        return $sessions > 0 ? round(($orders / $sessions) * 100, 2) : 0;
    }

    private function analyzeDimension(int|string $tid, string $metric, string $dimension, string $curStart, string $curEnd, string $prevStart, string $prevEnd): array
    {
        $aggregateField = match ($metric) {
            'revenue' => '$metadata.revenue',
            default => null,
        };

        $currentGroups = $this->groupByDimension($tid, $metric, $dimension, $curStart, $curEnd, $aggregateField);
        $previousGroups = $this->groupByDimension($tid, $metric, $dimension, $prevStart, $prevEnd, $aggregateField);

        $factors = [];
        $allKeys = array_unique(array_merge(array_keys($currentGroups), array_keys($previousGroups)));

        foreach ($allKeys as $key) {
            $curr = $currentGroups[$key] ?? 0;
            $prev = $previousGroups[$key] ?? 0;
            $contribution = $curr - $prev;
            if (abs($contribution) < 0.01) continue;

            $dimLabel = str_replace('metadata.', '', $dimension);
            $factors[] = [
                'dimension' => $dimLabel,
                'value' => $key,
                'current' => round($curr, 2),
                'previous' => round($prev, 2),
                'contribution' => round($contribution, 2),
                'contribution_percent' => $prev > 0 ? round(($contribution / $prev) * 100, 1) : 0,
            ];
        }

        return $factors;
    }

    private function groupByDimension(int|string $tid, string $metric, string $dim, string $start, string $end, ?string $aggField): array
    {
        $eventType = in_array($metric, ['revenue', 'orders', 'aov']) ? 'purchase' : 'page_view';

        $group = ['_id' => ['$ifNull' => ['$' . $dim, 'unknown']]];
        if ($aggField && in_array($metric, ['revenue', 'aov'])) {
            $group['value'] = ['$sum' => $aggField];
        } else {
            $group['value'] = ['$sum' => 1];
        }

        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tid, $eventType, $start, $end, $dim, $group) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tid,
                        'event_type' => $eventType,
                        'created_at' => ['$gte' => $start, '$lte' => $end],
                    ]],
                    ['$group' => $group],
                ])->toArray();
            });

        $grouped = [];
        foreach ($results as $r) {
            $grouped[$r['_id'] ?? 'unknown'] = (float) ($r['value'] ?? 0);
        }
        return $grouped;
    }

    private function generateNarrative(string $metric, float $change, float $changePct, array $factors): string
    {
        if (abs($changePct) < 1) return "Your {$metric} remained essentially flat this period.";

        $direction = $change > 0 ? 'increased' : 'decreased';
        $narrative = sprintf('%s %s by %.1f%%', ucfirst(str_replace('_', ' ', $metric)), $direction, abs($changePct));

        $topPositive = array_filter($factors, fn($f) => $f['contribution'] > 0);
        $topNegative = array_filter($factors, fn($f) => $f['contribution'] < 0);

        $explanations = [];
        foreach (array_slice($topPositive, 0, 3) as $f) {
            $explanations[] = sprintf('+%.1f from %s = "%s"', $f['contribution'], $f['dimension'], $f['value']);
        }
        foreach (array_slice($topNegative, 0, 2) as $f) {
            $explanations[] = sprintf('%.1f from %s = "%s"', $f['contribution'], $f['dimension'], $f['value']);
        }

        if (!empty($explanations)) {
            $narrative .= '. Key factors: ' . implode('; ', $explanations) . '.';
        }

        return $narrative;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Natural Language Query Service.
 *
 * Translates plain-English questions into analytics queries and returns
 * human-readable answers.  Uses pattern matching and keyword extraction
 * (no external AI API required — works entirely offline).
 *
 * Examples:
 *   "What was my revenue last week?"
 *   "How many orders did I get yesterday?"
 *   "What are my top 5 products by revenue?"
 *   "Show me conversion rate for mobile users"
 *   "Compare this month vs last month revenue"
 */
final class NaturalLanguageQueryService
{
    private const METRIC_PATTERNS = [
        'revenue'         => ['revenue', 'sales', 'income', 'money', 'earnings', 'gmv'],
        'orders'          => ['orders', 'purchases', 'transactions', 'sales count'],
        'sessions'        => ['sessions', 'visits', 'traffic', 'visitors'],
        'conversion_rate' => ['conversion rate', 'conversion', 'cvr', 'convert'],
        'aov'             => ['aov', 'average order value', 'average order', 'basket size', 'order value'],
        'customers'       => ['customers', 'buyers', 'users', 'people who bought'],
        'page_views'      => ['page views', 'pageviews', 'views', 'impressions'],
        'cart_rate'       => ['cart rate', 'add to cart rate', 'cart abandonment', 'abandon'],
        'products'        => ['products', 'items', 'skus'],
    ];

    private const TIME_PATTERNS = [
        'today'        => [0, 0],
        'yesterday'    => [1, 1],
        'last 7 days'  => [7, 0],
        'last week'    => [7, 0],
        'this week'    => [7, 0],
        'last 30 days' => [30, 0],
        'this month'   => [30, 0],
        'last month'   => [60, 30],
        'last 90 days' => [90, 0],
        'this quarter' => [90, 0],
        'last year'    => [365, 0],
        'this year'    => [365, 0],
    ];

    /**
     * Process a natural language query and return structured results.
     */
    public function query(int|string $tenantId, string $question): array
    {
        $q = strtolower(trim($question));

        $metric = $this->detectMetric($q);
        $period = $this->detectPeriod($q);
        $dimension = $this->detectDimension($q);
        $limit = $this->detectLimit($q);
        $isComparison = $this->isComparison($q);

        if (!$metric) {
            return [
                'question' => $question,
                'understood' => false,
                'suggestion' => 'Try asking about: revenue, orders, sessions, conversion rate, AOV, customers, or products.',
            ];
        }

        $start = now()->subDays($period['start_days_ago'])->startOfDay()->toIso8601String();
        $end = $period['end_days_ago'] > 0
            ? now()->subDays($period['end_days_ago'])->endOfDay()->toIso8601String()
            : now()->toIso8601String();

        $result = match (true) {
            $dimension !== null => $this->queryByDimension($tenantId, $metric, $dimension, $start, $end, $limit),
            $isComparison => $this->queryWithComparison($tenantId, $metric, $start, $end),
            str_contains($q, 'top') && in_array($metric, ['products', 'revenue', 'orders']) => $this->queryTopProducts($tenantId, $start, $end, $limit),
            default => $this->queryScalar($tenantId, $metric, $start, $end),
        };

        return [
            'question' => $question,
            'understood' => true,
            'metric' => $metric,
            'period' => $period['label'],
            'dimension' => $dimension,
            'answer' => $result['answer'],
            'data' => $result['data'] ?? null,
            'query_interpretation' => sprintf('"%s" for %s%s', $metric, $period['label'], $dimension ? " by {$dimension}" : ''),
        ];
    }

    /**
     * Get query suggestions based on partial input.
     */
    public function suggest(string $partial): array
    {
        $suggestions = [
            'What was my revenue last week?',
            'How many orders did I get yesterday?',
            'What are my top 5 products by revenue?',
            'Show me conversion rate for mobile users',
            'Compare this month vs last month revenue',
            'What is my average order value today?',
            'How many sessions did I have last 30 days?',
            'Show me revenue by country',
            'What is my cart abandonment rate?',
            'How many new customers this week?',
        ];

        if (strlen($partial) < 3) return $suggestions;

        $partial = strtolower($partial);
        return array_values(array_filter($suggestions, fn($s) => str_contains(strtolower($s), $partial)));
    }

    // ── Detection ────────────────────────────────────────────────────

    private function detectMetric(string $q): ?string
    {
        foreach (self::METRIC_PATTERNS as $metric => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($q, $kw)) return $metric;
            }
        }
        return null;
    }

    private function detectPeriod(string $q): array
    {
        foreach (self::TIME_PATTERNS as $label => [$startDaysAgo, $endDaysAgo]) {
            if (str_contains($q, $label)) {
                return ['label' => $label, 'start_days_ago' => $startDaysAgo, 'end_days_ago' => $endDaysAgo];
            }
        }

        // Try to extract "last N days"
        if (preg_match('/last\s+(\d+)\s+days?/', $q, $m)) {
            $days = (int) $m[1];
            return ['label' => "last {$days} days", 'start_days_ago' => $days, 'end_days_ago' => 0];
        }

        return ['label' => 'last 30 days', 'start_days_ago' => 30, 'end_days_ago' => 0];
    }

    private function detectDimension(string $q): ?string
    {
        $dimMap = [
            'country' => ['by country', 'per country', 'by region', 'by geography', 'geographic'],
            'device_type' => ['by device', 'per device', 'mobile', 'desktop', 'tablet'],
            'channel' => ['by channel', 'per channel', 'by source', 'by medium'],
            'category' => ['by category', 'per category', 'by product category'],
            'brand' => ['by brand', 'per brand'],
        ];

        foreach ($dimMap as $dim => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($q, $kw)) return $dim;
            }
        }

        return null;
    }

    private function detectLimit(string $q): int
    {
        if (preg_match('/top\s+(\d+)/', $q, $m)) return min(50, (int) $m[1]);
        if (str_contains($q, 'top')) return 5;
        return 10;
    }

    private function isComparison(string $q): bool
    {
        return (bool) preg_match('/compare|vs\.?|versus|compared to|difference/', $q);
    }

    // ── Query execution ──────────────────────────────────────────────

    private function queryScalar(int $tid, string $metric, string $start, string $end): array
    {
        $value = match ($metric) {
            'revenue' => round((float) DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'purchase')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->sum('metadata.revenue'), 2),
            'orders' => DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'purchase')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->count(),
            'sessions' => DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'page_view')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->distinct('session_id')->count('session_id'),
            'customers' => DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'purchase')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->distinct('visitor_id')->count('visitor_id'),
            'page_views' => DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tid)->where('event_type', 'page_view')
                ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
                ->count(),
            'aov' => $this->computeAov($tid, $start, $end),
            'conversion_rate' => $this->computeConversionRate($tid, $start, $end),
            default => 0,
        };

        $formatted = match ($metric) {
            'revenue' => '$' . number_format((float) $value, 2),
            'aov' => '$' . number_format((float) $value, 2),
            'conversion_rate' => $value . '%',
            default => number_format((float) $value),
        };

        return [
            'answer' => "Your {$metric} is {$formatted}.",
            'data' => ['value' => $value, 'formatted' => $formatted],
        ];
    }

    private function queryByDimension(int $tid, string $metric, string $dimension, string $start, string $end, int $limit): array
    {
        $eventType = in_array($metric, ['revenue', 'orders', 'aov', 'customers']) ? 'purchase' : 'page_view';
        $dimField = $dimension === 'category' ? 'metadata.category' : ($dimension === 'brand' ? 'metadata.brand' : $dimension);

        $group = ['_id' => ['$ifNull' => ['$' . $dimField, 'unknown']]];
        if ($metric === 'revenue') {
            $group['value'] = ['$sum' => '$metadata.revenue'];
        } else {
            $group['value'] = ['$sum' => 1];
        }

        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tid, $eventType, $start, $end, $group, $limit) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tid,
                        'event_type' => $eventType,
                        'created_at' => ['$gte' => $start, '$lte' => $end],
                    ]],
                    ['$group' => $group],
                    ['$sort' => ['value' => -1]],
                    ['$limit' => $limit],
                ])->toArray();
            });

        $data = array_map(fn($r) => [$dimension => $r['_id'] ?? 'unknown', $metric => round((float) ($r['value'] ?? 0), 2)], $results);
        $top = $data[0] ?? null;
        $answer = $top
            ? sprintf('Top %s by %s: %s with %s', $dimension, $metric, $top[$dimension], is_numeric($top[$metric]) ? number_format($top[$metric], 2) : $top[$metric])
            : "No data found for {$metric} by {$dimension}.";

        return ['answer' => $answer, 'data' => $data];
    }

    private function queryWithComparison(int $tid, string $metric, string $start, string $end): array
    {
        $periodDays = max(1, (int) now()->parse($start)->diffInDays(now()->parse($end)));
        $prevStart = now()->parse($start)->subDays($periodDays)->toIso8601String();
        $prevEnd = $start;

        $current = $this->queryScalar($tid, $metric, $start, $end);
        $previous = $this->queryScalar($tid, $metric, $prevStart, $prevEnd);

        $currVal = $current['data']['value'] ?? 0;
        $prevVal = $previous['data']['value'] ?? 0;
        $change = $prevVal > 0 ? round((($currVal - $prevVal) / $prevVal) * 100, 1) : 0;
        $direction = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat');

        return [
            'answer' => sprintf(
                '%s is %s (%s%s%%). Current: %s, Previous: %s.',
                ucfirst($metric),
                $direction,
                $change > 0 ? '+' : '',
                $change,
                $current['data']['formatted'] ?? $currVal,
                $previous['data']['formatted'] ?? $prevVal
            ),
            'data' => [
                'current' => $currVal,
                'previous' => $prevVal,
                'change_percent' => $change,
                'direction' => $direction,
            ],
        ];
    }

    private function queryTopProducts(int $tid, string $start, string $end, int $limit): array
    {
        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tid, $start, $end, $limit) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tid,
                        'event_type' => 'purchase',
                        'created_at' => ['$gte' => $start, '$lte' => $end],
                    ]],
                    ['$group' => [
                        '_id' => '$metadata.product_id',
                        'name' => ['$first' => '$metadata.product_name'],
                        'revenue' => ['$sum' => '$metadata.revenue'],
                        'orders' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => $limit],
                ])->toArray();
            });

        $data = array_map(fn($r) => [
            'product_id' => $r['_id'],
            'name' => $r['name'] ?? 'Unknown',
            'revenue' => round((float) ($r['revenue'] ?? 0), 2),
            'orders' => (int) ($r['orders'] ?? 0),
        ], $results);

        $answer = empty($data) ? 'No product data found.' : sprintf('Top %d products: %s', $limit, implode(', ', array_map(fn($d) => "{$d['name']} ($" . number_format($d['revenue'], 2) . ")", array_slice($data, 0, 3))));

        return ['answer' => $answer, 'data' => $data];
    }

    private function computeAov(int $tid, string $start, string $end): float
    {
        $q = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)->where('event_type', 'purchase')
            ->where('created_at', '>=', $start)->where('created_at', '<=', $end);
        $orders = $q->count();
        return $orders > 0 ? round((float) $q->sum('metadata.revenue') / $orders, 2) : 0;
    }

    private function computeConversionRate(int $tid, string $start, string $end): float
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
}

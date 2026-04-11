<?php

namespace Modules\BusinessIntelligence\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Product Intelligence Service
 *
 * Reads from: synced_orders (items), synced_products
 * Powers: Product Leaderboard, Category BCG Matrix, Rising/Falling Stars
 */
class ProductIntelService
{
    private function tid(int|string $t): array
    {
        return [(int) $t, (string) $t];
    }

    /* ══════════════════════════════════════════════════════════════
     *  3.1 — PRODUCT PERFORMANCE LEADERBOARD
     * ══════════════════════════════════════════════════════════════ */

    public function leaderboard(int $tenantId, string $sortBy = 'revenue', ?Carbon $from = null, ?Carbon $to = null, int $limit = 50): array
    {
        $cacheKey = "bi:prod:lb:{$tenantId}:{$sortBy}:" . md5(($from ?? '') . ($to ?? '')) . ":{$limit}";
        return Cache::remember($cacheKey, now()->addMinutes(15), fn () => $this->computeLeaderboard($tenantId, $sortBy, $from, $to, $limit));
    }

    private function computeLeaderboard(int $tenantId, string $sortBy, ?Carbon $from, ?Carbon $to, int $limit): array
    {
        try {
            $tids = $this->tid($tenantId);
            $from = $from ?? Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'))->subDays(30)->startOfDay();
            $to   = $to ?? Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'));

            // Current period
            $current = $this->productAgg($tids, $from, $to);

            // Previous period (same length, before $from)
            $days    = $from->diffInDays($to);
            $prevTo  = $from->copy()->subSecond();
            $prevFrom = $prevTo->copy()->subDays($days)->startOfDay();
            $previous = $this->productAgg($tids, $prevFrom, $prevTo);

            // Merge + calculate growth
            $products = [];
            foreach ($current as $name => $c) {
                $p = $previous[$name] ?? ['revenue' => 0, 'qty' => 0, 'orders' => 0, 'discount' => 0];
                $growth = $p['revenue'] > 0 ? round(($c['revenue'] - $p['revenue']) / $p['revenue'] * 100, 1) : ($c['revenue'] > 0 ? 100 : 0);

                // Margin estimate: revenue minus discounts as a proxy (cost data often unavailable)
                $marginAmt = max(0, $c['revenue'] - abs($c['discount']));
                $marginPct = $c['revenue'] > 0 ? round($marginAmt / $c['revenue'] * 100, 1) : 0;

                $products[] = [
                    'name'         => $name,
                    'revenue'      => round($c['revenue'], 2),
                    'qty'          => $c['qty'],
                    'orders'       => $c['orders'],
                    'aov'          => $c['orders'] > 0 ? round($c['revenue'] / $c['orders'], 2) : 0,
                    'discount'     => round(abs($c['discount']), 2),
                    'margin'       => $marginAmt,
                    'margin_pct'   => $marginPct,
                    'growth'       => $growth,
                    'prev_revenue' => round($p['revenue'], 2),
                    'trend'        => $growth > 20 ? '↑↑' : ($growth > 5 ? '↑' : ($growth < -10 ? '↓' : '→')),
                ];
            }

            // Sort
            usort($products, fn ($a, $b) => $b[$sortBy] <=> $a[$sortBy]);

            // Assign rank
            foreach ($products as $i => &$p) {
                $p['rank'] = $i + 1;
            }

            return array_slice($products, 0, $limit);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] ProductIntel::leaderboard failed: ' . $e->getMessage());
            return [];
        }
    }

    private function productAgg(array $tids, Carbon $from, Carbon $to): array
    {
        $raw = DB::connection('mongodb')->table('synced_orders')
            ->raw(fn ($col) => $col->aggregate([
                ['$match' => [
                    'tenant_id'  => ['$in' => $tids],
                    'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($from->copy()->utc()->getTimestampMs()),
                                     '$lte' => new \MongoDB\BSON\UTCDateTime($to->copy()->utc()->getTimestampMs())],
                    'status'     => ['$nin' => ['cancelled', 'canceled']],
                ]],
                ['$unwind' => '$items'],
                ['$group' => [
                    '_id'      => '$items.name',
                    'revenue'  => ['$sum' => '$items.row_total'],
                    'qty'      => ['$sum' => '$items.qty'],
                    'discount' => ['$sum' => ['$ifNull' => ['$items.discount', 0]]],
                    'orders'   => ['$addToSet' => '$_id'],
                ]],
                ['$addFields' => ['orders' => ['$size' => '$orders']]],
            ], ['maxTimeMS' => 30000]));

        $map = [];
        foreach ($raw as $r) {
            $map[$r['_id'] ?? 'Unknown'] = [
                'revenue'  => $r['revenue'] ?? 0,
                'qty'      => $r['qty'] ?? 0,
                'orders'   => $r['orders'] ?? 0,
                'discount' => $r['discount'] ?? 0,
            ];
        }
        return $map;
    }

    /* ══════════════════════════════════════════════════════════════
     *  3.1b — RISING & FALLING STARS
     * ══════════════════════════════════════════════════════════════ */

    public function risingFallingStars(int $tenantId, int $limit = 10): array
    {
        try {
            $all = $this->leaderboard($tenantId, 'growth', limit: 200);

            $rising  = array_filter($all, fn ($p) => $p['growth'] > 20 && $p['revenue'] > 0);
            $falling = array_filter($all, fn ($p) => $p['growth'] < -10 && $p['prev_revenue'] > 500);

            usort($rising, fn ($a, $b) => $b['growth'] <=> $a['growth']);
            usort($falling, fn ($a, $b) => $a['growth'] <=> $b['growth']);

            return [
                'rising'  => array_slice(array_values($rising), 0, $limit),
                'falling' => array_slice(array_values($falling), 0, $limit),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] ProductIntel::risingFallingStars failed: ' . $e->getMessage());
            return ['rising' => [], 'falling' => []];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  3.2 — CATEGORY BCG MATRIX
     *  Plots categories by: revenue share (x) vs growth rate (y)
     * ══════════════════════════════════════════════════════════════ */

    public function categoryMatrix(int $tenantId): array
    {
        try {
            $tids = $this->tid($tenantId);
            $now  = Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'));

            // Build category → product name map from synced_products
            $prods = DB::connection('mongodb')->table('synced_products')
                ->whereIn('tenant_id', $tids)
                ->get(['name', 'categories']);
            $catMap = [];
            foreach ($prods as $p) {
                $cats = $p['categories'] ?? [];
                if (is_array($cats)) {
                    foreach ($cats as $c) {
                        $catName = is_array($c) ? ($c['name'] ?? 'Unknown') : (string) $c;
                        $catMap[$p['name'] ?? ''] = $catName;
                    }
                }
            }

            // Current period (30 days)
            $curFrom  = $now->copy()->subDays(30)->startOfDay();
            $curItems = $this->categoryAgg($tids, $curFrom, $now, $catMap);

            // Previous period (30 days before that)
            $prevTo   = $curFrom->copy()->subSecond();
            $prevFrom = $prevTo->copy()->subDays(30)->startOfDay();
            $prevItems = $this->categoryAgg($tids, $prevFrom, $prevTo, $catMap);

            $totalRevenue = array_sum(array_column($curItems, 'revenue')) ?: 1;
            $categories = [];

            foreach ($curItems as $cat => $c) {
                $p = $prevItems[$cat] ?? ['revenue' => 0, 'orders' => 0];
                $growth = $p['revenue'] > 0 ? round(($c['revenue'] - $p['revenue']) / $p['revenue'] * 100, 1) : ($c['revenue'] > 0 ? 100 : 0);
                $share  = round($c['revenue'] / $totalRevenue * 100, 1);

                $medianGrowth = 10; // threshold
                $medianShare  = 100 / max(count($curItems), 1);
                $quadrant = ($growth >= $medianGrowth)
                    ? ($share >= $medianShare ? 'star' : 'question_mark')
                    : ($share >= $medianShare ? 'cash_cow' : 'dog');

                $categories[] = [
                    'category' => $cat,
                    'revenue'  => round($c['revenue'], 2),
                    'orders'   => $c['orders'],
                    'share'    => $share,
                    'growth'   => $growth,
                    'quadrant' => $quadrant,
                ];
            }

            usort($categories, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
            return $categories;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] ProductIntel::categoryMatrix failed: ' . $e->getMessage());
            return [];
        }
    }

    private function categoryAgg(array $tids, Carbon $from, Carbon $to, array $catMap): array
    {
        $raw = DB::connection('mongodb')->table('synced_orders')
            ->raw(fn ($col) => $col->aggregate([
                ['$match' => [
                    'tenant_id'  => ['$in' => $tids],
                    'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($from->copy()->utc()->getTimestampMs()),
                                     '$lte' => new \MongoDB\BSON\UTCDateTime($to->copy()->utc()->getTimestampMs())],
                    'status'     => ['$nin' => ['cancelled', 'canceled']],
                ]],
                ['$unwind' => '$items'],
                ['$group' => [
                    '_id'     => '$items.name',
                    'revenue' => ['$sum' => '$items.row_total'],
                    'orders'  => ['$addToSet' => '$_id'],
                ]],
                ['$addFields' => ['orders' => ['$size' => '$orders']]],
            ], ['maxTimeMS' => 30000]));

        $cats = [];
        foreach ($raw as $r) {
            $productName = $r['_id'] ?? 'Unknown';
            $category    = $catMap[$productName] ?? 'Uncategorized';
            if (!isset($cats[$category])) {
                $cats[$category] = ['revenue' => 0, 'orders' => 0];
            }
            $cats[$category]['revenue'] += $r['revenue'] ?? 0;
            $cats[$category]['orders']  += $r['orders'] ?? 0;
        }

        return $cats;
    }

    /* ══════════════════════════════════════════════════════════════
     *  PARETO ANALYSIS (80/20 rule)
     * ══════════════════════════════════════════════════════════════ */

    public function paretoAnalysis(int $tenantId): array
    {
        try {
            $all = $this->leaderboard($tenantId, 'revenue', limit: 500);
            $totalRev = array_sum(array_column($all, 'revenue')) ?: 1;
            $totalProducts = count($all);

            $cumulative = 0;
            $top20pct   = (int) ceil($totalProducts * 0.2);
            $top20rev   = 0;
            $pareto     = [];

            foreach ($all as $i => $p) {
                $cumulative += $p['revenue'];
                $pareto[] = [
                    'rank'       => $i + 1,
                    'name'       => $p['name'],
                    'revenue'    => $p['revenue'],
                    'cumulative' => round($cumulative / $totalRev * 100, 1),
                ];
                if ($i < $top20pct) {
                    $top20rev += $p['revenue'];
                }
            }

            return [
                'total_products'   => $totalProducts,
                'top_20_pct_count' => $top20pct,
                'top_20_pct_rev'   => round($top20rev / $totalRev * 100, 1),
                'pareto_curve'     => array_slice($pareto, 0, 50),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] ProductIntel::paretoAnalysis failed: ' . $e->getMessage());
            return ['total_products' => 0, 'top_20_pct_count' => 0, 'top_20_pct_rev' => 0, 'pareto_curve' => []];
        }
    }
}

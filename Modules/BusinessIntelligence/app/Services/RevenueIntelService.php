<?php

namespace Modules\BusinessIntelligence\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Revenue Intelligence Service — the "hero" BI engine.
 *
 * Reads from: synced_orders (MongoDB)
 * Powers: Revenue Command Center, Trends, Breakdown Matrix, Margin Analysis
 */
class RevenueIntelService
{
    /* ── helper: cast tenant_id consistently ── */
    private function tid(int|string $t): array
    {
        return [(int) $t, (string) $t];
    }

    /* ══════════════════════════════════════════════════════════════
     *  1.1 — REVENUE COMMAND CENTER (KPIs + sparklines)
     * ══════════════════════════════════════════════════════════════ */

    /**
     * Get revenue KPIs for today, this week, this month, this year + comparison.
     */
    public function commandCenter(int $tenantId): array
    {
        return Cache::remember("bi:revenue:cc:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            return $this->computeCommandCenter($tenantId);
        });
    }

    private function computeCommandCenter(int $tenantId): array
    {
        try {
            $tids = $this->tid($tenantId);
            $tz   = config('ecom360.default_timezone', 'Asia/Kolkata');
            $now  = Carbon::now($tz);
            $col  = DB::connection('mongodb')->table('synced_orders');

            // Helper: aggregate revenue + count for a date range
            $agg = function (Carbon $from, Carbon $to) use ($tids) {
                $rows = DB::connection('mongodb')->table('synced_orders')
                    ->whereIn('tenant_id', $tids)
                    ->whereBetween('created_at', [$from->utc(), $to->utc()])
                    ->get(['grand_total', 'discount_amount', 'status']);

                $orders   = $rows->whereNotIn('status', ['cancelled', 'canceled', 'closed']);
                $refunded = $rows->whereIn('status', ['closed']);
                return [
                    'revenue'   => round($orders->sum('grand_total'), 2),
                    'net'       => round($orders->sum('grand_total') - $orders->sum('discount_amount'), 2),
                    'orders'    => $orders->count(),
                    'aov'       => $orders->count() ? round($orders->sum('grand_total') / $orders->count(), 2) : 0,
                    'refunds'   => round($refunded->sum('grand_total'), 2),
                    'discounts' => round($orders->sum('discount_amount'), 2),
                ];
            };

            $today     = $agg($now->copy()->startOfDay(), $now);
            $yesterday = $agg($now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay());
            $thisWeek  = $agg($now->copy()->startOfWeek(), $now);
            $lastWeek  = $agg($now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek());
            $thisMonth = $agg($now->copy()->startOfMonth(), $now);
            $lastMonth = $agg($now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth());
            $thisYear  = $agg($now->copy()->startOfYear(), $now);
            $lastYear  = $agg($now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear());

            $pct = fn ($cur, $prev) => $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : ($cur > 0 ? 100 : 0);

            return [
                'today'      => array_merge($today, ['change' => $pct($today['revenue'], $yesterday['revenue'])]),
                'this_week'  => array_merge($thisWeek, ['change' => $pct($thisWeek['revenue'], $lastWeek['revenue'])]),
                'this_month' => array_merge($thisMonth, ['change' => $pct($thisMonth['revenue'], $lastMonth['revenue'])]),
                'this_year'  => array_merge($thisYear, ['change' => $pct($thisYear['revenue'], $lastYear['revenue'])]),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BI query failed', [
                'method' => __METHOD__,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  1.1b — REVENUE BY HOUR (today) + BY DAY (this month)
     * ══════════════════════════════════════════════════════════════ */

    public function revenueByHour(int $tenantId): array
    {
        return Cache::remember("bi:revenue:hourly:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            return $this->computeRevenueByHour($tenantId);
        });
    }

    private function computeRevenueByHour(int $tenantId): array
    {
        try {
            $tids = $this->tid($tenantId);
            $now  = Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'));
            $from = $now->copy()->startOfDay()->utc();
            $to   = $now->copy()->endOfDay()->utc();

            $raw = DB::connection('mongodb')->table('synced_orders')
                ->raw(function ($col) use ($tids, $from, $to) {
                    return $col->aggregate([
                        ['$match' => [
                            'tenant_id' => ['$in' => $tids],
                            'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($from->getTimestampMs()),
                                             '$lte' => new \MongoDB\BSON\UTCDateTime($to->getTimestampMs())],
                            'status' => ['$nin' => ['cancelled', 'canceled']],
                        ]],
                        ['$group' => [
                            '_id' => ['$hour' => ['date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]],
                            'revenue' => ['$sum' => '$grand_total'],
                            'orders'  => ['$sum' => 1],
                        ]],
                        ['$sort' => ['_id' => 1]],
                    ], ['maxTimeMS' => 30000]);
                });

            $hours = array_fill(0, 24, ['revenue' => 0, 'orders' => 0]);
            foreach ($raw as $r) {
                $h = $r['_id'];
                $hours[$h] = ['revenue' => round($r['revenue'], 2), 'orders' => $r['orders']];
            }

            return array_map(fn ($d, $h) => ['hour' => $h, 'label' => sprintf('%02d:00', $h)] + $d, $hours, array_keys($hours));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BI query failed', [
                'method' => __METHOD__,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function revenueByDay(int $tenantId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $tz   = config('ecom360.default_timezone', 'Asia/Kolkata');
        $from = $from ?? Carbon::now($tz)->startOfMonth();
        $to   = $to ?? Carbon::now($tz);
        $cacheKey = "bi:revenue:daily:{$tenantId}:" . md5(($from ? $from->toDateString() : '') . ($to ? $to->toDateString() : ''));

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($tenantId, $from, $to) {
            return $this->computeRevenueByDay($tenantId, $from, $to);
        });
    }

    private function computeRevenueByDay(int $tenantId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        try {
            $tids = $this->tid($tenantId);
            $tz   = config('ecom360.default_timezone', 'Asia/Kolkata');
            $from = $from ?? Carbon::now($tz)->startOfMonth();
            $to   = $to ?? Carbon::now($tz);

            $raw = DB::connection('mongodb')->table('synced_orders')
                ->raw(function ($col) use ($tids, $from, $to) {
                    return $col->aggregate([
                        ['$match' => [
                            'tenant_id' => ['$in' => $tids],
                            'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($from->copy()->utc()->getTimestampMs()),
                                             '$lte' => new \MongoDB\BSON\UTCDateTime($to->copy()->utc()->getTimestampMs())],
                            'status' => ['$nin' => ['cancelled', 'canceled']],
                        ]],
                        ['$group' => [
                            '_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]],
                            'revenue' => ['$sum' => '$grand_total'],
                            'orders'  => ['$sum' => 1],
                        ]],
                        ['$sort' => ['_id' => 1]],
                    ], ['maxTimeMS' => 30000]);
                });

            return collect($raw)->map(fn ($r) => [
                'date'    => $r['_id'],
                'revenue' => round($r['revenue'], 2),
                'orders'  => $r['orders'],
            ])->values()->all();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BI query failed', [
                'method' => __METHOD__,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  1.2 — TREND ANALYSIS (moving averages + anomaly flags)
     * ══════════════════════════════════════════════════════════════ */

    public function trendAnalysis(int $tenantId, int $days = 90): array
    {
        try {
            $tids = $this->tid($tenantId);
            $from = Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'))->subDays($days)->startOfDay();
            $daily = $this->revenueByDay($tenantId, $from);

            $vals = array_column($daily, 'revenue');
            $n    = count($vals);

            // Moving averages
            $ma7  = $this->movingAvg($vals, 7);
            $ma30 = $this->movingAvg($vals, 30);

            // Anomaly detection (flag days deviating > 30% from 14-day rolling average)
            $ma14 = $this->movingAvg($vals, 14);
            $anomalies = [];
            foreach ($vals as $i => $v) {
                if ($ma14[$i] > 0) {
                    $dev = ($v - $ma14[$i]) / $ma14[$i];
                    if (abs($dev) > 0.30) {
                        $anomalies[] = [
                            'date'      => $daily[$i]['date'],
                            'revenue'   => $v,
                            'expected'  => round($ma14[$i], 2),
                            'deviation' => round($dev * 100, 1),
                            'type'      => $dev > 0 ? 'spike' : 'dip',
                        ];
                    }
                }
            }

            // Revenue velocity (7-day rate of change)
            $velocity = [];
            for ($i = 7; $i < $n; $i++) {
                $prev = $ma7[$i - 7] ?: 1;
                $velocity[] = ['date' => $daily[$i]['date'], 'velocity' => round(($ma7[$i] - $prev) / $prev * 100, 1)];
            }

            return [
                'daily'     => $daily,
                'ma7'       => array_map(fn ($v, $i) => ['date' => $daily[$i]['date'] ?? null, 'value' => round($v, 2)], $ma7, array_keys($ma7)),
                'ma30'      => array_map(fn ($v, $i) => ['date' => $daily[$i]['date'] ?? null, 'value' => round($v, 2)], $ma30, array_keys($ma30)),
                'anomalies' => $anomalies,
                'velocity'  => $velocity,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BI query failed', [
                'method' => __METHOD__,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return ['daily' => [], 'ma7' => [], 'ma30' => [], 'anomalies' => [], 'velocity' => []];
        }
    }

    private function movingAvg(array $vals, int $window): array
    {
        $result = [];
        $n = count($vals);
        for ($i = 0; $i < $n; $i++) {
            if ($i < $window - 1) {
                $result[] = $vals[$i]; // not enough data yet
            } else {
                $slice = array_slice($vals, $i - $window + 1, $window);
                $result[] = array_sum($slice) / $window;
            }
        }
        return $result;
    }

    /* ══════════════════════════════════════════════════════════════
     *  1.3 — REVENUE BREAKDOWN MATRIX
     * ══════════════════════════════════════════════════════════════ */

    public function revenueBreakdown(int $tenantId, string $dimension, ?Carbon $from = null, ?Carbon $to = null): array
    {
        try {
            $tids = $this->tid($tenantId);
            $from = $from ?? Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'))->subDays(30)->startOfDay();
            $to   = $to ?? Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'));

            $match = [
                'tenant_id' => ['$in' => $tids],
                'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($from->copy()->utc()->getTimestampMs()),
                                 '$lte' => new \MongoDB\BSON\UTCDateTime($to->copy()->utc()->getTimestampMs())],
                'status' => ['$nin' => ['cancelled', 'canceled']],
            ];

            $pipeline = match ($dimension) {
                'category' => [
                    ['$match' => $match],
                    ['$unwind' => '$items'],
                    ['$group' => ['_id' => '$items.name', 'revenue' => ['$sum' => '$items.row_total'], 'qty' => ['$sum' => '$items.qty'], 'orders' => ['$addToSet' => '$_id']]],
                    ['$addFields' => ['orders' => ['$size' => '$orders']]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => 50],
                ],
                'payment' => [
                    ['$match' => $match],
                    ['$group' => ['_id' => '$payment_method', 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                    ['$sort' => ['revenue' => -1]],
                ],
                'status' => [
                    ['$match' => array_diff_key($match, ['status' => 1])],
                    ['$group' => ['_id' => '$status', 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                    ['$sort' => ['revenue' => -1]],
                ],
                'coupon' => [
                    ['$match' => array_merge($match, ['coupon_code' => ['$ne' => null]])],
                    ['$group' => ['_id' => '$coupon_code', 'revenue' => ['$sum' => '$grand_total'], 'discount' => ['$sum' => '$discount_amount'], 'orders' => ['$sum' => 1]]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => 30],
                ],
                'day_of_week' => [
                    ['$match' => $match],
                    ['$group' => ['_id' => ['$dayOfWeek' => ['date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]], 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                    ['$sort' => ['_id' => 1]],
                ],
                'hour' => [
                    ['$match' => $match],
                    ['$group' => ['_id' => ['$hour' => ['date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]], 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                    ['$sort' => ['_id' => 1]],
                ],
                'new_vs_returning' => $this->newVsReturningPipeline($match),
                default => [
                    ['$match' => $match],
                    ['$group' => ['_id' => null, 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                ],
            };

            $raw = DB::connection('mongodb')->table('synced_orders')
                ->raw(fn ($col) => $col->aggregate($pipeline, ['maxTimeMS' => 30000]));

            return collect($raw)->map(fn ($r) => [
                'dimension' => $r['_id'] ?? 'N/A',
                'revenue'   => round($r['revenue'] ?? 0, 2),
                'orders'    => $r['orders'] ?? 0,
                'discount'  => round($r['discount'] ?? 0, 2),
                'qty'       => $r['qty'] ?? null,
            ])->values()->all();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BI query failed', [
                'method' => __METHOD__,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function newVsReturningPipeline(array $match): array
    {
        return [
            ['$match' => $match],
            ['$group' => [
                '_id' => '$customer_email',
                'order_count' => ['$sum' => 1],
                'revenue'     => ['$sum' => '$grand_total'],
                'first_order' => ['$min' => '$created_at'],
            ]],
            ['$group' => [
                '_id' => ['$cond' => [['$gt' => ['$order_count', 1]], 'Returning', 'New']],
                'revenue'   => ['$sum' => '$revenue'],
                'customers' => ['$sum' => 1],
                'orders'    => ['$sum' => '$order_count'],
            ]],
            ['$sort' => ['_id' => 1]],
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  1.4 — MARGIN ANALYSIS (if cost data known)
     * ══════════════════════════════════════════════════════════════ */

    public function marginAnalysis(int $tenantId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        try {
            $tids = $this->tid($tenantId);
            $from = $from ?? Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'))->subDays(30)->startOfDay();
            $to   = $to ?? Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'));

            // Get product cost map from synced_products
            $products = DB::connection('mongodb')->table('synced_products')
                ->whereIn('tenant_id', $tids)
                ->get(['external_id', 'name', 'price', 'categories']);

            $costMap = [];
            foreach ($products as $p) {
                $costMap[$p['external_id'] ?? ''] = [
                    'name'       => $p['name'] ?? 'Unknown',
                    'price'      => $p['price'] ?? 0,
                    'categories' => $p['categories'] ?? [],
                ];
            }

            // Get order items
            $orders = DB::connection('mongodb')->table('synced_orders')
                ->whereIn('tenant_id', $tids)
                ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
                ->whereNotIn('status', ['cancelled', 'canceled'])
                ->get(['items']);

            $productStats = [];
            foreach ($orders as $o) {
                foreach ($o['items'] ?? [] as $item) {
                    $pid = $item['product_id'] ?? $item['sku'] ?? 'unknown';
                    if (!isset($productStats[$pid])) {
                        $productStats[$pid] = [
                            'name'     => $item['name'] ?? $costMap[$pid]['name'] ?? 'Unknown',
                            'revenue'  => 0,
                            'qty'      => 0,
                            'discount' => 0,
                        ];
                    }
                    $productStats[$pid]['revenue']  += ($item['row_total'] ?? 0);
                    $productStats[$pid]['qty']      += ($item['qty'] ?? 0);
                    $productStats[$pid]['discount'] += ($item['discount'] ?? 0);
                }
            }

            // Sort by revenue desc
            uasort($productStats, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

            $result = [];
            $rank = 0;
            foreach (array_slice($productStats, 0, 50, true) as $pid => $s) {
                $rank++;
                $result[] = [
                    'rank'     => $rank,
                    'product'  => $s['name'],
                    'revenue'  => round($s['revenue'], 2),
                    'qty'      => $s['qty'],
                    'discount' => round($s['discount'], 2),
                    'net'      => round($s['revenue'] - $s['discount'], 2),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BI query failed', [
                'method' => __METHOD__,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  TOP PERFORMERS (quick summary)
     * ══════════════════════════════════════════════════════════════ */

    public function topPerformers(int $tenantId, ?Carbon $from = null, ?Carbon $to = null, int $limit = 10): array
    {
        try {
            $tids = $this->tid($tenantId);
            $from = $from ?? Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'))->subDays(30)->startOfDay();
            $to   = $to ?? Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'));

            $match = [
                'tenant_id' => ['$in' => $tids],
                'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($from->copy()->utc()->getTimestampMs()),
                                 '$lte' => new \MongoDB\BSON\UTCDateTime($to->copy()->utc()->getTimestampMs())],
                'status' => ['$nin' => ['cancelled', 'canceled']],
            ];

            // Top products by revenue
            $products = DB::connection('mongodb')->table('synced_orders')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => $match],
                    ['$unwind' => '$items'],
                    ['$group' => ['_id' => '$items.name', 'revenue' => ['$sum' => '$items.row_total'], 'qty' => ['$sum' => '$items.qty']]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => $limit],
                ], ['maxTimeMS' => 30000]));

            // Top payment methods
            $payments = DB::connection('mongodb')->table('synced_orders')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => $match],
                    ['$group' => ['_id' => '$payment_method', 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => 5],
                ], ['maxTimeMS' => 30000]));

            return [
                'products' => collect($products)->map(fn ($r) => ['name' => $r['_id'], 'revenue' => round($r['revenue'], 2), 'qty' => $r['qty']])->values()->all(),
                'payments' => collect($payments)->map(fn ($r) => ['method' => $r['_id'] ?? 'Unknown', 'revenue' => round($r['revenue'], 2), 'orders' => $r['orders']])->values()->all(),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BI query failed', [
                'method' => __METHOD__,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return ['products' => [], 'payments' => []];
        }
    }
}

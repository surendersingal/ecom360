<?php

namespace Modules\BusinessIntelligence\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Customer Intelligence Service
 *
 * Reads from: synced_customers, synced_orders, cdp_profiles
 * Powers: Customer Overview, Cohort Retention, Value Distribution, Journey
 */
class CustomerIntelService
{
    private function tid(int|string $t): array
    {
        return [(int) $t, (string) $t];
    }

    /* ══════════════════════════════════════════════════════════════
     *  2.1 — CUSTOMER OVERVIEW KPIs
     * ══════════════════════════════════════════════════════════════ */

    public function overview(int $tenantId): array
    {
        return Cache::remember("bi:cust:overview:{$tenantId}", now()->addMinutes(15), fn () => $this->computeOverview($tenantId));
    }

    private function computeOverview(int $tenantId): array
    {
        try {
            $tids = $this->tid($tenantId);
            $now  = Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'));

            $totalCustomers = DB::connection('mongodb')->table('synced_customers')
                ->whereIn('tenant_id', $tids)->count();

            // Customers with orders
            $custOrders = DB::connection('mongodb')->table('synced_orders')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                    ['$group' => [
                        '_id' => '$customer_email',
                        'orders'   => ['$sum' => 1],
                        'revenue'  => ['$sum' => '$grand_total'],
                        'first'    => ['$min' => '$created_at'],
                        'last'     => ['$max' => '$created_at'],
                    ]],
                ], ['maxTimeMS' => 30000]));

            $custs = collect($custOrders);
            $withOrders = $custs->count();

            // Active in last 30 days
            $active30 = $custs->filter(function ($c) use ($now) {
                $last = $c['last'] ?? null;
                if (!$last) return false;
                $ts = $last instanceof \MongoDB\BSON\UTCDateTime ? $last->toDateTime() : new \DateTime($last);
                return Carbon::instance($ts)->gte($now->copy()->subDays(30));
            })->count();

            // New this month
            $monthStart = $now->copy()->startOfMonth()->utc();
            $newThisMonth = DB::connection('mongodb')->table('synced_customers')
                ->whereIn('tenant_id', $tids)
                ->where('created_at', '>=', $monthStart)
                ->count();

            // Repeat rate
            $repeaters = $custs->filter(fn ($c) => ($c['orders'] ?? 0) > 1)->count();
            $repeatRate = $withOrders > 0 ? round($repeaters / $withOrders * 100, 1) : 0;

            // Average LTV + AOV
            $avgLtv = $withOrders > 0 ? round($custs->avg('revenue'), 2) : 0;
            $totalOrders = $custs->sum('orders');
            $totalRevenue = $custs->sum('revenue');
            $avgOrdersPerCust = $withOrders > 0 ? round($totalOrders / $withOrders, 1) : 0;

            // Churn (no order in 90 days but had one before)
            $churned = $custs->filter(function ($c) use ($now) {
                $last = $c['last'] ?? null;
                if (!$last) return false;
                $ts = $last instanceof \MongoDB\BSON\UTCDateTime ? $last->toDateTime() : new \DateTime($last);
                return Carbon::instance($ts)->lt($now->copy()->subDays(90));
            })->count();
            $churnRate = $withOrders > 0 ? round($churned / $withOrders * 100, 1) : 0;

            return [
                'total_customers'     => $totalCustomers,
                'with_orders'         => $withOrders,
                'active_30d'          => $active30,
                'active_30d_pct'      => $withOrders > 0 ? round($active30 / $withOrders * 100, 1) : 0,
                'new_this_month'      => $newThisMonth,
                'repeat_purchase_rate' => $repeatRate,
                'avg_ltv'             => $avgLtv,
                'avg_orders_per_cust' => $avgOrdersPerCust,
                'churn_rate_90d'      => $churnRate,
                'total_revenue'       => round($totalRevenue, 2),
                'total_orders'        => $totalOrders,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BI query failed', [
                'method' => __METHOD__,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return ['total_customers' => 0, 'with_orders' => 0, 'active_30d' => 0, 'active_30d_pct' => 0, 'new_this_month' => 0, 'repeat_purchase_rate' => 0, 'avg_ltv' => 0, 'avg_orders_per_cust' => 0, 'churn_rate_90d' => 0, 'total_revenue' => 0, 'total_orders' => 0];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  2.1b — CUSTOMER ACQUISITION TREND (last 12 months)
     * ══════════════════════════════════════════════════════════════ */

    public function acquisitionTrend(int $tenantId, int $months = 12): array
    {
        try {
            $tids = $this->tid($tenantId);
            $from = Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'))->subMonths($months)->startOfMonth();

            $raw = DB::connection('mongodb')->table('synced_customers')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => [
                        'tenant_id'  => ['$in' => $tids],
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($from->copy()->utc()->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'   => ['$dateToString' => ['format' => '%Y-%m', 'date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]],
                        'count' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['_id' => 1]],
                ], ['maxTimeMS' => 30000]));

            return collect($raw)->map(fn ($r) => ['month' => $r['_id'], 'new_customers' => $r['count']])->values()->all();
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
     *  2.1c — GEOGRAPHIC DISTRIBUTION (billing address)
     * ══════════════════════════════════════════════════════════════ */

    public function geoDistribution(int $tenantId, int $limit = 20): array
    {
        try {
            $tids = $this->tid($tenantId);

            $raw = DB::connection('mongodb')->table('synced_orders')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => [
                        'tenant_id' => ['$in' => $tids],
                        'status'    => ['$nin' => ['cancelled', 'canceled']],
                        'billing_address.city' => ['$exists' => true, '$ne' => null],
                    ]],
                    ['$group' => [
                        '_id'     => ['$toLower' => '$billing_address.city'],
                        'revenue' => ['$sum' => '$grand_total'],
                        'orders'  => ['$sum' => 1],
                        'customers' => ['$addToSet' => '$customer_email'],
                    ]],
                    ['$addFields' => ['customers' => ['$size' => '$customers']]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => $limit],
                ], ['maxTimeMS' => 30000]));

            return collect($raw)->map(fn ($r) => [
                'city'      => ucwords($r['_id'] ?? 'Unknown'),
                'revenue'   => round($r['revenue'], 2),
                'orders'    => $r['orders'],
                'customers' => $r['customers'],
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
     *  2.4 — COHORT RETENTION ANALYSIS
     * ══════════════════════════════════════════════════════════════ */

    public function cohortRetention(int $tenantId, int $months = 6): array
    {
        return Cache::remember("bi:cust:cohort:{$tenantId}:{$months}", now()->addMinutes(15), fn () => $this->computeCohortRetention($tenantId, $months));
    }

    private function computeCohortRetention(int $tenantId, int $months = 6): array
    {
        try {
            $tids = $this->tid($tenantId);
            $from = Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'))->subMonths($months)->startOfMonth();

            // Get all orders
            $orders = DB::connection('mongodb')->table('synced_orders')
                ->whereIn('tenant_id', $tids)
                ->where('created_at', '>=', $from->copy()->utc())
                ->whereNotIn('status', ['cancelled', 'canceled'])
                ->get(['customer_email', 'created_at']);

            // Build customer order timeline
            $customerOrders = [];
            foreach ($orders as $o) {
                $email = $o['customer_email'] ?? null;
                if (!$email) continue;

                $dt = $o['created_at'];
                if ($dt instanceof \MongoDB\BSON\UTCDateTime) {
                    $dt = Carbon::instance($dt->toDateTime())->setTimezone(config('ecom360.default_timezone', 'Asia/Kolkata'));
                } else {
                    $dt = Carbon::parse($dt)->setTimezone(config('ecom360.default_timezone', 'Asia/Kolkata'));
                }
                $month = $dt->format('Y-m');

                $customerOrders[$email][] = $month;
            }

            // Assign each customer to first-purchase cohort
            $cohorts = [];
            foreach ($customerOrders as $email => $months_list) {
                sort($months_list);
                $cohortMonth = $months_list[0];
                $uniqueMonths = array_unique($months_list);

                if (!isset($cohorts[$cohortMonth])) {
                    $cohorts[$cohortMonth] = ['size' => 0, 'months' => []];
                }
                $cohorts[$cohortMonth]['size']++;

                foreach ($uniqueMonths as $m) {
                    if (!isset($cohorts[$cohortMonth]['months'][$m])) {
                        $cohorts[$cohortMonth]['months'][$m] = 0;
                    }
                    $cohorts[$cohortMonth]['months'][$m]++;
                }
            }

            // Build retention table
            ksort($cohorts);
            $table = [];

            foreach ($cohorts as $cohortMonth => $data) {
                $size  = $data['size'];
                $row   = ['cohort' => $cohortMonth, 'size' => $size, 'retention' => []];
                $start = Carbon::parse($cohortMonth . '-01');

                // M+0 through M+$months
                for ($i = 0; $i <= $months; $i++) {
                    $checkMonth = $start->copy()->addMonths($i)->format('Y-m');
                    $active     = $data['months'][$checkMonth] ?? 0;
                    $row['retention'][] = [
                        'month'   => 'M+' . $i,
                        'active'  => $active,
                        'rate'    => $size > 0 ? round($active / $size * 100, 1) : 0,
                    ];
                }

                $table[] = $row;
            }

            return $table;
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
     *  2.3 — CUSTOMER VALUE DISTRIBUTION HISTOGRAM
     * ══════════════════════════════════════════════════════════════ */

    public function valueDistribution(int $tenantId): array
    {
        try {
            $tids = $this->tid($tenantId);

            $raw = DB::connection('mongodb')->table('synced_orders')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                    ['$group' => ['_id' => '$customer_email', 'ltv' => ['$sum' => '$grand_total']]],
                ], ['maxTimeMS' => 30000]));

            $ltvs = collect($raw)->pluck('ltv')->sort()->values();

            // Build histogram buckets
            $buckets = [
                ['range' => '₹0-500',    'min' => 0,     'max' => 500],
                ['range' => '₹500-2K',   'min' => 500,   'max' => 2000],
                ['range' => '₹2K-5K',    'min' => 2000,  'max' => 5000],
                ['range' => '₹5K-10K',   'min' => 5000,  'max' => 10000],
                ['range' => '₹10K-25K',  'min' => 10000, 'max' => 25000],
                ['range' => '₹25K-50K',  'min' => 25000, 'max' => 50000],
                ['range' => '₹50K-100K', 'min' => 50000, 'max' => 100000],
                ['range' => '₹100K+',    'min' => 100000, 'max' => PHP_INT_MAX],
            ];

            foreach ($buckets as &$b) {
                $b['count'] = $ltvs->filter(fn ($v) => $v >= $b['min'] && $v < $b['max'])->count();
                unset($b['min'], $b['max']);
            }

            return [
                'total_customers' => $ltvs->count(),
                'avg_ltv'         => round($ltvs->avg() ?? 0, 2),
                'median_ltv'      => round($ltvs->median() ?? 0, 2),
                'max_ltv'         => round($ltvs->max() ?? 0, 2),
                'distribution'    => $buckets,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] CustomerIntelService::valueDistribution failed: ' . $e->getMessage());
            return ['total_customers' => 0, 'avg_ltv' => 0, 'median_ltv' => 0, 'max_ltv' => 0, 'distribution' => []];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  New vs Returning ratio over time
     * ══════════════════════════════════════════════════════════════ */

    public function newVsReturning(int $tenantId, int $months = 6): array
    {
        try {
            $tids = $this->tid($tenantId);
            $from = Carbon::now(config('ecom360.default_timezone', 'Asia/Kolkata'))->subMonths($months)->startOfMonth();

            $orders = DB::connection('mongodb')->table('synced_orders')
                ->whereIn('tenant_id', $tids)
                ->where('created_at', '>=', $from->copy()->utc())
                ->whereNotIn('status', ['cancelled', 'canceled'])
                ->orderBy('created_at')
                ->get(['customer_email', 'created_at']);

            $known = [];
            $monthly = [];

            foreach ($orders as $o) {
                $email = $o['customer_email'] ?? null;
                if (!$email) continue;

                $dt = $o['created_at'];
                if ($dt instanceof \MongoDB\BSON\UTCDateTime) {
                    $dt = Carbon::instance($dt->toDateTime())->setTimezone(config('ecom360.default_timezone', 'Asia/Kolkata'));
                } else {
                    $dt = Carbon::parse($dt)->setTimezone(config('ecom360.default_timezone', 'Asia/Kolkata'));
                }
                $m = $dt->format('Y-m');

                if (!isset($monthly[$m])) {
                    $monthly[$m] = ['new' => 0, 'returning' => 0, 'new_revenue' => 0, 'returning_revenue' => 0];
                }

                if (!isset($known[$email])) {
                    $known[$email] = true;
                    $monthly[$m]['new']++;
                } else {
                    $monthly[$m]['returning']++;
                }
            }

            ksort($monthly);
            return collect($monthly)->map(fn ($d, $m) => ['month' => $m] + $d)->values()->all();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] CustomerIntelService::newVsReturning failed: ' . $e->getMessage());
            return [];
        }
    }
}

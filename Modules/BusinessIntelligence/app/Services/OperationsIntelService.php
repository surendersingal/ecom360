<?php

namespace Modules\BusinessIntelligence\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operations Intelligence Service
 *
 * Reads from: synced_orders, tracking_events, search_logs
 * Powers: Order Pipeline, Cross-Module Heatmap, Coupon Intelligence
 */
class OperationsIntelService
{
    private function tid(int|string $t): array
    {
        return [(int) $t, (string) $t];
    }

    /* ══════════════════════════════════════════════════════════════
     *  4.1 — ORDER PIPELINE (counts + value by status)
     * ══════════════════════════════════════════════════════════════ */

    public function orderPipeline(int $tenantId): array
    {
        $tids = $this->tid($tenantId);

        $raw = DB::connection('mongodb')->table('synced_orders')
            ->raw(fn ($col) => $col->aggregate([
                ['$match' => ['tenant_id' => ['$in' => $tids]]],
                ['$group' => [
                    '_id'     => '$status',
                    'count'   => ['$sum' => 1],
                    'revenue' => ['$sum' => '$grand_total'],
                    'avg'     => ['$avg' => '$grand_total'],
                ]],
                ['$sort' => ['count' => -1]],
            ]));

        $stages = collect($raw)->map(fn ($r) => [
            'status'  => $r['_id'] ?? 'unknown',
            'count'   => $r['count'],
            'revenue' => round($r['revenue'], 2),
            'avg'     => round($r['avg'], 2),
        ])->values()->all();

        $total = collect($stages)->sum('count');
        $totalValue = collect($stages)->sum('revenue');

        // At-risk statuses
        $riskStatuses = ['pending', 'holded', 'fraud', 'payment_review'];
        $atRisk = collect($stages)->filter(fn ($s) => in_array($s['status'], $riskStatuses));

        return [
            'stages'         => $stages,
            'total_orders'   => $total,
            'total_value'    => round($totalValue, 2),
            'at_risk_count'  => $atRisk->sum('count'),
            'at_risk_value'  => round($atRisk->sum('revenue'), 2),
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  4.1b — DAILY ORDER VOLUME (last 30 days)
     * ══════════════════════════════════════════════════════════════ */

    public function dailyOrderVolume(int $tenantId, int $days = 30): array
    {
        $tids = $this->tid($tenantId);
        $from = Carbon::now('Asia/Kolkata')->subDays($days)->startOfDay();

        $raw = DB::connection('mongodb')->table('synced_orders')
            ->raw(fn ($col) => $col->aggregate([
                ['$match' => [
                    'tenant_id'  => ['$in' => $tids],
                    'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($from->copy()->utc()->getTimestampMs())],
                ]],
                ['$group' => [
                    '_id'     => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at', 'timezone' => 'Asia/Kolkata']],
                    'orders'  => ['$sum' => 1],
                    'revenue' => ['$sum' => '$grand_total'],
                ]],
                ['$sort' => ['_id' => 1]],
            ]));

        return collect($raw)->map(fn ($r) => [
            'date'    => $r['_id'],
            'orders'  => $r['orders'],
            'revenue' => round($r['revenue'], 2),
        ])->values()->all();
    }

    /* ══════════════════════════════════════════════════════════════
     *  4.2 — CROSS-MODULE ACTIVITY HEATMAP (hour × day_of_week)
     * ══════════════════════════════════════════════════════════════ */

    public function activityHeatmap(int $tenantId): array
    {
        $tids = $this->tid($tenantId);
        $tz   = 'Asia/Kolkata';

        // Orders heatmap
        $orderHeat = DB::connection('mongodb')->table('synced_orders')
            ->raw(fn ($col) => $col->aggregate([
                ['$match' => ['tenant_id' => ['$in' => $tids]]],
                ['$project' => [
                    'dow'  => ['$dayOfWeek'  => ['date' => '$created_at', 'timezone' => $tz]],
                    'hour' => ['$hour'       => ['date' => '$created_at', 'timezone' => $tz]],
                ]],
                ['$group' => ['_id' => ['dow' => '$dow', 'hour' => '$hour'], 'count' => ['$sum' => 1]]],
            ]));

        // Tracking events heatmap
        $eventHeat = DB::connection('mongodb')->table('tracking_events')
            ->raw(fn ($col) => $col->aggregate([
                ['$match' => ['tenant_id' => ['$in' => $tids]]],
                ['$project' => [
                    'dow'  => ['$dayOfWeek'  => ['date' => '$created_at', 'timezone' => $tz]],
                    'hour' => ['$hour'       => ['date' => '$created_at', 'timezone' => $tz]],
                ]],
                ['$group' => ['_id' => ['dow' => '$dow', 'hour' => '$hour'], 'count' => ['$sum' => 1]]],
            ]));

        // Search heatmap
        $searchHeat = DB::connection('mongodb')->table('search_logs')
            ->raw(fn ($col) => $col->aggregate([
                ['$match' => ['tenant_id' => ['$in' => $tids]]],
                ['$project' => [
                    'dow'  => ['$dayOfWeek'  => ['date' => '$created_at', 'timezone' => $tz]],
                    'hour' => ['$hour'       => ['date' => '$created_at', 'timezone' => $tz]],
                ]],
                ['$group' => ['_id' => ['dow' => '$dow', 'hour' => '$hour'], 'count' => ['$sum' => 1]]],
            ]));

        $formatHeatmap = function ($raw) {
            $grid = [];
            foreach ($raw as $r) {
                $dow  = $r['_id']['dow'] ?? 1; // 1=Sun...7=Sat
                $hour = $r['_id']['hour'] ?? 0;
                $grid[] = ['day' => $dow, 'hour' => $hour, 'value' => $r['count']];
            }
            return $grid;
        };

        return [
            'orders'  => $formatHeatmap($orderHeat),
            'events'  => $formatHeatmap($eventHeat),
            'search'  => $formatHeatmap($searchHeat),
            'day_labels' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  4.3 — COUPON INTELLIGENCE
     * ══════════════════════════════════════════════════════════════ */

    public function couponIntelligence(int $tenantId): array
    {
        $tids = $this->tid($tenantId);

        $raw = DB::connection('mongodb')->table('synced_orders')
            ->raw(fn ($col) => $col->aggregate([
                ['$match' => [
                    'tenant_id'   => ['$in' => $tids],
                    'coupon_code' => ['$exists' => true, '$ne' => null, '$ne' => ''],
                    'status'      => ['$nin' => ['cancelled', 'canceled']],
                ]],
                ['$group' => [
                    '_id'      => ['$toUpper' => '$coupon_code'],
                    'uses'     => ['$sum' => 1],
                    'revenue'  => ['$sum' => '$grand_total'],
                    'discount' => ['$sum' => '$discount_amount'],
                    'avg_order'=> ['$avg' => '$grand_total'],
                    'customers'=> ['$addToSet' => '$customer_email'],
                ]],
                ['$addFields' => ['unique_customers' => ['$size' => '$customers']]],
                ['$project' => ['customers' => 0]],
                ['$sort' => ['revenue' => -1]],
            ]));

        $coupons = collect($raw)->map(function ($r) {
            $discount = abs($r['discount'] ?? 0);
            $revenue  = $r['revenue'] ?? 0;
            $roi      = $discount > 0 ? round(($revenue - $discount) / $discount, 2) : 0;
            return [
                'code'             => $r['_id'],
                'uses'             => $r['uses'],
                'revenue'          => round($revenue, 2),
                'total_discount'   => round($discount, 2),
                'avg_order_value'  => round($r['avg_order'], 2),
                'unique_customers' => $r['unique_customers'],
                'roi'              => $roi,
                'uses_per_customer'=> $r['unique_customers'] > 0 ? round($r['uses'] / $r['unique_customers'], 1) : 0,
            ];
        })->values();

        // Summary
        $totalWithCoupon = $coupons->sum('uses');
        $totalAllOrders  = DB::connection('mongodb')->table('synced_orders')
            ->whereIn('tenant_id', $tids)
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->count();

        // Abuse detection: >3 uses per customer
        $abuseSuspects = $coupons->filter(fn ($c) => $c['uses_per_customer'] > 3)->values();

        return [
            'coupons'              => $coupons->all(),
            'total_coupon_orders'  => $totalWithCoupon,
            'total_orders'         => $totalAllOrders,
            'coupon_usage_rate'    => $totalAllOrders > 0 ? round($totalWithCoupon / $totalAllOrders * 100, 1) : 0,
            'total_discount_given' => round($coupons->sum('total_discount'), 2),
            'total_coupon_revenue' => round($coupons->sum('revenue'), 2),
            'abuse_suspects'       => $abuseSuspects->all(),
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  4.1c — PAYMENT METHOD ANALYSIS
     * ══════════════════════════════════════════════════════════════ */

    public function paymentAnalysis(int $tenantId): array
    {
        $tids = $this->tid($tenantId);

        $raw = DB::connection('mongodb')->table('synced_orders')
            ->raw(fn ($col) => $col->aggregate([
                ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                ['$group' => [
                    '_id'     => '$payment_method',
                    'count'   => ['$sum' => 1],
                    'revenue' => ['$sum' => '$grand_total'],
                    'avg'     => ['$avg' => '$grand_total'],
                ]],
                ['$sort' => ['revenue' => -1]],
            ]));

        return collect($raw)->map(fn ($r) => [
            'method'  => $r['_id'] ?? 'unknown',
            'orders'  => $r['count'],
            'revenue' => round($r['revenue'], 2),
            'aov'     => round($r['avg'], 2),
        ])->values()->all();
    }
}

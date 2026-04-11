<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BusinessIntelligence\Models\Kpi;

/**
 * Calculates and manages Key Performance Indicators (KPIs).
 * Supports real-time and cached metric computation with period comparisons.
 *
 * Built-in metrics:
 *   revenue, orders, aov, customers, sessions, conversion_rate,
 *   cart_abandonment_rate, clv, returning_customer_rate, bounce_rate
 */
final class KpiService
{
    // ─── CRUD Methods ─────────────────────────────────────────────

    /**
     * List all KPIs for a tenant.
     */
    public function list(int $tenantId): \Illuminate\Database\Eloquent\Collection
    {
        return Kpi::where('tenant_id', $tenantId)->orderByDesc('updated_at')->get();
    }

    /**
     * Create a new KPI for a tenant.
     */
    public function create(int $tenantId, array $data): Kpi
    {
        return Kpi::create(array_merge($data, [
            'tenant_id' => $tenantId,
            'is_active' => $data['is_active'] ?? true,
            'refresh_interval' => $data['refresh_interval'] ?? 15,
        ]));
    }

    /**
     * Find a single KPI by ID scoped to tenant.
     */
    public function find(int $tenantId, int $id): ?Kpi
    {
        return Kpi::where('tenant_id', $tenantId)->find($id);
    }

    /**
     * Update a KPI.
     */
    public function update(int $tenantId, int $id, array $data): Kpi
    {
        $kpi = Kpi::where('tenant_id', $tenantId)->findOrFail($id);
        $kpi->update($data);
        return $kpi->fresh();
    }

    /**
     * Delete a KPI.
     */
    public function delete(int $tenantId, int $id): void
    {
        Kpi::where('tenant_id', $tenantId)->findOrFail($id)->delete();
    }

    // ─── Refresh & Dashboard ─────────────────────────────────────

    /**
     * Refresh all active KPIs for a tenant.
     */
    public function refreshAll(int $tenantId): int
    {
        $kpis = Kpi::where('tenant_id', $tenantId)->where('is_active', true)->get();
        $refreshed = 0;

        foreach ($kpis as $kpi) {
            try {
                $this->refresh($kpi);
                $refreshed++;
            } catch (\Throwable $e) {
                Log::error("[KpiService] Failed to refresh KPI #{$kpi->id}: {$e->getMessage()}");
            }
        }

        return $refreshed;
    }

    /**
     * Refresh a single KPI: calculate current and previous period values.
     */
    public function refresh(Kpi $kpi): void
    {
        $calculation = $kpi->calculation ?? [];
        $metric = $kpi->metric;

        $currentValue = $this->calculate($kpi->tenant_id, $metric, $calculation, 'current');
        $previousValue = $this->calculate($kpi->tenant_id, $metric, $calculation, 'previous');

        $kpi->update([
            'current_value' => $currentValue,
            'previous_value' => $previousValue,
        ]);

        // Cache for dashboard widgets
        Cache::put(
            "kpi:{$kpi->tenant_id}:{$metric}",
            ['current' => $currentValue, 'previous' => $previousValue, 'updated_at' => now()->toIso8601String()],
            now()->addMinutes((int) ($kpi->refresh_interval ?? 15))
        );
    }

    /**
     * Get all KPI values for a tenant (from cache or recalculate).
     */
    public function getDashboard(int $tenantId): array
    {
        $kpis = Kpi::where('tenant_id', $tenantId)->where('is_active', true)->get();
        $result = [];

        foreach ($kpis as $kpi) {
            $cached = Cache::get("kpi:{$tenantId}:{$kpi->metric}");
            if ($cached) {
                $result[] = [
                    'id' => $kpi->id,
                    'name' => $kpi->name,
                    'metric' => $kpi->metric,
                    'current_value' => $cached['current'],
                    'previous_value' => $cached['previous'],
                    'change_percent' => $kpi->change_percent,
                    'target_value' => $kpi->target_value,
                    'is_on_track' => $kpi->is_on_track,
                    'unit' => $kpi->unit,
                    'direction' => $kpi->direction,
                    'category' => $kpi->category,
                ];
            } else {
                $this->refresh($kpi);
                $result[] = [
                    'id' => $kpi->id,
                    'name' => $kpi->name,
                    'metric' => $kpi->metric,
                    'current_value' => $kpi->current_value,
                    'previous_value' => $kpi->previous_value,
                    'change_percent' => $kpi->change_percent,
                    'target_value' => $kpi->target_value,
                    'is_on_track' => $kpi->is_on_track,
                    'unit' => $kpi->unit,
                    'direction' => $kpi->direction,
                    'category' => $kpi->category,
                ];
            }
        }

        return $result;
    }

    /**
     * Create default KPIs for a new tenant.
     */
    public function createDefaults(int $tenantId): void
    {
        $defaults = [
            ['name' => 'Revenue', 'metric' => 'revenue', 'unit' => 'currency', 'direction' => 'up', 'category' => 'revenue'],
            ['name' => 'Orders', 'metric' => 'orders', 'unit' => 'number', 'direction' => 'up', 'category' => 'revenue'],
            ['name' => 'Average Order Value', 'metric' => 'aov', 'unit' => 'currency', 'direction' => 'up', 'category' => 'revenue'],
            ['name' => 'Customers', 'metric' => 'customers', 'unit' => 'number', 'direction' => 'up', 'category' => 'customers'],
            ['name' => 'Sessions', 'metric' => 'sessions', 'unit' => 'number', 'direction' => 'up', 'category' => 'traffic'],
            ['name' => 'Conversion Rate', 'metric' => 'conversion_rate', 'unit' => 'percent', 'direction' => 'up', 'category' => 'conversion'],
            ['name' => 'Cart Abandonment Rate', 'metric' => 'cart_abandonment_rate', 'unit' => 'percent', 'direction' => 'down', 'category' => 'conversion'],
            ['name' => 'Customer Lifetime Value', 'metric' => 'clv', 'unit' => 'currency', 'direction' => 'up', 'category' => 'customers'],
            ['name' => 'Returning Customer Rate', 'metric' => 'returning_customer_rate', 'unit' => 'percent', 'direction' => 'up', 'category' => 'customers'],
            ['name' => 'Bounce Rate', 'metric' => 'bounce_rate', 'unit' => 'percent', 'direction' => 'down', 'category' => 'traffic'],
        ];

        foreach ($defaults as $kpi) {
            Kpi::firstOrCreate(
                ['tenant_id' => $tenantId, 'metric' => $kpi['metric']],
                array_merge($kpi, [
                    'tenant_id' => $tenantId,
                    'calculation' => ['period' => '30d'],
                    'is_active' => true,
                    'refresh_interval' => 15,
                ])
            );
        }
    }

    // ─── Metric Calculators ──────────────────────────────────────────

    private function calculate(int $tenantId, string $metric, array $config, string $period): float
    {
        $days = $this->parsePeriodDays($config['period'] ?? '30d');
        [$start, $end] = $this->getPeriodRange($days, $period);

        $tid = (string) $tenantId;

        return match ($metric) {
            'revenue' => $this->calcRevenue($tid, $start, $end),
            'orders' => (float) $this->calcOrders($tid, $start, $end),
            'aov' => $this->calcAov($tid, $start, $end),
            'customers' => (float) $this->calcCustomers($tid, $start, $end),
            'sessions' => (float) $this->calcSessions($tid, $start, $end),
            'conversion_rate' => $this->calcConversionRate($tid, $start, $end),
            'cart_abandonment_rate' => $this->calcCartAbandonmentRate($tid, $start, $end),
            'clv' => $this->calcClv($tid),
            'returning_customer_rate' => $this->calcReturningRate($tid, $start, $end),
            'bounce_rate' => $this->calcBounceRate($tid, $start, $end),
            default => 0.0,
        };
    }

    private function calcRevenue(string $tid, mixed $start, mixed $end): float
    {
        // Use synced_orders (Magento orders) — tracking_events stores revenue in custom_data.revenue
        // but synced_orders.grand_total is the authoritative source (used by RevenueIntelService)
        $tids = [(int) $tid, (string) $tid];
        $result = DB::connection('mongodb')->table('synced_orders')
            ->whereIn('tenant_id', $tids)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->sum('grand_total');

        return round((float) $result, 2);
    }

    private function calcOrders(string $tid, mixed $start, mixed $end): int
    {
        $tids = [(int) $tid, (string) $tid];
        return DB::connection('mongodb')->table('synced_orders')
            ->whereIn('tenant_id', $tids)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->count();
    }

    private function calcAov(string $tid, string $start, string $end): float
    {
        $revenue = $this->calcRevenue($tid, $start, $end);
        $orders = $this->calcOrders($tid, $start, $end);
        return $orders > 0 ? round($revenue / $orders, 2) : 0.0;
    }

    private function calcCustomers(string $tid, string $start, string $end): int
    {
        return DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tid)
            ->where('first_seen_at', '>=', $start)
            ->where('first_seen_at', '<=', $end)
            ->count();
    }

    private function calcSessions(string $tid, string $start, string $end): int
    {
        return DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)
            ->where('event_type', 'page_view')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->distinct('session_id')
            ->count('session_id');
    }

    private function calcConversionRate(string $tid, string $start, string $end): float
    {
        $sessions = $this->calcSessions($tid, $start, $end);
        $orders = $this->calcOrders($tid, $start, $end);
        return $sessions > 0 ? round(($orders / $sessions) * 100, 2) : 0.0;
    }

    private function calcCartAbandonmentRate(string $tid, string $start, string $end): float
    {
        $carts = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)
            ->where('event_type', 'add_to_cart')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->distinct('session_id')
            ->count('session_id');

        $purchases = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)
            ->where('event_type', 'purchase')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->distinct('session_id')
            ->count('session_id');

        return $carts > 0 ? round((($carts - $purchases) / $carts) * 100, 2) : 0.0;
    }

    private function calcClv(string $tid): float
    {
        $result = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tid)
            ->where('total_orders', '>', 0)
            ->avg('lifetime_value');

        return round((float) ($result ?? 0), 2);
    }

    private function calcReturningRate(string $tid, string $start, string $end): float
    {
        $total = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tid)
            ->where('last_purchase_at', '>=', $start)
            ->where('last_purchase_at', '<=', $end)
            ->count();

        $returning = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tid)
            ->where('last_purchase_at', '>=', $start)
            ->where('last_purchase_at', '<=', $end)
            ->where('total_orders', '>', 1)
            ->count();

        return $total > 0 ? round(($returning / $total) * 100, 2) : 0.0;
    }

    private function calcBounceRate(string $tid, string $start, string $end): float
    {
        // A "bounce" = session with only 1 page view
        $allSessions = $this->calcSessions($tid, $start, $end);
        if ($allSessions === 0) return 0.0;

        // Count sessions with > 1 page views
        $engagedSessions = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tid, $start, $end) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tid,
                        'event_type' => 'page_view',
                        'created_at' => ['$gte' => $start, '$lte' => $end],
                    ]],
                    ['$group' => ['_id' => '$session_id', 'count' => ['$sum' => 1]]],
                    ['$match' => ['count' => ['$gt' => 1]]],
                    ['$count' => 'engaged'],
                ], ['maxTimeMS' => 30000])->toArray();
            });

        $engaged = $engagedSessions[0]['engaged'] ?? 0;
        $bounces = $allSessions - $engaged;

        return round(($bounces / $allSessions) * 100, 2);
    }

    private function parsePeriodDays(string $period): int
    {
        if (preg_match('/^(\d+)d$/', $period, $m)) return (int) $m[1];
        if (preg_match('/^(\d+)w$/', $period, $m)) return (int) $m[1] * 7;
        if (preg_match('/^(\d+)m$/', $period, $m)) return (int) $m[1] * 30;
        return 30;
    }

    private function getPeriodRange(int $days, string $period): array
    {
        // Return MongoDB\BSON\UTCDateTime objects so date comparisons work
        // correctly against BSON-stored dates in MongoDB collections.
        if ($period === 'current') {
            return [
                new \MongoDB\BSON\UTCDateTime(now()->subDays($days)->startOfDay()->getTimestampMs()),
                new \MongoDB\BSON\UTCDateTime(now()->endOfDay()->getTimestampMs()),
            ];
        }

        return [
            new \MongoDB\BSON\UTCDateTime(now()->subDays($days * 2)->startOfDay()->getTimestampMs()),
            new \MongoDB\BSON\UTCDateTime(now()->subDays($days)->endOfDay()->getTimestampMs()),
        ];
    }
}

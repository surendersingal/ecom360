<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Events\IntegrationEvent;

/**
 * Real-Time Alerts Service (Analytics layer).
 *
 * Monitors key metrics in real-time and fires alerts when anomalies or
 * threshold breaches are detected.  Complements the BI AlertService by
 * running at higher frequency (every minute) on hot tracking data.
 *
 * Alert types:
 *   - traffic_spike / traffic_drop
 *   - conversion_drop
 *   - error_spike  (4xx/5xx on tracked pages)
 *   - revenue_anomaly
 *   - cart_abandonment_spike
 *   - bot_traffic_detected
 */
final class RealTimeAlertsService
{
    private const WINDOW_MINUTES = 10;
    private const COMPARISON_HOURS = 24;

    /**
     * Run all real-time alert checks for a tenant.
     * Designed to be called every 1-2 minutes from a scheduler.
     */
    public function evaluate(int|string $tenantId): array
    {
        $alerts = [];

        $alerts = array_merge($alerts, $this->checkTraffic($tenantId));
        $alerts = array_merge($alerts, $this->checkConversion($tenantId));
        $alerts = array_merge($alerts, $this->checkRevenue($tenantId));
        $alerts = array_merge($alerts, $this->checkCartAbandonment($tenantId));

        foreach ($alerts as $alert) {
            $this->fireAlert($tenantId, $alert);
        }

        return $alerts;
    }

    /**
     * Get current real-time pulse (for dashboard widgets).
     */
    public function getPulse(int|string $tenantId): array
    {
        $now = now();
        $windowStart = $now->copy()->subMinutes(self::WINDOW_MINUTES)->toIso8601String();
        $prevWindowStart = $now->copy()->subMinutes(self::WINDOW_MINUTES * 2)->toIso8601String();
        $prevWindowEnd = $now->copy()->subMinutes(self::WINDOW_MINUTES)->toIso8601String();

        $currentEvents = $this->countEvents($tenantId, $windowStart, $now->toIso8601String());
        $prevEvents = $this->countEvents($tenantId, $prevWindowStart, $prevWindowEnd);

        $currentSessions = $this->countSessions($tenantId, $windowStart, $now->toIso8601String());
        $currentPurchases = $this->countPurchases($tenantId, $windowStart, $now->toIso8601String());

        return [
            'window_minutes' => self::WINDOW_MINUTES,
            'timestamp' => $now->toIso8601String(),
            'events_current_window' => $currentEvents,
            'events_previous_window' => $prevEvents,
            'events_change_percent' => $prevEvents > 0 ? round((($currentEvents - $prevEvents) / $prevEvents) * 100, 1) : 0,
            'active_sessions' => $currentSessions,
            'purchases_in_window' => $currentPurchases,
            'health' => $this->determineHealth($currentEvents, $prevEvents),
        ];
    }

    /**
     * Get recent alerts for a tenant.
     */
    public function getRecentAlerts(int|string $tenantId, int $limit = 20): array
    {
        return DB::connection('mongodb')->table('realtime_alerts')
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn($a) => (array) $a)
            ->all();
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(int|string $tenantId, string $alertId): bool
    {
        return DB::connection('mongodb')->table('realtime_alerts')
            ->where('tenant_id', $tenantId)
            ->where('_id', $alertId)
            ->update(['acknowledged_at' => now()->toIso8601String()]) > 0;
    }

    // ── Alert Checks ─────────────────────────────────────────────────

    private function checkTraffic(int|string $tenantId): array
    {
        $alerts = [];
        $now = now();
        $windowStart = $now->copy()->subMinutes(self::WINDOW_MINUTES)->toIso8601String();
        $nowStr = $now->toIso8601String();

        $currentSessions = $this->countSessions($tenantId, $windowStart, $nowStr);

        // Get average for same window over past N days
        $avgSessions = $this->getHistoricalAverage($tenantId, 'sessions', self::COMPARISON_HOURS);

        if ($avgSessions > 0) {
            $ratio = $currentSessions / $avgSessions;

            if ($ratio > 3.0) {
                $alerts[] = [
                    'type' => 'traffic_spike',
                    'severity' => 'warning',
                    'message' => sprintf('Traffic is %.0f%% above normal (%.0f vs avg %.0f sessions)', ($ratio - 1) * 100, $currentSessions, $avgSessions),
                    'current_value' => $currentSessions,
                    'expected_value' => $avgSessions,
                ];
            } elseif ($ratio < 0.3 && $avgSessions > 5) {
                $alerts[] = [
                    'type' => 'traffic_drop',
                    'severity' => 'critical',
                    'message' => sprintf('Traffic dropped %.0f%% below normal (%.0f vs avg %.0f sessions)', (1 - $ratio) * 100, $currentSessions, $avgSessions),
                    'current_value' => $currentSessions,
                    'expected_value' => $avgSessions,
                ];
            }
        }

        return $alerts;
    }

    private function checkConversion(int|string $tenantId): array
    {
        $alerts = [];
        $windowStart = now()->subMinutes(60)->toIso8601String();
        $nowStr = now()->toIso8601String();

        $sessions = $this->countSessions($tenantId, $windowStart, $nowStr);
        $purchases = $this->countPurchases($tenantId, $windowStart, $nowStr);
        $currentRate = $sessions > 0 ? ($purchases / $sessions) * 100 : 0;

        $avgRate = (float) Cache::get("rt_alert:{$tenantId}:avg_conversion", 0);
        if ($avgRate > 0 && $currentRate < $avgRate * 0.5 && $sessions > 10) {
            $alerts[] = [
                'type' => 'conversion_drop',
                'severity' => 'critical',
                'message' => sprintf('Conversion rate dropped to %.1f%% (avg: %.1f%%)', $currentRate, $avgRate),
                'current_value' => round($currentRate, 2),
                'expected_value' => round($avgRate, 2),
            ];
        }

        // Update rolling average
        if ($sessions > 5) {
            $newAvg = $avgRate > 0 ? ($avgRate * 0.9 + $currentRate * 0.1) : $currentRate;
            Cache::put("rt_alert:{$tenantId}:avg_conversion", $newAvg, now()->addHours(48));
        }

        return $alerts;
    }

    private function checkRevenue(int|string $tenantId): array
    {
        $alerts = [];
        $windowStart = now()->subMinutes(60)->toIso8601String();
        $nowStr = now()->toIso8601String();

        $revenue = (float) DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'purchase')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $nowStr)
            ->sum('metadata.revenue');

        $avgRevenue = (float) Cache::get("rt_alert:{$tenantId}:avg_hourly_revenue", 0);

        if ($avgRevenue > 0 && $revenue > $avgRevenue * 5) {
            $alerts[] = [
                'type' => 'revenue_anomaly',
                'severity' => 'info',
                'message' => sprintf('Revenue spike: $%.2f in last hour (avg: $%.2f)', $revenue, $avgRevenue),
                'current_value' => $revenue,
                'expected_value' => $avgRevenue,
            ];
        }

        // Update rolling average
        if ($revenue > 0) {
            $newAvg = $avgRevenue > 0 ? ($avgRevenue * 0.95 + $revenue * 0.05) : $revenue;
            Cache::put("rt_alert:{$tenantId}:avg_hourly_revenue", $newAvg, now()->addHours(48));
        }

        return $alerts;
    }

    private function checkCartAbandonment(int|string $tenantId): array
    {
        $alerts = [];
        $windowStart = now()->subMinutes(60)->toIso8601String();
        $nowStr = now()->toIso8601String();

        $carts = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'add_to_cart')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $nowStr)
            ->distinct('session_id')
            ->count('session_id');

        $purchases = $this->countPurchases($tenantId, $windowStart, $nowStr);
        $abandonRate = $carts > 0 ? (($carts - $purchases) / $carts) * 100 : 0;

        if ($carts > 5 && $abandonRate > 85) {
            $alerts[] = [
                'type' => 'cart_abandonment_spike',
                'severity' => 'warning',
                'message' => sprintf('Cart abandonment at %.0f%% (%d carts, %d purchases)', $abandonRate, $carts, $purchases),
                'current_value' => round($abandonRate, 1),
                'expected_value' => 70, // typical benchmark
            ];
        }

        return $alerts;
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function countEvents(int $tid, string $start, string $end): int
    {
        return DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->count();
    }

    private function countSessions(int $tid, string $start, string $end): int
    {
        return DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->distinct('session_id')
            ->count('session_id');
    }

    private function countPurchases(int $tid, string $start, string $end): int
    {
        return DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tid)
            ->where('event_type', 'purchase')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->count();
    }

    private function getHistoricalAverage(int $tid, string $metric, int $hours): float
    {
        $cacheKey = "rt_alert:{$tid}:hist_avg:{$metric}";
        return (float) Cache::remember($cacheKey, now()->addMinutes(30), function () use ($tid, $metric, $hours) {
            $periods = max(1, (int) ($hours * 60 / self::WINDOW_MINUTES));
            $totalStart = now()->subHours($hours)->toIso8601String();
            $totalEnd = now()->toIso8601String();

            $total = match ($metric) {
                'sessions' => $this->countSessions($tid, $totalStart, $totalEnd),
                'events' => $this->countEvents($tid, $totalStart, $totalEnd),
                default => 0,
            };

            return $total / $periods;
        });
    }

    private function determineHealth(int $current, int $previous): string
    {
        if ($previous === 0) return 'unknown';
        $ratio = $current / $previous;
        if ($ratio < 0.3) return 'critical';
        if ($ratio < 0.6) return 'degraded';
        if ($ratio > 3.0) return 'elevated';
        return 'healthy';
    }

    private function fireAlert(int|string $tenantId, array $alert): void
    {
        // Cooldown: don't fire same alert type within 15 minutes
        $cooldownKey = "rt_alert:{$tenantId}:cooldown:{$alert['type']}";
        if (Cache::has($cooldownKey)) return;
        Cache::put($cooldownKey, true, now()->addMinutes(15));

        DB::connection('mongodb')->table('realtime_alerts')->insert([
            'tenant_id' => $tenantId,
            'type' => $alert['type'],
            'severity' => $alert['severity'],
            'message' => $alert['message'],
            'current_value' => $alert['current_value'] ?? null,
            'expected_value' => $alert['expected_value'] ?? null,
            'acknowledged_at' => null,
            'created_at' => now()->toIso8601String(),
        ]);

        IntegrationEvent::dispatch($tenantId, 'analytics', 'realtime_alert', $alert);
    }
}

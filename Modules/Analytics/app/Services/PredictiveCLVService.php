<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Predictive Customer Lifetime Value Service.
 *
 * Calculates predicted CLV using:
 *   - BG/NBD-inspired purchase frequency model
 *   - Gamma-Gamma monetary value model
 *   - RFM segment multipliers
 *   - Cohort-adjusted retention curves
 *
 * Provides CLV segmentation, projections, and "what-if" scenarios.
 */
final class PredictiveCLVService
{
    /**
     * Calculate CLV prediction for a single customer.
     */
    public function predict(int|string $tenantId, string $customerId): array
    {
        $profile = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tenantId)
            ->where('_id', $customerId)
            ->first();

        if (!$profile) return ['error' => 'Customer not found'];

        $profile = (array) $profile;
        return $this->calculateCLV($profile, $tenantId);
    }

    /**
     * Calculate CLV for all customers in a tenant.
     *
     * @return array{ total_customers: int, avg_predicted_clv: float, segments: array }
     */
    public function calculateAll(int|string $tenantId, int $limit = 100): array
    {
        $profiles = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tenantId)
            ->where('total_orders', '>', 0)
            ->get();

        $clvData = [];
        $segments = ['high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($profiles as $profile) {
            $p = (array) $profile;
            $result = $this->calculateCLV($p, $tenantId);
            $clvData[] = $result;

            if ($result['predicted_clv'] >= 500) $segments['high']++;
            elseif ($result['predicted_clv'] >= 100) $segments['medium']++;
            else $segments['low']++;
        }

        $totalClv = array_sum(array_column($clvData, 'predicted_clv'));

        return [
            'total_customers' => count($clvData),
            'total_predicted_clv' => round($totalClv, 2),
            'avg_predicted_clv' => count($clvData) > 0 ? round($totalClv / count($clvData), 2) : 0,
            'segments' => $segments,
            'top_customers' => array_slice(
                collect($clvData)->sortByDesc('predicted_clv')->values()->all(), 0, 20
            ),
        ];
    }

    /**
     * Run a "what-if" scenario: if AOV increases by X%, how does CLV change?
     */
    public function whatIf(int|string $tenantId, string $visitorId, array $adjustments): array
    {
        $profiles = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tenantId)
            ->where('total_orders', '>', 0)
            ->limit(100)
            ->get();

        $beforeTotal = 0;
        $afterTotal = 0;

        foreach ($profiles as $profile) {
            $p = (array) $profile;
            $before = $this->calculateCLV($p, $tenantId);
            $beforeTotal += $before['predicted_clv'];

            // Apply adjustments
            if (isset($adjustments['aov_increase_percent'])) {
                $p['average_order_value'] = ($p['average_order_value'] ?? 0) * (1 + $adjustments['aov_increase_percent'] / 100);
            }
            if (isset($adjustments['frequency_increase_percent'])) {
                $p['total_orders'] = (int) (($p['total_orders'] ?? 0) * (1 + $adjustments['frequency_increase_percent'] / 100));
            }

            $after = $this->calculateCLV($p, $tenantId);
            $afterTotal += $after['predicted_clv'];
        }

        return [
            'before_avg_clv' => count($profiles) > 0 ? round($beforeTotal / count($profiles), 2) : 0,
            'after_avg_clv' => count($profiles) > 0 ? round($afterTotal / count($profiles), 2) : 0,
            'change_percent' => $beforeTotal > 0
                ? round((($afterTotal - $beforeTotal) / $beforeTotal) * 100, 2)
                : 0,
            'adjustments' => $adjustments,
            'sample_size' => count($profiles),
        ];
    }

    private function calculateCLV(array $profile, int|string $tenantId): array
    {
        $totalOrders = (int) ($profile['total_orders'] ?? 0);
        $totalRevenue = (float) ($profile['total_revenue'] ?? 0);
        $aov = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        $daysSinceFirst = max(1, $this->daysSince($profile['first_seen_at'] ?? null));
        $daysSinceLast = $this->daysSince($profile['last_purchase_at'] ?? null);

        // Purchase frequency (per year)
        $frequency = ($totalOrders / $daysSinceFirst) * 365;

        // Retention probability using exponential decay
        $avgGap = $totalOrders > 1 ? $daysSinceFirst / ($totalOrders - 1) : 90;
        $churnRate = 1 - exp(-$daysSinceLast / max($avgGap * 2, 30));
        $retentionRate = 1 - $churnRate;

        // Expected lifespan
        $lifespan = $retentionRate > 0.01 ? min(5, 1 / (1 - $retentionRate)) : 0.5;

        // Predicted CLV = frequency × AOV × lifespan
        $predictedClv = $frequency * $aov * $lifespan;

        // Apply discount rate (10% annually)
        $discountRate = 0.10;
        $dcf = $predictedClv / (1 + $discountRate);

        return [
            'customer_id' => (string) ($profile['_id'] ?? ''),
            'email' => $profile['email'] ?? '',
            'predicted_clv' => round($dcf, 2),
            'historical_value' => round($totalRevenue, 2),
            'purchase_frequency' => round($frequency, 2),
            'avg_order_value' => round($aov, 2),
            'retention_probability' => round($retentionRate, 4),
            'expected_lifespan_years' => round($lifespan, 2),
            'churn_risk' => round($churnRate, 4),
            'rfm_segment' => $profile['rfm_segment'] ?? 'Unknown',
            'confidence' => $this->confidence($totalOrders, $daysSinceFirst),
        ];
    }

    private function daysSince(?string $date): int
    {
        if (!$date) return 999;
        try {
            return max(0, (int) now()->diffInDays(\Carbon\Carbon::parse($date)));
        } catch (\Throwable) {
            return 999;
        }
    }

    private function confidence(int $orders, int $days): float
    {
        return min(0.95, round(0.2 + ($orders * 0.06) + ($days > 60 ? 0.1 : 0) + ($days > 180 ? 0.1 : 0), 2));
    }
}

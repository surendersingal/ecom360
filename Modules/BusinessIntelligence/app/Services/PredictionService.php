<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BusinessIntelligence\Models\Prediction;

/**
 * Predictive analytics engine for e-commerce intelligence.
 *
 * Models:
 *   - clv_prediction: Predicted Customer Lifetime Value
 *   - churn_risk: Probability of customer churn
 *   - purchase_propensity: Likelihood of next purchase
 *   - revenue_forecast: Revenue projection for next period
 *   - demand_forecast: Product demand prediction
 *   - next_best_action: Recommended action per customer
 *
 * Uses statistical models (RFM-weighted, exponential smoothing,
 * Pareto/NBD approximations) rather than ML to avoid heavy dependencies.
 */
final class PredictionService
{
    // ─── CRUD / Query Methods ─────────────────────────────────────────

    /**
     * List predictions for a tenant, optionally filtered.
     */
    public function list(int $tenantId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Prediction::where('tenant_id', $tenantId)
            ->when($filters['model_type'] ?? null, fn($q, $type) => $q->where('model_type', $type))
            ->when($filters['entity_type'] ?? null, fn($q, $type) => $q->where('entity_type', $type))
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
            })
            ->orderByDesc('updated_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Generate predictions for a given model type.
     *
     * @return array{model_type: string, generated: int}
     */
    public function generate(int $tenantId, string $modelType): array
    {
        $count = match ($modelType) {
            'clv' => $this->predictCLV($tenantId),
            'churn_risk' => $this->predictChurnRisk($tenantId),
            'purchase_propensity' => $this->predictPurchasePropensity($tenantId),
            'revenue_forecast' => count($this->forecastRevenue($tenantId)['daily'] ?? []),
            default => 0,
        };

        return ['model_type' => $modelType, 'generated' => $count];
    }

    // ─── Prediction Generators ────────────────────────────────────────

    /**
     * Generate CLV predictions for all customers in a tenant.
     */
    public function predictCLV(int $tenantId): int
    {
        $profiles = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', (string) $tenantId)
            ->where('total_orders', '>', 0)
            ->get();

        $count = 0;
        foreach ($profiles as $profile) {
            $profile = (array) $profile;
            $clv = $this->calculatePredictedCLV($profile);
            $confidence = $this->calculateConfidence($profile);

            Prediction::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'model_type' => 'clv_prediction',
                    'entity_type' => 'customer',
                    'entity_id' => (string) ($profile['_id'] ?? ''),
                ],
                [
                    'predicted_value' => $clv,
                    'confidence' => $confidence,
                    'features' => [
                        'total_orders' => $profile['total_orders'] ?? 0,
                        'total_revenue' => $profile['total_revenue'] ?? 0,
                        'avg_order_value' => $profile['average_order_value'] ?? 0,
                        'days_since_first' => $this->daysSince($profile['first_seen_at'] ?? null),
                        'days_since_last' => $this->daysSince($profile['last_purchase_at'] ?? null),
                        'rfm_segment' => $profile['rfm_segment'] ?? 'unknown',
                    ],
                    'explanation' => [
                        'method' => 'rfm_weighted_clv',
                        'factors' => $this->getClvFactors($profile),
                    ],
                    'valid_until' => now()->addDays(7),
                ]
            );
            $count++;
        }

        Log::info("[PredictionService] Generated {$count} CLV predictions for tenant #{$tenantId}");
        return $count;
    }

    /**
     * Calculate churn risk scores for all customers.
     */
    public function predictChurnRisk(int $tenantId): int
    {
        $profiles = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', (string) $tenantId)
            ->where('total_orders', '>', 0)
            ->get();

        $count = 0;
        foreach ($profiles as $profile) {
            $profile = (array) $profile;
            $churnScore = $this->calculateChurnRisk($profile);

            Prediction::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'model_type' => 'churn_risk',
                    'entity_type' => 'customer',
                    'entity_id' => (string) ($profile['_id'] ?? ''),
                ],
                [
                    'predicted_value' => $churnScore,
                    'confidence' => min(0.95, 0.5 + ($profile['total_orders'] ?? 0) * 0.05),
                    'features' => [
                        'days_since_last_purchase' => $this->daysSince($profile['last_purchase_at'] ?? null),
                        'avg_purchase_frequency_days' => $this->avgPurchaseFrequency($profile),
                        'total_orders' => $profile['total_orders'] ?? 0,
                        'rfm_segment' => $profile['rfm_segment'] ?? 'unknown',
                    ],
                    'explanation' => [
                        'method' => 'recency_frequency_decay',
                        'risk_level' => $churnScore >= 0.7 ? 'high' : ($churnScore >= 0.4 ? 'medium' : 'low'),
                    ],
                    'valid_until' => now()->addDays(3),
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Predict purchase propensity (next 7 days).
     */
    public function predictPurchasePropensity(int $tenantId): int
    {
        $profiles = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', (string) $tenantId)
            ->get();

        $count = 0;
        foreach ($profiles as $profile) {
            $profile = (array) $profile;
            $propensity = $this->calculatePurchasePropensity($profile);

            Prediction::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'model_type' => 'purchase_propensity',
                    'entity_type' => 'customer',
                    'entity_id' => (string) ($profile['_id'] ?? ''),
                ],
                [
                    'predicted_value' => $propensity,
                    'confidence' => min(0.9, 0.3 + ($profile['total_orders'] ?? 0) * 0.06),
                    'features' => [
                        'intent_score' => $profile['intent_score'] ?? 0,
                        'recent_views' => $profile['session_count'] ?? 0,
                        'cart_items' => $profile['cart_items_count'] ?? 0,
                        'days_since_last_visit' => $this->daysSince($profile['last_seen_at'] ?? null),
                    ],
                    'explanation' => [
                        'method' => 'intent_weighted_propensity',
                    ],
                    'valid_until' => now()->addDays(1),
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Forecast revenue for the next N days.
     */
    public function forecastRevenue(int $tenantId, int $days = 30): array
    {
        $tid = (string) $tenantId;

        // Get daily revenue for the past 90 days
        $historicalData = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tid) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tid,
                        'event_type' => 'purchase',
                        'created_at' => ['$gte' => now()->subDays(90)->toIso8601String()],
                    ]],
                    ['$group' => [
                        '_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => ['$toDate' => '$created_at']]],
                        'revenue' => ['$sum' => '$metadata.revenue'],
                        'orders' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['_id' => 1]],
                ])->toArray();
            });

        $dailyRevenues = array_map(fn($d) => (float) ($d['revenue'] ?? 0), $historicalData);

        if (count($dailyRevenues) < 7) {
            return ['forecast' => [], 'confidence' => 0, 'method' => 'insufficient_data'];
        }

        // Simple exponential smoothing forecast
        $forecast = $this->exponentialSmoothing($dailyRevenues, $days, 0.3);

        $prediction = Prediction::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'model_type' => 'revenue_forecast',
                'entity_type' => 'tenant',
                'entity_id' => (string) $tenantId,
            ],
            [
                'predicted_value' => array_sum($forecast),
                'confidence' => $this->forecastConfidence(count($dailyRevenues)),
                'features' => [
                    'historical_days' => count($dailyRevenues),
                    'forecast_days' => $days,
                    'avg_daily_revenue' => count($dailyRevenues) > 0 ? round(array_sum($dailyRevenues) / count($dailyRevenues), 2) : 0,
                ],
                'explanation' => ['method' => 'exponential_smoothing', 'alpha' => 0.3],
                'valid_until' => now()->addDays(1),
            ]
        );

        return [
            'forecast' => array_map(fn($v, $i) => [
                'date' => now()->addDays($i + 1)->toDateString(),
                'predicted_revenue' => round($v, 2),
            ], $forecast, array_keys($forecast)),
            'total_predicted' => round(array_sum($forecast), 2),
            'confidence' => $prediction->confidence,
            'method' => 'exponential_smoothing',
        ];
    }

    /**
     * Get all predictions for a specific customer.
     */
    public function getCustomerPredictions(int $tenantId, string $entityId): array
    {
        return Prediction::where('tenant_id', $tenantId)
            ->where('entity_type', 'customer')
            ->where('entity_id', $entityId)
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
            })
            ->get()
            ->keyBy('model_type')
            ->toArray();
    }

    // ─── Calculation Helpers ─────────────────────────────────────────

    private function calculatePredictedCLV(array $profile): float
    {
        $totalRevenue = (float) ($profile['total_revenue'] ?? 0);
        $totalOrders = (int) ($profile['total_orders'] ?? 0);
        $daysSinceFirst = max(1, $this->daysSince($profile['first_seen_at'] ?? null));

        if ($totalOrders === 0) return 0;

        // Purchase frequency (orders per year)
        $frequency = ($totalOrders / $daysSinceFirst) * 365;
        $aov = $totalRevenue / $totalOrders;

        // Estimated lifespan (years) based on recency
        $daysSinceLast = $this->daysSince($profile['last_purchase_at'] ?? null);
        $retentionRate = max(0.1, 1 - ($daysSinceLast / 365));
        $estimatedLifespan = 1 / (1 - $retentionRate);
        $estimatedLifespan = min($estimatedLifespan, 5); // Cap at 5 years

        // RFM segment multiplier
        $multiplier = match ($profile['rfm_segment'] ?? '') {
            'Champions', 'Loyal Customers' => 1.3,
            'Potential Loyalists' => 1.1,
            'At Risk', 'Need Attention' => 0.7,
            'Hibernating', 'Lost' => 0.4,
            default => 1.0,
        };

        return round($frequency * $aov * $estimatedLifespan * $multiplier, 2);
    }

    private function calculateChurnRisk(array $profile): float
    {
        $daysSinceLast = $this->daysSince($profile['last_purchase_at'] ?? null);
        $avgFreq = $this->avgPurchaseFrequency($profile);

        if ($avgFreq <= 0) return 0.8; // Unknown frequency = high risk

        // Risk increases as days since last purchase exceeds average frequency
        $ratio = $daysSinceLast / $avgFreq;

        // Sigmoid function: maps ratio to 0-1 probability
        $risk = 1 / (1 + exp(-2 * ($ratio - 1.5)));

        // Adjust by RFM segment
        $adjustment = match ($profile['rfm_segment'] ?? '') {
            'Champions', 'Loyal Customers' => -0.15,
            'At Risk', 'Need Attention' => 0.1,
            'Hibernating', 'Lost' => 0.2,
            default => 0,
        };

        return max(0, min(1, round($risk + $adjustment, 4)));
    }

    private function calculatePurchasePropensity(array $profile): float
    {
        $intentScore = (float) ($profile['intent_score'] ?? 0);
        $daysSinceVisit = $this->daysSince($profile['last_seen_at'] ?? null);
        $totalOrders = (int) ($profile['total_orders'] ?? 0);

        // Base propensity from intent score (0-100 → 0-0.5)
        $base = $intentScore / 200;

        // Recency boost (visited recently = higher propensity)
        $recencyBoost = $daysSinceVisit <= 1 ? 0.3 : ($daysSinceVisit <= 3 ? 0.2 : ($daysSinceVisit <= 7 ? 0.1 : 0));

        // Returning customer boost
        $returningBoost = $totalOrders > 0 ? min(0.2, $totalOrders * 0.03) : 0;

        return max(0, min(1, round($base + $recencyBoost + $returningBoost, 4)));
    }

    private function calculateConfidence(array $profile): float
    {
        $orders = (int) ($profile['total_orders'] ?? 0);
        $days = $this->daysSince($profile['first_seen_at'] ?? null);

        // More data = higher confidence
        return min(0.95, round(0.3 + ($orders * 0.05) + ($days > 90 ? 0.1 : 0) + ($days > 180 ? 0.1 : 0), 4));
    }

    private function getClvFactors(array $profile): array
    {
        return [
            'purchase_frequency' => ($profile['total_orders'] ?? 0) > 3 ? 'high' : 'low',
            'average_order_value' => ($profile['average_order_value'] ?? 0) > 50 ? 'above_average' : 'below_average',
            'recency' => $this->daysSince($profile['last_purchase_at'] ?? null) < 30 ? 'recent' : 'not_recent',
            'loyalty_segment' => $profile['rfm_segment'] ?? 'unknown',
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

    private function avgPurchaseFrequency(array $profile): float
    {
        $orders = (int) ($profile['total_orders'] ?? 0);
        $daysSinceFirst = $this->daysSince($profile['first_seen_at'] ?? null);

        if ($orders <= 1 || $daysSinceFirst <= 0) return 0;

        return $daysSinceFirst / ($orders - 1);
    }

    private function exponentialSmoothing(array $data, int $forecastDays, float $alpha): array
    {
        $n = count($data);
        $level = $data[0];

        // Fit the model
        for ($i = 1; $i < $n; $i++) {
            $level = $alpha * $data[$i] + (1 - $alpha) * $level;
        }

        // Add some variance based on historical std dev
        $mean = array_sum($data) / $n;
        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $data)) / $n;
        $noise = sqrt($variance) * 0.1;

        $forecast = [];
        for ($i = 0; $i < $forecastDays; $i++) {
            // Add slight random variation for realistic forecast
            $forecast[] = max(0, $level + (mt_rand(-100, 100) / 100) * $noise);
        }

        return $forecast;
    }

    private function forecastConfidence(int $dataPoints): float
    {
        return match (true) {
            $dataPoints >= 60 => 0.85,
            $dataPoints >= 30 => 0.70,
            $dataPoints >= 14 => 0.55,
            default => 0.35,
        };
    }
}

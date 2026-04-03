<?php

declare(strict_types=1);

namespace Modules\Analytics\Services\Cdp;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\CdpProfile;

/**
 * RFM Analysis & Computed Properties Engine.
 *
 * Computes:
 *  - RFM scores (Recency, Frequency, Monetary) with 10-segment classification
 *  - Churn risk estimation
 *  - Purchase propensity scoring
 *  - Discount sensitivity
 *  - Predicted LTV (simple model)
 *  - Preferred channel
 *
 * These computed values are stored on each CdpProfile in the `computed` field.
 */
final class CdpRfmService
{
    /**
     * The 10 RFM segments based on score ranges.
     */
    private const RFM_SEGMENTS = [
        'Champion'        => ['r_min' => 4, 'r_max' => 5, 'fm_min' => 4, 'fm_max' => 5],
        'Loyal'           => ['r_min' => 2, 'r_max' => 5, 'fm_min' => 3, 'fm_max' => 5],
        'Potential Loyal'  => ['r_min' => 3, 'r_max' => 5, 'fm_min' => 1, 'fm_max' => 3],
        'New Customer'    => ['r_min' => 4, 'r_max' => 5, 'fm_min' => 1, 'fm_max' => 1],
        'Promising'       => ['r_min' => 3, 'r_max' => 4, 'fm_min' => 1, 'fm_max' => 1],
        'Need Attention'  => ['r_min' => 2, 'r_max' => 3, 'fm_min' => 2, 'fm_max' => 3],
        'About to Sleep'  => ['r_min' => 2, 'r_max' => 3, 'fm_min' => 1, 'fm_max' => 2],
        'At Risk'         => ['r_min' => 1, 'r_max' => 2, 'fm_min' => 2, 'fm_max' => 5],
        'Cannot Lose'     => ['r_min' => 1, 'r_max' => 1, 'fm_min' => 4, 'fm_max' => 5],
        'Hibernating'     => ['r_min' => 1, 'r_max' => 2, 'fm_min' => 1, 'fm_max' => 2],
    ];

    /**
     * Compute RFM scores and all derived properties for ALL profiles of a tenant.
     *
     * @return array{computed: int, segments: array}
     */
    public function computeAll(string $tenantId): array
    {
        $profiles = CdpProfile::forTenant($tenantId)
            ->where('transactional.total_orders', '>', 0)
            ->get();

        if ($profiles->isEmpty()) {
            return ['computed' => 0, 'segments' => []];
        }

        // Step 1: Collect all RFM raw values to compute percentile boundaries
        $recencies   = [];
        $frequencies = [];
        $monetaries  = [];

        foreach ($profiles as $p) {
            $t = $p->transactional ?? [];
            $recencies[]   = (int) ($t['days_since_last_order'] ?? 9999);
            $frequencies[] = (int) ($t['total_orders'] ?? 0);
            $monetaries[]  = (float) ($t['lifetime_revenue'] ?? 0);
        }

        // Step 2: Compute quintile boundaries (5 buckets each)
        $rBounds = $this->quintileBoundaries($recencies, true); // lower recency = better, so reverse
        $fBounds = $this->quintileBoundaries($frequencies);
        $mBounds = $this->quintileBoundaries($monetaries);

        // Step 3: Score each profile & classify
        $segmentCounts = array_fill_keys(array_keys(self::RFM_SEGMENTS), 0);
        $computed = 0;

        foreach ($profiles as $p) {
            $t = $p->transactional ?? [];
            $b = $p->behavioural ?? [];

            $recency   = (int) ($t['days_since_last_order'] ?? 9999);
            $frequency = (int) ($t['total_orders'] ?? 0);
            $monetary  = (float) ($t['lifetime_revenue'] ?? 0);

            $rScore = $this->scoreValue($recency, $rBounds, true);
            $fScore = $this->scoreValue($frequency, $fBounds);
            $mScore = $this->scoreValue($monetary, $mBounds);

            $rfmScore   = "{$rScore}{$fScore}{$mScore}";
            $rfmSegment = $this->classifyRfm($rScore, $fScore, $mScore);

            // Churn risk
            $churnRisk = $this->computeChurnRisk($recency, $frequency, $b);

            // Purchase propensity
            $propensity = $this->computePurchasePropensity($recency, $frequency, $b);

            // Discount sensitivity
            $discountSensitivity = $this->computeDiscountSensitivity($t);

            // Predicted LTV (simple: avg monthly * 12)
            $predictedLtv = $this->predictLtv($t);

            // Preferred channel
            $preferredChannel = $this->determinePreferredChannel($p);

            $computedData = [
                'rfm_score'             => $rfmScore,
                'rfm_r'                 => $rScore,
                'rfm_f'                 => $fScore,
                'rfm_m'                 => $mScore,
                'rfm_segment'           => $rfmSegment,
                'churn_risk_score'      => round($churnRisk, 3),
                'churn_risk_level'      => $churnRisk > 0.7 ? 'high' : ($churnRisk > 0.4 ? 'medium' : 'low'),
                'purchase_propensity'   => round($propensity, 3),
                'discount_sensitivity'  => $discountSensitivity,
                'predicted_ltv_12m'     => round($predictedLtv, 2),
                'preferred_channel'     => $preferredChannel,
                'scored_at'             => Carbon::now()->toISOString(),
            ];

            $p->update(['computed' => $computedData]);
            $segmentCounts[$rfmSegment] = ($segmentCounts[$rfmSegment] ?? 0) + 1;
            $computed++;
        }

        // Also set zero-order profiles as "Hibernating" with default computed
        $zeroOrderProfiles = CdpProfile::forTenant($tenantId)
            ->where(function ($q) {
                $q->where('transactional.total_orders', 0)
                  ->orWhereNull('transactional.total_orders');
            })
            ->get();

        foreach ($zeroOrderProfiles as $p) {
            $p->update([
                'computed' => [
                    'rfm_score'            => '111',
                    'rfm_r'                => 1, 'rfm_f' => 1, 'rfm_m' => 1,
                    'rfm_segment'          => 'Hibernating',
                    'churn_risk_score'     => 0.95,
                    'churn_risk_level'     => 'high',
                    'purchase_propensity'  => 0.05,
                    'discount_sensitivity' => 'unknown',
                    'predicted_ltv_12m'    => 0,
                    'preferred_channel'    => 'email',
                    'scored_at'            => Carbon::now()->toISOString(),
                ],
            ]);
        }

        return [
            'computed'  => $computed,
            'segments'  => $segmentCounts,
            'no_orders' => $zeroOrderProfiles->count(),
        ];
    }

    /**
     * Get RFM segment summary for the dashboard.
     */
    public function getRfmSummary(string $tenantId): array
    {
        $segments = DB::connection('mongodb')
            ->table('cdp_profiles')
            ->raw(function ($collection) use ($tenantId) {
                return $collection->aggregate([
                    ['$match' => ['tenant_id' => $tenantId, 'computed.rfm_segment' => ['$exists' => true]]],
                    ['$group' => [
                        '_id'         => '$computed.rfm_segment',
                        'count'       => ['$sum' => 1],
                        'total_rev'   => ['$sum' => '$transactional.lifetime_revenue'],
                        'avg_orders'  => ['$avg' => '$transactional.total_orders'],
                        'avg_aov'     => ['$avg' => '$transactional.avg_order_value'],
                    ]],
                    ['$sort' => ['count' => -1]],
                ], ['maxTimeMS' => 30000]);
            });

        $result = collect(iterator_to_array($segments))->map(fn($r) => [
            'segment'    => $r['_id'],
            'count'      => $r['count'],
            'total_rev'  => round((float) ($r['total_rev'] ?? 0), 2),
            'avg_orders' => round((float) ($r['avg_orders'] ?? 0), 1),
            'avg_aov'    => round((float) ($r['avg_aov'] ?? 0), 2),
            'icon'       => $this->segmentIcon($r['_id']),
            'color'      => $this->segmentColor($r['_id']),
        ])->toArray();

        $totalProfiled = collect($result)->sum('count');

        // Add percentage
        return array_map(function ($seg) use ($totalProfiled) {
            $seg['percentage'] = $totalProfiled > 0 ? round(($seg['count'] / $totalProfiled) * 100, 1) : 0;
            return $seg;
        }, $result);
    }

    /**
     * Get segment movement (transitions) over last 30 days.
     * Compares current RFM vs the `member_trend` on CdpSegment (if available).
     */
    public function getSegmentMovement(string $tenantId): array
    {
        // Simplified: return current distribution only
        // Full implementation would track daily snapshots and compare
        return $this->getRfmSummary($tenantId);
    }

    /* ══════════════════════════════════════════
     *  SCORING INTERNALS
     * ══════════════════════════════════════════ */

    /**
     * Compute quintile boundaries for a list of values.
     * Returns [p20, p40, p60, p80] thresholds.
     */
    private function quintileBoundaries(array $values, bool $reverse = false): array
    {
        if (empty($values)) {
            return [0, 0, 0, 0];
        }
        sort($values);
        $n = count($values);

        $p20 = $values[(int) floor($n * 0.2)] ?? 0;
        $p40 = $values[(int) floor($n * 0.4)] ?? 0;
        $p60 = $values[(int) floor($n * 0.6)] ?? 0;
        $p80 = $values[(int) floor($n * 0.8)] ?? 0;

        return [$p20, $p40, $p60, $p80];
    }

    /**
     * Score a value (1–5) based on quintile boundaries.
     * If $reverse is true, lower values get higher scores (e.g., recency).
     */
    private function scoreValue(float $value, array $bounds, bool $reverse = false): int
    {
        [$p20, $p40, $p60, $p80] = $bounds;

        if ($reverse) {
            // Lower value = higher score (e.g., fewer days since last order = better)
            if ($value <= $p20) return 5;
            if ($value <= $p40) return 4;
            if ($value <= $p60) return 3;
            if ($value <= $p80) return 2;
            return 1;
        }

        // Higher value = higher score (e.g., more orders = better)
        if ($value >= $p80) return 5;
        if ($value >= $p60) return 4;
        if ($value >= $p40) return 3;
        if ($value >= $p20) return 2;
        return 1;
    }

    /**
     * Classify into one of 10 RFM segments based on R, F, M scores.
     */
    private function classifyRfm(int $r, int $f, int $m): string
    {
        $fm = (int) round(($f + $m) / 2); // combined FM score

        foreach (self::RFM_SEGMENTS as $name => $range) {
            if ($r >= $range['r_min'] && $r <= $range['r_max']
                && $fm >= $range['fm_min'] && $fm <= $range['fm_max']) {
                return $name;
            }
        }

        return 'Hibernating'; // fallback
    }

    /**
     * Compute churn risk (0.0 – 1.0).
     * Higher = more likely to churn.
     */
    private function computeChurnRisk(int $daysSinceLastOrder, int $totalOrders, array $behavioural): float
    {
        $risk = 0.0;

        // Recency factor (most important)
        if ($daysSinceLastOrder > 180) {
            $risk += 0.4;
        } elseif ($daysSinceLastOrder > 90) {
            $risk += 0.3;
        } elseif ($daysSinceLastOrder > 60) {
            $risk += 0.2;
        } elseif ($daysSinceLastOrder > 30) {
            $risk += 0.1;
        }

        // Frequency factor
        if ($totalOrders <= 1) {
            $risk += 0.25;
        } elseif ($totalOrders <= 2) {
            $risk += 0.15;
        } elseif ($totalOrders <= 3) {
            $risk += 0.05;
        }

        // Engagement factor (sessions in last 30 days)
        $sessions30d = $behavioural['sessions_30d'] ?? 0;
        if ($sessions30d === 0) {
            $risk += 0.2;
        } elseif ($sessions30d <= 1) {
            $risk += 0.1;
        }

        // Days since last seen
        $daysSinceLastSeen = $behavioural['days_since_last_seen'] ?? 999;
        if ($daysSinceLastSeen > 60) {
            $risk += 0.15;
        } elseif ($daysSinceLastSeen > 30) {
            $risk += 0.05;
        }

        return min($risk, 1.0);
    }

    /**
     * Compute purchase propensity (0.0 – 1.0).
     * Higher = more likely to buy soon.
     */
    private function computePurchasePropensity(int $daysSinceLastOrder, int $totalOrders, array $behavioural): float
    {
        $score = 0.0;

        // Recent browser → strong signal
        $sessions30d = $behavioural['sessions_30d'] ?? 0;
        if ($sessions30d >= 5) {
            $score += 0.3;
        } elseif ($sessions30d >= 2) {
            $score += 0.2;
        } elseif ($sessions30d >= 1) {
            $score += 0.1;
        }

        // Recency of last order
        if ($daysSinceLastOrder <= 14) {
            $score += 0.15; // recently bought — might buy again
        } elseif ($daysSinceLastOrder <= 30) {
            $score += 0.2; // in the sweet spot
        } elseif ($daysSinceLastOrder <= 60) {
            $score += 0.15;
        }

        // Order frequency (loyal buyers)
        if ($totalOrders >= 5) {
            $score += 0.25;
        } elseif ($totalOrders >= 3) {
            $score += 0.15;
        } elseif ($totalOrders >= 2) {
            $score += 0.1;
        }

        // Days since last seen (active browsing)
        $daysSinceLastSeen = $behavioural['days_since_last_seen'] ?? 999;
        if ($daysSinceLastSeen <= 3) {
            $score += 0.15;
        } elseif ($daysSinceLastSeen <= 7) {
            $score += 0.1;
        }

        return min($score, 1.0);
    }

    /**
     * Determine discount sensitivity: low | medium | high.
     */
    private function computeDiscountSensitivity(array $transactional): string
    {
        $couponRate = $transactional['coupon_usage_rate'] ?? 0;

        if ($couponRate >= 60) return 'high';
        if ($couponRate >= 25) return 'medium';
        return 'low';
    }

    /**
     * Predict 12-month LTV using simple extrapolation.
     */
    private function predictLtv(array $transactional): float
    {
        $lifetime = $transactional['lifetime_revenue'] ?? 0;
        $daysSinceFirst = $transactional['days_since_first_order'] ?? 0;

        if ($daysSinceFirst <= 0 || $lifetime <= 0) {
            return 0;
        }

        // Monthly run rate × 12
        $monthlyRate = ($lifetime / max($daysSinceFirst, 1)) * 30;
        return $monthlyRate * 12;
    }

    /**
     * Determine preferred communication channel.
     */
    private function determinePreferredChannel(CdpProfile $profile): string
    {
        $engagement = $profile->engagement ?? [];
        $emailClick = (float) ($engagement['email_click_rate'] ?? 0);
        $smsSubscribed = $engagement['sms_subscribed'] ?? false;

        if ($emailClick > 30) return 'email';
        if ($smsSubscribed) return 'sms';
        return 'email';
    }

    /* ══════════════════════════════════════════
     *  SEGMENT DISPLAY HELPERS
     * ══════════════════════════════════════════ */

    private function segmentIcon(string $segment): string
    {
        return match ($segment) {
            'Champion'       => '🏆',
            'Loyal'          => '💛',
            'Potential Loyal' => '🚀',
            'New Customer'   => '🆕',
            'Promising'      => '🔮',
            'Need Attention' => '💤',
            'About to Sleep' => '😴',
            'At Risk'        => '⚠️',
            'Cannot Lose'    => '❌',
            'Hibernating'    => '👻',
            default          => '📊',
        };
    }

    private function segmentColor(string $segment): string
    {
        return match ($segment) {
            'Champion'       => '#28a745',
            'Loyal'          => '#20c997',
            'Potential Loyal' => '#17a2b8',
            'New Customer'   => '#007bff',
            'Promising'      => '#6f42c1',
            'Need Attention' => '#ffc107',
            'About to Sleep' => '#fd7e14',
            'At Risk'        => '#dc3545',
            'Cannot Lose'    => '#e83e8c',
            'Hibernating'    => '#6c757d',
            default          => '#adb5bd',
        };
    }
}

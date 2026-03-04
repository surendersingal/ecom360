<?php
declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * DynamicPricingService — VIP Price Thawing & RFM-based dynamic discounts.
 *
 * Powers Use Cases:
 *   - VIP Price Thawing (UC6)
 *   - Dormancy Gradient Flows (UC13)
 *   - Win-Back with Price-Match Coupons (UC12)
 */
class DynamicPricingService
{
    private const CACHE_TTL = 1800; // 30 min

    /**
     * Calculate RFM (Recency, Frequency, Monetary) score for a customer.
     * Returns normalized 0-100 score.
     */
    public function calculateRfmScore(int $tenantId, string $email): array
    {
        $cacheKey = "rfm:{$tenantId}:{$email}";
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId, $email) {
            return $this->computeRfm($tenantId, $email);
        });
    }

    /**
     * Get dynamic price for a customer (VIP thawing).
     * A lapsed VIP gets escalating discounts to re-engage.
     */
    public function getVipPrice(int $tenantId, string $email, float $basePrice): array
    {
        $rfm = $this->calculateRfmScore($tenantId, $email);
        $score = $rfm['rfm_score'] ?? 0;
        $recencyDays = $rfm['recency_days'] ?? 999;
        $tier = $rfm['tier'] ?? 'unknown';

        // VIP price thawing: higher discount for lapsed VIPs
        $discount = 0;
        $reason = 'standard_price';

        if ($tier === 'champion' || $tier === 'loyal') {
            // Active VIP — small loyalty discount
            $discount = 5;
            $reason = 'vip_loyalty_discount';
        } elseif ($tier === 'at_risk') {
            // At-risk VIP — escalating discount based on recency
            if ($recencyDays > 90) {
                $discount = 20;
                $reason = 'vip_thaw_urgent';
            } elseif ($recencyDays > 60) {
                $discount = 15;
                $reason = 'vip_thaw_moderate';
            } else {
                $discount = 10;
                $reason = 'vip_thaw_early';
            }
        } elseif ($tier === 'hibernating') {
            // Fully lapsed VIP — maximum incentive
            $discount = min(25, 10 + ($recencyDays / 30) * 3);
            $reason = 'vip_thaw_win_back';
        } elseif ($tier === 'new_customer') {
            // New customer — welcome discount
            $discount = 10;
            $reason = 'new_customer_welcome';
        }

        $discountedPrice = round($basePrice * (1 - $discount / 100), 2);

        return [
            'base_price'       => $basePrice,
            'discounted_price' => $discountedPrice,
            'discount_percent' => round($discount, 1),
            'reason'           => $reason,
            'tier'             => $tier,
            'rfm_score'        => $score,
            'recency_days'     => $recencyDays,
            'valid_for_hours'  => $this->getOfferWindow($tier),
        ];
    }

    /**
     * Calculate dormancy gradient — escalating urgency for win-back campaigns.
     */
    public function getDormancyGradient(int $tenantId, string $email): array
    {
        $rfm = $this->calculateRfmScore($tenantId, $email);
        $recencyDays = $rfm['recency_days'] ?? 0;
        $tier = $rfm['tier'] ?? 'unknown';
        $isVip = in_array($tier, ['champion', 'loyal', 'at_risk', 'hibernating']);

        // Dormancy stages with escalating offers
        $stages = [
            ['min' => 0, 'max' => 30, 'stage' => 'active', 'action' => 'none', 'discount' => 0],
            ['min' => 30, 'max' => 60, 'stage' => 'cooling', 'action' => 'gentle_nudge', 'discount' => 5],
            ['min' => 60, 'max' => 90, 'stage' => 'dormant', 'action' => 'personal_email', 'discount' => 10],
            ['min' => 90, 'max' => 120, 'stage' => 'at_risk', 'action' => 'exclusive_offer', 'discount' => 15],
            ['min' => 120, 'max' => 180, 'stage' => 'lapsing', 'action' => 'final_offer', 'discount' => 20],
            ['min' => 180, 'max' => 999, 'stage' => 'lost', 'action' => 'hail_mary', 'discount' => 25],
        ];

        $currentStage = $stages[0];
        foreach ($stages as $stage) {
            if ($recencyDays >= $stage['min'] && $recencyDays < $stage['max']) {
                $currentStage = $stage;
                break;
            }
        }

        // VIPs get boosted discounts
        if ($isVip) {
            $currentStage['discount'] = min(30, $currentStage['discount'] + 5);
        }

        return [
            'email'          => $email,
            'recency_days'   => $recencyDays,
            'tier'           => $tier,
            'is_vip'         => $isVip,
            'dormancy_stage' => $currentStage['stage'],
            'recommended_action' => $currentStage['action'],
            'discount_percent' => $currentStage['discount'],
            'lifetime_value' => $rfm['monetary_total'] ?? 0,
            'order_count'    => $rfm['frequency'] ?? 0,
        ];
    }

    /**
     * Suggest price-match coupon to win back customer.
     */
    public function suggestPriceMatchCoupon(int $tenantId, string $email, float $competitorPrice, float $ourPrice): array
    {
        $rfm = $this->calculateRfmScore($tenantId, $email);
        $tier = $rfm['tier'] ?? 'unknown';
        $priceDiff = $ourPrice - $competitorPrice;

        if ($priceDiff <= 0) {
            return [
                'match_needed' => false,
                'reason'       => 'our_price_already_lower',
                'our_price'    => $ourPrice,
                'competitor_price' => $competitorPrice,
            ];
        }

        // How much we can afford to match based on customer value
        $maxMatchPercent = match ($tier) {
            'champion' => 100,  // Full match for champions
            'loyal'    => 90,
            'at_risk'  => 110,  // Beat competitor for at-risk
            'hibernating' => 110,
            'new_customer' => 80,
            default => 50,
        };

        $matchAmount = min($priceDiff * ($maxMatchPercent / 100), $priceDiff);
        $finalPrice = round($ourPrice - $matchAmount, 2);

        return [
            'match_needed'       => true,
            'our_price'          => $ourPrice,
            'competitor_price'   => $competitorPrice,
            'price_difference'   => round($priceDiff, 2),
            'coupon_amount'      => round($matchAmount, 2),
            'final_price'        => $finalPrice,
            'match_percent'      => round(($matchAmount / $priceDiff) * 100, 1),
            'customer_tier'      => $tier,
            'rfm_score'          => $rfm['rfm_score'] ?? 0,
            'valid_for_hours'    => $this->getOfferWindow($tier),
        ];
    }

    /**
     * Bulk segment customers by RFM tier.
     */
    public function segmentCustomersByRfm(int $tenantId): array
    {
        try {
            $customers = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->distinct('customer_identifier.value')
                ->get()
                ->map(fn($ci) => is_array($ci) ? ($ci['value'] ?? $ci) : $ci)
                ->filter()
                ->unique()
                ->values();

            $segments = [
                'champion'     => [],
                'loyal'        => [],
                'at_risk'      => [],
                'hibernating'  => [],
                'new_customer' => [],
                'casual'       => [],
            ];

            foreach ($customers as $email) {
                if (!is_string($email)) continue;
                $rfm = $this->calculateRfmScore($tenantId, $email);
                $tier = $rfm['tier'] ?? 'casual';
                $segments[$tier][] = [
                    'email'     => $email,
                    'rfm_score' => $rfm['rfm_score'],
                    'recency'   => $rfm['recency_days'],
                    'frequency' => $rfm['frequency'],
                    'monetary'  => $rfm['monetary_total'],
                ];
            }

            $summary = [];
            foreach ($segments as $tier => $members) {
                $summary[$tier] = [
                    'count'    => count($members),
                    'avg_rfm'  => count($members) > 0
                        ? round(array_sum(array_column($members, 'rfm_score')) / count($members), 1)
                        : 0,
                    'members'  => array_slice($members, 0, 20), // Top 20 per segment
                ];
            }

            return [
                'segments'        => $summary,
                'total_customers' => $customers->count(),
            ];
        } catch (\Exception $e) {
            Log::error("DynamicPricingService::segmentCustomersByRfm error: {$e->getMessage()}");
            return ['segments' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Internal: compute RFM for a customer.
     */
    private function computeRfm(int $tenantId, string $email): array
    {
        try {
            $purchases = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('customer_identifier.value', $email)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($purchases->isEmpty()) {
                return [
                    'email' => $email, 'rfm_score' => 0, 'tier' => 'unknown',
                    'recency_days' => 999, 'frequency' => 0, 'monetary_total' => 0,
                    'recency_score' => 0, 'frequency_score' => 0, 'monetary_score' => 0,
                ];
            }

            // Cast stdClass results to arrays for consistent access
            $purchases = $purchases->map(fn($p) => (array) $p);

            // Recency: days since last purchase
            $lastPurchaseDate = $purchases->first()['created_at'] ?? now()->toDateTimeString();
            $recencyDays = (int) now()->diffInDays($lastPurchaseDate);

            // Frequency: total orders
            $frequency = $purchases->count();

            // Monetary: total spend
            $monetaryTotal = $purchases->sum(fn($p) => (float) (((array) ($p['metadata'] ?? []))['total'] ?? 0));

            // Score each dimension 1-5
            $recencyScore = match (true) {
                $recencyDays <= 7 => 5,
                $recencyDays <= 30 => 4,
                $recencyDays <= 60 => 3,
                $recencyDays <= 120 => 2,
                default => 1,
            };

            $frequencyScore = match (true) {
                $frequency >= 20 => 5,
                $frequency >= 10 => 4,
                $frequency >= 5 => 3,
                $frequency >= 2 => 2,
                default => 1,
            };

            $monetaryScore = match (true) {
                $monetaryTotal >= 5000 => 5,
                $monetaryTotal >= 2000 => 4,
                $monetaryTotal >= 500 => 3,
                $monetaryTotal >= 100 => 2,
                default => 1,
            };

            // Weighted RFM score (0-100)
            $rfmScore = round(
                ($recencyScore * 0.4 + $frequencyScore * 0.35 + $monetaryScore * 0.25) * 20
            );

            // Tier assignment
            $tier = match (true) {
                $rfmScore >= 80 && $recencyScore >= 4 => 'champion',
                $rfmScore >= 60 && $recencyScore >= 3 => 'loyal',
                $rfmScore >= 50 && $recencyScore <= 2 => 'at_risk',
                $rfmScore >= 30 && $recencyScore <= 1 => 'hibernating',
                $frequency <= 1 && $recencyScore >= 4 => 'new_customer',
                default => 'casual',
            };

            // First purchase date
            $firstPurchaseDate = $purchases->last()['created_at'] ?? null;  // Already cast to array above
            $customerAgeDays = $firstPurchaseDate ? (int) now()->diffInDays($firstPurchaseDate) : 0;

            return [
                'email'           => $email,
                'rfm_score'       => (int) $rfmScore,
                'tier'            => $tier,
                'recency_days'    => $recencyDays,
                'frequency'       => $frequency,
                'monetary_total'  => round($monetaryTotal, 2),
                'recency_score'   => $recencyScore,
                'frequency_score' => $frequencyScore,
                'monetary_score'  => $monetaryScore,
                'customer_age_days' => $customerAgeDays,
                'avg_order_value' => $frequency > 0 ? round($monetaryTotal / $frequency, 2) : 0,
            ];
        } catch (\Exception $e) {
            Log::error("DynamicPricingService::computeRfm error: {$e->getMessage()}");
            return [
                'email' => $email, 'rfm_score' => 0, 'tier' => 'unknown',
                'recency_days' => 999, 'frequency' => 0, 'monetary_total' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get offer window in hours based on tier urgency.
     */
    private function getOfferWindow(string $tier): int
    {
        return match ($tier) {
            'at_risk'      => 24,
            'hibernating'  => 48,
            'new_customer' => 72,
            'champion'     => 168, // 1 week
            'loyal'        => 120, // 5 days
            default        => 48,
        };
    }
}

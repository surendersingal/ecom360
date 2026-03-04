<?php
declare(strict_types=1);

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CouponService — Single-use coupon generation, price-match coupons, abandoned-cart coupons.
 *
 * Powers Use Cases:
 *   - Exit-Intent Dynamic Coupon (UC1)
 *   - Win-Back with Price-Match Coupons (UC12)
 *   - Abandoned Cart Recovery Coupons (UC4)
 *   - Birthday/Anniversary Triggers (UC11)
 */
class CouponService
{
    /**
     * Generate a single-use coupon for a customer.
     */
    public function generate(int $tenantId, array $params): array
    {
        $code = $params['code'] ?? $this->generateCode($params['prefix'] ?? 'EC360');
        $type = $params['type'] ?? 'percentage'; // percentage | fixed_amount | free_shipping
        $value = (float) ($params['value'] ?? 10);
        $minOrderAmount = (float) ($params['min_order_amount'] ?? 0);
        $maxDiscount = (float) ($params['max_discount'] ?? 0);
        $expiresIn = (int) ($params['expires_in_hours'] ?? 48);
        $reason = $params['reason'] ?? 'general';
        $email = $params['email'] ?? null;
        $productIds = $params['product_ids'] ?? [];
        $categoryIds = $params['category_ids'] ?? [];

        try {
            $coupon = [
                'tenant_id'        => $tenantId,
                'code'             => $code,
                'type'             => $type,
                'value'            => $value,
                'min_order_amount' => $minOrderAmount,
                'max_discount'     => $maxDiscount,
                'email'            => $email,
                'reason'           => $reason,
                'product_ids'      => $productIds,
                'category_ids'     => $categoryIds,
                'single_use'       => true,
                'used'             => false,
                'used_at'          => null,
                'expires_at'       => now()->addHours($expiresIn)->toDateTimeString(),
                'created_at'       => now()->toDateTimeString(),
                'metadata'         => $params['metadata'] ?? [],
            ];

            DB::connection('mongodb')
                ->table('coupons')
                ->insert($coupon);

            return [
                'success'   => true,
                'code'      => $code,
                'type'      => $type,
                'value'     => $value,
                'expires_at' => $coupon['expires_at'],
                'reason'    => $reason,
                'display'   => $this->formatDisplay($type, $value),
            ];
        } catch (\Exception $e) {
            Log::error("CouponService::generate error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate exit-intent coupon with escalation.
     * First exit → 5%, second → 10%, third → free shipping.
     */
    public function generateExitIntentCoupon(int $tenantId, string $email, string $sessionId): array
    {
        $exitCount = $this->getExitIntentCount($tenantId, $email);
        $escalation = $this->getEscalation($exitCount);

        return $this->generate($tenantId, [
            'email'          => $email,
            'type'           => $escalation['type'],
            'value'          => $escalation['value'],
            'expires_in_hours' => $escalation['expires_in_hours'],
            'reason'         => 'exit_intent',
            'prefix'         => 'EXIT',
            'min_order_amount' => $escalation['min_order'] ?? 0,
            'metadata'       => [
                'session_id' => $sessionId,
                'exit_count' => $exitCount + 1,
                'escalation_level' => $escalation['level'],
            ],
        ]);
    }

    /**
     * Generate abandoned cart recovery coupon.
     */
    public function generateAbandonedCartCoupon(int $tenantId, string $email, float $cartTotal): array
    {
        // Graduated discount based on cart value
        $discount = match (true) {
            $cartTotal >= 200 => 15,
            $cartTotal >= 100 => 12,
            $cartTotal >= 50  => 10,
            default           => 8,
        };

        return $this->generate($tenantId, [
            'email'            => $email,
            'type'             => 'percentage',
            'value'            => $discount,
            'expires_in_hours' => 24,
            'reason'           => 'abandoned_cart',
            'prefix'           => 'CART',
            'min_order_amount' => 0,
            'metadata'         => ['cart_total' => $cartTotal],
        ]);
    }

    /**
     * Generate birthday/anniversary coupon.
     */
    public function generateBirthdayCoupon(int $tenantId, string $email, string $occasion = 'birthday'): array
    {
        $value = $occasion === 'birthday' ? 20 : 15;
        $prefix = $occasion === 'birthday' ? 'BDAY' : 'ANNIV';

        return $this->generate($tenantId, [
            'email'            => $email,
            'type'             => 'percentage',
            'value'            => $value,
            'expires_in_hours' => 168, // 7 days
            'reason'           => $occasion,
            'prefix'           => $prefix,
            'metadata'         => ['occasion' => $occasion],
        ]);
    }

    /**
     * Generate price-match coupon for specific product.
     */
    public function generatePriceMatchCoupon(
        int $tenantId,
        string $email,
        string $productId,
        float $ourPrice,
        float $competitorPrice
    ): array {
        $diff = max(0, $ourPrice - $competitorPrice);
        if ($diff <= 0) {
            return ['success' => false, 'error' => 'Our price is already lower or equal.'];
        }

        $matchPct = 105; // Beat competitor by 5%
        $couponValue = round($diff * ($matchPct / 100), 2);

        return $this->generate($tenantId, [
            'email'            => $email,
            'type'             => 'fixed_amount',
            'value'            => $couponValue,
            'expires_in_hours' => 48,
            'reason'           => 'price_match',
            'prefix'           => 'MATCH',
            'product_ids'      => [$productId],
            'min_order_amount' => 0,
            'metadata' => [
                'product_id'       => $productId,
                'our_price'        => $ourPrice,
                'competitor_price' => $competitorPrice,
                'match_amount'     => $couponValue,
            ],
        ]);
    }

    /**
     * Validate a coupon.
     */
    public function validate(int $tenantId, string $code, ?string $email = null, float $orderTotal = 0): array
    {
        try {
            $couponRaw = DB::connection('mongodb')
                ->table('coupons')
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->first();

            if (!$couponRaw) {
                return ['valid' => false, 'error' => 'Coupon not found.'];
            }

            $coupon = (array) $couponRaw;

            if ($coupon['used'] ?? false) {
                return ['valid' => false, 'error' => 'Coupon already used.'];
            }

            if (isset($coupon['expires_at']) && now()->gt($coupon['expires_at'])) {
                return ['valid' => false, 'error' => 'Coupon expired.'];
            }

            if (!empty($coupon['email']) && $email && $coupon['email'] !== $email) {
                return ['valid' => false, 'error' => 'Coupon not valid for this account.'];
            }

            if (($coupon['min_order_amount'] ?? 0) > 0 && $orderTotal < $coupon['min_order_amount']) {
                return [
                    'valid' => false,
                    'error' => "Minimum order amount is {$coupon['min_order_amount']}.",
                ];
            }

            $discount = $this->calculateDiscount($coupon, $orderTotal);

            return [
                'valid'        => true,
                'code'         => $code,
                'type'         => $coupon['type'],
                'value'        => $coupon['value'],
                'discount'     => $discount,
                'expires_at'   => $coupon['expires_at'] ?? null,
                'reason'       => $coupon['reason'] ?? 'general',
            ];
        } catch (\Exception $e) {
            Log::error("CouponService::validate error: {$e->getMessage()}");
            return ['valid' => false, 'error' => 'Validation error.'];
        }
    }

    /**
     * Redeem (mark used) a coupon.
     */
    public function redeem(int $tenantId, string $code, string $orderId): array
    {
        try {
            $updated = DB::connection('mongodb')
                ->table('coupons')
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->where('used', false)
                ->update([
                    'used'     => true,
                    'used_at'  => now()->toDateTimeString(),
                    'order_id' => $orderId,
                ]);

            return ['success' => $updated > 0];
        } catch (\Exception $e) {
            Log::error("CouponService::redeem error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List coupons for a tenant.
     */
    public function listCoupons(int $tenantId, array $filters = []): array
    {
        try {
            $query = DB::connection('mongodb')
                ->table('coupons')
                ->where('tenant_id', $tenantId);

            if (isset($filters['email'])) {
                $query->where('email', $filters['email']);
            }
            if (isset($filters['reason'])) {
                $query->where('reason', $filters['reason']);
            }
            if (isset($filters['used'])) {
                $query->where('used', (bool) $filters['used']);
            }
            if (isset($filters['active'])) {
                $query->where('expires_at', '>=', now()->toDateTimeString());
                $query->where('used', false);
            }

            $coupons = $query->orderBy('created_at', 'desc')
                ->limit((int) ($filters['limit'] ?? 50))
                ->get();

            return [
                'coupons' => $coupons->toArray(),
                'count'   => $coupons->count(),
            ];
        } catch (\Exception $e) {
            Log::error("CouponService::listCoupons error: {$e->getMessage()}");
            return ['coupons' => [], 'error' => $e->getMessage()];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function generateCode(string $prefix = 'EC360'): string
    {
        return strtoupper($prefix . '-' . Str::random(8));
    }

    private function getExitIntentCount(int $tenantId, string $email): int
    {
        try {
            return (int) DB::connection('mongodb')
                ->table('coupons')
                ->where('tenant_id', $tenantId)
                ->where('email', $email)
                ->where('reason', 'exit_intent')
                ->where('created_at', '>=', now()->subDays(30)->toDateTimeString())
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getEscalation(int $exitCount): array
    {
        return match (true) {
            $exitCount >= 3 => [
                'type' => 'free_shipping', 'value' => 0,
                'expires_in_hours' => 12, 'level' => 'max',
                'min_order' => 25,
            ],
            $exitCount >= 1 => [
                'type' => 'percentage', 'value' => 10,
                'expires_in_hours' => 24, 'level' => 'medium',
                'min_order' => 0,
            ],
            default => [
                'type' => 'percentage', 'value' => 5,
                'expires_in_hours' => 48, 'level' => 'low',
                'min_order' => 0,
            ],
        };
    }

    private function calculateDiscount(array $coupon, float $orderTotal): float
    {
        $discount = match ($coupon['type'] ?? 'percentage') {
            'percentage'    => $orderTotal * ($coupon['value'] / 100),
            'fixed_amount'  => $coupon['value'],
            'free_shipping' => 0, // handled separately at checkout
            default         => 0,
        };

        if (($coupon['max_discount'] ?? 0) > 0) {
            $discount = min($discount, $coupon['max_discount']);
        }

        return round($discount, 2);
    }

    private function formatDisplay(string $type, float $value): string
    {
        return match ($type) {
            'percentage'    => "{$value}% OFF",
            'fixed_amount'  => "\${$value} OFF",
            'free_shipping' => 'FREE SHIPPING',
            default         => "{$value} discount",
        };
    }
}

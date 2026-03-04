<?php
declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * ReturnRiskService — Serial returner detection and return risk scoring.
 *
 * Powers Use Cases:
 *   - Serial Returner Detection (UC10)
 *   - Return-Risk adjusted promotions
 */
class ReturnRiskService
{
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Score return risk for a customer (0-100, higher = riskier).
     */
    public function scoreCustomer(int $tenantId, string $email): array
    {
        $cacheKey = "return_risk:{$tenantId}:{$email}";
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId, $email) {
            return $this->computeReturnRisk($tenantId, $email);
        });
    }

    /**
     * Detect serial returners across the tenant.
     */
    public function detectSerialReturners(int $tenantId, float $returnRateThreshold = 30, int $minOrders = 3): array
    {
        try {
            // Get all refund events
            $refunds = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'refund')
                ->where('created_at', '>=', now()->subDays(365)->toDateTimeString())
                ->get();

            // Get all purchase events
            $purchases = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('created_at', '>=', now()->subDays(365)->toDateTimeString())
                ->get();

            // Group by customer email
            $customerPurchases = [];
            foreach ($purchases as $p) {
                $p = $p instanceof \stdClass ? json_decode(json_encode($p), true) : $p;
                $email = $p['customer_identifier']['value'] ?? ($p['metadata']['customer_email'] ?? null);
                if ($email) {
                    $customerPurchases[$email] = ($customerPurchases[$email] ?? 0) + 1;
                }
            }

            $customerRefunds = [];
            foreach ($refunds as $r) {
                $r = $r instanceof \stdClass ? json_decode(json_encode($r), true) : $r;
                $email = $r['customer_identifier']['value'] ?? ($r['metadata']['customer_email'] ?? null);
                if ($email) {
                    $customerRefunds[$email] = ($customerRefunds[$email] ?? 0) + 1;
                }
            }

            $serialReturners = [];
            foreach ($customerRefunds as $email => $refundCount) {
                $orderCount = $customerPurchases[$email] ?? 0;
                if ($orderCount < $minOrders) continue;

                $returnRate = ($refundCount / $orderCount) * 100;
                if ($returnRate >= $returnRateThreshold) {
                    $risk = $this->computeReturnRisk($tenantId, $email);
                    $serialReturners[] = [
                        'email'        => $email,
                        'order_count'  => $orderCount,
                        'refund_count' => $refundCount,
                        'return_rate'  => round($returnRate, 2),
                        'risk_score'   => $risk['risk_score'],
                        'risk_level'   => $risk['risk_level'],
                        'total_refund_amount' => $risk['total_refund_amount'],
                    ];
                }
            }

            usort($serialReturners, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);

            return [
                'serial_returners' => $serialReturners,
                'count'            => count($serialReturners),
                'threshold'        => $returnRateThreshold,
                'min_orders'       => $minOrders,
                'analysis_period'  => '365_days',
            ];
        } catch (\Exception $e) {
            Log::error("ReturnRiskService::detectSerialReturners error: {$e->getMessage()}");
            return ['serial_returners' => [], 'count' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Analyze return patterns for a product.
     */
    public function analyzeProductReturns(int $tenantId, int $limit = 50): array
    {
        try {
            $refunds = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'refund')
                ->where('created_at', '>=', now()->subDays(180)->toDateTimeString())
                ->get();

            $purchases = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('created_at', '>=', now()->subDays(180)->toDateTimeString())
                ->get();

            // Count purchases per product
            $productPurchases = [];
            foreach ($purchases as $p) {
                $items = $p['metadata']['items'] ?? [];
                foreach ($items as $item) {
                    $pid = (string) ($item['product_id'] ?? '');
                    if (!$pid) continue;
                    if (!isset($productPurchases[$pid])) {
                        $productPurchases[$pid] = ['name' => $item['name'] ?? '', 'count' => 0];
                    }
                    $productPurchases[$pid]['count'] += (int) ($item['qty'] ?? 1);
                }
            }

            // Count returns per product (from refund metadata or order association)
            $productReturns = [];
            foreach ($refunds as $r) {
                $orderId = $r['metadata']['order_id'] ?? '';
                // Find the original purchase for this order
                $originalOrder = $purchases->first(fn($p) => ($p['metadata']['order_id'] ?? '') === $orderId);
                if ($originalOrder) {
                    $items = $originalOrder['metadata']['items'] ?? [];
                    foreach ($items as $item) {
                        $pid = (string) ($item['product_id'] ?? '');
                        if (!$pid) continue;
                        $productReturns[$pid] = ($productReturns[$pid] ?? 0) + 1;
                    }
                }
            }

            $analysis = [];
            foreach ($productReturns as $pid => $returnCount) {
                $purchaseCount = $productPurchases[$pid]['count'] ?? 0;
                $returnRate = $purchaseCount > 0 ? ($returnCount / $purchaseCount) * 100 : 0;

                $analysis[] = [
                    'product_id'    => $pid,
                    'name'          => $productPurchases[$pid]['name'] ?? '',
                    'purchase_count' => $purchaseCount,
                    'return_count'  => $returnCount,
                    'return_rate'   => round($returnRate, 2),
                    'risk_flag'     => $returnRate > 20 ? 'high' : ($returnRate > 10 ? 'medium' : 'low'),
                ];
            }

            usort($analysis, fn($a, $b) => $b['return_rate'] <=> $a['return_rate']);

            return [
                'products' => array_slice($analysis, 0, $limit),
                'high_risk_count' => count(array_filter($analysis, fn($a) => $a['risk_flag'] === 'high')),
            ];
        } catch (\Exception $e) {
            Log::error("ReturnRiskService::analyzeProductReturns error: {$e->getMessage()}");
            return ['products' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Should we offer a coupon to this customer? Returns false for serial returners.
     */
    public function shouldOfferCoupon(int $tenantId, string $email): bool
    {
        $risk = $this->scoreCustomer($tenantId, $email);
        return ($risk['risk_score'] ?? 0) < 60;
    }

    /**
     * Compute return risk score for a specific customer.
     */
    private function computeReturnRisk(int $tenantId, string $email): array
    {
        try {
            $purchases = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('customer_identifier.value', $email)
                ->get();

            $refunds = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'refund')
                ->where('customer_identifier.value', $email)
                ->get();

            $orderCount = $purchases->count();
            $refundCount = $refunds->count();

            if ($orderCount === 0) {
                return [
                    'email'              => $email,
                    'risk_score'         => 0,
                    'risk_level'         => 'unknown',
                    'order_count'        => 0,
                    'refund_count'       => 0,
                    'return_rate'        => 0,
                    'total_refund_amount' => 0,
                ];
            }

            $returnRate = ($refundCount / $orderCount) * 100;
            $totalRefundAmount = $refunds->sum(fn($r) => (float) (((array) $r)['metadata']['refund_amount'] ?? 0));
            $totalPurchaseAmount = $purchases->sum(fn($p) => (float) (((array) $p)['metadata']['total'] ?? 0));
            $refundValueRate = $totalPurchaseAmount > 0 ? ($totalRefundAmount / $totalPurchaseAmount) * 100 : 0;

            // Weighted risk score: 40% return frequency, 30% return value, 20% recency, 10% velocity
            $frequencyScore = min(100, $returnRate * 2);
            $valueScore = min(100, $refundValueRate * 2);

            // Recency: recent returns are riskier
            $lastRefund = $refunds->max('created_at');
            $daysSinceLastReturn = $lastRefund ? now()->diffInDays($lastRefund) : 365;
            $recencyScore = max(0, 100 - $daysSinceLastReturn);

            // Velocity: returns increasing over time?
            $recentRefunds = $refunds->filter(fn($r) => now()->diffInDays(((array) $r)['created_at'] ?? now()) < 90)->count();
            $velocityScore = min(100, $recentRefunds * 25);

            $riskScore = round(
                $frequencyScore * 0.4 +
                $valueScore * 0.3 +
                $recencyScore * 0.2 +
                $velocityScore * 0.1
            );

            $riskLevel = match (true) {
                $riskScore >= 70 => 'high',
                $riskScore >= 40 => 'medium',
                $riskScore >= 15 => 'low',
                default => 'minimal',
            };

            return [
                'email'               => $email,
                'risk_score'          => (int) $riskScore,
                'risk_level'          => $riskLevel,
                'order_count'         => $orderCount,
                'refund_count'        => $refundCount,
                'return_rate'         => round($returnRate, 2),
                'total_refund_amount' => round($totalRefundAmount, 2),
                'refund_value_rate'   => round($refundValueRate, 2),
                'days_since_last_return' => $daysSinceLastReturn,
                'factors' => [
                    'frequency_score' => round($frequencyScore, 1),
                    'value_score'     => round($valueScore, 1),
                    'recency_score'   => round($recencyScore, 1),
                    'velocity_score'  => round($velocityScore, 1),
                ],
            ];
        } catch (\Exception $e) {
            Log::error("ReturnRiskService::computeReturnRisk error: {$e->getMessage()}");
            return [
                'email' => $email, 'risk_score' => 0, 'risk_level' => 'unknown',
                'order_count' => 0, 'refund_count' => 0, 'return_rate' => 0,
                'total_refund_amount' => 0, 'error' => $e->getMessage(),
            ];
        }
    }
}

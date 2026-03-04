<?php
declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AutonomousOpsService — Self-driving business operations powered by BI.
 *
 * UC21: Dynamic Pricing for Stale Inventory — auto markdown based on aging
 * UC22: Fraud Scoring on Every Order — real-time risk assessment
 * UC23: Demand Forecasting + Auto-Reorder — predict stockouts before they happen
 * UC24: Shipping Cost Analyzer — optimize carrier selection by route
 * UC25: Return Rate Anomaly Detection — flag unusual return patterns
 */
class AutonomousOpsService
{
    /**
     * UC21: Age-based dynamic pricing — stale inventory gets auto-markdown recommendations.
     */
    public function staleInventoryPricing(int $tenantId): array
    {
        try {
            $products = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('stock_qty', '>', 0)
                ->get();

            $staleItems = [];
            $totalPotentialRecovery = 0;

            foreach ($products as $productRaw) {
                $product = (array) $productRaw;
                $createdAt = $product['created_at'] ?? $product['synced_at'] ?? null;
                if (!$createdAt) continue;

                $daysInInventory = now()->diffInDays($createdAt);

                // Get recent sales velocity
                $recentSales = DB::connection('mongodb')
                    ->table('synced_orders')
                    ->where('tenant_id', $tenantId)
                    ->where('items.product_id', $product['external_id'] ?? '')
                    ->where('created_at', '>=', now()->subDays(30)->toDateTimeString())
                    ->count();

                $velocity = $recentSales / 30; // sales per day
                $daysToSellOut = $velocity > 0 ? ($product['stock_qty'] ?? 0) / $velocity : 999;

                // Stale if: >60 days old AND low velocity
                if ($daysInInventory >= 60 && $velocity < 0.5) {
                    $originalPrice = $product['price'] ?? 0;
                    $markdownPct = $this->calculateMarkdown($daysInInventory, $velocity, $daysToSellOut);
                    $suggestedPrice = round($originalPrice * (1 - $markdownPct / 100), 2);

                    $item = [
                        'product_id'       => $product['external_id'] ?? '',
                        'name'             => $product['name'] ?? '',
                        'original_price'   => $originalPrice,
                        'suggested_price'  => $suggestedPrice,
                        'markdown_pct'     => $markdownPct,
                        'stock_qty'        => $product['stock_qty'] ?? 0,
                        'days_in_inventory' => $daysInInventory,
                        'sales_velocity'   => round($velocity, 3),
                        'days_to_sellout'  => round($daysToSellOut),
                        'potential_recovery' => round(($product['stock_qty'] ?? 0) * $suggestedPrice, 2),
                        'category'         => $product['category'] ?? '',
                        'urgency'          => $daysInInventory > 120 ? 'critical' : ($daysInInventory > 90 ? 'high' : 'medium'),
                    ];

                    $totalPotentialRecovery += $item['potential_recovery'];
                    $staleItems[] = $item;
                }
            }

            usort($staleItems, fn($a, $b) => $b['days_in_inventory'] <=> $a['days_in_inventory']);

            return [
                'success'       => true,
                'stale_count'   => count($staleItems),
                'total_potential_recovery' => round($totalPotentialRecovery, 2),
                'by_urgency'    => [
                    'critical' => collect($staleItems)->where('urgency', 'critical')->count(),
                    'high'     => collect($staleItems)->where('urgency', 'high')->count(),
                    'medium'   => collect($staleItems)->where('urgency', 'medium')->count(),
                ],
                'items'         => array_slice($staleItems, 0, 50),
                'strategy'      => 'age_velocity_based',
            ];
        } catch (\Exception $e) {
            Log::error("AutonomousOps::stalePricing error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC22: Real-time fraud scoring on every order.
     */
    public function fraudScoring(int $tenantId, array $order): array
    {
        try {
            $score = 0;
            $flags = [];
            $email = $order['customer_email'] ?? '';

            // 1. Velocity check — multiple orders in short time
            $recentOrders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('customer_email', $email)
                ->where('created_at', '>=', now()->subHours(24)->toDateTimeString())
                ->count();

            if ($recentOrders >= 5) {
                $score += 30;
                $flags[] = 'velocity_spike';
            } elseif ($recentOrders >= 3) {
                $score += 15;
                $flags[] = 'moderate_velocity';
            }

            // 2. Order value anomaly
            $avgOrderValue = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('customer_email', $email)
                ->avg('total') ?? 0;

            $currentTotal = $order['total'] ?? 0;
            if ($avgOrderValue > 0 && $currentTotal > $avgOrderValue * 3) {
                $score += 25;
                $flags[] = 'value_anomaly';
            }

            // 3. Shipping/billing mismatch
            $shipping = $order['shipping_address'] ?? [];
            $billing = $order['billing_address'] ?? [];
            if (!empty($shipping) && !empty($billing)) {
                if (($shipping['country'] ?? '') !== ($billing['country'] ?? '')) {
                    $score += 20;
                    $flags[] = 'country_mismatch';
                }
                if (($shipping['zip'] ?? '') !== ($billing['zip'] ?? '')) {
                    $score += 10;
                    $flags[] = 'zip_mismatch';
                }
            }

            // 4. New account + high value
            $accountAge = DB::connection('mongodb')
                ->table('synced_customers')
                ->where('tenant_id', $tenantId)
                ->where('email', $email)
                ->first();
            
            if ($accountAge) {
                $daysSinceCreation = now()->diffInDays($accountAge['created_at'] ?? now());
                if ($daysSinceCreation <= 1 && $currentTotal > 200) {
                    $score += 20;
                    $flags[] = 'new_account_high_value';
                }
            }

            // 5. High-risk items (electronics, gift cards)
            foreach ($order['items'] ?? [] as $item) {
                $name = strtolower($item['name'] ?? '');
                if (str_contains($name, 'gift card') || str_contains($name, 'giftcard')) {
                    $score += 15;
                    $flags[] = 'gift_card_purchase';
                }
            }

            // 6. Multiple failed payment attempts
            $failedAttempts = $order['failed_payment_attempts'] ?? 0;
            if ($failedAttempts >= 3) {
                $score += 20;
                $flags[] = 'multiple_payment_failures';
            }

            $riskLevel = match (true) {
                $score >= 70 => 'critical',
                $score >= 50 => 'high',
                $score >= 30 => 'medium',
                $score >= 10 => 'low',
                default      => 'clean',
            };

            $recommendation = match ($riskLevel) {
                'critical' => 'BLOCK: Hold order for manual review immediately',
                'high'     => 'REVIEW: Flag for fraud team review before shipping',
                'medium'   => 'MONITOR: Process but monitor for additional signals',
                'low'      => 'PASS: Minor flags, proceed with standard checks',
                default    => 'PASS: No risk signals detected',
            };

            return [
                'success'        => true,
                'order_id'       => $order['order_id'] ?? null,
                'fraud_score'    => min(100, $score),
                'risk_level'     => $riskLevel,
                'flags'          => $flags,
                'recommendation' => $recommendation,
                'details'        => [
                    'recent_orders'   => $recentOrders,
                    'avg_order_value' => round($avgOrderValue, 2),
                    'current_total'   => $currentTotal,
                    'failed_attempts' => $failedAttempts,
                ],
            ];
        } catch (\Exception $e) {
            Log::error("AutonomousOps::fraudScoring error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage(), 'fraud_score' => 0, 'risk_level' => 'unknown'];
        }
    }

    /**
     * UC23: Demand forecasting — predict stockouts and suggest auto-reorder.
     */
    public function demandForecasting(int $tenantId): array
    {
        try {
            $products = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('stock_qty', '>', 0)
                ->get();

            $forecasts = [];
            foreach ($products as $product) {
                $pid = $product['external_id'] ?? '';
                if (!$pid) continue;

                // Get weekly sales for past 12 weeks
                $weeklySales = [];
                for ($w = 11; $w >= 0; $w--) {
                    $start = now()->subWeeks($w + 1)->toDateTimeString();
                    $end = now()->subWeeks($w)->toDateTimeString();
                    $count = DB::connection('mongodb')
                        ->table('synced_orders')
                        ->where('tenant_id', $tenantId)
                        ->where('items.product_id', $pid)
                        ->where('created_at', '>=', $start)
                        ->where('created_at', '<', $end)
                        ->count();
                    $weeklySales[] = $count;
                }

                $recentAvg = count($weeklySales) > 0
                    ? array_sum(array_slice($weeklySales, -4)) / 4
                    : 0;

                // Simple trend detection
                $firstHalf = array_sum(array_slice($weeklySales, 0, 6));
                $secondHalf = array_sum(array_slice($weeklySales, 6));
                $trend = $firstHalf > 0 ? round(($secondHalf - $firstHalf) / $firstHalf * 100, 1) : 0;

                // Predict weeks until stockout
                $currentStock = $product['stock_qty'] ?? 0;
                $weeksToStockout = $recentAvg > 0 ? round($currentStock / $recentAvg, 1) : 999;

                if ($weeksToStockout <= 4) {
                    $reorderQty = max(1, (int) ceil($recentAvg * 8)); // 8-week supply

                    $forecasts[] = [
                        'product_id'       => $pid,
                        'name'             => $product['name'] ?? '',
                        'current_stock'    => $currentStock,
                        'weekly_avg_sales' => round($recentAvg, 1),
                        'trend_pct'        => $trend,
                        'trend_direction'  => $trend > 10 ? 'up' : ($trend < -10 ? 'down' : 'stable'),
                        'weeks_to_stockout' => $weeksToStockout,
                        'stockout_date'    => now()->addWeeks((int) $weeksToStockout)->toDateString(),
                        'reorder_qty'      => $reorderQty,
                        'urgency'          => $weeksToStockout <= 1 ? 'critical' : ($weeksToStockout <= 2 ? 'high' : 'medium'),
                        'weekly_sales_history' => $weeklySales,
                    ];
                }
            }

            usort($forecasts, fn($a, $b) => $a['weeks_to_stockout'] <=> $b['weeks_to_stockout']);

            return [
                'success'    => true,
                'total_products' => $products->count(),
                'at_risk_count'  => count($forecasts),
                'forecasts'  => array_slice($forecasts, 0, 50),
                'by_urgency' => [
                    'critical' => collect($forecasts)->where('urgency', 'critical')->count(),
                    'high'     => collect($forecasts)->where('urgency', 'high')->count(),
                    'medium'   => collect($forecasts)->where('urgency', 'medium')->count(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error("AutonomousOps::demandForecast error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC24: Shipping cost analyzer — optimize carrier selection by route.
     */
    public function shippingCostAnalyzer(int $tenantId): array
    {
        try {
            $orders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subDays(90)->toDateTimeString())
                ->get();

            $routeData = [];
            foreach ($orders as $order) {
                $region = $order['shipping_address']['state'] ?? $order['shipping_address']['region'] ?? 'unknown';
                $carrier = $order['shipping_method'] ?? 'standard';
                $cost = $order['shipping_cost'] ?? 0;
                $days = isset($order['delivered_at'], $order['shipped_at'])
                    ? now()->parse($order['delivered_at'])->diffInDays($order['shipped_at'])
                    : null;

                $key = "{$region}:{$carrier}";
                if (!isset($routeData[$key])) {
                    $routeData[$key] = [
                        'region' => $region, 'carrier' => $carrier,
                        'costs' => [], 'delivery_days' => [], 'order_count' => 0,
                    ];
                }
                $routeData[$key]['costs'][] = $cost;
                if ($days !== null) $routeData[$key]['delivery_days'][] = $days;
                $routeData[$key]['order_count']++;
            }

            $analysis = [];
            foreach ($routeData as $route) {
                $avgCost = count($route['costs']) > 0 ? array_sum($route['costs']) / count($route['costs']) : 0;
                $avgDays = count($route['delivery_days']) > 0 ? array_sum($route['delivery_days']) / count($route['delivery_days']) : null;

                $analysis[] = [
                    'region'             => $route['region'],
                    'carrier'            => $route['carrier'],
                    'order_count'        => $route['order_count'],
                    'avg_cost'           => round($avgCost, 2),
                    'avg_delivery_days'  => $avgDays ? round($avgDays, 1) : null,
                    'cost_per_day'       => $avgDays > 0 ? round($avgCost / $avgDays, 2) : null,
                    'total_spent'        => round(array_sum($route['costs']), 2),
                ];
            }

            // Group by region to find best carrier per route
            $regionOptimal = [];
            foreach (collect($analysis)->groupBy('region') as $region => $carriers) {
                $sorted = $carriers->sortBy('cost_per_day')->values();
                $regionOptimal[$region] = [
                    'best_value'    => $sorted->first(),
                    'fastest'       => $carriers->sortBy('avg_delivery_days')->first(),
                    'cheapest'      => $carriers->sortBy('avg_cost')->first(),
                    'carrier_count' => $carriers->count(),
                ];
            }

            return [
                'success'        => true,
                'total_orders'   => $orders->count(),
                'routes_analyzed' => count($analysis),
                'total_shipping_cost' => round(collect($analysis)->sum('total_spent'), 2),
                'avg_shipping_cost'   => round(collect($analysis)->avg('avg_cost'), 2),
                'route_analysis' => $analysis,
                'optimal_by_region' => $regionOptimal,
                'savings_opportunity' => $this->calculateShippingSavings($analysis, $regionOptimal),
            ];
        } catch (\Exception $e) {
            Log::error("AutonomousOps::shippingAnalyzer error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC25: Return rate anomaly detection — flag unusual return patterns.
     */
    public function returnRateAnomaly(int $tenantId): array
    {
        try {
            $orders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subDays(90)->toDateTimeString())
                ->get();

            $productReturns = [];
            foreach ($orders as $order) {
                foreach ($order['items'] ?? [] as $item) {
                    $pid = $item['product_id'] ?? '';
                    if (!$pid) continue;

                    if (!isset($productReturns[$pid])) {
                        $productReturns[$pid] = [
                            'name' => $item['name'] ?? '', 'category' => $item['category'] ?? '',
                            'total_sold' => 0, 'total_returned' => 0, 'return_reasons' => [],
                        ];
                    }
                    $productReturns[$pid]['total_sold'] += $item['quantity'] ?? 1;

                    if (($order['status'] ?? '') === 'returned' || ($item['returned'] ?? false)) {
                        $productReturns[$pid]['total_returned'] += $item['quantity'] ?? 1;
                        $reason = $item['return_reason'] ?? $order['return_reason'] ?? 'unspecified';
                        $productReturns[$pid]['return_reasons'][] = $reason;
                    }
                }
            }

            // Calculate overall average return rate
            $allRates = [];
            foreach ($productReturns as $pid => $data) {
                if ($data['total_sold'] >= 5) {
                    $rate = $data['total_returned'] / $data['total_sold'];
                    $allRates[] = $rate;
                    $productReturns[$pid]['return_rate'] = round($rate * 100, 1);
                }
            }
            $avgReturnRate = count($allRates) > 0 ? array_sum($allRates) / count($allRates) : 0;
            $stdDev = $this->standardDeviation($allRates);

            $anomalies = [];
            foreach ($productReturns as $pid => $data) {
                if (!isset($data['return_rate'])) continue;
                $rate = $data['return_rate'] / 100;

                // Flag if >2 standard deviations above mean
                if ($rate > $avgReturnRate + (2 * $stdDev) && $data['total_returned'] >= 3) {
                    $reasonCounts = array_count_values($data['return_reasons']);
                    arsort($reasonCounts);

                    $anomalies[] = [
                        'product_id'    => $pid,
                        'name'          => $data['name'],
                        'category'      => $data['category'],
                        'return_rate'   => $data['return_rate'],
                        'total_sold'    => $data['total_sold'],
                        'total_returned' => $data['total_returned'],
                        'top_reasons'   => array_slice($reasonCounts, 0, 3, true),
                        'severity'      => $rate > $avgReturnRate + (3 * $stdDev) ? 'critical' : 'warning',
                        'recommendation' => $this->returnRecommendation($reasonCounts),
                    ];
                }
            }

            usort($anomalies, fn($a, $b) => $b['return_rate'] <=> $a['return_rate']);

            return [
                'success'            => true,
                'avg_return_rate'    => round($avgReturnRate * 100, 1),
                'std_deviation'      => round($stdDev * 100, 1),
                'anomaly_count'      => count($anomalies),
                'anomalies'          => array_slice($anomalies, 0, 30),
                'total_products_analyzed' => count(array_filter($productReturns, fn($d) => isset($d['return_rate']))),
            ];
        } catch (\Exception $e) {
            Log::error("AutonomousOps::returnAnomaly error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Helpers ──────────────────────────────────────────

    private function calculateMarkdown(int|float $daysInInventory, float $velocity, float $daysToSellOut): float
    {
        if ($daysInInventory > 180) return 50;
        if ($daysInInventory > 120) return 35;
        if ($daysInInventory > 90) return 20;
        return 10;
    }

    private function calculateShippingSavings(array $analysis, array $regionOptimal): array
    {
        $potentialSavings = 0;
        foreach ($analysis as $route) {
            $region = $route['region'];
            $cheapest = $regionOptimal[$region]['cheapest']['avg_cost'] ?? $route['avg_cost'];
            $diff = $route['avg_cost'] - $cheapest;
            if ($diff > 0) {
                $potentialSavings += $diff * $route['order_count'];
            }
        }
        return [
            'estimated_savings' => round($potentialSavings, 2),
            'period' => '90_days',
        ];
    }

    private function standardDeviation(array $values): float
    {
        if (count($values) < 2) return 0;
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / (count($values) - 1);
        return sqrt($variance);
    }

    private function returnRecommendation(array $reasons): string
    {
        $topReason = array_key_first($reasons) ?? '';
        return match (true) {
            str_contains($topReason, 'size') || str_contains($topReason, 'fit') => 'Improve size guide / add fit finder tool',
            str_contains($topReason, 'quality') => 'Conduct supplier quality audit',
            str_contains($topReason, 'color') || str_contains($topReason, 'description') => 'Update product images/description for accuracy',
            str_contains($topReason, 'defect') || str_contains($topReason, 'damaged') => 'Review packaging and shipping process',
            default => 'Investigate with customer feedback',
        };
    }
}

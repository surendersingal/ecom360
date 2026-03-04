<?php
declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use MongoDB\Laravel\Eloquent\Model;

/**
 * InventoryService — Dead stock detection, margin analysis, and replenishment prediction.
 *
 * Powers Use Cases:
 *   - Dead-Stock Intelligent Bundling (UC7)
 *   - Margin-Optimized Search Ranking (UC17)
 *   - Inventory-Aware Replenishment Alerts (UC16)
 */
class InventoryService
{
    /**
     * Analyze dead stock — products with no sales in N days.
     */
    public function detectDeadStock(int $tenantId, int $daysSinceLastSale = 90, int $limit = 100): array
    {
        try {
            $cutoffDate = now()->subDays($daysSinceLastSale)->toDateTimeString();

            // Get products synced to the platform
            $products = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('status', 'enabled')
                ->get();

            // Get order items from the last N days
            $recentSoldProducts = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('created_at', '>=', $cutoffDate)
                ->get()
                ->pluck('metadata.items')
                ->flatten(1)
                ->pluck('product_id')
                ->unique()
                ->toArray();

            $deadStock = [];
            foreach ($products as $product) {
                $product = $product instanceof \stdClass ? (array) $product : $product;
                $productId = (string) ($product['external_id'] ?? $product['id'] ?? $product['product_id'] ?? '');
                if (!in_array($productId, $recentSoldProducts)) {
                    $deadStock[] = [
                        'product_id'     => $productId,
                        'name'           => $product['name'] ?? '',
                        'sku'            => $product['sku'] ?? '',
                        'price'          => (float) ($product['price'] ?? 0),
                        'cost_price'     => (float) ($product['cost_price'] ?? 0),
                        'stock_qty'      => (int) ($product['stock_qty'] ?? 0),
                        'stock_value'    => (float) ($product['cost_price'] ?? $product['price'] ?? 0) * (int) ($product['stock_qty'] ?? 0),
                        'days_since_sale' => $daysSinceLastSale,
                        'categories'     => $product['categories'] ?? [],
                    ];
                }
            }

            // Sort by stock value descending (prioritize highest capital tied up)
            usort($deadStock, fn($a, $b) => $b['stock_value'] <=> $a['stock_value']);

            return [
                'success' => true,
                'dead_stock_count' => count($deadStock),
                'total_stock_value' => array_sum(array_column($deadStock, 'stock_value')),
                'dead_stock' => array_slice($deadStock, 0, $limit),
                'analysis_period_days' => $daysSinceLastSale,
            ];
        } catch (\Exception $e) {
            Log::error("InventoryService::detectDeadStock error: {$e->getMessage()}");
            return ['success' => false, 'dead_stock_count' => 0, 'dead_stock' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate intelligent bundle suggestions for dead stock items.
     */
    public function suggestBundles(int $tenantId, int $maxBundles = 10): array
    {
        try {
            $deadStock = $this->detectDeadStock($tenantId, 60, 50);
            $deadItems = $deadStock['items'] ?? [];
            if (empty($deadItems)) return ['bundles' => []];

            // Get frequently bought together data
            $frequentPairs = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('created_at', '>=', now()->subDays(180)->toDateTimeString())
                ->get()
                ->pluck('metadata.items')
                ->filter()
                ->map(fn($items) => collect($items)->pluck('product_id')->toArray());

            $bundles = [];
            $deadProductIds = collect($deadItems)->pluck('product_id')->toArray();

            foreach ($deadItems as $deadItem) {
                // Find popular products frequently bought with items in the same category
                $complementary = [];
                foreach ($frequentPairs as $orderItems) {
                    if (!in_array($deadItem['product_id'], $orderItems)) {
                        // Check if category matches
                        foreach ($orderItems as $itemId) {
                            if (!in_array($itemId, $deadProductIds)) {
                                $complementary[] = $itemId;
                            }
                        }
                    }
                }

                $topCompanion = collect($complementary)->countBy()->sortDesc()->keys()->first();
                if ($topCompanion) {
                    $bundles[] = [
                        'dead_stock_product' => $deadItem,
                        'companion_product_id' => $topCompanion,
                        'suggested_discount' => $this->calculateBundleDiscount($deadItem),
                        'bundle_type' => 'dead_stock_clearance',
                    ];
                }

                if (count($bundles) >= $maxBundles) break;
            }

            return ['bundles' => $bundles, 'generated_at' => now()->toIso8601String()];
        } catch (\Exception $e) {
            Log::error("InventoryService::suggestBundles error: {$e->getMessage()}");
            return ['bundles' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Margin analysis — compute profit margins per product/category.
     */
    public function analyzeMargins(int $tenantId, string $groupBy = 'product', int $limit = 50): array
    {
        try {
            $products = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->get();

            $sales = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('created_at', '>=', now()->subDays(90))
                ->get()
                ->map(fn($e) => (array) $e)
                ->pluck('metadata.items')
                ->flatten(1)
                ->map(fn($i) => is_object($i) ? (array) $i : $i);

            $productMap = [];
            foreach ($products as $pRaw) {
                $p = (array) $pRaw;
                $pid = (string) ($p['external_id'] ?? $p['id'] ?? $p['product_id'] ?? '');
                $productMap[$pid] = [
                    'name'       => $p['name'] ?? '',
                    'sku'        => $p['sku'] ?? '',
                    'price'      => (float) ($p['price'] ?? 0),
                    'cost_price' => (float) ($p['cost_price'] ?? 0),
                    'categories' => $p['categories'] ?? [],
                ];
            }

            $marginData = [];
            foreach ($sales as $item) {
                if (!$item) continue;
                $pid = (string) ($item['product_id'] ?? '');
                $product = $productMap[$pid] ?? null;
                if (!$product) continue;

                $revenue = (float) ($item['row_total'] ?? ($item['price'] ?? 0) * ($item['qty'] ?? 1));
                $cost = $product['cost_price'] * (int) ($item['qty'] ?? 1);
                $margin = $revenue > 0 ? (($revenue - $cost) / $revenue) * 100 : 0;

                $key = $groupBy === 'category'
                    ? (($product['categories'][0] ?? 'Uncategorized'))
                    : $pid;

                if (!isset($marginData[$key])) {
                    $marginData[$key] = [
                        'key'       => $key,
                        'name'      => $groupBy === 'category' ? $key : $product['name'],
                        'revenue'   => 0,
                        'cost'      => 0,
                        'units_sold' => 0,
                    ];
                }
                $marginData[$key]['revenue'] += $revenue;
                $marginData[$key]['cost'] += $cost;
                $marginData[$key]['units_sold'] += (int) ($item['qty'] ?? 1);
            }

            // Calculate margin percentages
            foreach ($marginData as &$item) {
                $item['profit'] = $item['revenue'] - $item['cost'];
                $item['margin_percent'] = $item['revenue'] > 0
                    ? round(($item['profit'] / $item['revenue']) * 100, 2)
                    : 0;
            }

            // Sort by profit descending
            usort($marginData, fn($a, $b) => $b['profit'] <=> $a['profit']);

            $totalRevenue = array_sum(array_column($marginData, 'revenue'));
            $totalCost = array_sum(array_column($marginData, 'cost'));

            return [
                'group_by'        => $groupBy,
                'total_revenue'   => round($totalRevenue, 2),
                'total_cost'      => round($totalCost, 2),
                'total_profit'    => round($totalRevenue - $totalCost, 2),
                'overall_margin'  => $totalRevenue > 0 ? round((($totalRevenue - $totalCost) / $totalRevenue) * 100, 2) : 0,
                'items'           => array_slice($marginData, 0, $limit),
                'analysis_period' => '90_days',
            ];
        } catch (\Exception $e) {
            Log::error("InventoryService::analyzeMargins error: {$e->getMessage()}");
            return ['items' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Replenishment prediction — estimate when products need reordering.
     */
    public function predictReplenishment(int $tenantId, int $leadTimeDays = 14, float $safetyStockMultiplier = 1.5): array
    {
        try {
            $products = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('status', 'enabled')
                ->where('stock_qty', '>', 0)
                ->get();

            // Calculate daily consumption rates from sales data
            $salesLast90 = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('created_at', '>=', now()->subDays(90)->toDateTimeString())
                ->get()
                ->pluck('metadata.items')
                ->flatten(1);

            $consumptionRates = [];
            foreach ($salesLast90 as $item) {
                if (!$item) continue;
                $pid = (string) ($item['product_id'] ?? '');
                $consumptionRates[$pid] = ($consumptionRates[$pid] ?? 0) + (int) ($item['qty'] ?? 1);
            }

            // Convert to daily rate (90-day denominator)
            foreach ($consumptionRates as &$total) {
                $total = round($total / 90, 4);
            }

            $alerts = [];
            foreach ($products as $product) {
                $pid = (string) ($product['id'] ?? $product['product_id'] ?? '');
                $stockQty = (int) ($product['stock_qty'] ?? 0);
                $dailyRate = $consumptionRates[$pid] ?? 0;

                if ($dailyRate <= 0) continue; // No sales history, skip

                $daysOfStock = $dailyRate > 0 ? round($stockQty / $dailyRate) : 999;
                $reorderPoint = ceil($dailyRate * $leadTimeDays * $safetyStockMultiplier);
                $suggestedOrderQty = max(1, ceil($dailyRate * 30)); // 30-day supply

                $urgency = 'normal';
                if ($daysOfStock <= $leadTimeDays) {
                    $urgency = 'critical';
                } elseif ($daysOfStock <= $leadTimeDays * 2) {
                    $urgency = 'warning';
                }

                if ($urgency !== 'normal' || $stockQty <= $reorderPoint) {
                    $alerts[] = [
                        'product_id'          => $pid,
                        'name'                => $product['name'] ?? '',
                        'sku'                 => $product['sku'] ?? '',
                        'current_stock'       => $stockQty,
                        'daily_consumption'   => round($dailyRate, 2),
                        'days_of_stock'       => (int) $daysOfStock,
                        'reorder_point'       => (int) $reorderPoint,
                        'suggested_order_qty' => (int) $suggestedOrderQty,
                        'urgency'             => $urgency,
                        'estimated_stockout'  => now()->addDays((int) $daysOfStock)->toDateString(),
                    ];
                }
            }

            usort($alerts, function ($a, $b) {
                $urgencyOrder = ['critical' => 0, 'warning' => 1, 'normal' => 2];
                return ($urgencyOrder[$a['urgency']] ?? 2) <=> ($urgencyOrder[$b['urgency']] ?? 2)
                    ?: $a['days_of_stock'] <=> $b['days_of_stock'];
            });

            return [
                'alerts_count'    => count($alerts),
                'critical_count'  => count(array_filter($alerts, fn($a) => $a['urgency'] === 'critical')),
                'warning_count'   => count(array_filter($alerts, fn($a) => $a['urgency'] === 'warning')),
                'alerts'          => $alerts,
                'lead_time_days'  => $leadTimeDays,
                'safety_multiplier' => $safetyStockMultiplier,
            ];
        } catch (\Exception $e) {
            Log::error("InventoryService::predictReplenishment error: {$e->getMessage()}");
            return ['alerts' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Get product margin data for search ranking (used by AiSearch).
     */
    public function getMarginRanking(int $tenantId, array $productIds): array
    {
        try {
            $products = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $productIds)
                ->get();

            $rankings = [];
            foreach ($products as $p) {
                $pid = (string) ($p['id'] ?? '');
                $price = (float) ($p['price'] ?? 0);
                $cost = (float) ($p['cost_price'] ?? 0);
                $margin = $price > 0 && $cost > 0 ? (($price - $cost) / $price) * 100 : 50;
                $stockQty = (int) ($p['stock_qty'] ?? 0);

                $rankings[$pid] = [
                    'margin_percent' => round($margin, 2),
                    'stock_qty'      => $stockQty,
                    'boost_score'    => $this->calculateBoostScore($margin, $stockQty),
                ];
            }

            return $rankings;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function calculateBundleDiscount(array $deadItem): float
    {
        $daysStale = $deadItem['days_since_sale'] ?? 90;
        // Increase discount as stock ages: 10% base, up to 40%
        return min(40, 10 + ($daysStale / 10));
    }

    private function calculateBoostScore(float $marginPercent, int $stockQty): float
    {
        // Higher margin + adequate stock = higher boost
        $marginScore = min(1, $marginPercent / 50); // normalize to 0-1
        $stockScore = $stockQty > 10 ? 1 : ($stockQty > 0 ? 0.5 : 0);
        return round(($marginScore * 0.7 + $stockScore * 0.3), 4);
    }
}

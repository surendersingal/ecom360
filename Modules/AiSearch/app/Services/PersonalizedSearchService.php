<?php
declare(strict_types=1);

namespace Modules\AiSearch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * PersonalizedSearchService — User-context aware search enhancements.
 *
 * UC3: Personalized Size/Fit Filtering — auto-apply known size
 * UC4: Out-of-Stock Rerouting — suggest alternatives for OOS items
 * UC7: B2B Wholesale Search Gates — role-based filtering & pricing
 * UC8: Trend-Injected Search Ranking — boost trending items from Analytics
 */
class PersonalizedSearchService
{
    private SearchService $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * UC3: Auto-apply known size/fit preferences to search results.
     */
    public function personalizedSizeSearch(int $tenantId, array $params, ?string $customerId = null): array
    {
        $results = $this->searchService->search($tenantId, $params);

        if (!$customerId || empty($results['results'])) {
            return $results;
        }

        // Fetch customer size profile from order history
        $sizeProfile = $this->getCustomerSizeProfile($tenantId, $customerId);

        if (empty($sizeProfile)) {
            return $results;
        }

        // Annotate each result with fit recommendation
        foreach ($results['results'] as &$product) {
            $category = strtolower($product['category'] ?? '');
            $applicableSize = null;

            if (str_contains($category, 'shoe') || str_contains($category, 'footwear')) {
                $applicableSize = $sizeProfile['shoe_size'] ?? null;
                $product['recommended_size'] = $applicableSize;
                $product['size_type'] = 'shoe';
            } elseif (str_contains($category, 'shirt') || str_contains($category, 'top')) {
                $applicableSize = $sizeProfile['top_size'] ?? null;
                $product['recommended_size'] = $applicableSize;
                $product['size_type'] = 'top';
            } elseif (str_contains($category, 'pant') || str_contains($category, 'bottom') || str_contains($category, 'jean')) {
                $applicableSize = $sizeProfile['bottom_size'] ?? null;
                $product['recommended_size'] = $applicableSize;
                $product['size_type'] = 'bottom';
            }

            // Check stock for recommended size
            if ($applicableSize && isset($product['variants'])) {
                $inStock = collect($product['variants'])
                    ->where('size', $applicableSize)
                    ->where('stock_qty', '>', 0)
                    ->isNotEmpty();
                $product['recommended_size_in_stock'] = $inStock;
            }
        }

        // Boost products where recommended size is in stock
        usort($results['results'], function ($a, $b) {
            $aStock = $a['recommended_size_in_stock'] ?? false;
            $bStock = $b['recommended_size_in_stock'] ?? false;
            if ($aStock && !$bStock) return -1;
            if (!$aStock && $bStock) return 1;
            return 0;
        });

        $results['personalization'] = [
            'size_profile'  => $sizeProfile,
            'sizes_applied' => true,
        ];

        return $results;
    }

    /**
     * UC4: Reroute OOS search results to available alternatives.
     */
    public function outOfStockReroute(int $tenantId, array $params): array
    {
        $results = $this->searchService->search($tenantId, $params);

        if (empty($results['results'])) {
            return $results;
        }

        $oosProducts = [];
        $inStockResults = [];
        $alternatives = [];

        foreach ($results['results'] as $product) {
            $isOos = ($product['stock_qty'] ?? 0) <= 0 || ($product['status'] ?? '') === 'out_of_stock';

            if ($isOos) {
                $oosProducts[] = $product;
                // Find alternatives by category + price range
                $altResults = $this->findAlternatives($tenantId, $product);
                if (!empty($altResults)) {
                    $alternatives[] = [
                        'original_product' => [
                            'id'   => $product['id'] ?? $product['external_id'] ?? null,
                            'name' => $product['name'] ?? '',
                        ],
                        'alternatives' => $altResults,
                        'reason'       => 'Currently out of stock',
                    ];
                }
            } else {
                $inStockResults[] = $product;
            }
        }

        // Inject alternatives into results
        $results['results'] = $inStockResults;
        $results['oos_alternatives'] = $alternatives;
        $results['oos_count'] = count($oosProducts);
        $results['rerouted'] = count($alternatives) > 0;

        return $results;
    }

    /**
     * UC7: B2B search with wholesale gates, pricing tiers, and min-qty.
     */
    public function b2bSearch(int $tenantId, array $params, ?array $customerContext = null): array
    {
        $role = $customerContext['role'] ?? 'retail';
        $tier = $customerContext['pricing_tier'] ?? 'default';
        $company = $customerContext['company'] ?? null;

        // Gate: only show B2B-eligible products for wholesale customers
        if (in_array($role, ['wholesale', 'distributor', 'dealer'])) {
            $params['filters'] = array_merge($params['filters'] ?? [], [
                'b2b_eligible' => true,
            ]);
        }

        $results = $this->searchService->search($tenantId, $params);

        if (empty($results['results'])) {
            return $results;
        }

        // Apply tier pricing
        $tierPricing = $this->getTierPricing($tenantId, $tier);

        foreach ($results['results'] as &$product) {
            $originalPrice = $product['price'] ?? 0;

            if ($role === 'wholesale' || $role === 'distributor') {
                $discount = $tierPricing[$product['category'] ?? 'default'] ?? ($tierPricing['default'] ?? 0);
                $product['wholesale_price'] = round($originalPrice * (1 - $discount / 100), 2);
                $product['discount_pct'] = $discount;
                $product['min_order_qty'] = $product['moq'] ?? $this->getDefaultMOQ($product['category'] ?? '');
                $product['case_pack_size'] = $product['case_pack'] ?? null;
                $product['tiered_pricing'] = $this->buildTieredPricing($originalPrice, $product['category'] ?? '');
            }

            // Hide competitive info from non-B2B users
            if ($role === 'retail') {
                unset($product['wholesale_price'], $product['margin'], $product['cost']);
            }
        }

        $results['b2b_context'] = [
            'role'         => $role,
            'pricing_tier' => $tier,
            'company'      => $company,
            'is_b2b'       => in_array($role, ['wholesale', 'distributor', 'dealer']),
        ];

        return $results;
    }

    /**
     * UC8: Boost trending items in search results using Analytics signals.
     */
    public function trendInjectedSearch(int $tenantId, array $params): array
    {
        $results = $this->searchService->search($tenantId, $params);

        if (empty($results['results'])) {
            return $results;
        }

        // Get trending products from Analytics module
        $trendingIds = $this->getTrendingProductIds($tenantId);
        $viralIds = $this->getViralProductIds($tenantId);
        $socialBuzz = $this->getSocialBuzzProducts($tenantId);

        foreach ($results['results'] as &$product) {
            $pid = $product['id'] ?? $product['external_id'] ?? '';
            $trendScore = 0;
            $badges = [];

            if (in_array($pid, $trendingIds)) {
                $trendScore += 30;
                $badges[] = 'trending';
            }
            if (in_array($pid, $viralIds)) {
                $trendScore += 50;
                $badges[] = 'viral';
            }
            if (isset($socialBuzz[$pid])) {
                $trendScore += $socialBuzz[$pid] * 0.1;
                if ($socialBuzz[$pid] > 100) $badges[] = 'social_hit';
            }

            // Velocity boost: recent conversion spike
            if (isset($product['conversion_velocity']) && $product['conversion_velocity'] > 2.0) {
                $trendScore += 20;
                $badges[] = 'fast_mover';
            }

            $product['trend_score'] = $trendScore;
            $product['trend_badges'] = $badges;
        }

        // Re-rank: blend relevance with trend score
        usort($results['results'], function ($a, $b) {
            $scoreA = ($a['relevance_score'] ?? 0) * 0.7 + ($a['trend_score'] ?? 0) * 0.3;
            $scoreB = ($b['relevance_score'] ?? 0) * 0.7 + ($b['trend_score'] ?? 0) * 0.3;
            return $scoreB <=> $scoreA;
        });

        $results['trend_injection'] = [
            'trending_count' => count(array_filter($results['results'], fn($r) => ($r['trend_score'] ?? 0) > 0)),
            'active' => true,
        ];

        return $results;
    }

    // ── Private Helpers ──────────────────────────────────────────

    private function getCustomerSizeProfile(int $tenantId, string $customerId): array
    {
        $cacheKey = "size_profile:{$tenantId}:{$customerId}";
        return Cache::remember($cacheKey, 3600, function () use ($tenantId, $customerId) {
            $orders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get();

            $sizes = ['shoe_size' => [], 'top_size' => [], 'bottom_size' => []];
            foreach ($orders as $order) {
                foreach ($order['items'] ?? [] as $item) {
                    $cat = strtolower($item['category'] ?? '');
                    $size = $item['size'] ?? $item['selected_size'] ?? null;
                    if (!$size) continue;

                    if (str_contains($cat, 'shoe') || str_contains($cat, 'footwear')) {
                        $sizes['shoe_size'][] = $size;
                    } elseif (str_contains($cat, 'shirt') || str_contains($cat, 'top')) {
                        $sizes['top_size'][] = $size;
                    } elseif (str_contains($cat, 'pant') || str_contains($cat, 'jean')) {
                        $sizes['bottom_size'][] = $size;
                    }
                }
            }

            $profile = [];
            foreach ($sizes as $key => $values) {
                if (!empty($values)) {
                    $counts = array_count_values($values);
                    arsort($counts);
                    $profile[$key] = array_key_first($counts);
                }
            }
            return $profile;
        });
    }

    private function findAlternatives(int $tenantId, array $product): array
    {
        try {
            $category = $product['category'] ?? '';
            $price = $product['price'] ?? 0;

            if (!$category) return [];

            return DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('category', $category)
                ->where('stock_qty', '>', 0)
                ->where('price', '>=', $price * 0.7)
                ->where('price', '<=', $price * 1.3)
                ->limit(3)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getTierPricing(int $tenantId, string $tier): array
    {
        $defaults = [
            'gold'     => ['default' => 20, 'electronics' => 15, 'fashion' => 25],
            'silver'   => ['default' => 15, 'electronics' => 10, 'fashion' => 18],
            'bronze'   => ['default' => 10, 'electronics' => 7, 'fashion' => 12],
            'default'  => ['default' => 0],
        ];
        return $defaults[$tier] ?? $defaults['default'];
    }

    private function getDefaultMOQ(string $category): int
    {
        $moqMap = ['electronics' => 5, 'fashion' => 12, 'groceries' => 24, 'beauty' => 6];
        return $moqMap[strtolower($category)] ?? 10;
    }

    private function buildTieredPricing(float $basePrice, string $category): array
    {
        return [
            ['min_qty' => 1, 'max_qty' => 11, 'price' => $basePrice],
            ['min_qty' => 12, 'max_qty' => 49, 'price' => round($basePrice * 0.9, 2)],
            ['min_qty' => 50, 'max_qty' => 199, 'price' => round($basePrice * 0.82, 2)],
            ['min_qty' => 200, 'max_qty' => null, 'price' => round($basePrice * 0.75, 2)],
        ];
    }

    private function getTrendingProductIds(int $tenantId): array
    {
        return Cache::remember("trending_pids:{$tenantId}", 600, function () use ($tenantId) {
            try {
                return DB::connection('mongodb')
                    ->table('events')
                    ->where('tenant_id', $tenantId)
                    ->where('event', 'product_view')
                    ->where('created_at', '>=', now()->subHours(24)->toDateTimeString())
                    ->groupBy('product_id')
                    ->orderByRaw(['count' => -1])
                    ->limit(50)
                    ->pluck('product_id')
                    ->toArray();
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    private function getViralProductIds(int $tenantId): array
    {
        return Cache::remember("viral_pids:{$tenantId}", 600, function () use ($tenantId) {
            try {
                return DB::connection('mongodb')
                    ->table('events')
                    ->where('tenant_id', $tenantId)
                    ->where('event', 'share')
                    ->where('created_at', '>=', now()->subHours(48)->toDateTimeString())
                    ->groupBy('product_id')
                    ->orderByRaw(['count' => -1])
                    ->limit(20)
                    ->pluck('product_id')
                    ->toArray();
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    private function getSocialBuzzProducts(int $tenantId): array
    {
        return Cache::remember("social_buzz:{$tenantId}", 900, function () use ($tenantId) {
            try {
                $events = DB::connection('mongodb')
                    ->table('events')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('event', ['share', 'wishlist_add', 'review_submit'])
                    ->where('created_at', '>=', now()->subHours(72)->toDateTimeString())
                    ->get();

                $buzz = [];
                foreach ($events as $e) {
                    $pid = $e['product_id'] ?? null;
                    if ($pid) {
                        $buzz[$pid] = ($buzz[$pid] ?? 0) + 1;
                    }
                }
                return $buzz;
            } catch (\Exception $e) {
                return [];
            }
        });
    }
}

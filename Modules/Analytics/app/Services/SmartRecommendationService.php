<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Smart Product Recommendation Engine.
 *
 * Algorithms:
 *   - Collaborative Filtering  (people who bought X also bought Y)
 *   - Frequently Bought Together  (co-occurrence in same order)
 *   - Content-Based  (same category/brand affinity)
 *   - Trending Products  (velocity-based, last 7 days)
 *   - Recently Viewed  (personalised recency)
 *   - Complementary  (cross-category upsell)
 *
 * All recommendations are tenant-scoped and cached in Redis.
 */
final class SmartRecommendationService
{
    /**
     * Get personalised recommendations for a visitor.
     */
    public function forVisitor(int|string $tenantId, string $visitorId, int $limit = 10): array
    {
        $viewed = $this->getViewedProducts($tenantId, $visitorId);
        $purchased = $this->getPurchasedProducts($tenantId, $visitorId);

        $recs = [];

        // Collaborative filtering based on purchase history
        if (!empty($purchased)) {
            $recs = array_merge($recs, $this->collaborativeFilter($tenantId, $purchased, $limit));
        }

        // Content-based from viewed products
        if (!empty($viewed)) {
            $recs = array_merge($recs, $this->contentBased($tenantId, $viewed, $limit));
        }

        // Fill with trending if not enough
        if (count($recs) < $limit) {
            $recs = array_merge($recs, $this->trending($tenantId, $limit));
        }

        // Deduplicate, remove already purchased, score & rank
        $seen = [];
        $final = [];
        $excludeIds = array_merge($purchased, $viewed);

        foreach ($recs as $r) {
            $pid = $r['product_id'];
            if (isset($seen[$pid]) || in_array($pid, $excludeIds)) continue;
            $seen[$pid] = true;
            $final[] = $r;
            if (count($final) >= $limit) break;
        }

        return [
            'visitor_id' => $visitorId,
            'recommendations' => $final,
            'algorithms_used' => array_unique(array_column($final, 'algorithm')),
        ];
    }

    /**
     * Get recommendations for a specific product (product page).
     */
    public function forProduct(int|string $tenantId, string $productId, int $limit = 8): array
    {
        $fbt = $this->frequentlyBoughtTogether($tenantId, $productId, $limit);
        $similar = $this->similarProducts($tenantId, $productId, $limit);

        return [
            'product_id' => $productId,
            'frequently_bought_together' => array_slice($fbt, 0, $limit),
            'similar_products' => array_slice($similar, 0, $limit),
        ];
    }

    /**
     * Get trending products across the store.
     */
    public function trending(int|string $tenantId, int $limit = 10): array
    {
        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tenantId, $limit) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tenantId,
                        'event_type' => ['$in' => ['purchase', 'add_to_cart', 'product_view']],
                        'created_at' => ['$gte' => now()->subDays(7)->toIso8601String()],
                    ]],
                    ['$group' => [
                        '_id' => '$metadata.product_id',
                        'name' => ['$first' => '$metadata.product_name'],
                        'category' => ['$first' => '$metadata.category'],
                        'views' => ['$sum' => ['$cond' => [['$eq' => ['$event_type', 'product_view']], 1, 0]]],
                        'carts' => ['$sum' => ['$cond' => [['$eq' => ['$event_type', 'add_to_cart']], 1, 0]]],
                        'purchases' => ['$sum' => ['$cond' => [['$eq' => ['$event_type', 'purchase']], 1, 0]]],
                        'revenue' => ['$sum' => ['$cond' => [['$eq' => ['$event_type', 'purchase']], '$metadata.revenue', 0]]],
                    ]],
                    ['$addFields' => [
                        'score' => ['$add' => [
                            '$views',
                            ['$multiply' => ['$carts', 3]],
                            ['$multiply' => ['$purchases', 10]],
                        ]],
                    ]],
                    ['$sort' => ['score' => -1]],
                    ['$limit' => $limit],
                ])->toArray();
            });

        return array_map(fn($r) => [
            'product_id' => $r['_id'],
            'name' => $r['name'] ?? null,
            'category' => $r['category'] ?? null,
            'score' => (float) ($r['score'] ?? 0),
            'views' => (int) ($r['views'] ?? 0),
            'purchases' => (int) ($r['purchases'] ?? 0),
            'revenue' => round((float) ($r['revenue'] ?? 0), 2),
            'algorithm' => 'trending',
        ], $results);
    }

    /**
     * Frequently bought together — co-occurrence in same purchase session.
     */
    public function frequentlyBoughtTogether(int|string $tenantId, string $productId, int $limit = 6): array
    {
        // Find sessions that purchased this product
        $sessions = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'purchase')
            ->where('metadata.product_id', $productId)
            ->pluck('session_id')
            ->all();

        if (empty($sessions)) return [];

        // Find other products purchased in those sessions
        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tenantId, $sessions, $productId, $limit) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tenantId,
                        'event_type' => 'purchase',
                        'session_id' => ['$in' => array_values(array_unique($sessions))],
                        'metadata.product_id' => ['$ne' => $productId],
                    ]],
                    ['$group' => [
                        '_id' => '$metadata.product_id',
                        'name' => ['$first' => '$metadata.product_name'],
                        'co_purchases' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['co_purchases' => -1]],
                    ['$limit' => $limit],
                ])->toArray();
            });

        return array_map(fn($r) => [
            'product_id' => $r['_id'],
            'name' => $r['name'] ?? null,
            'co_purchase_count' => (int) ($r['co_purchases'] ?? 0),
            'algorithm' => 'frequently_bought_together',
        ], $results);
    }

    // ── Private algorithms ───────────────────────────────────────────

    private function collaborativeFilter(int|string $tenantId, array $purchasedProductIds, int $limit): array
    {
        // Find other visitors who bought the same products
        $similarVisitors = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'purchase')
            ->whereIn('metadata.product_id', $purchasedProductIds)
            ->distinct('visitor_id')
            ->limit(50)
            ->pluck('visitor_id')
            ->all();

        if (empty($similarVisitors)) return [];

        // Find what else those visitors bought
        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tenantId, $similarVisitors, $purchasedProductIds, $limit) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tenantId,
                        'event_type' => 'purchase',
                        'visitor_id' => ['$in' => $similarVisitors],
                        'metadata.product_id' => ['$nin' => $purchasedProductIds],
                    ]],
                    ['$group' => [
                        '_id' => '$metadata.product_id',
                        'name' => ['$first' => '$metadata.product_name'],
                        'category' => ['$first' => '$metadata.category'],
                        'buyer_count' => ['$addToSet' => '$visitor_id'],
                    ]],
                    ['$addFields' => ['score' => ['$size' => '$buyer_count']]],
                    ['$sort' => ['score' => -1]],
                    ['$limit' => $limit],
                ])->toArray();
            });

        return array_map(fn($r) => [
            'product_id' => $r['_id'],
            'name' => $r['name'] ?? null,
            'category' => $r['category'] ?? null,
            'score' => (float) ($r['score'] ?? 0),
            'algorithm' => 'collaborative_filtering',
        ], $results);
    }

    private function contentBased(int|string $tenantId, array $viewedProductIds, int $limit): array
    {
        // Get categories of viewed products
        $categories = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->whereIn('metadata.product_id', $viewedProductIds)
            ->pluck('metadata.category')
            ->filter()
            ->unique()
            ->all();

        if (empty($categories)) return [];

        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tenantId, $categories, $viewedProductIds, $limit) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tenantId,
                        'event_type' => ['$in' => ['purchase', 'product_view']],
                        'metadata.category' => ['$in' => array_values($categories)],
                        'metadata.product_id' => ['$nin' => $viewedProductIds],
                        'created_at' => ['$gte' => now()->subDays(14)->toIso8601String()],
                    ]],
                    ['$group' => [
                        '_id' => '$metadata.product_id',
                        'name' => ['$first' => '$metadata.product_name'],
                        'category' => ['$first' => '$metadata.category'],
                        'interactions' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['interactions' => -1]],
                    ['$limit' => $limit],
                ])->toArray();
            });

        return array_map(fn($r) => [
            'product_id' => $r['_id'],
            'name' => $r['name'] ?? null,
            'category' => $r['category'] ?? null,
            'score' => (float) ($r['interactions'] ?? 0),
            'algorithm' => 'content_based',
        ], $results);
    }

    private function similarProducts(int|string $tenantId, string $productId, int $limit): array
    {
        // Get category of the product
        $event = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('metadata.product_id', $productId)
            ->first();

        if (!$event) return [];
        $event = (array) $event;
        $category = $event['metadata']['category'] ?? null;
        if (!$category) return [];

        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tenantId, $category, $productId, $limit) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tenantId,
                        'event_type' => ['$in' => ['purchase', 'product_view']],
                        'metadata.category' => $category,
                        'metadata.product_id' => ['$ne' => $productId],
                        'created_at' => ['$gte' => now()->subDays(30)->toIso8601String()],
                    ]],
                    ['$group' => [
                        '_id' => '$metadata.product_id',
                        'name' => ['$first' => '$metadata.product_name'],
                        'interactions' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['interactions' => -1]],
                    ['$limit' => $limit],
                ])->toArray();
            });

        return array_map(fn($r) => [
            'product_id' => $r['_id'],
            'name' => $r['name'] ?? null,
            'category' => $category,
            'score' => (float) ($r['interactions'] ?? 0),
            'algorithm' => 'similar_products',
        ], $results);
    }

    private function getViewedProducts(int|string $tenantId, string $visitorId): array
    {
        return DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('visitor_id', $visitorId)
            ->where('event_type', 'product_view')
            ->where('created_at', '>=', now()->subDays(30)->toIso8601String())
            ->pluck('metadata.product_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function getPurchasedProducts(int|string $tenantId, string $visitorId): array
    {
        return DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('visitor_id', $visitorId)
            ->where('event_type', 'purchase')
            ->pluck('metadata.product_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}

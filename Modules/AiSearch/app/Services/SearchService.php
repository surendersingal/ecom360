<?php
declare(strict_types=1);

namespace Modules\AiSearch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Modules\AiSearch\Models\SearchLog;

/**
 * SearchService — AI-powered product search with semantic understanding.
 *
 * Powers Use Cases:
 *   - Margin-Optimized Search Ranking (UC17)
 *   - Multi-Language Smart Segmentation (UC18)
 *   - Product discovery + auto-suggestions
 */
class SearchService
{
    private RelevanceService $relevanceService;

    public function __construct(RelevanceService $relevanceService)
    {
        $this->relevanceService = $relevanceService;
    }

    /**
     * Search products with AI-powered ranking.
     */
    public function search(string|int $tenantId, array $params): array
    {
        $startTime = microtime(true);
        $query = $params['query'] ?? '';
        $filters = $params['filters'] ?? [];
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));
        $sortBy = $params['sort_by'] ?? 'relevance';
        $language = $params['language'] ?? 'en';
        $tenantId = (string) $tenantId; // MongoDB stores tenant_id as string

        try {
            // Expand query with synonyms and NLP
            $expandedQuery = $this->expandQuery($query, $language);

            // Build MongoDB search — focus on name + sku for precision
            $dbQuery = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId);

            // Text search: name + sku only (not description) for better precision
            if ($query) {
                $significantTerms = $this->getSignificantTerms($expandedQuery);

                $dbQuery->where(function ($q) use ($query, $significantTerms) {
                    // Full phrase match on name
                    $escapedPhrase = preg_quote($query, '/');
                    $q->where('name', 'regex', "/{$escapedPhrase}/i");

                    // All significant terms in name (AND logic)
                    if (count($significantTerms) > 1) {
                        $q->orWhere(function ($inner) use ($significantTerms) {
                            foreach ($significantTerms as $term) {
                                $inner->where('name', 'regex', '/' . preg_quote($term, '/') . '/i');
                            }
                        });
                    }

                    // Individual significant terms in name or sku
                    foreach ($significantTerms as $term) {
                        $escaped = preg_quote($term, '/');
                        $q->orWhere('name', 'regex', "/{$escaped}/i")
                          ->orWhere('sku', 'regex', "/{$escaped}/i");
                    }
                });
            }

            // Apply filters
            $this->applyFilters($dbQuery, $filters);

            // Get raw results
            $rawResults = $dbQuery->limit(200)->get();

            // Score and rank results
            $scored = $this->relevanceService->scoreResults($tenantId, $rawResults, $query, $sortBy);

            // Paginate
            $total = count($scored);
            $results = array_slice($scored, ($page - 1) * $perPage, $perPage);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Log search
            $this->logSearch($tenantId, $params, count($results), $responseTimeMs);

            // Generate suggestions if no results
            $suggestions = $total === 0 ? $this->getSuggestions($tenantId, $query) : [];

            return [
                'success'       => true,
                'query'         => $query,
                'results'       => $results,
                'total'         => $total,
                'page'          => $page,
                'per_page'      => $perPage,
                'has_more'      => ($page * $perPage) < $total,
                'suggestions'   => $suggestions,
                'facets'        => $this->buildFacets($rawResults),
                'response_time_ms' => $responseTimeMs,
            ];
        } catch (\Exception $e) {
            Log::error("SearchService::search error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage(), 'results' => []];
        }
    }

    /**
     * Get auto-complete suggestions as user types.
     */
    public function suggest(string|int $tenantId, string $prefix, int $limit = 8): array
    {
        $tenantId = (string) $tenantId; // MongoDB stores tenant_id as string
        $cacheKey = "search_suggest:{$tenantId}:" . md5($prefix);

        return Cache::remember($cacheKey, 300, function () use ($tenantId, $prefix, $limit) {
            try {
                $escaped = preg_quote($prefix, '/');

                // Product name suggestions
                $products = DB::connection('mongodb')
                    ->table('synced_products')
                    ->where('tenant_id', $tenantId)
                    ->where('name', 'regex', "/^{$escaped}/i")
                    ->limit($limit)
                    ->get(['name', 'external_id', 'price', 'image']);

                // Category suggestions
                $categories = DB::connection('mongodb')
                    ->table('synced_categories')
                    ->where('tenant_id', $tenantId)
                    ->where('name', 'regex', "/^{$escaped}/i")
                    ->limit(3)
                    ->get(['name', 'external_id']);

                // Popular search terms
                $popularTerms = SearchLog::where('tenant_id', $tenantId)
                    ->where('query', 'regex', "/^{$escaped}/i")
                    ->where('created_at', '>=', now()->subDays(30)->toDateTimeString())
                    ->raw(function ($collection) use ($tenantId, $escaped) {
                        return $collection->aggregate([
                            ['$match' => [
                                'tenant_id' => $tenantId,
                                'query' => ['$regex' => "^{$escaped}", '$options' => 'i'],
                            ]],
                            ['$group' => ['_id' => '$query', 'count' => ['$sum' => 1]]],
                            ['$sort' => ['count' => -1]],
                            ['$limit' => 5],
                        ]);
                    });

                return [
                    'products'   => $products->map(fn($p) => [
                        'name'  => is_object($p) ? ($p->name ?? '') : ($p['name'] ?? ''),
                        'id'    => is_object($p) ? ($p->external_id ?? null) : ($p['external_id'] ?? null),
                        'price' => is_object($p) ? ($p->price ?? null) : ($p['price'] ?? null),
                        'image' => is_object($p) ? ($p->image ?? null) : ($p['image'] ?? null),
                    ])->values()->toArray(),
                    'categories' => $categories->map(fn($c) => [
                        'name' => is_object($c) ? ($c->name ?? '') : ($c['name'] ?? ''),
                        'id'   => is_object($c) ? ($c->external_id ?? null) : ($c['external_id'] ?? null),
                    ])->values()->toArray(),
                    'popular'    => collect($popularTerms)->map(fn($t) => [
                        'term'  => is_object($t) ? ($t->_id ?? '') : ($t['_id'] ?? ''),
                        'count' => is_object($t) ? ($t->count ?? 0) : ($t['count'] ?? 0),
                    ])->values()->toArray(),
                ];
            } catch (\Exception $e) {
                Log::error("SearchService::suggest error: {$e->getMessage()}");
                return ['products' => [], 'categories' => [], 'popular' => []];
            }
        });
    }

    /**
     * Get trending searches for the tenant.
     */
    public function getTrending(string|int $tenantId, int $limit = 10): array
    {
        $tenantId = (string) $tenantId; // MongoDB stores tenant_id as string
        try {
            $trending = SearchLog::where('tenant_id', $tenantId)
                ->where('query_type', 'text')
                ->where('created_at', '>=', now()->subDays(7)->toDateTimeString())
                ->raw(function ($collection) use ($tenantId, $limit) {
                    return $collection->aggregate([
                        ['$match' => [
                            'tenant_id' => $tenantId,
                            'query_type' => 'text',
                        ]],
                        ['$group' => [
                            '_id' => '$query',
                            'count' => ['$sum' => 1],
                            'avg_results' => ['$avg' => '$results_count'],
                        ]],
                        ['$sort' => ['count' => -1]],
                        ['$limit' => $limit],
                    ]);
                });

            return [
                'trending' => collect($trending)->map(fn($t) => [
                    'query'       => $t->_id ?? ($t['_id'] ?? ''),
                    'search_count' => $t->count ?? ($t['count'] ?? 0),
                    'avg_results' => round($t->avg_results ?? ($t['avg_results'] ?? 0)),
                ])->values()->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error("SearchService::getTrending error: {$e->getMessage()}");
            return ['trending' => []];
        }
    }

    /**
     * Get search analytics.
     */
    public function getAnalytics(string|int $tenantId, int $days = 30): array
    {
        $tenantId = (string) $tenantId; // MongoDB stores tenant_id as string
        try {
            $since = now()->subDays($days)->toDateTimeString();
            $logs = SearchLog::where('tenant_id', $tenantId)
                ->where('created_at', '>=', $since)
                ->get();

            $total = $logs->count();
            $withClicks = $logs->whereNotNull('clicked_product_id')->count();
            $converted = $logs->where('converted', true)->count();
            $zeroResults = $logs->where('results_count', 0)->count();

            $topQueries = $logs->groupBy('query')
                ->map(fn($group, $query) => [
                    'query' => $query,
                    'count' => $group->count(),
                    'ctr'   => $group->whereNotNull('clicked_product_id')->count() / max(1, $group->count()) * 100,
                ])
                ->sortByDesc('count')
                ->values()
                ->take(20);

            $zeroResultQueries = $logs->where('results_count', 0)
                ->groupBy('query')
                ->map(fn($group, $query) => ['query' => $query, 'count' => $group->count()])
                ->sortByDesc('count')
                ->values()
                ->take(20);

            return [
                'period_days'        => $days,
                'total_searches'     => $total,
                'click_through_rate' => $total > 0 ? round(($withClicks / $total) * 100, 2) : 0,
                'conversion_rate'    => $total > 0 ? round(($converted / $total) * 100, 2) : 0,
                'zero_result_rate'   => $total > 0 ? round(($zeroResults / $total) * 100, 2) : 0,
                'avg_response_time'  => (int) $logs->avg('response_time_ms'),
                'top_queries'        => $topQueries->toArray(),
                'zero_result_queries' => $zeroResultQueries->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error("SearchService::getAnalytics error: {$e->getMessage()}");
            return ['error' => $e->getMessage()];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function expandQuery(string $query, string $language): array
    {
        $terms = array_filter(explode(' ', trim($query)));

        // Domain-aware synonym expansion (duty-free / liquor store)
        $synonyms = [
            'whisky'    => ['whiskey'],
            'whiskey'   => ['whisky'],
            'scotch'    => ['whisky', 'whiskey'],
            'vodka'     => ['spirit'],
            'rum'       => ['spirit'],
            'gin'       => ['spirit'],
            'brandy'    => ['cognac'],
            'cognac'    => ['brandy'],
            'perfume'   => ['fragrance', 'cologne', 'eau de'],
            'fragrance' => ['perfume', 'cologne'],
            'cologne'   => ['perfume', 'fragrance'],
            'chocolate' => ['confectionery'],
            'wine'      => ['champagne', 'sparkling'],
        ];

        $expanded = $terms;
        foreach ($terms as $term) {
            $lower = strtolower($term);
            if (isset($synonyms[$lower])) {
                $expanded = array_merge($expanded, $synonyms[$lower]);
            }
        }

        return array_unique($expanded);
    }

    /**
     * Filter out stop words and very short terms to improve search precision.
     */
    private function getSignificantTerms(array $terms): array
    {
        $stopWords = [
            'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for',
            'is', 'it', 'by', 'with', 'from', 'as', 'be', 'was', 'are', 'been',
            'yo', 'ml', 'cl', 'ltr', 'pack', 'set', 'gift', 'box',
            'year', 'years', 'old',
        ];

        return array_values(array_filter($terms, function ($t) use ($stopWords) {
            $lower = strtolower($t);
            return strlen($t) > 1 && !in_array($lower, $stopWords);
        }));
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['category'])) {
            // categories is an array field in MongoDB
            $query->where('categories', $filters['category']);
        }
        if (!empty($filters['min_price'])) {
            $query->where('price', '>=', (float) $filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $query->where('price', '<=', (float) $filters['max_price']);
        }
        if (isset($filters['in_stock'])) {
            $query->where('stock_qty', '>', 0);
        }
        if (!empty($filters['brand'])) {
            $query->where('brand', $filters['brand']);
        }
        if (!empty($filters['color'])) {
            $query->where('attributes.color', $filters['color']);
        }
        if (!empty($filters['size'])) {
            $query->where('attributes.size', $filters['size']);
        }
    }

    private function buildFacets($results): array
    {
        // Categories — flatten the categories array field
        $categories = $results->flatMap(function ($p) {
            $p = $p instanceof \stdClass ? (array) $p : $p;
            $cats = $p['categories'] ?? [];
            if ($cats instanceof \MongoDB\Model\BSONArray) {
                $cats = (array) $cats;
            }
            return is_array($cats) ? $cats : [$cats];
        })->filter()->countBy()->sortDesc()->take(10);

        $categoryFacets = $categories->map(function ($count, $name) {
            return ['label' => $name, 'value' => $name, 'count' => $count];
        })->values()->toArray();

        // Dynamic price ranges based on actual result prices (INR)
        $prices = $results->map(function ($p) {
            $p = $p instanceof \stdClass ? (array) $p : $p;
            return (float) ($p['price'] ?? 0);
        })->filter(fn($p) => $p > 0)->sort()->values();

        $priceRanges = $this->buildDynamicPriceRanges($prices);

        return [
            'categories'   => $categoryFacets,
            'price_ranges' => $priceRanges,
        ];
    }

    /**
     * Build dynamic INR price ranges based on actual result price distribution.
     */
    private function buildDynamicPriceRanges($prices): array
    {
        if ($prices->isEmpty()) return [];

        $min = $prices->min();
        $max = $prices->max();

        // INR-appropriate break points
        $breakPoints = [500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000];

        // Keep break points that fall within the price range
        $relevantBreaks = array_values(array_filter($breakPoints, fn($bp) => $bp > $min && $bp < $max));

        // Limit to 5 ranges max
        if (count($relevantBreaks) > 4) {
            // Pick evenly spaced break points
            $step = max(1, (int) ceil(count($relevantBreaks) / 4));
            $filtered = [];
            for ($i = 0; $i < count($relevantBreaks) && count($filtered) < 4; $i += $step) {
                $filtered[] = $relevantBreaks[$i];
            }
            $relevantBreaks = $filtered;
        }

        $ranges = [];
        $prev = 0;

        foreach ($relevantBreaks as $bp) {
            $count = $prices->filter(fn($p) => $p >= $prev && $p < $bp)->count();
            if ($count > 0) {
                $label = $prev === 0
                    ? 'Under ₹' . number_format($bp)
                    : '₹' . number_format($prev) . ' - ₹' . number_format($bp);
                $ranges[] = [
                    'label' => $label,
                    'value' => $prev . '-' . $bp,
                    'min'   => $prev,
                    'max'   => $bp,
                    'count' => $count,
                ];
            }
            $prev = $bp;
        }

        // Last range
        $count = $prices->filter(fn($p) => $p >= $prev)->count();
        if ($count > 0) {
            $ranges[] = [
                'label' => 'Over ₹' . number_format($prev),
                'value' => $prev . '-',
                'min'   => $prev,
                'max'   => null,
                'count' => $count,
            ];
        }

        return $ranges;
    }

    private function getSuggestions(string|int $tenantId, string $query): array
    {
        // Find similar queries that had results
        $suggestions = [];
        $words = explode(' ', $query);

        foreach ($words as $word) {
            if (strlen($word) < 3) continue;
            $escaped = preg_quote(substr($word, 0, 3), '/');
            $similar = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('name', 'regex', "/{$escaped}/i")
                ->limit(3)
                ->pluck('name');

            foreach ($similar as $name) {
                $suggestions[] = $name;
            }
        }

        return array_unique(array_slice($suggestions, 0, 5));
    }

    private function logSearch(string|int $tenantId, array $params, int $resultsCount, int $responseTimeMs): void
    {
        try {
            SearchLog::create([
                'tenant_id'       => $tenantId,
                'session_id'      => $params['session_id'] ?? null,
                'visitor_id'      => $params['visitor_id'] ?? null,
                'customer_email'  => $params['email'] ?? null,
                'query'           => $params['query'] ?? '',
                'query_type'      => $params['query_type'] ?? 'text',
                'results_count'   => $resultsCount,
                'language'        => $params['language'] ?? 'en',
                'filters_applied' => $params['filters'] ?? [],
                'response_time_ms' => $responseTimeMs,
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to log search: {$e->getMessage()}");
        }
    }
}

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
 *
 * All configurable values are read from TenantSettings (module=aisearch).
 */
class SearchService
{
    private RelevanceService $relevanceService;

    public function __construct(RelevanceService $relevanceService)
    {
        $this->relevanceService = $relevanceService;
    }

    /**
     * Load all aisearch settings for a tenant (cached 1 hour).
     */
    private function loadSettings(string $tenantId): array
    {
        return Cache::remember("tenant_settings:{$tenantId}:aisearch", 3600, function () use ($tenantId) {
            return \App\Models\TenantSetting::where('tenant_id', (int) $tenantId)
                ->where('module', 'aisearch')
                ->pluck('value', 'key')
                ->toArray();
        });
    }

    private function setting(array $settings, string $key, mixed $default = null): mixed
    {
        return $settings[$key] ?? $default;
    }

    /**
     * Return widget-facing configuration flags for the storefront.
     * Mirrors the pattern used by Chatbot's getWidgetConfig().
     */
    public function getWidgetConfig(string $tenantId): array
    {
        $settings = $this->loadSettings($tenantId);

        return [
            'visual_search_enabled'  => (bool) $this->setting($settings, 'visual_search_enabled', false),
            'voice_search_enabled'   => (bool) $this->setting($settings, 'voice_search_enabled', false),
            'suggest_enabled'        => (bool) $this->setting($settings, 'autocomplete_enabled', true),
            'trending_enabled'       => (bool) $this->setting($settings, 'trending_enabled', true),
            'widget_color'           => $this->setting($settings, 'search_widget_color', null),
            'widget_placeholder'     => $this->setting($settings, 'search_placeholder_text', 'Search products, brands, categories...'),
            'widget_show_images'     => (bool) $this->setting($settings, 'suggest_show_images', true),
            'widget_show_prices'     => (bool) $this->setting($settings, 'suggest_show_prices', true),
            'widget_show_brands'     => (bool) $this->setting($settings, 'show_brand_in_results', true),
            'widget_keyboard_shortcut' => (bool) $this->setting($settings, 'search_keyboard_shortcut', true),
            'comparison_enabled'     => (bool) $this->setting($settings, 'comparison_enabled', false),
        ];
    }

    /**
     * Search products with AI-powered ranking.
     */
    public function search(string|int $tenantId, array $params): array
    {
        $startTime = microtime(true);
        $tenantId = (string) $tenantId;
        $settings = $this->loadSettings($tenantId);

        $query = strip_tags($params['query'] ?? '');
        $filters = $params['filters'] ?? [];
        $page = max(1, (int) ($params['page'] ?? 1));
        $defaultPerPage = (int) $this->setting($settings, 'search_results_per_page', 20);
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? $defaultPerPage)));
        $sortBy = $params['sort_by'] ?? 'relevance';
        $language = $params['language'] ?? 'en';

        $maxRawResults = (int) $this->setting($settings, 'search_max_raw_results', 500);
        $nlqEnabled = (bool) $this->setting($settings, 'nlq_enabled', true);
        $fuzzyEnabled = (bool) $this->setting($settings, 'fuzzy_matching_enabled', true);
        $synonymEnabled = (bool) $this->setting($settings, 'synonym_expansion_enabled', true);
        $currencySymbol = $this->setting($settings, 'search_currency_symbol', '₹');

        try {
            // Step 1: Parse natural language intent (category + price extraction)
            $nlq = $nlqEnabled
                ? $this->parseNaturalLanguageQuery($tenantId, $query, $settings)
                : ['text_query' => $query, 'filters' => [], 'interpretation' => null, 'sort_intent' => null];
            $searchQuery = $nlq['text_query'];

            // Apply NLQ-extracted sort intent when caller hasn't set an explicit sort
            if (!empty($nlq['sort_intent']) && $sortBy === 'relevance') {
                $sortBy = $nlq['sort_intent']['field'] . '_' . $nlq['sort_intent']['dir'];
            }

            // Merge NLQ-extracted filters with explicit filters (explicit takes priority)
            $mergedFilters = array_merge($nlq['filters'], $filters);

            // Step 2: Expand query with synonyms
            $expandedQuery = ($searchQuery && $synonymEnabled)
                ? $this->expandQuery($searchQuery, $language, $settings)
                : ($searchQuery ? array_filter(explode(' ', trim($searchQuery))) : []);

            // Step 3: Build MongoDB query
            $dbQuery = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId);

            // Text search with fuzzy matching
            $significantTerms = [];
            $fuzzyPatterns = [];
            if ($searchQuery) {
                $significantTerms = $this->getSignificantTerms($expandedQuery);
                $fuzzyPatterns = $fuzzyEnabled ? $this->generateFuzzyPatterns($significantTerms) : [];

                $dbQuery->where(function ($q) use ($searchQuery, $significantTerms, $fuzzyPatterns) {
                    // Full phrase match on name (highest priority)
                    $escapedPhrase = preg_quote($searchQuery, '/');
                    $q->where('name', 'regex', "/{$escapedPhrase}/i");

                    // All significant terms in name (AND logic)
                    if (count($significantTerms) > 1) {
                        $q->orWhere(function ($inner) use ($significantTerms) {
                            foreach ($significantTerms as $term) {
                                $inner->where('name', 'regex', '/' . preg_quote($term, '/') . '/i');
                            }
                        });
                    }

                    // Individual significant terms in name + sku
                    foreach ($significantTerms as $term) {
                        $escaped = preg_quote($term, '/');
                        $q->orWhere('name', 'regex', "/{$escaped}/i");
                        if (strlen($term) >= 4) {
                            $q->orWhere('sku', 'regex', "/{$escaped}/i");
                        }
                    }

                    // Fuzzy prefix patterns (catch typos like johhie→johnnie)
                    foreach ($fuzzyPatterns as $pattern) {
                        $q->orWhere('name', 'regex', $pattern);
                    }
                });
            }

            // Apply filters (NLQ-extracted + explicit)
            $this->applyFilters($dbQuery, $mergedFilters);

            // Get raw results (configurable limit)
            $rawResults = $dbQuery->limit($maxRawResults)->get();

            // Score and rank results (pass original query for relevance calc)
            $scored = $this->relevanceService->scoreResults(
                $tenantId, $rawResults, $searchQuery ?: $query, $sortBy, $settings
            );

            $total = count($scored);
            $fallbackHint = null;
            $facetSource = $rawResults;

            // Smart fallback: if NLQ price filter yields 0 results, relax price and show hint
            if ($total === 0 && (bool) $this->setting($settings, 'smart_price_fallback', true) &&
                !empty($nlq['filters']) &&
                (isset($nlq['filters']['min_price']) || isset($nlq['filters']['max_price']))) {

                $relaxedFilters = $nlq['filters'];
                unset($relaxedFilters['min_price'], $relaxedFilters['max_price']);
                $relaxedMerged = array_merge($relaxedFilters, $filters);

                $fallbackQuery = DB::connection('mongodb')
                    ->table('synced_products')
                    ->where('tenant_id', $tenantId);

                // Re-apply text search if present
                if ($searchQuery) {
                    $fallbackQuery->where(function ($q) use ($searchQuery, $significantTerms, $fuzzyPatterns) {
                        $escapedPhrase = preg_quote($searchQuery, '/');
                        $q->where('name', 'regex', "/{$escapedPhrase}/i");
                        foreach ($significantTerms as $term) {
                            $escaped = preg_quote($term, '/');
                            $q->orWhere('name', 'regex', "/{$escaped}/i");
                        }
                        foreach ($fuzzyPatterns as $pattern) {
                            $q->orWhere('name', 'regex', $pattern);
                        }
                    });
                }

                $this->applyFilters($fallbackQuery, $relaxedMerged);
                $fallbackRaw = $fallbackQuery->orderBy('price', 'asc')->limit($maxRawResults)->get();

                if ($fallbackRaw->count() > 0) {
                    $minPrice = $fallbackRaw->min('price');
                    $categoryName = $nlq['filters']['category'] ?? 'products';
                    $priceFormatted = $currencySymbol . number_format((float) $minPrice);

                    if (isset($nlq['filters']['max_price'])) {
                        $fallbackHint = "No {$categoryName} under {$currencySymbol}" . number_format($nlq['filters']['max_price']) . ". Cheapest starts at {$priceFormatted}.";
                    } else {
                        $fallbackHint = "Showing {$categoryName} results without price filter.";
                    }

                    $scored = $this->relevanceService->scoreResults(
                        $tenantId, $fallbackRaw, $searchQuery ?: $query, $sortBy, $settings
                    );
                    $total = count($scored);
                    $facetSource = $fallbackRaw;
                }
            }

            // Paginate
            $results = array_slice($scored, ($page - 1) * $perPage, $perPage);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logSearch($tenantId, $params, count($results), $responseTimeMs);

            $suggestions = $total === 0 ? $this->getSuggestions($tenantId, $query) : [];

            return [
                'success'          => true,
                'query'            => $query,
                'interpreted_as'   => $nlq['interpretation'] ?? null,
                'fallback_hint'    => $fallbackHint,
                'results'          => $results,
                'total'            => $total,
                'page'             => $page,
                'per_page'         => $perPage,
                'has_more'         => ($page * $perPage) < $total,
                'suggestions'      => $suggestions,
                'facets'           => $this->buildFacets($facetSource, $settings),
                'response_time_ms' => $responseTimeMs,
            ];
        } catch (\Throwable $e) {
            Log::warning('[AiSearch] SearchService::search failed (MongoDB may be offline): ' . $e->getMessage());
            return [
                'success'     => true,
                'results'     => [],
                'total'       => 0,
                'facets'      => [],
                'suggestions' => [],
                'query'       => $query ?? '',
                'query_info'  => ['error' => 'Search service temporarily unavailable'],
                'page'        => $page ?? 1,
                'per_page'    => $perPage ?? 20,
                'has_more'    => false,
            ];
        }
    }

    /**
     * Get auto-complete suggestions as user types.
     */
    public function suggest(string|int $tenantId, string $prefix, int $limit = 8): array
    {
        $tenantId = (string) $tenantId; // MongoDB stores tenant_id as string
        $settings = $this->loadSettings($tenantId);
        $cacheTtl = (int) $this->setting($settings, 'suggest_cache_ttl', 300);
        $cacheKey = "search_suggest:{$tenantId}:" . md5($prefix);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($tenantId, $prefix, $limit) {
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
                // Carbon is NOT auto-converted in raw aggregation pipelines — use UTCDateTime
                $since30d = new \MongoDB\BSON\UTCDateTime(now()->subDays(30)->startOfDay()->timestamp * 1000);
                $popularTerms = SearchLog::where('tenant_id', $tenantId)
                    ->where('query', 'regex', "/^{$escaped}/i")
                    ->where('created_at', '>=', now()->subDays(30)->startOfDay())
                    ->raw(function ($collection) use ($tenantId, $escaped, $since30d) {
                        return $collection->aggregate([
                            ['$match' => [
                                'tenant_id'  => $tenantId,
                                'query'      => ['$regex' => "^{$escaped}", '$options' => 'i'],
                                'created_at' => ['$gte' => $since30d],
                            ]],
                            ['$group' => ['_id' => '$query', 'count' => ['$sum' => 1]]],
                            ['$sort' => ['count' => -1]],
                            ['$limit' => 5],
                        ], ['maxTimeMS' => 30000]);
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
            } catch (\Throwable $e) {
                Log::warning('[AiSearch] SearchService::suggest failed: ' . $e->getMessage());
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
            // Carbon is NOT auto-converted in raw aggregation pipelines — use UTCDateTime directly
            $since = new \MongoDB\BSON\UTCDateTime(now()->subDays(7)->startOfDay()->timestamp * 1000);
            $trending = SearchLog::where('tenant_id', $tenantId)
                ->raw(function ($collection) use ($tenantId, $limit, $since) {
                    return $collection->aggregate([
                        ['$match' => [
                            'tenant_id'  => $tenantId,
                            'query_type' => 'text',
                            'created_at' => ['$gte' => $since],
                            // Filter out empty/null queries
                            'query'      => ['$exists' => true, '$ne' => ''],
                        ]],
                        ['$group' => [
                            '_id'         => '$query',
                            'count'       => ['$sum' => 1],
                            'avg_results' => ['$avg' => '$results_count'],
                        ]],
                        ['$sort'  => ['count' => -1]],
                        ['$limit' => $limit],
                    ], ['maxTimeMS' => 30000]);
                });

            return [
                'trending' => collect($trending)->map(fn($t) => [
                    'query'       => $t->_id ?? ($t['_id'] ?? ''),
                    'search_count' => $t->count ?? ($t['count'] ?? 0),
                    'avg_results' => round($t->avg_results ?? ($t['avg_results'] ?? 0)),
                ])->values()->toArray(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[AiSearch] SearchService::getTrending failed: ' . $e->getMessage());
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
            // Use Carbon object directly — BSON UTCDateTime doesn't compare with PHP strings
            $since = now()->subDays($days)->startOfDay();
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
        } catch (\Throwable $e) {
            Log::warning('[AiSearch] SearchService::getAnalytics failed: ' . $e->getMessage());
            return [
                'period_days'         => $days,
                'total_searches'      => 0,
                'click_through_rate'  => 0,
                'conversion_rate'     => 0,
                'zero_result_rate'    => 0,
                'avg_response_time'   => 0,
                'top_queries'         => [],
                'zero_result_queries' => [],
            ];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function expandQuery(string $query, string $language, array $settings = []): array
    {
        $terms = array_filter(explode(' ', trim($query)));

        // Default domain-aware synonym expansion (duty-free / liquor store)
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

        // Merge custom synonyms from admin settings (format: "word → syn1, syn2")
        $customSynonyms = $this->setting($settings, 'custom_synonyms', '');
        if (is_string($customSynonyms) && trim($customSynonyms)) {
            foreach (explode("\n", $customSynonyms) as $line) {
                $line = trim($line);
                if (empty($line) || !str_contains($line, '→')) continue;
                [$word, $syns] = array_map('trim', explode('→', $line, 2));
                $word = strtolower($word);
                $synList = array_map('trim', explode(',', $syns));
                $synonyms[$word] = array_merge($synonyms[$word] ?? [], $synList);
            }
        }

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
            'yo', 'ml', 'cl', 'ltr',
            'year', 'years', 'old',
            // Note: 'gift', 'box', 'set', 'pack' deliberately kept as searchable terms
            // so queries like "gift set", "whisky gift box", "gift ideas" work correctly.
        ];

        return array_values(array_filter($terms, function ($t) use ($stopWords) {
            $lower = strtolower($t);
            return strlen($t) > 1 && !in_array($lower, $stopWords);
        }));
    }

    // ── Natural Language Query (NLQ) Parser ──────────────────────────

    /**
     * Parse natural language queries to extract intent.
     * Examples:
     *   "liquor under 500"     → category=Liquor, max_price=500
     *   "whisky above 5000"    → text=whisky, min_price=5000
     *   "perfume between 1000 and 5000" → category=Perfumes, min_price=1000, max_price=5000
     */
    private function parseNaturalLanguageQuery(string $tenantId, string $query, array $settings = []): array
    {
        $original = $query;
        $queryLower = strtolower(trim($query));
        $filters = [];
        $interpretation = [];
        $currencySymbol = $this->setting($settings, 'search_currency_symbol', '₹');

        // ── Price extraction ──

        // "between X and Y" / "between X to Y"
        if (preg_match('/between\s+[₹$]?\s*(\d+)\s*(?:and|to|-)\s*[₹$]?\s*(\d+)/i', $queryLower, $m)) {
            $filters['min_price'] = (float) $m[1];
            $filters['max_price'] = (float) $m[2];
            $interpretation[] = "Price {$currencySymbol}" . number_format((float) $m[1]) . " - {$currencySymbol}" . number_format((float) $m[2]);
            $queryLower = trim(str_ireplace($m[0], '', $queryLower));
        }
        // "under 500" / "below 1000" / "less than" / "upto" / "within" / "max"
        elseif (preg_match('/(?:under|below|less\s+than|upto|up\s+to|cheaper\s+than|within|max|budget)\s+[₹$]?\s*(\d+)/i', $queryLower, $m)) {
            $filters['max_price'] = (float) $m[1];
            $interpretation[] = "Price under {$currencySymbol}" . number_format((float) $m[1]);
            $queryLower = trim(str_ireplace($m[0], '', $queryLower));
        }
        // "from 4000 to 5000" / "from X - Y" (range with from keyword)
        elseif (preg_match('/from\s+[₹$]?\s*(\d+)\s*(?:to|-)\s*[₹$]?\s*(\d+)/i', $queryLower, $m)) {
            $filters['min_price'] = (float) $m[1];
            $filters['max_price'] = (float) $m[2];
            $interpretation[] = "Price {$currencySymbol}" . number_format((float) $m[1]) . " - {$currencySymbol}" . number_format((float) $m[2]);
            $queryLower = trim(str_ireplace($m[0], '', $queryLower));
        }
        // "above 5000" / "over 1000" / "more than" / "from" / "starting" / "min"
        elseif (preg_match('/(?:above|over|more\s+than|from|starting|min|atleast|at\s+least)\s+[₹$]?\s*(\d+)/i', $queryLower, $m)) {
            $filters['min_price'] = (float) $m[1];
            $interpretation[] = "Price over {$currencySymbol}" . number_format((float) $m[1]);
            $queryLower = trim(str_ireplace($m[0], '', $queryLower));
        }
        // "₹X to Y" or "₹X-Y" as price range (currency prefixed)
        elseif (preg_match('/[₹$]\s*(\d+)\s*(?:to|-)\s*[₹$]?\s*(\d+)/i', $queryLower, $m)) {
            $min = (float) $m[1]; $max = (float) $m[2];
            if ($min < $max && $min >= 100) {
                $filters['min_price'] = $min;
                $filters['max_price'] = $max;
                $interpretation[] = "Price {$currencySymbol}" . number_format($min) . " - {$currencySymbol}" . number_format($max);
                $queryLower = trim(str_ireplace($m[0], '', $queryLower));
            }
        }
        // "4000 to 5000" or "4000-5000" bare number range (min >= 100 to avoid age matches)
        elseif (preg_match('/\b(\d{3,})\s*(?:to|-)\s*(\d{3,})\b/', $queryLower, $m)) {
            $min = (float) $m[1]; $max = (float) $m[2];
            if ($min < $max && $min >= 100) {
                $filters['min_price'] = $min;
                $filters['max_price'] = $max;
                $interpretation[] = "Price {$currencySymbol}" . number_format($min) . " - {$currencySymbol}" . number_format($max);
                $queryLower = trim(str_ireplace($m[0], '', $queryLower));
            }
        }

        // ── Sort directive extraction ──
        // Strip "sort by X", "order by X", "alphabetically", etc. from the query
        // before word processing so they don't pollute the text search.
        $sortIntent = null;
        $sortPatterns = [
            // "sort by name z to a" / "z to a" before generic "sort by name"
            '/\b(?:sort(?:ed)?\s+by\s+name|order(?:ed)?\s+by\s+name)\s+(?:z\s*(?:to|-)\s*a|desc(?:ending)?)\b/i'
                => ['field' => 'name', 'dir' => 'desc'],
            // "sort by name" / "order by name" / "sorted by name (a-z)" / "alphabetically"
            '/\b(?:sort(?:ed)?\s+by\s+name|order(?:ed)?\s+by\s+name)(?:\s+(?:a\s*(?:to|-)\s*z|asc(?:ending)?))?+\b/i'
                => ['field' => 'name', 'dir' => 'asc'],
            '/\balphabetical(?:ly|order)?\b/i'
                => ['field' => 'name', 'dir' => 'asc'],
            '/\bprice\s+low(?:\s+to\s+high)?\b|\b(?:sort(?:ed)?\s+by|order(?:ed)?\s+by)\s+price(?:\s+asc(?:ending)?)?\b|\bcheapest\s+first\b/i'
                => ['field' => 'price', 'dir' => 'asc'],
            '/\bprice\s+high(?:\s+to\s+low)?\b|\bmost\s+expensive\b|\bhighest\s+price\b/i'
                => ['field' => 'price', 'dir' => 'desc'],
            '/\bnewest\b|\blatest\b|\brecently\s+added\b|\bnew\s+arrivals?\b/i'
                => ['field' => 'created_at', 'dir' => 'desc'],
            '/\btop\s+rated\b|\bbest\s+rated\b|\bhighest\s+rated\b/i'
                => ['field' => 'rating', 'dir' => 'desc'],
        ];
        foreach ($sortPatterns as $pattern => $sort) {
            if (preg_match($pattern, $queryLower)) {
                $sortIntent = $sort;
                $queryLower = trim(preg_replace($pattern, ' ', $queryLower));
                $queryLower = trim(preg_replace('/\s{2,}/', ' ', $queryLower));
                // Strip orphaned "by" left after removing "sort by"
                $queryLower = trim(preg_replace('/\bby\b/', '', $queryLower));
                $queryLower = trim(preg_replace('/\s{2,}/', ' ', $queryLower));
                $label = $sort['dir'] === 'asc' ? 'A→Z' : 'Z→A';
                if ($sort['field'] === 'price') $label = $sort['dir'] === 'asc' ? 'Price ↑' : 'Price ↓';
                if ($sort['field'] === 'created_at') $label = 'Newest first';
                if ($sort['field'] === 'rating') $label = 'Top rated';
                $interpretation[] = "Sort: {$label}";
                break;
            }
        }

        // ── Category extraction ── (match against real MongoDB categories + aliases)

        $categoryAliases = [
            'liquor'        => 'Liquor',
            'liquors'       => 'Liquor',
            'alcohol'       => 'Liquor',
            'drinks'        => 'Liquor',
            'spirits'       => 'Liquor',
            'perfume'       => 'Perfumes',
            'perfumes'      => 'Perfumes',
            'fragrance'     => 'Perfumes',
            'fragrances'    => 'Perfumes',
            'cologne'       => 'Colognes',
            'beauty'        => 'Beauty',
            'cosmetics'     => 'Beauty',
            'makeup'        => 'Beauty',
            'chocolate'     => 'Confectionery',
            'chocolates'    => 'Confectionery',
            'confectionery' => 'Confectionery',
            'candy'         => 'Confectionery',
            'sweets'        => 'Confectionery',
            'lipstick'      => 'Lips',
            'lips'          => 'Lips',
            'skincare'      => 'Skincare',
            'wine'          => 'Wine',
            'wines'         => 'Wine',
            'champagne'     => 'Champagne',
            'vodka'         => 'Vodka',
            'gin'           => 'Gin',
            'rum'           => 'Rum',
            'brandy'        => 'Brandy',
            'cognac'        => 'Cognac',
            'tequila'       => 'Tequila',
            'bourbon'       => 'Bourbon',
            'scotch'        => 'Blended Scotch',
            // Gift intent: don't strip as a category — keep as text for NLQ
            // (handled in SemanticSearchService::giftConcierge instead)
        ];

        // "gift" as a word should NOT be consumed as a category —
        // remove it from remainingWords only if it's part of a "gift + category" phrase.
        // We handle it below as a flag to enrich results, not strip the word.

        // Merge custom category aliases from admin settings (format: "alias → Category Name")
        $customAliases = $this->setting($settings, 'category_aliases', '');
        if (is_string($customAliases) && trim($customAliases)) {
            foreach (explode("\n", $customAliases) as $line) {
                $line = trim($line);
                if (empty($line) || !str_contains($line, '→')) continue;
                [$alias, $category] = array_map('trim', explode('→', $line, 2));
                $categoryAliases[strtolower($alias)] = $category;
            }
        }

        // Also load actual categories from DB cache
        $dbCategories = $this->getCachedCategories($tenantId);

        $words = array_values(array_filter(explode(' ', $queryLower)));
        $remainingWords = [];
        $matchedCategory = null;

        // Check multi-word categories first (e.g. "single malt", "blended scotch")
        $skipNext = false;
        for ($i = 0; $i < count($words); $i++) {
            if ($skipNext) { $skipNext = false; continue; }
            $word = $words[$i];
            $bigram = ($i < count($words) - 1) ? $word . ' ' . $words[$i + 1] : '';

            // Multi-word match
            if ($bigram && !$matchedCategory) {
                foreach ($dbCategories as $cat) {
                    if (strtolower($cat) === $bigram) {
                        $matchedCategory = $cat;
                        $interpretation[] = "Category: {$cat}";
                        $skipNext = true;
                        break;
                    }
                }
                if ($skipNext) continue;
            }

            // Single-word alias match
            if (!$matchedCategory && isset($categoryAliases[$word])) {
                $matchedCategory = $categoryAliases[$word];
                $interpretation[] = "Category: {$matchedCategory}";
                continue;
            }

            // Single-word exact category match
            if (!$matchedCategory) {
                foreach ($dbCategories as $cat) {
                    if (strtolower($cat) === $word) {
                        $matchedCategory = $cat;
                        $interpretation[] = "Category: {$cat}";
                        break;
                    }
                }
                if ($matchedCategory) continue;
            }

            $remainingWords[] = $word;
        }

        if ($matchedCategory) {
            $filters['category'] = $matchedCategory;
        }

        $textQuery = trim(implode(' ', $remainingWords));

        return [
            'text_query'     => $textQuery,
            'filters'        => $filters,
            'interpretation' => !empty($interpretation) ? implode(' · ', $interpretation) : null,
            'sort_intent'    => $sortIntent,
        ];
    }

    // ── Fuzzy / Typo-Tolerant Search ─────────────────────────────────

    /**
     * Generate prefix-based fuzzy regex patterns for catching typos.
     * "johhie" -> prefix "joh" -> matches "Johnnie"
     * "glenlevit" -> prefix "glen" -> matches "Glenlivet"
     */
    private function generateFuzzyPatterns(array $significantTerms): array
    {
        $patterns = [];

        foreach ($significantTerms as $term) {
            $len = strlen($term);
            if ($len < 3) continue;

            // Use first 3 chars for short terms, first 4 for longer terms
            $prefixLen = $len >= 6 ? 4 : 3;
            $prefix = preg_quote(substr($term, 0, $prefixLen), '/');
            $patterns[] = "/\\b{$prefix}\\w*/i";

            // For 6+ char terms, also use middle trigram (catches suffix typos)
            if ($len >= 6) {
                $mid = (int)($len / 2);
                $midTri = preg_quote(substr($term, $mid - 1, 3), '/');
                $patterns[] = "/\\b\\w*{$midTri}\\w*/i";
            }
        }

        return array_unique($patterns);
    }

    /**
     * Get cached distinct categories for a tenant.
     */
    private function getCachedCategories(string $tenantId): array
    {
        return Cache::remember("tenant_categories:{$tenantId}", 3600, function () use ($tenantId) {
            try {
                $categories = DB::connection('mongodb')
                    ->table('synced_products')
                    ->where('tenant_id', $tenantId)
                    ->raw(function ($collection) use ($tenantId) {
                        return $collection->distinct('categories', ['tenant_id' => $tenantId]);
                    });
                return array_values(array_filter(is_array($categories) ? $categories : iterator_to_array($categories)));
            } catch (\Exception $e) {
                Log::error("getCachedCategories error: {$e->getMessage()}");
                return [];
            }
        });
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
            // Case-insensitive brand matching (brands may be stored in mixed case)
            $query->where('brand', 'regex', '/' . preg_quote((string) $filters['brand'], '/') . '/i');
        }
        // Alias: 'brands' (plural) → same as 'brand'
        if (empty($filters['brand']) && !empty($filters['brands'])) {
            $query->where('brand', 'regex', '/' . preg_quote((string) $filters['brands'], '/') . '/i');
        }
        if (!empty($filters['color'])) {
            $query->where('attributes.color', $filters['color']);
        }
        if (!empty($filters['size'])) {
            $query->where('attributes.size', $filters['size']);
        }
    }

    private function buildFacets($results, array $settings = []): array
    {
        $facets = [];

        // Categories — flatten the categories array field
        if ((bool) ($settings['facet_categories_enabled'] ?? true)) {
            $catLimit = (int) ($settings['facet_categories_limit'] ?? 10);
            $categories = $results->flatMap(function ($p) {
                $p = $p instanceof \stdClass ? (array) $p : $p;
                $cats = $p['categories'] ?? [];
                if ($cats instanceof \MongoDB\Model\BSONArray) {
                    $cats = (array) $cats;
                }
                return is_array($cats) ? $cats : [$cats];
            })->filter()->countBy()->sortDesc()->take($catLimit);

            $facets['categories'] = $categories->map(function ($count, $name) {
                return ['label' => $name, 'value' => $name, 'count' => $count];
            })->values()->toArray();
        } else {
            $facets['categories'] = [];
        }

        // Dynamic price ranges based on actual result prices
        if ((bool) ($settings['facet_price_enabled'] ?? true)) {
            $currencySymbol = $settings['search_currency_symbol'] ?? '₹';
            $prices = $results->map(function ($p) {
                $p = $p instanceof \stdClass ? (array) $p : $p;
                return (float) ($p['price'] ?? 0);
            })->filter(fn($p) => $p > 0)->sort()->values();

            $facets['price_ranges'] = $this->buildDynamicPriceRanges($prices, $currencySymbol);
        } else {
            $facets['price_ranges'] = [];
        }

        // Brands — aggregate from the brand field
        if ((bool) ($settings['facet_brands_enabled'] ?? true)) {
            $brandLimit = (int) ($settings['facet_brands_limit'] ?? 15);
            $brands = $results->map(function ($p) {
                $p = $p instanceof \stdClass ? (array) $p : $p;
                return $p['brand'] ?? null;
            })->filter()->countBy()->sortDesc()->take($brandLimit);

            $facets['brands'] = $brands->map(function ($count, $name) {
                return ['label' => $name, 'value' => $name, 'count' => $count];
            })->values()->toArray();
        } else {
            $facets['brands'] = [];
        }

        return $facets;
    }

    /**
     * Build dynamic INR price ranges based on actual result price distribution.
     */
    private function buildDynamicPriceRanges($prices, string $currencySymbol = '₹'): array
    {
        if ($prices->isEmpty()) return [];

        $min = $prices->min();
        $max = $prices->max();

        // Currency-appropriate break points
        $breakPoints = [500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000];

        // Keep break points that fall within the price range
        $relevantBreaks = array_values(array_filter($breakPoints, fn($bp) => $bp > $min && $bp < $max));

        // Limit to 5 ranges max
        if (count($relevantBreaks) > 4) {
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
                    ? "Under {$currencySymbol}" . number_format($bp)
                    : "{$currencySymbol}" . number_format($prev) . " - {$currencySymbol}" . number_format($bp);
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
                'label' => "Over {$currencySymbol}" . number_format($prev),
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

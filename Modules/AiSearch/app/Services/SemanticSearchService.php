<?php
declare(strict_types=1);

namespace Modules\AiSearch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * SemanticSearchService — Natural language gift concierge + intent parsing.
 *
 * UC1: Semantic Gift Concierge — "gift for 5-year-old boy who likes space"
 * UC5: Typo Auto-Correction — learns from Analytics bounce rates
 * UC6: Subscription Discovery — prioritize Subscribe & Save variants
 * UC9: Feature-Based Comparison — "OLED TV vs QLED TV" → comparison table
 * UC10: Voice-to-Cart — parse spoken intent into cart actions
 */
class SemanticSearchService
{
    private SearchService $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * UC1: Parse natural language gift query → curated results.
     */
    public function giftConcierge(int $tenantId, string $query): array
    {
        try {
            $attributes = $this->parseGiftAttributes($query);

            // Build search filters from parsed attributes
            $filters = [];
            if ($attributes['age_range']) {
                $filters['age_range'] = $attributes['age_range'];
            }
            if ($attributes['gender']) {
                $filters['gender'] = $attributes['gender'];
            }
            if ($attributes['budget_max']) {
                $filters['max_price'] = $attributes['budget_max'];
            }

            // Search with expanded terms from interests
            $searchTerms = array_merge($attributes['interests'], $attributes['keywords']);
            $searchQuery = implode(' ', $searchTerms);

            $results = $this->searchService->search($tenantId, [
                'query'   => $searchQuery,
                'filters' => $filters,
                'sort_by' => 'conversion_rate',
                'per_page' => 12,
            ]);

            // Tag results with gift-worthiness score
            $results['gift_attributes'] = $attributes;
            $results['gift_suggestions'] = $this->generateGiftSuggestions($attributes);
            $results['is_gift_search'] = true;

            // Re-rank by conversion rate + gift relevance
            if (!empty($results['results'])) {
                $results['results'] = $this->rankByGiftRelevance($results['results'], $attributes);
            }

            return $results;
        } catch (\Exception $e) {
            Log::error("SemanticSearch::giftConcierge error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC5: Auto-correct typos using bounce-rate learning.
     */
    public function autoCorrect(int $tenantId, string $query): array
    {
        $cacheKey = "typo_map:{$tenantId}";
        $typoMap = Cache::remember($cacheKey, 3600, function () use ($tenantId) {
            return $this->buildTypoMap($tenantId);
        });

        $corrected = $query;
        $words = explode(' ', strtolower($query));
        $corrections = [];

        foreach ($words as $i => $word) {
            if (isset($typoMap[$word])) {
                $words[$i] = $typoMap[$word]['correction'];
                $corrections[] = [
                    'original'   => $word,
                    'corrected'  => $typoMap[$word]['correction'],
                    'confidence' => $typoMap[$word]['confidence'],
                ];
            } else {
                // Levenshtein distance check against known terms
                $closest = $this->findClosestMatch($word, array_keys($typoMap));
                if ($closest && levenshtein($word, $closest) <= 2) {
                    $words[$i] = $typoMap[$closest]['correction'] ?? $closest;
                    $corrections[] = [
                        'original'   => $word,
                        'corrected'  => $words[$i],
                        'confidence' => 0.7,
                    ];
                }
            }
        }

        return [
            'original_query'  => $query,
            'corrected_query' => implode(' ', $words),
            'was_corrected'   => !empty($corrections),
            'corrections'     => $corrections,
        ];
    }

    /**
     * UC6: Prioritize subscription variants for consumable items.
     */
    public function subscriptionDiscovery(int $tenantId, string $query): array
    {
        $results = $this->searchService->search($tenantId, [
            'query'    => $query,
            'per_page' => 30,
        ]);

        if (empty($results['results'])) {
            return $results;
        }

        $consumableKeywords = ['coffee', 'tea', 'vitamin', 'supplement', 'protein', 'shampoo',
            'soap', 'detergent', 'toothpaste', 'razor', 'battery', 'filter', 'ink', 'toner',
            'pet food', 'diaper', 'formula', 'snack', 'powder', 'capsule', 'cream', 'lotion'];

        $isConsumable = false;
        foreach ($consumableKeywords as $keyword) {
            if (stripos($query, $keyword) !== false) {
                $isConsumable = true;
                break;
            }
        }

        if ($isConsumable) {
            // Boost subscription variants to top
            usort($results['results'], function ($a, $b) {
                $aHasSub = $this->hasSubscriptionVariant($a);
                $bHasSub = $this->hasSubscriptionVariant($b);
                if ($aHasSub && !$bHasSub) return -1;
                if (!$aHasSub && $bHasSub) return 1;
                return 0;
            });
            $results['subscription_hint'] = true;
            $results['subscription_message'] = 'Subscribe & Save options available! Save up to 15% with regular delivery.';
        }

        return $results;
    }

    /**
     * UC9: Parse "X vs Y" queries into comparison tables.
     */
    public function featureComparison(int $tenantId, string $query): array
    {
        $vsMatch = preg_match('/(.+?)\s+vs\.?\s+(.+)/i', $query, $matches);
        if (!$vsMatch) {
            return ['is_comparison' => false];
        }

        $termA = trim($matches[1]);
        $termB = trim($matches[2]);

        // Search for top products in each category
        $resultsA = $this->searchService->search($tenantId, [
            'query' => $termA, 'per_page' => 5, 'sort_by' => 'relevance',
        ]);
        $resultsB = $this->searchService->search($tenantId, [
            'query' => $termB, 'per_page' => 5, 'sort_by' => 'relevance',
        ]);

        // Extract comparable features
        $features = $this->extractComparableFeatures(
            $resultsA['results'] ?? [],
            $resultsB['results'] ?? []
        );

        return [
            'is_comparison'  => true,
            'category_a'     => $termA,
            'category_b'     => $termB,
            'products_a'     => array_slice($resultsA['results'] ?? [], 0, 3),
            'products_b'     => array_slice($resultsB['results'] ?? [], 0, 3),
            'comparison_table' => $features,
            'recommendation' => $this->generateComparisonRecommendation($termA, $termB, $features),
        ];
    }

    /**
     * UC10: Parse voice/text intent into cart actions.
     */
    public function voiceToCart(int $tenantId, string $transcript): array
    {
        $items = $this->parseCartIntent($transcript);
        $resolved = [];

        foreach ($items as $item) {
            $searchResults = $this->searchService->search($tenantId, [
                'query'    => $item['product_query'],
                'per_page' => 3,
                'sort_by'  => 'relevance',
            ]);

            $match = $searchResults['results'][0] ?? null;

            $resolved[] = [
                'parsed_query'  => $item['product_query'],
                'quantity'      => $item['quantity'],
                'matched'       => $match !== null,
                'product'       => $match,
                'confidence'    => $match ? $this->calculateMatchConfidence($item['product_query'], $match) : 0,
                'alternatives'  => array_slice($searchResults['results'] ?? [], 1, 2),
            ];
        }

        return [
            'original_transcript' => $transcript,
            'parsed_items'        => $resolved,
            'items_count'         => count($resolved),
            'all_matched'         => collect($resolved)->every(fn($r) => $r['matched']),
            'cart_actions'        => collect($resolved)->filter(fn($r) => $r['matched'])->map(fn($r) => [
                'action'     => 'add_to_cart',
                'product_id' => $r['product']['id'] ?? $r['product']['external_id'] ?? null,
                'sku'        => $r['product']['sku'] ?? null,
                'quantity'   => $r['quantity'],
            ])->values()->toArray(),
        ];
    }

    // ── Private Helpers ──────────────────────────────────────────

    private function parseGiftAttributes(string $query): array
    {
        $result = [
            'age_range'  => null,
            'gender'     => null,
            'interests'  => [],
            'keywords'   => [],
            'budget_max' => null,
            'occasion'   => null,
        ];

        // Age detection
        if (preg_match('/(\d{1,2})\s*(?:year|yr|yo)/i', $query, $m)) {
            $age = (int) $m[1];
            $result['age_range'] = $age <= 2 ? '0-2' : ($age <= 5 ? '3-5' : ($age <= 12 ? '6-12' : ($age <= 17 ? '13-17' : '18+')));
        }

        // Gender detection
        $maleWords = ['boy', 'son', 'husband', 'boyfriend', 'father', 'dad', 'brother', 'uncle', 'male', 'man', 'him', 'his'];
        $femaleWords = ['girl', 'daughter', 'wife', 'girlfriend', 'mother', 'mom', 'sister', 'aunt', 'female', 'woman', 'her', 'she'];
        $lower = strtolower($query);
        foreach ($maleWords as $w) { if (str_contains($lower, $w)) { $result['gender'] = 'male'; break; } }
        if (!$result['gender']) {
            foreach ($femaleWords as $w) { if (str_contains($lower, $w)) { $result['gender'] = 'female'; break; } }
        }

        // Interest detection
        $interestMap = [
            'space'    => ['space', 'astronaut', 'rocket', 'planet', 'nasa', 'star', 'galaxy'],
            'sports'   => ['sports', 'football', 'soccer', 'basketball', 'baseball', 'tennis', 'athletic'],
            'music'    => ['music', 'guitar', 'piano', 'drum', 'instrument', 'headphone', 'speaker'],
            'art'      => ['art', 'drawing', 'painting', 'craft', 'creative', 'coloring'],
            'tech'     => ['tech', 'computer', 'game', 'gaming', 'robot', 'coding', 'electronic'],
            'cooking'  => ['cook', 'kitchen', 'baking', 'chef', 'food', 'recipe'],
            'outdoor'  => ['outdoor', 'camping', 'hiking', 'garden', 'fishing', 'nature'],
            'reading'  => ['book', 'reading', 'story', 'literature', 'novel'],
        ];
        foreach ($interestMap as $interest => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $result['interests'][] = $interest;
                    $result['keywords'] = array_merge($result['keywords'], $keywords);
                    break;
                }
            }
        }

        // Budget detection
        if (preg_match('/under\s*\$?(\d+)/i', $query, $m)) {
            $result['budget_max'] = (float) $m[1];
        }

        // Occasion
        $occasions = ['birthday', 'christmas', 'anniversary', 'valentine', 'graduation', 'wedding', 'baby shower'];
        foreach ($occasions as $occ) {
            if (str_contains($lower, $occ)) { $result['occasion'] = $occ; break; }
        }

        return $result;
    }

    private function rankByGiftRelevance(array $results, array $attributes): array
    {
        foreach ($results as &$r) {
            $giftScore = 0;
            $name = strtolower($r['name'] ?? '');
            foreach ($attributes['keywords'] as $kw) {
                if (str_contains($name, strtolower($kw))) $giftScore += 10;
            }
            if (isset($r['conversion_rate'])) $giftScore += $r['conversion_rate'] * 0.5;
            if (isset($r['review_count']) && $r['review_count'] > 10) $giftScore += 5;
            $r['gift_relevance_score'] = $giftScore;
        }
        usort($results, fn($a, $b) => ($b['gift_relevance_score'] ?? 0) <=> ($a['gift_relevance_score'] ?? 0));
        return $results;
    }

    private function generateGiftSuggestions(array $attributes): array
    {
        $suggestions = [];
        if ($attributes['age_range'] === '3-5') {
            $suggestions[] = 'Educational toys';
            $suggestions[] = 'Building blocks';
        }
        if (in_array('space', $attributes['interests'])) {
            $suggestions[] = 'Telescope';
            $suggestions[] = 'Space Lego set';
            $suggestions[] = 'Planet puzzle';
        }
        return $suggestions;
    }

    private function buildTypoMap(int $tenantId): array
    {
        try {
            // Find searches with high bounce rates
            $bouncedSearches = DB::connection('mongodb')
                ->table('search_logs')
                ->where('tenant_id', $tenantId)
                ->where('results_count', 0)
                ->where('created_at', '>=', now()->subDays(90)->toDateTimeString())
                ->get(['query'])
                ->groupBy('query');

            $typoMap = [];
            $knownBrands = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->distinct('brand')
                ->filter()
                ->values()
                ->toArray();

            $knownNames = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->pluck('name')
                ->map(fn($n) => strtolower($n))
                ->toArray();

            $allKnown = array_merge(
                array_map('strtolower', $knownBrands),
                $knownNames
            );

            foreach ($bouncedSearches as $query => $logs) {
                $freq = count($logs);
                if ($freq < 3) continue; // Only fix frequent typos

                $closest = $this->findClosestMatch(strtolower($query), $allKnown);
                if ($closest && levenshtein(strtolower($query), $closest) <= 2) {
                    $typoMap[strtolower($query)] = [
                        'correction' => $closest,
                        'confidence' => min(1.0, $freq / 20),
                        'occurrences' => $freq,
                    ];
                }
            }

            return $typoMap;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function findClosestMatch(string $word, array $candidates): ?string
    {
        $bestMatch = null;
        $bestDist = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $dist = levenshtein($word, $candidate);
            if ($dist < $bestDist && $dist <= 2) {
                $bestDist = $dist;
                $bestMatch = $candidate;
            }
        }
        return $bestMatch;
    }

    private function hasSubscriptionVariant(array $product): bool
    {
        return isset($product['subscription_available']) && $product['subscription_available']
            || isset($product['type']) && str_contains(strtolower($product['type']), 'subscription');
    }

    private function extractComparableFeatures(array $productsA, array $productsB): array
    {
        $allAttributes = [];
        foreach (array_merge($productsA, $productsB) as $p) {
            if (isset($p['attributes']) && is_array($p['attributes'])) {
                $allAttributes = array_merge($allAttributes, array_keys($p['attributes']));
            }
        }
        $allAttributes = array_unique($allAttributes);

        $table = [];
        foreach ($allAttributes as $attr) {
            $aValues = collect($productsA)->pluck("attributes.{$attr}")->filter()->unique()->values()->toArray();
            $bValues = collect($productsB)->pluck("attributes.{$attr}")->filter()->unique()->values()->toArray();
            if (!empty($aValues) || !empty($bValues)) {
                $table[] = [
                    'feature'    => ucfirst(str_replace('_', ' ', $attr)),
                    'category_a' => implode(', ', $aValues),
                    'category_b' => implode(', ', $bValues),
                ];
            }
        }

        // Always include price comparison
        $table[] = [
            'feature'    => 'Price Range',
            'category_a' => $this->priceRange($productsA),
            'category_b' => $this->priceRange($productsB),
        ];

        return $table;
    }

    private function priceRange(array $products): string
    {
        $prices = collect($products)->pluck('price')->filter();
        if ($prices->isEmpty()) return 'N/A';
        return '$' . number_format($prices->min(), 2) . ' - $' . number_format($prices->max(), 2);
    }

    private function generateComparisonRecommendation(string $a, string $b, array $features): string
    {
        return "Based on our catalog, {$a} products tend to offer " . count($features) .
            " comparable features with {$b}. View detailed specifications above to make the best choice.";
    }

    private function parseCartIntent(string $transcript): array
    {
        $items = [];
        // Pattern: "add (N) (product)" or "(N) (product)"
        $patterns = [
            '/(?:add\s+)?(\d+|one|two|three|four|five|six|seven|eight|nine|ten|a|an)\s+(?:bag|box|pack|bottle|can|jar|tube|pair|set)s?\s+(?:of\s+)?(.+?)(?:\s+and\s+|$)/i',
            '/(?:add\s+)?(\d+|one|two|three|four|five|six|seven|eight|nine|ten|a|an)\s+(.+?)(?:\s+and\s+|$)/i',
            '/(?:add\s+)?(.+?)(?:\s+and\s+|$)/i',
        ];

        $wordToNum = ['a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
            'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];

        // Split by "and" first
        $parts = preg_split('/\s+and\s+/i', $transcript);
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            $qty = 1;
            $productQuery = $part;

            if (preg_match('/^(?:add\s+)?(\d+|one|two|three|four|five|six|seven|eight|nine|ten|a|an)\s+(?:bag|box|pack|bottle|can|jar|tube|pair|set)s?\s+(?:of\s+)?(.+)/i', $part, $m)) {
                $qty = is_numeric($m[1]) ? (int) $m[1] : ($wordToNum[strtolower($m[1])] ?? 1);
                $productQuery = trim($m[2]);
            } elseif (preg_match('/^(?:add\s+)?(\d+|one|two|three|four|five|six|seven|eight|nine|ten|a|an)\s+(.+)/i', $part, $m)) {
                $qty = is_numeric($m[1]) ? (int) $m[1] : ($wordToNum[strtolower($m[1])] ?? 1);
                $productQuery = trim($m[2]);
            }

            // Strip leading "add "
            $productQuery = preg_replace('/^add\s+/i', '', $productQuery);

            $items[] = [
                'quantity'      => $qty,
                'product_query' => $productQuery,
                'raw_input'     => $part,
            ];
        }

        return $items;
    }

    private function calculateMatchConfidence(string $query, array $product): float
    {
        $name = strtolower($product['name'] ?? '');
        $query = strtolower($query);
        if ($name === $query) return 1.0;
        if (str_contains($name, $query)) return 0.9;
        similar_text($query, $name, $pct);
        return round($pct / 100, 2);
    }
}

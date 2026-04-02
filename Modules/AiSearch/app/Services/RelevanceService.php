<?php
declare(strict_types=1);

namespace Modules\AiSearch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RelevanceService — Margin-aware and behavioral search ranking.
 *
 * Powers Use Cases:
 *   - Margin-Optimized Search Ranking (UC17)
 *   - Personalized search results
 */
class RelevanceService
{
    /**
     * Score and rank search results.
     * Accepts optional $settings array with tenant-specific weights and config.
     */
    public function scoreResults(string|int $tenantId, $results, string $query, string $sortBy = 'relevance', array $settings = []): array
    {
        // Read weights from settings (0-100 scale → 0-1 scale)
        $wText       = ((float) ($settings['weight_text_relevance'] ?? 60)) / 100;
        $wMargin     = ((float) ($settings['weight_margin_boost'] ?? 15)) / 100;
        $wPopularity = ((float) ($settings['weight_popularity'] ?? 10)) / 100;
        $wFreshness  = ((float) ($settings['weight_freshness'] ?? 10)) / 100;
        $wStock      = ((float) ($settings['weight_stock'] ?? 5)) / 100;

        $currency    = $settings['search_currency_code'] ?? 'INR';
        $baseUrl     = rtrim($settings['search_store_base_url'] ?? '', '/');
        $urlPattern  = $settings['search_product_url_pattern'] ?? '/default/{url_key}.html';

        $scored = $results->map(function ($product) use ($tenantId, $query, $wText, $wMargin, $wPopularity, $wFreshness, $wStock, $currency, $baseUrl, $urlPattern) {
            $product = $product instanceof \stdClass ? (array) $product : $product;
            $score = 0;

            // Text relevance — Most important for search quality
            $textScore = $this->calculateTextRelevance($product, $query);
            $score += $textScore * $wText;

            // Margin boost — Higher-margin products rank higher
            $marginScore = $this->calculateMarginScore($product);
            $score += $marginScore * $wMargin;

            // Popularity boost
            $popularityScore = $this->calculatePopularityScore($product);
            $score += $popularityScore * $wPopularity;

            // Freshness boost — Newer products get a slight boost
            $freshnessScore = $this->calculateFreshnessScore($product);
            $score += $freshnessScore * $wFreshness;

            // Stock penalty — Low stock items are penalized
            $stockScore = $this->calculateStockScore($product);
            $score += $stockScore * $wStock;

            // Extract categories from array field
            $categories = $product['categories'] ?? [];
            if ($categories instanceof \MongoDB\Model\BSONArray) {
                $categories = (array) $categories;
            }
            $category = is_array($categories) ? ($categories[0] ?? null) : $categories;

            // Build product URL from url_key (configurable pattern)
            $urlKey = $product['url_key'] ?? '';
            $productUrl = $urlKey ? str_replace('{url_key}', $urlKey, $urlPattern) : null;

            return [
                'id'              => $product['external_id'] ?? (string) ($product['_id'] ?? ''),
                'name'            => $product['name'] ?? '',
                'sku'             => $product['sku'] ?? '',
                'price'           => $product['price'] ?? 0,
                'special_price'   => $product['special_price'] ?? null,
                'currency'        => $currency,
                'image'           => $product['image_url'] ?? $product['image'] ?? null,
                'category'        => $category,
                'brand'           => $product['brand'] ?? null,
                'url_key'         => $urlKey,
                'url'             => $productUrl,
                'stock_qty'       => $product['stock_qty'] ?? 0,
                'in_stock'        => ($product['stock_qty'] ?? 0) > 0,
                'rating'          => $product['rating'] ?? null,
                'review_count'    => $product['review_count'] ?? 0,
                'relevance_score' => round($score, 4),
                'score_breakdown' => [
                    'text'       => round($textScore, 2),
                    'margin'     => round($marginScore, 2),
                    'popularity' => round($popularityScore, 2),
                    'freshness'  => round($freshnessScore, 2),
                    'stock'      => round($stockScore, 2),
                ],
            ];
        })->toArray();

        // Sort based on requested sort
        usort($scored, function ($a, $b) use ($sortBy) {
            return match ($sortBy) {
                'price_asc'  => ($a['price'] ?? 0) <=> ($b['price'] ?? 0),
                'price_desc' => ($b['price'] ?? 0) <=> ($a['price'] ?? 0),
                'newest'     => 0, // Already sorted by freshness in score
                'rating'     => ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0),
                default      => ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0),
            };
        });

        return $scored;
    }

    /**
     * Get margin boost scores for a set of product IDs (used by BI integration).
     */
    public function getMarginBoosts(string|int $tenantId, array $productIds): array
    {
        $tenantId = (string) $tenantId;
        try {
            $products = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->whereIn('external_id', $productIds)
                ->get();

            $boosts = [];
            foreach ($products as $product) {
                $id = $product['external_id'] ?? '';
                $boosts[$id] = $this->calculateMarginScore($product);
            }

            return $boosts;
        } catch (\Exception $e) {
            Log::error("RelevanceService::getMarginBoosts error: {$e->getMessage()}");
            return [];
        }
    }

    // ── Private scoring methods ──────────────────────────────────────

    private function calculateTextRelevance($product, string $query): float
    {
        if (empty($query)) return 50;

        $name = strtolower(trim($product['name'] ?? ''));
        $sku = strtolower(trim($product['sku'] ?? ''));
        $description = strtolower(trim($product['description'] ?? ''));
        $queryLower = strtolower(trim($query));
        $queryWords = array_values(array_filter(explode(' ', $queryLower), fn($w) => strlen($w) > 1));

        // Exact name match — perfect score
        if ($name === $queryLower) {
            return 100;
        }

        $score = 0;

        // SKU exact match — very high
        if ($sku === $queryLower) {
            return 95;
        }

        // Name starts with query phrase
        if (str_starts_with($name, $queryLower)) {
            $score += 90;
        }
        // Name contains full query phrase
        elseif (str_contains($name, $queryLower)) {
            $score += 80;
        }
        // SKU contains query
        elseif (str_contains($sku, str_replace(' ', '', $queryLower))) {
            $score += 70;
        }

        // Word-level matching in name (most important metric)
        if (count($queryWords) > 0) {
            $nameWords = array_values(array_filter(explode(' ', $name), fn($w) => strlen($w) > 0));
            $exactMatchedWords = 0;
            $fuzzyMatchedWords = 0;
            $totalFuzzyQuality = 0.0;

            foreach ($queryWords as $qw) {
                $exactMatch = false;
                $fuzzyMatch = false;
                $bestFuzzyQuality = 0.0;

                foreach ($nameWords as $nw) {
                    if (strlen($nw) === 0) continue;

                    // Exact/substring match — require name word to be comparable length
                    // "glenlevit" contains "glen" (4/9=44%) → NOT exact, fall through to fuzzy
                    // "walker" contains "walk" (4/6=67%) → NOT exact, fall through to fuzzy
                    // "glenlivet" contains "glenliv" (7/9=78%) → exact
                    if ($nw === $qw || str_contains($nw, $qw) ||
                        (strlen($qw) > 2 && str_contains($qw, $nw) && strlen($nw) >= strlen($qw) * 0.7)) {
                        $exactMatch = true;
                        break;
                    }

                    // Fuzzy match (Levenshtein + phonetic) for words 3+ chars
                    if (strlen($qw) >= 3 && strlen($nw) >= 3) {
                        $maxDist = max(2, (int) ceil(strlen($qw) * 0.35));
                        $dist = levenshtein($qw, $nw);
                        if ($dist <= $maxDist) {
                            $quality = 1.0 - ($dist / max(strlen($qw), strlen($nw)));
                            if ($quality > $bestFuzzyQuality) {
                                $bestFuzzyQuality = $quality;
                                $fuzzyMatch = true;
                            }
                        }
                        // Phonetic comparison (metaphone / soundex)
                        if ($bestFuzzyQuality < 0.7) {
                            if (metaphone($qw) === metaphone($nw) || soundex($qw) === soundex($nw)) {
                                $quality = 0.7;
                                if ($quality > $bestFuzzyQuality) {
                                    $bestFuzzyQuality = $quality;
                                    $fuzzyMatch = true;
                                }
                            }
                        }
                    }
                }

                if ($exactMatch) {
                    $exactMatchedWords++;
                } elseif ($fuzzyMatch) {
                    $fuzzyMatchedWords++;
                    $totalFuzzyQuality += $bestFuzzyQuality;
                }
            }

            $totalMatched = $exactMatchedWords + $fuzzyMatchedWords;
            $totalRatio = count($queryWords) > 0 ? $totalMatched / count($queryWords) : 0;

            // Unified scoring: reward products where ALL query words match
            if ($totalRatio >= 1.0) {
                // All query words matched (exact or fuzzy) — best outcome
                $avgFuzzyQuality = $fuzzyMatchedWords > 0 ? $totalFuzzyQuality / $fuzzyMatchedWords : 1.0;
                $baseScore = ($exactMatchedWords * 50 + $fuzzyMatchedWords * $avgFuzzyQuality * 45) / count($queryWords);
                $score += $baseScore;
            } elseif ($totalRatio >= 0.5) {
                // Partial match — lower score
                $avgFuzzyQuality = $fuzzyMatchedWords > 0 ? $totalFuzzyQuality / $fuzzyMatchedWords : 1.0;
                $baseScore = ($exactMatchedWords * 30 + $fuzzyMatchedWords * $avgFuzzyQuality * 20) / count($queryWords);
                $score += $baseScore;
            } else {
                // Poor match — minimal score
                $score += $totalMatched * 5;
            }

            // Consecutive word matching bonus (phrase-aware)
            if (count($queryWords) > 1) {
                $consecutiveMatches = 0;
                for ($i = 0; $i < count($queryWords) - 1; $i++) {
                    $pair = preg_quote($queryWords[$i], '/') . '[\s\-]+' . preg_quote($queryWords[$i + 1], '/');
                    if (preg_match("/{$pair}/i", $name)) {
                        $consecutiveMatches++;
                    }
                }
                $score += min(20, $consecutiveMatches * 8);
            }
        }

        // Small bonus for description match (tiebreaker only)
        if (!empty($description) && str_contains($description, $queryLower)) {
            $score += 5;
        }

        return min(100, $score);
    }

    private function calculateMarginScore($product): float
    {
        $price = (float) ($product['price'] ?? 0);
        $cost = (float) ($product['cost_price'] ?? $product['cost'] ?? 0);

        if ($price <= 0 || $cost <= 0) return 50; // Neutral if no cost data

        $margin = (($price - $cost) / $price) * 100;

        // Map margin to 0-100 scale
        return match (true) {
            $margin >= 70 => 100,
            $margin >= 50 => 85,
            $margin >= 30 => 65,
            $margin >= 15 => 45,
            $margin >= 5  => 25,
            default       => 10,
        };
    }

    private function calculatePopularityScore($product): float
    {
        $reviewCount = (int) ($product['review_count'] ?? 0);
        $rating = (float) ($product['rating'] ?? 0);
        $salesCount = (int) ($product['sales_count'] ?? 0);

        // Combined popularity metric
        $ratingScore = $rating * 20; // Max 100
        $reviewScore = min(100, $reviewCount * 2); // Cap at 50 reviews = 100
        $salesScore = min(100, $salesCount * 5); // Cap at 20 sales = 100

        return ($ratingScore * 0.4 + $reviewScore * 0.3 + $salesScore * 0.3);
    }

    private function calculateFreshnessScore($product): float
    {
        $createdAt = $product['created_at'] ?? $product['synced_at'] ?? null;
        if (!$createdAt) return 50;

        try {
            $daysOld = (int) now()->diffInDays($createdAt);
        } catch (\Exception $e) {
            return 50;
        }

        return match (true) {
            $daysOld <= 7   => 100,
            $daysOld <= 30  => 80,
            $daysOld <= 90  => 60,
            $daysOld <= 180 => 40,
            default         => 20,
        };
    }

    private function calculateStockScore($product): float
    {
        $stock = (int) ($product['stock_qty'] ?? 0);

        if ($stock <= 0) return 0;  // Out of stock = 0
        if ($stock < 5)  return 40; // Low stock
        if ($stock < 20) return 70; // Moderate stock
        return 100;                   // Well stocked
    }
}

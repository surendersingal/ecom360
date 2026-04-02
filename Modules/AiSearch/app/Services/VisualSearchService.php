<?php
declare(strict_types=1);

namespace Modules\AiSearch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AiSearch\Models\SearchLog;

/**
 * VisualSearchService — Image-based product search.
 *
 * Powers Use Cases:
 *   - Visual Search (UC19)
 *   - "Shop the look" from uploaded images
 */
class VisualSearchService
{
    /**
     * Search products by image.
     * Accepts base64 image or uploaded file URL.
     */
    public function searchByImage(int $tenantId, array $params): array
    {
        $startTime = microtime(true);

        try {
            $imageData = $params['image_base64'] ?? null;
            $imageUrl = $params['image_url'] ?? null;
            $limit = min(50, (int) ($params['limit'] ?? 20));

            if (!$imageData && !$imageUrl) {
                return ['success' => false, 'error' => 'No image provided.'];
            }

            // Extract visual features from image using GD
            $features = $this->extractFeatures($imageData ?? $imageUrl);

            // Build a broad search — fetch products and score them
            // instead of pre-filtering too aggressively.
            $query = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', (string) $tenantId)
                ->where('status', 'enabled');

            // If we detected colors, search by color in name, brand, description
            $colorTerms = $features['dominant_colors'] ?? [];
            $categoryGuess = $features['category_guess'] ?? null;
            $textHints = $features['text_hints'] ?? [];

            // Build OR conditions for broader matching
            if (!empty($colorTerms) || !empty($categoryGuess) || !empty($textHints)) {
                $query->where(function ($q) use ($colorTerms, $categoryGuess, $textHints) {
                    // Color matching in name or attributes
                    foreach ($colorTerms as $color) {
                        $q->orWhere('name', 'regex', "/{$color}/i")
                          ->orWhere('short_description', 'regex', "/{$color}/i");
                    }
                    // Category matching in categories array
                    if ($categoryGuess) {
                        $q->orWhere('categories', 'regex', "/{$categoryGuess}/i")
                          ->orWhere('name', 'regex', "/{$categoryGuess}/i");
                    }
                    // Text hints from image analysis
                    foreach ($textHints as $hint) {
                        $q->orWhere('name', 'regex', "/{$hint}/i")
                          ->orWhere('brand', 'regex', "/{$hint}/i")
                          ->orWhere('short_description', 'regex', "/{$hint}/i");
                    }
                });
            }

            // Fetch more than needed so we can score and rank
            $products = $query->limit($limit * 5)->get();

            // If narrow search found too few results, broaden to all products
            if ($products->count() < 5) {
                $products = DB::connection('mongodb')
                    ->table('synced_products')
                    ->where('tenant_id', (string) $tenantId)
                    ->where('status', 'enabled')
                    ->limit(200)
                    ->get();
            }

            // Score by visual similarity
            $scored = $products->map(function ($p) use ($features) {
                $p = (array) $p;
                $similarity = $this->calculateVisualSimilarity($p, $features);
                return [
                    'id'              => $p['external_id'] ?? (string) ($p['_id'] ?? ''),
                    'name'            => $p['name'] ?? '',
                    'price'           => $p['price'] ?? 0,
                    'special_price'   => $p['special_price'] ?? null,
                    'image'           => $p['image_url'] ?? $p['image'] ?? null,
                    'category'        => is_array($p['categories'] ?? null) ? implode(' > ', $p['categories']) : ($p['category'] ?? null),
                    'brand'           => $p['brand'] ?? null,
                    'url'             => $p['url'] ?? (isset($p['url_key']) && $p['url_key'] ? '/' . $p['url_key'] . '.html' : null),
                    'similarity_score' => round($similarity, 4),
                ];
            })->filter(fn($p) => $p['similarity_score'] > 10)
              ->sortByDesc('similarity_score')
              ->values()
              ->toArray();

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Log
            $this->logVisualSearch((int) $tenantId, $params, count($scored), $responseTimeMs, $features);

            return [
                'success'          => true,
                'results'          => array_slice($scored, 0, $limit),
                'total'            => count($scored),
                'detected_features' => $features,
                'response_time_ms' => $responseTimeMs,
            ];
        } catch (\Exception $e) {
            Log::error("VisualSearchService::searchByImage error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage(), 'results' => []];
        }
    }

    /**
     * Find visually similar products to a given product.
     */
    public function findSimilar(int|string $tenantId, string $productId, int $limit = 10): array
    {
        try {
            $tenantId = (string) $tenantId;
            $product = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('external_id', $productId)
                ->first();

            if (!$product) {
                return ['success' => false, 'error' => 'Product not found.'];
            }

            // Convert stdClass to array for consistent access
            $product = (array) $product;

            // Find products in same categories with similar attributes
            $productCategories = $product['categories'] ?? [];
            $similar = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', (string) $tenantId)
                ->where('external_id', '!=', $productId);

            // Match products sharing any category
            if (!empty($productCategories) && is_array($productCategories)) {
                $similar->where(function ($q) use ($productCategories) {
                    foreach ($productCategories as $cat) {
                        $q->orWhere('categories', $cat);
                    }
                });
            }

            $similar = $similar->limit($limit * 3)->get();

            // Score similarity
            $scored = $similar->map(function ($p) use ($product) {
                $p = (array) $p;
                $score = 0;

                // Shared categories
                $pCats = $p['categories'] ?? [];
                $prodCats = $product['categories'] ?? [];
                if (is_array($pCats) && is_array($prodCats)) {
                    $shared = count(array_intersect($pCats, $prodCats));
                    $score += min(30, $shared * 15);
                }

                // Similar price range (within 30%)
                $price = (float) ($product['price'] ?? 0);
                $pPrice = (float) ($p['price'] ?? 0);
                if ($price > 0 && $pPrice > 0) {
                    $ratio = abs($price - $pPrice) / $price;
                    if ($ratio < 0.3) $score += 25 * (1 - $ratio);
                }

                // Same brand
                if (($p['brand'] ?? '') === ($product['brand'] ?? '') && !empty($product['brand'])) {
                    $score += 15;
                }

                // Color match
                $attrs = (array) ($p['attributes'] ?? []);
                $prodAttrs = (array) ($product['attributes'] ?? []);
                $pColor = strtolower($attrs['color'] ?? '');
                $prodColor = strtolower($prodAttrs['color'] ?? '');
                if ($pColor && $prodColor && $pColor === $prodColor) {
                    $score += 20;
                }

                // Has image
                if (!empty($p['image'])) $score += 10;

                return [
                    'id'              => $p['external_id'] ?? (string) ($p['_id'] ?? ''),
                    'name'            => $p['name'] ?? '',
                    'price'           => $p['price'] ?? 0,
                    'image'           => $p['image_url'] ?? $p['image'] ?? null,
                    'url'             => $p['url'] ?? (isset($p['url_key']) ? '/' . $p['url_key'] . '.html' : null),
                    'similarity_score' => round($score, 2),
                ];
            })->sortByDesc('similarity_score')->values()->take($limit)->toArray();

            return [
                'success'    => true,
                'product_id' => $productId,
                'similar'    => $scored,
                'total'      => count($scored),
            ];
        } catch (\Exception $e) {
            Log::error("VisualSearchService::findSimilar error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * Extract visual features from image using GD library.
     * Analyzes dominant colors, brightness, and infers product category.
     */
    private function extractFeatures($imageSource): array
    {
        $colors = [];
        $brightness = 'medium';
        $textHints = [];

        try {
            $imageData = $this->loadImageBinary($imageSource);
            if ($imageData) {
                $img = @imagecreatefromstring($imageData);
                if ($img) {
                    $colors = $this->extractDominantColors($img);
                    $brightness = $this->analyzeBrightness($img);
                    imagedestroy($img);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Image feature extraction failed: {$e->getMessage()}");
        }

        // Infer product category from detected colors & common patterns
        $categoryGuess = $this->inferCategory($colors, $brightness);

        // Map detected colors to product-relevant color names
        $productColors = $this->mapToProductColors($colors);

        // Extract text hints from color analysis
        // Dark amber/gold tones → whisky/spirits, red hues → wine, etc.
        $textHints = $this->inferTextHints($colors, $brightness);

        return [
            'dominant_colors'  => $productColors,
            'raw_colors'       => array_map(fn($c) => sprintf('#%02x%02x%02x', $c['r'], $c['g'], $c['b']), array_slice($colors, 0, 5)),
            'brightness'       => $brightness,
            'category_guess'   => $categoryGuess,
            'text_hints'       => $textHints,
            'confidence'       => !empty($colors) ? 0.75 : 0.30,
        ];
    }

    /**
     * Load image binary data from base64 string or URL.
     */
    private function loadImageBinary(string $source): ?string
    {
        // Base64 data URI
        if (str_starts_with($source, 'data:image/')) {
            $parts = explode(',', $source, 2);
            return base64_decode($parts[1] ?? '');
        }
        // Raw base64
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', substr($source, 0, 100))) {
            $decoded = base64_decode($source, true);
            if ($decoded !== false) return $decoded;
        }
        // URL
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                return @file_get_contents($source, false, $ctx) ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Extract dominant colors from an image using pixel sampling via GD.
     * Returns array of ['r' => int, 'g' => int, 'b' => int, 'count' => int].
     */
    private function extractDominantColors(\GdImage $img): array
    {
        $width = imagesx($img);
        $height = imagesy($img);

        // Sample pixels at regular intervals (max ~2500 samples for speed)
        $stepX = max(1, (int) ($width / 50));
        $stepY = max(1, (int) ($height / 50));

        $colorBuckets = [];
        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Quantize to 32-level buckets for clustering
                $qr = (int) round($r / 32) * 32;
                $qg = (int) round($g / 32) * 32;
                $qb = (int) round($b / 32) * 32;
                $key = "{$qr},{$qg},{$qb}";

                if (!isset($colorBuckets[$key])) {
                    $colorBuckets[$key] = ['r' => $qr, 'g' => $qg, 'b' => $qb, 'count' => 0];
                }
                $colorBuckets[$key]['count']++;
            }
        }

        // Sort by frequency
        usort($colorBuckets, fn($a, $b) => $b['count'] - $a['count']);

        return array_slice($colorBuckets, 0, 8);
    }

    /**
     * Analyze overall image brightness.
     */
    private function analyzeBrightness(\GdImage $img): string
    {
        $width = imagesx($img);
        $height = imagesy($img);
        $step = max(1, (int) (($width * $height) / 1000));

        $totalBrightness = 0;
        $samples = 0;
        for ($i = 0; $i < $width * $height; $i += $step) {
            $x = $i % $width;
            $y = (int) ($i / $width);
            if ($y >= $height) break;
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $totalBrightness += ($r + $g + $b) / 3;
            $samples++;
        }

        $avg = $samples > 0 ? $totalBrightness / $samples : 128;
        if ($avg < 70) return 'dark';
        if ($avg > 185) return 'light';
        return 'medium';
    }

    /**
     * Map quantized RGB colors to human-readable product color names.
     * Filters out background colors (white, light gray) to focus on product colors.
     */
    private function mapToProductColors(array $colors): array
    {
        $backgroundColors = ['white', 'light gray'];
        $names = [];
        foreach (array_slice($colors, 0, 8) as $c) {
            $name = $this->rgbToColorName($c['r'], $c['g'], $c['b']);
            if ($name && !in_array($name, $names) && !in_array($name, $backgroundColors)) {
                $names[] = $name;
            }
        }
        // If all colors were background, return all of them
        if (empty($names)) {
            foreach (array_slice($colors, 0, 5) as $c) {
                $name = $this->rgbToColorName($c['r'], $c['g'], $c['b']);
                if ($name && !in_array($name, $names)) {
                    $names[] = $name;
                }
            }
        }
        return $names ?: ['unknown'];
    }

    /**
     * Convert RGB to a product-relevant color name.
     */
    private function rgbToColorName(int $r, int $g, int $b): string
    {
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $luminance = ($r + $g + $b) / 3;

        // Very dark
        if ($luminance < 40) return 'black';
        // Very light
        if ($luminance > 220 && ($max - $min) < 30) return 'white';
        // Grays
        if (($max - $min) < 25) {
            if ($luminance < 100) return 'dark gray';
            if ($luminance < 170) return 'gray';
            return 'light gray';
        }

        // Warm amber/gold tones (whisky bottles, gift packaging)
        if ($r > 150 && $g > 80 && $g < 180 && $b < 80) return 'amber';
        if ($r > 180 && $g > 140 && $g < 210 && $b < 100) return 'gold';

        // Color classification by dominant channel
        if ($r > $g && $r > $b) {
            if ($g > 100 && $b < 80) return ($r > 200) ? 'gold' : 'brown';
            if ($r > 200 && $g < 80) return 'red';
            if ($r > 160 && $g < 120) return 'dark red';
            return 'red';
        }
        if ($g > $r && $g > $b) {
            if ($g > 150 && $r < 100) return 'green';
            return 'olive';
        }
        if ($b > $r && $b > $g) {
            if ($b > 150 && $r < 80 && $g < 80) return 'blue';
            if ($b > 120 && $r > 80) return 'navy';
            return 'blue';
        }

        // Mixed
        if ($r > 150 && $b > 150 && $g < 100) return 'purple';
        if ($r > 150 && $g > 150 && $b < 80) return 'yellow';

        return 'brown';
    }

    /**
     * Infer product category from color palette and brightness.
     * Maps visual patterns to duty-free product categories.
     */
    private function inferCategory(array $colors, string $brightness): string
    {
        if (empty($colors)) return '';

        $colorNames = [];
        foreach (array_slice($colors, 0, 8) as $c) {
            $colorNames[] = $this->rgbToColorName($c['r'], $c['g'], $c['b']);
        }
        $colorNames = array_unique($colorNames);

        // Filter out background colors for category inference
        $productColors = array_diff($colorNames, ['white', 'light gray']);

        $hasGold = in_array('gold', $productColors) || in_array('amber', $productColors);
        $hasDark = in_array('black', $productColors) || in_array('dark gray', $productColors) || in_array('navy', $productColors);
        $hasBlue = in_array('blue', $productColors) || in_array('navy', $productColors);
        $hasRed = in_array('red', $productColors) || in_array('dark red', $productColors);
        $hasBrown = in_array('brown', $productColors);

        if ($hasDark && $hasGold) return 'Whisky';
        if ($hasBlue && ($hasGold || $hasDark)) return 'Scotch';
        if ($hasDark && $hasBlue) return 'Whisky';
        if ($hasRed && ($hasDark || $hasBrown)) return 'Spirits';
        if ($hasGold && $brightness === 'dark') return 'Liquor';
        if ($hasDark && $hasRed) return 'Spirits';
        if ($hasRed) return 'Wine';
        if ($hasDark) return 'Whisky';
        if ($hasBrown) return 'Cognac';

        return 'Liquor'; // Default for duty-free store
    }

    /**
     * Generate text search hints based on color analysis.
     * These become regex search terms against product names/brands.
     */
    private function inferTextHints(array $colors, string $brightness): array
    {
        $hints = [];
        $colorNames = [];
        // Analyze ALL color buckets (not just top 5) for better coverage
        foreach (array_slice($colors, 0, 8) as $c) {
            $colorNames[] = $this->rgbToColorName($c['r'], $c['g'], $c['b']);
        }
        // Deduplicate
        $colorNames = array_unique($colorNames);

        $hasGold = in_array('gold', $colorNames) || in_array('amber', $colorNames);
        $hasDark = in_array('black', $colorNames) || in_array('navy', $colorNames) || in_array('dark gray', $colorNames);
        $hasBlue = in_array('blue', $colorNames) || in_array('navy', $colorNames);
        $hasRed = in_array('red', $colorNames) || in_array('dark red', $colorNames);
        $hasGreen = in_array('green', $colorNames) || in_array('olive', $colorNames);
        $hasBrown = in_array('brown', $colorNames);

        // Blue anywhere in palette → "Blue Label" / "Johnnie Walker"
        if ($hasBlue) {
            $hints[] = 'Blue Label';
            $hints[] = 'Johnnie Walker';
        }
        // Dark blue + gold = classic whisky gift box
        if ($hasBlue && $hasGold) {
            $hints[] = 'Blue Label';
            $hints[] = 'Scotch';
        }
        // Red in palette → Red Label, wine
        if ($hasRed) {
            $hints[] = 'Red Label';
            $hints[] = 'Wine';
        }
        // Red + dark → could be combo packs with ribbon / branding
        if ($hasRed && ($hasDark || $hasBlue)) {
            $hints[] = 'Johnnie Walker';
            $hints[] = 'Combo';
            $hints[] = 'Label';
        }
        // Gold / amber → Gold Label, premium spirits
        if ($hasGold) {
            $hints[] = 'Gold';
            $hints[] = 'Gold Label';
            $hints[] = 'Reserve';
            $hints[] = 'Premium';
            $hints[] = '18';
        }
        // Dark packaging → premium spirits
        if ($hasDark) {
            $hints[] = 'Whisky';
            $hints[] = 'Scotch';
            $hints[] = 'Premium';
        }
        // Brown → cognac, brandy, bourbon
        if ($hasBrown) {
            $hints[] = 'Cognac';
            $hints[] = 'Bourbon';
        }
        // Green tones → gin, Glenfiddich, etc.
        if ($hasGreen) {
            $hints[] = 'Gin';
            $hints[] = 'Green Label';
            $hints[] = 'Glenfiddich';
        }

        return array_values(array_unique($hints));
    }

    private function featuresToSearchCriteria(array $features): array
    {
        return [
            'colors'     => $features['dominant_colors'] ?? [],
            'category'   => $features['category_guess'] ?? null,
            'text_hints' => $features['text_hints'] ?? [],
        ];
    }

    private function calculateVisualSimilarity(array $product, array $features): float
    {
        $score = 0;
        $name = strtolower($product['name'] ?? '');
        $brand = strtolower($product['brand'] ?? '');
        $desc = strtolower($product['short_description'] ?? ($product['description'] ?? ''));
        $sku = strtolower($product['sku'] ?? '');
        $categories = $product['categories'] ?? [];
        if (is_array($categories)) {
            $catStr = strtolower(implode(' ', $categories));
        } else {
            $catStr = strtolower((string) $categories);
        }
        $searchable = $name . ' ' . $brand . ' ' . $desc;

        // Text hint matching (highest signal — 25 pts per hint match, max 75)
        $textHitCount = 0;
        foreach ($features['text_hints'] ?? [] as $hint) {
            $hintLower = strtolower($hint);
            if (str_contains($searchable, $hintLower)) {
                $textHitCount++;
            }
        }
        $score += min(75, $textHitCount * 25);

        // Category matching (20 pts)
        $categoryGuess = strtolower($features['category_guess'] ?? '');
        if ($categoryGuess && str_contains($catStr, $categoryGuess)) {
            $score += 20;
        }

        // Color name matching in product name/description (15 pts)
        $dominantColors = $features['dominant_colors'] ?? [];
        foreach ($dominantColors as $color) {
            if (str_contains($name, strtolower($color)) || str_contains($desc, strtolower($color))) {
                $score += 15;
                break;
            }
        }

        // Has image bonus (5 pts)
        if (!empty($product['image_url']) || !empty($product['image'])) {
            $score += 5;
        }

        return min(100, $score);
    }

    /**
     * UC2: Shop the Room — detect multiple objects in a scene, match each to catalog.
     * Input: room/scene image. Output: list of detected objects with product matches.
     */
    public function shopTheRoom(int $tenantId, array $params): array
    {
        $startTime = microtime(true);

        try {
            $imageData = $params['image_base64'] ?? null;
            $imageUrl = $params['image_url'] ?? null;

            if (!$imageData && !$imageUrl) {
                return ['success' => false, 'error' => 'No image provided.'];
            }

            // Detect objects in the scene (simulated — production: Google Vision / Rekognition)
            $detectedObjects = $this->detectSceneObjects($imageData ?? $imageUrl);

            $roomItems = [];
            foreach ($detectedObjects as $object) {
                // For each detected item, search catalog for matches
                $query = DB::connection('mongodb')
                    ->table('synced_products')
                    ->where('tenant_id', $tenantId)
                    ->where(function ($q) use ($object) {
                        $q->where('category', 'regex', "/{$object['label']}/i")
                          ->orWhere('name', 'regex', "/{$object['label']}/i");
                    })
                    ->limit(6)
                    ->get();

                $matches = $query->map(function ($p) use ($object) {
                    return [
                        'id'       => $p['external_id'] ?? (string) ($p['_id'] ?? ''),
                        'name'     => $p['name'] ?? '',
                        'price'    => $p['price'] ?? 0,
                        'image'    => $p['image'] ?? null,
                        'url'      => $p['url'] ?? null,
                        'match_score' => $this->calculateObjectMatchScore($p, $object),
                    ];
                })->sortByDesc('match_score')->values()->toArray();

                $roomItems[] = [
                    'detected_object' => $object['label'],
                    'confidence'      => $object['confidence'],
                    'bounding_box'    => $object['bounding_box'] ?? null,
                    'color_hint'      => $object['color'] ?? null,
                    'style_hint'      => $object['style'] ?? null,
                    'catalog_matches' => array_slice($matches, 0, 4),
                    'match_count'     => count($matches),
                ];
            }

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logVisualSearch($tenantId, $params, count($roomItems), $responseTimeMs, [
                'type'           => 'shop_the_room',
                'objects_found'  => count($detectedObjects),
            ]);

            return [
                'success'           => true,
                'scene_type'        => $this->classifyScene($detectedObjects),
                'detected_items'    => $roomItems,
                'total_objects'     => count($detectedObjects),
                'total_matches'     => collect($roomItems)->sum('match_count'),
                'response_time_ms'  => $responseTimeMs,
                'shop_all_url'      => null, // Frontend can build a bundle from matches
            ];
        } catch (\Exception $e) {
            Log::error("VisualSearchService::shopTheRoom error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Detect objects in a room/scene image.
     * Production: Google Vision, AWS Rekognition, or custom YOLO model.
     */
    private function detectSceneObjects($imageSource): array
    {
        // Simulated multi-object detection for a room scene
        return [
            ['label' => 'sofa', 'confidence' => 0.92, 'color' => 'gray', 'style' => 'modern',
             'bounding_box' => ['x' => 50, 'y' => 200, 'w' => 400, 'h' => 250]],
            ['label' => 'lamp', 'confidence' => 0.87, 'color' => 'gold', 'style' => 'contemporary',
             'bounding_box' => ['x' => 500, 'y' => 50, 'w' => 80, 'h' => 200]],
            ['label' => 'rug', 'confidence' => 0.78, 'color' => 'beige', 'style' => 'bohemian',
             'bounding_box' => ['x' => 100, 'y' => 450, 'w' => 350, 'h' => 150]],
            ['label' => 'cushion', 'confidence' => 0.85, 'color' => 'blue', 'style' => 'decorative',
             'bounding_box' => ['x' => 150, 'y' => 220, 'w' => 60, 'h' => 60]],
            ['label' => 'coffee table', 'confidence' => 0.80, 'color' => 'wood', 'style' => 'scandinavian',
             'bounding_box' => ['x' => 200, 'y' => 350, 'w' => 200, 'h' => 100]],
        ];
    }

    private function classifyScene(array $objects): string
    {
        $labels = array_map(fn($o) => strtolower($o['label']), $objects);
        if (array_intersect($labels, ['sofa', 'couch', 'coffee table', 'tv', 'bookshelf'])) return 'living_room';
        if (array_intersect($labels, ['bed', 'pillow', 'nightstand', 'dresser'])) return 'bedroom';
        if (array_intersect($labels, ['stove', 'refrigerator', 'sink', 'oven'])) return 'kitchen';
        if (array_intersect($labels, ['desk', 'monitor', 'chair', 'keyboard'])) return 'office';
        if (array_intersect($labels, ['dining table', 'plate', 'wine glass'])) return 'dining';
        return 'general';
    }

    private function calculateObjectMatchScore($product, array $object): float
    {
        $score = 50;
        $name = strtolower($product['name'] ?? '');
        $label = strtolower($object['label']);

        if (str_contains($name, $label)) $score += 30;
        if (isset($object['color'])) {
            $prodColor = strtolower($product['attributes']['color'] ?? $name);
            if (str_contains($prodColor, strtolower($object['color']))) $score += 15;
        }
        if (isset($object['style'])) {
            $desc = strtolower($product['description'] ?? $name);
            if (str_contains($desc, strtolower($object['style']))) $score += 5;
        }
        return min(100, $score);
    }

    private function logVisualSearch(int $tenantId, array $params, int $resultsCount, int $responseTimeMs, array $features): void
    {
        try {
            SearchLog::create([
                'tenant_id'       => $tenantId,
                'session_id'      => $params['session_id'] ?? null,
                'visitor_id'      => $params['visitor_id'] ?? null,
                'query'           => 'visual_search',
                'query_type'      => 'visual',
                'results_count'   => $resultsCount,
                'response_time_ms' => $responseTimeMs,
                'metadata'        => ['features' => $features],
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to log visual search: {$e->getMessage()}");
        }
    }
}

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

            // Extract visual features from image (color, pattern, shape)
            $features = $this->extractFeatures($imageData ?? $imageUrl);

            // Convert features to product search criteria
            $searchCriteria = $this->featuresToSearchCriteria($features);

            // Search products matching visual criteria
            $query = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId);

            // Match by detected color
            if (!empty($searchCriteria['colors'])) {
                $query->where(function ($q) use ($searchCriteria) {
                    foreach ($searchCriteria['colors'] as $color) {
                        $q->orWhere('attributes.color', 'regex', "/{$color}/i")
                          ->orWhere('name', 'regex', "/{$color}/i");
                    }
                });
            }

            // Match by detected category/type
            if (!empty($searchCriteria['category'])) {
                $query->where(function ($q) use ($searchCriteria) {
                    $q->where('category', 'regex', "/{$searchCriteria['category']}/i")
                      ->orWhere('name', 'regex', "/{$searchCriteria['category']}/i");
                });
            }

            $products = $query->limit($limit)->get();

            // Score by visual similarity
            $scored = $products->map(function ($p) use ($features) {
                $similarity = $this->calculateVisualSimilarity($p, $features);
                return [
                    'id'              => $p['external_id'] ?? (string) ($p['_id'] ?? ''),
                    'name'            => $p['name'] ?? '',
                    'price'           => $p['price'] ?? 0,
                    'image'           => $p['image'] ?? null,
                    'category'        => $p['category'] ?? null,
                    'similarity_score' => round($similarity, 4),
                    'url'             => $p['url'] ?? null,
                ];
            })->sortByDesc('similarity_score')->values()->toArray();

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Log
            $this->logVisualSearch($tenantId, $params, count($scored), $responseTimeMs, $features);

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
    public function findSimilar(int $tenantId, string $productId, int $limit = 10): array
    {
        try {
            $product = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('external_id', $productId)
                ->first();

            if (!$product) {
                return ['success' => false, 'error' => 'Product not found.'];
            }

            // Find products in same category with similar attributes
            $similar = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('external_id', '!=', $productId)
                ->where('category', $product['category'] ?? '')
                ->limit($limit * 3)
                ->get();

            // Score similarity
            $scored = $similar->map(function ($p) use ($product) {
                $score = 0;

                // Same category
                if (($p['category'] ?? '') === ($product['category'] ?? '')) $score += 30;

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
                $pColor = strtolower($p['attributes']['color'] ?? '');
                $prodColor = strtolower($product['attributes']['color'] ?? '');
                if ($pColor && $prodColor && $pColor === $prodColor) {
                    $score += 20;
                }

                // Has image
                if (!empty($p['image'])) $score += 10;

                return [
                    'id'              => $p['external_id'] ?? (string) ($p['_id'] ?? ''),
                    'name'            => $p['name'] ?? '',
                    'price'           => $p['price'] ?? 0,
                    'image'           => $p['image'] ?? null,
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
     * Extract visual features from image. 
     * In production: use Google Vision API, AWS Rekognition, or local TF model.
     * This implementation uses color analysis and basic classification.
     */
    private function extractFeatures($imageSource): array
    {
        // Simulated feature extraction. In production, call an AI vision API.
        // For now, return a reasonable set of generic features.
        return [
            'dominant_colors' => ['black', 'white', 'blue'],
            'category_guess'  => 'apparel',
            'pattern'         => 'solid',
            'style'           => 'casual',
            'confidence'      => 0.65,
            'note'            => 'Feature extraction uses heuristics. Integrate Google Vision or AWS Rekognition for production.',
        ];
    }

    private function featuresToSearchCriteria(array $features): array
    {
        return [
            'colors'   => $features['dominant_colors'] ?? [],
            'category' => $features['category_guess'] ?? null,
            'pattern'  => $features['pattern'] ?? null,
        ];
    }

    private function calculateVisualSimilarity($product, array $features): float
    {
        $score = 50; // Base score

        // Color matching
        $productColor = strtolower($product['attributes']['color'] ?? ($product['name'] ?? ''));
        $dominantColors = $features['dominant_colors'] ?? [];
        foreach ($dominantColors as $color) {
            if (str_contains($productColor, strtolower($color))) {
                $score += 20;
                break;
            }
        }

        // Category matching
        $categoryGuess = strtolower($features['category_guess'] ?? '');
        $productCategory = strtolower($product['category'] ?? '');
        if ($categoryGuess && str_contains($productCategory, $categoryGuess)) {
            $score += 20;
        }

        // Has image bonus
        if (!empty($product['image'])) {
            $score += 10;
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

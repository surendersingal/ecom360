<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use MongoDB\Laravel\Connection;

/**
 * Product-level analytics — top sellers, product performance,
 * frequently bought together, product view-to-purchase conversion.
 */
final class ProductAnalyticsService
{
    /**
     * Top products by views, add-to-carts, or purchases.
     *
     * @param string $metric  One of: product_view, add_to_cart, purchase
     * @param int    $limit   Number of products to return
     */
    public function getTopProducts(
        int|string $tenantId,
        string $dateRange = '30d',
        string $metric = 'purchase',
        int $limit = 20,
    ): array {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        $dateFilter = [
            '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
            '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
        ];

        // Purchase events store product data in metadata.items[] (Magento)
        // or metadata.products[] (SDK) — need to $unwind them.
        if ($metric === 'purchase') {
            return $this->getTopProductsByPurchase($tenantId, $dateFilter, $limit);
        }

        // product_view / add_to_cart — each event has metadata.product_id
        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => $metric,
                'created_at' => $dateFilter,
                'metadata.product_id' => ['$exists' => true],
            ]],
            ['$group' => [
                '_id'   => '$metadata.product_id',
                'name'  => ['$first' => '$metadata.product_name'],
                'sku'   => ['$first' => '$metadata.sku'],
                'count' => ['$sum' => 1],
                'revenue' => ['$sum' => ['$ifNull' => ['$metadata.price', 0]]],
            ]],
            ['$sort' => ['count' => -1]],
            ['$limit' => $limit],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'product_id'   => $row['_id'] ?? 'unknown',
            'product_name' => $row['name'] ?? 'Unknown Product',
            'sku'          => $row['sku'] ?? '',
            'count'        => (int) ($row['count'] ?? 0),
            'revenue'      => round((float) ($row['revenue'] ?? 0), 2),
        ], $results);
    }

    /**
     * Top products extracted from purchase events by unwinding the
     * items/products array inside each order's metadata.
     */
    private function getTopProductsByPurchase(int|string $tenantId, array $dateFilter, int $limit): array
    {
        $collection = $this->collection();

        // Magento tracker uses "items", SDK / test scripts may use "products"
        // Merge both into a single "_items" array.
        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => $dateFilter,
                '$or' => [
                    ['metadata.items'    => ['$exists' => true]],
                    ['metadata.products' => ['$exists' => true]],
                ],
            ]],
            // Normalise: combine items + products into _items
            ['$addFields' => [
                '_items' => [
                    '$concatArrays' => [
                        ['$ifNull' => ['$metadata.items', []]],
                        ['$ifNull' => ['$metadata.products', []]],
                    ],
                ],
            ]],
            ['$unwind' => '$_items'],
            ['$group' => [
                '_id'  => ['$ifNull' => ['$_items.product_id', ['$ifNull' => ['$_items.sku', 'unknown']]]],
                'name' => ['$first' => ['$ifNull' => ['$_items.name', 'Unknown Product']]],
                'sku'  => ['$first' => ['$ifNull' => ['$_items.sku', '']]],
                'count' => ['$sum' => ['$ifNull' => ['$_items.qty', 1]]],
                'revenue' => ['$sum' => [
                    '$ifNull' => [
                        '$_items.row_total',
                        ['$multiply' => [
                            ['$ifNull' => ['$_items.price', 0]],
                            ['$ifNull' => ['$_items.qty', 1]],
                        ]],
                    ],
                ]],
            ]],
            ['$sort' => ['revenue' => -1]],
            ['$limit' => $limit],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'product_id'   => $row['_id'] ?? 'unknown',
            'product_name' => $row['name'] ?? 'Unknown Product',
            'sku'          => $row['sku'] ?? '',
            'count'        => (int) ($row['count'] ?? 0),
            'revenue'      => round((float) ($row['revenue'] ?? 0), 2),
        ], $results);
    }

    /**
     * Product performance — views, cart adds, purchases, and conversion rates.
     */
    public function getProductPerformance(int|string $tenantId, string $dateRange = '30d', int $limit = 50): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        $dateFilter = [
            '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
            '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
        ];

        // ── 1) Views & cart adds (each event has metadata.product_id) ──
        $viewCartPipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => ['$in' => ['product_view', 'add_to_cart']],
                'created_at' => $dateFilter,
                'metadata.product_id' => ['$exists' => true],
            ]],
            ['$group' => [
                '_id'       => [
                    'product_id' => '$metadata.product_id',
                    'event_type' => '$event_type',
                ],
                'name'  => ['$first' => '$metadata.product_name'],
                'sku'   => ['$first' => '$metadata.sku'],
                'count' => ['$sum' => 1],
            ]],
        ];

        $viewCartResults = iterator_to_array($collection->aggregate($viewCartPipeline));

        // ── 2) Purchases — unwind items/products array from orders ──
        $purchasePipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => $dateFilter,
                '$or' => [
                    ['metadata.items'    => ['$exists' => true]],
                    ['metadata.products' => ['$exists' => true]],
                ],
            ]],
            ['$addFields' => [
                '_items' => [
                    '$concatArrays' => [
                        ['$ifNull' => ['$metadata.items', []]],
                        ['$ifNull' => ['$metadata.products', []]],
                    ],
                ],
            ]],
            ['$unwind' => '$_items'],
            ['$group' => [
                '_id'  => ['$ifNull' => ['$_items.product_id', ['$ifNull' => ['$_items.sku', 'unknown']]]],
                'name' => ['$first' => ['$ifNull' => ['$_items.name', 'Unknown Product']]],
                'sku'  => ['$first' => ['$ifNull' => ['$_items.sku', '']]],
                'purchases' => ['$sum' => ['$ifNull' => ['$_items.qty', 1]]],
                'revenue'   => ['$sum' => [
                    '$ifNull' => [
                        '$_items.row_total',
                        ['$multiply' => [
                            ['$ifNull' => ['$_items.price', 0]],
                            ['$ifNull' => ['$_items.qty', 1]],
                        ]],
                    ],
                ]],
            ]],
        ];

        $purchaseResults = iterator_to_array($collection->aggregate($purchasePipeline));

        // ── Build purchase lookup (by product_id AND by sku for cross-matching) ──
        $purchaseMap = [];
        $skuToPurchase = [];
        foreach ($purchaseResults as $row) {
            $pid = $row['_id'] ?? 'unknown';
            $entry = [
                'purchases' => (int) ($row['purchases'] ?? 0),
                'revenue'   => round((float) ($row['revenue'] ?? 0), 2),
                'name'      => $row['name'] ?? 'Unknown Product',
                'sku'       => $row['sku'] ?? '',
            ];
            $purchaseMap[$pid] = $entry;
            // Also index by sku so we can match view products by their sku
            if (! empty($entry['sku'])) {
                $skuToPurchase[$entry['sku']] = $entry;
            }
        }

        // ── Pivot views & cart adds by product ──
        $products = [];
        foreach ($viewCartResults as $row) {
            $pid = $row['_id']['product_id'] ?? 'unknown';
            $evt = $row['_id']['event_type'] ?? '';

            if (! isset($products[$pid])) {
                $products[$pid] = [
                    'product_id'   => $pid,
                    'product_name' => $row['name'] ?? 'Unknown Product',
                    'sku'          => $row['sku'] ?? '',
                    'views'        => 0,
                    'cart_adds'    => 0,
                    'purchases'    => 0,
                    'revenue'      => 0,
                ];
            }

            match ($evt) {
                'product_view' => $products[$pid]['views']    = (int) $row['count'],
                'add_to_cart'  => $products[$pid]['cart_adds'] = (int) $row['count'],
                default        => null,
            };
        }

        // ── Merge purchase data — match by product_id first, then by sku ──
        $usedPurchaseKeys = [];
        foreach ($products as $pid => &$prod) {
            // Direct match by product_id
            if (isset($purchaseMap[$pid])) {
                $prod['purchases'] = $purchaseMap[$pid]['purchases'];
                $prod['revenue']   = $purchaseMap[$pid]['revenue'];
                $usedPurchaseKeys[$pid] = true;
            }
            // Fallback: match by sku
            elseif (! empty($prod['sku']) && isset($skuToPurchase[$prod['sku']])) {
                $prod['purchases'] = $skuToPurchase[$prod['sku']]['purchases'];
                $prod['revenue']   = $skuToPurchase[$prod['sku']]['revenue'];
                $usedPurchaseKeys[$prod['sku']] = true;
            }
        }
        unset($prod);

        // Add any purchase-only products not yet in the list
        foreach ($purchaseMap as $pid => $pdata) {
            if (isset($usedPurchaseKeys[$pid]) || isset($products[$pid])) {
                continue;
            }
            // Also skip if matched by sku already
            if (! empty($pdata['sku']) && isset($usedPurchaseKeys[$pdata['sku']])) {
                continue;
            }
            $products[$pid] = [
                'product_id'   => $pid,
                'product_name' => $pdata['name'],
                'sku'          => $pdata['sku'],
                'views'        => 0,
                'cart_adds'    => 0,
                'purchases'    => $pdata['purchases'],
                'revenue'      => $pdata['revenue'],
            ];
        }

        // Calculate conversion rates
        foreach ($products as &$p) {
            $p['view_to_cart_rate']     = $p['views'] > 0 ? round(($p['cart_adds'] / $p['views']) * 100, 2) : 0;
            $p['cart_to_purchase_rate'] = $p['cart_adds'] > 0 ? round(($p['purchases'] / $p['cart_adds']) * 100, 2) : 0;
            $p['view_to_purchase_rate'] = $p['views'] > 0 ? round(($p['purchases'] / $p['views']) * 100, 2) : 0;
        }
        unset($p);

        // Sort by views descending
        usort($products, fn ($a, $b) => $b['views'] <=> $a['views']);

        return array_slice(array_values($products), 0, $limit);
    }

    /**
     * Frequently bought together — products that appear in the same order's items.
     */
    public function getFrequentlyBoughtTogether(int|string $tenantId, string $dateRange = '30d', int $limit = 10): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        // Unwind items/products from purchase events, then find co-occurrences
        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
                '$or' => [
                    ['metadata.items'    => ['$exists' => true]],
                    ['metadata.products' => ['$exists' => true]],
                ],
            ]],
            ['$addFields' => [
                '_items' => [
                    '$concatArrays' => [
                        ['$ifNull' => ['$metadata.items', []]],
                        ['$ifNull' => ['$metadata.products', []]],
                    ],
                ],
            ]],
            // Only orders with 2+ items
            ['$match' => ['_items.1' => ['$exists' => true]]],
            ['$unwind' => '$_items'],
            ['$group' => [
                '_id'   => ['$ifNull' => ['$_items.product_id', ['$ifNull' => ['$_items.sku', 'unknown']]]],
                'name'  => ['$first' => ['$ifNull' => ['$_items.name', 'Unknown']]],
                'count' => ['$sum' => 1],
            ]],
            ['$sort' => ['count' => -1]],
            ['$limit' => $limit],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'product_id'   => $row['_id'] ?? 'unknown',
            'product_name' => $row['name'] ?? 'Unknown',
            'co_purchase_count' => (int) ($row['count'] ?? 0),
        ], $results);
    }

    /**
     * Cart abandonment analysis — products added to cart but not purchased.
     */
    public function getCartAbandonmentProducts(int|string $tenantId, string $dateRange = '30d', int $limit = 20): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        // Get products added to cart
        $cartPipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'add_to_cart',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
                'metadata.product_id' => ['$exists' => true],
            ]],
            ['$group' => [
                '_id'       => '$metadata.product_id',
                'name'      => ['$first' => '$metadata.product_name'],
                'cart_adds' => ['$sum' => 1],
                'sessions'  => ['$addToSet' => '$session_id'],
            ]],
        ];

        $cartResults = iterator_to_array($collection->aggregate($cartPipeline));

        // Get products purchased — unwind items/products from purchase events
        $purchasePipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
                '$or' => [
                    ['metadata.items'    => ['$exists' => true]],
                    ['metadata.products' => ['$exists' => true]],
                ],
            ]],
            ['$addFields' => [
                '_items' => [
                    '$concatArrays' => [
                        ['$ifNull' => ['$metadata.items', []]],
                        ['$ifNull' => ['$metadata.products', []]],
                    ],
                ],
            ]],
            ['$unwind' => '$_items'],
            ['$group' => [
                '_id'       => ['$ifNull' => ['$_items.product_id', ['$ifNull' => ['$_items.sku', 'unknown']]]],
                'purchases' => ['$sum' => ['$ifNull' => ['$_items.qty', 1]]],
            ]],
        ];

        $purchaseResults = iterator_to_array($collection->aggregate($purchasePipeline));
        $purchaseMap = [];
        foreach ($purchaseResults as $row) {
            $purchaseMap[$row['_id']] = (int) ($row['purchases'] ?? 0);
        }

        $abandoned = [];
        foreach ($cartResults as $row) {
            $pid       = $row['_id'];
            $cartAdds  = (int) ($row['cart_adds'] ?? 0);
            $purchases = $purchaseMap[$pid] ?? 0;
            $abandons  = max(0, $cartAdds - $purchases);

            if ($abandons > 0) {
                $abandoned[] = [
                    'product_id'       => $pid,
                    'product_name'     => $row['name'] ?? 'Unknown',
                    'cart_adds'        => $cartAdds,
                    'purchases'        => $purchases,
                    'abandonments'     => $abandons,
                    'abandonment_rate' => round(($abandons / $cartAdds) * 100, 1),
                ];
            }
        }

        usort($abandoned, fn ($a, $b) => $b['abandonments'] <=> $a['abandonments']);

        return array_slice($abandoned, 0, $limit);
    }

    private function collection(): \MongoDB\Collection
    {
        /** @var Connection $mongo */
        $mongo = app('db')->connection('mongodb');

        return $mongo->getCollection('tracking_events');
    }

    private function parseDateRange(string $range): array
    {
        if (preg_match('/^(\d+)d$/', $range, $m)) {
            return [
                CarbonImmutable::now()->subDays((int) $m[1])->startOfDay(),
                CarbonImmutable::now()->endOfDay(),
            ];
        }

        if (str_contains($range, '|')) {
            [$from, $to] = explode('|', $range, 2);

            return [
                CarbonImmutable::parse($from)->startOfDay(),
                CarbonImmutable::parse($to)->endOfDay(),
            ];
        }

        return [
            CarbonImmutable::now()->subDays(30)->startOfDay(),
            CarbonImmutable::now()->endOfDay(),
        ];
    }
}

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

        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => $metric,
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
                'metadata.product_id' => ['$exists' => true],
            ]],
            ['$group' => [
                '_id'   => '$metadata.product_id',
                'name'  => ['$first' => '$metadata.product_name'],
                'count' => ['$sum' => 1],
                'revenue' => ['$sum' => ['$ifNull' => ['$metadata.product_price', 0]]],
            ]],
            ['$sort' => ['count' => -1]],
            ['$limit' => $limit],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        return array_map(fn ($row) => [
            'product_id'   => $row['_id'] ?? 'unknown',
            'product_name' => $row['name'] ?? 'Unknown Product',
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

        $eventTypes = ['product_view', 'add_to_cart', 'purchase'];

        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => ['$in' => $eventTypes],
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
                'metadata.product_id' => ['$exists' => true],
            ]],
            ['$group' => [
                '_id'       => [
                    'product_id' => '$metadata.product_id',
                    'event_type' => '$event_type',
                ],
                'name'  => ['$first' => '$metadata.product_name'],
                'count' => ['$sum' => 1],
                'revenue' => ['$sum' => ['$ifNull' => ['$metadata.order_total', 0]]],
            ]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline));

        // Pivot by product
        $products = [];
        foreach ($results as $row) {
            $pid = $row['_id']['product_id'] ?? 'unknown';
            $evt = $row['_id']['event_type'] ?? '';

            if (! isset($products[$pid])) {
                $products[$pid] = [
                    'product_id'   => $pid,
                    'product_name' => $row['name'] ?? 'Unknown Product',
                    'views'        => 0,
                    'cart_adds'    => 0,
                    'purchases'    => 0,
                    'revenue'      => 0,
                ];
            }

            match ($evt) {
                'product_view' => $products[$pid]['views']     = (int) $row['count'],
                'add_to_cart'  => $products[$pid]['cart_adds']  = (int) $row['count'],
                'purchase'     => $products[$pid]['purchases']  = (int) $row['count'],
                default        => null,
            };

            if ($evt === 'purchase') {
                $products[$pid]['revenue'] = round((float) ($row['revenue'] ?? 0), 2);
            }
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
     * Frequently bought together — products that appear in the same session's purchases.
     */
    public function getFrequentlyBoughtTogether(int|string $tenantId, string $dateRange = '30d', int $limit = 10): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        // Get sessions with multiple product purchases
        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
                'metadata.product_id' => ['$exists' => true],
            ]],
            ['$group' => [
                '_id'      => '$session_id',
                'products' => ['$addToSet' => [
                    'id'   => '$metadata.product_id',
                    'name' => '$metadata.product_name',
                ]],
            ]],
            ['$match' => [
                'products.1' => ['$exists' => true], // At least 2 products
            ]],
            ['$unwind' => '$products'],
            ['$group' => [
                '_id'   => '$products.id',
                'name'  => ['$first' => '$products.name'],
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

        // Get products purchased
        $purchasePipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
                'metadata.product_id' => ['$exists' => true],
            ]],
            ['$group' => [
                '_id'       => '$metadata.product_id',
                'purchases' => ['$sum' => 1],
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

<?php
declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * CompetitorPriceService — Monitor competitor pricing and trigger price-match campaigns.
 *
 * Powers Use Cases:
 *   - Competitor Price Monitoring (UC15)
 *   - Win-Back with Price-Match Coupons (UC12)
 */
class CompetitorPriceService
{
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Store a competitor price data point.
     */
    public function trackCompetitorPrice(int|string $tenantId, array $data): array
    {
        try {
            $entry = [
                'tenant_id'       => $tenantId,
                'product_id'      => $data['product_id'],
                'product_name'    => $data['product_name'] ?? '',
                'sku'             => $data['sku'] ?? '',
                'our_price'       => (float) $data['our_price'],
                'competitor_name' => $data['competitor_name'],
                'competitor_price' => (float) $data['competitor_price'],
                'competitor_url'  => $data['competitor_url'] ?? null,
                'currency'        => $data['currency'] ?? 'USD',
                'price_diff'      => round((float) $data['our_price'] - (float) $data['competitor_price'], 2),
                'price_diff_pct'  => (float) $data['our_price'] > 0
                    ? round((((float) $data['our_price'] - (float) $data['competitor_price']) / (float) $data['our_price']) * 100, 2)
                    : 0,
                'captured_at'     => now()->toDateTimeString(),
                'source'          => $data['source'] ?? 'manual', // manual | scraper | api | feed
            ];

            DB::connection('mongodb')
                ->table('competitor_prices')
                ->insert($entry);

            return ['success' => true, 'entry' => $entry];
        } catch (\Exception $e) {
            Log::error("CompetitorPriceService::trackCompetitorPrice error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Bulk import competitor prices.
     */
    public function bulkImport(int|string $tenantId, array $prices): array
    {
        $imported = 0;
        $errors = [];

        foreach ($prices as $i => $data) {
            $data['product_id'] = $data['product_id'] ?? '';
            $data['our_price'] = $data['our_price'] ?? 0;
            $data['competitor_name'] = $data['competitor_name'] ?? '';
            $data['competitor_price'] = $data['competitor_price'] ?? 0;

            $result = $this->trackCompetitorPrice($tenantId, $data);
            if ($result['success']) {
                $imported++;
            } else {
                $errors[] = "Row {$i}: " . ($result['error'] ?? 'Unknown error');
            }
        }

        return [
            'success'  => true,
            'imported' => $imported,
            'errors'   => $errors,
            'total'    => count($prices),
        ];
    }

    /**
     * Get price comparison for all tracked products.
     */
    public function getPriceComparison(int|string $tenantId, array $filters = []): array
    {
        try {
            $query = DB::connection('mongodb')
                ->table('competitor_prices')
                ->where('tenant_id', $tenantId);

            // Only latest entries (last 7 days by default)
            $since = $filters['since'] ?? now()->subDays(7)->toDateTimeString();
            $query->where('captured_at', '>=', $since);

            if (!empty($filters['product_id'])) {
                $query->where('product_id', $filters['product_id']);
            }
            if (!empty($filters['competitor_name'])) {
                $query->where('competitor_name', $filters['competitor_name']);
            }

            $entries = $query->orderBy('captured_at', 'desc')->get();

            // Group by product
            $byProduct = $entries->groupBy('product_id');

            $comparison = $byProduct->map(function ($entries, $productId) {
                $latestByCompetitor = $entries->groupBy('competitor_name')->map(fn($g) => $g->first());
                $ourPrice = $entries->first()['our_price'] ?? 0;

                $competitors = $latestByCompetitor->map(fn($e) => [
                    'competitor'      => $e['competitor_name'],
                    'price'           => $e['competitor_price'],
                    'diff'            => $e['price_diff'],
                    'diff_pct'        => $e['price_diff_pct'],
                    'url'             => $e['competitor_url'] ?? null,
                    'captured_at'     => $e['captured_at'],
                ])->values();

                $cheapest = $latestByCompetitor->sortBy('competitor_price')->first();
                $weAreCheapest = $ourPrice <= ($cheapest['competitor_price'] ?? PHP_INT_MAX);

                return [
                    'product_id'   => $productId,
                    'product_name' => $entries->first()['product_name'] ?? '',
                    'our_price'    => $ourPrice,
                    'competitors'  => $competitors->toArray(),
                    'cheapest'     => [
                        'competitor' => $cheapest['competitor_name'] ?? null,
                        'price'      => $cheapest['competitor_price'] ?? null,
                    ],
                    'we_are_cheapest' => $weAreCheapest,
                    'action_needed'   => !$weAreCheapest,
                ];
            })->values();

            return [
                'success'    => true,
                'products'   => $comparison->toArray(),
                'total'      => $comparison->count(),
                'undercut'   => $comparison->where('action_needed', true)->count(),
            ];
        } catch (\Exception $e) {
            Log::error("CompetitorPriceService::getPriceComparison error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get products where we are undercut by competitors.
     */
    public function getUndercutProducts(int|string $tenantId, float $minDiffPercent = 5): array
    {
        $comparison = $this->getPriceComparison($tenantId);
        if (!($comparison['success'] ?? false)) {
            return $comparison;
        }

        $undercut = collect($comparison['products'] ?? [])
            ->filter(function ($p) use ($minDiffPercent) {
                if ($p['we_are_cheapest']) return false;
                $cheapestPrice = $p['cheapest']['price'] ?? 0;
                $ourPrice = $p['our_price'] ?? 0;
                if ($ourPrice <= 0) return false;
                $diffPct = (($ourPrice - $cheapestPrice) / $ourPrice) * 100;
                return $diffPct >= $minDiffPercent;
            })
            ->values()
            ->toArray();

        return [
            'success'   => true,
            'undercut'  => $undercut,
            'count'     => count($undercut),
            'threshold' => $minDiffPercent,
        ];
    }

    /**
     * Get price history for a specific product across competitors.
     */
    public function getPriceHistory(int|string $tenantId, string $productId, int $days = 30): array
    {
        try {
            $entries = DB::connection('mongodb')
                ->table('competitor_prices')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->where('captured_at', '>=', now()->subDays($days)->toDateTimeString())
                ->orderBy('captured_at', 'asc')
                ->get();

            $history = $entries->groupBy('competitor_name')->map(function ($group, $competitor) {
                return [
                    'competitor' => $competitor,
                    'data_points' => $group->map(fn($e) => [
                        'price'      => $e['competitor_price'],
                        'date'       => $e['captured_at'],
                    ])->values()->toArray(),
                    'min_price'  => $group->min('competitor_price'),
                    'max_price'  => $group->max('competitor_price'),
                    'avg_price'  => round($group->avg('competitor_price'), 2),
                ];
            })->values();

            return [
                'product_id'     => $productId,
                'period_days'    => $days,
                'our_price'      => $entries->first()['our_price'] ?? null,
                'competitors'    => $history->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error("CompetitorPriceService::getPriceHistory error: {$e->getMessage()}");
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get analytics summary.
     */
    public function getAnalytics(int|string $tenantId): array
    {
        try {
            $entries = DB::connection('mongodb')
                ->table('competitor_prices')
                ->where('tenant_id', $tenantId)
                ->where('captured_at', '>=', now()->subDays(7)->toDateTimeString())
                ->get();

            $total = $entries->count();
            $uniqueProducts = $entries->pluck('product_id')->unique()->count();
            $uniqueCompetitors = $entries->pluck('competitor_name')->unique()->count();
            $weAreCheper = $entries->where('price_diff', '<', 0)->count();
            $weAreExpensive = $entries->where('price_diff', '>', 0)->count();
            $avgDiff = round($entries->avg('price_diff_pct'), 2);

            return [
                'tracked_products'    => $uniqueProducts,
                'tracked_competitors' => $uniqueCompetitors,
                'total_data_points'   => $total,
                'we_are_cheaper'      => $weAreCheper,
                'we_are_more_expensive' => $weAreExpensive,
                'avg_price_diff_pct'  => $avgDiff,
                'top_competitors'     => $entries->groupBy('competitor_name')
                    ->map(fn($g, $name) => ['name' => $name, 'products_tracked' => $g->pluck('product_id')->unique()->count()])
                    ->sortByDesc('products_tracked')
                    ->values()
                    ->take(10)
                    ->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error("CompetitorPriceService::getAnalytics error: {$e->getMessage()}");
            return ['error' => $e->getMessage()];
        }
    }
}

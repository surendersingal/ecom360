<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Analytics Engine — Automated insights, anomaly detection,
 * predictive analytics, and intelligent recommendations.
 *
 * This service analyzes tracking data to surface actionable insights
 * that go beyond basic analytics (Google Analytics level).
 *
 * Features:
 * - Anomaly detection (traffic spikes/drops, conversion rate changes)
 * - Predictive revenue forecasting
 * - Automated insight generation
 * - Churn risk scoring
 * - Purchase propensity modeling
 * - Drop-off point identification
 * - Optimal timing analysis
 */
final class AiAnalyticsService
{
    private const INSIGHTS_CACHE_TTL = 900; // 15 minutes

    // ------------------------------------------------------------------
    //  Anomaly Detection
    // ------------------------------------------------------------------

    /**
     * Detect anomalies by comparing current period to historical averages.
     *
     * Uses a simple z-score approach: if today's metric deviates more than
     * 2 standard deviations from the 30-day moving average, flag it.
     *
     * @return Collection<int, array{metric: string, current: float, average: float, deviation: float, type: string, severity: string, message: string}>
     */
    public function detectAnomalies(int|string $tenantId): Collection
    {
        $cacheKey = "ai_anomalies:{$tenantId}";

        return Cache::store('redis')->remember($cacheKey, self::INSIGHTS_CACHE_TTL, function () use ($tenantId) {
            return $this->computeAnomalies($tenantId);
        });
    }

    private function computeAnomalies(int|string $tenantId): Collection
    {
        $mongo = DB::connection('mongodb');
        $now = CarbonImmutable::now();
        $anomalies = collect();

        // Get daily event counts for last 30 days
        $dailyCounts = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays(31)->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'   => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at']],
                        'count' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['_id' => 1]],
                ])->toArray();
            });

        $counts = collect($dailyCounts)->pluck('count')->toArray();

        if (count($counts) >= 7) {
            $today = end($counts);
            $historical = array_slice($counts, 0, -1);
            $mean = array_sum($historical) / count($historical);
            $stdDev = $this->standardDeviation($historical);

            if ($stdDev > 0) {
                $zScore = ($today - $mean) / $stdDev;

                if (abs($zScore) > 2.0) {
                    $type = $zScore > 0 ? 'spike' : 'drop';
                    $severity = abs($zScore) > 3.0 ? 'critical' : 'warning';
                    $pctChange = $mean > 0 ? round((($today - $mean) / $mean) * 100, 1) : 0;

                    $anomalies->push([
                        'metric'    => 'Daily Traffic',
                        'current'   => (float) $today,
                        'average'   => round($mean, 1),
                        'deviation' => round($zScore, 2),
                        'type'      => $type,
                        'severity'  => $severity,
                        'message'   => $type === 'spike'
                            ? "Traffic surged {$pctChange}% above the 30-day average"
                            : "Traffic dropped {$pctChange}% below the 30-day average",
                    ]);
                }
            }
        }

        // Check conversion rate anomaly
        $conversionByDay = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'event_type' => ['$in' => ['page_view', 'purchase']],
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays(14)->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'       => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at']],
                        'views'     => ['$sum' => ['$cond' => [['$eq' => ['$event_type', 'page_view']], 1, 0]]],
                        'purchases' => ['$sum' => ['$cond' => [['$eq' => ['$event_type', 'purchase']], 1, 0]]],
                    ]],
                    ['$sort' => ['_id' => 1]],
                ])->toArray();
            });

        $convRates = collect($conversionByDay)->map(function ($day) {
            return $day['views'] > 0 ? ($day['purchases'] / $day['views']) * 100 : 0;
        })->toArray();

        if (count($convRates) >= 7) {
            $todayRate = end($convRates);
            $histRates = array_slice($convRates, 0, -1);
            $meanRate = array_sum($histRates) / count($histRates);
            $stdRate = $this->standardDeviation($histRates);

            if ($stdRate > 0 && $meanRate > 0) {
                $rateZ = ($todayRate - $meanRate) / $stdRate;

                if (abs($rateZ) > 1.5) {
                    $pctChange = round((($todayRate - $meanRate) / $meanRate) * 100, 1);
                    $anomalies->push([
                        'metric'    => 'Conversion Rate',
                        'current'   => round($todayRate, 2),
                        'average'   => round($meanRate, 2),
                        'deviation' => round($rateZ, 2),
                        'type'      => $rateZ > 0 ? 'improvement' : 'decline',
                        'severity'  => abs($rateZ) > 2.5 ? 'critical' : 'warning',
                        'message'   => $rateZ > 0
                            ? "Conversion rate improved {$pctChange}% vs average"
                            : "Conversion rate declined {$pctChange}% vs average",
                    ]);
                }
            }
        }

        return $anomalies;
    }

    // ------------------------------------------------------------------
    //  Predictive Revenue Forecast
    // ------------------------------------------------------------------

    /**
     * Simple linear regression to forecast next 7 days of revenue.
     *
     * @return array{historical: array, forecast: array, trend: string, confidence: float}
     */
    public function forecastRevenue(int|string $tenantId, int $daysHistory = 30, int $daysForecast = 7): array
    {
        $mongo = DB::connection('mongodb');
        $now = CarbonImmutable::now();

        $dailyRevenue = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now, $daysHistory) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'event_type' => 'purchase',
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays($daysHistory)->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'     => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at']],
                        'revenue' => ['$sum' => ['$ifNull' => ['$metadata.order_total', 0]]],
                        'orders'  => ['$sum' => 1],
                    ]],
                    ['$sort' => ['_id' => 1]],
                ])->toArray();
            });

        $historical = collect($dailyRevenue)->map(fn ($d) => [
            'date'    => $d['_id'],
            'revenue' => (float) $d['revenue'],
            'orders'  => (int) $d['orders'],
        ])->values()->toArray();

        if (count($historical) < 7) {
            return [
                'historical' => $historical,
                'forecast'   => [],
                'trend'      => 'insufficient_data',
                'confidence' => 0,
            ];
        }

        // Linear regression
        $values = array_column($historical, 'revenue');
        $n = count($values);
        $sumX = $sumY = $sumXY = $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumX  += $i;
            $sumY  += $values[$i];
            $sumXY += $i * $values[$i];
            $sumX2 += $i * $i;
        }

        $denominator = ($n * $sumX2) - ($sumX * $sumX);
        $slope = $denominator != 0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denominator : 0;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        // R-squared for confidence
        $meanY = $sumY / $n;
        $ssRes = $ssTot = 0;
        for ($i = 0; $i < $n; $i++) {
            $predicted = $intercept + ($slope * $i);
            $ssRes += ($values[$i] - $predicted) ** 2;
            $ssTot += ($values[$i] - $meanY) ** 2;
        }
        $rSquared = $ssTot > 0 ? max(0, 1 - ($ssRes / $ssTot)) : 0;

        // Generate forecast
        $forecast = [];
        $lastDate = CarbonImmutable::parse(end($historical)['date']);
        for ($i = 1; $i <= $daysForecast; $i++) {
            $predicted = max(0, $intercept + ($slope * ($n + $i - 1)));
            $forecast[] = [
                'date'    => $lastDate->addDays($i)->format('Y-m-d'),
                'revenue' => round($predicted, 2),
            ];
        }

        $trend = $slope > 1 ? 'growing' : ($slope < -1 ? 'declining' : 'stable');

        return [
            'historical' => $historical,
            'forecast'   => $forecast,
            'trend'      => $trend,
            'confidence' => round($rSquared * 100, 1),
        ];
    }

    // ------------------------------------------------------------------
    //  Automated Insights
    // ------------------------------------------------------------------

    /**
     * Generate a list of actionable insights by analyzing the data.
     *
     * @return Collection<int, array{title: string, description: string, impact: string, category: string, icon: string}>
     */
    public function generateInsights(int|string $tenantId): Collection
    {
        $cacheKey = "ai_insights:{$tenantId}";

        return Cache::store('redis')->remember($cacheKey, self::INSIGHTS_CACHE_TTL, function () use ($tenantId) {
            return $this->computeInsights($tenantId);
        });
    }

    private function computeInsights(int|string $tenantId): Collection
    {
        $mongo = DB::connection('mongodb');
        $now = CarbonImmutable::now();
        $insights = collect();

        // 1. Identify high drop-off pages
        $funnelDropOff = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'event_type' => ['$in' => ['product_view', 'add_to_cart', 'begin_checkout', 'purchase']],
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays(30)->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'   => '$event_type',
                        'count' => ['$sum' => 1],
                    ]],
                ])->toArray();
            });

        $funnelData = collect($funnelDropOff)->keyBy('_id');
        $views = $funnelData->get('product_view')['count'] ?? 0;
        $carts = $funnelData->get('add_to_cart')['count'] ?? 0;
        $checkouts = $funnelData->get('begin_checkout')['count'] ?? 0;
        $purchases = $funnelData->get('purchase')['count'] ?? 0;

        if ($views > 10 && $carts > 0) {
            $viewToCart = round(($carts / $views) * 100, 1);
            if ($viewToCart < 5) {
                $insights->push([
                    'title'       => 'Low Add-to-Cart Rate',
                    'description' => "Only {$viewToCart}% of product views lead to cart additions. Consider improving product pages with better images, reviews, or pricing.",
                    'impact'      => 'high',
                    'category'    => 'conversion',
                    'icon'        => 'shopping-cart',
                ]);
            }
        }

        if ($carts > 5 && $checkouts > 0) {
            $cartToCheckout = round(($checkouts / $carts) * 100, 1);
            if ($cartToCheckout < 30) {
                $insights->push([
                    'title'       => 'Cart Abandonment Issue',
                    'description' => "Only {$cartToCheckout}% of carts proceed to checkout. Consider adding trust badges, free shipping thresholds, or exit-intent popups.",
                    'impact'      => 'critical',
                    'category'    => 'conversion',
                    'icon'        => 'exclamation-triangle',
                ]);
            }
        }

        if ($checkouts > 5 && $purchases > 0) {
            $checkoutToPurchase = round(($purchases / $checkouts) * 100, 1);
            if ($checkoutToPurchase < 50) {
                $insights->push([
                    'title'       => 'Checkout Drop-off Detected',
                    'description' => "Only {$checkoutToPurchase}% of checkouts complete payment. Review your checkout flow for friction points, payment options, or error handling.",
                    'impact'      => 'critical',
                    'category'    => 'conversion',
                    'icon'        => 'credit-card',
                ]);
            }
        }

        // 2. Peak hours analysis
        $hourlyData = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'event_type' => 'purchase',
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays(30)->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'      => ['$hour' => '$created_at'],
                        'revenue'  => ['$sum' => ['$ifNull' => ['$metadata.order_total', 0]]],
                        'orders'   => ['$sum' => 1],
                    ]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => 3],
                ])->toArray();
            });

        if (!empty($hourlyData)) {
            $peakHours = collect($hourlyData)->map(fn ($h) => sprintf('%02d:00', $h['_id']))->join(', ');
            $insights->push([
                'title'       => 'Peak Revenue Hours Identified',
                'description' => "Your top revenue hours are {$peakHours}. Consider scheduling marketing campaigns and promotions around these times.",
                'impact'      => 'medium',
                'category'    => 'timing',
                'icon'        => 'clock',
            ]);
        }

        // 3. Mobile vs Desktop conversion comparison
        $deviceConv = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'event_type' => ['$in' => ['page_view', 'purchase']],
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays(30)->getTimestampMs())],
                        'metadata.device.device_type' => ['$exists' => true],
                    ]],
                    ['$group' => [
                        '_id'       => ['device' => '$metadata.device.device_type', 'event' => '$event_type'],
                        'count'     => ['$sum' => 1],
                    ]],
                ])->toArray();
            });

        $deviceMap = collect($deviceConv)->groupBy(fn ($d) => $d['_id']['device']);
        foreach (['Mobile', 'Desktop'] as $device) {
            $group = $deviceMap->get($device, collect());
            $dViews = $group->firstWhere(fn ($d) => $d['_id']['event'] === 'page_view')['count'] ?? 0;
            $dPurchases = $group->firstWhere(fn ($d) => $d['_id']['event'] === 'purchase')['count'] ?? 0;

            if ($device === 'Mobile' && $dViews > 20 && $dPurchases / max($dViews, 1) < 0.01) {
                $insights->push([
                    'title'       => 'Mobile Conversion Gap',
                    'description' => 'Mobile visitors convert at a significantly lower rate. Optimize your mobile checkout experience and page load speed.',
                    'impact'      => 'high',
                    'category'    => 'device',
                    'icon'        => 'device-mobile',
                ]);
            }
        }

        // 4. Top performing products insight
        $topProducts = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'event_type' => 'purchase',
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays(30)->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'     => '$metadata.product_name',
                        'revenue' => ['$sum' => ['$ifNull' => ['$metadata.order_total', 0]]],
                        'orders'  => ['$sum' => 1],
                    ]],
                    ['$sort' => ['revenue' => -1]],
                    ['$limit' => 3],
                ])->toArray();
            });

        if (count($topProducts) >= 2) {
            $topName = $topProducts[0]['_id'] ?? 'Unknown';
            $topRevenue = number_format((float) ($topProducts[0]['revenue'] ?? 0), 2);
            $insights->push([
                'title'       => 'Top Revenue Driver',
                'description' => "\"{$topName}\" generated \${$topRevenue} in the last 30 days. Consider promoting it more prominently or creating bundles.",
                'impact'      => 'medium',
                'category'    => 'product',
                'icon'        => 'star',
            ]);
        }

        // 5. Session depth analysis
        $avgDepth = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays(7)->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'   => '$session_id',
                        'depth' => ['$sum' => 1],
                    ]],
                    ['$group' => [
                        '_id'      => null,
                        'avgDepth' => ['$avg' => '$depth'],
                    ]],
                ])->toArray();
            });

        $depth = $avgDepth[0]['avgDepth'] ?? 0;
        if ($depth > 0 && $depth < 2.5) {
            $insights->push([
                'title'       => 'Low Session Depth',
                'description' => sprintf('Average session depth is only %.1f events. Visitors aren\'t exploring. Improve internal linking, recommendations, and content discovery.', $depth),
                'impact'      => 'medium',
                'category'    => 'engagement',
                'icon'        => 'arrow-trending-down',
            ]);
        }

        return $insights;
    }

    // ------------------------------------------------------------------
    //  Real-time Traffic Summary
    // ------------------------------------------------------------------

    /**
     * Get real-time traffic overview (last 30 minutes).
     *
     * @return array{active_sessions: int, events_per_minute: float, top_pages: array, geo_breakdown: array}
     */
    public function getRealTimeOverview(int|string $tenantId): array
    {
        $mongo = DB::connection('mongodb');
        $now = CarbonImmutable::now();
        $thirtyMinAgo = $now->subMinutes(30);

        // Active sessions
        $sessions = $mongo->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', new \MongoDB\BSON\UTCDateTime($thirtyMinAgo->getTimestampMs()))
            ->distinct('session_id')
            ->get();

        $activeSessions = count($sessions);

        // Events in last 30 min
        $eventCount = $mongo->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', new \MongoDB\BSON\UTCDateTime($thirtyMinAgo->getTimestampMs()))
            ->count();

        $eventsPerMinute = $eventCount > 0 ? round($eventCount / 30, 1) : 0;

        // Top pages right now
        $topPages = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $thirtyMinAgo) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'event_type' => 'page_view',
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($thirtyMinAgo->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'   => '$url',
                        'views' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['views' => -1]],
                    ['$limit' => 5],
                ])->toArray();
            });

        // Geo breakdown
        $geoBreakdown = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $thirtyMinAgo) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'            => $tenantId,
                        'created_at'           => ['$gte' => new \MongoDB\BSON\UTCDateTime($thirtyMinAgo->getTimestampMs())],
                        'metadata.geo.country' => ['$exists' => true],
                    ]],
                    ['$group' => [
                        '_id'      => '$metadata.geo.country',
                        'sessions' => ['$addToSet' => '$session_id'],
                    ]],
                    ['$project' => [
                        'country'  => '$_id',
                        'sessions' => ['$size' => '$sessions'],
                    ]],
                    ['$sort' => ['sessions' => -1]],
                    ['$limit' => 10],
                ])->toArray();
            });

        return [
            'active_sessions'   => $activeSessions,
            'events_per_minute' => $eventsPerMinute,
            'top_pages'         => collect($topPages)->toArray(),
            'geo_breakdown'     => collect($geoBreakdown)->toArray(),
        ];
    }

    // ------------------------------------------------------------------
    //  Country-wise Analytics
    // ------------------------------------------------------------------

    /**
     * Get detailed country-wise visit analytics.
     *
     * @return array{countries: array, total_countries: int}
     */
    public function getCountryAnalytics(int|string $tenantId, int $days = 30): array
    {
        $mongo = DB::connection('mongodb');
        $now = CarbonImmutable::now();

        $countries = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now, $days) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'            => $tenantId,
                        'created_at'           => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays($days)->getTimestampMs())],
                        'metadata.geo.country' => ['$exists' => true, '$ne' => null],
                    ]],
                    ['$group' => [
                        '_id'          => '$metadata.geo.country',
                        'country_code' => ['$first' => '$metadata.geo.country_code'],
                        'sessions'     => ['$addToSet' => '$session_id'],
                        'events'       => ['$sum' => 1],
                        'purchases'    => ['$sum' => ['$cond' => [['$eq' => ['$event_type', 'purchase']], 1, 0]]],
                        'revenue'      => ['$sum' => ['$cond' => [
                            ['$eq' => ['$event_type', 'purchase']],
                            ['$ifNull' => ['$metadata.order_total', 0]],
                            0,
                        ]]],
                    ]],
                    ['$project' => [
                        'country'      => '$_id',
                        'country_code' => 1,
                        'sessions'     => ['$size' => '$sessions'],
                        'events'       => 1,
                        'purchases'    => 1,
                        'revenue'      => 1,
                    ]],
                    ['$sort' => ['sessions' => -1]],
                ])->toArray();
            });

        return [
            'countries'       => collect($countries)->toArray(),
            'total_countries' => count($countries),
        ];
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function standardDeviation(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0;

        $mean = array_sum($values) / $n;
        $sumSquares = 0;
        foreach ($values as $v) {
            $sumSquares += ($v - $mean) ** 2;
        }

        return sqrt($sumSquares / ($n - 1));
    }
}

<?php
declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AdvancedBIService — Deep business intelligence analytics.
 *
 * UC26: Product Cannibalization Detection — new products eating old revenue
 * UC27: LTV vs CAC Health Dashboard — unit economics per channel
 * UC28: Real-Time Conversion Probability — live scoring of active sessions
 * UC29: Device & Browser Revenue Mapping — granular device analytics
 * UC30: Cohort Analysis by Acquisition Channel — source-based retention
 */
class AdvancedBIService
{
    /**
     * UC26: Detect product cannibalization — when new products steal sales from existing.
     */
    public function productCannibalization(int $tenantId, string $dateRange = '90d'): array
    {
        try {
            $days = (int) filter_var($dateRange, FILTER_SANITIZE_NUMBER_INT) ?: 90;
            $midpoint = now()->subDays($days / 2);

            // Get sales in first half vs second half per product
            $firstHalfStart = now()->subDays($days)->toDateTimeString();
            $midpointStr = $midpoint->toDateTimeString();

            $salesByProduct = function ($start, $end) use ($tenantId) {
                $orders = DB::connection('mongodb')
                    ->table('synced_orders')
                    ->where('tenant_id', $tenantId)
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end)
                    ->get();

                $sales = [];
                foreach ($orders as $order) {
                    foreach ($order['items'] ?? [] as $item) {
                        $pid = $item['product_id'] ?? '';
                        if (!$pid) continue;
                        if (!isset($sales[$pid])) {
                            $sales[$pid] = ['qty' => 0, 'revenue' => 0, 'name' => $item['name'] ?? '', 'category' => $item['category'] ?? ''];
                        }
                        $sales[$pid]['qty'] += $item['quantity'] ?? 1;
                        $sales[$pid]['revenue'] += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                    }
                }
                return $sales;
            };

            $firstHalf = $salesByProduct($firstHalfStart, $midpointStr);
            $secondHalf = $salesByProduct($midpointStr, now()->toDateTimeString());

            // Detect newly launched products (only in second half)
            $newProducts = array_diff_key($secondHalf, $firstHalf);

            // Find declining products in same category as new launches
            $cannibalization = [];
            foreach ($newProducts as $newPid => $newData) {
                $category = $newData['category'];
                if (!$category) continue;

                foreach ($firstHalf as $oldPid => $oldData) {
                    if ($oldData['category'] !== $category) continue;

                    $secondHalfOld = $secondHalf[$oldPid] ?? ['revenue' => 0, 'qty' => 0];
                    $revenueDecline = $oldData['revenue'] - $secondHalfOld['revenue'];
                    $declinePct = $oldData['revenue'] > 0 ? ($revenueDecline / $oldData['revenue']) * 100 : 0;

                    if ($declinePct > 20) {
                        $cannibalization[] = [
                            'cannibalizer' => ['id' => $newPid, 'name' => $newData['name'], 'revenue' => round($newData['revenue'], 2)],
                            'victim'       => ['id' => $oldPid, 'name' => $oldData['name'], 'revenue_before' => round($oldData['revenue'], 2), 'revenue_after' => round($secondHalfOld['revenue'], 2)],
                            'category'     => $category,
                            'decline_pct'  => round($declinePct, 1),
                            'revenue_shift' => round($revenueDecline, 2),
                            'confidence'   => $declinePct > 50 ? 'high' : 'medium',
                        ];
                    }
                }
            }

            usort($cannibalization, fn($a, $b) => $b['revenue_shift'] <=> $a['revenue_shift']);

            return [
                'success'              => true,
                'period'               => $dateRange,
                'new_products_count'   => count($newProducts),
                'cannibalization_cases' => count($cannibalization),
                'total_revenue_shifted' => round(collect($cannibalization)->sum('revenue_shift'), 2),
                'cases'                => array_slice($cannibalization, 0, 20),
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedBI::cannibalization error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC27: LTV vs CAC health dashboard per acquisition channel.
     */
    public function ltvVsCacHealth(int $tenantId): array
    {
        try {
            // Get customer acquisition data
            $customers = DB::connection('mongodb')
                ->table('synced_customers')
                ->where('tenant_id', $tenantId)
                ->get();

            // Get all orders
            $orders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->get()
                ->groupBy('customer_email');

            // Get marketing spend by channel (from events or campaign data)
            $channelSpend = DB::connection('mongodb')
                ->table('marketing_campaigns')
                ->where('tenant_id', $tenantId)
                ->get()
                ->groupBy('channel');

            $channelMetrics = [];
            $channels = ['organic', 'google_ads', 'facebook', 'instagram', 'email', 'referral', 'direct', 'tiktok'];

            foreach ($channels as $channel) {
                $channelCustomers = $customers->filter(fn($c) =>
                    strtolower($c['source'] ?? $c['utm_source'] ?? 'direct') === $channel
                );

                $totalLtv = 0;
                $customerCount = 0;

                foreach ($channelCustomers as $customer) {
                    $email = $customer['email'] ?? '';
                    $customerOrders = $orders[$email] ?? collect();
                    $ltv = $customerOrders->sum('total');
                    $totalLtv += $ltv;
                    $customerCount++;
                }

                $avgLtv = $customerCount > 0 ? $totalLtv / $customerCount : 0;
                $spend = $channelSpend[$channel]?->sum('spend') ?? 0;
                $cac = $customerCount > 0 && $spend > 0 ? $spend / $customerCount : 0;
                $ratio = $cac > 0 ? $avgLtv / $cac : 0;

                $channelMetrics[] = [
                    'channel'        => $channel,
                    'customers'      => $customerCount,
                    'avg_ltv'        => round($avgLtv, 2),
                    'total_spend'    => round($spend, 2),
                    'cac'            => round($cac, 2),
                    'ltv_cac_ratio'  => round($ratio, 2),
                    'health'         => $ratio >= 3 ? 'excellent' : ($ratio >= 2 ? 'healthy' : ($ratio >= 1 ? 'break_even' : 'unhealthy')),
                    'total_revenue'  => round($totalLtv, 2),
                    'roi_pct'        => $spend > 0 ? round(($totalLtv - $spend) / $spend * 100, 1) : 0,
                ];
            }

            usort($channelMetrics, fn($a, $b) => $b['ltv_cac_ratio'] <=> $a['ltv_cac_ratio']);

            return [
                'success'    => true,
                'channels'   => $channelMetrics,
                'summary'    => [
                    'best_channel'    => $channelMetrics[0]['channel'] ?? 'N/A',
                    'avg_ltv_overall' => round(collect($channelMetrics)->avg('avg_ltv'), 2),
                    'avg_cac_overall' => round(collect($channelMetrics)->avg('cac'), 2),
                    'healthy_channels'   => collect($channelMetrics)->whereIn('health', ['excellent', 'healthy'])->count(),
                    'unhealthy_channels' => collect($channelMetrics)->where('health', 'unhealthy')->count(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedBI::ltvVsCac error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC28: Real-time conversion probability scoring for active sessions.
     */
    public function conversionProbability(int $tenantId): array
    {
        try {
            // Get active sessions (last 30 minutes)
            $activeSessions = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subMinutes(30)->toDateTimeString())
                ->get()
                ->groupBy('session_id');

            $scoredSessions = [];
            foreach ($activeSessions as $sessionId => $events) {
                if (!$sessionId) continue;

                $score = 0;
                $signals = [];

                $eventTypes = collect($events)->pluck('event')->toArray();
                $pageCount = collect($events)->where('event', 'page_view')->count();
                $lastEvent = collect($events)->max('created_at');
                $sessionDuration = now()->diffInMinutes(collect($events)->min('created_at'));

                // Scoring signals
                if (in_array('add_to_cart', $eventTypes)) { $score += 30; $signals[] = 'added_to_cart'; }
                if (in_array('begin_checkout', $eventTypes)) { $score += 25; $signals[] = 'started_checkout'; }
                if (in_array('add_payment_info', $eventTypes)) { $score += 20; $signals[] = 'payment_info'; }
                if (in_array('wishlist_add', $eventTypes)) { $score += 10; $signals[] = 'wishlisted'; }
                if ($pageCount >= 5) { $score += 10; $signals[] = 'high_engagement'; }
                if ($sessionDuration >= 3 && $sessionDuration <= 15) { $score += 10; $signals[] = 'optimal_duration'; }
                if (in_array('product_view', $eventTypes) && collect($events)->where('event', 'product_view')->count() >= 3) {
                    $score += 5; $signals[] = 'multiple_products_viewed';
                }

                // Check returning visitor
                $visitorId = collect($events)->first()['visitor_id'] ?? null;
                if ($visitorId) {
                    $previousVisits = DB::connection('mongodb')
                        ->table('events')
                        ->where('tenant_id', $tenantId)
                        ->where('visitor_id', $visitorId)
                        ->where('created_at', '<', now()->subMinutes(30)->toDateTimeString())
                        ->exists();
                    if ($previousVisits) { $score += 10; $signals[] = 'returning_visitor'; }
                }

                $scoredSessions[] = [
                    'session_id'           => $sessionId,
                    'visitor_id'           => $visitorId,
                    'conversion_probability' => min(100, $score),
                    'signals'              => $signals,
                    'page_views'           => $pageCount,
                    'session_duration_min' => $sessionDuration,
                    'last_activity'        => $lastEvent,
                    'event_count'          => count($events),
                    'recommendation'       => $score >= 60 ? 'high_value_nudge' : ($score >= 30 ? 'engagement_offer' : 'observe'),
                ];
            }

            usort($scoredSessions, fn($a, $b) => $b['conversion_probability'] <=> $a['conversion_probability']);

            return [
                'success'         => true,
                'active_sessions' => count($scoredSessions),
                'high_intent'     => collect($scoredSessions)->where('conversion_probability', '>=', 60)->count(),
                'medium_intent'   => collect($scoredSessions)->whereBetween('conversion_probability', [30, 59])->count(),
                'low_intent'      => collect($scoredSessions)->where('conversion_probability', '<', 30)->count(),
                'sessions'        => array_slice($scoredSessions, 0, 50),
                'avg_probability' => round(collect($scoredSessions)->avg('conversion_probability') ?? 0, 1),
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedBI::conversionProb error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC29: Device & browser revenue mapping — granular analytics.
     */
    public function deviceRevenueMapping(int $tenantId, string $dateRange = '30d'): array
    {
        try {
            $days = (int) filter_var($dateRange, FILTER_SANITIZE_NUMBER_INT) ?: 30;

            $events = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('created_at', '>=', now()->subDays($days))
                ->get();

            $deviceData = [];
            foreach ($events as $eventRaw) {
                $event = (array) $eventRaw;
                $props = (array) ($event['properties'] ?? []);
                $device = $event['device_type'] ?? $props['device_type'] ?? $props['device'] ?? 'unknown';
                $browser = $event['browser'] ?? $props['browser'] ?? 'unknown';
                $os = $event['os'] ?? $props['os'] ?? 'unknown';
                $revenue = $event['revenue'] ?? $props['order_total'] ?? $props['value'] ?? 0;

                $deviceKey = strtolower($device);
                if (!isset($deviceData[$deviceKey])) {
                    $deviceData[$deviceKey] = ['orders' => 0, 'revenue' => 0, 'browsers' => [], 'os' => []];
                }
                $deviceData[$deviceKey]['orders']++;
                $deviceData[$deviceKey]['revenue'] += $revenue;

                $bKey = strtolower($browser);
                $deviceData[$deviceKey]['browsers'][$bKey] = ($deviceData[$deviceKey]['browsers'][$bKey] ?? 0) + $revenue;

                $oKey = strtolower($os);
                $deviceData[$deviceKey]['os'][$oKey] = ($deviceData[$deviceKey]['os'][$oKey] ?? 0) + $revenue;
            }

            $totalRevenue = array_sum(array_column($deviceData, 'revenue'));
            $breakdown = [];
            foreach ($deviceData as $device => $data) {
                $breakdown[] = [
                    'device'      => $device,
                    'orders'      => $data['orders'],
                    'revenue'     => round($data['revenue'], 2),
                    'share_pct'   => $totalRevenue > 0 ? round($data['revenue'] / $totalRevenue * 100, 1) : 0,
                    'avg_order'   => $data['orders'] > 0 ? round($data['revenue'] / $data['orders'], 2) : 0,
                    'top_browser' => (function($arr) { arsort($arr); return array_key_first($arr) ?: 'N/A'; })($data['browsers'] ?: ['N/A' => 0]),
                    'top_os'      => (function($arr) { arsort($arr); return array_key_first($arr) ?: 'N/A'; })($data['os'] ?: ['N/A' => 0]),
                    'browsers'    => $data['browsers'],
                    'os_breakdown' => $data['os'],
                ];
            }

            usort($breakdown, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

            return [
                'success'       => true,
                'period'        => $dateRange,
                'total_revenue' => round($totalRevenue, 2),
                'total_orders'  => $events->count(),
                'devices'       => $breakdown,
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedBI::deviceRevenue error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC30: Cohort analysis by acquisition channel — retention heatmap data.
     */
    public function cohortByAcquisition(int $tenantId, string $dateRange = '6m'): array
    {
        try {
            $months = (int) filter_var($dateRange, FILTER_SANITIZE_NUMBER_INT) ?: 6;

            $customers = DB::connection('mongodb')
                ->table('synced_customers')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subMonths($months)->toDateTimeString())
                ->get();

            $orders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subMonths($months)->toDateTimeString())
                ->get()
                ->groupBy('customer_email');

            $cohorts = [];
            foreach ($customers as $customer) {
                $email = $customer['email'] ?? '';
                $source = strtolower($customer['source'] ?? $customer['utm_source'] ?? 'direct');
                $cohortMonth = date('Y-m', strtotime($customer['created_at'] ?? 'now'));
                $key = "{$source}:{$cohortMonth}";

                if (!isset($cohorts[$key])) {
                    $cohorts[$key] = [
                        'channel'      => $source,
                        'cohort_month' => $cohortMonth,
                        'customers'    => 0,
                        'retention'    => array_fill(0, $months, 0),
                        'revenue'      => array_fill(0, $months, 0),
                    ];
                }
                $cohorts[$key]['customers']++;

                // Track monthly retention
                $customerOrders = $orders[$email] ?? collect();
                foreach ($customerOrders as $order) {
                    $orderMonth = date('Y-m', strtotime($order['created_at']));
                    $monthDiff = (int) now()->parse($orderMonth)->diffInMonths($cohortMonth);
                    if ($monthDiff >= 0 && $monthDiff < $months) {
                        $cohorts[$key]['retention'][$monthDiff]++;
                        $cohorts[$key]['revenue'][$monthDiff] += $order['total'] ?? 0;
                    }
                }
            }

            // Convert retention to percentages
            $cohortData = [];
            foreach ($cohorts as $cohort) {
                $total = $cohort['customers'];
                $retentionPct = array_map(fn($r) => $total > 0 ? round($r / $total * 100, 1) : 0, $cohort['retention']);

                $cohortData[] = [
                    'channel'        => $cohort['channel'],
                    'cohort_month'   => $cohort['cohort_month'],
                    'customers'      => $total,
                    'retention_pct'  => $retentionPct,
                    'revenue_by_month' => array_map(fn($r) => round($r, 2), $cohort['revenue']),
                    'month_1_retention' => $retentionPct[1] ?? 0,
                    'avg_retention'  => round(array_sum(array_slice($retentionPct, 1)) / max(1, count($retentionPct) - 1), 1),
                ];
            }

            usort($cohortData, fn($a, $b) => $b['avg_retention'] <=> $a['avg_retention']);

            return [
                'success'     => true,
                'period'      => $dateRange,
                'cohorts'     => $cohortData,
                'total_cohorts' => count($cohortData),
                'best_channel'  => $cohortData[0]['channel'] ?? 'N/A',
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedBI::cohortByAcquisition error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

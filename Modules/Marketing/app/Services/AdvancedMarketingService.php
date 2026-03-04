<?php
declare(strict_types=1);

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AdvancedMarketingService — Loyalty, churn, and lifecycle engines.
 *
 * UC16: Discount Addiction Flagging — detect & wean off discount-dependent buyers
 * UC17: VIP Early Access Windows — priority product launches for top customers
 * UC18: Churn-Risk Winback Sequences — multi-step re-engagement
 * UC19: Smart Replenishment Reminders — predict refill timing
 * UC20: Milestone & Anniversary Automation — birthday, membership, etc.
 */
class AdvancedMarketingService
{
    /**
     * UC16: Flag customers addicted to discounts and recommend weaning strategy.
     */
    public function discountAddictionAnalysis(int $tenantId): array
    {
        try {
            $orders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subMonths(12)->toDateTimeString())
                ->get();

            $customerStats = [];
            foreach ($orders as $orderRaw) {
                $order = (array) $orderRaw;
                $email = $order['customer_email'] ?? null;
                if (!$email) continue;

                if (!isset($customerStats[$email])) {
                    $customerStats[$email] = [
                        'total_orders'    => 0,
                        'discount_orders' => 0,
                        'total_revenue'   => 0,
                        'total_discount'  => 0,
                    ];
                }

                $customerStats[$email]['total_orders']++;
                $customerStats[$email]['total_revenue'] += $order['total'] ?? 0;

                $discountAmt = $order['discount_amount'] ?? 0;
                if ($discountAmt > 0 || !empty($order['coupon_code'])) {
                    $customerStats[$email]['discount_orders']++;
                    $customerStats[$email]['total_discount'] += $discountAmt;
                }
            }

            $addicted = [];
            $atRisk = [];
            $healthy = [];

            foreach ($customerStats as $email => $stats) {
                if ($stats['total_orders'] < 3) continue;

                $discountRate = $stats['discount_orders'] / $stats['total_orders'];
                $avgDiscount = $stats['total_orders'] > 0 ? $stats['total_discount'] / $stats['total_orders'] : 0;

                $entry = [
                    'email'             => $email,
                    'total_orders'      => $stats['total_orders'],
                    'discount_orders'   => $stats['discount_orders'],
                    'discount_rate'     => round($discountRate * 100, 1),
                    'avg_discount'      => round($avgDiscount, 2),
                    'total_revenue'     => round($stats['total_revenue'], 2),
                    'revenue_at_risk'   => round($stats['total_revenue'] * $discountRate, 2),
                ];

                if ($discountRate >= 0.8) {
                    $entry['status'] = 'addicted';
                    $entry['strategy'] = 'gradual_weaning';
                    $entry['recommendation'] = 'Reduce discount by 5% each order. Introduce loyalty points instead.';
                    $addicted[] = $entry;
                } elseif ($discountRate >= 0.5) {
                    $entry['status'] = 'at_risk';
                    $entry['strategy'] = 'value_education';
                    $entry['recommendation'] = 'Highlight product value. Offer free shipping instead of discounts.';
                    $atRisk[] = $entry;
                } else {
                    $entry['status'] = 'healthy';
                    $healthy[] = $entry;
                }
            }

            return [
                'success'  => true,
                'summary'  => [
                    'addicted_count'  => count($addicted),
                    'at_risk_count'   => count($atRisk),
                    'healthy_count'   => count($healthy),
                    'total_analyzed'  => count($addicted) + count($atRisk) + count($healthy),
                    'revenue_at_risk' => round(collect($addicted)->sum('revenue_at_risk'), 2),
                ],
                'addicted_customers' => array_slice($addicted, 0, 50),
                'at_risk_customers'  => array_slice($atRisk, 0, 50),
                'weaning_strategies' => [
                    ['step' => 1, 'action' => 'Replace 20% discount with 10% + free shipping'],
                    ['step' => 2, 'action' => 'Replace discount with loyalty points worth 15%'],
                    ['step' => 3, 'action' => 'Offer exclusive early access instead of discount'],
                    ['step' => 4, 'action' => 'Full price with premium gift wrapping'],
                ],
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedMarketing::discountAddiction error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC17: VIP early access — grant priority product launch windows.
     */
    public function vipEarlyAccess(int $tenantId, array $productLaunch): array
    {
        try {
            $launchDate = $productLaunch['launch_date'] ?? now()->addDays(7)->toDateString();
            $productId = $productLaunch['product_id'] ?? null;
            $earlyAccessHours = $productLaunch['early_access_hours'] ?? 48;

            // Identify VIP customers (top 10% by LTV)
            /** @var \Traversable $topCustomers */
            $topCustomers = DB::connection('mongodb')
                ->table('synced_orders')
                ->raw(function ($collection) use ($tenantId) {
                    return $collection->aggregate([
                        ['$match' => ['tenant_id' => $tenantId]],
                        ['$group' => [
                            '_id' => '$customer_email',
                            'ltv' => ['$sum' => '$total'],
                            'orders' => ['$sum' => 1],
                            'last_order' => ['$max' => '$created_at'],
                        ]],
                        ['$sort' => ['ltv' => -1]],
                        ['$limit' => 100],
                    ]);
                });

            $vips = collect(iterator_to_array($topCustomers))
                ->map(fn($c) => [
                    'email'      => $c['_id'],
                    'ltv'        => round($c['ltv'], 2),
                    'orders'     => $c['orders'],
                    'tier'       => $c['ltv'] >= 5000 ? 'platinum' : ($c['ltv'] >= 2000 ? 'gold' : 'silver'),
                ])
                ->values()
                ->toArray();

            // Build tiered access windows
            $accessWindows = [
                ['tier' => 'platinum', 'access_start' => now()->parse($launchDate)->subHours($earlyAccessHours)->toDateTimeString(), 'discount' => 15],
                ['tier' => 'gold', 'access_start' => now()->parse($launchDate)->subHours($earlyAccessHours / 2)->toDateTimeString(), 'discount' => 10],
                ['tier' => 'silver', 'access_start' => now()->parse($launchDate)->subHours($earlyAccessHours / 4)->toDateTimeString(), 'discount' => 5],
                ['tier' => 'general', 'access_start' => $launchDate, 'discount' => 0],
            ];

            return [
                'success'         => true,
                'product_id'      => $productId,
                'launch_date'     => $launchDate,
                'vip_count'       => count($vips),
                'vip_tiers'       => [
                    'platinum' => collect($vips)->where('tier', 'platinum')->count(),
                    'gold'     => collect($vips)->where('tier', 'gold')->count(),
                    'silver'   => collect($vips)->where('tier', 'silver')->count(),
                ],
                'access_windows'  => $accessWindows,
                'vip_list'        => array_slice($vips, 0, 20),
                'notification_schedule' => [
                    ['when' => '-72h', 'channel' => 'email', 'message' => 'Exclusive preview: new launch coming soon!'],
                    ['when' => '-24h', 'channel' => 'push', 'message' => 'Your VIP early access starts tomorrow!'],
                    ['when' => '0h', 'channel' => 'sms', 'message' => '🎉 Your early access is LIVE! Shop now →'],
                ],
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedMarketing::vipEarlyAccess error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC18: Churn-risk winback multi-step sequences.
     */
    public function churnRiskWinback(int $tenantId): array
    {
        try {
            // Find customers at risk (no activity in 30-90 days)
            $thirtyDaysAgo = now()->subDays(30)->toDateTimeString();
            $ninetyDaysAgo = now()->subDays(90)->toDateTimeString();

            $recentActive = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->distinct('visitor_id');

            $previouslyActive = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $ninetyDaysAgo)
                ->where('created_at', '<', $thirtyDaysAgo)
                ->get()
                ->groupBy('customer_email');

            $churnRisk = [];
            foreach ($previouslyActive as $email => $orders) {
                if (in_array($email, (array) $recentActive)) continue;

                $totalSpent = $orders->sum('total');
                $orderCount = $orders->count();
                $lastOrder = $orders->max('created_at');
                $daysSince = now()->diffInDays($lastOrder);

                $riskLevel = $daysSince > 60 ? 'high' : ($daysSince > 45 ? 'medium' : 'low');

                $churnRisk[] = [
                    'email'       => $email,
                    'ltv'         => round($totalSpent, 2),
                    'orders'      => $orderCount,
                    'last_order'  => $lastOrder,
                    'days_silent' => $daysSince,
                    'risk_level'  => $riskLevel,
                ];
            }

            // Sort by LTV (save highest value customers first)
            usort($churnRisk, fn($a, $b) => $b['ltv'] <=> $a['ltv']);

            return [
                'success'     => true,
                'total_at_risk' => count($churnRisk),
                'by_risk_level' => [
                    'high'   => collect($churnRisk)->where('risk_level', 'high')->count(),
                    'medium' => collect($churnRisk)->where('risk_level', 'medium')->count(),
                    'low'    => collect($churnRisk)->where('risk_level', 'low')->count(),
                ],
                'revenue_at_risk' => round(collect($churnRisk)->sum('ltv'), 2),
                'churn_customers' => array_slice($churnRisk, 0, 50),
                'winback_sequence' => [
                    ['day' => 0, 'channel' => 'email', 'action' => 'We miss you! See what\'s new', 'discount' => 0],
                    ['day' => 3, 'channel' => 'push', 'action' => 'Your favorites have updates', 'discount' => 0],
                    ['day' => 7, 'channel' => 'email', 'action' => 'Exclusive 10% welcome back offer', 'discount' => 10],
                    ['day' => 14, 'channel' => 'sms', 'action' => 'Last chance: 15% off expires tonight', 'discount' => 15],
                    ['day' => 30, 'channel' => 'email', 'action' => 'Final winback: 20% + free shipping', 'discount' => 20],
                ],
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedMarketing::churnWinback error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC19: Smart replenishment reminders — predict refill timing.
     */
    public function smartReplenishment(int $tenantId): array
    {
        try {
            // Find repeat purchases of consumable products
            $orders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subMonths(12)->toDateTimeString())
                ->orderBy('created_at', 'asc')
                ->get();

            $purchaseHistory = [];
            foreach ($orders as $orderRaw) {
                $order = (array) $orderRaw;
                $items = (array) ($order['items'] ?? []);
                foreach ($items as $itemRaw) {
                    $item = (array) $itemRaw;
                    $pid = $item['product_id'] ?? $item['sku'] ?? null;
                    $email = $order['customer_email'] ?? null;
                    if (!$pid || !$email) continue;

                    $key = "{$email}:{$pid}";
                    $purchaseHistory[$key][] = [
                        'date'  => $order['created_at'],
                        'qty'   => $item['quantity'] ?? 1,
                        'email' => $email,
                        'product_name' => $item['name'] ?? '',
                        'product_id'   => $pid,
                    ];
                }
            }

            $reminders = [];
            foreach ($purchaseHistory as $key => $purchases) {
                if (count($purchases) < 2) continue;

                // Calculate average days between purchases
                $intervals = [];
                for ($i = 1; $i < count($purchases); $i++) {
                    $diff = now()->parse($purchases[$i]['date'])->diffInDays($purchases[$i - 1]['date']);
                    if ($diff > 0) $intervals[] = $diff;
                }

                if (empty($intervals)) continue;

                $avgInterval = array_sum($intervals) / count($intervals);
                $lastPurchase = end($purchases);
                $daysSinceLast = now()->diffInDays($lastPurchase['date']);
                $daysUntilRefill = max(0, round($avgInterval - $daysSinceLast));

                if ($daysUntilRefill <= 7 && $daysUntilRefill >= 0) {
                    $reminders[] = [
                        'customer_email' => $lastPurchase['email'],
                        'product_id'     => $lastPurchase['product_id'],
                        'product_name'   => $lastPurchase['product_name'],
                        'avg_interval_days' => round($avgInterval),
                        'days_since_last'   => $daysSinceLast,
                        'estimated_refill'  => now()->addDays($daysUntilRefill)->toDateString(),
                        'urgency'           => $daysUntilRefill <= 2 ? 'high' : 'medium',
                        'purchase_count'    => count($purchases),
                        'message' => "Time to restock {$lastPurchase['product_name']}? Based on your pattern, you'll need more in {$daysUntilRefill} days.",
                    ];
                }
            }

            usort($reminders, fn($a, $b) => $a['days_since_last'] <=> $b['days_since_last']);

            return [
                'success'       => true,
                'total_tracked' => count($purchaseHistory),
                'reminders_due' => count($reminders),
                'reminders'     => array_slice($reminders, 0, 50),
                'channels'      => ['email', 'push', 'sms'],
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedMarketing::replenishment error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC20: Milestone & anniversary automation — birthday, membership anniversary, etc.
     */
    public function milestoneAutomation(int $tenantId): array
    {
        try {
            $today = now()->format('m-d');
            $tomorrow = now()->addDay()->format('m-d');
            $nextWeek = now()->addDays(7)->format('m-d');

            // Get customer data with dates
            $customers = DB::connection('mongodb')
                ->table('synced_customers')
                ->where('tenant_id', $tenantId)
                ->get();

            $milestones = [];
            foreach ($customers as $customerRaw) {
                $customer = (array) $customerRaw;
                $email = $customer['email'] ?? null;
                if (!$email) continue;

                // Birthday check
                $birthday = $customer['birthday'] ?? $customer['date_of_birth'] ?? null;
                if ($birthday) {
                    $bDay = date('m-d', strtotime($birthday));
                    if ($bDay === $today || $bDay === $tomorrow) {
                        $milestones[] = [
                            'customer_email' => $email,
                            'type'           => 'birthday',
                            'date'           => $birthday,
                            'is_today'       => $bDay === $today,
                            'offer'          => ['type' => 'discount', 'value' => 20, 'unit' => 'percent'],
                            'message'        => '🎂 Happy Birthday! Here\'s 20% off to celebrate YOU!',
                        ];
                    }
                }

                // Membership anniversary
                $joinDate = $customer['created_at'] ?? null;
                if ($joinDate) {
                    $joinDateStr = $joinDate instanceof \Carbon\Carbon || $joinDate instanceof \Illuminate\Support\Carbon
                        ? $joinDate->format('Y-m-d')
                        : (string) $joinDate;
                    $anniversaryDay = date('m-d', strtotime($joinDateStr));
                    if ($anniversaryDay === $today) {
                        $years = now()->diffInYears($joinDateStr);
                        $milestones[] = [
                            'customer_email' => $email,
                            'type'           => 'anniversary',
                            'date'           => $joinDate,
                            'years'          => $years,
                            'offer'          => ['type' => 'discount', 'value' => min($years * 5, 25), 'unit' => 'percent'],
                            'message'        => "🎉 Happy {$years}-year anniversary! Enjoy " . min($years * 5, 25) . '% off!',
                        ];
                    }
                }

                // Order milestone check
                $orderCount = DB::connection('mongodb')
                    ->table('synced_orders')
                    ->where('tenant_id', $tenantId)
                    ->where('customer_email', $email)
                    ->count();

                $milestoneOrders = [10, 25, 50, 100];
                if (in_array($orderCount, $milestoneOrders)) {
                    $milestones[] = [
                        'customer_email' => $email,
                        'type'           => 'order_milestone',
                        'count'          => $orderCount,
                        'offer'          => ['type' => 'gift_card', 'value' => $orderCount >= 50 ? 50 : 25, 'unit' => 'currency'],
                        'message'        => "🏆 Wow, {$orderCount} orders! Here's a \$" . ($orderCount >= 50 ? 50 : 25) . " gift card as a thank you!",
                    ];
                }
            }

            return [
                'success'      => true,
                'total_milestones' => count($milestones),
                'by_type'      => [
                    'birthday'        => collect($milestones)->where('type', 'birthday')->count(),
                    'anniversary'     => collect($milestones)->where('type', 'anniversary')->count(),
                    'order_milestone' => collect($milestones)->where('type', 'order_milestone')->count(),
                ],
                'milestones'   => array_slice($milestones, 0, 50),
                'auto_send'    => true,
                'channels'     => ['email', 'push'],
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedMarketing::milestones error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

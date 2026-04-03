<?php
declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * CdpAdvancedService — Next-gen CDP & Analytics operations.
 *
 * UC41: Offline-to-Online Stitching — match POS data to web visitors
 * UC42: Zombie Account Reactivation — identify & revive dormant accounts
 * UC43: Product Affinity Mapping — "frequently bought together" graph
 * UC44: Zero-Party Data Collection Engine — preference center, quizzes
 * UC45: Refund Impact Analyzer — track true cost of refunds on LTV
 */
class CdpAdvancedService
{
    /**
     * UC41: Offline-to-online identity stitching.
     */
    public function offlineOnlineStitching(int|string $tenantId): array
    {
        try {
            // SAFETY: bounded query
            $offlineOrders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('channel', 'pos')
                ->where('created_at', '>=', now()->subDays(90)->toDateTimeString())
                ->take(10000)
                ->get();

            // SAFETY: bounded query
            $onlineVisitors = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('customer_email')
                ->where('created_at', '>=', now()->subDays(90)->toDateTimeString())
                ->take(10000)
                ->get()
                ->groupBy('customer_email');

            $stitchedProfiles = [];
            $unmatched = 0;

            foreach ($offlineOrders as $order) {
                $email = $order['customer_email'] ?? null;
                $phone = $order['customer_phone'] ?? null;

                if ($email && isset($onlineVisitors[$email])) {
                    $onlineEvents = $onlineVisitors[$email];

                    $stitchedProfiles[] = [
                        'email'          => $email,
                        'offline_orders' => 1,
                        'offline_revenue' => $order['total'] ?? 0,
                        'online_sessions' => $onlineEvents->unique('session_id')->count(),
                        'online_events'  => $onlineEvents->count(),
                        'first_online'   => $onlineEvents->min('created_at'),
                        'last_offline'   => $order['created_at'],
                        'stitch_method'  => 'email',
                        'unified_ltv'    => ($order['total'] ?? 0) + $onlineEvents->where('event', 'purchase')->sum('revenue'),
                    ];
                } else {
                    $unmatched++;
                }
            }

            // Aggregate by email
            $unified = collect($stitchedProfiles)->groupBy('email')->map(function ($profiles, $email) {
                return [
                    'email'           => $email,
                    'offline_orders'  => $profiles->sum('offline_orders'),
                    'offline_revenue' => round($profiles->sum('offline_revenue'), 2),
                    'online_sessions' => $profiles->max('online_sessions'),
                    'unified_ltv'     => round($profiles->sum('unified_ltv'), 2),
                    'stitch_method'   => 'email',
                ];
            })->values()->toArray();

            return [
                'success'           => true,
                'total_offline'     => $offlineOrders->count(),
                'stitched_count'    => count($unified),
                'unmatched_count'   => $unmatched,
                'stitch_rate'       => $offlineOrders->count() > 0
                    ? round(count($unified) / $offlineOrders->count() * 100, 1) : 0,
                'profiles'          => array_slice($unified, 0, 50),
                'total_unified_ltv' => round(collect($unified)->sum('unified_ltv'), 2),
            ];
        } catch (\Exception $e) {
            Log::error("CdpAdvanced::stitching error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC42: Identify zombie accounts and generate reactivation strategies.
     */
    public function zombieAccountReactivation(int|string $tenantId): array
    {
        try {
            $sixMonthsAgo = now()->subMonths(6)->toDateTimeString();
            $oneYearAgo = now()->subMonths(12)->toDateTimeString();

            // SAFETY: bounded query
            $allCustomers = DB::connection('mongodb')
                ->table('synced_customers')
                ->where('tenant_id', $tenantId)
                ->take(10000)
                ->get();

            // Pre-fetch all order stats in one aggregation instead of N+1 queries per customer
            $orderStats = DB::connection('mongodb')
                ->table('synced_orders')
                ->raw(function ($collection) use ($tenantId) {
                    return $collection->aggregate([
                        ['$match' => ['tenant_id' => $tenantId]],
                        ['$group' => [
                            '_id' => '$customer_email',
                            'last_order_date' => ['$max' => '$created_at'],
                            'total_spent' => ['$sum' => '$total'],
                            'order_count' => ['$sum' => 1],
                        ]],
                    ], ['maxTimeMS' => 30000]);
                });

            // Index order stats by email for O(1) lookup
            $orderStatsByEmail = [];
            foreach ($orderStats as $stat) {
                $stat = (array) $stat;
                $orderStatsByEmail[$stat['_id']] = $stat;
            }

            $zombies = [];
            foreach ($allCustomers as $customer) {
                $email = $customer['email'] ?? null;
                if (!$email) continue;

                $stats = $orderStatsByEmail[$email] ?? null;
                if (!$stats) continue;

                $lastOrderDate = $stats['last_order_date'] ?? null;
                if (!$lastOrderDate || $lastOrderDate >= $sixMonthsAgo) continue;

                $totalSpent = $stats['total_spent'] ?? 0;
                $orderCount = $stats['order_count'] ?? 0;

                $daysSilent = now()->diffInDays($lastOrderDate);
                $tier = $totalSpent >= 1000 ? 'high_value' : ($totalSpent >= 300 ? 'medium_value' : 'low_value');

                $zombies[] = [
                    'email'        => $email,
                    'first_name'   => $customer['first_name'] ?? '',
                    'total_spent'  => round($totalSpent, 2),
                    'order_count'  => $orderCount,
                    'last_order'   => $lastOrderDate,
                    'days_silent'  => $daysSilent,
                    'value_tier'   => $tier,
                    'reactivation_strategy' => $this->getReactivationStrategy($tier, $daysSilent),
                ];
            }

            usort($zombies, fn($a, $b) => $b['total_spent'] <=> $a['total_spent']);

            return [
                'success'       => true,
                'zombie_count'  => count($zombies),
                'by_tier'       => [
                    'high_value'   => collect($zombies)->where('value_tier', 'high_value')->count(),
                    'medium_value' => collect($zombies)->where('value_tier', 'medium_value')->count(),
                    'low_value'    => collect($zombies)->where('value_tier', 'low_value')->count(),
                ],
                'total_dormant_revenue' => round(collect($zombies)->sum('total_spent'), 2),
                'zombies'       => array_slice($zombies, 0, 50),
                'reactivation_playbook' => [
                    ['day' => 0, 'action' => 'Send "We miss you" email with personalized picks'],
                    ['day' => 7, 'action' => 'Push notification with new arrivals in favorite categories'],
                    ['day' => 14, 'action' => 'Exclusive comeback discount (10-20% based on tier)'],
                    ['day' => 30, 'action' => 'Final re-engagement with top offer + survey'],
                    ['day' => 60, 'action' => 'Move to "sunset" segment — reduce frequency'],
                ],
            ];
        } catch (\Exception $e) {
            Log::error("CdpAdvanced::zombieAccounts error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC43: Product affinity mapping — "frequently bought together" graph.
     */
    public function productAffinityMapping(int|string $tenantId, ?string $productId = null): array
    {
        try {
            $query = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subDays(180)->toDateTimeString());

            // SAFETY: bounded query
            $orders = $query->take(50000)->get();

            // Build co-purchase matrix
            $coPurchase = [];
            $productNames = [];

            foreach ($orders as $orderRaw) {
                $order = (array) $orderRaw;
                $orderItems = array_map(fn($i) => (array) $i, (array) ($order['items'] ?? []));
                $items = collect($orderItems)->pluck('product_id')->filter()->toArray();
                foreach ($orderItems as $item) {
                    $pid = $item['product_id'] ?? '';
                    if ($pid) $productNames[$pid] = $item['name'] ?? '';
                }

                // Generate pairs
                for ($i = 0; $i < count($items); $i++) {
                    for ($j = $i + 1; $j < count($items); $j++) {
                        $pair = [$items[$i], $items[$j]];
                        sort($pair);
                        $key = implode(':', $pair);
                        $coPurchase[$key] = ($coPurchase[$key] ?? 0) + 1;
                    }
                }
            }

            arsort($coPurchase);

            // If specific product requested, filter to its affinities
            if ($productId) {
                $affinities = [];
                foreach ($coPurchase as $key => $count) {
                    [$a, $b] = explode(':', $key);
                    if ($a === $productId || $b === $productId) {
                        $otherId = $a === $productId ? $b : $a;
                        $affinities[] = [
                            'product_id'   => $otherId,
                            'product_name' => $productNames[$otherId] ?? 'Unknown',
                            'co_purchase_count' => $count,
                            'affinity_score'    => round($count / max(1, $orders->count()) * 100, 2),
                        ];
                    }
                }

                usort($affinities, fn($a, $b) => $b['co_purchase_count'] <=> $a['co_purchase_count']);

                return [
                    'success'    => true,
                    'product_id' => $productId,
                    'product_name' => $productNames[$productId] ?? '',
                    'affinities' => array_slice($affinities, 0, 20),
                ];
            }

            // Global top affinities
            $topPairs = [];
            foreach (array_slice($coPurchase, 0, 30, true) as $key => $count) {
                [$a, $b] = explode(':', $key);
                $topPairs[] = [
                    'product_a'    => ['id' => $a, 'name' => $productNames[$a] ?? ''],
                    'product_b'    => ['id' => $b, 'name' => $productNames[$b] ?? ''],
                    'co_purchase_count' => $count,
                    'affinity_score'    => round($count / max(1, $orders->count()) * 100, 2),
                ];
            }

            return [
                'success'       => true,
                'total_pairs'   => count($coPurchase),
                'orders_analyzed' => $orders->count(),
                'top_affinities' => $topPairs,
            ];
        } catch (\Exception $e) {
            Log::error("CdpAdvanced::affinityMapping error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC44: Zero-party data collection — preference center data.
     */
    public function zeroPartyDataEngine(int|string $tenantId, ?string $customerEmail = null): array
    {
        try {
            if ($customerEmail) {
                // Get existing preferences
                $prefs = DB::connection('mongodb')
                    ->table('customer_preferences')
                    ->where('tenant_id', $tenantId)
                    ->where('email', $customerEmail)
                    ->first();

                return [
                    'success'    => true,
                    'customer'   => $customerEmail,
                    'preferences' => $prefs ? ($prefs['preferences'] ?? []) : [],
                    'quiz_completed' => isset($prefs['quiz_completed']) && $prefs['quiz_completed'],
                    'preference_center' => [
                        'favorite_categories' => $prefs['favorite_categories'] ?? [],
                        'style_preferences'   => $prefs['style_preferences'] ?? [],
                        'communication_prefs' => $prefs['communication_prefs'] ?? ['email' => true, 'sms' => false, 'push' => true],
                        'budget_range'        => $prefs['budget_range'] ?? null,
                        'interests'           => $prefs['interests'] ?? [],
                    ],
                    'suggestion_quiz' => $this->buildPreferenceQuiz(),
                ];
            }

            // Tenant-wide overview
            $totalCollected = DB::connection('mongodb')
                ->table('customer_preferences')
                ->where('tenant_id', $tenantId)
                ->count();

            $totalCustomers = DB::connection('mongodb')
                ->table('synced_customers')
                ->where('tenant_id', $tenantId)
                ->count();

            return [
                'success'          => true,
                'total_profiles'   => $totalCollected,
                'total_customers'  => $totalCustomers,
                'collection_rate'  => $totalCustomers > 0 ? round($totalCollected / $totalCustomers * 100, 1) : 0,
                'data_points'      => [
                    'categories'       => 'Favorite categories',
                    'styles'           => 'Style preferences',
                    'communication'    => 'Channel preferences',
                    'budget'           => 'Budget range',
                    'interests'        => 'Personal interests',
                ],
                'collection_methods' => [
                    'preference_center' => 'Settings page preference center',
                    'style_quiz'        => 'Interactive style quiz widget',
                    'post_purchase'     => 'Post-purchase feedback forms',
                    'progressive'       => 'Progressive profiling in emails',
                ],
            ];
        } catch (\Exception $e) {
            Log::error("CdpAdvanced::zeroPartyData error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC45: Analyze real cost of refunds on customer LTV.
     */
    public function refundImpactAnalyzer(int|string $tenantId, string $dateRange = '90d'): array
    {
        try {
            $days = (int) filter_var($dateRange, FILTER_SANITIZE_NUMBER_INT) ?: 90;

            // SAFETY: bounded query
            $orders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subDays($days)->toDateTimeString())
                ->take(50000)
                ->get();

            $totalRevenue = 0;
            $totalRefunds = 0;
            $refundedCustomers = [];
            $nonRefundCustomers = [];

            foreach ($orders as $order) {
                $email = $order['customer_email'] ?? '';
                $total = $order['total'] ?? 0;
                $totalRevenue += $total;

                if (in_array($order['status'] ?? '', ['refunded', 'returned'])) {
                    $totalRefunds += $total;
                    $refundedCustomers[$email] = ($refundedCustomers[$email] ?? 0) + $total;
                } else {
                    $nonRefundCustomers[$email] = ($nonRefundCustomers[$email] ?? 0) + $total;
                }
            }

            // Pre-fetch post-refund order counts in one batch query instead of N+1
            $refunderEmails = array_keys($refundedCustomers);
            $postRefundOrders = [];
            if (!empty($refunderEmails)) {
                $postRefundCounts = DB::connection('mongodb')
                    ->table('synced_orders')
                    ->raw(function ($collection) use ($tenantId, $refunderEmails) {
                        return $collection->aggregate([
                            ['$match' => [
                                'tenant_id' => $tenantId,
                                'customer_email' => ['$in' => $refunderEmails],
                                'status' => ['$nin' => ['refunded', 'returned']],
                            ]],
                            ['$group' => [
                                '_id' => '$customer_email',
                                'count' => ['$sum' => 1],
                            ]],
                        ], ['maxTimeMS' => 30000]);
                    });
                foreach ($postRefundCounts as $row) {
                    $row = (array) $row;
                    $postRefundOrders[$row['_id']] = $row['count'];
                }
                // Fill in zeros for refunders with no post-refund orders
                foreach ($refunderEmails as $email) {
                    if (!isset($postRefundOrders[$email])) {
                        $postRefundOrders[$email] = 0;
                    }
                }
            }

            $refunderReturnRate = count($refundedCustomers) > 0
                ? count(array_filter($postRefundOrders, fn($c) => $c > 0)) / count($refundedCustomers) * 100
                : 0;

            $avgLtvRefunders = count($refundedCustomers) > 0
                ? array_sum($refundedCustomers) / count($refundedCustomers)
                : 0;
            $avgLtvNonRefunders = count($nonRefundCustomers) > 0
                ? array_sum($nonRefundCustomers) / count($nonRefundCustomers)
                : 0;

            return [
                'success'          => true,
                'period'           => $dateRange,
                'total_revenue'    => round($totalRevenue, 2),
                'total_refunds'    => round($totalRefunds, 2),
                'refund_rate'      => $totalRevenue > 0 ? round($totalRefunds / $totalRevenue * 100, 1) : 0,
                'unique_refunders' => count($refundedCustomers),
                'refunder_return_rate' => round($refunderReturnRate, 1),
                'avg_ltv_refunders'    => round($avgLtvRefunders, 2),
                'avg_ltv_non_refunders' => round($avgLtvNonRefunders, 2),
                'ltv_gap_pct'      => $avgLtvNonRefunders > 0
                    ? round(($avgLtvNonRefunders - $avgLtvRefunders) / $avgLtvNonRefunders * 100, 1) : 0,
                'insight'          => $refunderReturnRate > 50
                    ? 'Good news: Most refund customers come back. Your refund policy builds trust.'
                    : 'Opportunity: Improve post-refund engagement to retain these customers.',
                'recommendations'  => [
                    'Fast refund processing increases return rate by ~30%',
                    'Follow up refunds with personalized recommendations',
                    'Offer exchange before refund to retain revenue',
                    'Analyze top refund reasons to reduce returns proactively',
                ],
            ];
        } catch (\Exception $e) {
            Log::error("CdpAdvanced::refundImpact error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Helpers ──────────────────────────────────────────

    private function getReactivationStrategy(string $tier, int|float $daysSilent): array
    {
        return match ($tier) {
            'high_value' => [
                'priority'  => 'critical',
                'discount'  => 25,
                'channel'   => 'personal_call',
                'message'   => 'We miss our VIP! Here\'s an exclusive 25% off to welcome you back.',
            ],
            'medium_value' => [
                'priority'  => 'high',
                'discount'  => 15,
                'channel'   => 'email_sms',
                'message'   => 'It\'s been a while! Enjoy 15% off your next order.',
            ],
            default => [
                'priority'  => 'normal',
                'discount'  => 10,
                'channel'   => 'email',
                'message'   => 'We have new arrivals you might love! Plus 10% off.',
            ],
        };
    }

    private function buildPreferenceQuiz(): array
    {
        return [
            'questions' => [
                ['q' => 'What categories interest you most?', 'type' => 'multi_select',
                 'options' => ['Fashion', 'Electronics', 'Home & Garden', 'Beauty', 'Sports', 'Books']],
                ['q' => 'What\'s your typical budget per order?', 'type' => 'single_select',
                 'options' => ['Under $50', '$50-$100', '$100-$200', '$200+']],
                ['q' => 'How often would you like to hear from us?', 'type' => 'single_select',
                 'options' => ['Daily deals', 'Weekly digest', 'Monthly highlights', 'Only sales events']],
                ['q' => 'What matters most to you?', 'type' => 'rank',
                 'options' => ['Price', 'Quality', 'Brand', 'Sustainability', 'Fast shipping']],
            ],
            'incentive' => '10% off for completing the quiz',
        ];
    }
}

<?php

namespace Modules\BusinessIntelligence\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cross-Module Intelligence Service
 *
 * Reads from: marketing_campaigns (MySQL), synced_orders, search_logs,
 *             chatbot_conversations, chatbot_messages, tracking_events
 * Powers: Marketing Attribution, Search→Revenue, Chatbot→Conversion, Customer 360
 */
class CrossModuleIntelService
{
    private function tid(int|string $t): array
    {
        return [(int) $t, (string) $t];
    }

    /* ══════════════════════════════════════════════════════════════
     *  5.1 — MARKETING → REVENUE ATTRIBUTION
     * ══════════════════════════════════════════════════════════════ */

    public function marketingAttribution(int $tenantId): array
    {
        return Cache::remember("bi:cross:mktg:{$tenantId}", now()->addMinutes(10), fn () => $this->computeMarketingAttribution($tenantId));
    }

    private function computeMarketingAttribution(int $tenantId): array
    {
        try {
            $tids = $this->tid($tenantId);

            // Get campaigns from MySQL
            $campaigns = DB::table('marketing_campaigns')
                ->where('tenant_id', $tenantId)
                ->get();

            // Get order totals
            $totalRevenue = DB::connection('mongodb')->table('synced_orders')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                    ['$group' => ['_id' => null, 'total' => ['$sum' => '$grand_total'], 'count' => ['$sum' => 1]]],
                ], ['maxTimeMS' => 30000]));
            $totalRev = collect($totalRevenue)->first()['total'] ?? 0;
            $totalOrd = collect($totalRevenue)->first()['count'] ?? 0;

            $campaignData = $campaigns->map(function ($c) use ($totalRev) {
                $sent      = $c->total_sent ?? 0;
                $delivered = $c->total_delivered ?? 0;
                $opened    = $c->total_opened ?? 0;
                $clicked   = $c->total_clicked ?? 0;
                $converted = $c->total_converted ?? 0;
                $revenue   = (float) ($c->revenue ?? 0);

                return [
                    'id'              => $c->id,
                    'name'            => $c->name ?? $c->subject ?? 'Campaign #' . $c->id,
                    'type'            => $c->type ?? 'email',
                    'sent_at'         => $c->sent_at ?? null,
                    'sent'            => $sent,
                    'delivered'       => $delivered,
                    'opened'          => $opened,
                    'clicked'         => $clicked,
                    'converted'       => $converted,
                    'revenue'         => $revenue,
                    'open_rate'       => $delivered > 0 ? round($opened / $delivered * 100, 1) : 0,
                    'click_rate'      => $opened > 0 ? round($clicked / $opened * 100, 1) : 0,
                    'conversion_rate' => $clicked > 0 ? round($converted / $clicked * 100, 1) : 0,
                    'revenue_share'   => $totalRev > 0 ? round($revenue / $totalRev * 100, 2) : 0,
                    'roas'            => $sent > 0 ? round($revenue / ($sent * 0.5), 2) : 0, // Assume ₹0.50 per send
                ];
            })->sortByDesc('revenue')->values();

            $totalCampaignRevenue = $campaignData->sum('revenue');

            return [
                'campaigns'               => $campaignData->all(),
                'total_campaign_revenue'   => round($totalCampaignRevenue, 2),
                'total_store_revenue'      => round($totalRev, 2),
                'attributed_pct'           => $totalRev > 0 ? round($totalCampaignRevenue / $totalRev * 100, 1) : 0,
                'total_campaigns'          => $campaigns->count(),
                'avg_campaign_revenue'     => $campaigns->count() > 0 ? round($totalCampaignRevenue / $campaigns->count(), 2) : 0,
                'funnel' => [
                    'sent'      => $campaigns->sum('total_sent'),
                    'delivered' => $campaigns->sum('total_delivered'),
                    'opened'    => $campaigns->sum('total_opened'),
                    'clicked'   => $campaigns->sum('total_clicked'),
                    'converted' => $campaigns->sum('total_converted'),
                    'revenue'   => round($totalCampaignRevenue, 2),
                ],
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] CrossModuleIntelService::marketingAttribution failed: ' . $e->getMessage());
            return ['campaigns' => [], 'total_campaign_revenue' => 0, 'total_store_revenue' => 0, 'attributed_pct' => 0, 'total_campaigns' => 0, 'avg_campaign_revenue' => 0, 'funnel' => ['sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'converted' => 0, 'revenue' => 0]];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  5.2 — SEARCH → REVENUE CORRELATION
     * ══════════════════════════════════════════════════════════════ */

    public function searchRevenue(int $tenantId): array
    {
        try {
            $tids = $this->tid($tenantId);

            // Search metrics
            $searchAgg = DB::connection('mongodb')->table('search_logs')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids]]],
                    ['$group' => [
                        '_id' => null,
                        'total_searches'      => ['$sum' => 1],
                        'with_clicks'         => ['$sum' => ['$cond' => [['$ne' => ['$clicked_product_id', null]], 1, 0]]],
                        'with_conversions'    => ['$sum' => ['$cond' => ['$converted', 1, 0]]],
                        'avg_response_time'   => ['$avg' => '$response_time_ms'],
                        'zero_results'        => ['$sum' => ['$cond' => [['$eq' => ['$results_count', 0]], 1, 0]]],
                    ]],
                ], ['maxTimeMS' => 30000]));

            $metrics = collect($searchAgg)->first();
            $totalSearches = $metrics['total_searches'] ?? 0;

            // Top search queries by volume + conversion
            $topQueries = DB::connection('mongodb')->table('search_logs')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids], 'query' => ['$ne' => null]]],
                    ['$group' => [
                        '_id'         => ['$toLower' => '$query'],
                        'searches'    => ['$sum' => 1],
                        'clicks'      => ['$sum' => ['$cond' => [['$ne' => ['$clicked_product_id', null]], 1, 0]]],
                        'conversions' => ['$sum' => ['$cond' => ['$converted', 1, 0]]],
                        'order_ids'   => ['$addToSet' => '$conversion_order_id'],
                    ]],
                    ['$sort' => ['searches' => -1]],
                    ['$limit' => 20],
                ], ['maxTimeMS' => 30000]));

            // Match converted search order IDs to get revenue
            $allOrderIds = collect($topQueries)->pluck('order_ids')->flatten()->filter()->unique()->values()->all();

            $orderRevMap = [];
            if (!empty($allOrderIds)) {
                $orderRev = DB::connection('mongodb')->table('synced_orders')
                    ->whereIn('tenant_id', $tids)
                    ->whereIn('external_id', $allOrderIds)
                    ->get(['external_id', 'grand_total']);

                foreach ($orderRev as $o) {
                    $orderRevMap[$o['external_id'] ?? ''] = $o['grand_total'] ?? 0;
                }
            }

            $queries = collect($topQueries)->map(function ($r) use ($orderRevMap) {
                $revenue = 0;
                foreach (($r['order_ids'] ?? []) as $oid) {
                    $revenue += $orderRevMap[$oid] ?? 0;
                }
                return [
                    'query'           => $r['_id'],
                    'searches'        => $r['searches'],
                    'clicks'          => $r['clicks'],
                    'conversions'     => $r['conversions'],
                    'revenue'         => round($revenue, 2),
                    'click_rate'      => $r['searches'] > 0 ? round($r['clicks'] / $r['searches'] * 100, 1) : 0,
                    'conversion_rate' => $r['clicks'] > 0 ? round($r['conversions'] / $r['clicks'] * 100, 1) : 0,
                ];
            })->values()->all();

            // Zero result queries
            $zeroResults = DB::connection('mongodb')->table('search_logs')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids], 'results_count' => 0]],
                    ['$group' => ['_id' => ['$toLower' => '$query'], 'count' => ['$sum' => 1]]],
                    ['$sort' => ['count' => -1]],
                    ['$limit' => 10],
                ], ['maxTimeMS' => 30000]));

            return [
                'summary' => [
                    'total_searches'    => $totalSearches,
                    'click_rate'        => $totalSearches > 0 ? round(($metrics['with_clicks'] ?? 0) / $totalSearches * 100, 1) : 0,
                    'conversion_rate'   => $totalSearches > 0 ? round(($metrics['with_conversions'] ?? 0) / $totalSearches * 100, 1) : 0,
                    'avg_response_ms'   => round($metrics['avg_response_time'] ?? 0),
                    'zero_result_rate'  => $totalSearches > 0 ? round(($metrics['zero_results'] ?? 0) / $totalSearches * 100, 1) : 0,
                ],
                'top_queries'     => $queries,
                'zero_results'    => collect($zeroResults)->map(fn ($r) => ['query' => $r['_id'], 'count' => $r['count']])->values()->all(),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] CrossModuleIntelService::searchRevenue failed: ' . $e->getMessage());
            return ['summary' => ['total_searches' => 0, 'click_rate' => 0, 'conversion_rate' => 0, 'avg_response_ms' => 0, 'zero_result_rate' => 0], 'top_queries' => [], 'zero_results' => []];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  5.3 — CHATBOT → CONVERSION IMPACT
     * ══════════════════════════════════════════════════════════════ */

    public function chatbotImpact(int $tenantId): array
    {
        try {
            $tids = $this->tid($tenantId);

            // Chatbot conversations summary
            $chatSummary = DB::connection('mongodb')->table('chatbot_conversations')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids]]],
                    ['$group' => [
                        '_id'              => null,
                        'total'            => ['$sum' => 1],
                        'resolved'         => ['$sum' => ['$cond' => [['$eq' => ['$status', 'resolved']], 1, 0]]],
                        'with_email'       => ['$sum' => ['$cond' => [['$ne' => ['$customer_email', null]], 1, 0]]],
                        'avg_satisfaction' => ['$avg' => '$satisfaction_score'],
                        'sessions'         => ['$addToSet' => '$session_id'],
                        'emails'           => ['$addToSet' => '$customer_email'],
                    ]],
                ], ['maxTimeMS' => 30000]));

            $cs = collect($chatSummary)->first();
            $totalConvos  = $cs['total'] ?? 0;
            $chatSessions = collect($cs['sessions'] ?? [])->filter()->unique();
            $chatEmails   = collect($cs['emails'] ?? [])->filter()->unique();

            // Chatbot message actions (add_to_cart, etc.)
            $actions = DB::connection('mongodb')->table('chatbot_messages')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids], 'action' => ['$ne' => null]]],
                    ['$group' => ['_id' => '$action', 'count' => ['$sum' => 1]]],
                    ['$sort' => ['count' => -1]],
                ], ['maxTimeMS' => 30000]));

            // Get chatbot user orders
            $chatbotCustomerRevenue = 0;
            $chatbotCustomerOrders  = 0;
            if ($chatEmails->isNotEmpty()) {
                $custOrders = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn ($col) => $col->aggregate([
                        ['$match' => [
                            'tenant_id'      => ['$in' => $tids],
                            'customer_email' => ['$in' => $chatEmails->values()->all()],
                            'status'         => ['$nin' => ['cancelled', 'canceled']],
                        ]],
                        ['$group' => ['_id' => null, 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                    ], ['maxTimeMS' => 30000]));
                $co = collect($custOrders)->first();
                $chatbotCustomerRevenue = $co['revenue'] ?? 0;
                $chatbotCustomerOrders  = $co['orders'] ?? 0;
            }

            // Non-chatbot customer orders for comparison
            $totalOrderAgg = DB::connection('mongodb')->table('synced_orders')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                    ['$group' => ['_id' => null, 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                ], ['maxTimeMS' => 30000]));
            $ta = collect($totalOrderAgg)->first();
            $totalRevenue = $ta['revenue'] ?? 0;
            $totalOrders  = $ta['orders'] ?? 0;

            $nonChatRevenue = $totalRevenue - $chatbotCustomerRevenue;
            $nonChatOrders  = $totalOrders - $chatbotCustomerOrders;

            // Intent distribution
            $intents = DB::connection('mongodb')->table('chatbot_conversations')
                ->raw(fn ($col) => $col->aggregate([
                    ['$match' => ['tenant_id' => ['$in' => $tids], 'intent' => ['$ne' => null]]],
                    ['$group' => ['_id' => '$intent', 'count' => ['$sum' => 1]]],
                    ['$sort' => ['count' => -1]],
                    ['$limit' => 10],
                ], ['maxTimeMS' => 30000]));

            return [
                'summary' => [
                    'total_conversations'  => $totalConvos,
                    'resolved'             => $cs['resolved'] ?? 0,
                    'resolution_rate'      => $totalConvos > 0 ? round(($cs['resolved'] ?? 0) / $totalConvos * 100, 1) : 0,
                    'avg_satisfaction'     => round($cs['avg_satisfaction'] ?? 0, 1),
                    'unique_users'         => $chatEmails->count(),
                ],
                'revenue_impact' => [
                    'chatbot_customer_revenue' => round($chatbotCustomerRevenue, 2),
                    'chatbot_customer_orders'  => $chatbotCustomerOrders,
                    'chatbot_customer_aov'     => $chatbotCustomerOrders > 0 ? round($chatbotCustomerRevenue / $chatbotCustomerOrders, 2) : 0,
                    'non_chat_aov'             => $nonChatOrders > 0 ? round($nonChatRevenue / $nonChatOrders, 2) : 0,
                    'aov_lift_pct'             => $nonChatOrders > 0 && $chatbotCustomerOrders > 0
                        ? round(
                            (($chatbotCustomerRevenue / $chatbotCustomerOrders) - ($nonChatRevenue / $nonChatOrders))
                            / ($nonChatRevenue / $nonChatOrders) * 100,
                            1
                        ) : 0,
                    'revenue_share'            => $totalRevenue > 0 ? round($chatbotCustomerRevenue / $totalRevenue * 100, 1) : 0,
                ],
                'actions' => collect($actions)->map(fn ($r) => ['action' => $r['_id'], 'count' => $r['count']])->values()->all(),
                'intents' => collect($intents)->map(fn ($r) => ['intent' => $r['_id'], 'count' => $r['count']])->values()->all(),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] CrossModuleIntelService::chatbotImpact failed: ' . $e->getMessage());
            return ['summary' => ['total_conversations' => 0, 'resolved' => 0, 'resolution_rate' => 0, 'avg_satisfaction' => 0, 'unique_users' => 0], 'revenue_impact' => ['chatbot_customer_revenue' => 0, 'chatbot_customer_orders' => 0, 'chatbot_customer_aov' => 0, 'non_chat_aov' => 0, 'aov_lift_pct' => 0, 'revenue_share' => 0], 'actions' => [], 'intents' => []];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC04 — CUSTOMER 360 (unified view for a single customer)
     * ══════════════════════════════════════════════════════════════ */

    public function customer360(int $tenantId, string $email): array
    {
        try {
            $tids = $this->tid($tenantId);

            // Profile from synced_customers
            $profile = DB::connection('mongodb')->table('synced_customers')
                ->whereIn('tenant_id', $tids)
                ->where('email', $email)
                ->first();

            // CDP profile if exists
            $cdp = DB::connection('mongodb')->table('cdp_profiles')
                ->whereIn('tenant_id', $tids)
                ->where('email', $email)
                ->first();

            // Orders
            $orders = DB::connection('mongodb')->table('synced_orders')
                ->whereIn('tenant_id', $tids)
                ->where('customer_email', $email)
                ->orderBy('created_at', 'desc')
                ->get();

            $orderList = collect($orders);
            $nonCancelled = $orderList->whereNotIn('status', ['cancelled', 'canceled']);

            // Search history
            $searches = DB::connection('mongodb')->table('search_logs')
                ->whereIn('tenant_id', $tids)
                ->where('customer_email', $email)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get(['query', 'results_count', 'clicked_product_id', 'converted', 'created_at']);

            // Chat history
            $chats = DB::connection('mongodb')->table('chatbot_conversations')
                ->whereIn('tenant_id', $tids)
                ->where('customer_email', $email)
                ->orderBy('started_at', 'desc')
                ->limit(10)
                ->get(['intent', 'status', 'satisfaction_score', 'started_at']);

            return [
                'profile' => [
                    'email'     => $email,
                    'name'      => $profile['name'] ?? (($profile['firstname'] ?? '') . ' ' . ($profile['lastname'] ?? '')),
                    'gender'    => $profile['gender'] ?? null,
                    'created'   => $profile['created_at'] ?? null,
                ],
                'cdp' => $cdp ? [
                    'rfm_segment'    => $cdp['computed']['rfm_segment'] ?? null,
                    'churn_risk'     => $cdp['computed']['churn_risk'] ?? null,
                    'predicted_ltv'  => $cdp['predictions']['predicted_ltv'] ?? null,
                    'total_sessions' => $cdp['behavioural']['total_sessions'] ?? null,
                ] : null,
                'orders' => [
                    'total'     => $nonCancelled->count(),
                    'revenue'   => round($nonCancelled->sum('grand_total'), 2),
                    'aov'       => $nonCancelled->count() > 0 ? round($nonCancelled->sum('grand_total') / $nonCancelled->count(), 2) : 0,
                    'first'     => $orderList->last()['created_at'] ?? null,
                    'last'      => $orderList->first()['created_at'] ?? null,
                    'recent'    => $orderList->take(5)->map(fn ($o) => [
                        'order_number' => $o['order_number'] ?? $o['external_id'] ?? '-',
                        'total'        => round($o['grand_total'] ?? 0, 2),
                        'status'       => $o['status'] ?? 'unknown',
                        'date'         => $o['created_at'] ?? null,
                    ])->values()->all(),
                ],
                'searches' => collect($searches)->map(fn ($s) => [
                    'query'     => $s['query'] ?? '',
                    'results'   => $s['results_count'] ?? 0,
                    'converted' => (bool) ($s['converted'] ?? false),
                ])->values()->all(),
                'chats' => collect($chats)->map(fn ($c) => [
                    'intent'       => $c['intent'] ?? null,
                    'status'       => $c['status'] ?? null,
                    'satisfaction' => $c['satisfaction_score'] ?? null,
                    'date'         => $c['started_at'] ?? null,
                ])->values()->all(),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BI] CrossModuleIntelService::customer360 failed: ' . $e->getMessage());
            return ['profile' => ['email' => $email, 'name' => '', 'gender' => null, 'created' => null], 'cdp' => null, 'orders' => ['total' => 0, 'revenue' => 0, 'aov' => 0, 'first' => null, 'last' => null, 'recent' => []], 'searches' => [], 'chats' => []];
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC05 — SEARCH → ORDER FUNNEL (Search × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC05_searchToOrderFunnel(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc05:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $rows = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'query' => ['$ne' => null]]],
                        ['$group' => [
                            '_id'         => ['$toLower' => '$query'],
                            'searches'    => ['$sum' => 1],
                            'clicks'      => ['$sum' => ['$cond' => [['$ne' => ['$clicked_product_id', null]], 1, 0]]],
                            'conversions' => ['$sum' => ['$cond' => ['$converted', 1, 0]]],
                            'order_ids'   => ['$addToSet' => '$conversion_order_id'],
                        ]],
                        ['$sort' => ['conversions' => -1]],
                        ['$limit' => 20],
                    ], ['maxTimeMS' => 30000]));

                $orderIds = collect($rows)->pluck('order_ids')->flatten()->filter()->unique()->values()->all();
                $revMap = [];
                if (!empty($orderIds)) {
                    $ords = DB::connection('mongodb')->table('synced_orders')
                        ->whereIn('tenant_id', $tids)->whereIn('external_id', $orderIds)
                        ->get(['external_id', 'grand_total']);
                    foreach ($ords as $o) { $revMap[$o['external_id'] ?? ''] = $o['grand_total'] ?? 0; }
                }

                $queries = collect($rows)->map(function ($r) use ($revMap) {
                    $rev = array_sum(array_map(fn($id) => $revMap[$id] ?? 0, $r['order_ids'] ?? []));
                    $s = $r['searches']; $c = $r['clicks'];
                    return [
                        'query'           => $r['_id'],
                        'searches'        => $s,
                        'clicks'          => $c,
                        'conversions'     => $r['conversions'],
                        'revenue'         => round($rev, 2),
                        'click_rate'      => $s > 0 ? round($c / $s * 100, 1) : 0,
                        'conversion_rate' => $c > 0 ? round($r['conversions'] / $c * 100, 1) : 0,
                        'revenue_per_search' => $s > 0 ? round($rev / $s, 2) : 0,
                    ];
                })->sortByDesc('revenue')->values()->all();

                return ['queries' => $queries, 'total_queries_analyzed' => count($queries), 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC05 failed: ' . $e->getMessage());
                return ['queries' => [], 'total_queries_analyzed' => 0, 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC06 — ZERO-RESULT OPPORTUNITIES (Search × Products)
     * ══════════════════════════════════════════════════════════════ */
    public function UC06_zeroResultOpportunities(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc06:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $zeros = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'results_count' => 0, 'query' => ['$ne' => null]]],
                        ['$group' => ['_id' => ['$toLower' => '$query'], 'count' => ['$sum' => 1]]],
                        ['$sort' => ['count' => -1]],
                        ['$limit' => 15],
                    ], ['maxTimeMS' => 30000]));

                $opportunities = collect($zeros)->map(function ($r) use ($tids) {
                    $q = $r['_id'];
                    $similar = DB::connection('mongodb')->table('synced_products')
                        ->whereIn('tenant_id', $tids)
                        ->where('name', 'regexp', new \MongoDB\BSON\Regex(preg_quote(substr($q, 0, 5), '/'), 'i'))
                        ->limit(2)->get(['name', 'price']);
                    return [
                        'query'            => $q,
                        'missed_searches'  => $r['count'],
                        'similar_products' => collect($similar)->map(fn($p) => ['name' => $p['name'] ?? '', 'price' => $p['price'] ?? 0])->values()->all(),
                        'gap_type'         => empty($similar) ? 'no_product' : 'catalog_mismatch',
                    ];
                })->values()->all();

                return ['opportunities' => $opportunities, 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC06 failed: ' . $e->getMessage());
                return ['opportunities' => [], 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC07 — ABANDONED SEARCH RECOVERY (Search × Orders × Marketing)
     * ══════════════════════════════════════════════════════════════ */
    public function UC07_abandonedSearchRecovery(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc07:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $abandoned = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'results_count' => ['$gt' => 0], 'clicked_product_id' => ['$ne' => null], 'converted' => false, 'customer_email' => ['$ne' => null]]],
                        ['$group' => ['_id' => '$customer_email', 'abandon_count' => ['$sum' => 1], 'queries' => ['$addToSet' => '$query']]],
                        ['$sort' => ['abandon_count' => -1]],
                        ['$limit' => 20],
                    ], ['maxTimeMS' => 30000]));

                $emails = collect($abandoned)->pluck('_id')->filter()->values()->all();
                $revMap = [];
                if (!empty($emails)) {
                    $ords = DB::connection('mongodb')->table('synced_orders')
                        ->raw(fn($col) => $col->aggregate([
                            ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $emails], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                            ['$group' => ['_id' => '$customer_email', 'ltv' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                        ], ['maxTimeMS' => 30000]));
                    foreach ($ords as $o) { $revMap[$o['_id']] = ['ltv' => round($o['ltv'], 2), 'orders' => $o['orders']]; }
                }

                $list = collect($abandoned)->map(fn($r) => [
                    'email'          => $r['_id'],
                    'abandoned_searches' => $r['abandon_count'],
                    'top_queries'    => array_slice($r['queries'] ?? [], 0, 3),
                    'lifetime_value' => $revMap[$r['_id']]['ltv'] ?? 0,
                    'total_orders'   => $revMap[$r['_id']]['orders'] ?? 0,
                    'priority_score' => ($r['abandon_count'] * 10) + ($revMap[$r['_id']]['ltv'] ?? 0) / 100,
                ])->sortByDesc('priority_score')->values()->all();

                return ['recovery_list' => $list, 'total_at_risk' => count($list), 'modules' => ['AiSearch', 'DataSync', 'Marketing']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC07 failed: ' . $e->getMessage());
                return ['recovery_list' => [], 'total_at_risk' => 0, 'modules' => ['AiSearch', 'DataSync', 'Marketing']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC08 — SEARCH SEASONALITY (Search × Orders × Time)
     * ══════════════════════════════════════════════════════════════ */
    public function UC08_searchSeasonality(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc08:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $searchByHour = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids]]],
                        ['$group' => [
                            '_id'         => ['hour' => ['$hour' => '$created_at'], 'dow' => ['$dayOfWeek' => '$created_at']],
                            'searches'    => ['$sum' => 1],
                            'conversions' => ['$sum' => ['$cond' => ['$converted', 1, 0]]],
                        ]],
                    ], ['maxTimeMS' => 30000]));

                $ordersByHour = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                        ['$group' => [
                            '_id'     => ['hour' => ['$hour' => '$created_at'], 'dow' => ['$dayOfWeek' => '$created_at']],
                            'orders'  => ['$sum' => 1],
                            'revenue' => ['$sum' => '$grand_total'],
                        ]],
                    ], ['maxTimeMS' => 30000]));

                $matrix = [];
                foreach ($searchByHour as $r) {
                    $key = ($r['_id']['dow'] ?? 0) . '_' . ($r['_id']['hour'] ?? 0);
                    $matrix[$key] = ['dow' => $r['_id']['dow'] ?? 0, 'hour' => $r['_id']['hour'] ?? 0, 'searches' => $r['searches'], 'conversions' => $r['conversions'], 'orders' => 0, 'revenue' => 0];
                }
                foreach ($ordersByHour as $r) {
                    $key = ($r['_id']['dow'] ?? 0) . '_' . ($r['_id']['hour'] ?? 0);
                    if (!isset($matrix[$key])) $matrix[$key] = ['dow' => $r['_id']['dow'] ?? 0, 'hour' => $r['_id']['hour'] ?? 0, 'searches' => 0, 'conversions' => 0];
                    $matrix[$key]['orders']  = $r['orders'];
                    $matrix[$key]['revenue'] = round($r['revenue'], 2);
                }

                $peak = collect($matrix)->sortByDesc('searches')->first();
                return ['matrix' => array_values($matrix), 'peak_slot' => $peak, 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC08 failed: ' . $e->getMessage());
                return ['matrix' => [], 'peak_slot' => null, 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC09 — CATEGORY SEARCH-TO-SALES GAP (Search × Categories × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC09_categorySearchToSalesGap(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc09:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $cats = DB::connection('mongodb')->table('synced_categories')
                    ->whereIn('tenant_id', $tids)->limit(30)->get(['name', 'external_id']);

                $result = [];
                foreach ($cats as $cat) {
                    $name = $cat['name'] ?? '';
                    if (strlen($name) < 3) continue;
                    $regex = new \MongoDB\BSON\Regex(preg_quote($name, '/'), 'i');
                    $searchCount = DB::connection('mongodb')->table('search_logs')
                        ->whereIn('tenant_id', $tids)->where('query', 'regexp', $regex)->count();
                    $salesAgg = DB::connection('mongodb')->table('synced_orders')
                        ->raw(fn($col) => $col->aggregate([
                            ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                            ['$unwind' => '$items'],
                            ['$match' => ['items.category' => $regex]],
                            ['$group' => ['_id' => null, 'revenue' => ['$sum' => '$items.row_total'], 'count' => ['$sum' => 1]]],
                        ], ['maxTimeMS' => 15000]));
                    $sales = collect($salesAgg)->first();
                    $result[] = [
                        'category'      => $name,
                        'search_volume' => $searchCount,
                        'sales_count'   => $sales['count'] ?? 0,
                        'revenue'       => round($sales['revenue'] ?? 0, 2),
                        'gap_score'     => $searchCount > 0 && ($sales['count'] ?? 0) === 0 ? $searchCount : 0,
                    ];
                }
                usort($result, fn($a, $b) => $b['gap_score'] <=> $a['gap_score']);
                return ['gaps' => array_slice($result, 0, 15), 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC09 failed: ' . $e->getMessage());
                return ['gaps' => [], 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC10 — CHATBOT TO CHECKOUT PATH (Chatbot × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC10_chatbotToCheckoutPath(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc10:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $convos = DB::connection('mongodb')->table('chatbot_conversations')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$ne' => null]]],
                        ['$group' => ['_id' => '$customer_email', 'first_chat' => ['$min' => '$started_at']]],
                    ], ['maxTimeMS' => 30000]));

                $emails = collect($convos)->pluck('_id')->filter()->values()->all();
                $chatMap = [];
                foreach ($convos as $c) { $chatMap[$c['_id']] = $c['first_chat']; }

                $windows = ['24h' => ['count' => 0, 'revenue' => 0], '7d' => ['count' => 0, 'revenue' => 0], '30d' => ['count' => 0, 'revenue' => 0]];
                if (!empty($emails)) {
                    $orders = DB::connection('mongodb')->table('synced_orders')
                        ->whereIn('tenant_id', $tids)->whereIn('customer_email', $emails)
                        ->where('status', 'not in', ['cancelled', 'canceled'])
                        ->get(['customer_email', 'grand_total', 'created_at']);
                    foreach ($orders as $o) {
                        $email = $o['customer_email'] ?? '';
                        $chatAt = isset($chatMap[$email]) ? strtotime((string)$chatMap[$email]) : null;
                        $orderAt = strtotime((string)($o['created_at'] ?? ''));
                        if (!$chatAt || !$orderAt || $orderAt < $chatAt) continue;
                        $diff = $orderAt - $chatAt;
                        $rev = $o['grand_total'] ?? 0;
                        if ($diff <= 86400)    { $windows['24h']['count']++; $windows['24h']['revenue'] += $rev; }
                        if ($diff <= 604800)   { $windows['7d']['count']++;  $windows['7d']['revenue']  += $rev; }
                        if ($diff <= 2592000)  { $windows['30d']['count']++; $windows['30d']['revenue'] += $rev; }
                    }
                }
                foreach ($windows as &$w) { $w['revenue'] = round($w['revenue'], 2); }
                return ['chatbot_users' => count($emails), 'conversion_windows' => $windows, 'modules' => ['Chatbot', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC10 failed: ' . $e->getMessage());
                return ['chatbot_users' => 0, 'conversion_windows' => [], 'modules' => ['Chatbot', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC11 — CHATBOT ABANDONMENT (Chatbot × Orders × Time)
     * ══════════════════════════════════════════════════════════════ */
    public function UC11_chatbotAbandonment(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc11:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $cutoff = now()->subDays(30)->toDateTime();
                $highIntent = DB::connection('mongodb')->table('chatbot_conversations')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'intent' => ['$in' => ['product_search', 'add_to_cart']], 'customer_email' => ['$ne' => null], 'started_at' => ['$gte' => $cutoff]]],
                        ['$group' => ['_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$started_at']], 'sessions' => ['$addToSet' => '$customer_email'], 'count' => ['$sum' => 1]]],
                        ['$sort' => ['_id' => 1]],
                    ], ['maxTimeMS' => 30000]));

                $allEmails = DB::connection('mongodb')->table('chatbot_conversations')
                    ->whereIn('tenant_id', $tids)->whereIn('intent', ['product_search', 'add_to_cart'])
                    ->where('customer_email', '!=', null)->pluck('customer_email')->unique()->values()->all();

                $orderedEmails = [];
                if (!empty($allEmails)) {
                    $since7d = now()->subDays(7)->toDateTime();
                    $ords = DB::connection('mongodb')->table('synced_orders')
                        ->whereIn('tenant_id', $tids)->whereIn('customer_email', $allEmails)
                        ->where('created_at', '>=', $since7d)->pluck('customer_email')->unique()->values()->all();
                    $orderedEmails = $ords;
                }

                $abandonedEmails = array_diff($allEmails, $orderedEmails);
                $dailyBreakdown = collect($highIntent)->map(fn($r) => ['date' => $r['_id'], 'high_intent_sessions' => $r['count']])->values()->all();

                return [
                    'total_high_intent_sessions' => count($allEmails),
                    'abandoned_count'            => count($abandonedEmails),
                    'converted_count'            => count($orderedEmails),
                    'abandonment_rate'           => count($allEmails) > 0 ? round(count($abandonedEmails) / count($allEmails) * 100, 1) : 0,
                    'daily_breakdown'            => $dailyBreakdown,
                    'modules'                    => ['Chatbot', 'DataSync'],
                ];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC11 failed: ' . $e->getMessage());
                return ['total_high_intent_sessions' => 0, 'abandoned_count' => 0, 'converted_count' => 0, 'abandonment_rate' => 0, 'daily_breakdown' => [], 'modules' => ['Chatbot', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC12 — CHATBOT PRODUCT COMPLAINTS (Chatbot × Products)
     * ══════════════════════════════════════════════════════════════ */
    public function UC12_chatbotProductComplaints(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc12:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $complaints = DB::connection('mongodb')->table('chatbot_conversations')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'intent' => ['$in' => ['complaint', 'return_request']]]],
                        ['$group' => ['_id' => '$intent', 'count' => ['$sum' => 1], 'conversation_ids' => ['$addToSet' => '$_id']]],
                    ], ['maxTimeMS' => 30000]));

                $keywords = ['whisky', 'rum', 'vodka', 'gin', 'wine', 'champagne', 'beer', 'liquor', 'perfume', 'chocolate', 'gift', 'cigars'];
                $keywordCounts = [];
                foreach ($keywords as $kw) {
                    $cnt = DB::connection('mongodb')->table('chatbot_messages')
                        ->whereIn('tenant_id', $tids)
                        ->where('content', 'regexp', new \MongoDB\BSON\Regex($kw, 'i'))
                        ->where('role', 'user')
                        ->count();
                    if ($cnt > 0) $keywordCounts[$kw] = $cnt;
                }
                arsort($keywordCounts);

                return [
                    'by_intent'       => collect($complaints)->map(fn($r) => ['intent' => $r['_id'], 'count' => $r['count']])->values()->all(),
                    'product_keywords'=> array_slice($keywordCounts, 0, 10, true),
                    'modules'         => ['Chatbot', 'DataSync'],
                ];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC12 failed: ' . $e->getMessage());
                return ['by_intent' => [], 'product_keywords' => [], 'modules' => ['Chatbot', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC13 — CHATBOT UPSELL SUCCESS (Chatbot × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC13_chatbotUpsellSuccess(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc13:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $recConvos = DB::connection('mongodb')->table('chatbot_conversations')
                    ->whereIn('tenant_id', $tids)->whereIn('intent', ['recommendation', 'product_search'])
                    ->where('customer_email', '!=', null)
                    ->get(['customer_email', 'started_at']);

                $emails = collect($recConvos)->pluck('customer_email')->filter()->unique()->values()->all();
                $chatMap = [];
                foreach ($recConvos as $c) {
                    $e = $c['customer_email'] ?? '';
                    if ($e && !isset($chatMap[$e])) $chatMap[$e] = strtotime((string)($c['started_at'] ?? ''));
                }

                $upsellOrders = 0; $upsellRevenue = 0;
                if (!empty($emails)) {
                    $orders = DB::connection('mongodb')->table('synced_orders')
                        ->whereIn('tenant_id', $tids)->whereIn('customer_email', $emails)
                        ->where('status', 'not in', ['cancelled', 'canceled'])
                        ->get(['customer_email', 'grand_total', 'created_at']);
                    foreach ($orders as $o) {
                        $chatAt = $chatMap[$o['customer_email'] ?? ''] ?? null;
                        $orderAt = strtotime((string)($o['created_at'] ?? ''));
                        if ($chatAt && $orderAt > $chatAt && ($orderAt - $chatAt) <= 172800) {
                            $upsellOrders++; $upsellRevenue += $o['grand_total'] ?? 0;
                        }
                    }
                }

                return [
                    'recommendation_sessions' => count($emails),
                    'upsell_orders'           => $upsellOrders,
                    'upsell_revenue'          => round($upsellRevenue, 2),
                    'upsell_conversion_rate'  => count($emails) > 0 ? round($upsellOrders / count($emails) * 100, 1) : 0,
                    'avg_upsell_order_value'  => $upsellOrders > 0 ? round($upsellRevenue / $upsellOrders, 2) : 0,
                    'modules'                 => ['Chatbot', 'DataSync'],
                ];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC13 failed: ' . $e->getMessage());
                return ['recommendation_sessions' => 0, 'upsell_orders' => 0, 'upsell_revenue' => 0, 'upsell_conversion_rate' => 0, 'avg_upsell_order_value' => 0, 'modules' => ['Chatbot', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC14 — CHATBOT SENTIMENT VS ORDERS (Chatbot × Orders × Analytics)
     * ══════════════════════════════════════════════════════════════ */
    public function UC14_chatbotSentimentVsOrders(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc14:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $segments = [
                    'unhappy'  => ['$lte' => 2],
                    'neutral'  => ['$eq' => 3],
                    'happy'    => ['$gte' => 4],
                ];
                $result = [];
                foreach ($segments as $label => $cond) {
                    $convos = DB::connection('mongodb')->table('chatbot_conversations')
                        ->whereIn('tenant_id', $tids)
                        ->raw(fn($col) => $col->find(['tenant_id' => ['$in' => $tids], 'satisfaction_score' => $cond, 'customer_email' => ['$ne' => null]], ['projection' => ['customer_email' => 1]]));
                    $emails = collect($convos)->pluck('customer_email')->filter()->unique()->values()->all();
                    $reorders = 0; $revenue = 0;
                    if (!empty($emails)) {
                        $since = now()->subDays(30)->toDateTime();
                        $ords = DB::connection('mongodb')->table('synced_orders')
                            ->raw(fn($col) => $col->aggregate([
                                ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $emails], 'status' => ['$nin' => ['cancelled', 'canceled']], 'created_at' => ['$gte' => $since]]],
                                ['$group' => ['_id' => null, 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                            ], ['maxTimeMS' => 20000]));
                        $o = collect($ords)->first();
                        $reorders = $o['orders'] ?? 0; $revenue = $o['revenue'] ?? 0;
                    }
                    $result[$label] = ['customers' => count($emails), 'reorders_30d' => $reorders, 'revenue_30d' => round($revenue, 2), 'reorder_rate' => count($emails) > 0 ? round($reorders / count($emails) * 100, 1) : 0];
                }
                return ['by_sentiment' => $result, 'insight' => 'Higher satisfaction correlates with repeat orders', 'modules' => ['Chatbot', 'DataSync', 'Analytics']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC14 failed: ' . $e->getMessage());
                return ['by_sentiment' => [], 'modules' => ['Chatbot', 'DataSync', 'Analytics']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC15 — CAMPAIGN TO SEARCH BEHAVIOR (Marketing × Search)
     * ══════════════════════════════════════════════════════════════ */
    public function UC15_campaignToSearchBehavior(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc15:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $campaigns = DB::table('marketing_campaigns')->where('tenant_id', $tenantId)->whereNotNull('sent_at')->orderByDesc('sent_at')->limit(10)->get();
                $result = [];
                foreach ($campaigns as $c) {
                    $sentAt = $c->sent_at ? new \MongoDB\BSON\UTCDateTime(strtotime($c->sent_at) * 1000) : null;
                    if (!$sentAt) continue;
                    $recipients = DB::table('marketing_messages')->where('campaign_id', $c->id)->pluck('recipient_email')->unique()->values()->all();
                    if (empty($recipients)) { $result[] = ['campaign' => $c->name ?? 'Campaign #'.$c->id, 'top_searches' => []]; continue; }
                    $searches = DB::connection('mongodb')->table('search_logs')
                        ->raw(fn($col) => $col->aggregate([
                            ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $recipients], 'created_at' => ['$gte' => $sentAt]]],
                            ['$group' => ['_id' => ['$toLower' => '$query'], 'count' => ['$sum' => 1]]],
                            ['$sort' => ['count' => -1]],
                            ['$limit' => 5],
                        ], ['maxTimeMS' => 15000]));
                    $result[] = ['campaign' => $c->name ?? 'Campaign #'.$c->id, 'type' => $c->type ?? 'email', 'sent_at' => $c->sent_at, 'top_searches' => collect($searches)->map(fn($r) => ['query' => $r['_id'], 'count' => $r['count']])->values()->all()];
                }
                return ['campaigns' => $result, 'modules' => ['Marketing', 'AiSearch']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC15 failed: ' . $e->getMessage());
                return ['campaigns' => [], 'modules' => ['Marketing', 'AiSearch']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC16 — SEGMENT SEARCH AFFINITY (Analytics × Search)
     * ══════════════════════════════════════════════════════════════ */
    public function UC16_segmentSearchAffinity(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc16:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $segments = ['Champions', 'Loyal', 'At Risk', 'Hibernating', 'Lost'];
                $result = [];
                foreach ($segments as $seg) {
                    $profiles = DB::connection('mongodb')->table('cdp_profiles')
                        ->whereIn('tenant_id', $tids)->where('computed.rfm_segment', $seg)->limit(200)
                        ->get(['email']);
                    $emails = collect($profiles)->pluck('email')->filter()->values()->all();
                    if (empty($emails)) { $result[$seg] = ['customer_count' => 0, 'top_searches' => []]; continue; }
                    $searches = DB::connection('mongodb')->table('search_logs')
                        ->raw(fn($col) => $col->aggregate([
                            ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $emails]]],
                            ['$group' => ['_id' => ['$toLower' => '$query'], 'count' => ['$sum' => 1]]],
                            ['$sort' => ['count' => -1]],
                            ['$limit' => 5],
                        ], ['maxTimeMS' => 15000]));
                    $result[$seg] = ['customer_count' => count($emails), 'top_searches' => collect($searches)->map(fn($r) => ['query' => $r['_id'], 'count' => $r['count']])->values()->all()];
                }
                return ['by_segment' => $result, 'modules' => ['Analytics', 'AiSearch']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC16 failed: ' . $e->getMessage());
                return ['by_segment' => [], 'modules' => ['Analytics', 'AiSearch']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC17 — CAMPAIGN UNSUBSCRIBE RISK (Marketing × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC17_campaignUnsubscribeRisk(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc17:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $unsubs = DB::table('marketing_contacts')->where('tenant_id', $tenantId)->whereNotNull('unsubscribed_at')->pluck('email')->all();
                if (empty($unsubs)) return ['total_unsubscribed' => 0, 'had_orders' => 0, 'total_revenue_lost' => 0, 'avg_days_to_unsub' => 0, 'modules' => ['Marketing', 'DataSync']];

                $orderData = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $unsubs], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                        ['$group' => ['_id' => '$customer_email', 'revenue' => ['$sum' => '$grand_total'], 'last_order' => ['$max' => '$created_at']]],
                    ], ['maxTimeMS' => 30000]));

                $hadOrders = collect($orderData)->count();
                $totalRev = collect($orderData)->sum('revenue');
                $unsubDates = DB::table('marketing_contacts')->where('tenant_id', $tenantId)->whereNotNull('unsubscribed_at')->whereIn('email', collect($orderData)->pluck('_id')->all())->get(['email', 'unsubscribed_at']);
                $orderMap = [];
                foreach ($orderData as $o) { $orderMap[$o['_id']] = $o['last_order']; }
                $diffs = [];
                foreach ($unsubDates as $u) {
                    $last = isset($orderMap[$u->email]) ? strtotime((string)$orderMap[$u->email]) : null;
                    $unsub = strtotime($u->unsubscribed_at);
                    if ($last && $unsub > $last) $diffs[] = ($unsub - $last) / 86400;
                }
                return ['total_unsubscribed' => count($unsubs), 'had_orders' => $hadOrders, 'total_revenue_lost' => round($totalRev, 2), 'avg_days_last_order_to_unsub' => $diffs ? round(array_sum($diffs) / count($diffs), 1) : 0, 'modules' => ['Marketing', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC17 failed: ' . $e->getMessage());
                return ['total_unsubscribed' => 0, 'had_orders' => 0, 'total_revenue_lost' => 0, 'avg_days_last_order_to_unsub' => 0, 'modules' => ['Marketing', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC18 — FLOW TRIGGER EFFECTIVENESS (Marketing × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC18_flowTriggerEffectiveness(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc18:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $flows = DB::table('marketing_flows')->where('tenant_id', $tenantId)->get(['id', 'name']);
                $result = [];
                foreach ($flows as $f) {
                    $enrollments = DB::table('marketing_flow_enrollments')->where('flow_id', $f->id)->get(['contact_email', 'completed_at', 'enrolled_at']);
                    $completed = $enrollments->whereNotNull('completed_at');
                    $emails = $completed->pluck('contact_email')->filter()->unique()->values()->all();
                    $revenue = 0; $ordersCount = 0;
                    if (!empty($emails)) {
                        $since7d = now()->subDays(7)->toDateTime();
                        $ords = DB::connection('mongodb')->table('synced_orders')
                            ->raw(fn($col) => $col->aggregate([
                                ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $emails], 'status' => ['$nin' => ['cancelled', 'canceled']], 'created_at' => ['$gte' => $since7d]]],
                                ['$group' => ['_id' => null, 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                            ], ['maxTimeMS' => 20000]));
                        $o = collect($ords)->first();
                        $revenue = $o['revenue'] ?? 0; $ordersCount = $o['orders'] ?? 0;
                    }
                    $result[] = ['flow' => $f->name, 'enrolled' => $enrollments->count(), 'completed' => $completed->count(), 'completion_rate' => $enrollments->count() > 0 ? round($completed->count() / $enrollments->count() * 100, 1) : 0, 'orders_7d' => $ordersCount, 'revenue_7d' => round($revenue, 2)];
                }
                return ['flows' => $result, 'modules' => ['Marketing', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC18 failed: ' . $e->getMessage());
                return ['flows' => [], 'modules' => ['Marketing', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC19 — CAMPAIGN TIMING OPTIMIZER (Marketing × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC19_campaignTimingOptimizer(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc19:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $campaigns = DB::table('marketing_campaigns')->where('tenant_id', $tenantId)->whereNotNull('sent_at')->where('total_converted', '>', 0)->get(['sent_at', 'total_sent', 'total_converted', 'total_opened']);
                $byHourDow = [];
                foreach ($campaigns as $c) {
                    $dt = \Carbon\Carbon::parse($c->sent_at);
                    $key = $dt->dayOfWeek . '_' . $dt->hour;
                    if (!isset($byHourDow[$key])) $byHourDow[$key] = ['dow' => $dt->dayOfWeek, 'hour' => $dt->hour, 'campaigns' => 0, 'total_sent' => 0, 'total_converted' => 0, 'total_opened' => 0];
                    $byHourDow[$key]['campaigns']++;
                    $byHourDow[$key]['total_sent']      += $c->total_sent ?? 0;
                    $byHourDow[$key]['total_converted'] += $c->total_converted ?? 0;
                    $byHourDow[$key]['total_opened']    += $c->total_opened ?? 0;
                }
                foreach ($byHourDow as &$v) {
                    $v['conversion_rate'] = $v['total_sent'] > 0 ? round($v['total_converted'] / $v['total_sent'] * 100, 2) : 0;
                    $v['open_rate']       = $v['total_sent'] > 0 ? round($v['total_opened'] / $v['total_sent'] * 100, 2) : 0;
                }
                usort($byHourDow, fn($a, $b) => $b['conversion_rate'] <=> $a['conversion_rate']);
                return ['best_times' => array_slice(array_values($byHourDow), 0, 10), 'modules' => ['Marketing', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC19 failed: ' . $e->getMessage());
                return ['best_times' => [], 'modules' => ['Marketing', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC20 — DEMAND FORECAST BY SEARCH (Search × Inventory)
     * ══════════════════════════════════════════════════════════════ */
    public function UC20_demandForecastBySearch(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc20:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $since7d = now()->subDays(7)->toDateTime();
                $topQueries = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'created_at' => ['$gte' => $since7d]]],
                        ['$group' => ['_id' => ['$toLower' => '$query'], 'volume' => ['$sum' => 1]]],
                        ['$sort' => ['volume' => -1]],
                        ['$limit' => 20],
                    ], ['maxTimeMS' => 30000]));

                $risks = [];
                foreach ($topQueries as $r) {
                    $q = $r['_id'];
                    $prod = DB::connection('mongodb')->table('synced_products')
                        ->whereIn('tenant_id', $tids)->where('name', 'regexp', new \MongoDB\BSON\Regex(preg_quote(substr($q, 0, 6), '/'), 'i'))
                        ->first(['name', 'stock_quantity', 'price']);
                    $stock = (int)($prod['stock_quantity'] ?? $prod['qty'] ?? 99);
                    $risks[] = [
                        'query'          => $q,
                        'search_volume'  => $r['volume'],
                        'matched_product'=> $prod['name'] ?? null,
                        'stock'          => $stock,
                        'risk'           => $r['volume'] > 10 && $stock < 5 ? 'stockout_risk' : ($stock === 0 ? 'out_of_stock' : 'ok'),
                    ];
                }
                $stockoutRisks = array_filter($risks, fn($r) => $r['risk'] !== 'ok');
                return ['demand_forecast' => $risks, 'stockout_risks' => array_values($stockoutRisks), 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC20 failed: ' . $e->getMessage());
                return ['demand_forecast' => [], 'stockout_risks' => [], 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC21 — OUT-OF-STOCK REVENUE LOSS (Inventory × Search × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC21_outOfStockRevenueLoss(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc21:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $oos = DB::connection('mongodb')->table('synced_products')
                    ->whereIn('tenant_id', $tids)->where('stock_status', 'outofstock')->limit(50)
                    ->get(['name', 'external_id', 'price']);

                $avgOrd = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                        ['$group' => ['_id' => null, 'avg' => ['$avg' => '$grand_total']]],
                    ], ['maxTimeMS' => 20000]));
                $avgOrderValue = collect($avgOrd)->first()['avg'] ?? 3000;

                $result = [];
                foreach ($oos as $p) {
                    $name = $p['name'] ?? '';
                    $pid  = (string)($p['external_id'] ?? $p['_id'] ?? '');
                    $searchClicks = DB::connection('mongodb')->table('search_logs')
                        ->whereIn('tenant_id', $tids)->where('clicked_product_id', $pid)->count();
                    if ($searchClicks === 0 && strlen($name) > 3) {
                        $searchClicks = DB::connection('mongodb')->table('search_logs')
                            ->whereIn('tenant_id', $tids)->where('query', 'regexp', new \MongoDB\BSON\Regex(preg_quote(substr($name, 0, 6), '/'), 'i'))->count();
                    }
                    $revLost = round($searchClicks * $avgOrderValue * 0.03, 2);
                    if ($revLost > 0 || $searchClicks > 0) {
                        $result[] = ['product' => $name, 'search_clicks' => $searchClicks, 'estimated_revenue_lost' => $revLost];
                    }
                }
                usort($result, fn($a, $b) => $b['estimated_revenue_lost'] <=> $a['estimated_revenue_lost']);
                $totalLost = array_sum(array_column($result, 'estimated_revenue_lost'));
                return ['out_of_stock_products' => count($oos), 'revenue_loss_items' => array_slice($result, 0, 15), 'total_estimated_loss' => round($totalLost, 2), 'modules' => ['DataSync', 'AiSearch']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC21 failed: ' . $e->getMessage());
                return ['out_of_stock_products' => 0, 'revenue_loss_items' => [], 'total_estimated_loss' => 0, 'modules' => ['DataSync', 'AiSearch']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC22 — CATEGORY TREND SURFACE (Search × Orders × Time)
     * ══════════════════════════════════════════════════════════════ */
    public function UC22_categoryTrendSurface(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc22:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $cats = DB::connection('mongodb')->table('synced_categories')->whereIn('tenant_id', $tids)->limit(20)->get(['name']);
                $now7d  = now()->subDays(7)->toDateTime();
                $prev7d = now()->subDays(14)->toDateTime();
                $result = [];
                foreach ($cats as $cat) {
                    $n = $cat['name'] ?? ''; if (strlen($n) < 3) continue;
                    $regex = new \MongoDB\BSON\Regex(preg_quote($n, '/'), 'i');
                    $cur  = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('query', 'regexp', $regex)->where('created_at', '>=', $now7d)->count();
                    $prev = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('query', 'regexp', $regex)->where('created_at', '>=', $prev7d)->where('created_at', '<', $now7d)->count();
                    $searchTrend = $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : ($cur > 0 ? 100 : 0);
                    $revCur  = DB::connection('mongodb')->table('synced_orders')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids], 'created_at' => ['$gte' => $now7d]]], ['$unwind' => '$items'], ['$match' => ['items.name' => $regex]], ['$group' => ['_id' => null, 'rev' => ['$sum' => '$items.row_total']]]], ['maxTimeMS' => 10000]));
                    $revPrev = DB::connection('mongodb')->table('synced_orders')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids], 'created_at' => ['$gte' => $prev7d], 'created_at_2' => ['$lt' => $now7d]]], ['$unwind' => '$items'], ['$match' => ['items.name' => $regex]], ['$group' => ['_id' => null, 'rev' => ['$sum' => '$items.row_total']]]], ['maxTimeMS' => 10000]));
                    $rc = collect($revCur)->first()['rev'] ?? 0;
                    $rp = collect($revPrev)->first()['rev'] ?? 0;
                    $revTrend = $rp > 0 ? round(($rc - $rp) / $rp * 100, 1) : ($rc > 0 ? 100 : 0);
                    $result[] = ['category' => $n, 'search_volume_7d' => $cur, 'search_trend_pct' => $searchTrend, 'revenue_trend_pct' => $revTrend, 'signal' => $searchTrend > 20 && $revTrend < 5 ? 'rising_demand_flat_revenue' : ($searchTrend > 10 && $revTrend > 10 ? 'rising_all' : 'stable')];
                }
                usort($result, fn($a, $b) => $b['search_volume_7d'] <=> $a['search_volume_7d']);
                return ['trends' => $result, 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC22 failed: ' . $e->getMessage());
                return ['trends' => [], 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC23 — BUNDLE OPPORTUNITY (Orders × Search × Products)
     * ══════════════════════════════════════════════════════════════ */
    public function UC23_bundleOpportunity(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc23:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $orders = DB::connection('mongodb')->table('synced_orders')
                    ->whereIn('tenant_id', $tids)->where('status', 'not in', ['cancelled', 'canceled'])
                    ->where(function($q) { $q->where('items', 'size', ['$gte' => 2]); })
                    ->limit(200)->get(['items']);

                $pairCounts = [];
                foreach ($orders as $o) {
                    $items = collect($o['items'] ?? [])->pluck('name')->filter()->unique()->values()->all();
                    for ($i = 0; $i < count($items); $i++) {
                        for ($j = $i + 1; $j < count($items); $j++) {
                            $pair = [$items[$i], $items[$j]]; sort($pair);
                            $key = implode(' + ', $pair);
                            $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
                        }
                    }
                }
                arsort($pairCounts);
                $bundles = array_map(fn($k, $v) => ['products' => $k, 'co_purchase_count' => $v], array_keys(array_slice($pairCounts, 0, 10, true)), array_slice($pairCounts, 0, 10, true));
                return ['bundle_opportunities' => $bundles, 'modules' => ['DataSync', 'AiSearch']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC23 failed: ' . $e->getMessage());
                return ['bundle_opportunities' => [], 'modules' => ['DataSync', 'AiSearch']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC24 — BRAND SEARCH SHARE (Search × Orders × Products)
     * ══════════════════════════════════════════════════════════════ */
    public function UC24_brandSearchShare(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc24:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $brands = DB::connection('mongodb')->table('synced_products')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'brand' => ['$ne' => null, '$ne' => '']]],
                        ['$group' => ['_id' => ['$toLower' => '$brand'], 'products' => ['$sum' => 1]]],
                        ['$sort' => ['products' => -1]], ['$limit' => 20],
                    ], ['maxTimeMS' => 20000]));

                $totalSearches = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->count();
                $totalRevenue  = DB::connection('mongodb')->table('synced_orders')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]], ['$group' => ['_id' => null, 'rev' => ['$sum' => '$grand_total']]]], ['maxTimeMS' => 20000]));
                $totalRev = collect($totalRevenue)->first()['rev'] ?? 1;

                $result = [];
                foreach ($brands as $b) {
                    $brand = $b['_id'];
                    $regex = new \MongoDB\BSON\Regex(preg_quote($brand, '/'), 'i');
                    $sv = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('query', 'regexp', $regex)->count();
                    $revAgg = DB::connection('mongodb')->table('synced_orders')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]], ['$unwind' => '$items'], ['$match' => ['items.brand' => $regex]], ['$group' => ['_id' => null, 'rev' => ['$sum' => '$items.row_total'], 'cnt' => ['$sum' => 1]]]], ['maxTimeMS' => 10000]));
                    $rv = collect($revAgg)->first();
                    $result[] = ['brand' => $brand, 'products' => $b['products'], 'search_volume' => $sv, 'search_share_pct' => $totalSearches > 0 ? round($sv / $totalSearches * 100, 2) : 0, 'order_count' => $rv['cnt'] ?? 0, 'revenue' => round($rv['rev'] ?? 0, 2), 'revenue_share_pct' => $totalRev > 0 ? round(($rv['rev'] ?? 0) / $totalRev * 100, 2) : 0];
                }
                usort($result, fn($a, $b) => $b['search_volume'] <=> $a['search_volume']);
                return ['brands' => $result, 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC24 failed: ' . $e->getMessage());
                return ['brands' => [], 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC25 — HIGH-VALUE CUSTOMER JOURNEY (All 5 Modules)
     * ══════════════════════════════════════════════════════════════ */
    public function UC25_highValueCustomerJourney(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc25:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $topCustomers = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']], 'customer_email' => ['$ne' => null]]],
                        ['$group' => ['_id' => '$customer_email', 'ltv' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                        ['$sort' => ['ltv' => -1]], ['$limit' => 20],
                    ], ['maxTimeMS' => 30000]));

                $result = [];
                foreach ($topCustomers as $c) {
                    $email = $c['_id'];
                    $searches = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('customer_email', $email)->count();
                    $chats    = DB::connection('mongodb')->table('chatbot_conversations')->whereIn('tenant_id', $tids)->where('customer_email', $email)->count();
                    $campaigns= DB::table('marketing_messages')->where('recipient_email', $email)->count();
                    $result[] = ['email' => $email, 'ltv' => round($c['ltv'], 2), 'orders' => $c['orders'], 'searches' => $searches, 'chatbot_sessions' => $chats, 'campaigns_received' => $campaigns, 'engagement_score' => $searches + ($chats * 3) + $c['orders'] * 5];
                }
                return ['vip_customers' => $result, 'modules' => ['DataSync', 'AiSearch', 'Chatbot', 'Marketing', 'Analytics']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC25 failed: ' . $e->getMessage());
                return ['vip_customers' => [], 'modules' => ['DataSync', 'AiSearch', 'Chatbot', 'Marketing', 'Analytics']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC26 — CHURN RISK WITH CHAT SIGNALS (Analytics × Chatbot × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC26_churnRiskWithChatSignals(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc26:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $highRisk = DB::connection('mongodb')->table('cdp_profiles')
                    ->whereIn('tenant_id', $tids)->where('computed.churn_risk', 'high')->limit(500)
                    ->get(['email']);
                $riskEmails = collect($highRisk)->pluck('email')->filter()->values()->all();

                $chatSignals = [];
                if (!empty($riskEmails)) {
                    $badChats = DB::connection('mongodb')->table('chatbot_conversations')
                        ->raw(fn($col) => $col->find(['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $riskEmails], '$or' => [['satisfaction_score' => ['$lte' => 2]], ['intent' => 'complaint']]], ['projection' => ['customer_email' => 1, 'intent' => 1, 'satisfaction_score' => 1, 'started_at' => 1]]));
                    foreach ($badChats as $chat) {
                        $e = $chat['customer_email'] ?? '';
                        if ($e) $chatSignals[$e][] = ['intent' => $chat['intent'] ?? null, 'score' => $chat['satisfaction_score'] ?? null];
                    }
                }
                $watchlist = array_map(fn($email) => ['email' => $email, 'chat_signals' => $chatSignals[$email] ?? [], 'signal_count' => count($chatSignals[$email] ?? [])], array_filter($riskEmails, fn($e) => isset($chatSignals[$e])));
                usort($watchlist, fn($a, $b) => $b['signal_count'] <=> $a['signal_count']);
                return ['total_high_risk' => count($riskEmails), 'with_bad_chat_signals' => count($watchlist), 'priority_watchlist' => array_slice($watchlist, 0, 20), 'modules' => ['Analytics', 'Chatbot', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC26 failed: ' . $e->getMessage());
                return ['total_high_risk' => 0, 'with_bad_chat_signals' => 0, 'priority_watchlist' => [], 'modules' => ['Analytics', 'Chatbot', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC27 — DORMANT CUSTOMER SEARCH HISTORY (Orders × Search)
     * ══════════════════════════════════════════════════════════════ */
    public function UC27_dormantCustomerSearchHistory(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc27:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $cutoff90 = now()->subDays(90)->toDateTime();
                $since30d  = now()->subDays(30)->toDateTime();
                $dormant = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$ne' => null]]],
                        ['$group' => ['_id' => '$customer_email', 'last_order' => ['$max' => '$created_at']]],
                        ['$match' => ['last_order' => ['$lt' => $cutoff90]]],
                        ['$limit' => 300],
                    ], ['maxTimeMS' => 30000]));
                $dormantEmails = collect($dormant)->pluck('_id')->filter()->values()->all();
                $lastOrderMap = [];
                foreach ($dormant as $d) { $lastOrderMap[$d['_id']] = $d['last_order']; }

                $reEngaged = [];
                if (!empty($dormantEmails)) {
                    $searches = DB::connection('mongodb')->table('search_logs')
                        ->raw(fn($col) => $col->aggregate([
                            ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $dormantEmails], 'created_at' => ['$gte' => $since30d]]],
                            ['$group' => ['_id' => '$customer_email', 'count' => ['$sum' => 1], 'last_query' => ['$last' => '$query']]],
                            ['$sort' => ['count' => -1]], ['$limit' => 50],
                        ], ['maxTimeMS' => 30000]));
                    foreach ($searches as $s) {
                        $reEngaged[] = ['email' => $s['_id'], 'searches_30d' => $s['count'], 'last_query' => $s['last_query'], 'last_order_date' => $lastOrderMap[$s['_id']] ?? null, 'days_dormant' => isset($lastOrderMap[$s['_id']]) ? (int)((time() - strtotime((string)$lastOrderMap[$s['_id']])) / 86400) : null];
                    }
                }
                return ['dormant_but_searching' => $reEngaged, 'total_browsing_not_buying' => count($reEngaged), 'modules' => ['DataSync', 'AiSearch']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC27 failed: ' . $e->getMessage());
                return ['dormant_but_searching' => [], 'total_browsing_not_buying' => 0, 'modules' => ['DataSync', 'AiSearch']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC28 — NEW CUSTOMER FIRST-TOUCH ROI (Analytics × Orders × All)
     * ══════════════════════════════════════════════════════════════ */
    public function UC28_newCustomerFirstTouchROI(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc28:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $newCusts = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$ne' => null]]],
                        ['$group' => ['_id' => '$customer_email', 'first_order' => ['$min' => '$created_at'], 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                        ['$match' => ['orders' => 1]],
                        ['$limit' => 500],
                    ], ['maxTimeMS' => 30000]));

                $channels = ['organic_search' => ['count' => 0, 'revenue' => 0], 'chatbot_assisted' => ['count' => 0, 'revenue' => 0], 'campaign' => ['count' => 0, 'revenue' => 0], 'direct' => ['count' => 0, 'revenue' => 0]];
                foreach ($newCusts as $c) {
                    $email = $c['_id']; $rev = $c['revenue'] ?? 0;
                    $firstOrder = $c['first_order'];
                    $hasSearch = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('customer_email', $email)->count() > 0;
                    $hasChat   = DB::connection('mongodb')->table('chatbot_conversations')->whereIn('tenant_id', $tids)->where('customer_email', $email)->count() > 0;
                    $hasCampaign = DB::table('marketing_messages')->where('recipient_email', $email)->count() > 0;
                    if ($hasSearch)        { $channels['organic_search']['count']++;    $channels['organic_search']['revenue']    += $rev; }
                    elseif ($hasChat)      { $channels['chatbot_assisted']['count']++;  $channels['chatbot_assisted']['revenue']  += $rev; }
                    elseif ($hasCampaign)  { $channels['campaign']['count']++;          $channels['campaign']['revenue']          += $rev; }
                    else                   { $channels['direct']['count']++;             $channels['direct']['revenue']             += $rev; }
                }
                foreach ($channels as &$ch) { $ch['revenue'] = round($ch['revenue'], 2); $ch['avg_revenue'] = $ch['count'] > 0 ? round($ch['revenue'] / $ch['count'], 2) : 0; }
                return ['first_touch_roi' => $channels, 'modules' => ['DataSync', 'AiSearch', 'Chatbot', 'Marketing', 'Analytics']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC28 failed: ' . $e->getMessage());
                return ['first_touch_roi' => [], 'modules' => ['DataSync', 'AiSearch', 'Chatbot', 'Marketing', 'Analytics']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC29 — VIP BEHAVIOR PROFILE (Orders × Search × Chatbot × Marketing)
     * ══════════════════════════════════════════════════════════════ */
    public function UC29_vipBehaviorProfile(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc29:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $topCustomers = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']], 'customer_email' => ['$ne' => null]]],
                        ['$group' => ['_id' => '$customer_email', 'ltv' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1], 'dates' => ['$push' => '$created_at']]],
                        ['$sort' => ['ltv' => -1]], ['$limit' => 10],
                    ], ['maxTimeMS' => 30000]));

                $profiles = [];
                foreach ($topCustomers as $c) {
                    $email = $c['_id'];
                    $dates = collect($c['dates'] ?? [])->map(fn($d) => strtotime((string)$d))->sort()->values();
                    $gaps = [];
                    for ($i = 1; $i < $dates->count(); $i++) { $gaps[] = ($dates[$i] - $dates[$i-1]) / 86400; }
                    $avgDaysBetween = $gaps ? round(array_sum($gaps) / count($gaps), 1) : null;
                    $searchCount = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('customer_email', $email)->count();
                    $chatCount   = DB::connection('mongodb')->table('chatbot_conversations')->whereIn('tenant_id', $tids)->where('customer_email', $email)->count();
                    $mktgStats   = DB::table('marketing_messages')->where('recipient_email', $email)->selectRaw('count(*) as sent, sum(opened_at is not null) as opened')->first();
                    $profiles[] = ['email' => $email, 'ltv' => round($c['ltv'], 2), 'orders' => $c['orders'], 'avg_days_between_orders' => $avgDaysBetween, 'total_searches' => $searchCount, 'chatbot_sessions' => $chatCount, 'campaigns_received' => $mktgStats->sent ?? 0, 'campaign_open_rate' => ($mktgStats->sent ?? 0) > 0 ? round(($mktgStats->opened ?? 0) / $mktgStats->sent * 100, 1) : 0];
                }
                return ['vip_profiles' => $profiles, 'modules' => ['DataSync', 'AiSearch', 'Chatbot', 'Marketing']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC29 failed: ' . $e->getMessage());
                return ['vip_profiles' => [], 'modules' => ['DataSync', 'AiSearch', 'Chatbot', 'Marketing']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC30 — ORDER ISSUE HEATMAP (Chatbot × Orders × Time)
     * ══════════════════════════════════════════════════════════════ */
    public function UC30_orderIssueHeatmap(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc30:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $issues = DB::connection('mongodb')->table('chatbot_conversations')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'intent' => ['$in' => ['complaint', 'return_request']]]],
                        ['$group' => ['_id' => ['hour' => ['$hour' => '$started_at'], 'dow' => ['$dayOfWeek' => '$started_at']], 'count' => ['$sum' => 1]]],
                    ], ['maxTimeMS' => 30000]));
                $matrix = collect($issues)->map(fn($r) => ['dow' => $r['_id']['dow'] ?? 0, 'hour' => $r['_id']['hour'] ?? 0, 'complaints' => $r['count']])->sortByDesc('complaints')->values()->all();
                $peak = count($matrix) > 0 ? $matrix[0] : null;
                return ['heatmap' => $matrix, 'peak_complaint_slot' => $peak, 'modules' => ['Chatbot', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC30 failed: ' . $e->getMessage());
                return ['heatmap' => [], 'peak_complaint_slot' => null, 'modules' => ['Chatbot', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC31 — RETURN RATE BY SEARCH QUERY (Search × Chatbot × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC31_returnRateBySearchQuery(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc31:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $converted = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'converted' => true, 'customer_email' => ['$ne' => null], 'query' => ['$ne' => null]]],
                        ['$group' => ['_id' => ['$toLower' => '$query'], 'emails' => ['$addToSet' => '$customer_email'], 'count' => ['$sum' => 1]]],
                        ['$sort' => ['count' => -1]], ['$limit' => 20],
                    ], ['maxTimeMS' => 30000]));

                $returnEmails = DB::connection('mongodb')->table('chatbot_conversations')
                    ->whereIn('tenant_id', $tids)->where('intent', 'return_request')->where('customer_email', '!=', null)
                    ->pluck('customer_email')->unique()->values()->all();

                $result = collect($converted)->map(function ($r) use ($returnEmails) {
                    $emails = $r['emails'] ?? [];
                    $returns = count(array_intersect($emails, $returnEmails));
                    return ['query' => $r['_id'], 'conversions' => $r['count'], 'returns' => $returns, 'return_rate' => $r['count'] > 0 ? round($returns / $r['count'] * 100, 1) : 0];
                })->sortByDesc('return_rate')->values()->all();
                return ['return_rate_by_query' => $result, 'modules' => ['AiSearch', 'Chatbot', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC31 failed: ' . $e->getMessage());
                return ['return_rate_by_query' => [], 'modules' => ['AiSearch', 'Chatbot', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC32 — PAYMENT FAILURE RECOVERY (Orders × Chatbot)
     * ══════════════════════════════════════════════════════════════ */
    public function UC32_paymentFailureRecovery(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc32:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $failed = DB::connection('mongodb')->table('synced_orders')
                    ->whereIn('tenant_id', $tids)->whereIn('status', ['payment_failed', 'pending_payment'])
                    ->get(['customer_email', 'created_at', 'grand_total']);
                $failedEmails = collect($failed)->pluck('customer_email')->filter()->unique()->values()->all();
                $chatRescued = 0; $recovered = 0; $recoveredRev = 0;
                if (!empty($failedEmails)) {
                    $chatRescued = DB::connection('mongodb')->table('chatbot_conversations')
                        ->whereIn('tenant_id', $tids)->whereIn('customer_email', $failedEmails)->count();
                    $since7d = now()->subDays(7)->toDateTime();
                    $recoveredOrds = DB::connection('mongodb')->table('synced_orders')
                        ->raw(fn($col) => $col->aggregate([
                            ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $failedEmails], 'status' => 'complete', 'created_at' => ['$gte' => $since7d]]],
                            ['$group' => ['_id' => null, 'count' => ['$sum' => 1], 'revenue' => ['$sum' => '$grand_total']]],
                        ], ['maxTimeMS' => 20000]));
                    $ro = collect($recoveredOrds)->first();
                    $recovered = $ro['count'] ?? 0; $recoveredRev = $ro['revenue'] ?? 0;
                }
                return ['payment_failures' => count($failed), 'unique_customers' => count($failedEmails), 'chatbot_intervened' => $chatRescued, 'recovered_orders' => $recovered, 'recovered_revenue' => round($recoveredRev, 2), 'recovery_rate' => count($failedEmails) > 0 ? round($recovered / count($failedEmails) * 100, 1) : 0, 'modules' => ['DataSync', 'Chatbot']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC32 failed: ' . $e->getMessage());
                return ['payment_failures' => 0, 'unique_customers' => 0, 'chatbot_intervened' => 0, 'recovered_orders' => 0, 'recovered_revenue' => 0, 'recovery_rate' => 0, 'modules' => ['DataSync', 'Chatbot']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC33 — FULFILLMENT DELAY IMPACT (Orders × Chatbot × Time)
     * ══════════════════════════════════════════════════════════════ */
    public function UC33_fulfillmentDelayImpact(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc33:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $weekly = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids]]],
                        ['$group' => ['_id' => ['$dateToString' => ['format' => '%Y-W%V', 'date' => '$created_at']], 'count' => ['$sum' => 1]]],
                        ['$sort' => ['_id' => -1]], ['$limit' => 8],
                    ], ['maxTimeMS' => 30000]));
                $chatByWeek = DB::connection('mongodb')->table('chatbot_conversations')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'intent' => ['$in' => ['complaint', 'order_tracking']]]],
                        ['$group' => ['_id' => ['$dateToString' => ['format' => '%Y-W%V', 'date' => '$started_at']], 'complaints' => ['$sum' => 1]]],
                        ['$sort' => ['_id' => -1]], ['$limit' => 8],
                    ], ['maxTimeMS' => 30000]));
                $chatMap = [];
                foreach ($chatByWeek as $r) { $chatMap[$r['_id']] = $r['complaints']; }
                $result = collect($weekly)->map(fn($r) => ['week' => $r['_id'], 'orders' => $r['count'], 'complaints' => $chatMap[$r['_id']] ?? 0, 'complaint_rate' => $r['count'] > 0 ? round(($chatMap[$r['_id']] ?? 0) / $r['count'] * 100, 1) : 0])->values()->all();
                return ['weekly_correlation' => $result, 'modules' => ['DataSync', 'Chatbot']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC33 failed: ' . $e->getMessage());
                return ['weekly_correlation' => [], 'modules' => ['DataSync', 'Chatbot']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC34 — PEAK DEMAND READINESS (Search × Inventory × Marketing)
     * ══════════════════════════════════════════════════════════════ */
    public function UC34_peakDemandReadiness(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc34:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $byHour = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids]]],
                        ['$group' => ['_id' => ['$hour' => '$created_at'], 'volume' => ['$sum' => 1]]],
                        ['$sort' => ['_id' => 1]],
                    ], ['maxTimeMS' => 30000]));
                $volumes = collect($byHour)->pluck('volume')->all();
                $avg = $volumes ? array_sum($volumes) / count($volumes) : 1;
                $peaks = collect($byHour)->filter(fn($r) => $r['volume'] > $avg * 2)->values()->all();
                $inStockRate = 0;
                $totalProducts = DB::connection('mongodb')->table('synced_products')->whereIn('tenant_id', $tids)->count();
                $inStock = DB::connection('mongodb')->table('synced_products')->whereIn('tenant_id', $tids)->where('stock_status', 'instock')->count();
                if ($totalProducts > 0) $inStockRate = round($inStock / $totalProducts * 100, 1);
                $activeCampaigns = DB::table('marketing_campaigns')->where('tenant_id', $tenantId)->where('status', 'active')->count();
                $readinessScore = min(100, round($inStockRate * 0.5 + ($activeCampaigns > 0 ? 30 : 0) + (count($peaks) > 0 ? 20 : 0)));
                return ['peak_hours' => collect($peaks)->map(fn($r) => ['hour' => $r['_id'], 'volume' => $r['volume'], 'vs_avg' => round($r['volume'] / $avg, 1) . 'x'])->values()->all(), 'inventory_health' => ['in_stock_rate' => $inStockRate, 'in_stock' => $inStock, 'total' => $totalProducts], 'active_campaigns' => $activeCampaigns, 'readiness_score' => $readinessScore, 'modules' => ['AiSearch', 'DataSync', 'Marketing']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC34 failed: ' . $e->getMessage());
                return ['peak_hours' => [], 'inventory_health' => [], 'active_campaigns' => 0, 'readiness_score' => 0, 'modules' => ['AiSearch', 'DataSync', 'Marketing']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC35 — REVENUE BY ACQUISITION CHANNEL (All Modules)
     * ══════════════════════════════════════════════════════════════ */
    public function UC35_revenueByAcquisitionChannel(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc35:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $customers = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$ne' => null], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                        ['$group' => ['_id' => '$customer_email', 'revenue' => ['$sum' => '$grand_total'], 'orders' => ['$sum' => 1]]],
                        ['$limit' => 500],
                    ], ['maxTimeMS' => 30000]));
                $channels = ['organic_search' => ['customers' => 0, 'revenue' => 0], 'chatbot_assisted' => ['customers' => 0, 'revenue' => 0], 'campaign' => ['customers' => 0, 'revenue' => 0], 'direct' => ['customers' => 0, 'revenue' => 0]];
                foreach ($customers as $c) {
                    $email = $c['_id']; $rev = $c['revenue'] ?? 0;
                    if (DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('customer_email', $email)->count()) { $ch = 'organic_search'; }
                    elseif (DB::connection('mongodb')->table('chatbot_conversations')->whereIn('tenant_id', $tids)->where('customer_email', $email)->count()) { $ch = 'chatbot_assisted'; }
                    elseif (DB::table('marketing_messages')->where('recipient_email', $email)->count()) { $ch = 'campaign'; }
                    else { $ch = 'direct'; }
                    $channels[$ch]['customers']++; $channels[$ch]['revenue'] += $rev;
                }
                foreach ($channels as &$ch) { $ch['revenue'] = round($ch['revenue'], 2); $ch['avg_revenue'] = $ch['customers'] > 0 ? round($ch['revenue'] / $ch['customers'], 2) : 0; }
                return ['by_channel' => $channels, 'modules' => ['DataSync', 'AiSearch', 'Chatbot', 'Marketing', 'Analytics']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC35 failed: ' . $e->getMessage());
                return ['by_channel' => [], 'modules' => ['DataSync', 'AiSearch', 'Chatbot', 'Marketing', 'Analytics']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC36 — DISCOUNT SENSITIVITY BY SEGMENT (Marketing × Analytics × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC36_discountSensitivityBySegment(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc36:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $segments = ['Champions', 'Loyal', 'At Risk', 'Hibernating'];
                $result = [];
                foreach ($segments as $seg) {
                    $profiles = DB::connection('mongodb')->table('cdp_profiles')->whereIn('tenant_id', $tids)->where('computed.rfm_segment', $seg)->limit(200)->get(['email']);
                    $emails = collect($profiles)->pluck('email')->filter()->values()->all();
                    if (empty($emails)) { $result[$seg] = ['customers' => 0, 'coupon_orders' => 0, 'total_orders' => 0, 'coupon_rate' => 0, 'avg_discount' => 0]; continue; }
                    $orderAgg = DB::connection('mongodb')->table('synced_orders')
                        ->raw(fn($col) => $col->aggregate([
                            ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => $emails], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                            ['$group' => ['_id' => null, 'total' => ['$sum' => 1], 'with_discount' => ['$sum' => ['$cond' => [['$gt' => [['$ifNull' => ['$discount_amount', 0]], 0]], 1, 0]]], 'avg_discount' => ['$avg' => ['$ifNull' => ['$discount_amount', 0]]]]],
                        ], ['maxTimeMS' => 20000]));
                    $o = collect($orderAgg)->first();
                    $result[$seg] = ['customers' => count($emails), 'coupon_orders' => $o['with_discount'] ?? 0, 'total_orders' => $o['total'] ?? 0, 'coupon_rate' => ($o['total'] ?? 0) > 0 ? round(($o['with_discount'] ?? 0) / $o['total'] * 100, 1) : 0, 'avg_discount' => round($o['avg_discount'] ?? 0, 2)];
                }
                return ['by_segment' => $result, 'modules' => ['Marketing', 'Analytics', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC36 failed: ' . $e->getMessage());
                return ['by_segment' => [], 'modules' => ['Marketing', 'Analytics', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC37 — LTV BY FIRST PRODUCT (Orders × Products)
     * ══════════════════════════════════════════════════════════════ */
    public function UC37_ltvByFirstProduct(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc37:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $firstOrders = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$ne' => null]]],
                        ['$sort' => ['created_at' => 1]],
                        ['$group' => ['_id' => '$customer_email', 'first_order_id' => ['$first' => '$external_id'], 'first_items' => ['$first' => '$items'], 'first_date' => ['$first' => '$created_at']]],
                        ['$limit' => 500],
                    ], ['maxTimeMS' => 30000]));

                $emailToFirstProduct = [];
                foreach ($firstOrders as $o) {
                    $items = $o['first_items'] ?? [];
                    $firstItem = is_array($items) && !empty($items) ? $items[0]['name'] ?? null : null;
                    if ($firstItem) $emailToFirstProduct[$o['_id']] = $firstItem;
                }

                $allOrders = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$in' => array_keys($emailToFirstProduct)], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                        ['$group' => ['_id' => '$customer_email', 'ltv' => ['$sum' => '$grand_total']]],
                    ], ['maxTimeMS' => 30000]));

                $ltvByProduct = [];
                foreach ($allOrders as $o) {
                    $email = $o['_id']; $ltv = $o['ltv'] ?? 0;
                    $prod = $emailToFirstProduct[$email] ?? 'Unknown';
                    if (!isset($ltvByProduct[$prod])) $ltvByProduct[$prod] = ['product' => $prod, 'customer_count' => 0, 'total_ltv' => 0];
                    $ltvByProduct[$prod]['customer_count']++;
                    $ltvByProduct[$prod]['total_ltv'] += $ltv;
                }
                foreach ($ltvByProduct as &$v) { $v['avg_ltv'] = $v['customer_count'] > 0 ? round($v['total_ltv'] / $v['customer_count'], 2) : 0; $v['total_ltv'] = round($v['total_ltv'], 2); }
                usort($ltvByProduct, fn($a, $b) => $b['avg_ltv'] <=> $a['avg_ltv']);
                return ['gateway_products' => array_slice(array_values($ltvByProduct), 0, 15), 'modules' => ['DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC37 failed: ' . $e->getMessage());
                return ['gateway_products' => [], 'modules' => ['DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC38 — CROSS-SELL GAP (Search × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC38_crossSellGap(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc38:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $sessionSearches = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'session_id' => ['$ne' => null], 'query' => ['$ne' => null]]],
                        ['$group' => ['_id' => '$session_id', 'queries' => ['$addToSet' => ['$toLower' => '$query']]]],
                        ['$match' => ['queries' => ['$size' => ['$gte' => 2]]]],
                        ['$limit' => 300],
                    ], ['maxTimeMS' => 30000]));

                $searchPairs = [];
                foreach ($sessionSearches as $s) {
                    $qs = array_values($s['queries'] ?? []); sort($qs);
                    for ($i = 0; $i < count($qs); $i++) for ($j = $i+1; $j < count($qs); $j++) {
                        $key = $qs[$i] . ' × ' . $qs[$j];
                        $searchPairs[$key] = ($searchPairs[$key] ?? 0) + 1;
                    }
                }
                arsort($searchPairs);
                $avgOrd = DB::connection('mongodb')->table('synced_orders')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]], ['$group' => ['_id' => null, 'avg' => ['$avg' => '$grand_total']]]], ['maxTimeMS' => 20000]));
                $aov = collect($avgOrd)->first()['avg'] ?? 3000;
                $result = array_map(fn($k, $v) => ['pair' => $k, 'co_search_count' => $v, 'estimated_opportunity' => round($v * $aov * 0.03, 2)], array_keys(array_slice($searchPairs, 0, 15, true)), array_slice($searchPairs, 0, 15, true));
                return ['cross_sell_opportunities' => $result, 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC38 failed: ' . $e->getMessage());
                return ['cross_sell_opportunities' => [], 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC39 — LIVE CART ABANDONMENT (Analytics × Chatbot)
     * ══════════════════════════════════════════════════════════════ */
    public function UC39_liveCartAbandonment(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc39:{$tenantId}", now()->addMinutes(5), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $since2h = now()->subHours(2)->toDateTime();
                $addedCart = DB::connection('mongodb')->table('tracking_events')
                    ->whereIn('tenant_id', $tids)->where('event_type', 'add_to_cart')->where('created_at', '>=', $since2h)
                    ->pluck('session_id')->filter()->unique()->values()->all();
                $purchased = DB::connection('mongodb')->table('tracking_events')
                    ->whereIn('tenant_id', $tids)->where('event_type', 'purchase')->where('created_at', '>=', $since2h)
                    ->pluck('session_id')->filter()->unique()->values()->all();
                $abandoned = array_diff($addedCart, $purchased);
                $chatRescued = DB::connection('mongodb')->table('chatbot_conversations')
                    ->whereIn('tenant_id', $tids)->whereIn('session_id', $abandoned)->count();
                $avgOrd = DB::connection('mongodb')->table('synced_orders')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]], ['$group' => ['_id' => null, 'avg' => ['$avg' => '$grand_total']]]], ['maxTimeMS' => 15000]));
                $aov = collect($avgOrd)->first()['avg'] ?? 3000;
                return ['abandoned_carts' => count($abandoned), 'chatbot_rescued' => $chatRescued, 'rescue_rate' => count($abandoned) > 0 ? round($chatRescued / count($abandoned) * 100, 1) : 0, 'revenue_at_risk' => round(count($abandoned) * $aov, 2), 'modules' => ['Analytics', 'Chatbot', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC39 failed: ' . $e->getMessage());
                return ['abandoned_carts' => 0, 'chatbot_rescued' => 0, 'rescue_rate' => 0, 'revenue_at_risk' => 0, 'modules' => ['Analytics', 'Chatbot', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC40 — RAGE CLICK TO ORDER LOSS (Analytics × Chatbot × Orders)
     * ══════════════════════════════════════════════════════════════ */
    public function UC40_rageClickToOrderLoss(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc40:{$tenantId}", now()->addMinutes(5), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $rageSessions = DB::connection('mongodb')->table('tracking_events')
                    ->whereIn('tenant_id', $tids)->where('event_type', 'rage_click')
                    ->pluck('session_id')->filter()->unique()->values()->all();
                $rageOrders = 0; $normalOrders = 0;
                $since1h = now()->subHour()->toDateTime();
                if (!empty($rageSessions)) {
                    $rageOrders = DB::connection('mongodb')->table('tracking_events')
                        ->whereIn('tenant_id', $tids)->whereIn('session_id', $rageSessions)->where('event_type', 'purchase')->where('created_at', '>=', $since1h)->count();
                }
                $totalPurchaseSessions = DB::connection('mongodb')->table('tracking_events')
                    ->whereIn('tenant_id', $tids)->where('event_type', 'purchase')->where('created_at', '>=', $since1h)
                    ->pluck('session_id')->filter()->unique()->count();
                $normalOrders = max(0, $totalPurchaseSessions - $rageOrders);
                $normalSessions = max(1, DB::connection('mongodb')->table('tracking_events')->whereIn('tenant_id', $tids)->where('created_at', '>=', $since1h)->pluck('session_id')->filter()->unique()->count() - count($rageSessions));
                $avgOrd = DB::connection('mongodb')->table('synced_orders')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids]]], ['$group' => ['_id' => null, 'avg' => ['$avg' => '$grand_total']]]], ['maxTimeMS' => 15000]));
                $aov = collect($avgOrd)->first()['avg'] ?? 3000;
                $rageConvRate   = count($rageSessions) > 0 ? round($rageOrders / count($rageSessions) * 100, 2) : 0;
                $normalConvRate = $normalSessions > 0 ? round($normalOrders / $normalSessions * 100, 2) : 0;
                $lostConversions = max(0, count($rageSessions) * ($normalConvRate - $rageConvRate) / 100);
                return ['rage_click_sessions' => count($rageSessions), 'rage_conversion_rate' => $rageConvRate, 'normal_conversion_rate' => $normalConvRate, 'estimated_lost_conversions' => round($lostConversions, 1), 'estimated_revenue_loss' => round($lostConversions * $aov, 2), 'modules' => ['Analytics', 'Chatbot', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC40 failed: ' . $e->getMessage());
                return ['rage_click_sessions' => 0, 'rage_conversion_rate' => 0, 'normal_conversion_rate' => 0, 'estimated_lost_conversions' => 0, 'estimated_revenue_loss' => 0, 'modules' => ['Analytics', 'Chatbot', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC41 — SESSION INTENT SCORING (Analytics × Search × Chatbot)
     * ══════════════════════════════════════════════════════════════ */
    public function UC41_sessionIntentScoring(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc41:{$tenantId}", now()->addMinutes(5), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $since30m = now()->subMinutes(30)->toDateTime();
                $events = DB::connection('mongodb')->table('tracking_events')
                    ->whereIn('tenant_id', $tids)->where('created_at', '>=', $since30m)
                    ->get(['session_id', 'event_type']);
                $scores = [];
                foreach ($events as $e) {
                    $sid = $e['session_id'] ?? ''; if (!$sid) continue;
                    if (!isset($scores[$sid])) $scores[$sid] = ['session_id' => $sid, 'search' => 0, 'product_view' => 0, 'add_to_cart' => 0, 'chat' => 0];
                    match ($e['event_type'] ?? '') {
                        'search'       => $scores[$sid]['search']++,
                        'product_view' => $scores[$sid]['product_view']++,
                        'add_to_cart'  => $scores[$sid]['add_to_cart']++,
                        'chat_start'   => $scores[$sid]['chat']++,
                        default        => null,
                    };
                }
                foreach ($scores as &$s) {
                    $s['intent_score'] = min(100, ($s['search'] * 5) + ($s['product_view'] * 10) + ($s['add_to_cart'] * 30) + ($s['chat'] * 15));
                }
                usort($scores, fn($a, $b) => $b['intent_score'] <=> $a['intent_score']);
                return ['high_intent_sessions' => array_slice(array_values($scores), 0, 20), 'total_active_sessions' => count($scores), 'modules' => ['Analytics', 'AiSearch', 'Chatbot']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC41 failed: ' . $e->getMessage());
                return ['high_intent_sessions' => [], 'total_active_sessions' => 0, 'modules' => ['Analytics', 'AiSearch', 'Chatbot']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC42 — REAL-TIME CHURN SIGNALS (Analytics × Search × Marketing × Chatbot)
     * ══════════════════════════════════════════════════════════════ */
    public function UC42_realTimeChurnSignals(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc42:{$tenantId}", now()->addMinutes(5), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $since14d = now()->subDays(14)->toDateTime();
                $since7d  = now()->subDays(7)->toDateTime();
                $watchlist = [];

                // Signal 1: Recent unsubscribes
                $unsubs = DB::table('marketing_contacts')->where('tenant_id', $tenantId)->where('unsubscribed_at', '>=', now()->subDays(7)->toDateTimeString())->pluck('email')->all();
                foreach ($unsubs as $email) $watchlist[$email] = ['email' => $email, 'signals' => ['unsubscribed_email']];

                // Signal 2: Complaints in last 14 days
                $complaints = DB::connection('mongodb')->table('chatbot_conversations')
                    ->whereIn('tenant_id', $tids)->where('intent', 'complaint')->where('started_at', '>=', $since14d)->pluck('customer_email')->filter()->unique()->values()->all();
                foreach ($complaints as $email) {
                    $watchlist[$email] = $watchlist[$email] ?? ['email' => $email, 'signals' => []];
                    $watchlist[$email]['signals'][] = 'complaint_in_14d';
                }

                // Signal 3: Search drop (have history but none in last 7d)
                $hadSearches = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('customer_email', '!=', null)->pluck('customer_email')->filter()->unique()->values()->all();
                $recentSearches = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('created_at', '>=', $since7d)->where('customer_email', '!=', null)->pluck('customer_email')->filter()->unique()->values()->all();
                $searchDropped = array_diff($hadSearches, $recentSearches);
                foreach (array_slice($searchDropped, 0, 50) as $email) {
                    $watchlist[$email] = $watchlist[$email] ?? ['email' => $email, 'signals' => []];
                    $watchlist[$email]['signals'][] = 'search_activity_dropped';
                }

                foreach ($watchlist as &$w) $w['risk_score'] = count($w['signals']) * 33;
                usort($watchlist, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);
                return ['churn_watchlist' => array_slice(array_values($watchlist), 0, 30), 'total_at_risk' => count($watchlist), 'modules' => ['Analytics', 'AiSearch', 'Marketing', 'Chatbot']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC42 failed: ' . $e->getMessage());
                return ['churn_watchlist' => [], 'total_at_risk' => 0, 'modules' => ['Analytics', 'AiSearch', 'Marketing', 'Chatbot']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC43 — PROACTIVE REORDER SIGNALS (Orders × Search)
     * ══════════════════════════════════════════════════════════════ */
    public function UC43_proactiveReorderSignals(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc43:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $repeatBuyers = DB::connection('mongodb')->table('synced_orders')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'customer_email' => ['$ne' => null], 'status' => ['$nin' => ['cancelled', 'canceled']]]],
                        ['$group' => ['_id' => '$customer_email', 'order_count' => ['$sum' => 1], 'dates' => ['$push' => '$created_at'], 'last_order' => ['$max' => '$created_at'], 'top_product' => ['$last' => '$items']]],
                        ['$match' => ['order_count' => ['$gte' => 2]]],
                        ['$limit' => 200],
                    ], ['maxTimeMS' => 30000]));

                $reorderReady = [];
                foreach ($repeatBuyers as $c) {
                    $email = $c['_id'];
                    $dates = collect($c['dates'] ?? [])->map(fn($d) => strtotime((string)$d))->sort()->values()->all();
                    if (count($dates) < 2) continue;
                    $gaps = [];
                    for ($i = 1; $i < count($dates); $i++) $gaps[] = ($dates[$i] - $dates[$i-1]) / 86400;
                    $avgGap = array_sum($gaps) / count($gaps);
                    $daysSince = (time() - strtotime((string)($c['last_order']))) / 86400;
                    $readiness = $avgGap > 0 ? $daysSince / $avgGap : 0;
                    if ($readiness >= 0.75) {
                        $hasRecentSearch = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('customer_email', $email)->where('created_at', '>=', now()->subDays(7)->toDateTime())->count() > 0;
                        $firstItem = is_array($c['top_product']) && !empty($c['top_product']) ? ($c['top_product'][0]['name'] ?? null) : null;
                        $reorderReady[] = ['email' => $email, 'avg_order_interval_days' => round($avgGap, 1), 'days_since_last_order' => round($daysSince, 0), 'reorder_readiness' => round($readiness, 2), 'recent_search_activity' => $hasRecentSearch, 'top_product' => $firstItem];
                    }
                }
                usort($reorderReady, fn($a, $b) => $b['reorder_readiness'] <=> $a['reorder_readiness']);
                return ['reorder_ready' => array_slice($reorderReady, 0, 30), 'total' => count($reorderReady), 'modules' => ['DataSync', 'AiSearch']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC43 failed: ' . $e->getMessage());
                return ['reorder_ready' => [], 'total' => 0, 'modules' => ['DataSync', 'AiSearch']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC44 — SEARCH GAP ANALYSIS (Search × Categories)
     * ══════════════════════════════════════════════════════════════ */
    public function UC44_searchGapAnalysis(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc44:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $zeros = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'results_count' => 0, 'query' => ['$ne' => null]]],
                        ['$group' => ['_id' => ['$toLower' => '$query'], 'volume' => ['$sum' => 1]]],
                        ['$sort' => ['volume' => -1]], ['$limit' => 30],
                    ], ['maxTimeMS' => 30000]));

                $catNames = DB::connection('mongodb')->table('synced_categories')->whereIn('tenant_id', $tids)->pluck('name')->filter()->values()->all();
                $result = collect($zeros)->map(function ($r) use ($catNames) {
                    $q = $r['_id'];
                    $matchedCat = collect($catNames)->first(fn($c) => str_contains(strtolower($c), substr($q, 0, 5)) || str_contains($q, strtolower(substr($c, 0, 5))));
                    return ['query' => $q, 'search_volume' => $r['volume'], 'nearest_category' => $matchedCat, 'is_category_gap' => $matchedCat === null];
                })->values()->all();
                return ['gaps' => $result, 'true_gaps' => array_values(array_filter($result, fn($r) => $r['is_category_gap'])), 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC44 failed: ' . $e->getMessage());
                return ['gaps' => [], 'true_gaps' => [], 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC45 — NEW PRODUCT LAUNCH READINESS (DataSync × Search × Marketing × Chatbot)
     * ══════════════════════════════════════════════════════════════ */
    public function UC45_newProductLaunchReadiness(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc45:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $since30d = now()->subDays(30)->toDateTime();
                $newProducts = DB::connection('mongodb')->table('synced_products')
                    ->whereIn('tenant_id', $tids)->where('created_at', '>=', $since30d)->limit(30)
                    ->get(['name', 'external_id', 'price', 'stock_status']);

                $result = [];
                foreach ($newProducts as $p) {
                    $name = $p['name'] ?? ''; if (strlen($name) < 3) continue;
                    $regex = new \MongoDB\BSON\Regex(preg_quote(substr($name, 0, 8), '/'), 'i');
                    $searches = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('query', 'regexp', $regex)->count();
                    $chatInquiries = DB::connection('mongodb')->table('chatbot_messages')->whereIn('tenant_id', $tids)->where('content', 'regexp', $regex)->count();
                    $campaignMentions = DB::table('marketing_campaigns')->where('tenant_id', $tenantId)->where(function($q) use ($name) { $q->where('subject', 'like', '%'.substr($name, 0, 8).'%')->orWhere('name', 'like', '%'.substr($name, 0, 8).'%'); })->count();
                    $score = min(100, ($searches * 5) + ($chatInquiries * 10) + ($campaignMentions * 20) + ($p['stock_status'] === 'instock' ? 20 : 0));
                    $result[] = ['product' => $name, 'price' => $p['price'] ?? 0, 'in_stock' => ($p['stock_status'] ?? '') === 'instock', 'search_count' => $searches, 'chat_inquiries' => $chatInquiries, 'campaign_mentions' => $campaignMentions, 'launch_readiness_score' => $score];
                }
                usort($result, fn($a, $b) => $b['launch_readiness_score'] <=> $a['launch_readiness_score']);
                return ['new_products' => $result, 'modules' => ['DataSync', 'AiSearch', 'Marketing', 'Chatbot']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC45 failed: ' . $e->getMessage());
                return ['new_products' => [], 'modules' => ['DataSync', 'AiSearch', 'Marketing', 'Chatbot']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC46 — CAMPAIGN CANNIBALIZATION DETECTOR (Marketing × Analytics)
     * ══════════════════════════════════════════════════════════════ */
    public function UC46_campaignCannibalizationDetector(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc46:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $messages = DB::table('marketing_messages')->where('tenant_id', $tenantId)->whereNotNull('recipient_email')->get(['recipient_email', 'channel', 'sent_at', 'converted']);
                $byEmail = [];
                foreach ($messages as $m) {
                    $email = $m->recipient_email; $day = date('Y-m-d', strtotime($m->sent_at ?? 'now'));
                    $byEmail[$email][$day][] = ['channel' => $m->channel, 'converted' => (bool)$m->converted];
                }
                $onlyEmail = ['count' => 0, 'converted' => 0]; $onlySms = ['count' => 0, 'converted' => 0]; $both = ['count' => 0, 'converted' => 0];
                foreach ($byEmail as $email => $days) {
                    foreach ($days as $day => $msgs) {
                        $channels = array_unique(array_column($msgs, 'channel'));
                        $conv = (bool)max(array_column($msgs, 'converted'));
                        if (in_array('email', $channels) && in_array('sms', $channels)) { $both['count']++; if ($conv) $both['converted']++; }
                        elseif (in_array('email', $channels)) { $onlyEmail['count']++; if ($conv) $onlyEmail['converted']++; }
                        elseif (in_array('sms', $channels)) { $onlySms['count']++; if ($conv) $onlySms['converted']++; }
                    }
                }
                $rateEmail = $onlyEmail['count'] > 0 ? round($onlyEmail['converted'] / $onlyEmail['count'] * 100, 2) : 0;
                $rateSms   = $onlySms['count'] > 0 ? round($onlySms['converted'] / $onlySms['count'] * 100, 2) : 0;
                $rateBoth  = $both['count'] > 0 ? round($both['converted'] / $both['count'] * 100, 2) : 0;
                $baseline  = max($rateEmail, $rateSms);
                $lift      = $baseline > 0 ? round(($rateBoth - $baseline) / $baseline * 100, 1) : 0;
                return ['only_email' => array_merge($onlyEmail, ['rate' => $rateEmail]), 'only_sms' => array_merge($onlySms, ['rate' => $rateSms]), 'both_channels' => array_merge($both, ['rate' => $rateBoth]), 'incremental_lift_pct' => $lift, 'is_cannibalization_risk' => $lift < 5, 'modules' => ['Marketing', 'Analytics']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC46 failed: ' . $e->getMessage());
                return ['only_email' => [], 'only_sms' => [], 'both_channels' => [], 'incremental_lift_pct' => 0, 'is_cannibalization_risk' => false, 'modules' => ['Marketing', 'Analytics']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC47 — PRICE SEARCH CONVERSION ELASTICITY (Products × Search)
     * ══════════════════════════════════════════════════════════════ */
    public function UC47_priceSearchConversionElasticity(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc47:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $discounted = DB::connection('mongodb')->table('synced_products')
                    ->whereIn('tenant_id', $tids)->where('original_price', '!=', null)->limit(30)
                    ->get(['name', 'price', 'original_price', 'external_id']);

                $result = [];
                foreach ($discounted as $p) {
                    $name = $p['name'] ?? ''; if (strlen($name) < 3) continue;
                    $current = (float)($p['price'] ?? 0); $original = (float)($p['original_price'] ?? 0);
                    if ($original <= 0 || $current >= $original) continue;
                    $discount_pct = round(($original - $current) / $original * 100, 1);
                    $regex = new \MongoDB\BSON\Regex(preg_quote(substr($name, 0, 6), '/'), 'i');
                    $searches = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('query', 'regexp', $regex)->count();
                    $clicks   = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('query', 'regexp', $regex)->where('clicked_product_id', '!=', null)->count();
                    $convs    = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->where('query', 'regexp', $regex)->where('converted', true)->count();
                    $result[] = ['product' => $name, 'original_price' => $original, 'current_price' => $current, 'discount_pct' => $discount_pct, 'searches' => $searches, 'click_rate' => $searches > 0 ? round($clicks / $searches * 100, 1) : 0, 'conversion_rate' => $clicks > 0 ? round($convs / $clicks * 100, 1) : 0];
                }
                usort($result, fn($a, $b) => $b['discount_pct'] <=> $a['discount_pct']);
                return ['price_elasticity' => $result, 'modules' => ['DataSync', 'AiSearch']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC47 failed: ' . $e->getMessage());
                return ['price_elasticity' => [], 'modules' => ['DataSync', 'AiSearch']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC48 — MARGIN-OPTIMIZED SEARCH RANKING (Search × Products)
     * ══════════════════════════════════════════════════════════════ */
    public function UC48_marginOptimizedSearchRanking(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc48:{$tenantId}", now()->addMinutes(15), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);
                $topQueries = DB::connection('mongodb')->table('search_logs')
                    ->raw(fn($col) => $col->aggregate([
                        ['$match' => ['tenant_id' => ['$in' => $tids], 'clicked_product_id' => ['$ne' => null], 'query' => ['$ne' => null]]],
                        ['$group' => ['_id' => ['$toLower' => '$query'], 'clicks' => ['$sum' => 1], 'clicked_ids' => ['$addToSet' => '$clicked_product_id']]],
                        ['$sort' => ['clicks' => -1]], ['$limit' => 20],
                    ], ['maxTimeMS' => 30000]));

                $result = [];
                foreach ($topQueries as $r) {
                    $ids = array_slice($r['clicked_ids'] ?? [], 0, 5);
                    $products = DB::connection('mongodb')->table('synced_products')
                        ->whereIn('tenant_id', $tids)->whereIn('external_id', $ids)
                        ->get(['name', 'price', 'cost', 'external_id']);
                    $productDetails = collect($products)->map(function ($p) {
                        $price = (float)($p['price'] ?? 0); $cost = (float)($p['cost'] ?? 0);
                        $margin = $price > 0 && $cost > 0 ? round(($price - $cost) / $price * 100, 1) : null;
                        return ['name' => $p['name'] ?? '', 'price' => $price, 'margin_pct' => $margin];
                    })->values()->all();
                    $avgMargin = collect($productDetails)->whereNotNull('margin_pct')->avg('margin_pct');
                    $result[] = ['query' => $r['_id'], 'clicks' => $r['clicks'], 'top_clicked_products' => $productDetails, 'avg_margin_pct' => $avgMargin ? round($avgMargin, 1) : null, 'margin_flag' => $avgMargin !== null && $avgMargin < 15 ? 'low_margin_leakage' : 'ok'];
                }
                return ['search_margin_analysis' => $result, 'modules' => ['AiSearch', 'DataSync']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC48 failed: ' . $e->getMessage());
                return ['search_margin_analysis' => [], 'modules' => ['AiSearch', 'DataSync']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC49 — OMNI-CHANNEL CONVERSION FUNNEL (ALL 6 MODULES — USP)
     * ══════════════════════════════════════════════════════════════ */
    public function UC49_omniChannelConversionFunnel(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc49:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);

                // Stage 1: Campaign Sent
                $campaignSent = DB::table('marketing_campaigns')->where('tenant_id', $tenantId)->sum('total_sent') ?? 0;
                // Stage 2: Campaign Opened
                $campaignOpened = DB::table('marketing_campaigns')->where('tenant_id', $tenantId)->sum('total_opened') ?? 0;
                // Stage 3: Search Performed
                $searchCount = DB::connection('mongodb')->table('search_logs')->whereIn('tenant_id', $tids)->count();
                // Stage 4: Product Viewed (tracked events)
                $productViews = DB::connection('mongodb')->table('tracking_events')->whereIn('tenant_id', $tids)->where('event_type', 'product_view')->count();
                // Stage 5: Chatbot Assisted
                $chatAssists = DB::connection('mongodb')->table('chatbot_conversations')->whereIn('tenant_id', $tids)->count();
                // Stage 6: Cart Added
                $cartAdds = DB::connection('mongodb')->table('tracking_events')->whereIn('tenant_id', $tids)->where('event_type', 'add_to_cart')->count();
                // Stage 7: Order Placed
                $ordersPlaced = DB::connection('mongodb')->table('synced_orders')->whereIn('tenant_id', $tids)->where('status', 'not in', ['cancelled', 'canceled'])->count();
                // Stage 8: Repeat Purchasers
                $repeatBuyers = DB::connection('mongodb')->table('synced_orders')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]], ['$group' => ['_id' => '$customer_email', 'c' => ['$sum' => 1]]], ['$match' => ['c' => ['$gte' => 2]]]], ['maxTimeMS' => 30000]));
                $repeatCount = collect($repeatBuyers)->count();

                $stages = [
                    ['stage' => '1_campaign_sent',    'label' => 'Campaign Sent',     'count' => (int)$campaignSent,  'module' => 'Marketing'],
                    ['stage' => '2_campaign_opened',  'label' => 'Campaign Opened',   'count' => (int)$campaignOpened,'module' => 'Marketing'],
                    ['stage' => '3_search_performed', 'label' => 'Search Performed',  'count' => $searchCount,        'module' => 'AiSearch'],
                    ['stage' => '4_product_viewed',   'label' => 'Product Viewed',    'count' => $productViews,       'module' => 'Analytics'],
                    ['stage' => '5_chatbot_assisted', 'label' => 'Chatbot Assisted',  'count' => $chatAssists,        'module' => 'Chatbot'],
                    ['stage' => '6_cart_added',       'label' => 'Cart Added',        'count' => $cartAdds,           'module' => 'Analytics'],
                    ['stage' => '7_order_placed',     'label' => 'Order Placed',      'count' => $ordersPlaced,       'module' => 'DataSync'],
                    ['stage' => '8_repeat_purchase',  'label' => 'Repeat Purchase',   'count' => $repeatCount,        'module' => 'DataSync+Analytics'],
                ];

                // Calculate drop-off between each stage
                for ($i = 1; $i < count($stages); $i++) {
                    $prev = $stages[$i-1]['count'];
                    $curr = $stages[$i]['count'];
                    $stages[$i]['dropoff_pct'] = $prev > 0 ? round((1 - min(1, $curr / $prev)) * 100, 1) : 0;
                }
                $stages[0]['dropoff_pct'] = 0;

                return ['funnel' => $stages, 'overall_conversion_rate' => $campaignSent > 0 ? round($ordersPlaced / $campaignSent * 100, 3) : 0, 'modules' => ['Marketing', 'AiSearch', 'Analytics', 'Chatbot', 'DataSync', 'BusinessIntelligence']];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC49 failed: ' . $e->getMessage());
                return ['funnel' => [], 'overall_conversion_rate' => 0, 'modules' => ['Marketing', 'AiSearch', 'Analytics', 'Chatbot', 'DataSync', 'BusinessIntelligence']];
            }
        });
    }

    /* ══════════════════════════════════════════════════════════════
     *  UC50 — STORE HEALTH SCORE (ALL 6 MODULES — USP)
     * ══════════════════════════════════════════════════════════════ */
    public function UC50_storeHealthScore(int $tenantId): array
    {
        return Cache::remember("bi:cross:uc50:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            try {
                $tids = $this->tid($tenantId);

                // (1) Search Quality Score (20 pts)
                $sAgg = DB::connection('mongodb')->table('search_logs')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids]]], ['$group' => ['_id' => null, 'total' => ['$sum' => 1], 'clicks' => ['$sum' => ['$cond' => [['$ne' => ['$clicked_product_id', null]], 1, 0]]], 'zeros' => ['$sum' => ['$cond' => [['$eq' => ['$results_count', 0]], 1, 0]]]]]], ['maxTimeMS' => 20000]));
                $s = collect($sAgg)->first();
                $ctr = ($s['total'] ?? 0) > 0 ? $s['clicks'] / $s['total'] : 0;
                $zeroRate = ($s['total'] ?? 0) > 0 ? $s['zeros'] / $s['total'] : 0;
                $searchScore = round(($ctr * 15 + (1 - $zeroRate) * 5) * 20, 1);

                // (2) Chatbot Resolution Score (20 pts)
                $cAgg = DB::connection('mongodb')->table('chatbot_conversations')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids]]], ['$group' => ['_id' => null, 'total' => ['$sum' => 1], 'resolved' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'resolved']], 1, 0]]], 'avg_sat' => ['$avg' => '$satisfaction_score']]]], ['maxTimeMS' => 20000]));
                $c = collect($cAgg)->first();
                $resRate = ($c['total'] ?? 0) > 0 ? ($c['resolved'] ?? 0) / $c['total'] : 0;
                $satRate = (($c['avg_sat'] ?? 0) / 5);
                $chatScore = round(($resRate * 0.5 + $satRate * 0.5) * 20, 1);

                // (3) Campaign ROI Score (20 pts)
                $mAgg = DB::table('marketing_campaigns')->where('tenant_id', $tenantId)->selectRaw('avg(case when total_sent>0 then total_opened/total_sent else 0 end) as avg_open, avg(case when total_sent>0 then total_converted/total_sent else 0 end) as avg_conv')->first();
                $campaignScore = round(((float)($mAgg->avg_open ?? 0) * 0.4 + (float)($mAgg->avg_conv ?? 0) * 0.6) * 20, 1);

                // (4) Inventory Health (20 pts)
                $totalProds = DB::connection('mongodb')->table('synced_products')->whereIn('tenant_id', $tids)->count();
                $inStockProds = DB::connection('mongodb')->table('synced_products')->whereIn('tenant_id', $tids)->where('stock_status', 'instock')->count();
                $inventoryScore = $totalProds > 0 ? round($inStockProds / $totalProds * 20, 1) : 0;

                // (5) Customer Retention (20 pts)
                $custAgg = DB::connection('mongodb')->table('synced_orders')->raw(fn($col) => $col->aggregate([['$match' => ['tenant_id' => ['$in' => $tids], 'status' => ['$nin' => ['cancelled', 'canceled']]]], ['$group' => ['_id' => '$customer_email', 'cnt' => ['$sum' => 1]]]], ['maxTimeMS' => 20000]));
                $allCusts = collect($custAgg);
                $repeatPct = $allCusts->count() > 0 ? $allCusts->where('cnt', '>=', 2)->count() / $allCusts->count() : 0;
                $champPct = 0;
                $cdpTotal = DB::connection('mongodb')->table('cdp_profiles')->whereIn('tenant_id', $tids)->count();
                if ($cdpTotal > 0) { $champCount = DB::connection('mongodb')->table('cdp_profiles')->whereIn('tenant_id', $tids)->where('computed.rfm_segment', 'Champions')->count(); $champPct = $champCount / $cdpTotal; }
                $retentionScore = round(($repeatPct * 0.6 + $champPct * 0.4) * 20, 1);

                $totalScore = min(100, round($searchScore + $chatScore + $campaignScore + $inventoryScore + $retentionScore, 1));

                return [
                    'store_health_score' => $totalScore,
                    'grade'              => $totalScore >= 80 ? 'A' : ($totalScore >= 65 ? 'B' : ($totalScore >= 50 ? 'C' : 'D')),
                    'breakdown'          => [
                        'search_quality'    => ['score' => $searchScore, 'max' => 20, 'ctr' => round($ctr * 100, 1), 'zero_result_rate' => round($zeroRate * 100, 1)],
                        'chatbot_resolution'=> ['score' => $chatScore, 'max' => 20, 'resolution_rate' => round($resRate * 100, 1), 'avg_satisfaction' => round($c['avg_sat'] ?? 0, 1)],
                        'campaign_roi'      => ['score' => $campaignScore, 'max' => 20, 'avg_open_rate' => round((float)($mAgg->avg_open ?? 0) * 100, 1), 'avg_conv_rate' => round((float)($mAgg->avg_conv ?? 0) * 100, 1)],
                        'inventory_health'  => ['score' => $inventoryScore, 'max' => 20, 'in_stock_rate' => $totalProds > 0 ? round($inStockProds / $totalProds * 100, 1) : 0],
                        'customer_retention'=> ['score' => $retentionScore, 'max' => 20, 'repeat_purchase_rate' => round($repeatPct * 100, 1), 'champions_pct' => round($champPct * 100, 1)],
                    ],
                    'modules' => ['AiSearch', 'Chatbot', 'Marketing', 'DataSync', 'Analytics', 'BusinessIntelligence'],
                ];
            } catch (\Throwable $e) {
                \Log::warning('[BI] UC50 failed: ' . $e->getMessage());
                return ['store_health_score' => 0, 'grade' => 'N/A', 'breakdown' => [], 'modules' => ['AiSearch', 'Chatbot', 'Marketing', 'DataSync', 'Analytics', 'BusinessIntelligence']];
            }
        });
    }
}

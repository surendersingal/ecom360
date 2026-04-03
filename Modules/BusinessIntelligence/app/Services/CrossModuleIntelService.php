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
     *  5.5 — CUSTOMER 360 (unified view for a single customer)
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
}

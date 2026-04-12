<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\BusinessIntelligence\Services\RevenueIntelService;
use Modules\BusinessIntelligence\Services\ProductIntelService;
use Modules\BusinessIntelligence\Services\CustomerIntelService;
use Modules\BusinessIntelligence\Services\OperationsIntelService;
use Carbon\Carbon;
use Modules\BusinessIntelligence\Services\CrossModuleIntelService;

/**
 * BI Intelligence Controller
 *
 * Web pages: return blade views (data loaded via AJAX → API methods below)
 * API methods: call service layer → return JSON
 */
final class BiController extends Controller
{
    public function __construct(
        private readonly RevenueIntelService    $revenue,
        private readonly ProductIntelService    $product,
        private readonly CustomerIntelService   $customer,
        private readonly OperationsIntelService $operations,
        private readonly CrossModuleIntelService $crossModule,
    ) {}

    private function tid(Request $request): int
    {
        // Try request attributes first (set by API key middleware)
        $tenant = $request->attributes->get('tenant');
        if ($tenant !== null) {
            return (int) $tenant->id;
        }

        // Fall back to authenticated user's tenant_id (Sanctum auth)
        $user = $request->user();
        if ($user !== null && isset($user->tenant_id)) {
            return (int) $user->tenant_id;
        }

        abort(403, 'Unable to resolve tenant context.');
    }

    /**
     * Safely parse a date string into a Carbon instance.
     * Returns null when the value is empty or unparseable.
     */
    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Parse an integer query parameter with bounds.
     */
    private function parseIntParam(?string $value, int $default, int $min = 1, int $max = 500): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $int = (int) $value;
        return max($min, min($max, $int));
    }

    /* ═══════════════════════════════════════
     *  WEB PAGES (AJAX-driven, return views)
     * ═══════════════════════════════════════ */

    public function revenue(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.revenue');
    }

    public function products(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.products');
    }

    public function customers(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.customers');
    }

    public function cohorts(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.cohorts');
    }

    public function operations(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.operations');
    }

    public function coupons(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.coupons');
    }

    public function attribution(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.attribution');
    }

    public function searchRevenue(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.search-revenue');
    }

    public function chatbotImpact(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.chatbot-impact');
    }

    public function copilot(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.copilot');
    }

    /* ═══════════════════════════════════════
     *  API ENDPOINTS — Revenue Intelligence
     * ═══════════════════════════════════════ */

    public function apiRevenueCommandCenter(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->revenue->commandCenter($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiRevenueByHour(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->revenue->revenueByHour($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiRevenueByDay(Request $request): JsonResponse
    {
        $tid  = $this->tid($request);
        $from = $this->parseDate($request->query('from'));
        $to   = $this->parseDate($request->query('to'));
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->revenue->revenueByDay($tid, $from, $to),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiRevenueTrend(Request $request): JsonResponse
    {
        $tid  = $this->tid($request);
        $days = $this->parseIntParam($request->query('days'), 90, 1, 365);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->revenue->trendAnalysis($tid, $days),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiRevenueBreakdown(Request $request): JsonResponse
    {
        $tid       = $this->tid($request);
        $allowed   = ['category', 'brand', 'channel', 'source'];
        $dimension = in_array($request->query('dimension'), $allowed, true)
                     ? $request->query('dimension')
                     : 'category';
        $from      = $this->parseDate($request->query('from'));
        $to        = $this->parseDate($request->query('to'));
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->revenue->revenueBreakdown($tid, $dimension, $from, $to),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiRevenueMargin(Request $request): JsonResponse
    {
        $tid  = $this->tid($request);
        $from = $this->parseDate($request->query('from'));
        $to   = $this->parseDate($request->query('to'));
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->revenue->marginAnalysis($tid, $from, $to),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiRevenueTopPerformers(Request $request): JsonResponse
    {
        $tid   = $this->tid($request);
        $from  = $this->parseDate($request->query('from'));
        $to    = $this->parseDate($request->query('to'));
        $limit = $this->parseIntParam($request->query('limit'), 10, 1, 500);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->revenue->topPerformers($tid, $from, $to, $limit),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /* ═══════════════════════════════════════
     *  API — Product Intelligence
     * ═══════════════════════════════════════ */

    public function apiProductLeaderboard(Request $request): JsonResponse
    {
        $tid    = $this->tid($request);
        $allowedSorts = ['revenue', 'qty', 'quantity', 'margin', 'orders', 'growth'];
        $sortRaw = $request->query('sort', 'revenue');
        // Map external param names to internal product array keys
        $sortMap = ['quantity' => 'qty'];
        $sortBy = isset($sortMap[$sortRaw]) ? $sortMap[$sortRaw]
                  : (in_array($sortRaw, $allowedSorts, true) ? $sortRaw : 'revenue');
        $from   = $this->parseDate($request->query('from'));
        $to     = $this->parseDate($request->query('to'));
        $limit  = $this->parseIntParam($request->query('limit'), 25, 1, 500);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->product->leaderboard($tid, $sortBy, $from, $to, $limit),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiProductStars(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->product->risingFallingStars($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiCategoryMatrix(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->product->categoryMatrix($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiParetoAnalysis(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->product->paretoAnalysis($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /* ═══════════════════════════════════════
     *  API — Customer Intelligence
     * ═══════════════════════════════════════ */

    public function apiCustomerOverview(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->customer->overview($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiCustomerAcquisition(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->customer->acquisitionTrend($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiCustomerGeo(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->customer->geoDistribution($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiCohortRetention(Request $request): JsonResponse
    {
        $tid    = $this->tid($request);
        $months = $this->parseIntParam($request->query('months'), 6, 1, 24);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->customer->cohortRetention($tid, $months),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiValueDistribution(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->customer->valueDistribution($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiNewVsReturning(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->customer->newVsReturning($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /* ═══════════════════════════════════════
     *  API — Operations Intelligence
     * ═══════════════════════════════════════ */

    public function apiOrderPipeline(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->operations->orderPipeline($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiDailyOrderVolume(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->operations->dailyOrderVolume($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiHeatmap(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->operations->activityHeatmap($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiCouponIntelligence(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->operations->couponIntelligence($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiPaymentAnalysis(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->operations->paymentAnalysis($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /* ═══════════════════════════════════════
     *  API — Cross-Module Intelligence
     * ═══════════════════════════════════════ */

    public function apiMarketingAttribution(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->crossModule->marketingAttribution($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiSearchRevenue(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->crossModule->searchRevenue($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiChatbotImpact(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->crossModule->chatbotImpact($tid),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apiCustomer360(Request $request): JsonResponse
    {
        $tid   = $this->tid($request);
        $email = $request->query('email');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'error' => 'A valid email is required'], 422);
        }
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->crossModule->customer360($tid, $email),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Use Cases UC05–UC50 ────────────────────────────────────────
    public function apiUC05(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC05_searchToOrderFunnel($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC06(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC06_zeroResultOpportunities($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC07(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC07_abandonedSearchRecovery($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC08(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC08_searchSeasonality($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC09(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC09_categorySearchToSalesGap($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC10(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC10_chatbotToCheckoutPath($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC11(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC11_chatbotAbandonment($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC12(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC12_chatbotProductComplaints($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC13(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC13_chatbotUpsellSuccess($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC14(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC14_chatbotSentimentVsOrders($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC15(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC15_campaignToSearchBehavior($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC16(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC16_segmentSearchAffinity($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC17(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC17_campaignUnsubscribeRisk($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC18(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC18_flowTriggerEffectiveness($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC19(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC19_campaignTimingOptimizer($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC20(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC20_demandForecastBySearch($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC21(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC21_outOfStockRevenueLoss($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC22(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC22_categoryTrendSurface($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC23(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC23_bundleOpportunity($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC24(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC24_brandSearchShare($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC25(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC25_highValueCustomerJourney($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC26(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC26_churnRiskWithChatSignals($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC27(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC27_dormantCustomerSearchHistory($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC28(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC28_newCustomerFirstTouchROI($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC29(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC29_vipBehaviorProfile($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC30(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC30_orderIssueHeatmap($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC31(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC31_returnRateBySearchQuery($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC32(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC32_paymentFailureRecovery($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC33(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC33_fulfillmentDelayImpact($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC34(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC34_peakDemandReadiness($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC35(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC35_revenueByAcquisitionChannel($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC36(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC36_discountSensitivityBySegment($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC37(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC37_ltvByFirstProduct($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC38(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC38_crossSellGap($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC39(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC39_liveCartAbandonment($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC40(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC40_rageClickToOrderLoss($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC41(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC41_sessionIntentScoring($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC42(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC42_realTimeChurnSignals($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC43(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC43_proactiveReorderSignals($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC44(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC44_searchGapAnalysis($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC45(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC45_newProductLaunchReadiness($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC46(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC46_campaignCannibalizationDetector($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC47(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC47_priceSearchConversionElasticity($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC48(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC48_marginOptimizedSearchRanking($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC49(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC49_omniChannelConversionFunnel($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
    public function apiUC50(Request $request): JsonResponse { $t=$this->tid($request); try { return response()->json(['success'=>true,'data'=>$this->crossModule->UC50_storeHealthScore($t)]); } catch(\Throwable $e){ return response()->json(['success'=>false,'error'=>$e->getMessage()],500); } }
}

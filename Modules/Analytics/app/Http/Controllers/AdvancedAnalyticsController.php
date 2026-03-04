<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Analytics\Services\PredictiveCLVService;
use Modules\Analytics\Services\RevenueWaterfallService;
use Modules\Analytics\Services\WhyExplanationService;
use Modules\Analytics\Services\BehavioralTriggerService;
use Modules\Analytics\Services\CustomerJourneyService;
use Modules\Analytics\Services\SmartRecommendationService;
use Modules\Analytics\Services\AudienceSyncService;
use Modules\Analytics\Services\RealTimeAlertsService;
use Modules\Analytics\Services\NaturalLanguageQueryService;
use Modules\Analytics\Services\CompetitiveBenchmarkService;

/**
 * Advanced Analytics API — 10 differentiating features.
 */
final class AdvancedAnalyticsController extends Controller
{
    use ApiResponse;

    // ─── Predictive CLV ──────────────────────────────────────────────

    public function clvPredict(Request $request): JsonResponse
    {
        $service = app(PredictiveCLVService::class);

        if ($visitorId = $request->query('visitor_id')) {
            $result = $service->predict($this->tenantId(), $visitorId);
        } else {
            $result = $service->calculateAll($this->tenantId(), (int) $request->query('limit', 100));
        }

        return $this->successResponse($result);
    }

    public function clvWhatIf(Request $request): JsonResponse
    {
        $request->validate([
            'visitor_id' => 'required|string',
            'scenario' => 'required|array',
        ]);

        $service = app(PredictiveCLVService::class);
        $result = $service->whatIf($this->tenantId(), $request->input('visitor_id'), $request->input('scenario'));

        return $this->successResponse($result);
    }

    // ─── Revenue Waterfall ───────────────────────────────────────────

    public function revenueWaterfall(Request $request): JsonResponse
    {
        $service = app(RevenueWaterfallService::class);
        $result = $service->analyze(
            $this->tenantId(),
            $request->query('start_date'),
            $request->query('end_date'),
        );

        return $this->successResponse($result);
    }

    // ─── Why Explanation ─────────────────────────────────────────────

    public function whyExplain(Request $request): JsonResponse
    {
        $request->validate([
            'metric' => 'required|in:revenue,orders,sessions,aov,conversion_rate',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $service = app(WhyExplanationService::class);
        $result = $service->explain(
            $this->tenantId(),
            $request->input('metric'),
            $request->input('start_date'),
            $request->input('end_date'),
            $request->input('prev_start_date'),
            $request->input('prev_end_date'),
        );

        return $this->successResponse($result);
    }

    // ─── Behavioral Triggers ─────────────────────────────────────────

    public function behavioralTriggers(Request $request): JsonResponse
    {
        $service = app(BehavioralTriggerService::class);
        $result = $service->evaluateAll($this->tenantId());

        return $this->successResponse($result);
    }

    // ─── Customer Journey ────────────────────────────────────────────

    public function customerJourney(Request $request): JsonResponse
    {
        $service = app(CustomerJourneyService::class);

        if ($visitorId = $request->query('visitor_id')) {
            $result = $service->getJourney($this->tenantId(), $visitorId);
        } else {
            $result = $service->getJourneyPatterns($this->tenantId(), (int) $request->query('limit', 100));
        }

        return $this->successResponse($result);
    }

    public function dropOffPoints(): JsonResponse
    {
        $service = app(CustomerJourneyService::class);
        $result = $service->getDropOffPoints($this->tenantId());

        return $this->successResponse($result);
    }

    // ─── Smart Recommendations ───────────────────────────────────────

    public function recommendations(Request $request): JsonResponse
    {
        $service = app(SmartRecommendationService::class);

        if ($visitorId = $request->query('visitor_id')) {
            $result = $service->forVisitor($this->tenantId(), $visitorId, (int) $request->query('limit', 10));
        } elseif ($productId = $request->query('product_id')) {
            $result = $service->forProduct($this->tenantId(), $productId, (int) $request->query('limit', 8));
        } else {
            $result = $service->trending($this->tenantId(), (int) $request->query('limit', 10));
        }

        return $this->successResponse($result);
    }

    // ─── Audience Sync ───────────────────────────────────────────────

    public function audienceSegments(): JsonResponse
    {
        $service = app(AudienceSyncService::class);
        return $this->successResponse($service->listSegments($this->tenantId()));
    }

    public function audienceSync(Request $request): JsonResponse
    {
        $request->validate([
            'segment_id' => 'required|integer',
            'destination' => 'required|string',
            'credentials' => 'required|array',
        ]);

        $service = app(AudienceSyncService::class);
        $result = $service->sync(
            $this->tenantId(),
            $request->input('segment_id'),
            $request->input('destination'),
            $request->input('credentials'),
        );

        return $this->successResponse($result);
    }

    public function audienceDestinations(): JsonResponse
    {
        $service = app(AudienceSyncService::class);
        return $this->successResponse($service->getSupportedDestinations());
    }

    // ─── Real-Time Alerts ────────────────────────────────────────────

    public function realtimePulse(): JsonResponse
    {
        $service = app(RealTimeAlertsService::class);
        return $this->successResponse($service->getPulse($this->tenantId()));
    }

    public function realtimeAlerts(): JsonResponse
    {
        $service = app(RealTimeAlertsService::class);
        return $this->successResponse($service->getRecentAlerts($this->tenantId()));
    }

    public function acknowledgeAlert(Request $request, string $alertId): JsonResponse
    {
        $service = app(RealTimeAlertsService::class);
        $service->acknowledge($this->tenantId(), $alertId);

        return $this->successResponse(['acknowledged' => true]);
    }

    // ─── Natural Language Query ──────────────────────────────────────

    public function nlQuery(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:3|max:500']);

        $service = app(NaturalLanguageQueryService::class);
        $result = $service->query($this->tenantId(), $request->input('q'));

        return $this->successResponse($result);
    }

    public function nlSuggest(Request $request): JsonResponse
    {
        $service = app(NaturalLanguageQueryService::class);
        return $this->successResponse($service->suggest($request->input('q', '')));
    }

    // ─── Competitive Benchmarks ──────────────────────────────────────

    public function competitiveBenchmarks(): JsonResponse
    {
        $service = app(CompetitiveBenchmarkService::class);
        return $this->successResponse($service->compare($this->tenantId()));
    }

    // ──────────────────────────────────────────────────────────────────

    private function tenantId(): int
    {
        $user = Auth::user();
        if ($user === null || !isset($user->tenant_id)) {
            abort(403, 'Tenant context required.');
        }
        return (string) $user->tenant_id;
    }
}

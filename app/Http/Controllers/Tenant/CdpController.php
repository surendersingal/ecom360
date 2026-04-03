<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Analytics\Models\CdpSegment;
use Modules\Analytics\Services\Cdp\CdpProfileService;
use Modules\Analytics\Services\Cdp\CdpRfmService;
use Modules\Analytics\Services\Cdp\CdpSegmentService;

/**
 * Handles all CDP (Customer Data Platform) pages and API endpoints.
 *
 * Web routes serve blade templates.
 * API routes (prefixed /api/v1/cdp) return JSON for AJAX calls from those templates.
 */
final class CdpController extends Controller
{
    public function __construct(
        private readonly CdpProfileService $profileService,
        private readonly CdpSegmentService $segmentService,
        private readonly CdpRfmService     $rfmService,
    ) {}

    private function tid(Request $request): string
    {
        // Try request attributes first (set by API key middleware)
        $tenant = $request->attributes->get('tenant');
        if ($tenant !== null) {
            return (string) $tenant->id;
        }

        // Fall back to authenticated user's tenant_id (Sanctum auth)
        $user = $request->user();
        if ($user !== null && isset($user->tenant_id)) {
            return (string) $user->tenant_id;
        }

        abort(403, 'Unable to resolve tenant context.');
    }

    /* ══════════════════════════════════════════════════════════
     *  WEB PAGES — Blade views
     * ══════════════════════════════════════════════════════════ */

    /** CDP Dashboard — overview of all CDP metrics */
    public function dashboard(Request $request, string $tenant): View
    {
        return view('tenant.pages.cdp.dashboard');
    }

    /** Customer Profiles — list view with search/filter */
    public function profiles(Request $request, string $tenant): View
    {
        return view('tenant.pages.cdp.profiles');
    }

    /** Single Customer Profile — the Golden Record */
    public function profileDetail(Request $request, string $tenant, string $profileId): View
    {
        return view('tenant.pages.cdp.profile-detail', ['profileId' => $profileId]);
    }

    /** Segments — list + builder */
    public function segments(Request $request, string $tenant): View
    {
        return view('tenant.pages.cdp.segments');
    }

    /** Segment Detail — members + performance */
    public function segmentDetail(Request $request, string $tenant, string $segmentId): View
    {
        return view('tenant.pages.cdp.segment-detail', ['segmentId' => $segmentId]);
    }

    /** RFM Analysis — 10-segment grid */
    public function rfm(Request $request, string $tenant): View
    {
        return view('tenant.pages.cdp.rfm');
    }

    /** Predictions — AI-powered predictive segments */
    public function predictions(Request $request, string $tenant): View
    {
        return view('tenant.pages.cdp.predictions');
    }

    /** Data Health — profile completeness + quality */
    public function dataHealth(Request $request, string $tenant): View
    {
        return view('tenant.pages.cdp.data-health');
    }

    /* ══════════════════════════════════════════════════════════
     *  API ENDPOINTS — JSON responses for AJAX
     * ══════════════════════════════════════════════════════════ */

    /** GET /api/v1/cdp/dashboard — dashboard KPIs */
    public function apiDashboard(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $stats = $this->profileService->getDashboardStats($tid);
            return response()->json(['success' => true, 'data' => $stats]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiDashboard failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['total_profiles' => 0, 'total_segments' => 0, 'data_health' => 0]]);
        }
    }

    /** GET /api/v1/cdp/profiles — paginated profile list */
    public function apiProfiles(Request $request): JsonResponse
    {
        $tid  = $this->tid($request);
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 25);
        $filters = [
            'search'      => $request->query('search'),
            'rfm_segment' => $request->query('rfm_segment'),
            'churn_risk'  => $request->query('churn_risk'),
            'min_ltv'     => $request->query('min_ltv'),
            'city'        => $request->query('city'),
        ];

        try {
            $result = $this->profileService->listProfiles($tid, $page, $perPage, $filters);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiProfiles failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['profiles' => [], 'total' => 0]]);
        }
    }

    /** GET /api/v1/cdp/profiles/{id} — single profile detail */
    public function apiProfileDetail(Request $request, string $profileId): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $profile = $this->profileService->getProfile($tid, $profileId);
            if (! $profile) {
                return response()->json(['success' => false, 'error' => 'Profile not found'], 404);
            }
            return response()->json(['success' => true, 'data' => $profile]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiProfileDetail failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => null]);
        }
    }

    /** GET /api/v1/cdp/profiles/{id}/timeline — customer event timeline */
    public function apiProfileTimeline(Request $request, string $profileId): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $profile = $this->profileService->getProfile($tid, $profileId);
            if (! $profile) {
                return response()->json(['success' => false, 'error' => 'Profile not found'], 404);
            }
            $timeline = $this->profileService->getTimeline($tid, $profile->email, (int) $request->query('limit', 50));
            return response()->json(['success' => true, 'data' => $timeline]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiProfileTimeline failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['events' => []]]);
        }
    }

    /** POST /api/v1/cdp/profiles/build — trigger full profile rebuild */
    public function apiBuildProfiles(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $result = $this->profileService->buildAllProfiles($tid);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiBuildProfiles failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['message' => 'Build service temporarily unavailable']]);
        }
    }

    /** GET /api/v1/cdp/segments — list all segments */
    public function apiSegments(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $segments = $this->segmentService->listSegments($tid);
            return response()->json(['success' => true, 'data' => $segments]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiSegments failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['segments' => []]]);
        }
    }

    /** POST /api/v1/cdp/segments — create a new segment */
    public function apiCreateSegment(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        $request->validate([
            'name'       => 'required|string|max:100',
            'conditions' => 'required|array',
        ]);

        try {
            $segment = $this->segmentService->createSegment($tid, $request->all());
            return response()->json(['success' => true, 'data' => $segment], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiCreateSegment failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => null, 'message' => 'Segment creation temporarily unavailable']);
        }
    }

    /** GET /api/v1/cdp/segments/{id} — segment detail + members */
    public function apiSegmentDetail(Request $request, string $segmentId): JsonResponse
    {
        $tid  = $this->tid($request);
        $page = (int) $request->query('page', 1);
        try {
            $result = $this->segmentService->getSegmentMembers($tid, $segmentId, $page);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiSegmentDetail failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['segment' => null, 'members' => [], 'total' => 0]]);
        }
    }

    /** PUT /api/v1/cdp/segments/{id} — update segment */
    public function apiUpdateSegment(Request $request, string $segmentId): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $segment = $this->segmentService->updateSegment($tid, $segmentId, $request->all());
            return response()->json(['success' => true, 'data' => $segment]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiUpdateSegment failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => null, 'message' => 'Update temporarily unavailable']);
        }
    }

    /** DELETE /api/v1/cdp/segments/{id} — delete segment */
    public function apiDeleteSegment(Request $request, string $segmentId): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $this->segmentService->deleteSegment($tid, $segmentId);
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiDeleteSegment failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'message' => 'Delete temporarily unavailable']);
        }
    }

    /** POST /api/v1/cdp/segments/preview — preview segment member count */
    public function apiPreviewSegment(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        $request->validate(['conditions' => 'required|array']);

        try {
            $count = $this->segmentService->previewSegment($tid, $request->input('conditions'));
            return response()->json(['success' => true, 'data' => ['member_count' => $count]]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiPreviewSegment failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['member_count' => 0]]);
        }
    }

    /** POST /api/v1/cdp/segments/{id}/evaluate — force re-evaluate */
    public function apiEvaluateSegment(Request $request, string $segmentId): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $segment = CdpSegment::forTenant($tid)->findOrFail($segmentId);
            $count = $this->segmentService->evaluateSegment($tid, $segment);
            return response()->json(['success' => true, 'data' => ['member_count' => $count]]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiEvaluateSegment failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['member_count' => 0]]);
        }
    }

    /** POST /api/v1/cdp/segments/overlap — audience overlap analysis */
    public function apiSegmentOverlap(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        $request->validate([
            'segment_a_id' => 'required|string',
            'segment_b_id' => 'required|string',
        ]);

        try {
            $result = $this->segmentService->overlapAnalysis(
                $tid,
                $request->input('segment_a_id'),
                $request->input('segment_b_id')
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiSegmentOverlap failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['overlap_count' => 0, 'overlap_percentage' => 0]]);
        }
    }

    /** GET /api/v1/cdp/rfm — RFM segment summary */
    public function apiRfm(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $summary = $this->rfmService->getRfmSummary($tid);
            return response()->json(['success' => true, 'data' => $summary]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiRfm failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['segments' => [], 'grid' => []]]);
        }
    }

    /** POST /api/v1/cdp/rfm/recalculate — trigger RFM + computed props recalculation */
    public function apiRfmRecalculate(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $result = $this->rfmService->computeAll($tid);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiRfmRecalculate failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['message' => 'RFM recalculation temporarily unavailable']]);
        }
    }

    /** GET /api/v1/cdp/predictions — predictive segment summaries */
    public function apiPredictions(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $profiles = \Modules\Analytics\Models\CdpProfile::forTenant($tid)
                ->whereNotNull('computed.rfm_segment')
                ->get();

            $likelyToBuy     = $profiles->filter(fn($p) => ($p->computed['purchase_propensity'] ?? 0) >= 0.7)->count();
            $highChurn       = $profiles->filter(fn($p) => ($p->computed['churn_risk'] ?? 0) >= 0.7)->count();
            $discountSeekers = $profiles->filter(fn($p) => ($p->computed['discount_sensitivity'] ?? 0) >= 0.6)->count();
            $fullPriceBuyers = $profiles->filter(fn($p) => ($p->transactional['coupon_usage_rate'] ?? 1) == 0)->count();

            // Distribution buckets
            $propBuckets = [
                ['range' => '0-20%',  'count' => $profiles->filter(fn($p) => ($p->computed['purchase_propensity'] ?? 0) < 0.2)->count()],
                ['range' => '20-40%', 'count' => $profiles->filter(fn($p) => ($v = $p->computed['purchase_propensity'] ?? 0) >= 0.2 && $v < 0.4)->count()],
                ['range' => '40-60%', 'count' => $profiles->filter(fn($p) => ($v = $p->computed['purchase_propensity'] ?? 0) >= 0.4 && $v < 0.6)->count()],
                ['range' => '60-80%', 'count' => $profiles->filter(fn($p) => ($v = $p->computed['purchase_propensity'] ?? 0) >= 0.6 && $v < 0.8)->count()],
                ['range' => '80-100%','count' => $profiles->filter(fn($p) => ($p->computed['purchase_propensity'] ?? 0) >= 0.8)->count()],
            ];

            $churnBuckets = [
                ['range' => '0-20%',  'count' => $profiles->filter(fn($p) => ($p->computed['churn_risk'] ?? 0) < 0.2)->count()],
                ['range' => '20-40%', 'count' => $profiles->filter(fn($p) => ($v = $p->computed['churn_risk'] ?? 0) >= 0.2 && $v < 0.4)->count()],
                ['range' => '40-60%', 'count' => $profiles->filter(fn($p) => ($v = $p->computed['churn_risk'] ?? 0) >= 0.4 && $v < 0.6)->count()],
                ['range' => '60-80%', 'count' => $profiles->filter(fn($p) => ($v = $p->computed['churn_risk'] ?? 0) >= 0.6 && $v < 0.8)->count()],
                ['range' => '80-100%','count' => $profiles->filter(fn($p) => ($p->computed['churn_risk'] ?? 0) >= 0.8)->count()],
            ];

            $ltvBuckets = [
                ['range' => '₹0',        'count' => $profiles->filter(fn($p) => ($p->predictions['predicted_ltv'] ?? 0) == 0)->count()],
                ['range' => '₹1-5K',     'count' => $profiles->filter(fn($p) => ($v = $p->predictions['predicted_ltv'] ?? 0) > 0 && $v <= 5000)->count()],
                ['range' => '₹5K-25K',   'count' => $profiles->filter(fn($p) => ($v = $p->predictions['predicted_ltv'] ?? 0) > 5000 && $v <= 25000)->count()],
                ['range' => '₹25K-100K', 'count' => $profiles->filter(fn($p) => ($v = $p->predictions['predicted_ltv'] ?? 0) > 25000 && $v <= 100000)->count()],
                ['range' => '₹100K+',    'count' => $profiles->filter(fn($p) => ($p->predictions['predicted_ltv'] ?? 0) > 100000)->count()],
            ];

            // Top LTV customers
            $topLtv = $profiles->sortByDesc(fn($p) => $p->predictions['predicted_ltv'] ?? 0)
                ->take(10)
                ->map(fn($p) => [
                    '_id'                 => (string) $p->_id,
                    'email'               => $p->email,
                    'name'                => trim(($p->demographics['firstname'] ?? '') . ' ' . ($p->demographics['lastname'] ?? '')) ?: $p->email,
                    'predicted_ltv'       => $p->predictions['predicted_ltv'] ?? 0,
                    'current_ltv'         => $p->transactional['lifetime_revenue'] ?? 0,
                    'purchase_propensity' => $p->computed['purchase_propensity'] ?? 0,
                    'churn_risk_level'    => $p->computed['churn_risk_level'] ?? null,
                    'rfm_segment'         => $p->computed['rfm_segment'] ?? null,
                ])->values();

            return response()->json([
                'success' => true,
                'data'    => [
                    'likely_to_buy'           => $likelyToBuy,
                    'high_churn'              => $highChurn,
                    'discount_seekers'        => $discountSeekers,
                    'full_price_buyers'       => $fullPriceBuyers,
                    'propensity_distribution' => $propBuckets,
                    'churn_distribution'      => $churnBuckets,
                    'ltv_distribution'        => $ltvBuckets,
                    'top_ltv_customers'       => $topLtv,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiPredictions failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['churn_risk' => [], 'ltv_distribution' => [], 'likely_to_buy' => 0, 'high_churn' => 0, 'discount_seekers' => 0, 'full_price_buyers' => 0, 'propensity_distribution' => [], 'churn_distribution' => [], 'top_ltv_customers' => []]]);
        }
    }

    /** GET /api/v1/cdp/data-health — data quality metrics */
    public function apiDataHealth(Request $request): JsonResponse
    {
        $tid = $this->tid($request);
        try {
            $total = \Modules\Analytics\Models\CdpProfile::forTenant($tid)->count();
            $profiles = \Modules\Analytics\Models\CdpProfile::forTenant($tid)->get();

            $missingDob    = $profiles->filter(fn($p) => empty($p->demographics['dob'] ?? null))->count();
            $missingGender = $profiles->filter(fn($p) => empty($p->demographics['gender'] ?? null))->count();
            $missingCity   = $profiles->filter(fn($p) => empty($p->demographics['city'] ?? null))->count();
            $missingPhone  = $profiles->filter(fn($p) => empty($p->phone))->count();
            $withIssues    = $profiles->filter(fn($p) => ! empty($p->data_quality_flags))->count();
            $perfect       = $profiles->filter(fn($p) => ($p->profile_completeness ?? 0) >= 100)->count();

            // Completeness distribution as array of {range, count}
            $completeness = $profiles->pluck('profile_completeness')->filter();
            $avgCompleteness = $completeness->avg() ?? 0;
            $completenessDistribution = [
                ['range' => '0-25%',   'count' => $completeness->filter(fn($v) => $v <= 25)->count()],
                ['range' => '26-50%',  'count' => $completeness->filter(fn($v) => $v > 25 && $v <= 50)->count()],
                ['range' => '51-75%',  'count' => $completeness->filter(fn($v) => $v > 50 && $v <= 75)->count()],
                ['range' => '76-100%', 'count' => $completeness->filter(fn($v) => $v > 75)->count()],
            ];

            // Missing fields array
            $missingFields = collect([
                ['field' => 'Date of Birth', 'count' => $missingDob],
                ['field' => 'Gender',        'count' => $missingGender],
                ['field' => 'City',          'count' => $missingCity],
                ['field' => 'Phone',         'count' => $missingPhone],
            ])->sortByDesc('count')->values();

            // Quality issues breakdown
            $issueMap = [];
            foreach ($profiles as $p) {
                foreach ($p->data_quality_flags ?? [] as $flag) {
                    $issueMap[$flag] = ($issueMap[$flag] ?? 0) + 1;
                }
            }
            $qualityIssues = collect($issueMap)->map(fn($cnt, $issue) => ['issue' => $issue, 'count' => $cnt])
                ->sortByDesc('count')->values();

            return response()->json([
                'success' => true,
                'data'    => [
                    'total_profiles'            => $total,
                    'avg_completeness'          => round($avgCompleteness, 1),
                    'profiles_with_issues'      => $withIssues,
                    'perfect_profiles'          => $perfect,
                    'completeness_distribution' => $completenessDistribution,
                    'missing_fields'            => $missingFields,
                    'quality_issues'            => $qualityIssues,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[CDP] apiDataHealth failed: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => ['completeness' => 0, 'quality_score' => 0, 'total_profiles' => 0, 'avg_completeness' => 0, 'profiles_with_issues' => 0, 'perfect_profiles' => 0, 'completeness_distribution' => [], 'missing_fields' => [], 'quality_issues' => []]]);
        }
    }

    /** GET /api/v1/cdp/dimensions — available segment builder dimensions */
    public function apiDimensions(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'dimensions' => CdpSegment::dimensions(),
                'operators'  => CdpSegment::operators(),
            ],
        ]);
    }
}

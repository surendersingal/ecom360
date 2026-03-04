<?php

namespace Modules\AiSearch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\AiSearch\Services\SearchService;
use Modules\AiSearch\Services\VisualSearchService;

class AiSearchController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SearchService $searchService,
        private VisualSearchService $visualSearchService
    ) {}

    /**
     * Resolve tenant ID from Sanctum user OR ValidateTrackingApiKey middleware.
     */
    private function tenantId(Request $request): string
    {
        // API-key auth (widget/storefront) — set by ValidateTrackingApiKey middleware
        if ($request->has('_tenant_id')) {
            return (string) $request->input('_tenant_id');
        }

        // Sanctum auth (dashboard / admin)
        return (string) $request->user()->tenant_id;
    }

    /**
     * POST|GET /api/v1/search — AI-powered product search.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query'    => 'required_without:q|nullable|string|max:500',
            'q'        => 'required_without:query|nullable|string|max:500',
            'filters'  => 'nullable|array',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'limit'    => 'nullable|integer|min:1|max:100',
            'sort_by'  => 'nullable|string|in:relevance,price_asc,price_desc,newest,rating',
            'language' => 'nullable|string|max:5',
        ]);

        $tenantId = $this->tenantId($request);

        // Normalise: widget sends 'q', dashboard sends 'query'
        $params = $request->all();
        if (!isset($params['query']) && isset($params['q'])) {
            $params['query'] = $params['q'];
        }
        if (isset($params['limit']) && !isset($params['per_page'])) {
            $params['per_page'] = $params['limit'];
        }

        // Build filters from flat widget params if not passed as filters array
        if (empty($params['filters'])) {
            $filters = [];
            if (!empty($params['categories'])) {
                $filters['category'] = $params['categories'];
            }
            if (!empty($params['min_price'])) {
                $filters['min_price'] = $params['min_price'];
            }
            if (!empty($params['max_price'])) {
                $filters['max_price'] = $params['max_price'];
            }
            if (!empty($filters)) {
                $params['filters'] = $filters;
            }
        }

        $result = $this->searchService->search($tenantId, $params);

        return $result['success']
            ? response()->json($result)
            : $this->errorResponse($result['error'] ?? 'Search failed', 422);
    }

    /**
     * GET /api/v1/search/suggest — Auto-complete suggestions.
     */
    public function suggest(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1|max:200',
        ]);

        $tenantId = $this->tenantId($request);
        $result = $this->searchService->suggest($tenantId, $request->input('q'));

        return response()->json($result);
    }

    /**
     * POST /api/v1/search/visual — Visual/image-based search.
     */
    public function visualSearch(Request $request): JsonResponse
    {
        $request->validate([
            'image_base64' => 'required_without:image_url|nullable|string',
            'image_url'    => 'required_without:image_base64|nullable|url',
            'limit'        => 'nullable|integer|min:1|max:50',
        ]);

        $tenantId = $this->tenantId($request);
        $result = $this->visualSearchService->searchByImage($tenantId, $request->all());

        return $result['success']
            ? response()->json($result)
            : $this->errorResponse($result['error'] ?? 'Visual search failed', 422);
    }

    /**
     * GET /api/v1/search/similar/{productId} — Find similar products.
     */
    public function similar(Request $request, string $productId): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $limit = (int) $request->input('limit', 10);
        $result = $this->visualSearchService->findSimilar($tenantId, $productId, $limit);

        return $result['success']
            ? response()->json($result)
            : $this->errorResponse($result['error'] ?? 'Not found', 404);
    }

    /**
     * GET /api/v1/search/trending — Trending searches.
     */
    public function trending(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        return $this->successResponse($this->searchService->getTrending($tenantId));
    }

    /**
     * GET /api/v1/search/analytics — Search analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $days = (int) $request->input('days', 30);
        return $this->successResponse($this->searchService->getAnalytics($tenantId, $days));
    }
}

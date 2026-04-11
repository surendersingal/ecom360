<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\BusinessIntelligence\Services\PredictionService;
use Modules\BusinessIntelligence\Services\BenchmarkService;
use Modules\BusinessIntelligence\Services\QueryBuilderService;

final class InsightsController extends Controller
{
    use ApiResponse;

    /**
     * Get predictions for a specific customer or all.
     */
    public function predictions(Request $request): JsonResponse
    {
        $service = app(PredictionService::class);
        $customerId = $request->query('customer_id');

        if ($customerId) {
            $predictions = $service->getCustomerPredictions($this->tenantId(), $customerId);
        } else {
            $predictions = $service->list($this->tenantId(), $request->query());
        }

        return $this->successResponse($predictions);
    }

    /**
     * Generate fresh predictions.
     */
    public function generatePredictions(Request $request): JsonResponse
    {
        $request->validate([
            'model_type' => 'required|in:clv,churn_risk,purchase_propensity,revenue_forecast',
        ]);

        $service = app(PredictionService::class);
        $result = $service->generate($this->tenantId(), $request->input('model_type'));

        return $this->successResponse($result);
    }

    /**
     * Get competitive benchmarks.
     */
    public function benchmarks(): JsonResponse
    {
        $service = app(BenchmarkService::class);
        $benchmarks = $service->getSummary($this->tenantId());

        return $this->successResponse($benchmarks);
    }

    /**
     * Execute an ad-hoc query.
     */
    public function query(Request $request): JsonResponse
    {
        $request->validate([
            'data_source' => 'required|in:events,customers,sessions,campaigns,contacts,orders',
            'filters' => 'nullable|array',
            'group_by' => 'nullable|string',
            'aggregations' => 'nullable|array',
        ]);

        $params = $request->all();

        // Normalize group_by: the service expects an array of {field, granularity} objects.
        // Accept both a flat string ("rfm_segment") and a proper array.
        if (isset($params['group_by']) && is_string($params['group_by'])) {
            $params['group_by'] = [['field' => $params['group_by']]];
        } elseif (isset($params['group_by']) && is_array($params['group_by'])) {
            // Allow both [['field'=>'x']] and ['x'] shorthand arrays
            $params['group_by'] = array_map(
                fn ($g) => is_string($g) ? ['field' => $g] : $g,
                $params['group_by']
            );
        }

        $service = app(QueryBuilderService::class);
        $result = $service->execute($this->tenantId(), $params);

        return $this->successResponse($result);
    }

    /**
     * Get available fields for a data source.
     */
    public function availableFields(string $source): JsonResponse
    {
        $service = app(QueryBuilderService::class);
        $fields = $service->getAvailableFields($source);

        return $this->successResponse(['source' => $source, 'fields' => $fields]);
    }

    private function tenantId(): int
    {
        $user = Auth::user();
        if ($user === null || !isset($user->tenant_id)) {
            abort(403, 'Tenant context required.');
        }
        return (int) $user->tenant_id;
    }
}

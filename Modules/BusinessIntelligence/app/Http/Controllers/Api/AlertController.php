<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\BusinessIntelligence\Services\AlertService;

final class AlertController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $service = app(AlertService::class);
        $alerts = $service->list($this->tenantId(), $request->query());

        return $this->successResponse($alerts);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'kpi_id' => 'required|integer|exists:bi_kpis,id',
            'condition' => 'required|in:above,below,change_percent,anomaly',
            'threshold' => 'required|numeric',
            'channels' => 'required|array',
        ]);

        $service = app(AlertService::class);
        $alert = $service->create($this->tenantId(), $request->all());

        return $this->successResponse($alert, 201);
    }

    public function show(int $id): JsonResponse
    {
        $service = app(AlertService::class);
        $alert = $service->find($this->tenantId(), $id);

        if (!$alert) return $this->errorResponse('Alert not found', 404);

        return $this->successResponse($alert);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $service = app(AlertService::class);
        $alert = $service->update($this->tenantId(), $id, $request->all());

        return $this->successResponse($alert);
    }

    public function destroy(int $id): JsonResponse
    {
        $service = app(AlertService::class);
        $service->delete($this->tenantId(), $id);

        return $this->successResponse(['deleted' => true]);
    }

    public function history(int $id): JsonResponse
    {
        $service = app(AlertService::class);
        $history = $service->getHistory($this->tenantId(), $id);

        return $this->successResponse($history);
    }

    public function acknowledge(int $alertHistoryId): JsonResponse
    {
        $service = app(AlertService::class);
        $service->acknowledge($this->tenantId(), $alertHistoryId);

        return $this->successResponse(['acknowledged' => true]);
    }

    public function evaluate(): JsonResponse
    {
        $service = app(AlertService::class);
        $results = $service->evaluateAll($this->tenantId());

        return $this->successResponse(['evaluated' => count($results), 'triggered' => $results]);
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

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
            'name'      => 'required|string|max:255',
            'condition' => 'required|in:above,below,change_percent,anomaly',
            'threshold' => 'required|numeric',
        ]);

        // Map incoming fields to actual table schema (kpi_id → metric_key, channels → notify_channels)
        $data = [
            'name'            => $request->input('name'),
            'condition'       => $request->input('condition'),
            'threshold'       => $request->input('threshold'),
            'channels'        => $request->input('channels', $request->input('notify_channels', [])),
            'recipients'      => $request->input('recipients', []),
            'is_active'       => $request->input('is_active', true),
        ];

        if ($request->filled('kpi_id')) {
            $kpi = \Modules\BusinessIntelligence\Models\Kpi::where('tenant_id', $this->tenantId())
                ->find((int) $request->input('kpi_id'));
            $data['metric_key'] = $kpi?->metric ?? 'kpi_' . $request->input('kpi_id');
        } else {
            $data['metric_key'] = $request->input('metric_key', 'custom');
        }

        $service = app(AlertService::class);
        $alert = $service->create($this->tenantId(), $data);

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
        try {
            $service = app(AlertService::class);
            $alert = $service->update($this->tenantId(), $id, $request->all());
            return $this->successResponse($alert);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Alert not found', 404);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $service = app(AlertService::class);
            $service->delete($this->tenantId(), $id);
            return $this->successResponse(['deleted' => true]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Alert not found', 404);
        }
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

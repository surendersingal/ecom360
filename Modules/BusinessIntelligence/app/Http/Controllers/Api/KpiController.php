<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\BusinessIntelligence\Services\KpiService;

final class KpiController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $service = app(KpiService::class);
        $kpis = $service->list($this->tenantId());

        return $this->successResponse($kpis);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'metric' => 'required|string',
            'target' => 'nullable|numeric',
            'target_value' => 'nullable|numeric',
        ]);

        $data = $request->all();

        // Map 'target' → 'target_value' for convenience
        if (isset($data['target']) && !isset($data['target_value'])) {
            $data['target_value'] = $data['target'];
            unset($data['target']);
        }

        $service = app(KpiService::class);
        $kpi = $service->create($this->tenantId(), $data);

        return $this->successResponse($kpi, 201);
    }

    public function show(int $id): JsonResponse
    {
        $service = app(KpiService::class);
        $kpi = $service->find($this->tenantId(), $id);

        if (!$kpi) return $this->errorResponse('KPI not found', 404);

        return $this->successResponse($kpi);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $service = app(KpiService::class);
        $kpi = $service->update($this->tenantId(), $id, $request->all());

        return $this->successResponse($kpi);
    }

    public function destroy(int $id): JsonResponse
    {
        $service = app(KpiService::class);
        $service->delete($this->tenantId(), $id);

        return $this->successResponse(['deleted' => true]);
    }

    public function refresh(): JsonResponse
    {
        $service = app(KpiService::class);
        $service->refreshAll($this->tenantId());

        return $this->successResponse(['status' => 'refreshed']);
    }

    public function defaults(): JsonResponse
    {
        $service = app(KpiService::class);
        $service->createDefaults($this->tenantId());

        return $this->successResponse(['status' => 'defaults_created']);
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

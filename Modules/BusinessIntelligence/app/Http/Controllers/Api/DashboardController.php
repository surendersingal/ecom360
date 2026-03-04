<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\BusinessIntelligence\Models\Dashboard;

final class DashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $dashboards = Dashboard::where('tenant_id', $this->tenantId())
            ->when($request->query('is_public'), fn($q) => $q->where('is_public', true))
            ->orderByDesc('updated_at')
            ->paginate($request->query('per_page', 15));

        return $this->successResponse($dashboards);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'layout' => 'nullable|array',
            'widgets' => 'nullable|array',
        ]);

        $dashboard = Dashboard::create([
            'tenant_id' => $this->tenantId(),
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'layout' => $request->input('layout', []),
            'widgets' => $request->input('widgets', []),
            'filters' => $request->input('filters', []),
            'is_default' => $request->boolean('is_default', false),
            'is_public' => $request->boolean('is_public', false),
        ]);

        return $this->successResponse($dashboard, 201);
    }

    public function show(int $id): JsonResponse
    {
        $dashboard = Dashboard::where('tenant_id', $this->tenantId())->find($id);
        if (!$dashboard) return $this->errorResponse('Dashboard not found', 404);

        return $this->successResponse($dashboard);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $dashboard = Dashboard::where('tenant_id', $this->tenantId())->findOrFail($id);
        $dashboard->update($request->only(['name', 'description', 'layout', 'widgets', 'filters', 'is_default', 'is_public']));

        return $this->successResponse($dashboard->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        Dashboard::where('tenant_id', $this->tenantId())->findOrFail($id)->delete();

        return $this->successResponse(['deleted' => true]);
    }

    public function duplicate(int $id): JsonResponse
    {
        $original = Dashboard::where('tenant_id', $this->tenantId())->findOrFail($id);

        $copy = $original->replicate();
        $copy->name = $original->name . ' (Copy)';
        $copy->is_default = false;
        $copy->save();

        return $this->successResponse($copy, 201);
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

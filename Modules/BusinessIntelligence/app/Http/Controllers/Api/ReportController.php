<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\BusinessIntelligence\Services\ReportService;

final class ReportController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $service = app(ReportService::class);
        $reports = $service->list($this->tenantId(), $request->query());

        return $this->successResponse($reports);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'config' => 'required|array',
        ]);

        try {
            $service = app(ReportService::class);
            $report = $service->create($this->tenantId(), $request->all());
            return $this->successResponse($report, 201);
        } catch (\Throwable $e) {
            return $this->errorResponse('Report creation failed: ' . $e->getMessage(), 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        $service = app(ReportService::class);
        $report = $service->find($this->tenantId(), $id);

        if (!$report) return $this->errorResponse('Report not found', 404);

        return $this->successResponse($report);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $service = app(ReportService::class);
            $report = $service->update($this->tenantId(), $id, $request->all());
            return $this->successResponse($report);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Report not found', 404);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $service = app(ReportService::class);
            $service->delete($this->tenantId(), $id);
            return $this->successResponse(['deleted' => true]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Report not found', 404);
        }
    }

    public function execute(Request $request, int $id): JsonResponse
    {
        $service = app(ReportService::class);
        $result = $service->execute($this->tenantId(), $id, $request->input('filters', []));

        return $this->successResponse($result);
    }

    public function templates(): JsonResponse
    {
        $service = app(ReportService::class);
        return $this->successResponse($service->getTemplates());
    }

    public function createFromTemplate(Request $request): JsonResponse
    {
        $request->validate([
            'template' => 'required|string|in:revenue_overview,customer_acquisition,campaign_performance',
        ]);

        $service = app(ReportService::class);
        try {
            $report = $service->createFromTemplate($this->tenantId(), $request->input('template'), $request->input('name'));
            return $this->successResponse($report, 201);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
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

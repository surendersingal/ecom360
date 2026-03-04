<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\BusinessIntelligence\Services\ExportService;

final class ExportController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $service = app(ExportService::class);
        $exports = $service->list($this->tenantId(), $request->query());

        return $this->successResponse($exports);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'report_id' => 'required|integer|exists:bi_reports,id',
            'format' => 'required|in:csv,xlsx,json,pdf',
        ]);

        $service = app(ExportService::class);
        $export = $service->export($this->tenantId(), $request->input('report_id'), $request->input('format'), $request->input('filters', []));

        return $this->successResponse($export, 201);
    }

    public function show(int $id): JsonResponse
    {
        $service = app(ExportService::class);
        $export = $service->find($this->tenantId(), $id);

        if (!$export) return $this->errorResponse('Export not found', 404);

        return $this->successResponse($export);
    }

    public function download(int $id): mixed
    {
        $service = app(ExportService::class);
        $export = $service->find($this->tenantId(), $id);

        if (!$export || !$export->file_path) {
            return $this->errorResponse('Export file not found', 404);
        }

        return response()->download(storage_path('app/' . $export->file_path));
    }

    public function destroy(int $id): JsonResponse
    {
        $service = app(ExportService::class);
        $service->delete($this->tenantId(), $id);

        return $this->successResponse(['deleted' => true]);
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

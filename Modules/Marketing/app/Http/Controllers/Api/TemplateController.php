<?php

declare(strict_types=1);

namespace Modules\Marketing\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Marketing\Services\TemplateService;

final class TemplateController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $service = app(TemplateService::class);
        $templates = $service->list($this->tenantId(), $request->query());

        return $this->successResponse($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'channel' => 'required|in:email,whatsapp,rcs,push,sms',
            'subject' => 'nullable|string|max:255',
            'body_html' => 'nullable|string',
            'body_text' => 'nullable|string',
        ]);

        $service = app(TemplateService::class);
        $template = $service->create($this->tenantId(), $request->all());

        return $this->successResponse($template, 201);
    }

    public function show(int $id): JsonResponse
    {
        $service = app(TemplateService::class);
        $template = $service->find($this->tenantId(), $id);

        if (!$template) return $this->errorResponse('Template not found', 404);

        return $this->successResponse($template);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $service = app(TemplateService::class);
        $template = $service->update($this->tenantId(), $id, $request->all());

        return $this->successResponse($template);
    }

    public function destroy(int $id): JsonResponse
    {
        $service = app(TemplateService::class);
        $service->delete($this->tenantId(), $id);

        return $this->successResponse(['deleted' => true]);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $service = app(TemplateService::class);
        $preview = $service->preview($this->tenantId(), $id, $request->input('contact_id'));

        return $this->successResponse($preview);
    }

    public function duplicate(int $id): JsonResponse
    {
        $service = app(TemplateService::class);
        $template = $service->clone($this->tenantId(), $id);

        return $this->successResponse($template, 201);
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

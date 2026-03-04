<?php

declare(strict_types=1);

namespace Modules\Marketing\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Marketing\Services\FlowExecutionService;
use Modules\Marketing\Models\Flow;
use Modules\Marketing\Models\FlowNode;
use Modules\Marketing\Models\FlowEdge;

final class FlowController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $flows = Flow::where('tenant_id', $this->tenantId())
            ->withCount('nodes')
            ->when($request->query('status'), fn($q, $s) => $q->where('status', $s))
            ->orderByDesc('updated_at')
            ->paginate($request->query('per_page', 15));

        return $this->successResponse($flows);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'trigger_type' => 'required|in:event,segment_enter,segment_exit,date_field,manual,schedule,webhook',
            'trigger_config' => 'nullable|array',
        ]);

        $flow = Flow::create([
            'tenant_id' => $this->tenantId(),
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'trigger_type' => $request->input('trigger_type'),
            'trigger_config' => $request->input('trigger_config', []),
            'status' => 'draft',
            'canvas' => $request->input('canvas', []),
        ]);

        return $this->successResponse($flow, 201);
    }

    public function show(int $id): JsonResponse
    {
        $flow = Flow::where('tenant_id', $this->tenantId())
            ->with(['nodes', 'edges'])
            ->find($id);

        if (!$flow) return $this->errorResponse('Flow not found', 404);

        return $this->successResponse($flow);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $flow = Flow::where('tenant_id', $this->tenantId())->findOrFail($id);
        $flow->update($request->only(['name', 'description', 'trigger_type', 'trigger_config', 'status', 'canvas']));

        return $this->successResponse($flow->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $flow = Flow::where('tenant_id', $this->tenantId())->findOrFail($id);
        $flow->nodes()->delete();
        $flow->edges()->delete();
        $flow->delete();

        return $this->successResponse(['deleted' => true]);
    }

    /**
     * Save the entire flow canvas (nodes + edges) in one request.
     */
    public function saveCanvas(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'nodes' => 'required|array',
            'nodes.*.node_id' => 'required|string',
            'nodes.*.type' => 'required|string',
            'edges' => 'nullable|array',
            'edges.*.source_node_id' => 'required|string',
            'edges.*.target_node_id' => 'required|string',
        ]);

        $flow = Flow::where('tenant_id', $this->tenantId())->findOrFail($id);

        // Replace all nodes
        $flow->nodes()->delete();
        foreach ($request->input('nodes', []) as $node) {
            FlowNode::create([
                'flow_id' => $flow->id,
                'node_id' => $node['node_id'],
                'type' => $node['type'],
                'config' => $node['config'] ?? [],
                'position' => $node['position'] ?? [],
            ]);
        }

        // Replace all edges
        $flow->edges()->delete();
        foreach ($request->input('edges', []) as $edge) {
            FlowEdge::create([
                'flow_id' => $flow->id,
                'source_node_id' => $edge['source_node_id'],
                'target_node_id' => $edge['target_node_id'],
                'label' => $edge['label'] ?? null,
            ]);
        }

        $flow->update(['canvas' => $request->input('canvas', [])]);

        return $this->successResponse($flow->load(['nodes', 'edges']));
    }

    /**
     * Activate a flow.
     */
    public function activate(int $id): JsonResponse
    {
        $flow = Flow::where('tenant_id', $this->tenantId())->findOrFail($id);

        if ($flow->nodes()->count() === 0) {
            return $this->errorResponse('Cannot activate a flow with no nodes.', 422);
        }

        $flow->update(['status' => 'active', 'activated_at' => now()]);

        return $this->successResponse(['status' => 'active']);
    }

    /**
     * Pause a flow.
     */
    public function pause(int $id): JsonResponse
    {
        $flow = Flow::where('tenant_id', $this->tenantId())->findOrFail($id);
        $flow->update(['status' => 'paused']);

        return $this->successResponse(['status' => 'paused']);
    }

    /**
     * Manually enroll a contact into a flow.
     */
    public function enroll(Request $request, int $id): JsonResponse
    {
        $request->validate(['contact_id' => 'required|integer']);

        $service = app(FlowExecutionService::class);
        $enrollment = $service->enroll($id, $request->input('contact_id'));

        return $this->successResponse($enrollment, 201);
    }

    /**
     * Get flow performance stats.
     */
    public function stats(int $id): JsonResponse
    {
        $flow = Flow::where('tenant_id', $this->tenantId())
            ->withCount(['enrollments as total_enrolled', 'enrollments as active_enrolled' => fn($q) => $q->where('status', 'active')])
            ->findOrFail($id);

        return $this->successResponse([
            'flow_id' => $id,
            'status' => $flow->status,
            'total_enrolled' => $flow->total_enrolled ?? 0,
            'active_enrolled' => $flow->active_enrolled ?? 0,
            'conversion_rate' => $flow->conversion_rate,
        ]);
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

<?php

namespace Modules\Chatbot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Chatbot\Services\ChatService;

class ChatbotController extends Controller
{
    use ApiResponse;

    public function __construct(private ChatService $chatService) {}

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
     * POST /api/v1/chatbot/send — Send a message and get AI response.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'message'         => 'required|string|max:2000',
            'conversation_id' => 'nullable|string',
            'session_id'      => 'nullable|string',
            'email'           => 'nullable|email',
            'context'         => 'nullable|array',
            'page_url'        => 'nullable|string',
        ]);

        $tenantId = $this->tenantId($request);
        $result = $this->chatService->sendMessage($tenantId, $request->all());

        return $result['success']
            ? $this->success($result)
            : $this->error($result['error'] ?? 'Failed to process message', 422);
    }

    /**
     * POST /api/v1/chatbot/rage-click — Handle rage-click intervention.
     */
    public function rageClick(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
            'element'    => 'nullable|string',
            'page_url'   => 'nullable|string|url',
        ]);

        $tenantId = $this->tenantId($request);
        $result = $this->chatService->handleRageClick($tenantId, $request->all());

        return $this->success($result);
    }

    /**
     * GET /api/v1/chatbot/history/{conversationId}
     */
    public function history(string $conversationId): JsonResponse
    {
        $result = $this->chatService->getHistory($conversationId);

        return $result['success']
            ? $this->success($result)
            : $this->error($result['error'] ?? 'Not found', 404);
    }

    /**
     * GET /api/v1/chatbot/conversations — List conversations.
     */
    public function conversations(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $result = $this->chatService->listConversations($tenantId, $request->all());

        return $this->success($result);
    }

    /**
     * POST /api/v1/chatbot/resolve/{conversationId}
     */
    public function resolve(Request $request, string $conversationId): JsonResponse
    {
        $request->validate([
            'satisfaction_score' => 'nullable|integer|min:1|max:5',
        ]);

        $result = $this->chatService->resolveConversation(
            $conversationId,
            $request->input('satisfaction_score')
        );

        return $result['success']
            ? $this->success($result)
            : $this->error($result['error'] ?? 'Failed', 422);
    }

    /**
     * GET /api/v1/chatbot/widget-config
     */
    public function widgetConfig(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        return $this->success($this->chatService->getWidgetConfig($tenantId));
    }

    /**
     * GET /api/v1/chatbot/analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $days = (int) $request->input('days', 30);
        return $this->success($this->chatService->getAnalytics($tenantId, $days));
    }

}

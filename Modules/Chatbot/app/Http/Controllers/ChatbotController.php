<?php

namespace Modules\Chatbot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Chatbot\Services\ChatService;
use Modules\Chatbot\Services\AdvancedChatService;
use Modules\Chatbot\Services\ProactiveSupportService;
use Modules\Chatbot\Services\CommunicationService;

class ChatbotController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private AdvancedChatService $advancedChatService,
        private ProactiveSupportService $proactiveSupportService,
        private CommunicationService $communicationService,
    ) {}

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

    // ── Advanced Chat Services (UC36-40) ─────────────────────────────

    /**
     * POST /api/v1/chatbot/advanced/order-tracking
     */
    public function advancedOrderTracking(Request $request): JsonResponse
    {
        $request->validate(['order_id' => 'required|string', 'customer_email' => 'nullable|string']);
        $tenantId = $this->tenantId($request);
        $result = $this->advancedChatService->visualOrderTracking(
            (int) $tenantId,
            $request->input('order_id'),
            $request->input('customer_email', '')
        );
        return $this->success($result);
    }

    /**
     * POST /api/v1/chatbot/advanced/objection-handler
     */
    public function objectionHandler(Request $request): JsonResponse
    {
        $request->validate(['objection_type' => 'required|string', 'product_id' => 'nullable|string']);
        $tenantId = $this->tenantId($request);
        $result = $this->advancedChatService->preCheckoutObjectionHandler(
            (int) $tenantId,
            $request->all(),
            $request->input('objection_type', '')
        );
        return $this->success($result);
    }

    /**
     * POST /api/v1/chatbot/advanced/subscription
     */
    public function subscriptionManagement(Request $request): JsonResponse
    {
        $request->validate(['action' => 'required|string']);
        $tenantId = $this->tenantId($request);
        $result = $this->advancedChatService->subscriptionManagement((int) $tenantId, $request->all());
        return $this->success($result);
    }

    /**
     * POST /api/v1/chatbot/advanced/gift-card
     */
    public function giftCardBuilder(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $result = $this->advancedChatService->giftCardBuilder((int) $tenantId, $request->all());
        return $this->success($result);
    }

    /**
     * POST /api/v1/chatbot/advanced/video-review
     */
    public function videoReviewGuide(Request $request): JsonResponse
    {
        $request->validate(['product_id' => 'required|string']);
        $tenantId = $this->tenantId($request);
        $result = $this->advancedChatService->videoReviewGuide((int) $tenantId, $request->all());
        return $this->success($result);
    }

    // ── Proactive Support Services (UC31-35) ─────────────────────────

    /**
     * POST /api/v1/chatbot/proactive/order-modification
     */
    public function orderModification(Request $request): JsonResponse
    {
        $request->validate(['order_id' => 'required|string', 'action' => 'required|string']);
        $tenantId = $this->tenantId($request);
        $result = $this->proactiveSupportService->orderModification((int) $tenantId, $request->all());
        return $this->success($result);
    }

    /**
     * POST /api/v1/chatbot/proactive/sentiment-escalation
     */
    public function sentimentEscalation(Request $request): JsonResponse
    {
        $request->validate(['conversation_id' => 'required|string', 'message' => 'required|string']);
        $tenantId = $this->tenantId($request);
        $result = $this->proactiveSupportService->sentimentEscalation((int) $tenantId, $request->all());
        return $this->success($result);
    }

    /**
     * POST /api/v1/chatbot/proactive/vip-greeting
     */
    public function vipGreeting(Request $request): JsonResponse
    {
        $request->validate(['customer_email' => 'required|email']);
        $tenantId = $this->tenantId($request);
        $result = $this->proactiveSupportService->vipGreeting((int) $tenantId, $request->input('customer_email'));
        return $this->success($result);
    }

    /**
     * POST /api/v1/chatbot/proactive/warranty-claim
     */
    public function warrantyClaim(Request $request): JsonResponse
    {
        $request->validate(['step' => 'required|string']);
        $tenantId = $this->tenantId($request);
        $result = $this->proactiveSupportService->warrantyClaim((int) $tenantId, $request->all());
        return $this->success($result);
    }

    /**
     * POST /api/v1/chatbot/proactive/sizing-assistant
     */
    public function sizingAssistant(Request $request): JsonResponse
    {
        $request->validate(['cart_items' => 'required|array']);
        $tenantId = $this->tenantId($request);
        $result = $this->proactiveSupportService->multiItemSizingAssistant((int) $tenantId, $request->all());
        return $this->success($result);
    }

    // ── Form & Communication Endpoints ────────────────────────────

    /**
     * POST /api/v1/chatbot/form-submit — Submit a chatbot form (escalation, complaint, etc.).
     */
    public function formSubmit(Request $request): JsonResponse
    {
        $request->validate([
            'form_id'         => 'required|string|max:100',
            'form_data'       => 'required|array',
            'submit_action'   => 'nullable|string|max:100',
            'conversation_id' => 'nullable|string',
            'communications'  => 'nullable|array',
        ]);

        $tenantId = $this->tenantId($request);
        $result = $this->communicationService->processFormSubmission((int) $tenantId, $request->all());

        return $result['success']
            ? $this->success($result)
            : $this->error($result['error'] ?? 'Form submission failed', 422);
    }

    /**
     * POST /api/v1/chatbot/communicate — Send a communication (email, WhatsApp, push, SMS).
     */
    public function communicate(Request $request): JsonResponse
    {
        $request->validate([
            'type'    => 'required|string|max:50',
            'channel' => 'required|string|in:email,whatsapp,push,sms',
            'to'      => 'required|string|max:255',
            'data'    => 'nullable|array',
        ]);

        $tenantId = $this->tenantId($request);
        $result = $this->communicationService->sendNotification((int) $tenantId, $request->all());

        return $result['success']
            ? $this->success($result)
            : $this->error($result['error'] ?? 'Communication failed', 422);
    }

    /**
     * POST /api/v1/chatbot/communicate-multi — Send to multiple channels at once.
     */
    public function communicateMulti(Request $request): JsonResponse
    {
        $request->validate([
            'channels' => 'required|array|min:1',
            'channels.*' => 'string|in:email,whatsapp,push,sms',
            'type'     => 'required|string|max:50',
            'data'     => 'required|array',
        ]);

        $tenantId = $this->tenantId($request);
        $result = $this->communicationService->sendMultiChannel(
            (int) $tenantId,
            $request->input('channels'),
            $request->input('type'),
            $request->input('data')
        );

        return $this->success($result);
    }

    /**
     * GET /api/v1/chatbot/communications — Get communication history.
     */
    public function communicationHistory(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $result = $this->communicationService->getCommunicationHistory((int) $tenantId, $request->all());

        return $this->success($result);
    }

}

<?php
declare(strict_types=1);

namespace Modules\Chatbot\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Chatbot\Models\Conversation;
use Modules\Chatbot\Models\Message;

/**
 * ChatService — Core conversational AI engine.
 *
 * Powers Use Cases:
 *   - Rage-Click Intervention via Chatbot (UC2)
 *   - AI Chatbot Conversational Checkout (UC20)
 *   - Post-purchase support / FAQ
 */
class ChatService
{
    private IntentService $intentService;
    private OrderTrackingService $orderTrackingService;

    public function __construct(IntentService $intentService, OrderTrackingService $orderTrackingService)
    {
        $this->intentService = $intentService;
        $this->orderTrackingService = $orderTrackingService;
    }

    /**
     * Send a message and get AI response.
     */
    public function sendMessage(int $tenantId, array $params): array
    {
        try {
            $conversationId = $params['conversation_id'] ?? null;
            $sessionId = $params['session_id'] ?? Str::uuid()->toString();
            $message = $params['message'] ?? '';
            $context = $params['context'] ?? [];

            // Get or create conversation
            $conversation = $conversationId
                ? Conversation::find($conversationId)
                : $this->createConversation($tenantId, $sessionId, $params);

            if (!$conversation) {
                return ['success' => false, 'error' => 'Conversation not found.'];
            }

            // Save user message
            $userMessage = Message::create([
                'conversation_id' => (string) $conversation->_id,
                'tenant_id'       => $tenantId,
                'role'            => 'user',
                'content'         => $message,
                'content_type'    => 'text',
                'metadata'        => ['context' => $context],
            ]);

            // Detect intent
            $intent = $this->intentService->detect($message, $context);

            // Update conversation intent
            $conversation->update(['intent' => $intent['intent']]);

            // Generate response based on intent
            $response = $this->generateResponse($tenantId, $conversation, $intent, $message, $context);

            // Save assistant message
            $assistantMessage = Message::create([
                'conversation_id' => (string) $conversation->_id,
                'tenant_id'       => $tenantId,
                'role'            => 'assistant',
                'content'         => $response['message'],
                'content_type'    => $response['content_type'] ?? 'text',
                'intent'          => $intent['intent'],
                'confidence'      => $intent['confidence'],
                'quick_replies'   => $response['quick_replies'] ?? [],
                'action'          => $response['action'] ?? null,
                'action_payload'  => $response['action_payload'] ?? null,
            ]);

            return [
                'success'         => true,
                'conversation_id' => (string) $conversation->_id,
                'message'         => $response['message'],
                'content_type'    => $response['content_type'] ?? 'text',
                'quick_replies'   => $response['quick_replies'] ?? [],
                'action'          => $response['action'] ?? null,
                'action_payload'  => $response['action_payload'] ?? null,
                'intent'          => $intent['intent'],
                'confidence'      => $intent['confidence'],
            ];
        } catch (\Exception $e) {
            Log::error("ChatService::sendMessage error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handle rage-click intervention — triggered when a user rage-clicks.
     */
    public function handleRageClick(int $tenantId, array $params): array
    {
        $sessionId = $params['session_id'] ?? Str::uuid()->toString();
        $element = $params['element'] ?? 'unknown';
        $pageUrl = $params['page_url'] ?? '';

        $conversation = $this->createConversation($tenantId, $sessionId, array_merge($params, [
            'trigger' => 'rage_click',
        ]));

        // System message about the trigger
        Message::create([
            'conversation_id' => (string) $conversation->_id,
            'tenant_id'       => $tenantId,
            'role'            => 'system',
            'content'         => "Rage-click detected on element: {$element} at URL: {$pageUrl}",
            'content_type'    => 'text',
        ]);

        $helpMessage = $this->contextualHelp($element, $pageUrl);

        // Proactive assistant message
        $assistantMsg = Message::create([
            'conversation_id' => (string) $conversation->_id,
            'tenant_id'       => $tenantId,
            'role'            => 'assistant',
            'content'         => $helpMessage['message'],
            'content_type'    => 'text',
            'intent'          => 'rage_click_help',
            'confidence'      => 1.0,
            'quick_replies'   => $helpMessage['quick_replies'],
        ]);

        return [
            'success'         => true,
            'conversation_id' => (string) $conversation->_id,
            'message'         => $helpMessage['message'],
            'quick_replies'   => $helpMessage['quick_replies'],
            'trigger'         => 'rage_click',
        ];
    }

    /**
     * Get conversation history.
     */
    public function getHistory(string $conversationId): array
    {
        try {
            $conversation = Conversation::find($conversationId);
            if (!$conversation) {
                return ['success' => false, 'error' => 'Conversation not found.'];
            }

            $messages = Message::where('conversation_id', $conversationId)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($m) => [
                    'id'            => (string) $m->_id,
                    'role'          => $m->role,
                    'content'       => $m->content,
                    'content_type'  => $m->content_type,
                    'quick_replies' => $m->quick_replies ?? [],
                    'action'        => $m->action,
                    'created_at'    => $m->created_at?->toIso8601String(),
                ]);

            return [
                'success'         => true,
                'conversation_id' => $conversationId,
                'messages'        => $messages->toArray(),
                'status'          => $conversation->status,
                'intent'          => $conversation->intent,
            ];
        } catch (\Exception $e) {
            Log::error("ChatService::getHistory error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List conversations for a tenant.
     */
    public function listConversations(int $tenantId, array $filters = []): array
    {
        try {
            $query = Conversation::where('tenant_id', $tenantId);

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (isset($filters['email'])) {
                $query->where('customer_email', $filters['email']);
            }
            if (isset($filters['intent'])) {
                $query->where('intent', $filters['intent']);
            }

            $conversations = $query->orderBy('created_at', 'desc')
                ->limit((int) ($filters['limit'] ?? 50))
                ->get()
                ->map(fn($c) => [
                    'id'             => (string) $c->_id,
                    'email'          => $c->customer_email,
                    'status'         => $c->status,
                    'intent'         => $c->intent,
                    'channel'        => $c->channel,
                    'message_count'  => Message::where('conversation_id', (string) $c->_id)->count(),
                    'started_at'     => $c->started_at?->toIso8601String(),
                    'resolved_at'    => $c->resolved_at?->toIso8601String(),
                ]);

            return [
                'success'       => true,
                'conversations' => $conversations->toArray(),
                'count'         => $conversations->count(),
            ];
        } catch (\Exception $e) {
            Log::error("ChatService::listConversations error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Resolve a conversation.
     */
    public function resolveConversation(string $conversationId, ?int $satisfactionScore = null): array
    {
        try {
            $conversation = Conversation::find($conversationId);
            if (!$conversation) {
                return ['success' => false, 'error' => 'Conversation not found.'];
            }

            $conversation->update([
                'status'             => 'resolved',
                'resolved_at'       => now(),
                'satisfaction_score' => $satisfactionScore,
            ]);

            return ['success' => true, 'status' => 'resolved'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get widget configuration for embedding.
     */
    public function getWidgetConfig(int $tenantId): array
    {
        $settings = DB::table('tenant_settings')
            ->where('tenant_id', $tenantId)
            ->whereIn('key', [
                'chatbot_enabled', 'chatbot_greeting', 'chatbot_name',
                'chatbot_avatar', 'chatbot_color', 'chatbot_position',
                'chatbot_language', 'chatbot_auto_open_seconds',
            ])
            ->pluck('value', 'key')
            ->toArray();

        return [
            'enabled'       => (bool) ($settings['chatbot_enabled'] ?? true),
            'name'          => $settings['chatbot_name'] ?? 'Shopping Assistant',
            'greeting'      => $settings['chatbot_greeting'] ?? 'Hi! How can I help you today?',
            'avatar'        => $settings['chatbot_avatar'] ?? null,
            'color'         => $settings['chatbot_color'] ?? '#4F46E5',
            'position'      => $settings['chatbot_position'] ?? 'bottom-right',
            'language'      => $settings['chatbot_language'] ?? 'en',
            'auto_open'     => (int) ($settings['chatbot_auto_open_seconds'] ?? 0),
        ];
    }

    /**
     * Get analytics for chatbot usage.
     */
    public function getAnalytics(int $tenantId, int $days = 30): array
    {
        try {
            $since = now()->subDays($days)->toDateTimeString();
            $conversations = Conversation::where('tenant_id', $tenantId)
                ->where('created_at', '>=', $since)
                ->get();

            $total = $conversations->count();
            $resolved = $conversations->where('status', 'resolved')->count();
            $escalated = $conversations->where('status', 'escalated')->count();

            $avgSatisfaction = $conversations
                ->whereNotNull('satisfaction_score')
                ->avg('satisfaction_score');

            $intentBreakdown = $conversations->groupBy('intent')->map->count();

            return [
                'period_days'          => $days,
                'total_conversations'  => $total,
                'resolved'             => $resolved,
                'resolution_rate'      => $total > 0 ? round(($resolved / $total) * 100, 2) : 0,
                'escalated'            => $escalated,
                'escalation_rate'      => $total > 0 ? round(($escalated / $total) * 100, 2) : 0,
                'avg_satisfaction'     => $avgSatisfaction ? round($avgSatisfaction, 1) : null,
                'intent_breakdown'     => $intentBreakdown->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error("ChatService::getAnalytics error: {$e->getMessage()}");
            return ['error' => $e->getMessage()];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function createConversation(int $tenantId, string $sessionId, array $params): Conversation
    {
        return Conversation::create([
            'tenant_id'      => $tenantId,
            'session_id'     => $sessionId,
            'visitor_id'     => $params['visitor_id'] ?? null,
            'customer_email' => $params['email'] ?? null,
            'customer_name'  => $params['name'] ?? null,
            'channel'        => $params['channel'] ?? 'widget',
            'status'         => 'active',
            'intent'         => null,
            'language'       => $params['language'] ?? 'en',
            'started_at'     => now(),
            'metadata'       => [
                'trigger'  => $params['trigger'] ?? 'user_initiated',
                'page_url' => $params['page_url'] ?? null,
                'user_agent' => $params['user_agent'] ?? null,
            ],
        ]);
    }

    private function generateResponse(int $tenantId, Conversation $conversation, array $intent, string $message, array $context): array
    {
        return match ($intent['intent']) {
            'order_tracking'    => $this->handleOrderTracking($tenantId, $message, $context),
            'product_inquiry'   => $this->handleProductInquiry($tenantId, $message, $context),
            'checkout_help'     => $this->handleCheckoutHelp($tenantId, $message, $context),
            'return_request'    => $this->handleReturnRequest($tenantId, $message, $context),
            'coupon_inquiry'    => $this->handleCouponInquiry($tenantId, $message, $context),
            'size_help'         => $this->handleSizeHelp($tenantId, $message, $context),
            'shipping_inquiry'  => $this->handleShippingInquiry($tenantId, $message, $context),
            'add_to_cart'       => $this->handleAddToCart($tenantId, $message, $context),
            'greeting'          => $this->handleGreeting($tenantId),
            'farewell'          => $this->handleFarewell($conversation),
            default             => $this->handleGeneral($tenantId, $message, $context),
        };
    }

    private function handleOrderTracking(int $tenantId, string $message, array $context): array
    {
        // Extract order ID from message
        preg_match('/(?:order|#)\s*([A-Z0-9\-]+)/i', $message, $matches);
        $orderId = $matches[1] ?? ($context['order_id'] ?? null);

        if (!$orderId) {
            return [
                'message'       => "I'd be happy to help track your order! Could you please provide your order number? It usually starts with # or looks like ORD-XXXX.",
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Check my recent orders', 'value' => 'show_recent_orders'],
                ],
            ];
        }

        $tracking = $this->orderTrackingService->getOrderStatus($tenantId, $orderId);

        if (!$tracking['found']) {
            return [
                'message' => "I couldn't find order #{$orderId}. Please double-check the order number or provide the email address used for the order.",
                'content_type' => 'text',
            ];
        }

        return [
            'message'      => "📦 **Order #{$orderId}**\n\nStatus: **{$tracking['status']}**\n{$tracking['status_detail']}\n\nEstimated delivery: {$tracking['estimated_delivery']}",
            'content_type' => 'text',
            'quick_replies' => [
                ['label' => 'Track shipment', 'value' => 'track_shipment_' . $orderId],
                ['label' => 'Return this order', 'value' => 'return_order_' . $orderId],
                ['label' => 'Contact support', 'value' => 'escalate'],
            ],
        ];
    }

    private function handleProductInquiry(int $tenantId, string $message, array $context): array
    {
        // Search products from synced data
        $products = DB::connection('mongodb')
            ->table('synced_products')
            ->where('tenant_id', $tenantId)
            ->where('name', 'regex', '/' . preg_quote($message, '/') . '/i')
            ->limit(5)
            ->get();

        if ($products->isEmpty()) {
            return [
                'message'       => "I couldn't find products matching your query. Could you try different keywords or let me help you browse categories?",
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Browse categories', 'value' => 'browse_categories'],
                    ['label' => 'Best sellers', 'value' => 'best_sellers'],
                    ['label' => 'New arrivals', 'value' => 'new_arrivals'],
                ],
            ];
        }

        $productList = $products->map(fn($p) => "• **{$p['name']}** — \${$p['price']}")->implode("\n");

        return [
            'message'       => "Here are some products I found:\n\n{$productList}\n\nWould you like more details about any of these?",
            'content_type'  => 'text',
            'quick_replies' => $products->take(3)->map(fn($p) => [
                'label' => "View {$p['name']}",
                'value' => 'view_product_' . ($p['external_id'] ?? $p['_id']),
            ])->values()->toArray(),
        ];
    }

    private function handleCheckoutHelp(int $tenantId, string $message, array $context): array
    {
        return [
            'message'       => "I can help you complete your purchase! What issue are you having?",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Payment not working', 'value' => 'payment_issue'],
                ['label' => 'Apply a coupon', 'value' => 'apply_coupon'],
                ['label' => 'Shipping options', 'value' => 'shipping_options'],
                ['label' => 'Change delivery address', 'value' => 'change_address'],
            ],
        ];
    }

    private function handleReturnRequest(int $tenantId, string $message, array $context): array
    {
        return [
            'message'       => "I'm sorry you need to make a return. I can help with that!\n\nOur return policy allows returns within 30 days of delivery for most items. Would you like to start a return?",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Start a return', 'value' => 'start_return'],
                ['label' => 'Exchange instead', 'value' => 'exchange_item'],
                ['label' => 'Return policy details', 'value' => 'return_policy'],
            ],
        ];
    }

    private function handleCouponInquiry(int $tenantId, string $message, array $context): array
    {
        // Check for active coupons in the context
        $email = $context['email'] ?? null;
        $coupons = [];

        if ($email) {
            $coupons = DB::connection('mongodb')
                ->table('coupons')
                ->where('tenant_id', $tenantId)
                ->where('email', $email)
                ->where('used', false)
                ->where('expires_at', '>=', now()->toDateTimeString())
                ->get();
        }

        if ($coupons instanceof \Illuminate\Support\Collection && $coupons->isNotEmpty()) {
            $couponList = $coupons->map(fn($c) => "• **{$c['code']}** — {$c['value']}% off (expires {$c['expires_at']})")->implode("\n");
            return [
                'message'      => "Great news! You have active coupons:\n\n{$couponList}\n\nWould you like me to apply one to your cart?",
                'content_type' => 'text',
                'action'       => 'apply_coupon',
                'action_payload' => ['coupons' => $coupons->pluck('code')->toArray()],
                'quick_replies' => $coupons->take(3)->map(fn($c) => [
                    'label' => "Apply {$c['code']}",
                    'value' => 'apply_coupon_' . $c['code'],
                ])->values()->toArray(),
            ];
        }

        return [
            'message'       => "I don't see any active coupons for your account right now. But keep checking — we send exclusive offers via email!",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Subscribe for offers', 'value' => 'subscribe_offers'],
                ['label' => 'Check sale items', 'value' => 'browse_sale'],
            ],
        ];
    }

    private function handleSizeHelp(int $tenantId, string $message, array $context): array
    {
        return [
            'message'       => "I'd be happy to help with sizing! You can share your measurements and I'll recommend the right size, or check our size guide.",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Size guide', 'value' => 'size_guide'],
                ['label' => 'Help me choose', 'value' => 'size_help_interactive'],
            ],
        ];
    }

    private function handleShippingInquiry(int $tenantId, string $message, array $context): array
    {
        return [
            'message'       => "Here are our shipping options:\n\n• **Standard** — 5-7 business days (Free over \$50)\n• **Express** — 2-3 business days (\$9.99)\n• **Next Day** — 1 business day (\$19.99)\n\nAll orders include tracking!",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Track my order', 'value' => 'track_order'],
                ['label' => 'Free shipping threshold', 'value' => 'free_shipping_info'],
            ],
        ];
    }

    private function handleAddToCart(int $tenantId, string $message, array $context): array
    {
        $productId = $context['product_id'] ?? null;
        
        if ($productId) {
            return [
                'message'        => "I've added that to your cart! 🛒",
                'content_type'   => 'text',
                'action'         => 'add_to_cart',
                'action_payload' => ['product_id' => $productId, 'qty' => 1],
                'quick_replies'  => [
                    ['label' => 'View cart', 'value' => 'view_cart'],
                    ['label' => 'Continue shopping', 'value' => 'continue_shopping'],
                    ['label' => 'Checkout', 'value' => 'go_to_checkout'],
                ],
            ];
        }

        return [
            'message'       => "Sure! Which product would you like to add? You can share a product name or link.",
            'content_type'  => 'text',
        ];
    }

    private function handleGreeting(int $tenantId): array
    {
        return [
            'message'       => "Hello! 👋 Welcome! I'm your shopping assistant. How can I help you today?",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Track my order', 'value' => 'track_order'],
                ['label' => 'Find a product', 'value' => 'find_product'],
                ['label' => 'I need help', 'value' => 'need_help'],
                ['label' => 'Browse deals', 'value' => 'browse_deals'],
            ],
        ];
    }

    private function handleFarewell(Conversation $conversation): array
    {
        $conversation->update(['status' => 'resolved', 'resolved_at' => now()]);

        return [
            'message'       => "Thank you for chatting! Have a wonderful day! 😊",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Rate this chat', 'value' => 'rate_chat'],
            ],
        ];
    }

    private function handleGeneral(int $tenantId, string $message, array $context): array
    {
        return [
            'message'       => "I understand you need help with that. Let me see what I can do!\n\nHere are some things I can help with:",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Track order', 'value' => 'track_order'],
                ['label' => 'Product questions', 'value' => 'product_help'],
                ['label' => 'Returns & exchanges', 'value' => 'return_help'],
                ['label' => 'Talk to a human', 'value' => 'escalate'],
            ],
        ];
    }

    private function contextualHelp(string $element, string $pageUrl): array
    {
        $helpMsg = "I noticed you might be having trouble. Let me help!";
        $replies = [];

        // Context-aware help based on the element clicked
        if (str_contains($element, 'cart') || str_contains($element, 'add-to-cart') || str_contains($element, 'addtocart')) {
            $helpMsg = "Having trouble adding to cart? Let me help! Sometimes items may be out of stock or require a size/color selection first.";
            $replies = [
                ['label' => 'Check availability', 'value' => 'check_stock'],
                ['label' => 'Help me choose', 'value' => 'product_help'],
            ];
        } elseif (str_contains($element, 'checkout') || str_contains($element, 'payment')) {
            $helpMsg = "Running into issues at checkout? I can help resolve payment or form issues.";
            $replies = [
                ['label' => 'Payment help', 'value' => 'payment_help'],
                ['label' => 'Apply coupon', 'value' => 'coupon_help'],
            ];
        } elseif (str_contains($element, 'search') || str_contains($element, 'filter')) {
            $helpMsg = "Can't find what you're looking for? Let me help you search!";
            $replies = [
                ['label' => 'Help me find', 'value' => 'product_search'],
                ['label' => 'Browse categories', 'value' => 'browse_categories'],
            ];
        } elseif (str_contains($element, 'size') || str_contains($element, 'variant')) {
            $helpMsg = "Need help choosing the right size or option? I can guide you!";
            $replies = [
                ['label' => 'Size guide', 'value' => 'size_guide'],
                ['label' => 'Help me choose', 'value' => 'size_help'],
            ];
        } else {
            $replies = [
                ['label' => 'I need help', 'value' => 'general_help'],
                ['label' => "I'm just browsing", 'value' => 'dismiss'],
            ];
        }

        return [
            'message'       => $helpMsg,
            'quick_replies' => $replies,
        ];
    }
}

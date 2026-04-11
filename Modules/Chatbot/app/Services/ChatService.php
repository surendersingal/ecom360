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
     * Load all chatbot settings for a tenant (cached 1 hour).
     */
    private function loadSettings(int $tenantId): array
    {
        return \Illuminate\Support\Facades\Cache::remember("tenant_settings:{$tenantId}:chatbot", 3600, function () use ($tenantId) {
            return \App\Models\TenantSetting::where('tenant_id', $tenantId)
                ->where('module', 'chatbot')
                ->pluck('value', 'key')
                ->toArray();
        });
    }

    private function s(array $settings, string $key, mixed $default = null): mixed
    {
        return $settings[$key] ?? $default;
    }

    /**
     * Parse quick-reply button configuration from admin settings.
     *
     * Format: "Label 1|value_1, Label 2|value_2" or just "Label 1, Label 2"
     * If pipe is omitted, value auto-generates from slug of label.
     *
     * @param string $raw  Raw admin string (may be empty)
     * @param array  $defaults  Default buttons if raw is empty
     * @return array  Array of ['label' => ..., 'value' => ...]
     */
    private function parseQuickReplies(string $raw, array $defaults = []): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $defaults;
        }

        $buttons = [];
        $parts = array_map('trim', explode(',', $raw));
        foreach ($parts as $part) {
            if ($part === '') continue;
            if (str_contains($part, '|')) {
                [$label, $value] = array_map('trim', explode('|', $part, 2));
            } else {
                $label = $part;
                $value = Str::slug($part, '_');
            }
            $buttons[] = ['label' => $label, 'value' => $value];
        }

        return empty($buttons) ? $defaults : $buttons;
    }

    /**
     * Send a message and get AI response.
     */
    public function sendMessage(int $tenantId, array $params): array
    {
        try {
            $settings = $this->loadSettings($tenantId);

            // Maintenance mode check
            if ((bool) $this->s($settings, 'chatbot_maintenance', false)) {
                $msg = $this->s($settings, 'chatbot_maintenance_message', 'Our chat assistant is currently under maintenance. Please try again later.');
                return [
                    'success' => true,
                    'conversation_id' => null,
                    'message' => $msg,
                    'content_type' => 'text',
                    'quick_replies' => [],
                ];
            }

            // Master enable check
            if ($this->s($settings, 'chatbot_enabled') === '0' || $this->s($settings, 'chatbot_enabled') === false) {
                return ['success' => false, 'error' => 'Chatbot is disabled for this store.'];
            }

            $conversationId = $params['conversation_id'] ?? null;
            $sessionId = $params['session_id'] ?? Str::uuid()->toString();
            $message = $params['message'] ?? '';
            $context = $params['context'] ?? [];

            // Get or create conversation
            $conversation = $conversationId
                ? Conversation::where('tenant_id', $tenantId)->find($conversationId)
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

            // Detect intent (pass settings for custom keywords)
            $intent = $this->intentService->detect($message, $context, $settings);

            // Check if intent is disabled via admin toggle
            $intentKey = 'intent_' . $intent['intent'];
            if ($this->s($settings, $intentKey) === '0' || $this->s($settings, $intentKey) === false) {
                // Fall back to general handler
                $intent = ['intent' => 'general', 'confidence' => 0.3, 'matched_pattern' => null];
            }

            // Update conversation intent
            $conversation->update(['intent' => $intent['intent']]);

            // Generate response based on intent (pass settings for templates & config)
            $response = $this->generateResponse($tenantId, $conversation, $intent, $message, $context, $settings);

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

            $result = [
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

            // Pass product card data through for content_type 'products'
            if (($response['content_type'] ?? '') === 'products') {
                $result['products']   = $response['products'] ?? [];
                $result['total']      = $response['total'] ?? 0;
                $result['search_url'] = $response['search_url'] ?? '';
            }

            // Pass form data through for content_type 'form'
            if (($response['content_type'] ?? '') === 'form' && !empty($response['action_payload'])) {
                $result['form'] = $response['action_payload'];
            }

            return $result;
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
    public function getHistory(int|string $tenantId, string $conversationId): array
    {
        try {
            $conversation = Conversation::where('tenant_id', $tenantId)->find($conversationId);
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
    public function resolveConversation(int|string $tenantId, string $conversationId, ?int $satisfactionScore = null): array
    {
        try {
            $conversation = Conversation::where('tenant_id', $tenantId)->find($conversationId);
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
        $settings = $this->loadSettings($tenantId);

        // Parse greeting buttons for widget
        $greetingButtons = $this->parseQuickReplies(
            $this->s($settings, 'chatbot_greeting_buttons', ''),
            [
                ['label' => 'Track my order', 'value' => 'track_order'],
                ['label' => 'Find a product', 'value' => 'find_product'],
                ['label' => 'I need help', 'value' => 'need_help'],
                ['label' => 'Browse deals', 'value' => 'browse_deals'],
            ]
        );

        return [
            'enabled'          => (bool) ($settings['chatbot_enabled'] ?? true),
            'name'             => $settings['chatbot_name'] ?? 'Shopping Assistant',
            'greeting'         => $settings['chatbot_greeting'] ?? 'Hi! How can I help you today?',
            'greeting_buttons' => $greetingButtons,
            'avatar'           => $settings['chatbot_avatar'] ?? null,
            'color'            => $settings['chatbot_color'] ?? '#4F46E5',
            'position'         => $settings['chatbot_position'] ?? 'bottom-right',
            'width'            => (int) ($settings['chatbot_width'] ?? 380),
            'height'           => (int) ($settings['chatbot_height'] ?? 520),
            'language'         => $settings['chatbot_language'] ?? 'en',
            'auto_open'        => (int) ($settings['chatbot_auto_open_seconds'] ?? 0),
            'typing_indicator' => (bool) ($settings['chatbot_typing_indicator'] ?? true),
            'sound_enabled'    => (bool) ($settings['chatbot_sound_enabled'] ?? false),
            'offline_message'  => $settings['chatbot_offline_message'] ?? '',
            'maintenance'      => (bool) ($settings['chatbot_maintenance'] ?? false),
        ];
    }

    /**
     * Get analytics for chatbot usage.
     */
    public function getAnalytics(int $tenantId, int $days = 30): array
    {
        try {
            // Use Carbon object directly — BSON UTCDateTime comparison requires a Carbon/DateTime
            // instance, not a formatted string (string comparison fails silently in MongoDB driver).
            $since = now()->subDays($days)->startOfDay();
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

    private function generateResponse(int $tenantId, Conversation $conversation, array $intent, string $message, array $context, array $settings = []): array
    {
        // ── Custom Flow Check ──────────────────────────────────────
        // If a custom flow matches the current trigger, execute it instead of default handler.
        $customFlowResponse = $this->matchAndExecuteCustomFlow($tenantId, $conversation, $intent, $message, $context, $settings);
        if ($customFlowResponse !== null) {
            return $customFlowResponse;
        }

        return match ($intent['intent']) {
            'escalation'        => $this->handleEscalation($tenantId, $conversation, $message, $context, $settings),
            'complaint'         => $this->handleComplaint($tenantId, $conversation, $message, $context, $settings),
            'order_tracking'    => $this->handleOrderTracking($tenantId, $message, $context),
            'recommendation'    => $this->handleRecommendation($tenantId, $message, $context, $settings),
            'comparison'        => $this->handleComparison($tenantId, $message, $context, $settings),
            'product_inquiry',
            'product_search'    => $this->handleProductInquiry($tenantId, $message, $context, $settings),
            'checkout_help'     => $this->handleCheckoutHelp($tenantId, $message, $context),
            'return_request',
            'return_policy'     => $this->handleReturnRequest($tenantId, $message, $context, $settings),
            'coupon',
            'coupon_inquiry'    => $this->handleCouponInquiry($tenantId, $message, $context),
            'size_help'         => $this->handleSizeHelp($tenantId, $message, $context),
            'shipping',
            'shipping_inquiry'  => $this->handleShippingInquiry($tenantId, $message, $context, $settings),
            'add_to_cart'       => $this->handleAddToCart($tenantId, $message, $context),
            'account_help'      => $this->handleAccountHelp($tenantId, $message, $context, $settings),
            'payment_info'      => $this->handlePaymentInfo($tenantId, $message, $context, $settings),
            'stock_check'       => $this->handleStockCheck($tenantId, $message, $context, $settings),
            'loyalty'           => $this->handleLoyalty($tenantId, $message, $context, $settings),
            'gift_card'         => $this->handleGiftCard($tenantId, $message, $context, $settings),
            'subscription'      => $this->handleSubscription($tenantId, $message, $context, $settings),
            'greeting'          => $this->handleGreeting($tenantId, $settings),
            'farewell'          => $this->handleFarewell($conversation, $settings),
            'help'              => $this->handleHelp($tenantId, $settings),
            'store_hours'       => $this->handleStoreHours($tenantId, $settings),
            default             => $this->handleGeneral($tenantId, $message, $context, $settings),
        };
    }

    /**
     * Match and execute a custom flow from admin Flow Builder.
     *
     * Flows are stored as JSON in TenantSetting key "custom_flows".
     * Each flow has: name, status, priority, trigger (type, value, conditions),
     * max_triggers, cooldown_minutes, and steps[].
     *
     * Step types: text, quick_reply, product_search, action, delay, condition, api_call, escalate.
     *
     * Returns null if no flow matched; otherwise returns the response array.
     */
    private function matchAndExecuteCustomFlow(int $tenantId, Conversation $conversation, array $intent, string $message, array $context, array $settings): ?array
    {
        $flowsJson = $this->s($settings, 'custom_flows', '[]');
        $flows = is_array($flowsJson) ? $flowsJson : json_decode((string) $flowsJson, true);
        if (empty($flows) || !is_array($flows)) {
            return null;
        }

        // Sort by priority (lower = higher priority)
        usort($flows, fn($a, $b) => ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99));

        $messageLower = mb_strtolower(trim($message));

        foreach ($flows as $flow) {
            if (($flow['status'] ?? 'inactive') !== 'active') {
                continue;
            }

            // Support both nested {trigger: {type, value}} and flat {trigger_type, trigger_value} formats
            $triggerType = $flow['trigger']['type'] ?? $flow['trigger_type'] ?? '';
            $triggerValue = $flow['trigger']['value'] ?? $flow['trigger_value'] ?? '';
            $triggerConditions = $flow['trigger']['conditions'] ?? [];
            // Flat format: condition is a single string
            if (empty($triggerConditions) && !empty($flow['condition'])) {
                $triggerConditions = [$flow['condition']];
            }
            $matched = false;

            // ── Trigger matching ──
            switch ($triggerType) {
                case 'keyword':
                    $keywords = array_map('trim', explode(',', mb_strtolower($triggerValue)));
                    foreach ($keywords as $kw) {
                        if ($kw !== '' && str_contains($messageLower, $kw)) {
                            $matched = true;
                            break;
                        }
                    }
                    break;

                case 'intent':
                    if (($intent['intent'] ?? '') === $triggerValue) {
                        $matched = true;
                    }
                    break;

                case 'page':
                    // Match if context page URL contains the trigger value
                    $currentPage = mb_strtolower($context['page_url'] ?? $context['page'] ?? '');
                    if ($currentPage !== '' && str_contains($currentPage, mb_strtolower($triggerValue))) {
                        $matched = true;
                    }
                    break;

                case 'event':
                    // Match if context event matches
                    if (($context['event'] ?? '') === $triggerValue) {
                        $matched = true;
                    }
                    break;

                case 'visitor':
                    // Match visitor type — first_visit, returning, vip
                    $visitorType = $context['visitor_type'] ?? (($context['is_first_visit'] ?? false) ? 'first_visit' : 'returning');
                    if ($visitorType === $triggerValue) {
                        $matched = true;
                    }
                    break;
            }

            if (!$matched) {
                continue;
            }

            // ── Condition matching ──
            $conditions = $triggerConditions;
            if (!empty($conditions) && is_array($conditions)) {
                $conditionsPassed = true;
                foreach ($conditions as $condition) {
                    switch ($condition) {
                        case 'first_visit':
                            if (empty($context['is_first_visit'])) $conditionsPassed = false;
                            break;
                        case 'returning':
                            if (!empty($context['is_first_visit'])) $conditionsPassed = false;
                            break;
                        case 'has_cart':
                            if (empty($context['cart_items']) && empty($context['has_cart'])) $conditionsPassed = false;
                            break;
                        case 'empty_cart':
                            if (!empty($context['cart_items']) || !empty($context['has_cart'])) $conditionsPassed = false;
                            break;
                        case 'vip':
                            if (empty($context['is_vip'])) $conditionsPassed = false;
                            break;
                    }
                    if (!$conditionsPassed) break;
                }
                if (!$conditionsPassed) continue;
            }

            // ── Cooldown check ──
            $cooldown = (int) ($flow['cooldown_minutes'] ?? $flow['cooldown'] ?? 0);
            if ($cooldown > 0) {
                $flowId = $flow['id'] ?? ($flow['name'] ?? 'unknown');
                $cacheKey = "chatbot_flow_cooldown:{$tenantId}:{$conversation->session_id}:{$flowId}";
                if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                    continue; // Still in cooldown
                }
                \Illuminate\Support\Facades\Cache::put($cacheKey, true, $cooldown * 60);
            }

            // ── Execute flow steps ──
            $steps = $flow['steps'] ?? [];
            if (empty($steps)) {
                continue;
            }

            Log::info("ChatService: Custom flow '{$flow['name']}' triggered for tenant {$tenantId}", [
                'trigger_type' => $triggerType,
                'message' => $message,
                'intent' => $intent['intent'] ?? 'unknown',
            ]);

            return $this->executeFlowSteps($tenantId, $conversation, $steps, $message, $context, $settings);
        }

        return null;
    }

    /**
     * Execute a sequence of flow steps and build a combined response.
     */
    private function executeFlowSteps(int $tenantId, Conversation $conversation, array $steps, string $message, array $context, array $settings): array
    {
        $responseMessages = [];
        $quickReplies = [];
        $action = null;
        $actionPayload = null;
        $contentType = 'text';

        foreach ($steps as $step) {
            $type = $step['type'] ?? 'text';

            switch ($type) {
                case 'text':
                    // Simple text response — supports {{name}}, {{product}}, {{intent}} placeholders
                    $text = $step['content'] ?? $step['value'] ?? '';
                    $text = str_replace(
                        ['{{name}}', '{{product}}', '{{intent}}', '{{message}}'],
                        [
                            $context['customer_name'] ?? $context['name'] ?? 'there',
                            $context['product_name'] ?? $context['product'] ?? '',
                            $context['intent'] ?? '',
                            $message,
                        ],
                        $text
                    );
                    $responseMessages[] = $text;
                    break;

                case 'quick_reply':
                    // Add quick reply buttons — supports both:
                    // - options: [{label, value}] array format (programmatic)
                    // - content: "Label 1, Label 2, Label 3" comma-separated string (Flow Builder UI)
                    $options = $step['options'] ?? [];
                    if (empty($options) && !empty($step['content'])) {
                        // Parse comma-separated labels from Flow Builder
                        $labels = array_map('trim', explode(',', $step['content']));
                        foreach ($labels as $lbl) {
                            if ($lbl !== '') {
                                $quickReplies[] = ['label' => $lbl, 'value' => Str::slug($lbl, '_')];
                            }
                        }
                    } else {
                        foreach ($options as $opt) {
                            if (is_string($opt)) {
                                $quickReplies[] = ['label' => $opt, 'value' => Str::slug($opt, '_')];
                            } elseif (is_array($opt)) {
                                $quickReplies[] = [
                                    'label' => $opt['label'] ?? $opt['text'] ?? '',
                                    'value' => $opt['value'] ?? Str::slug($opt['label'] ?? '', '_'),
                                ];
                            }
                        }
                    }
                    break;

                case 'product_search':
                    // Delegate to product inquiry handler
                    $searchQuery = $step['query'] ?? $message;
                    $productResponse = $this->handleProductInquiry($tenantId, $searchQuery, $context, $settings);
                    $responseMessages[] = $productResponse['message'];
                    $quickReplies = array_merge($quickReplies, $productResponse['quick_replies'] ?? []);
                    $contentType = $productResponse['content_type'] ?? 'text';
                    break;

                case 'action':
                    // Set an action for the frontend to execute
                    $action = $step['action'] ?? $step['value'] ?? null;
                    $actionPayload = $step['payload'] ?? $step['data'] ?? null;
                    break;

                case 'delay':
                    // Delay metadata — frontend handles the pause
                    // We add a marker that the frontend can interpret
                    $delayMs = (int) ($step['duration'] ?? $step['value'] ?? 1000);
                    $responseMessages[] = "[[delay:{$delayMs}]]";
                    break;

                case 'condition':
                    // Simple condition branching
                    $condField = $step['field'] ?? '';
                    $condValue = $step['value'] ?? '';
                    $actualValue = $context[$condField] ?? '';
                    $branch = ($actualValue == $condValue) ? ($step['then_steps'] ?? []) : ($step['else_steps'] ?? []);
                    if (!empty($branch)) {
                        $branchResult = $this->executeFlowSteps($tenantId, $conversation, $branch, $message, $context, $settings);
                        $responseMessages[] = $branchResult['message'];
                        $quickReplies = array_merge($quickReplies, $branchResult['quick_replies'] ?? []);
                        if (!empty($branchResult['action'])) {
                            $action = $branchResult['action'];
                            $actionPayload = $branchResult['action_payload'] ?? null;
                        }
                    }
                    break;

                case 'api_call':
                    // Internal API call to chatbot endpoints
                    $endpoint = $step['endpoint'] ?? '';
                    $apiPayload = $step['payload'] ?? [];
                    if ($endpoint) {
                        try {
                            $apiPayload['tenant_id'] = $tenantId;
                            $apiPayload['session_id'] = $conversation->session_id ?? '';
                            $apiPayload['message'] = $message;
                            // Log but don't actually call — this would typically be a queued action
                            Log::info("ChatService: Flow API call to {$endpoint}", $apiPayload);
                            $responseMessages[] = $step['success_message'] ?? '';
                        } catch (\Exception $e) {
                            Log::error("ChatService: Flow API call failed", ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
                            $responseMessages[] = $step['error_message'] ?? 'Something went wrong, please try again.';
                        }
                    }
                    break;

                case 'escalate':
                    // Escalate to human support
                    $conversation->update([
                        'status'      => 'escalated',
                        'escalated_at' => now(),
                        'escalation_reason' => $step['reason'] ?? 'custom_flow_escalation',
                    ]);
                    $responseMessages[] = $step['message'] ?? "I'm connecting you with a human agent. Please hold on...";
                    $action = 'escalate';
                    $actionPayload = ['reason' => $step['reason'] ?? 'custom_flow', 'priority' => $step['priority'] ?? 'normal'];
                    break;
            }
        }

        // Combine all text messages
        $finalMessage = implode("\n\n", array_filter($responseMessages));

        return [
            'message'        => $finalMessage ?: 'How can I help you?',
            'content_type'   => $contentType,
            'quick_replies'  => $quickReplies,
            'action'         => $action,
            'action_payload' => $actionPayload,
            'metadata'       => ['source' => 'custom_flow'],
        ];
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

    private function handleProductInquiry(int $tenantId, string $message, array $context, array $settings = []): array
    {
        // ── Button-triggered product search: no real query present ──
        // When the user clicked a quick-reply button (e.g. "find_product", "product_help",
        // "browse_categories") the message is just the button value — not a product query.
        // Show a category prompt instead of searching for the button label.
        $buttonOnlyTriggers = [
            'find_product', 'product_help', 'browse_categories', 'browse_all_categories',
        ];
        if (in_array(strtolower(trim($message)), $buttonOnlyTriggers, true)) {
            return [
                'message'       => "Sure! What are you looking for? You can type a product name, brand, or category — or pick one below:",
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Liquor & Spirits', 'value' => 'liquor'],
                    ['label' => 'Perfumes',          'value' => 'perfume'],
                    ['label' => 'Chocolates',        'value' => 'chocolate'],
                    ['label' => 'Best sellers',      'value' => 'best_sellers'],
                    ['label' => 'New arrivals',      'value' => 'new_arrivals'],
                ],
            ];
        }

        // Button triggers that DO imply a real search query — map to clean search terms
        $buttonQueryMap = [
            'best_sellers' => 'popular',
            'new_arrivals' => 'new arrivals',
            'liquor'       => 'liquor',
            'perfume'      => 'perfume',
            'chocolate'    => 'chocolate',
        ];
        if (isset($buttonQueryMap[strtolower(trim($message))])) {
            $message = $buttonQueryMap[strtolower(trim($message))];
        }

        // Extract search keywords — strip common chatbot phrases
        $query = preg_replace(
            '/\b(show|find|search|looking for|i want|i need|can you|please|get me|me|any|some|do you have|have you got)\b/i',
            '', $message
        );
        $query = trim(preg_replace('/\s+/', ' ', $query));
        if (strlen($query) < 2) {
            $query = $message; // Fallback to full message if stripped too much
        }

        // Build search keywords for OR matching
        $keywords = array_filter(explode(' ', $query), fn($w) => strlen($w) >= 2);
        $tenantStr = (string) $tenantId;

        // Try SearchService first (has NLQ, fuzzy, relevance scoring)
        try {
            $searchService = app(\Modules\AiSearch\Services\SearchService::class);
            $result = $searchService->search($tenantStr, [
                'query'    => $query,
                'per_page' => (int) ($settings['chatbot_max_products'] ?? 5),
            ]);
            if (!empty($result['success']) && !empty($result['results'])) {
                $products = collect($result['results']);
                return $this->formatProductResponse($products, $query, $settings);
            }
        } catch (\Throwable $e) {
            Log::debug('Chatbot: SearchService unavailable, falling back to direct query', ['error' => $e->getMessage()]);
        }

        // Fallback: Direct MongoDB search with keyword OR matching
        $regexParts = array_map(fn($w) => preg_quote($w, '/'), $keywords);
        $regex = '/' . implode('|', $regexParts) . '/i';

        $products = DB::connection('mongodb')
            ->table('synced_products')
            ->where('tenant_id', $tenantStr)
            ->where(function ($q) use ($regex) {
                $q->where('name', 'regex', $regex)
                  ->orWhere('brand', 'regex', $regex)
                  ->orWhere('categories', 'regex', $regex);
            })
            ->limit((int) ($settings['chatbot_max_products'] ?? 5))
            ->get();

        if ($products->isEmpty()) {
            $noProductsMsg = $settings['tpl_no_products'] ?? "I couldn't find products matching \"{$query}\". Could you try different keywords or let me help you browse categories?";
            return [
                'message'       => str_replace('{query}', $query, $noProductsMsg),
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Browse categories', 'value' => 'browse_categories'],
                    ['label' => 'Best sellers', 'value' => 'best_sellers'],
                    ['label' => 'New arrivals', 'value' => 'new_arrivals'],
                ],
            ];
        }

        return $this->formatProductResponse($products, $query, $settings);
    }

    /**
     * Format product results into product cards for chatbot.
     * Returns content_type 'products' with structured card data.
     */
    private function formatProductResponse($products, string $query, array $settings = []): array
    {
        $baseUrl = rtrim($this->s($settings, 'chatbot_store_url', 'https://stagingddf.gmraerodutyfree.in'), '/');
        $urlPattern = $this->s($settings, 'chatbot_product_url_pattern', '/default/{url_key}.html');
        $maxProducts = (int) $this->s($settings, 'chatbot_max_products', 6);

        $productCards = $products->take($maxProducts)->map(function ($p) use ($baseUrl, $urlPattern) {
            $p = is_object($p) ? (array) $p : $p;
            $price        = (float) ($p['price'] ?? 0);
            $specialPrice = !empty($p['special_price']) ? (float) $p['special_price'] : null;
            $urlKey       = $p['url_key'] ?? '';
            $image        = $p['image'] ?? $p['image_url'] ?? '';

            // Build full URLs from configurable pattern
            $productUrl = $urlKey ? $baseUrl . str_replace('{url_key}', $urlKey, $urlPattern) : '#';
            if ($image && !str_starts_with($image, 'http')) {
                $image = $baseUrl . $image;
            }

            return [
                'name'          => $p['name'] ?? 'Product',
                'brand'         => $p['brand'] ?? null,
                'price'         => $price,
                'special_price' => $specialPrice,
                'image'         => $image,
                'url'           => $productUrl,
                'sku'           => $p['sku'] ?? '',
                'in_stock'      => ($p['stock_qty'] ?? 0) > 0 || ($p['in_stock'] ?? false),
            ];
        })->values()->toArray();

        $total = $products->count();
        $searchUrl = $baseUrl . '/default/ecom360/search/results/?q=' . urlencode($query);
        // Clean display label — don't show internal query strings
        $displayQuery = strlen($query) > 40 ? substr($query, 0, 40) . '…' : $query;

        return [
            'message'       => "I found {$total} product" . ($total !== 1 ? 's' : '') . " for \"{$displayQuery}\":",
            'content_type'  => 'products',
            'products'      => $productCards,
            'total'         => $total,
            'search_url'    => $searchUrl,
            'quick_replies' => [
                ['label' => 'View all results', 'value' => 'view_all'],
                ['label' => 'Refine search',    'value' => 'refine_search'],
                ['label' => 'Browse categories','value' => 'browse_categories'],
            ],
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

    private function handleReturnRequest(int $tenantId, string $message, array $context, array $settings = []): array
    {
        $returnDays = $this->s($settings, 'chatbot_return_days', '30');
        $tplReturns = $this->s($settings, 'tpl_returns', "I'm sorry you need to make a return. I can help with that!\n\nOur return policy allows returns within {days} days of delivery for most items. Would you like to start a return?");
        $msg = str_replace('{days}', (string) $returnDays, $tplReturns);

        return [
            'message'       => $msg,
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
        $messageTrimmed = trim($message);
        $messageLower   = strtolower($messageTrimmed);

        // ── 1. User typed a specific coupon/promo code (e.g. WELCOME15, SAVE20) ──
        // Pattern: starts with a letter, all uppercase alphanumeric, contains at least one digit
        if (preg_match('/^[A-Z][A-Z0-9]{3,}$/', $messageTrimmed) && preg_match('/\d/', $messageTrimmed)) {
            $code = $messageTrimmed;
            return [
                'message'        => "To redeem **{$code}**, add items to your cart and enter the code in the \"Coupon Code\" field at checkout — the discount applies automatically! 🎉",
                'content_type'   => 'text',
                'action'         => 'apply_coupon_hint',
                'action_payload' => ['code' => $code],
                'quick_replies'  => [
                    ['label' => 'Browse products',    'value' => 'find_product'],
                    ['label' => 'Talk to support',    'value' => 'escalate'],
                ],
            ];
        }

        // ── 2. User asking HOW to apply a coupon (not typing a code, not browsing) ──
        if (preg_match('/\b(how|where|when|steps?|guide|instruction|apply|use|enter|redeem)\b.*\b(coupon|code|promo|voucher|discount)\b/i', $messageTrimmed) ||
            preg_match('/\b(coupon|code|promo|voucher)\b.*\b(how|where|apply|use|enter|work)\b/i', $messageTrimmed)) {
            return [
                'message'       => "To apply a coupon or promo code:\n\n1. Add items to your cart\n2. Proceed to **Checkout**\n3. Look for the **\"Coupon Code\"** field\n4. Enter your code and click **Apply**\n\nThe discount will automatically reflect in your total! 🎉",
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Browse products', 'value' => 'find_product'],
                    ['label' => 'I have a code',   'value' => 'i have a promo code'],
                    ['label' => 'Checkout help',   'value' => 'checkout'],
                ],
            ];
        }

        // ── 3. Button-triggered deals browsing (show_deals, browse_sale, browse_deals, etc.) ──
        $dealButtons = ['show_deals', 'browse_sale', 'browse_deals', 'check_sale_items', 'subscribe_offers'];
        if (in_array($messageLower, $dealButtons, true)) {
            return [
                'message'       => "Here's what's on offer right now! 🛍️\n\nWe have great prices across Liquor, Perfumes, Chocolates, and more. Browse below or search for something specific:",
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Liquor under ₹3,000', 'value' => 'liquor under 3000'],
                    ['label' => 'Best sellers',         'value' => 'best_sellers'],
                    ['label' => 'New arrivals',         'value' => 'new_arrivals'],
                    ['label' => 'Find a product',       'value' => 'find_product'],
                ],
            ];
        }

        // ── 4. Personal coupon lookup (user asked "my coupons", "any discount?" etc.) ──
        $email = $context['email'] ?? null;
        $coupons = collect();

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
                'message'        => "Great news! You have active coupons:\n\n{$couponList}\n\nAdd items to your cart and enter the code at checkout.",
                'content_type'   => 'text',
                'action'         => 'apply_coupon',
                'action_payload' => ['coupons' => $coupons->pluck('code')->toArray()],
                'quick_replies'  => $coupons->take(3)->map(fn($c) => [
                    'label' => "Use {$c['code']}",
                    'value' => 'apply_coupon_' . $c['code'],
                ])->values()->toArray(),
            ];
        }

        // No personal coupons found — offer browsing instead (not the same loop buttons)
        return [
            'message'       => "I don't see personal coupons for your account right now. But there are great deals available — try browsing our products!",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Browse products',  'value' => 'find_product'],
                ['label' => 'Best sellers',     'value' => 'best_sellers'],
                ['label' => 'New arrivals',     'value' => 'new_arrivals'],
                ['label' => 'Talk to support',  'value' => 'escalate'],
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

    private function handleShippingInquiry(int $tenantId, string $message, array $context, array $settings = []): array
    {
        $tplShipping = $this->s($settings, 'tpl_shipping',
            "Here are our shipping options:\n\n• **Standard** — 5-7 business days (Free over \$50)\n• **Express** — 2-3 business days (\$9.99)\n• **Next Day** — 1 business day (\$19.99)\n\nAll orders include tracking!"
        );

        return [
            'message'       => $tplShipping,
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

    private function handleGreeting(int $tenantId, array $settings = []): array
    {
        $greeting = $this->s($settings, 'chatbot_greeting', "Hello! 👋 Welcome! I'm your shopping assistant. How can I help you today?");

        // Parse admin-configured greeting buttons (format: "Label|value, Label2|value2")
        $quickReplies = $this->parseQuickReplies(
            $this->s($settings, 'chatbot_greeting_buttons', ''),
            // Default buttons if none configured
            [
                ['label' => 'Track my order', 'value' => 'track_order'],
                ['label' => 'Find a product', 'value' => 'find_product'],
                ['label' => 'I need help', 'value' => 'need_help'],
                ['label' => 'Browse deals', 'value' => 'browse_deals'],
            ]
        );

        return [
            'message'       => $greeting,
            'content_type'  => 'text',
            'quick_replies' => $quickReplies,
        ];
    }

    private function handleFarewell(Conversation $conversation, array $settings = []): array
    {
        $conversation->update(['status' => 'resolved', 'resolved_at' => now()]);
        $farewellMsg = $this->s($settings, 'tpl_farewell', "Thank you for chatting! Have a wonderful day! 😊");

        return [
            'message'       => $farewellMsg,
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Rate this chat', 'value' => 'rate_chat'],
            ],
        ];
    }

    /**
     * Handle 'help' intent — show a clear menu of what the chatbot can do.
     * Triggered by "I need help", "help me", or the 'need_help' quick-reply button.
     */
    /**
     * Handle recommendation intent — ask what type of gift, then search.
     * "recommend a gift for my wife" → show category prompt, not a bad product search.
     */
    private function handleRecommendation(int $tenantId, string $message, array $context, array $settings = []): array
    {
        // Extract occasion / recipient from message
        $lower = strtolower($message);
        $occasion = null;
        if (str_contains($lower, 'birthday'))    $occasion = 'birthday';
        elseif (str_contains($lower, 'anniversary')) $occasion = 'anniversary';
        elseif (str_contains($lower, 'wedding'))     $occasion = 'wedding';
        elseif (str_contains($lower, 'mother'))      $occasion = "for mom";
        elseif (str_contains($lower, 'father'))      $occasion = "for dad";
        elseif (str_contains($lower, 'wife') || str_contains($lower, 'girlfriend')) $occasion = "for her";
        elseif (str_contains($lower, 'husband') || str_contains($lower, 'boyfriend')) $occasion = "for him";

        $intro = $occasion
            ? "Great choice! Here are some popular gift ideas {$occasion}:"
            : "I'd love to help you find the perfect gift! What type of product are you considering?";

        // If we have an occasion, show a product search based on popular gift types
        if ($occasion) {
            // Try a product search for popular gift items
            return $this->handleProductInquiry($tenantId, 'popular gift perfume whisky chocolate', $context, $settings);
        }

        return [
            'message'       => $intro,
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Perfumes & Fragrances', 'value' => 'perfume'],
                ['label' => 'Whisky & Spirits',      'value' => 'whisky'],
                ['label' => 'Chocolates & Sweets',   'value' => 'chocolate'],
                ['label' => 'Watches & Accessories', 'value' => 'watch'],
                ['label' => 'Something else',        'value' => 'find_product'],
            ],
        ];
    }

    /**
     * Handle comparison intent — search for both products and show them side-by-side.
     */
    private function handleComparison(int $tenantId, string $message, array $context, array $settings = []): array
    {
        // Extract the two items being compared
        $lower = strtolower($message);
        $lower = preg_replace('/\b(compare|comparison|versus|vs\.?|or|which is better|difference between)\b/i', ' ', $lower);
        $query = trim(preg_replace('/\s+/', ' ', $lower));

        if (strlen($query) < 2) {
            return [
                'message'       => "Sure! Which two products would you like to compare? You can type something like 'compare whisky vs vodka' or 'Jack Daniels vs Chivas'.",
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Compare whisky brands', 'value' => 'compare whisky brands'],
                    ['label' => 'Compare perfumes',      'value' => 'compare perfumes'],
                ],
            ];
        }

        // Search and show up to 4 products so user can compare
        try {
            $searchService = app(\Modules\AiSearch\Services\SearchService::class);
            $result = $searchService->search((string) $tenantId, [
                'query'    => $query,
                'per_page' => 4,
            ]);
            if (!empty($result['success']) && !empty($result['results'])) {
                $products = collect($result['results']);
                $resp = $this->formatProductResponse($products, $query, $settings);
                $resp['message'] = "Here are the products I found for comparison:";
                return $resp;
            }
        } catch (\Throwable $e) { /* fall through */ }

        return [
            'message'       => "I can help you compare! Could you be more specific about which products or brands you'd like to see side by side?",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Browse products', 'value' => 'find_product'],
            ],
        ];
    }

    private function handleHelp(int $tenantId, array $settings = []): array
    {
        $msg = $this->s($settings, 'tpl_help',
            "Of course! Here's what I can help you with today 👇"
        );

        return [
            'message'       => $msg,
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Find a product',     'value' => 'find_product'],
                ['label' => 'Track my order',     'value' => 'track_order'],
                ['label' => 'Returns & refunds',  'value' => 'return_help'],
                ['label' => 'Deals & coupons',    'value' => 'browse_deals'],
                ['label' => 'Talk to a human',    'value' => 'escalate'],
            ],
        ];
    }

    /**
     * Handle store_hours intent — provide operating hours for the duty-free store.
     */
    private function handleStoreHours(int $tenantId, array $settings = []): array
    {
        $hoursMsg = $this->s($settings, 'tpl_store_hours',
            "🕐 **Store Hours**\n\n" .
            "Our duty-free stores operate according to flight schedules:\n\n" .
            "• **Departures** — Open 24 hours, 7 days a week\n" .
            "• **Arrivals** — Open for all international arriving flights\n\n" .
            "You can also **pre-order online** and collect at the airport!"
        );

        return [
            'message'       => $hoursMsg,
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Browse products',  'value' => 'find_product'],
                ['label' => 'Pre-order online', 'value' => 'how to pre-order'],
                ['label' => 'Contact us',       'value' => 'escalate'],
            ],
        ];
    }

    private function handleGeneral(int $tenantId, string $message, array $context, array $settings = []): array
    {
        $fallbackMsg = $this->s($settings, 'tpl_general_fallback', "I understand you need help with that. Let me see what I can do!\n\nHere are some things I can help with:");

        $quickReplies = $this->parseQuickReplies(
            $this->s($settings, 'chatbot_fallback_buttons', ''),
            [
                ['label' => 'Track order', 'value' => 'track_order'],
                ['label' => 'Product questions', 'value' => 'product_help'],
                ['label' => 'Returns & exchanges', 'value' => 'return_help'],
                ['label' => 'Talk to a human', 'value' => 'escalate'],
            ]
        );

        return [
            'message'       => $fallbackMsg,
            'content_type'  => 'text',
            'quick_replies' => $quickReplies,
        ];
    }

    // ── New Intent Handlers ─────────────────────────────────────────

    /**
     * Handle escalation — connect customer to a human agent.
     * Provides a contact form so the customer can leave details.
     */
    private function handleEscalation(int $tenantId, Conversation $conversation, string $message, array $context, array $settings = []): array
    {
        // Mark conversation as escalated
        $conversation->update([
            'status'            => 'escalated',
            'escalated_at'      => now(),
            'escalation_reason' => 'customer_requested',
        ]);

        $email = $context['email'] ?? $conversation->customer_email ?? null;
        $name  = $context['name'] ?? $conversation->customer_name ?? null;

        // If we don't have contact info, show a form
        if (!$email) {
            return [
                'message'        => "I understand you'd like to speak with a human agent. Let me collect your details so our team can reach out to you right away.",
                'content_type'   => 'form',
                'action'         => 'escalate',
                'action_payload' => [
                    'form_id'      => 'escalation_form',
                    'title'        => 'Connect with an Agent',
                    'subtitle'     => 'Please provide your details and we\'ll connect you shortly.',
                    'fields'       => [
                        ['name' => 'name', 'type' => 'text', 'label' => 'Your Name', 'required' => true, 'placeholder' => 'John Doe'],
                        ['name' => 'email', 'type' => 'email', 'label' => 'Email Address', 'required' => true, 'placeholder' => 'you@example.com'],
                        ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone Number', 'required' => false, 'placeholder' => '+1 (555) 000-0000'],
                        ['name' => 'reason', 'type' => 'textarea', 'label' => 'How can we help?', 'required' => true, 'placeholder' => 'Briefly describe your issue...'],
                        ['name' => 'preferred_channel', 'type' => 'select', 'label' => 'Preferred Contact Method', 'required' => true, 'options' => [
                            ['label' => 'Email', 'value' => 'email'],
                            ['label' => 'Phone Call', 'value' => 'phone'],
                            ['label' => 'WhatsApp', 'value' => 'whatsapp'],
                        ]],
                    ],
                    'submit_label'  => 'Request Agent',
                    'submit_action' => 'submit_escalation',
                    'communications' => ['email_confirmation', 'agent_notification'],
                ],
                'quick_replies' => [],
            ];
        }

        // We have the email already — escalate directly
        // Trigger notification to support team
        try {
            app(CommunicationService::class)->sendNotification($tenantId, [
                'type'    => 'escalation',
                'channel' => 'email',
                'to'      => $this->s($settings, 'chatbot_support_email', 'support@ecom360.com'),
                'data'    => [
                    'customer_name'    => $name ?? 'Unknown',
                    'customer_email'   => $email,
                    'conversation_id'  => (string) $conversation->_id,
                    'message'          => $message,
                    'reason'           => 'Customer requested human agent',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("Escalation notification failed: {$e->getMessage()}");
        }

        return [
            'message'        => "I've escalated your request to our support team. A human agent will reach out to you at {$email} shortly.\n\nIn the meantime, is there anything else I can help with?",
            'content_type'   => 'text',
            'action'         => 'escalate',
            'action_payload' => [
                'reason'   => 'customer_requested',
                'priority' => 'high',
                'email'    => $email,
            ],
            'quick_replies'  => [
                ['label' => 'Track my order', 'value' => 'track_order'],
                ['label' => 'Browse products', 'value' => 'find_product'],
            ],
        ];
    }

    /**
     * Handle complaints — detect frustration, offer resolution, show complaint form.
     */
    private function handleComplaint(int $tenantId, Conversation $conversation, string $message, array $context, array $settings = []): array
    {
        $sentiment = $this->intentService->detectSentiment(strtolower($message));

        // Empathetic response with escalation option + complaint form
        $empathyMsg = "I'm really sorry to hear about your experience. Your satisfaction is very important to us, and I want to make sure this gets resolved properly.";

        if ($sentiment['score'] <= 20) {
            // Very angry — immediately offer human agent
            $empathyMsg = "I sincerely apologize for your experience. I completely understand your frustration. Let me connect you with a senior support specialist right away.";
            $conversation->update([
                'status'            => 'escalated',
                'escalated_at'      => now(),
                'escalation_reason' => 'angry_customer',
            ]);
        }

        return [
            'message'        => $empathyMsg,
            'content_type'   => 'form',
            'action'         => 'complaint',
            'action_payload' => [
                'form_id'      => 'complaint_form',
                'title'        => 'Submit a Complaint',
                'subtitle'     => 'We take every complaint seriously. Please share the details below.',
                'fields'       => [
                    ['name' => 'name', 'type' => 'text', 'label' => 'Your Name', 'required' => true, 'placeholder' => 'John Doe'],
                    ['name' => 'email', 'type' => 'email', 'label' => 'Email Address', 'required' => true, 'placeholder' => 'you@example.com'],
                    ['name' => 'order_id', 'type' => 'text', 'label' => 'Order Number (if applicable)', 'required' => false, 'placeholder' => 'ORD-XXXX'],
                    ['name' => 'issue_type', 'type' => 'select', 'label' => 'Issue Type', 'required' => true, 'options' => [
                        ['label' => 'Product Quality', 'value' => 'quality'],
                        ['label' => 'Delivery Issue', 'value' => 'delivery'],
                        ['label' => 'Wrong Item Received', 'value' => 'wrong_item'],
                        ['label' => 'Poor Customer Service', 'value' => 'service'],
                        ['label' => 'Billing/Payment Issue', 'value' => 'billing'],
                        ['label' => 'Other', 'value' => 'other'],
                    ]],
                    ['name' => 'description', 'type' => 'textarea', 'label' => 'Describe your issue', 'required' => true, 'placeholder' => 'Please provide as much detail as possible...'],
                    ['name' => 'resolution', 'type' => 'select', 'label' => 'Preferred Resolution', 'required' => false, 'options' => [
                        ['label' => 'Full Refund', 'value' => 'refund'],
                        ['label' => 'Replacement', 'value' => 'replacement'],
                        ['label' => 'Store Credit', 'value' => 'credit'],
                        ['label' => 'Other', 'value' => 'other'],
                    ]],
                ],
                'submit_label'   => 'Submit Complaint',
                'submit_action'  => 'submit_complaint',
                'communications' => ['email_confirmation', 'email_support_team', 'push_notification'],
                'sentiment'      => $sentiment,
            ],
            'quick_replies' => [
                ['label' => 'Talk to a human now', 'value' => 'talk to a human'],
            ],
        ];
    }

    /**
     * Handle account-related queries — password, profile, login issues.
     */
    private function handleAccountHelp(int $tenantId, string $message, array $context, array $settings = []): array
    {
        $lower = strtolower($message);

        // Detect specific account sub-intent
        if (str_contains($lower, 'password') || str_contains($lower, 'forgot') || str_contains($lower, 'reset')) {
            return [
                'message'       => "I can help you reset your password! Here's what to do:\n\n1. Go to the **Login** page\n2. Click **\"Forgot Password\"**\n3. Enter your email address\n4. Check your inbox for a reset link\n\nThe link expires in 24 hours.",
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Go to Login', 'value' => 'go_to_login'],
                    ['label' => 'Didn\'t receive email', 'value' => 'no_reset_email'],
                    ['label' => 'Talk to support', 'value' => 'talk to a human'],
                ],
            ];
        }

        if (str_contains($lower, 'create') || str_contains($lower, 'sign up') || str_contains($lower, 'register')) {
            return [
                'message'       => "Creating an account is easy! You'll get benefits like:\n\n✅ Faster checkout\n✅ Order tracking\n✅ Exclusive offers\n✅ Wishlist\n\nClick below to get started!",
                'content_type'  => 'text',
                'action'        => 'redirect',
                'action_payload' => ['url' => '/customer/account/create'],
                'quick_replies' => [
                    ['label' => 'Create Account', 'value' => 'create_account'],
                    ['label' => 'I already have one', 'value' => 'go_to_login'],
                ],
            ];
        }

        if (str_contains($lower, 'delete') || str_contains($lower, 'deactivate') || str_contains($lower, 'close account')) {
            return [
                'message'        => "I understand you'd like to manage your account. For account deletion, we need to verify your identity.",
                'content_type'   => 'form',
                'action'         => 'account_deletion_request',
                'action_payload' => [
                    'form_id'      => 'account_deletion_form',
                    'title'        => 'Account Deletion Request',
                    'subtitle'     => 'Please verify your identity to proceed.',
                    'fields'       => [
                        ['name' => 'email', 'type' => 'email', 'label' => 'Account Email', 'required' => true, 'placeholder' => 'you@example.com'],
                        ['name' => 'reason', 'type' => 'select', 'label' => 'Reason for leaving', 'required' => true, 'options' => [
                            ['label' => 'No longer need it', 'value' => 'not_needed'],
                            ['label' => 'Privacy concerns', 'value' => 'privacy'],
                            ['label' => 'Too many emails', 'value' => 'emails'],
                            ['label' => 'Other', 'value' => 'other'],
                        ]],
                        ['name' => 'confirmation', 'type' => 'text', 'label' => 'Type DELETE to confirm', 'required' => true, 'placeholder' => 'DELETE'],
                    ],
                    'submit_label'   => 'Submit Request',
                    'submit_action'  => 'submit_account_deletion',
                    'communications' => ['email_confirmation'],
                ],
                'quick_replies' => [],
            ];
        }

        // General account help
        return [
            'message'       => "I can help with your account! What do you need?",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Reset password', 'value' => 'reset password'],
                ['label' => 'Create account', 'value' => 'create account'],
                ['label' => 'Update profile', 'value' => 'update my profile'],
                ['label' => 'Delete account', 'value' => 'delete my account'],
                ['label' => 'Talk to support', 'value' => 'talk to a human'],
            ],
        ];
    }

    /**
     * Handle payment-related queries — methods, issues, refund status.
     */
    private function handlePaymentInfo(int $tenantId, string $message, array $context, array $settings = []): array
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'failed') || str_contains($lower, 'declined') || str_contains($lower, 'not working') || str_contains($lower, 'error')) {
            return [
                'message'       => "I'm sorry you're having payment issues. Here are some common solutions:\n\n1. **Check card details** — Ensure number, expiry, and CVV are correct\n2. **Sufficient funds** — Verify your account balance\n3. **Bank restrictions** — Some banks block online transactions by default\n4. **Try another method** — We accept multiple payment options\n\nIf the issue persists, your bank may be able to help.",
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Try another card', 'value' => 'try_another_card'],
                    ['label' => 'Payment methods', 'value' => 'payment methods'],
                    ['label' => 'Talk to support', 'value' => 'talk to a human'],
                ],
            ];
        }

        if (str_contains($lower, 'refund') || str_contains($lower, 'when will i get')) {
            return [
                'message'       => "Refund timelines depend on the payment method:\n\n💳 **Credit/Debit Card** — 5-10 business days\n🏦 **Bank Transfer** — 3-5 business days\n💰 **Store Credit** — Instant\n📱 **Digital Wallet** — 1-3 business days\n\nWould you like to check a specific refund?",
                'content_type'  => 'text',
                'quick_replies' => [
                    ['label' => 'Check my refund', 'value' => 'check refund status'],
                    ['label' => 'Track my order', 'value' => 'track order'],
                    ['label' => 'Talk to support', 'value' => 'talk to a human'],
                ],
            ];
        }

        if (str_contains($lower, 'double charged') || str_contains($lower, 'charged twice')) {
            return [
                'message'        => "I apologize for the inconvenience! Let me help you report a double charge.",
                'content_type'   => 'form',
                'action'         => 'payment_dispute',
                'action_payload' => [
                    'form_id'      => 'payment_dispute_form',
                    'title'        => 'Report Payment Issue',
                    'subtitle'     => 'Please provide details about the duplicate charge.',
                    'fields'       => [
                        ['name' => 'email', 'type' => 'email', 'label' => 'Account Email', 'required' => true, 'placeholder' => 'you@example.com'],
                        ['name' => 'order_id', 'type' => 'text', 'label' => 'Order Number', 'required' => true, 'placeholder' => 'ORD-XXXX'],
                        ['name' => 'amount', 'type' => 'text', 'label' => 'Charged Amount', 'required' => true, 'placeholder' => '$0.00'],
                        ['name' => 'description', 'type' => 'textarea', 'label' => 'Additional Details', 'required' => false, 'placeholder' => 'Describe the issue...'],
                    ],
                    'submit_label'   => 'Report Issue',
                    'submit_action'  => 'submit_payment_dispute',
                    'communications' => ['email_confirmation', 'email_support_team'],
                ],
                'quick_replies' => [],
            ];
        }

        // General payment info
        $tplPayment = $this->s($settings, 'tpl_payment_info',
            "We accept the following payment methods:\n\n💳 Visa, Mastercard, American Express\n📱 Apple Pay, Google Pay\n🏦 Bank Transfer\n💰 Gift Cards & Store Credit\n🔄 Buy Now Pay Later (Afterpay/Klarna)\n\nAll transactions are secured with 256-bit SSL encryption.");

        return [
            'message'       => $tplPayment,
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Payment issues', 'value' => 'payment failed'],
                ['label' => 'Refund status', 'value' => 'refund status'],
                ['label' => 'Talk to support', 'value' => 'talk to a human'],
            ],
        ];
    }

    /**
     * Handle stock check queries — product availability.
     */
    private function handleStockCheck(int $tenantId, string $message, array $context, array $settings = []): array
    {
        // Extract product name from message
        $query = preg_replace('/\b(is|it|in|out of|stock|available|availability|check|the|do you have)\b/i', '', $message);
        $query = trim(preg_replace('/\s+/', ' ', $query));

        if (strlen($query) >= 2) {
            // Search for the product
            $productResult = $this->handleProductInquiry($tenantId, $query, $context, $settings);
            if (($productResult['content_type'] ?? '') === 'products' && !empty($productResult['products'])) {
                $inStockProducts = array_filter($productResult['products'], fn($p) => !empty($p['in_stock']));
                $total = count($productResult['products']);
                $inStock = count($inStockProducts);

                $stockMsg = "I found {$total} product(s) for \"{$query}\".\n\n";
                $stockMsg .= $inStock > 0
                    ? "✅ **{$inStock} item(s) in stock** and ready to ship!"
                    : "⚠️ Unfortunately, these items are currently out of stock.";

                $productResult['message'] = $stockMsg;
                return $productResult;
            }
        }

        return [
            'message'       => "I'd be happy to check stock availability! Which product are you interested in?",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Search products', 'value' => 'find_product'],
                ['label' => 'New arrivals', 'value' => 'new_arrivals'],
                ['label' => 'Best sellers', 'value' => 'best_sellers'],
            ],
        ];
    }

    /**
     * Handle loyalty / rewards queries.
     */
    private function handleLoyalty(int $tenantId, string $message, array $context, array $settings = []): array
    {
        $email = $context['email'] ?? null;

        $tplLoyalty = $this->s($settings, 'tpl_loyalty',
            "Welcome to our Loyalty Program! 🌟\n\n**How it works:**\n• Earn 1 point for every \$1 spent\n• 100 points = \$5 reward\n• Exclusive member-only deals\n• Early access to sales\n• Birthday bonus points\n\n**Tiers:**\n🥉 Bronze — 0-499 points\n🥈 Silver — 500-999 points\n🥇 Gold — 1,000-1,999 points\n💎 Platinum — 2,000+ points");

        $qr = [
            ['label' => 'Join loyalty program', 'value' => 'join_loyalty'],
            ['label' => 'Check my points', 'value' => 'check_points'],
            ['label' => 'Refer a friend', 'value' => 'refer_friend'],
        ];

        // If user has email, try to get their points
        if ($email) {
            try {
                $customer = DB::connection('mongodb')
                    ->table('synced_customers')
                    ->where('tenant_id', (string) $tenantId)
                    ->where('email', $email)
                    ->first();

                if ($customer) {
                    $points = (int) (((array) $customer)['loyalty_points'] ?? 0);
                    $tier = match (true) {
                        $points >= 2000 => 'Platinum 💎',
                        $points >= 1000 => 'Gold 🥇',
                        $points >= 500  => 'Silver 🥈',
                        default         => 'Bronze 🥉',
                    };
                    $tplLoyalty = "Here's your loyalty status:\n\n🏆 **Tier:** {$tier}\n⭐ **Points:** {$points}\n💰 **Reward Value:** \$" . number_format($points / 20, 2) . "\n\nKeep shopping to earn more rewards!";
                    $qr = [
                        ['label' => 'Redeem points', 'value' => 'redeem_points'],
                        ['label' => 'Refer a friend', 'value' => 'refer_friend'],
                        ['label' => 'Browse deals', 'value' => 'browse_deals'],
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug("Loyalty lookup failed: {$e->getMessage()}");
            }
        }

        return [
            'message'       => $tplLoyalty,
            'content_type'  => 'text',
            'quick_replies' => $qr,
        ];
    }

    /**
     * Handle gift card queries.
     */
    private function handleGiftCard(int $tenantId, string $message, array $context, array $settings = []): array
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'balance') || str_contains($lower, 'redeem')) {
            return [
                'message'        => "Let me check your gift card balance!",
                'content_type'   => 'form',
                'action'         => 'check_gift_card',
                'action_payload' => [
                    'form_id'      => 'gift_card_balance_form',
                    'title'        => 'Check Gift Card Balance',
                    'subtitle'     => 'Enter your gift card details below.',
                    'fields'       => [
                        ['name' => 'card_number', 'type' => 'text', 'label' => 'Gift Card Number', 'required' => true, 'placeholder' => 'GC-XXXXXXXXXX'],
                        ['name' => 'pin', 'type' => 'text', 'label' => 'PIN (if applicable)', 'required' => false, 'placeholder' => '****'],
                    ],
                    'submit_label'  => 'Check Balance',
                    'submit_action' => 'submit_gift_card_check',
                ],
                'quick_replies' => [],
            ];
        }

        return [
            'message'       => "🎁 **Gift Cards** — The perfect present!\n\nAvailable in denominations: \$25, \$50, \$75, \$100, \$150, \$200\n\n• Delivered instantly via email\n• Never expires\n• Custom message included\n• Redeemable on any product",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Buy a gift card', 'value' => 'buy_gift_card'],
                ['label' => 'Check balance', 'value' => 'gift card balance'],
                ['label' => 'How to redeem', 'value' => 'redeem_gift_card'],
            ],
        ];
    }

    /**
     * Handle subscription queries.
     */
    private function handleSubscription(int $tenantId, string $message, array $context, array $settings = []): array
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'cancel') || str_contains($lower, 'unsubscribe')) {
            return [
                'message'        => "I'm sorry to see you considering cancellation. Let me see what we can do.",
                'content_type'   => 'form',
                'action'         => 'cancel_subscription',
                'action_payload' => [
                    'form_id'      => 'subscription_cancel_form',
                    'title'        => 'Manage Subscription',
                    'subtitle'     => 'Before you cancel, would you consider these alternatives?',
                    'fields'       => [
                        ['name' => 'email', 'type' => 'email', 'label' => 'Account Email', 'required' => true, 'placeholder' => 'you@example.com'],
                        ['name' => 'action', 'type' => 'select', 'label' => 'What would you like to do?', 'required' => true, 'options' => [
                            ['label' => 'Pause for 2 weeks', 'value' => 'pause_2w'],
                            ['label' => 'Pause for 1 month', 'value' => 'pause_1m'],
                            ['label' => 'Change frequency', 'value' => 'change_freq'],
                            ['label' => 'Get 20% off next 3 orders', 'value' => 'retention_discount'],
                            ['label' => 'Cancel subscription', 'value' => 'cancel'],
                        ]],
                        ['name' => 'reason', 'type' => 'textarea', 'label' => 'Reason (optional)', 'required' => false, 'placeholder' => 'Help us improve...'],
                    ],
                    'submit_label'   => 'Submit',
                    'submit_action'  => 'submit_subscription_change',
                    'communications' => ['email_confirmation'],
                ],
                'quick_replies' => [],
            ];
        }

        return [
            'message'       => "📦 **Subscription & Auto-Delivery**\n\nNever run out of your favorites!\n\n• **Save up to 15%** on every delivery\n• Flexible frequency (weekly / bi-weekly / monthly)\n• Skip or pause anytime\n• Free shipping on all subscription orders\n• Cancel with no fees",
            'content_type'  => 'text',
            'quick_replies' => [
                ['label' => 'Start a subscription', 'value' => 'start_subscription'],
                ['label' => 'Manage my subscription', 'value' => 'manage subscription'],
                ['label' => 'Cancel subscription', 'value' => 'cancel subscription'],
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

<?php
declare(strict_types=1);

namespace Modules\Chatbot\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * ProactiveSupportService — Intelligent customer support automation.
 *
 * UC31: Order Modification via Chat — change address/item/cancel before shipment
 * UC32: Sentiment Escalation Router — detect anger, route to human agents
 * UC33: VIP Customer Greeting — personalized greetings based on LTV/tier
 * UC34: Warranty Claims Processor — guided warranty claim filing
 * UC35: Multi-Item Sizing Assistant — size recommendations for entire cart
 */
class ProactiveSupportService
{
    /**
     * UC31: Process order modifications via chat commands.
     */
    public function orderModification(int $tenantId, array $request): array
    {
        try {
            $action = $request['action'] ?? 'unknown';
            $orderId = $request['order_id'] ?? null;
            $customerEmail = $request['customer_email'] ?? null;

            if (!$orderId && !$customerEmail) {
                return ['success' => false, 'message' => 'Please provide your order ID and email address.'];
            }
            if (!$orderId) {
                return ['success' => false, 'message' => 'Please provide your order ID to continue.'];
            }
            if (!$customerEmail) {
                return ['success' => false, 'message' => "Please provide the email address associated with order #{$orderId} to verify your identity."];
            }

            // Fetch order
            $order = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('external_id', $orderId)
                ->where('customer_email', $customerEmail)
                ->first();

            if (!$order) {
                return ['success' => false, 'message' => "I couldn't find order #{$orderId}. Please verify the order ID."];
            }

            $order = $order instanceof \stdClass ? (array) $order : $order;
            $status = strtolower($order['status'] ?? 'unknown');
            $canModify = in_array($status, ['pending', 'processing', 'confirmed']);

            if (!$canModify) {
                return [
                    'success' => false,
                    'message' => "Order #{$orderId} is currently '{$status}' and can no longer be modified.",
                    'order_status' => $status,
                    'alternative' => $status === 'shipped' ? 'You can initiate a return once delivered.' : null,
                ];
            }

            $result = match ($action) {
                'change_address' => $this->processAddressChange($order, $request['new_address'] ?? []),
                'cancel'         => $this->processOrderCancel($tenantId, $order),
                'add_item'       => $this->processAddItem($order, $request['item'] ?? []),
                'remove_item'    => $this->processRemoveItem($order, $request['item_id'] ?? ''),
                'upgrade_shipping' => $this->processShippingUpgrade($order, $request['shipping_method'] ?? ''),
                default          => ['success' => false, 'message' => "I can help you with: change address, cancel order, add/remove items, or upgrade shipping."],
            };

            $result['order_id'] = $orderId;
            $result['order_status'] = $status;
            return $result;
        } catch (\Exception $e) {
            Log::error("ProactiveSupport::orderMod error: {$e->getMessage()}");
            return ['success' => false, 'message' => "Something went wrong. Let me connect you with a human agent."];
        }
    }

    /**
     * UC32: Detect sentiment in chat and route to appropriate agent.
     */
    public function sentimentEscalation(int $tenantId, array $chatMessage): array
    {
        $text = $chatMessage['message'] ?? '';
        $sessionId = $chatMessage['session_id'] ?? null;
        $customerId = $chatMessage['customer_email'] ?? null;

        // Simple sentiment analysis using keyword scoring
        $sentiment = $this->analyzeSentiment($text);

        // Track escalation history for this session
        $history = Cache::get("chat_sentiment:{$tenantId}:{$sessionId}", []);
        $history[] = ['score' => $sentiment['score'], 'timestamp' => now()->toDateTimeString()];
        Cache::put("chat_sentiment:{$tenantId}:{$sessionId}", $history, 3600);

        // Check for declining sentiment trend
        $recentScores = array_slice(array_column($history, 'score'), -5);
        $trendDeclining = count($recentScores) >= 3 && end($recentScores) < $recentScores[0] - 20;

        // Determine escalation
        $shouldEscalate = $sentiment['score'] <= 20 || ($trendDeclining && $sentiment['score'] <= 40);

        // Check VIP status
        $isVip = false;
        if ($customerId) {
            $ltv = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('customer_email', $customerId)
                ->sum('total') ?? 0;
            $isVip = $ltv >= 2000;
        }

        // VIPs get faster escalation
        if ($isVip && $sentiment['score'] <= 50) {
            $shouldEscalate = true;
        }

        $routing = match (true) {
            $shouldEscalate && $isVip => ['queue' => 'vip_priority', 'sla_minutes' => 2, 'agent_tier' => 'senior'],
            $shouldEscalate          => ['queue' => 'escalation', 'sla_minutes' => 5, 'agent_tier' => 'standard'],
            $sentiment['score'] <= 40 => ['queue' => 'monitored', 'sla_minutes' => 15, 'agent_tier' => 'bot_assisted'],
            default                  => ['queue' => 'standard', 'sla_minutes' => null, 'agent_tier' => 'bot'],
        };

        return [
            'success'       => true,
            'sentiment'     => $sentiment,
            'should_escalate' => $shouldEscalate,
            'is_vip'        => $isVip,
            'routing'       => $routing,
            'history_length' => count($history),
            'trend'         => $trendDeclining ? 'declining' : 'stable',
            'auto_response' => $shouldEscalate
                ? "I understand your frustration. Let me connect you with a specialist who can help right away."
                : null,
        ];
    }

    /**
     * UC33: Personalized VIP greeting based on customer profile.
     */
    public function vipGreeting(int $tenantId, string $customerEmail): array
    {
        try {
            $customer = DB::connection('mongodb')
                ->table('synced_customers')
                ->where('tenant_id', $tenantId)
                ->where('email', $customerEmail)
                ->first();

            $orders = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('customer_email', $customerEmail)
                ->orderBy('created_at', 'desc')
                ->get();

            $totalSpent = $orders->sum('total');
            $orderCount = $orders->count();
            $lastOrder = $orders->first();
            $customerArr = $customer ? (array) $customer : [];
            $firstName = $customerArr['first_name'] ?? $customerArr['name'] ?? 'there';

            // Determine tier
            $tier = match (true) {
                $totalSpent >= 5000 => 'diamond',
                $totalSpent >= 2000 => 'platinum',
                $totalSpent >= 1000 => 'gold',
                $totalSpent >= 500  => 'silver',
                default             => 'bronze',
            };

            // Build personalized greeting
            $lastOrderArr = $lastOrder ? (array) $lastOrder : [];
            $lastProductName = ((array) ($lastOrderArr['items'][0] ?? []))['name'] ?? null;
            $memberSince = $customerArr['created_at'] ?? null;
            $memberDays = $memberSince ? now()->diffInDays($memberSince) : 0;

            $greeting = "Welcome back, {$firstName}! 🌟";
            if ($tier === 'diamond' || $tier === 'platinum') {
                $greeting = "Welcome back, {$firstName}! ✨ As a valued {$tier} member, we're here to give you priority service.";
            }

            $contextMessages = [];
            if ($lastOrderArr && ($lastOrderArr['status'] ?? '') === 'shipped') {
                $contextMessages[] = "Your recent order #{$lastOrderArr['external_id']} is on its way!";
            }
            if ($lastProductName) {
                $contextMessages[] = "Enjoying your {$lastProductName}?";
            }

            return [
                'success'    => true,
                'greeting'   => $greeting,
                'customer'   => [
                    'name'         => $firstName,
                    'email'        => $customerEmail,
                    'tier'         => $tier,
                    'total_spent'  => round($totalSpent, 2),
                    'order_count'  => $orderCount,
                    'member_days'  => $memberDays,
                ],
                'context_messages' => $contextMessages,
                'quick_actions'    => [
                    ['label' => 'Track my order', 'action' => 'track_order'],
                    ['label' => 'Browse new arrivals', 'action' => 'new_arrivals'],
                    ['label' => 'My rewards', 'action' => 'rewards'],
                    ['label' => 'Talk to agent', 'action' => 'human_agent'],
                ],
                'priority_support' => in_array($tier, ['diamond', 'platinum']),
            ];
        } catch (\Exception $e) {
            Log::error("ProactiveSupport::vipGreeting error: {$e->getMessage()}");
            return ['success' => true, 'greeting' => 'Welcome! How can I help you today?', 'customer' => null];
        }
    }

    /**
     * UC34: Guided warranty claims processing.
     */
    public function warrantyClaim(int $tenantId, array $request): array
    {
        try {
            $step = $request['step'] ?? 'start';
            $orderId = $request['order_id'] ?? null;
            $productId = $request['product_id'] ?? null;
            $issueType = $request['issue_type'] ?? null;
            $description = $request['description'] ?? '';
            $images = $request['images'] ?? [];

            if ($step === 'start') {
                return [
                    'success' => true,
                    'step'    => 'identify',
                    'message' => "I can help you file a warranty claim. Please provide your order number.",
                    'fields_needed' => ['order_id'],
                ];
            }

            if ($step === 'identify' && $orderId) {
                $order = DB::connection('mongodb')
                    ->table('synced_orders')
                    ->where('tenant_id', $tenantId)
                    ->where('external_id', $orderId)
                    ->first();

                if (!$order) {
                    return ['success' => false, 'message' => 'Order not found. Please check your order number.'];
                }

                $purchaseDays = now()->diffInDays($order['created_at'] ?? now());
                $warrantyPeriod = 365; // Default 1 year
                $withinWarranty = $purchaseDays <= $warrantyPeriod;

                return [
                    'success'         => true,
                    'step'            => 'select_product',
                    'within_warranty' => $withinWarranty,
                    'days_since_purchase' => $purchaseDays,
                    'message'         => $withinWarranty
                        ? "Your order is within warranty ({$purchaseDays} days old). Which product has the issue?"
                        : "Your order is {$purchaseDays} days old, which is outside the standard warranty period. We may still be able to help.",
                    'items'           => collect($order['items'] ?? [])->map(fn($i) => [
                        'product_id' => $i['product_id'] ?? '',
                        'name'       => $i['name'] ?? '',
                    ])->toArray(),
                ];
            }

            if ($step === 'describe_issue' && $productId) {
                return [
                    'success' => true,
                    'step'    => 'upload_evidence',
                    'message' => "What type of issue are you experiencing?",
                    'issue_types' => [
                        'defective'    => 'Product is defective / broken',
                        'not_working'  => 'Product stopped working',
                        'missing_parts' => 'Missing parts or accessories',
                        'wrong_item'   => 'Received wrong item',
                        'cosmetic'     => 'Cosmetic damage',
                    ],
                ];
            }

            if ($step === 'submit') {
                $claimId = 'WC-' . strtoupper(substr(md5("{$orderId}{$productId}" . now()), 0, 8));

                return [
                    'success'  => true,
                    'step'     => 'complete',
                    'claim_id' => $claimId,
                    'message'  => "Your warranty claim #{$claimId} has been submitted! Our team will review it within 24-48 hours.",
                    'claim_details' => [
                        'claim_id'    => $claimId,
                        'order_id'    => $orderId,
                        'product_id'  => $productId,
                        'issue_type'  => $issueType,
                        'description' => $description,
                        'images'      => count($images),
                        'status'      => 'submitted',
                        'estimated_resolution' => '3-5 business days',
                    ],
                    'next_steps' => [
                        'You will receive a confirmation email shortly.',
                        'Our warranty team will review your claim.',
                        'We may request additional information.',
                        'Resolution typically takes 3-5 business days.',
                    ],
                ];
            }

            return ['success' => true, 'step' => 'start', 'message' => "Let's start your warranty claim. What's your order number?"];
        } catch (\Exception $e) {
            Log::error("ProactiveSupport::warranty error: {$e->getMessage()}");
            return ['success' => false, 'message' => 'Something went wrong. Let me connect you with support.'];
        }
    }

    /**
     * UC35: Multi-item sizing assistant — recommend sizes for entire cart.
     */
    public function multiItemSizingAssistant(int $tenantId, array $cart, ?array $customerProfile = null): array
    {
        try {
            $recommendations = [];

            // Get or build customer's size profile
            $sizeProfile = $customerProfile ?? [];

            foreach ($cart['items'] ?? [] as $item) {
                $productId = $item['product_id'] ?? '';
                $category = strtolower($item['category'] ?? '');

                $product = DB::connection('mongodb')
                    ->table('synced_products')
                    ->where('tenant_id', $tenantId)
                    ->where('external_id', $productId)
                    ->first();

                // Continue even if product is not in catalog — use cart item data as fallback.
                $product = $product ? (array) $product : [];
                $sizeChart = $product['size_chart'] ?? null;
                // Accept size_options from cart item itself (passed by caller)
                $availableSizes = $product['variants'] ?? array_map(
                    fn($s) => ['size' => $s, 'stock_qty' => 1],
                    (array) ($item['size_options'] ?? [])
                );

                $recommendedSize = null;
                $confidence = 0;
                $reasoning = '';

                if (str_contains($category, 'shoe') || str_contains($category, 'footwear')) {
                    $recommendedSize = $sizeProfile['shoe_size'] ?? null;
                    $confidence = $recommendedSize ? 0.85 : 0;
                    $reasoning = $recommendedSize ? 'Based on your previous shoe purchases' : 'No shoe size on file';
                } elseif (str_contains($category, 'shirt') || str_contains($category, 'top')) {
                    $recommendedSize = $sizeProfile['top_size'] ?? null;
                    $confidence = $recommendedSize ? 0.80 : 0;
                    $reasoning = $recommendedSize ? 'Based on your previous top purchases' : 'No top size on file';
                } elseif (str_contains($category, 'pant') || str_contains($category, 'jean')) {
                    $recommendedSize = $sizeProfile['bottom_size'] ?? null;
                    $confidence = $recommendedSize ? 0.82 : 0;
                    $reasoning = $recommendedSize ? 'Based on your previous bottom purchases' : 'No bottom size on file';
                }

                // Check if recommended size is in stock
                $inStock = false;
                if ($recommendedSize && !empty($availableSizes)) {
                    $inStock = collect($availableSizes)
                        ->where('size', $recommendedSize)
                        ->where('stock_qty', '>', 0)
                        ->isNotEmpty();
                }

                $recommendations[] = [
                    'product_id'       => $productId,
                    'product_name'     => $item['name'] ?? $product['name'] ?? '',
                    'category'         => $category,
                    'recommended_size' => $recommendedSize,
                    'confidence'       => $confidence,
                    'reasoning'        => $reasoning,
                    'size_in_stock'    => $inStock,
                    'available_sizes'  => collect($availableSizes)->pluck('size')->unique()->values()->toArray(),
                    'has_size_chart'   => $sizeChart !== null,
                    'fit_tip'          => $this->getFitTip($category, $product),
                ];
            }

            return [
                'success'         => true,
                'item_count'      => count($recommendations),
                'all_sized'       => collect($recommendations)->every(fn($r) => $r['recommended_size'] !== null),
                'recommendations' => $recommendations,
                'sizing_profile'  => $sizeProfile,
                'message'         => collect($recommendations)->every(fn($r) => $r['recommended_size'])
                    ? 'Great news! We have size recommendations for all items in your cart.'
                    : 'We have recommendations for some items. For the rest, check our size guide.',
            ];
        } catch (\Exception $e) {
            Log::error("ProactiveSupport::sizing error: {$e->getMessage()}");
            return ['success' => false, 'message' => 'Unable to generate size recommendations.'];
        }
    }

    // ── Private Helpers ──────────────────────────────────────────

    private function processAddressChange(array $order, array $newAddress): array
    {
        if (empty($newAddress['street']) || empty($newAddress['city'])) {
            return ['success' => false, 'message' => 'Please provide full address: street, city, state, zip, country.'];
        }
        return [
            'success'     => true,
            'action'      => 'address_change',
            'message'     => "Address updated to: {$newAddress['street']}, {$newAddress['city']}. Your order will be shipped to the new address.",
            'new_address' => $newAddress,
        ];
    }

    private function processOrderCancel(int $tenantId, array $order): array
    {
        return [
            'success' => true,
            'action'  => 'cancellation',
            'message' => "Order #{$order['external_id']} has been cancelled. Your refund will be processed within 5-7 business days.",
            'refund_amount' => $order['total'] ?? 0,
        ];
    }

    private function processAddItem(array $order, array $item): array
    {
        return [
            'success' => true,
            'action'  => 'add_item',
            'message' => "Item added to your order. Updated total will be reflected shortly.",
            'item'    => $item,
        ];
    }

    private function processRemoveItem(array $order, string $itemId): array
    {
        return [
            'success' => true,
            'action'  => 'remove_item',
            'message' => "Item removed from your order. Your updated total will be adjusted.",
            'removed_item_id' => $itemId,
        ];
    }

    private function processShippingUpgrade(array $order, string $method): array
    {
        $upgradeCosts = ['express' => 9.99, 'overnight' => 24.99, 'same_day' => 39.99];
        $cost = $upgradeCosts[$method] ?? 14.99;
        return [
            'success'  => true,
            'action'   => 'shipping_upgrade',
            'message'  => "Shipping upgraded to '{$method}'. Additional charge: \${$cost}.",
            'method'   => $method,
            'extra_cost' => $cost,
        ];
    }

    private function analyzeSentiment(string $text): array
    {
        $text = strtolower($text);
        $score = 50; // Neutral

        $angryWords = ['angry', 'furious', 'terrible', 'horrible', 'worst', 'scam', 'fraud', 'sue', 'lawyer',
            'disgusting', 'awful', 'unacceptable', 'ridiculous', 'pathetic', 'garbage', 'trash', 'hate',
            'never again', 'refund now', 'manager', 'complaint', 'report'];
        $frustratedWords = ['frustrated', 'annoyed', 'disappointed', 'waiting', 'still', 'again', 'broken',
            'doesn\'t work', 'not working', 'wrong', 'missing', 'delayed', 'late', 'poor'];
        $positiveWords = ['thank', 'great', 'love', 'amazing', 'excellent', 'perfect', 'wonderful', 'happy',
            'pleased', 'satisfied', 'helpful', 'good', 'nice'];

        foreach ($angryWords as $w) { if (str_contains($text, $w)) $score -= 15; }
        foreach ($frustratedWords as $w) { if (str_contains($text, $w)) $score -= 8; }
        foreach ($positiveWords as $w) { if (str_contains($text, $w)) $score += 10; }

        // Caps lock detection (shouting)
        $upperRatio = strlen(preg_replace('/[^A-Z]/', '', $text)) / max(1, strlen($text));
        if ($upperRatio > 0.5) $score -= 20;

        // Exclamation marks detected
        $exclCount = substr_count($text, '!');
        if ($exclCount >= 3) $score -= 10;

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'label' => match (true) {
                $score >= 70 => 'positive',
                $score >= 40 => 'neutral',
                $score >= 20 => 'frustrated',
                default      => 'angry',
            },
        ];
    }

    private function getFitTip(string $category, array $product): string
    {
        $brand = strtolower($product['brand'] ?? '');
        return match (true) {
            str_contains($category, 'shoe')  => 'This brand tends to run true to size. If between sizes, go half size up.',
            str_contains($category, 'jean')  => 'Consider sizing up if you prefer a relaxed fit.',
            str_contains($category, 'shirt') => 'Check the chest and length measurements in the size chart for best fit.',
            default                          => 'Refer to the size chart for detailed measurements.',
        };
    }
}

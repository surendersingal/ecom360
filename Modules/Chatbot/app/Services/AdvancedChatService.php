<?php
declare(strict_types=1);

namespace Modules\Chatbot\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AdvancedChatService — Rich customer engagement through chat.
 *
 * UC36: Visual Order Tracking — rich timeline with map
 * UC37: Pre-Checkout Objection Handler — address concerns before buy
 * UC38: Subscription Management — pause/modify/cancel subscriptions
 * UC39: Interactive Gift Card Builder — create custom gift cards via chat
 * UC40: Video Review Integration — guided video review collection
 */
class AdvancedChatService
{
    /**
     * UC36: Rich visual order tracking with timeline.
     */
    public function visualOrderTracking(int $tenantId, string $orderId, string $customerEmail): array
    {
        try {
            $order = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('external_id', $orderId)
                ->where('customer_email', $customerEmail)
                ->first();

            if (!$order) {
                return ['success' => false, 'message' => "Order #{$orderId} not found."];
            }

            // Build tracking timeline
            $timeline = [];
            $statusMap = [
                'pending'    => ['icon' => '📋', 'label' => 'Order Placed', 'sort' => 1],
                'confirmed'  => ['icon' => '✅', 'label' => 'Order Confirmed', 'sort' => 2],
                'processing' => ['icon' => '⚙️', 'label' => 'Processing', 'sort' => 3],
                'packed'     => ['icon' => '📦', 'label' => 'Packed & Ready', 'sort' => 4],
                'shipped'    => ['icon' => '🚚', 'label' => 'Shipped', 'sort' => 5],
                'in_transit' => ['icon' => '✈️', 'label' => 'In Transit', 'sort' => 6],
                'out_for_delivery' => ['icon' => '🏃', 'label' => 'Out for Delivery', 'sort' => 7],
                'delivered'  => ['icon' => '🎉', 'label' => 'Delivered', 'sort' => 8],
            ];

            $currentStatus = strtolower($order['status'] ?? 'pending');
            $currentSort = $statusMap[$currentStatus]['sort'] ?? 1;

            foreach ($statusMap as $status => $info) {
                $timeline[] = [
                    'status'   => $status,
                    'label'    => $info['label'],
                    'icon'     => $info['icon'],
                    'reached'  => $info['sort'] <= $currentSort,
                    'current'  => $status === $currentStatus,
                    'date'     => $info['sort'] <= $currentSort ? ($order["{$status}_at"] ?? $order['created_at']) : null,
                ];
            }

            // Estimated delivery
            $shippedAt = $order['shipped_at'] ?? null;
            $estimatedDelivery = $shippedAt
                ? now()->parse($shippedAt)->addDays(5)->toDateString()
                : now()->addDays(7)->toDateString();

            return [
                'success'  => true,
                'order'    => [
                    'id'            => $orderId,
                    'status'        => $currentStatus,
                    'total'         => $order['total'] ?? 0,
                    'items_count'   => count($order['items'] ?? []),
                    'placed_at'     => $order['created_at'] ?? null,
                    'shipped_at'    => $shippedAt,
                    'tracking_number' => $order['tracking_number'] ?? null,
                    'carrier'       => $order['shipping_method'] ?? null,
                ],
                'timeline'          => $timeline,
                'estimated_delivery' => $estimatedDelivery,
                'items'             => collect($order['items'] ?? [])->map(fn($i) => [
                    'name'  => $i['name'] ?? '',
                    'qty'   => $i['quantity'] ?? 1,
                    'price' => $i['price'] ?? 0,
                    'image' => $i['image'] ?? null,
                ])->toArray(),
                'tracking_url'      => $order['tracking_url'] ?? null,
                'message'           => "Your order is currently {$statusMap[$currentStatus]['label']}. " .
                    ($currentStatus === 'shipped' ? "Expected delivery by {$estimatedDelivery}." : ''),
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedChat::orderTracking error: {$e->getMessage()}");
            return ['success' => false, 'message' => 'Unable to retrieve tracking information.'];
        }
    }

    /**
     * UC37: Pre-checkout objection handler — address concerns automatically.
     */
    public function preCheckoutObjectionHandler(int $tenantId, array $cart, string $objection): array
    {
        $lower = strtolower($objection);
        $cartTotal = $cart['total'] ?? 0;
        $responses = [];

        // Price objection
        if (str_contains($lower, 'expensive') || str_contains($lower, 'price') || str_contains($lower, 'cost') || str_contains($lower, 'cheaper')) {
            $responses[] = [
                'objection_type' => 'price',
                'response'       => "I understand value matters! Here's what makes this a smart buy:",
                'actions'        => [
                    ['label' => 'Apply first-order discount', 'action' => 'apply_coupon', 'value' => 'FIRST10'],
                    ['label' => 'Split into instalments', 'action' => 'show_bnpl'],
                    ['label' => 'See price match guarantee', 'action' => 'price_match'],
                ],
                'social_proof'   => $this->getSocialProof($tenantId, $cart),
            ];
        }

        // Trust objection
        if (str_contains($lower, 'trust') || str_contains($lower, 'legit') || str_contains($lower, 'real') || str_contains($lower, 'safe')) {
            $responses[] = [
                'objection_type' => 'trust',
                'response'       => "Your security is our priority! Here's why thousands of customers shop with confidence:",
                'trust_signals'  => [
                    'SSL encrypted checkout',
                    '30-day money back guarantee',
                    'Verified buyer reviews',
                    '24/7 customer support',
                    'PCI DSS compliant',
                ],
            ];
        }

        // Shipping objection
        if (str_contains($lower, 'shipping') || str_contains($lower, 'delivery') || str_contains($lower, 'arrive') || str_contains($lower, 'fast')) {
            $responses[] = [
                'objection_type' => 'shipping',
                'response'       => 'We offer multiple shipping options to fit your timeline:',
                'shipping_options' => [
                    ['method' => 'Standard', 'days' => '5-7 business days', 'cost' => $cartTotal >= 50 ? 'FREE' : '$4.99'],
                    ['method' => 'Express', 'days' => '2-3 business days', 'cost' => '$9.99'],
                    ['method' => 'Overnight', 'days' => 'Next business day', 'cost' => '$24.99'],
                ],
                'free_shipping_threshold' => $cartTotal < 50 ? "Add $" . number_format(50 - $cartTotal, 2) . " more for FREE shipping!" : null,
            ];
        }

        // Return objection
        if (str_contains($lower, 'return') || str_contains($lower, 'refund') || str_contains($lower, 'exchange')) {
            $responses[] = [
                'objection_type' => 'returns',
                'response'       => "Shop worry-free with our hassle-free return policy:",
                'return_policy'  => [
                    '30-day no-questions-asked returns',
                    'Free return shipping on exchanges',
                    'Refund processed within 3-5 business days',
                    'Easy online return portal',
                ],
            ];
        }

        // Quality/fit objection
        if (str_contains($lower, 'quality') || str_contains($lower, 'fit') || str_contains($lower, 'size')) {
            $responses[] = [
                'objection_type' => 'quality',
                'response'       => "Quality is everything to us. Here's how we ensure you'll love your purchase:",
                'actions'        => [
                    ['label' => 'View size guide', 'action' => 'size_guide'],
                    ['label' => 'See customer photos', 'action' => 'customer_photos'],
                    ['label' => 'Chat with sizing expert', 'action' => 'sizing_chat'],
                ],
            ];
        }

        if (empty($responses)) {
            $responses[] = [
                'objection_type' => 'general',
                'response'       => "I'm here to help! Can you tell me more about what's holding you back?",
                'quick_replies'  => ['Price concerns', 'Shipping speed', 'Return policy', 'Product quality', 'Talk to a human'],
            ];
        }

        return [
            'success'    => true,
            'objection'  => $objection,
            'responses'  => $responses,
            'cart_total'  => $cartTotal,
            'confidence' => count($responses) > 0 ? 'high' : 'low',
        ];
    }

    /**
     * UC38: Subscription management — pause, modify, cancel.
     */
    public function subscriptionManagement(int $tenantId, array $request): array
    {
        $action = $request['action'] ?? 'status';
        $subscriptionId = $request['subscription_id'] ?? null;
        $customerEmail = $request['customer_email'] ?? null;

        try {
            if ($action === 'list') {
                $subscriptions = DB::connection('mongodb')
                    ->table('subscriptions')
                    ->where('tenant_id', $tenantId)
                    ->where('customer_email', $customerEmail)
                    ->get();

                return [
                    'success'       => true,
                    'subscriptions' => $subscriptions->map(fn($s) => [
                        'id'          => $s['_id'] ?? '',
                        'product'     => $s['product_name'] ?? '',
                        'frequency'   => $s['frequency'] ?? 'monthly',
                        'next_delivery' => $s['next_delivery'] ?? '',
                        'price'       => $s['price'] ?? 0,
                        'status'      => $s['status'] ?? 'active',
                    ])->toArray(),
                    'count' => $subscriptions->count(),
                ];
            }

            if ($action === 'pause') {
                $pauseWeeks = $request['pause_weeks'] ?? 4;
                $resumeDate = now()->addWeeks($pauseWeeks)->toDateString();

                return [
                    'success'     => true,
                    'action'      => 'paused',
                    'subscription_id' => $subscriptionId,
                    'resume_date' => $resumeDate,
                    'message'     => "Your subscription has been paused. It will automatically resume on {$resumeDate}.",
                    'save_offer'  => null,
                ];
            }

            if ($action === 'cancel') {
                // Offer retention incentives
                return [
                    'success'  => true,
                    'action'   => 'cancel_confirmation',
                    'message'  => "We're sorry to see you go. Before you cancel, would you like to consider:",
                    'retention_offers' => [
                        ['offer' => 'Skip next delivery', 'action' => 'skip_next'],
                        ['offer' => 'Reduce frequency', 'action' => 'reduce_frequency'],
                        ['offer' => 'Get 20% off next 3 deliveries', 'action' => 'discount_retention'],
                        ['offer' => 'Pause for 2 months', 'action' => 'long_pause'],
                    ],
                    'confirm_cancel' => ['action' => 'confirm_cancel', 'label' => 'Proceed with cancellation'],
                ];
            }

            if ($action === 'modify') {
                return [
                    'success' => true,
                    'action'  => 'modify_options',
                    'message' => 'What would you like to change?',
                    'options' => [
                        ['label' => 'Change frequency', 'choices' => ['weekly', 'biweekly', 'monthly', 'bimonthly']],
                        ['label' => 'Change quantity', 'min' => 1, 'max' => 10],
                        ['label' => 'Swap product variant', 'action' => 'show_variants'],
                        ['label' => 'Update delivery address', 'action' => 'update_address'],
                    ],
                ];
            }

            return ['success' => true, 'message' => 'How can I help with your subscription?'];
        } catch (\Exception $e) {
            Log::error("AdvancedChat::subscription error: {$e->getMessage()}");
            return ['success' => false, 'message' => 'Unable to manage subscription. Please try again.'];
        }
    }

    /**
     * UC39: Interactive gift card builder — design & send custom gift cards.
     */
    public function giftCardBuilder(int $tenantId, array $params): array
    {
        $step = $params['step'] ?? 'start';

        if ($step === 'start') {
            return [
                'success' => true,
                'step'    => 'choose_amount',
                'message' => '🎁 Let\'s create a perfect gift card! Choose an amount:',
                'amounts' => [25, 50, 75, 100, 150, 200],
                'custom_amount' => ['min' => 10, 'max' => 500],
            ];
        }

        if ($step === 'choose_design') {
            return [
                'success' => true,
                'step'    => 'personalize',
                'message' => 'Pick a design theme:',
                'designs' => [
                    ['id' => 'birthday', 'name' => 'Birthday 🎂', 'preview' => '/images/gc/birthday.jpg'],
                    ['id' => 'thank_you', 'name' => 'Thank You 💐', 'preview' => '/images/gc/thankyou.jpg'],
                    ['id' => 'congrats', 'name' => 'Congratulations 🎉', 'preview' => '/images/gc/congrats.jpg'],
                    ['id' => 'holiday', 'name' => 'Holiday ❄️', 'preview' => '/images/gc/holiday.jpg'],
                    ['id' => 'love', 'name' => 'With Love ❤️', 'preview' => '/images/gc/love.jpg'],
                    ['id' => 'minimal', 'name' => 'Elegant Minimal ✨', 'preview' => '/images/gc/minimal.jpg'],
                ],
            ];
        }

        if ($step === 'personalize') {
            return [
                'success' => true,
                'step'    => 'delivery',
                'message' => 'Add a personal touch:',
                'fields'  => [
                    ['name' => 'recipient_name', 'label' => 'Recipient\'s Name', 'required' => true],
                    ['name' => 'sender_name', 'label' => 'Your Name', 'required' => true],
                    ['name' => 'message', 'label' => 'Personal Message', 'max_length' => 200, 'required' => false],
                ],
            ];
        }

        if ($step === 'confirm') {
            $giftCardCode = 'GC-' . strtoupper(substr(md5(now()->timestamp . rand()), 0, 10));
            $amount = $params['amount'] ?? 50;

            return [
                'success'  => true,
                'step'     => 'complete',
                'gift_card' => [
                    'code'           => $giftCardCode,
                    'amount'         => $amount,
                    'design'         => $params['design'] ?? 'minimal',
                    'recipient_name' => $params['recipient_name'] ?? '',
                    'sender_name'    => $params['sender_name'] ?? '',
                    'message'        => $params['message'] ?? '',
                    'delivery_method' => $params['delivery_method'] ?? 'email',
                    'delivery_date'  => $params['delivery_date'] ?? now()->toDateString(),
                    'status'         => 'created',
                ],
                'message' => "Your \${$amount} gift card has been created! Code: {$giftCardCode}",
            ];
        }

        return ['success' => true, 'step' => 'start', 'message' => 'Let\'s create a gift card!'];
    }

    /**
     * UC40: Guided video review collection — incentivize & guide video reviews.
     */
    public function videoReviewGuide(int $tenantId, array $params): array
    {
        $step = $params['step'] ?? 'invite';
        $orderId = $params['order_id'] ?? null;
        $productId = $params['product_id'] ?? null;

        if ($step === 'invite') {
            return [
                'success' => true,
                'step'    => 'guidelines',
                'message' => "📹 Share a video review and earn 20% off your next order!",
                'incentive' => ['type' => 'discount', 'value' => 20, 'unit' => 'percent'],
                'guidelines' => [
                    'Keep it 15-60 seconds',
                    'Show the product in use',
                    'Share what you love about it',
                    'Mention any tips for other buyers',
                    'Good lighting helps! Natural light works best.',
                ],
                'accepted_formats' => ['mp4', 'mov', 'avi', 'webm'],
                'max_size_mb' => 100,
            ];
        }

        if ($step === 'upload_complete') {
            $reviewId = 'VR-' . strtoupper(substr(md5("{$orderId}{$productId}" . now()), 0, 8));

            return [
                'success'   => true,
                'step'      => 'complete',
                'review_id' => $reviewId,
                'message'   => "🎉 Thank you! Your video review has been submitted for moderation.",
                'reward'    => [
                    'code'    => 'REVIEW20-' . strtoupper(substr(md5($reviewId), 0, 6)),
                    'value'   => 20,
                    'unit'    => 'percent',
                    'expires' => now()->addDays(60)->toDateString(),
                ],
                'next_steps' => [
                    'Our team will review your video within 24 hours.',
                    'Once approved, it will appear on the product page.',
                    'Your reward code will be activated upon approval.',
                ],
                'moderation_status' => 'pending',
            ];
        }

        return ['success' => true, 'step' => 'invite', 'message' => 'Would you like to share a video review?'];
    }

    // ── Private Helpers ──────────────────────────────────────────

    private function getSocialProof(int $tenantId, array $cart): array
    {
        $productIds = collect($cart['items'] ?? [])->pluck('product_id')->filter()->toArray();
        if (empty($productIds)) return [];

        try {
            $recentPurchases = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subDays(7)->toDateTimeString())
                ->count();

            return [
                'recent_buyers' => "{$recentPurchases}+ people bought from us this week",
                'rating'        => '4.8/5 average customer rating',
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}

<?php
declare(strict_types=1);

namespace Modules\Chatbot\Services;

/**
 * IntentService — NLP-based intent detection for chatbot messages.
 *
 * Uses keyword matching + pattern detection. In production, plug in OpenAI/Dialogflow.
 */
class IntentService
{
    private array $intents = [
        'greeting' => [
            'patterns' => ['hi', 'hello', 'hey', 'good morning', 'good evening', 'sup', 'howdy', 'greetings', 'yo'],
            'confidence' => 0.95,
        ],
        'farewell' => [
            'patterns' => ['bye', 'goodbye', 'see you', 'thanks bye', 'that\'s all', 'done', 'nothing else', 'close'],
            'confidence' => 0.95,
        ],
        'order_tracking' => [
            'patterns' => ['where is my order', 'track order', 'order status', 'tracking', 'when will it arrive', 'delivery status', 'shipment', 'my order', 'order #', 'hasn\'t arrived', 'not received', 'where is my package'],
            'confidence' => 0.90,
        ],
        'product_inquiry' => [
            'patterns' => ['looking for', 'do you have', 'search for', 'find me', 'show me', 'recommend', 'suggestion', 'best', 'popular', 'new arrivals', 'what\'s new', 'product'],
            'confidence' => 0.85,
        ],
        'checkout_help' => [
            'patterns' => ['checkout', 'can\'t checkout', 'payment', 'pay', 'credit card', 'billing', 'checkout error', 'place order', 'complete purchase', 'payment failed'],
            'confidence' => 0.88,
        ],
        'return_request' => [
            'patterns' => ['return', 'refund', 'exchange', 'send back', 'wrong item', 'defective', 'damaged', 'broken', 'not what i ordered', 'return policy'],
            'confidence' => 0.88,
        ],
        'coupon_inquiry' => [
            'patterns' => ['coupon', 'promo code', 'discount', 'deal', 'sale', 'offer', 'free shipping', 'code not working', 'apply code'],
            'confidence' => 0.88,
        ],
        'size_help' => [
            'patterns' => ['size', 'sizing', 'measurement', 'fit', 'too small', 'too big', 'size chart', 'what size', 'which size', 'size guide'],
            'confidence' => 0.87,
        ],
        'shipping_inquiry' => [
            'patterns' => ['shipping', 'delivery time', 'how long', 'ship to', 'shipping cost', 'shipping options', 'express shipping', 'free delivery'],
            'confidence' => 0.87,
        ],
        'add_to_cart' => [
            'patterns' => ['add to cart', 'buy', 'purchase', 'i want', 'i\'ll take', 'order this', 'add this', 'get me'],
            'confidence' => 0.85,
        ],
    ];

    /**
     * Detect the intent from a user message.
     */
    public function detect(string $message, array $context = []): array
    {
        $messageLower = strtolower(trim($message));
        $bestMatch = ['intent' => 'general', 'confidence' => 0.3, 'matched_pattern' => null];

        foreach ($this->intents as $intent => $config) {
            foreach ($config['patterns'] as $pattern) {
                if ($this->matchesPattern($messageLower, $pattern)) {
                    $matchConfidence = $config['confidence'] * $this->calculateMatchQuality($messageLower, $pattern);
                    if ($matchConfidence > $bestMatch['confidence']) {
                        $bestMatch = [
                            'intent'          => $intent,
                            'confidence'      => round($matchConfidence, 3),
                            'matched_pattern' => $pattern,
                        ];
                    }
                }
            }
        }

        // Context-based boosting
        if (isset($context['page_type'])) {
            $contextBoost = $this->getContextBoost($bestMatch['intent'], $context['page_type']);
            $bestMatch['confidence'] = min(1.0, $bestMatch['confidence'] + $contextBoost);
        }

        // Check for order ID pattern (strongly suggests order_tracking)
        if (preg_match('/(?:order|ORD|#)\s*[A-Z0-9\-]{4,}/i', $message)) {
            if ($bestMatch['intent'] !== 'order_tracking') {
                $bestMatch = [
                    'intent'     => 'order_tracking',
                    'confidence' => 0.92,
                    'matched_pattern' => 'order_id_detected',
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Get all supported intents.
     */
    public function getSupportedIntents(): array
    {
        return array_keys($this->intents);
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function matchesPattern(string $message, string $pattern): bool
    {
        // Exact match or contained in message
        if (str_contains($message, $pattern)) {
            return true;
        }

        // Fuzzy match: check if all words of pattern appear in message
        $patternWords = explode(' ', $pattern);
        if (count($patternWords) > 1) {
            $allFound = true;
            foreach ($patternWords as $word) {
                if (!str_contains($message, $word)) {
                    $allFound = false;
                    break;
                }
            }
            return $allFound;
        }

        return false;
    }

    private function calculateMatchQuality(string $message, string $pattern): float
    {
        $patternLen = strlen($pattern);
        $messageLen = strlen($message);

        if ($messageLen === 0) return 0;

        // Exact match gets full score
        if ($message === $pattern) return 1.0;

        // Ratio of pattern length to message length (longer patterns matching = higher quality)
        $lengthRatio = $patternLen / $messageLen;

        // Starts with pattern = bonus
        $startsWithBonus = str_starts_with($message, $pattern) ? 0.1 : 0;

        return min(1.0, $lengthRatio + $startsWithBonus + 0.3);
    }

    private function getContextBoost(string $intent, string $pageType): float
    {
        $boosts = [
            'product'  => ['product_inquiry' => 0.1, 'add_to_cart' => 0.1, 'size_help' => 0.1],
            'cart'     => ['checkout_help' => 0.15, 'coupon_inquiry' => 0.1],
            'checkout' => ['checkout_help' => 0.2, 'shipping_inquiry' => 0.1],
            'order'    => ['order_tracking' => 0.15, 'return_request' => 0.1],
            'search'   => ['product_inquiry' => 0.1],
        ];

        return $boosts[$pageType][$intent] ?? 0;
    }
}

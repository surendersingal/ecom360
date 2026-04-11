<?php
declare(strict_types=1);

namespace Modules\Chatbot\Services;

/**
 * IntentService — NLP-based intent detection for chatbot messages.
 *
 * Uses keyword matching + pattern detection + sentiment analysis.
 * Supports 18 intents covering all customer interaction scenarios.
 */
class IntentService
{
    private array $intents = [
        'greeting' => [
            'patterns'   => ['hi', 'hello', 'hey', 'good morning', 'good evening', 'sup', 'howdy', 'greetings', 'yo', 'hi there', 'good afternoon'],
            'confidence' => 0.95,
            'priority'   => 10,
        ],
        'farewell' => [
            'patterns'   => ['bye', 'goodbye', 'see you', 'thanks bye', 'that\'s all', 'done', 'nothing else', 'close', 'end chat', 'have a nice day'],
            'confidence' => 0.95,
            'priority'   => 10,
        ],
        'escalation' => [
            'patterns'   => [
                'i want to talk to a human', 'i want to speak to a human', 'want to talk to a human',
                'talk to a human', 'human agent', 'real person', 'speak to someone',
                'speak to agent', 'live agent', 'live chat', 'connect me to agent',
                'transfer to agent', 'talk to manager', 'supervisor', 'representative',
                'customer service', 'need a human', 'real human', 'operator',
                'speak with a person', 'get me a person', 'escalate', 'agent please',
                'can i talk to someone', 'this bot is useless', 'i need help from a person',
            ],
            'confidence' => 0.95,
            'priority'   => 1, // Highest priority — never overridden
        ],
        'help' => [
            'patterns'   => [
                'help me please', 'help me', 'i need help', 'can you help', 'assist me',
                'need assistance', 'please help', 'help needed', 'need support',
                'can someone help', 'i need assistance',
            ],
            'confidence' => 0.88,
            'priority'   => 4,
        ],
        'store_hours' => [
            'patterns'   => [
                'what time is your store open', 'store hours', 'opening hours', 'closing time',
                'when do you open', 'when are you open', 'store open', 'are you open',
                'what time do you open', 'what time do you close', 'business hours',
                'what time is the store', 'when is the store open',
            ],
            'confidence' => 0.90,
            'priority'   => 4,
        ],
        'comparison' => [
            'patterns'   => [
                'compare', 'vs', 'versus', 'difference between', 'better than',
                'which is better', 'side by side', 'compare products', 'which one is better',
                'pros and cons', 'which should i choose', 'which one should i buy',
            ],
            'confidence' => 0.90,
            'priority'   => 5,
        ],
        'recommendation' => [
            'patterns'   => [
                'recommend a gift', 'gift for my', 'gift for him', 'gift for her',
                'recommend something', 'what do you recommend', 'birthday gift', 'anniversary gift',
                'gift idea', 'present for', 'surprise gift', 'perfect gift',
                'what should i buy', 'suggestion for gift', 'need a gift',
            ],
            'confidence' => 0.90,
            'priority'   => 5,
        ],
        'complaint' => [
            'patterns'   => [
                'complaint', 'complain', 'frustrated', 'angry', 'terrible', 'worst',
                'horrible', 'disgusting', 'unacceptable', 'ridiculous', 'pathetic',
                'scam', 'fraud', 'rip off', 'waste of money', 'never again',
                'very disappointed', 'extremely disappointed', 'i am furious',
                'this is terrible', 'worst experience', 'file a complaint',
                'not acceptable', 'very unhappy', 'totally unacceptable',
                'i want to complain', 'make a complaint', 'report issue',
            ],
            'confidence' => 0.92,
            'priority'   => 2,
        ],
        'return_policy' => [
            'patterns'   => [
                'return policy', 'refund policy', 'exchange policy', 'what is your return',
                'how do i return', 'can i return', 'return window', 'days to return',
                'return process', 'how does return work',
            ],
            'confidence' => 0.92,
            'priority'   => 3,
        ],
        'return_request' => [
            'patterns'   => [
                'return', 'refund', 'exchange', 'send back', 'wrong item', 'defective',
                'damaged', 'broken', 'not what i ordered', 'want my money back',
                'item is broken', 'received wrong', 'want to return', 'initiate return',
                'start a return', 'return this', 'money back', 'i want a refund',
            ],
            'confidence' => 0.90,
            'priority'   => 3,
        ],
        'order_tracking' => [
            'patterns'   => [
                'where is my order', 'track order', 'order status', 'tracking',
                'when will it arrive', 'delivery status', 'shipment', 'my order',
                'order #', 'hasn\'t arrived', 'not received', 'where is my package',
                'track my package', 'delivery date', 'shipping update', 'when will i get',
            ],
            'confidence' => 0.90,
            'priority'   => 5,
        ],
        'account_help' => [
            'patterns'   => [
                'my account', 'account settings', 'change password', 'reset password',
                'forgot password', 'can\'t login', 'can\'t log in', 'login problem',
                'sign up', 'create account', 'register', 'delete my account',
                'update profile', 'change email', 'account details', 'my profile',
                'account locked', 'unlock account', 'deactivate account', 'account info',
                'update my information', 'change my details',
            ],
            'confidence' => 0.88,
            'priority'   => 5,
        ],
        'payment_info' => [
            'patterns'   => [
                'payment method', 'payment options', 'credit card', 'debit card',
                'bank transfer', 'payment failed', 'payment declined', 'billing',
                'invoice', 'receipt', 'payment issue', 'charge', 'double charged',
                'refund status', 'when will i get refund', 'payment confirmation',
                'accepted payment', 'pay with', 'apple pay', 'google pay', 'paypal',
                'installment', 'emi', 'buy now pay later',
            ],
            'confidence' => 0.88,
            'priority'   => 5,
        ],
        'product_search' => [
            'patterns'   => [
                'show me whiskey', 'show me vodka', 'show me rum', 'show me gin',
                'find whiskey', 'find vodka', 'search whiskey', 'under 5000',
                'under 1000', 'under 2000', 'under 3000', 'under 500',
                'below 5000', 'below 1000', 'below 2000', 'below 3000',
                'products under', 'items under', 'bottles under', 'price range',
                'under ₹', 'under rs', 'budget under', 'within budget',
                'show me products', 'find products', 'search products',
                'find me some', 'find me a', 'show me a',
            ],
            'confidence' => 0.90,
            'priority'   => 7,
        ],
        'product_inquiry' => [
            'patterns'   => [
                'looking for', 'do you carry', 'search for', 'best', 'popular', 'new arrivals',
                'what\'s new', 'product', 'price', 'cost', 'how much',
                'under', 'below', 'above', 'cheap', 'expensive', 'budget',
                // ── Brand keywords (auto-detect as product inquiry) ──
                'chivas', 'johnnie walker', 'jack daniels', 'jameson', 'singleton',
                'glenfiddich', 'glenlivet', 'macallan', 'hennessy', 'remy martin',
                'absolut', 'smirnoff', 'grey goose', 'belvedere', 'ciroc',
                'bacardi', 'captain morgan', 'havana club', 'baileys', 'kahlua',
                'moet', 'veuve clicquot', 'dom perignon', 'patron', 'jose cuervo',
                'jagermeister', 'bombay sapphire', 'tanqueray', 'hendricks', 'beefeater',
                'budweiser', 'heineken', 'corona', 'stella artois', 'carlsberg',
                'toblerone', 'lindt', 'godiva', 'ferrero', 'ghirardelli',
                'mac', 'estee lauder', 'clinique', 'lancome', 'dior',
                'chanel', 'gucci', 'prada', 'versace', 'burberry',
                'victoria secret', 'bath body', 'yankee candle',
                'samsung', 'apple', 'sony', 'bose', 'jbl',
                'ray ban', 'oakley', 'michael kors', 'coach', 'kate spade',
                'whisky', 'whiskey', 'vodka', 'rum', 'gin', 'tequila', 'wine',
                'beer', 'champagne', 'cognac', 'brandy', 'liqueur', 'liquor', 'scotch',
                'bourbon', 'perfume', 'fragrance', 'cologne', 'lipstick', 'skincare',
                'chocolate', 'candy', 'sweets', 'snacks', 'cigarette', 'tobacco',
                'sunglasses', 'watch', 'handbag', 'wallet', 'luggage',
            ],
            'confidence' => 0.85,
            'priority'   => 8,
        ],
        'stock_check' => [
            'patterns'   => [
                'do you have macallan', 'do you have johnnie', 'do you have jack',
                'do you have glenfiddich', 'do you have hennessy', 'do you have absolut',
                'do you have grey goose', 'do you have patron', 'do you have chivas',
                'do you have it in stock', 'do you have this in stock', 'have it in stock',
                'in stock', 'out of stock', 'availability', 'is it available',
                'stock check', 'do you have it', 'back in stock',
                'when will it be available', 'restock', 'sold out', 'notify me',
                'stock status', 'is this in stock', 'is it in stock', 'have in stock',
            ],
            'confidence' => 0.90,
            'priority'   => 5,
        ],
        'checkout_help' => [
            'patterns'   => [
                'checkout', 'can\'t checkout', 'checkout error', 'place order',
                'complete purchase', 'checkout problem', 'how to checkout',
                'checkout not working', 'submit order', 'finalize order',
            ],
            'confidence' => 0.88,
            'priority'   => 5,
        ],
        'coupon' => [
            'patterns'   => ['coupon', 'promo code', 'discount', 'deal', 'sale', 'offer', 'free shipping', 'code not working', 'apply code', 'voucher', 'promotion', 'apply coupon'],
            'confidence' => 0.88,
            'priority'   => 6,
        ],
        'size_help' => [
            'patterns'   => ['size', 'sizing', 'measurement', 'fit', 'too small', 'too big', 'size chart', 'what size', 'which size', 'size guide'],
            'confidence' => 0.87,
            'priority'   => 7,
        ],
        'shipping' => [
            'patterns'   => ['shipping', 'delivery time', 'how long does shipping', 'ship to', 'shipping cost', 'shipping options', 'express shipping', 'free delivery', 'shipping rate', 'international shipping', 'how long does it take', 'when will it ship'],
            'confidence' => 0.88,
            'priority'   => 6,
        ],
        'loyalty' => [
            'patterns'   => [
                'loyalty', 'reward', 'rewards', 'points', 'loyalty program',
                'membership', 'member', 'referral', 'refer a friend', 'earn points',
                'redeem points', 'my points', 'loyalty card', 'vip', 'tier',
                'loyalty status', 'benefits',
            ],
            'confidence' => 0.87,
            'priority'   => 7,
        ],
        'gift_card' => [
            'patterns'   => [
                'gift card', 'gift voucher', 'gift certificate', 'e-gift',
                'gift card balance', 'redeem gift card', 'buy gift card',
                'send a gift', 'gift for someone',
            ],
            'confidence' => 0.87,
            'priority'   => 7,
        ],
        'subscription' => [
            'patterns'   => [
                'subscription', 'subscribe', 'unsubscribe', 'recurring order',
                'auto renew', 'cancel subscription', 'pause subscription',
                'subscription status', 'manage subscription', 'modify subscription',
            ],
            'confidence' => 0.87,
            'priority'   => 7,
        ],
        'add_to_cart' => [
            'patterns'   => ['add to cart', 'i want to buy', 'i want to purchase', 'i want to order', 'i\'ll take', 'order this', 'add this', 'i want to add', 'buy this', 'purchase this'],
            'confidence' => 0.85,
            'priority'   => 8,
        ],
    ];

    /**
     * High-priority intents that should NOT be overridden by order ID detection.
     */
    private array $orderIdImmuneIntents = [
        'return_request', 'return_policy', 'complaint', 'escalation', 'payment_info',
    ];

    /**
     * Detect the intent from a user message.
     * Accepts optional $settings to merge custom product keywords.
     */
    public function detect(string $message, array $context = [], array $settings = []): array
    {
        $messageLower = strtolower(trim($message));

        // ── Quick-reply button value detection ──────────────────────────
        // Button values are slug-formatted (underscored). Map them to intents
        // directly so they are never treated as free-text product queries.
        $buttonIntentMap = [
            'need_help'          => 'help',
            'find_product'       => 'product_search',
            'product_help'       => 'product_search',
            'browse_categories'  => 'product_search',
            'best_sellers'       => 'product_search',
            'new_arrivals'       => 'product_search',
            'browse_sale'        => 'coupon',
            'browse_deals'       => 'coupon',
            'show_deals'         => 'coupon',
            'check_sale_items'   => 'coupon',
            'subscribe_offers'   => 'coupon',
            'track_order'        => 'order_tracking',
            'return_help'        => 'return_request',
            'escalate'           => 'escalation',
            'contact_support'    => 'escalation',
            'size_guide'         => 'size_help',
            'shipping_options'   => 'shipping',
            'payment_help'       => 'payment_info',
            'rate_chat'          => 'farewell',
            'start_return'       => 'return_request',
            'exchange_item'      => 'return_request',
            'return_policy'      => 'return_policy',
            'apply_coupon'       => 'coupon',
            'subscribe_offers'   => 'coupon',
        ];
        if (isset($buttonIntentMap[$messageLower])) {
            return [
                'intent'          => $buttonIntentMap[$messageLower],
                'confidence'      => 0.99,
                'matched_pattern' => 'quick_reply_button:' . $messageLower,
            ];
        }

        // ── Promo / coupon code detection ────────────────────────────────
        // If message looks like a discount code (all-uppercase alphanum, 4-20 chars,
        // at least one digit), treat it as coupon intent so the handler can explain
        // how to apply it — e.g. "WELCOME15", "SAVE20", "DDF10OFF".
        if (preg_match('/^[A-Z][A-Z0-9]{3,19}$/', $message) && preg_match('/\d/', $message)) {
            return [
                'intent'          => 'coupon',
                'confidence'      => 0.97,
                'matched_pattern' => 'promo_code_detected',
            ];
        }

        // Build runtime intents with custom keywords merged
        $runtimeIntents = $this->intents;
        $customKeywords = $settings['custom_product_keywords'] ?? '';
        if (is_string($customKeywords) && trim($customKeywords)) {
            $extraPatterns = array_filter(array_map('trim', explode("\n", $customKeywords)));
            $runtimeIntents['product_inquiry']['patterns'] = array_merge(
                $runtimeIntents['product_inquiry']['patterns'],
                array_map('strtolower', $extraPatterns)
            );
        }

        // Pre-detect comparison: "compare X vs Y" or "compare X versus Y"
        if (str_contains($messageLower, 'compare') &&
            (str_contains($messageLower, ' vs ') || str_contains($messageLower, 'versus'))) {
            return ['intent' => 'comparison', 'confidence' => 0.95, 'matched_pattern' => 'compare_vs_detected'];
        }

        $bestMatch = ['intent' => 'general', 'confidence' => 0.3, 'matched_pattern' => null, 'priority' => 99];

        foreach ($runtimeIntents as $intent => $config) {
            $intentPriority = $config['priority'] ?? 10;
            foreach ($config['patterns'] as $pattern) {
                if ($this->matchesPattern($messageLower, $pattern)) {
                    $matchConfidence = $config['confidence'] * $this->calculateMatchQuality($messageLower, $pattern);
                    // Prefer higher confidence; on tie, prefer lower priority number (= more important)
                    if ($matchConfidence > $bestMatch['confidence'] ||
                        ($matchConfidence === $bestMatch['confidence'] && $intentPriority < $bestMatch['priority'])) {
                        $bestMatch = [
                            'intent'          => $intent,
                            'confidence'      => round($matchConfidence, 3),
                            'matched_pattern' => $pattern,
                            'priority'        => $intentPriority,
                        ];
                    }
                }
            }
        }

        // Sentiment-based escalation detection (angry messages → complaint)
        $sentiment = $this->detectSentiment($messageLower);
        if ($sentiment['label'] === 'angry' && !in_array($bestMatch['intent'], ['escalation', 'complaint'])) {
            $bestMatch = [
                'intent'          => 'complaint',
                'confidence'      => 0.90,
                'matched_pattern' => 'sentiment_angry',
                'priority'        => 2,
            ];
        }

        // Context-based boosting
        if (isset($context['page_type'])) {
            $contextBoost = $this->getContextBoost($bestMatch['intent'], $context['page_type']);
            $bestMatch['confidence'] = min(1.0, $bestMatch['confidence'] + $contextBoost);
        }

        // Check for order ID pattern — ONLY override if current intent is not high-priority
        if (preg_match('/(?:order|ORD)\s*#?\s*[A-Z0-9\-]{4,}|#\d{4,}/i', $message)) {
            if (!in_array($bestMatch['intent'], $this->orderIdImmuneIntents) &&
                $bestMatch['intent'] !== 'order_tracking') {
                $bestMatch = [
                    'intent'          => 'order_tracking',
                    'confidence'      => 0.92,
                    'matched_pattern' => 'order_id_detected',
                    'priority'        => 5,
                ];
            }
        }

        // Remove priority from public response
        unset($bestMatch['priority']);

        return $bestMatch;
    }

    /**
     * Get all supported intents.
     */
    public function getSupportedIntents(): array
    {
        return array_keys($this->intents);
    }

    /**
     * Detect sentiment from message text.
     * Returns score (0-100) and label.
     */
    public function detectSentiment(string $text): array
    {
        $text = strtolower($text);
        $score = 50; // Neutral baseline

        $angryWords = [
            'angry', 'furious', 'terrible', 'horrible', 'worst', 'scam', 'fraud',
            'sue', 'lawyer', 'disgusting', 'awful', 'unacceptable', 'ridiculous',
            'pathetic', 'garbage', 'trash', 'hate', 'never again', 'refund now',
            'complaint', 'report', 'rip off', 'incompetent', 'useless',
        ];
        $frustratedWords = [
            'frustrated', 'annoyed', 'disappointed', 'waiting', 'still waiting',
            'again', 'broken', 'doesn\'t work', 'not working', 'wrong', 'missing',
            'delayed', 'late', 'poor', 'confusing', 'difficult', 'complicated',
        ];
        $positiveWords = [
            'thank', 'great', 'love', 'amazing', 'excellent', 'perfect', 'wonderful',
            'happy', 'pleased', 'satisfied', 'helpful', 'good', 'nice', 'awesome',
        ];

        foreach ($angryWords as $w) { if (str_contains($text, $w)) $score -= 15; }
        foreach ($frustratedWords as $w) { if (str_contains($text, $w)) $score -= 8; }
        foreach ($positiveWords as $w) { if (str_contains($text, $w)) $score += 10; }

        // ALL CAPS detection (shouting)
        $original = $text;
        $upperRatio = strlen(preg_replace('/[^A-Z]/', '', $original)) / max(1, strlen($original));
        if ($upperRatio > 0.5 && strlen($original) > 5) $score -= 20;

        // Multiple exclamation marks
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
            'product'  => ['product_inquiry' => 0.1, 'add_to_cart' => 0.1, 'size_help' => 0.1, 'stock_check' => 0.1],
            'cart'     => ['checkout_help' => 0.15, 'coupon' => 0.1, 'payment_info' => 0.1],
            'checkout' => ['checkout_help' => 0.2, 'shipping' => 0.1, 'payment_info' => 0.15],
            'order'    => ['order_tracking' => 0.15, 'return_request' => 0.1],
            'search'   => ['product_inquiry' => 0.1],
            'account'  => ['account_help' => 0.15],
        ];

        return $boosts[$pageType][$intent] ?? 0;
    }
}

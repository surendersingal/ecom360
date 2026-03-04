<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\Redis;

/**
 * "Live Session Context" cache — provides micro-second access to the
 * active state of a shopper's session without touching MongoDB.
 *
 * The AI Search & Chatbot modules depend on this service to inject
 * real-time context (current product page, active cart) directly into
 * LLM system prompts for hyper-personalised responses.
 *
 * All keys live in Redis with a 30-minute sliding TTL.
 */
final class LiveContextService
{
    /**
     * TTL in seconds (30 minutes).
     */
    private const int TTL = 1800;

    /**
     * Redis key prefixes.
     */
    private const string PREFIX_PAGE = 'live_ctx:page:';
    private const string PREFIX_CART = 'live_ctx:cart:';

    // ------------------------------------------------------------------
    //  Writers
    // ------------------------------------------------------------------

    /**
     * Store the ID of the product the user is currently viewing.
     *
     * Called on every `product_view` event so the Chatbot can say:
     * "I see you're looking at the Red Nike Shoes …"
     */
    public function updateCurrentPage(string $sessionId, string $productId): void
    {
        Redis::setex(
            self::PREFIX_PAGE . $sessionId,
            self::TTL,
            json_encode([
                'product_id' => $productId,
                'viewed_at'  => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Keep a live mirror of the user's shopping cart.
     *
     * Called on every `cart_update` event (add / remove / quantity change).
     *
     * @param  string            $sessionId
     * @param  list<array{id: string, name: string, qty: int, price: float}> $cartItems
     * @param  float             $cartTotal
     */
    public function updateLiveCart(string $sessionId, array $cartItems, float $cartTotal): void
    {
        Redis::setex(
            self::PREFIX_CART . $sessionId,
            self::TTL,
            json_encode([
                'items'      => $cartItems,
                'total'      => $cartTotal,
                'updated_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
        );
    }

    // ------------------------------------------------------------------
    //  Reader
    // ------------------------------------------------------------------

    /**
     * Return the full live context for a session.
     *
     * This is the method the Chatbot / AI Search modules call to inject
     * real-time context into LLM prompts.
     *
     * @return array{
     *     current_page: array{product_id: string, viewed_at: string}|null,
     *     active_cart: array{items: list<array>, total: float, updated_at: string}|null,
     * }
     */
    public function getContext(string $sessionId): array
    {
        $pageRaw = Redis::get(self::PREFIX_PAGE . $sessionId);
        $cartRaw = Redis::get(self::PREFIX_CART . $sessionId);

        return [
            'current_page' => $pageRaw !== null
                ? json_decode($pageRaw, true, 512, JSON_THROW_ON_ERROR)
                : null,
            'active_cart'  => $cartRaw !== null
                ? json_decode($cartRaw, true, 512, JSON_THROW_ON_ERROR)
                : null,
        ];
    }

    // ------------------------------------------------------------------
    //  Attribution helpers
    // ------------------------------------------------------------------

    /**
     * Record that this session arrived via a specific campaign or AI search.
     *
     * Stored with a 24-hour TTL so the attribution window matches industry
     * standards for last-click attribution.
     */
    public function recordAttribution(string $sessionId, string $source, string $sourceId): void
    {
        $key = "live_ctx:attr:{$sessionId}";

        Redis::setex(
            $key,
            86400, // 24 hours
            json_encode([
                'source'    => $source,    // 'campaign' | 'ai_search'
                'source_id' => $sourceId,
                'at'        => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Retrieve the attribution data for a session (if any, within 24h).
     *
     * @return array{source: string, source_id: string, at: string}|null
     */
    public function getAttribution(string $sessionId): ?array
    {
        $raw = Redis::get("live_ctx:attr:{$sessionId}");

        if ($raw === null) {
            return null;
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}

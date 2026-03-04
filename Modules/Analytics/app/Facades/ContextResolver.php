<?php

declare(strict_types=1);

namespace Modules\Analytics\Facades;

use Illuminate\Support\Facades\Facade;
use Modules\Analytics\Services\LiveContextService;

/**
 * Cross-module facade for the Live Session Context cache.
 *
 * Any module (Chatbot, AI Search, Marketing) can resolve real-time
 * session data without a direct dependency on the Analytics internals:
 *
 *     use Modules\Analytics\Facades\ContextResolver;
 *
 *     $ctx = ContextResolver::getContext($sessionId);
 *     // → ['current_page' => [...], 'active_cart' => [...]]
 *
 * @method static void  updateCurrentPage(string $sessionId, string $productId)
 * @method static void  updateLiveCart(string $sessionId, array $cartItems, float $cartTotal)
 * @method static array getContext(string $sessionId)
 * @method static void  recordAttribution(string $sessionId, string $source, string $sourceId)
 * @method static array|null getAttribution(string $sessionId)
 *
 * @see LiveContextService
 */
final class ContextResolver extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LiveContextService::class;
    }
}

<?php
declare(strict_types=1);

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * MagicLinkService — Tokenized cart reconstruction, 1-click reorder, cross-device cart merge.
 *
 * Powers Use Cases:
 *   - Magic-Link 1-Click Reorder (UC8)
 *   - Cross-Device Cart Recovery (UC4)
 *   - Abandoned Cart Recovery via SMS/Email links
 */
class MagicLinkService
{
    private const TOKEN_EXPIRY_HOURS = 72;

    /**
     * Create a magic link that reconstructs a specific cart.
     */
    public function createCartRecoveryLink(int $tenantId, string $email, array $cartItems, ?string $couponCode = null): array
    {
        try {
            $token = Str::random(64);

            $link = [
                'tenant_id'   => $tenantId,
                'token'        => $token,
                'type'         => 'cart_recovery',
                'email'        => $email,
                'cart_items'   => $cartItems,
                'coupon_code'  => $couponCode,
                'used'         => false,
                'click_count'  => 0,
                'expires_at'   => now()->addHours(self::TOKEN_EXPIRY_HOURS)->toDateTimeString(),
                'created_at'   => now()->toDateTimeString(),
            ];

            DB::connection('mongodb')
                ->table('magic_links')
                ->insert($link);

            $url = $this->buildUrl($tenantId, $token, 'cart');

            return [
                'success'    => true,
                'token'      => $token,
                'url'        => $url,
                'short_url'  => $this->shortenUrl($url),
                'expires_at' => $link['expires_at'],
                'cart_items'  => count($cartItems),
                'coupon'     => $couponCode,
            ];
        } catch (\Exception $e) {
            Log::error("MagicLinkService::createCartRecoveryLink error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a 1-click reorder link from a previous order.
     */
    public function createReorderLink(int $tenantId, string $email, string $orderId, ?float $discount = null): array
    {
        try {
            // Get original order items from MongoDB
            $order = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('metadata.order_id', $orderId)
                ->first();

            if (!$order) {
                return ['success' => false, 'error' => 'Order not found.'];
            }

            $items = $order['metadata']['items'] ?? [];
            if (empty($items)) {
                return ['success' => false, 'error' => 'No items found in order.'];
            }

            $cartItems = array_map(fn($item) => [
                'product_id' => $item['product_id'] ?? '',
                'sku'        => $item['sku'] ?? '',
                'name'       => $item['name'] ?? '',
                'qty'        => $item['qty'] ?? 1,
                'price'      => $item['price'] ?? 0,
            ], $items);

            $token = Str::random(64);
            $couponCode = null;

            // Generate a coupon if discount requested
            if ($discount && $discount > 0) {
                $couponCode = 'REORDER-' . strtoupper(Str::random(6));
                DB::connection('mongodb')
                    ->table('coupons')
                    ->insert([
                        'tenant_id'        => $tenantId,
                        'code'             => $couponCode,
                        'type'             => 'percentage',
                        'value'            => $discount,
                        'email'            => $email,
                        'reason'           => 'reorder_incentive',
                        'single_use'       => true,
                        'used'             => false,
                        'min_order_amount' => 0,
                        'max_discount'     => 0,
                        'expires_at'       => now()->addDays(7)->toDateTimeString(),
                        'created_at'       => now()->toDateTimeString(),
                    ]);
            }

            $link = [
                'tenant_id'    => $tenantId,
                'token'         => $token,
                'type'          => 'reorder',
                'email'         => $email,
                'order_id'      => $orderId,
                'cart_items'    => $cartItems,
                'coupon_code'   => $couponCode,
                'discount'      => $discount,
                'used'          => false,
                'click_count'   => 0,
                'expires_at'    => now()->addDays(30)->toDateTimeString(),
                'created_at'    => now()->toDateTimeString(),
            ];

            DB::connection('mongodb')
                ->table('magic_links')
                ->insert($link);

            $url = $this->buildUrl($tenantId, $token, 'reorder');

            return [
                'success'     => true,
                'token'       => $token,
                'url'         => $url,
                'short_url'   => $this->shortenUrl($url),
                'order_id'    => $orderId,
                'item_count'  => count($cartItems),
                'coupon'      => $couponCode,
                'discount'    => $discount,
                'expires_at'  => $link['expires_at'],
            ];
        } catch (\Exception $e) {
            Log::error("MagicLinkService::createReorderLink error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create cross-device cart merge link.
     * When clicked on a new device, merges the saved cart with current cart.
     */
    public function createCrossDeviceLink(int $tenantId, string $email, string $fingerprint, array $cartItems): array
    {
        try {
            $token = Str::random(64);

            $link = [
                'tenant_id'         => $tenantId,
                'token'              => $token,
                'type'               => 'cross_device',
                'email'              => $email,
                'source_fingerprint' => $fingerprint,
                'cart_items'         => $cartItems,
                'used'               => false,
                'click_count'        => 0,
                'expires_at'         => now()->addHours(24)->toDateTimeString(),
                'created_at'         => now()->toDateTimeString(),
            ];

            DB::connection('mongodb')
                ->table('magic_links')
                ->insert($link);

            $url = $this->buildUrl($tenantId, $token, 'merge');

            return [
                'success'    => true,
                'token'      => $token,
                'url'        => $url,
                'expires_at' => $link['expires_at'],
            ];
        } catch (\Exception $e) {
            Log::error("MagicLinkService::createCrossDeviceLink error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Resolve a magic link token — returns the payload for the store to process.
     */
    public function resolve(string $token): array
    {
        try {
            $link = DB::connection('mongodb')
                ->table('magic_links')
                ->where('token', $token)
                ->first();

            if (!$link) {
                return ['valid' => false, 'error' => 'Invalid or expired link.'];
            }

            if (isset($link['expires_at']) && now()->gt($link['expires_at'])) {
                return ['valid' => false, 'error' => 'Link has expired.'];
            }

            // Increment click count
            DB::connection('mongodb')
                ->table('magic_links')
                ->where('token', $token)
                ->increment('click_count');

            // Mark as used on first click
            if (!($link['used'] ?? false)) {
                DB::connection('mongodb')
                    ->table('magic_links')
                    ->where('token', $token)
                    ->update([
                        'used'    => true,
                        'used_at' => now()->toDateTimeString(),
                    ]);
            }

            return [
                'valid'       => true,
                'type'        => $link['type'],
                'cart_items'  => $link['cart_items'] ?? [],
                'coupon_code' => $link['coupon_code'] ?? null,
                'email'       => $link['email'] ?? null,
                'order_id'    => $link['order_id'] ?? null,
                'metadata'    => [
                    'click_count' => ($link['click_count'] ?? 0) + 1,
                    'created_at'  => $link['created_at'] ?? null,
                ],
            ];
        } catch (\Exception $e) {
            Log::error("MagicLinkService::resolve error: {$e->getMessage()}");
            return ['valid' => false, 'error' => 'Could not resolve link.'];
        }
    }

    /**
     * Get magic link analytics.
     */
    public function getAnalytics(int $tenantId, int $days = 30): array
    {
        try {
            $links = DB::connection('mongodb')
                ->table('magic_links')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', now()->subDays($days)->toDateTimeString())
                ->get();

            $total = $links->count();
            $clicked = $links->where('click_count', '>', 0)->count();
            $converted = $links->where('used', true)->count();

            $byType = $links->groupBy('type')->map(function ($group) {
                return [
                    'total'     => $group->count(),
                    'clicked'   => $group->where('click_count', '>', 0)->count(),
                    'converted' => $group->where('used', true)->count(),
                ];
            });

            return [
                'period_days'      => $days,
                'total_links'      => $total,
                'clicked'          => $clicked,
                'click_rate'       => $total > 0 ? round(($clicked / $total) * 100, 2) : 0,
                'converted'        => $converted,
                'conversion_rate'  => $total > 0 ? round(($converted / $total) * 100, 2) : 0,
                'by_type'          => $byType->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error("MagicLinkService::getAnalytics error: {$e->getMessage()}");
            return ['error' => $e->getMessage()];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function buildUrl(int $tenantId, string $token, string $action): string
    {
        // The Magento plugin or store-side handler will resolve this
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        $domain = $tenant->domain ?? $tenant->slug ?? 'store';

        return "https://{$domain}/ecom360/cart/recover?token={$token}&action={$action}";
    }

    private function shortenUrl(string $url): string
    {
        // Basic short URL — in production, integrate with Bitly/TinyURL
        $hash = substr(md5($url), 0, 8);
        return "https://ec360.link/{$hash}";
    }
}

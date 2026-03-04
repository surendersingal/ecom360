<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multi-auth middleware for widget/storefront endpoints.
 *
 * Supports BOTH authentication methods:
 *   1. X-Ecom360-Key header (API key) — used by Magento storefront widgets
 *   2. Authorization: Bearer (Sanctum) — used by the admin dashboard
 *
 * On API-key success: merges `_tenant_id` into the request and sets CORS headers.
 * On Sanctum success: standard auth flow, no extra headers.
 */
final class AuthenticateApiKeyOrSanctum
{
    public function handle(Request $request, Closure $next): Response
    {
        // ── 1. Try API-key auth first (storefront widgets) ───────────
        $apiKey = $request->header('X-Ecom360-Key')
            ?: $request->query('api_key');

        if ($apiKey !== null && $apiKey !== '') {
            $tenant = Tenant::where('api_key', $apiKey)
                ->where('is_active', true)
                ->first();

            if ($tenant === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive API key.',
                ], 403);
            }

            // Merge tenant context for downstream controllers.
            $request->merge(['_tenant_id' => (string) $tenant->id]);
            $request->attributes->set('tenant', $tenant);

            $response = $next($request);

            // CORS headers for cross-origin widget embedding.
            $response->headers->set('Access-Control-Allow-Origin', $request->header('Origin', '*'));
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Ecom360-Key, X-Requested-With');
            $response->headers->set('Access-Control-Max-Age', '86400');

            return $response;
        }

        // ── 2. Fall back to Sanctum (dashboard / admin) ──────────────
        if ($request->bearerToken()) {
            // Delegate to Sanctum middleware via the auth guard.
            $guard = auth('sanctum');

            if ($guard->check()) {
                $request->setUserResolver(fn () => $guard->user());
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // ── 3. No credentials at all ────────────────────────────────
        return response()->json([
            'success' => false,
            'message' => 'Authentication required. Provide X-Ecom360-Key header or Bearer token.',
        ], 401);
    }
}

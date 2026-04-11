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
 * Supports THREE authentication methods (in priority order):
 *   1. X-Ecom360-Key header (API key) — Magento storefront widgets (cross-origin)
 *   2. Authorization: Bearer <token> (Sanctum PAT) — dashboard JS with session token
 *   3. Session auth (Sanctum stateful) — logged-in dashboard user, no explicit token
 *
 * On API-key success: merges `_tenant_id` + sets CORS headers for cross-origin embed.
 * On Bearer/session success: standard Sanctum user resolver, tenant from user record.
 */
final class AuthenticateApiKeyOrSanctum
{
    public function handle(Request $request, Closure $next): Response
    {
        // ── 1. API-key auth (storefront widgets, cross-origin) ──────────
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

            $request->merge(['_tenant_id' => (string) $tenant->id]);
            $request->attributes->set('tenant', $tenant);

            $response = $next($request);

            // CORS for cross-origin widget embedding
            $response->headers->set('Access-Control-Allow-Origin', $request->header('Origin', '*'));
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Ecom360-Key, X-Requested-With');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '86400');

            return $response;
        }

        // ── 2. Bearer token auth (Sanctum PAT) ─────────────────────────
        if ($request->bearerToken()) {
            $guard = auth('sanctum');

            if ($guard->check()) {
                $user = $guard->user();
                $request->setUserResolver(fn () => $user);

                // Inject tenant context from user record so downstream controllers
                // can access the tenant without requiring an API key.
                $this->injectTenantFromUser($request, $user);

                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Invalid or expired Bearer token.',
            ], 401);
        }

        // ── 3. Session auth (Sanctum stateful — logged-in dashboard user) ─
        // This handles same-origin JS fetch() calls from the dashboard UI
        // that don't send an explicit Bearer token (use session cookie instead).
        $sanctumGuard = auth('sanctum');
        if ($sanctumGuard->check()) {
            $user = $sanctumGuard->user();
            $request->setUserResolver(fn () => $user);
            $this->injectTenantFromUser($request, $user);
            return $next($request);
        }

        // ── 4. No credentials at all ────────────────────────────────────
        return response()->json([
            'success' => false,
            'message' => 'Authentication required. Provide X-Ecom360-Key header or Bearer token.',
        ], 401);
    }

    /**
     * Inject tenant context into the request from the authenticated user's tenant_id.
     * Required so AI Search and Chatbot controllers can resolve which tenant to query.
     */
    private function injectTenantFromUser(Request $request, mixed $user): void
    {
        if ($user === null || empty($user->tenant_id)) {
            return;
        }

        $tenant = Tenant::find($user->tenant_id);
        if ($tenant) {
            $request->merge(['_tenant_id' => (string) $tenant->id]);
            $request->attributes->set('tenant', $tenant);
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the X-Ecom360-Key header against the tenants.api_key column.
 *
 * On success, merges `_tenant_id` into the request so downstream
 * controllers/services can scope data without Sanctum auth.
 *
 * Also sets permissive CORS headers for cross-origin storefront usage.
 */
final class ValidateTrackingApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // Header is primary; query param is fallback for sendBeacon (cannot set headers).
        $apiKey = $request->header('X-Ecom360-Key')
            ?: $request->query('api_key');

        if ($apiKey === null || $apiKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing X-Ecom360-Key header.',
            ], 401);
        }

        $tenant = Tenant::where('api_key', $apiKey)
            ->where('is_active', true)
            ->first();

        if ($tenant === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or inactive API key.',
            ], 403);
        }

        // Merge tenant context into the request for downstream use.
        $request->merge(['_tenant_id' => (string) $tenant->id]);
        $request->attributes->set('tenant', $tenant);

        $response = $next($request);

        // CORS headers for cross-origin storefront embedding.
        $response->headers->set('Access-Control-Allow-Origin', $request->header('Origin', '*'));
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Ecom360-Key, X-Requested-With');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}

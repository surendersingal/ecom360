<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates server-to-server sync authentication.
 *
 * Requires both:
 *  - X-Ecom360-Key    → tenant api_key
 *  - X-Ecom360-Secret → tenant secret_key
 *
 * On success merges `_tenant_id` and the Tenant model into the request
 * so downstream controllers can scope data without Sanctum auth.
 *
 * Also sets CORS headers for cross-origin storefront usage.
 */
final class ValidateSyncAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey    = $request->header('X-Ecom360-Key', '');
        $secretKey = $request->header('X-Ecom360-Secret', '');

        if ($apiKey === '' || $apiKey === null) {
            return response()->json([
                'success' => false,
                'message' => 'Missing X-Ecom360-Key header.',
            ], 401);
        }

        if ($secretKey === '' || $secretKey === null) {
            return response()->json([
                'success' => false,
                'message' => 'Missing X-Ecom360-Secret header. Server-to-server sync requires both API key and secret.',
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

        if ($tenant->secret_key !== $secretKey) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid secret key.',
            ], 403);
        }

        // Merge tenant context into the request.
        $request->merge(['_tenant_id' => (string) $tenant->id]);
        $request->attributes->set('tenant', $tenant);

        $response = $next($request);

        // CORS headers.
        $response->headers->set('Access-Control-Allow-Origin', $request->header('Origin', '*'));
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Ecom360-Key, X-Ecom360-Secret, X-Requested-With');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}

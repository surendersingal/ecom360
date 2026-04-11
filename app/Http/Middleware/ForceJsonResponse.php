<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force JSON responses for all API routes.
 *
 * Laravel's default behaviour redirects on validation failure when the request
 * does not include `Accept: application/json`. API clients (tracker SDKs,
 * Magento module, etc.) typically do not send this header.  This middleware
 * adds it before the request is processed, ensuring validation errors return
 * HTTP 422 JSON instead of HTTP 302 redirects.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}

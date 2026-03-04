<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only allows access if the authenticated user is a super admin.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_super_admin) {
            abort(403, 'Access denied. Super admin privileges required.');
        }

        return $next($request);
    }
}

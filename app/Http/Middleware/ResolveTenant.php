<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a Tenant from the {tenant} route slug and shares it with all views.
 * Also verifies the authenticated user belongs to this tenant (or is impersonating).
 */
final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('tenant');

        if (! $slug) {
            abort(404, 'Store not found.');
        }

        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->first();

        if (! $tenant) {
            abort(404, 'Store not found or inactive.');
        }

        $user = $request->user();

        // Allow if impersonating (super admin) or if user belongs to this tenant
        $isImpersonating = $request->session()->has('impersonating_from_admin_id');

        if (! $isImpersonating && (! $user || $user->tenant_id !== $tenant->id)) {
            abort(403, 'You do not have access to this store.');
        }

        // Set Spatie team scope so permission/role checks are tenant-aware
        \setPermissionsTeamId($tenant->id);

        // Share tenant with all views and bind to container
        view()->share('tenant', $tenant);
        app()->instance('currentTenant', $tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}

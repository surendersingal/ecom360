<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate-keeps a single named permission for tenant-scoped users.
 *
 * Usage in routes (via route middleware alias):
 *   ->middleware('permission:analytics.view')
 *
 * Behaviour matrix
 * ─────────────────────────────────────────────────────────────────
 * Condition                         Result
 * ─────────────────────────────────────────────────────────────────
 * No authenticated user             Pass through (API-key / widget
 *                                   paths; auth handled elsewhere)
 * is_super_admin === true           Pass through (bypass all gates)
 * User lacks the permission (JSON)  403 JSON error response
 * User lacks the permission (web)   redirect()->back() with error
 *                                   flash, or abort(403) if no
 *                                   previous URL available
 * User has the permission           Pass through
 * ─────────────────────────────────────────────────────────────────
 */
final class RequireTenantPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        // 1. No user at all — let upstream auth middleware decide what to do.
        //    Widget / API-key calls that carry no session user still need to
        //    reach their destination (e.g. public tracking endpoints).
        if ($user === null) {
            return $next($request);
        }

        // 2. Super-admins bypass every tenant permission gate.
        if ($user->is_super_admin) {
            return $next($request);
        }

        // 3. Scope Spatie to this user's tenant so ->can() checks the right
        //    team partition.  We guard against a null tenant_id defensively;
        //    if it somehow occurs the check falls through to the unscoped
        //    Spatie global team (which will almost certainly deny).
        if ($user->tenant_id !== null) {
            setPermissionsTeamId($user->tenant_id);
        }

        // 4. Check permission.
        if (! $user->can($permission)) {
            return $this->denyResponse($request, $permission);
        }

        return $next($request);
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    private function denyResponse(Request $request, string $permission): Response
    {
        if ($request->expectsJson()) {
            return response()->json(
                [
                    'success' => false,
                    'error'   => "Permission denied: {$permission}",
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        // Web request: prefer a redirect back with a user-visible error.
        // If there is no previous URL (direct navigation) we hard-abort.
        $previous = url()->previous();

        if ($previous && $previous !== url()->current()) {
            return redirect($previous)
                ->withErrors(['permission' => "You do not have permission to perform this action ({$permission})."])
                ->with('error', "Permission denied: {$permission}");
        }

        abort(Response::HTTP_FORBIDDEN, "Permission denied: {$permission}");
    }
}

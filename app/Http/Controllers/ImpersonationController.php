<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Handles admin impersonation of tenant users.
 *
 * Flow:
 *  1. Super admin clicks "Login as Tenant" on a store in the admin panel.
 *  2. We store the admin's user ID in the session and log in as a tenant user.
 *  3. The admin is redirected to the tenant panel (/app/{slug}).
 *  4. A persistent banner shows "You are impersonating…" with a "Back to Admin" link.
 *  5. Clicking "Back to Admin" restores the original admin session.
 */
final class ImpersonationController extends Controller
{
    /**
     * Start impersonating a tenant — switch AUTH to a user belonging to this tenant.
     */
    public function start(Request $request, Tenant $tenant): RedirectResponse
    {
        // Only super admins can impersonate
        $currentUser = $request->user();
        if (! $currentUser || ! $currentUser->is_super_admin) {
            abort(403, 'Unauthorized: Only super admins can impersonate tenants.');
        }

        // Find a user belonging to this tenant (prefer the first user created)
        $tenantUser = User::where('tenant_id', $tenant->id)->orderBy('id')->first();

        if (! $tenantUser) {
            return redirect()->back()->with('error', "No users found for store: {$tenant->name}. Create a user first.");
        }

        // Store original admin ID in session
        $request->session()->put('impersonating_from_admin_id', $currentUser->id);
        $request->session()->put('impersonating_tenant_name', $tenant->name);

        // Switch authentication to the tenant user
        Auth::login($tenantUser);

        return redirect("/app/{$tenant->slug}");
    }

    /**
     * Stop impersonating — restore original admin session.
     */
    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->get('impersonating_from_admin_id');

        if (! $adminId) {
            return redirect('/admin');
        }

        // Restore admin user
        $adminUser = User::find($adminId);

        if ($adminUser && $adminUser->is_super_admin) {
            Auth::login($adminUser);
        }

        // Clean up session
        $request->session()->forget('impersonating_from_admin_id');
        $request->session()->forget('impersonating_tenant_name');

        return redirect('/admin');
    }
}

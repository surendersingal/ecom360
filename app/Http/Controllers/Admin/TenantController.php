<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

final class TenantController extends Controller
{
    public function index(): View
    {
        $tenants = Tenant::withCount('users')->latest()->get();
        return view('admin.tenants.index', compact('tenants'));
    }

    public function create(): View
    {
        return view('admin.tenants.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'   => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['slug']        = Str::slug($validated['name']);
        $validated['api_key']     = 'ek_' . Str::random(32);
        $validated['is_active']   = $request->boolean('is_active', true);
        $validated['is_verified'] = $request->boolean('is_verified', false);

        // Ensure unique slug
        $baseSlug = $validated['slug'];
        $counter = 1;
        while (Tenant::where('slug', $validated['slug'])->exists()) {
            $validated['slug'] = $baseSlug . '-' . $counter++;
        }

        $tenant = Tenant::create($validated);

        // Provision the three tenant roles (scoped to this tenant via team_id)
        $this->provisionTenantRoles($tenant->id);

        // Optionally create a default admin user for the tenant
        if ($request->filled('user_name') && $request->filled('user_email')) {
            $user = User::create([
                'tenant_id'      => $tenant->id,
                'name'           => $request->input('user_name'),
                'email'          => $request->input('user_email'),
                'password'       => bcrypt($request->input('user_password', 'password')),
                'is_super_admin' => false,
            ]);

            // Assign Admin role to the default user
            setPermissionsTeamId($tenant->id);
            $user->assignRole('Admin');
        }

        return redirect()->route('admin.tenants.index')->with('success', "Store '{$tenant->name}' created successfully.");
    }

    public function show(Tenant $tenant): View
    {
        $tenant->loadCount('users');
        $users = $tenant->users()->get();
        return view('admin.tenants.show', compact('tenant', 'users'));
    }

    public function edit(Tenant $tenant): View
    {
        return view('admin.tenants.edit', compact('tenant'));
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'name'   => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['is_active']   = $request->boolean('is_active');
        $validated['is_verified'] = $request->boolean('is_verified');

        $tenant->update($validated);

        return redirect()->route('admin.tenants.show', $tenant)->with('success', 'Store updated successfully.');
    }

    public function destroy(Tenant $tenant): RedirectResponse
    {
        $name = $tenant->name;
        $tenant->users()->delete();
        $tenant->settings()->delete();
        $tenant->delete();

        return redirect()->route('admin.tenants.index')->with('success', "Store '{$name}' deleted.");
    }

    /**
     * Toggle active status.
     */
    public function toggleActive(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['is_active' => ! $tenant->is_active]);
        $status = $tenant->is_active ? 'activated' : 'deactivated';
        return back()->with('success', "Store '{$tenant->name}' {$status}.");
    }

    /**
     * Toggle verification.
     */
    public function verify(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['is_verified' => ! $tenant->is_verified]);
        $status = $tenant->is_verified ? 'verified' : 'unverified';
        return back()->with('success', "Store '{$tenant->name}' marked as {$status}.");
    }

    /**
     * Regenerate API key.
     */
    public function regenerateApiKey(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['api_key' => 'ek_' . Str::random(32)]);
        return back()->with('success', 'API key regenerated.');
    }

    /**
     * Provision the three standard roles (Admin / Editor / Viewer) for a new tenant.
     * Uses Spatie's team feature — each role is scoped to this tenant's ID.
     */
    private function provisionTenantRoles(int $tenantId): void
    {
        setPermissionsTeamId($tenantId);

        foreach (['Admin', 'Editor', 'Viewer'] as $roleName) {
            Role::firstOrCreate([
                'name'       => $roleName,
                'guard_name' => 'sanctum',
                'tenant_id'  => $tenantId,
            ]);
        }
    }
}

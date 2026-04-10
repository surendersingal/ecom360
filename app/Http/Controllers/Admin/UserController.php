<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UserController extends Controller
{
    private const TENANT_ROLES = ['Admin', 'Editor', 'Viewer'];

    public function index(): View
    {
        $users = User::with('tenant')->latest()->get();

        // Attach each user's role name using tenant-scoped team permissions
        // We query model_has_roles directly to avoid N+1 and team-scope complexity
        $userRoles = \DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('model_has_roles.model_id', $users->pluck('id'))
            ->select('model_has_roles.model_id', 'roles.name as role_name')
            ->pluck('role_name', 'model_id');

        $users->each(fn($u) => $u->role_name = $userRoles->get($u->id));

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        $tenants = Tenant::where('is_active', true)->orderBy('name')->get();
        $roles   = self::TENANT_ROLES;
        return view('admin.users.create', compact('tenants', 'roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $isSuperAdmin = $request->boolean('is_super_admin', false);

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'unique:users,email'],
            'password'       => ['required', 'string', 'min:8', 'confirmed'],
            'tenant_id'      => [$isSuperAdmin ? 'nullable' : 'required', 'exists:tenants,id'],
            'role'           => [$isSuperAdmin ? 'nullable' : 'required', 'in:Admin,Editor,Viewer'],
            'is_super_admin' => ['boolean'],
        ]);

        $role = $request->input('role');
        unset($validated['role']);

        $validated['password']       = bcrypt($validated['password']);
        $validated['is_super_admin'] = $isSuperAdmin;

        $user = User::create($validated);

        // Assign role scoped to the tenant using Spatie teams
        if ($user->tenant_id && $role && ! $user->is_super_admin) {
            setPermissionsTeamId($user->tenant_id);
            $user->assignRole($role);
        }

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function edit(User $user): View
    {
        $tenants     = Tenant::where('is_active', true)->orderBy('name')->get();
        $roles       = self::TENANT_ROLES;
        $currentRole = null;

        if ($user->tenant_id) {
            setPermissionsTeamId($user->tenant_id);
            $currentRole = $user->roles->first()?->name;
        }

        return view('admin.users.edit', compact('user', 'tenants', 'roles', 'currentRole'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $isSuperAdmin = $request->boolean('is_super_admin', false);

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'unique:users,email,' . $user->id],
            'password'       => ['nullable', 'string', 'min:8', 'confirmed'],
            'tenant_id'      => [$isSuperAdmin ? 'nullable' : 'required', 'exists:tenants,id'],
            'role'           => [$isSuperAdmin ? 'nullable' : 'required', 'in:Admin,Editor,Viewer'],
            'is_super_admin' => ['boolean'],
        ]);

        $role = $request->input('role');
        unset($validated['role']);

        $validated['is_super_admin'] = $isSuperAdmin;

        if ($request->filled('password')) {
            $validated['password'] = bcrypt($request->input('password'));
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        // Sync role scoped to the tenant
        if ($user->tenant_id && $role && ! $user->is_super_admin) {
            setPermissionsTeamId($user->tenant_id);
            $user->syncRoles([$role]);
        } elseif ($user->is_super_admin) {
            // Super admins have no tenant role
            $user->syncRoles([]);
        }

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->is_super_admin && User::where('is_super_admin', true)->count() <= 1) {
            return back()->with('error', 'Cannot delete the last super admin.');
        }

        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'User deleted.');
    }
}

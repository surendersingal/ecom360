<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Manages tenant-scoped users and roles.
 *
 * Every mutating route should be protected upstream with:
 *   middleware(['auth:sanctum', 'tenant', 'permission:users.manage'])
 *
 * Read-only routes (index, edit) may use 'permission:users.view'.
 *
 * The ResolveTenant middleware already calls setPermissionsTeamId() and
 * stashes the resolved Tenant in $request->attributes->get('tenant').
 * We call setPermissionsTeamId() again in each method for safety in case
 * the method is reached through a code-path that bypasses the middleware.
 */
final class UserManagementController extends Controller
{
    // ------------------------------------------------------------------
    //  Default roles that must not be deleted
    // ------------------------------------------------------------------

    /** @var list<string> */
    private const PROTECTED_ROLES = ['Admin', 'Editor', 'Viewer'];

    // ══════════════════════════════════════════════════════════════════
    //  User CRUD
    // ══════════════════════════════════════════════════════════════════

    /**
     * List all users belonging to this tenant.
     */
    public function index(Request $request, string $tenant): View
    {
        [$tenantModel] = $this->bootstrap($request);

        $users = User::where('tenant_id', $tenantModel->id)
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Eager-load each user's role name via the Spatie join.
        // We resolve roles inside the tenant scope already set by bootstrap().
        $userIds  = $users->pluck('id');
        $roleMap  = \DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User())->getMorphClass())
            ->whereIn('model_has_roles.model_id', $userIds)
            ->where('roles.tenant_id', $tenantModel->id)
            ->select('model_has_roles.model_id', 'roles.name as role_name')
            ->get()
            ->keyBy('model_id');

        return view('tenant.pages.users.index', compact('users', 'roleMap'));
    }

    /**
     * Show the form to create a new tenant user.
     */
    public function create(Request $request, string $tenant): View
    {
        [$tenantModel] = $this->bootstrap($request);

        $roles = $this->tenantRoles($tenantModel);

        return view('tenant.pages.users.create', compact('roles'));
    }

    /**
     * Persist a new tenant user and assign their role.
     */
    public function store(Request $request, string $tenant): RedirectResponse
    {
        [$tenantModel] = $this->bootstrap($request);

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role_id'  => ['required', 'integer'],
        ]);

        // Verify the requested role actually belongs to this tenant.
        $role = $this->findTenantRole($tenantModel, (int) $validated['role_id']);

        if ($role === null) {
            return back()->withErrors(['role_id' => 'Invalid role selected.'])->withInput();
        }

        // Prevent creating super-admin accounts through this interface.
        $user = User::create([
            'tenant_id'     => $tenantModel->id,
            'name'          => $validated['name'],
            'email'         => $validated['email'],
            'password'      => $validated['password'], // cast to hashed by model
            'is_super_admin' => false,
        ]);

        setPermissionsTeamId($tenantModel->id);
        $user->assignRole($role);

        return redirect()
            ->route('tenant.users.index', ['tenant' => $tenant])
            ->with('success', "User {$user->name} created successfully.");
    }

    /**
     * Show the edit form for an existing tenant user.
     */
    public function edit(Request $request, string $tenant, User $user): View
    {
        [$tenantModel] = $this->bootstrap($request);

        $this->assertUserBelongsToTenant($user, $tenantModel);

        $roles       = $this->tenantRoles($tenantModel);
        $currentRole = $user->roles()->where('tenant_id', $tenantModel->id)->first();

        return view('tenant.pages.users.edit', compact('user', 'roles', 'currentRole'));
    }

    /**
     * Update an existing tenant user's details and role.
     */
    public function update(Request $request, string $tenant, User $user): RedirectResponse
    {
        [$tenantModel] = $this->bootstrap($request);

        $this->assertUserBelongsToTenant($user, $tenantModel);

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', "unique:users,email,{$user->id}"],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role_id'  => ['required', 'integer'],
        ]);

        $role = $this->findTenantRole($tenantModel, (int) $validated['role_id']);

        if ($role === null) {
            return back()->withErrors(['role_id' => 'Invalid role selected.'])->withInput();
        }

        $user->name  = $validated['name'];
        $user->email = $validated['email'];

        if (filled($validated['password'])) {
            $user->password = $validated['password']; // cast to hashed by model
        }

        // Prevent accidentally granting super-admin via this form.
        $user->is_super_admin = false;

        $user->save();

        setPermissionsTeamId($tenantModel->id);
        $user->syncRoles([$role]);

        return redirect()
            ->route('tenant.users.index', ['tenant' => $tenant])
            ->with('success', "User {$user->name} updated successfully.");
    }

    /**
     * Remove a tenant user.
     */
    public function destroy(Request $request, string $tenant, User $user): RedirectResponse
    {
        [$tenantModel] = $this->bootstrap($request);

        $this->assertUserBelongsToTenant($user, $tenantModel);

        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        $userName = $user->name;
        $user->delete();

        return redirect()
            ->route('tenant.users.index', ['tenant' => $tenant])
            ->with('success', "User {$userName} deleted.");
    }

    // ══════════════════════════════════════════════════════════════════
    //  Role management
    // ══════════════════════════════════════════════════════════════════

    /**
     * List all roles for this tenant with permission counts.
     */
    public function roles(Request $request, string $tenant): View
    {
        [$tenantModel] = $this->bootstrap($request);

        $roles = Role::where('guard_name', 'sanctum')
            ->where('tenant_id', $tenantModel->id)
            ->withCount('permissions')
            ->orderBy('name')
            ->get();

        return view('tenant.pages.users.roles', compact('roles'));
    }

    /**
     * Create a custom role for this tenant and assign selected permissions.
     */
    public function createRole(Request $request, string $tenant): RedirectResponse
    {
        [$tenantModel] = $this->bootstrap($request);

        $validated = $request->validate([
            'name'        => [
                'required',
                'string',
                'max:100',
                // Must be unique per tenant (not globally unique).
                \Illuminate\Validation\Rule::unique('roles', 'name')
                    ->where('tenant_id', $tenantModel->id)
                    ->where('guard_name', 'sanctum'),
            ],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        // Reject attempts to shadow protected role names.
        if (in_array($validated['name'], self::PROTECTED_ROLES, true)) {
            return back()
                ->withErrors(['name' => 'The role "' . $validated['name'] . '" is a reserved role name and cannot be recreated.'])
                ->withInput();
        }

        setPermissionsTeamId($tenantModel->id);

        /** @var Role $role */
        $role = Role::create([
            'name'       => $validated['name'],
            'guard_name' => 'sanctum',
            'tenant_id'  => $tenantModel->id,
        ]);

        $selectedPermissions = $validated['permissions'] ?? [];

        if ($selectedPermissions !== []) {
            // Only allow granting permissions that actually exist (guard-scoped).
            $perms = Permission::whereIn('name', $selectedPermissions)
                ->where('guard_name', 'sanctum')
                ->get();

            $role->givePermissionTo($perms);
        }

        return redirect()
            ->route('tenant.users.roles', ['tenant' => $tenant])
            ->with('success', "Role {$role->name} created with " . count($selectedPermissions) . ' permission(s).');
    }

    /**
     * Delete a custom tenant role (protected default roles are safe-guarded).
     */
    public function destroyRole(Request $request, string $tenant, int $roleId): RedirectResponse
    {
        [$tenantModel] = $this->bootstrap($request);

        $role = Role::where('id', $roleId)
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $tenantModel->id)
            ->first();

        if ($role === null) {
            abort(404, 'Role not found for this tenant.');
        }

        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            return back()->withErrors([
                'role' => "The \"{$role->name}\" role is a default system role and cannot be deleted.",
            ]);
        }

        $roleName = $role->name;
        $role->delete();

        return redirect()
            ->route('tenant.users.roles', ['tenant' => $tenant])
            ->with('success', "Role {$roleName} deleted.");
    }

    // ══════════════════════════════════════════════════════════════════
    //  Private helpers
    // ══════════════════════════════════════════════════════════════════

    /**
     * Common bootstrap for every action:
     *  1. Resolve the tenant from request attributes (set by ResolveTenant middleware).
     *  2. Authorise the acting user (must have users.manage or be super-admin).
     *  3. Re-set the Spatie team scope to this tenant.
     *
     * @return array{0: Tenant}
     */
    private function bootstrap(Request $request): array
    {
        /** @var Tenant|null $tenantModel */
        $tenantModel = $request->attributes->get('tenant');

        if ($tenantModel === null) {
            // ResolveTenant middleware should have run first; treat as 404.
            abort(404, 'Tenant context missing.');
        }

        $actingUser = auth()->user();

        if (
            $actingUser === null
            || (! $actingUser->is_super_admin && ! $actingUser->can('users.manage'))
        ) {
            abort(403, 'You do not have permission to manage users.');
        }

        // Ensure team scope is set (ResolveTenant already does this, but be explicit).
        setPermissionsTeamId($tenantModel->id);

        return [$tenantModel];
    }

    /**
     * Abort with 403 if the given user does not belong to the given tenant.
     */
    private function assertUserBelongsToTenant(User $user, Tenant $tenantModel): void
    {
        if ($user->tenant_id !== $tenantModel->id) {
            abort(403, 'This user does not belong to your tenant.');
        }
    }

    /**
     * Return all Spatie Role models scoped to this tenant (guard = sanctum).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    private function tenantRoles(Tenant $tenantModel): \Illuminate\Database\Eloquent\Collection
    {
        return Role::where('guard_name', 'sanctum')
            ->where('tenant_id', $tenantModel->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a role by ID that is verified to belong to this tenant.
     */
    private function findTenantRole(Tenant $tenantModel, int $roleId): ?Role
    {
        return Role::where('id', $roleId)
            ->where('guard_name', 'sanctum')
            ->where('tenant_id', $tenantModel->id)
            ->first();
    }
}

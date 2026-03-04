<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Provisions default RBAC roles & permissions for a newly-created Tenant.
 *
 * Call {@see provisionForTenant()} from an observer, event listener,
 * or directly after creating a Tenant record.
 */
final readonly class RoleManagerService
{
    /**
     * Module-scoped permissions every tenant receives by default.
     *
     * @var array<string, list<string>>
     */
    private const array DEFAULT_PERMISSIONS = [
        'analytics' => [
            'analytics.view',
            'analytics.export',
            'analytics.manage',
        ],
        'ai_search' => [
            'ai_search.query',
            'ai_search.manage',
        ],
        'chatbot' => [
            'chatbot.view',
            'chatbot.configure',
            'chatbot.manage',
        ],
        'business_intelligence' => [
            'business_intelligence.view',
            'business_intelligence.export',
            'business_intelligence.manage',
        ],
        'marketing' => [
            'marketing.view',
            'marketing.send',
            'marketing.manage',
        ],
    ];

    /**
     * Roles and the permission patterns they receive.
     * "*.manage" implies every permission in that module.
     *
     * @var array<string, list<string>>
     */
    private const array ROLE_MAP = [
        'Admin' => ['*'],                                     // all permissions
        'Editor' => [
            'analytics.view', 'analytics.export',
            'ai_search.query',
            'chatbot.view', 'chatbot.configure',
            'business_intelligence.view', 'business_intelligence.export',
            'marketing.view', 'marketing.send',
        ],
        'Viewer' => [
            'analytics.view',
            'ai_search.query',
            'chatbot.view',
            'business_intelligence.view',
            'marketing.view',
        ],
    ];

    /**
     * Provision default roles & permissions for $tenant.
     */
    public function provisionForTenant(Tenant $tenant): void
    {
        // Reset cached roles/permissions so new entries are visible immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Ensure all default permissions exist (guard = "sanctum").
        $allPermissions = $this->ensurePermissionsExist();

        // 2. Create roles scoped to this tenant and attach permissions.
        foreach (self::ROLE_MAP as $roleName => $permissionKeys) {
            $role = Role::findOrCreate(
                name: $roleName,
                guardName: 'sanctum',
            );

            // Spatie teams: set the tenant context.
            setPermissionsTeamId($tenant->id);

            $permissions = $permissionKeys === ['*']
                ? $allPermissions
                : array_filter(
                    $allPermissions,
                    static fn (Permission $p): bool => in_array($p->name, $permissionKeys, true),
                );

            $role->syncPermissions($permissions);
        }

        Log::info("[RoleManager] Provisioned roles & permissions for tenant [{$tenant->slug}].");
    }

    /**
     * Create every permission defined in DEFAULT_PERMISSIONS (idempotent).
     *
     * @return list<Permission>
     */
    private function ensurePermissionsExist(): array
    {
        $all = [];

        foreach (self::DEFAULT_PERMISSIONS as $permissions) {
            foreach ($permissions as $permissionName) {
                $all[] = Permission::findOrCreate($permissionName, 'sanctum');
            }
        }

        return $all;
    }
}

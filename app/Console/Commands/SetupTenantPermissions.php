<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Artisan command to create/sync all tenant roles and permissions.
 *
 * Usage:
 *   php artisan ecom360:setup-permissions              # all tenants
 *   php artisan ecom360:setup-permissions --tenant=5   # specific tenant
 */
final class SetupTenantPermissions extends Command
{
    protected $signature = 'ecom360:setup-permissions
                            {--tenant= : Specific tenant ID to process (omit for all tenants)}';

    protected $description = 'Create/sync Spatie roles and permissions for every tenant (or a single one).';

    /**
     * All 15 permission names.
     *
     * @var list<string>
     */
    private const ALL_PERMISSIONS = [
        'analytics.view',
        'analytics.manage',
        'ai_search.query',
        'ai_search.manage',
        'marketing.view',
        'marketing.manage',
        'business_intelligence.view',
        'business_intelligence.manage',
        'chatbot.view',
        'chatbot.configure',
        'cdp.view',
        'cdp.manage',
        'datasync.view',
        'datasync.manage',
        'users.view',
        'users.manage',
        'settings.manage',
    ];

    /**
     * Role → permission mapping.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'Admin' => self::ALL_PERMISSIONS,

        'Editor' => [
            'analytics.view',
            'analytics.manage',
            'ai_search.query',
            'ai_search.manage',
            'marketing.view',
            'marketing.manage',
            'business_intelligence.view',
            'business_intelligence.manage',
            'chatbot.view',
            'chatbot.configure',
            'cdp.view',
            'cdp.manage',
            'datasync.view',
            'users.view',
        ],

        'Viewer' => [
            'analytics.view',
            'ai_search.query',
            'marketing.view',
            'business_intelligence.view',
            'chatbot.view',
            'cdp.view',
            'datasync.view',
        ],
    ];

    public function handle(): int
    {
        $tenantIdOption = $this->option('tenant');

        $tenants = $tenantIdOption
            ? Tenant::where('id', (int) $tenantIdOption)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error(
                $tenantIdOption
                    ? "No tenant found with ID {$tenantIdOption}."
                    : 'No tenants exist in the database.'
            );

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $this->processOneTenant($tenant);
        }

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    private function processOneTenant(Tenant $tenant): void
    {
        $this->newLine();
        $this->line("──────────────────────────────────────────────────");
        $this->info("Tenant #{$tenant->id}: {$tenant->name} (slug: {$tenant->slug})");
        $this->line("──────────────────────────────────────────────────");

        // 1. Set team scope so all Spatie operations are tenant-scoped.
        setPermissionsTeamId($tenant->id);

        // 2. Create / ensure all permissions exist (guard = sanctum).
        $this->line('  → Ensuring permissions…');
        $permissionsCreated = 0;

        foreach (self::ALL_PERMISSIONS as $permName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permName, 'guard_name' => 'sanctum'],
            );

            if ($permission->wasRecentlyCreated) {
                ++$permissionsCreated;
                $this->line("    [created] {$permName}");
            }
        }

        if ($permissionsCreated === 0) {
            $this->line('    All permissions already existed.');
        } else {
            $this->line("    {$permissionsCreated} permission(s) created.");
        }

        // 3. Create / update roles and sync their permissions.
        $this->line('  → Ensuring roles and syncing permissions…');

        foreach (self::ROLE_PERMISSIONS as $roleName => $permNames) {
            /** @var Role $role */
            $role = Role::firstOrCreate(
                [
                    'name'       => $roleName,
                    'guard_name' => 'sanctum',
                    'tenant_id'  => $tenant->id,
                ],
            );

            $action = $role->wasRecentlyCreated ? '[created]' : '[exists] ';

            // Resolve Permission models by name (guard-scoped).
            $permissionModels = Permission::whereIn('name', $permNames)
                ->where('guard_name', 'sanctum')
                ->get();

            $role->syncPermissions($permissionModels);

            $this->line("    {$action} {$roleName} — synced " . count($permNames) . ' permission(s).');
        }

        $this->info("  ✓ Tenant #{$tenant->id} complete.");
    }
}

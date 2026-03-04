<?php

declare(strict_types=1);

namespace Modules\DataSync\Services;

use Modules\DataSync\Enums\ConsentLevel;
use Modules\DataSync\Enums\SyncEntity;
use Modules\DataSync\Models\SyncConnection;
use Modules\DataSync\Models\SyncPermission;

/**
 * Manages per-entity sync consent for a connected store.
 *
 * Public entities (products, categories, inventory, sales) are auto-granted
 * when a connection registers. Restricted / sensitive entities require
 * explicit opt-in from the store admin (via module settings).
 */
final class PermissionService
{
    /**
     * Check whether a specific entity is permitted for an active connection.
     */
    public function isEntityAllowed(SyncConnection $connection, SyncEntity $entity): bool
    {
        // Public catalog data is always allowed when connection is active.
        if (!$entity->requiresConsent()) {
            return $connection->is_active;
        }

        $permission = SyncPermission::where('connection_id', $connection->id)
            ->where('entity', $entity->value)
            ->first();

        return $permission !== null && $permission->enabled && $connection->is_active;
    }

    /**
     * Initialize default permissions when a new connection registers.
     * Public entities are auto-enabled; restricted/sensitive are disabled.
     */
    public function initializePermissions(SyncConnection $connection): void
    {
        foreach (SyncEntity::cases() as $entity) {
            $isPublic = !$entity->requiresConsent();

            SyncPermission::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'entity'        => $entity->value,
                ],
                [
                    'tenant_id'     => $connection->tenant_id,
                    'consent_level' => $entity->consentLevel()->value,
                    'enabled'       => $isPublic,
                    'granted_at'    => $isPublic ? now() : null,
                    'granted_by'    => $isPublic ? 'auto' : null,
                ],
            );
        }
    }

    /**
     * Grant permission for a specific entity (called when client opts in
     * from their Magento module or WP plugin settings).
     */
    public function grantPermission(
        SyncConnection $connection,
        SyncEntity $entity,
        string $grantedBy = 'module_settings',
    ): SyncPermission {
        return SyncPermission::updateOrCreate(
            [
                'connection_id' => $connection->id,
                'entity'        => $entity->value,
            ],
            [
                'tenant_id'     => $connection->tenant_id,
                'consent_level' => $entity->consentLevel()->value,
                'enabled'       => true,
                'granted_at'    => now(),
                'revoked_at'    => null,
                'granted_by'    => $grantedBy,
            ],
        );
    }

    /**
     * Revoke permission for a specific entity.
     */
    public function revokePermission(SyncConnection $connection, SyncEntity $entity): SyncPermission
    {
        /** @var SyncPermission $permission */
        $permission = SyncPermission::updateOrCreate(
            [
                'connection_id' => $connection->id,
                'entity'        => $entity->value,
            ],
            [
                'tenant_id'     => $connection->tenant_id,
                'consent_level' => $entity->consentLevel()->value,
                'enabled'       => false,
                'revoked_at'    => now(),
            ],
        );

        return $permission;
    }

    /**
     * Bulk update permissions from module settings payload.
     *
     * @param array<string, bool> $permissions  e.g. ['products' => true, 'orders' => true, 'customers' => false]
     */
    public function updatePermissionsFromModule(
        SyncConnection $connection,
        array $permissions,
        string $grantedBy = 'module_settings',
    ): void {
        foreach ($permissions as $entityValue => $enabled) {
            $entity = SyncEntity::tryFrom($entityValue);
            if ($entity === null) {
                continue;
            }

            if ($enabled) {
                $this->grantPermission($connection, $entity, $grantedBy);
            } else {
                $this->revokePermission($connection, $entity);
            }
        }
    }

    /**
     * Get all permissions for a connection as an associative array.
     *
     * @return array<string, bool>
     */
    public function getPermissions(SyncConnection $connection): array
    {
        $permissions = SyncPermission::where('connection_id', $connection->id)->get();

        $result = [];
        foreach (SyncEntity::cases() as $entity) {
            $perm = $permissions->firstWhere('entity', $entity->value);
            $result[$entity->value] = $perm !== null && $perm->enabled;
        }

        return $result;
    }
}

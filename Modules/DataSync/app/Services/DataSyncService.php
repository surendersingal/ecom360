<?php

declare(strict_types=1);

namespace Modules\DataSync\Services;

use App\Events\IntegrationEvent;
use Illuminate\Support\Facades\Log;
use Modules\DataSync\Enums\Platform;
use Modules\DataSync\Enums\SyncEntity;
use Modules\DataSync\Models\SyncConnection;
use Modules\DataSync\Models\SyncLog;
use Modules\DataSync\Models\SyncedAbandonedCart;
use Modules\DataSync\Models\SyncedCategory;
use Modules\DataSync\Models\SyncedCustomer;
use Modules\DataSync\Models\SyncedInventory;
use Modules\DataSync\Models\SyncedOrder;
use Modules\DataSync\Models\SyncedPopupCapture;
use Modules\DataSync\Models\SyncedProduct;
use Modules\DataSync\Models\SyncedSalesData;
use Modules\DataSync\Services\Normalizers\CategoryNormalizer;
use Modules\DataSync\Services\Normalizers\CustomerNormalizer;
use Modules\DataSync\Services\Normalizers\InventoryNormalizer;
use Modules\DataSync\Services\Normalizers\OrderNormalizer;
use Modules\DataSync\Services\Normalizers\ProductNormalizer;

/**
 * Core orchestrator for all incoming sync data.
 *
 * Flow:
 *  1. Resolve the SyncConnection from the tenant + platform info.
 *  2. Check PermissionService for entity consent.
 *  3. Normalize data via the appropriate Normalizer.
 *  4. Upsert into MongoDB.
 *  5. Log the sync batch.
 *  6. Dispatch IntegrationEvent so other modules (Analytics, BI) can react.
 */
final class DataSyncService
{
    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly ProductNormalizer $productNormalizer,
        private readonly CategoryNormalizer $categoryNormalizer,
        private readonly OrderNormalizer $orderNormalizer,
        private readonly CustomerNormalizer $customerNormalizer,
        private readonly InventoryNormalizer $inventoryNormalizer,
    ) {}

    /*
    |----------------------------------------------------------------------
    | Connection Registration
    |----------------------------------------------------------------------
    */

    /**
     * Register or update a store connection (called by module handshake).
     *
     * @param  int    $tenantId
     * @param  array  $data  ['platform', 'store_url', 'store_name', 'store_id', 'platform_version', 'module_version', 'php_version', 'locale', 'currency', 'timezone']
     * @param  array<string, bool>  $permissions  ['products' => true, 'orders' => true, ...]
     * @return SyncConnection
     */
    public function registerConnection(int $tenantId, array $data, array $permissions = []): SyncConnection
    {
        $connection = SyncConnection::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'store_url' => $data['store_url'] ?? '',
                'store_id'  => (int) ($data['store_id'] ?? 0),
            ],
            [
                'platform'         => $data['platform'] ?? 'custom',
                'platform_version' => $data['platform_version'] ?? null,
                'module_version'   => $data['module_version'] ?? null,
                'store_name'       => $data['store_name'] ?? null,
                'php_version'      => $data['php_version'] ?? null,
                'locale'           => $data['locale'] ?? 'en_US',
                'currency'         => $data['currency'] ?? 'USD',
                'timezone'         => $data['timezone'] ?? 'UTC',
                'is_active'        => true,
                'last_heartbeat_at' => now(),
            ],
        );

        // Initialize default permissions + apply module settings.
        $this->permissionService->initializePermissions($connection);
        if (!empty($permissions)) {
            $this->permissionService->updatePermissionsFromModule($connection, $permissions, 'module_settings');
        }

        IntegrationEvent::dispatch('datasync', 'connection.registered', [
            'tenant_id'     => $tenantId,
            'connection_id' => $connection->id,
            'platform'      => $connection->platform->value,
            'store_url'     => $connection->store_url,
        ]);

        return $connection;
    }

    /**
     * Resolve the SyncConnection for a tenant + request payload.
     */
    public function resolveConnection(int $tenantId, string $platform, int $storeId = 0): ?SyncConnection
    {
        return SyncConnection::where('tenant_id', $tenantId)
            ->where('platform', $platform)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve or auto-create a connection for incoming sync data.
     */
    public function resolveOrCreateConnection(int $tenantId, array $meta): SyncConnection
    {
        $platform = $meta['platform'] ?? 'custom';
        $storeId  = (int) ($meta['store_id'] ?? 0);

        $connection = $this->resolveConnection($tenantId, $platform, $storeId);

        if ($connection === null) {
            $connection = $this->registerConnection($tenantId, array_merge($meta, [
                'store_url' => $meta['store_url'] ?? 'unknown',
            ]));
        } else {
            $connection->update(['last_heartbeat_at' => now()]);
        }

        return $connection;
    }

    /*
    |----------------------------------------------------------------------
    | Sync Methods — one per entity
    |----------------------------------------------------------------------
    */

    /**
     * Sync products (public — no consent required).
     *
     * @param  int    $tenantId
     * @param  array  $payload  ['products' => [...], 'platform' => '...', 'store_id' => 0]
     * @return array  Sync result summary
     */
    public function syncProducts(int $tenantId, array $payload): array
    {
        return $this->syncEntity(
            $tenantId,
            $payload,
            SyncEntity::Products,
            'products',
            fn (array $items, Platform $p) => $this->productNormalizer->normalizeBatch($items, $p),
            SyncedProduct::class,
        );
    }

    /**
     * Sync categories (public — no consent required).
     */
    public function syncCategories(int $tenantId, array $payload): array
    {
        return $this->syncEntity(
            $tenantId,
            $payload,
            SyncEntity::Categories,
            'categories',
            fn (array $items, Platform $p) => $this->categoryNormalizer->normalizeBatch($items, $p),
            SyncedCategory::class,
        );
    }

    /**
     * Sync orders (restricted — requires consent).
     */
    public function syncOrders(int $tenantId, array $payload): array
    {
        return $this->syncEntity(
            $tenantId,
            $payload,
            SyncEntity::Orders,
            'orders',
            fn (array $items, Platform $p) => $this->orderNormalizer->normalizeBatch($items, $p),
            SyncedOrder::class,
        );
    }

    /**
     * Sync customers (sensitive — requires full PII consent).
     */
    public function syncCustomers(int $tenantId, array $payload): array
    {
        return $this->syncEntity(
            $tenantId,
            $payload,
            SyncEntity::Customers,
            'customers',
            fn (array $items, Platform $p) => $this->customerNormalizer->normalizeBatch($items, $p),
            SyncedCustomer::class,
        );
    }

    /**
     * Sync inventory (public — no consent required).
     */
    public function syncInventory(int $tenantId, array $payload): array
    {
        return $this->syncEntity(
            $tenantId,
            $payload,
            SyncEntity::Inventory,
            'items',
            fn (array $items, Platform $p) => $this->inventoryNormalizer->normalizeBatch($items, $p),
            SyncedInventory::class,
        );
    }

    /**
     * Sync aggregated sales data (public — no PII).
     */
    public function syncSales(int $tenantId, array $payload): array
    {
        $startMs    = hrtime(true);
        $connection = $this->resolveOrCreateConnection($tenantId, $payload);
        $platform   = Platform::tryFrom($payload['platform'] ?? '') ?? Platform::Custom;

        if (!$this->permissionService->isEntityAllowed($connection, SyncEntity::Sales)) {
            return $this->permissionDeniedResult(SyncEntity::Sales);
        }

        $items   = $payload['sales_data'] ?? [];
        $created = 0;
        $updated = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($items as $row) {
            try {
                $existing = SyncedSalesData::where('tenant_id', (string) $tenantId)
                    ->where('date', $row['date'] ?? '')
                    ->where('platform', $platform->value)
                    ->first();

                $doc = array_merge($row, [
                    'tenant_id' => (string) $tenantId,
                    'platform'  => $platform->value,
                    'store_id'  => (int) ($payload['store_id'] ?? 0),
                    'currency'  => $payload['currency'] ?? $row['currency'] ?? 'USD',
                    'synced_at' => now(),
                ]);

                if ($existing) {
                    $existing->update($doc);
                    $updated++;
                } else {
                    SyncedSalesData::create($doc);
                    $created++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $e->getMessage();
            }
        }

        $durationMs = (int) ((hrtime(true) - $startMs) / 1_000_000);

        $this->logSync($connection, SyncEntity::Sales, count($items), $created, $updated, $failed, $errors, $durationMs);

        $this->dispatchSyncEvent($tenantId, SyncEntity::Sales, $created, $updated, $platform);

        return $this->buildResult(SyncEntity::Sales, count($items), $created, $updated, $failed, $errors);
    }

    /**
     * Sync abandoned carts (restricted — requires consent).
     */
    public function syncAbandonedCarts(int $tenantId, array $payload): array
    {
        $startMs    = hrtime(true);
        $connection = $this->resolveOrCreateConnection($tenantId, $payload);
        $platform   = Platform::tryFrom($payload['platform'] ?? '') ?? Platform::Custom;

        if (!$this->permissionService->isEntityAllowed($connection, SyncEntity::AbandonedCarts)) {
            return $this->permissionDeniedResult(SyncEntity::AbandonedCarts);
        }

        $items   = $payload['abandoned_carts'] ?? [];
        $created = 0;
        $updated = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($items as $row) {
            try {
                $externalId = (string) ($row['quote_id'] ?? $row['cart_id'] ?? $row['id'] ?? '');

                $existing = SyncedAbandonedCart::where('tenant_id', (string) $tenantId)
                    ->where('external_id', $externalId)
                    ->where('platform', $platform->value)
                    ->first();

                $doc = [
                    'tenant_id'        => (string) $tenantId,
                    'platform'         => $platform->value,
                    'external_id'      => $externalId,
                    'store_id'         => (int) ($payload['store_id'] ?? 0),
                    'customer_email'   => $row['customer_email'] ?? null,
                    'customer_name'    => $row['customer_name'] ?? null,
                    'customer_id'      => isset($row['customer_id']) ? (string) $row['customer_id'] : null,
                    'grand_total'      => (float) ($row['grand_total'] ?? 0),
                    'items_count'      => (int) ($row['items_count'] ?? 0),
                    'items'            => $row['items'] ?? [],
                    'status'           => $row['status'] ?? 'abandoned',
                    'email_sent'       => (bool) ($row['email_sent'] ?? false),
                    'abandoned_at'     => $row['abandoned_at'] ?? null,
                    'last_activity_at' => $row['last_activity_at'] ?? null,
                    'synced_at'        => now(),
                ];

                if ($existing) {
                    $existing->update($doc);
                    $updated++;
                } else {
                    SyncedAbandonedCart::create($doc);
                    $created++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $e->getMessage();
            }
        }

        $durationMs = (int) ((hrtime(true) - $startMs) / 1_000_000);
        $this->logSync($connection, SyncEntity::AbandonedCarts, count($items), $created, $updated, $failed, $errors, $durationMs);
        $this->dispatchSyncEvent($tenantId, SyncEntity::AbandonedCarts, $created, $updated, $platform);

        return $this->buildResult(SyncEntity::AbandonedCarts, count($items), $created, $updated, $failed, $errors);
    }

    /**
     * Sync popup captures (sensitive — requires PII consent).
     */
    public function syncPopupCaptures(int $tenantId, array $payload): array
    {
        $startMs    = hrtime(true);
        $connection = $this->resolveOrCreateConnection($tenantId, $payload);
        $platform   = Platform::tryFrom($payload['platform'] ?? '') ?? Platform::Custom;

        if (!$this->permissionService->isEntityAllowed($connection, SyncEntity::PopupCaptures)) {
            return $this->permissionDeniedResult(SyncEntity::PopupCaptures);
        }

        $items   = $payload['captures'] ?? [];
        $created = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($items as $row) {
            try {
                SyncedPopupCapture::create([
                    'tenant_id'   => (string) $tenantId,
                    'platform'    => $platform->value,
                    'store_id'    => (int) ($payload['store_id'] ?? 0),
                    'session_id'  => $row['session_id'] ?? null,
                    'customer_id' => isset($row['customer_id']) ? (string) $row['customer_id'] : null,
                    'name'        => $row['name'] ?? null,
                    'email'       => $row['email'] ?? null,
                    'phone'       => $row['phone'] ?? null,
                    'dob'         => $row['dob'] ?? null,
                    'extra_data'  => $row['extra_data'] ?? null,
                    'page_url'    => $row['page_url'] ?? null,
                    'captured_at' => $row['captured_at'] ?? null,
                    'synced_at'   => now(),
                ]);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $e->getMessage();
            }
        }

        $durationMs = (int) ((hrtime(true) - $startMs) / 1_000_000);
        $this->logSync($connection, SyncEntity::PopupCaptures, count($items), $created, 0, $failed, $errors, $durationMs);
        $this->dispatchSyncEvent($tenantId, SyncEntity::PopupCaptures, $created, 0, $platform);

        return $this->buildResult(SyncEntity::PopupCaptures, count($items), $created, 0, $failed, $errors);
    }

    /*
    |----------------------------------------------------------------------
    | Generic Entity Sync Pipeline
    |----------------------------------------------------------------------
    */

    /**
     * @param  int           $tenantId
     * @param  array         $payload      Full request payload
     * @param  SyncEntity    $entity       Entity type
     * @param  string        $itemsKey     Key in payload containing the data array
     * @param  callable      $normalizer   fn(array $items, Platform $p): array
     * @param  class-string  $modelClass   MongoDB model class
     * @return array
     */
    private function syncEntity(
        int $tenantId,
        array $payload,
        SyncEntity $entity,
        string $itemsKey,
        callable $normalizer,
        string $modelClass,
    ): array {
        $startMs    = hrtime(true);
        $connection = $this->resolveOrCreateConnection($tenantId, $payload);
        $platform   = Platform::tryFrom($payload['platform'] ?? '') ?? Platform::Custom;

        // Permission check — public entities pass, restricted need consent.
        if (!$this->permissionService->isEntityAllowed($connection, $entity)) {
            return $this->permissionDeniedResult($entity);
        }

        $rawItems       = $payload[$itemsKey] ?? [];
        $normalizedItems = $normalizer($rawItems, $platform);

        $created = 0;
        $updated = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($normalizedItems as $item) {
            try {
                $externalId = $item['external_id'] ?? ($item['product_id'] ?? '');

                $existing = $modelClass::where('tenant_id', (string) $tenantId)
                    ->where('external_id', $externalId)
                    ->where('platform', $platform->value)
                    ->first();

                $doc = array_merge($item, [
                    'tenant_id' => (string) $tenantId,
                    'platform'  => $platform->value,
                    'store_id'  => (int) ($payload['store_id'] ?? 0),
                    'synced_at' => now(),
                ]);

                if ($existing) {
                    $existing->update($doc);
                    $updated++;
                } else {
                    $modelClass::create($doc);
                    $created++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = substr($e->getMessage(), 0, 200);
                Log::channel('single')->warning("DataSync {$entity->value} failed", [
                    'tenant_id' => $tenantId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $durationMs = (int) ((hrtime(true) - $startMs) / 1_000_000);

        $this->logSync($connection, $entity, count($rawItems), $created, $updated, $failed, $errors, $durationMs);

        $this->dispatchSyncEvent($tenantId, $entity, $created, $updated, $platform);

        return $this->buildResult($entity, count($rawItems), $created, $updated, $failed, $errors);
    }

    /*
    |----------------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------------
    */

    private function logSync(
        SyncConnection $connection,
        SyncEntity $entity,
        int $received,
        int $created,
        int $updated,
        int $failed,
        array $errors,
        int $durationMs,
    ): void {
        SyncLog::create([
            'tenant_id'        => $connection->tenant_id,
            'connection_id'    => $connection->id,
            'entity'           => $entity->value,
            'platform'         => $connection->platform->value,
            'direction'        => 'push',
            'status'           => $failed > 0 ? ($created + $updated > 0 ? 'partial' : 'failed') : 'completed',
            'records_received' => $received,
            'records_created'  => $created,
            'records_updated'  => $updated,
            'records_failed'   => $failed,
            'errors'           => empty($errors) ? null : $errors,
            'duration_ms'      => $durationMs,
        ]);
    }

    private function dispatchSyncEvent(int $tenantId, SyncEntity $entity, int $created, int $updated, Platform $platform): void
    {
        IntegrationEvent::dispatch('datasync', "sync.{$entity->value}.completed", [
            'tenant_id' => $tenantId,
            'entity'    => $entity->value,
            'platform'  => $platform->value,
            'created'   => $created,
            'updated'   => $updated,
        ]);
    }

    private function permissionDeniedResult(SyncEntity $entity): array
    {
        return [
            'success'  => false,
            'entity'   => $entity->value,
            'message'  => "Sync permission denied for '{$entity->label()}'. The store admin must enable this in their module settings.",
            'received' => 0,
            'created'  => 0,
            'updated'  => 0,
            'failed'   => 0,
        ];
    }

    private function buildResult(SyncEntity $entity, int $received, int $created, int $updated, int $failed, array $errors): array
    {
        return [
            'success'  => $failed === 0,
            'entity'   => $entity->value,
            'received' => $received,
            'created'  => $created,
            'updated'  => $updated,
            'failed'   => $failed,
            'errors'   => $errors,
        ];
    }
}

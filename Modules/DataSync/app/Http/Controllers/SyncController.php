<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\DataSync\Http\Requests\RegisterConnectionRequest;
use Modules\DataSync\Http\Requests\SyncAbandonedCartsRequest;
use Modules\DataSync\Http\Requests\SyncCategoriesRequest;
use Modules\DataSync\Http\Requests\SyncCustomersRequest;
use Modules\DataSync\Http\Requests\SyncInventoryRequest;
use Modules\DataSync\Http\Requests\SyncOrdersRequest;
use Modules\DataSync\Http\Requests\SyncPopupCapturesRequest;
use Modules\DataSync\Http\Requests\SyncProductsRequest;
use Modules\DataSync\Http\Requests\SyncSalesRequest;
use Modules\DataSync\Http\Requests\UpdatePermissionsRequest;
use Modules\DataSync\Services\DataSyncService;
use Modules\DataSync\Services\PermissionService;

/**
 * Receives sync data pushed by connected Magento modules / WP plugins.
 *
 * All routes are protected by ValidateSyncAuth middleware which validates
 * both X-Ecom360-Key and X-Ecom360-Secret headers.
 */
final class SyncController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DataSyncService $dataSyncService,
        private readonly PermissionService $permissionService,
    ) {}

    /*
    |----------------------------------------------------------------------
    | Connection Handshake
    |----------------------------------------------------------------------
    */

    /**
     * POST /api/v1/sync/register
     *
     * Called by the Magento module / WP plugin on first connection or
     * when the store admin changes sync settings. Returns the connection
     * details and current permissions.
     */
    public function register(RegisterConnectionRequest $request): JsonResponse
    {
        $tenantId    = (int) $request->input('_tenant_id');
        $data        = $request->validated();
        $permissions = $data['permissions'] ?? [];

        $connection = $this->dataSyncService->registerConnection($tenantId, $data, $permissions);

        return $this->successResponse([
            'connection_id' => $connection->id,
            'platform'      => $connection->platform->value,
            'store_url'     => $connection->store_url,
            'is_active'     => $connection->is_active,
            'permissions'   => $this->permissionService->getPermissions($connection),
        ], 'Connection registered successfully.');
    }

    /**
     * POST /api/v1/sync/heartbeat
     *
     * Lightweight health-check called periodically by the module.
     */
    public function heartbeat(\Illuminate\Http\Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $platform = $request->input('platform', 'custom');
        $storeId  = (int) $request->input('store_id', 0);

        $connection = $this->dataSyncService->resolveConnection($tenantId, $platform, $storeId);

        if ($connection === null) {
            return $this->errorResponse('No active connection found. Please register first.', 404);
        }

        $connection->update(['last_heartbeat_at' => now()]);

        return $this->successResponse([
            'connection_id' => $connection->id,
            'is_active'     => $connection->is_active,
            'permissions'   => $this->permissionService->getPermissions($connection),
        ]);
    }

    /**
     * POST /api/v1/sync/permissions
     *
     * Called when the store admin updates sync permissions in module settings.
     */
    public function updatePermissions(UpdatePermissionsRequest $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $platform = $request->input('platform', 'custom');
        $storeId  = (int) $request->input('store_id', 0);

        $connection = $this->dataSyncService->resolveConnection($tenantId, $platform, $storeId);

        if ($connection === null) {
            return $this->errorResponse('No active connection found. Please register first.', 404);
        }

        $this->permissionService->updatePermissionsFromModule(
            $connection,
            $request->input('permissions', []),
            'module_settings',
        );

        return $this->successResponse([
            'permissions' => $this->permissionService->getPermissions($connection),
        ], 'Permissions updated successfully.');
    }

    /*
    |----------------------------------------------------------------------
    | Sync Endpoints — Catalog (Public, no consent needed)
    |----------------------------------------------------------------------
    */

    /**
     * POST /api/v1/sync/products
     */
    public function syncProducts(SyncProductsRequest $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $result   = $this->dataSyncService->syncProducts($tenantId, $request->all());

        return $result['success'] !== false
            ? $this->successResponse($result, 'Products synced.')
            : $this->errorResponse($result['message'] ?? 'Sync failed.', 403);
    }

    /**
     * POST /api/v1/sync/categories
     */
    public function syncCategories(SyncCategoriesRequest $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $result   = $this->dataSyncService->syncCategories($tenantId, $request->all());

        return $result['success'] !== false
            ? $this->successResponse($result, 'Categories synced.')
            : $this->errorResponse($result['message'] ?? 'Sync failed.', 403);
    }

    /**
     * POST /api/v1/sync/inventory
     */
    public function syncInventory(SyncInventoryRequest $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $result   = $this->dataSyncService->syncInventory($tenantId, $request->all());

        return $result['success'] !== false
            ? $this->successResponse($result, 'Inventory synced.')
            : $this->errorResponse($result['message'] ?? 'Sync failed.', 403);
    }

    /**
     * POST /api/v1/sync/sales
     */
    public function syncSales(SyncSalesRequest $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $result   = $this->dataSyncService->syncSales($tenantId, $request->all());

        return $result['success'] !== false
            ? $this->successResponse($result, 'Sales data synced.')
            : $this->errorResponse($result['message'] ?? 'Sync failed.', 403);
    }

    /*
    |----------------------------------------------------------------------
    | Sync Endpoints — Customer Data (Restricted / Sensitive, needs consent)
    |----------------------------------------------------------------------
    */

    /**
     * POST /api/v1/sync/orders
     *
     * Restricted — client must enable "sync_orders" in module settings.
     */
    public function syncOrders(SyncOrdersRequest $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $result   = $this->dataSyncService->syncOrders($tenantId, $request->all());

        return $result['success'] !== false
            ? $this->successResponse($result, 'Orders synced.')
            : $this->errorResponse($result['message'] ?? 'Sync permission denied.', 403);
    }

    /**
     * POST /api/v1/sync/customers
     *
     * Sensitive — client must enable "sync_customers" in module settings.
     */
    public function syncCustomers(SyncCustomersRequest $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $result   = $this->dataSyncService->syncCustomers($tenantId, $request->all());

        return $result['success'] !== false
            ? $this->successResponse($result, 'Customers synced.')
            : $this->errorResponse($result['message'] ?? 'Sync permission denied.', 403);
    }

    /**
     * POST /api/v1/sync/abandoned-carts
     *
     * Restricted — client must enable abandoned cart tracking.
     */
    public function syncAbandonedCarts(SyncAbandonedCartsRequest $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $result   = $this->dataSyncService->syncAbandonedCarts($tenantId, $request->all());

        return $result['success'] !== false
            ? $this->successResponse($result, 'Abandoned carts synced.')
            : $this->errorResponse($result['message'] ?? 'Sync permission denied.', 403);
    }

    /**
     * POST /api/v1/sync/popup-captures
     *
     * Sensitive — contains PII (email, phone, name).
     */
    public function syncPopupCaptures(SyncPopupCapturesRequest $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');
        $result   = $this->dataSyncService->syncPopupCaptures($tenantId, $request->all());

        return $result['success'] !== false
            ? $this->successResponse($result, 'Popup captures synced.')
            : $this->errorResponse($result['message'] ?? 'Sync permission denied.', 403);
    }

    /*
    |----------------------------------------------------------------------
    | Status / Read endpoints
    |----------------------------------------------------------------------
    */

    /**
     * GET /api/v1/sync/status
     *
     * Returns sync status overview for the authenticated tenant.
     */
    public function status(\Illuminate\Http\Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('_tenant_id');

        $connections = \Modules\DataSync\Models\SyncConnection::where('tenant_id', $tenantId)->get();

        $data = $connections->map(function ($conn) {
            $recentLogs = \Modules\DataSync\Models\SyncLog::where('connection_id', $conn->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['entity', 'status', 'records_received', 'records_created', 'records_updated', 'records_failed', 'duration_ms', 'created_at']);

            return [
                'connection_id'    => $conn->id,
                'platform'         => $conn->platform->value,
                'store_url'        => $conn->store_url,
                'store_name'       => $conn->store_name,
                'is_active'        => $conn->is_active,
                'last_heartbeat'   => $conn->last_heartbeat_at?->toIso8601String(),
                'permissions'      => $this->permissionService->getPermissions($conn),
                'recent_syncs'     => $recentLogs,
            ];
        });

        return $this->successResponse($data);
    }
}

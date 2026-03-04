<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\DataSync\Enums\ConsentLevel;
use Modules\DataSync\Enums\Platform;
use Modules\DataSync\Enums\SyncEntity;
use Modules\DataSync\Models\SyncConnection;
use Modules\DataSync\Models\SyncLog;
use Modules\DataSync\Models\SyncPermission;
use Modules\DataSync\Models\SyncedAbandonedCart;
use Modules\DataSync\Models\SyncedCategory;
use Modules\DataSync\Models\SyncedCustomer;
use Modules\DataSync\Models\SyncedInventory;
use Modules\DataSync\Models\SyncedOrder;
use Modules\DataSync\Models\SyncedPopupCapture;
use Modules\DataSync\Models\SyncedProduct;
use Modules\DataSync\Models\SyncedSalesData;
use Modules\DataSync\Services\DataSyncService;
use Modules\DataSync\Services\PermissionService;
use Tests\TestCase;

/**
 * Phase 15: DataSync Module — Platform Data Synchronization
 *
 * Tests UC111-UC125 — Connection registration, permission enforcement,
 * catalog sync (products, categories, inventory, sales), customer data
 * consent gating (orders, customers, abandoned carts), platform-aware
 * normalization (Magento vs WooCommerce), audit logging, heartbeat,
 * and IntegrationEvent dispatch.
 */
final class Phase15_DataSyncTest extends TestCase
{
    private Tenant $tenant;
    private User   $user;
    private string $apiKey;
    private string $secretKey;

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey    = 'ek_' . Str::random(48);
        $this->secretKey = 'sk_' . Str::random(48);

        $this->tenant = Tenant::create([
            'name'       => 'DataSync Test Tenant',
            'slug'       => 'ds-test-' . substr(md5((string) mt_rand()), 0, 8),
            'is_active'  => true,
            'api_key'    => $this->apiKey,
            'secret_key' => $this->secretKey,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'DS Tester',
            'email'     => 'ds-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

        // Clean MongoDB collections
        foreach ($this->mongoCollections() as $model) {
            $model::where('tenant_id', (string) $this->tenant->id)->delete();
            $model::where('tenant_id', $this->tenant->id)->delete();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->mongoCollections() as $model) {
            $model::where('tenant_id', (string) $this->tenant->id)->delete();
            $model::where('tenant_id', $this->tenant->id)->delete();
        }

        SyncLog::where('tenant_id', $this->tenant->id)->delete();
        SyncPermission::where('tenant_id', $this->tenant->id)->delete();
        SyncConnection::where('tenant_id', $this->tenant->id)->delete();
        $this->user->forceDelete();
        $this->tenant->forceDelete();

        parent::tearDown();
    }

    /** @return list<class-string> */
    private function mongoCollections(): array
    {
        return [
            SyncedProduct::class,
            SyncedCategory::class,
            SyncedOrder::class,
            SyncedCustomer::class,
            SyncedInventory::class,
            SyncedSalesData::class,
            SyncedAbandonedCart::class,
            SyncedPopupCapture::class,
        ];
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** Returns headers for server-to-server sync auth. */
    private function syncHeaders(): array
    {
        return [
            'X-Ecom360-Key'    => $this->apiKey,
            'X-Ecom360-Secret' => $this->secretKey,
        ];
    }

    /** Registers a Magento connection and returns it. */
    private function registerMagentoConnection(array $permissions = []): SyncConnection
    {
        $response = $this->postJson('/api/v1/sync/register', [
            'platform'         => 'magento2',
            'store_url'        => 'https://magento.example.com',
            'store_name'       => 'Test Magento Store',
            'store_id'         => 0,
            'platform_version' => '2.4.7',
            'module_version'   => '1.0.0',
            'php_version'      => '8.3.0',
            'locale'           => 'en_US',
            'currency'         => 'USD',
            'timezone'         => 'America/New_York',
            'permissions'      => $permissions,
        ], $this->syncHeaders());

        $response->assertStatus(200);

        return SyncConnection::where('tenant_id', $this->tenant->id)->first();
    }

    // ==================================================================
    //  UC111: Connection Registration — Magento Handshake
    // ==================================================================

    /** @test */
    public function uc111_magento_connection_registers_with_permissions(): void
    {
        $response = $this->postJson('/api/v1/sync/register', [
            'platform'         => 'magento2',
            'store_url'        => 'https://magento.example.com',
            'store_name'       => 'My Magento Store',
            'store_id'         => 0,
            'platform_version' => '2.4.7',
            'module_version'   => '1.0.0',
            'permissions'      => [
                'products'   => true,
                'categories' => true,
                'orders'     => true,
                'customers'  => false,
            ],
        ], $this->syncHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.platform', 'magento2')
            ->assertJsonPath('data.is_active', true);

        // Verify connection in DB
        $connection = SyncConnection::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($connection);
        $this->assertEquals(Platform::Magento2, $connection->platform);
        $this->assertEquals('https://magento.example.com', $connection->store_url);

        // Verify permissions: public entities auto-enabled, orders granted, customers denied
        $perms = $response->json('data.permissions');
        $this->assertTrue($perms['products']);
        $this->assertTrue($perms['categories']);
        $this->assertTrue($perms['inventory']);     // public = auto
        $this->assertTrue($perms['sales']);          // public = auto
        $this->assertTrue($perms['orders']);          // explicitly granted
        $this->assertFalse($perms['customers']);      // explicitly denied
    }

    // ==================================================================
    //  UC112: Auth Rejection — Missing/Invalid Auth
    // ==================================================================

    /** @test */
    public function uc112_sync_rejects_missing_auth_headers(): void
    {
        // No headers at all
        $response = $this->postJson('/api/v1/sync/products', [
            'products' => [['name' => 'Widget', 'price' => 9.99]],
        ]);
        $response->assertStatus(401);

        // API key only, no secret
        $response = $this->postJson('/api/v1/sync/products', [
            'products' => [['name' => 'Widget', 'price' => 9.99]],
        ], ['X-Ecom360-Key' => $this->apiKey]);
        $response->assertStatus(401);

        // Wrong secret
        $response = $this->postJson('/api/v1/sync/products', [
            'products' => [['name' => 'Widget', 'price' => 9.99]],
        ], [
            'X-Ecom360-Key'    => $this->apiKey,
            'X-Ecom360-Secret' => 'wrong_secret',
        ]);
        $response->assertStatus(403);
    }

    // ==================================================================
    //  UC113: Products Sync — Magento Payload (Public, No Consent)
    // ==================================================================

    /** @test */
    public function uc113_magento_products_sync_creates_and_updates(): void
    {
        $this->registerMagentoConnection();

        // First sync — create
        $response = $this->postJson('/api/v1/sync/products', [
            'products' => [
                [
                    'id'          => '101',
                    'sku'         => 'MAG-001',
                    'name'        => 'Magento Widget',
                    'price'       => 29.99,
                    'special_price' => 19.99,
                    'status'      => 'enabled',
                    'type'        => 'simple',
                    'url_key'     => 'magento-widget',
                    'categories'  => ['Electronics', 'Gadgets'],
                    'category_ids' => ['5', '10'],
                ],
                [
                    'id'   => '102',
                    'sku'  => 'MAG-002',
                    'name' => 'Magento Gizmo',
                    'price' => 49.99,
                ],
            ],
            'platform' => 'magento2',
            'store_id' => 0,
        ], $this->syncHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 0);

        // Verify in MongoDB
        $products = SyncedProduct::where('tenant_id', (string) $this->tenant->id)->get();
        $this->assertCount(2, $products);

        $widget = $products->firstWhere('sku', 'MAG-001');
        $this->assertEquals('101', $widget->external_id);
        $this->assertEquals(29.99, $widget->price);
        $this->assertEquals(19.99, $widget->special_price);
        $this->assertEquals(['Electronics', 'Gadgets'], $widget->categories);

        // Second sync — update existing + create new
        $response = $this->postJson('/api/v1/sync/products', [
            'products' => [
                [
                    'id'    => '101',
                    'sku'   => 'MAG-001',
                    'name'  => 'Magento Widget Pro',
                    'price' => 39.99,
                ],
                [
                    'id'    => '103',
                    'sku'   => 'MAG-003',
                    'name'  => 'Magento Doohickey',
                    'price' => 14.99,
                ],
            ],
            'platform' => 'magento2',
            'store_id' => 0,
        ], $this->syncHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1);

        // Verify update
        $updated = SyncedProduct::where('tenant_id', (string) $this->tenant->id)
            ->where('external_id', '101')->first();
        $this->assertEquals('Magento Widget Pro', $updated->name);
        $this->assertEquals(39.99, $updated->price);
    }

    // ==================================================================
    //  UC114: Categories Sync — Normalized Hierarchy
    // ==================================================================

    /** @test */
    public function uc114_categories_sync_preserves_hierarchy(): void
    {
        $this->registerMagentoConnection();

        $response = $this->postJson('/api/v1/sync/categories', [
            'categories' => [
                [
                    'id'          => '2',
                    'name'        => 'Default Category',
                    'is_active'   => true,
                    'level'       => 1,
                    'parent_id'   => '1',
                    'path'        => '1/2',
                ],
                [
                    'id'          => '5',
                    'name'        => 'Electronics',
                    'url_key'     => 'electronics',
                    'is_active'   => true,
                    'level'       => 2,
                    'parent_id'   => '2',
                    'path'        => '1/2/5',
                    'product_count' => 42,
                ],
            ],
            'platform' => 'magento2',
            'store_id' => 0,
        ], $this->syncHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 2);

        $cat = SyncedCategory::where('tenant_id', (string) $this->tenant->id)
            ->where('external_id', '5')->first();

        $this->assertEquals('Electronics', $cat->name);
        $this->assertEquals(2, $cat->level);
        $this->assertEquals('2', $cat->parent_id);
        $this->assertEquals('1/2/5', $cat->path);
        $this->assertEquals(42, $cat->product_count);
    }

    // ==================================================================
    //  UC115: Orders Sync — Restricted, Requires Consent
    // ==================================================================

    /** @test */
    public function uc115_orders_sync_blocked_without_consent(): void
    {
        // Register without orders permission
        $this->registerMagentoConnection(['orders' => false]);

        $response = $this->postJson('/api/v1/sync/orders', [
            'orders' => [
                [
                    'entity_id'  => '1001',
                    'order_id'   => '100000001',
                    'status'     => 'complete',
                    'grand_total' => 149.99,
                    'items'      => [],
                ],
            ],
            'platform' => 'magento2',
            'store_id' => 0,
        ], $this->syncHeaders());

        $response->assertStatus(403);

        // Verify NO orders stored
        $count = SyncedOrder::where('tenant_id', (string) $this->tenant->id)->count();
        $this->assertEquals(0, $count);
    }

    // ==================================================================
    //  UC116: Orders Sync — Granted Consent
    // ==================================================================

    /** @test */
    public function uc116_orders_sync_works_with_consent(): void
    {
        $this->registerMagentoConnection(['orders' => true]);

        $response = $this->postJson('/api/v1/sync/orders', [
            'orders' => [
                [
                    'entity_id'      => '1001',
                    'order_id'       => '100000001',
                    'status'         => 'complete',
                    'state'          => 'complete',
                    'grand_total'    => 149.99,
                    'subtotal'       => 129.99,
                    'tax_amount'     => 10.00,
                    'shipping_amount' => 10.00,
                    'discount_amount' => 0,
                    'total_qty'      => 2,
                    'currency'       => 'USD',
                    'payment_method' => 'stripe',
                    'customer_email' => 'buyer@example.com',
                    'customer_id'    => '5',
                    'is_guest'       => false,
                    'items' => [
                        ['product_id' => '101', 'sku' => 'MAG-001', 'name' => 'Widget', 'qty' => 2, 'price' => 29.99, 'row_total' => 59.98],
                    ],
                ],
            ],
            'platform' => 'magento2',
            'store_id' => 0,
        ], $this->syncHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 1);

        $order = SyncedOrder::where('tenant_id', (string) $this->tenant->id)->first();
        $this->assertEquals('1001', $order->external_id);
        $this->assertEquals(149.99, $order->grand_total);
        $this->assertEquals('buyer@example.com', $order->customer_email);
        $this->assertCount(1, $order->items);
    }

    // ==================================================================
    //  UC117: Customers Sync — Sensitive, Requires Full PII Consent
    // ==================================================================

    /** @test */
    public function uc117_customers_sync_gated_by_pii_consent(): void
    {
        // Without consent → blocked
        $this->registerMagentoConnection(['customers' => false]);

        $response = $this->postJson('/api/v1/sync/customers', [
            'customers' => [
                ['id' => '5', 'email' => 'john@example.com', 'firstname' => 'John', 'lastname' => 'Doe'],
            ],
            'platform' => 'magento2',
        ], $this->syncHeaders());

        $response->assertStatus(403);

        // Grant consent via permissions update
        $this->postJson('/api/v1/sync/permissions', [
            'permissions' => ['customers' => true],
            'platform'    => 'magento2',
        ], $this->syncHeaders())->assertStatus(200);

        // Now sync works
        $response = $this->postJson('/api/v1/sync/customers', [
            'customers' => [
                ['id' => '5', 'email' => 'john@example.com', 'firstname' => 'John', 'lastname' => 'Doe'],
            ],
            'platform' => 'magento2',
        ], $this->syncHeaders());

        $response->assertStatus(200)->assertJsonPath('data.created', 1);

        $customer = SyncedCustomer::where('tenant_id', (string) $this->tenant->id)->first();
        $this->assertEquals('john@example.com', $customer->email);
        $this->assertEquals('John', $customer->firstname);
    }

    // ==================================================================
    //  UC118: Inventory Sync — Public, No Consent
    // ==================================================================

    /** @test */
    public function uc118_inventory_sync_tracks_stock_levels(): void
    {
        $this->registerMagentoConnection();

        $response = $this->postJson('/api/v1/sync/inventory', [
            'items' => [
                ['product_id' => 101, 'sku' => 'MAG-001', 'name' => 'Widget', 'price' => 29.99, 'cost' => 12.50, 'qty' => 150, 'is_in_stock' => true, 'min_qty' => 10, 'low_stock' => false],
                ['product_id' => 102, 'sku' => 'MAG-002', 'name' => 'Gizmo', 'price' => 49.99, 'qty' => 3, 'is_in_stock' => true, 'min_qty' => 5, 'low_stock' => true],
            ],
            'platform' => 'magento2',
            'store_id' => 0,
        ], $this->syncHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 2);

        $inv = SyncedInventory::where('tenant_id', (string) $this->tenant->id)
            ->where('sku', 'MAG-002')->first();
        $this->assertEquals(3.0, $inv->qty);
        $this->assertTrue($inv->low_stock);
    }

    // ==================================================================
    //  UC119: Sales Data Sync — Aggregated Daily Stats
    // ==================================================================

    /** @test */
    public function uc119_sales_data_sync_upserts_daily_aggregates(): void
    {
        $this->registerMagentoConnection();

        $response = $this->postJson('/api/v1/sync/sales', [
            'sales_data' => [
                ['date' => '2026-02-20', 'total_orders' => 15, 'total_revenue' => 2499.99, 'avg_order_value' => 166.67, 'total_items' => 42],
                ['date' => '2026-02-21', 'total_orders' => 22, 'total_revenue' => 3150.00, 'avg_order_value' => 143.18, 'total_items' => 58],
            ],
            'platform' => 'magento2',
            'store_id' => 0,
            'currency' => 'USD',
        ], $this->syncHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 2);

        // Re-sync same date → should update
        $response2 = $this->postJson('/api/v1/sync/sales', [
            'sales_data' => [
                ['date' => '2026-02-20', 'total_orders' => 18, 'total_revenue' => 2899.99, 'avg_order_value' => 161.11, 'total_items' => 50],
            ],
            'platform' => 'magento2',
            'store_id' => 0,
            'currency' => 'USD',
        ], $this->syncHeaders());

        $response2->assertStatus(200)
            ->assertJsonPath('data.updated', 1);

        $salesRow = SyncedSalesData::where('tenant_id', (string) $this->tenant->id)
            ->where('date', '2026-02-20')->first();
        $this->assertEquals(18, $salesRow->total_orders);
        $this->assertEquals(2899.99, $salesRow->total_revenue);
    }

    // ==================================================================
    //  UC120: Abandoned Carts Sync — Restricted
    // ==================================================================

    /** @test */
    public function uc120_abandoned_carts_require_consent(): void
    {
        $this->registerMagentoConnection(['abandoned_carts' => false]);

        $response = $this->postJson('/api/v1/sync/abandoned-carts', [
            'abandoned_carts' => [
                ['quote_id' => 456, 'customer_email' => 'user@example.com', 'grand_total' => 89.99, 'items_count' => 3, 'status' => 'abandoned'],
            ],
            'platform' => 'magento2',
        ], $this->syncHeaders());

        $response->assertStatus(403);

        // Grant consent, try again
        $this->postJson('/api/v1/sync/permissions', [
            'permissions' => ['abandoned_carts' => true],
            'platform'    => 'magento2',
        ], $this->syncHeaders());

        $response2 = $this->postJson('/api/v1/sync/abandoned-carts', [
            'abandoned_carts' => [
                ['quote_id' => 456, 'customer_email' => 'user@example.com', 'grand_total' => 89.99, 'items_count' => 3, 'status' => 'abandoned'],
            ],
            'platform' => 'magento2',
        ], $this->syncHeaders());

        $response2->assertStatus(200)->assertJsonPath('data.created', 1);
    }

    // ==================================================================
    //  UC121: WooCommerce Products — Platform-Aware Normalization
    // ==================================================================

    /** @test */
    public function uc121_woocommerce_products_normalized_differently(): void
    {
        // Register as WooCommerce
        $this->postJson('/api/v1/sync/register', [
            'platform'  => 'woocommerce',
            'store_url' => 'https://woo.example.com',
            'store_name' => 'My WooShop',
        ], $this->syncHeaders())->assertStatus(200);

        $response = $this->postJson('/api/v1/sync/products', [
            'products' => [
                [
                    'id'                => 42,
                    'name'              => 'WooCommerce Tee',
                    'slug'              => 'woocommerce-tee',
                    'price'             => '24.99',
                    'regular_price'     => '29.99',
                    'sale_price'        => '24.99',
                    'status'            => 'publish',
                    'type'              => 'variable',
                    'categories'        => [['id' => 15, 'name' => 'Clothing']],
                    'images'            => [['src' => 'https://woo.example.com/tee.jpg']],
                    'short_description' => 'A nice tee',
                ],
            ],
            'platform' => 'woocommerce',
        ], $this->syncHeaders());

        $response->assertStatus(200)->assertJsonPath('data.created', 1);

        $product = SyncedProduct::where('tenant_id', (string) $this->tenant->id)->first();
        $this->assertEquals('42', $product->external_id);
        $this->assertEquals('woocommerce-tee', $product->url_key);      // slug → url_key
        $this->assertEquals(24.99, $product->special_price);              // sale_price → special_price
        $this->assertEquals(['Clothing'], $product->categories);          // nested → flat
        $this->assertEquals('https://woo.example.com/tee.jpg', $product->image_url);
        $this->assertEquals('woocommerce', $product->platform);
    }

    // ==================================================================
    //  UC122: Audit Logging — Every Sync Creates a Log Entry
    // ==================================================================

    /** @test */
    public function uc122_sync_operations_create_audit_logs(): void
    {
        $this->registerMagentoConnection();

        // Sync products
        $this->postJson('/api/v1/sync/products', [
            'products' => [
                ['id' => '1', 'name' => 'P1', 'price' => 10],
                ['id' => '2', 'name' => 'P2', 'price' => 20],
            ],
            'platform' => 'magento2',
        ], $this->syncHeaders());

        $log = SyncLog::where('tenant_id', $this->tenant->id)
            ->where('entity', 'products')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('push', $log->direction);
        $this->assertEquals('completed', $log->status);
        $this->assertEquals(2, $log->records_received);
        $this->assertEquals(2, $log->records_created);
        $this->assertEquals(0, $log->records_failed);
        $this->assertNotNull($log->duration_ms);
    }

    // ==================================================================
    //  UC123: Heartbeat — Keeps Connection Alive
    // ==================================================================

    /** @test */
    public function uc123_heartbeat_updates_last_seen(): void
    {
        $conn = $this->registerMagentoConnection();
        $this->assertNotNull($conn->last_heartbeat_at);

        sleep(1); // ensure timestamp difference

        $response = $this->postJson('/api/v1/sync/heartbeat', [
            'platform' => 'magento2',
            'store_id' => 0,
        ], $this->syncHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        $conn->refresh();
        $this->assertTrue($conn->last_heartbeat_at->isAfter(now()->subSeconds(5)));
    }

    // ==================================================================
    //  UC124: Popup Captures — Sensitive PII Consent
    // ==================================================================

    /** @test */
    public function uc124_popup_captures_require_sensitive_consent(): void
    {
        $this->registerMagentoConnection(['popup_captures' => false]);

        $response = $this->postJson('/api/v1/sync/popup-captures', [
            'captures' => [
                ['email' => 'lead@example.com', 'name' => 'Jane', 'page_url' => '/sale'],
            ],
            'platform' => 'magento2',
        ], $this->syncHeaders());

        $response->assertStatus(403);

        // Grant
        $this->postJson('/api/v1/sync/permissions', [
            'permissions' => ['popup_captures' => true],
            'platform'    => 'magento2',
        ], $this->syncHeaders());

        $response2 = $this->postJson('/api/v1/sync/popup-captures', [
            'captures' => [
                ['email' => 'lead@example.com', 'name' => 'Jane', 'page_url' => '/sale'],
            ],
            'platform' => 'magento2',
        ], $this->syncHeaders());

        $response2->assertStatus(200)->assertJsonPath('data.created', 1);

        $capture = SyncedPopupCapture::where('tenant_id', (string) $this->tenant->id)->first();
        $this->assertEquals('lead@example.com', $capture->email);
    }

    // ==================================================================
    //  UC125: Sync Status Endpoint — Overview
    // ==================================================================

    /** @test */
    public function uc125_status_endpoint_returns_sync_overview(): void
    {
        $this->registerMagentoConnection(['orders' => true]);

        // Do one sync to have log data
        $this->postJson('/api/v1/sync/products', [
            'products' => [['id' => '1', 'name' => 'P1', 'price' => 5]],
            'platform' => 'magento2',
        ], $this->syncHeaders());

        $response = $this->getJson('/api/v1/sync/status', $this->syncHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $connections = $response->json('data');
        $this->assertCount(1, $connections);
        $this->assertEquals('magento2', $connections[0]['platform']);
        $this->assertTrue($connections[0]['is_active']);
        $this->assertArrayHasKey('permissions', $connections[0]);
        $this->assertArrayHasKey('recent_syncs', $connections[0]);
        $this->assertNotEmpty($connections[0]['recent_syncs']);
    }

    // ==================================================================
    //  UC126: Permission Service — Public Auto-Grant, Restricted Default Off
    // ==================================================================

    /** @test */
    public function uc126_permission_service_enforces_consent_levels(): void
    {
        $permissionService = app(PermissionService::class);

        $conn = $this->registerMagentoConnection();

        // Public entities should be auto-allowed
        $this->assertTrue($permissionService->isEntityAllowed($conn, SyncEntity::Products));
        $this->assertTrue($permissionService->isEntityAllowed($conn, SyncEntity::Categories));
        $this->assertTrue($permissionService->isEntityAllowed($conn, SyncEntity::Inventory));
        $this->assertTrue($permissionService->isEntityAllowed($conn, SyncEntity::Sales));

        // Restricted/sensitive should be denied by default
        $this->assertFalse($permissionService->isEntityAllowed($conn, SyncEntity::Orders));
        $this->assertFalse($permissionService->isEntityAllowed($conn, SyncEntity::Customers));
        $this->assertFalse($permissionService->isEntityAllowed($conn, SyncEntity::AbandonedCarts));
        $this->assertFalse($permissionService->isEntityAllowed($conn, SyncEntity::PopupCaptures));

        // Grant orders
        $permissionService->grantPermission($conn, SyncEntity::Orders, 'test');
        $this->assertTrue($permissionService->isEntityAllowed($conn, SyncEntity::Orders));

        // Revoke orders
        $permissionService->revokePermission($conn, SyncEntity::Orders);
        $this->assertFalse($permissionService->isEntityAllowed($conn, SyncEntity::Orders));
    }

    // ==================================================================
    //  UC127: Magento → WooCommerce — Same Tenant, Two Platforms
    // ==================================================================

    /** @test */
    public function uc127_tenant_can_have_multiple_platform_connections(): void
    {
        // Register Magento
        $this->postJson('/api/v1/sync/register', [
            'platform'  => 'magento2',
            'store_url' => 'https://magento.example.com',
        ], $this->syncHeaders())->assertStatus(200);

        // Register WooCommerce
        $this->postJson('/api/v1/sync/register', [
            'platform'  => 'woocommerce',
            'store_url' => 'https://woo.example.com',
        ], $this->syncHeaders())->assertStatus(200);

        $connections = SyncConnection::where('tenant_id', $this->tenant->id)->get();
        $this->assertCount(2, $connections);

        // Sync products to each
        $this->postJson('/api/v1/sync/products', [
            'products' => [['id' => '1', 'name' => 'Magento Product', 'price' => 10]],
            'platform' => 'magento2',
        ], $this->syncHeaders())->assertStatus(200);

        $this->postJson('/api/v1/sync/products', [
            'products' => [['id' => '1', 'name' => 'WooCommerce Product', 'price' => 15]],
            'platform' => 'woocommerce',
        ], $this->syncHeaders())->assertStatus(200);

        $products = SyncedProduct::where('tenant_id', (string) $this->tenant->id)->get();
        $this->assertCount(2, $products);
        $this->assertCount(1, $products->where('platform', 'magento2'));
        $this->assertCount(1, $products->where('platform', 'woocommerce'));
    }

    // ==================================================================
    //  UC128: IntegrationEvent Dispatch — Cross-Module Communication
    // ==================================================================

    /** @test */
    public function uc128_sync_dispatches_integration_events(): void
    {
        $this->registerMagentoConnection();

        $dispatched = [];
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\IntegrationEvent::class,
            function (\App\Events\IntegrationEvent $e) use (&$dispatched) {
                if ($e->moduleName === 'datasync' && str_starts_with($e->eventName, 'sync.')) {
                    $dispatched[] = $e->eventName;
                }
            }
        );

        $this->postJson('/api/v1/sync/products', [
            'products' => [['id' => '1', 'name' => 'P1', 'price' => 5]],
            'platform' => 'magento2',
        ], $this->syncHeaders());

        $this->assertContains('sync.products.completed', $dispatched);
    }

    // ==================================================================
    //  UC129: Validation — Rejects Malformed Payloads
    // ==================================================================

    /** @test */
    public function uc129_sync_rejects_invalid_payloads(): void
    {
        $this->registerMagentoConnection();

        // Empty products array
        $response = $this->postJson('/api/v1/sync/products', [
            'products' => [],
            'platform' => 'magento2',
        ], $this->syncHeaders());
        $response->assertStatus(422);

        // Missing products key
        $response = $this->postJson('/api/v1/sync/products', [
            'platform' => 'magento2',
        ], $this->syncHeaders());
        $response->assertStatus(422);

        // Invalid platform
        $response = $this->postJson('/api/v1/sync/register', [
            'platform'  => 'invalid_platform',
            'store_url' => 'https://example.com',
        ], $this->syncHeaders());
        $response->assertStatus(422);
    }

    // ==================================================================
    //  UC130: Data Sync Service — Direct Service Invocation
    // ==================================================================

    /** @test */
    public function uc130_datasync_service_works_independently_of_http(): void
    {
        $service = app(DataSyncService::class);

        // Register connection via service
        $connection = $service->registerConnection($this->tenant->id, [
            'platform'  => 'magento2',
            'store_url' => 'https://magento-direct.example.com',
            'store_id'  => 0,
        ], ['orders' => true, 'customers' => true]);

        $this->assertNotNull($connection);
        $this->assertEquals(Platform::Magento2, $connection->platform);

        // Sync products directly
        $result = $service->syncProducts($this->tenant->id, [
            'products' => [
                ['id' => '999', 'name' => 'Direct Product', 'price' => 7.77, 'sku' => 'DIR-001'],
            ],
            'platform' => 'magento2',
            'store_url' => 'https://magento-direct.example.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['created']);

        // Sync orders (consented)
        $result = $service->syncOrders($this->tenant->id, [
            'orders' => [
                ['entity_id' => '5001', 'order_id' => '200000001', 'status' => 'pending', 'grand_total' => 55.00, 'items' => []],
            ],
            'platform' => 'magento2',
            'store_url' => 'https://magento-direct.example.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['created']);
    }
}

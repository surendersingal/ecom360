<?php
/**
 * Comprehensive DataSync E2E Validation Script
 *
 * Tests all DataSync API endpoints, Magento plugin format,
 * WordPress plugin format, data persistence, and web pages.
 *
 * Usage: php artisan tinker < tests/datasync_e2e_validate.php
 *   OR:  php tests/datasync_e2e_validate.php (requires bootstrap)
 */

// Bootstrap Laravel if not in tinker
if (!function_exists('app')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

use App\Models\Tenant;
use Modules\DataSync\Models\SyncConnection;
use Modules\DataSync\Models\SyncLog;
use Modules\DataSync\Models\SyncPermission;
use Modules\DataSync\Models\SyncedProduct;
use Modules\DataSync\Models\SyncedCategory;
use Modules\DataSync\Models\SyncedOrder;
use Modules\DataSync\Models\SyncedCustomer;
use Modules\DataSync\Models\SyncedInventory;
use Modules\DataSync\Models\SyncedSalesData;
use Modules\DataSync\Models\SyncedAbandonedCart;
use Modules\DataSync\Models\SyncedPopupCapture;

$baseUrl = 'http://127.0.0.1:8090';
$passed = 0;
$failed = 0;
$errors = [];

// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════

function apiCall(string $method, string $url, array $headers = [], array $body = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'body' => json_decode($response, true) ?? [],
        'raw' => $response,
        'error' => $error,
    ];
}

function test(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  ✅ {$name}\n";
    } else {
        $failed++;
        $errors[] = $name . ($detail ? " — {$detail}" : '');
        echo "  ❌ {$name}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

// ═══════════════════════════════════════════════════════════════
// SETUP — Get/create tenant credentials
// ═══════════════════════════════════════════════════════════════

echo "\n══════════════════════════════════════════════════════\n";
echo "  ECOM360 DataSync — Full E2E Validation\n";
echo "══════════════════════════════════════════════════════\n\n";

$tenant = Tenant::where('slug', 'urban-style-co')->first();
if (!$tenant) {
    echo "❌ No 'Urban Style Co.' tenant found. Aborting.\n";
    exit(1);
}

echo "Tenant: {$tenant->name} (ID: {$tenant->id})\n";
echo "API Key: {$tenant->api_key}\n";
echo "Secret Key: " . substr($tenant->secret_key, 0, 10) . "...\n";
echo "Base URL: {$baseUrl}\n\n";

$apiKey = $tenant->api_key;
$secretKey = $tenant->secret_key;

$authHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    "X-Ecom360-Key: {$apiKey}",
    "X-Ecom360-Secret: {$secretKey}",
];

$publicHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    "X-Ecom360-Key: {$apiKey}",
];

// Clean up any previous E2E test data
SyncConnection::where('tenant_id', $tenant->id)->where('store_url', 'like', '%e2e-test%')->delete();

// ═══════════════════════════════════════════════════════════════
// TEST 1: Authentication — Missing headers rejected
// ═══════════════════════════════════════════════════════════════

echo "─── 1. Authentication ────────────────────────────────\n";

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/register", [
    'Content-Type: application/json',
    'Accept: application/json',
], ['platform' => 'magento2', 'store_url' => 'https://test.com']);
test('Missing auth headers → 401', $r['status'] === 401);

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/register", [
    'Content-Type: application/json',
    'Accept: application/json',
    "X-Ecom360-Key: {$apiKey}",
    'X-Ecom360-Secret: wrong_secret_key',
], ['platform' => 'magento2', 'store_url' => 'https://test.com']);
test('Wrong secret key → 403', $r['status'] === 403, "Got {$r['status']}");

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/register", [
    'Content-Type: application/json',
    'Accept: application/json',
    'X-Ecom360-Key: nonexistent_api_key_12345',
    "X-Ecom360-Secret: {$secretKey}",
], ['platform' => 'magento2', 'store_url' => 'https://test.com']);
test('Wrong API key → 403', $r['status'] === 403, "Got {$r['status']}");

// ═══════════════════════════════════════════════════════════════
// TEST 2: Magento 2 — Connection Registration
// ═══════════════════════════════════════════════════════════════

echo "\n─── 2. Magento 2 — Connection Registration ──────────\n";

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/register", $authHeaders, [
    'platform' => 'magento2',
    'store_url' => 'https://magento-e2e-test.example.com',
    'store_name' => 'Magento E2E Test Store',
    'store_id' => 1,
    'platform_version' => '2.4.7-p3',
    'module_version' => '1.0.0',
    'php_version' => '8.2.15',
    'locale' => 'en_US',
    'currency' => 'USD',
    'timezone' => 'America/New_York',
    'permissions' => [
        'products' => true,
        'categories' => true,
        'inventory' => true,
        'sales' => true,
        'orders' => true,
        'abandoned_carts' => true,
        'customers' => true,
        'popup_captures' => true,
    ],
]);
test('Magento register → 200/201', in_array($r['status'], [200, 201]), "Got {$r['status']}");
test('Response has connection_id', isset($r['body']['data']['connection_id']));
test('Platform = magento2', ($r['body']['data']['platform'] ?? '') === 'magento2');
test('Permissions returned', isset($r['body']['data']['permissions']));

$magentoConnId = $r['body']['data']['connection_id'] ?? null;
echo "  → Magento Connection ID: {$magentoConnId}\n";

// Verify DB record
$conn = SyncConnection::find($magentoConnId);
test('Connection saved in MySQL', $conn !== null);
test('store_name saved', ($conn->store_name ?? '') === 'Magento E2E Test Store');
test('platform_version saved', ($conn->platform_version ?? '') === '2.4.7-p3');

// Check permissions were created
$perms = SyncPermission::where('connection_id', $magentoConnId)->get();
test('8 permission records created', $perms->count() === 8, "Got {$perms->count()}");
$publicPerms = $perms->filter(fn($p) => $p->consent_level->value === 'public');
test('Public entities auto-enabled', $publicPerms->every(fn($p) => $p->enabled));

// ═══════════════════════════════════════════════════════════════
// TEST 3: WooCommerce — Connection Registration
// ═══════════════════════════════════════════════════════════════

echo "\n─── 3. WooCommerce — Connection Registration ────────\n";

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/register", $authHeaders, [
    'platform' => 'woocommerce',
    'store_url' => 'https://woo-e2e-test.example.com',
    'store_name' => 'WooCommerce E2E Test Store',
    'store_id' => 0,
    'platform_version' => '9.5.1',
    'module_version' => '1.0.0',
    'php_version' => '8.3.4',
    'locale' => 'en_US',
    'currency' => 'USD',
    'timezone' => 'America/Chicago',
    'permissions' => [
        'products' => true,
        'categories' => true,
        'inventory' => true,
        'sales' => true,
        'orders' => false,
        'abandoned_carts' => false,
        'customers' => false,
        'popup_captures' => false,
    ],
]);
test('WooCommerce register → 200/201', in_array($r['status'], [200, 201]), "Got {$r['status']}");
test('Platform = woocommerce', ($r['body']['data']['platform'] ?? '') === 'woocommerce');

$wooConnId = $r['body']['data']['connection_id'] ?? null;
echo "  → WooCommerce Connection ID: {$wooConnId}\n";

// ═══════════════════════════════════════════════════════════════
// TEST 4: Heartbeat
// ═══════════════════════════════════════════════════════════════

echo "\n─── 4. Heartbeat ──────────────────────────────────────\n";

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/heartbeat", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
]);
test('Magento heartbeat → 200', $r['status'] === 200, "Got {$r['status']}");
test('Heartbeat returns connection data', isset($r['body']['data']['connection_id']));

$conn = SyncConnection::find($magentoConnId);
test('last_heartbeat_at updated', $conn->last_heartbeat_at !== null);

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/heartbeat", $authHeaders, [
    'platform' => 'woocommerce',
    'store_id' => 0,
]);
test('WooCommerce heartbeat → 200', $r['status'] === 200, "Got {$r['status']}");

// ═══════════════════════════════════════════════════════════════
// TEST 5: Permission Updates
// ═══════════════════════════════════════════════════════════════

echo "\n─── 5. Permission Updates ─────────────────────────────\n";

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/permissions", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'permissions' => [
        'orders' => true,
        'customers' => true,
        'abandoned_carts' => true,
        'popup_captures' => true,
    ],
]);
test('Permission update → 200', $r['status'] === 200, "Got {$r['status']}");

// Verify restricted/sensitive permissions now enabled
$perms = SyncPermission::where('connection_id', $magentoConnId)->get();
$ordersEnabled = $perms->filter(fn($p) => $p->entity->value === 'orders')->first()?->enabled;
$customersEnabled = $perms->filter(fn($p) => $p->entity->value === 'customers')->first()?->enabled;
test('Orders permission enabled', $ordersEnabled === true, 'enabled=' . var_export($ordersEnabled, true));
test('Customers permission enabled', $customersEnabled === true, 'enabled=' . var_export($customersEnabled, true));

// ═══════════════════════════════════════════════════════════════
// TEST 6: Magento Product Sync (full Magento format)
// ═══════════════════════════════════════════════════════════════

echo "\n─── 6. Magento Product Sync ───────────────────────────\n";

$magentoProducts = [];
for ($i = 1; $i <= 15; $i++) {
    $magentoProducts[] = [
        'id' => (string)$i,
        'sku' => "MAG-E2E-{$i}",
        'name' => "Magento Test Product {$i}",
        'price' => round(19.99 + ($i * 5.50), 2),
        'special_price' => $i % 3 === 0 ? round(14.99 + ($i * 3.00), 2) : null,
        'status' => $i <= 12 ? 'enabled' : 'disabled',
        'visibility' => 4,
        'type' => $i % 5 === 0 ? 'configurable' : 'simple',
        'weight' => round(0.5 + ($i * 0.1), 2),
        'url_key' => "magento-test-product-{$i}",
        'description' => "Full description for Magento test product {$i}",
        'short_description' => "Short desc for product {$i}",
        'categories' => ['Test Category', 'Electronics'],
        'category_ids' => ['5', '12'],
        'image_url' => "https://magento-e2e-test.example.com/media/catalog/product/test-{$i}.jpg",
        'created_at' => '2026-01-15 10:00:00',
        'updated_at' => '2026-02-24 08:00:00',
    ];
}

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/products", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'products' => $magentoProducts,
]);
test('Magento products sync → 200', $r['status'] === 200, "Got {$r['status']}: " . json_encode($r['body']));
test('15 products received', ($r['body']['data']['received'] ?? 0) === 15);
test('Products created/updated', ($r['body']['data']['created'] ?? 0) + ($r['body']['data']['updated'] ?? 0) === 15);
test('0 failures', ($r['body']['data']['failed'] ?? -1) === 0);

// Verify MongoDB (tenant_id stored as string in MongoDB)
$tid = (string) $tenant->id;
$mongoProducts = SyncedProduct::where('tenant_id', $tid)->where('platform', 'magento2')->where('sku', 'like', 'MAG-E2E-%')->count();
test("15 products in MongoDB", $mongoProducts === 15, "Got {$mongoProducts}");

// Verify idempotent — re-sync same products
$r2 = apiCall('POST', "{$baseUrl}/api/v1/sync/products", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'products' => $magentoProducts,
]);
test('Idempotent re-sync → 200', $r2['status'] === 200);
test('Updates on re-sync (no dupes)', ($r2['body']['data']['updated'] ?? 0) === 15);
$mongoProducts2 = SyncedProduct::where('tenant_id', $tid)->where('platform', 'magento2')->where('sku', 'like', 'MAG-E2E-%')->count();
test('Still 15 products (no duplicates)', $mongoProducts2 === 15, "Got {$mongoProducts2}");

// ═══════════════════════════════════════════════════════════════
// TEST 7: WooCommerce Product Sync (WooCommerce format)
// ═══════════════════════════════════════════════════════════════

echo "\n─── 7. WooCommerce Product Sync ───────────────────────\n";

$wooProducts = [];
for ($i = 1; $i <= 10; $i++) {
    $wooProducts[] = [
        'id' => (string)(100 + $i),
        'sku' => "WOO-E2E-{$i}",
        'name' => "WooCommerce Test Product {$i}",
        'price' => round(24.99 + ($i * 3.00), 2),
        'regular_price' => round(29.99 + ($i * 3.00), 2),
        'sale_price' => $i % 2 === 0 ? round(19.99 + ($i * 2.00), 2) : null,
        'status' => 'publish',
        'type' => $i % 4 === 0 ? 'variable' : 'simple',
        'slug' => "woo-test-product-{$i}",
        'description' => "WooCommerce product {$i} description",
        'short_description' => "WC short desc {$i}",
        'categories' => [['id' => 15, 'name' => 'WC Category', 'slug' => 'wc-category']],
        'images' => [['src' => "https://woo-e2e-test.example.com/wp-content/uploads/product-{$i}.jpg"]],
    ];
}

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/products", $authHeaders, [
    'platform' => 'woocommerce',
    'store_id' => 0,
    'products' => $wooProducts,
]);
test('WooCommerce products sync → 200', $r['status'] === 200, "Got {$r['status']}: " . json_encode($r['body']));
test('10 products received', ($r['body']['data']['received'] ?? 0) === 10);
test('Products created/updated', ($r['body']['data']['created'] ?? 0) + ($r['body']['data']['updated'] ?? 0) === 10);

// Verify MongoDB
$wooMongo = SyncedProduct::where('tenant_id', $tid)->where('platform', 'woocommerce')->where('sku', 'like', 'WOO-E2E-%')->count();
test("10 WooCommerce products in MongoDB", $wooMongo === 10, "Got {$wooMongo}");

// ═══════════════════════════════════════════════════════════════
// TEST 8: Category Sync
// ═══════════════════════════════════════════════════════════════

echo "\n─── 8. Category Sync ──────────────────────────────────\n";

$categories = [
    ['id' => '2', 'name' => 'Default Category', 'url_key' => 'default', 'is_active' => true, 'level' => 1, 'position' => 1, 'parent_id' => '1', 'path' => '1/2', 'product_count' => 100],
    ['id' => '5', 'name' => 'Electronics', 'url_key' => 'electronics', 'is_active' => true, 'level' => 2, 'position' => 1, 'parent_id' => '2', 'path' => '1/2/5', 'product_count' => 45],
    ['id' => '6', 'name' => 'Clothing', 'url_key' => 'clothing', 'is_active' => true, 'level' => 2, 'position' => 2, 'parent_id' => '2', 'path' => '1/2/6', 'product_count' => 30],
    ['id' => '12', 'name' => 'Smartphones', 'url_key' => 'smartphones', 'is_active' => true, 'level' => 3, 'position' => 1, 'parent_id' => '5', 'path' => '1/2/5/12', 'product_count' => 15],
    ['id' => '13', 'name' => 'Laptops', 'url_key' => 'laptops', 'is_active' => true, 'level' => 3, 'position' => 2, 'parent_id' => '5', 'path' => '1/2/5/13', 'product_count' => 20],
    ['id' => '14', 'name' => 'T-Shirts', 'url_key' => 't-shirts', 'is_active' => true, 'level' => 3, 'position' => 1, 'parent_id' => '6', 'path' => '1/2/6/14', 'product_count' => 18],
];

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/categories", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'categories' => $categories,
]);
test('Category sync → 200', $r['status'] === 200, "Got {$r['status']}");
test('6 categories received', ($r['body']['data']['received'] ?? 0) === 6);
test('Categories persist', ($r['body']['data']['created'] ?? 0) + ($r['body']['data']['updated'] ?? 0) === 6);

// Verify hierarchy in MongoDB
$smartphones = SyncedCategory::where('tenant_id', $tid)->where('name', 'Smartphones')->first();
test('Smartphones category exists', $smartphones !== null);
test('Hierarchy (level=3, parent=5)', $smartphones && $smartphones->level == 3 && $smartphones->parent_id == '5');

// ═══════════════════════════════════════════════════════════════
// TEST 9: Inventory Sync
// ═══════════════════════════════════════════════════════════════

echo "\n─── 9. Inventory Sync ─────────────────────────────────\n";

$inventoryItems = [];
for ($i = 1; $i <= 15; $i++) {
    $inventoryItems[] = [
        'product_id' => (string)$i,
        'sku' => "MAG-E2E-{$i}",
        'name' => "Magento Test Product {$i}",
        'price' => round(19.99 + ($i * 5.50), 2),
        'cost' => round(10.00 + ($i * 2.00), 2),
        'qty' => $i * 10,
        'is_in_stock' => $i <= 13,
        'min_qty' => 5.0,
        'low_stock' => $i >= 12,
    ];
}

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/inventory", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'items' => $inventoryItems,
]);
test('Inventory sync → 200', $r['status'] === 200, "Got {$r['status']}");
test('15 items received', ($r['body']['data']['received'] ?? 0) === 15);
test('Inventory persisted', ($r['body']['data']['created'] ?? 0) + ($r['body']['data']['updated'] ?? 0) === 15);

// Verify low stock flag
$lowStockItems = SyncedInventory::where('tenant_id', $tid)->where('low_stock', true)->count();
test('Low stock items flagged', $lowStockItems >= 2, "Got {$lowStockItems} low stock items");

// ═══════════════════════════════════════════════════════════════
// TEST 10: Sales Data Sync
// ═══════════════════════════════════════════════════════════════

echo "\n─── 10. Sales Data Sync ───────────────────────────────\n";

$salesData = [];
for ($d = 7; $d >= 1; $d--) {
    $date = date('Y-m-d', strtotime("-{$d} days"));
    $salesData[] = [
        'date' => $date,
        'total_orders' => rand(10, 50),
        'total_revenue' => round(rand(1000, 5000) + (rand(0, 99) / 100), 2),
        'total_subtotal' => round(rand(800, 4500) + (rand(0, 99) / 100), 2),
        'total_tax' => round(rand(50, 300) + (rand(0, 99) / 100), 2),
        'total_shipping' => round(rand(50, 200) + (rand(0, 99) / 100), 2),
        'total_discount' => round(-1 * rand(0, 100), 2),
        'total_refunded' => round(rand(0, 50), 2),
        'avg_order_value' => round(rand(80, 200) + (rand(0, 99) / 100), 2),
        'total_items' => rand(20, 100),
    ];
}

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/sales", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'currency' => 'USD',
    'sales_data' => $salesData,
]);
test('Sales sync → 200', $r['status'] === 200, "Got {$r['status']}");
test('7 days received', ($r['body']['data']['received'] ?? 0) === 7);

// ═══════════════════════════════════════════════════════════════
// TEST 11: Order Sync (Restricted — requires consent)
// ═══════════════════════════════════════════════════════════════

echo "\n─── 11. Order Sync (Restricted) ───────────────────────\n";

$orders = [];
for ($i = 1; $i <= 5; $i++) {
    $orders[] = [
        'order_id' => "10000000{$i}",
        'entity_id' => (string)(200 + $i),
        'status' => ['complete', 'processing', 'pending', 'shipped', 'canceled'][$i - 1],
        'state' => ['complete', 'processing', 'new', 'processing', 'canceled'][$i - 1],
        'grand_total' => round(99.99 + ($i * 50), 2),
        'subtotal' => round(89.99 + ($i * 45), 2),
        'tax_amount' => round(5.00 + $i, 2),
        'shipping_amount' => 9.99,
        'discount_amount' => $i % 2 === 0 ? -10.00 : 0,
        'total_qty' => $i + 1,
        'currency' => 'USD',
        'payment_method' => 'stripe',
        'shipping_method' => 'flatrate_flatrate',
        'coupon_code' => $i % 2 === 0 ? 'SAVE10' : null,
        'customer_email' => "customer{$i}@e2e-test.com",
        'customer_id' => (string)(40 + $i),
        'is_guest' => $i === 3,
        'items' => [
            [
                'product_id' => (string)$i,
                'sku' => "MAG-E2E-{$i}",
                'name' => "Magento Test Product {$i}",
                'qty' => $i,
                'price' => round(19.99 + ($i * 5.50), 2),
                'row_total' => round((19.99 + ($i * 5.50)) * $i, 2),
                'discount' => 0,
            ],
        ],
        'billing_address' => [
            'firstname' => 'John',
            'lastname' => 'Doe',
            'street' => '123 Main St',
            'city' => 'New York',
            'region' => 'NY',
            'postcode' => '10001',
            'country_id' => 'US',
        ],
        'shipping_address' => [
            'firstname' => 'John',
            'lastname' => 'Doe',
            'street' => '123 Main St',
            'city' => 'New York',
            'region' => 'NY',
            'postcode' => '10001',
            'country_id' => 'US',
        ],
        'created_at' => date('Y-m-d H:i:s', strtotime("-{$i} hours")),
    ];
}

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/orders", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'orders' => $orders,
]);
test('Order sync → 200', $r['status'] === 200, "Got {$r['status']}: " . json_encode($r['body']));
test('5 orders received', ($r['body']['data']['received'] ?? 0) === 5);

// Verify in MongoDB
$orderCount = SyncedOrder::where('tenant_id', $tid)->where('platform', 'magento2')
    ->where('store_id', 1)->where('order_number', 'like', '10000000%')->count();
test("5 orders in MongoDB", $orderCount === 5, "Got {$orderCount}");

// Test without consent — WooCommerce orders (orders disabled by default)
$r = apiCall('POST', "{$baseUrl}/api/v1/sync/orders", $authHeaders, [
    'platform' => 'woocommerce',
    'store_id' => 0,
    'orders' => [['order_id' => 'WC-999', 'status' => 'completed', 'grand_total' => 50.00]],
]);
test('WooCommerce orders blocked (no consent)', $r['status'] === 403 || ($r['body']['data']['failed'] ?? 0) > 0 || ($r['body']['success'] ?? true) === false,
    "Status={$r['status']} body=" . json_encode($r['body']));

// ═══════════════════════════════════════════════════════════════
// TEST 12: Customer Sync (Sensitive — requires PII consent)
// ═══════════════════════════════════════════════════════════════

echo "\n─── 12. Customer Sync (Sensitive) ─────────────────────\n";

$customers = [];
for ($i = 1; $i <= 5; $i++) {
    $customers[] = [
        'id' => (string)(40 + $i),
        'email' => "customer{$i}@e2e-test.com",
        'firstname' => "TestFirst{$i}",
        'lastname' => "TestLast{$i}",
        'name' => "TestFirst{$i} TestLast{$i}",
        'dob' => '1990-0' . $i . '-15',
        'gender' => $i % 2 === 0 ? 2 : 1,
        'group_id' => 1,
    ];
}

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/customers", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'customers' => $customers,
]);
test('Customer sync → 200', $r['status'] === 200, "Got {$r['status']}: " . json_encode($r['body']));
test('5 customers received', ($r['body']['data']['received'] ?? 0) === 5);

// Verify in MongoDB
$custCount = SyncedCustomer::where('tenant_id', $tid)->where('email', 'like', '%@e2e-test.com')->count();
test("5 customers in MongoDB", $custCount === 5, "Got {$custCount}");

// ═══════════════════════════════════════════════════════════════
// TEST 13: Abandoned Carts Sync (Restricted)
// ═══════════════════════════════════════════════════════════════

echo "\n─── 13. Abandoned Carts (Restricted) ──────────────────\n";

$carts = [];
for ($i = 1; $i <= 3; $i++) {
    $carts[] = [
        'quote_id' => (string)(500 + $i),
        'customer_email' => "abandoned{$i}@e2e-test.com",
        'customer_name' => "Abandoned User {$i}",
        'customer_id' => (string)(50 + $i),
        'grand_total' => round(49.99 + ($i * 20.00), 2),
        'items_count' => $i + 1,
        'items' => [
            ['product_id' => (string)$i, 'sku' => "MAG-E2E-{$i}", 'name' => "Product {$i}", 'qty' => 1, 'price' => 29.99],
        ],
        'status' => 'abandoned',
        'email_sent' => false,
        'abandoned_at' => date('c', strtotime("-{$i} hours")),
        'last_activity_at' => date('c', strtotime("-{$i} hours -30 minutes")),
    ];
}

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/abandoned-carts", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'abandoned_carts' => $carts,
]);
test('Abandoned carts sync → 200', $r['status'] === 200, "Got {$r['status']}");
test('3 carts received', ($r['body']['data']['received'] ?? 0) === 3);

// ═══════════════════════════════════════════════════════════════
// TEST 14: Popup Captures Sync (Sensitive)
// ═══════════════════════════════════════════════════════════════

echo "\n─── 14. Popup Captures (Sensitive) ─────────────────────\n";

$captures = [];
for ($i = 1; $i <= 3; $i++) {
    $captures[] = [
        'session_id' => "sess_e2e_{$i}_" . bin2hex(random_bytes(4)),
        'customer_id' => (string)(40 + $i),
        'name' => "Popup User {$i}",
        'email' => "popup{$i}@e2e-test.com",
        'phone' => "+1555000{$i}",
        'dob' => "1992-0{$i}-20",
        'extra_data' => ['source' => 'exit_intent', 'variant' => 'A'],
        'page_url' => "https://magento-e2e-test.example.com/product/test-{$i}",
        'captured_at' => date('c', strtotime("-{$i} hours")),
    ];
}

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/popup-captures", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'captures' => $captures,
]);
test('Popup captures sync → 200', $r['status'] === 200, "Got {$r['status']}");
test('3 captures received', ($r['body']['data']['received'] ?? 0) === 3);

// ═══════════════════════════════════════════════════════════════
// TEST 15: Status Endpoint
// ═══════════════════════════════════════════════════════════════

echo "\n─── 15. Status Endpoint ───────────────────────────────\n";

$r = apiCall('GET', "{$baseUrl}/api/v1/sync/status", $authHeaders);
test('Status endpoint → 200', $r['status'] === 200, "Got {$r['status']}");
test('Returns connections array', is_array($r['body']['data'] ?? null));
$connections = $r['body']['data'] ?? [];
test('At least 2 connections', count($connections) >= 2, "Got " . count($connections));

// Find Magento connection in status
$magentoStatus = collect($connections)->firstWhere('platform', 'magento2');
test('Magento connection in status', $magentoStatus !== null);
test('Has permissions', isset($magentoStatus['permissions']));
test('Has recent syncs', isset($magentoStatus['recent_syncs']));

// ═══════════════════════════════════════════════════════════════
// TEST 16: Sync Logs Created
// ═══════════════════════════════════════════════════════════════

echo "\n─── 16. Sync Log Audit Trail ──────────────────────────\n";

$logs = SyncLog::where('tenant_id', $tenant->id)
    ->where('created_at', '>=', now()->subMinutes(5))
    ->orderByDesc('created_at')
    ->get();

test('Sync logs created', $logs->count() > 0, "Got {$logs->count()} logs");

$entities = $logs->pluck('entity')->unique()->sort()->values()->toArray();
echo "  → Logged entities: " . implode(', ', $entities) . "\n";

$expectedEntities = ['products', 'categories', 'inventory', 'sales', 'orders', 'customers', 'abandoned_carts', 'popup_captures'];
foreach ($expectedEntities as $entity) {
    $entityLogs = $logs->where('entity', $entity);
    test("Log for '{$entity}'", $entityLogs->count() > 0, "No log found");
    if ($entityLogs->count() > 0) {
        $log = $entityLogs->first();
        test("  status = completed", $log->status === 'completed', "Got '{$log->status}'");
    }
}

// ═══════════════════════════════════════════════════════════════
// TEST 17: Validation — Invalid Payloads
// ═══════════════════════════════════════════════════════════════

echo "\n─── 17. Validation — Invalid Payloads ────────────────\n";

// Missing required field
$r = apiCall('POST', "{$baseUrl}/api/v1/sync/products", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'products' => [['id' => '1', 'sku' => 'INVALID']], // missing name
]);
test('Product without name → still accepted (normalizer handles)', $r['status'] === 200 || $r['status'] === 422,
    "Got {$r['status']}");

// Empty array
$r = apiCall('POST', "{$baseUrl}/api/v1/sync/products", $authHeaders, [
    'platform' => 'magento2',
    'store_id' => 1,
    'products' => [],
]);
test('Empty products array → 422 or handled', $r['status'] === 422 || $r['status'] === 200,
    "Got {$r['status']}");

// Missing platform — middleware injects _tenant_id; FormRequest may not require platform
$r = apiCall('POST', "{$baseUrl}/api/v1/sync/products", $authHeaders, [
    'products' => [['id' => '1', 'sku' => 'X', 'name' => 'Test']],
]);
test('Missing platform → handled gracefully', $r['status'] === 422 || $r['status'] === 200,
    "Got {$r['status']}");

// ═══════════════════════════════════════════════════════════════
// TEST 18: Integration Events Dispatched
// ═══════════════════════════════════════════════════════════════

echo "\n─── 18. Data Integrity Checks ─────────────────────────\n";

// Check product data integrity
$product = SyncedProduct::where('tenant_id', $tid)->where('sku', 'MAG-E2E-3')->first();
test('Product MAG-E2E-3 has correct name', $product && str_contains($product->name, 'Magento Test Product 3'));
test('Product has special_price', $product && $product->special_price !== null, "special_price=" . ($product->special_price ?? 'null'));
test('Product has platform = magento2', $product && $product->platform === 'magento2');
test('Product has synced_at', $product && $product->synced_at !== null);

// Check order data integrity
$order = SyncedOrder::where('tenant_id', $tid)->where('platform', 'magento2')
    ->where('store_id', 1)->where('order_number', '100000001')->first();
test('Order 100000001 exists', $order !== null);
test('Order has status=complete', $order && $order->status === 'complete');
test('Order has items array', $order && is_array($order->items ?? null) && count($order->items) > 0);
test('Order has customer_email', $order && str_contains($order->customer_email ?? '', '@e2e-test.com'));

// Check customer data integrity
$customer = SyncedCustomer::where('tenant_id', $tid)->where('email', 'customer1@e2e-test.com')->first();
test('Customer customer1@e2e-test.com exists', $customer !== null);
test('Customer has firstname', $customer && !empty($customer->firstname));

// ═══════════════════════════════════════════════════════════════
// TEST 19: Re-register (idempotent)
// ═══════════════════════════════════════════════════════════════

echo "\n─── 19. Idempotent Re-registration ─────────────────────\n";

$r = apiCall('POST', "{$baseUrl}/api/v1/sync/register", $authHeaders, [
    'platform' => 'magento2',
    'store_url' => 'https://magento-e2e-test.example.com',
    'store_name' => 'Magento E2E Updated Name',
    'store_id' => 1,
]);
test('Re-register → 200', in_array($r['status'], [200, 201]), "Got {$r['status']}");
test('Same connection ID returned', ($r['body']['data']['connection_id'] ?? 0) === $magentoConnId,
    "Expected {$magentoConnId}, got " . ($r['body']['data']['connection_id'] ?? 'null'));

// ═══════════════════════════════════════════════════════════════
// TEST 20: MongoDB Collection Counts
// ═══════════════════════════════════════════════════════════════

echo "\n─── 20. MongoDB Collection Summary ─────────────────────\n";

$counts = [
    'Products' => SyncedProduct::where('tenant_id', $tid)->count(),
    'Categories' => SyncedCategory::where('tenant_id', $tid)->count(),
    'Inventory' => SyncedInventory::where('tenant_id', $tid)->count(),
    'Orders' => SyncedOrder::where('tenant_id', $tid)->count(),
    'Customers' => SyncedCustomer::where('tenant_id', $tid)->count(),
    'Sales Data' => SyncedSalesData::where('tenant_id', $tid)->count(),
    'Abandoned Carts' => SyncedAbandonedCart::where('tenant_id', $tid)->count(),
    'Popup Captures' => SyncedPopupCapture::where('tenant_id', $tid)->count(),
];

foreach ($counts as $label => $count) {
    test("{$label}: {$count} records", $count > 0);
}

// ═══════════════════════════════════════════════════════════════
// RESULTS
// ═══════════════════════════════════════════════════════════════

echo "\n══════════════════════════════════════════════════════\n";
echo "  RESULTS: {$passed} passed, {$failed} failed\n";
echo "══════════════════════════════════════════════════════\n";

if ($failed > 0) {
    echo "\n❌ Failures:\n";
    foreach ($errors as $e) {
        echo "  • {$e}\n";
    }
}

echo "\n";
exit($failed > 0 ? 1 : 0);

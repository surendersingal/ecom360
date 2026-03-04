<?php
// Quick script to check incoming events from buildnetic.com
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Checking for events from buildnetic.com ===\n\n";

$events = DB::connection('mongodb')->table('tracking_events')
    ->where('url', 'regex', '/buildnetic/i')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

if ($events->isEmpty()) {
    echo "❌ No events from buildnetic.com found.\n\n";
} else {
    echo "✅ Found {$events->count()} events from buildnetic.com:\n";
    foreach ($events as $e) {
        $ea = (array)$e;
        echo "  [{$ea['event_type']}] {$ea['url']} | session: " . ($ea['session_id'] ?? 'n/a') . " | " . ($ea['created_at'] ?? '') . "\n";
        if (!empty($ea['metadata'])) {
            echo "    metadata: " . json_encode($ea['metadata'], JSON_UNESCAPED_SLASHES) . "\n";
        }
        if (!empty($ea['customer_identifier'])) {
            echo "    customer: " . json_encode($ea['customer_identifier']) . "\n";
        }
    }
    echo "\n";
}

echo "=== Last 5 events overall (any source) ===\n\n";

$recent = DB::connection('mongodb')->table('tracking_events')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

foreach ($recent as $e) {
    $ea = (array)$e;
    $url = $ea['url'] ?? 'no-url';
    $type = $ea['event_type'] ?? 'unknown';
    $ts = $ea['created_at'] ?? 'no-ts';
    $tenant = $ea['_tenant_id'] ?? 'no-tenant';
    echo "  [{$type}] tenant:{$tenant} | {$url} | {$ts}\n";
}

echo "\n=== Total event count ===\n";
echo "  Total: " . DB::connection('mongodb')->table('tracking_events')->count() . "\n";

// Check Laravel log for recent API errors
echo "\n=== Recent API log entries ===\n";
$logFile = storage_path('logs/laravel.log');
if (file_exists($logFile)) {
    $lines = array_slice(file($logFile), -30);
    $apiLines = array_filter($lines, fn($l) => stripos($l, 'collect') !== false || stripos($l, 'buildnetic') !== false || stripos($l, 'tracking') !== false);
    if (empty($apiLines)) {
        echo "  No relevant API log entries in last 30 lines.\n";
    } else {
        foreach ($apiLines as $l) echo "  " . trim($l) . "\n";
    }
} else {
    echo "  No log file found.\n";
}

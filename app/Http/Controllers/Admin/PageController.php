<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Analytics\Services\SessionAnalyticsService;
use Modules\Analytics\Services\TrackingService;

/**
 * Handles static admin pages: Analytics, System Health, Modules, Settings, etc.
 */
final class PageController extends Controller
{
    public function platformAnalytics(): View
    {
        $tenants = Tenant::where('is_active', true)->get();

        // Aggregate cross-tenant metrics
        $platformStats = ['total_events' => 0, 'total_sessions' => 0];
        $perTenant = [];

        foreach ($tenants as $t) {
            try {
                $traffic  = app(TrackingService::class)->aggregateTraffic((string) $t->id, '30d');
                $sessions = app(SessionAnalyticsService::class)->getSessionMetrics((string) $t->id, '30d');
                $perTenant[] = [
                    'name'     => $t->name,
                    'slug'     => $t->slug,
                    'events'   => $traffic['total_events'] ?? 0,
                    'sessions' => $sessions['total_sessions'] ?? 0,
                    'bounce'   => $sessions['bounce_rate'] ?? 0,
                ];
                $platformStats['total_events']   += $traffic['total_events'] ?? 0;
                $platformStats['total_sessions']  += $sessions['total_sessions'] ?? 0;
            } catch (\Throwable) {
                $perTenant[] = ['name' => $t->name, 'slug' => $t->slug, 'events' => 0, 'sessions' => 0, 'bounce' => 0];
            }
        }

        return view('admin.pages.platform-analytics', compact('tenants', 'platformStats', 'perTenant'));
    }

    public function tenantAnalytics(): View
    {
        $tenants = Tenant::withCount('users')->where('is_active', true)->get();

        $analytics = [];
        foreach ($tenants as $t) {
            try {
                $traffic = app(TrackingService::class)->aggregateTraffic((string) $t->id, '30d');
                $analytics[$t->id] = $traffic;
            } catch (\Throwable) {
                $analytics[$t->id] = ['total_events' => 0, 'unique_sessions' => 0];
            }
        }

        return view('admin.pages.tenant-analytics', compact('tenants', 'analytics'));
    }

    public function activityLog(): View
    {
        return view('admin.pages.activity-log');
    }

    public function systemHealth(): View
    {
        $health = [
            'php_version'   => PHP_VERSION,
            'laravel'       => app()->version(),
            'memory_usage'  => round(memory_get_usage(true) / 1048576, 2) . ' MB',
            'disk_free'     => round(disk_free_space('/') / 1073741824, 2) . ' GB',
        ];

        return view('admin.pages.system-health', compact('health'));
    }

    public function modules(): View
    {
        $modulesPath = base_path('modules_statuses.json');
        $modules = file_exists($modulesPath) ? json_decode(file_get_contents($modulesPath), true) : [];

        return view('admin.pages.modules', compact('modules'));
    }

    public function dataManagement(): View
    {
        return view('admin.pages.data-management');
    }

    public function settings(): View
    {
        return view('admin.pages.settings');
    }

    public function revenueOverview(): View
    {
        return view('admin.pages.revenue-overview');
    }

    public function roles(): View
    {
        return view('admin.pages.roles');
    }

    public function queueMonitor(): View
    {
        return view('admin.pages.queue-monitor');
    }

    public function eventBus(): View
    {
        return view('admin.pages.event-bus');
    }

    // UC50 – Cross-Tenant Benchmarking (Admin-level)
    public function crossTenantBenchmarking(): View
    {
        $tenants = Tenant::where('is_active', true)->get();

        $benchmarks = [];
        foreach ($tenants as $t) {
            try {
                $data = app(\Modules\Analytics\Services\AdvancedAnalyticsOpsService::class)
                    ->crossTenantBenchmarking((string) $t->id, '30d');
                $benchmarks[$t->id] = array_merge(['name' => $t->name, 'slug' => $t->slug], $data);
            } catch (\Throwable) {
                $benchmarks[$t->id] = ['name' => $t->name, 'slug' => $t->slug, 'benchmarks' => [], 'percentile' => 0];
            }
        }

        return view('admin.pages.cross-tenant-benchmarking', compact('tenants', 'benchmarks'));
    }

    public function datasyncOverview(): View
    {
        $connections = \Modules\DataSync\Models\SyncConnection::with('permissions')
            ->orderByDesc('last_heartbeat_at')->get();
        $recentLogs = \Modules\DataSync\Models\SyncLog::orderByDesc('created_at')->limit(50)->get();
        return view('admin.pages.datasync', compact('connections', 'recentLogs'));
    }
}

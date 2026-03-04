<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BusinessIntelligence\Services\AlertService;
use Modules\BusinessIntelligence\Services\KpiService;

/**
 * Periodically refreshes all KPIs and evaluates alerts for all tenants.
 * Scheduled to run every 15 minutes.
 */
final class RefreshKpisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct()
    {
        $this->queue = 'bi';
    }

    public function handle(KpiService $kpiService, AlertService $alertService): void
    {
        $tenants = DB::table('tenants')->pluck('id');

        foreach ($tenants as $tenantId) {
            try {
                $refreshed = $kpiService->refreshAll((int) $tenantId);
                $alerts = $alertService->evaluateAll((int) $tenantId);

                Log::debug("[RefreshKpisJob] Tenant #{$tenantId}: {$refreshed} KPIs refreshed, {$alerts['triggered']} alerts triggered");
            } catch (\Throwable $e) {
                Log::error("[RefreshKpisJob] Failed for tenant #{$tenantId}: {$e->getMessage()}");
            }
        }
    }
}

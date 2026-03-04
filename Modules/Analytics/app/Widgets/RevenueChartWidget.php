<?php

declare(strict_types=1);

namespace Modules\Analytics\Widgets;

use App\Contracts\WidgetInterface;
use Modules\Analytics\Services\RevenueAnalyticsService;

/**
 * Revenue Chart widget — resolves real revenue data from MongoDB
 * via the RevenueAnalyticsService. Used by the WidgetRegistry API.
 */
final class RevenueChartWidget implements WidgetInterface
{
    public function getName(): string
    {
        return 'Revenue Chart';
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'module'      => 'analytics',
            'description' => 'Displays revenue over a configurable time range.',
            'icon'        => 'chart-line',
            'default_w'   => 6,
            'default_h'   => 4,
            'category'    => 'Analytics',
        ];
    }

    /**
     * @param  array<string, mixed>  $params  Expected keys: tenant_id, date_range.
     * @return array<string, mixed>
     */
    public function resolveData(array $params): array
    {
        $tenantId  = $params['tenant_id'] ?? '';
        $dateRange = $params['date_range'] ?? '30d';

        if ($tenantId === '') {
            return ['total_revenue' => 0, 'chart_data' => [], 'error' => 'No tenant context.'];
        }

        $service = app(RevenueAnalyticsService::class);

        $dailyRevenue = $service->getDailyRevenue($tenantId, $dateRange);
        $comparison = $service->getRevenueComparison($tenantId, $dateRange);

        $chartData = array_map(fn (array $day) => [
            'date' => $day['date'] ?? $day['_id'] ?? '',
            'revenue' => (float) ($day['revenue'] ?? 0),
            'orders' => (int) ($day['orders'] ?? 0),
        ], $dailyRevenue);

        $totalRevenue = array_sum(array_column($chartData, 'revenue'));

        return [
            'total_revenue' => round($totalRevenue, 2),
            'chart_data'    => $chartData,
            'comparison'    => $comparison,
            'date_range'    => $dateRange,
        ];
    }
}

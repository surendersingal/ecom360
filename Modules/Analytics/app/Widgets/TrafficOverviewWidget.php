<?php

declare(strict_types=1);

namespace Modules\Analytics\Widgets;

use App\Contracts\WidgetInterface;
use Illuminate\Support\Facades\Auth;
use Modules\Analytics\Services\TrackingService;

/**
 * Dashboard widget: Traffic Overview.
 *
 * Exposes MongoDB aggregation data to the Core Dashboard Engine,
 * formatted for a Chart.js-compatible frontend.
 */
final class TrafficOverviewWidget implements WidgetInterface
{
    public function __construct(
        private readonly TrackingService $trackingService,
    ) {}

    public function getName(): string
    {
        return 'analytics.traffic_overview';
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'module'      => 'analytics',
            'description' => 'Displays unique sessions and event-type breakdown over a configurable time range.',
            'icon'        => 'chart-bar',
            'default_w'   => 6,
            'default_h'   => 4,
            'category'    => 'Analytics',
        ];
    }

    /**
     * Resolve live data from MongoDB aggregation, formatted for Chart.js.
     *
     * @param  array<string, mixed>  $params  Expected: date_range (e.g. '7d', '30d')
     * @return array<string, mixed>
     */
    public function resolveData(array $params): array
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        $tenantId  = (string) ($params['tenant_id'] ?? $user?->tenant_id ?? '');
        $dateRange = (string) ($params['date_range'] ?? '30d');

        if ($tenantId === '') {
            return [
                'error' => 'Unable to resolve tenant context.',
            ];
        }

        $aggregation = $this->trackingService->aggregateTraffic($tenantId, $dateRange);

        // Format for Chart.js (bar chart of event types)
        $labels   = array_keys($aggregation['event_type_breakdown']);
        $datasets = [
            [
                'label'           => 'Events by Type',
                'data'            => array_values($aggregation['event_type_breakdown']),
                'backgroundColor' => $this->generateColours(count($labels)),
            ],
        ];

        return [
            'unique_sessions' => $aggregation['unique_sessions'],
            'total_events'    => $aggregation['total_events'],
            'date_from'       => $aggregation['date_from'],
            'date_to'         => $aggregation['date_to'],
            'chart' => [
                'type'     => 'bar',
                'labels'   => $labels,
                'datasets' => $datasets,
            ],
        ];
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Generate a palette of RGBA colours for Chart.js bars.
     *
     * @return list<string>
     */
    private function generateColours(int $count): array
    {
        $palette = [
            'rgba(59,130,246,0.7)',   // blue
            'rgba(16,185,129,0.7)',   // green
            'rgba(245,158,11,0.7)',   // amber
            'rgba(239,68,68,0.7)',    // red
            'rgba(139,92,246,0.7)',   // violet
            'rgba(236,72,153,0.7)',   // pink
            'rgba(14,165,233,0.7)',   // sky
        ];

        return array_slice(
            array_merge($palette, $palette), // double to cover >7 types
            0,
            max($count, 1),
        );
    }
}

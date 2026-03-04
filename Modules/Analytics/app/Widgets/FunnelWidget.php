<?php

declare(strict_types=1);

namespace Modules\Analytics\Widgets;

use App\Contracts\WidgetInterface;
use Illuminate\Support\Facades\Auth;
use Modules\Analytics\Services\EcommerceFunnelService;

/**
 * Dashboard widget: eCommerce Funnel.
 *
 * Visualises the four-stage conversion funnel:
 *   product_view → add_to_cart → begin_checkout → purchase
 */
final class FunnelWidget implements WidgetInterface
{
    public function __construct(
        private readonly EcommerceFunnelService $funnelService,
    ) {}

    public function getName(): string
    {
        return 'analytics.funnel';
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'module'      => 'analytics',
            'description' => 'Shows conversion funnel from product views through to purchases with drop-off percentages.',
            'icon'        => 'funnel',
            'default_w'   => 6,
            'default_h'   => 4,
            'category'    => 'Analytics',
        ];
    }

    /**
     * @param  array<string, mixed> $params  Expected: tenant_id, date_range
     * @return array<string, mixed>
     */
    public function resolveData(array $params): array
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        $tenantId  = (string) ($params['tenant_id'] ?? $user?->tenant_id ?? '');
        $dateRange = (string) ($params['date_range'] ?? '30d');

        if ($tenantId === '') {
            return ['error' => 'Unable to resolve tenant context.'];
        }

        $funnel = $this->funnelService->getFunnelMetrics($tenantId, $dateRange);

        // Re-format for Chart.js horizontal bar
        $labels   = array_column($funnel['stages'], 'stage');
        $counts   = array_column($funnel['stages'], 'unique_sessions');
        $dropOffs = array_column($funnel['stages'], 'drop_off_pct');

        return [
            'chart' => [
                'type'     => 'bar',
                'labels'   => $labels,
                'datasets' => [
                    [
                        'label'           => 'Unique Sessions',
                        'data'            => $counts,
                        'backgroundColor' => ['#3B82F6', '#8B5CF6', '#F59E0B', '#10B981'],
                    ],
                ],
            ],
            'drop_offs'              => array_combine($labels, $dropOffs),
            'overall_conversion_pct' => $funnel['overall_conversion_pct'],
            'date_from'              => $funnel['date_from'],
            'date_to'                => $funnel['date_to'],
        ];
    }
}

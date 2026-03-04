<?php

declare(strict_types=1);

namespace Modules\Analytics\Widgets;

use App\Contracts\WidgetInterface;
use Illuminate\Support\Facades\Auth;
use MongoDB\Laravel\Connection;

/**
 * Dashboard widget: RFM Score Distribution.
 *
 * Groups all CustomerProfiles by their RFM segment label and returns
 * counts suitable for a pie / doughnut chart.
 */
final class RfmDistributionWidget implements WidgetInterface
{
    public function getName(): string
    {
        return 'analytics.rfm_distribution';
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'module'      => 'analytics',
            'description' => 'Shows the distribution of customers across RFM segments (VIP, Loyal, At Risk, Hibernating, Churned).',
            'icon'        => 'chart-pie',
            'default_w'   => 4,
            'default_h'   => 4,
            'category'    => 'Analytics',
        ];
    }

    /**
     * @param  array<string, mixed> $params  Expected: tenant_id, date_range (unused for RFM)
     * @return array<string, mixed>
     */
    public function resolveData(array $params): array
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        $tenantId = (string) ($params['tenant_id'] ?? $user?->tenant_id ?? '');

        if ($tenantId === '') {
            return ['error' => 'Unable to resolve tenant context.'];
        }

        /** @var Connection $mongo */
        $mongo = app('db')->connection('mongodb');
        $collection = $mongo->getCollection('customer_profiles');

        // Group by rfm_score and bucket into segments.
        $pipeline = [
            ['$match' => ['tenant_id' => $tenantId, 'rfm_score' => ['$exists' => true]]],
            [
                '$addFields' => [
                    'rfm_total' => [
                        '$sum' => [
                            '$map' => [
                                'input' => ['$split' => ['$rfm_score', '']],
                                'as'    => 'digit',
                                'in'    => ['$toInt' => '$$digit'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                '$addFields' => [
                    'segment' => [
                        '$switch' => [
                            'branches' => [
                                ['case' => ['$gte' => ['$rfm_total', 13]], 'then' => 'VIP'],
                                ['case' => ['$gte' => ['$rfm_total', 10]], 'then' => 'Loyal'],
                                ['case' => ['$gte' => ['$rfm_total', 7]],  'then' => 'At Risk'],
                                ['case' => ['$gte' => ['$rfm_total', 4]],  'then' => 'Hibernating'],
                            ],
                            'default' => 'Churned',
                        ],
                    ],
                ],
            ],
            [
                '$group' => [
                    '_id'   => '$segment',
                    'count' => ['$sum' => 1],
                ],
            ],
            ['$sort' => ['count' => -1]],
        ];

        $results  = iterator_to_array($collection->aggregate($pipeline));
        $labels   = array_column($results, '_id');
        $counts   = array_column($results, 'count');

        $colourMap = [
            'VIP'         => '#10B981',
            'Loyal'       => '#3B82F6',
            'At Risk'     => '#F59E0B',
            'Hibernating' => '#F97316',
            'Churned'     => '#EF4444',
        ];

        $colours = array_map(
            fn (string $label): string => $colourMap[$label] ?? '#6B7280',
            $labels,
        );

        return [
            'chart' => [
                'type'     => 'doughnut',
                'labels'   => $labels,
                'datasets' => [
                    [
                        'data'            => $counts,
                        'backgroundColor' => $colours,
                    ],
                ],
            ],
            'total_customers' => array_sum($counts),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use MongoDB\Laravel\Connection;
use Modules\Analytics\Models\CustomerProfile;

/**
 * Cohort analysis service — customer retention curves, repeat purchase
 * rates, and cohort-based revenue analysis.
 */
final class CohortAnalysisService
{
    /**
     * Monthly cohort retention matrix.
     *
     * Groups customers by their first-purchase month, then tracks how many
     * return to purchase in subsequent months.
     *
     * @return array{cohorts: array, retention_matrix: array, months: string[]}
     */
    public function getRetentionCohorts(int|string $tenantId, int $monthsBack = 6): array
    {
        $collection = $this->collection();

        $dateFrom = CarbonImmutable::now()->subMonths($monthsBack)->startOfMonth();
        $dateTo   = CarbonImmutable::now()->endOfDay();

        // Get first-purchase month + all purchase months per session→customer
        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
            ]],
            ['$group' => [
                '_id'          => '$session_id',
                'first_purchase' => ['$min' => '$created_at'],
                'purchase_dates' => ['$push' => '$created_at'],
            ]],
            ['$project' => [
                'cohort_month' => ['$dateToString' => ['format' => '%Y-%m', 'date' => '$first_purchase']],
                'active_months' => [
                    '$setUnion' => [[
                        '$map' => [
                            'input' => '$purchase_dates',
                            'as'    => 'dt',
                            'in'    => ['$dateToString' => ['format' => '%Y-%m', 'date' => '$$dt']],
                        ],
                    ]],
                ],
            ]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline, ['maxTimeMS' => 30000]));

        // Build cohort map
        $months = [];
        $cohortData = [];

        for ($i = 0; $i < $monthsBack; $i++) {
            $m = CarbonImmutable::now()->subMonths($monthsBack - 1 - $i)->format('Y-m');
            $months[] = $m;
            $cohortData[$m] = ['size' => 0, 'months' => array_fill(0, $monthsBack, 0)];
        }

        foreach ($results as $row) {
            $cohortMonth  = $row['cohort_month'] ?? '';
            $activeMonths = $row['active_months'] ?? [];

            if (! isset($cohortData[$cohortMonth])) {
                continue;
            }

            $cohortData[$cohortMonth]['size']++;

            foreach ($activeMonths as $am) {
                $monthIdx = array_search($am, $months, true);
                $cohortIdx = array_search($cohortMonth, $months, true);

                if ($monthIdx !== false && $cohortIdx !== false && $monthIdx >= $cohortIdx) {
                    $offset = $monthIdx - $cohortIdx;
                    if ($offset < $monthsBack) {
                        $cohortData[$cohortMonth]['months'][$offset]++;
                    }
                }
            }
        }

        // Build retention percentages
        $retentionMatrix = [];
        foreach ($months as $m) {
            $size = $cohortData[$m]['size'];
            $retentionMatrix[$m] = [
                'cohort_month' => $m,
                'cohort_size'  => $size,
                'retention'    => array_map(
                    fn ($count) => $size > 0 ? round(($count / $size) * 100, 1) : 0,
                    $cohortData[$m]['months'],
                ),
            ];
        }

        return [
            'months'           => $months,
            'retention_matrix' => array_values($retentionMatrix),
        ];
    }

    /**
     * Repeat purchase rate — percentage of customers who purchased more than once.
     */
    public function getRepeatPurchaseRate(int|string $tenantId, string $dateRange = '90d'): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        $collection = $this->collection();

        $pipeline = [
            ['$match' => [
                'tenant_id'  => $tenantId,
                'event_type' => 'purchase',
                'created_at' => [
                    '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                    '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                ],
            ]],
            ['$group' => [
                '_id'            => '$session_id',
                'purchase_count' => ['$sum' => 1],
                'total_spent'    => ['$sum' => '$metadata.order_total'],
            ]],
            ['$facet' => [
                'all'     => [['$count' => 'count']],
                'repeat'  => [
                    ['$match' => ['purchase_count' => ['$gt' => 1]]],
                    ['$count' => 'count'],
                ],
                'frequency_distribution' => [
                    ['$bucket' => [
                        'groupBy'    => '$purchase_count',
                        'boundaries' => [1, 2, 3, 4, 5, 10, 50],
                        'default'    => '50+',
                        'output'     => [
                            'count'   => ['$sum' => 1],
                            'revenue' => ['$sum' => '$total_spent'],
                        ],
                    ]],
                ],
            ]],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline, ['maxTimeMS' => 30000]));
        $facets  = $results[0] ?? [];

        $total  = (int) ($facets['all'][0]['count'] ?? 0);
        $repeat = (int) ($facets['repeat'][0]['count'] ?? 0);

        $distribution = [];
        $bucketLabels = ['1 order', '2 orders', '3 orders', '4 orders', '5-9 orders', '10-49 orders', '50+ orders'];
        foreach (($facets['frequency_distribution'] ?? []) as $i => $bucket) {
            $distribution[] = [
                'label'   => $bucketLabels[$i] ?? (string) $bucket['_id'],
                'count'   => (int) ($bucket['count'] ?? 0),
                'revenue' => round((float) ($bucket['revenue'] ?? 0), 2),
            ];
        }

        return [
            'total_customers'      => $total,
            'repeat_customers'     => $repeat,
            'one_time_customers'   => $total - $repeat,
            'repeat_purchase_rate' => $total > 0 ? round(($repeat / $total) * 100, 1) : 0,
            'frequency_distribution' => $distribution,
        ];
    }

    /**
     * Customer lifetime value distribution by RFM segment.
     */
    public function getClvBySegment(int|string $tenantId): array
    {
        $profiles = CustomerProfile::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('rfm_score')
            ->get(['rfm_score', 'rfm_details']);

        $segments = [
            'VIP'         => ['count' => 0, 'total_monetary' => 0],
            'Loyal'       => ['count' => 0, 'total_monetary' => 0],
            'At Risk'     => ['count' => 0, 'total_monetary' => 0],
            'Hibernating' => ['count' => 0, 'total_monetary' => 0],
            'Churned'     => ['count' => 0, 'total_monetary' => 0],
        ];

        foreach ($profiles as $profile) {
            $score   = (int) ($profile->rfm_score ?? 0);
            $monetary = (float) ($profile->rfm_details['monetary'] ?? 0);

            $segment = match (true) {
                $score >= 13 => 'VIP',
                $score >= 10 => 'Loyal',
                $score >= 7  => 'At Risk',
                $score >= 4  => 'Hibernating',
                default      => 'Churned',
            };

            if (isset($segments[$segment])) {
                $segments[$segment]['count']++;
                $segments[$segment]['total_monetary'] += $monetary;
            }
        }

        $result = [];
        foreach ($segments as $name => $data) {
            $result[] = [
                'segment'     => $name,
                'count'       => $data['count'],
                'avg_clv'     => $data['count'] > 0 ? round($data['total_monetary'] / $data['count'], 2) : 0,
                'total_value' => round($data['total_monetary'], 2),
            ];
        }

        return $result;
    }

    private function collection(): \MongoDB\Collection
    {
        /** @var Connection $mongo */
        $mongo = app('db')->connection('mongodb');

        return $mongo->getCollection('tracking_events');
    }

    private function parseDateRange(string $range): array
    {
        if (preg_match('/^(\d+)d$/', $range, $m)) {
            return [
                CarbonImmutable::now()->subDays((int) $m[1])->startOfDay(),
                CarbonImmutable::now()->endOfDay(),
            ];
        }

        if (str_contains($range, '|')) {
            [$from, $to] = explode('|', $range, 2);

            return [
                CarbonImmutable::parse($from)->startOfDay(),
                CarbonImmutable::parse($to)->endOfDay(),
            ];
        }

        return [
            CarbonImmutable::now()->subDays(90)->startOfDay(),
            CarbonImmutable::now()->endOfDay(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use MongoDB\Laravel\Connection;

/**
 * Calculates eCommerce funnel metrics using MongoDB Aggregation Pipelines.
 *
 * The standard four-stage funnel:
 *   product_view  →  add_to_cart  →  begin_checkout  →  purchase
 *
 * For each transition the service returns the absolute count and the exact
 * drop-off percentage so the BI module (or a dashboard widget) can render
 * a funnel visualisation out of the box.
 */
final class EcommerceFunnelService
{
    /**
     * The ordered list of funnel stages.
     *
     * @var list<string>
     */
    private const array FUNNEL_STAGES = [
        'product_view',
        'add_to_cart',
        'begin_checkout',
        'purchase',
    ];

    /**
     * Calculate the funnel metrics for a tenant over a date range.
     *
     * @param  int $tenantId
     * @param  string $dateRange  Supports '7d', '30d', '90d', or 'Y-m-d|Y-m-d'.
     *
     * @return array{
     *     stages: list<array{
     *         stage: string,
     *         unique_sessions: int,
     *         drop_off_pct: float,
     *     }>,
     *     overall_conversion_pct: float,
     *     date_from: string,
     *     date_to: string,
     * }
     */
    public function getFunnelMetrics(int|string $tenantId, string $dateRange = '30d'): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($dateRange);

        /** @var Connection $mongo */
        $mongo = app('db')->connection('mongodb');
        $collection = $mongo->getCollection('tracking_events');

        // ------------------------------------------------------------------
        // Build a $facet with one branch per funnel stage.
        // Each branch counts unique sessions that performed the event type.
        // ------------------------------------------------------------------
        $facetBranches = [];

        foreach (self::FUNNEL_STAGES as $stage) {
            $facetBranches[$stage] = [
                [
                    '$match' => ['event_type' => $stage],
                ],
                [
                    '$group' => ['_id' => '$session_id'],
                ],
                [
                    '$count' => 'unique_sessions',
                ],
            ];
        }

        $pipeline = [
            // Stage 1: Scope to tenant + date window.
            [
                '$match' => [
                    'tenant_id'  => $tenantId,
                    'event_type' => ['$in' => self::FUNNEL_STAGES],
                    'created_at' => [
                        '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                        '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
                    ],
                ],
            ],
            // Stage 2: Facet — one branch per funnel step.
            [
                '$facet' => $facetBranches,
            ],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline, ['maxTimeMS' => 30000]));
        $facets  = $results[0] ?? [];

        // ------------------------------------------------------------------
        // Extract counts and calculate stage-to-stage drop-offs.
        // ------------------------------------------------------------------
        $stageCounts = [];
        foreach (self::FUNNEL_STAGES as $stage) {
            $stageCounts[$stage] = (int) ($facets[$stage][0]['unique_sessions'] ?? 0);
        }

        $stages      = [];
        $previousCount = 0;

        foreach (self::FUNNEL_STAGES as $index => $stage) {
            $count = $stageCounts[$stage];

            $dropOffPct = 0.0;
            if ($index > 0 && $previousCount > 0) {
                $dropOffPct = round((1 - ($count / $previousCount)) * 100, 2);
            }

            $stages[] = [
                'stage'           => $stage,
                'unique_sessions' => $count,
                'drop_off_pct'    => $dropOffPct,
            ];

            $previousCount = $count;
        }

        // Overall conversion: product_view → purchase
        $topOfFunnel    = $stageCounts[self::FUNNEL_STAGES[0]];
        $bottomOfFunnel = $stageCounts[self::FUNNEL_STAGES[array_key_last(self::FUNNEL_STAGES)]];

        $overallConversion = $topOfFunnel > 0
            ? round(($bottomOfFunnel / $topOfFunnel) * 100, 2)
            : 0.0;

        return [
            'stages'                  => $stages,
            'overall_conversion_pct'  => $overallConversion,
            'date_from'               => $dateFrom->toDateString(),
            'date_to'                 => $dateTo->toDateString(),
        ];
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function parseDateRange(string $range): array
    {
        if (preg_match('/^(\d+)d$/', $range, $m)) {
            $days = (int) $m[1];
            return [
                CarbonImmutable::now()->subDays($days)->startOfDay(),
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
            CarbonImmutable::now()->subDays(30)->startOfDay(),
            CarbonImmutable::now()->endOfDay(),
        ];
    }
}

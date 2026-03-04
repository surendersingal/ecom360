<?php
declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AdvancedAnalyticsOpsService — Advanced analytics operations.
 *
 * UC46: Multi-Touch Attribution Modeler — custom attribution models
 * UC47: Session Journey Replay — reconstruct user sessions step by step
 * UC48: GDPR Purge Impact Simulator — preview impact before deletion
 * UC49: Form-Field Abandonment Heatmap — which fields cause dropout
 * UC50: Cross-Tenant Benchmarking — anonymized performance comparison
 */
class AdvancedAnalyticsOpsService
{
    /**
     * UC46: Multi-touch attribution — various models.
     */
    public function multiTouchAttribution(int|string $tenantId, string $model = 'linear', string $dateRange = '30d'): array
    {
        try {
            $days = (int) filter_var($dateRange, FILTER_SANITIZE_NUMBER_INT) ?: 30;

            // Get conversion events with touchpoint history
            $conversions = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event', 'purchase')
                ->where('created_at', '>=', now()->subDays($days)->toDateTimeString())
                ->get();

            $channelCredits = [];
            $totalRevenue = 0;

            foreach ($conversions as $conv) {
                $revenue = $conv['revenue'] ?? $conv['properties']['value'] ?? 0;
                $totalRevenue += $revenue;

                // Get touchpoints for this visitor
                $visitorId = $conv['visitor_id'] ?? null;
                if (!$visitorId) continue;

                $touchpoints = DB::connection('mongodb')
                    ->table('events')
                    ->where('tenant_id', $tenantId)
                    ->where('visitor_id', $visitorId)
                    ->where('created_at', '<=', $conv['created_at'])
                    ->where('created_at', '>=', now()->subDays($days * 2)->toDateTimeString())
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->map(fn($e) => $e['utm_source'] ?? $e['referrer_source'] ?? $e['channel'] ?? 'direct')
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                if (empty($touchpoints)) $touchpoints = ['direct'];

                $credits = $this->calculateAttribution($touchpoints, $revenue, $model);
                foreach ($credits as $channel => $credit) {
                    $channelCredits[$channel] = ($channelCredits[$channel] ?? 0) + $credit;
                }
            }

            arsort($channelCredits);

            $attribution = [];
            foreach ($channelCredits as $channel => $credit) {
                $attribution[] = [
                    'channel'        => $channel,
                    'credited_revenue' => round($credit, 2),
                    'share_pct'      => $totalRevenue > 0 ? round($credit / $totalRevenue * 100, 1) : 0,
                ];
            }

            return [
                'success'        => true,
                'model'          => $model,
                'period'         => $dateRange,
                'total_revenue'  => round($totalRevenue, 2),
                'conversions'    => $conversions->count(),
                'channels'       => $attribution,
                'available_models' => ['first_touch', 'last_touch', 'linear', 'time_decay', 'u_shaped', 'w_shaped'],
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedAnalyticsOps::attribution error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC47: Session journey replay — reconstruct a session step by step.
     */
    public function sessionJourneyReplay(int|string $tenantId, string $sessionId): array
    {
        try {
            $events = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('session_id', $sessionId)
                ->orderBy('created_at', 'asc')
                ->get();

            if ($events->isEmpty()) {
                return ['success' => false, 'error' => 'Session not found.'];
            }

            $timeline = [];
            $prevTime = null;

            foreach ($events as $event) {
                $currentTime = $event['created_at'] ?? null;
                $timeDelta = $prevTime && $currentTime
                    ? now()->parse($currentTime)->diffInSeconds($prevTime)
                    : 0;

                $timeline[] = [
                    'event'       => $event['event'] ?? 'unknown',
                    'timestamp'   => $currentTime,
                    'time_delta_s' => $timeDelta,
                    'page_url'    => $event['page_url'] ?? $event['url'] ?? null,
                    'page_title'  => $event['page_title'] ?? null,
                    'product_id'  => $event['product_id'] ?? null,
                    'product_name' => $event['product_name'] ?? $event['properties']['product_name'] ?? null,
                    'revenue'     => $event['revenue'] ?? $event['properties']['value'] ?? null,
                    'device'      => $event['device_type'] ?? null,
                    'properties'  => $event['properties'] ?? [],
                ];

                $prevTime = $currentTime;
            }

            $firstEvent = $events->first();
            $lastEvent = $events->last();
            $sessionDuration = now()->parse($lastEvent['created_at'])->diffInSeconds($firstEvent['created_at']);

            $converted = collect($events)->contains('event', 'purchase');
            $addedToCart = collect($events)->contains('event', 'add_to_cart');

            return [
                'success'          => true,
                'session_id'       => $sessionId,
                'visitor_id'       => $firstEvent['visitor_id'] ?? null,
                'customer_email'   => collect($events)->pluck('customer_email')->filter()->first(),
                'device'           => $firstEvent['device_type'] ?? 'unknown',
                'browser'          => $firstEvent['browser'] ?? 'unknown',
                'start_time'       => $firstEvent['created_at'],
                'end_time'         => $lastEvent['created_at'],
                'duration_seconds' => $sessionDuration,
                'event_count'      => $events->count(),
                'page_views'       => collect($events)->where('event', 'page_view')->count(),
                'converted'        => $converted,
                'added_to_cart'    => $addedToCart,
                'entry_page'       => $firstEvent['page_url'] ?? $firstEvent['url'] ?? null,
                'exit_page'        => $lastEvent['page_url'] ?? $lastEvent['url'] ?? null,
                'utm_source'       => $firstEvent['utm_source'] ?? null,
                'timeline'         => $timeline,
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedAnalyticsOps::journeyReplay error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC48: GDPR data purge impact simulator — preview what gets deleted.
     */
    public function gdprPurgeSimulator(int|string $tenantId, string $customerEmail): array
    {
        try {
            // Count all data associated with this customer
            $eventCount = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('customer_email', $customerEmail)
                ->count();

            $orderCount = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('customer_email', $customerEmail)
                ->count();

            $totalRevenue = DB::connection('mongodb')
                ->table('synced_orders')
                ->where('tenant_id', $tenantId)
                ->where('customer_email', $customerEmail)
                ->sum('total') ?? 0;

            $searchLogs = DB::connection('mongodb')
                ->table('search_logs')
                ->where('tenant_id', $tenantId)
                ->where('customer_email', $customerEmail)
                ->count();

            $customerProfile = DB::connection('mongodb')
                ->table('synced_customers')
                ->where('tenant_id', $tenantId)
                ->where('email', $customerEmail)
                ->first();

            $preferences = DB::connection('mongodb')
                ->table('customer_preferences')
                ->where('tenant_id', $tenantId)
                ->where('email', $customerEmail)
                ->count();

            $totalRecords = $eventCount + $orderCount + $searchLogs + ($customerProfile ? 1 : 0) + $preferences;

            // Impact assessment
            $segmentsAffected = DB::connection('mongodb')
                ->table('audience_segments')
                ->where('tenant_id', $tenantId)
                ->where('member_emails', $customerEmail)
                ->count();

            return [
                'success'     => true,
                'customer'    => $customerEmail,
                'simulation'  => true,
                'data_summary' => [
                    'events'        => $eventCount,
                    'orders'        => $orderCount,
                    'search_logs'   => $searchLogs,
                    'customer_profile' => $customerProfile ? 1 : 0,
                    'preferences'   => $preferences,
                    'total_records' => $totalRecords,
                ],
                'revenue_impact' => [
                    'historical_revenue' => round($totalRevenue, 2),
                    'note' => 'Revenue records will be anonymized (email removed), not deleted, for financial compliance.',
                ],
                'segments_affected' => $segmentsAffected,
                'purge_actions' => [
                    ['collection' => 'events', 'action' => 'DELETE', 'records' => $eventCount],
                    ['collection' => 'synced_orders', 'action' => 'ANONYMIZE', 'records' => $orderCount, 'note' => 'PII removed, amounts retained'],
                    ['collection' => 'search_logs', 'action' => 'DELETE', 'records' => $searchLogs],
                    ['collection' => 'synced_customers', 'action' => 'DELETE', 'records' => $customerProfile ? 1 : 0],
                    ['collection' => 'customer_preferences', 'action' => 'DELETE', 'records' => $preferences],
                ],
                'compliance_notes' => [
                    'Financial records will be anonymized per GDPR Art. 17(3)(b)',
                    'Purge is irreversible once confirmed',
                    'Export customer data (GDPR Art. 20) should be done first',
                    'Audit log entry will be created',
                ],
                'estimated_time' => $totalRecords > 10000 ? '2-5 minutes' : 'Under 30 seconds',
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedAnalyticsOps::gdprPurge error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC49: Form-field abandonment heatmap — which fields cause checkout dropout.
     */
    public function formFieldAbandonment(int|string $tenantId, string $dateRange = '30d'): array
    {
        try {
            $days = (int) filter_var($dateRange, FILTER_SANITIZE_NUMBER_INT) ?: 30;

            // Get form interaction events
            $formEvents = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->whereIn('event', ['form_field_focus', 'form_field_blur', 'form_field_error', 'form_abandon', 'checkout_step'])
                ->where('created_at', '>=', now()->subDays($days)->toDateTimeString())
                ->get();

            $fieldStats = [];
            $sessionFields = [];

            foreach ($formEvents as $event) {
                $field = $event['properties']['field_name'] ?? $event['field_name'] ?? null;
                $sessionId = $event['session_id'] ?? '';

                if (!$field) continue;

                if (!isset($fieldStats[$field])) {
                    $fieldStats[$field] = [
                        'interactions' => 0,
                        'errors'       => 0,
                        'time_spent_ms' => 0,
                        'abandons_on'  => 0,
                    ];
                }

                $fieldStats[$field]['interactions']++;

                if ($event['event'] === 'form_field_error') {
                    $fieldStats[$field]['errors']++;
                }

                if (isset($event['properties']['time_spent_ms'])) {
                    $fieldStats[$field]['time_spent_ms'] += $event['properties']['time_spent_ms'];
                }

                // Track last field before abandon
                if ($event['event'] === 'form_abandon') {
                    $lastField = $event['properties']['last_field'] ?? $field;
                    if (isset($fieldStats[$lastField])) {
                        $fieldStats[$lastField]['abandons_on']++;
                    }
                }
            }

            $totalInteractions = array_sum(array_column($fieldStats, 'interactions'));
            $heatmap = [];

            foreach ($fieldStats as $field => $stats) {
                $avgTimeMs = $stats['interactions'] > 0 ? $stats['time_spent_ms'] / $stats['interactions'] : 0;
                $errorRate = $stats['interactions'] > 0 ? $stats['errors'] / $stats['interactions'] * 100 : 0;
                $abandonRate = $stats['interactions'] > 0 ? $stats['abandons_on'] / $stats['interactions'] * 100 : 0;

                // Friction score: higher = more problematic
                $frictionScore = ($errorRate * 0.4) + ($abandonRate * 0.4) + (min(100, $avgTimeMs / 50) * 0.2);

                $heatmap[] = [
                    'field_name'     => $field,
                    'interactions'   => $stats['interactions'],
                    'error_count'    => $stats['errors'],
                    'error_rate'     => round($errorRate, 1),
                    'avg_time_ms'    => round($avgTimeMs),
                    'abandon_count'  => $stats['abandons_on'],
                    'abandon_rate'   => round($abandonRate, 1),
                    'friction_score' => round($frictionScore, 1),
                    'heat_level'     => $frictionScore > 60 ? 'hot' : ($frictionScore > 30 ? 'warm' : 'cool'),
                ];
            }

            usort($heatmap, fn($a, $b) => $b['friction_score'] <=> $a['friction_score']);

            return [
                'success'        => true,
                'period'         => $dateRange,
                'total_form_events' => $formEvents->count(),
                'fields_tracked' => count($heatmap),
                'heatmap'        => $heatmap,
                'top_friction_fields' => array_slice(array_filter($heatmap, fn($f) => $f['heat_level'] === 'hot'), 0, 5),
                'recommendations' => $this->formRecommendations($heatmap),
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedAnalyticsOps::formAbandonment error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC50: Cross-tenant anonymized benchmarking.
     * NOTE: Admin-only feature for platform-level insights.
     */
    public function crossTenantBenchmarking(null|int|string $tenantId = null): array
    {
        try {
            // Get all tenants for benchmarking
            $tenants = DB::connection('mongodb')
                ->table('synced_orders')
                ->distinct('tenant_id');

            $benchmarks = [];
            foreach ($tenants as $tid) {
                $orders = DB::connection('mongodb')
                    ->table('synced_orders')
                    ->where('tenant_id', $tid)
                    ->where('created_at', '>=', now()->subDays(30)->toDateTimeString())
                    ->get();

                $revenue = $orders->sum('total');
                $orderCount = $orders->count();
                $uniqueCustomers = $orders->unique('customer_email')->count();

                $events = DB::connection('mongodb')
                    ->table('events')
                    ->where('tenant_id', $tid)
                    ->where('created_at', '>=', now()->subDays(30)->toDateTimeString())
                    ->count();

                $benchmarks[] = [
                    'tenant_id'       => $tid,
                    'is_current'      => $tid === $tenantId,
                    'revenue_30d'     => round($revenue, 2),
                    'orders_30d'      => $orderCount,
                    'aov'             => $orderCount > 0 ? round($revenue / $orderCount, 2) : 0,
                    'unique_customers' => $uniqueCustomers,
                    'events_30d'      => $events,
                    'conversion_rate' => $events > 0 ? round($orderCount / $events * 100, 2) : 0,
                ];
            }

            // Calculate percentiles
            $revenueValues = collect($benchmarks)->pluck('revenue_30d')->sort()->values();
            $aovValues = collect($benchmarks)->pluck('aov')->sort()->values();
            $conversionValues = collect($benchmarks)->pluck('conversion_rate')->sort()->values();

            $industryAvg = [
                'avg_revenue'         => round($revenueValues->avg(), 2),
                'median_revenue'      => round($revenueValues->median(), 2),
                'avg_aov'             => round($aovValues->avg(), 2),
                'avg_conversion_rate' => round($conversionValues->avg(), 2),
            ];

            // Rank current tenant
            $currentTenant = collect($benchmarks)->where('is_current', true)->first();
            $percentile = null;
            if ($currentTenant) {
                $belowCount = collect($benchmarks)->where('revenue_30d', '<', $currentTenant['revenue_30d'])->count();
                $percentile = count($benchmarks) > 0 ? round($belowCount / count($benchmarks) * 100) : null;
            }

            // Anonymize tenant IDs except current
            $anonymized = collect($benchmarks)->map(function ($b, $i) {
                if (!$b['is_current']) {
                    $b['tenant_id'] = 'Tenant ' . chr(65 + $i % 26);
                }
                return $b;
            })->toArray();

            return [
                'success'          => true,
                'tenant_count'     => count($benchmarks),
                'industry_avg'     => $industryAvg,
                'current_percentile' => $percentile,
                'current_tenant'   => $currentTenant,
                'benchmarks'       => $anonymized,
            ];
        } catch (\Exception $e) {
            Log::error("AdvancedAnalyticsOps::benchmarking error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Helpers ──────────────────────────────────────────

    private function calculateAttribution(array $touchpoints, float $revenue, string $model): array
    {
        $count = count($touchpoints);
        $credits = [];

        switch ($model) {
            case 'first_touch':
                $credits[$touchpoints[0]] = $revenue;
                break;

            case 'last_touch':
                $credits[end($touchpoints)] = $revenue;
                break;

            case 'linear':
                $share = $revenue / max(1, $count);
                foreach ($touchpoints as $tp) {
                    $credits[$tp] = ($credits[$tp] ?? 0) + $share;
                }
                break;

            case 'time_decay':
                $totalWeight = 0;
                for ($i = 0; $i < $count; $i++) {
                    $totalWeight += pow(2, $i);
                }
                for ($i = 0; $i < $count; $i++) {
                    $weight = pow(2, $i) / max(1, $totalWeight);
                    $credits[$touchpoints[$i]] = ($credits[$touchpoints[$i]] ?? 0) + ($revenue * $weight);
                }
                break;

            case 'u_shaped':
                if ($count === 1) {
                    $credits[$touchpoints[0]] = $revenue;
                } elseif ($count === 2) {
                    $credits[$touchpoints[0]] = ($credits[$touchpoints[0]] ?? 0) + $revenue * 0.5;
                    $credits[$touchpoints[1]] = ($credits[$touchpoints[1]] ?? 0) + $revenue * 0.5;
                } else {
                    $credits[$touchpoints[0]] = ($credits[$touchpoints[0]] ?? 0) + $revenue * 0.4;
                    $credits[end($touchpoints)] = ($credits[end($touchpoints)] ?? 0) + $revenue * 0.4;
                    $midShare = ($revenue * 0.2) / ($count - 2);
                    for ($i = 1; $i < $count - 1; $i++) {
                        $credits[$touchpoints[$i]] = ($credits[$touchpoints[$i]] ?? 0) + $midShare;
                    }
                }
                break;

            case 'w_shaped':
                if ($count <= 3) {
                    return $this->calculateAttribution($touchpoints, $revenue, 'linear');
                }
                $midIndex = (int) floor($count / 2);
                $credits[$touchpoints[0]] = ($credits[$touchpoints[0]] ?? 0) + $revenue * 0.3;
                $credits[$touchpoints[$midIndex]] = ($credits[$touchpoints[$midIndex]] ?? 0) + $revenue * 0.3;
                $credits[end($touchpoints)] = ($credits[end($touchpoints)] ?? 0) + $revenue * 0.3;
                $remainingShare = ($revenue * 0.1) / max(1, $count - 3);
                for ($i = 1; $i < $count - 1; $i++) {
                    if ($i !== $midIndex) {
                        $credits[$touchpoints[$i]] = ($credits[$touchpoints[$i]] ?? 0) + $remainingShare;
                    }
                }
                break;

            default:
                return $this->calculateAttribution($touchpoints, $revenue, 'linear');
        }

        return $credits;
    }

    private function formRecommendations(array $heatmap): array
    {
        $recs = [];
        foreach ($heatmap as $field) {
            if ($field['heat_level'] !== 'hot') continue;
            $fieldName = $field['field_name'];
            if (str_contains($fieldName, 'phone')) {
                $recs[] = "Consider making '{$fieldName}' optional — high abandonment detected.";
            } elseif (str_contains($fieldName, 'address')) {
                $recs[] = "Add address auto-complete for '{$fieldName}' to reduce friction.";
            } elseif (str_contains($fieldName, 'email')) {
                $recs[] = "Add real-time email validation for '{$fieldName}'.";
            } else {
                $recs[] = "'{$fieldName}' has high friction ({$field['friction_score']}). Consider simplifying or providing help text.";
            }
        }
        return $recs ?: ['All form fields appear healthy.'];
    }
}

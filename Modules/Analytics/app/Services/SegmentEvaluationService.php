<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\AudienceSegment;

/**
 * Segment evaluation engine — processes rule-based segments against
 * MongoDB tracking data and customer profiles.
 *
 * Supports three segment types:
 *  - Visitor Segments: based on session behavior (pages visited, events, duration)
 *  - Customer Segments: based on RFM scores, purchase history, profile data
 *  - Traffic Segments: based on source, geography, device, campaign
 */
final class SegmentEvaluationService
{
    /**
     * Evaluate a single segment and return matching count.
     */
    public function evaluateSegment(AudienceSegment $segment): int
    {
        $rules = $segment->rules ?? [];
        $tenantId = (string) $segment->tenant_id;

        if (empty($rules)) {
            return 0;
        }

        $segmentType = $this->detectSegmentType($rules);

        return match ($segmentType) {
            'visitor'  => $this->evaluateVisitorSegment($tenantId, $rules),
            'customer' => $this->evaluateCustomerSegment($tenantId, $rules),
            'traffic'  => $this->evaluateTrafficSegment($tenantId, $rules),
            default    => $this->evaluateGenericSegment($tenantId, $rules),
        };
    }

    /**
     * Evaluate all active segments for a tenant and update member counts.
     */
    public function evaluateAllSegments(int|string $tenantId): array
    {
        $segments = AudienceSegment::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $results = [];

        foreach ($segments as $segment) {
            try {
                $count = $this->evaluateSegment($segment);
                $segment->update(['member_count' => $count]);
                $results[$segment->name] = $count;
            } catch (\Throwable $e) {
                Log::warning("[Segments] Failed to evaluate '{$segment->name}': {$e->getMessage()}");
                $results[$segment->name] = -1;
            }
        }

        return $results;
    }

    /**
     * Get pre-built visitor segments with live counts.
     *
     * @return Collection<int, array{name: string, count: int, description: string, type: string}>
     */
    public function getVisitorSegments(int|string $tenantId): Collection
    {
        $mongo = DB::connection('mongodb');
        $now = CarbonImmutable::now();
        $thirtyDaysAgo = $now->subDays(30);

        $segments = collect();

        // New Visitors (first seen in last 7 days, single session)
        $newVisitors = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $now) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subDays(7)->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'           => '$session_id',
                        'event_count'   => ['$sum' => 1],
                        'first_seen'    => ['$min' => '$created_at'],
                    ]],
                    ['$match' => ['event_count' => ['$lte' => 3]]],
                    ['$count' => 'total'],
                ])->toArray();
            });

        $segments->push([
            'name'        => 'New Visitors',
            'count'       => $newVisitors[0]['total'] ?? 0,
            'description' => 'First-time visitors in the last 7 days with minimal activity',
            'type'        => 'visitor',
            'color'       => '#0ea5e9',
        ]);

        // Engaged Visitors (5+ events per session, 30d)
        $engaged = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $thirtyDaysAgo) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($thirtyDaysAgo->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'         => '$session_id',
                        'event_count' => ['$sum' => 1],
                    ]],
                    ['$match' => ['event_count' => ['$gte' => 5]]],
                    ['$count' => 'total'],
                ])->toArray();
            });

        $segments->push([
            'name'        => 'Engaged Visitors',
            'count'       => $engaged[0]['total'] ?? 0,
            'description' => 'Sessions with 5+ events in the last 30 days',
            'type'        => 'visitor',
            'color'       => '#10b981',
        ]);

        // Bounced Visitors (single page view sessions, 30d)
        $bounced = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $thirtyDaysAgo) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($thirtyDaysAgo->getTimestampMs())],
                    ]],
                    ['$group' => [
                        '_id'         => '$session_id',
                        'event_count' => ['$sum' => 1],
                    ]],
                    ['$match' => ['event_count' => 1]],
                    ['$count' => 'total'],
                ])->toArray();
            });

        $segments->push([
            'name'        => 'Bounced Visitors',
            'count'       => $bounced[0]['total'] ?? 0,
            'description' => 'Sessions with only a single event (bounced)',
            'type'        => 'visitor',
            'color'       => '#ef4444',
        ]);

        return $segments;
    }

    /**
     * Get pre-built customer segments with live counts from RFM data.
     *
     * @return Collection<int, array{name: string, count: int, description: string, type: string}>
     */
    public function getCustomerSegments(int|string $tenantId): Collection
    {
        $mongo = DB::connection('mongodb');
        $segments = collect();

        $rfmGroups = [
            ['name' => 'VIP Customers',     'min' => 13, 'max' => 15, 'desc' => 'Top-tier buyers (RFM score 13-15)', 'color' => '#6366f1'],
            ['name' => 'Loyal Customers',    'min' => 10, 'max' => 12, 'desc' => 'Regular repeat purchasers (RFM 10-12)', 'color' => '#10b981'],
            ['name' => 'At Risk',            'min' => 7,  'max' => 9,  'desc' => 'Previously active, declining engagement', 'color' => '#f59e0b'],
            ['name' => 'Hibernating',        'min' => 4,  'max' => 6,  'desc' => 'Low recent activity, needs reactivation', 'color' => '#f97316'],
            ['name' => 'Churned',            'min' => 0,  'max' => 3,  'desc' => 'No recent activity, likely lost', 'color' => '#ef4444'],
        ];

        foreach ($rfmGroups as $group) {
            $count = $mongo->table('customer_profiles')
                ->where('tenant_id', $tenantId)
                ->where('rfm_score', '>=', $group['min'])
                ->where('rfm_score', '<=', $group['max'])
                ->count();

            $segments->push([
                'name'        => $group['name'],
                'count'       => $count,
                'description' => $group['desc'],
                'type'        => 'customer',
                'color'       => $group['color'],
            ]);
        }

        return $segments;
    }

    /**
     * Get pre-built traffic segments with live counts.
     *
     * @return Collection<int, array{name: string, count: int, description: string, type: string}>
     */
    public function getTrafficSegments(int|string $tenantId): Collection
    {
        $mongo = DB::connection('mongodb');
        $now = CarbonImmutable::now();
        $thirtyDaysAgo = $now->subDays(30);
        $segments = collect();

        // Direct Traffic (no referrer/UTM)
        $direct = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $thirtyDaysAgo) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($thirtyDaysAgo->getTimestampMs())],
                        'metadata.utm_source' => ['$exists' => false],
                        'metadata.referrer'   => ['$in' => [null, '', 'direct']],
                    ]],
                    ['$group' => ['_id' => '$session_id']],
                    ['$count' => 'total'],
                ])->toArray();
            });

        $segments->push([
            'name'        => 'Direct Traffic',
            'count'       => $direct[0]['total'] ?? 0,
            'description' => 'Visitors arriving without referrer or UTM parameters',
            'type'        => 'traffic',
            'color'       => '#64748b',
        ]);

        // Organic Search
        $organic = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $thirtyDaysAgo) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($thirtyDaysAgo->getTimestampMs())],
                        '$or' => [
                            ['metadata.utm_medium' => 'organic'],
                            ['metadata.referrer' => ['$regex' => 'google|bing|yahoo|duckduckgo', '$options' => 'i']],
                        ],
                    ]],
                    ['$group' => ['_id' => '$session_id']],
                    ['$count' => 'total'],
                ])->toArray();
            });

        $segments->push([
            'name'        => 'Organic Search',
            'count'       => $organic[0]['total'] ?? 0,
            'description' => 'Visitors from search engines (Google, Bing, etc.)',
            'type'        => 'traffic',
            'color'       => '#10b981',
        ]);

        // Paid Traffic
        $paid = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $thirtyDaysAgo) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($thirtyDaysAgo->getTimestampMs())],
                        '$or' => [
                            ['metadata.utm_medium' => ['$in' => ['cpc', 'ppc', 'paid', 'paidsocial']]],
                            ['metadata.utm_source' => ['$in' => ['google_ads', 'facebook_ads', 'meta_ads']]],
                        ],
                    ]],
                    ['$group' => ['_id' => '$session_id']],
                    ['$count' => 'total'],
                ])->toArray();
            });

        $segments->push([
            'name'        => 'Paid Traffic',
            'count'       => $paid[0]['total'] ?? 0,
            'description' => 'Visitors from paid campaigns (CPC, PPC, Paid Social)',
            'type'        => 'traffic',
            'color'       => '#f59e0b',
        ]);

        // Social Traffic
        $social = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $thirtyDaysAgo) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($thirtyDaysAgo->getTimestampMs())],
                        '$or' => [
                            ['metadata.utm_medium' => 'social'],
                            ['metadata.referrer' => ['$regex' => 'facebook|twitter|instagram|linkedin|tiktok|youtube|pinterest', '$options' => 'i']],
                        ],
                    ]],
                    ['$group' => ['_id' => '$session_id']],
                    ['$count' => 'total'],
                ])->toArray();
            });

        $segments->push([
            'name'        => 'Social Traffic',
            'count'       => $social[0]['total'] ?? 0,
            'description' => 'Visitors from social media platforms',
            'type'        => 'traffic',
            'color'       => '#8b5cf6',
        ]);

        // Mobile Traffic
        $mobile = $mongo->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $thirtyDaysAgo) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($thirtyDaysAgo->getTimestampMs())],
                        'metadata.device.device_type' => 'Mobile',
                    ]],
                    ['$group' => ['_id' => '$session_id']],
                    ['$count' => 'total'],
                ])->toArray();
            });

        $segments->push([
            'name'        => 'Mobile Traffic',
            'count'       => $mobile[0]['total'] ?? 0,
            'description' => 'Visitors on mobile devices',
            'type'        => 'traffic',
            'color'       => '#0ea5e9',
        ]);

        return $segments;
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    private function detectSegmentType(array $rules): string
    {
        $fields = collect($rules)->pluck('field')->filter()->toArray();

        if (empty($fields)) {
            return 'generic';
        }

        $hasRfm = collect($fields)->contains(fn ($f) => str_contains($f, 'rfm'));
        $hasTraffic = collect($fields)->contains(fn ($f) => str_contains($f, 'utm') || str_contains($f, 'referrer') || str_contains($f, 'device'));
        $hasVisitor = collect($fields)->contains(fn ($f) => str_contains($f, 'event_count') || str_contains($f, 'page_view') || str_contains($f, 'session'));

        if ($hasRfm) return 'customer';
        if ($hasTraffic) return 'traffic';
        if ($hasVisitor) return 'visitor';

        return 'generic';
    }

    private function evaluateVisitorSegment(int|string $tenantId, array $rules): int
    {
        return $this->evaluateGenericSegment($tenantId, $rules);
    }

    private function evaluateCustomerSegment(int|string $tenantId, array $rules): int
    {
        $query = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tenantId);

        foreach ($rules as $rule) {
            $field    = $rule['field'] ?? null;
            $operator = $rule['operator'] ?? '==';
            $value    = $rule['value'] ?? null;

            if (!$field || $value === null) continue;

            $value = is_numeric($value) ? (float) $value : $value;

            $query->where($field, match ($operator) {
                '>='        => '>=',
                '<='        => '<=',
                '>'         => '>',
                '<'         => '<',
                '!='        => '!=',
                'contains'  => 'like',
                default     => '=',
            }, $operator === 'contains' ? "%{$value}%" : $value);
        }

        return $query->count();
    }

    private function evaluateTrafficSegment(int|string $tenantId, array $rules): int
    {
        return $this->evaluateGenericSegment($tenantId, $rules);
    }

    private function evaluateGenericSegment(int|string $tenantId, array $rules): int
    {
        $query = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId);

        foreach ($rules as $rule) {
            $field    = $rule['field'] ?? null;
            $operator = $rule['operator'] ?? '==';
            $value    = $rule['value'] ?? null;

            if (!$field || $value === null) continue;

            $value = is_numeric($value) ? (float) $value : $value;

            $query->where($field, match ($operator) {
                '>='        => '>=',
                '<='        => '<=',
                '>'         => '>',
                '<'         => '<',
                '!='        => '!=',
                'contains'  => 'like',
                default     => '=',
            }, $operator === 'contains' ? "%{$value}%" : $value);
        }

        return $query->distinct('session_id')->count('session_id');
    }
}

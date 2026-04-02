<?php

declare(strict_types=1);

namespace Modules\Analytics\Services\Cdp;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\CdpProfile;
use Modules\Analytics\Models\CdpSegment;

/**
 * Segment builder & evaluation engine.
 *
 * Translates visual conditions (AND/OR groups) into MongoDB queries against cdp_profiles
 * to determine segment membership. Supports dynamic auto-refresh and static snapshots.
 */
final class CdpSegmentService
{
    /**
     * Create a new segment.
     */
    public function createSegment(string $tenantId, array $data): CdpSegment
    {
        $segment = CdpSegment::create([
            'tenant_id'           => $tenantId,
            'name'                => $data['name'],
            'description'         => $data['description'] ?? '',
            'type'                => $data['type'] ?? 'dynamic',
            'conditions'          => $data['conditions'] ?? [],
            'member_count'        => 0,
            'refresh_frequency'   => $data['refresh_frequency'] ?? 'daily',
            'is_active'           => true,
            'synced_to_marketing' => false,
            'member_trend'        => [],
        ]);

        // Evaluate immediately
        $this->evaluateSegment($tenantId, $segment);

        return $segment->fresh();
    }

    /**
     * Update segment conditions and re-evaluate.
     */
    public function updateSegment(string $tenantId, string $segmentId, array $data): CdpSegment
    {
        $segment = CdpSegment::forTenant($tenantId)->findOrFail($segmentId);

        $segment->update(array_filter([
            'name'              => $data['name'] ?? null,
            'description'       => $data['description'] ?? null,
            'conditions'        => $data['conditions'] ?? null,
            'refresh_frequency' => $data['refresh_frequency'] ?? null,
            'is_active'         => $data['is_active'] ?? null,
        ], fn($v) => $v !== null));

        // Re-evaluate
        $this->evaluateSegment($tenantId, $segment->fresh());

        return $segment->fresh();
    }

    /**
     * Delete a segment.
     */
    public function deleteSegment(string $tenantId, string $segmentId): bool
    {
        $segment = CdpSegment::forTenant($tenantId)->findOrFail($segmentId);
        return (bool) $segment->delete();
    }

    /**
     * Evaluate a segment — count & tag matching profiles.
     *
     * @return int Number of matching profiles
     */
    public function evaluateSegment(string $tenantId, CdpSegment $segment): int
    {
        $filter = $this->buildMongoFilter($tenantId, $segment->conditions ?? []);

        $count = CdpProfile::where($filter)->count();

        // Update member count + trend
        $trend = $segment->member_trend ?? [];
        $trend[] = ['date' => Carbon::now()->toDateString(), 'count' => $count];
        // Keep last 180 days
        $trend = array_slice($trend, -180);

        $segment->update([
            'member_count'      => $count,
            'member_trend'      => $trend,
            'last_evaluated_at' => Carbon::now(),
        ]);

        return $count;
    }

    /**
     * Preview segment — return count without saving.
     */
    public function previewSegment(string $tenantId, array $conditions): int
    {
        $filter = $this->buildMongoFilter($tenantId, $conditions);
        return CdpProfile::where($filter)->count();
    }

    /**
     * Get members of a segment (paginated).
     */
    public function getSegmentMembers(string $tenantId, string $segmentId, int $page = 1, int $perPage = 25): array
    {
        $segment = CdpSegment::forTenant($tenantId)->findOrFail($segmentId);
        $filter  = $this->buildMongoFilter($tenantId, $segment->conditions ?? []);

        $total    = CdpProfile::where($filter)->count();
        $profiles = CdpProfile::where($filter)
            ->orderByDesc('transactional.lifetime_revenue')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return [
            'segment'     => $segment,
            'profiles'    => $profiles,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * List all segments for a tenant.
     */
    public function listSegments(string $tenantId): array
    {
        $segments = CdpSegment::forTenant($tenantId)
            ->orderByDesc('updated_at')
            ->get();

        return $segments->toArray();
    }

    /**
     * Evaluate ALL active dynamic segments for a tenant (nightly cron).
     */
    public function evaluateAllSegments(string $tenantId): array
    {
        $segments = CdpSegment::forTenant($tenantId)
            ->active()
            ->where('type', '!=', 'static')
            ->get();

        $results = [];
        foreach ($segments as $segment) {
            try {
                $count = $this->evaluateSegment($tenantId, $segment);
                $results[] = ['id' => $segment->_id, 'name' => $segment->name, 'count' => $count];
            } catch (\Throwable $e) {
                $results[] = ['id' => $segment->_id, 'name' => $segment->name, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Audience overlap analysis between two segments.
     */
    public function overlapAnalysis(string $tenantId, string $segmentAId, string $segmentBId): array
    {
        $segA = CdpSegment::forTenant($tenantId)->findOrFail($segmentAId);
        $segB = CdpSegment::forTenant($tenantId)->findOrFail($segmentBId);

        $filterA = $this->buildMongoFilter($tenantId, $segA->conditions ?? []);
        $filterB = $this->buildMongoFilter($tenantId, $segB->conditions ?? []);

        // Get email sets for each
        $emailsA = CdpProfile::where($filterA)->pluck('email')->toArray();
        $emailsB = CdpProfile::where($filterB)->pluck('email')->toArray();

        $setA = collect($emailsA);
        $setB = collect($emailsB);
        $overlap = $setA->intersect($setB);

        return [
            'segment_a'     => ['id' => $segA->_id, 'name' => $segA->name, 'count' => $setA->count()],
            'segment_b'     => ['id' => $segB->_id, 'name' => $segB->name, 'count' => $setB->count()],
            'overlap_count' => $overlap->count(),
            'a_only'        => $setA->count() - $overlap->count(),
            'b_only'        => $setB->count() - $overlap->count(),
            'overlap_pct'   => $setA->count() > 0 ? round(($overlap->count() / $setA->count()) * 100, 1) : 0,
        ];
    }

    /* ══════════════════════════════════════════
     *  CONDITION → MONGO FILTER TRANSLATOR
     * ══════════════════════════════════════════ */

    /**
     * Translate visual conditions into a MongoDB filter array.
     *
     * Input format:
     * [
     *   {
     *     "group": "and",
     *     "rules": [
     *       {"dimension": "transactional", "field": "lifetime_revenue", "operator": ">", "value": 10000},
     *       {"dimension": "computed", "field": "rfm_segment", "operator": "==", "value": "Champion"}
     *     ]
     *   },
     *   {
     *     "group": "or",
     *     "rules": [
     *       {"dimension": "behavioural", "field": "sessions_30d", "operator": ">", "value": 5}
     *     ]
     *   }
     * ]
     *
     * All groups are ANDed; rules within an "or" group use $or.
     */
    private function buildMongoFilter(string $tenantId, array $conditionGroups): array
    {
        $filter = ['tenant_id' => $tenantId];

        if (empty($conditionGroups)) {
            return $filter;
        }

        $andClauses = [];

        foreach ($conditionGroups as $group) {
            $groupType = $group['group'] ?? 'and';
            $rules     = $group['rules'] ?? [];

            if (empty($rules)) {
                continue;
            }

            $ruleClauses = [];
            foreach ($rules as $rule) {
                $clause = $this->ruleToMongoClause($rule);
                if ($clause) {
                    $ruleClauses[] = $clause;
                }
            }

            if (empty($ruleClauses)) {
                continue;
            }

            if ($groupType === 'or') {
                $andClauses[] = ['$or' => $ruleClauses];
            } else {
                // AND — just merge all clauses
                foreach ($ruleClauses as $clause) {
                    $andClauses[] = $clause;
                }
            }
        }

        if (! empty($andClauses)) {
            $filter['$and'] = $andClauses;
        }

        return $filter;
    }

    /**
     * Convert a single rule to a MongoDB query clause.
     */
    private function ruleToMongoClause(array $rule): ?array
    {
        $dimension = $rule['dimension'] ?? '';
        $field     = $rule['field'] ?? '';
        $operator  = $rule['operator'] ?? '==';
        $value     = $rule['value'] ?? null;

        if (! $dimension || ! $field) {
            return null;
        }

        // Map dimension.field to the actual MongoDB field path
        $mongoField = $this->resolveFieldPath($dimension, $field);

        // Cast value to appropriate type
        $value = $this->castValue($value, $operator);

        return match ($operator) {
            '=='           => [$mongoField => $value],
            '!='           => [$mongoField => ['$ne' => $value]],
            '>'            => [$mongoField => ['$gt' => $value]],
            '>='           => [$mongoField => ['$gte' => $value]],
            '<'            => [$mongoField => ['$lt' => $value]],
            '<='           => [$mongoField => ['$lte' => $value]],
            'contains'     => [$mongoField => ['$regex' => preg_quote((string) $value, '/'), '$options' => 'i']],
            'not_contains' => [$mongoField => ['$not' => ['$regex' => preg_quote((string) $value, '/'), '$options' => 'i']]],
            'starts_with'  => [$mongoField => ['$regex' => '^' . preg_quote((string) $value, '/'), '$options' => 'i']],
            'in'           => [$mongoField => ['$in' => (array) $value]],
            'not_in'       => [$mongoField => ['$nin' => (array) $value]],
            'exists'       => [$mongoField => ['$exists' => true, '$ne' => null]],
            'not_exists'   => ['$or' => [[$mongoField => ['$exists' => false]], [$mongoField => null]]],
            'between'      => [$mongoField => ['$gte' => $value[0] ?? 0, '$lte' => $value[1] ?? 0]],
            'days_ago'     => [$mongoField => ['$lte' => Carbon::now()->subDays((int) $value)->toDateString()]],
            'days_within'  => [$mongoField => ['$gte' => Carbon::now()->subDays((int) $value)->toDateString()]],
            default        => null,
        };
    }

    /**
     * Map dimension + field to actual MongoDB document path in cdp_profiles.
     */
    private function resolveFieldPath(string $dimension, string $field): string
    {
        return match ($dimension) {
            'transactional' => "transactional.{$field}",
            'demographic'   => "demographics.{$field}",
            'behavioural'   => "behavioural.{$field}",
            'engagement'    => "engagement.{$field}",
            'search'        => "search.{$field}",
            'chatbot'       => "chatbot.{$field}",
            'computed'      => "computed.{$field}",
            'identity'      => "identity.{$field}",
            default         => $field,
        };
    }

    /**
     * Cast value to appropriate type for MongoDB query.
     */
    private function castValue(mixed $value, string $operator): mixed
    {
        if ($operator === 'between' && is_array($value)) {
            return array_map(fn($v) => is_numeric($v) ? (float) $v : $v, $value);
        }
        if ($operator === 'in' || $operator === 'not_in') {
            return (array) $value;
        }
        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }
        if ($value === 'true') return true;
        if ($value === 'false') return false;

        return $value;
    }
}

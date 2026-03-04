<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\Log;
use MongoDB\Laravel\Eloquent\Builder;
use Modules\Analytics\Models\AudienceSegment;
use Modules\Analytics\Models\CustomerProfile;

/**
 * Translates the JSON rules stored in an AudienceSegment into a
 * dynamic MongoDB query against the CustomerProfile collection.
 *
 * Supported operators:
 *   ==, !=, >, >=, <, <=        — scalar comparisons
 *   in, not_in                  — array membership
 *   contains                    — substring / regex match
 *   exists, not_exists          — field presence checks
 *
 * Dot-notation fields are supported (e.g. "custom_attributes.loyalty_tier").
 */
final class AudienceBuilderService
{
    /**
     * Build a MongoDB\Laravel\Eloquent\Builder for the given segment rules.
     *
     * The query is always scoped to the segment's tenant_id.
     */
    public function buildQuery(AudienceSegment $segment): Builder
    {
        $query = CustomerProfile::query()
            ->where('tenant_id', (string) $segment->tenant_id);

        foreach ($segment->rules as $rule) {
            $field    = $rule['field']    ?? null;
            $operator = $rule['operator'] ?? null;
            $value    = $rule['value']    ?? null;

            if ($field === null || $operator === null) {
                Log::warning('[AudienceBuilder] Skipping malformed rule.', $rule);
                continue;
            }

            $query = $this->applyCondition($query, $field, $operator, $value);
        }

        return $query;
    }

    /**
     * Execute the segment query and return matching CustomerProfile MongoDB _id values.
     *
     * @return list<string>
     */
    public function getMatchingCustomerIds(AudienceSegment $segment): array
    {
        return $this->buildQuery($segment)
            ->pluck('_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Count matching profiles without hydrating full models.
     */
    public function countMatching(AudienceSegment $segment): int
    {
        return $this->buildQuery($segment)->count();
    }

    // ------------------------------------------------------------------
    //  Rule → Query condition translator
    // ------------------------------------------------------------------

    private function applyCondition(Builder $query, string $field, string $operator, mixed $value): Builder
    {
        return match ($operator) {
            // Equality / inequality
            '==', 'eq'       => $query->where($field, '=', $this->castValue($value)),
            '!=', 'neq'      => $query->where($field, '!=', $this->castValue($value)),

            // Numeric comparisons
            '>'              => $query->where($field, '>', $this->castNumeric($value)),
            '>='             => $query->where($field, '>=', $this->castNumeric($value)),
            '<'              => $query->where($field, '<', $this->castNumeric($value)),
            '<='             => $query->where($field, '<=', $this->castNumeric($value)),

            // Array membership
            'in'             => $query->whereIn($field, (array) $value),
            'not_in'         => $query->whereNotIn($field, (array) $value),

            // Substring / regex match
            'contains'       => $query->where($field, 'regex', '/' . preg_quote((string) $value, '/') . '/i'),

            // Field presence
            'exists'         => $query->whereNotNull($field),
            'not_exists'     => $query->whereNull($field),

            // Fallback — treat unknown operators as equality
            default          => $query->where($field, '=', $this->castValue($value)),
        };
    }

    // ------------------------------------------------------------------
    //  Value casting helpers
    // ------------------------------------------------------------------

    /**
     * Attempt to cast a value to its most appropriate PHP type.
     */
    private function castValue(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return str_contains((string) $value, '.')
                ? (float) $value
                : (int) $value;
        }

        if ($value === 'true')  return true;
        if ($value === 'false') return false;
        if ($value === 'null')  return null;

        return $value;
    }

    /**
     * Force a numeric cast (int or float).
     */
    private function castNumeric(mixed $value): int|float
    {
        if (is_numeric($value)) {
            return str_contains((string) $value, '.')
                ? (float) $value
                : (int) $value;
        }

        return 0;
    }
}

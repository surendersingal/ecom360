<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Log;

/**
 * Rules engine for evaluating conditional logic in flows, campaigns,
 * and automated triggers.
 *
 * Supports rule groups with AND/OR logic and nested conditions.
 *
 * Rule format:
 *   { "match": "all|any", "rules": [ { "field": "...", "operator": "...", "value": "..." }, ... ] }
 *
 * Supported operators:
 *   eq, neq, gt, gte, lt, lte, contains, not_contains,
 *   starts_with, ends_with, in, not_in, is_empty, is_not_empty,
 *   between, regex, exists
 */
final class RulesEngineService
{
    /**
     * Evaluate a rule group against a data context.
     *
     * @param  array  $ruleGroup  { match: "all"|"any", rules: [...] }
     * @param  array  $context    Flat or nested data to evaluate against
     */
    public function evaluate(array $ruleGroup, array $context): bool
    {
        $match = $ruleGroup['match'] ?? 'all';
        $rules = $ruleGroup['rules'] ?? [];

        if (empty($rules)) return true;

        foreach ($rules as $rule) {
            // Nested group
            if (isset($rule['rules'])) {
                $result = $this->evaluate($rule, $context);
            } else {
                $result = $this->evaluateRule($rule, $context);
            }

            if ($match === 'any' && $result) return true;
            if ($match === 'all' && !$result) return false;
        }

        return $match === 'all';
    }

    /**
     * Evaluate a single rule against the context.
     */
    private function evaluateRule(array $rule, array $context): bool
    {
        $field = $rule['field'] ?? null;
        $operator = $rule['operator'] ?? 'eq';
        $expected = $rule['value'] ?? null;

        if ($field === null) return false;

        $actual = $this->resolveField($field, $context);

        try {
            return match ($operator) {
                'eq', '==' => $actual == $expected,
                'neq', '!=' => $actual != $expected,
                'gt', '>' => (float) $actual > (float) $expected,
                'gte', '>=' => (float) $actual >= (float) $expected,
                'lt', '<' => (float) $actual < (float) $expected,
                'lte', '<=' => (float) $actual <= (float) $expected,
                'contains' => is_string($actual) && str_contains(strtolower($actual), strtolower((string) $expected)),
                'not_contains' => is_string($actual) && !str_contains(strtolower($actual), strtolower((string) $expected)),
                'starts_with' => is_string($actual) && str_starts_with(strtolower($actual), strtolower((string) $expected)),
                'ends_with' => is_string($actual) && str_ends_with(strtolower($actual), strtolower((string) $expected)),
                'in' => in_array($actual, (array) $expected),
                'not_in' => !in_array($actual, (array) $expected),
                'is_empty' => empty($actual),
                'is_not_empty' => !empty($actual),
                'between' => is_array($expected) && count($expected) === 2
                    && (float) $actual >= (float) $expected[0]
                    && (float) $actual <= (float) $expected[1],
                'regex' => is_string($actual) && (bool) preg_match((string) $expected, $actual),
                'exists' => $actual !== null,
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning("[RulesEngine] Error evaluating rule: {$e->getMessage()}", $rule);
            return false;
        }
    }

    /**
     * Resolve a dot-notation field from the context array.
     */
    private function resolveField(string $field, array $context): mixed
    {
        $keys = explode('.', $field);
        $value = $context;

        foreach ($keys as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }
}

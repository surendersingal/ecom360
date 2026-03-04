<?php

declare(strict_types=1);

if (!function_exists('safe_num')) {
    /**
     * Safely format a value for display in KPI cards.
     * If the value is an array, returns its count; otherwise formats as number.
     */
    function safe_num(mixed $value, int $decimals = 0): string
    {
        if (is_array($value) || $value instanceof \Countable) {
            return number_format(count($value), $decimals);
        }

        return number_format(is_numeric($value) ? (float) $value : 0, $decimals);
    }
}

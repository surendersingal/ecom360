<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BusinessIntelligence\Models\Report;

/**
 * Builds and executes BI queries against both MongoDB (analytics events,
 * customer profiles) and MySQL (orders, campaigns, contacts) data sources.
 *
 * Supports drag-and-drop style query building with:
 *   - Data source selection (events, customers, orders, campaigns, sessions)
 *   - Field selection with aggregations (sum, avg, count, min, max, distinct)
 *   - Filtering (where conditions)
 *   - Grouping (group by fields with time granularity)
 *   - Sorting
 *   - Limits
 */
final class QueryBuilderService
{
    /**
     * Execute a query configuration and return tabular results.
     *
     * @param  array  $queryConfig  {
     *   source: "events"|"customers"|"sessions"|"orders"|"campaigns"|"contacts",
     *   fields: [ { name, alias?, aggregation?: "sum"|"avg"|"count"|"min"|"max"|"distinct" } ],
     *   filters: [ { field, operator, value } ],
     *   group_by: [ { field, granularity?: "day"|"week"|"month"|"year" } ],
     *   order_by: [ { field, direction: "asc"|"desc" } ],
     *   limit: int,
     *   tenant_id: string
     * }
     * @return array{ columns: string[], rows: array[], total: int }
     */
    public function execute(array|int $queryConfigOrTenantId, ?array $requestData = null): array
    {
        if (is_int($queryConfigOrTenantId)) {
            // Called as execute(tenantId, requestData) from InsightsController
            $queryConfig = $requestData ?? [];
            $queryConfig['tenant_id'] = (string) $queryConfigOrTenantId;
            $queryConfig['source'] = $queryConfig['data_source'] ?? $queryConfig['source'] ?? 'events';
        } else {
            $queryConfig = $queryConfigOrTenantId;
        }

        $source = $queryConfig['source'] ?? 'events';
        $tenantId = $queryConfig['tenant_id'] ?? null;

        if (!$tenantId) {
            return ['columns' => [], 'rows' => [], 'total' => 0];
        }

        return match ($source) {
            'events' => $this->queryMongoDB('tracking_events', $queryConfig),
            'customers' => $this->queryMongoDB('customer_profiles', $queryConfig),
            'sessions' => $this->queryMongoDB('tracking_events', $this->addSessionConfig($queryConfig)),
            'orders', 'campaigns', 'contacts' => $this->queryMySQL($source, $queryConfig),
            default => ['columns' => [], 'rows' => [], 'total' => 0],
        };
    }

    /**
     * Validate a query config before execution.
     *
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(array $queryConfig): array
    {
        $errors = [];

        if (empty($queryConfig['source'])) {
            $errors[] = 'Data source is required.';
        }

        if (empty($queryConfig['fields'])) {
            $errors[] = 'At least one field is required.';
        }

        if (empty($queryConfig['tenant_id'])) {
            $errors[] = 'Tenant ID is required.';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Get available fields for a data source.
     */
    public function getAvailableFields(string $source): array
    {
        return match ($source) {
            'events' => [
                'event_type', 'url', 'page_title', 'referrer', 'session_id',
                'visitor_id', 'device_type', 'browser', 'os', 'country', 'city',
                'created_at', 'metadata.*',
            ],
            'customers' => [
                'email', 'first_name', 'last_name', 'total_orders', 'total_revenue',
                'average_order_value', 'lifetime_value', 'rfm_segment', 'intent_score',
                'first_seen_at', 'last_seen_at', 'last_purchase_at', 'custom_attributes.*',
            ],
            'sessions' => [
                'session_id', 'page_count', 'duration', 'entry_page', 'exit_page',
                'device_type', 'country', 'channel', 'converted',
            ],
            'campaigns' => [
                'name', 'type', 'channel', 'status', 'total_sent', 'total_delivered',
                'total_opened', 'total_clicked', 'total_converted', 'total_revenue',
                'sent_at', 'completed_at',
            ],
            'contacts' => [
                'email', 'first_name', 'last_name', 'phone', 'status', 'tags',
                'city', 'country', 'created_at',
            ],
            default => [],
        };
    }

    private function queryMongoDB(string $collection, array $config): array
    {
        try {
            $query = DB::connection('mongodb')->table($collection)
                ->where('tenant_id', $config['tenant_id']);

            // Apply filters
            foreach ($config['filters'] ?? [] as $filter) {
                $query = $this->applyFilter($query, $filter);
            }

            // Apply date range if present
            if (!empty($config['date_range'])) {
                $query->where('created_at', '>=', $config['date_range']['start'])
                    ->where('created_at', '<=', $config['date_range']['end']);
            }

            // Group by + aggregations
            $groupBy = $config['group_by'] ?? [];
            $fields = $config['fields'] ?? [];

            if (!empty($groupBy)) {
                return $this->executeAggregation($collection, $config);
            }

            // Simple select
            $selectFields = array_map(fn($f) => $f['name'] ?? $f, $fields);
            $query->select($selectFields);

            // Ordering
            foreach ($config['order_by'] ?? [] as $order) {
                $query->orderBy($order['field'], $order['direction'] ?? 'desc');
            }

            // Limit
            $limit = min((int) ($config['limit'] ?? 1000), 10000);
            $total = $query->count();
            $rows = $query->limit($limit)->get()->toArray();

            return [
                'columns' => $selectFields,
                'rows' => array_map(fn($r) => (array) $r, $rows),
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Log::error("[QueryBuilder] MongoDB query failed: {$e->getMessage()}");
            return ['columns' => [], 'rows' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    private function executeAggregation(string $collection, array $config): array
    {
        try {
            $pipeline = [];

            // Match stage (tenant + filters)
            $matchStage = ['tenant_id' => $config['tenant_id']];
            foreach ($config['filters'] ?? [] as $filter) {
                $field = $filter['field'] ?? null;
                $operator = $filter['operator'] ?? 'eq';
                $value = $filter['value'] ?? null;
                if ($field) {
                    $matchStage[$field] = $this->mongoOperator($operator, $value);
                }
            }

            if (!empty($config['date_range'])) {
                $matchStage['created_at'] = [
                    '$gte' => $config['date_range']['start'],
                    '$lte' => $config['date_range']['end'],
                ];
            }

            $pipeline[] = ['$match' => $matchStage];

            // Group stage
            $groupId = [];
            foreach ($config['group_by'] ?? [] as $gb) {
                $field = $gb['field'];
                $granularity = $gb['granularity'] ?? null;

                if ($granularity && str_contains($field, 'at') || str_contains($field, 'date')) {
                    $groupId[$field] = match ($granularity) {
                        'day' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$' . $field]],
                        'week' => ['$dateToString' => ['format' => '%Y-W%V', 'date' => '$' . $field]],
                        'month' => ['$dateToString' => ['format' => '%Y-%m', 'date' => '$' . $field]],
                        'year' => ['$dateToString' => ['format' => '%Y', 'date' => '$' . $field]],
                        default => '$' . $field,
                    };
                } else {
                    $groupId[$field] = '$' . $field;
                }
            }

            $groupStage = ['_id' => $groupId];
            foreach ($config['fields'] ?? [] as $f) {
                $name = $f['name'] ?? $f;
                $alias = $f['alias'] ?? $name;
                $agg = $f['aggregation'] ?? null;

                if ($agg) {
                    $groupStage[$alias] = match ($agg) {
                        'sum' => ['$sum' => '$' . $name],
                        'avg' => ['$avg' => '$' . $name],
                        'count' => ['$sum' => 1],
                        'min' => ['$min' => '$' . $name],
                        'max' => ['$max' => '$' . $name],
                        'distinct' => ['$addToSet' => '$' . $name],
                        default => ['$first' => '$' . $name],
                    };
                }
            }

            $pipeline[] = ['$group' => $groupStage];

            // Sort
            foreach ($config['order_by'] ?? [] as $order) {
                $pipeline[] = ['$sort' => [$order['field'] => $order['direction'] === 'asc' ? 1 : -1]];
            }

            // Limit
            $pipeline[] = ['$limit' => min((int) ($config['limit'] ?? 1000), 10000)];

            $results = DB::connection('mongodb')->table($collection)->raw(function ($col) use ($pipeline) {
                return $col->aggregate($pipeline, ['maxTimeMS' => 30000])->toArray();
            });

            $rows = array_map(fn($r) => (array) $r, $results);
            $columns = !empty($rows) ? array_keys($rows[0]) : [];

            return ['columns' => $columns, 'rows' => $rows, 'total' => count($rows)];
        } catch (\Throwable $e) {
            Log::error("[QueryBuilder] Aggregation failed: {$e->getMessage()}");
            return ['columns' => [], 'rows' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    private function queryMySQL(string $source, array $config): array
    {
        try {
            $table = match ($source) {
                'campaigns' => 'marketing_campaigns',
                'contacts' => 'marketing_contacts',
                'orders' => 'marketing_messages', // Messages serve as order-like records
                default => $source,
            };

            $query = DB::table($table)->where('tenant_id', $config['tenant_id']);

            foreach ($config['filters'] ?? [] as $filter) {
                $query = $this->applyFilter($query, $filter);
            }

            $fields = array_map(fn($f) => $f['name'] ?? $f, $config['fields'] ?? ['*']);
            $total = $query->count();

            foreach ($config['order_by'] ?? [] as $order) {
                $query->orderBy($order['field'], $order['direction'] ?? 'desc');
            }

            $limit = min((int) ($config['limit'] ?? 1000), 10000);
            $rows = $query->select($fields)->limit($limit)->get()->toArray();

            return [
                'columns' => $fields,
                'rows' => array_map(fn($r) => (array) $r, $rows),
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Log::error("[QueryBuilder] MySQL query failed: {$e->getMessage()}");
            return ['columns' => [], 'rows' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    private function applyFilter($query, array $filter)
    {
        $field = $filter['field'] ?? null;
        $operator = $filter['operator'] ?? 'eq';
        $value = $filter['value'] ?? null;

        if (!$field) return $query;

        return match ($operator) {
            'eq', '==' => $query->where($field, '=', $value),
            'neq', '!=' => $query->where($field, '!=', $value),
            'gt', '>' => $query->where($field, '>', $value),
            'gte', '>=' => $query->where($field, '>=', $value),
            'lt', '<' => $query->where($field, '<', $value),
            'lte', '<=' => $query->where($field, '<=', $value),
            'in' => $query->whereIn($field, (array) $value),
            'not_in' => $query->whereNotIn($field, (array) $value),
            'contains', 'like' => $query->where($field, 'like', "%{$value}%"),
            'is_null' => $query->whereNull($field),
            'is_not_null' => $query->whereNotNull($field),
            default => $query->where($field, '=', $value),
        };
    }

    private function mongoOperator(string $operator, mixed $value): mixed
    {
        return match ($operator) {
            'eq', '==' => $value,
            'neq', '!=' => ['$ne' => $value],
            'gt', '>' => ['$gt' => $value],
            'gte', '>=' => ['$gte' => $value],
            'lt', '<' => ['$lt' => $value],
            'lte', '<=' => ['$lte' => $value],
            'in' => ['$in' => (array) $value],
            'not_in' => ['$nin' => (array) $value],
            'contains' => ['$regex' => $value, '$options' => 'i'],
            default => $value,
        };
    }

    private function addSessionConfig(array $config): array
    {
        $config['filters'][] = ['field' => 'event_type', 'operator' => 'eq', 'value' => 'page_view'];
        return $config;
    }
}

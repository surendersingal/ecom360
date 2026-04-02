<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BusinessIntelligence\Models\Report;

/**
 * Manages BI reports: creation, execution, scheduling, and caching.
 * Supports standard, custom, SQL-based, and scheduled report types.
 */
final class ReportService
{
    public function __construct(
        private readonly QueryBuilderService $queryBuilder,
    ) {}

    // ─── CRUD Methods ─────────────────────────────────────────────────

    /**
     * List reports for a tenant with optional filters.
     */
    public function list(int $tenantId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Report::where('tenant_id', $tenantId)
            ->when($filters['type'] ?? null, fn($q, $type) => $q->where('type', $type))
            ->when($filters['is_public'] ?? null, fn($q) => $q->where('is_public', true))
            ->when($filters['is_favorite'] ?? null, fn($q) => $q->where('is_favorite', true))
            ->orderByDesc('updated_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Find a single report by ID scoped to tenant.
     */
    public function find(int $tenantId, int $id): ?Report
    {
        return Report::where('tenant_id', $tenantId)->find($id);
    }

    /**
     * Update a report.
     */
    public function update(int $tenantId, int $id, array $data): Report
    {
        $report = Report::where('tenant_id', $tenantId)->findOrFail($id);
        $report->update($data);
        return $report->fresh();
    }

    /**
     * Delete a report.
     */
    public function delete(int $tenantId, int $id): void
    {
        Report::where('tenant_id', $tenantId)->findOrFail($id)->delete();
    }

    /**
     * Get available report templates.
     */
    public function getTemplates(): array
    {
        return $this->getStandardReports();
    }

    /**
     * Create a report from a pre-built template.
     */
    public function createFromTemplate(int $tenantId, string $templateKey, ?string $name = null): Report
    {
        $templates = $this->getStandardReports();
        $template = $templates[$templateKey] ?? null;

        if (!$template) {
            throw new \RuntimeException("Unknown report template: {$templateKey}");
        }

        return Report::create([
            'tenant_id' => $tenantId,
            'name' => $name ?? $template['name'],
            'type' => 'standard',
            'config' => $template['config'],
            'visualizations' => $template['visualizations'] ?? [],
        ]);
    }

    // ─── Report Creation & Execution ──────────────────────────────────

    /**
     * Create a new report.
     */
    public function create(int $tenantId, array $data): Report
    {
        $validTypes = ['standard', 'custom', 'sql', 'scheduled'];
        $type = in_array($data['type'] ?? '', $validTypes) ? $data['type'] : 'custom';

        return Report::create([
            'tenant_id' => $tenantId,
            'created_by' => $data['created_by'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $type,
            'config' => $data['config'] ?? [],
            'visualizations' => $data['visualizations'] ?? [],
            'filters' => $data['filters'] ?? [],
            'schedule' => $data['schedule'] ?? null,
            'is_public' => $data['is_public'] ?? false,
        ]);
    }

    /**
     * Execute a report and return its results.
     *
     * Accepts either (Report, filters) or (tenantId, reportId, filters) signature.
     *
     * @return array{ columns: string[], rows: array[], total: int, metadata: array }
     */
    public function execute(Report|int $reportOrTenantId, array|int $filtersOrReportId = [], array $overrideFilters = []): array
    {
        if ($reportOrTenantId instanceof Report) {
            $report = $reportOrTenantId;
            $overrideFilters = is_array($filtersOrReportId) ? $filtersOrReportId : [];
        } else {
            $report = Report::where('tenant_id', $reportOrTenantId)->findOrFail((int) $filtersOrReportId);
        }

        $startTime = microtime(true);

        $queryConfig = $report->config;
        $queryConfig['tenant_id'] = (string) $report->tenant_id;

        // Merge report-level filters with runtime overrides
        $queryConfig['filters'] = array_merge(
            $queryConfig['filters'] ?? [],
            $report->filters ?? [],
            $overrideFilters,
        );

        $result = $this->queryBuilder->execute($queryConfig);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $report->update(['last_run_at' => now()]);

        $result['metadata'] = [
            'report_id' => $report->id,
            'report_name' => $report->name,
            'executed_at' => now()->toIso8601String(),
            'duration_ms' => $duration,
            'visualizations' => $report->visualizations,
        ];

        return $result;
    }

    /**
     * Get pre-built standard report configs.
     */
    public function getStandardReports(): array
    {
        return [
            'revenue_overview' => [
                'name' => 'Revenue Overview',
                'config' => [
                    'source' => 'events',
                    'fields' => [
                        ['name' => 'metadata.revenue', 'alias' => 'total_revenue', 'aggregation' => 'sum'],
                        ['name' => 'event_type', 'alias' => 'orders', 'aggregation' => 'count'],
                    ],
                    'filters' => [['field' => 'event_type', 'operator' => 'eq', 'value' => 'purchase']],
                    'group_by' => [['field' => 'created_at', 'granularity' => 'day']],
                    'order_by' => [['field' => 'created_at', 'direction' => 'asc']],
                ],
                'visualizations' => [['type' => 'line', 'x' => 'created_at', 'y' => 'total_revenue']],
            ],
            'customer_acquisition' => [
                'name' => 'Customer Acquisition',
                'config' => [
                    'source' => 'customers',
                    'fields' => [
                        ['name' => 'email', 'alias' => 'new_customers', 'aggregation' => 'count'],
                    ],
                    'group_by' => [['field' => 'first_seen_at', 'granularity' => 'week']],
                    'order_by' => [['field' => 'first_seen_at', 'direction' => 'asc']],
                ],
                'visualizations' => [['type' => 'bar', 'x' => 'first_seen_at', 'y' => 'new_customers']],
            ],
            'campaign_performance' => [
                'name' => 'Campaign Performance',
                'config' => [
                    'source' => 'campaigns',
                    'fields' => [
                        ['name' => 'name'], ['name' => 'channel'], ['name' => 'total_sent'],
                        ['name' => 'total_delivered'], ['name' => 'total_opened'],
                        ['name' => 'total_clicked'], ['name' => 'total_converted'],
                    ],
                    'order_by' => [['field' => 'created_at', 'direction' => 'desc']],
                ],
                'visualizations' => [['type' => 'table']],
            ],
            'top_products' => [
                'name' => 'Top Products',
                'config' => [
                    'source' => 'events',
                    'fields' => [
                        ['name' => 'metadata.product_name', 'alias' => 'product'],
                        ['name' => 'metadata.revenue', 'alias' => 'revenue', 'aggregation' => 'sum'],
                        ['name' => 'event_type', 'alias' => 'purchases', 'aggregation' => 'count'],
                    ],
                    'filters' => [['field' => 'event_type', 'operator' => 'eq', 'value' => 'purchase']],
                    'group_by' => [['field' => 'metadata.product_name']],
                    'order_by' => [['field' => 'revenue', 'direction' => 'desc']],
                    'limit' => 20,
                ],
                'visualizations' => [['type' => 'bar', 'x' => 'product', 'y' => 'revenue']],
            ],
            'traffic_sources' => [
                'name' => 'Traffic Sources',
                'config' => [
                    'source' => 'events',
                    'fields' => [
                        ['name' => 'referrer', 'alias' => 'source'],
                        ['name' => 'session_id', 'alias' => 'sessions', 'aggregation' => 'distinct'],
                    ],
                    'filters' => [['field' => 'event_type', 'operator' => 'eq', 'value' => 'page_view']],
                    'group_by' => [['field' => 'referrer']],
                    'order_by' => [['field' => 'sessions', 'direction' => 'desc']],
                    'limit' => 15,
                ],
                'visualizations' => [['type' => 'pie', 'label' => 'source', 'value' => 'sessions']],
            ],
            'rfm_distribution' => [
                'name' => 'RFM Customer Segments',
                'config' => [
                    'source' => 'customers',
                    'fields' => [
                        ['name' => 'rfm_segment', 'alias' => 'segment'],
                        ['name' => 'email', 'alias' => 'count', 'aggregation' => 'count'],
                        ['name' => 'total_revenue', 'alias' => 'total_revenue', 'aggregation' => 'sum'],
                    ],
                    'group_by' => [['field' => 'rfm_segment']],
                    'order_by' => [['field' => 'count', 'direction' => 'desc']],
                ],
                'visualizations' => [['type' => 'donut', 'label' => 'segment', 'value' => 'count']],
            ],
        ];
    }
}

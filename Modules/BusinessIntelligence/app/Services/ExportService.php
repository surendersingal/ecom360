<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BusinessIntelligence\Models\Export;
use Modules\BusinessIntelligence\Models\Report;

/**
 * Handles data exports from BI reports in multiple formats.
 * Supports CSV, XLSX (via OpenSpout), JSON, and PDF generation.
 */
final class ExportService
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {}

    // ─── CRUD Methods ─────────────────────────────────────────────────

    /**
     * List exports for a tenant with optional filters.
     */
    public function list(int $tenantId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Export::where('tenant_id', $tenantId)
            ->when($filters['report_id'] ?? null, fn($q, $rid) => $q->where('report_id', $rid))
            ->when($filters['status'] ?? null, fn($q, $s) => $q->where('status', $s))
            ->when($filters['format'] ?? null, fn($q, $f) => $q->where('format', $f))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Find a single export by ID scoped to tenant.
     */
    public function find(int $tenantId, int $id): ?Export
    {
        return Export::where('tenant_id', $tenantId)->find($id);
    }

    /**
     * Delete an export and its file.
     */
    public function delete(int $tenantId, int $id): void
    {
        $export = Export::where('tenant_id', $tenantId)->findOrFail($id);

        if ($export->file_path && Storage::disk('local')->exists($export->file_path)) {
            Storage::disk('local')->delete($export->file_path);
        }

        $export->delete();
    }

    // ─── Export Execution ─────────────────────────────────────────────

    /**
     * Create and execute an export job.
     *
     * Accepts either (Report, format, userId, filters) or (tenantId, reportId, format, filters) signature.
     */
    public function export(Report|int $reportOrTenantId, string|int $formatOrReportId, int|string|null $userIdOrFormat = null, array $filters = []): Export
    {
        if ($reportOrTenantId instanceof Report) {
            $report = $reportOrTenantId;
            $format = (string) $formatOrReportId;
            $userId = is_int($userIdOrFormat) ? $userIdOrFormat : null;
        } else {
            $tenantId = $reportOrTenantId;
            $report = Report::where('tenant_id', $tenantId)->findOrFail((int) $formatOrReportId);
            $format = is_string($userIdOrFormat) ? $userIdOrFormat : 'csv';
            // filters is already the 4th arg
            $userId = null;
        }

        $export = Export::create([
            'tenant_id' => $report->tenant_id,
            'created_by' => $userId,
            'report_id' => $report->id,
            'name' => "{$report->name} - " . now()->format('Y-m-d H:i'),
            'format' => $format,
            'status' => 'processing',
            'filters' => $filters,
            'started_at' => now(),
        ]);

        try {
            $result = $this->reportService->execute($report, $filters);
            $rows = $result['rows'] ?? [];
            $columns = $result['columns'] ?? [];

            $filePath = match ($format) {
                'csv' => $this->exportCsv($export, $columns, $rows),
                'json' => $this->exportJson($export, $result),
                'xlsx' => $this->exportXlsx($export, $columns, $rows),
                default => $this->exportCsv($export, $columns, $rows),
            };

            $fileSize = Storage::disk('local')->exists($filePath) ? Storage::disk('local')->size($filePath) : 0;

            $export->update([
                'status' => 'completed',
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'row_count' => count($rows),
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("[ExportService] Export failed: {$e->getMessage()}");
            $export->update(['status' => 'failed', 'completed_at' => now()]);
        }

        return $export;
    }

    /**
     * Get download path for a completed export.
     */
    public function getDownloadPath(Export $export): ?string
    {
        if ($export->status !== 'completed' || !$export->file_path) {
            return null;
        }

        return Storage::disk('local')->path($export->file_path);
    }

    private function exportCsv(Export $export, array $columns, array $rows): string
    {
        $path = "exports/bi/{$export->tenant_id}/{$export->id}.csv";
        $fullPath = storage_path("app/{$path}");

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($fullPath, 'w');

        // Header row
        if (!empty($columns)) {
            fputcsv($handle, $columns);
        } elseif (!empty($rows)) {
            fputcsv($handle, array_keys($rows[0]));
        }

        // Data rows
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                $values[] = is_array($value) ? json_encode($value) : (string) ($value ?? '');
            }
            fputcsv($handle, $values);
        }

        fclose($handle);
        return $path;
    }

    private function exportJson(Export $export, array $result): string
    {
        $path = "exports/bi/{$export->tenant_id}/{$export->id}.json";

        Storage::disk('local')->put($path, json_encode([
            'report' => $export->name,
            'exported_at' => now()->toIso8601String(),
            'total_rows' => $result['total'] ?? count($result['rows'] ?? []),
            'columns' => $result['columns'] ?? [],
            'data' => $result['rows'] ?? [],
        ], JSON_PRETTY_PRINT));

        return $path;
    }

    private function exportXlsx(Export $export, array $columns, array $rows): string
    {
        // Use OpenSpout if available, otherwise fall back to CSV
        if (!class_exists(\OpenSpout\Writer\XLSX\Writer::class)) {
            Log::info('[ExportService] OpenSpout not available, falling back to CSV.');
            return $this->exportCsv($export, $columns, $rows);
        }

        $path = "exports/bi/{$export->tenant_id}/{$export->id}.xlsx";
        $fullPath = storage_path("app/{$path}");

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($fullPath);

        // Header
        if (!empty($columns)) {
            $headerCells = array_map(fn($c) => \OpenSpout\Common\Entity\Cell::fromValue($c), $columns);
            $writer->addRow(new \OpenSpout\Common\Entity\Row($headerCells));
        }

        // Rows
        foreach ($rows as $row) {
            $cells = array_map(function ($value) {
                if (is_array($value)) $value = json_encode($value);
                return \OpenSpout\Common\Entity\Cell::fromValue((string) ($value ?? ''));
            }, array_values($row));
            $writer->addRow(new \OpenSpout\Common\Entity\Row($cells));
        }

        $writer->close();
        return $path;
    }
}

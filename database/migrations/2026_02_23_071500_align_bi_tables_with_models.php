<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns all BI table schemas with their Eloquent models.
 * Renames mismatched columns and adds missing columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── bi_kpis: rename metric_key→metric, config→calculation ──
        Schema::table('bi_kpis', function (Blueprint $table) {
            $table->renameColumn('metric_key', 'metric');
            $table->renameColumn('config', 'calculation');
        });
        Schema::table('bi_kpis', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
            $table->string('unit', 50)->nullable()->after('calculation');
            $table->string('direction', 10)->nullable()->after('unit');
            $table->string('category', 50)->nullable()->after('direction');
            $table->boolean('is_active')->default(true)->after('change_percent');
            $table->unsignedInteger('refresh_interval')->default(15)->after('is_active');
        });

        // ── bi_dashboards: add widgets + filters json columns ──
        Schema::table('bi_dashboards', function (Blueprint $table) {
            $table->json('widgets')->nullable()->after('layout');
            $table->json('filters')->nullable()->after('widgets');
        });

        // ── bi_alerts: rename notify_channels→channels, add kpi_id + cooldown ──
        Schema::table('bi_alerts', function (Blueprint $table) {
            $table->renameColumn('notify_channels', 'channels');
        });
        Schema::table('bi_alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('kpi_id')->nullable()->after('tenant_id');
            $table->unsignedInteger('cooldown_minutes')->default(60)->after('is_active');
            $table->foreign('kpi_id')->references('id')->on('bi_kpis')->nullOnDelete();
        });

        // ── bi_alert_history: rename metric_value→triggered_value, notified→notified_channels ──
        Schema::table('bi_alert_history', function (Blueprint $table) {
            $table->renameColumn('metric_value', 'triggered_value');
            $table->renameColumn('notified', 'notified_channels');
        });
        Schema::table('bi_alert_history', function (Blueprint $table) {
            $table->string('condition')->nullable()->after('threshold_value');
            $table->timestamp('acknowledged_at')->nullable()->after('notified_channels');
        });

        // ── bi_saved_queries: rename result_cache→result_columns, add data_source + is_public ──
        Schema::table('bi_saved_queries', function (Blueprint $table) {
            $table->renameColumn('result_cache', 'result_columns');
        });
        Schema::table('bi_saved_queries', function (Blueprint $table) {
            $table->string('data_source')->nullable()->after('description');
            $table->boolean('is_public')->default(false)->after('result_columns');
        });

        // ── bi_exports: rename config→filters, add report_id + file_size + started_at ──
        Schema::table('bi_exports', function (Blueprint $table) {
            $table->renameColumn('config', 'filters');
        });
        Schema::table('bi_exports', function (Blueprint $table) {
            $table->unsignedBigInteger('report_id')->nullable()->after('created_by');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_path');
            $table->timestamp('started_at')->nullable()->after('completed_at');
            $table->foreign('report_id')->references('id')->on('bi_reports')->nullOnDelete();
        });

        // ── bi_predictions: rename input_features→features, output→explanation, add predicted_value ──
        Schema::table('bi_predictions', function (Blueprint $table) {
            $table->renameColumn('input_features', 'features');
            $table->renameColumn('output', 'explanation');
        });
        Schema::table('bi_predictions', function (Blueprint $table) {
            $table->decimal('predicted_value', 16, 4)->nullable()->after('model_type');
        });

        // ── bi_benchmarks: rename metric_key→metric, p25→industry_p25, etc. ──
        Schema::table('bi_benchmarks', function (Blueprint $table) {
            $table->renameColumn('metric_key', 'metric');
            $table->renameColumn('p25', 'industry_p25');
            $table->renameColumn('p50', 'industry_p50');
            $table->renameColumn('p75', 'industry_p75');
            $table->renameColumn('p90', 'industry_p90');
        });
        Schema::table('bi_benchmarks', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->decimal('tenant_value', 16, 4)->nullable()->after('industry');
            $table->timestamp('calculated_at')->nullable()->after('sample_size');
        });
    }

    public function down(): void
    {
        // ── bi_benchmarks ──
        Schema::table('bi_benchmarks', function (Blueprint $table) {
            $table->dropColumn(['tenant_id', 'tenant_value', 'calculated_at']);
        });
        Schema::table('bi_benchmarks', function (Blueprint $table) {
            $table->renameColumn('metric', 'metric_key');
            $table->renameColumn('industry_p25', 'p25');
            $table->renameColumn('industry_p50', 'p50');
            $table->renameColumn('industry_p75', 'p75');
            $table->renameColumn('industry_p90', 'p90');
        });

        // ── bi_predictions ──
        Schema::table('bi_predictions', function (Blueprint $table) {
            $table->dropColumn('predicted_value');
        });
        Schema::table('bi_predictions', function (Blueprint $table) {
            $table->renameColumn('features', 'input_features');
            $table->renameColumn('explanation', 'output');
        });

        // ── bi_exports ──
        Schema::table('bi_exports', function (Blueprint $table) {
            $table->dropForeign(['report_id']);
            $table->dropColumn(['report_id', 'file_size', 'started_at']);
        });
        Schema::table('bi_exports', function (Blueprint $table) {
            $table->renameColumn('filters', 'config');
        });

        // ── bi_saved_queries ──
        Schema::table('bi_saved_queries', function (Blueprint $table) {
            $table->dropColumn(['data_source', 'is_public']);
        });
        Schema::table('bi_saved_queries', function (Blueprint $table) {
            $table->renameColumn('result_columns', 'result_cache');
        });

        // ── bi_alert_history ──
        Schema::table('bi_alert_history', function (Blueprint $table) {
            $table->dropColumn(['condition', 'acknowledged_at']);
        });
        Schema::table('bi_alert_history', function (Blueprint $table) {
            $table->renameColumn('triggered_value', 'metric_value');
            $table->renameColumn('notified_channels', 'notified');
        });

        // ── bi_alerts ──
        Schema::table('bi_alerts', function (Blueprint $table) {
            $table->dropForeign(['kpi_id']);
            $table->dropColumn(['kpi_id', 'cooldown_minutes']);
        });
        Schema::table('bi_alerts', function (Blueprint $table) {
            $table->renameColumn('channels', 'notify_channels');
        });

        // ── bi_dashboards ──
        Schema::table('bi_dashboards', function (Blueprint $table) {
            $table->dropColumn(['widgets', 'filters']);
        });

        // ── bi_kpis ──
        Schema::table('bi_kpis', function (Blueprint $table) {
            $table->dropColumn(['description', 'unit', 'direction', 'category', 'is_active', 'refresh_interval']);
        });
        Schema::table('bi_kpis', function (Blueprint $table) {
            $table->renameColumn('metric', 'metric_key');
            $table->renameColumn('calculation', 'config');
        });
    }
};

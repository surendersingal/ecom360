<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BI module — reports, dashboards, KPIs, alerts, data exports.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── BI Reports ─────────────────────────────────────────────────
        Schema::create('bi_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', [
                'standard',     // pre-built report
                'custom',       // user-created query builder
                'sql',          // raw SQL (admin only)
                'scheduled',    // auto-generated on schedule
            ])->default('standard');
            $table->json('config')->nullable();          // query config, filters, dimensions, metrics
            $table->json('visualizations')->nullable();  // [{type: bar/line/pie/table, config:{}}]
            $table->json('filters')->nullable();         // saved filter state
            $table->json('schedule')->nullable();        // {frequency, recipients, format}
            $table->boolean('is_public')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'type']);
        });

        // ── BI Dashboards ──────────────────────────────────────────────
        Schema::create('bi_dashboards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('layout')->nullable();          // grid layout: [{widget_key, report_id, x, y, w, h, config}]
            $table->boolean('is_default')->default(false);
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->index(['tenant_id']);
        });

        // ── KPI Definitions ────────────────────────────────────────────
        Schema::create('bi_kpis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('metric_key', 100);           // revenue, aov, conversion_rate, etc.
            $table->json('config')->nullable();           // {source, formula, filters, period}
            $table->decimal('target_value', 12, 4)->nullable();
            $table->decimal('current_value', 12, 4)->nullable();
            $table->decimal('previous_value', 12, 4)->nullable();
            $table->enum('trend', ['up', 'down', 'flat', 'none'])->default('none');
            $table->decimal('change_percent', 8, 2)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'metric_key']);
        });

        // ── BI Alerts ──────────────────────────────────────────────────
        Schema::create('bi_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('metric_key', 100);
            $table->enum('condition', ['above', 'below', 'change_percent', 'anomaly']);
            $table->decimal('threshold', 12, 4)->nullable();
            $table->json('notify_channels')->nullable();  // [email, push, webhook]
            $table->json('recipients')->nullable();       // [user_ids or emails]
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('trigger_count')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        // ── BI Alert History ───────────────────────────────────────────
        Schema::create('bi_alert_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('bi_alerts')->cascadeOnDelete();
            $table->decimal('metric_value', 12, 4);
            $table->decimal('threshold_value', 12, 4);
            $table->text('message')->nullable();
            $table->json('notified')->nullable();
            $table->timestamps();
            $table->index(['alert_id']);
        });

        // ── Saved Queries ──────────────────────────────────────────────
        Schema::create('bi_saved_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('query_config');                 // {dimensions, metrics, filters, sort, limit}
            $table->json('result_cache')->nullable();
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id']);
        });

        // ── Data Exports ───────────────────────────────────────────────
        Schema::create('bi_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->enum('format', ['csv', 'xlsx', 'json', 'pdf'])->default('csv');
            $table->json('config')->nullable();           // source, filters, columns
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        // ── Predictive Models ──────────────────────────────────────────
        Schema::create('bi_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('model_type', [
                'clv_prediction',
                'churn_risk',
                'purchase_propensity',
                'revenue_forecast',
                'demand_forecast',
                'next_best_action',
            ]);
            $table->json('input_features')->nullable();
            $table->json('output')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('entity_type', 50)->nullable();  // customer, product, category
            $table->string('entity_id', 100)->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'model_type']);
            $table->index(['entity_type', 'entity_id']);
        });

        // ── Benchmarks (cross-tenant anonymized) ───────────────────────
        Schema::create('bi_benchmarks', function (Blueprint $table): void {
            $table->id();
            $table->string('metric_key', 100);
            $table->string('industry', 100)->nullable();
            $table->string('tier', 50)->nullable();       // small, mid, enterprise
            $table->decimal('p25', 12, 4)->nullable();
            $table->decimal('p50', 12, 4)->nullable();
            $table->decimal('p75', 12, 4)->nullable();
            $table->decimal('p90', 12, 4)->nullable();
            $table->decimal('mean', 12, 4)->nullable();
            $table->unsignedInteger('sample_size')->default(0);
            $table->string('period', 20);                 // 2026-02, 2026-Q1
            $table->timestamps();
            $table->unique(['metric_key', 'industry', 'tier', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_benchmarks');
        Schema::dropIfExists('bi_predictions');
        Schema::dropIfExists('bi_exports');
        Schema::dropIfExists('bi_saved_queries');
        Schema::dropIfExists('bi_alert_history');
        Schema::dropIfExists('bi_alerts');
        Schema::dropIfExists('bi_kpis');
        Schema::dropIfExists('bi_dashboards');
        Schema::dropIfExists('bi_reports');
    }
};

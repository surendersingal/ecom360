<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Add compound indexes to MongoDB collections used by Analytics, DataSync,
 * and related modules.  Every index is tenant-scoped so that per-tenant
 * queries can leverage index prefix compression and avoid collection scans.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        // ── tracking_events ─────────────────────────────────────────
        Schema::connection('mongodb')->table('tracking_events', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'te_tenant_created');
            $table->index(['tenant_id', 'event_type', 'created_at'], 'te_tenant_event_created');
            $table->index(['tenant_id', 'visitor_id'], 'te_tenant_visitor');
            $table->index(['tenant_id', 'session_id'], 'te_tenant_session');
        });

        // ── synced_orders ───────────────────────────────────────────
        Schema::connection('mongodb')->table('synced_orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'so_tenant_created');
            $table->index(['tenant_id', 'customer_email'], 'so_tenant_customer_email');
            $table->index(['tenant_id', 'status'], 'so_tenant_status');
            $table->unique(['tenant_id', 'external_id'], 'so_tenant_external_unique');
        });

        // ── synced_customers ────────────────────────────────────────
        Schema::connection('mongodb')->table('synced_customers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'email'], 'sc_tenant_email_unique');
            $table->index(['tenant_id', 'created_at'], 'sc_tenant_created');
            $table->unique(['tenant_id', 'external_id'], 'sc_tenant_external_unique');
        });

        // ── synced_products ─────────────────────────────────────────
        Schema::connection('mongodb')->table('synced_products', function (Blueprint $table) {
            $table->unique(['tenant_id', 'external_id'], 'sp_tenant_external_unique');
            $table->index(['tenant_id', 'status'], 'sp_tenant_status');
            $table->index(['tenant_id', 'created_at'], 'sp_tenant_created');
        });

        // ── synced_categories ───────────────────────────────────────
        Schema::connection('mongodb')->table('synced_categories', function (Blueprint $table) {
            $table->unique(['tenant_id', 'external_id'], 'scat_tenant_external_unique');
        });

        // ── synced_inventory ────────────────────────────────────────
        Schema::connection('mongodb')->table('synced_inventory', function (Blueprint $table) {
            $table->index(['tenant_id', 'product_id'], 'si_tenant_product');
            $table->index(['tenant_id', 'sku'], 'si_tenant_sku');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->table('tracking_events', function (Blueprint $table) {
            $table->dropIndex('te_tenant_created');
            $table->dropIndex('te_tenant_event_created');
            $table->dropIndex('te_tenant_visitor');
            $table->dropIndex('te_tenant_session');
        });

        Schema::connection('mongodb')->table('synced_orders', function (Blueprint $table) {
            $table->dropIndex('so_tenant_created');
            $table->dropIndex('so_tenant_customer_email');
            $table->dropIndex('so_tenant_status');
            $table->dropIndex('so_tenant_external_unique');
        });

        Schema::connection('mongodb')->table('synced_customers', function (Blueprint $table) {
            $table->dropIndex('sc_tenant_email_unique');
            $table->dropIndex('sc_tenant_created');
            $table->dropIndex('sc_tenant_external_unique');
        });

        Schema::connection('mongodb')->table('synced_products', function (Blueprint $table) {
            $table->dropIndex('sp_tenant_external_unique');
            $table->dropIndex('sp_tenant_status');
            $table->dropIndex('sp_tenant_created');
        });

        Schema::connection('mongodb')->table('synced_categories', function (Blueprint $table) {
            $table->dropIndex('scat_tenant_external_unique');
        });

        Schema::connection('mongodb')->table('synced_inventory', function (Blueprint $table) {
            $table->dropIndex('si_tenant_product');
            $table->dropIndex('si_tenant_sku');
        });
    }
};

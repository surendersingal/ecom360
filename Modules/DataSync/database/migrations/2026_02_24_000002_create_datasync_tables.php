<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the DataSync module's relational tables:
 *
 *  - sync_connections   — one row per connected store (Magento / WooCommerce)
 *  - sync_permissions   — per-entity consent flags
 *  - sync_logs          — audit trail for every sync batch
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |------------------------------------------------------------------
        | sync_connections — stores connected via Magento module / WP plugin
        |------------------------------------------------------------------
        */
        Schema::create('sync_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('platform', 32);        // magento2 | woocommerce
            $table->string('platform_version')->nullable();
            $table->string('module_version')->nullable();
            $table->string('store_url');
            $table->string('store_name')->nullable();
            $table->unsignedSmallInteger('store_id')->default(0); // Magento store-view ID
            $table->string('php_version')->nullable();
            $table->string('locale', 10)->default('en_US');
            $table->string('currency', 3)->default('USD');
            $table->string('timezone')->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'platform']);
            $table->unique(['tenant_id', 'store_url', 'store_id']);
        });

        /*
        |------------------------------------------------------------------
        | sync_permissions — per-entity consent (defense-in-depth)
        |------------------------------------------------------------------
        | consent_level:
        |   public     — catalog items, no PII (products, categories, inventory)
        |   restricted — requires explicit opt-in (orders, customers)
        |   sensitive  — PII only with compliance (email, address)
        |------------------------------------------------------------------
        */
        Schema::create('sync_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('sync_connections')->cascadeOnDelete();
            $table->string('entity', 40);           // products | categories | orders | customers | inventory | sales | abandoned_carts | popup_captures
            $table->string('consent_level', 16);     // public | restricted | sensitive
            $table->boolean('enabled')->default(false);
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('granted_by')->nullable(); // "module_settings" | "admin_panel" | email
            $table->timestamps();

            $table->unique(['connection_id', 'entity']);
            $table->index(['tenant_id', 'entity', 'enabled']);
        });

        /*
        |------------------------------------------------------------------
        | sync_logs — audit trail for every sync batch
        |------------------------------------------------------------------
        */
        Schema::create('sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('sync_connections')->cascadeOnDelete();
            $table->string('entity', 40);
            $table->string('platform', 32);
            $table->enum('direction', ['push', 'pull'])->default('push');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'partial'])->default('pending');
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->json('errors')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity', 'created_at']);
            $table->index(['connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('sync_permissions');
        Schema::dropIfExists('sync_connections');
    }
};

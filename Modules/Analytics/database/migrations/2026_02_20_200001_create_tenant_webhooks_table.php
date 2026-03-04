<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores per-tenant webhook endpoints so store owners can push
 * real-time tracking events to external servers or Zapier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->string('endpoint_url', 2048);
            $table->string('secret_key')->nullable();
            $table->json('subscribed_events'); // e.g. ['purchase', 'cart_abandon']
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_webhooks');
    }
};

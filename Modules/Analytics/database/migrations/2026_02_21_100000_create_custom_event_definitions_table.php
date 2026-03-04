<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_event_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('event_key', 100);
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->json('schema')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('event_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'event_key']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_event_definitions');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores dynamic audience segment definitions.
 *
 * The `rules` JSON column holds an array of conditions that the
 * AudienceBuilderService translates into MongoDB queries against
 * the CustomerProfile collection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->string('name');
            $table->json('rules');
            $table->unsignedInteger('member_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_segments');
    }
};

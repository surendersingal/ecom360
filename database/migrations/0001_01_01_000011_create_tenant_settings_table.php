<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('module', 50)->comment('e.g. analytics, marketing, chatbot');
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module', 'key']);
            $table->index(['tenant_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavioral_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->json('trigger_condition')->comment('JSON rules: intent_level, min_cart_total, event_type, etc.');
            $table->string('action_type', 50)->comment('popup | discount | notification | redirect');
            $table->json('action_payload')->comment('Intervention details: title, discount_code, redirect_url, etc.');
            $table->unsignedTinyInteger('priority')->default(50)->comment('1-100, higher = evaluated first');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('cooldown_minutes')->default(30)->comment('Min minutes between re-firing per session');
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavioral_rules');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds an api_key column to the tenants table so storefronts
 * can authenticate tracking requests without Sanctum tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('api_key', 64)->nullable()->unique()->after('domain');
        });

        // Back-fill API keys for existing tenants.
        foreach (\App\Models\Tenant::all() as $tenant) {
            $tenant->update(['api_key' => 'ek_' . Str::random(48)]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('api_key');
        });
    }
};

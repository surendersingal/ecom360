<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a secret_key column to the tenants table for server-to-server
 * sync authentication (used by Magento module / WP plugin bulk sync).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('secret_key', 64)->nullable()->unique()->after('api_key');
        });

        // Back-fill existing tenants with a generated secret key.
        foreach (\App\Models\Tenant::all() as $tenant) {
            $tenant->update(['secret_key' => 'sk_' . Str::random(48)]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('secret_key');
        });
    }
};

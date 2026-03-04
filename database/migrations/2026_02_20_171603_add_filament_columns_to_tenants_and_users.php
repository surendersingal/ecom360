<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('is_verified')->default(false)->after('is_active');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false)->after('password');
            // Allow super admins to exist without a tenant.
            $table->foreignId('tenant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('is_verified');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_super_admin');
        });
    }
};

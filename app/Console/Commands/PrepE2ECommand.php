<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\E2ECustomerSeeder;
use Database\Seeders\E2ETenantSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Modules\Analytics\Models\BehavioralRule;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;

/**
 * Prepare a clean, perfectly deterministic state for the E2E test suite.
 *
 * Usage:
 *   php artisan ecom360:prep-e2e
 *
 * Steps:
 *   1. Truncate the MongoDB test collections (tracking_events, customer_profiles).
 *   2. Delete E2E-specific MySQL rows (tenant, user, behavioral_rules).
 *   3. Flush the E2E-related Redis keys (intent scores, live context, cooldowns).
 *   4. Run E2ETenantSeeder → E2ECustomerSeeder.
 */
final class PrepE2ECommand extends Command
{
    /** @var string */
    protected $signature = 'ecom360:prep-e2e';

    /** @var string */
    protected $description = 'Truncate test collections, flush Redis, and seed deterministic E2E data.';

    public function handle(): int
    {
        $this->info('⏳ Preparing E2E environment …');

        // ------------------------------------------------------------------
        //  1. Truncate MongoDB test collections
        // ------------------------------------------------------------------
        $this->info('  → Truncating MongoDB collections …');
        TrackingEvent::truncate();
        CustomerProfile::truncate();

        // ------------------------------------------------------------------
        //  2. Remove E2E MySQL records (order matters: FK constraints)
        // ------------------------------------------------------------------
        $this->info('  → Cleaning E2E MySQL records …');
        $tenant = Tenant::where('slug', E2ETenantSeeder::TENANT_SLUG)->first();

        if ($tenant !== null) {
            BehavioralRule::where('tenant_id', $tenant->id)->delete();
            User::where('tenant_id', $tenant->id)->delete();
            $tenant->delete();
        }

        // ------------------------------------------------------------------
        //  3. Flush E2E-related Redis keys
        // ------------------------------------------------------------------
        $this->info('  → Flushing Redis test keys …');
        $this->flushPattern('intent:score:e2e_*');
        $this->flushPattern('live_ctx:page:e2e_*');
        $this->flushPattern('live_ctx:cart:e2e_*');
        $this->flushPattern('live_ctx:attr:e2e_*');
        $this->flushPattern('intervention:cooldown:*:e2e_*');

        // ------------------------------------------------------------------
        //  4. Run the deterministic seeders
        // ------------------------------------------------------------------
        $this->info('  → Running E2ETenantSeeder …');
        (new E2ETenantSeeder())->run();

        $this->info('  → Running E2ECustomerSeeder …');
        (new E2ECustomerSeeder())->run();

        $this->newLine();
        $this->info('✅  E2E environment ready.');

        return self::SUCCESS;
    }

    /**
     * Delete all Redis keys matching a glob pattern.
     */
    private function flushPattern(string $pattern): void
    {
        $keys = Redis::keys($pattern);

        if ($keys !== []) {
            Redis::del(...$keys);
        }
    }
}

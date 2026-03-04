<?php

declare(strict_types=1);

namespace Modules\Analytics\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Purge old tracking events beyond the configured retention period.
 *
 * Usage:
 *   php artisan analytics:purge-old-events              # default 90 days
 *   php artisan analytics:purge-old-events --days=180   # 180 days
 *   php artisan analytics:purge-old-events --dry-run    # show count only
 *
 * Schedule via app/Console/Kernel.php:
 *   $schedule->command('analytics:purge-old-events')->daily();
 */
final class PurgeOldEvents extends Command
{
    protected $signature = 'analytics:purge-old-events
                            {--days=90 : Events older than this many days will be deleted}
                            {--tenant= : Only purge for a specific tenant ID}
                            {--dry-run : Show count of events to delete without actually deleting}';

    protected $description = 'Delete tracking events older than the configured retention period';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $tenantId = $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = CarbonImmutable::now()->subDays($days)->startOfDay();

        $this->info("Retention policy: {$days} days");
        $this->info("Cutoff date: {$cutoff->toDateTimeString()}");

        $mongo = app('db')->connection('mongodb');
        $collection = $mongo->getCollection('tracking_events');

        $filter = [
            'created_at' => [
                '$lt' => new \MongoDB\BSON\UTCDateTime($cutoff->getTimestamp() * 1000),
            ],
        ];

        if ($tenantId !== null) {
            $filter['tenant_id'] = $tenantId;
            $this->info("Scoped to tenant: {$tenantId}");
        }

        $count = $collection->countDocuments($filter);

        if ($dryRun) {
            $this->warn("[DRY RUN] Would delete {$count} events older than {$days} days.");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('No events to purge.');

            return self::SUCCESS;
        }

        $this->info("Deleting {$count} events...");

        $result = $collection->deleteMany($filter);
        $deleted = $result->getDeletedCount();

        $this->info("✓ Deleted {$deleted} tracking events.");

        // Also clean up orphaned customer profiles (optional)
        $profileCollection = $mongo->getCollection('customer_profiles');
        $orphanFilter = [
            'known_sessions' => ['$size' => 0],
        ];
        if ($tenantId !== null) {
            $orphanFilter['tenant_id'] = $tenantId;
        }
        $orphanCount = $profileCollection->countDocuments($orphanFilter);

        if ($orphanCount > 0) {
            $this->info("Found {$orphanCount} orphaned customer profiles with no sessions.");
        }

        return self::SUCCESS;
    }
}

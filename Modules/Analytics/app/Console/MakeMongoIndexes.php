<?php

declare(strict_types=1);

namespace Modules\Analytics\Console;

use Illuminate\Console\Command;
use MongoDB\Laravel\Connection;

/**
 * Creates compound indexes on the tracking_events MongoDB collection
 * to keep dashboard aggregation queries lightning-fast.
 *
 * Usage:  php artisan analytics:make-mongo-indexes
 */
final class MakeMongoIndexes extends Command
{
    protected $signature = 'analytics:make-mongo-indexes';

    protected $description = 'Create compound MongoDB indexes for the Analytics tracking_events collection.';

    public function handle(): int
    {
        /** @var Connection $mongo */
        $mongo = app('db')->connection('mongodb');

        $collection = $mongo->getCollection('tracking_events');

        /*
        |----------------------------------------------------------------------
        | Compound index: tenant_id + event_type
        |----------------------------------------------------------------------
        | Almost every dashboard query filters by tenant first, then groups
        | or filters by event_type. This compound index covers both.
        */
        $collection->createIndex(
            ['tenant_id' => 1, 'event_type' => 1],
            ['name' => 'idx_tenant_event_type', 'background' => true],
        );

        /*
        |----------------------------------------------------------------------
        | Compound index: tenant_id + created_at
        |----------------------------------------------------------------------
        | Time-range queries (last 7d, last 30d, custom range) need fast
        | lookups scoped to a tenant.
        */
        $collection->createIndex(
            ['tenant_id' => 1, 'created_at' => -1],
            ['name' => 'idx_tenant_created_at', 'background' => true],
        );

        /*
        |----------------------------------------------------------------------
        | Compound index: tenant_id + session_id
        |----------------------------------------------------------------------
        | Unique session counting (aggregateTraffic) benefits from this.
        */
        $collection->createIndex(
            ['tenant_id' => 1, 'session_id' => 1],
            ['name' => 'idx_tenant_session', 'background' => true],
        );

        $this->info('✓ MongoDB indexes created on tracking_events collection.');

        return self::SUCCESS;
    }
}

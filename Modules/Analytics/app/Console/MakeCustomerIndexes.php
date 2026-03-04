<?php

declare(strict_types=1);

namespace Modules\Analytics\Console;

use Illuminate\Console\Command;
use MongoDB\Laravel\Connection;

/**
 * Creates a compound unique index on the customer_profiles MongoDB collection
 * to prevent duplicate customer records per tenant.
 *
 * Usage:  php artisan analytics:make-customer-indexes
 */
final class MakeCustomerIndexes extends Command
{
    protected $signature = 'analytics:make-customer-indexes';

    protected $description = 'Create compound MongoDB indexes for the Analytics customer_profiles collection.';

    public function handle(): int
    {
        /** @var Connection $mongo */
        $mongo = app('db')->connection('mongodb');

        $collection = $mongo->getCollection('customer_profiles');

        /*
        |----------------------------------------------------------------------
        | Compound unique index: tenant_id + identifier_value
        |----------------------------------------------------------------------
        | Guarantees exactly one profile per identifier within a tenant.
        | For example, 'john@example.com' can only exist once for tenant_42.
        */
        $collection->createIndex(
            ['tenant_id' => 1, 'identifier_value' => 1],
            [
                'name'       => 'idx_tenant_identifier_unique',
                'unique'     => true,
                'background' => true,
            ],
        );

        /*
        |----------------------------------------------------------------------
        | Compound index: tenant_id + known_sessions (multikey)
        |----------------------------------------------------------------------
        | Speeds up reverse lookups — "which profile owns this session?"
        */
        $collection->createIndex(
            ['tenant_id' => 1, 'known_sessions' => 1],
            ['name' => 'idx_tenant_known_sessions', 'background' => true],
        );

        $this->info('✓ MongoDB indexes created on customer_profiles collection.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Modules\Analytics\Models\CustomerProfile;

/**
 * Deterministic E2E Customer Seeder.
 *
 * Creates a known MongoDB CustomerProfile linked to the E2E tenant.
 * The profile is pre-loaded with a specific device fingerprint so the
 * E2E test suite can verify fingerprint-based session stitching.
 *
 * All values are hardcoded — NO Faker.
 */
final class E2ECustomerSeeder extends Seeder
{
    /** @var string The known customer email seeded into MongoDB. */
    public const string CUSTOMER_EMAIL = 'e2e_buyer@example.com';

    /** @var string Exact device fingerprint hash pre-loaded in the profile. */
    public const string KNOWN_FINGERPRINT = 'e2e_known_device_hash_123';

    public function run(): void
    {
        $tenant = Tenant::where('slug', E2ETenantSeeder::TENANT_SLUG)->firstOrFail();

        $tenantId = (string) $tenant->id;

        // Remove any stale E2E profile before re-seeding.
        CustomerProfile::where('tenant_id', $tenantId)
            ->where('identifier_value', self::CUSTOMER_EMAIL)
            ->delete();

        CustomerProfile::create([
            'tenant_id'           => $tenantId,
            'identifier_type'     => 'email',
            'identifier_value'    => self::CUSTOMER_EMAIL,
            'known_sessions'      => [],
            'device_fingerprints' => [self::KNOWN_FINGERPRINT],
            'custom_attributes'   => [],
        ]);
    }
}

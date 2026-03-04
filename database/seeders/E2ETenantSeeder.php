<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Analytics\Models\BehavioralRule;

/**
 * Deterministic E2E Tenant Seeder.
 *
 * Creates a known MySQL Tenant, User (with Sanctum), and a BehavioralRule
 * that triggers a '10_percent_discount' action when intent_score >= 50.
 *
 * All values are hardcoded — NO Faker. This ensures perfectly predictable
 * state for the E2E assertion suite.
 */
final class E2ETenantSeeder extends Seeder
{
    /** @var string Slug used to look up the E2E tenant in all tests. */
    public const string TENANT_SLUG = 'e2e-test-client';

    /** @var string Tenant display name (matches the requested client_id concept). */
    public const string TENANT_NAME = 'E2E_TEST_CLIENT';

    /** @var string E2E user email for Sanctum authentication. */
    public const string USER_EMAIL = 'e2e@example.com';

    /** @var string E2E user plaintext password. */
    public const string USER_PASSWORD = 'e2e-password';

    /** @var string Name of the seeded behavioral rule. */
    public const string RULE_NAME = '10 Percent Discount';

    public function run(): void
    {
        // ---------------------------------------------------------------
        //  1. Tenant
        // ---------------------------------------------------------------
        $tenant = Tenant::firstOrCreate(
            ['slug' => self::TENANT_SLUG],
            [
                'name'      => self::TENANT_NAME,
                'domain'    => 'e2e.test',
                'is_active' => true,
            ],
        );

        // ---------------------------------------------------------------
        //  2. Authenticated User
        // ---------------------------------------------------------------
        User::firstOrCreate(
            ['email' => self::USER_EMAIL],
            [
                'tenant_id' => $tenant->id,
                'name'      => 'E2E Test User',
                'password'  => bcrypt(self::USER_PASSWORD),
            ],
        );

        // ---------------------------------------------------------------
        //  3. BehavioralRule: fire a 10 % discount when intent_score >= 50
        // ---------------------------------------------------------------
        BehavioralRule::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => self::RULE_NAME],
            [
                'trigger_condition' => ['min_intent_score' => 50],
                'action_type'       => 'discount',
                'action_payload'    => [
                    'discount_code'    => 'SAVE10',
                    'discount_percent' => 10,
                    'title'            => 'Here\'s 10 % off!',
                ],
                'priority'          => 80,
                'is_active'         => true,
                'cooldown_minutes'  => 5,
            ],
        );
    }
}

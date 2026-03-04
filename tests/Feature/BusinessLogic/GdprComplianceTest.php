<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessLogic;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\PrivacyComplianceService;
use Tests\TestCase;

/**
 * GDPR "Right to be Forgotten" (Art. 17) compliance test.
 *
 * Seeds a CustomerProfile with 50 historical TrackingEvents, executes
 * the PrivacyComplianceService::purgeCustomerData() method, and asserts
 * that every trace of the customer's data is completely erased from
 * MongoDB and Redis.
 */
final class GdprComplianceTest extends TestCase
{
    private Tenant $tenant;
    private User   $user;

    private const string CUSTOMER_EMAIL = 'gdpr-forget-me@example.com';

    /** Session IDs linked to the customer. */
    private array $sessionIds = [];

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name'      => 'GDPR Test Tenant',
            'slug'      => 'gdpr-test-' . uniqid(),
            'is_active' => true,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'GDPR Tester',
            'email'     => 'gdpr-tester-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

        // Ensure clean slate.
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();

        // Seed test data.
        $this->seedCustomerWithHistory();
    }

    protected function tearDown(): void
    {
        // Safety net — purge anything left behind by a failing assertion.
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        $this->user->forceDelete();
        $this->tenant->forceDelete();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Seeder
    // ------------------------------------------------------------------

    /**
     * Create a CustomerProfile with 5 sessions and distribute 50 tracking
     * events across those sessions (10 per session).
     */
    private function seedCustomerWithHistory(): void
    {
        $tenantId = (string) $this->tenant->id;

        // Generate 5 deterministic session IDs.
        $this->sessionIds = [];
        for ($s = 0; $s < 5; $s++) {
            $this->sessionIds[] = "gdpr_session_{$s}_" . uniqid();
        }

        // Create the profile.
        CustomerProfile::create([
            'tenant_id'           => $tenantId,
            'identifier_type'     => 'email',
            'identifier_value'    => self::CUSTOMER_EMAIL,
            'known_sessions'      => $this->sessionIds,
            'device_fingerprints' => ['gdpr_fp_hash_abc'],
            'custom_attributes'   => ['loyalty_tier' => 'gold'],
        ]);

        // Seed 50 tracking events (10 per session).
        $eventTypes = ['page_view', 'product_view', 'add_to_cart', 'search', 'click',
                       'cart_update', 'begin_checkout', 'checkout', 'purchase', 'page_view'];

        foreach ($this->sessionIds as $sessionId) {
            foreach ($eventTypes as $i => $eventType) {
                TrackingEvent::create([
                    'tenant_id'  => $tenantId,
                    'session_id' => $sessionId,
                    'event_type' => $eventType,
                    'url'        => "https://example.com/page/{$i}",
                    'metadata'   => ['index' => $i],
                    'custom_data' => [],
                    'ip_address' => '10.0.0.1',
                    'user_agent' => 'PHPUnit/GDPR',
                ]);
            }
        }

        // Seed some Redis keys so we can verify they are purged too.
        foreach ($this->sessionIds as $sessionId) {
            Redis::set("intent:score:{$sessionId}", 42);
            Redis::expire("intent:score:{$sessionId}", 1800);
        }
    }

    // ------------------------------------------------------------------
    //  Test Case — Right to be Forgotten
    // ------------------------------------------------------------------

    /**
     * After purgeCustomerData(), MongoDB must contain ZERO records for
     * the customer's sessions, and the CustomerProfile must be deleted.
     */
    public function test_purge_customer_data_erases_all_traces(): void
    {
        $tenantId = (string) $this->tenant->id;

        // ---- Sanity check: data exists before purge -------------------
        $profileBefore = CustomerProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('identifier_value', self::CUSTOMER_EMAIL)
            ->first();

        $this->assertNotNull($profileBefore, 'Profile must exist before purge.');

        $eventsBefore = TrackingEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('session_id', $this->sessionIds)
            ->count();

        $this->assertSame(50, $eventsBefore, 'Expected 50 seeded events.');

        // ---- Execute the GDPR purge -----------------------------------
        /** @var PrivacyComplianceService $privacy */
        $privacy = app(PrivacyComplianceService::class);

        $result = $privacy->purgeCustomerData($tenantId, self::CUSTOMER_EMAIL);

        // ---- Assert return summary ------------------------------------
        $this->assertTrue($result['profile_found']);
        $this->assertSame(5, $result['sessions_purged']);
        $this->assertSame(50, $result['events_deleted']);
        $this->assertGreaterThanOrEqual(0, $result['redis_keys_removed']);

        // ---- Assert MongoDB is clean ----------------------------------
        $profileAfter = CustomerProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('identifier_value', self::CUSTOMER_EMAIL)
            ->first();

        $this->assertNull($profileAfter, 'Profile must be deleted after purge.');

        $eventsAfter = TrackingEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('session_id', $this->sessionIds)
            ->count();

        $this->assertSame(0, $eventsAfter, 'All tracking events must be erased.');

        // ---- Assert Redis keys are gone --------------------------------
        foreach ($this->sessionIds as $sessionId) {
            $this->assertSame(
                0,
                (int) Redis::exists("intent:score:{$sessionId}"),
                "Redis intent key for {$sessionId} must be purged.",
            );
        }
    }

    /**
     * Calling purgeCustomerData for a non-existent customer must return
     * a safe no-op result without errors.
     */
    public function test_purge_nonexistent_customer_returns_noop(): void
    {
        /** @var PrivacyComplianceService $privacy */
        $privacy = app(PrivacyComplianceService::class);

        $result = $privacy->purgeCustomerData(
            (string) $this->tenant->id,
            'ghost-user@example.com',
        );

        $this->assertFalse($result['profile_found']);
        $this->assertSame(0, $result['sessions_purged']);
        $this->assertSame(0, $result['events_deleted']);
    }
}

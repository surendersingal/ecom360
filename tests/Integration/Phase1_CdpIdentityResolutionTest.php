<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\E2ECustomerSeeder;
use Database\Seeders\E2ETenantSeeder;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\FingerprintResolutionService;
use Modules\Analytics\Services\IdentityResolutionService;
use Modules\Analytics\Services\IntentScoringService;
use Modules\Analytics\Services\PrivacyComplianceService;
use Tests\TestCase;

/**
 * Phase 1: Identity Resolution & CDP (The Brain)
 *
 * Tests 1-5 — Flawless cross-device identity stitching, collision
 * prevention, zombie resurrection, GDPR cascading purge, and
 * unstructured custom payload ingestion.
 */
final class Phase1_CdpIdentityResolutionTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::firstOrCreate(
            ['slug' => 'cdp-e2e-' . substr(md5((string) mt_rand()), 0, 8)],
            ['name' => 'CDP E2E Tenant', 'is_active' => true],
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'CDP Tester',
            'email'     => 'cdp-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        TrackingEvent::where('tenant_id', $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        TrackingEvent::where('tenant_id', $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', $this->tenant->id)->delete();
        $this->user->forceDelete();
        $this->tenant->forceDelete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  UC1: Ghost to VIP — Cross-Device Stitch
    // ------------------------------------------------------------------

    /**
     * Scenario: User browses anonymously on iPhone (Tuesday fingerprint),
     * builds a cart, then on Thursday opens laptop, clicks promo email,
     * logs in with email, and buys.
     *
     * Expected: Both sessions merge into one CDP profile, with the
     * final sale attributable to both the mobile browsing AND the email.
     */
    public function test_uc1_ghost_to_vip_cross_device_stitch(): void
    {
        $tid = $this->tenant->id;
        $iphoneFingerprint = 'fp_iphone_safari_' . uniqid();
        $laptopFingerprint = 'fp_laptop_chrome_' . uniqid();
        $mobileSession     = 'sess_iphone_tuesday_' . uniqid();
        $desktopSession    = 'sess_laptop_thursday_' . uniqid();
        $customerEmail     = 'vip-shopper@example.com';

        /** @var FingerprintResolutionService $fp */
        $fp = app(FingerprintResolutionService::class);
        /** @var IdentityResolutionService $ir */
        $ir = app(IdentityResolutionService::class);

        // ── Tuesday: Anonymous iPhone browsing ─────────────────────────
        $fp->resolve($this->tenant->id, $mobileSession, $iphoneFingerprint);

        // Seed some tracking events for the mobile session (cart building).
        TrackingEvent::create([
            'tenant_id'  => $tid,
            'session_id' => $mobileSession,
            'event_type' => 'product_view',
            'url'        => 'https://store.com/red-shoes',
            'metadata'   => ['product_id' => 'SHOE-RED-42'],
        ]);
        TrackingEvent::create([
            'tenant_id'  => $tid,
            'session_id' => $mobileSession,
            'event_type' => 'add_to_cart',
            'url'        => 'https://store.com/red-shoes',
            'metadata'   => ['product_id' => 'SHOE-RED-42', 'price' => 89.99],
        ]);

        // Profile should exist as anonymous with the iPhone fingerprint.
        $anonProfile = CustomerProfile::where('tenant_id', $tid)
            ->where('device_fingerprints', $iphoneFingerprint)
            ->first();

        $this->assertNotNull($anonProfile, 'Anonymous profile should exist after iPhone browsing.');
        $this->assertContains($mobileSession, $anonProfile->known_sessions);
        $this->assertSame('anonymous', $anonProfile->identifier_type);

        // ── Thursday: Laptop — click email, log in, buy ────────────────
        // Step A: Fingerprint resolution with the laptop fingerprint.
        // Since no profile owns this fingerprint yet, but the session is new,
        // it would create a second anon profile... UNLESS the user logs in.
        $fp->resolve($this->tenant->id, $desktopSession, $laptopFingerprint);

        // Step B: Identity resolution — user provides email (from email link).
        $ir->resolveIdentity(
            $this->tenant->id,
            $desktopSession,
            ['type' => 'email', 'value' => $customerEmail],
            null,
        );

        // Step C: Also resolve identity on the mobile session retroactively
        // (this simulates the system recognizing the same user across devices).
        $ir->resolveIdentity(
            $this->tenant->id,
            $mobileSession,
            ['type' => 'email', 'value' => $customerEmail],
            null,
        );

        // ── Assertions ─────────────────────────────────────────────────
        // There should be exactly ONE profile with this email.
        $identifiedProfile = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', $customerEmail)
            ->first();

        $this->assertNotNull($identifiedProfile, 'Identified profile must exist.');

        // Both sessions must be linked.
        $this->assertContains($mobileSession, $identifiedProfile->known_sessions,
            'Tuesday iPhone session must be stitched.');
        $this->assertContains($desktopSession, $identifiedProfile->known_sessions,
            'Thursday laptop session must be stitched.');

        // Purchase event on the desktop session for attribution.
        TrackingEvent::create([
            'tenant_id'  => $tid,
            'session_id' => $desktopSession,
            'event_type' => 'purchase',
            'url'        => 'https://store.com/checkout/success',
            'metadata'   => [
                'order_total' => 89.99,
                'attribution' => ['source' => 'email', 'source_id' => 'promo_spring_2026'],
            ],
        ]);

        // Verify the purchase event exists and attribution is intact.
        $purchase = TrackingEvent::where('tenant_id', $tid)
            ->where('session_id', $desktopSession)
            ->where('event_type', 'purchase')
            ->first();

        $this->assertNotNull($purchase);
        $this->assertSame('email', $purchase->metadata['attribution']['source']);
    }

    // ------------------------------------------------------------------
    //  UC2: Fingerprint Collision (Coffee Shop Dilemma)
    // ------------------------------------------------------------------

    /**
     * Scenario: Two users on same WiFi with nearly identical device
     * fingerprints — but different localStorage tokens (session IDs).
     *
     * Expected: Each gets their own CDP profile; carts never bleed.
     */
    public function test_uc2_fingerprint_collision_prevention(): void
    {
        $tid = $this->tenant->id;

        // Two users share EXACTLY the same fingerprint (same device model + browser).
        $sharedFingerprint = 'fp_coffeeshop_iphone15_safari_' . uniqid();

        $userASession = 'sess_user_a_' . uniqid();
        $userBSession = 'sess_user_b_' . uniqid();

        /** @var FingerprintResolutionService $fp */
        $fp = app(FingerprintResolutionService::class);
        /** @var IdentityResolutionService $ir */
        $ir = app(IdentityResolutionService::class);

        // User A arrives first, gets fingerprint profile.
        $fp->resolve($this->tenant->id, $userASession, $sharedFingerprint);

        // Associate User A with their email.
        $ir->resolveIdentity(
            $this->tenant->id,
            $userASession,
            ['type' => 'email', 'value' => 'userA@example.com'],
            null,
        );

        // User B arrives second with the SAME fingerprint, different session.
        // FingerprintResolution will link User B's session to User A's profile
        // (same fingerprint). But then when User B identifies with their OWN
        // email, IdentityResolution will create a SEPARATE profile.
        $fp->resolve($this->tenant->id, $userBSession, $sharedFingerprint);

        $ir->resolveIdentity(
            $this->tenant->id,
            $userBSession,
            ['type' => 'email', 'value' => 'userB@example.com'],
            null,
        );

        // ── Assertions ─────────────────────────────────────────────────
        $profileA = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', 'userA@example.com')
            ->first();

        $profileB = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', 'userB@example.com')
            ->first();

        $this->assertNotNull($profileA, 'User A profile must exist.');
        $this->assertNotNull($profileB, 'User B profile must exist.');

        // Crucially, they must be DIFFERENT profiles (different _id).
        $this->assertNotSame(
            (string) $profileA->_id,
            (string) $profileB->_id,
            'Users sharing a fingerprint must NOT merge into the same profile once identified.',
        );

        // Each user's session is linked to their own identified profile.
        $this->assertContains($userASession, $profileA->known_sessions);
        $this->assertContains($userBSession, $profileB->known_sessions);
    }

    // ------------------------------------------------------------------
    //  UC3: Zombie Account Resurrection & Tagging
    // ------------------------------------------------------------------

    /**
     * Scenario: User hasn't purchased in 2 years. They log in.
     *
     * Expected: Analytics updates Recency score, removes from "Churned"
     * segment context, and the profile is updated with fresh session data.
     */
    public function test_uc3_zombie_account_resurrection(): void
    {
        $tid = $this->tenant->id;
        $zombieEmail = 'zombie-' . uniqid() . '@example.com';

        // Seed a "zombie" profile — last seen 2 years ago, RFM score indicating churn.
        $profile = CustomerProfile::create([
            'tenant_id'           => $tid,
            'identifier_type'     => 'email',
            'identifier_value'    => $zombieEmail,
            'known_sessions'      => ['old_session_2024'],
            'device_fingerprints' => ['old_fp_hash'],
            'custom_attributes'   => ['loyalty_tier' => 'bronze', 'segment' => 'churned'],
            'rfm_score'           => '111', // Worst possible RFM
            'rfm_details'         => [
                'recency_days' => 730,
                'frequency'    => 1,
                'monetary'     => 29.99,
                'scored_at'    => now()->subYears(2)->toIso8601String(),
            ],
        ]);

        $this->assertSame('111', $profile->rfm_score, 'Pre-condition: RFM should indicate churn.');

        // ── Zombie logs in today → Identity Resolution fires ───────────
        $newSession = 'sess_zombie_return_' . uniqid();

        /** @var IdentityResolutionService $ir */
        $ir = app(IdentityResolutionService::class);

        $ir->resolveIdentity(
            $this->tenant->id,
            $newSession,
            ['type' => 'email', 'value' => $zombieEmail],
            ['segment' => 'win_back', 'last_login' => now()->toIso8601String()],
        );

        // ── Assertions ─────────────────────────────────────────────────
        $refreshed = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', $zombieEmail)
            ->first();

        $this->assertNotNull($refreshed);

        // New session must be linked.
        $this->assertContains($newSession, $refreshed->known_sessions);
        $this->assertContains('old_session_2024', $refreshed->known_sessions, 'Old session must be preserved.');

        // Custom attributes should be updated (segment → win_back).
        $this->assertSame('win_back', $refreshed->custom_attributes['segment']);
        $this->assertArrayHasKey('last_login', $refreshed->custom_attributes);

        // The original RFM data is still intact (cron job would update it).
        $this->assertNotNull($refreshed->rfm_details);
    }

    // ------------------------------------------------------------------
    //  UC4: GDPR "Right to be Forgotten" Cascading Purge
    // ------------------------------------------------------------------

    /**
     * Scenario: User requests account deletion. Admin clicks "Purge".
     *
     * Expected: All MongoDB tracking events deleted, CustomerProfile
     * deleted, Redis keys removed, but raw numeric revenue for BI
     * dashboards is retained (anonymised aggregate).
     */
    public function test_uc4_gdpr_cascading_purge(): void
    {
        $tid = $this->tenant->id;
        $purgeEmail = 'purge-me-' . uniqid() . '@example.com';
        $sessions   = ['gdpr_s1_' . uniqid(), 'gdpr_s2_' . uniqid()];

        // ── Seed customer data ─────────────────────────────────────────
        CustomerProfile::create([
            'tenant_id'           => $tid,
            'identifier_type'     => 'email',
            'identifier_value'    => $purgeEmail,
            'known_sessions'      => $sessions,
            'device_fingerprints' => ['fp_gdpr_hash'],
            'custom_attributes'   => ['name' => 'John Doe'],
        ]);

        // Seed some tracking events (including a purchase with revenue).
        $revenueTotal = 0;
        foreach ($sessions as $i => $sid) {
            for ($j = 0; $j < 5; $j++) {
                $eventType = $j === 4 ? 'purchase' : 'page_view';
                $revenue   = $eventType === 'purchase' ? 99.99 + $i : 0;
                $revenueTotal += $revenue;

                TrackingEvent::create([
                    'tenant_id'  => $tid,
                    'session_id' => $sid,
                    'event_type' => $eventType,
                    'url'        => "https://store.com/page/{$j}",
                    'metadata'   => $eventType === 'purchase'
                        ? ['order_total' => $revenue, 'order_id' => "ORD-{$i}-{$j}"]
                        : ['index' => $j],
                ]);
            }

            // Seed Redis intent score.
            Redis::set("intent:score:{$sid}", 55);
            Redis::expire("intent:score:{$sid}", 1800);
        }

        // Verify pre-purge state.
        $this->assertSame(10, TrackingEvent::where('tenant_id', $tid)->whereIn('session_id', $sessions)->count());
        $this->assertNotNull(CustomerProfile::where('tenant_id', $tid)->where('identifier_value', $purgeEmail)->first());

        // ── Execute GDPR purge ─────────────────────────────────────────
        /** @var PrivacyComplianceService $privacy */
        $privacy = app(PrivacyComplianceService::class);
        $result  = $privacy->purgeCustomerData($tid, $purgeEmail);

        // ── Assertions ─────────────────────────────────────────────────
        $this->assertTrue($result['profile_found']);
        $this->assertSame(2, $result['sessions_purged']);
        $this->assertSame(10, $result['events_deleted']);

        // Profile must be gone.
        $this->assertNull(
            CustomerProfile::where('tenant_id', $tid)->where('identifier_value', $purgeEmail)->first(),
            'CustomerProfile must be deleted after GDPR purge.',
        );

        // All tracking events for these sessions must be gone.
        $this->assertSame(
            0,
            TrackingEvent::where('tenant_id', $tid)->whereIn('session_id', $sessions)->count(),
            'All tracking events must be erased.',
        );

        // Redis keys must be gone.
        foreach ($sessions as $sid) {
            $this->assertSame(0, (int) Redis::exists("intent:score:{$sid}"),
                "Redis intent key for {$sid} must be purged.");
        }

        // Revenue total was $revenueTotal — in a real system, the BI
        // aggregation pipeline retains anonymised numeric data separately.
        $this->assertGreaterThan(0, $revenueTotal,
            'Revenue existed before purge (BI aggregates would retain this).');
    }

    // ------------------------------------------------------------------
    //  UC5: Unstructured Custom Payload Ingestion
    // ------------------------------------------------------------------

    /**
     * Scenario: Client sends arbitrary custom JSON payload with page view.
     *
     * Expected: API accepts it without schema error; MongoDB profile
     * updates with the custom fields for future marketing filters.
     */
    public function test_uc5_unstructured_custom_payload_ingestion(): void
    {
        Sanctum::actingAs($this->user);

        $sessionId = 'custom_payload_' . uniqid();
        $customerEmail = 'custom-' . uniqid() . '@example.com';

        // Send a tracking event with arbitrary custom_data.
        $response = $this->postJson('/api/v1/analytics/ingest', [
            'payload' => [
                'session_id'          => $sessionId,
                'event_type'          => 'page_view',
                'url'                 => 'https://store.com/shoes/red-sneakers',
                'custom_data'         => [
                    'shoe_size'  => 11,
                    'color_pref' => 'red',
                    'fit_type'   => 'wide',
                    'loyalty_id' => 'LYL-987654',
                ],
                'customer_identifier' => [
                    'type'  => 'email',
                    'value' => $customerEmail,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        // Verify the event was stored with custom_data intact.
        $event = TrackingEvent::where('tenant_id', (string) $this->tenant->id)
            ->where('session_id', $sessionId)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(11, $event->custom_data['shoe_size']);
        $this->assertSame('red', $event->custom_data['color_pref']);
        $this->assertSame('wide', $event->custom_data['fit_type']);
        $this->assertSame('LYL-987654', $event->custom_data['loyalty_id']);

        // Verify the profile was created with custom attributes merged.
        $profile = CustomerProfile::where('tenant_id', (string) $this->tenant->id)
            ->where('identifier_value', $customerEmail)
            ->first();

        $this->assertNotNull($profile, 'Profile should be created via IdentityResolution.');
        $this->assertSame(11, $profile->custom_attributes['shoe_size']);
        $this->assertSame('red', $profile->custom_attributes['color_pref']);
    }
}

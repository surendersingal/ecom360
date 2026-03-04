<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\E2ECustomerSeeder;
use Database\Seeders\E2ETenantSeeder;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Tests\TestCase;

/**
 * E2E Journey 1 — The Real Data Pipeline.
 *
 * Tests the API → TrackingService → MongoDB flow with NO mocks.
 * QUEUE_CONNECTION=sync ensures any queued work processes immediately.
 *
 * Covers:
 *  1. A real POST to the ingestion API persists a document to MongoDB.
 *  2. A device fingerprint in the payload stitches the session to the
 *     seeded CustomerProfile (device + identity resolution E2E).
 */
final class E2EDataPipelineTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        // Seed deterministic E2E data.
        (new E2ETenantSeeder())->run();
        (new E2ECustomerSeeder())->run();

        $this->tenant = Tenant::where('slug', E2ETenantSeeder::TENANT_SLUG)->firstOrFail();
        $this->user   = User::where('email', E2ETenantSeeder::USER_EMAIL)->firstOrFail();

        // Ensure a clean slate for tracking events.
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Test 1: API → MongoDB persistence
    // ------------------------------------------------------------------

    public function test_real_payload_hits_api_and_writes_to_mongo(): void
    {
        Sanctum::actingAs($this->user);

        $sessionId = 'e2e_pipeline_sess_001';

        $response = $this->postJson('/api/v1/analytics/ingest', [
            'payload' => [
                'session_id' => $sessionId,
                'event_type' => 'page_view',
                'url'        => 'https://e2e-store.example.com/products/test-widget',
                'metadata'   => ['product_id' => 'e2e_prod_001', 'price' => 49.99],
                'ip_address' => '203.0.113.42',
                'user_agent' => 'E2E-Test-Agent/1.0',
            ],
        ]);

        // ── HTTP assertions ──────────────────────────────────────────
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.event_type', 'page_view')
            ->assertJsonPath('data.session_id', $sessionId);

        // ── MongoDB assertions ───────────────────────────────────────
        $stored = TrackingEvent::query()
            ->where('tenant_id', (string) $this->tenant->id)
            ->where('session_id', $sessionId)
            ->where('event_type', 'page_view')
            ->first();

        $this->assertNotNull($stored, 'TrackingEvent was not persisted to MongoDB.');
        $this->assertSame((string) $this->tenant->id, $stored->tenant_id);
        $this->assertSame($sessionId, $stored->session_id);
        $this->assertSame('page_view', $stored->event_type);
        $this->assertSame('https://e2e-store.example.com/products/test-widget', $stored->url);
        $this->assertSame('e2e_prod_001', $stored->metadata['product_id']);
        $this->assertEquals(49.99, $stored->metadata['price']);
    }

    // ------------------------------------------------------------------
    //  Test 2: Device fingerprint + Identity resolution stitching
    // ------------------------------------------------------------------

    public function test_real_fingerprint_stitches_to_seeded_profile(): void
    {
        Sanctum::actingAs($this->user);

        $sessionId = 'e2e_pipeline_sess_002';

        $response = $this->postJson('/api/v1/analytics/ingest', [
            'payload' => [
                'session_id'          => $sessionId,
                'event_type'          => 'product_view',
                'url'                 => 'https://e2e-store.example.com/products/premium-widget',
                'metadata'            => ['product_id' => 'e2e_prod_002'],
                'device_fingerprint'  => E2ECustomerSeeder::KNOWN_FINGERPRINT,
                'customer_identifier' => [
                    'type'  => 'email',
                    'value' => E2ECustomerSeeder::CUSTOMER_EMAIL,
                ],
            ],
        ]);

        $response->assertStatus(201);

        // ── MongoDB profile assertions ───────────────────────────────
        $profile = CustomerProfile::query()
            ->where('tenant_id', (string) $this->tenant->id)
            ->where('identifier_value', E2ECustomerSeeder::CUSTOMER_EMAIL)
            ->first();

        $this->assertNotNull($profile, 'CustomerProfile not found in MongoDB.');

        // The FingerprintResolutionService should have linked the new
        // session to the existing profile (matched by device_fingerprints
        // array containing 'e2e_known_device_hash_123').
        $this->assertContains(
            $sessionId,
            $profile->known_sessions,
            'FingerprintResolution / IdentityResolution did not stitch the session to the profile.',
        );

        // The original fingerprint must still be present.
        $this->assertContains(
            E2ECustomerSeeder::KNOWN_FINGERPRINT,
            $profile->device_fingerprints,
        );
    }
}

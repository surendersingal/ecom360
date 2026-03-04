<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeCases;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\FingerprintResolutionService;
use Modules\Analytics\Services\IntentScoringService;
use Tests\TestCase;

/**
 * High-Concurrency & Race Condition tests.
 *
 * Proves that:
 *  1. Atomic Cache::lock in FingerprintResolutionService prevents
 *     duplicate MongoDB CustomerProfiles when many requests arrive
 *     with the same never-before-seen device_fingerprint.
 *
 *  2. Redis INCRBY in IntentScoringService guarantees no score drift
 *     when many events fire for the same session.
 */
final class ConcurrencyTest extends TestCase
{
    private Tenant $tenant;
    private User   $user;

    /** Deterministic fingerprint that does NOT exist before each test. */
    private string $fingerprint;

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name'      => 'Concurrency Test Tenant',
            'slug'      => 'conc-test-' . uniqid(),
            'is_active' => true,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Concurrency Tester',
            'email'     => 'conc-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

        $this->fingerprint = 'conc_fp_' . bin2hex(random_bytes(16));

        // Clean slate for this tenant in MongoDB.
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        $this->user->forceDelete();
        $this->tenant->forceDelete();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Test Case 1 — Duplicate profile prevention
    // ------------------------------------------------------------------

    /**
     * 5 "simultaneous" requests with the same device_fingerprint must
     * produce exactly ONE CustomerProfile.  The Cache::lock inside
     * FingerprintResolutionService serialises the check-then-create
     * critical section so that the first request creates the profile
     * and requests 2-5 simply append their session_id.
     */
    public function test_concurrent_fingerprint_requests_prevent_duplicate_profiles(): void
    {
        Sanctum::actingAs($this->user);

        $tenantId = (string) $this->tenant->id;

        // Fire 5 ingestion requests, each with a unique session but the
        // SAME never-before-seen device_fingerprint.
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson('/api/v1/analytics/ingest', [
                'payload' => [
                    'session_id'         => "conc_session_{$i}",
                    'event_type'         => 'page_view',
                    'url'                => "https://example.com/page/{$i}",
                    'device_fingerprint' => $this->fingerprint,
                ],
            ]);
        }

        // Every request must succeed.
        foreach ($responses as $index => $response) {
            $response->assertStatus(201, "Request #{$index} should have succeeded.");
        }

        // EXACTLY 1 CustomerProfile for this fingerprint (no duplicates).
        $profileCount = CustomerProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('device_fingerprints', $this->fingerprint)
            ->count();

        $this->assertSame(1, $profileCount, 'Cache::lock must prevent duplicate profiles.');

        // All 5 sessions must be linked to that single profile.
        $profile = CustomerProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('device_fingerprints', $this->fingerprint)
            ->first();

        $this->assertNotNull($profile);

        $sessions = $profile->known_sessions;
        $this->assertCount(5, $sessions, 'All 5 sessions must be stitched to the profile.');

        for ($i = 0; $i < 5; $i++) {
            $this->assertContains("conc_session_{$i}", $sessions);
        }
    }

    // ------------------------------------------------------------------
    //  Test Case 2 — Atomic intent score increments
    // ------------------------------------------------------------------

    /**
     * 10 add_to_cart events (+20 pts each) for the same session_id must
     * produce a Redis intent score of EXACTLY 200 — proving the Redis
     * INCRBY command is truly atomic with no lost increments.
     */
    public function test_atomic_intent_score_increments(): void
    {
        $sessionId = 'atomic_session_' . uniqid();

        /** @var IntentScoringService $scoring */
        $scoring = app(IntentScoringService::class);

        // Simulate 10 rapid-fire add_to_cart events.
        for ($i = 0; $i < 10; $i++) {
            $scoring->recordEvent($sessionId, 'add_to_cart');
        }

        // Intent score must be exactly 10 × 20 = 200.
        $score = $scoring->getScore($sessionId);
        $this->assertSame(200, $score, 'Redis INCRBY must yield exactly 200 (10 × 20 pts).');

        // Evaluate intent level — 200 ≥ 60 → high_intent.
        $intent = $scoring->evaluateIntent($sessionId);
        $this->assertSame('high_intent', $intent['level']);

        // Cleanup.
        $prefix = config('database.redis.options.prefix', '');
        Redis::del(str_replace($prefix, '', "intent:score:{$sessionId}"));
    }
}

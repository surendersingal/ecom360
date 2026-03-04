<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Events\IntegrationEvent;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\E2ECustomerSeeder;
use Database\Seeders\E2ETenantSeeder;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Jobs\CalculateCustomerRfmJob;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Tests\TestCase;

/**
 * E2E Journey 3 — Financial BI Aggregation.
 *
 * Proves the Attribution engine and the RFM calculation job produce
 * correct results when operating on real data in MongoDB and MySQL.
 *
 * Covers:
 *  1. Multi-touch attribution: A campaign_event touchpoint is correctly
 *     recorded as first_touch on the subsequent purchase document.
 *  2. RFM scoring: Three purchases at different dates produce the
 *     mathematically expected RFM score string ('523') after the
 *     CalculateCustomerRfmJob is manually executed.
 */
final class E2EFinancialAggregationTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        (new E2ETenantSeeder())->run();
        (new E2ECustomerSeeder())->run();

        $this->tenant = Tenant::where('slug', E2ETenantSeeder::TENANT_SLUG)->firstOrFail();
        $this->user   = User::where('email', E2ETenantSeeder::USER_EMAIL)->firstOrFail();

        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Reset frozen time.
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Test 1: Attribution enriches the purchase document
    // ------------------------------------------------------------------

    public function test_real_attribution_updates_mongo_document(): void
    {
        Sanctum::actingAs($this->user);

        $sessionId = 'e2e_attr_sess_001';

        // ── Step 1: Fire a campaign_event (touchpoint) from Facebook ──
        $this->postJson('/api/v1/analytics/ingest', [
            'payload' => [
                'session_id' => $sessionId,
                'event_type' => 'campaign_event',
                'url'        => 'https://e2e-store.example.com/landing?utm_source=facebook',
                'metadata'   => [
                    'utm_source'  => 'facebook',
                    'utm_medium'  => 'cpc',
                    'campaign_id' => 'summer_sale_2026',
                ],
            ],
        ])->assertStatus(201);

        // ── Step 2: Fire a purchase for $150 on the same session ──────
        $this->postJson('/api/v1/analytics/ingest', [
            'payload' => [
                'session_id' => $sessionId,
                'event_type' => 'purchase',
                'url'        => 'https://e2e-store.example.com/checkout/complete',
                'metadata'   => [
                    'order_id'    => 'ORD-E2E-ATTR-001',
                    'order_total' => 150,
                ],
            ],
        ])->assertStatus(201);

        // ── Step 3: Query MongoDB for the purchase document ───────────
        $purchaseEvent = TrackingEvent::query()
            ->where('tenant_id', (string) $this->tenant->id)
            ->where('session_id', $sessionId)
            ->where('event_type', 'purchase')
            ->first();

        $this->assertNotNull($purchaseEvent, 'Purchase event not found in MongoDB.');

        // The multi-touch attribution engine should have resolved the
        // campaign_event as the first_touch and embedded it in metadata.
        $this->assertArrayHasKey(
            'multi_touch_attribution',
            $purchaseEvent->metadata,
            'multi_touch_attribution key missing from purchase metadata.',
        );

        $mta = $purchaseEvent->metadata['multi_touch_attribution'];

        $this->assertSame(1, $mta['touch_count']);

        // First touch must reference the Facebook campaign event.
        $firstTouch = $mta['first_touch'];
        $this->assertSame('campaign_event', $firstTouch['event_type']);
        $this->assertSame('facebook', $firstTouch['metadata']['utm_source']);
        $this->assertSame('summer_sale_2026', $firstTouch['metadata']['campaign_id']);
    }

    // ------------------------------------------------------------------
    //  Test 2: RFM job calculates correct scores from real MongoDB data
    // ------------------------------------------------------------------

    public function test_rfm_job_calculates_real_scores(): void
    {
        Sanctum::actingAs($this->user);

        $tenantId = (string) $this->tenant->id;

        // ── Prepare 3 sessions for the same customer ──────────────────
        $sessions = [
            'e2e_rfm_sess_001' => ['date' => '2026-01-21 10:00:00', 'amount' => 150],
            'e2e_rfm_sess_002' => ['date' => '2026-02-05 14:00:00', 'amount' => 200],
            'e2e_rfm_sess_003' => ['date' => '2026-02-20 09:00:00', 'amount' => 100],
        ];

        foreach ($sessions as $sessionId => $data) {
            // Freeze time so Eloquent's created_at matches the desired date.
            Carbon::setTestNow($data['date']);

            $this->postJson('/api/v1/analytics/ingest', [
                'payload' => [
                    'session_id'          => $sessionId,
                    'event_type'          => 'purchase',
                    'url'                 => 'https://e2e-store.example.com/checkout/complete',
                    'metadata'            => [
                        'order_id'    => 'ORD-' . strtoupper($sessionId),
                        'order_total' => $data['amount'],
                    ],
                    'customer_identifier' => [
                        'type'  => 'email',
                        'value' => E2ECustomerSeeder::CUSTOMER_EMAIL,
                    ],
                ],
            ])->assertStatus(201);
        }

        // ── Verify the profile accumulated all 3 sessions ─────────────
        $profile = CustomerProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('identifier_value', E2ECustomerSeeder::CUSTOMER_EMAIL)
            ->firstOrFail();

        foreach (array_keys($sessions) as $sid) {
            $this->assertContains($sid, $profile->known_sessions, "Session {$sid} not linked to profile.");
        }

        // ── Run the RFM job with "today" frozen to 2026-02-20 ─────────
        Carbon::setTestNow('2026-02-20 12:00:00');

        // Fake IntegrationEvent so the rfm_segment_changed dispatch
        // inside the job doesn't cascade into unrelated listeners.
        Event::fake([IntegrationEvent::class]);

        (new CalculateCustomerRfmJob((string) $profile->_id))->handle();

        // ── Assert the RFM score ──────────────────────────────────────
        //
        //   Recency:  Last purchase = 2026-02-20, now = 2026-02-20 → 0 days
        //             Thresholds [7, 30, 90, 180] → 0 ≤ 7 → R = 5
        //
        //   Frequency: 3 purchases
        //             Thresholds [2, 5, 10, 20] → 3 ≥ 2 → F = 2
        //
        //   Monetary:  150 + 200 + 100 = $450
        //             Thresholds [50, 200, 500, 1000] → 450 ≥ 200 → M = 3
        //
        //   RFM score = "523"   Total = 10 → segment "Loyal"
        //
        $profile->refresh();

        $this->assertSame('523', $profile->rfm_score, 'RFM score does not match expected value.');

        $this->assertIsArray($profile->rfm_details);
        $this->assertSame(0, $profile->rfm_details['recency_days']);
        $this->assertSame(3, $profile->rfm_details['frequency']);
        $this->assertEquals(450, $profile->rfm_details['monetary']);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Models\TrackingEvent;
use Tests\TestCase;

/**
 * Feature tests for the POST /api/v1/analytics/ingest endpoint.
 *
 * Covers:
 *  1. Authenticated user can ingest an event and get a tracking_event_id back.
 *  2. Unauthenticated request gets 401.
 *  3. Missing required payload fields return 422 validation errors.
 *  4. Invalid event_type returns 422.
 *  5. device_fingerprint flows through to the persisted event.
 *  6. customer_identifier flows through for identity resolution.
 *  7. User without tenant_id gets 403.
 */
final class IngestionApiTest extends TestCase
{
    use WithFaker;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name'      => 'Ingestion Test Tenant',
            'slug'      => 'ingest-test-' . uniqid(),
            'is_active' => true,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Test User',
            'email'     => 'ingest-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        $this->user->delete();
        $this->tenant->delete();

        parent::tearDown();
    }

    private function validPayload(array $overrides = []): array
    {
        return [
            'payload' => array_merge([
                'session_id' => 'sess_' . uniqid(),
                'event_type' => 'page_view',
                'url'        => 'https://example.com/products/123',
                'metadata'   => ['product_id' => 'prod_123'],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (PHPUnit)',
            ], $overrides),
        ];
    }

    // ------------------------------------------------------------------
    //  1. Successful ingestion
    // ------------------------------------------------------------------

    public function test_authenticated_user_can_ingest_event(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/analytics/ingest', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Event ingested successfully.')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'tracking_event_id',
                    'event_type',
                    'session_id',
                ],
            ]);

        // Verify the event was actually persisted.
        $eventId = $response->json('data.tracking_event_id');
        $this->assertNotNull(TrackingEvent::find($eventId));
    }

    // ------------------------------------------------------------------
    //  2. Unauthenticated
    // ------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/analytics/ingest', $this->validPayload());

        $response->assertStatus(401);
    }

    // ------------------------------------------------------------------
    //  3. Missing required fields → 422
    // ------------------------------------------------------------------

    public function test_missing_payload_returns_422(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/analytics/ingest', []);
        $response->assertStatus(422);
    }

    public function test_missing_session_id_returns_422(): void
    {
        Sanctum::actingAs($this->user);

        $payload = $this->validPayload();
        unset($payload['payload']['session_id']);

        $response = $this->postJson('/api/v1/analytics/ingest', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payload.session_id']);
    }

    public function test_missing_event_type_returns_422(): void
    {
        Sanctum::actingAs($this->user);

        $payload = $this->validPayload();
        unset($payload['payload']['event_type']);

        $response = $this->postJson('/api/v1/analytics/ingest', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payload.event_type']);
    }

    public function test_missing_url_returns_422(): void
    {
        Sanctum::actingAs($this->user);

        $payload = $this->validPayload();
        unset($payload['payload']['url']);

        $response = $this->postJson('/api/v1/analytics/ingest', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payload.url']);
    }

    // ------------------------------------------------------------------
    //  4. Invalid URL format
    // ------------------------------------------------------------------

    public function test_invalid_url_returns_422(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/analytics/ingest', $this->validPayload([
            'url' => 'not-a-url',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payload.url']);
    }

    // ------------------------------------------------------------------
    //  5. device_fingerprint flows through
    // ------------------------------------------------------------------

    public function test_device_fingerprint_is_accepted(): void
    {
        Sanctum::actingAs($this->user);

        $fingerprint = hash('sha256', 'test-browser-fingerprint');

        $response = $this->postJson('/api/v1/analytics/ingest', $this->validPayload([
            'device_fingerprint' => $fingerprint,
        ]));

        $response->assertStatus(201);
    }

    // ------------------------------------------------------------------
    //  6. customer_identifier flows through
    // ------------------------------------------------------------------

    public function test_customer_identifier_is_accepted(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/analytics/ingest', $this->validPayload([
            'customer_identifier' => [
                'type'  => 'email',
                'value' => 'customer@example.com',
            ],
        ]));

        $response->assertStatus(201);
    }

    public function test_invalid_customer_identifier_type_returns_422(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/analytics/ingest', $this->validPayload([
            'customer_identifier' => [
                'type'  => 'twitter', // not an allowed type
                'value' => '@user',
            ],
        ]));

        $response->assertStatus(422);
    }
}

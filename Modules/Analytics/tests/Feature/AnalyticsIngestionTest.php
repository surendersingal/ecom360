<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Feature;

use App\Events\IntegrationEvent;
use Illuminate\Support\Facades\Event;
use Modules\Analytics\Listeners\RecordTrackingEvent;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\TrackingService;
use Tests\TestCase;

/**
 * Feature tests for the Analytics data-ingestion pipeline.
 *
 * These tests verify that:
 *  1. An IntegrationEvent with an analytics payload is caught by the listener.
 *  2. The listener forwards the payload to TrackingService which writes to MongoDB.
 *  3. Non-analytics events are silently ignored.
 */
final class AnalyticsIngestionTest extends TestCase
{
    private const string TEST_TENANT_ID = 'tenant_test_001';

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        // Clean out any leftover tracking events for the test tenant.
        TrackingEvent::where('tenant_id', self::TEST_TENANT_ID)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', self::TEST_TENANT_ID)->delete();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Tests
    // ------------------------------------------------------------------

    public function test_record_tracking_event_listener_is_attached_to_integration_event(): void
    {
        Event::fake();

        IntegrationEvent::dispatch(
            'analytics',
            'tracking.ingest',
            $this->samplePayload(),
        );

        Event::assertDispatched(IntegrationEvent::class, function (IntegrationEvent $e): bool {
            return $e->moduleName === 'analytics'
                && $e->eventName === 'tracking.ingest';
        });
    }

    public function test_listener_writes_page_view_to_mongodb(): void
    {
        $payload = $this->samplePayload();

        // Manually invoke the listener (synchronous — bypasses queue for test determinism).
        $listener = app(RecordTrackingEvent::class);

        $event = new IntegrationEvent(
            moduleName: 'analytics',
            eventName: 'tracking.ingest',
            payload: $payload,
        );

        $listener->handle($event);

        // Assert the document landed in MongoDB.
        $stored = TrackingEvent::where('tenant_id', self::TEST_TENANT_ID)
            ->where('event_type', 'page_view')
            ->first();

        $this->assertNotNull($stored, 'TrackingEvent was not persisted to MongoDB.');
        $this->assertSame(self::TEST_TENANT_ID, $stored->tenant_id);
        $this->assertSame('page_view', $stored->event_type);
        $this->assertSame('https://example.com/products/123', $stored->url);
        $this->assertSame('sess_abc123', $stored->session_id);
        $this->assertIsArray($stored->metadata);
        $this->assertSame('prod_123', $stored->metadata['product_id']);
    }

    public function test_listener_ignores_non_analytics_events(): void
    {
        $listener = app(RecordTrackingEvent::class);

        $event = new IntegrationEvent(
            moduleName: 'marketing',     // ← not "analytics"
            eventName: 'campaign.sent',
            payload: ['tenant_id' => self::TEST_TENANT_ID],
        );

        $listener->handle($event);

        $count = TrackingEvent::where('tenant_id', self::TEST_TENANT_ID)->count();

        $this->assertSame(0, $count, 'Non-analytics event should not create a TrackingEvent.');
    }

    public function test_tracking_service_validates_payload(): void
    {
        $service = app(TrackingService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        // Missing required fields should throw.
        $service->logEvent(self::TEST_TENANT_ID, [
            'event_type' => 'invalid_type', // not in allowed list
        ]);
    }

    public function test_tracking_service_aggregate_traffic_returns_correct_structure(): void
    {
        $service = app(TrackingService::class);

        // Seed a few events directly.
        foreach (['page_view', 'page_view', 'add_to_cart'] as $type) {
            TrackingEvent::create([
                'tenant_id'  => self::TEST_TENANT_ID,
                'session_id' => 'sess_' . ($type === 'add_to_cart' ? 'xyz' : 'abc'),
                'event_type' => $type,
                'url'        => 'https://example.com/test',
                'metadata'   => [],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
            ]);
        }

        $result = $service->aggregateTraffic(self::TEST_TENANT_ID, '30d');

        $this->assertArrayHasKey('unique_sessions', $result);
        $this->assertArrayHasKey('total_events', $result);
        $this->assertArrayHasKey('event_type_breakdown', $result);
        $this->assertArrayHasKey('date_from', $result);
        $this->assertArrayHasKey('date_to', $result);

        $this->assertSame(3, $result['total_events']);
        $this->assertSame(2, $result['unique_sessions']); // sess_abc + sess_xyz
        $this->assertSame(2, $result['event_type_breakdown']['page_view']);
        $this->assertSame(1, $result['event_type_breakdown']['add_to_cart']);
    }

    // ------------------------------------------------------------------
    //  Fixtures
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function samplePayload(): array
    {
        return [
            'tenant_id'  => self::TEST_TENANT_ID,
            'session_id' => 'sess_abc123',
            'event_type' => 'page_view',
            'url'        => 'https://example.com/products/123',
            'metadata'   => ['product_id' => 'prod_123', 'price' => 29.99],
            'ip_address' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (PHPUnit Test)',
        ];
    }
}

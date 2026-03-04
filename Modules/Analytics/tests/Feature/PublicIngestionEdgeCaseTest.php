<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Str;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Tests\TestCase;

/**
 * Edge-case and stress tests for the analytics ingestion pipeline.
 *
 * Covers:
 *  - Oversized payloads
 *  - Deeply nested metadata
 *  - Concurrent session writes
 *  - Unicode / special character handling
 *  - Empty batch
 *  - Rapid duplicate events
 *  - Very long URL strings
 *  - XSS-like payloads (should be accepted but never rendered)
 *  - Numeric session IDs
 *  - Minimalist payloads
 */
final class PublicIngestionEdgeCaseTest extends TestCase
{
    private Tenant $tenant;
    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = 'ek_' . Str::random(48);
        $this->tenant = Tenant::create([
            'name'      => 'Edge Case Store',
            'slug'      => 'edge-' . Str::random(6),
            'api_key'   => $this->apiKey,
            'is_active' => true,
        ]);

        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        $this->tenant->delete();

        parent::tearDown();
    }

    private function headers(): array
    {
        return [
            'X-Ecom360-Key' => $this->apiKey,
            'Accept'        => 'application/json',
        ];
    }

    private function minimalPayload(array $overrides = []): array
    {
        return array_merge([
            'session_id' => 's_' . Str::random(12),
            'event_type' => 'page_view',
            'url'        => 'https://example.com',
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Minimalist payload (only required fields)
    // ─────────────────────────────────────────────────────────────────

    public function test_minimal_required_fields_only(): void
    {
        $response = $this->postJson('/api/v1/collect', $this->minimalPayload(), $this->headers());
        $response->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Unicode product names and metadata
    // ─────────────────────────────────────────────────────────────────

    public function test_unicode_metadata_is_preserved(): void
    {
        $payload = $this->minimalPayload([
            'event_type' => 'product_view',
            'metadata'   => [
                'product_name' => 'MacBook Pro — Pro Display™ «Special» 日本語テスト',
                'description'  => 'Ñoño café résumé über naïve 中文测试 Ελληνικά',
                'emoji'        => '🚀💻🎉',
            ],
        ]);

        $this->postJson('/api/v1/collect', $payload, $this->headers())->assertStatus(201);

        $event = TrackingEvent::where('tenant_id', (string) $this->tenant->id)->first();
        $this->assertStringContainsString('日本語テスト', $event->metadata['product_name']);
        $this->assertStringContainsString('🚀💻🎉', $event->metadata['emoji']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Deeply nested metadata (5 levels)
    // ─────────────────────────────────────────────────────────────────

    public function test_deeply_nested_metadata_is_accepted(): void
    {
        $payload = $this->minimalPayload([
            'metadata' => [
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => 'deep_value',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->postJson('/api/v1/collect', $payload, $this->headers())->assertStatus(201);

        $event = TrackingEvent::where('tenant_id', (string) $this->tenant->id)->first();
        $this->assertSame(
            'deep_value',
            $event->metadata['level1']['level2']['level3']['level4']['level5']
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: XSS-like payload in metadata (stored, never rendered)
    // ─────────────────────────────────────────────────────────────────

    public function test_xss_payload_is_stored_without_execution(): void
    {
        $xss = '<script>alert("XSS")</script><img src=x onerror=alert(1)>';

        $payload = $this->minimalPayload([
            'metadata' => ['page_title' => $xss, 'category' => $xss],
        ]);

        $this->postJson('/api/v1/collect', $payload, $this->headers())->assertStatus(201);

        $event = TrackingEvent::where('tenant_id', (string) $this->tenant->id)->first();
        // Data is stored raw (analytics data); sanitation happens at render time.
        $this->assertSame($xss, $event->metadata['page_title']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Very long URL (2048 chars — common limit)
    // ─────────────────────────────────────────────────────────────────

    public function test_long_url_is_accepted(): void
    {
        $longPath = str_repeat('a', 2000);
        $url = 'https://example.com/' . $longPath;

        $payload = $this->minimalPayload(['url' => $url]);

        $this->postJson('/api/v1/collect', $payload, $this->headers())->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Empty batch
    // ─────────────────────────────────────────────────────────────────

    public function test_empty_events_array_returns_422(): void
    {
        $response = $this->postJson('/api/v1/collect/batch', ['events' => []], $this->headers());
        $response->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Missing events key in batch
    // ─────────────────────────────────────────────────────────────────

    public function test_missing_events_key_in_batch_returns_422(): void
    {
        $response = $this->postJson('/api/v1/collect/batch', [], $this->headers());
        $response->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Rapid successive events from same session (burst)
    // ─────────────────────────────────────────────────────────────────

    public function test_rapid_burst_of_events_are_all_stored(): void
    {
        $sessionId = 's_burst_' . Str::random(8);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/collect', $this->minimalPayload([
                'session_id' => $sessionId,
                'url'        => "https://example.com/page/{$i}",
            ]), $this->headers())->assertStatus(201);
        }

        $count = TrackingEvent::where('tenant_id', (string) $this->tenant->id)
            ->where('session_id', $sessionId)
            ->count();

        $this->assertSame(10, $count);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Numeric session ID (SDK might produce)
    // ─────────────────────────────────────────────────────────────────

    public function test_numeric_session_id_is_accepted(): void
    {
        $payload = $this->minimalPayload(['session_id' => '9876543210']);

        $this->postJson('/api/v1/collect', $payload, $this->headers())->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Empty string session ID (should fail)
    // ─────────────────────────────────────────────────────────────────

    public function test_empty_string_session_id_returns_422(): void
    {
        $payload = $this->minimalPayload(['session_id' => '']);

        $this->postJson('/api/v1/collect', $payload, $this->headers())->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Event type with underscores / valid snake_case
    // ─────────────────────────────────────────────────────────────────

    public function test_complex_valid_snake_case_event_type(): void
    {
        $payload = $this->minimalPayload(['event_type' => 'custom_section_a_viewed']);

        $this->postJson('/api/v1/collect', $payload, $this->headers())->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Large custom_data object
    // ─────────────────────────────────────────────────────────────────

    public function test_large_custom_data_is_accepted(): void
    {
        $customData = [];
        for ($i = 0; $i < 50; $i++) {
            $customData["field_{$i}"] = Str::random(100);
        }

        $payload = $this->minimalPayload(['custom_data' => $customData]);

        $this->postJson('/api/v1/collect', $payload, $this->headers())->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Request without Accept header still works
    // ─────────────────────────────────────────────────────────────────

    public function test_request_without_accept_header(): void
    {
        $response = $this->postJson('/api/v1/collect', $this->minimalPayload(), [
            'X-Ecom360-Key' => $this->apiKey,
        ]);

        $response->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Batch with exactly 50 events (boundary)
    // ─────────────────────────────────────────────────────────────────

    public function test_batch_with_exactly_50_events_is_accepted(): void
    {
        $events = [];
        for ($i = 0; $i < 50; $i++) {
            $events[] = $this->minimalPayload(['url' => "https://example.com/page/{$i}"]);
        }

        $response = $this->postJson('/api/v1/collect/batch', ['events' => $events], $this->headers());
        $response->assertStatus(201);
        $response->assertJsonPath('data.ingested', 50);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: sendBeacon fallback — API key via query param
    // ─────────────────────────────────────────────────────────────────

    public function test_api_key_via_query_param_is_accepted(): void
    {
        $response = $this->postJson(
            '/api/v1/collect?api_key=' . $this->apiKey,
            $this->minimalPayload(),
            ['Accept' => 'application/json']  // no X-Ecom360-Key header
        );

        $response->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Both header and query param — header takes precedence
    // ─────────────────────────────────────────────────────────────────

    public function test_header_api_key_takes_precedence_over_query_param(): void
    {
        $wrongKey = 'ek_' . Str::random(48);

        // Header has correct key, query has wrong key → should succeed.
        $response = $this->postJson(
            '/api/v1/collect?api_key=' . $wrongKey,
            $this->minimalPayload(),
            $this->headers()
        );

        $response->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Multiple event types for same product in one session
    // ─────────────────────────────────────────────────────────────────

    public function test_multiple_interactions_with_same_product(): void
    {
        $sessionId = 's_product_' . Str::random(8);
        $productMeta = ['product_id' => 'prod_dup', 'product_name' => 'Test Product'];

        $types = ['product_view', 'add_to_cart', 'remove_from_cart', 'product_view', 'add_to_cart', 'begin_checkout'];

        foreach ($types as $type) {
            $this->postJson('/api/v1/collect', $this->minimalPayload([
                'session_id' => $sessionId,
                'event_type' => $type,
                'metadata'   => $productMeta,
            ]), $this->headers())->assertStatus(201);
        }

        $events = TrackingEvent::where('tenant_id', (string) $this->tenant->id)
            ->where('session_id', $sessionId)
            ->get();

        $this->assertCount(6, $events);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Edge: Boolean and null values in metadata
    // ─────────────────────────────────────────────────────────────────

    public function test_boolean_and_null_values_in_metadata(): void
    {
        $payload = $this->minimalPayload([
            'metadata' => [
                'is_logged_in'   => false,
                'has_discount'   => true,
                'coupon_applied' => null,
                'items_count'    => 0,
            ],
        ]);

        $this->postJson('/api/v1/collect', $payload, $this->headers())->assertStatus(201);

        $event = TrackingEvent::where('tenant_id', (string) $this->tenant->id)->first();
        $this->assertFalse($event->metadata['is_logged_in']);
        $this->assertTrue($event->metadata['has_discount']);
        $this->assertNull($event->metadata['coupon_applied']);
        $this->assertSame(0, $event->metadata['items_count']);
    }
}

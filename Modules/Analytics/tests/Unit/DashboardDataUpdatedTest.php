<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Unit;

use Illuminate\Broadcasting\Channel;
use Modules\Analytics\Events\DashboardDataUpdated;
use Tests\TestCase;

/**
 * Verify the DashboardDataUpdated broadcast event structure.
 */
final class DashboardDataUpdatedTest extends TestCase
{
    public function test_broadcasts_on_tenant_and_admin_channels(): void
    {
        $event = new DashboardDataUpdated(
            tenantId:  'tenant-123',
            eventType: 'page_view',
            sessionId: 'sess-abc',
            timestamp: '2025-01-01T00:00:00+00:00',
        );

        $channels = $event->broadcastOn();
        $this->assertCount(2, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertInstanceOf(Channel::class, $channels[1]);
        $this->assertEquals('dashboard.tenant-123', $channels[0]->name);
        $this->assertEquals('admin.dashboard', $channels[1]->name);
    }

    public function test_broadcast_as_returns_custom_name(): void
    {
        $event = new DashboardDataUpdated(
            tenantId:  'tenant-123',
            eventType: 'page_view',
            sessionId: 'sess-abc',
            timestamp: '2025-01-01T00:00:00+00:00',
        );

        $this->assertEquals('analytics.updated', $event->broadcastAs());
    }

    public function test_broadcast_with_returns_minimal_payload(): void
    {
        $event = new DashboardDataUpdated(
            tenantId:  'tenant-123',
            eventType: 'purchase',
            sessionId: 'sess-xyz',
            timestamp: '2025-02-15T12:30:00+00:00',
        );

        $payload = $event->broadcastWith();

        $this->assertEquals([
            'tenant_id'  => 'tenant-123',
            'event_type' => 'purchase',
            'session_id' => 'sess-xyz',
            'timestamp'  => '2025-02-15T12:30:00+00:00',
        ], $payload);
    }
}

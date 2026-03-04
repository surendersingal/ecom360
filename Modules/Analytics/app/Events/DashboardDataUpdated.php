<?php

declare(strict_types=1);

namespace Modules\Analytics\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired whenever new tracking data is ingested.
 *
 * This pushes a lightweight notification over WebSockets so that any
 * connected Filament dashboard automatically refreshes its widgets
 * (TrafficStats, ConversionFunnel, LiveVisitors, PlatformOverview)
 * without the user having to manually refresh the page.
 *
 * The event is broadcast on a public channel per tenant:
 *   `dashboard.{tenantId}`
 *
 * For the admin panel, a global channel is also used:
 *   `admin.dashboard`
 */
final class DashboardDataUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $eventType,
        public readonly string $sessionId,
        public readonly string $timestamp,
    ) {}

    /**
     * Broadcast on both the tenant-specific and admin-global channels.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("dashboard.{$this->tenantId}"),
            new Channel('admin.dashboard'),
        ];
    }

    /**
     * Custom broadcast event name for cleaner JS-side listening.
     */
    public function broadcastAs(): string
    {
        return 'analytics.updated';
    }

    /**
     * Data payload sent over the wire — kept minimal to reduce bandwidth.
     *
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'tenant_id'  => $this->tenantId,
            'event_type' => $this->eventType,
            'session_id' => $this->sessionId,
            'timestamp'  => $this->timestamp,
        ];
    }
}

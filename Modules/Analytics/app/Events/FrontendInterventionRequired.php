<?php

declare(strict_types=1);

namespace Modules\Analytics\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when the Dynamic Rules Engine determines that
 * a real-time intervention should be displayed on the frontend.
 *
 * This event is broadcast IMMEDIATELY (ShouldBroadcastNow) on a private
 * channel scoped to the session ID, so the frontend JS SDK receives it
 * via WebSockets within milliseconds.
 *
 * The frontend is expected to render the intervention based on:
 *  - action_type: 'popup' | 'discount' | 'notification' | 'redirect'
 *  - action_payload: Arbitrary JSON (title, body, discount_code, redirect_url, etc.)
 *
 * Example payload on the wire:
 * {
 *   "session_id": "abc-123",
 *   "rule_id": 42,
 *   "rule_name": "Cart Abandonment Saver",
 *   "action_type": "discount",
 *   "action_payload": { "title": "Wait!", "discount_code": "SAVE10", "discount_percent": 10 },
 *   "intent": { "score": 15, "level": "abandon_risk" },
 *   "fired_at": "2026-02-20T14:30:00+00:00"
 * }
 */
final class FrontendInterventionRequired implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly int    $ruleId,
        public readonly string $ruleName,
        public readonly string $actionType,
        public readonly array  $actionPayload,
        public readonly array  $intent,
        public readonly string $firedAt,
    ) {}

    /**
     * The private channel this event broadcasts on.
     *
     * Frontend subscribes to: `private-session.{sessionId}`
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('session.' . $this->sessionId),
        ];
    }

    /**
     * The event name the frontend listens for.
     */
    public function broadcastAs(): string
    {
        return 'intervention.required';
    }

    /**
     * Data payload sent over the wire.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id'     => $this->sessionId,
            'rule_id'        => $this->ruleId,
            'rule_name'      => $this->ruleName,
            'action_type'    => $this->actionType,
            'action_payload' => $this->actionPayload,
            'intent'         => $this->intent,
            'fired_at'       => $this->firedAt,
        ];
    }
}

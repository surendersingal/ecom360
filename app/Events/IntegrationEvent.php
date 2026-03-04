<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A cross-module integration event dispatched through the Redis Event Bus.
 *
 * Any module can fire this event. The EventBusRouter listener will
 * inspect the payload and forward it to the correct destination module.
 */
final class IntegrationEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  string              $moduleName  The originating module (e.g. "Analytics").
     * @param  string              $eventName   A dot-notation event key (e.g. "report.generated").
     * @param  array<string,mixed> $payload     Arbitrary data carried by the event.
     */
    public function __construct(
        public readonly string $moduleName,
        public readonly string $eventName,
        public readonly array  $payload = [],
    ) {}
}

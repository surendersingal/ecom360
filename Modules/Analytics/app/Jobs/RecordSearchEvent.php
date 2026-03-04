<?php

declare(strict_types=1);

namespace Modules\Analytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Records a search event originating from the AiSearch module.
 *
 * Placeholder — replace the handle() body with real metric-recording logic.
 */
final class RecordSearchEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        Log::info('[Analytics] Recording search event from AiSearch.', $this->payload);

        // TODO: Persist search metrics to the analytics data store.
    }
}

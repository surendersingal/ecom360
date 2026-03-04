<?php

declare(strict_types=1);

namespace Modules\Marketing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Models\FlowEnrollment;
use Modules\Marketing\Services\FlowExecutionService;

/**
 * Processes a single flow step for an enrollment.
 * Dispatched when an enrollment advances to a new node.
 */
final class ProcessFlowStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly int $enrollmentId,
    ) {
        $this->queue = 'marketing-flows';
    }

    public function handle(FlowExecutionService $flowService): void
    {
        $enrollment = FlowEnrollment::find($this->enrollmentId);
        if (!$enrollment) {
            Log::warning("[ProcessFlowStep] Enrollment #{$this->enrollmentId} not found.");
            return;
        }

        $flowService->processStep($enrollment);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Marketing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Marketing\Models\Campaign;
use Modules\Marketing\Services\CampaignService;

/**
 * Queued job to send a campaign. Allows large campaigns to be
 * processed asynchronously without blocking the HTTP request.
 */
final class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600; // 1 hour max

    public function __construct(
        private readonly int $campaignId,
    ) {
        $this->queue = 'marketing';
    }

    public function handle(CampaignService $campaignService): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (!$campaign) {
            Log::error("[SendCampaignJob] Campaign #{$this->campaignId} not found.");
            return;
        }

        if ($campaign->status !== 'scheduled' && $campaign->status !== 'draft') {
            Log::warning("[SendCampaignJob] Campaign #{$this->campaignId} has status '{$campaign->status}', skipping.");
            return;
        }

        try {
            if ($campaign->type === 'ab_test') {
                $campaignService->sendAbTest($campaign);
            } else {
                $campaignService->send($campaign);
            }
        } catch (\Throwable $e) {
            Log::error("[SendCampaignJob] Failed: {$e->getMessage()}", [
                'campaign_id' => $this->campaignId,
            ]);
            $campaign->update(['status' => 'failed']);
        }
    }
}

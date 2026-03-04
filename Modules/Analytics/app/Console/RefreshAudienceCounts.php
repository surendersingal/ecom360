<?php

declare(strict_types=1);

namespace Modules\Analytics\Console;

use App\Events\IntegrationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\AudienceSegment;
use Modules\Analytics\Services\AudienceBuilderService;

/**
 * Scheduled command that refreshes audience segment member counts.
 *
 * Runs hourly (registered in AnalyticsServiceProvider). For every active
 * AudienceSegment it:
 *   1. Executes the dynamic MongoDB query via AudienceBuilderService.
 *   2. Updates the MySQL `member_count` column.
 *   3. Detects customers who entered or left the segment and dispatches
 *      IntegrationEvents so other modules (e.g. Marketing) can react.
 */
final class RefreshAudienceCounts extends Command
{
    /** @var string */
    protected $signature = 'analytics:refresh-audience-counts';

    /** @var string */
    protected $description = 'Recalculate member counts for all active audience segments.';

    public function __construct(
        private readonly AudienceBuilderService $audienceBuilder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $segments = AudienceSegment::query()
            ->where('is_active', true)
            ->get();

        if ($segments->isEmpty()) {
            $this->info('No active audience segments found — nothing to refresh.');
            return self::SUCCESS;
        }

        $this->info("Refreshing {$segments->count()} audience segment(s)…");

        foreach ($segments as $segment) {
            /** @var AudienceSegment $segment */
            $this->refreshSegment($segment);
        }

        $this->info('All segments refreshed.');

        return self::SUCCESS;
    }

    private function refreshSegment(AudienceSegment $segment): void
    {
        // --- Previous member set (stored as JSON on the segment) ---------
        $previousIds = $this->getPreviousMemberIds($segment);

        // --- Current member set (live MongoDB query) ---------------------
        $currentIds = $this->audienceBuilder->getMatchingCustomerIds($segment);

        // --- Update MySQL ------------------------------------------------
        $segment->update([
            'member_count' => count($currentIds),
        ]);

        // --- Diff: detect entries & exits --------------------------------
        $entered = array_diff($currentIds, $previousIds);
        $exited  = array_diff($previousIds, $currentIds);

        // --- Dispatch events for each change -----------------------------
        foreach ($entered as $customerId) {
            IntegrationEvent::dispatch(
                'Analytics',
                'audience_segment_entered',
                [
                    'customer_id' => $customerId,
                    'segment_id'  => $segment->id,
                    'segment_name' => $segment->name,
                    'tenant_id'   => (string) $segment->tenant_id,
                ],
            );
        }

        foreach ($exited as $customerId) {
            IntegrationEvent::dispatch(
                'Analytics',
                'audience_segment_exited',
                [
                    'customer_id' => $customerId,
                    'segment_id'  => $segment->id,
                    'segment_name' => $segment->name,
                    'tenant_id'   => (string) $segment->tenant_id,
                ],
            );
        }

        // --- Cache the current member set for the next diff cycle --------
        $this->storeMemberIds($segment, $currentIds);

        $enteredCount = count($entered);
        $exitedCount  = count($exited);

        $this->line(
            "  [{$segment->name}] members: {$segment->member_count}"
            . ($enteredCount > 0 ? " (+{$enteredCount} entered)" : '')
            . ($exitedCount > 0 ? " (-{$exitedCount} exited)" : ''),
        );

        Log::info("[AudienceRefresh] Segment [{$segment->name}] → {$segment->member_count} members, {$enteredCount} entered, {$exitedCount} exited.");
    }

    // ------------------------------------------------------------------
    //  Member-set persistence (Redis cache, keyed by segment ID)
    // ------------------------------------------------------------------

    /**
     * Retrieve the previous member ID set from cache.
     *
     * @return list<string>
     */
    private function getPreviousMemberIds(AudienceSegment $segment): array
    {
        $cached = cache()->get($this->cacheKey($segment));

        return is_array($cached) ? $cached : [];
    }

    /**
     * Store the current member ID set in cache for the next cycle.
     *
     * @param list<string> $ids
     */
    private function storeMemberIds(AudienceSegment $segment, array $ids): void
    {
        // TTL of 2 hours — gives plenty of headroom for the hourly schedule.
        cache()->put($this->cacheKey($segment), $ids, now()->addHours(2));
    }

    private function cacheKey(AudienceSegment $segment): string
    {
        return "audience_segment:{$segment->id}:member_ids";
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Jobs;

use App\Events\IntegrationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MongoDB\Laravel\Connection;
use Modules\Analytics\Models\CustomerProfile;

/**
 * Queued job that recalculates the RFM (Recency, Frequency, Monetary)
 * score for a single CustomerProfile.
 *
 * How it works:
 *   1. Load the CustomerProfile by its MongoDB _id.
 *   2. Query tracking_events for all 'purchase' events across the customer's
 *      known sessions.
 *   3. Calculate R (days since last purchase), F (total orders), M (total spend).
 *   4. Assign a 1-5 score for each metric using standard percentile thresholds.
 *   5. Persist the composite rfm_score (e.g. '555' = VIP) back to the profile.
 *   6. Dispatch an IntegrationEvent so other modules (e.g. Marketing) can react.
 */
final class CalculateCustomerRfmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param string $customerProfileId  The MongoDB ObjectId of the CustomerProfile document.
     */
    public function __construct(
        public readonly string $customerProfileId,
    ) {
        $this->onConnection('redis');
        $this->onQueue('analytics');
    }

    // ------------------------------------------------------------------
    //  RFM percentile thresholds (days / count / dollars)
    // ------------------------------------------------------------------

    /** Recency: fewer days = better score → thresholds in ascending order. */
    private const array RECENCY_THRESHOLDS = [7, 30, 90, 180]; // 5,4,3,2,1

    /** Frequency: more orders = better score → thresholds in ascending order. */
    private const array FREQUENCY_THRESHOLDS = [2, 5, 10, 20]; // 1,2,3,4,5

    /** Monetary: higher spend = better score → thresholds in ascending order. */
    private const array MONETARY_THRESHOLDS = [50, 200, 500, 1000]; // 1,2,3,4,5

    public function handle(): void
    {
        $profile = CustomerProfile::find($this->customerProfileId);

        if ($profile === null) {
            Log::warning("[RFM] CustomerProfile [{$this->customerProfileId}] not found — skipping.");
            return;
        }

        $knownSessions = $profile->known_sessions ?? [];

        if ($knownSessions === []) {
            Log::debug("[RFM] Profile [{$this->customerProfileId}] has no sessions — skipping.");
            return;
        }

        // ------------------------------------------------------------------
        //  Query MongoDB: aggregate all purchase events across sessions
        // ------------------------------------------------------------------

        /** @var Connection $mongo */
        $mongo = app('db')->connection('mongodb');
        $collection = $mongo->getCollection('tracking_events');

        $pipeline = [
            [
                '$match' => [
                    'tenant_id'  => $profile->tenant_id,
                    'session_id' => ['$in' => $knownSessions],
                    'event_type' => 'purchase',
                ],
            ],
            [
                '$group' => [
                    '_id'           => null,
                    'last_purchase' => ['$max' => '$created_at'],
                    'frequency'     => ['$sum' => 1],
                    'monetary'      => ['$sum' => '$metadata.order_total'],
                ],
            ],
        ];

        $results = iterator_to_array($collection->aggregate($pipeline, ['maxTimeMS' => 30000]));
        $data    = $results[0] ?? null;

        if ($data === null) {
            // No purchases yet — assign lowest possible score.
            $this->saveScore($profile, 999, 0, 0.0, '111');
            return;
        }

        // ------------------------------------------------------------------
        //  Calculate raw values
        // ------------------------------------------------------------------
        $lastPurchaseDate = $data['last_purchase'] instanceof \MongoDB\BSON\UTCDateTime
            ? CarbonImmutable::createFromTimestampMs((int) (string) $data['last_purchase'])
            : CarbonImmutable::parse((string) $data['last_purchase']);

        $recencyDays = (int) CarbonImmutable::now()->diffInDays($lastPurchaseDate);
        $frequency   = (int) $data['frequency'];
        $monetary    = (float) ($data['monetary'] ?? 0);

        // ------------------------------------------------------------------
        //  Score each dimension (1-5)
        // ------------------------------------------------------------------
        $rScore = $this->scoreRecency($recencyDays);
        $fScore = $this->scoreAscending($frequency, self::FREQUENCY_THRESHOLDS);
        $mScore = $this->scoreAscending($monetary, self::MONETARY_THRESHOLDS);

        $rfmScore = "{$rScore}{$fScore}{$mScore}";

        $previousScore = $profile->rfm_score;

        $this->saveScore($profile, $recencyDays, $frequency, $monetary, $rfmScore);

        // ------------------------------------------------------------------
        //  Dispatch IntegrationEvent → Marketing / other modules
        // ------------------------------------------------------------------
        $segment = $this->resolveSegmentLabel($rfmScore);

        IntegrationEvent::dispatch(
            'Analytics',
            'rfm_segment_changed',
            [
                'customer_id'    => $this->customerProfileId,
                'tenant_id'      => $profile->tenant_id,
                'previous_score' => $previousScore,
                'new_score'      => $rfmScore,
                'new_segment'    => $segment,
            ],
        );

        Log::info("[RFM] Profile [{$this->customerProfileId}] scored [{$rfmScore}] → segment [{$segment}].");
    }

    // ------------------------------------------------------------------
    //  Scoring helpers
    // ------------------------------------------------------------------

    /**
     * Recency scoring: fewer days since last purchase = higher score.
     * ≤7d → 5, ≤30d → 4, ≤90d → 3, ≤180d → 2, >180d → 1
     */
    private function scoreRecency(int $days): int
    {
        foreach (self::RECENCY_THRESHOLDS as $index => $threshold) {
            if ($days <= $threshold) {
                return 5 - $index; // 5, 4, 3, 2
            }
        }

        return 1;
    }

    /**
     * Ascending scoring: higher value = higher score.
     *
     * @param  int|float   $value
     * @param  list<int|float> $thresholds  Four ascending thresholds.
     */
    private function scoreAscending(int|float $value, array $thresholds): int
    {
        for ($i = count($thresholds) - 1; $i >= 0; $i--) {
            if ($value >= $thresholds[$i]) {
                return $i + 2; // 2,3,4,5
            }
        }

        return 1;
    }

    /**
     * Persist the RFM score and breakdown details to the profile.
     */
    private function saveScore(
        CustomerProfile $profile,
        int $recencyDays,
        int $frequency,
        float $monetary,
        string $rfmScore,
    ): void {
        $profile->update([
            'rfm_score'   => $rfmScore,
            'rfm_details' => [
                'recency_days' => $recencyDays,
                'frequency'    => $frequency,
                'monetary'     => $monetary,
                'scored_at'    => CarbonImmutable::now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Map an RFM composite score to a human-readable segment label.
     */
    private function resolveSegmentLabel(string $rfmScore): string
    {
        $total = array_sum(array_map('intval', str_split($rfmScore)));

        return match (true) {
            $total >= 13 => 'VIP',
            $total >= 10 => 'Loyal',
            $total >= 7  => 'At Risk',
            $total >= 4  => 'Hibernating',
            default      => 'Churned',
        };
    }
}

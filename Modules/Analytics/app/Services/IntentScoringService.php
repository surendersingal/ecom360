<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Real-Time Intent Scoring Engine.
 *
 * Maintains a continuously updated numeric score in Redis for every
 * active session.  Each incoming event type contributes positively or
 * negatively to the score.  The score decays naturally because the Redis
 * key expires after 30 minutes of inactivity (same window as the Live
 * Context cache).
 *
 * The score is used by:
 *  - EvaluateBehavioralRules (to fire real-time interventions)
 *  - BI dashboards (to show intent distribution)
 *  - Marketing (to trigger campaigns for high-intent users)
 *
 * Score thresholds:
 *  >=  60  → high_intent     (add_to_cart, begin_checkout, purchase)
 *  >=  30  → warm            (repeat product views, search + click)
 *  >=   0  → browsing        (page views, light engagement)
 *  <    0  → abandon_risk    (homepage bounce, cart removal, inactivity)
 */
final class IntentScoringService
{
    /**
     * Redis key prefix for intent scores.
     */
    private const string PREFIX = 'intent:score:';

    /**
     * TTL in seconds — 30 minutes (matches Live Context window).
     */
    private const int TTL = 1800;

    /**
     * Event-to-score mapping.
     *
     * Positive values signal purchase intent; negative values signal
     * disengagement. Fine-tune these weights based on conversion data.
     *
     * @var array<string, int>
     */
    private const array SCORE_MAP = [
        'page_view'       =>  2,
        'product_view'    =>  5,
        'search'          =>  4,
        'click'           =>  3,
        'add_to_cart'     => 20,
        'cart_update'     =>  5,
        'remove_from_cart'=> -10,
        'begin_checkout'  => 30,
        'checkout'        => 30,
        'purchase'        => 50,
        'search_event'    =>  4,
        'chat_event'      =>  8,
        'campaign_event'  =>  3,
    ];

    // ------------------------------------------------------------------
    //  Writers
    // ------------------------------------------------------------------

    /**
     * Increment (or decrement) the intent score for a session based
     * on the event type just received.
     *
     * @return int  The updated score after the increment.
     */
    public function recordEvent(string $sessionId, string $eventType): int
    {
        $delta = self::SCORE_MAP[$eventType] ?? 1;
        $key   = self::PREFIX . $sessionId;

        /** @var int $newScore */
        $newScore = (int) Redis::incrby($key, $delta);

        // Reset the sliding TTL on every event.
        Redis::expire($key, self::TTL);

        return $newScore;
    }

    /**
     * Apply a manual adjustment to the score (e.g. from BI rules or A/B tests).
     */
    public function adjustScore(string $sessionId, int $delta): int
    {
        $key = self::PREFIX . $sessionId;

        $newScore = (int) Redis::incrby($key, $delta);
        Redis::expire($key, self::TTL);

        return $newScore;
    }

    // ------------------------------------------------------------------
    //  Readers
    // ------------------------------------------------------------------

    /**
     * Return the raw numeric intent score.
     */
    public function getScore(string $sessionId): int
    {
        return (int) Redis::get(self::PREFIX . $sessionId);
    }

    /**
     * Evaluate the intent level label for the given session.
     *
     * @return array{
     *     score: int,
     *     level: string,
     *     label: string,
     * }
     */
    public function evaluateIntent(string $sessionId): array
    {
        $score = $this->getScore($sessionId);

        [$level, $label] = match (true) {
            $score >= 60 => ['high_intent',   'High Intent — likely to convert'],
            $score >= 30 => ['warm',          'Warm — engaged but not committed'],
            $score >= 0  => ['browsing',      'Browsing — casual exploration'],
            default      => ['abandon_risk',  'Abandon Risk — showing exit signals'],
        };

        return [
            'score' => $score,
            'level' => $level,
            'label' => $label,
        ];
    }

    /**
     * Bulk-read intent scores for multiple sessions (e.g. for BI dashboards).
     *
     * @param  list<string> $sessionIds
     * @return array<string, int>  Keyed by session ID.
     */
    public function getScores(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $keys   = array_map(fn (string $sid) => self::PREFIX . $sid, $sessionIds);
        $values = Redis::mget($keys);

        $result = [];
        foreach ($sessionIds as $i => $sid) {
            $result[$sid] = (int) ($values[$i] ?? 0);
        }

        return $result;
    }

    /**
     * Remove intent score data for a session (e.g. after conversion).
     */
    public function flush(string $sessionId): void
    {
        Redis::del(self::PREFIX . $sessionId);
    }
}

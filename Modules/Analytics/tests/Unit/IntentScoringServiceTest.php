<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Unit;

use Illuminate\Support\Facades\Redis;
use Modules\Analytics\Services\IntentScoringService;
use Tests\TestCase;

/**
 * Unit tests for IntentScoringService.
 *
 * Covers:
 *  1. Recording events increments the score by the correct weight.
 *  2. Intent level thresholds (high_intent, warm, browsing, abandon_risk).
 *  3. Negative events reduce the score.
 *  4. Bulk score reads via getScores().
 *  5. Score flush removes the key.
 *  6. Manual score adjustment.
 */
final class IntentScoringServiceTest extends TestCase
{
    private IntentScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IntentScoringService::class);

        // Clear any test keys.
        $this->flushTestKeys();
    }

    protected function tearDown(): void
    {
        $this->flushTestKeys();
        parent::tearDown();
    }

    private function flushTestKeys(): void
    {
        foreach (['intent_sess_1', 'intent_sess_2', 'intent_sess_3', 'intent_sess_high', 'intent_sess_warm', 'intent_sess_browse', 'intent_sess_risk', 'intent_sess_adj', 'intent_sess_flush'] as $sid) {
            Redis::del("intent:score:{$sid}");
        }
    }

    // ------------------------------------------------------------------
    //  1. recordEvent increments correctly
    // ------------------------------------------------------------------

    public function test_page_view_adds_2_points(): void
    {
        $score = $this->service->recordEvent('intent_sess_1', 'page_view');
        $this->assertSame(2, $score);
    }

    public function test_add_to_cart_adds_20_points(): void
    {
        $score = $this->service->recordEvent('intent_sess_1', 'add_to_cart');
        $this->assertSame(20, $score);
    }

    public function test_purchase_adds_50_points(): void
    {
        $score = $this->service->recordEvent('intent_sess_2', 'purchase');
        $this->assertSame(50, $score);
    }

    public function test_cumulative_scoring(): void
    {
        $this->service->recordEvent('intent_sess_3', 'page_view');        // +2  = 2
        $this->service->recordEvent('intent_sess_3', 'product_view');     // +5  = 7
        $score = $this->service->recordEvent('intent_sess_3', 'add_to_cart'); // +20 = 27

        $this->assertSame(27, $score);
        $this->assertSame(27, $this->service->getScore('intent_sess_3'));
    }

    // ------------------------------------------------------------------
    //  2. Intent level thresholds
    // ------------------------------------------------------------------

    public function test_high_intent_level_at_60_plus(): void
    {
        // purchase (50) + begin_checkout (30) = 80
        $this->service->recordEvent('intent_sess_high', 'purchase');
        $this->service->recordEvent('intent_sess_high', 'begin_checkout');

        $intent = $this->service->evaluateIntent('intent_sess_high');

        $this->assertSame('high_intent', $intent['level']);
        $this->assertSame(80, $intent['score']);
    }

    public function test_warm_level_between_30_and_59(): void
    {
        // begin_checkout = 30
        $this->service->recordEvent('intent_sess_warm', 'begin_checkout');

        $intent = $this->service->evaluateIntent('intent_sess_warm');

        $this->assertSame('warm', $intent['level']);
        $this->assertSame(30, $intent['score']);
    }

    public function test_browsing_level_between_0_and_29(): void
    {
        // page_view = 2
        $this->service->recordEvent('intent_sess_browse', 'page_view');

        $intent = $this->service->evaluateIntent('intent_sess_browse');

        $this->assertSame('browsing', $intent['level']);
        $this->assertSame(2, $intent['score']);
    }

    public function test_abandon_risk_level_below_0(): void
    {
        // remove_from_cart = -10
        $this->service->recordEvent('intent_sess_risk', 'remove_from_cart');

        $intent = $this->service->evaluateIntent('intent_sess_risk');

        $this->assertSame('abandon_risk', $intent['level']);
        $this->assertSame(-10, $intent['score']);
    }

    // ------------------------------------------------------------------
    //  3. Negative events reduce score
    // ------------------------------------------------------------------

    public function test_remove_from_cart_reduces_score(): void
    {
        $this->service->recordEvent('intent_sess_1', 'product_view');       // +5  = 5
        $score = $this->service->recordEvent('intent_sess_1', 'remove_from_cart'); // -10 = -5

        $this->assertSame(-5, $score);
    }

    // ------------------------------------------------------------------
    //  4. Bulk reads
    // ------------------------------------------------------------------

    public function test_get_scores_returns_multiple_sessions(): void
    {
        $this->service->recordEvent('intent_sess_1', 'page_view');     // 2
        $this->service->recordEvent('intent_sess_2', 'add_to_cart');   // 20

        $scores = $this->service->getScores(['intent_sess_1', 'intent_sess_2', 'intent_sess_nonexistent']);

        $this->assertSame(2, $scores['intent_sess_1']);
        $this->assertSame(20, $scores['intent_sess_2']);
        $this->assertSame(0, $scores['intent_sess_nonexistent']);
    }

    public function test_get_scores_empty_array(): void
    {
        $scores = $this->service->getScores([]);
        $this->assertSame([], $scores);
    }

    // ------------------------------------------------------------------
    //  5. Flush
    // ------------------------------------------------------------------

    public function test_flush_removes_score(): void
    {
        $this->service->recordEvent('intent_sess_flush', 'purchase');
        $this->assertSame(50, $this->service->getScore('intent_sess_flush'));

        $this->service->flush('intent_sess_flush');
        $this->assertSame(0, $this->service->getScore('intent_sess_flush'));
    }

    // ------------------------------------------------------------------
    //  6. Manual adjustment
    // ------------------------------------------------------------------

    public function test_adjust_score_adds_delta(): void
    {
        $this->service->recordEvent('intent_sess_adj', 'page_view'); // 2
        $newScore = $this->service->adjustScore('intent_sess_adj', 10);

        $this->assertSame(12, $newScore);
    }

    public function test_adjust_score_subtracts_delta(): void
    {
        $this->service->recordEvent('intent_sess_adj', 'add_to_cart'); // 20
        $newScore = $this->service->adjustScore('intent_sess_adj', -25);

        $this->assertSame(-5, $newScore);
    }
}

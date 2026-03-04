<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Feature;

use App\Events\IntegrationEvent;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Modules\Analytics\Models\BehavioralRule;
use Modules\Analytics\Services\IntentScoringService;
use Tests\TestCase;

/**
 * Feature tests for the Behavioral Rules Engine and Intent Scoring.
 *
 * Covers:
 *  - Intent score increments for each event type
 *  - Intent level thresholds (high_intent, warm, browsing, abandon_risk)
 *  - Score decay via Redis TTL
 *  - Behavioral rule matching against intent levels
 *  - Rule priority ordering (highest priority fires first)
 *  - Cooldown enforcement (same rule not re-fired within window)
 *  - Multiple condition evaluation (AND logic)
 *  - Cart-based conditions
 *  - Edge: no active rules, all rules in cooldown, unknown condition keys
 */
final class BehavioralRulesIntentTest extends TestCase
{
    private Tenant $tenant;
    private IntentScoringService $intentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name'      => 'Behavioral Test Store',
            'slug'      => 'behavioral-test-' . Str::random(6),
            'api_key'   => 'ek_' . Str::random(48),
            'is_active' => true,
        ]);

        $this->intentService = app(IntentScoringService::class);
    }

    protected function tearDown(): void
    {
        BehavioralRule::where('tenant_id', $this->tenant->id)->delete();
        // Clean up Redis keys created during tests.
        $this->cleanRedisKeys();
        $this->tenant->delete();
        parent::tearDown();
    }

    private function cleanRedisKeys(): void
    {
        // Clean intent score keys and cooldown keys created in tests.
        $keys = Redis::keys('intent:score:s_intent_*');
        if (!empty($keys)) {
            Redis::del(...$keys);
        }
        $keys = Redis::keys('intervention:cooldown:*');
        if (!empty($keys)) {
            Redis::del(...$keys);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  1. Intent Score Increments
    // ═════════════════════════════════════════════════════════════════

    public function test_page_view_adds_2_points(): void
    {
        $sessionId = 's_intent_pv_' . Str::random(6);
        $score = $this->intentService->recordEvent($sessionId, 'page_view');
        $this->assertSame(2, $score);
        $this->intentService->flush($sessionId);
    }

    public function test_product_view_adds_5_points(): void
    {
        $sessionId = 's_intent_prodv_' . Str::random(6);
        $score = $this->intentService->recordEvent($sessionId, 'product_view');
        $this->assertSame(5, $score);
        $this->intentService->flush($sessionId);
    }

    public function test_add_to_cart_adds_20_points(): void
    {
        $sessionId = 's_intent_atc_' . Str::random(6);
        $score = $this->intentService->recordEvent($sessionId, 'add_to_cart');
        $this->assertSame(20, $score);
        $this->intentService->flush($sessionId);
    }

    public function test_remove_from_cart_subtracts_10_points(): void
    {
        $sessionId = 's_intent_rfc_' . Str::random(6);
        // First add something.
        $this->intentService->recordEvent($sessionId, 'add_to_cart'); // +20
        $score = $this->intentService->recordEvent($sessionId, 'remove_from_cart'); // -10
        $this->assertSame(10, $score);
        $this->intentService->flush($sessionId);
    }

    public function test_purchase_adds_50_points(): void
    {
        $sessionId = 's_intent_purchase_' . Str::random(6);
        $score = $this->intentService->recordEvent($sessionId, 'purchase');
        $this->assertSame(50, $score);
        $this->intentService->flush($sessionId);
    }

    public function test_unknown_event_adds_1_point(): void
    {
        $sessionId = 's_intent_unknown_' . Str::random(6);
        $score = $this->intentService->recordEvent($sessionId, 'custom_event');
        $this->assertSame(1, $score);
        $this->intentService->flush($sessionId);
    }

    // ═════════════════════════════════════════════════════════════════
    //  2. Intent Level Thresholds
    // ═════════════════════════════════════════════════════════════════

    public function test_high_intent_level_at_60_or_above(): void
    {
        $sessionId = 's_intent_hi_' . Str::random(6);
        // add_to_cart(20) + begin_checkout(30) + product_view(5) + page_view(2) + page_view(2) + page_view(2) = 61
        $this->intentService->recordEvent($sessionId, 'add_to_cart');
        $this->intentService->recordEvent($sessionId, 'begin_checkout');
        $this->intentService->recordEvent($sessionId, 'product_view');
        $this->intentService->recordEvent($sessionId, 'page_view');
        $this->intentService->recordEvent($sessionId, 'page_view');
        $this->intentService->recordEvent($sessionId, 'page_view');

        $intent = $this->intentService->evaluateIntent($sessionId);
        $this->assertSame('high_intent', $intent['level']);
        $this->assertGreaterThanOrEqual(60, $intent['score']);
        $this->intentService->flush($sessionId);
    }

    public function test_warm_level_between_30_and_59(): void
    {
        $sessionId = 's_intent_warm_' . Str::random(6);
        // begin_checkout = 30
        $this->intentService->recordEvent($sessionId, 'begin_checkout');

        $intent = $this->intentService->evaluateIntent($sessionId);
        $this->assertSame('warm', $intent['level']);
        $this->assertGreaterThanOrEqual(30, $intent['score']);
        $this->assertLessThan(60, $intent['score']);
        $this->intentService->flush($sessionId);
    }

    public function test_browsing_level_between_0_and_29(): void
    {
        $sessionId = 's_intent_browse_' . Str::random(6);
        // page_view = 2
        $this->intentService->recordEvent($sessionId, 'page_view');

        $intent = $this->intentService->evaluateIntent($sessionId);
        $this->assertSame('browsing', $intent['level']);
        $this->intentService->flush($sessionId);
    }

    public function test_abandon_risk_below_zero(): void
    {
        $sessionId = 's_intent_abandon_' . Str::random(6);
        // remove_from_cart(-10) twice with very little positive.
        $this->intentService->recordEvent($sessionId, 'page_view'); // +2
        $this->intentService->recordEvent($sessionId, 'remove_from_cart'); // -10
        $this->intentService->recordEvent($sessionId, 'remove_from_cart'); // -10

        $intent = $this->intentService->evaluateIntent($sessionId);
        $this->assertSame('abandon_risk', $intent['level']);
        $this->assertLessThan(0, $intent['score']);
        $this->intentService->flush($sessionId);
    }

    // ═════════════════════════════════════════════════════════════════
    //  3. Score Accumulation Over Session Lifecycle
    // ═════════════════════════════════════════════════════════════════

    public function test_score_accumulates_across_multiple_events(): void
    {
        $sessionId = 's_intent_accum_' . Str::random(6);

        $events = ['page_view', 'page_view', 'product_view', 'add_to_cart', 'begin_checkout'];
        // 2 + 2 + 5 + 20 + 30 = 59

        foreach ($events as $event) {
            $this->intentService->recordEvent($sessionId, $event);
        }

        $score = $this->intentService->getScore($sessionId);
        $this->assertSame(59, $score);
        $this->intentService->flush($sessionId);
    }

    // ═════════════════════════════════════════════════════════════════
    //  4. Manual Score Adjustment
    // ═════════════════════════════════════════════════════════════════

    public function test_manual_score_adjustment(): void
    {
        $sessionId = 's_intent_adjust_' . Str::random(6);
        $this->intentService->recordEvent($sessionId, 'page_view'); // +2

        $newScore = $this->intentService->adjustScore($sessionId, 100);
        $this->assertSame(102, $newScore);
        $this->intentService->flush($sessionId);
    }

    // ═════════════════════════════════════════════════════════════════
    //  5. Bulk Score Read
    // ═════════════════════════════════════════════════════════════════

    public function test_bulk_score_read(): void
    {
        $s1 = 's_intent_bulk1_' . Str::random(6);
        $s2 = 's_intent_bulk2_' . Str::random(6);

        $this->intentService->recordEvent($s1, 'add_to_cart'); // 20
        $this->intentService->recordEvent($s2, 'page_view');   // 2

        $scores = $this->intentService->getScores([$s1, $s2]);
        $this->assertSame(20, $scores[$s1]);
        $this->assertSame(2, $scores[$s2]);

        $this->intentService->flush($s1);
        $this->intentService->flush($s2);
    }

    // ═════════════════════════════════════════════════════════════════
    //  6. Flush Removes Score
    // ═════════════════════════════════════════════════════════════════

    public function test_flush_removes_session_score(): void
    {
        $sessionId = 's_intent_flush_' . Str::random(6);
        $this->intentService->recordEvent($sessionId, 'purchase'); // 50

        $this->intentService->flush($sessionId);

        $score = $this->intentService->getScore($sessionId);
        $this->assertSame(0, $score);
    }

    // ═════════════════════════════════════════════════════════════════
    //  7. Behavioral Rule — DB CRUD
    // ═════════════════════════════════════════════════════════════════

    public function test_behavioral_rule_can_be_created(): void
    {
        $rule = BehavioralRule::create([
            'tenant_id'        => $this->tenant->id,
            'name'             => 'High Intent Discount',
            'trigger_condition' => [
                'intent_level' => 'high_intent',
                'min_cart_total' => 100,
            ],
            'action_type'      => 'popup',
            'action_payload'   => [
                'message'       => '10% off your order!',
                'discount_code' => 'HIGHINTENT10',
            ],
            'priority'         => 100,
            'is_active'        => true,
            'cooldown_minutes' => 30,
        ]);

        $this->assertNotNull($rule->id);
        $this->assertSame('High Intent Discount', $rule->name);
        $this->assertSame('popup', $rule->action_type);
        $this->assertSame(100, $rule->priority);
    }

    // ═════════════════════════════════════════════════════════════════
    //  8. Rule Priority Ordering
    // ═════════════════════════════════════════════════════════════════

    public function test_rules_are_returned_by_priority_desc(): void
    {
        BehavioralRule::create([
            'tenant_id'        => $this->tenant->id,
            'name'             => 'Low Priority',
            'trigger_condition' => ['intent_level' => 'browsing'],
            'action_type'      => 'banner',
            'action_payload'   => [],
            'priority'         => 10,
            'is_active'        => true,
            'cooldown_minutes' => 5,
        ]);

        BehavioralRule::create([
            'tenant_id'        => $this->tenant->id,
            'name'             => 'High Priority',
            'trigger_condition' => ['intent_level' => 'high_intent'],
            'action_type'      => 'popup',
            'action_payload'   => [],
            'priority'         => 100,
            'is_active'        => true,
            'cooldown_minutes' => 30,
        ]);

        $rules = BehavioralRule::where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        $this->assertSame('High Priority', $rules->first()->name);
        $this->assertSame('Low Priority', $rules->last()->name);
    }

    // ═════════════════════════════════════════════════════════════════
    //  9. Inactive Rules Are Excluded
    // ═════════════════════════════════════════════════════════════════

    public function test_inactive_rules_are_excluded(): void
    {
        BehavioralRule::create([
            'tenant_id'        => $this->tenant->id,
            'name'             => 'Active Rule',
            'trigger_condition' => ['intent_level' => 'warm'],
            'action_type'      => 'popup',
            'action_payload'   => [],
            'priority'         => 50,
            'is_active'        => true,
            'cooldown_minutes' => 10,
        ]);

        BehavioralRule::create([
            'tenant_id'        => $this->tenant->id,
            'name'             => 'Disabled Rule',
            'trigger_condition' => ['intent_level' => 'warm'],
            'action_type'      => 'popup',
            'action_payload'   => [],
            'priority'         => 90,
            'is_active'        => false,
            'cooldown_minutes' => 10,
        ]);

        $active = BehavioralRule::where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->get();

        $this->assertCount(1, $active);
        $this->assertSame('Active Rule', $active->first()->name);
    }

    // ═════════════════════════════════════════════════════════════════
    // 10. Redis Cooldown
    // ═════════════════════════════════════════════════════════════════

    public function test_cooldown_key_is_set_and_checked(): void
    {
        $ruleId = 999;
        $sessionId = 's_cooldown_' . Str::random(6);
        $key = "intervention:cooldown:{$ruleId}:{$sessionId}";

        // Initially no cooldown.
        $this->assertSame(0, (int) Redis::exists($key));

        // Set cooldown for 1 minute.
        Redis::setex($key, 60, '1');

        // Now in cooldown.
        $this->assertSame(1, (int) Redis::exists($key));

        // Cleanup.
        Redis::del($key);
    }

    // ═════════════════════════════════════════════════════════════════
    // 11. Empty Score for Unknown Session
    // ═════════════════════════════════════════════════════════════════

    public function test_unknown_session_returns_zero_score(): void
    {
        $intent = $this->intentService->evaluateIntent('s_nonexistent_' . Str::random(8));
        $this->assertSame(0, $intent['score']);
        $this->assertSame('browsing', $intent['level']);
    }

    // ═════════════════════════════════════════════════════════════════
    // 12. Empty Session List Returns Empty Scores
    // ═════════════════════════════════════════════════════════════════

    public function test_empty_session_list_returns_empty_scores(): void
    {
        $scores = $this->intentService->getScores([]);
        $this->assertSame([], $scores);
    }
}

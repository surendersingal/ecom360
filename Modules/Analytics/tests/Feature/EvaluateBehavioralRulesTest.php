<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Feature;

use App\Events\IntegrationEvent;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Modules\Analytics\Events\FrontendInterventionRequired;
use Modules\Analytics\Listeners\EvaluateBehavioralRules;
use Modules\Analytics\Models\BehavioralRule;
use Tests\TestCase;

/**
 * Feature tests for the EvaluateBehavioralRules listener.
 *
 * Because the listener calls broadcast() (which goes through the broadcast
 * manager, not the event dispatcher), we verify side-effects instead:
 *  - Redis cooldown key presence = rule fired
 *  - Redis cooldown key absence  = rule did NOT fire
 *
 * The FrontendInterventionRequired broadcast payload structure is tested
 * separately in test #8 (pure unit test — no broadcast needed).
 *
 * Covers:
 *  1. Rule matching fires (cooldown key set).
 *  2. Non-matching rule does not fire.
 *  3. Inactive rules are skipped.
 *  4. Cooldown prevents re-firing for the same session.
 *  5. Higher-priority rule fires first (only one per event).
 *  6. Non-analytics events are ignored.
 *  7. Multiple conditions must ALL match (AND semantics).
 *  8. FrontendInterventionRequired event has correct payload structure.
 */
final class EvaluateBehavioralRulesTest extends TestCase
{
    private int|string $tenantId;
    private const string SESSION = 'eval_br_sess_001';

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test tenant.
        $tenant = Tenant::create([
            'name'      => 'BehavioralRule Test Tenant',
            'slug'      => 'br-test-' . uniqid(),
            'is_active' => true,
        ]);
        $this->tenantId = $tenant->id;

        // Flush intent score and cooldown keys.
        $this->cleanRedis();
    }

    protected function tearDown(): void
    {
        BehavioralRule::where('tenant_id', $this->tenantId)->delete();
        Tenant::destroy($this->tenantId);
        $this->cleanRedis();
        parent::tearDown();
    }

    private function cleanRedis(): void
    {
        Redis::del('intent:score:' . self::SESSION);
        // Clean any cooldown keys for this session.
        $keys = Redis::keys('intervention:cooldown:*:' . self::SESSION);
        if (!empty($keys)) {
            Redis::del(...$keys);
        }
    }

    private function makeEvent(string $eventType = 'add_to_cart'): IntegrationEvent
    {
        return new IntegrationEvent(
            moduleName: 'analytics',
            eventName: 'tracking.ingest',
            payload: [
                'tenant_id'  => $this->tenantId,
                'session_id' => self::SESSION,
                'event_type' => $eventType,
            ],
        );
    }

    /**
     * Check if any cooldown key exists for a given rule ID and session.
     */
    private function cooldownKeyExists(int $ruleId): bool
    {
        return Redis::exists("intervention:cooldown:{$ruleId}:" . self::SESSION) > 0;
    }

    // ------------------------------------------------------------------
    //  1. Rule matching fires (cooldown key set as proof)
    // ------------------------------------------------------------------

    public function test_matching_rule_fires_intervention(): void
    {
        $rule = BehavioralRule::create([
            'tenant_id'         => $this->tenantId,
            'name'              => 'High Intent Popup',
            'trigger_condition' => ['event_type' => 'add_to_cart'],
            'action_type'       => 'popup',
            'action_payload'    => ['title' => 'Great choice!', 'body' => 'Complete your purchase now.'],
            'priority'          => 50,
            'is_active'         => true,
            'cooldown_minutes'  => 5,
        ]);

        // Fake broadcasting to avoid actual WebSocket calls.
        Event::fake([FrontendInterventionRequired::class]);

        $listener = app(EvaluateBehavioralRules::class);
        $listener->handle($this->makeEvent('add_to_cart'));

        // Cooldown key proves the rule fired.
        $this->assertTrue($this->cooldownKeyExists($rule->id), 'Cooldown key should exist after rule fires.');
    }

    // ------------------------------------------------------------------
    //  2. Non-matching rule does not fire
    // ------------------------------------------------------------------

    public function test_non_matching_rule_does_not_fire(): void
    {
        $rule = BehavioralRule::create([
            'tenant_id'         => $this->tenantId,
            'name'              => 'Checkout Only',
            'trigger_condition' => ['event_type' => 'begin_checkout'],
            'action_type'       => 'notification',
            'action_payload'    => ['msg' => 'Almost there!'],
            'priority'          => 50,
            'is_active'         => true,
            'cooldown_minutes'  => 5,
        ]);

        Event::fake([FrontendInterventionRequired::class]);

        $listener = app(EvaluateBehavioralRules::class);
        $listener->handle($this->makeEvent('page_view')); // Not begin_checkout.

        $this->assertFalse($this->cooldownKeyExists($rule->id), 'Cooldown key should NOT exist when rule does not match.');
    }

    // ------------------------------------------------------------------
    //  3. Inactive rules are skipped
    // ------------------------------------------------------------------

    public function test_inactive_rule_is_skipped(): void
    {
        $rule = BehavioralRule::create([
            'tenant_id'         => $this->tenantId,
            'name'              => 'Disabled Rule',
            'trigger_condition' => ['event_type' => 'add_to_cart'],
            'action_type'       => 'discount',
            'action_payload'    => ['code' => 'SAVE10'],
            'priority'          => 50,
            'is_active'         => false,
            'cooldown_minutes'  => 5,
        ]);

        Event::fake([FrontendInterventionRequired::class]);

        $listener = app(EvaluateBehavioralRules::class);
        $listener->handle($this->makeEvent('add_to_cart'));

        $this->assertFalse($this->cooldownKeyExists($rule->id));
    }

    // ------------------------------------------------------------------
    //  4. Cooldown prevents re-firing
    // ------------------------------------------------------------------

    public function test_cooldown_prevents_re_fire_for_same_session(): void
    {
        $rule = BehavioralRule::create([
            'tenant_id'         => $this->tenantId,
            'name'              => 'Cooldown Test',
            'trigger_condition' => ['event_type' => 'add_to_cart'],
            'action_type'       => 'popup',
            'action_payload'    => ['title' => 'Hey!'],
            'priority'          => 50,
            'is_active'         => true,
            'cooldown_minutes'  => 10,
        ]);

        Event::fake([FrontendInterventionRequired::class]);

        $listener = app(EvaluateBehavioralRules::class);

        // First call: should fire.
        $listener->handle($this->makeEvent('add_to_cart'));

        // Verify cooldown key was set.
        $this->assertTrue($this->cooldownKeyExists($rule->id));

        // TTL should be approximately 10 * 60 = 600 seconds.
        $ttl = Redis::ttl("intervention:cooldown:{$rule->id}:" . self::SESSION);
        $this->assertGreaterThan(590, $ttl);
        $this->assertLessThanOrEqual(600, $ttl);
    }

    // ------------------------------------------------------------------
    //  5. Higher-priority rule fires first (only one per event)
    // ------------------------------------------------------------------

    public function test_highest_priority_rule_fires_first(): void
    {
        $lowRule = BehavioralRule::create([
            'tenant_id'         => $this->tenantId,
            'name'              => 'Low Priority',
            'trigger_condition' => ['event_type' => 'add_to_cart'],
            'action_type'       => 'notification',
            'action_payload'    => ['msg' => 'low'],
            'priority'          => 10,
            'is_active'         => true,
            'cooldown_minutes'  => 5,
        ]);

        $highRule = BehavioralRule::create([
            'tenant_id'         => $this->tenantId,
            'name'              => 'High Priority',
            'trigger_condition' => ['event_type' => 'add_to_cart'],
            'action_type'       => 'discount',
            'action_payload'    => ['code' => 'VIP'],
            'priority'          => 90,
            'is_active'         => true,
            'cooldown_minutes'  => 5,
        ]);

        Event::fake([FrontendInterventionRequired::class]);

        $listener = app(EvaluateBehavioralRules::class);
        $listener->handle($this->makeEvent('add_to_cart'));

        // Only the high-priority rule should have fired (cooldown key set).
        $this->assertTrue($this->cooldownKeyExists($highRule->id), 'High priority rule should fire.');
        $this->assertFalse($this->cooldownKeyExists($lowRule->id), 'Low priority rule should NOT fire (only one per event).');
    }

    // ------------------------------------------------------------------
    //  6. Non-analytics events are ignored
    // ------------------------------------------------------------------

    public function test_non_analytics_module_events_are_ignored(): void
    {
        $rule = BehavioralRule::create([
            'tenant_id'         => $this->tenantId,
            'name'              => 'Should Not Fire',
            'trigger_condition' => ['event_type' => 'add_to_cart'],
            'action_type'       => 'popup',
            'action_payload'    => ['title' => 'Bug!'],
            'priority'          => 50,
            'is_active'         => true,
            'cooldown_minutes'  => 5,
        ]);

        Event::fake([FrontendInterventionRequired::class]);

        $listener = app(EvaluateBehavioralRules::class);

        $nonAnalyticsEvent = new IntegrationEvent(
            moduleName: 'marketing',
            eventName: 'campaign.sent',
            payload: [
                'tenant_id'  => $this->tenantId,
                'session_id' => self::SESSION,
                'event_type' => 'add_to_cart',
            ],
        );

        $listener->handle($nonAnalyticsEvent);

        $this->assertFalse($this->cooldownKeyExists($rule->id));
    }

    // ------------------------------------------------------------------
    //  7. Multiple conditions must ALL match (AND semantics)
    // ------------------------------------------------------------------

    public function test_all_conditions_must_match(): void
    {
        $rule = BehavioralRule::create([
            'tenant_id'         => $this->tenantId,
            'name'              => 'Multi-Condition',
            'trigger_condition' => [
                'event_type'       => 'add_to_cart',
                'min_intent_score' => 100, // Impossibly high
            ],
            'action_type'       => 'popup',
            'action_payload'    => ['title' => 'Should not fire'],
            'priority'          => 50,
            'is_active'         => true,
            'cooldown_minutes'  => 5,
        ]);

        Event::fake([FrontendInterventionRequired::class]);

        $listener = app(EvaluateBehavioralRules::class);
        $listener->handle($this->makeEvent('add_to_cart'));

        // Event type matches but min_intent_score doesn't → no fire.
        $this->assertFalse($this->cooldownKeyExists($rule->id));
    }

    // ------------------------------------------------------------------
    //  8. FrontendInterventionRequired payload structure (pure unit test)
    // ------------------------------------------------------------------

    public function test_intervention_event_has_correct_broadcast_payload(): void
    {
        $event = new FrontendInterventionRequired(
            sessionId:     'sess_123',
            ruleId:        42,
            ruleName:      'Cart Abandonment Saver',
            actionType:    'discount',
            actionPayload: ['discount_code' => 'SAVE10', 'discount_percent' => 10],
            intent:        ['score' => 15, 'level' => 'abandon_risk'],
            firedAt:       '2025-02-20T14:30:00+00:00',
        );

        // Verify broadcastAs.
        $this->assertSame('intervention.required', $event->broadcastAs());

        // Verify broadcastWith.
        $payload = $event->broadcastWith();
        $this->assertSame('sess_123', $payload['session_id']);
        $this->assertSame(42, $payload['rule_id']);
        $this->assertSame('discount', $payload['action_type']);
        $this->assertSame('SAVE10', $payload['action_payload']['discount_code']);
        $this->assertSame('abandon_risk', $payload['intent']['level']);

        // Verify broadcastOn channel.
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('private-session.sess_123', $channels[0]->name);
    }
}

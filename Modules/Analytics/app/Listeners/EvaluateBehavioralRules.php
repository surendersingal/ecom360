<?php

declare(strict_types=1);

namespace Modules\Analytics\Listeners;

use App\Events\IntegrationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Analytics\Events\FrontendInterventionRequired;
use Modules\Analytics\Models\BehavioralRule;
use Modules\Analytics\Services\IntentScoringService;
use Modules\Analytics\Services\LiveContextService;

/**
 * Dynamic Rules Engine listener.
 *
 * Runs AFTER every analytics event is ingested. It:
 *  1. Updates the session's intent score via IntentScoringService.
 *  2. Loads all active BehavioralRules for the tenant.
 *  3. Evaluates each rule's trigger_condition against the current
 *     session context (intent score, cart total, event type, etc.).
 *  4. Respects cooldown windows (per rule per session via Redis).
 *  5. Broadcasts FrontendInterventionRequired for the first matching rule.
 *
 * Runs on a dedicated Redis queue to avoid blocking the main analytics
 * ingestion pipeline.
 */
final class EvaluateBehavioralRules implements ShouldQueue
{
    public string $connection = 'redis';
    public string $queue = 'interventions';

    public function __construct(
        private readonly IntentScoringService $intentService,
        private readonly LiveContextService $liveContextService,
    ) {}

    public function handle(IntegrationEvent $event): void
    {
        // Only process analytics events.
        if (strtolower($event->moduleName) !== 'analytics') {
            return;
        }

        $tenantId  = $event->payload['tenant_id'] ?? null;
        $sessionId = $event->payload['session_id'] ?? null;
        $eventType = $event->payload['event_type'] ?? null;

        if ($tenantId === null || $sessionId === null || $eventType === null) {
            return;
        }

        // ------------------------------------------------------------------
        //  Step 1: Update the intent score with this event.
        // ------------------------------------------------------------------
        $this->intentService->recordEvent($sessionId, $eventType);
        $intent = $this->intentService->evaluateIntent($sessionId);

        // ------------------------------------------------------------------
        //  Step 2: Load active rules for this tenant, ordered by priority DESC.
        // ------------------------------------------------------------------
        $rules = BehavioralRule::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        // ------------------------------------------------------------------
        //  Step 3: Build session context for evaluation.
        // ------------------------------------------------------------------
        $liveContext = $this->liveContextService->getContext($sessionId);

        $context = [
            'intent_level'  => $intent['level'],
            'intent_score'  => $intent['score'],
            'event_type'    => $eventType,
            'cart_total'    => $liveContext['active_cart']['total'] ?? 0.0,
            'cart_items'    => count($liveContext['active_cart']['items'] ?? []),
            'has_cart'      => $liveContext['active_cart'] !== null,
            'current_page'  => $liveContext['current_page']['product_id'] ?? null,
        ];

        // ------------------------------------------------------------------
        //  Step 4 & 5: Evaluate rules and fire the first match.
        // ------------------------------------------------------------------
        foreach ($rules as $rule) {
            /** @var BehavioralRule $rule */
            if (!$this->matchesCondition($rule->trigger_condition, $context)) {
                continue;
            }

            if ($this->isInCooldown($rule->id, $sessionId)) {
                Log::debug(
                    "[BehavioralRules] Rule [{$rule->name}] matched but is in cooldown for session [{$sessionId}].",
                );
                continue;
            }

            // Fire the intervention!
            $this->fireIntervention($rule, $sessionId, $intent);
            $this->setCooldown($rule->id, $sessionId, $rule->cooldown_minutes);

            Log::info(
                "[BehavioralRules] Fired rule [{$rule->name}] (#{$rule->id}) "
                . "for session [{$sessionId}] in tenant [{$tenantId}]. "
                . "Intent: {$intent['level']} ({$intent['score']}).",
            );

            // Only fire ONE intervention per event to avoid overwhelming the user.
            return;
        }
    }

    // ------------------------------------------------------------------
    //  Rule Evaluation
    // ------------------------------------------------------------------

    /**
     * Check if ALL conditions in the trigger_condition match the current context.
     *
     * Supported condition keys:
     *  - intent_level:    Exact match (e.g. "abandon_risk", "high_intent")
     *  - min_intent_score: Score must be >= this value
     *  - max_intent_score: Score must be <= this value
     *  - event_type:       Exact match on the current event type
     *  - min_cart_total:   Cart total must be >= this value
     *  - min_cart_items:   Cart must have >= this many items
     *  - has_cart:         Boolean — cart must exist (true) or not (false)
     *
     * @param  array<string, mixed> $condition
     * @param  array<string, mixed> $context
     */
    private function matchesCondition(array $condition, array $context): bool
    {
        foreach ($condition as $key => $expected) {
            $matched = match ($key) {
                'intent_level'    => ($context['intent_level'] ?? '') === $expected,
                'min_intent_score' => ($context['intent_score'] ?? 0) >= $expected,
                'max_intent_score' => ($context['intent_score'] ?? 0) <= $expected,
                'event_type'      => ($context['event_type'] ?? '') === $expected,
                'min_cart_total'  => ($context['cart_total'] ?? 0) >= $expected,
                'min_cart_items'  => ($context['cart_items'] ?? 0) >= $expected,
                'has_cart'        => ($context['has_cart'] ?? false) === (bool) $expected,
                default           => true, // Unknown keys are ignored (forward-compatible).
            };

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------
    //  Cooldown Management (Redis-backed)
    // ------------------------------------------------------------------

    /**
     * Check if a rule is currently in cooldown for a specific session.
     */
    private function isInCooldown(int $ruleId, string $sessionId): bool
    {
        return Redis::exists("intervention:cooldown:{$ruleId}:{$sessionId}") > 0;
    }

    /**
     * Set cooldown so the same rule doesn't re-fire too quickly for the same session.
     */
    private function setCooldown(int $ruleId, string $sessionId, int $cooldownMinutes): void
    {
        if ($cooldownMinutes <= 0) {
            return;
        }

        Redis::setex(
            "intervention:cooldown:{$ruleId}:{$sessionId}",
            $cooldownMinutes * 60,
            '1',
        );
    }

    // ------------------------------------------------------------------
    //  Intervention Dispatch
    // ------------------------------------------------------------------

    /**
     * Broadcast the FrontendInterventionRequired event via WebSockets.
     *
     * Wrapped in try-catch to ensure a WebSocket outage (Reverb/Pusher
     * being offline) never crashes the analytics pipeline.  The event
     * data is already persisted in MongoDB; the real-time push is
     * best-effort only.
     */
    private function fireIntervention(BehavioralRule $rule, string $sessionId, array $intent): void
    {
        try {
            event(new FrontendInterventionRequired(
                sessionId:     $sessionId,
                ruleId:        $rule->id,
                ruleName:      $rule->name,
                actionType:    $rule->action_type,
                actionPayload: $rule->action_payload,
                intent:        [
                    'score' => $intent['score'],
                    'level' => $intent['level'],
                ],
                firedAt:       now()->toIso8601String(),
            ));
        } catch (\Throwable $e) {
            report($e);

            Log::warning(
                "[BehavioralRules] Broadcast failed for rule [{$rule->name}] "
                . "session [{$sessionId}]: {$e->getMessage()}",
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Events\IntegrationEvent;

/**
 * Behavioural Trigger Service.
 *
 * Detects real-time behavioural patterns and fires IntegrationEvents so that
 * the Marketing module can react (e.g., send abandoned-cart emails, price-drop
 * alerts, browse-abandonment reminders, back-in-stock notifications).
 *
 * Trigger types:
 *   - cart_abandoned:  Cart created but no purchase within threshold.
 *   - browse_abandoned: Product viewed 2+ times but no add-to-cart.
 *   - price_drop:  Watched product price decreased.
 *   - back_in_stock:  Product flagged out-of-stock is available again.
 *   - high_intent:  Intent score crossed configurable threshold.
 *   - repeat_visit:  Customer returned within N days.
 *   - milestone:  Customer hit order-count or spend milestone.
 */
final class BehavioralTriggerService
{
    private const CART_ABANDON_MINUTES = 60;
    private const BROWSE_ABANDON_HOURS = 24;
    private const INTENT_THRESHOLD = 70;

    /**
     * Run all trigger evaluations for a tenant.
     * Typically called from a scheduled job every 5-10 min.
     */
    public function evaluateAll(int|string $tenantId): array
    {
        $results = [
            'cart_abandoned' => $this->detectCartAbandonment($tenantId),
            'browse_abandoned' => $this->detectBrowseAbandonment($tenantId),
            'high_intent' => $this->detectHighIntent($tenantId),
            'milestones' => $this->detectMilestones($tenantId),
        ];

        return $results;
    }

    /**
     * Evaluate triggers for a single event (real-time).
     * Called from the tracking pipeline after each event is stored.
     */
    public function evaluateEvent(int|string $tenantId, array $event): void
    {
        $type = $event['event_type'] ?? '';

        match ($type) {
            'add_to_cart' => $this->scheduleCartAbandonCheck($tenantId, $event),
            'product_view' => $this->checkBrowsePattern($tenantId, $event),
            'purchase' => $this->checkMilestone($tenantId, $event),
            default => null,
        };
    }

    // ── Cart Abandonment ─────────────────────────────────────────────

    private function detectCartAbandonment(int|string $tenantId): int
    {
        $threshold = now()->subMinutes(self::CART_ABANDON_MINUTES)->toIso8601String();
        $recentLimit = now()->subHours(24)->toIso8601String();

        // Find sessions with add_to_cart but no purchase
        $cartSessions = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'add_to_cart')
            ->where('created_at', '>=', $recentLimit)
            ->where('created_at', '<=', $threshold)
            ->distinct('session_id')
            ->pluck('session_id')
            ->all();

        if (empty($cartSessions)) return 0;

        $purchasedSessions = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'purchase')
            ->whereIn('session_id', $cartSessions)
            ->distinct('session_id')
            ->pluck('session_id')
            ->all();

        $abandonedSessions = array_diff($cartSessions, $purchasedSessions);

        // Check we haven't already triggered for these sessions
        $alreadyTriggered = DB::connection('mongodb')->table('behavioral_triggers')
            ->where('tenant_id', $tenantId)
            ->where('trigger_type', 'cart_abandoned')
            ->whereIn('session_id', $abandonedSessions)
            ->pluck('session_id')
            ->all();

        $newAbandoned = array_diff($abandonedSessions, $alreadyTriggered);
        $count = 0;

        foreach ($newAbandoned as $sessionId) {
            $cartItems = DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tenantId)
                ->where('session_id', $sessionId)
                ->where('event_type', 'add_to_cart')
                ->get()
                ->map(fn($e) => (array) $e)
                ->all();

            $visitorId = $cartItems[0]['visitor_id'] ?? null;
            if (!$visitorId) continue;

            // Record trigger
            DB::connection('mongodb')->table('behavioral_triggers')->insert([
                'tenant_id' => $tenantId,
                'trigger_type' => 'cart_abandoned',
                'visitor_id' => $visitorId,
                'session_id' => $sessionId,
                'data' => ['items' => array_map(fn($i) => $i['metadata'] ?? [], $cartItems)],
                'created_at' => now()->toIso8601String(),
            ]);

            IntegrationEvent::dispatch($tenantId, 'analytics', 'behavioral_trigger', [
                'trigger_type' => 'cart_abandoned',
                'visitor_id' => $visitorId,
                'session_id' => $sessionId,
                'items' => array_map(fn($i) => $i['metadata'] ?? [], $cartItems),
            ]);

            $count++;
        }

        return $count;
    }

    // ── Browse Abandonment ───────────────────────────────────────────

    private function detectBrowseAbandonment(int|string $tenantId): int
    {
        $threshold = now()->subHours(self::BROWSE_ABANDON_HOURS)->toIso8601String();
        $recentLimit = now()->subHours(48)->toIso8601String();

        // Find visitors who viewed products 2+ times but never added to cart
        $results = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tenantId, $recentLimit, $threshold) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tenantId,
                        'event_type' => 'product_view',
                        'created_at' => ['$gte' => $recentLimit, '$lte' => $threshold],
                    ]],
                    ['$group' => [
                        '_id' => '$visitor_id',
                        'view_count' => ['$sum' => 1],
                        'products' => ['$addToSet' => '$metadata.product_id'],
                    ]],
                    ['$match' => ['view_count' => ['$gte' => 2]]],
                ])->toArray();
            });

        $count = 0;
        foreach ($results as $r) {
            $visitorId = $r['_id'];
            if (!$visitorId) continue;

            // Check if they added to cart
            $addedToCart = DB::connection('mongodb')->table('tracking_events')
                ->where('tenant_id', $tenantId)
                ->where('visitor_id', $visitorId)
                ->where('event_type', 'add_to_cart')
                ->where('created_at', '>=', $recentLimit)
                ->exists();

            if ($addedToCart) continue;

            // Check not already triggered
            $alreadyTriggered = DB::connection('mongodb')->table('behavioral_triggers')
                ->where('tenant_id', $tenantId)
                ->where('trigger_type', 'browse_abandoned')
                ->where('visitor_id', $visitorId)
                ->where('created_at', '>=', $recentLimit)
                ->exists();

            if ($alreadyTriggered) continue;

            DB::connection('mongodb')->table('behavioral_triggers')->insert([
                'tenant_id' => $tenantId,
                'trigger_type' => 'browse_abandoned',
                'visitor_id' => $visitorId,
                'data' => ['products' => $r['products'] ?? [], 'view_count' => $r['view_count']],
                'created_at' => now()->toIso8601String(),
            ]);

            IntegrationEvent::dispatch($tenantId, 'analytics', 'behavioral_trigger', [
                'trigger_type' => 'browse_abandoned',
                'visitor_id' => $visitorId,
                'products' => $r['products'] ?? [],
            ]);

            $count++;
        }

        return $count;
    }

    // ── High Intent ──────────────────────────────────────────────────

    private function detectHighIntent(int|string $tenantId): int
    {
        $highIntentVisitors = DB::connection('mongodb')->table('tracking_events')
            ->raw(function ($col) use ($tenantId) {
                return $col->aggregate([
                    ['$match' => [
                        'tenant_id' => $tenantId,
                        'event_type' => 'intent_score_updated',
                        'metadata.score' => ['$gte' => self::INTENT_THRESHOLD],
                        'created_at' => ['$gte' => now()->subHours(1)->toIso8601String()],
                    ]],
                    ['$group' => [
                        '_id' => '$visitor_id',
                        'max_score' => ['$max' => '$metadata.score'],
                    ]],
                ])->toArray();
            });

        $count = 0;
        foreach ($highIntentVisitors as $v) {
            $visitorId = $v['_id'];
            if (!$visitorId) continue;

            // Check not already triggered recently
            $alreadyTriggered = DB::connection('mongodb')->table('behavioral_triggers')
                ->where('tenant_id', $tenantId)
                ->where('trigger_type', 'high_intent')
                ->where('visitor_id', $visitorId)
                ->where('created_at', '>=', now()->subHours(24)->toIso8601String())
                ->exists();

            if ($alreadyTriggered) continue;

            DB::connection('mongodb')->table('behavioral_triggers')->insert([
                'tenant_id' => $tenantId,
                'trigger_type' => 'high_intent',
                'visitor_id' => $visitorId,
                'data' => ['score' => $v['max_score']],
                'created_at' => now()->toIso8601String(),
            ]);

            IntegrationEvent::dispatch($tenantId, 'analytics', 'behavioral_trigger', [
                'trigger_type' => 'high_intent',
                'visitor_id' => $visitorId,
                'score' => $v['max_score'],
            ]);

            $count++;
        }

        return $count;
    }

    // ── Milestones ───────────────────────────────────────────────────

    private function detectMilestones(int|string $tenantId): int
    {
        $milestoneThresholds = [
            'orders' => [1, 5, 10, 25, 50, 100],
            'spend'  => [100, 500, 1000, 5000, 10000],
        ];

        $profiles = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tenantId)
            ->get();

        $count = 0;
        foreach ($profiles as $profile) {
            $profile = (array) $profile;
            $orders = (int) ($profile['total_orders'] ?? 0);
            $spend = (float) ($profile['total_revenue'] ?? 0);
            $visitorId = $profile['visitor_id'] ?? $profile['_id'] ?? null;
            if (!$visitorId) continue;

            foreach ($milestoneThresholds['orders'] as $m) {
                if ($orders === $m) {
                    $this->fireMilestone($tenantId, $visitorId, 'order_count', $m);
                    $count++;
                }
            }

            foreach ($milestoneThresholds['spend'] as $m) {
                if ($spend >= $m && $spend < $m * 1.1) {
                    $this->fireMilestone($tenantId, $visitorId, 'total_spend', $m);
                    $count++;
                }
            }
        }

        return $count;
    }

    private function fireMilestone(int|string $tenantId, string $visitorId, string $type, float $value): void
    {
        $alreadyTriggered = DB::connection('mongodb')->table('behavioral_triggers')
            ->where('tenant_id', $tenantId)
            ->where('trigger_type', 'milestone')
            ->where('visitor_id', $visitorId)
            ->where('data.milestone_type', $type)
            ->where('data.milestone_value', $value)
            ->exists();

        if ($alreadyTriggered) return;

        DB::connection('mongodb')->table('behavioral_triggers')->insert([
            'tenant_id' => $tenantId,
            'trigger_type' => 'milestone',
            'visitor_id' => $visitorId,
            'data' => ['milestone_type' => $type, 'milestone_value' => $value],
            'created_at' => now()->toIso8601String(),
        ]);

        IntegrationEvent::dispatch($tenantId, 'analytics', 'behavioral_trigger', [
            'trigger_type' => 'milestone',
            'visitor_id' => $visitorId,
            'milestone_type' => $type,
            'milestone_value' => $value,
        ]);
    }

    // ── Inline helpers for real-time evaluation ──────────────────────

    private function scheduleCartAbandonCheck(int|string $tenantId, array $event): void
    {
        // In a production system you'd dispatch a delayed job.
        // The batch detectCartAbandonment() handles it via schedule.
        Log::debug('Cart abandon check scheduled', [
            'tenant' => $tenantId,
            'session' => $event['session_id'] ?? null,
        ]);
    }

    private function checkBrowsePattern(int|string $tenantId, array $event): void
    {
        $visitorId = $event['visitor_id'] ?? null;
        if (!$visitorId) return;

        $viewCount = DB::connection('mongodb')->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where('visitor_id', $visitorId)
            ->where('event_type', 'product_view')
            ->where('created_at', '>=', now()->subHours(2)->toIso8601String())
            ->count();

        if ($viewCount >= 3) {
            IntegrationEvent::dispatch($tenantId, 'analytics', 'behavioral_trigger', [
                'trigger_type' => 'high_browse_activity',
                'visitor_id' => $visitorId,
                'view_count' => $viewCount,
            ]);
        }
    }

    private function checkMilestone(int|string $tenantId, array $event): void
    {
        $visitorId = $event['visitor_id'] ?? null;
        if (!$visitorId) return;

        $profile = DB::connection('mongodb')->table('customer_profiles')
            ->where('tenant_id', $tenantId)
            ->where('visitor_id', $visitorId)
            ->first();

        if (!$profile) return;

        $profile = (array) $profile;
        $orders = (int) ($profile['total_orders'] ?? 0);
        if (in_array($orders, [1, 5, 10, 25, 50, 100])) {
            $this->fireMilestone($tenantId, $visitorId, 'order_count', $orders);
        }
    }
}

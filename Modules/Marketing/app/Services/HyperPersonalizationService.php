<?php
declare(strict_types=1);

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * HyperPersonalizationService — Advanced marketing personalization engine.
 *
 * UC11: Weather-Triggered Campaigns — rain/snow/heat → product push
 * UC12: Payday Surge Campaigns — trigger offers based on paycheck cycles
 * UC13: Cart Abandonment Down-Selling — offer cheaper variant on abandon
 * UC14: Post-Purchase UGC Incentive — automated review/photo request
 * UC15: Back-in-Stock Micro-Targeting — notify only high-intent waitlist
 */
class HyperPersonalizationService
{
    /**
     * UC11: Generate weather-triggered campaign recommendations.
     */
    public function weatherTriggeredCampaigns(int $tenantId, array $weatherData = []): array
    {
        try {
            $temperature = $weatherData['temperature'] ?? null;
            $condition = strtolower($weatherData['condition'] ?? 'clear');
            $region = $weatherData['region'] ?? 'default';

            // Map weather conditions to product categories & messaging
            $triggers = $this->mapWeatherToTriggers($condition, $temperature);

            // Find target audience in region
            $audience = $this->getRegionalAudience($tenantId, $region);

            $campaigns = [];
            foreach ($triggers as $trigger) {
                // Find matching products
                $products = DB::connection('mongodb')
                    ->table('synced_products')
                    ->where('tenant_id', $tenantId)
                    ->where('category', 'regex', "/{$trigger['category']}/i")
                    ->where('stock_qty', '>', 0)
                    ->orderBy('conversion_rate', 'desc')
                    ->limit(6)
                    ->get();

                if ($products->isEmpty()) continue;

                $campaigns[] = [
                    'trigger_type'    => 'weather',
                    'weather_condition' => $condition,
                    'temperature'     => $temperature,
                    'region'          => $region,
                    'category'        => $trigger['category'],
                    'subject_line'    => $trigger['subject'],
                    'push_message'    => $trigger['push_message'],
                    'urgency'         => $trigger['urgency'],
                    'products'        => $products->take(4)->map(fn($p) => [
                        'id'    => $p['external_id'] ?? '',
                        'name'  => $p['name'] ?? '',
                        'price' => $p['price'] ?? 0,
                        'image' => $p['image'] ?? null,
                    ])->toArray(),
                    'audience_size'   => $audience['count'] ?? 0,
                    'recommended_channel' => $trigger['channel'],
                    'recommended_time'    => $trigger['send_time'],
                ];
            }

            return [
                'success'    => true,
                'campaigns'  => $campaigns,
                'weather'    => $weatherData,
                'created_at' => now()->toDateTimeString(),
            ];
        } catch (\Exception $e) {
            Log::error("HyperPersonalization::weatherTriggered error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC12: Detect payday cycles and trigger surge campaigns.
     */
    public function paydaySurgeCampaigns(int $tenantId): array
    {
        try {
            $dayOfMonth = (int) now()->format('d');
            $dayOfWeek = (int) now()->format('N');

            // Common payday patterns
            $isPayday = in_array($dayOfMonth, [1, 15, 25, 28, 30])
                || ($dayOfWeek === 5 && $dayOfMonth >= 1 && $dayOfMonth <= 7); // First Friday

            $isPrePayday = in_array($dayOfMonth, [14, 24, 27, 29]);
            $isPostPayday = in_array($dayOfMonth, [2, 16, 26]);

            // Identify customers with known purchase patterns
            /** @var \Traversable $highSpenders */
            $highSpenders = DB::connection('mongodb')
                ->table('synced_orders')
                ->raw(function ($collection) use ($tenantId) {
                    return $collection->aggregate([
                        ['$match' => ['tenant_id' => $tenantId]],
                        ['$group' => [
                            '_id' => '$customer_email',
                            'order_count' => ['$sum' => 1],
                            'avg_order_value' => ['$avg' => '$total'],
                            'last_order' => ['$max' => '$created_at'],
                            'purchase_days' => ['$push' => ['$dayOfMonth' => '$created_at']],
                        ]],
                        ['$match' => ['order_count' => ['$gte' => 3]]],
                        ['$sort' => ['avg_order_value' => -1]],
                        ['$limit' => 500],
                    ], ['maxTimeMS' => 30000]);
                });

            $campaigns = [];

            if ($isPayday || $isPostPayday) {
                $campaigns[] = [
                    'type'         => 'payday_surge',
                    'trigger_day'  => $dayOfMonth,
                    'subject_line' => $isPayday
                        ? '💰 Payday Treat! Exclusive deals just for you'
                        : '🎉 Weekend after payday? Time to shop!',
                    'strategy'     => $isPayday ? 'premium_products' : 'flash_deals',
                    'discount_pct' => $isPayday ? 0 : 15,
                    'target_segment' => 'high_value_repeat',
                    'audience_size'  => count(iterator_to_array($highSpenders)),
                    'recommended_products' => 'top_margin_in_stock',
                    'channels'     => ['email', 'push', 'sms'],
                    'optimal_time' => $isPayday ? '10:00 AM' : '11:00 AM',
                ];
            }

            if ($isPrePayday) {
                $campaigns[] = [
                    'type'         => 'pre_payday_tease',
                    'trigger_day'  => $dayOfMonth,
                    'subject_line' => '👀 Tomorrow is the day — preview what\'s waiting',
                    'strategy'     => 'wishlist_reminder',
                    'discount_pct' => 0,
                    'target_segment' => 'wishlist_holders',
                    'channels'     => ['email', 'push'],
                    'optimal_time' => '6:00 PM',
                ];
            }

            return [
                'success'       => true,
                'campaigns'     => $campaigns,
                'day_of_month'  => $dayOfMonth,
                'is_payday'     => $isPayday,
                'is_pre_payday' => $isPrePayday,
            ];
        } catch (\Exception $e) {
            Log::error("HyperPersonalization::paydaySurge error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC13: On cart abandon, offer cheaper alternative (down-sell).
     */
    public function cartAbandonmentDownSell(int $tenantId, array $abandonedCart): array
    {
        try {
            $downSellOffers = [];

            foreach ($abandonedCart['items'] ?? [] as $item) {
                $productId = $item['product_id'] ?? $item['sku'] ?? null;
                $price = (float) ($item['price'] ?? 0);

                if (!$productId || $price <= 0) continue;

                // Find cheaper alternatives in same category
                $category = $item['category'] ?? '';
                $alternatives = DB::connection('mongodb')
                    ->table('synced_products')
                    ->where('tenant_id', $tenantId)
                    ->where('category', $category)
                    ->where('price', '<', $price)
                    ->where('price', '>=', $price * 0.5) // At least 50% of original
                    ->where('stock_qty', '>', 0)
                    ->where('external_id', '!=', $productId)
                    ->orderBy('conversion_rate', 'desc')
                    ->limit(3)
                    ->get();

                if ($alternatives->isNotEmpty()) {
                    $bestAlt = $alternatives->first();
                    $savings = $price - ($bestAlt['price'] ?? 0);

                    $downSellOffers[] = [
                        'original_item' => [
                            'id'    => $productId,
                            'name'  => $item['name'] ?? '',
                            'price' => $price,
                        ],
                        'down_sell'     => [
                            'id'      => $bestAlt['external_id'] ?? '',
                            'name'    => $bestAlt['name'] ?? '',
                            'price'   => $bestAlt['price'] ?? 0,
                            'savings' => round($savings, 2),
                            'image'   => $bestAlt['image'] ?? null,
                        ],
                        'alternatives'  => $alternatives->take(3)->map(fn($a) => [
                            'id'    => $a['external_id'] ?? '',
                            'name'  => $a['name'] ?? '',
                            'price' => $a['price'] ?? 0,
                        ])->toArray(),
                        'message'  => "We noticed you left this behind. How about {$bestAlt['name']} for \$" . number_format($bestAlt['price'] ?? 0, 2) . "? You save \$" . number_format($savings, 2) . "!",
                    ];
                }
            }

            return [
                'success'    => true,
                'cart_id'    => $abandonedCart['cart_id'] ?? null,
                'customer'   => $abandonedCart['customer_email'] ?? null,
                'offers'     => $downSellOffers,
                'offer_count' => count($downSellOffers),
                'strategy'   => 'down_sell',
                'channel'    => 'email',
                'send_delay' => '2_hours',
            ];
        } catch (\Exception $e) {
            Log::error("HyperPersonalization::cartDownSell error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC14: Post-purchase UGC incentive — trigger review/photo request.
     */
    public function postPurchaseUgcIncentive(int $tenantId, array $order): array
    {
        try {
            $orderDate = $order['created_at'] ?? now()->toDateTimeString();
            $daysSincePurchase = now()->diffInDays($orderDate);

            // Optimal review request timing
            $optimalDelay = match (true) {
                str_contains(strtolower($order['category'] ?? ''), 'food') => 3,
                str_contains(strtolower($order['category'] ?? ''), 'electronic') => 14,
                str_contains(strtolower($order['category'] ?? ''), 'fashion') => 7,
                default => 10,
            };

            $incentives = [];
            foreach ($order['items'] ?? [] as $item) {
                $hasReview = DB::connection('mongodb')
                    ->table('events')
                    ->where('tenant_id', $tenantId)
                    ->where('event', 'review_submit')
                    ->where('customer_email', $order['customer_email'] ?? '')
                    ->where('product_id', $item['product_id'] ?? '')
                    ->exists();

                if (!$hasReview) {
                    $incentives[] = [
                        'product_id'   => $item['product_id'] ?? '',
                        'product_name' => $item['name'] ?? '',
                        'request_type' => 'review_with_photo',
                        'incentive'    => [
                            'type'   => 'discount_code',
                            'value'  => 10,
                            'unit'   => 'percent',
                            'code'   => 'REVIEW' . strtoupper(substr(md5($item['product_id'] ?? ''), 0, 6)),
                            'expiry' => now()->addDays(30)->toDateString(),
                        ],
                        'message' => "Love your {$item['name']}? Share a photo review and get 10% off your next order!",
                    ];
                }
            }

            return [
                'success'           => true,
                'order_id'          => $order['order_id'] ?? null,
                'customer'          => $order['customer_email'] ?? null,
                'days_since_purchase' => $daysSincePurchase,
                'optimal_delay_days'  => $optimalDelay,
                'ready_to_send'     => $daysSincePurchase >= $optimalDelay,
                'incentives'        => $incentives,
                'pending_reviews'   => count($incentives),
                'channel'           => 'email',
            ];
        } catch (\Exception $e) {
            Log::error("HyperPersonalization::postPurchaseUgc error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * UC15: Back-in-stock micro-targeting — notify only high-intent waitlisters.
     */
    public function backInStockMicroTarget(int $tenantId, string $productId): array
    {
        try {
            // Get product info
            $productRaw = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('external_id', $productId)
                ->first();

            $product = $productRaw ? (array) $productRaw : null;

            if (!$product || ($product['stock_qty'] ?? 0) <= 0) {
                return ['success' => false, 'error' => 'Product not found or still OOS'];
            }

            // Find high-intent users who viewed OOS product
            $interestedUsers = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->whereIn('event', ['product_view', 'wishlist_add', 'notify_me'])
                ->where('created_at', '>=', now()->subDays(30)->toDateTimeString())
                ->get()
                ->map(fn($e) => (array) $e)
                ->groupBy('visitor_id');

            $scoredUsers = [];
            foreach ($interestedUsers as $visitorId => $events) {
                $score = 0;
                foreach ($events as $e) {
                    $score += match ($e['event']) {
                        'notify_me'    => 50,
                        'wishlist_add' => 30,
                        'product_view' => 10,
                        default        => 5,
                    };
                }
                // Recency boost
                $lastEvent = collect($events)->max('created_at');
                $daysSince = now()->diffInDays($lastEvent);
                if ($daysSince <= 3) $score *= 1.5;
                elseif ($daysSince <= 7) $score *= 1.2;

                $scoredUsers[] = [
                    'visitor_id'    => $visitorId,
                    'email'         => collect($events)->pluck('customer_email')->filter()->first(),
                    'intent_score'  => round($score, 1),
                    'events_count'  => count($events),
                    'last_activity' => $lastEvent,
                ];
            }

            // Sort by intent score, take top users (limited by stock)
            usort($scoredUsers, fn($a, $b) => $b['intent_score'] <=> $a['intent_score']);
            $availableStock = $product['stock_qty'] ?? 0;
            $notifyList = array_slice($scoredUsers, 0, $availableStock);

            return [
                'success'       => true,
                'product'       => [
                    'id'    => $productId,
                    'name'  => $product['name'] ?? '',
                    'price' => $product['price'] ?? 0,
                    'stock' => $availableStock,
                ],
                'total_interested' => count($scoredUsers),
                'notify_list'      => $notifyList,
                'notify_count'     => count($notifyList),
                'strategy'         => 'high_intent_first',
                'message_template' => "Great news! {$product['name']} is back in stock. We saved one just for you!",
                'urgency_note'     => $availableStock < count($scoredUsers)
                    ? "Only {$availableStock} units — " . (count($scoredUsers) - $availableStock) . " people will miss out"
                    : null,
            ];
        } catch (\Exception $e) {
            Log::error("HyperPersonalization::backInStock error: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Private Helpers ──────────────────────────────────────────

    private function mapWeatherToTriggers(string $condition, ?float $temp): array
    {
        $triggers = [];
        if (str_contains($condition, 'rain') || str_contains($condition, 'storm')) {
            $triggers[] = [
                'category' => 'rain|umbrella|waterproof|raincoat',
                'subject'  => '🌧️ Rainy day essentials — stay dry in style',
                'push_message' => 'Rain incoming! ☔ Check out our waterproof collection',
                'urgency'  => 'high',
                'channel'  => 'push',
                'send_time' => 'immediate',
            ];
        }
        if (str_contains($condition, 'snow') || str_contains($condition, 'blizzard')) {
            $triggers[] = [
                'category' => 'winter|jacket|boots|glove|scarf|thermal',
                'subject'  => '❄️ Snow alert! Bundle up with these picks',
                'push_message' => 'Brrr! Snow is here. Winter essentials inside →',
                'urgency'  => 'high',
                'channel'  => 'push',
                'send_time' => 'immediate',
            ];
        }
        if ($temp !== null && $temp > 30) {
            $triggers[] = [
                'category' => 'summer|shorts|sunscreen|swimwear|fan|cooler',
                'subject'  => '☀️ Beat the heat — summer must-haves',
                'push_message' => "It's {$temp}°! Cool off with our summer collection",
                'urgency'  => 'medium',
                'channel'  => 'email',
                'send_time' => '8:00 AM',
            ];
        }
        if ($temp !== null && $temp < 5) {
            $triggers[] = [
                'category' => 'heating|blanket|hot chocolate|wool|thermal',
                'subject'  => '🥶 Cold snap! Cozy up with our warm picks',
                'push_message' => "Only {$temp}° outside. Time to get cozy!",
                'urgency'  => 'medium',
                'channel'  => 'email',
                'send_time' => '7:00 AM',
            ];
        }
        if (empty($triggers)) {
            $triggers[] = [
                'category' => 'bestseller|trending',
                'subject'  => '✨ Perfect weather for shopping — see what\'s trending',
                'push_message' => 'Beautiful day! Explore our trending picks',
                'urgency'  => 'low',
                'channel'  => 'email',
                'send_time' => '10:00 AM',
            ];
        }
        return $triggers;
    }

    private function getRegionalAudience(int $tenantId, string $region): array
    {
        try {
            $count = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('region', 'regex', "/{$region}/i")
                ->distinct('visitor_id')
                ->count();
            return ['count' => $count, 'region' => $region];
        } catch (\Exception $e) {
            return ['count' => 0, 'region' => $region];
        }
    }
}

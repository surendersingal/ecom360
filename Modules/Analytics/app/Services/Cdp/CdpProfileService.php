<?php

declare(strict_types=1);

namespace Modules\Analytics\Services\Cdp;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Analytics\Models\CdpProfile;
use Modules\Analytics\Models\CustomerProfile;
use Modules\DataSync\Models\SyncedCustomer;
use Modules\DataSync\Models\SyncedOrder;

/**
 * Builds & updates unified CDP profiles by reading from all 6 module collections.
 *
 * This is the CORE CDP service — it creates the "Golden Record" for each customer.
 * Call buildAllProfiles() to rebuild all profiles, or buildProfile() for a single customer.
 */
final class CdpProfileService
{
    /**
     * Build or refresh all CDP profiles for a tenant.
     * Steps: 1) get all unique emails, 2) build each profile.
     *
     * @return array{built: int, errors: int, duration_ms: int}
     */
    public function buildAllProfiles(string $tenantId): array
    {
        $start  = microtime(true);
        $built  = 0;
        $errors = 0;

        // Collect unique customer emails from synced_customers + synced_orders
        $emails = collect();

        // From synced_customers
        $custEmails = SyncedCustomer::where('tenant_id', $tenantId)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email')
            ->map(fn($e) => strtolower(trim($e)));
        $emails = $emails->merge($custEmails);

        // From synced_orders (includes guest orders)
        $orderEmails = SyncedOrder::where('tenant_id', $tenantId)
            ->whereNotNull('customer_email')
            ->where('customer_email', '!=', '')
            ->distinct('customer_email')
            ->pluck('customer_email')
            ->map(fn($e) => strtolower(trim($e)));
        $emails = $emails->merge($orderEmails);

        // From customer_profiles (identity-resolved sessions)
        $profileEmails = CustomerProfile::where('tenant_id', $tenantId)
            ->where('identifier_type', 'email')
            ->pluck('identifier_value')
            ->map(fn($e) => strtolower(trim($e)));
        $emails = $emails->merge($profileEmails);

        $emails = $emails->unique()->filter()->values();

        foreach ($emails as $email) {
            try {
                $this->buildProfile($tenantId, $email);
                $built++;
            } catch (\Throwable $e) {
                $errors++;
                Log::warning("CDP profile build failed for {$email}: " . $e->getMessage());
            }
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);

        return [
            'built'       => $built,
            'errors'      => $errors,
            'total_emails' => $emails->count(),
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Build or update one CDP profile for a single customer email.
     */
    public function buildProfile(string $tenantId, string $email): CdpProfile
    {
        $email = strtolower(trim($email));

        // Find or create
        $profile = CdpProfile::firstOrNew([
            'tenant_id' => $tenantId,
            'email'     => $email,
        ]);

        if (! $profile->cdp_uuid) {
            $profile->cdp_uuid = (string) Str::uuid();
        }

        // Build each layer
        $profile->identity      = $this->buildIdentityLayer($tenantId, $email);
        $profile->demographics  = $this->buildDemographicLayer($tenantId, $email);
        $profile->transactional = $this->buildTransactionalLayer($tenantId, $email);
        $profile->behavioural   = $this->buildBehaviouralLayer($tenantId, $email);
        $profile->search        = $this->buildSearchLayer($tenantId, $email);
        // engagement + chatbot layers are populated by write-back from those modules
        if (! $profile->engagement) {
            $profile->engagement = $this->defaultEngagement();
        }
        if (! $profile->chatbot) {
            $profile->chatbot = $this->defaultChatbot();
        }

        // Merge known_sessions from customer_profiles
        $profile->known_sessions = $this->collectKnownSessions($tenantId, $email);

        // Set external IDs
        $customer = SyncedCustomer::where('tenant_id', $tenantId)
            ->where('email', $email)->first();
        if ($customer) {
            $profile->magento_customer_id = $customer->external_id;
            $profile->phone = $customer->attributes['phone'] ?? $customer->attributes['telephone'] ?? null;
        }

        // Compute derived properties (RFM, churn, propensity — in CdpComputedPropsService)
        // Profile completeness
        $profile->profile_completeness = $this->computeCompleteness($profile);
        $profile->data_quality_flags   = $this->detectDataQualityIssues($tenantId, $profile);
        $profile->last_computed_at     = Carbon::now();

        $profile->save();

        return $profile;
    }

    /**
     * Get a single profile with all data.
     */
    public function getProfile(string $tenantId, string $profileId): ?CdpProfile
    {
        return CdpProfile::where('tenant_id', $tenantId)->find($profileId);
    }

    /**
     * Get paginated profiles for listing.
     */
    public function listProfiles(string $tenantId, int $page = 1, int $perPage = 25, array $filters = []): array
    {
        $query = CdpProfile::forTenant($tenantId);

        // Apply filters
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('email', 'like', "%{$s}%")
                  ->orWhere('demographics.firstname', 'like', "%{$s}%")
                  ->orWhere('demographics.lastname', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }
        if (! empty($filters['rfm_segment'])) {
            $query->rfmSegment($filters['rfm_segment']);
        }
        if (! empty($filters['churn_risk'])) {
            $query->churnRisk($filters['churn_risk']);
        }
        if (! empty($filters['min_ltv'])) {
            $query->where('transactional.lifetime_revenue', '>=', (float) $filters['min_ltv']);
        }
        if (! empty($filters['city'])) {
            $query->where('demographics.city', $filters['city']);
        }

        $total = $query->count();
        $profiles = $query->orderByDesc('transactional.lifetime_revenue')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return [
            'profiles'     => $profiles,
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Get CDP dashboard summary stats.
     */
    public function getDashboardStats(string $tenantId): array
    {
        $query = CdpProfile::forTenant($tenantId);

        $total = $query->count();
        $withOrders = (clone $query)->where('transactional.total_orders', '>', 0)->count();
        $avgCompleteness = (clone $query)->avg('profile_completeness') ?? 0;

        // RFM segment distribution
        $rfmDistribution = DB::connection('mongodb')
            ->table('cdp_profiles')
            ->raw(function ($collection) use ($tenantId) {
                return $collection->aggregate([
                    ['$match' => ['tenant_id' => $tenantId, 'computed.rfm_segment' => ['$exists' => true]]],
                    ['$group' => [
                        '_id'   => '$computed.rfm_segment',
                        'count' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['count' => -1]],
                ], ['maxTimeMS' => 30000]);
            });

        // Revenue summary
        $revenueSummary = DB::connection('mongodb')
            ->table('cdp_profiles')
            ->raw(function ($collection) use ($tenantId) {
                return $collection->aggregate([
                    ['$match' => ['tenant_id' => $tenantId]],
                    ['$group' => [
                        '_id'          => null,
                        'total_ltv'    => ['$sum' => '$transactional.lifetime_revenue'],
                        'avg_ltv'      => ['$avg' => '$transactional.lifetime_revenue'],
                        'avg_orders'   => ['$avg' => '$transactional.total_orders'],
                        'avg_aov'      => ['$avg' => '$transactional.avg_order_value'],
                    ]],
                ], ['maxTimeMS' => 30000]);
            });

        $rev = iterator_to_array($revenueSummary);
        $rev = $rev[0] ?? ['total_ltv' => 0, 'avg_ltv' => 0, 'avg_orders' => 0, 'avg_aov' => 0];

        // Churn risk distribution
        $churnDistribution = DB::connection('mongodb')
            ->table('cdp_profiles')
            ->raw(function ($collection) use ($tenantId) {
                return $collection->aggregate([
                    ['$match' => ['tenant_id' => $tenantId, 'computed.churn_risk_level' => ['$exists' => true]]],
                    ['$group' => [
                        '_id'   => '$computed.churn_risk_level',
                        'count' => ['$sum' => 1],
                    ]],
                ], ['maxTimeMS' => 30000]);
            });

        // Data quality summary
        $qualityIssues = (clone $query)->where('data_quality_flags', '!=', [])->count();

        return [
            'total_profiles'      => $total,
            'profiles_with_orders' => $withOrders,
            'anonymous_profiles'  => $total - $withOrders,
            'avg_completeness'    => round($avgCompleteness, 1),
            'total_ltv'           => round((float) ($rev['total_ltv'] ?? 0), 2),
            'avg_ltv'             => round((float) ($rev['avg_ltv'] ?? 0), 2),
            'avg_orders'          => round((float) ($rev['avg_orders'] ?? 0), 1),
            'avg_aov'             => round((float) ($rev['avg_aov'] ?? 0), 2),
            'rfm_distribution'    => collect(iterator_to_array($rfmDistribution))->map(fn($r) => [
                'segment' => $r['_id'] ?? 'Unknown',
                'count'   => $r['count'] ?? 0,
            ])->toArray(),
            'churn_distribution'  => collect(iterator_to_array($churnDistribution))->map(fn($r) => [
                'level' => $r['_id'] ?? 'Unknown',
                'count' => $r['count'] ?? 0,
            ])->toArray(),
            'quality_issues'      => $qualityIssues,
        ];
    }

    /**
     * Get customer event timeline for a single profile.
     */
    public function getTimeline(string $tenantId, string $email, int $limit = 50): array
    {
        $events = DB::connection('mongodb')
            ->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($email) {
                $q->where('metadata.customer_email', $email)
                  ->orWhere('custom_data.email', $email);
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get(['event_type', 'url', 'metadata', 'custom_data', 'session_id', 'created_at']);

        // Also get orders
        $orders = SyncedOrder::where('tenant_id', $tenantId)
            ->where('customer_email', $email)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['order_number', 'grand_total', 'status', 'items', 'created_at']);

        // Merge into unified timeline
        $timeline = collect();

        foreach ($events as $e) {
            $timeline->push([
                'type'      => $this->eventIcon($e['event_type'] ?? ''),
                'event'     => $e['event_type'] ?? 'unknown',
                'detail'    => $this->eventDetail($e),
                'timestamp' => $e['created_at'] ?? null,
                'source'    => 'analytics',
            ]);
        }

        foreach ($orders as $o) {
            $itemCount = is_array($o['items'] ?? null) ? count($o['items']) : 0;
            $timeline->push([
                'type'      => '📦',
                'event'     => 'order_placed',
                'detail'    => "Order #{$o['order_number']} — ₹" . number_format($o['grand_total'], 0) . " ({$itemCount} items) — {$o['status']}",
                'timestamp' => $o['created_at'] ?? null,
                'source'    => 'datasync',
            ]);
        }

        return $timeline->sortByDesc('timestamp')->values()->take($limit)->toArray();
    }

    /* ══════════════════════════════════════════════════════════════
     *  LAYER BUILDERS — Each builds one section of the Golden Record
     * ══════════════════════════════════════════════════════════════ */

    private function buildIdentityLayer(string $tenantId, string $email): array
    {
        $profile = CustomerProfile::where('tenant_id', $tenantId)
            ->where('identifier_type', 'email')
            ->where('identifier_value', $email)
            ->first();

        $customer = SyncedCustomer::where('tenant_id', $tenantId)
            ->where('email', $email)->first();

        return [
            'email'              => $email,
            'phone'              => $customer?->attributes['phone'] ?? $customer?->attributes['telephone'] ?? null,
            'magento_id'         => $customer?->external_id,
            'platform'           => $customer?->platform ?? 'magento',
            'sessions_linked'    => count($profile?->known_sessions ?? []),
            'fingerprints_linked' => count($profile?->device_fingerprints ?? []),
            'identity_confidence' => $customer ? 'high' : 'medium',
        ];
    }

    private function buildDemographicLayer(string $tenantId, string $email): array
    {
        $customer = SyncedCustomer::where('tenant_id', $tenantId)
            ->where('email', $email)->first();

        if (! $customer) {
            return [
                'firstname'       => null,
                'lastname'        => null,
                'city'            => null,
                'state'           => null,
                'gender'          => null,
                'dob'             => null,
                'customer_group'  => null,
                'account_created' => null,
            ];
        }

        // Try to get city/state from most recent order's shipping address
        $lastOrder = SyncedOrder::where('tenant_id', $tenantId)
            ->where('customer_email', $email)
            ->whereNotNull('shipping_address')
            ->orderByDesc('created_at')
            ->first();

        $addr = $lastOrder?->shipping_address ?? [];

        $groupMap = [1 => 'General', 2 => 'Wholesale', 3 => 'Retailer', 4 => 'VIP'];

        return [
            'firstname'       => $customer->firstname ?? $customer->name,
            'lastname'        => $customer->lastname,
            'city'            => $addr['city'] ?? null,
            'state'           => $addr['region'] ?? $addr['state'] ?? null,
            'pincode'         => $addr['postcode'] ?? null,
            'gender'          => match ($customer->gender) { 1 => 'Male', 2 => 'Female', default => null },
            'dob'             => $customer->dob,
            'customer_group'  => $groupMap[$customer->group_id ?? 0] ?? 'General',
            'account_created' => $customer->created_at?->toDateString(),
        ];
    }

    private function buildTransactionalLayer(string $tenantId, string $email): array
    {
        $orders = SyncedOrder::where('tenant_id', $tenantId)
            ->where('customer_email', $email)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($orders->isEmpty()) {
            return [
                'total_orders'         => 0,
                'lifetime_revenue'     => 0,
                'avg_order_value'      => 0,
                'last_order_date'      => null,
                'first_order_date'     => null,
                'days_since_last_order' => null,
                'days_since_first_order' => null,
                'avg_days_between_orders' => null,
                'favourite_category'   => null,
                'favourite_brand'      => null,
                'preferred_payment'    => null,
                'has_used_coupon'      => false,
                'coupon_usage_rate'    => 0,
                'categories'           => [],
                'brands'               => [],
            ];
        }

        $totalRevenue = $orders->sum('grand_total');
        $totalOrders  = $orders->count();
        $firstOrder   = $orders->first();
        $lastOrder    = $orders->last();

        // Category and brand frequency
        $categories = collect();
        $brands     = collect();
        $payments   = collect();
        $couponsUsed = 0;

        foreach ($orders as $order) {
            if ($order->coupon_code) {
                $couponsUsed++;
            }
            if ($order->payment_method) {
                $payments->push($order->payment_method);
            }
            foreach ($order->items ?? [] as $item) {
                if (! empty($item['category'])) {
                    $categories->push($item['category']);
                }
                if (! empty($item['brand'])) {
                    $brands->push($item['brand']);
                }
            }
        }

        // Days between orders
        $daysBetween = null;
        if ($totalOrders > 1) {
            $orderDates = $orders->map(fn($o) => Carbon::parse($o->created_at));
            $gaps = [];
            for ($i = 1; $i < $orderDates->count(); $i++) {
                $gaps[] = $orderDates[$i]->diffInDays($orderDates[$i - 1]);
            }
            $daysBetween = count($gaps) > 0 ? round(array_sum($gaps) / count($gaps), 1) : null;
        }

        return [
            'total_orders'         => $totalOrders,
            'lifetime_revenue'     => round($totalRevenue, 2),
            'avg_order_value'      => round($totalRevenue / max($totalOrders, 1), 2),
            'last_order_date'      => $lastOrder->created_at?->toDateString(),
            'first_order_date'     => $firstOrder->created_at?->toDateString(),
            'days_since_last_order'  => $lastOrder->created_at ? Carbon::now()->diffInDays($lastOrder->created_at) : null,
            'days_since_first_order' => $firstOrder->created_at ? Carbon::now()->diffInDays($firstOrder->created_at) : null,
            'avg_days_between_orders' => $daysBetween,
            'favourite_category'   => $categories->countBy()->sortDesc()->keys()->first(),
            'favourite_brand'      => $brands->countBy()->sortDesc()->keys()->first(),
            'preferred_payment'    => $payments->countBy()->sortDesc()->keys()->first(),
            'has_used_coupon'      => $couponsUsed > 0,
            'coupon_usage_rate'    => round(($couponsUsed / max($totalOrders, 1)) * 100, 1),
            'categories'           => $categories->countBy()->sortDesc()->take(5)->toArray(),
            'brands'               => $brands->countBy()->sortDesc()->take(5)->toArray(),
        ];
    }

    private function buildBehaviouralLayer(string $tenantId, string $email): array
    {
        // Get sessions from customer_profiles
        $profile = CustomerProfile::where('tenant_id', $tenantId)
            ->where('identifier_type', 'email')
            ->where('identifier_value', $email)
            ->first();

        $sessions = $profile?->known_sessions ?? [];
        $totalSessions = count($sessions);

        if ($totalSessions === 0) {
            return [
                'total_sessions'      => 0,
                'sessions_30d'        => 0,
                'avg_pages_per_session' => 0,
                'primary_device'      => null,
                'peak_browse_hour'    => null,
                'last_seen'           => null,
                'days_since_last_seen' => null,
            ];
        }

        // Get aggregate stats from tracking_events
        $stats = DB::connection('mongodb')
            ->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $sessions) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'session_id' => ['$in' => array_values($sessions)],
                    ]],
                    ['$group' => [
                        '_id'          => null,
                        'total_events' => ['$sum' => 1],
                        'last_seen'    => ['$max' => '$created_at'],
                        'devices'      => ['$addToSet' => '$metadata.device_type'],
                    ]],
                ], ['maxTimeMS' => 30000]);
            });

        $agg = iterator_to_array($stats);
        $agg = $agg[0] ?? ['total_events' => 0, 'last_seen' => null, 'devices' => []];

        // Sessions in last 30 days
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $sessions30d = DB::connection('mongodb')
            ->table('tracking_events')
            ->where('tenant_id', $tenantId)
            ->whereIn('session_id', $sessions)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->distinct('session_id')
            ->count();

        // Peak browse hour
        $hourData = DB::connection('mongodb')
            ->table('tracking_events')
            ->raw(function ($collection) use ($tenantId, $sessions) {
                return $collection->aggregate([
                    ['$match' => [
                        'tenant_id'  => $tenantId,
                        'session_id' => ['$in' => array_values($sessions)],
                    ]],
                    ['$group' => [
                        '_id'   => ['$hour' => ['date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]],
                        'count' => ['$sum' => 1],
                    ]],
                    ['$sort' => ['count' => -1]],
                    ['$limit' => 1],
                ], ['maxTimeMS' => 30000]);
            });
        $peakHour = iterator_to_array($hourData);
        $peakHour = $peakHour[0]['_id'] ?? null;

        $lastSeen = $agg['last_seen'] ?? null;

        return [
            'total_sessions'        => $totalSessions,
            'sessions_30d'          => $sessions30d,
            'avg_pages_per_session' => $totalSessions > 0 ? round(($agg['total_events'] ?? 0) / $totalSessions, 1) : 0,
            'primary_device'        => collect($agg['devices'] ?? [])->first() ?? 'unknown',
            'peak_browse_hour'      => $peakHour !== null ? sprintf('%d:00', $peakHour) : null,
            'last_seen'             => $lastSeen,
            'days_since_last_seen'  => $lastSeen ? Carbon::parse($lastSeen)->diffInDays(Carbon::now()) : null,
        ];
    }

    private function buildSearchLayer(string $tenantId, string $email): array
    {
        try {
            $searches = DB::connection('mongodb')
                ->table('search_logs')
                ->where('tenant_id', $tenantId)
                ->where('customer_email', $email)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['query', 'results_count', 'created_at']);

            if ($searches->isEmpty()) {
                return ['total_searches' => 0, 'top_searches' => [], 'last_search' => null, 'zero_result_searches' => 0];
            }

            $zeroResults = $searches->where('results_count', 0)->count();
            $topSearches = $searches->pluck('query')->countBy()->sortDesc()->take(5)->keys()->toArray();

            return [
                'total_searches'       => $searches->count(),
                'top_searches'         => $topSearches,
                'last_search'          => $searches->first()['query'] ?? null,
                'last_search_date'     => $searches->first()['created_at'] ?? null,
                'zero_result_searches' => $zeroResults,
            ];
        } catch (\Throwable) {
            return ['total_searches' => 0, 'top_searches' => [], 'last_search' => null, 'zero_result_searches' => 0];
        }
    }

    private function collectKnownSessions(string $tenantId, string $email): array
    {
        $profile = CustomerProfile::where('tenant_id', $tenantId)
            ->where('identifier_type', 'email')
            ->where('identifier_value', $email)
            ->first();

        return $profile?->known_sessions ?? [];
    }

    /* ══════════════════════════════════════════
     *  PROFILE COMPLETENESS & DATA QUALITY
     * ══════════════════════════════════════════ */

    private function computeCompleteness(CdpProfile $profile): int
    {
        $score = 0;
        $total = 0;

        // Identity (20 points)
        $total += 20;
        if ($profile->email) $score += 10;
        if ($profile->phone) $score += 5;
        if ($profile->magento_customer_id) $score += 5;

        // Demographics (20 points)
        $d = $profile->demographics ?? [];
        $total += 20;
        if (! empty($d['firstname'])) $score += 4;
        if (! empty($d['city'])) $score += 4;
        if (! empty($d['state'])) $score += 3;
        if (! empty($d['gender'])) $score += 3;
        if (! empty($d['dob'])) $score += 3;
        if (! empty($d['customer_group'])) $score += 3;

        // Transactional (25 points)
        $t = $profile->transactional ?? [];
        $total += 25;
        if (($t['total_orders'] ?? 0) > 0) $score += 15;
        if (! empty($t['favourite_category'])) $score += 5;
        if (! empty($t['preferred_payment'])) $score += 5;

        // Behavioural (20 points)
        $b = $profile->behavioural ?? [];
        $total += 20;
        if (($b['total_sessions'] ?? 0) > 0) $score += 10;
        if (! empty($b['primary_device'])) $score += 5;
        if (! empty($b['peak_browse_hour'])) $score += 5;

        // Engagement (15 points)
        $e = $profile->engagement ?? [];
        $total += 15;
        if (isset($e['email_subscribed'])) $score += 5;
        if (($e['emails_received'] ?? 0) > 0) $score += 5;
        if (($e['email_open_rate'] ?? 0) > 0) $score += 5;

        return $total > 0 ? (int) round(($score / $total) * 100) : 0;
    }

    private function detectDataQualityIssues(string $tenantId, CdpProfile $profile): array
    {
        $flags = [];

        $d = $profile->demographics ?? [];
        if (empty($d['dob'])) {
            $flags[] = ['type' => 'missing', 'field' => 'date_of_birth', 'impact' => 'Cannot trigger birthday automation'];
        }
        if (empty($d['gender'])) {
            $flags[] = ['type' => 'missing', 'field' => 'gender', 'impact' => 'Cannot use gender-based segments'];
        }
        if (empty($d['city'])) {
            $flags[] = ['type' => 'missing', 'field' => 'city', 'impact' => 'Cannot use location-based targeting'];
        }

        // Check for duplicate phone
        if ($profile->phone) {
            $dupeCount = CdpProfile::forTenant($tenantId)
                ->where('phone', $profile->phone)
                ->where('email', '!=', $profile->email)
                ->count();
            if ($dupeCount > 0) {
                $flags[] = ['type' => 'duplicate', 'field' => 'phone', 'impact' => "{$dupeCount} other profiles with same phone"];
            }
        }

        // High-intent anonymous: sessions but no orders
        $b = $profile->behavioural ?? [];
        $t = $profile->transactional ?? [];
        if (($b['total_sessions'] ?? 0) >= 3 && ($t['total_orders'] ?? 0) === 0) {
            $flags[] = ['type' => 'opportunity', 'field' => 'conversion', 'impact' => 'High-intent browser with no purchases'];
        }

        return $flags;
    }

    private function defaultEngagement(): array
    {
        return [
            'email_subscribed'   => true,
            'sms_subscribed'     => false,
            'emails_received'    => 0,
            'emails_opened'      => 0,
            'emails_clicked'     => 0,
            'email_open_rate'    => 0,
            'email_click_rate'   => 0,
            'last_campaign_name' => null,
            'last_engaged_date'  => null,
            'days_since_last_engaged' => null,
        ];
    }

    private function defaultChatbot(): array
    {
        return [
            'total_chats'     => 0,
            'last_topic'      => null,
            'last_chat_date'  => null,
            'avg_sentiment'   => null,
        ];
    }

    private function eventIcon(string $eventType): string
    {
        return match ($eventType) {
            'page_view'      => '👁️',
            'product_view'   => '👁️',
            'add_to_cart'    => '🛒',
            'begin_checkout' => '💳',
            'purchase'       => '📦',
            'search', 'search_event', 'ai_search_executed' => '🔍',
            'email_open'     => '📧',
            'email_click'    => '📧',
            'chat_event'     => '💬',
            default          => '📌',
        };
    }

    private function eventDetail(mixed $event): string
    {
        $type = $event['event_type'] ?? '';
        $meta = $event['metadata'] ?? [];
        $url  = $event['url'] ?? '';

        return match ($type) {
            'page_view'      => "Viewed page — {$url}",
            'product_view'   => 'Viewed product — ' . ($meta['product_name'] ?? $url),
            'add_to_cart'    => 'Added to cart — ' . ($meta['product_name'] ?? 'item'),
            'begin_checkout' => 'Started checkout — ₹' . number_format((float) ($meta['order_total'] ?? 0)),
            'purchase'       => 'Purchased — ₹' . number_format((float) ($meta['order_total'] ?? 0)),
            'search', 'search_event' => 'Searched "' . ($meta['query'] ?? $meta['search_query'] ?? '?') . '"',
            default          => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}

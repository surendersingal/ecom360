<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Unified CDP Customer Profile — the "Golden Record".
 *
 * Connection: mongodb | Collection: cdp_profiles
 *
 * Aggregates data from ALL modules:
 *   - synced_customers  (DataSync)  → identity, demographics
 *   - synced_orders     (DataSync)  → transactional metrics
 *   - tracking_events   (Analytics) → behavioural data
 *   - customer_profiles (Analytics) → identity resolution, sessions
 *   - search_logs       (AiSearch)  → search behaviour
 *   - chatbot events    (Chatbot)   → conversation data
 *
 * Each document is uniquely identified by (tenant_id + email).
 *
 * @property string      $tenant_id
 * @property string      $email                Primary identifier
 * @property string|null $phone
 * @property string|null $magento_customer_id   External platform customer ID
 * @property string|null $cdp_uuid              Internal cross-device UUID
 * @property array       $identity              Identity resolution data
 * @property array       $demographics          Name, city, state, gender, DOB, group, account_created
 * @property array       $transactional         Orders, LTV, AOV, last/first order, frequency, fav category/brand
 * @property array       $behavioural           Sessions, pages/session, device %, peak browse time, last_seen
 * @property array       $engagement            Email/SMS status, open/click rates, last campaign
 * @property array       $search                Top searches, last search, zero-result searches
 * @property array       $chatbot               Total chats, last topic, sentiment
 * @property array       $computed              RFM, CLV, churn_risk, purchase_propensity, discount_sensitivity, etc.
 * @property array       $predictions           pLTV, churn_30d/60d/90d, next_order_date, next_best_products
 * @property int         $profile_completeness  0–100 score
 * @property array       $data_quality_flags    Issues found (missing DOB, duplicate phone, etc.)
 * @property array       $known_sessions        All session IDs linked to this customer
 * @property array       $merged_profiles       Profile IDs that were merged into this one
 * @property \DateTime   $last_computed_at       When computed properties were last refreshed
 * @property \DateTime   $created_at
 * @property \DateTime   $updated_at
 */
final class CdpProfile extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'cdp_profiles';

    protected $fillable = [
        'tenant_id',
        'email',
        'phone',
        'magento_customer_id',
        'cdp_uuid',
        'identity',
        'demographics',
        'transactional',
        'behavioural',
        'engagement',
        'search',
        'chatbot',
        'computed',
        'predictions',
        'profile_completeness',
        'data_quality_flags',
        'known_sessions',
        'merged_profiles',
        'last_computed_at',
    ];

    protected function casts(): array
    {
        return [
            'identity'              => 'array',
            'demographics'          => 'array',
            'transactional'         => 'array',
            'behavioural'           => 'array',
            'engagement'            => 'array',
            'search'                => 'array',
            'chatbot'               => 'array',
            'computed'              => 'array',
            'predictions'           => 'array',
            'profile_completeness'  => 'integer',
            'data_quality_flags'    => 'array',
            'merged_profiles'       => 'array',
            'last_computed_at'      => 'datetime',
        ];
    }

    /* ── Scopes ─────────────────────────────────────────────── */

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeRfmSegment($query, string $segment)
    {
        return $query->where('computed.rfm_segment', $segment);
    }

    public function scopeChurnRisk($query, string $level)
    {
        return $query->where('computed.churn_risk_level', $level);
    }

    /* ── Helpers ─────────────────────────────────────────────── */

    public function getFullNameAttribute(): string
    {
        $d = $this->demographics ?? [];
        return trim(($d['firstname'] ?? '') . ' ' . ($d['lastname'] ?? '')) ?: $this->email;
    }

    public function getRfmSegmentAttribute(): string
    {
        return $this->computed['rfm_segment'] ?? 'Unknown';
    }

    public function getLifetimeRevenueAttribute(): float
    {
        return (float) ($this->transactional['lifetime_revenue'] ?? 0);
    }
}

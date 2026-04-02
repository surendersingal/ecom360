<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * CDP Segment definition stored in MongoDB.
 *
 * Connection: mongodb | Collection: cdp_segments
 *
 * Defines a customer segment using a set of filter conditions.
 * Segments can be dynamic (auto-refreshes) or static (snapshot).
 *
 * @property string      $tenant_id
 * @property string      $name               Segment display name
 * @property string      $description        Human-readable description
 * @property string      $type               'dynamic' | 'static' | 'rfm' | 'predictive'
 * @property array       $conditions         Filter conditions (AND/OR groups)
 * @property int         $member_count        Current member count
 * @property string      $refresh_frequency  'realtime' | 'daily' | 'manual'
 * @property bool        $is_active
 * @property bool        $synced_to_marketing Whether exported to Marketing module
 * @property string|null $marketing_audience_id  ID in Marketing module
 * @property array       $member_trend        Historical member counts [{date, count}]
 * @property \DateTime   $last_evaluated_at
 * @property \DateTime   $created_at
 * @property \DateTime   $updated_at
 *
 * CONDITIONS SCHEMA:
 * [
 *   {
 *     "group": "and",
 *     "rules": [
 *       {"dimension": "transactional", "field": "lifetime_revenue", "operator": ">", "value": 10000},
 *       {"dimension": "transactional", "field": "days_since_last_order", "operator": ">", "value": 60},
 *       {"dimension": "computed", "field": "rfm_segment", "operator": "==", "value": "At Risk"}
 *     ]
 *   }
 * ]
 *
 * DIMENSION MAP (field sources):
 *   transactional  → cdp_profiles.transactional.*
 *   demographic    → cdp_profiles.demographics.*
 *   behavioural    → cdp_profiles.behavioural.*
 *   engagement     → cdp_profiles.engagement.*
 *   search         → cdp_profiles.search.*
 *   chatbot        → cdp_profiles.chatbot.*
 *   computed       → cdp_profiles.computed.*
 *   product        → cdp_profiles.transactional.categories / brands
 */
final class CdpSegment extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'cdp_segments';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'type',
        'conditions',
        'member_count',
        'refresh_frequency',
        'is_active',
        'synced_to_marketing',
        'marketing_audience_id',
        'member_trend',
        'last_evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'conditions'           => 'array',
            'member_count'         => 'integer',
            'is_active'            => 'boolean',
            'synced_to_marketing'  => 'boolean',
            'member_trend'         => 'array',
            'last_evaluated_at'    => 'datetime',
        ];
    }

    /* ── Scopes ────────────────────────────────── */

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /* ── Helpers ────────────────────────────────── */

    /**
     * Available operators for segment conditions.
     */
    public static function operators(): array
    {
        return [
            '=='           => 'equals',
            '!='           => 'not equals',
            '>'            => 'greater than',
            '>='           => 'greater or equal',
            '<'            => 'less than',
            '<='           => 'less or equal',
            'contains'     => 'contains',
            'not_contains' => 'does not contain',
            'starts_with'  => 'starts with',
            'in'           => 'is one of',
            'not_in'       => 'is not one of',
            'exists'       => 'has value',
            'not_exists'   => 'has no value',
            'between'      => 'is between',
            'days_ago'     => 'was X days ago',
            'days_within'  => 'within last X days',
        ];
    }

    /**
     * Available dimensions and their fields for the segment builder UI.
     */
    public static function dimensions(): array
    {
        return [
            'transactional' => [
                'label'  => 'Transactional',
                'fields' => [
                    'total_orders'           => ['label' => 'Total Orders', 'type' => 'number'],
                    'lifetime_revenue'       => ['label' => 'Lifetime Revenue (₹)', 'type' => 'number'],
                    'avg_order_value'        => ['label' => 'Average Order Value', 'type' => 'number'],
                    'days_since_last_order'  => ['label' => 'Days Since Last Order', 'type' => 'number'],
                    'days_since_first_order' => ['label' => 'Days Since First Order', 'type' => 'number'],
                    'avg_days_between_orders' => ['label' => 'Avg Days Between Orders', 'type' => 'number'],
                    'favourite_category'     => ['label' => 'Favourite Category', 'type' => 'string'],
                    'favourite_brand'        => ['label' => 'Favourite Brand', 'type' => 'string'],
                    'preferred_payment'      => ['label' => 'Preferred Payment', 'type' => 'string'],
                    'has_used_coupon'        => ['label' => 'Has Used Coupon', 'type' => 'boolean'],
                    'coupon_usage_rate'      => ['label' => 'Coupon Usage Rate (%)', 'type' => 'number'],
                ],
            ],
            'demographic' => [
                'label'  => 'Demographic',
                'fields' => [
                    'city'           => ['label' => 'City', 'type' => 'string'],
                    'state'          => ['label' => 'State', 'type' => 'string'],
                    'gender'         => ['label' => 'Gender', 'type' => 'string'],
                    'customer_group' => ['label' => 'Customer Group', 'type' => 'string'],
                    'account_age_days' => ['label' => 'Account Age (days)', 'type' => 'number'],
                ],
            ],
            'behavioural' => [
                'label'  => 'Behavioural',
                'fields' => [
                    'total_sessions'      => ['label' => 'Total Sessions', 'type' => 'number'],
                    'sessions_30d'        => ['label' => 'Sessions (last 30d)', 'type' => 'number'],
                    'avg_pages_per_session' => ['label' => 'Avg Pages/Session', 'type' => 'number'],
                    'primary_device'       => ['label' => 'Primary Device', 'type' => 'string'],
                    'peak_browse_hour'     => ['label' => 'Peak Browse Hour', 'type' => 'number'],
                    'days_since_last_seen' => ['label' => 'Days Since Last Seen', 'type' => 'number'],
                ],
            ],
            'engagement' => [
                'label'  => 'Engagement',
                'fields' => [
                    'email_subscribed'   => ['label' => 'Email Subscribed', 'type' => 'boolean'],
                    'email_open_rate'    => ['label' => 'Email Open Rate (%)', 'type' => 'number'],
                    'email_click_rate'   => ['label' => 'Email Click Rate (%)', 'type' => 'number'],
                    'sms_subscribed'     => ['label' => 'SMS Subscribed', 'type' => 'boolean'],
                    'days_since_last_engaged' => ['label' => 'Days Since Last Engagement', 'type' => 'number'],
                ],
            ],
            'search' => [
                'label'  => 'Search',
                'fields' => [
                    'total_searches'       => ['label' => 'Total Searches', 'type' => 'number'],
                    'zero_result_searches' => ['label' => 'Zero-Result Searches', 'type' => 'number'],
                    'searched_keyword'     => ['label' => 'Searched For', 'type' => 'string'],
                ],
            ],
            'computed' => [
                'label'  => 'CDP Computed',
                'fields' => [
                    'rfm_segment'           => ['label' => 'RFM Segment', 'type' => 'string'],
                    'rfm_score'             => ['label' => 'RFM Score (3-digit)', 'type' => 'string'],
                    'churn_risk_level'      => ['label' => 'Churn Risk', 'type' => 'string'],
                    'churn_risk_score'      => ['label' => 'Churn Risk Score (0-1)', 'type' => 'number'],
                    'purchase_propensity'   => ['label' => 'Purchase Propensity (0-1)', 'type' => 'number'],
                    'discount_sensitivity'  => ['label' => 'Discount Sensitivity', 'type' => 'string'],
                    'predicted_ltv_12m'     => ['label' => 'Predicted LTV (12m)', 'type' => 'number'],
                    'preferred_channel'     => ['label' => 'Preferred Channel', 'type' => 'string'],
                ],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Resolved customer profile linking anonymous sessions to a known identity.
 *
 * Connection: mongodb | Collection: customer_profiles
 *
 * A single profile is uniquely identified by (tenant_id + identifier_value).
 * The known_sessions array accumulates every session_id this customer has
 * ever used, enabling full cross-session journey reconstruction.
 *
 * @property string      $tenant_id
 * @property string      $identifier_type   'email' | 'phone'
 * @property string      $identifier_value  e.g. 'john@example.com', '+14155551234'
 * @property list<string> $known_sessions     Session IDs linked to this customer.
 * @property list<string> $device_fingerprints Device fingerprint hashes linked to this customer.
 * @property array       $custom_attributes   Arbitrary data: loyalty points, preferences, etc.
 * @property string|null $rfm_score           Three-digit RFM score, e.g. '555' (VIP) or '111' (churned).
 * @property array|null  $rfm_details         Breakdown: recency_days, frequency, monetary, scored_at.
 * @property \DateTime   $created_at
 * @property \DateTime   $updated_at
 */
final class CustomerProfile extends Model
{
    /** @var string */
    protected $connection = 'mongodb';

    /** @var string */
    protected $collection = 'customer_profiles';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'identifier_type',
        'identifier_value',
        'known_sessions',
        'device_fingerprints',
        'custom_attributes',
        'rfm_score',
        'rfm_details',
    ];

    /**
     * Casts for associative-array / object fields only.
     *
     * NOTE: `known_sessions` and `device_fingerprints` are intentionally
     * left UN-cast so MongoDB stores them as native BSON arrays. This
     * enables $addToSet / push() and array-element queries (e.g.
     * where('known_sessions', $sessionId)). Using the 'array' cast
     * would serialize them as JSON strings, breaking those operations.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'custom_attributes' => 'array',
            'rfm_details'       => 'array',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * High-velocity tracking event stored in MongoDB.
 *
 * Connection: mongodb | Collection: tracking_events
 *
 * @property string      $tenant_id
 * @property string      $session_id
 * @property string      $event_type   e.g. 'page_view', 'add_to_cart'
 * @property string      $url
 * @property array       $metadata     Flexible bag: product IDs, prices, etc.
 * @property array       $custom_data  Schemaless arbitrary data from the client.
 * @property string      $ip_address
 * @property string      $user_agent
 * @property \DateTime   $created_at
 * @property \DateTime   $updated_at
 */
final class TrackingEvent extends Model
{
    /** @var string */
    protected $connection = 'mongodb';

    /** @var string */
    protected $collection = 'tracking_events';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'session_id',
        'event_type',
        'url',
        'metadata',
        'custom_data',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * Casts for date fields only.
     *
     * NOTE: `metadata` and `custom_data` are intentionally left UN-cast
     * so MongoDB stores them as native BSON documents.  This lets raw
     * aggregation pipelines traverse nested fields via dot notation
     * (e.g. `$metadata.order_total` in the RFM job).  The Eloquent
     * driver already converts BSON documents ↔ PHP arrays transparently.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Synced abandoned cart stored in MongoDB (restricted — needs consent).
 *
 * @property string      $tenant_id
 * @property string      $platform
 * @property string      $external_id      Quote / cart ID
 * @property string|null $customer_email
 * @property string|null $customer_name
 * @property string|null $customer_id
 * @property float       $grand_total
 * @property int         $items_count
 * @property array       $items
 * @property string      $status
 * @property bool        $email_sent
 * @property string|null $abandoned_at
 * @property string|null $last_activity_at
 * @property \DateTime   $synced_at
 */
final class SyncedAbandonedCart extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'synced_abandoned_carts';

    protected $fillable = [
        'tenant_id',
        'platform',
        'external_id',
        'store_id',
        'customer_email',
        'customer_name',
        'customer_id',
        'grand_total',
        'items_count',
        'items',
        'status',
        'email_sent',
        'abandoned_at',
        'last_activity_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'grand_total'  => 'float',
            'items_count'  => 'integer',
            'email_sent'   => 'boolean',
            'synced_at'    => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }
}

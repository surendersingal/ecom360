<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Synced popup / lead capture stored in MongoDB (sensitive — PII).
 *
 * @property string      $tenant_id
 * @property string      $platform
 * @property string|null $session_id
 * @property string|null $customer_id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $dob
 * @property array|null  $extra_data
 * @property string|null $page_url
 * @property string|null $captured_at
 * @property \DateTime   $synced_at
 */
final class SyncedPopupCapture extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'synced_popup_captures';

    protected $fillable = [
        'tenant_id',
        'platform',
        'store_id',
        'session_id',
        'customer_id',
        'name',
        'email',
        'phone',
        'dob',
        'extra_data',
        'page_url',
        'captured_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at'  => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

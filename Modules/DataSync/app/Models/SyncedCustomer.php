<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Synced customer stored in MongoDB (sensitive — requires full PII consent).
 *
 * @property string      $tenant_id
 * @property string      $platform
 * @property string      $external_id
 * @property string      $email
 * @property string|null $firstname
 * @property string|null $lastname
 * @property string|null $name
 * @property string|null $dob
 * @property int|null    $gender
 * @property int|null    $group_id
 * @property array       $attributes
 * @property int         $store_id
 * @property \DateTime   $synced_at
 */
final class SyncedCustomer extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'synced_customers';

    protected $fillable = [
        'tenant_id',
        'platform',
        'external_id',
        'email',
        'firstname',
        'lastname',
        'name',
        'dob',
        'gender',
        'group_id',
        'attributes',
        'store_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'gender'     => 'integer',
            'group_id'   => 'integer',
            'store_id'   => 'integer',
            'synced_at'  => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

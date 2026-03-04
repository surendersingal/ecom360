<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Synced category stored in MongoDB.
 *
 * @property string      $tenant_id
 * @property string      $platform
 * @property string      $external_id
 * @property string      $name
 * @property string|null $url_key
 * @property bool        $is_active
 * @property int         $level
 * @property int         $position
 * @property string|null $parent_id
 * @property string|null $path
 * @property string|null $description
 * @property bool        $include_in_menu
 * @property int         $product_count
 * @property int         $store_id
 * @property \DateTime   $synced_at
 */
final class SyncedCategory extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'synced_categories';

    protected $fillable = [
        'tenant_id',
        'platform',
        'external_id',
        'name',
        'url_key',
        'is_active',
        'level',
        'position',
        'parent_id',
        'path',
        'description',
        'include_in_menu',
        'product_count',
        'store_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'       => 'boolean',
            'include_in_menu' => 'boolean',
            'level'           => 'integer',
            'position'        => 'integer',
            'product_count'   => 'integer',
            'store_id'        => 'integer',
            'synced_at'       => 'datetime',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
        ];
    }
}

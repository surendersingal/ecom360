<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Synced product stored in MongoDB.
 *
 * @property string      $tenant_id
 * @property string      $platform
 * @property string      $external_id       Platform-specific product ID
 * @property string      $sku
 * @property string      $name
 * @property float       $price
 * @property float|null  $special_price
 * @property string      $status
 * @property string      $type              simple | configurable | variable | grouped
 * @property string|null $url_key
 * @property string|null $image_url
 * @property string|null $description
 * @property string|null $short_description
 * @property array       $categories
 * @property array       $category_ids
 * @property array       $variants          Child products / variations
 * @property array       $attributes        Additional platform-specific attrs
 * @property int         $store_id
 * @property \DateTime   $synced_at
 */
final class SyncedProduct extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'synced_products';

    protected $fillable = [
        'tenant_id',
        'platform',
        'external_id',
        'sku',
        'name',
        'price',
        'special_price',
        'status',
        'type',
        'visibility',
        'weight',
        'url_key',
        'image_url',
        'description',
        'short_description',
        'categories',
        'category_ids',
        'variants',
        'attributes',
        'brand',
        'store_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price'         => 'float',
            'special_price' => 'float',
            'store_id'      => 'integer',
            'synced_at'     => 'datetime',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
        ];
    }
}

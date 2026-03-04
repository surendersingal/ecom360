<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Synced inventory / stock data in MongoDB (public — no PII).
 *
 * @property string      $tenant_id
 * @property string      $platform
 * @property string      $product_id
 * @property string      $sku
 * @property string|null $name
 * @property float       $price
 * @property float|null  $cost
 * @property float|null  $special_price
 * @property float       $qty
 * @property bool        $is_in_stock
 * @property float       $min_qty
 * @property bool        $low_stock
 * @property \DateTime   $synced_at
 */
final class SyncedInventory extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'synced_inventory';

    protected $fillable = [
        'tenant_id',
        'platform',
        'product_id',
        'sku',
        'name',
        'price',
        'cost',
        'special_price',
        'qty',
        'is_in_stock',
        'min_qty',
        'low_stock',
        'store_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price'         => 'float',
            'cost'          => 'float',
            'special_price' => 'float',
            'qty'           => 'float',
            'is_in_stock'   => 'boolean',
            'min_qty'       => 'float',
            'low_stock'     => 'boolean',
            'synced_at'     => 'datetime',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
        ];
    }
}

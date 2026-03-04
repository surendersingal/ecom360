<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Aggregated daily sales data in MongoDB (public — no PII).
 *
 * @property string    $tenant_id
 * @property string    $platform
 * @property string    $date
 * @property int       $total_orders
 * @property float     $total_revenue
 * @property float     $total_subtotal
 * @property float     $total_tax
 * @property float     $total_shipping
 * @property float     $total_discount
 * @property float     $total_refunded
 * @property float     $avg_order_value
 * @property int       $total_items
 * @property string    $currency
 * @property int       $store_id
 * @property \DateTime $synced_at
 */
final class SyncedSalesData extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'synced_sales_data';

    protected $fillable = [
        'tenant_id',
        'platform',
        'date',
        'total_orders',
        'total_revenue',
        'total_subtotal',
        'total_tax',
        'total_shipping',
        'total_discount',
        'total_refunded',
        'avg_order_value',
        'total_items',
        'currency',
        'store_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'total_orders'    => 'integer',
            'total_revenue'   => 'float',
            'total_subtotal'  => 'float',
            'total_tax'       => 'float',
            'total_shipping'  => 'float',
            'total_discount'  => 'float',
            'total_refunded'  => 'float',
            'avg_order_value' => 'float',
            'total_items'     => 'integer',
            'store_id'        => 'integer',
            'synced_at'       => 'datetime',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
        ];
    }
}

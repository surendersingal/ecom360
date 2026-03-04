<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Synced order stored in MongoDB (restricted — requires consent).
 *
 * @property string      $tenant_id
 * @property string      $platform
 * @property string      $external_id       Platform order ID
 * @property string      $order_number      Display order number
 * @property string      $status
 * @property string|null $state
 * @property float       $grand_total
 * @property float       $subtotal
 * @property float       $tax_amount
 * @property float       $shipping_amount
 * @property float       $discount_amount
 * @property int         $total_qty
 * @property string      $currency
 * @property string|null $payment_method
 * @property string|null $shipping_method
 * @property string|null $coupon_code
 * @property string|null $customer_email
 * @property string|null $customer_id
 * @property bool        $is_guest
 * @property array       $items
 * @property array|null  $billing_address
 * @property array|null  $shipping_address
 * @property int         $store_id
 * @property \DateTime   $synced_at
 */
final class SyncedOrder extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'synced_orders';

    protected $fillable = [
        'tenant_id',
        'platform',
        'external_id',
        'order_number',
        'status',
        'state',
        'grand_total',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'total_qty',
        'currency',
        'payment_method',
        'shipping_method',
        'coupon_code',
        'customer_email',
        'customer_id',
        'is_guest',
        'items',
        'billing_address',
        'shipping_address',
        'store_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'grand_total'       => 'float',
            'subtotal'          => 'float',
            'tax_amount'        => 'float',
            'shipping_amount'   => 'float',
            'discount_amount'   => 'float',
            'total_qty'         => 'integer',
            'is_guest'          => 'boolean',
            'store_id'          => 'integer',
            'synced_at'         => 'datetime',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('orders') || !is_array($this->orders)) {
            return;
        }

        $this->merge([
            'orders' => collect($this->orders)->map(function ($order) {
                if (isset($order['status'])) {
                    $order['status'] = strip_tags($order['status']);
                }
                if (isset($order['customer_name'])) {
                    $order['customer_name'] = strip_tags($order['customer_name']);
                }
                return $order;
            })->toArray(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'orders'                  => 'required|array|min:1|max:200',
            'orders.*.order_id'       => 'nullable',
            'orders.*.entity_id'      => 'nullable',
            'orders.*.id'             => 'nullable',
            'orders.*.status'         => 'nullable|string',
            'orders.*.grand_total'    => 'nullable|numeric|min:0',
            'orders.*.subtotal'       => 'nullable|numeric|min:0',
            'orders.*.tax_amount'     => 'nullable|numeric|min:0',
            'orders.*.shipping_amount' => 'nullable|numeric|min:0',
            'orders.*.discount_amount' => 'nullable|numeric',
            'platform'                => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'                => 'nullable|integer|min:0',
        ];
    }
}

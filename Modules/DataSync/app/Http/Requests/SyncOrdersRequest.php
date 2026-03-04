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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'orders'                  => 'required|array|min:1|max:200',
            'orders.*.order_id'       => 'nullable',
            'orders.*.entity_id'      => 'nullable',
            'orders.*.id'             => 'nullable',
            'orders.*.status'         => 'nullable|string',
            'orders.*.grand_total'    => 'nullable|numeric',
            'platform'                => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'                => 'nullable|integer|min:0',
        ];
    }
}

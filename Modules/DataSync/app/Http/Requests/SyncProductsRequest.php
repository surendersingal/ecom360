<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates incoming product sync payload.
 */
final class SyncProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'products'              => 'required|array|min:1|max:500',
            'products.*.id'         => 'nullable',
            'products.*.sku'        => 'nullable|string',
            'products.*.name'       => 'required|string',
            'products.*.price'      => 'nullable|numeric|min:0',
            'platform'              => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'              => 'nullable|integer|min:0',
        ];
    }
}

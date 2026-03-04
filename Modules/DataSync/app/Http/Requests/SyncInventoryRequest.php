<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items'              => 'required|array|min:1|max:500',
            'items.*.product_id' => 'nullable',
            'items.*.sku'        => 'nullable|string',
            'items.*.qty'        => 'nullable|numeric',
            'platform'           => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'           => 'nullable|integer|min:0',
        ];
    }
}

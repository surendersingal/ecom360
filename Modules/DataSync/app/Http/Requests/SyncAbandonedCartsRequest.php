<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncAbandonedCartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'abandoned_carts'                    => 'required|array|min:1|max:200',
            'abandoned_carts.*.quote_id'         => 'nullable',
            'abandoned_carts.*.customer_email'   => 'nullable|email',
            'platform'                           => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'                           => 'nullable|integer|min:0',
        ];
    }
}

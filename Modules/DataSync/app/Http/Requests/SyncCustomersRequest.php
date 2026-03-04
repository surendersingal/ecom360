<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customers'              => 'required|array|min:1|max:200',
            'customers.*.id'         => 'nullable',
            'customers.*.email'      => 'required|email',
            'platform'               => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'               => 'nullable|integer|min:0',
        ];
    }
}

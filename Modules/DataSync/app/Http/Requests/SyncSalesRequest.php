<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncSalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sales_data'               => 'required|array|min:1|max:365',
            'sales_data.*.date'        => 'required|date',
            'sales_data.*.total_revenue' => 'nullable|numeric',
            'platform'                  => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'                  => 'nullable|integer|min:0',
            'currency'                  => 'nullable|string|max:3',
        ];
    }
}

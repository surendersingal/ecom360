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

    protected function prepareForValidation(): void
    {
        if (!$this->has('abandoned_carts') || !is_array($this->abandoned_carts)) {
            return;
        }

        $this->merge([
            'abandoned_carts' => collect($this->abandoned_carts)->map(function ($cart) {
                if (isset($cart['customer_name'])) {
                    $cart['customer_name'] = strip_tags($cart['customer_name']);
                }
                return $cart;
            })->toArray(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'abandoned_carts'                    => 'required|array|min:1|max:200',
            'abandoned_carts.*.quote_id'         => 'nullable',
            'abandoned_carts.*.customer_email'   => 'nullable|email',
            'abandoned_carts.*.grand_total'      => 'nullable|numeric|min:0',
            'platform'                           => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'                           => 'nullable|integer|min:0',
        ];
    }
}

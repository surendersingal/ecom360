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

    protected function prepareForValidation(): void
    {
        if (!$this->has('products') || !is_array($this->products)) {
            return;
        }

        $this->merge([
            'products' => collect($this->products)->map(function ($product) {
                if (isset($product['name'])) {
                    $product['name'] = strip_tags($product['name']);
                }
                if (isset($product['description'])) {
                    $product['description'] = strip_tags($product['description']);
                }
                if (isset($product['short_description'])) {
                    $product['short_description'] = strip_tags($product['short_description']);
                }
                if (isset($product['sku'])) {
                    $product['sku'] = strip_tags($product['sku']);
                }
                return $product;
            })->toArray(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'products'              => 'required|array|min:1|max:500',
            'products.*.id'         => 'nullable',
            'products.*.sku'        => 'nullable|string',
            'products.*.name'       => 'required|string|max:500',
            'products.*.price'      => 'nullable|numeric|min:0',
            'platform'              => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'              => 'nullable|integer|min:0',
        ];
    }
}

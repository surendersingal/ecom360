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

    protected function prepareForValidation(): void
    {
        if (!$this->has('items') || !is_array($this->items)) {
            return;
        }

        $this->merge([
            'items' => collect($this->items)->map(function ($item) {
                if (isset($item['sku'])) {
                    $item['sku'] = strip_tags($item['sku']);
                }
                return $item;
            })->toArray(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items'              => 'required|array|min:1|max:500',
            'items.*.product_id' => 'nullable',
            'items.*.sku'        => 'nullable|string',
            'items.*.qty'        => 'nullable|numeric|min:0',
            'platform'           => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'           => 'nullable|integer|min:0',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('categories') || !is_array($this->categories)) {
            return;
        }

        $this->merge([
            'categories' => collect($this->categories)->map(function ($category) {
                if (isset($category['name'])) {
                    $category['name'] = strip_tags($category['name']);
                }
                if (isset($category['description'])) {
                    $category['description'] = strip_tags($category['description']);
                }
                return $category;
            })->toArray(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'categories'             => 'required|array|min:1|max:500',
            'categories.*.id'        => 'nullable',
            'categories.*.name'      => 'required|string|max:500',
            'platform'               => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'               => 'nullable|integer|min:0',
        ];
    }
}

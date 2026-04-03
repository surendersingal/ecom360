<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncPopupCapturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('captures') || !is_array($this->captures)) {
            return;
        }

        $this->merge([
            'captures' => collect($this->captures)->map(function ($capture) {
                if (isset($capture['name'])) {
                    $capture['name'] = strip_tags($capture['name']);
                }
                if (isset($capture['session_id'])) {
                    $capture['session_id'] = strip_tags($capture['session_id']);
                }
                return $capture;
            })->toArray(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'captures'              => 'required|array|min:1|max:200',
            'captures.*.email'      => 'nullable|email',
            'captures.*.session_id' => 'nullable|string|max:255',
            'platform'              => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'              => 'nullable|integer|min:0',
        ];
    }
}

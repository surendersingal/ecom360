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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'captures'              => 'required|array|min:1|max:200',
            'captures.*.email'      => 'nullable|email',
            'captures.*.session_id' => 'nullable|string',
            'platform'              => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'              => 'nullable|integer|min:0',
        ];
    }
}

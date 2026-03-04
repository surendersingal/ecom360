<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the permission update payload sent by the module/plugin.
 */
final class UpdatePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permissions'   => 'required|array',
            'permissions.*' => 'boolean',
            'platform'      => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'      => 'nullable|integer|min:0',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\DataSync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the connection registration / handshake payload.
 */
final class RegisterConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by ValidateSyncAuth middleware.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'platform'         => 'required|string|in:magento2,woocommerce,shopify,custom',
            'store_url'        => 'required|url|max:500',
            'store_name'       => 'nullable|string|max:255',
            'store_id'         => 'nullable|integer|min:0',
            'platform_version' => 'nullable|string|max:50',
            'module_version'   => 'nullable|string|max:50',
            'php_version'      => 'nullable|string|max:20',
            'locale'           => 'nullable|string|max:10',
            'currency'         => 'nullable|string|max:3',
            'timezone'         => 'nullable|string|max:64',
            'permissions'      => 'nullable|array',
            'permissions.*'    => 'boolean',
        ];
    }
}

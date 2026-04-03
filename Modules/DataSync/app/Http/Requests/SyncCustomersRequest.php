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

    protected function prepareForValidation(): void
    {
        if (!$this->has('customers') || !is_array($this->customers)) {
            return;
        }

        $this->merge([
            'customers' => collect($this->customers)->map(function ($customer) {
                if (isset($customer['first_name'])) {
                    $customer['first_name'] = strip_tags($customer['first_name']);
                }
                if (isset($customer['last_name'])) {
                    $customer['last_name'] = strip_tags($customer['last_name']);
                }
                if (isset($customer['name'])) {
                    $customer['name'] = strip_tags($customer['name']);
                }
                return $customer;
            })->toArray(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customers'              => 'required|array|min:1|max:200',
            'customers.*.id'         => 'nullable',
            'customers.*.email'      => 'required|email',
            'customers.*.first_name' => 'nullable|string|max:255',
            'customers.*.last_name'  => 'nullable|string|max:255',
            'platform'               => 'nullable|string|in:magento2,woocommerce,shopify,custom',
            'store_id'               => 'nullable|integer|min:0',
        ];
    }
}

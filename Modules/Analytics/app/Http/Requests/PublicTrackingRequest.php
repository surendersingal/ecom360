<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates public tracking payloads from the Store JS SDK.
 *
 * Differences from StoreIngestionRequest:
 *  - No Sanctum auth required (API key validated by middleware)
 *  - Supports both single event and batched event payloads
 *  - Adds referrer, screen_resolution, timezone, language fields
 *  - Stricter rate-limit-friendly validation (fast reject)
 */
final class PublicTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by ValidateTrackingApiKey middleware.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Single event mode.
            'session_id'                      => ['required', 'string', 'max:128'],
            'event_type'                      => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'url'                             => ['required', 'string', 'max:2048'],
            'metadata'                        => ['sometimes', 'array'],
            'custom_data'                     => ['nullable', 'array'],
            'customer_identifier'             => ['nullable', 'array'],
            'customer_identifier.type'        => ['required_with:customer_identifier', 'string', 'in:email,phone'],
            'customer_identifier.value'       => ['required_with:customer_identifier', 'string', 'max:255'],
            'device_fingerprint'              => ['nullable', 'string', 'max:128'],
            'ip_address'                      => ['sometimes', 'string', 'ip'],
            'user_agent'                      => ['sometimes', 'string', 'max:512'],

            // Extended fields from the SDK.
            'referrer'                        => ['nullable', 'string', 'max:2048'],
            'screen_resolution'               => ['nullable', 'string', 'max:20'],
            'timezone'                        => ['nullable', 'string', 'max:50'],
            'language'                        => ['nullable', 'string', 'max:10'],
            'page_title'                      => ['nullable', 'string', 'max:512'],

            // UTM parameters captured by the SDK.
            'utm'                             => ['nullable', 'array'],
            'utm.source'                      => ['nullable', 'string', 'max:255'],
            'utm.medium'                      => ['nullable', 'string', 'max:255'],
            'utm.campaign'                    => ['nullable', 'string', 'max:255'],
            'utm.term'                        => ['nullable', 'string', 'max:255'],
            'utm.content'                     => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Guard against oversized payloads (2 MB limit).
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v) {
            $all = $this->all();
            if (is_array($all) && strlen((string) json_encode($all)) > 2_097_152) {
                $v->errors()->add('payload', 'The payload exceeds the maximum allowed size of 2 MB.');
            }
        });
    }
}

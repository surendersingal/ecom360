<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the incoming tracking/ingestion payload before it reaches
 * the Analytics module's TrackingService.
 *
 * Supports:
 *  - Core fields (session_id, event_type, url, metadata)
 *  - Schemaless custom_data (arbitrary key-value pairs)
 *  - Optional customer_identifier for identity resolution
 */
final class StoreIngestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by middleware (auth:sanctum).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ---------------------------------------------------------------
            //  Core tracking fields
            // ---------------------------------------------------------------
            'payload'              => ['required', 'array'],
            'payload.session_id'   => ['required', 'string', 'max:128'],
            'payload.event_type'   => ['required', 'string', 'max:50'],
            'payload.url'          => ['required', 'string', 'url', 'max:2048'],
            'payload.metadata'     => ['sometimes', 'array'],
            'payload.ip_address'   => ['sometimes', 'string', 'ip'],
            'payload.user_agent'   => ['sometimes', 'string', 'max:512'],

            // ---------------------------------------------------------------
            //  Schemaless custom data
            //  e.g. { "scroll_depth": 80, "variant_selected": "blue" }
            //  Hard limit: 2 MB when JSON-encoded (protects MongoDB).
            // ---------------------------------------------------------------
            'payload.custom_data'  => ['nullable', 'array'],

            // ---------------------------------------------------------------
            //  Device fingerprint for anonymous user recognition
            //  A SHA-256 hash generated client-side (e.g. FingerprintJS).
            // ---------------------------------------------------------------
            'payload.device_fingerprint' => ['nullable', 'string', 'max:128'],

            // ---------------------------------------------------------------
            //  Customer identity for resolution
            //  e.g. { "type": "email", "value": "user@example.com" }
            // ---------------------------------------------------------------
            'payload.customer_identifier'        => ['nullable', 'array'],
            'payload.customer_identifier.type'    => ['required_with:payload.customer_identifier', 'string', 'in:email,phone'],
            'payload.customer_identifier.value'   => ['required_with:payload.customer_identifier', 'string', 'max:255'],
        ];
    }

    /**
     * Additional validation after the standard rules pass.
     *
     * Guards against oversized payloads that could bloat MongoDB
     * or exhaust server memory (e.g. a 5 MB custom_data blob).
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v) {
            $payload = $this->input('payload');

            if (is_array($payload) && strlen((string) json_encode($payload)) > 2_097_152) {
                $v->errors()->add(
                    'payload',
                    'The payload exceeds the maximum allowed size of 2 MB.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payload.customer_identifier.type.in' => 'The identifier type must be either "email" or "phone".',
        ];
    }
}

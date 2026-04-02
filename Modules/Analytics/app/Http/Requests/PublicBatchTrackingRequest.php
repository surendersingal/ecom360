<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a batched array of tracking events from the Store JS SDK.
 *
 * The SDK batches events and sends them in a single POST to reduce
 * HTTP overhead. Each event in the batch is validated individually.
 */
final class PublicBatchTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events'                                => ['required', 'array', 'min:1', 'max:50'],
            'events.*.session_id'                   => ['required', 'string', 'max:128'],
            'events.*.event_type'                   => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'events.*.url'                          => ['required', 'string', 'max:2048'],
            'events.*.metadata'                     => ['sometimes', 'array'],
            'events.*.custom_data'                  => ['nullable', 'array'],
            'events.*.customer_identifier'          => ['nullable', 'array'],
            'events.*.customer_identifier.type'     => ['required_with:events.*.customer_identifier', 'string', 'in:email,phone'],
            'events.*.customer_identifier.value'    => ['required_with:events.*.customer_identifier', 'string', 'max:255'],
            'events.*.device_fingerprint'           => ['nullable', 'string', 'max:128'],
            'events.*.ip_address'                   => ['sometimes', 'string', 'ip'],
            'events.*.user_agent'                   => ['sometimes', 'string', 'max:512'],
            'events.*.referrer'                     => ['nullable', 'string', 'max:2048'],
            'events.*.screen_resolution'            => ['nullable', 'string', 'max:20'],
            'events.*.timezone'                     => ['nullable', 'string', 'max:50'],
            'events.*.language'                     => ['nullable', 'string', 'max:10'],
            'events.*.page_title'                   => ['nullable', 'string', 'max:512'],
            'events.*.utm'                          => ['nullable', 'array'],
            'events.*.utm.source'                   => ['nullable', 'string', 'max:255'],
            'events.*.utm.medium'                   => ['nullable', 'string', 'max:255'],
            'events.*.utm.campaign'                 => ['nullable', 'string', 'max:255'],
            'events.*.timestamp'                    => ['nullable', 'date'],
        ];
    }

    /**
     * Guard against oversized batch payloads (5 MB limit for batches).
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v) {
            $all = $this->all();
            if (is_array($all) && strlen((string) json_encode($all)) > 5_242_880) {
                $v->errors()->add('events', 'The batch payload exceeds the maximum allowed size of 5 MB.');
            }
        });
    }
}

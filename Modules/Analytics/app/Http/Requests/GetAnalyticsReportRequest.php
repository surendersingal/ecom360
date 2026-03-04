<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates incoming query parameters for the Analytics Reporting API.
 *
 * Expected parameters:
 *   - date_range:  '7d', '30d', '90d', 'ytd', or 'Y-m-d|Y-m-d'
 *   - widget_keys: array of dot-notation widget keys registered in WidgetRegistry
 */
final class GetAnalyticsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Sanctum handles auth at the route level.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_range'   => ['required', 'string', 'regex:/^(7d|30d|90d|ytd|\d{4}-\d{2}-\d{2}\|\d{4}-\d{2}-\d{2})$/'],
            'widget_keys'  => ['required', 'array', 'min:1'],
            'widget_keys.*' => ['required', 'string', 'regex:/^[a-z_]+\.[a-z_]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_range.regex'   => 'date_range must be one of: 7d, 30d, 90d, ytd, or a custom range (YYYY-MM-DD|YYYY-MM-DD).',
            'widget_keys.*.regex' => 'Each widget key must follow the format "module.widget_name".',
        ];
    }
}

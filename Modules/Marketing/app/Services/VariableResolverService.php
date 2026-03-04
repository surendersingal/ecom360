<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Log;
use Modules\Analytics\Models\CustomerProfile;

/**
 * Resolves template variables from multiple data sources.
 *
 * Variable namespaces:
 *   - contact.*       → Marketing contact fields
 *   - customer.*      → Analytics CustomerProfile attributes
 *   - order.*         → Last order data from analytics events
 *   - shop.*          → Tenant/store-level settings
 *   - campaign.*      → Current campaign metadata
 *   - date.*          → Current date/time helpers
 *   - custom.*        → Contact's custom_fields
 *
 * Usage in templates:
 *   "Hi {{ contact.first_name }}, your last order of {{ order.total }} ..."
 */
final class VariableResolverService
{
    /**
     * Resolve all variables for a given contact within a campaign context.
     *
     * @param  array  $contact   Contact model attributes
     * @param  array  $context   Additional context (campaign data, order data, etc.)
     * @return array<string, mixed>  Flat key-value map of resolved variables
     */
    public function resolve(array $contact, array $context = []): array
    {
        $variables = [];

        // Contact variables
        $variables = array_merge($variables, $this->resolveContactVars($contact));

        // Customer profile variables (from Analytics module)
        if (!empty($contact['email'])) {
            $variables = array_merge($variables, $this->resolveCustomerVars(
                $context['tenant_id'] ?? null,
                $contact['email']
            ));
        }

        // Order variables (last purchase)
        if (!empty($context['order'])) {
            $variables = array_merge($variables, $this->prefixKeys('order', $context['order']));
        }

        // Shop / tenant variables
        if (!empty($context['shop'])) {
            $variables = array_merge($variables, $this->prefixKeys('shop', $context['shop']));
        }

        // Campaign variables
        if (!empty($context['campaign'])) {
            $variables = array_merge($variables, $this->prefixKeys('campaign', $context['campaign']));
        }

        // Date helpers
        $variables['date.today'] = now()->toDateString();
        $variables['date.now'] = now()->toDateTimeString();
        $variables['date.year'] = (string) now()->year;
        $variables['date.month_name'] = now()->format('F');
        $variables['date.day_name'] = now()->format('l');

        // Custom fields (from contact)
        if (!empty($contact['custom_fields'])) {
            foreach ((array) $contact['custom_fields'] as $key => $value) {
                $variables["custom.{$key}"] = $value;
            }
        }

        return $variables;
    }

    /**
     * Resolve a rendered string by replacing {{ var }} placeholders.
     */
    public function renderString(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            fn(array $m) => (string) ($variables[$m[1]] ?? $m[0]),
            $template
        );
    }

    private function resolveContactVars(array $contact): array
    {
        return [
            'contact.first_name' => $contact['first_name'] ?? '',
            'contact.last_name' => $contact['last_name'] ?? '',
            'contact.full_name' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
            'contact.email' => $contact['email'] ?? '',
            'contact.phone' => $contact['phone'] ?? '',
            'contact.company' => $contact['company'] ?? '',
            'contact.city' => $contact['city'] ?? '',
            'contact.country' => $contact['country'] ?? '',
            'contact.tags' => implode(', ', (array) ($contact['tags'] ?? [])),
        ];
    }

    private function resolveCustomerVars(?string $tenantId, string $email): array
    {
        if (!$tenantId) return [];

        try {
            $profile = CustomerProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('email', $email)
                ->first();

            if (!$profile) return [];

            return [
                'customer.total_orders' => (string) ($profile->total_orders ?? 0),
                'customer.total_revenue' => number_format((float) ($profile->total_revenue ?? 0), 2),
                'customer.average_order' => number_format((float) ($profile->average_order_value ?? 0), 2),
                'customer.first_seen' => $profile->first_seen_at?->toDateString() ?? '',
                'customer.last_seen' => $profile->last_seen_at?->toDateString() ?? '',
                'customer.last_purchase' => $profile->last_purchase_at?->toDateString() ?? '',
                'customer.rfm_segment' => $profile->rfm_segment ?? '',
                'customer.intent_score' => (string) ($profile->intent_score ?? 0),
                'customer.lifetime_value' => number_format((float) ($profile->lifetime_value ?? 0), 2),
            ];
        } catch (\Throwable $e) {
            Log::warning("[VariableResolver] Failed to load customer profile: {$e->getMessage()}");
            return [];
        }
    }

    private function prefixKeys(string $prefix, array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $result["{$prefix}.{$key}"] = (string) ($value ?? '');
            }
        }
        return $result;
    }
}

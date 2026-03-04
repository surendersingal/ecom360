<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Log;
use Modules\Marketing\Models\Template;

/**
 * Template management: CRUD, rendering, variable extraction, cloning.
 */
final class TemplateService
{
    public function __construct(
        private readonly VariableResolverService $variableResolver,
    ) {}

    // ─── CRUD Methods ─────────────────────────────────────────────────

    /**
     * List templates for a tenant with optional filters.
     */
    public function list(int $tenantId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Template::where('tenant_id', $tenantId)
            ->when($filters['channel'] ?? null, fn($q, $ch) => $q->where('channel', $ch))
            ->when($filters['category'] ?? null, fn($q, $c) => $q->where('category', $c))
            ->when(isset($filters['is_active']), fn($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('updated_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Find a single template by ID scoped to tenant.
     */
    public function find(int $tenantId, int $id): ?Template
    {
        return Template::where('tenant_id', $tenantId)->find($id);
    }

    /**
     * Update a template.
     */
    public function update(int $tenantId, int $id, array $data): Template
    {
        $template = Template::where('tenant_id', $tenantId)->findOrFail($id);
        $template->update($data);
        return $template->fresh();
    }

    /**
     * Delete a template.
     */
    public function delete(int $tenantId, int $id): void
    {
        Template::where('tenant_id', $tenantId)->findOrFail($id)->delete();
    }

    // ─── Template Creation & Rendering ────────────────────────────────

    /**
     * Create a new template.
     */
    public function create(int $tenantId, array $data): Template
    {
        $template = Template::create([
            'tenant_id' => $tenantId,
            'channel' => $data['channel'],
            'name' => $data['name'],
            'subject' => $data['subject'] ?? null,
            'body_html' => $data['body_html'] ?? null,
            'body_text' => $data['body_text'] ?? null,
            'variables' => $data['variables'] ?? [],
            'attachments' => $data['attachments'] ?? [],
            'category' => $data['category'] ?? 'general',
            'is_active' => $data['is_active'] ?? true,
        ]);

        // Auto-detect variables if not explicitly provided
        if (empty($data['variables'])) {
            $detected = $template->extractVariables();
            $template->update(['variables' => $detected]);
        }

        return $template;
    }

    /**
     * Render a template with resolved variables for a specific contact.
     */
    public function renderForContact(Template $template, array $contactData, array $context = []): array
    {
        $variables = $this->variableResolver->resolve($contactData, $context);
        return $template->render($variables);
    }

    /**
     * Preview a template with sample data.
     *
     * Accepts either (Template) or (tenantId, templateId, contactId?) signature.
     */
    public function preview(Template|int $templateOrTenantId, ?int $templateId = null, ?int $contactId = null): array
    {
        if ($templateOrTenantId instanceof Template) {
            $template = $templateOrTenantId;
        } else {
            $template = Template::where('tenant_id', $templateOrTenantId)->findOrFail($templateId);
        }

        // If a contact ID is provided, render with real contact data
        if ($contactId) {
            $contact = \Modules\Marketing\Models\Contact::find($contactId);
            if ($contact) {
                return $this->renderForContact($template, $contact->toArray());
            }
        }

        $sampleVars = [];
        foreach ($template->extractVariables() as $var) {
            $sampleVars[$var] = match (true) {
                str_contains($var, 'first_name') => 'John',
                str_contains($var, 'last_name') => 'Doe',
                str_contains($var, 'email') => 'john@example.com',
                str_contains($var, 'total') => '$99.99',
                str_contains($var, 'date') => now()->toDateString(),
                str_contains($var, 'company') => 'Acme Inc.',
                str_contains($var, 'city') => 'New York',
                str_contains($var, 'name') => 'John Doe',
                default => "[{$var}]",
            };
        }

        return $template->render($sampleVars);
    }

    /**
     * Clone a template.
     *
     * Accepts either (Template, newName?) or (tenantId, templateId) signature.
     */
    public function clone(Template|int $templateOrTenantId, int|string|null $templateIdOrName = null): Template
    {
        if ($templateOrTenantId instanceof Template) {
            $template = $templateOrTenantId;
            $newName = is_string($templateIdOrName) ? $templateIdOrName : null;
        } else {
            $template = Template::where('tenant_id', $templateOrTenantId)->findOrFail((int) $templateIdOrName);
            $newName = null;
        }

        $clone = $template->replicate();
        $clone->name = $newName ?? "{$template->name} (Copy)";
        $clone->save();
        return $clone;
    }
}

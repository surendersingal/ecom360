<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Log;
use Modules\Marketing\Models\Contact;
use Modules\Marketing\Models\ContactList;

/**
 * Manages contacts: import, segmentation sync, subscription preferences,
 * and audience resolution for campaigns.
 */
final class ContactService
{
    // ─── CRUD Methods ─────────────────────────────────────────────────

    /**
     * List contacts for a tenant with optional filters.
     */
    public function list(int $tenantId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Contact::where('tenant_id', $tenantId)
            ->when($filters['status'] ?? null, fn($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn($q, $s) => $q->where(function ($q2) use ($s) {
                $q2->where('email', 'like', "%{$s}%")
                   ->orWhere('first_name', 'like', "%{$s}%")
                   ->orWhere('last_name', 'like', "%{$s}%");
            }))
            ->when($filters['tag'] ?? null, fn($q, $tag) => $q->whereJsonContains('tags', $tag))
            ->orderByDesc('updated_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Find a single contact by ID scoped to tenant.
     */
    public function find(int $tenantId, int $id): ?Contact
    {
        return Contact::where('tenant_id', $tenantId)->find($id);
    }

    /**
     * Update a contact.
     */
    public function update(int $tenantId, int $id, array $data): Contact
    {
        $contact = Contact::where('tenant_id', $tenantId)->findOrFail($id);
        $contact->update($data);
        return $contact->fresh();
    }

    /**
     * Delete a contact.
     */
    public function delete(int $tenantId, int $id): void
    {
        Contact::where('tenant_id', $tenantId)->findOrFail($id)->delete();
    }

    // ─── Upsert & Import ──────────────────────────────────────────────

    /**
     * Upsert a contact by email within a tenant.
     */
    public function upsert(int $tenantId, array $data): Contact
    {
        return Contact::updateOrCreate(
            ['tenant_id' => $tenantId, 'email' => $data['email']],
            array_filter([
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'company' => $data['company'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? null,
                'tags' => $data['tags'] ?? null,
                'custom_fields' => $data['custom_fields'] ?? null,
                'status' => $data['status'] ?? 'subscribed',
            ], fn($v) => $v !== null)
        );
    }

    /**
     * Bulk import contacts from a CSV-style array.
     *
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function bulkImport(int $tenantId, array $rows, ?int $listId = null): array
    {
        $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            if (empty($row['email'])) {
                $stats['skipped']++;
                continue;
            }

            $existed = Contact::where('tenant_id', $tenantId)
                ->where('email', $row['email'])
                ->exists();

            $contact = $this->upsert($tenantId, $row);

            if ($existed) {
                $stats['updated']++;
            } else {
                $stats['imported']++;
            }

            if ($listId) {
                $this->addToList($contact, $listId);
            }
        }

        return $stats;
    }

    /**
     * Add contact(s) to a list.
     *
     * Accepts either (Contact, listId) or (tenantId, listId, contactIds) signature.
     */
    public function addToList(Contact|int $contactOrTenantId, int $listId, ?array $contactIds = null): array|null
    {
        if ($contactOrTenantId instanceof Contact) {
            $contactOrTenantId->lists()->syncWithoutDetaching([$listId]);
            return null;
        }

        // Controller signature: addToList(tenantId, listId, contactIds)
        $tenantId = $contactOrTenantId;
        $added = 0;
        foreach ($contactIds ?? [] as $contactId) {
            $contact = Contact::where('tenant_id', $tenantId)->find($contactId);
            if ($contact) {
                $contact->lists()->syncWithoutDetaching([$listId]);
                $added++;
            }
        }
        return ['added' => $added];
    }

    /**
     * Remove contact(s) from a list.
     *
     * Accepts either (Contact, listId) or (tenantId, listId, contactIds) signature.
     */
    public function removeFromList(Contact|int $contactOrTenantId, int $listId, ?array $contactIds = null): array|null
    {
        if ($contactOrTenantId instanceof Contact) {
            $contactOrTenantId->lists()->detach([$listId]);
            return null;
        }

        // Controller signature: removeFromList(tenantId, listId, contactIds)
        $tenantId = $contactOrTenantId;
        $removed = 0;
        foreach ($contactIds ?? [] as $contactId) {
            $contact = Contact::where('tenant_id', $tenantId)->find($contactId);
            if ($contact) {
                $contact->lists()->detach([$listId]);
                $removed++;
            }
        }
        return ['removed' => $removed];
    }

    /**
     * Unsubscribe a contact.
     *
     * Accepts either (Contact) or (tenantId, contactId, channel) signature.
     */
    public function unsubscribe(Contact|int $contactOrTenantId, ?int $contactId = null, ?string $channel = null): void
    {
        if ($contactOrTenantId instanceof Contact) {
            $contact = $contactOrTenantId;
        } else {
            $contact = Contact::where('tenant_id', $contactOrTenantId)->findOrFail($contactId);
        }

        $contact->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);
    }

    // ─── List Management ──────────────────────────────────────────────

    /**
     * Get all contact lists for a tenant.
     */
    public function getLists(int $tenantId): \Illuminate\Database\Eloquent\Collection
    {
        return ContactList::where('tenant_id', $tenantId)->orderBy('name')->get();
    }

    /**
     * Create a new contact list.
     */
    public function createList(int $tenantId, array $data): ContactList
    {
        return ContactList::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    // ─── Audience Resolution ──────────────────────────────────────────

    /**
     * Resolve audience for a campaign. Returns contact IDs.
     *
     * Audience config format:
     *   { type: "list", list_ids: [1,2] }
     *   { type: "segment", segment_id: 5 }
     *   { type: "all" }
     *   { type: "tags", tags: ["vip", "repeat"] }
     *
     * @return \Illuminate\Support\Collection<int, Contact>
     */
    public function resolveAudience(int $tenantId, array $audience): \Illuminate\Support\Collection
    {
        $type = $audience['type'] ?? 'all';

        $query = Contact::where('tenant_id', $tenantId)
            ->where('status', 'subscribed');

        return match ($type) {
            'list' => $query->whereHas('lists', function ($q) use ($audience) {
                $q->whereIn('marketing_contact_lists.id', (array) ($audience['list_ids'] ?? []));
            })->get(),

            'segment' => $this->resolveSegmentAudience($tenantId, $audience['segment_id'] ?? 0),

            'tags' => $query->where(function ($q) use ($audience) {
                foreach ((array) ($audience['tags'] ?? []) as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            })->get(),

            'contact_ids' => $query->whereIn('id', (array) ($audience['contact_ids'] ?? []))->get(),

            default => $query->get(), // "all" subscribed contacts
        };
    }

    /**
     * Sync contacts from Analytics audience segments.
     * Converts CustomerProfile matches into Marketing contacts.
     */
    public function syncFromAnalyticsSegment(int $tenantId, int $segmentId): int
    {
        try {
            $audienceBuilder = app(\Modules\Analytics\Services\AudienceBuilderService::class);
            $segment = \Modules\Analytics\Models\AudienceSegment::findOrFail($segmentId);

            $profiles = $audienceBuilder->buildQuery($segment)->get();
            $synced = 0;

            foreach ($profiles as $profile) {
                if (empty($profile->email)) continue;

                $this->upsert($tenantId, [
                    'email' => $profile->email,
                    'first_name' => $profile->first_name ?? null,
                    'last_name' => $profile->last_name ?? null,
                    'tags' => [$profile->rfm_segment ?? 'analytics-sync'],
                ]);
                $synced++;
            }

            Log::info("[ContactService] Synced {$synced} contacts from segment #{$segmentId}");
            return $synced;
        } catch (\Throwable $e) {
            Log::error("[ContactService] Segment sync failed: {$e->getMessage()}");
            return 0;
        }
    }

    private function resolveSegmentAudience(int $tenantId, int $segmentId): \Illuminate\Support\Collection
    {
        try {
            $audienceBuilder = app(\Modules\Analytics\Services\AudienceBuilderService::class);
            $segment = \Modules\Analytics\Models\AudienceSegment::findOrFail($segmentId);
            $emails = $audienceBuilder->buildQuery($segment)->pluck('email')->filter()->all();

            return Contact::where('tenant_id', $tenantId)
                ->where('status', 'subscribed')
                ->whereIn('email', $emails)
                ->get();
        } catch (\Throwable $e) {
            Log::error("[ContactService] Segment audience resolution failed: {$e->getMessage()}");
            return collect();
        }
    }
}

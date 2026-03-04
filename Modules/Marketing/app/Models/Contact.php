<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Contact extends Model
{
    protected $table = 'marketing_contacts';

    protected $fillable = [
        'tenant_id', 'email', 'phone', 'first_name', 'last_name',
        'custom_fields', 'tags', 'status', 'whatsapp_opt_in',
        'push_token', 'device_id', 'subscribed_at', 'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'tags' => 'array',
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(ContactList::class, 'marketing_contact_list_members', 'contact_id', 'list_id');
    }

    public function messages(): HasMany { return $this->hasMany(Message::class, 'contact_id'); }

    public function enrollments(): HasMany { return $this->hasMany(FlowEnrollment::class, 'contact_id'); }

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: ($this->email ?? $this->phone ?? 'Unknown');
    }

    /**
     * Resolve template variables for this contact.
     */
    public function resolveVariables(): array
    {
        return [
            'contact.email'      => $this->email,
            'contact.phone'      => $this->phone,
            'contact.first_name' => $this->first_name,
            'contact.last_name'  => $this->last_name,
            'contact.full_name'  => $this->full_name,
            'contact.status'     => $this->status,
            ...$this->prefixCustomFields(),
        ];
    }

    private function prefixCustomFields(): array
    {
        $fields = [];
        foreach ($this->custom_fields ?? [] as $key => $value) {
            $fields["contact.custom.{$key}"] = $value;
        }
        return $fields;
    }
}

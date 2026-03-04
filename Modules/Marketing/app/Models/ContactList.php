<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ContactList extends Model
{
    protected $table = 'marketing_contact_lists';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'source', 'segment_id',
        'contact_count', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'marketing_contact_list_members', 'list_id', 'contact_id');
    }
}

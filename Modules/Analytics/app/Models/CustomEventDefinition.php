<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custom event definitions — tenants can define their own event
 * types beyond the built-in ones, with custom schemas.
 */
final class CustomEventDefinition extends Model
{
    protected $fillable = [
        'tenant_id',
        'event_key',
        'display_name',
        'description',
        'schema',
        'is_active',
        'event_count',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'is_active' => 'boolean',
            'event_count' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}

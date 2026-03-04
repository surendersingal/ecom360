<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Channel extends Model
{
    protected $table = 'marketing_channels';

    protected $fillable = [
        'tenant_id', 'type', 'name', 'provider', 'credentials',
        'settings', 'is_active', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}

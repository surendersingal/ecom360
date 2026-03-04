<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Dashboard extends Model
{
    protected $table = 'bi_dashboards';

    protected $fillable = [
        'tenant_id', 'created_by', 'name', 'description',
        'layout', 'widgets', 'filters', 'is_default', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'widgets' => 'array',
            'filters' => 'array',
            'is_default' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}

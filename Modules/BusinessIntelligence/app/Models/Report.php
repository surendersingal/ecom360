<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Report extends Model
{
    protected $table = 'bi_reports';

    protected $fillable = [
        'tenant_id', 'created_by', 'name', 'description', 'type',
        'config', 'visualizations', 'filters', 'schedule',
        'is_public', 'is_favorite', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'visualizations' => 'array',
            'filters' => 'array',
            'schedule' => 'array',
            'is_public' => 'boolean',
            'is_favorite' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}

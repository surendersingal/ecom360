<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Kpi extends Model
{
    protected $table = 'bi_kpis';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'metric', 'calculation',
        'target_value', 'current_value', 'previous_value',
        'unit', 'direction', 'category', 'is_active', 'refresh_interval',
    ];

    protected function casts(): array
    {
        return [
            'calculation' => 'array',
            'target_value' => 'decimal:4',
            'current_value' => 'decimal:4',
            'previous_value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function alerts(): HasMany { return $this->hasMany(Alert::class, 'kpi_id'); }

    public function getChangePercentAttribute(): ?float
    {
        if (!$this->previous_value || $this->previous_value == 0) return null;
        return round((($this->current_value - $this->previous_value) / $this->previous_value) * 100, 2);
    }

    public function getIsOnTrackAttribute(): bool
    {
        if (!$this->target_value) return true;
        return $this->direction === 'up'
            ? $this->current_value >= $this->target_value
            : $this->current_value <= $this->target_value;
    }
}

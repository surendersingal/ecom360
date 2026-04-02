<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Alert extends Model
{
    protected $table = 'bi_alerts';

    protected $fillable = [
        'tenant_id', 'kpi_id', 'name', 'metric_key', 'condition', 'threshold',
        'channels', 'recipients', 'is_active', 'cooldown_minutes', 'last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold'         => 'decimal:4',
            'channels'          => 'array',
            'recipients'        => 'array',
            'is_active'         => 'boolean',
            'cooldown_minutes'  => 'integer',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function kpi(): BelongsTo { return $this->belongsTo(Kpi::class); }
    public function history(): HasMany { return $this->hasMany(AlertHistory::class, 'alert_id'); }

    public function shouldTrigger(float $value): bool
    {
        if (!$this->is_active) return false;
        $cooldown = $this->cooldown_minutes ?? 60;
        if ($this->last_triggered_at && $this->last_triggered_at->diffInMinutes(now()) < $cooldown) {
            return false;
        }

        return match ($this->condition) {
            'above' => $value > $this->threshold,
            'below' => $value < $this->threshold,
            'change_percent' => abs($value) > $this->threshold,
            'anomaly' => $value > $this->threshold, // anomaly score
            default => false,
        };
    }
}

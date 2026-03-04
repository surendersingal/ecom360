<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Prediction extends Model
{
    protected $table = 'bi_predictions';

    protected $fillable = [
        'tenant_id', 'model_type', 'entity_type', 'entity_id',
        'predicted_value', 'confidence', 'features', 'explanation',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'predicted_value' => 'decimal:4',
            'confidence' => 'decimal:4',
            'features' => 'array',
            'explanation' => 'array',
            'valid_until' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    public function getConfidenceLevelAttribute(): string
    {
        return match (true) {
            $this->confidence >= 0.9 => 'very_high',
            $this->confidence >= 0.75 => 'high',
            $this->confidence >= 0.5 => 'medium',
            $this->confidence >= 0.25 => 'low',
            default => 'very_low',
        };
    }
}

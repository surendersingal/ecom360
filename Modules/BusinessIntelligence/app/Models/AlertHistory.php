<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AlertHistory extends Model
{
    protected $table = 'bi_alert_history';

    protected $fillable = [
        'alert_id', 'triggered_value', 'threshold_value',
        'condition', 'message', 'notified_channels', 'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'triggered_value' => 'decimal:4',
            'threshold_value' => 'decimal:4',
            'notified_channels' => 'array',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function alert(): BelongsTo { return $this->belongsTo(Alert::class); }

    public function acknowledge(): void
    {
        $this->update(['acknowledged_at' => now()]);
    }
}

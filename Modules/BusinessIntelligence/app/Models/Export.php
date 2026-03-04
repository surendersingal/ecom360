<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Export extends Model
{
    protected $table = 'bi_exports';

    protected $fillable = [
        'tenant_id', 'created_by', 'report_id', 'name',
        'format', 'status', 'file_path', 'file_size',
        'row_count', 'filters', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function report(): BelongsTo { return $this->belongsTo(Report::class); }

    public function getDurationAttribute(): ?int
    {
        if (!$this->started_at || !$this->completed_at) return null;
        return $this->started_at->diffInSeconds($this->completed_at);
    }
}

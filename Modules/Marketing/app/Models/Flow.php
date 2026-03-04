<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Flow extends Model
{
    protected $table = 'marketing_flows';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'trigger_type', 'trigger_config',
        'canvas', 'status', 'enrolled_count', 'completed_count',
        'converted_count', 'revenue_attributed',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'canvas' => 'array',
            'revenue_attributed' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function nodes(): HasMany { return $this->hasMany(FlowNode::class, 'flow_id'); }
    public function edges(): HasMany { return $this->hasMany(FlowEdge::class, 'flow_id'); }
    public function enrollments(): HasMany { return $this->hasMany(FlowEnrollment::class, 'flow_id'); }

    public function getConversionRateAttribute(): float
    {
        return $this->enrolled_count > 0
            ? round(($this->converted_count / $this->enrolled_count) * 100, 2)
            : 0;
    }
}

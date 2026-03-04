<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Benchmark extends Model
{
    protected $table = 'bi_benchmarks';

    protected $fillable = [
        'tenant_id', 'metric', 'period', 'tenant_value',
        'industry_p25', 'industry_p50', 'industry_p75', 'industry_p90',
        'sample_size', 'industry', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_value' => 'decimal:4',
            'industry_p25' => 'decimal:4',
            'industry_p50' => 'decimal:4',
            'industry_p75' => 'decimal:4',
            'industry_p90' => 'decimal:4',
            'calculated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function getPercentileAttribute(): int
    {
        return match (true) {
            $this->tenant_value >= $this->industry_p90 => 90,
            $this->tenant_value >= $this->industry_p75 => 75,
            $this->tenant_value >= $this->industry_p50 => 50,
            $this->tenant_value >= $this->industry_p25 => 25,
            default => 10,
        };
    }

    public function getPerformanceLabelAttribute(): string
    {
        return match (true) {
            $this->percentile >= 90 => 'Excellent',
            $this->percentile >= 75 => 'Above Average',
            $this->percentile >= 50 => 'Average',
            $this->percentile >= 25 => 'Below Average',
            default => 'Needs Improvement',
        };
    }
}

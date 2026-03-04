<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Campaign extends Model
{
    protected $table = 'marketing_campaigns';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'type', 'channel', 'status',
        'template_id', 'audience', 'schedule', 'ab_variants',
        'total_sent', 'total_delivered', 'total_opened', 'total_clicked',
        'total_converted', 'total_bounced', 'total_unsubscribed', 'total_revenue',
        'sent_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'schedule' => 'array',
            'ab_variants' => 'array',
            'total_revenue' => 'decimal:2',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function template(): BelongsTo { return $this->belongsTo(Template::class); }
    public function messages(): HasMany { return $this->hasMany(Message::class, 'campaign_id'); }

    public function getOpenRateAttribute(): float
    {
        return $this->total_delivered > 0
            ? round(($this->total_opened / $this->total_delivered) * 100, 2)
            : 0;
    }

    public function getClickRateAttribute(): float
    {
        return $this->total_delivered > 0
            ? round(($this->total_clicked / $this->total_delivered) * 100, 2)
            : 0;
    }

    public function getConversionRateAttribute(): float
    {
        return $this->total_sent > 0
            ? round(($this->total_converted / $this->total_sent) * 100, 2)
            : 0;
    }
}

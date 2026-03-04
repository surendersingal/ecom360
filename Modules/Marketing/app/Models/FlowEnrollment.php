<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FlowEnrollment extends Model
{
    protected $table = 'marketing_flow_enrollments';

    protected $fillable = [
        'flow_id', 'contact_id', 'current_node_id', 'status',
        'context', 'entered_at', 'completed_at', 'next_action_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'entered_at' => 'datetime',
            'completed_at' => 'datetime',
            'next_action_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo { return $this->belongsTo(Flow::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function logs(): HasMany { return $this->hasMany(FlowLog::class, 'enrollment_id'); }
}

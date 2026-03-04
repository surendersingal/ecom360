<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FlowLog extends Model
{
    protected $table = 'marketing_flow_logs';

    protected $fillable = ['enrollment_id', 'node_id', 'action', 'result', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function enrollment(): BelongsTo { return $this->belongsTo(FlowEnrollment::class); }
}

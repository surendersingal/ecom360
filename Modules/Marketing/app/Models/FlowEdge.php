<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FlowEdge extends Model
{
    protected $table = 'marketing_flow_edges';

    protected $fillable = ['flow_id', 'source_node_id', 'target_node_id', 'label', 'condition'];

    protected function casts(): array
    {
        return ['condition' => 'array'];
    }

    public function flow(): BelongsTo { return $this->belongsTo(Flow::class); }
}

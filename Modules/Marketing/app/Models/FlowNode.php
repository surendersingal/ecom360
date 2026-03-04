<?php

declare(strict_types=1);

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FlowNode extends Model
{
    protected $table = 'marketing_flow_nodes';

    protected $fillable = [
        'flow_id', 'node_id', 'type', 'config', 'position', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['config' => 'array', 'position' => 'array'];
    }

    public function flow(): BelongsTo { return $this->belongsTo(Flow::class); }
}

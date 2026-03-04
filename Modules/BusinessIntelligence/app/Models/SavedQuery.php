<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SavedQuery extends Model
{
    protected $table = 'bi_saved_queries';

    protected $fillable = [
        'tenant_id', 'created_by', 'name', 'description',
        'data_source', 'query_config', 'result_columns', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'query_config' => 'array',
            'result_columns' => 'array',
            'is_public' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}

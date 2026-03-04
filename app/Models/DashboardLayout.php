<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted dashboard grid layout for a specific user within a tenant.
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property int         $user_id
 * @property string      $name
 * @property bool        $is_default
 * @property array       $layout_data
 * @property Tenant      $tenant
 * @property User        $user
 */
final class DashboardLayout extends Model
{
    protected $table = 'dashboard_layouts';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'is_default',
        'layout_data',
    ];

    protected function casts(): array
    {
        return [
            'is_default'  => 'boolean',
            'layout_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

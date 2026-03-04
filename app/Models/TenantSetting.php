<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-module, per-tenant configuration store.
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property string      $module
 * @property string      $key
 * @property mixed       $value
 * @property Tenant      $tenant
 */
final class TenantSetting extends Model
{
    protected $table = 'tenant_settings';

    protected $fillable = [
        'tenant_id',
        'module',
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}

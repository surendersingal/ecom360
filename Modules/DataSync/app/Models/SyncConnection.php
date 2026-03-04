<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\DataSync\Enums\Platform;

/**
 * A connected store (Magento / WooCommerce) belonging to a tenant.
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property string      $platform
 * @property string|null $platform_version
 * @property string|null $module_version
 * @property string      $store_url
 * @property string|null $store_name
 * @property int         $store_id
 * @property string|null $php_version
 * @property string      $locale
 * @property string      $currency
 * @property string      $timezone
 * @property bool        $is_active
 * @property \DateTime|null $last_heartbeat_at
 */
final class SyncConnection extends Model
{
    protected $table = 'sync_connections';

    protected $fillable = [
        'tenant_id',
        'platform',
        'platform_version',
        'module_version',
        'store_url',
        'store_name',
        'store_id',
        'php_version',
        'locale',
        'currency',
        'timezone',
        'is_active',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'platform'          => Platform::class,
            'store_id'          => 'integer',
            'is_active'         => 'boolean',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return HasMany<SyncPermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(SyncPermission::class, 'connection_id');
    }

    /** @return HasMany<SyncLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'connection_id');
    }
}

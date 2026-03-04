<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\DataSync\Enums\ConsentLevel;
use Modules\DataSync\Enums\SyncEntity;

/**
 * Per-entity sync consent for a connected store.
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property int         $connection_id
 * @property string      $entity
 * @property string      $consent_level
 * @property bool        $enabled
 * @property \DateTime|null $granted_at
 * @property \DateTime|null $revoked_at
 * @property string|null $granted_by
 */
final class SyncPermission extends Model
{
    protected $table = 'sync_permissions';

    protected $fillable = [
        'tenant_id',
        'connection_id',
        'entity',
        'consent_level',
        'enabled',
        'granted_at',
        'revoked_at',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'entity'        => SyncEntity::class,
            'consent_level' => ConsentLevel::class,
            'enabled'       => 'boolean',
            'granted_at'    => 'datetime',
            'revoked_at'    => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return BelongsTo<SyncConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SyncConnection::class, 'connection_id');
    }
}

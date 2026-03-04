<?php

declare(strict_types=1);

namespace Modules\DataSync\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log — one row per sync batch received from a connected store.
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property int         $connection_id
 * @property string      $entity
 * @property string      $platform
 * @property string      $direction
 * @property string      $status
 * @property int         $records_received
 * @property int         $records_created
 * @property int         $records_updated
 * @property int         $records_failed
 * @property array|null  $errors
 * @property int|null    $duration_ms
 */
final class SyncLog extends Model
{
    protected $table = 'sync_logs';

    protected $fillable = [
        'tenant_id',
        'connection_id',
        'entity',
        'platform',
        'direction',
        'status',
        'records_received',
        'records_created',
        'records_updated',
        'records_failed',
        'errors',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'records_received' => 'integer',
            'records_created'  => 'integer',
            'records_updated'  => 'integer',
            'records_failed'   => 'integer',
            'errors'           => 'json',
            'duration_ms'      => 'integer',
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

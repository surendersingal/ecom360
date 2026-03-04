<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant webhook endpoint for real-time event export.
 *
 * Store owners configure an endpoint_url and optionally a secret_key
 * (used for HMAC SHA-256 payload signing). The `subscribed_events`
 * JSON column lists which event types trigger a webhook POST.
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property string      $endpoint_url
 * @property string|null $secret_key
 * @property array       $subscribed_events  e.g. ['purchase', 'cart_abandon']
 * @property bool        $is_active
 * @property \DateTime   $created_at
 * @property \DateTime   $updated_at
 */
final class TenantWebhook extends Model
{
    use HasFactory;

    /** @var string */
    protected $table = 'tenant_webhooks';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'endpoint_url',
        'secret_key',
        'subscribed_events',
        'is_active',
    ];

    /** @var list<string> */
    protected $hidden = [
        'secret_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscribed_events' => 'array',
            'is_active'         => 'boolean',
        ];
    }

    // ------------------------------------------------------------------
    //  Relationships
    // ------------------------------------------------------------------

    /**
     * @return BelongsTo<Tenant, self>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

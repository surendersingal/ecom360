<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant;

/**
 * A configurable rule that defines when real-time frontend interventions
 * (popups, discount overlays, exit-intent modals) should fire.
 *
 * Each rule belongs to a tenant and specifies:
 *  - trigger_condition: A JSON object describing WHEN to fire.
 *    e.g. { "intent_level": "abandon_risk", "min_cart_total": 50, "event_type": "remove_from_cart" }
 *  - action_type: What type of intervention to send (popup, discount, notification, redirect).
 *  - action_payload: A JSON object with the intervention details.
 *    e.g. { "title": "Wait!", "discount_code": "SAVE10", "discount_percent": 10 }
 *  - priority: Higher priority rules are evaluated first (1-100).
 *  - is_active: Soft toggle to enable/disable without deleting.
 *  - cooldown_minutes: Minimum minutes between re-firing for the same session.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property string $name
 * @property array  $trigger_condition
 * @property string $action_type          'popup' | 'discount' | 'notification' | 'redirect'
 * @property array  $action_payload
 * @property int    $priority
 * @property bool   $is_active
 * @property int    $cooldown_minutes
 */
final class BehavioralRule extends Model
{
    use HasFactory;

    /** @var string */
    protected $table = 'behavioral_rules';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'trigger_condition',
        'action_type',
        'action_payload',
        'priority',
        'is_active',
        'cooldown_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_condition' => 'array',
            'action_payload'    => 'array',
            'priority'          => 'integer',
            'is_active'         => 'boolean',
            'cooldown_minutes'  => 'integer',
        ];
    }

    /**
     * The tenant this rule belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

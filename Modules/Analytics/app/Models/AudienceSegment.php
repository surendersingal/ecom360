<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores a dynamic audience segment definition in MySQL.
 *
 * The `rules` JSON column contains an array of filter conditions that
 * the AudienceBuilderService translates into MongoDB queries against
 * the CustomerProfile collection.
 *
 * Example rules:
 *   [
 *     {"field": "rfm_score", "operator": ">=", "value": "400"},
 *     {"field": "custom_attributes.last_event", "operator": "==", "value": "cart_abandon"}
 *   ]
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property string      $name
 * @property array       $rules
 * @property int         $member_count
 * @property bool        $is_active
 * @property \DateTime   $created_at
 * @property \DateTime   $updated_at
 */
final class AudienceSegment extends Model
{
    use HasFactory;

    /** @var string */
    protected $table = 'audience_segments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'rules',
        'member_count',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules'        => 'array',
            'member_count' => 'integer',
            'is_active'    => 'boolean',
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

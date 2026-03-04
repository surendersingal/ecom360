<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $slug
 * @property string|null $domain
 * @property bool        $is_active
 * @property bool        $is_verified
 */
final class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'api_key',
        'secret_key',
        'is_active',
        'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'is_active'   => 'boolean',
            'is_verified' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    /**
     * @return HasMany<TenantSetting, $this>
     */
    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class, 'tenant_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TenantSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Global singleton for reading / writing per-module, per-tenant settings.
 *
 * Cached per tenant+module block so a single page load never causes N+1
 * queries regardless of how many settings are read.
 *
 * Bind as a singleton in AppServiceProvider.
 */
final class SettingsRegistry
{
    /**
     * In-memory buffer so repeated reads within a single request are free.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Cache TTL in seconds (1 hour).
     */
    private const int CACHE_TTL = 3600;

    // ------------------------------------------------------------------
    //  Public API
    // ------------------------------------------------------------------

    /**
     * Retrieve a setting value for the current tenant.
     */
    public function get(string $module, string $key, mixed $default = null): mixed
    {
        $settings = $this->loadModuleSettings($module);

        return $settings[$key] ?? $default;
    }

    /**
     * Persist a setting value for the current tenant and bust the cache.
     */
    public function set(string $module, string $key, mixed $value): void
    {
        $tenantId = $this->resolveTenantId();

        TenantSetting::updateOrCreate(
            attributes: [
                'tenant_id' => $tenantId,
                'module'    => $module,
                'key'       => $key,
            ],
            values: [
                'value' => $value,
            ],
        );

        // Bust both the Redis cache and the in-memory buffer.
        $this->forgetCache($module);
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    /**
     * Load all settings for $module in a single query (cached in Redis).
     *
     * @return array<string, mixed>
     */
    private function loadModuleSettings(string $module): array
    {
        $tenantId = $this->resolveTenantId();
        $cacheKey = $this->cacheKey($tenantId, $module);

        // Check in-memory first.
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        /** @var array<string, mixed> $settings */
        $settings = Cache::remember(
            key: $cacheKey,
            ttl: self::CACHE_TTL,
            callback: static fn (): array => TenantSetting::query()
                ->where('tenant_id', $tenantId)
                ->where('module', $module)
                ->pluck('value', 'key')
                ->toArray(),
        );

        $this->cache[$cacheKey] = $settings;

        return $settings;
    }

    private function forgetCache(string $module): void
    {
        $tenantId = $this->resolveTenantId();
        $cacheKey = $this->cacheKey($tenantId, $module);

        Cache::forget($cacheKey);
        unset($this->cache[$cacheKey]);
    }

    private function cacheKey(int|string $tenantId, string $module): string
    {
        return "tenant_settings:{$tenantId}:{$module}";
    }

    private function resolveTenantId(): int
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if ($user === null || !isset($user->tenant_id)) {
            throw new \RuntimeException('Cannot resolve tenant — no authenticated user.');
        }

        return $user->tenant_id;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeoIP enrichment service.
 *
 * Resolves IP addresses to geographic & network metadata using ip-api.com
 * with aggressive Redis caching (7-day TTL) to minimize external requests.
 *
 * Returns: country, country_code, region, city, lat, lon, timezone, isp, org
 */
final class GeoIpService
{
    private const CACHE_PREFIX = 'geoip:';
    private const CACHE_TTL = 60 * 60 * 24 * 7; // 7 days
    private const API_URL = 'http://ip-api.com/json/';

    /**
     * Resolve an IP address to geographic data.
     *
     * @return array{country: string, country_code: string, region: string, city: string, lat: float, lon: float, timezone: string, isp: string, org: string}|null
     */
    public function resolve(string $ip): ?array
    {
        if ($this->isPrivateIp($ip)) {
            return $this->fallbackGeo();
        }

        $cacheKey = self::CACHE_PREFIX . md5($ip);

        return Cache::store('redis')->remember($cacheKey, self::CACHE_TTL, function () use ($ip) {
            return $this->fetchFromApi($ip);
        });
    }

    /**
     * Enrich a tracking event payload with geo data.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function enrichPayload(array $payload): array
    {
        $ip = $payload['ip_address'] ?? null;

        if (empty($ip)) {
            return $payload;
        }

        $geo = $this->resolve($ip);

        if ($geo !== null) {
            $payload['metadata'] = array_merge($payload['metadata'] ?? [], [
                'geo' => $geo,
            ]);
        }

        return $payload;
    }

    /**
     * Parse User-Agent into device type, browser, and OS.
     *
     * @return array{device_type: string, browser: string, os: string}
     */
    public function parseUserAgent(string $userAgent): array
    {
        $ua = strtolower($userAgent);

        // Device type
        $deviceType = 'Desktop';
        if (preg_match('/mobile|android.*mobile|iphone|ipod|blackberry|opera mini|iemobile/i', $ua)) {
            $deviceType = 'Mobile';
        } elseif (preg_match('/tablet|ipad|android(?!.*mobile)|kindle|silk/i', $ua)) {
            $deviceType = 'Tablet';
        }

        // Browser
        $browser = 'Unknown';
        if (str_contains($ua, 'edg/') || str_contains($ua, 'edge/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) {
            $browser = 'Opera';
        } elseif (str_contains($ua, 'chrome') && !str_contains($ua, 'edg')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'safari') && !str_contains($ua, 'chrome')) {
            $browser = 'Safari';
        } elseif (str_contains($ua, 'firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'msie') || str_contains($ua, 'trident')) {
            $browser = 'IE';
        }

        // OS
        $os = 'Unknown';
        if (str_contains($ua, 'windows')) {
            $os = 'Windows';
        } elseif (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh')) {
            $os = 'macOS';
        } elseif (str_contains($ua, 'linux') && !str_contains($ua, 'android')) {
            $os = 'Linux';
        } elseif (str_contains($ua, 'android')) {
            $os = 'Android';
        } elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) {
            $os = 'iOS';
        }

        return [
            'device_type' => $deviceType,
            'browser'     => $browser,
            'os'          => $os,
        ];
    }

    private function fetchFromApi(string $ip): ?array
    {
        try {
            $response = Http::timeout(3)
                ->get(self::API_URL . $ip, [
                    'fields' => 'status,country,countryCode,regionName,city,lat,lon,timezone,isp,org',
                ]);

            if ($response->successful() && $response->json('status') === 'success') {
                $data = $response->json();
                return [
                    'country'      => $data['country'] ?? 'Unknown',
                    'country_code' => $data['countryCode'] ?? 'XX',
                    'region'       => $data['regionName'] ?? '',
                    'city'         => $data['city'] ?? '',
                    'lat'          => (float) ($data['lat'] ?? 0),
                    'lon'          => (float) ($data['lon'] ?? 0),
                    'timezone'     => $data['timezone'] ?? '',
                    'isp'          => $data['isp'] ?? '',
                    'org'          => $data['org'] ?? '',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("[GeoIP] API request failed for {$ip}: {$e->getMessage()}");
        }

        return $this->fallbackGeo();
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Fallback for private/local IPs during development.
     */
    private function fallbackGeo(): array
    {
        return [
            'country'      => 'Local',
            'country_code' => 'XX',
            'region'       => '',
            'city'         => 'Localhost',
            'lat'          => 0.0,
            'lon'          => 0.0,
            'timezone'     => config('app.timezone', 'UTC'),
            'isp'          => 'Local Network',
            'org'          => '',
        ];
    }
}

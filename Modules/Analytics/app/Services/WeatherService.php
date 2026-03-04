<?php
declare(strict_types=1);

namespace Modules\Analytics\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WeatherService — Weather-based marketing triggers and analytics.
 *
 * Powers Use Cases:
 *   - Weather-Adjusted Promotion (UC14)
 *   - Seasonal product recommendations
 */
class WeatherService
{
    private const CACHE_TTL = 1800; // 30 min
    private const OPENWEATHER_API = 'https://api.openweathermap.org/data/2.5/weather';

    /**
     * Get current weather for a location.
     */
    public function getWeather(string $city, ?string $countryCode = null): array
    {
        $location = $countryCode ? "{$city},{$countryCode}" : $city;
        $cacheKey = "weather:" . md5($location);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($location) {
            return $this->fetchWeather($location);
        });
    }

    /**
     * Get weather-based product recommendations.
     */
    public function getWeatherRecommendations(int|string $tenantId, string $city, ?string $countryCode = null): array
    {
        $weather = $this->getWeather($city, $countryCode);
        if (isset($weather['error'])) {
            return ['recommendations' => [], 'weather' => $weather];
        }

        $condition = $weather['condition'] ?? 'clear';
        $temp = $weather['temperature'] ?? 20;

        // Map weather to product categories/tags
        $productTags = $this->weatherToProductTags($condition, $temp);

        try {
            // Find matching products
            $query = DB::connection('mongodb')
                ->table('synced_products')
                ->where('tenant_id', $tenantId)
                ->where('stock_qty', '>', 0);

            $query->where(function ($q) use ($productTags) {
                foreach ($productTags as $tag) {
                    $escaped = preg_quote($tag, '/');
                    $q->orWhere('name', 'regex', "/{$escaped}/i")
                      ->orWhere('category', 'regex', "/{$escaped}/i")
                      ->orWhere('tags', 'regex', "/{$escaped}/i");
                }
            });

            $products = $query->limit(20)->get();

            return [
                'weather'         => $weather,
                'product_tags'    => $productTags,
                'recommendations' => $products->map(fn($p) => [
                    'id'       => $p['external_id'] ?? (string) ($p['_id'] ?? ''),
                    'name'     => $p['name'] ?? '',
                    'price'    => $p['price'] ?? 0,
                    'image'    => $p['image'] ?? null,
                    'category' => $p['category'] ?? null,
                ])->values()->toArray(),
                'promotion_message' => $this->getPromotionMessage($condition, $temp),
            ];
        } catch (\Exception $e) {
            Log::error("WeatherService::getWeatherRecommendations error: {$e->getMessage()}");
            return ['recommendations' => [], 'weather' => $weather, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if weather conditions warrant a promotion trigger.
     */
    public function shouldTriggerPromotion(string $city, ?string $countryCode = null): array
    {
        $weather = $this->getWeather($city, $countryCode);
        if (isset($weather['error'])) {
            return ['trigger' => false, 'reason' => $weather['error']];
        }

        $condition = $weather['condition'] ?? 'clear';
        $temp = $weather['temperature'] ?? 20;

        // Define trigger conditions
        $triggers = [];

        // Extreme cold — push winter gear
        if ($temp < 0) {
            $triggers[] = ['type' => 'cold_snap', 'message' => 'Extreme cold alert! Stay warm with our winter collection.', 'discount' => 15];
        }
        // Rain — push umbrellas, rain gear
        if (in_array($condition, ['rain', 'drizzle', 'thunderstorm'])) {
            $triggers[] = ['type' => 'rainy_day', 'message' => 'Rainy day? We\'ve got you covered!', 'discount' => 10];
        }
        // Heatwave
        if ($temp > 35) {
            $triggers[] = ['type' => 'heatwave', 'message' => 'Beat the heat with our cool summer essentials!', 'discount' => 12];
        }
        // Sunny weekend
        if ($condition === 'clear' && $temp >= 20 && $temp <= 30) {
            $triggers[] = ['type' => 'sunny_day', 'message' => 'Perfect weather for outdoor adventures!', 'discount' => 0];
        }
        // Snow
        if ($condition === 'snow') {
            $triggers[] = ['type' => 'snow_day', 'message' => 'Snow day specials on winter essentials!', 'discount' => 15];
        }

        return [
            'trigger'    => !empty($triggers),
            'weather'    => $weather,
            'promotions' => $triggers,
        ];
    }

    /**
     * Get weather analytics correlation with sales.
     */
    public function getWeatherSalesCorrelation(int|string $tenantId, string $city, int $days = 30): array
    {
        try {
            $purchases = DB::connection('mongodb')
                ->table('events')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'purchase')
                ->where('created_at', '>=', now()->subDays($days)->toDateTimeString())
                ->get();

            // Group sales by date
            $dailySales = $purchases->groupBy(function ($p) {
                try {
                    return now()->parse($p['created_at'])->format('Y-m-d');
                } catch (\Exception $e) {
                    return 'unknown';
                }
            })->map(fn($group) => [
                'revenue' => $group->sum(fn($p) => (float) ($p['metadata']['total'] ?? 0)),
                'orders'  => $group->count(),
            ]);

            return [
                'city'       => $city,
                'period'     => $days,
                'daily_data' => $dailySales->toArray(),
                'note'       => 'Correlate with historical weather data for full analysis. Requires weather history API key.',
            ];
        } catch (\Exception $e) {
            Log::error("WeatherService::getWeatherSalesCorrelation error: {$e->getMessage()}");
            return ['error' => $e->getMessage()];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function fetchWeather(string $location): array
    {
        $apiKey = config('services.openweather.api_key', '');

        if (empty($apiKey)) {
            // Return mock data when no API key configured
            return [
                'city'        => $location,
                'temperature' => 22,
                'feels_like'  => 23,
                'humidity'    => 60,
                'condition'   => 'clear',
                'description' => 'clear sky',
                'wind_speed'  => 3.5,
                'icon'        => '01d',
                'source'      => 'mock',
                'note'        => 'Set OPENWEATHER_API_KEY in .env for real weather data.',
            ];
        }

        try {
            $response = Http::timeout(5)->get(self::OPENWEATHER_API, [
                'q'     => $location,
                'appid' => $apiKey,
                'units' => 'metric',
            ]);

            if (!$response->successful()) {
                return ['error' => 'Weather API error: ' . $response->status()];
            }

            $data = $response->json();

            return [
                'city'        => $data['name'] ?? $location,
                'temperature' => $data['main']['temp'] ?? 0,
                'feels_like'  => $data['main']['feels_like'] ?? 0,
                'humidity'    => $data['main']['humidity'] ?? 0,
                'condition'   => strtolower($data['weather'][0]['main'] ?? 'clear'),
                'description' => $data['weather'][0]['description'] ?? '',
                'wind_speed'  => $data['wind']['speed'] ?? 0,
                'icon'        => $data['weather'][0]['icon'] ?? null,
                'source'      => 'openweathermap',
            ];
        } catch (\Exception $e) {
            Log::error("WeatherService::fetchWeather error: {$e->getMessage()}");
            return ['error' => $e->getMessage()];
        }
    }

    private function weatherToProductTags(string $condition, float $temp): array
    {
        $tags = [];

        // Temperature-based
        if ($temp < 5) {
            $tags = array_merge($tags, ['winter', 'coat', 'jacket', 'scarf', 'gloves', 'boots', 'thermal', 'fleece']);
        } elseif ($temp < 15) {
            $tags = array_merge($tags, ['sweater', 'hoodie', 'jacket', 'jeans', 'layering']);
        } elseif ($temp < 25) {
            $tags = array_merge($tags, ['casual', 'sneakers', 'light jacket', 'denim']);
        } else {
            $tags = array_merge($tags, ['summer', 'shorts', 'sandals', 'sunglasses', 'tank top', 'lightweight', 'swimwear']);
        }

        // Condition-based
        $conditionTags = match ($condition) {
            'rain', 'drizzle'  => ['umbrella', 'raincoat', 'waterproof', 'rain boots'],
            'thunderstorm'     => ['umbrella', 'raincoat', 'waterproof'],
            'snow'             => ['snow boots', 'winter coat', 'warm', 'insulated'],
            'clear'            => ['outdoor', 'sunglasses', 'sun hat'],
            'clouds'           => ['layering', 'comfortable'],
            'mist', 'fog'      => ['reflective', 'bright colors'],
            default            => [],
        };

        return array_unique(array_merge($tags, $conditionTags));
    }

    private function getPromotionMessage(string $condition, float $temp): string
    {
        if ($temp < 0) return "🥶 It's freezing! Warm up with 15% off winter essentials.";
        if ($temp < 10) return "🧥 Cold out there! Perfect day for cozy layers.";
        if (in_array($condition, ['rain', 'drizzle'])) return "🌧️ Rainy day alert! Stay dry with our rain gear.";
        if ($condition === 'snow') return "❄️ Snow day! Bundle up with our winter collection.";
        if ($temp > 35) return "🔥 Heatwave! Cool down with summer essentials.";
        if ($temp > 25) return "☀️ Beautiful day! Check out our summer collection.";
        return "🌤️ Great weather for shopping! Browse our latest arrivals.";
    }
}

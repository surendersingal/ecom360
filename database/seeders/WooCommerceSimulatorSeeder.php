<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use Modules\Analytics\Models\AudienceSegment;
use Modules\Analytics\Models\BehavioralRule;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\CustomEventDefinition;
use Modules\Analytics\Models\TenantWebhook;
use Modules\Analytics\Models\TrackingEvent;

/**
 * 🛒 WooCommerce Store Simulator
 *
 * Simulates a real WordPress/WooCommerce fashion store ("Urban Style Co.")
 * generating ~3500 realistic tracking events across 90 days, with:
 *
 * - 25 products across 5 categories
 * - 50 identified customers + ~250 anonymous visitors
 * - Realistic ecommerce funnel (browse → view → cart → checkout → purchase)
 * - Multi-channel acquisition (organic, paid search, social, email, direct)
 * - Geographic distribution (7 countries, 18 cities)
 * - Device variety (desktop, mobile, tablet)
 * - Customer profiles with RFM scores for cohort/segment analysis
 * - Audience segments, behavioral rules, webhooks, custom event definitions
 *
 * Also seeds lightweight data for existing tenants so admin comparison views work.
 *
 * Usage: php artisan db:seed --class=WooCommerceSimulatorSeeder
 */
final class WooCommerceSimulatorSeeder extends Seeder
{
    // ─── Store Configuration ─────────────────────────────────────────────

    private const STORE_NAME    = 'Urban Style Co.';
    private const STORE_SLUG    = 'urban-style-co';
    private const STORE_DOMAIN  = 'urbanstyleco.com';
    private const USER_EMAIL    = 'admin@urbanstyleco.com';
    private const USER_PASSWORD = 'password';
    private const API_KEY       = 'woo_live_sk_urbanstyle_2026_prod';
    private const BASE_URL      = 'https://urbanstyleco.com';
    private const DAYS_BACK     = 90;

    // ─── Product Catalog ─────────────────────────────────────────────────

    private const PRODUCTS = [
        ['id' => 'WC-1001', 'name' => 'Classic Fit Oxford Shirt',    'price' => 49.99,  'cat' => 'Mens Clothing'],
        ['id' => 'WC-1002', 'name' => 'Slim Denim Jeans',            'price' => 69.99,  'cat' => 'Mens Clothing'],
        ['id' => 'WC-1003', 'name' => 'Leather Belt',                'price' => 29.99,  'cat' => 'Accessories'],
        ['id' => 'WC-1004', 'name' => 'Running Sneakers Pro',        'price' => 119.99, 'cat' => 'Shoes'],
        ['id' => 'WC-1005', 'name' => 'Cashmere Crew Sweater',       'price' => 89.99,  'cat' => 'Womens Clothing'],
        ['id' => 'WC-1006', 'name' => 'Floral Print Dress',          'price' => 65.00,  'cat' => 'Womens Clothing'],
        ['id' => 'WC-1007', 'name' => 'Canvas Tote Bag',             'price' => 35.00,  'cat' => 'Accessories'],
        ['id' => 'WC-1008', 'name' => 'Wireless Earbuds X3',         'price' => 79.99,  'cat' => 'Electronics'],
        ['id' => 'WC-1009', 'name' => 'Aviator Sunglasses',          'price' => 45.00,  'cat' => 'Accessories'],
        ['id' => 'WC-1010', 'name' => 'Cotton Polo Shirt',           'price' => 39.99,  'cat' => 'Mens Clothing'],
        ['id' => 'WC-1011', 'name' => 'High-Waist Yoga Pants',       'price' => 55.00,  'cat' => 'Womens Clothing'],
        ['id' => 'WC-1012', 'name' => 'Chelsea Boots',               'price' => 149.99, 'cat' => 'Shoes'],
        ['id' => 'WC-1013', 'name' => 'Smart Watch Band',            'price' => 24.99,  'cat' => 'Electronics'],
        ['id' => 'WC-1014', 'name' => 'Linen Blazer',                'price' => 129.99, 'cat' => 'Mens Clothing'],
        ['id' => 'WC-1015', 'name' => 'Silk Scarf',                  'price' => 42.00,  'cat' => 'Accessories'],
        ['id' => 'WC-1016', 'name' => 'Platform Sneakers',           'price' => 89.99,  'cat' => 'Shoes'],
        ['id' => 'WC-1017', 'name' => 'Graphic Print T-Shirt',       'price' => 24.99,  'cat' => 'Mens Clothing'],
        ['id' => 'WC-1018', 'name' => 'Crossbody Bag',               'price' => 58.00,  'cat' => 'Accessories'],
        ['id' => 'WC-1019', 'name' => 'Bluetooth Speaker Mini',      'price' => 49.99,  'cat' => 'Electronics'],
        ['id' => 'WC-1020', 'name' => 'Pleated Midi Skirt',          'price' => 52.00,  'cat' => 'Womens Clothing'],
        ['id' => 'WC-1021', 'name' => 'Leather Wallet',              'price' => 39.99,  'cat' => 'Accessories'],
        ['id' => 'WC-1022', 'name' => 'Trail Hiking Boots',          'price' => 159.99, 'cat' => 'Shoes'],
        ['id' => 'WC-1023', 'name' => 'Wireless Charging Pad',       'price' => 34.99,  'cat' => 'Electronics'],
        ['id' => 'WC-1024', 'name' => 'Oversized Hoodie',            'price' => 45.00,  'cat' => 'Womens Clothing'],
        ['id' => 'WC-1025', 'name' => 'Statement Necklace',          'price' => 28.00,  'cat' => 'Accessories'],
    ];

    // ─── Geo Locations (weighted) ────────────────────────────────────────

    private const LOCATIONS = [
        ['country' => 'United States',  'cc' => 'US', 'tz' => 'America/New_York',    'w' => 55, 'cities' => [
            ['c' => 'New York',      'r' => 'New York',       'lat' => 40.71, 'lon' => -74.01],
            ['c' => 'Los Angeles',   'r' => 'California',     'lat' => 34.05, 'lon' => -118.24],
            ['c' => 'Chicago',       'r' => 'Illinois',       'lat' => 41.88, 'lon' => -87.63],
            ['c' => 'Houston',       'r' => 'Texas',          'lat' => 29.76, 'lon' => -95.37],
            ['c' => 'San Francisco', 'r' => 'California',     'lat' => 37.77, 'lon' => -122.42],
            ['c' => 'Miami',         'r' => 'Florida',        'lat' => 25.76, 'lon' => -80.19],
        ]],
        ['country' => 'United Kingdom', 'cc' => 'GB', 'tz' => 'Europe/London',       'w' => 15, 'cities' => [
            ['c' => 'London',        'r' => 'England',        'lat' => 51.51, 'lon' => -0.13],
            ['c' => 'Manchester',    'r' => 'England',        'lat' => 53.48, 'lon' => -2.24],
            ['c' => 'Birmingham',    'r' => 'England',        'lat' => 52.49, 'lon' => -1.89],
        ]],
        ['country' => 'Canada',         'cc' => 'CA', 'tz' => 'America/Toronto',     'w' => 10, 'cities' => [
            ['c' => 'Toronto',       'r' => 'Ontario',        'lat' => 43.65, 'lon' => -79.38],
            ['c' => 'Vancouver',     'r' => 'British Columbia','lat' => 49.28, 'lon' => -123.12],
        ]],
        ['country' => 'Germany',        'cc' => 'DE', 'tz' => 'Europe/Berlin',       'w' => 7, 'cities' => [
            ['c' => 'Berlin',        'r' => 'Berlin',         'lat' => 52.52, 'lon' => 13.40],
            ['c' => 'Munich',        'r' => 'Bavaria',        'lat' => 48.14, 'lon' => 11.58],
        ]],
        ['country' => 'Australia',      'cc' => 'AU', 'tz' => 'Australia/Sydney',    'w' => 5, 'cities' => [
            ['c' => 'Sydney',        'r' => 'New South Wales','lat' => -33.87, 'lon' => 151.21],
            ['c' => 'Melbourne',     'r' => 'Victoria',       'lat' => -37.81, 'lon' => 144.96],
        ]],
        ['country' => 'India',          'cc' => 'IN', 'tz' => 'Asia/Kolkata',        'w' => 5, 'cities' => [
            ['c' => 'Mumbai',        'r' => 'Maharashtra',    'lat' => 19.08, 'lon' => 72.88],
            ['c' => 'Bangalore',     'r' => 'Karnataka',      'lat' => 12.97, 'lon' => 77.59],
        ]],
        ['country' => 'France',         'cc' => 'FR', 'tz' => 'Europe/Paris',        'w' => 3, 'cities' => [
            ['c' => 'Paris',         'r' => 'Île-de-France',  'lat' => 48.86, 'lon' => 2.35],
        ]],
    ];

    // ─── Devices (weighted) ──────────────────────────────────────────────

    private const DEVICES = [
        ['type' => 'Desktop', 'browser' => 'Chrome',  'os' => 'Windows 10',  'w' => 32,
         'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'],
        ['type' => 'Desktop', 'browser' => 'Safari',  'os' => 'macOS',       'w' => 12,
         'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15'],
        ['type' => 'Desktop', 'browser' => 'Firefox', 'os' => 'Windows 11',  'w' => 6,
         'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0'],
        ['type' => 'Mobile',  'browser' => 'Chrome',  'os' => 'Android 14',  'w' => 22,
         'ua' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36'],
        ['type' => 'Mobile',  'browser' => 'Safari',  'os' => 'iOS 17',      'w' => 22,
         'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1'],
        ['type' => 'Tablet',  'browser' => 'Safari',  'os' => 'iPadOS 17',   'w' => 6,
         'ua' => 'Mozilla/5.0 (iPad; CPU OS 17_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1'],
    ];

    // ─── Traffic Channels (weighted) ─────────────────────────────────────

    private const CHANNELS = [
        ['source' => 'google',    'medium' => 'organic',  'campaign' => null,                   'referrer' => 'https://www.google.com/search?q=fashion+online+store', 'w' => 28],
        ['source' => 'direct',    'medium' => 'none',     'campaign' => null,                   'referrer' => null,                                                   'w' => 22],
        ['source' => 'google',    'medium' => 'cpc',      'campaign' => 'spring-collection-26', 'referrer' => 'https://www.google.com/aclk?sa=l',                     'w' => 18],
        ['source' => 'facebook',  'medium' => 'cpc',      'campaign' => 'retargeting-q1-2026',  'referrer' => 'https://www.facebook.com/',                            'w' => 12],
        ['source' => 'instagram', 'medium' => 'social',   'campaign' => 'influencer-feb-2026',  'referrer' => 'https://www.instagram.com/',                           'w' => 10],
        ['source' => 'mailchimp', 'medium' => 'email',    'campaign' => 'seasonal-newsletter',  'referrer' => 'https://mail.google.com/',                             'w' => 7],
        ['source' => 'bing',      'medium' => 'organic',  'campaign' => null,                   'referrer' => 'https://www.bing.com/search?q=urban+style+clothing',   'w' => 3],
    ];

    // ─── Page URLs (for browsing events) ─────────────────────────────────

    private const PAGES = [
        '/'                          => 'Urban Style Co. | Fashion & Lifestyle',
        '/shop'                      => 'Shop All Products | Urban Style Co.',
        '/shop/mens-clothing'        => 'Men\'s Clothing | Urban Style Co.',
        '/shop/womens-clothing'      => 'Women\'s Clothing | Urban Style Co.',
        '/shop/shoes'                => 'Shoes & Footwear | Urban Style Co.',
        '/shop/accessories'          => 'Accessories | Urban Style Co.',
        '/shop/electronics'          => 'Electronics & Gadgets | Urban Style Co.',
        '/about'                     => 'About Us | Urban Style Co.',
        '/contact'                   => 'Contact Us | Urban Style Co.',
        '/blog'                      => 'Style Blog | Urban Style Co.',
        '/blog/spring-fashion-trends'=> 'Spring Fashion Trends 2026 | Blog',
        '/sale'                      => 'Sale & Clearance | Urban Style Co.',
    ];

    // ─── Customer Names ──────────────────────────────────────────────────

    private const FIRST_NAMES = [
        'Emma','Oliver','Sophia','James','Ava','William','Isabella','Benjamin','Mia','Henry',
        'Charlotte','Alexander','Amelia','Sebastian','Harper','Jack','Evelyn','Daniel','Abigail','Matthew',
        'Emily','Lucas','Elizabeth','Mason','Sofia','Ethan','Ella','Logan','Madison','Aiden',
        'Scarlett','Jackson','Victoria','Liam','Aria','Noah','Grace','Elijah','Chloe','Owen',
        'Penelope','Caleb','Layla','Ryan','Riley','Nathan','Zoey','Dylan','Nora','Leo',
    ];

    private const LAST_NAMES = [
        'Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez',
        'Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin',
        'Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson',
        'Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores',
        'Green','Adams','Nelson','Baker','Hall','Rivera','Campbell','Mitchell','Carter','Roberts',
    ];

    // IP prefix per country (for realistic IP generation)
    private const IP_PREFIXES = ['US' => '24', 'GB' => '86', 'CA' => '99', 'DE' => '85', 'AU' => '1', 'IN' => '49', 'FR' => '78'];

    // ─── State ───────────────────────────────────────────────────────────

    private string $tenantId;
    /** @var array<string, array{name: string, email: string, sessions: list<string>}> */
    private array $customers = [];
    /** @var list<array> all events to bulk-insert */
    private array $events = [];
    private int $purchaseCount = 0;
    private int $sessionCount  = 0;

    // ═════════════════════════════════════════════════════════════════════
    //  MAIN
    // ═════════════════════════════════════════════════════════════════════

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('🛒  W O O C O M M E R C E   S I M U L A T O R');
        $this->command->info('   Generating realistic store data for "' . self::STORE_NAME . '"');
        $this->command->info('');

        // 1 ─ Tenant & User
        $tenant = $this->createTenantAndUser();
        $this->tenantId = (string) $tenant->id;

        // 2 ─ Pre-generate customer identities
        $this->generateCustomerIdentities();

        // 3 ─ Generate 90 days of tracking events
        $this->generateAllTrackingEvents();

        // 4 ─ Bulk-insert events into MongoDB
        $this->bulkInsertEvents();

        // 5 ─ Create customer profiles with RFM
        $this->createCustomerProfiles();

        // 6 ─ Seed MySQL analytics tables
        $this->seedAudienceSegments($tenant);
        $this->seedBehavioralRules($tenant);
        $this->seedWebhooks($tenant);
        $this->seedCustomEventDefinitions($tenant);

        // 7 ─ Seed lightweight data for existing tenants
        $this->seedExistingTenants();

        $this->command->info('');
        $this->command->info("✅  Done!");
        $this->command->info("   • Tenant:    {$tenant->name} (ID: {$tenant->id})");
        $this->command->info("   • Events:    " . number_format(count($this->events)));
        $this->command->info("   • Sessions:  " . number_format($this->sessionCount));
        $this->command->info("   • Customers: " . count($this->customers));
        $this->command->info("   • Purchases: " . number_format($this->purchaseCount));
        $this->command->info('');
    }

    // ═════════════════════════════════════════════════════════════════════
    //  1. TENANT & USER
    // ═════════════════════════════════════════════════════════════════════

    private function createTenantAndUser(): Tenant
    {
        $this->command->info('  → Creating tenant & user...');

        $tenant = Tenant::updateOrCreate(
            ['slug' => self::STORE_SLUG],
            [
                'name'        => self::STORE_NAME,
                'domain'      => self::STORE_DOMAIN,
                'api_key'     => self::API_KEY,
                'is_active'   => true,
                'is_verified' => true,
            ],
        );

        User::updateOrCreate(
            ['email' => self::USER_EMAIL],
            [
                'tenant_id' => $tenant->id,
                'name'      => 'Store Admin',
                'password'  => bcrypt(self::USER_PASSWORD),
            ],
        );

        return $tenant;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  2. CUSTOMER IDENTITIES
    // ═════════════════════════════════════════════════════════════════════

    private function generateCustomerIdentities(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $first = self::FIRST_NAMES[$i];
            $last  = self::LAST_NAMES[$i];
            $email = strtolower($first . '.' . $last . '@gmail.com');

            $this->customers[$email] = [
                'name'     => "$first $last",
                'email'    => $email,
                'sessions' => [],
            ];
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  3. GENERATE ALL TRACKING EVENTS
    // ═════════════════════════════════════════════════════════════════════

    private function generateAllTrackingEvents(): void
    {
        $this->command->info('  → Generating tracking events over ' . self::DAYS_BACK . ' days...');

        $startDate = Carbon::now()->subDays(self::DAYS_BACK)->startOfDay();
        $today     = Carbon::now();
        $bar       = $this->command->getOutput()->createProgressBar(self::DAYS_BACK);

        for ($day = 0; $day <= self::DAYS_BACK; $day++) {
            $date = $startDate->copy()->addDays($day);

            // More traffic in recent days, weekends slightly busier
            $isWeekend = $date->isWeekend();
            $isRecent  = $day > (self::DAYS_BACK - 30);
            $baseSessions = $isRecent ? rand(6, 14) : rand(3, 8);
            $sessionsToday = $isWeekend ? $baseSessions + rand(1, 3) : $baseSessions;

            // Extra spike: last 2 days get bonus sessions for "realtime" dashboards
            if ($day >= self::DAYS_BACK - 1) {
                $sessionsToday += rand(5, 10);
            }

            for ($s = 0; $s < $sessionsToday; $s++) {
                $this->generateOneSession($date);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->info('');
    }

    private function generateOneSession(Carbon $date): void
    {
        $this->sessionCount++;
        $sessionId = 'sess_' . Str::random(24);

        // Pick channel, device, location
        $channel  = $this->weightedPick(self::CHANNELS);
        $device   = $this->weightedPick(self::DEVICES);
        $location = $this->weightedPick(self::LOCATIONS);
        $city     = $location['cities'][array_rand($location['cities'])];

        // 40% of sessions are known customers; 60% anonymous
        $customerEmail = null;
        if (rand(1, 100) <= 40) {
            $emails = array_keys($this->customers);
            $customerEmail = $emails[array_rand($emails)];
            $this->customers[$customerEmail]['sessions'][] = $sessionId;
        }

        // Generate random hour (weighted toward daytime 8am-10pm)
        $hour   = $this->daytimeHour();
        $minute = rand(0, 59);
        $time   = $date->copy()->setTime($hour, $minute, rand(0, 59));
        $ip     = $this->randomIp($location['cc']);

        // Base metadata for this session
        $baseMeta = [
            'geo' => [
                'country'      => $location['country'],
                'country_code' => $location['cc'],
                'city'         => $city['c'],
                'region'       => $city['r'],
                'lat'          => $city['lat'],
                'lon'          => $city['lon'],
                'timezone'     => $location['tz'],
            ],
            'device' => [
                'device_type' => $device['type'],
                'browser'     => $device['browser'],
                'os'          => $device['os'],
            ],
        ];

        // ── Page Views (1-3) ──────────────────────────────────────────
        $pageUrls = array_keys(self::PAGES);
        $pageTitles = array_values(self::PAGES);
        $pvCount = rand(1, 3);

        for ($i = 0; $i < $pvCount; $i++) {
            $idx = ($i === 0) ? 0 : rand(0, count($pageUrls) - 1); // first hit is usually homepage
            $meta = $baseMeta;
            if ($i === 0 && $channel['referrer']) {
                $meta['referrer'] = $channel['referrer'];
            }
            if ($channel['campaign']) {
                $meta['utm_source']   = $channel['source'];
                $meta['utm_medium']   = $channel['medium'];
                $meta['utm_campaign'] = $channel['campaign'];
            }

            $this->addEvent($sessionId, 'page_view', self::BASE_URL . $pageUrls[$idx], $meta, $ip, $device['ua'], $time);
            $time = $time->copy()->addSeconds(rand(8, 45));
        }

        // ── Campaign Event (for paid channels) ───────────────────────
        if ($channel['medium'] === 'cpc' || $channel['medium'] === 'social') {
            $campaignMeta = $baseMeta;
            $campaignMeta['campaign_name'] = $channel['campaign'];
            $campaignMeta['utm_source']    = $channel['source'];
            $campaignMeta['utm_medium']    = $channel['medium'];
            $campaignMeta['source']        = $channel['source'];
            $campaignMeta['medium']        = $channel['medium'];
            $this->addEvent($sessionId, 'campaign_event', self::BASE_URL . '/shop', $campaignMeta, $ip, $device['ua'], $time);
            $time = $time->copy()->addSeconds(rand(2, 5));
        }

        // ── Search Event (10% of sessions) ───────────────────────────
        if (rand(1, 100) <= 10) {
            $searchTerms = ['denim jeans', 'running shoes', 'wireless earbuds', 'leather wallet', 'hoodie', 'dress', 'sneakers'];
            $searchMeta = $baseMeta;
            $searchMeta['query'] = $searchTerms[array_rand($searchTerms)];
            $this->addEvent($sessionId, 'search', self::BASE_URL . '/search', $searchMeta, $ip, $device['ua'], $time);
            $time = $time->copy()->addSeconds(rand(5, 15));
        }

        // ── Product Views (55% of sessions) ──────────────────────────
        if (rand(1, 100) > 55) {
            return; // bounce — session ends after browsing
        }

        $viewedProducts = $this->pickRandomProducts(rand(1, 4));
        foreach ($viewedProducts as $product) {
            $meta = $baseMeta;
            $meta['product_id']    = $product['id'];
            $meta['product_name']  = $product['name'];
            $meta['product_price'] = $product['price'];
            $meta['category']      = $product['cat'];

            $slug = Str::slug($product['name']);
            $this->addEvent($sessionId, 'product_view', self::BASE_URL . "/product/{$slug}", $meta, $ip, $device['ua'], $time);
            $time = $time->copy()->addSeconds(rand(20, 90));
        }

        // ── Add to Cart (30% of product viewers) ─────────────────────
        if (rand(1, 100) > 30) {
            return;
        }

        $cartProducts = array_slice($viewedProducts, 0, rand(1, min(3, count($viewedProducts))));
        foreach ($cartProducts as $product) {
            $meta = $baseMeta;
            $meta['product_id']    = $product['id'];
            $meta['product_name']  = $product['name'];
            $meta['product_price'] = $product['price'];
            $meta['category']      = $product['cat'];

            $this->addEvent($sessionId, 'add_to_cart', self::BASE_URL . '/cart', $meta, $ip, $device['ua'], $time);
            $time = $time->copy()->addSeconds(rand(3, 12));
        }

        // ── Remove from Cart (15% of cart adders remove one item) ────
        if (count($cartProducts) > 1 && rand(1, 100) <= 15) {
            $removed = $cartProducts[array_rand($cartProducts)];
            $meta = $baseMeta;
            $meta['product_id']   = $removed['id'];
            $meta['product_name'] = $removed['name'];
            $this->addEvent($sessionId, 'remove_from_cart', self::BASE_URL . '/cart', $meta, $ip, $device['ua'], $time);
            $time = $time->copy()->addSeconds(rand(5, 15));
            // Remove from cart array
            $cartProducts = array_filter($cartProducts, fn($p) => $p['id'] !== $removed['id']);
            $cartProducts = array_values($cartProducts);
        }

        if (empty($cartProducts)) {
            return;
        }

        // ── Begin Checkout (60% of cart adders) ──────────────────────
        if (rand(1, 100) > 60) {
            return;
        }

        $this->addEvent($sessionId, 'begin_checkout', self::BASE_URL . '/checkout', $baseMeta, $ip, $device['ua'], $time);
        $time = $time->copy()->addSeconds(rand(30, 180));

        // ── Purchase (75% of checkout starters) ──────────────────────
        if (rand(1, 100) > 75) {
            return;
        }

        $orderTotal = array_sum(array_column($cartProducts, 'price'));
        $orderTotal = round($orderTotal * (rand(90, 110) / 100), 2); // slight variance (tax/discount)

        $meta = $baseMeta;
        $meta['order_total']  = $orderTotal;
        $meta['product_id']   = $cartProducts[0]['id'];
        $meta['product_name'] = $cartProducts[0]['name'];
        $meta['category']     = $cartProducts[0]['cat'];
        $meta['items_count']  = count($cartProducts);
        $meta['products']     = array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'price' => $p['price']], $cartProducts);

        // Attribution
        if ($channel['campaign']) {
            $meta['attribution'] = [
                'source'    => $channel['source'] . '-ads',
                'source_id' => 'gclid_' . Str::random(12),
            ];
        } else {
            $meta['attribution'] = [
                'source'    => $channel['source'],
                'source_id' => $channel['source'] . '_' . Str::random(8),
            ];
        }

        // Multi-touch
        $meta['multi_touch_attribution'] = [
            'first_touch' => ['source' => $channel['source'], 'event_type' => 'page_view'],
            'last_touch'  => ['source' => $channel['source'], 'event_type' => 'product_view'],
            'touch_count' => rand(2, 6),
        ];

        $this->addEvent($sessionId, 'purchase', self::BASE_URL . '/order-confirmation', $meta, $ip, $device['ua'], $time);
        $this->purchaseCount++;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  4. BULK INSERT INTO MONGODB
    // ═════════════════════════════════════════════════════════════════════

    private function bulkInsertEvents(): void
    {
        $this->command->info('  → Inserting ' . count($this->events) . ' events into MongoDB...');

        $mongo      = app('db')->connection('mongodb');
        $collection = $mongo->getCollection('tracking_events');

        // Insert in chunks of 500 for memory efficiency
        foreach (array_chunk($this->events, 500) as $chunk) {
            $collection->insertMany($chunk);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  5. CUSTOMER PROFILES
    // ═════════════════════════════════════════════════════════════════════

    private function createCustomerProfiles(): void
    {
        $this->command->info('  → Creating customer profiles with RFM scores...');

        // Distribute RFM scores: VIP → At Risk
        $rfmDistribution = [
            '555' => 3,  '554' => 2,  '545' => 2,  // 7 VIPs
            '444' => 4,  '443' => 3,  '434' => 3,  // 10 Loyals
            '333' => 5,  '332' => 4,  '323' => 3,  // 12 Promising
            '222' => 4,  '221' => 3,  '212' => 4,  // 11 Need Attention
            '111' => 4,  '112' => 3,  '121' => 3,  // 10 At Risk
        ];

        $rfmScores = [];
        foreach ($rfmDistribution as $score => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rfmScores[] = (string) $score;
            }
        }
        shuffle($rfmScores);

        $idx = 0;
        foreach ($this->customers as $email => $customer) {
            if (empty($customer['sessions'])) {
                // Customers with no sessions in this period still get a profile
                $customer['sessions'] = ['sess_historical_' . Str::random(12)];
            }

            $rfm = (string) $rfmScores[$idx % count($rfmScores)];
            $r   = (int) $rfm[0];
            $f   = (int) $rfm[1];
            $m   = (int) $rfm[2];

            CustomerProfile::updateOrCreate(
                ['tenant_id' => $this->tenantId, 'identifier_value' => $email],
                [
                    'identifier_type'     => 'email',
                    'known_sessions'      => $customer['sessions'],
                    'device_fingerprints' => ['fp_' . md5($email)],
                    'custom_attributes'   => [
                        'full_name'    => $customer['name'],
                        'loyalty_tier' => $r >= 4 ? 'gold' : ($r >= 3 ? 'silver' : 'bronze'),
                        'first_seen'   => Carbon::now()->subDays(rand(30, 365))->toDateString(),
                        'total_orders' => $f * rand(1, 3),
                    ],
                    'rfm_score'   => $rfm,
                    'rfm_details' => [
                        'recency_days' => match (true) {
                            $r >= 5 => rand(1, 7),
                            $r >= 4 => rand(8, 20),
                            $r >= 3 => rand(21, 45),
                            $r >= 2 => rand(46, 90),
                            default => rand(91, 180),
                        },
                        'frequency' => $f * rand(1, 3),
                        'monetary'  => round($m * rand(50, 150) + rand(0, 99) * 0.01, 2),
                        'scored_at' => Carbon::now()->subHours(rand(1, 48))->toIso8601String(),
                    ],
                ],
            );

            $idx++;
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  6. MYSQL ANALYTICS TABLES
    // ═════════════════════════════════════════════════════════════════════

    private function seedAudienceSegments(Tenant $tenant): void
    {
        $this->command->info('  → Seeding audience segments...');

        $segments = [
            [
                'name'  => 'VIP Customers (RFM 4+)',
                'rules' => [
                    ['field' => 'rfm_score', 'operator' => '>=', 'value' => '400'],
                ],
                'member_count' => 17,
            ],
            [
                'name'  => 'At-Risk Customers (RFM < 200)',
                'rules' => [
                    ['field' => 'rfm_score', 'operator' => '<', 'value' => '200'],
                ],
                'member_count' => 10,
            ],
            [
                'name'  => 'High Spenders (Monetary 5)',
                'rules' => [
                    ['field' => 'custom_attributes.total_orders', 'operator' => '>=', 'value' => '5'],
                ],
                'member_count' => 8,
            ],
            [
                'name'  => 'Recent Cart Abandoners',
                'rules' => [
                    ['field' => 'custom_attributes.last_event', 'operator' => '==', 'value' => 'add_to_cart'],
                ],
                'member_count' => 12,
            ],
            [
                'name'  => 'Gold Loyalty Members',
                'rules' => [
                    ['field' => 'custom_attributes.loyalty_tier', 'operator' => '==', 'value' => 'gold'],
                ],
                'member_count' => 7,
            ],
        ];

        foreach ($segments as $seg) {
            AudienceSegment::updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $seg['name']],
                ['rules' => $seg['rules'], 'member_count' => $seg['member_count'], 'is_active' => true],
            );
        }
    }

    private function seedBehavioralRules(Tenant $tenant): void
    {
        $this->command->info('  → Seeding behavioral rules...');

        $rules = [
            [
                'name'              => 'Exit Intent – 15% Discount',
                'trigger_condition' => ['intent_level' => 'abandon_risk', 'min_cart_total' => 50],
                'action_type'       => 'discount',
                'action_payload'    => ['title' => 'Wait! Don\'t leave!', 'discount_code' => 'STAY15', 'discount_percent' => 15, 'message' => 'Use code STAY15 for 15% off your order!'],
                'priority'          => 90,
                'cooldown_minutes'  => 30,
            ],
            [
                'name'              => 'High Intent – Free Shipping',
                'trigger_condition' => ['min_intent_score' => 70, 'min_cart_total' => 75],
                'action_type'       => 'popup',
                'action_payload'    => ['title' => '🎉 Free Shipping!', 'message' => 'You qualify for FREE shipping on this order!', 'cta_text' => 'Complete Purchase'],
                'priority'          => 80,
                'cooldown_minutes'  => 60,
            ],
            [
                'name'              => 'Browse Abandoner – Product Recommendation',
                'trigger_condition' => ['event_type' => 'product_view', 'min_views' => 3, 'no_cart' => true],
                'action_type'       => 'notification',
                'action_payload'    => ['title' => 'Need help deciding?', 'message' => 'Check out our bestsellers!', 'redirect_url' => '/shop?sort=popular'],
                'priority'          => 60,
                'cooldown_minutes'  => 120,
            ],
        ];

        foreach ($rules as $rule) {
            BehavioralRule::updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $rule['name']],
                [
                    'trigger_condition' => $rule['trigger_condition'],
                    'action_type'       => $rule['action_type'],
                    'action_payload'    => $rule['action_payload'],
                    'priority'          => $rule['priority'],
                    'is_active'         => true,
                    'cooldown_minutes'  => $rule['cooldown_minutes'],
                ],
            );
        }
    }

    private function seedWebhooks(Tenant $tenant): void
    {
        $this->command->info('  → Seeding webhooks...');

        TenantWebhook::updateOrCreate(
            ['tenant_id' => $tenant->id, 'endpoint_url' => 'https://hooks.zapier.com/hooks/catch/12345/woo-orders/'],
            [
                'secret_key'        => 'whsec_' . Str::random(32),
                'subscribed_events' => ['purchase', 'begin_checkout'],
                'is_active'         => true,
            ],
        );

        TenantWebhook::updateOrCreate(
            ['tenant_id' => $tenant->id, 'endpoint_url' => 'https://urbanstyleco.com/api/webhooks/analytics'],
            [
                'secret_key'        => 'whsec_' . Str::random(32),
                'subscribed_events' => ['purchase', 'add_to_cart', 'remove_from_cart', 'page_view'],
                'is_active'         => true,
            ],
        );
    }

    private function seedCustomEventDefinitions(Tenant $tenant): void
    {
        $this->command->info('  → Seeding custom event definitions...');

        $definitions = [
            [
                'event_key'    => 'wishlist_add',
                'display_name' => 'Add to Wishlist',
                'description'  => 'Fired when a customer adds a product to their wishlist',
                'schema'       => ['product_id' => 'string', 'product_name' => 'string'],
                'event_count'  => rand(120, 350),
            ],
            [
                'event_key'    => 'size_guide_open',
                'display_name' => 'Size Guide Opened',
                'description'  => 'Customer opened the size guide modal on a product page',
                'schema'       => ['product_id' => 'string', 'size_type' => 'string'],
                'event_count'  => rand(80, 220),
            ],
            [
                'event_key'    => 'coupon_applied',
                'display_name' => 'Coupon Code Applied',
                'description'  => 'Customer applied a discount coupon at checkout',
                'schema'       => ['coupon_code' => 'string', 'discount_amount' => 'number'],
                'event_count'  => rand(30, 90),
            ],
        ];

        foreach ($definitions as $def) {
            CustomEventDefinition::updateOrCreate(
                ['tenant_id' => $tenant->id, 'event_key' => $def['event_key']],
                [
                    'display_name' => $def['display_name'],
                    'description'  => $def['description'],
                    'schema'       => $def['schema'],
                    'is_active'    => true,
                    'event_count'  => $def['event_count'],
                ],
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  7. SEED EXISTING TENANTS (lightweight)
    // ═════════════════════════════════════════════════════════════════════

    private function seedExistingTenants(): void
    {
        $this->command->info('  → Seeding events for existing tenants...');

        $existingTenants = Tenant::where('is_active', true)
            ->where('slug', '!=', self::STORE_SLUG)
            ->get();

        foreach ($existingTenants as $tenant) {
            $tid = (string) $tenant->id;
            $docs = [];

            // Generate 30 days × 2-4 sessions × 3-5 events = ~300 events per tenant
            for ($day = 0; $day < 30; $day++) {
                $date = Carbon::now()->subDays($day);
                $sessCount = rand(2, 4);

                for ($s = 0; $s < $sessCount; $s++) {
                    $sid      = 'sess_' . Str::random(20);
                    $loc      = $this->weightedPick(self::LOCATIONS);
                    $city     = $loc['cities'][array_rand($loc['cities'])];
                    $dev      = $this->weightedPick(self::DEVICES);
                    $ch       = $this->weightedPick(self::CHANNELS);
                    $hour     = $this->daytimeHour();
                    $time     = $date->copy()->setTime($hour, rand(0, 59), rand(0, 59));
                    $ip       = $this->randomIp($loc['cc']);
                    $baseMeta = [
                        'geo'    => ['country' => $loc['country'], 'country_code' => $loc['cc'], 'city' => $city['c'], 'region' => $city['r'], 'lat' => $city['lat'], 'lon' => $city['lon'], 'timezone' => $loc['tz']],
                        'device' => ['device_type' => $dev['type'], 'browser' => $dev['browser'], 'os' => $dev['os']],
                    ];

                    // 3-5 events: page_views + maybe product_view + maybe purchase
                    $docs[] = $this->buildEventDoc($tid, $sid, 'page_view', 'https://' . ($tenant->domain ?? $tenant->slug . '.example.com') . '/', $baseMeta, $ip, $dev['ua'], $time);
                    $time = $time->copy()->addSeconds(rand(10, 30));

                    if (rand(1, 100) <= 50) {
                        $product = self::PRODUCTS[array_rand(self::PRODUCTS)];
                        $meta = array_merge($baseMeta, ['product_id' => $product['id'], 'product_name' => $product['name'], 'product_price' => $product['price'], 'category' => $product['cat']]);
                        $docs[] = $this->buildEventDoc($tid, $sid, 'product_view', 'https://' . ($tenant->domain ?? $tenant->slug . '.example.com') . '/product/' . Str::slug($product['name']), $meta, $ip, $dev['ua'], $time);
                        $time = $time->copy()->addSeconds(rand(15, 45));

                        if (rand(1, 100) <= 20) {
                            $meta['order_total'] = $product['price'];
                            $meta['attribution'] = ['source' => $ch['source'], 'source_id' => $ch['source'] . '_' . Str::random(6)];
                            $docs[] = $this->buildEventDoc($tid, $sid, 'purchase', 'https://' . ($tenant->domain ?? $tenant->slug . '.example.com') . '/order-complete', $meta, $ip, $dev['ua'], $time);
                        }
                    }
                }
            }

            // Bulk insert
            if (!empty($docs)) {
                $mongo = app('db')->connection('mongodb');
                $mongo->getCollection('tracking_events')->insertMany($docs);
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═════════════════════════════════════════════════════════════════════

    private function addEvent(string $sessionId, string $eventType, string $url, array $metadata, string $ip, string $ua, Carbon $time): void
    {
        $this->events[] = $this->buildEventDoc($this->tenantId, $sessionId, $eventType, $url, $metadata, $ip, $ua, $time);
    }

    private function buildEventDoc(string $tenantId, string $sessionId, string $eventType, string $url, array $metadata, string $ip, string $ua, Carbon $time): array
    {
        $ts = new UTCDateTime($time->getTimestampMs());

        return [
            'tenant_id'   => $tenantId,
            'session_id'  => $sessionId,
            'event_type'  => $eventType,
            'url'         => $url,
            'metadata'    => $metadata,
            'custom_data' => [],
            'ip_address'  => $ip,
            'user_agent'  => $ua,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ];
    }

    /**
     * Weighted random selection from an array of items with 'w' or 'weight' key.
     */
    private function weightedPick(array $items): array
    {
        $total = 0;
        foreach ($items as $item) {
            $total += $item['w'] ?? $item['weight'] ?? 1;
        }

        $rand = rand(1, $total);
        $cumulative = 0;

        foreach ($items as $item) {
            $cumulative += $item['w'] ?? $item['weight'] ?? 1;
            if ($rand <= $cumulative) {
                return $item;
            }
        }

        return end($items);
    }

    /**
     * Generate a random hour weighted toward daytime (8am-10pm).
     */
    private function daytimeHour(): int
    {
        // 80% chance daytime (8-22), 20% nighttime (0-7, 23)
        if (rand(1, 100) <= 80) {
            return rand(8, 22);
        }

        return rand(0, 7);
    }

    /**
     * Pick N random products from the catalog.
     *
     * @return list<array>
     */
    private function pickRandomProducts(int $count): array
    {
        $keys = array_rand(self::PRODUCTS, min($count, count(self::PRODUCTS)));
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        return array_map(fn($k) => self::PRODUCTS[$k], $keys);
    }

    /**
     * Generate a realistic-looking IP for a country.
     */
    private function randomIp(string $countryCode): string
    {
        $prefix = self::IP_PREFIXES[$countryCode] ?? '203';

        return $prefix . '.' . rand(1, 254) . '.' . rand(1, 254) . '.' . rand(1, 254);
    }
}

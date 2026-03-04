<?php
/**
 * Server-to-server bulk data sync for WooCommerce stores.
 *
 * Mirrors Magento 2 module capabilities:
 *  - Connection registration & heartbeat
 *  - Products, categories, inventory, sales, orders, customers, abandoned carts
 *  - Cron-based periodic sync
 *
 * @package Ecom360_Analytics
 */

defined( 'ABSPATH' ) || exit;

final class Ecom360_DataSync {

    /** @var array */
    private $settings;

    /** @var string */
    private $endpoint;

    /** @var string */
    private $api_key;

    /** @var string */
    private $secret_key;

    /** @var int Batch size for API calls */
    private const BATCH_SIZE = 100;

    public function __construct( array $settings ) {
        $this->settings   = $settings;
        $this->endpoint   = rtrim( $settings['endpoint'] ?? '', '/' );
        $this->api_key    = $settings['api_key'] ?? '';
        $this->secret_key = $settings['secret_key'] ?? '';
    }

    /**
     * Register WP-Cron hooks for periodic sync.
     */
    public function register(): void {
        // Custom cron schedules
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );

        // Register cron hooks
        add_action( 'ecom360_sync_heartbeat',   [ $this, 'cron_heartbeat' ] );
        add_action( 'ecom360_sync_products',    [ $this, 'cron_sync_products' ] );
        add_action( 'ecom360_sync_categories',  [ $this, 'cron_sync_categories' ] );
        add_action( 'ecom360_sync_inventory',   [ $this, 'cron_sync_inventory' ] );
        add_action( 'ecom360_sync_orders',      [ $this, 'cron_sync_orders' ] );
        add_action( 'ecom360_sync_customers',   [ $this, 'cron_sync_customers' ] );
        add_action( 'ecom360_sync_sales',       [ $this, 'cron_sync_sales' ] );
        add_action( 'ecom360_sync_abandoned',   [ $this, 'cron_sync_abandoned_carts' ] );

        // Schedule events if not already
        $this->schedule_events();
    }

    /**
     * Add custom cron intervals.
     */
    public function add_cron_schedules( array $schedules ): array {
        $schedules['ecom360_15min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 Minutes', 'ecom360-analytics' ),
        ];
        $schedules['ecom360_2hours'] = [
            'interval' => 2 * HOUR_IN_SECONDS,
            'display'  => __( 'Every 2 Hours', 'ecom360-analytics' ),
        ];
        $schedules['ecom360_6hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __( 'Every 6 Hours', 'ecom360-analytics' ),
        ];
        return $schedules;
    }

    /**
     * Schedule cron events.
     */
    private function schedule_events(): void {
        $events = [
            'ecom360_sync_heartbeat'  => 'ecom360_15min',
            'ecom360_sync_products'   => 'hourly',
            'ecom360_sync_categories' => 'ecom360_6hours',
            'ecom360_sync_inventory'  => 'ecom360_2hours',
            'ecom360_sync_orders'     => 'ecom360_15min',
            'ecom360_sync_customers'  => 'ecom360_2hours',
            'ecom360_sync_sales'      => 'daily',
            'ecom360_sync_abandoned'  => 'ecom360_15min',
        ];

        foreach ( $events as $hook => $recurrence ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event( time(), $recurrence, $hook );
            }
        }
    }

    /**
     * Clear all scheduled cron events (on plugin deactivation or disable).
     */
    public static function clear_schedules(): void {
        $hooks = [
            'ecom360_sync_heartbeat', 'ecom360_sync_products', 'ecom360_sync_categories',
            'ecom360_sync_inventory', 'ecom360_sync_orders', 'ecom360_sync_customers',
            'ecom360_sync_sales', 'ecom360_sync_abandoned',
        ];
        foreach ( $hooks as $hook ) {
            $ts = wp_next_scheduled( $hook );
            if ( $ts ) {
                wp_unschedule_event( $ts, $hook );
            }
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * Connection Registration & Heartbeat
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Register connection with the Ecom360 platform.
     */
    public function register_connection(): array {
        $permissions = [
            'products'        => ! empty( $this->settings['sync_products'] ),
            'categories'      => ! empty( $this->settings['sync_categories'] ),
            'inventory'       => ! empty( $this->settings['sync_inventory'] ),
            'sales'           => ! empty( $this->settings['sync_sales'] ),
            'orders'          => ! empty( $this->settings['sync_orders'] ),
            'customers'       => ! empty( $this->settings['sync_customers'] ),
            'abandoned_carts' => ! empty( $this->settings['sync_abandoned_carts'] ),
            'popup_captures'  => false,
        ];

        return $this->sync_request( '/api/v1/sync/register', [
            'platform'         => 'woocommerce',
            'store_url'        => home_url(),
            'store_name'       => get_bloginfo( 'name' ),
            'store_id'         => 0,
            'platform_version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
            'module_version'   => ECM360_VERSION,
            'php_version'      => PHP_VERSION,
            'locale'           => get_locale(),
            'currency'         => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
            'timezone'         => wp_timezone_string(),
            'permissions'      => $permissions,
        ] );
    }

    /**
     * Cron: Send heartbeat.
     */
    public function cron_heartbeat(): void {
        if ( ! $this->is_sync_ready() ) return;

        $this->sync_request( '/api/v1/sync/heartbeat', [
            'platform' => 'woocommerce',
            'store_id' => 0,
        ] );
    }

    /* ═══════════════════════════════════════════════════════════════════
     * Product Sync
     * ═══════════════════════════════════════════════════════════════════ */

    public function cron_sync_products(): void {
        if ( ! $this->is_sync_ready() || empty( $this->settings['sync_products'] ) ) return;
        $this->sync_products();
    }

    public function sync_products(): array {
        $args = [
            'status'  => 'publish',
            'limit'   => self::BATCH_SIZE,
            'page'    => 1,
            'orderby' => 'date',
            'order'   => 'DESC',
        ];

        $allResults = [ 'synced' => 0, 'failed' => 0 ];

        do {
            $products = wc_get_products( $args );
            if ( empty( $products ) ) break;

            $batch = [];
            foreach ( $products as $product ) {
                $batch[] = [
                    'id'                => (string) $product->get_id(),
                    'sku'               => $product->get_sku() ?: 'wc-' . $product->get_id(),
                    'name'              => $product->get_name(),
                    'price'             => (float) $product->get_price(),
                    'regular_price'     => (float) $product->get_regular_price(),
                    'sale_price'        => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
                    'status'            => $product->get_status(),
                    'type'              => $product->get_type(),
                    'slug'              => $product->get_slug(),
                    'description'       => mb_substr( wp_strip_all_tags( $product->get_description() ), 0, 500 ),
                    'short_description' => mb_substr( wp_strip_all_tags( $product->get_short_description() ), 0, 300 ),
                    'categories'        => $this->get_product_categories( $product ),
                    'images'            => $this->get_product_images( $product ),
                    'weight'            => $product->get_weight() ? (float) $product->get_weight() : null,
                    'created_at'        => $product->get_date_created() ? $product->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
                    'updated_at'        => $product->get_date_modified() ? $product->get_date_modified()->format( 'Y-m-d H:i:s' ) : null,
                ];
            }

            $result = $this->sync_request( '/api/v1/sync/products', [
                'platform' => 'woocommerce',
                'store_id' => 0,
                'products' => $batch,
            ] );

            if ( $result['success'] ) {
                $allResults['synced'] += count( $batch );
            } else {
                $allResults['failed'] += count( $batch );
            }

            $args['page']++;
        } while ( count( $products ) === self::BATCH_SIZE );

        return $allResults;
    }

    /* ═══════════════════════════════════════════════════════════════════
     * Category Sync
     * ═══════════════════════════════════════════════════════════════════ */

    public function cron_sync_categories(): void {
        if ( ! $this->is_sync_ready() || empty( $this->settings['sync_categories'] ) ) return;
        $this->sync_categories();
    }

    public function sync_categories(): array {
        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [ 'synced' => 0, 'failed' => 0 ];
        }

        $batch = [];
        foreach ( $terms as $term ) {
            $depth = 0;
            $parent = $term;
            while ( $parent->parent ) {
                $depth++;
                $parent = get_term( $parent->parent, 'product_cat' );
            }

            $batch[] = [
                'id'              => (string) $term->term_id,
                'name'            => $term->name,
                'url_key'         => $term->slug,
                'is_active'       => true,
                'level'           => $depth + 1,
                'position'        => 0,
                'parent_id'       => $term->parent ? (string) $term->parent : '0',
                'description'     => $term->description,
                'include_in_menu' => true,
                'product_count'   => (int) $term->count,
            ];
        }

        $result = $this->sync_request( '/api/v1/sync/categories', [
            'platform'   => 'woocommerce',
            'store_id'   => 0,
            'categories' => $batch,
        ] );

        return [
            'synced' => $result['success'] ? count( $batch ) : 0,
            'failed' => $result['success'] ? 0 : count( $batch ),
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
     * Inventory Sync
     * ═══════════════════════════════════════════════════════════════════ */

    public function cron_sync_inventory(): void {
        if ( ! $this->is_sync_ready() || empty( $this->settings['sync_inventory'] ) ) return;
        $this->sync_inventory();
    }

    public function sync_inventory(): array {
        $args = [
            'status'  => 'publish',
            'limit'   => self::BATCH_SIZE,
            'page'    => 1,
        ];

        $allResults = [ 'synced' => 0, 'failed' => 0 ];

        do {
            $products = wc_get_products( $args );
            if ( empty( $products ) ) break;

            $batch = [];
            foreach ( $products as $product ) {
                $stock_qty = $product->get_stock_quantity();
                $batch[] = [
                    'product_id' => (string) $product->get_id(),
                    'sku'        => $product->get_sku() ?: 'wc-' . $product->get_id(),
                    'name'       => $product->get_name(),
                    'price'      => (float) $product->get_price(),
                    'cost'       => null, // WC doesn't have cost by default
                    'qty'        => $stock_qty !== null ? (float) $stock_qty : 0,
                    'is_in_stock' => $product->is_in_stock(),
                    'min_qty'    => (float) get_post_meta( $product->get_id(), '_low_stock_amount', true ) ?: 0,
                    'low_stock'  => $product->get_low_stock_amount() && $stock_qty <= $product->get_low_stock_amount(),
                ];
            }

            $result = $this->sync_request( '/api/v1/sync/inventory', [
                'platform' => 'woocommerce',
                'store_id' => 0,
                'items'    => $batch,
            ] );

            if ( $result['success'] ) {
                $allResults['synced'] += count( $batch );
            } else {
                $allResults['failed'] += count( $batch );
            }

            $args['page']++;
        } while ( count( $products ) === self::BATCH_SIZE );

        return $allResults;
    }

    /* ═══════════════════════════════════════════════════════════════════
     * Order Sync (Restricted — requires consent)
     * ═══════════════════════════════════════════════════════════════════ */

    public function cron_sync_orders(): void {
        if ( ! $this->is_sync_ready() || empty( $this->settings['sync_orders'] ) ) return;
        $this->sync_orders();
    }

    public function sync_orders(): array {
        $args = [
            'limit'   => self::BATCH_SIZE,
            'page'    => 1,
            'orderby' => 'date',
            'order'   => 'DESC',
            'date_created' => '>' . date( 'Y-m-d', strtotime( '-7 days' ) ),
        ];

        $allResults = [ 'synced' => 0, 'failed' => 0 ];

        do {
            $orders = wc_get_orders( $args );
            if ( empty( $orders ) ) break;

            $batch = [];
            foreach ( $orders as $order ) {
                $items = [];
                foreach ( $order->get_items() as $item ) {
                    $product = $item->get_product();
                    $items[] = [
                        'product_id' => (string) $item->get_product_id(),
                        'sku'        => $product ? $product->get_sku() : '',
                        'name'       => $item->get_name(),
                        'qty'        => $item->get_quantity(),
                        'price'      => (float) $order->get_item_total( $item, false ),
                        'row_total'  => (float) $item->get_total(),
                        'discount'   => 0,
                    ];
                }

                $batch[] = [
                    'order_id'        => (string) $order->get_id(),
                    'entity_id'       => (string) $order->get_id(),
                    'status'          => $order->get_status(),
                    'grand_total'     => (float) $order->get_total(),
                    'subtotal'        => (float) $order->get_subtotal(),
                    'tax_amount'      => (float) $order->get_total_tax(),
                    'shipping_amount' => (float) $order->get_shipping_total(),
                    'discount_amount' => (float) $order->get_total_discount() * -1,
                    'total_qty'       => $order->get_item_count(),
                    'currency'        => $order->get_currency(),
                    'payment_method'  => $order->get_payment_method(),
                    'shipping_method' => $order->get_shipping_method(),
                    'coupon_code'     => implode( ',', $order->get_coupon_codes() ) ?: null,
                    'customer_email'  => $order->get_billing_email(),
                    'customer_id'     => (string) $order->get_customer_id(),
                    'is_guest'        => $order->get_customer_id() === 0,
                    'items'           => $items,
                    'billing_address' => [
                        'firstname'  => $order->get_billing_first_name(),
                        'lastname'   => $order->get_billing_last_name(),
                        'street'     => $order->get_billing_address_1(),
                        'city'       => $order->get_billing_city(),
                        'region'     => $order->get_billing_state(),
                        'postcode'   => $order->get_billing_postcode(),
                        'country_id' => $order->get_billing_country(),
                    ],
                    'shipping_address' => [
                        'firstname'  => $order->get_shipping_first_name(),
                        'lastname'   => $order->get_shipping_last_name(),
                        'street'     => $order->get_shipping_address_1(),
                        'city'       => $order->get_shipping_city(),
                        'region'     => $order->get_shipping_state(),
                        'postcode'   => $order->get_shipping_postcode(),
                        'country_id' => $order->get_shipping_country(),
                    ],
                    'created_at' => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
                ];
            }

            $result = $this->sync_request( '/api/v1/sync/orders', [
                'platform' => 'woocommerce',
                'store_id' => 0,
                'orders'   => $batch,
            ] );

            if ( $result['success'] ) {
                $allResults['synced'] += count( $batch );
            } else {
                $allResults['failed'] += count( $batch );
            }

            $args['page']++;
        } while ( count( $orders ) === self::BATCH_SIZE );

        return $allResults;
    }

    /* ═══════════════════════════════════════════════════════════════════
     * Customer Sync (Sensitive — requires PII consent)
     * ═══════════════════════════════════════════════════════════════════ */

    public function cron_sync_customers(): void {
        if ( ! $this->is_sync_ready() || empty( $this->settings['sync_customers'] ) ) return;
        $this->sync_customers();
    }

    public function sync_customers(): array {
        $users = get_users( [
            'role__in' => [ 'customer', 'subscriber' ],
            'number'   => self::BATCH_SIZE,
            'orderby'  => 'registered',
            'order'    => 'DESC',
        ] );

        if ( empty( $users ) ) {
            return [ 'synced' => 0, 'failed' => 0 ];
        }

        $batch = [];
        foreach ( $users as $user ) {
            $batch[] = [
                'id'        => (string) $user->ID,
                'email'     => $user->user_email,
                'firstname' => get_user_meta( $user->ID, 'first_name', true ),
                'lastname'  => get_user_meta( $user->ID, 'last_name', true ),
                'name'      => $user->display_name,
            ];
        }

        $result = $this->sync_request( '/api/v1/sync/customers', [
            'platform'  => 'woocommerce',
            'store_id'  => 0,
            'customers' => $batch,
        ] );

        return [
            'synced' => $result['success'] ? count( $batch ) : 0,
            'failed' => $result['success'] ? 0 : count( $batch ),
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
     * Sales Data Sync
     * ═══════════════════════════════════════════════════════════════════ */

    public function cron_sync_sales(): void {
        if ( ! $this->is_sync_ready() || empty( $this->settings['sync_sales'] ) ) return;
        $this->sync_sales();
    }

    public function sync_sales( int $days = 7 ): array {
        global $wpdb;

        $sales_data = [];
        for ( $d = $days; $d >= 1; $d-- ) {
            $date = date( 'Y-m-d', strtotime( "-{$d} days" ) );
            $next = date( 'Y-m-d', strtotime( "-" . ( $d - 1 ) . " days" ) );

            // Use HPOS-compatible query
            if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) &&
                 \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT COUNT(*) as total_orders,
                            COALESCE(SUM(total_amount), 0) as total_revenue,
                            COALESCE(SUM(tax_amount), 0) as total_tax,
                            COALESCE(AVG(total_amount), 0) as avg_order_value
                     FROM {$wpdb->prefix}wc_orders
                     WHERE date_created_gmt >= %s AND date_created_gmt < %s
                       AND type = 'shop_order'
                       AND status IN ('wc-completed', 'wc-processing')",
                    $date, $next
                ) );
            } else {
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT COUNT(*) as total_orders,
                            COALESCE(SUM(pm_total.meta_value), 0) as total_revenue,
                            COALESCE(SUM(pm_tax.meta_value), 0) as total_tax,
                            COALESCE(AVG(pm_total.meta_value), 0) as avg_order_value
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                     LEFT JOIN {$wpdb->postmeta} pm_tax ON p.ID = pm_tax.post_id AND pm_tax.meta_key = '_order_tax'
                     WHERE p.post_type = 'shop_order'
                       AND p.post_date >= %s AND p.post_date < %s
                       AND p.post_status IN ('wc-completed', 'wc-processing')",
                    $date, $next
                ) );
            }

            $sales_data[] = [
                'date'            => $date,
                'total_orders'    => (int) ( $row->total_orders ?? 0 ),
                'total_revenue'   => (float) ( $row->total_revenue ?? 0 ),
                'total_tax'       => (float) ( $row->total_tax ?? 0 ),
                'avg_order_value' => (float) ( $row->avg_order_value ?? 0 ),
            ];
        }

        $result = $this->sync_request( '/api/v1/sync/sales', [
            'platform'   => 'woocommerce',
            'store_id'   => 0,
            'currency'   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
            'sales_data' => $sales_data,
        ] );

        return [
            'synced' => $result['success'] ? count( $sales_data ) : 0,
            'failed' => $result['success'] ? 0 : count( $sales_data ),
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
     * Abandoned Carts Sync (Restricted)
     * ═══════════════════════════════════════════════════════════════════ */

    public function cron_sync_abandoned_carts(): void {
        if ( ! $this->is_sync_ready() || empty( $this->settings['sync_abandoned_carts'] ) ) return;
        $this->sync_abandoned_carts();
    }

    public function sync_abandoned_carts(): array {
        // WooCommerce stores cart snapshots in usermeta via our WooCommerce hooks
        global $wpdb;

        $carts = $wpdb->get_results( $wpdb->prepare(
            "SELECT u.ID, u.user_email, u.display_name, um.meta_value as cart_data
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
             WHERE um.meta_key = '_ecom360_cart_snapshot'
               AND um.meta_value != ''
               AND um.meta_value IS NOT NULL
             ORDER BY u.user_registered DESC
             LIMIT %d",
            self::BATCH_SIZE
        ) );

        if ( empty( $carts ) ) {
            return [ 'synced' => 0, 'failed' => 0 ];
        }

        $batch = [];
        foreach ( $carts as $cart ) {
            $data = maybe_unserialize( $cart->cart_data );
            if ( ! is_array( $data ) || empty( $data['items'] ) ) continue;

            $batch[] = [
                'quote_id'        => 'wc-cart-' . $cart->ID,
                'customer_email'  => $cart->user_email,
                'customer_name'   => $cart->display_name,
                'customer_id'     => (string) $cart->ID,
                'grand_total'     => (float) ( $data['total'] ?? 0 ),
                'items_count'     => count( $data['items'] ?? [] ),
                'items'           => $data['items'] ?? [],
                'status'          => 'abandoned',
                'email_sent'      => false,
                'abandoned_at'    => $data['updated_at'] ?? date( 'c' ),
                'last_activity_at' => $data['updated_at'] ?? date( 'c' ),
            ];
        }

        if ( empty( $batch ) ) {
            return [ 'synced' => 0, 'failed' => 0 ];
        }

        $result = $this->sync_request( '/api/v1/sync/abandoned-carts', [
            'platform'       => 'woocommerce',
            'store_id'       => 0,
            'abandoned_carts' => $batch,
        ] );

        return [
            'synced' => $result['success'] ? count( $batch ) : 0,
            'failed' => $result['success'] ? 0 : count( $batch ),
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════
     * Helpers
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Check if DataSync is ready (enabled, has credentials).
     */
    private function is_sync_ready(): bool {
        return ! empty( $this->settings['sync_enabled'] )
            && ! empty( $this->endpoint )
            && ! empty( $this->api_key )
            && ! empty( $this->secret_key );
    }

    /**
     * Make an authenticated sync API request.
     */
    private function sync_request( string $path, array $body ): array {
        $url = $this->endpoint . $path;

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
                'X-Ecom360-Key'    => $this->api_key,
                'X-Ecom360-Secret' => $this->secret_key,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 'error', "Sync request failed [{$path}]: " . $response->get_error_message() );
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

        if ( $code >= 200 && $code < 300 ) {
            return array_merge( [ 'success' => true ], $data );
        }

        $this->log( 'warning', "Sync [{$path}] returned {$code}: " . wp_remote_retrieve_body( $response ) );
        return [ 'success' => false, 'status_code' => $code, 'data' => $data ];
    }

    /**
     * Get product categories as array.
     */
    private function get_product_categories( $product ): array {
        $terms = get_the_terms( $product->get_id(), 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) return [];

        return array_map( fn( $t ) => [
            'id'   => $t->term_id,
            'name' => $t->name,
            'slug' => $t->slug,
        ], $terms );
    }

    /**
     * Get product images.
     */
    private function get_product_images( $product ): array {
        $images = [];
        $image_id = $product->get_image_id();
        if ( $image_id ) {
            $images[] = [ 'src' => wp_get_attachment_url( $image_id ) ];
        }
        foreach ( $product->get_gallery_image_ids() as $gid ) {
            $images[] = [ 'src' => wp_get_attachment_url( $gid ) ];
        }
        return $images;
    }

    /**
     * Log a sync message.
     */
    private function log( string $level, string $message ): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->$level( $message, [ 'source' => 'ecom360-datasync' ] );
        } else {
            error_log( "[Ecom360 DataSync] [{$level}] {$message}" );
        }
    }
}

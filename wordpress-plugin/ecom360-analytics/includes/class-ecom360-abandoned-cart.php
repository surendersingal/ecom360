<?php
/**
 * Abandoned Cart Handler — detection, recovery emails, coupon generation.
 *
 * Replaces the simple usermeta approach with a proper DB table, recovery
 * tokens, email sending with configurable delay, and auto-coupon creation.
 * Now supports both guest and logged-in carts.
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_AbandonedCart {

    /** @var array<string, mixed> */
    private $settings;

    public function __construct( array $settings ) {
        $this->settings = $settings;
    }

    /**
     * Register hooks for cart tracking and cron.
     */
    public function register(): void {
        if ( empty( $this->settings['abandoned_cart_enabled'] ) ) return;

        // Track cart updates (logged-in users + guests via session)
        add_action( 'woocommerce_cart_updated', [ $this, 'save_cart_snapshot' ] );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'mark_converted' ] );

        // Register cron
        if ( ! wp_next_scheduled( 'ecom360_process_abandoned_carts' ) ) {
            wp_schedule_event( time(), 'ecom360_every_10min', 'ecom360_process_abandoned_carts' );
        }
        add_action( 'ecom360_process_abandoned_carts', [ __CLASS__, 'cron_process' ] );

        // Add custom cron interval
        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
    }

    /**
     * Add 10-minute cron interval.
     */
    public function add_cron_interval( $schedules ) {
        $schedules['ecom360_every_10min'] = [
            'interval' => 600,
            'display'  => __( 'Every 10 Minutes', 'ecom360-analytics' ),
        ];
        return $schedules;
    }

    /**
     * Save/update cart snapshot on cart change.
     */
    public function save_cart_snapshot(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;

        $cart = WC()->cart;
        if ( $cart->is_empty() ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_abandoned_carts';

        // Identify the session
        $session_id = $this->get_session_id();
        $customer_id = get_current_user_id() ?: null;
        $customer_email = '';
        $customer_name = '';

        if ( $customer_id ) {
            $user = get_userdata( $customer_id );
            if ( $user ) {
                $customer_email = $user->user_email;
                $customer_name = trim( $user->first_name . ' ' . $user->last_name );
            }
        }

        $items = [];
        foreach ( $cart->get_cart() as $item ) {
            $product = $item['data'];
            $items[] = [
                'product_id' => $item['product_id'],
                'name'       => $product ? $product->get_name() : '',
                'sku'        => $product ? $product->get_sku() : '',
                'price'      => $product ? (float) $product->get_price() : 0,
                'qty'        => $item['quantity'],
            ];
        }

        $data = [
            'customer_id'      => $customer_id,
            'customer_email'   => $customer_email,
            'customer_name'    => $customer_name,
            'grand_total'      => (float) $cart->get_total( 'edit' ),
            'items_count'      => $cart->get_cart_contents_count(),
            'items_json'       => wp_json_encode( $items ),
            'status'           => 'active',
            'last_activity_at' => current_time( 'mysql' ),
            'updated_at'       => current_time( 'mysql' ),
        ];

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE session_id = %s LIMIT 1",
            $session_id
        ) );

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'session_id' => $session_id ] );
        } else {
            $data['session_id'] = $session_id;
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }
    }

    /**
     * Mark cart as converted when order is placed.
     */
    public function mark_converted( $order_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_abandoned_carts';
        $session_id = $this->get_session_id();

        $wpdb->update( $table, [
            'status'     => 'converted',
            'updated_at' => current_time( 'mysql' ),
        ], [ 'session_id' => $session_id ] );
    }

    /**
     * Cron: detect abandoned carts, send recovery emails, sync to platform.
     */
    public static function cron_process(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_abandoned_carts';
        $settings = Ecom360_Settings::get();

        $timeout = (int) ( $settings['abandoned_cart_timeout'] ?? 60 );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $timeout * 60 );

        // Phase 1: Mark abandoned
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'abandoned', abandoned_at = NOW()
             WHERE status = 'active' AND last_activity_at < %s",
            $cutoff
        ) );

        // Phase 2: Send recovery emails
        if ( ! empty( $settings['abandoned_cart_send_email'] ) ) {
            self::send_recovery_emails( $settings );
        }

        // Phase 3: Sync to platform
        self::sync_to_platform( $settings );
    }

    /**
     * Send recovery emails to abandoned carts.
     */
    private static function send_recovery_emails( array $settings ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_abandoned_carts';

        $email_delay = (int) ( $settings['abandoned_cart_email_delay'] ?? 30 );
        $delay_cutoff = gmdate( 'Y-m-d H:i:s', time() - $email_delay * 60 );

        $carts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'abandoned' AND email_sent = 0 AND customer_email != '' AND abandoned_at < %s
             LIMIT 50",
            $delay_cutoff
        ), ARRAY_A );

        foreach ( $carts as $cart ) {
            // Generate recovery token
            $token = wp_generate_uuid4();
            $wpdb->update( $table, [ 'recovery_token' => $token ], [ 'id' => $cart['id'] ] );

            // Generate coupon if enabled
            $coupon_code = '';
            if ( ! empty( $settings['abandoned_cart_include_coupon'] ) ) {
                $coupon_code = self::generate_coupon( $settings );
                $wpdb->update( $table, [ 'coupon_code' => $coupon_code ], [ 'id' => $cart['id'] ] );
            }

            $recovery_url = rest_url( 'ecom360/v1/cart/recover' ) . '?token=' . $token;
            $items = json_decode( $cart['items_json'] ?? '[]', true );

            $subject = __( 'You left something behind!', 'ecom360-analytics' );
            $customer_name = $cart['customer_name'] ?: __( 'Valued Customer', 'ecom360-analytics' );

            $body = '<h2>' . sprintf( __( 'Hi %s,', 'ecom360-analytics' ), esc_html( $customer_name ) ) . '</h2>';
            $body .= '<p>' . __( 'You left some items in your cart. Grab them before they\'re gone!', 'ecom360-analytics' ) . '</p>';
            $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
            foreach ( $items as $item ) {
                $body .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( $item['name'] ?? '' ) . '</td>';
                $body .= '<td style="padding:8px;border-bottom:1px solid #eee;">x' . (int) ( $item['qty'] ?? 1 ) . '</td>';
                $body .= '<td style="padding:8px;border-bottom:1px solid #eee;">$' . number_format( (float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1), 2 ) . '</td></tr>';
            }
            $body .= '</table>';
            $body .= '<p><strong>' . __( 'Total:', 'ecom360-analytics' ) . '</strong> $' . number_format( (float)$cart['grand_total'], 2 ) . '</p>';

            if ( $coupon_code ) {
                $body .= '<p style="background:#eef2ff;padding:12px;border-radius:8px;text-align:center;">';
                $body .= __( 'Use code: ', 'ecom360-analytics' ) . '<strong>' . esc_html( $coupon_code ) . '</strong>';
                $body .= ' ' . __( 'for a special discount!', 'ecom360-analytics' ) . '</p>';
            }

            $body .= '<p style="text-align:center;margin:24px 0;">';
            $body .= '<a href="' . esc_url( $recovery_url ) . '" style="background:#4f46e5;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:600;">';
            $body .= __( 'Complete Your Purchase →', 'ecom360-analytics' ) . '</a></p>';

            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
            $sent = wp_mail( $cart['customer_email'], $subject, $body, $headers );

            if ( $sent ) {
                $wpdb->update( $table, [
                    'email_sent'    => 1,
                    'email_sent_at' => current_time( 'mysql' ),
                    'status'        => 'email_sent',
                ], [ 'id' => $cart['id'] ] );
            }
        }
    }

    /**
     * Generate a unique WooCommerce coupon code.
     */
    private static function generate_coupon( array $settings ): string {
        $code = 'ECM360-' . strtoupper( wp_generate_password( 6, false ) );
        $amount = (float) ( $settings['abandoned_cart_coupon_amount'] ?? 10 );
        $type = $settings['abandoned_cart_coupon_type'] ?? 'percent';

        $coupon = new \WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( $type );
        $coupon->set_amount( $amount );
        $coupon->set_usage_limit( 1 );
        $coupon->set_date_expires( strtotime( '+7 days' ) );
        $coupon->set_individual_use( true );
        $coupon->save();

        return $code;
    }

    /**
     * Sync unsynced abandoned carts to Ecom360 platform.
     */
    private static function sync_to_platform( array $settings ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_abandoned_carts';

        if ( empty( $settings['sync_abandoned_carts'] ) || empty( $settings['secret_key'] ) ) return;

        $carts = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE synced = 0 AND status IN ('abandoned','email_sent','recovered','converted') LIMIT 50",
            ARRAY_A
        );

        if ( empty( $carts ) ) return;

        $endpoint = rtrim( $settings['endpoint'], '/' ) . '/api/v1/sync/abandoned-carts';
        $payload = [
            'platform' => 'woocommerce',
            'store_id' => 1,
            'carts'    => array_map( function( $cart ) {
                return [
                    'cart_id'        => $cart['session_id'],
                    'customer_id'    => $cart['customer_id'] ?? null,
                    'customer_email' => $cart['customer_email'] ?? '',
                    'customer_name'  => $cart['customer_name'] ?? '',
                    'grand_total'    => (float) $cart['grand_total'],
                    'items_count'    => (int) $cart['items_count'],
                    'items'          => json_decode( $cart['items_json'] ?? '[]', true ),
                    'status'         => $cart['status'],
                    'coupon_code'    => $cart['coupon_code'] ?? null,
                    'abandoned_at'   => $cart['abandoned_at'] ?? null,
                    'recovered_at'   => $cart['recovered_at'] ?? null,
                    'store_id'       => 1,
                ];
            }, $carts ),
        ];

        $response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Ecom360-Key'    => $settings['api_key'],
                'X-Ecom360-Secret' => $settings['secret_key'],
            ],
            'body' => wp_json_encode( $payload ),
        ] );

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300 ) {
            $ids = wp_list_pluck( $carts, 'id' );
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET synced = 1 WHERE id IN ({$placeholders})",
                ...$ids
            ) );
        }
    }

    /**
     * Get session identifier (works for both guests and logged-in users).
     */
    private function get_session_id(): string {
        if ( get_current_user_id() ) {
            return 'user_' . get_current_user_id();
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            return 'wc_' . WC()->session->get_customer_id();
        }
        return 'anon_' . md5( $_SERVER['REMOTE_ADDR'] . ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
    }

    /**
     * Clear scheduled cron.
     */
    public static function clear_schedule(): void {
        wp_clear_scheduled_hook( 'ecom360_process_abandoned_carts' );
    }
}

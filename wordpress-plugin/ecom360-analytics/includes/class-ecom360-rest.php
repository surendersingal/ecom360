<?php
/**
 * WP REST API endpoints for Ecom360 (settings page AJAX).
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_Rest {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( 'ecom360/v1', '/test-connection', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'test_connection' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ]);

        register_rest_route( 'ecom360/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ]);

        // Popup form submission (public — no auth required for visitors)
        register_rest_route( 'ecom360/v1', '/popup-submit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'popup_submit' ],
            'permission_callback' => '__return_true',
        ]);

        // Push notification subscription (public)
        register_rest_route( 'ecom360/v1', '/push-subscribe', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'push_subscribe' ],
            'permission_callback' => '__return_true',
        ]);

        // Cart recovery redirect
        register_rest_route( 'ecom360/v1', '/cart/recover', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'cart_recover' ],
            'permission_callback' => '__return_true',
        ]);
    }

    public function admin_check(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * POST /wp-json/ecom360/v1/test-connection
     *
     * Sends a lightweight test event to the ecom360 backend and reports success / failure.
     */
    public function test_connection( \WP_REST_Request $request ): \WP_REST_Response {
        $settings = Ecom360_Settings::get();
        $endpoint = rtrim( $settings['endpoint'], '/' ) . '/api/v1/collect';
        $api_key  = $settings['api_key'];

        if ( empty( $endpoint ) || empty( $api_key ) ) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __( 'Please enter an API endpoint and key first.', 'ecom360-analytics' ),
            ], 400 );
        }

        $payload = [
            'event_type' => 'connection_test',
            'url'        => home_url(),
            'session_id' => 'wp_test_' . wp_generate_uuid4(),
            'metadata'   => [
                'plugin_version' => ECM360_VERSION,
                'wp_version'     => get_bloginfo( 'version' ),
                'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : 'n/a',
                'php_version'    => phpversion(),
                'site_url'       => home_url(),
            ],
        ];

        $response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'X-Ecom360-Key' => $api_key,
                'Accept'        => 'application/json',
            ],
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        ]);

        if ( is_wp_error( $response ) ) {
            $this->save_test_result( false );
            return new \WP_REST_Response([
                'success' => false,
                'message' => $response->get_error_message(),
            ], 502 );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 ) {
            $this->save_test_result( true );
            return new \WP_REST_Response([
                'success' => true,
                'message' => __( 'Connected successfully!', 'ecom360-analytics' ),
                'tenant'  => $body['tenant'] ?? null,
            ]);
        }

        $this->save_test_result( false );
        $msg = $body['message'] ?? wp_remote_retrieve_response_message( $response );
        return new \WP_REST_Response([
            'success' => false,
            'message' => sprintf( __( 'Server returned %d: %s', 'ecom360-analytics' ), $code, $msg ),
        ], $code );
    }

    /**
     * GET /wp-json/ecom360/v1/status
     */
    public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
        $settings = Ecom360_Settings::get();
        return new \WP_REST_Response([
            'is_connected' => ! empty( $settings['is_connected'] ),
            'last_test_at' => $settings['last_test_at'] ?? '',
            'version'      => ECM360_VERSION,
            'wc_active'    => class_exists( 'WooCommerce' ),
        ]);
    }

    /**
     * Persist the test result.
     */
    private function save_test_result( bool $connected ): void {
        $settings = Ecom360_Settings::get();
        $settings['is_connected'] = $connected;
        $settings['last_test_at'] = wp_date( 'Y-m-d H:i:s' );
        Ecom360_Settings::save( $settings );
    }

    /**
     * POST /wp-json/ecom360/v1/popup-submit
     * Stores popup capture locally and queues sync to platform.
     */
    public function popup_submit( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_popup_captures';

        $data = $request->get_json_params();

        // Validate email if collected
        $email = sanitize_email( $data['email'] ?? '' );
        if ( ! empty( $data['email'] ) && ! is_email( $email ) ) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __( 'Please enter a valid email address.', 'ecom360-analytics' ),
            ], 422 );
        }

        $row = [
            'session_id'   => sanitize_text_field( $data['session_id'] ?? '' ),
            'customer_id'  => get_current_user_id() ?: null,
            'name'         => sanitize_text_field( $data['name'] ?? '' ),
            'email'        => $email,
            'phone'        => sanitize_text_field( $data['phone'] ?? '' ),
            'dob'          => sanitize_text_field( $data['dob'] ?? '' ),
            'extra_data'   => wp_json_encode( $data['extra_data'] ?? [] ),
            'page_url'     => esc_url_raw( $data['page_url'] ?? '' ),
            'synced'       => 0,
            'created_at'   => current_time( 'mysql' ),
        ];

        $inserted = $wpdb->insert( $table, $row );

        if ( $inserted === false ) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __( 'Failed to save submission.', 'ecom360-analytics' ),
            ], 500 );
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __( 'Thank you for subscribing!', 'ecom360-analytics' ),
        ]);
    }

    /**
     * POST /wp-json/ecom360/v1/push-subscribe
     * Stores push subscription locally.
     */
    public function push_subscribe( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_push_subscriptions';

        $data = $request->get_json_params();

        $row = [
            'customer_id'       => get_current_user_id() ?: null,
            'endpoint'          => esc_url_raw( $data['endpoint'] ?? '' ),
            'subscription_data' => sanitize_text_field( $data['subscription_data'] ?? '' ),
            'token'             => sanitize_text_field( $data['token'] ?? '' ),
            'provider'          => sanitize_text_field( $data['provider'] ?? 'firebase' ),
            'user_agent'        => sanitize_text_field( $data['user_agent'] ?? '' ),
            'is_active'         => 1,
            'created_at'        => current_time( 'mysql' ),
        ];

        $wpdb->insert( $table, $row );

        return new \WP_REST_Response([
            'success' => true,
            'message' => __( 'Subscription saved.', 'ecom360-analytics' ),
        ]);
    }

    /**
     * GET /wp-json/ecom360/v1/cart/recover?token=xxx
     * Restores abandoned cart and redirects to checkout.
     */
    public function cart_recover( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $token = sanitize_text_field( $request->get_param( 'token' ) );

        if ( empty( $token ) ) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __( 'Invalid recovery link.', 'ecom360-analytics' ),
            ], 400 );
        }

        $table = $wpdb->prefix . 'ecom360_abandoned_carts';
        $cart = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE recovery_token = %s AND status IN ('abandoned','email_sent') LIMIT 1",
            $token
        ), ARRAY_A );

        if ( ! $cart ) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __( 'Recovery link expired or already used.', 'ecom360-analytics' ),
            ], 404 );
        }

        // Rebuild cart
        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->empty_cart();
            $items = json_decode( $cart['items_json'] ?? '[]', true );
            foreach ( $items as $item ) {
                $product_id = (int) ( $item['product_id'] ?? 0 );
                $qty = (int) ( $item['qty'] ?? 1 );
                if ( $product_id ) {
                    WC()->cart->add_to_cart( $product_id, $qty );
                }
            }

            // Apply coupon if present
            if ( ! empty( $cart['coupon_code'] ) ) {
                WC()->cart->apply_coupon( $cart['coupon_code'] );
            }

            // Update status
            $wpdb->update( $table, [
                'status'      => 'recovered',
                'recovered_at' => current_time( 'mysql' ),
            ], [ 'id' => $cart['id'] ] );
        }

        // Redirect to checkout
        wp_redirect( wc_get_checkout_url() );
        exit;
    }
}

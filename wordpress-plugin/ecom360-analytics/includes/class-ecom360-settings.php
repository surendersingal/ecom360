<?php
/**
 * Settings helper — manages all plugin options.
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_Settings {

    /**
     * Get all settings with defaults merged.
     *
     * @return array<string, mixed>
     */
    public static function get(): array {
        $saved = get_option( ECM360_OPTION_KEY, [] );
        return wp_parse_args( $saved, self::defaults() );
    }

    /**
     * Save all settings.
     *
     * @param array<string, mixed> $settings
     */
    public static function save( array $settings ): void {
        update_option( ECM360_OPTION_KEY, $settings );
    }

    /**
     * Get a single setting value.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function value( string $key, $default = '' ) {
        $settings = self::get();
        return $settings[ $key ] ?? $default;
    }

    /**
     * Default plugin settings.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return [
            // Connection
            'endpoint'          => '',
            'api_key'           => '',
            'secret_key'        => '',

            // Data Sync (server-to-server bulk sync)
            'sync_enabled'      => '',
            'sync_products'     => '1',
            'sync_categories'   => '1',
            'sync_inventory'    => '1',
            'sync_sales'        => '1',
            'sync_orders'       => '',
            'sync_customers'    => '',
            'sync_abandoned_carts' => '',

            // Event toggles
            'track_page_views'  => '1',
            'track_products'    => '1',
            'track_cart'        => '1',
            'track_checkout'    => '1',
            'track_purchases'   => '1',
            'track_search'      => '1',
            'track_login'       => '1',
            'track_register'    => '1',
            'track_reviews'     => '1',
            'track_wishlist'    => '1',
            'track_compare'     => '1',

            // Behavior
            'track_admins'      => '',
            'batch_events'      => '1',
            'batch_size'        => '10',
            'flush_interval'    => '5000', // ms
            'session_timeout'   => '30',   // minutes

            // Identity
            'enable_fingerprint' => '1',
            'capture_utm'        => '1',
            'capture_referrer'   => '1',

            // Popup Capture Widget
            'popup_enabled'           => '',
            'popup_trigger'           => 'time_delay',  // time_delay|scroll|exit_intent|page_load
            'popup_delay_seconds'     => '15',
            'popup_scroll_percent'    => '50',
            'popup_title'             => 'Get 10% Off Your First Order!',
            'popup_description'       => 'Subscribe to our newsletter and receive exclusive offers.',
            'popup_collect_name'      => '1',
            'popup_collect_email'     => '1',
            'popup_collect_phone'     => '',
            'popup_collect_dob'       => '',
            'popup_show_on'           => 'all_pages',
            'popup_show_frequency'    => 'once_per_session',

            // Push Notifications
            'push_enabled'            => '',
            'push_provider'           => 'firebase',   // firebase|onesignal
            'push_firebase_api_key'   => '',
            'push_firebase_sender_id' => '',
            'push_onesignal_app_id'   => '',
            'push_onesignal_api_key'  => '',
            'push_prompt_delay'       => '10',

            // Abandoned Cart Recovery
            'abandoned_cart_enabled'       => '1',
            'abandoned_cart_timeout'        => '60',     // minutes
            'abandoned_cart_send_email'     => '',
            'abandoned_cart_email_delay'    => '30',     // minutes
            'abandoned_cart_include_coupon' => '',
            'abandoned_cart_coupon_amount'  => '10',     // percent
            'abandoned_cart_coupon_type'    => 'percent', // percent|fixed_cart

            // Advanced Features
            'exit_intent_enabled'       => '1',
            'rage_click_enabled'        => '1',
            'free_shipping_bar_enabled' => '',
            'free_shipping_threshold'   => '50',
            'free_shipping_currency'    => '$',
            'interventions_enabled'     => '1',
            'interventions_poll_interval' => '15', // seconds
            'chatbot_enabled'           => '',
            'chatbot_position'          => 'bottom-right',
            'chatbot_greeting'          => 'Hi! How can I help you today?',
            'ai_search_enabled'         => '',
            'ai_search_visual_enabled'  => '',

            // Status
            'is_connected'       => '',
            'last_test_at'       => '',
        ];
    }
}

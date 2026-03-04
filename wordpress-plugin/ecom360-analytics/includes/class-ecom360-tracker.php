<?php
/**
 * Frontend tracker — enqueues the JS SDK and injects configuration.
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_Tracker {

    /** @var array<string, mixed> */
    private $settings;

    public function __construct( array $settings ) {
        $this->settings = $settings;
    }

    /**
     * Enqueue scripts and inject tracking config.
     */
    public function enqueue(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'register_scripts' ] );
        add_action( 'wp_head', [ $this, 'inject_config' ], 1 );
    }

    /**
     * Register and enqueue the tracker JavaScript.
     */
    public function register_scripts(): void {
        wp_enqueue_script(
            'ecom360-tracker',
            ECM360_PLUGIN_URL . 'assets/js/ecom360-tracker.js',
            [],
            ECM360_VERSION,
            [ 'strategy' => 'defer', 'in_footer' => false ]
        );

        // If WooCommerce is active, add the WC integration script
        if ( class_exists( 'WooCommerce' ) ) {
            wp_enqueue_script(
                'ecom360-wc',
                ECM360_PLUGIN_URL . 'assets/js/ecom360-wc.js',
                [ 'ecom360-tracker', 'jquery' ],
                ECM360_VERSION,
                [ 'strategy' => 'defer', 'in_footer' => true ]
            );
        }
    }

    /**
     * Inject tracker configuration snippet into <head>.
     */
    public function inject_config(): void {
        $config = [
            'endpoint'     => rtrim( $this->settings['endpoint'], '/' ),
            'apiKey'       => $this->settings['api_key'],
            'batchEvents'  => ! empty( $this->settings['batch_events'] ),
            'batchSize'    => (int) ( $this->settings['batch_size'] ?? 10 ),
            'flushInterval' => (int) ( $this->settings['flush_interval'] ?? 5000 ),
            'sessionTimeout' => (int) ( $this->settings['session_timeout'] ?? 30 ),
            'captureUtm'    => ! empty( $this->settings['capture_utm'] ),
            'captureReferrer' => ! empty( $this->settings['capture_referrer'] ),
            'enableFingerprint' => ! empty( $this->settings['enable_fingerprint'] ),
        ];

        // Build page context for the tracker
        $page_data = $this->get_page_data();

        // Merge events toggles
        $events = [
            'pageViews' => ! empty( $this->settings['track_page_views'] ),
            'products'  => ! empty( $this->settings['track_products'] ),
            'cart'      => ! empty( $this->settings['track_cart'] ),
            'checkout'  => ! empty( $this->settings['track_checkout'] ),
            'purchases' => ! empty( $this->settings['track_purchases'] ),
            'search'    => ! empty( $this->settings['track_search'] ),
            'login'     => ! empty( $this->settings['track_login'] ),
            'register'  => ! empty( $this->settings['track_register'] ),
            'reviews'   => ! empty( $this->settings['track_reviews'] ),
        ];

        // Advanced features config for exit-intent, rage-click, free shipping, interventions
        $advanced = [
            'exitIntent'               => ! empty( $this->settings['exit_intent_enabled'] ),
            'rageClick'                => ! empty( $this->settings['rage_click_enabled'] ),
            'freeShippingBar'          => ! empty( $this->settings['free_shipping_bar_enabled'] ),
            'freeShippingThreshold'    => (float) ( $this->settings['free_shipping_threshold'] ?? 50 ),
            'freeShippingCurrency'     => $this->settings['free_shipping_currency'] ?? '$',
            'interventions'            => ! empty( $this->settings['interventions_enabled'] ),
            'interventionsPollInterval' => (int) ( $this->settings['interventions_poll_interval'] ?? 15 ),
        ];

        echo '<script id="ecom360-config" type="application/json">'
            . wp_json_encode( [
                'config'   => $config,
                'events'   => $events,
                'page'     => $page_data,
                'advanced' => $advanced,
            ], JSON_UNESCAPED_SLASHES )
            . '</script>' . "\n";
    }

    /**
     * Build current page context.
     *
     * @return array<string, mixed>
     */
    private function get_page_data(): array {
        $data = [
            'url'       => home_url( $_SERVER['REQUEST_URI'] ?? '/' ),
            'title'     => wp_get_document_title(),
            'type'      => 'page',
        ];

        // Add user identity if logged in
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $data['customer'] = [
                'type'  => 'email',
                'value' => $user->user_email,
            ];
        }

        // WooCommerce page context
        if ( function_exists( 'is_shop' ) ) {
            if ( is_shop() ) {
                $data['type'] = 'shop';
            } elseif ( is_product_category() ) {
                $data['type'] = 'category';
                $term = get_queried_object();
                if ( $term ) {
                    $data['category'] = $term->name;
                }
            } elseif ( is_product() ) {
                $data['type'] = 'product';
                global $product;
                if ( $product instanceof WC_Product ) {
                    $data['product'] = [
                        'id'       => (string) $product->get_id(),
                        'name'     => $product->get_name(),
                        'price'    => (float) $product->get_price(),
                        'category' => $this->get_product_category( $product ),
                        'sku'      => $product->get_sku(),
                    ];
                }
            } elseif ( is_cart() ) {
                $data['type'] = 'cart';
            } elseif ( is_checkout() ) {
                $data['type'] = 'checkout';
            } elseif ( is_account_page() ) {
                $data['type'] = 'account';
            } elseif ( is_wc_endpoint_url( 'order-received' ) ) {
                $data['type'] = 'order_confirmation';
            }
        }

        return $data;
    }

    /**
     * Get primary category name for a product.
     */
    private function get_product_category( WC_Product $product ): string {
        $cats = $product->get_category_ids();
        if ( empty( $cats ) ) {
            return 'Uncategorized';
        }
        $term = get_term( $cats[0], 'product_cat' );
        return ( $term && ! is_wp_error( $term ) ) ? $term->name : 'Uncategorized';
    }
}

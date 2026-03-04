<?php
/**
 * Plugin Name:       Ecom360 Analytics
 * Plugin URI:        https://ecom360.io/wordpress
 * Description:       Complete ecommerce analytics tracking for WooCommerce stores. Captures page views, sessions, products, carts, purchases, customer events, and more — sent to your Ecom360 Analytics dashboard.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Ecom360
 * Author URI:        https://ecom360.io
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ecom360-analytics
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.6
 */

defined('ABSPATH') || exit;

// ─── Constants ───────────────────────────────────────────────────────
define('ECM360_VERSION',      '1.0.0');
define('ECM360_PLUGIN_FILE',  __FILE__);
define('ECM360_PLUGIN_DIR',   plugin_dir_path(__FILE__));
define('ECM360_PLUGIN_URL',   plugin_dir_url(__FILE__));
define('ECM360_OPTION_KEY',   'ecom360_settings');

// ─── Autoload ────────────────────────────────────────────────────────
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-settings.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-database.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-event-queue.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-tracker.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-woocommerce.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-admin.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-rest.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-datasync.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-popup.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-chatbot.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-aisearch.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-push.php';
require_once ECM360_PLUGIN_DIR . 'includes/class-ecom360-abandoned-cart.php';

// ─── Boot ────────────────────────────────────────────────────────────
/**
 * Main plugin class.
 */
final class Ecom360_Analytics {

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Admin settings page
        if ( is_admin() ) {
            new Ecom360_Admin();
        }

        // REST API endpoints (for settings test / health check + popup/push)
        new Ecom360_REST();

        // Frontend tracker & WooCommerce hooks
        add_action( 'wp', [ $this, 'init_tracking' ] );

        // Frontend widgets (popup, chatbot, AI search, push)
        add_action( 'wp', [ $this, 'init_widgets' ] );

        // WooCommerce server-side hooks (always loaded if WC is active)
        if ( $this->is_woocommerce_active() ) {
            new Ecom360_WooCommerce();
        }

        // Abandoned cart handler (WooCommerce required)
        if ( $this->is_woocommerce_active() ) {
            $this->init_abandoned_cart();
        }

        // DataSync: server-to-server bulk sync (WooCommerce required)
        if ( $this->is_woocommerce_active() ) {
            $this->init_datasync();
        }

        // Event queue consumer cron
        $this->init_event_queue_cron();

        // Activation / deactivation hooks
        register_activation_hook( ECM360_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( ECM360_PLUGIN_FILE, [ $this, 'deactivate' ] );
    }

    /**
     * Initialize client-side tracking on front-end pages.
     */
    public function init_tracking(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        $settings = Ecom360_Settings::get();
        if ( empty( $settings['api_key'] ) || empty( $settings['endpoint'] ) ) {
            return;
        }

        // Don't track logged-in admins unless they opted in
        if ( current_user_can( 'manage_options' ) && empty( $settings['track_admins'] ) ) {
            return;
        }

        $tracker = new Ecom360_Tracker( $settings );
        $tracker->enqueue();
    }

    /**
     * Initialize frontend widgets: popup, chatbot, AI search, push.
     */
    public function init_widgets(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        $settings = Ecom360_Settings::get();
        if ( empty( $settings['api_key'] ) || empty( $settings['endpoint'] ) ) {
            return;
        }

        // Don't show widgets to admin users (unless track_admins enabled)
        if ( current_user_can( 'manage_options' ) && empty( $settings['track_admins'] ) ) {
            return;
        }

        $popup = new Ecom360_Popup( $settings );
        $popup->enqueue();

        $chatbot = new Ecom360_Chatbot( $settings );
        $chatbot->enqueue();

        $aisearch = new Ecom360_AiSearch( $settings );
        $aisearch->enqueue();

        $push = new Ecom360_Push( $settings );
        $push->enqueue();
    }

    /**
     * Initialize abandoned cart handling.
     */
    private function init_abandoned_cart(): void {
        $settings = Ecom360_Settings::get();
        if ( empty( $settings['abandoned_cart_enabled'] ) ) return;

        $handler = new Ecom360_AbandonedCart( $settings );
        $handler->register();
    }

    /**
     * Register event queue consumer cron.
     */
    private function init_event_queue_cron(): void {
        if ( ! wp_next_scheduled( 'ecom360_process_event_queue' ) ) {
            wp_schedule_event( time(), 'every_minute', 'ecom360_process_event_queue' );
        }
        add_action( 'ecom360_process_event_queue', [ 'Ecom360_EventQueue', 'process' ] );
        add_filter( 'cron_schedules', [ $this, 'add_minute_cron' ] );
    }

    /**
     * Add per-minute cron schedule.
     */
    public function add_minute_cron( $schedules ) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => __( 'Every Minute', 'ecom360-analytics' ),
        ];
        return $schedules;
    }

    /**
     * Check if WooCommerce is active.
     */
    public function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Initialize DataSync module if enabled.
     */
    private function init_datasync(): void {
        $settings = Ecom360_Settings::get();
        if ( empty( $settings['sync_enabled'] ) || empty( $settings['secret_key'] ) ) {
            return;
        }

        $datasync = new Ecom360_DataSync( $settings );
        $datasync->register();
    }

    /**
     * Plugin activation: set defaults, create DB tables, register DataSync connection.
     */
    public function activate(): void {
        $defaults = Ecom360_Settings::defaults();
        if ( ! get_option( ECM360_OPTION_KEY ) ) {
            update_option( ECM360_OPTION_KEY, $defaults );
        }

        // Create custom database tables
        Ecom360_Database::install();

        // Register DataSync connection on activation if configured
        $settings = Ecom360_Settings::get();
        if ( ! empty( $settings['sync_enabled'] ) && ! empty( $settings['secret_key'] ) && ! empty( $settings['endpoint'] ) ) {
            $datasync = new Ecom360_DataSync( $settings );
            $datasync->register_connection();
        }
    }

    /**
     * Plugin deactivation: clear all cron schedules.
     */
    public function deactivate(): void {
        Ecom360_DataSync::clear_schedules();
        Ecom360_AbandonedCart::clear_schedule();
        wp_clear_scheduled_hook( 'ecom360_process_event_queue' );
    }
}

// ─── Launch ──────────────────────────────────────────────────────────
add_action( 'plugins_loaded', [ 'Ecom360_Analytics', 'instance' ] );

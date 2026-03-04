<?php
/**
 * Admin settings page for Ecom360 Analytics.
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ─────────────────────────── Menu ──────────────────────────────────── */

    public function add_menu(): void {
        // If WooCommerce is active, nest under it; otherwise under Settings
        $parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';

        add_submenu_page(
            $parent,
            __( 'Ecom360 Analytics', 'ecom360-analytics' ),
            __( 'Ecom360 Analytics', 'ecom360-analytics' ),
            'manage_options',
            'ecom360-analytics',
            [ $this, 'render_page' ]
        );
    }

    /* ─────────────────────────── Assets ────────────────────────────────── */

    public function enqueue_assets( $hook ): void {
        if ( false === strpos( $hook, 'ecom360-analytics' ) ) return;

        wp_enqueue_style(
            'ecom360-admin',
            ECM360_PLUGIN_URL . 'admin/css/admin.css',
            [],
            ECM360_VERSION
        );

        wp_enqueue_script(
            'ecom360-admin',
            ECM360_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            ECM360_VERSION,
            true
        );

        wp_localize_script( 'ecom360-admin', 'ecom360Admin', [
            'restUrl' => rest_url( 'ecom360/v1/' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ]);
    }

    /* ─────────────────────────── Settings registration ─────────────────── */

    public function register_settings(): void {
        register_setting( 'ecom360_settings', ECM360_OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize' ],
        ]);
    }

    /**
     * Sanitize incoming settings.
     *
     * @param  array $input
     * @return array
     */
    public function sanitize( $input ): array {
        $clean = Ecom360_Settings::defaults();

        $clean['endpoint']   = esc_url_raw( $input['endpoint'] ?? '' );
        $clean['api_key']    = sanitize_text_field( $input['api_key'] ?? '' );
        $clean['secret_key'] = sanitize_text_field( $input['secret_key'] ?? '' );

        // Checkboxes
        $toggles = [
            'track_page_views', 'track_products', 'track_cart', 'track_checkout',
            'track_purchases', 'track_search', 'track_login', 'track_register',
            'track_reviews', 'track_admins', 'batch_events', 'enable_fingerprint',
            'capture_utm', 'capture_referrer',
            'sync_enabled', 'sync_products', 'sync_categories', 'sync_inventory',
            'sync_sales', 'sync_orders', 'sync_customers', 'sync_abandoned_carts',
            // New feature toggles
            'popup_enabled', 'popup_collect_name', 'popup_collect_email',
            'popup_collect_phone', 'popup_collect_dob',
            'push_enabled',
            'abandoned_cart_enabled', 'abandoned_cart_send_email', 'abandoned_cart_include_coupon',
            'exit_intent_enabled', 'rage_click_enabled', 'free_shipping_bar_enabled',
            'interventions_enabled', 'chatbot_enabled', 'ai_search_enabled',
            'ai_search_visual_enabled', 'track_wishlist', 'track_compare',
        ];
        foreach ( $toggles as $key ) {
            $clean[ $key ] = ! empty( $input[ $key ] );
        }

        // Numeric
        $clean['batch_size']       = absint( $input['batch_size'] ?? 10 );
        $clean['flush_interval']   = absint( $input['flush_interval'] ?? 5000 );
        $clean['session_timeout']  = absint( $input['session_timeout'] ?? 30 );
        $clean['popup_delay_seconds']  = absint( $input['popup_delay_seconds'] ?? 5 );
        $clean['popup_scroll_percent'] = absint( $input['popup_scroll_percent'] ?? 50 );
        $clean['push_prompt_delay']    = absint( $input['push_prompt_delay'] ?? 10 );
        $clean['abandoned_cart_timeout']    = absint( $input['abandoned_cart_timeout'] ?? 30 );
        $clean['abandoned_cart_email_delay'] = absint( $input['abandoned_cart_email_delay'] ?? 60 );
        $clean['abandoned_cart_coupon_amount'] = absint( $input['abandoned_cart_coupon_amount'] ?? 10 );
        $clean['free_shipping_threshold'] = floatval( $input['free_shipping_threshold'] ?? 50 );
        $clean['interventions_poll_interval'] = absint( $input['interventions_poll_interval'] ?? 15 );

        // Strings
        $textFields = [
            'popup_trigger', 'popup_title', 'popup_description', 'popup_show_on',
            'popup_show_frequency', 'push_provider', 'push_firebase_api_key',
            'push_firebase_sender_id', 'push_onesignal_app_id', 'push_onesignal_api_key',
            'abandoned_cart_coupon_type', 'free_shipping_currency', 'chatbot_position',
            'chatbot_greeting',
        ];
        foreach ( $textFields as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $clean[ $key ] = sanitize_text_field( $input[ $key ] );
            }
        }

        // Preserve connection-test status
        $existing = Ecom360_Settings::get();
        $clean['is_connected'] = $existing['is_connected'] ?? false;
        $clean['last_test_at'] = $existing['last_test_at'] ?? '';

        return $clean;
    }

    /* ─────────────────────────── Page renderer ──────────────────────────── */

    public function render_page(): void {
        $s = Ecom360_Settings::get();
        ?>
        <div class="wrap ecom360-wrap">
            <h1>
                <span class="ecom360-logo">📊</span>
                <?php esc_html_e( 'Ecom360 Analytics', 'ecom360-analytics' ); ?>
            </h1>

            <!-- Connection status banner -->
            <div id="ecom360-status" class="ecom360-status <?php echo $s['is_connected'] ? 'connected' : 'disconnected'; ?>">
                <span class="indicator"></span>
                <span class="label">
                    <?php echo $s['is_connected']
                        ? esc_html__( 'Connected', 'ecom360-analytics' )
                        : esc_html__( 'Not connected', 'ecom360-analytics' ); ?>
                </span>
                <?php if ( $s['last_test_at'] ): ?>
                    <small>— <?php printf( esc_html__( 'last checked %s', 'ecom360-analytics' ), esc_html( $s['last_test_at'] ) ); ?></small>
                <?php endif; ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'ecom360_settings' ); ?>

                <!-- ═══ Connection ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'Connection', 'ecom360-analytics' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="endpoint"><?php esc_html_e( 'API Endpoint', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <input type="url" id="endpoint" name="<?php echo ECM360_OPTION_KEY; ?>[endpoint]"
                                       value="<?php echo esc_attr( $s['endpoint'] ); ?>" class="regular-text"
                                       placeholder="https://analytics.example.com">
                                <p class="description"><?php esc_html_e( 'Your Ecom360 platform URL (without /api path).', 'ecom360-analytics' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="api_key"><?php esc_html_e( 'API Key', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <input type="password" id="api_key" name="<?php echo ECM360_OPTION_KEY; ?>[api_key]"
                                       value="<?php echo esc_attr( $s['api_key'] ); ?>" class="regular-text"
                                       autocomplete="off">
                                <button type="button" id="ecom360-test-connection" class="button button-secondary">
                                    <?php esc_html_e( 'Test Connection', 'ecom360-analytics' ); ?>
                                </button>
                                <span id="ecom360-test-result"></span>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="secret_key"><?php esc_html_e( 'Secret Key', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <input type="password" id="secret_key" name="<?php echo ECM360_OPTION_KEY; ?>[secret_key]"
                                       value="<?php echo esc_attr( $s['secret_key'] ?? '' ); ?>" class="regular-text"
                                       autocomplete="off">
                                <p class="description"><?php esc_html_e( 'Required for server-to-server data sync (products, orders, customers).', 'ecom360-analytics' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ═══ Data Sync ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'Data Sync', 'ecom360-analytics' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Bulk sync WooCommerce data to your Ecom360 dashboard. Requires Secret Key above.', 'ecom360-analytics' ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Enable Data Sync', 'ecom360-analytics' ); ?></th>
                            <td>
                                <label class="ecom360-toggle">
                                    <input type="hidden" name="<?php echo ECM360_OPTION_KEY; ?>[sync_enabled]" value="0">
                                    <input type="checkbox" name="<?php echo ECM360_OPTION_KEY; ?>[sync_enabled]"
                                           value="1" <?php checked( $s['sync_enabled'] ?? '' ); ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                        <?php
                        $syncEntities = [
                            'sync_products'        => __( 'Products (Public)', 'ecom360-analytics' ),
                            'sync_categories'      => __( 'Categories (Public)', 'ecom360-analytics' ),
                            'sync_inventory'       => __( 'Inventory (Public)', 'ecom360-analytics' ),
                            'sync_sales'           => __( 'Sales Aggregates (Public)', 'ecom360-analytics' ),
                            'sync_orders'          => __( 'Orders (Restricted — includes email)', 'ecom360-analytics' ),
                            'sync_customers'       => __( 'Customers (Sensitive — PII)', 'ecom360-analytics' ),
                            'sync_abandoned_carts' => __( 'Abandoned Carts (Restricted)', 'ecom360-analytics' ),
                        ];
                        foreach ( $syncEntities as $key => $label ): ?>
                            <tr>
                                <th><?php echo esc_html( $label ); ?></th>
                                <td>
                                    <label class="ecom360-toggle">
                                        <input type="hidden" name="<?php echo ECM360_OPTION_KEY; ?>[<?php echo $key; ?>]" value="0">
                                        <input type="checkbox" name="<?php echo ECM360_OPTION_KEY; ?>[<?php echo $key; ?>]"
                                               value="1" <?php checked( $s[ $key ] ?? '' ); ?>>
                                        <span class="slider"></span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <!-- ═══ Event Tracking ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'Event Tracking', 'ecom360-analytics' ); ?></h2>
                    <table class="form-table">
                        <?php
                        $events = [
                            'track_page_views' => __( 'Page Views', 'ecom360-analytics' ),
                            'track_products'   => __( 'Product Views', 'ecom360-analytics' ),
                            'track_cart'       => __( 'Cart Events (add / remove / abandoned)', 'ecom360-analytics' ),
                            'track_checkout'   => __( 'Checkout Events', 'ecom360-analytics' ),
                            'track_purchases'  => __( 'Purchases & Orders', 'ecom360-analytics' ),
                            'track_search'     => __( 'Site Search', 'ecom360-analytics' ),
                            'track_login'      => __( 'User Login', 'ecom360-analytics' ),
                            'track_register'   => __( 'User Registration', 'ecom360-analytics' ),
                            'track_reviews'    => __( 'Product Reviews', 'ecom360-analytics' ),
                        ];
                        foreach ( $events as $key => $label ): ?>
                            <tr>
                                <th><?php echo esc_html( $label ); ?></th>
                                <td>
                                    <label class="ecom360-toggle">
                                        <input type="hidden" name="<?php echo ECM360_OPTION_KEY; ?>[<?php echo $key; ?>]" value="0">
                                        <input type="checkbox" name="<?php echo ECM360_OPTION_KEY; ?>[<?php echo $key; ?>]"
                                               value="1" <?php checked( $s[ $key ] ); ?>>
                                        <span class="slider"></span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <!-- ═══ Behavior ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'Behavior', 'ecom360-analytics' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Track Admin Users', 'ecom360-analytics' ); ?></th>
                            <td>
                                <label class="ecom360-toggle">
                                    <input type="hidden" name="<?php echo ECM360_OPTION_KEY; ?>[track_admins]" value="0">
                                    <input type="checkbox" name="<?php echo ECM360_OPTION_KEY; ?>[track_admins]"
                                           value="1" <?php checked( $s['track_admins'] ); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'When disabled, logged-in administrators are excluded.', 'ecom360-analytics' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Batch Events', 'ecom360-analytics' ); ?></th>
                            <td>
                                <label class="ecom360-toggle">
                                    <input type="hidden" name="<?php echo ECM360_OPTION_KEY; ?>[batch_events]" value="0">
                                    <input type="checkbox" name="<?php echo ECM360_OPTION_KEY; ?>[batch_events]"
                                           value="1" <?php checked( $s['batch_events'] ); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Queue events and send in batches to reduce HTTP requests.', 'ecom360-analytics' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="batch_size"><?php esc_html_e( 'Batch Size', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <input type="number" id="batch_size" name="<?php echo ECM360_OPTION_KEY; ?>[batch_size]"
                                       value="<?php echo esc_attr( $s['batch_size'] ); ?>" min="1" max="50" class="small-text">
                                <p class="description"><?php esc_html_e( 'Max events per batch request (API limit: 50).', 'ecom360-analytics' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="flush_interval"><?php esc_html_e( 'Flush Interval (ms)', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <input type="number" id="flush_interval" name="<?php echo ECM360_OPTION_KEY; ?>[flush_interval]"
                                       value="<?php echo esc_attr( $s['flush_interval'] ); ?>" min="1000" max="60000" step="1000" class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="session_timeout"><?php esc_html_e( 'Session Timeout (min)', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <input type="number" id="session_timeout" name="<?php echo ECM360_OPTION_KEY; ?>[session_timeout]"
                                       value="<?php echo esc_attr( $s['session_timeout'] ); ?>" min="5" max="120" class="small-text">
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ═══ Identity & Privacy ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'Identity & Privacy', 'ecom360-analytics' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Device Fingerprinting', 'ecom360-analytics' ); ?></th>
                            <td>
                                <label class="ecom360-toggle">
                                    <input type="hidden" name="<?php echo ECM360_OPTION_KEY; ?>[enable_fingerprint]" value="0">
                                    <input type="checkbox" name="<?php echo ECM360_OPTION_KEY; ?>[enable_fingerprint]"
                                           value="1" <?php checked( $s['enable_fingerprint'] ); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Generate a lightweight browser fingerprint for cross-session identification.', 'ecom360-analytics' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Capture UTM Parameters', 'ecom360-analytics' ); ?></th>
                            <td>
                                <label class="ecom360-toggle">
                                    <input type="hidden" name="<?php echo ECM360_OPTION_KEY; ?>[capture_utm]" value="0">
                                    <input type="checkbox" name="<?php echo ECM360_OPTION_KEY; ?>[capture_utm]"
                                           value="1" <?php checked( $s['capture_utm'] ); ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Capture Referrer', 'ecom360-analytics' ); ?></th>
                            <td>
                                <label class="ecom360-toggle">
                                    <input type="hidden" name="<?php echo ECM360_OPTION_KEY; ?>[capture_referrer]" value="0">
                                    <input type="checkbox" name="<?php echo ECM360_OPTION_KEY; ?>[capture_referrer]"
                                           value="1" <?php checked( $s['capture_referrer'] ); ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ═══ Popup Capture Widget ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'Popup Capture Widget', 'ecom360-analytics' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Show a lead capture popup to visitors. Captured data syncs to your Ecom360 dashboard.', 'ecom360-analytics' ); ?></p>
                    <table class="form-table">
                        <?php $this->render_toggle( $s, 'popup_enabled', __( 'Enable Popup', 'ecom360-analytics' ) ); ?>
                        <tr>
                            <th><label for="popup_trigger"><?php esc_html_e( 'Trigger', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <select id="popup_trigger" name="<?php echo ECM360_OPTION_KEY; ?>[popup_trigger]">
                                    <?php foreach ( ['time_delay' => 'Time Delay', 'scroll' => 'Scroll %', 'exit_intent' => 'Exit Intent', 'page_load' => 'Page Load'] as $v => $l ): ?>
                                        <option value="<?php echo $v; ?>" <?php selected( $s['popup_trigger'] ?? 'time_delay', $v ); ?>><?php echo esc_html( $l ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="popup_delay_seconds"><?php esc_html_e( 'Delay (seconds)', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="number" id="popup_delay_seconds" name="<?php echo ECM360_OPTION_KEY; ?>[popup_delay_seconds]" value="<?php echo esc_attr( $s['popup_delay_seconds'] ?? 5 ); ?>" min="0" max="120" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><label for="popup_scroll_percent"><?php esc_html_e( 'Scroll %', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="number" id="popup_scroll_percent" name="<?php echo ECM360_OPTION_KEY; ?>[popup_scroll_percent]" value="<?php echo esc_attr( $s['popup_scroll_percent'] ?? 50 ); ?>" min="0" max="100" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><label for="popup_title"><?php esc_html_e( 'Title', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="text" id="popup_title" name="<?php echo ECM360_OPTION_KEY; ?>[popup_title]" value="<?php echo esc_attr( $s['popup_title'] ?? '' ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="popup_description"><?php esc_html_e( 'Description', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="text" id="popup_description" name="<?php echo ECM360_OPTION_KEY; ?>[popup_description]" value="<?php echo esc_attr( $s['popup_description'] ?? '' ); ?>" class="regular-text"></td>
                        </tr>
                        <?php
                        $this->render_toggle( $s, 'popup_collect_name', __( 'Collect Name', 'ecom360-analytics' ) );
                        $this->render_toggle( $s, 'popup_collect_email', __( 'Collect Email', 'ecom360-analytics' ) );
                        $this->render_toggle( $s, 'popup_collect_phone', __( 'Collect Phone', 'ecom360-analytics' ) );
                        $this->render_toggle( $s, 'popup_collect_dob', __( 'Collect Date of Birth', 'ecom360-analytics' ) );
                        ?>
                        <tr>
                            <th><label for="popup_show_on"><?php esc_html_e( 'Show On', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <select id="popup_show_on" name="<?php echo ECM360_OPTION_KEY; ?>[popup_show_on]">
                                    <?php foreach ( ['all' => 'All Pages', 'homepage' => 'Homepage Only', 'products' => 'Product Pages', 'cart' => 'Cart/Checkout'] as $v => $l ): ?>
                                        <option value="<?php echo $v; ?>" <?php selected( $s['popup_show_on'] ?? 'all', $v ); ?>><?php echo esc_html( $l ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="popup_show_frequency"><?php esc_html_e( 'Frequency', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <select id="popup_show_frequency" name="<?php echo ECM360_OPTION_KEY; ?>[popup_show_frequency]">
                                    <?php foreach ( ['once' => 'Once Ever', 'once_per_session' => 'Once Per Session', 'always' => 'Every Page Load'] as $v => $l ): ?>
                                        <option value="<?php echo $v; ?>" <?php selected( $s['popup_show_frequency'] ?? 'once', $v ); ?>><?php echo esc_html( $l ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ═══ Push Notifications ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'Push Notifications', 'ecom360-analytics' ); ?></h2>
                    <table class="form-table">
                        <?php $this->render_toggle( $s, 'push_enabled', __( 'Enable Push Opt-in', 'ecom360-analytics' ) ); ?>
                        <tr>
                            <th><label for="push_provider"><?php esc_html_e( 'Provider', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <select id="push_provider" name="<?php echo ECM360_OPTION_KEY; ?>[push_provider]">
                                    <option value="firebase" <?php selected( $s['push_provider'] ?? 'firebase', 'firebase' ); ?>>Firebase</option>
                                    <option value="onesignal" <?php selected( $s['push_provider'] ?? '', 'onesignal' ); ?>>OneSignal</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="push_firebase_api_key"><?php esc_html_e( 'Firebase API Key', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="text" id="push_firebase_api_key" name="<?php echo ECM360_OPTION_KEY; ?>[push_firebase_api_key]" value="<?php echo esc_attr( $s['push_firebase_api_key'] ?? '' ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="push_firebase_sender_id"><?php esc_html_e( 'Firebase Sender ID', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="text" id="push_firebase_sender_id" name="<?php echo ECM360_OPTION_KEY; ?>[push_firebase_sender_id]" value="<?php echo esc_attr( $s['push_firebase_sender_id'] ?? '' ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="push_onesignal_app_id"><?php esc_html_e( 'OneSignal App ID', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="text" id="push_onesignal_app_id" name="<?php echo ECM360_OPTION_KEY; ?>[push_onesignal_app_id]" value="<?php echo esc_attr( $s['push_onesignal_app_id'] ?? '' ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="push_prompt_delay"><?php esc_html_e( 'Prompt Delay (s)', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="number" id="push_prompt_delay" name="<?php echo ECM360_OPTION_KEY; ?>[push_prompt_delay]" value="<?php echo esc_attr( $s['push_prompt_delay'] ?? 10 ); ?>" min="0" max="120" class="small-text"></td>
                        </tr>
                    </table>
                </div>

                <!-- ═══ Abandoned Cart Recovery ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'Abandoned Cart Recovery', 'ecom360-analytics' ); ?></h2>
                    <table class="form-table">
                        <?php $this->render_toggle( $s, 'abandoned_cart_enabled', __( 'Enable Abandoned Cart Tracking', 'ecom360-analytics' ) ); ?>
                        <tr>
                            <th><label for="abandoned_cart_timeout"><?php esc_html_e( 'Timeout (minutes)', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <input type="number" id="abandoned_cart_timeout" name="<?php echo ECM360_OPTION_KEY; ?>[abandoned_cart_timeout]" value="<?php echo esc_attr( $s['abandoned_cart_timeout'] ?? 30 ); ?>" min="5" max="1440" class="small-text">
                                <p class="description"><?php esc_html_e( 'Minutes of inactivity before a cart is marked abandoned.', 'ecom360-analytics' ); ?></p>
                            </td>
                        </tr>
                        <?php $this->render_toggle( $s, 'abandoned_cart_send_email', __( 'Send Recovery Emails', 'ecom360-analytics' ) ); ?>
                        <tr>
                            <th><label for="abandoned_cart_email_delay"><?php esc_html_e( 'Email Delay (minutes)', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="number" id="abandoned_cart_email_delay" name="<?php echo ECM360_OPTION_KEY; ?>[abandoned_cart_email_delay]" value="<?php echo esc_attr( $s['abandoned_cart_email_delay'] ?? 60 ); ?>" min="5" max="1440" class="small-text"></td>
                        </tr>
                        <?php $this->render_toggle( $s, 'abandoned_cart_include_coupon', __( 'Include Coupon Code', 'ecom360-analytics' ) ); ?>
                        <tr>
                            <th><label for="abandoned_cart_coupon_amount"><?php esc_html_e( 'Coupon Amount', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="number" id="abandoned_cart_coupon_amount" name="<?php echo ECM360_OPTION_KEY; ?>[abandoned_cart_coupon_amount]" value="<?php echo esc_attr( $s['abandoned_cart_coupon_amount'] ?? 10 ); ?>" min="1" max="100" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><label for="abandoned_cart_coupon_type"><?php esc_html_e( 'Coupon Type', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <select id="abandoned_cart_coupon_type" name="<?php echo ECM360_OPTION_KEY; ?>[abandoned_cart_coupon_type]">
                                    <option value="percent" <?php selected( $s['abandoned_cart_coupon_type'] ?? 'percent', 'percent' ); ?>>Percentage (%)</option>
                                    <option value="fixed_cart" <?php selected( $s['abandoned_cart_coupon_type'] ?? '', 'fixed_cart' ); ?>>Fixed Cart Discount</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ═══ AI Chatbot ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'AI Chatbot', 'ecom360-analytics' ); ?></h2>
                    <table class="form-table">
                        <?php $this->render_toggle( $s, 'chatbot_enabled', __( 'Enable Chatbot Widget', 'ecom360-analytics' ) ); ?>
                        <tr>
                            <th><label for="chatbot_position"><?php esc_html_e( 'Position', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <select id="chatbot_position" name="<?php echo ECM360_OPTION_KEY; ?>[chatbot_position]">
                                    <?php foreach ( ['bottom-right' => 'Bottom Right', 'bottom-left' => 'Bottom Left', 'top-right' => 'Top Right', 'top-left' => 'Top Left'] as $v => $l ): ?>
                                        <option value="<?php echo $v; ?>" <?php selected( $s['chatbot_position'] ?? 'bottom-right', $v ); ?>><?php echo esc_html( $l ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="chatbot_greeting"><?php esc_html_e( 'Greeting Message', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="text" id="chatbot_greeting" name="<?php echo ECM360_OPTION_KEY; ?>[chatbot_greeting]" value="<?php echo esc_attr( $s['chatbot_greeting'] ?? '' ); ?>" class="regular-text"></td>
                        </tr>
                    </table>
                </div>

                <!-- ═══ AI Search ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'AI Search', 'ecom360-analytics' ); ?></h2>
                    <table class="form-table">
                        <?php
                        $this->render_toggle( $s, 'ai_search_enabled', __( 'Enable AI Search Overlay', 'ecom360-analytics' ) );
                        $this->render_toggle( $s, 'ai_search_visual_enabled', __( 'Visual / Image Search', 'ecom360-analytics' ) );
                        ?>
                    </table>
                </div>

                <!-- ═══ Advanced Features ═══ -->
                <div class="ecom360-card">
                    <h2><?php esc_html_e( 'Advanced Features', 'ecom360-analytics' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'AI-driven engagement features. These require active platform connection.', 'ecom360-analytics' ); ?></p>
                    <table class="form-table">
                        <?php
                        $this->render_toggle( $s, 'exit_intent_enabled', __( 'Exit Intent Detection', 'ecom360-analytics' ) );
                        $this->render_toggle( $s, 'rage_click_enabled', __( 'Rage Click Detection', 'ecom360-analytics' ) );
                        $this->render_toggle( $s, 'free_shipping_bar_enabled', __( 'Free Shipping Progress Bar', 'ecom360-analytics' ) );
                        ?>
                        <tr>
                            <th><label for="free_shipping_threshold"><?php esc_html_e( 'Free Shipping Threshold', 'ecom360-analytics' ); ?></label></th>
                            <td>
                                <input type="number" id="free_shipping_threshold" name="<?php echo ECM360_OPTION_KEY; ?>[free_shipping_threshold]" value="<?php echo esc_attr( $s['free_shipping_threshold'] ?? 50 ); ?>" min="0" step="0.01" class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="free_shipping_currency"><?php esc_html_e( 'Currency Symbol', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="text" id="free_shipping_currency" name="<?php echo ECM360_OPTION_KEY; ?>[free_shipping_currency]" value="<?php echo esc_attr( $s['free_shipping_currency'] ?? '$' ); ?>" class="small-text"></td>
                        </tr>
                        <?php $this->render_toggle( $s, 'interventions_enabled', __( 'Intervention Polling', 'ecom360-analytics' ) ); ?>
                        <tr>
                            <th><label for="interventions_poll_interval"><?php esc_html_e( 'Poll Interval (seconds)', 'ecom360-analytics' ); ?></label></th>
                            <td><input type="number" id="interventions_poll_interval" name="<?php echo ECM360_OPTION_KEY; ?>[interventions_poll_interval]" value="<?php echo esc_attr( $s['interventions_poll_interval'] ?? 15 ); ?>" min="5" max="120" class="small-text"></td>
                        </tr>
                        <?php
                        $this->render_toggle( $s, 'track_wishlist', __( 'Track Wishlist', 'ecom360-analytics' ) );
                        $this->render_toggle( $s, 'track_compare', __( 'Track Product Compare', 'ecom360-analytics' ) );
                        ?>
                    </table>
                </div>

                <?php submit_button( __( 'Save Settings', 'ecom360-analytics' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render a standard toggle row.
     */
    private function render_toggle( array $s, string $key, string $label ): void {
        ?>
        <tr>
            <th><?php echo esc_html( $label ); ?></th>
            <td>
                <label class="ecom360-toggle">
                    <input type="hidden" name="<?php echo ECM360_OPTION_KEY; ?>[<?php echo $key; ?>]" value="0">
                    <input type="checkbox" name="<?php echo ECM360_OPTION_KEY; ?>[<?php echo $key; ?>]"
                           value="1" <?php checked( $s[ $key ] ?? '' ); ?>>
                    <span class="slider"></span>
                </label>
            </td>
        </tr>
        <?php
    }
}

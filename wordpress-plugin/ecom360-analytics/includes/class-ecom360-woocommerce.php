<?php
/**
 * WooCommerce event hooks – captures server-side commerce events and sends
 * them to the ecom360 API so they are recorded even when JS doesn't fire.
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_WooCommerce {

    /** @var array<string, mixed> */
    private $settings;

    /** @var string */
    private $endpoint;

    /** @var string */
    private $api_key;

    public function __construct( array $settings ) {
        $this->settings  = $settings;
        $this->endpoint  = rtrim( $settings['endpoint'], '/' ) . '/api/v1/collect';
        $this->api_key   = $settings['api_key'];
    }

    /**
     * Register WooCommerce hooks.
     */
    public function register(): void {
        // ---------- Cart events ----------
        if ( ! empty( $this->settings['track_cart'] ) ) {
            add_action( 'woocommerce_add_to_cart', [ $this, 'on_add_to_cart' ], 10, 6 );
            add_action( 'woocommerce_remove_cart_item', [ $this, 'on_remove_from_cart' ], 10, 2 );
            add_action( 'woocommerce_cart_item_restored', [ $this, 'on_cart_item_restored' ], 10, 2 );
        }

        // ---------- Checkout ----------
        if ( ! empty( $this->settings['track_checkout'] ) ) {
            add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_checkout' ], 10, 3 );
        }

        // ---------- Purchase / order ----------
        if ( ! empty( $this->settings['track_purchases'] ) ) {
            add_action( 'woocommerce_payment_complete', [ $this, 'on_purchase' ], 10, 1 );
            add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ], 10, 1 );
            add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );
            add_action( 'woocommerce_order_refunded', [ $this, 'on_refund' ], 10, 2 );
        }

        // ---------- User account events ----------
        if ( ! empty( $this->settings['track_login'] ) ) {
            add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
        }
        if ( ! empty( $this->settings['track_register'] ) ) {
            add_action( 'woocommerce_created_customer', [ $this, 'on_register' ], 10, 3 );
            add_action( 'user_register', [ $this, 'on_user_register' ], 99, 1 );
        }

        // ---------- Reviews ----------
        if ( ! empty( $this->settings['track_reviews'] ) ) {
            add_action( 'comment_post', [ $this, 'on_review' ], 10, 3 );
        }

        // ---------- Abandoned cart detection (scheduled event) ----------
        if ( ! empty( $this->settings['track_cart'] ) ) {
            add_action( 'woocommerce_cart_updated', [ $this, 'save_cart_snapshot' ] );
        }

        // ---------- Real-time Data Sync Observers ----------
        if ( ! empty( $this->settings['sync_enabled'] ) ) {
            // Product save
            if ( ! empty( $this->settings['sync_products'] ) ) {
                add_action( 'save_post_product', [ $this, 'on_product_save' ], 20, 1 );
            }
            // Category changes
            if ( ! empty( $this->settings['sync_categories'] ) ) {
                add_action( 'edited_product_cat', [ $this, 'on_category_save' ], 10, 2 );
                add_action( 'created_product_cat', [ $this, 'on_category_save' ], 10, 2 );
            }
            // Customer profile updates
            if ( ! empty( $this->settings['sync_customers'] ) ) {
                add_action( 'profile_update', [ $this, 'on_customer_save' ], 10, 2 );
            }
            // Inventory / stock changes
            if ( ! empty( $this->settings['sync_inventory'] ) ) {
                add_action( 'woocommerce_product_set_stock', [ $this, 'on_stock_change' ], 10, 1 );
                add_action( 'woocommerce_variation_set_stock', [ $this, 'on_stock_change' ], 10, 1 );
            }
        }

        // ---------- Wishlist tracking (YITH / TI WooCommerce Wishlist) ----------
        if ( ! empty( $this->settings['track_wishlist'] ) ) {
            add_action( 'yith_wcwl_added_to_wishlist', [ $this, 'on_wishlist_add' ], 10, 3 );
            add_action( 'tinvwl_product_added', [ $this, 'on_wishlist_add_ti' ], 10, 2 );
        }
    }

    /* ──────────────────────────────── Cart ─────────────────────────────── */

    public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
        $product = wc_get_product( $variation_id ?: $product_id );
        if ( ! $product ) return;

        $this->send( 'add_to_cart', [
            'product_id'   => (string) $product_id,
            'variation_id' => (string) $variation_id,
            'product_name' => $product->get_name(),
            'price'        => (float) $product->get_price(),
            'quantity'     => (int) $quantity,
            'category'     => $this->get_product_category( $product ),
            'sku'          => $product->get_sku(),
            'cart_total'   => $this->get_cart_total(),
            'cart_items'   => $this->get_cart_item_count(),
        ]);
    }

    public function on_remove_from_cart( $cart_item_key, $cart ): void {
        $item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
        if ( ! $item ) return;

        $product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );

        $this->send( 'remove_from_cart', [
            'product_id'   => (string) $item['product_id'],
            'variation_id' => (string) ( $item['variation_id'] ?? 0 ),
            'product_name' => $product ? $product->get_name() : '',
            'quantity'     => (int) ( $item['quantity'] ?? 1 ),
            'price'        => (float) ( $product ? $product->get_price() : 0 ),
            'cart_total'   => $this->get_cart_total(),
            'cart_items'   => $this->get_cart_item_count(),
        ]);
    }

    public function on_cart_item_restored( $cart_item_key, $cart ): void {
        $item = $cart->cart_contents[ $cart_item_key ] ?? null;
        if ( ! $item ) return;

        $product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );

        $this->send( 'restore_cart_item', [
            'product_id'   => (string) $item['product_id'],
            'product_name' => $product ? $product->get_name() : '',
            'quantity'     => (int) ( $item['quantity'] ?? 1 ),
        ]);
    }

    public function save_cart_snapshot(): void {
        if ( ! is_user_logged_in() ) return;

        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            delete_user_meta( get_current_user_id(), '_ecom360_cart_snapshot' );
            return;
        }

        $snapshot = [
            'time'  => time(),
            'total' => (float) $cart->get_cart_contents_total(),
            'items' => [],
        ];
        foreach ( $cart->get_cart() as $item ) {
            $p = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
            $snapshot['items'][] = [
                'product_id' => (string) $item['product_id'],
                'name'       => $p ? $p->get_name() : '',
                'qty'        => (int) $item['quantity'],
                'price'      => (float) ( $p ? $p->get_price() : 0 ),
            ];
        }
        update_user_meta( get_current_user_id(), '_ecom360_cart_snapshot', $snapshot );
    }

    /* ──────────────────────────── Checkout / Purchase ───────────────────── */

    public function on_checkout( $order_id, $posted_data, $order ): void {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) return;

        $this->send( 'begin_checkout', [
            'order_id'     => (string) $order_id,
            'total'        => (float) $order->get_total(),
            'item_count'   => $order->get_item_count(),
            'payment_method' => $order->get_payment_method(),
            'currency'     => $order->get_currency(),
            'items'        => $this->order_items( $order ),
        ], $this->customer_from_order( $order ) );
    }

    public function on_purchase( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Prevent duplicate firing
        if ( $order->get_meta( '_ecom360_purchase_tracked' ) ) return;
        $order->update_meta_data( '_ecom360_purchase_tracked', '1' );
        $order->save();

        $this->send( 'purchase', [
            'order_id'       => (string) $order_id,
            'total'          => (float) $order->get_total(),
            'subtotal'       => (float) $order->get_subtotal(),
            'tax'            => (float) $order->get_total_tax(),
            'shipping'       => (float) $order->get_shipping_total(),
            'discount'       => (float) $order->get_total_discount(),
            'payment_method' => $order->get_payment_method(),
            'currency'       => $order->get_currency(),
            'item_count'     => $order->get_item_count(),
            'items'          => $this->order_items( $order ),
            'coupons'        => $order->get_coupon_codes(),
        ], $this->customer_from_order( $order ) );

        // Clear abandoned-cart snapshot
        $user_id = $order->get_user_id();
        if ( $user_id ) {
            delete_user_meta( $user_id, '_ecom360_cart_snapshot' );
        }
    }

    public function on_order_completed( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( $order->get_meta( '_ecom360_completed_tracked' ) ) return;
        $order->update_meta_data( '_ecom360_completed_tracked', '1' );
        $order->save();

        $this->send( 'order_completed', [
            'order_id' => (string) $order_id,
            'total'    => (float) $order->get_total(),
            'currency' => $order->get_currency(),
        ], $this->customer_from_order( $order ) );
    }

    public function on_order_status_changed( $order_id, $old_status, $new_status, $order ): void {
        $this->send( 'order_status_changed', [
            'order_id'   => (string) $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
        ]);
    }

    public function on_refund( $order_id, $refund_id ): void {
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order || ! $refund ) return;

        $this->send( 'refund', [
            'order_id'      => (string) $order_id,
            'refund_id'     => (string) $refund_id,
            'refund_amount' => (float) $refund->get_amount(),
            'refund_reason' => $refund->get_reason(),
            'currency'      => $order->get_currency(),
        ], $this->customer_from_order( $order ) );
    }

    /* ──────────────────────────── User Events ──────────────────────────── */

    public function on_login( $user_login, $user ): void {
        $this->send( 'login', [
            'method' => 'password',
        ], [
            'type'  => 'email',
            'value' => $user->user_email,
        ]);
    }

    public function on_register( $customer_id, $new_customer_data, $password_generated ): void {
        $this->send( 'register', [
            'source' => 'woocommerce',
        ], [
            'type'  => 'email',
            'value' => $new_customer_data['user_email'] ?? '',
        ]);
    }

    public function on_user_register( $user_id ): void {
        // Fire only if WooCommerce didn't already handle it
        if ( did_action( 'woocommerce_created_customer' ) ) return;

        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $this->send( 'register', [
            'source' => 'wordpress',
        ], [
            'type'  => 'email',
            'value' => $user->user_email,
        ]);
    }

    /* ──────────────────────────── Reviews ──────────────────────────────── */

    public function on_review( $comment_id, $comment_approved, $commentdata ): void {
        if ( get_post_type( $commentdata['comment_post_ID'] ?? 0 ) !== 'product' ) return;

        $product = wc_get_product( $commentdata['comment_post_ID'] );
        $rating  = get_comment_meta( $comment_id, 'rating', true );

        $this->send( 'review', [
            'product_id'   => (string) ( $commentdata['comment_post_ID'] ?? '' ),
            'product_name' => $product ? $product->get_name() : '',
            'rating'       => (int) $rating,
            'approved'     => $comment_approved === 1,
        ]);
    }

    /* ──────────────────────── Real-time Sync Observers ─────────────────── */

    /**
     * Product saved — queue a sync to the platform.
     */
    public function on_product_save( $post_id ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        $product = wc_get_product( $post_id );
        if ( ! $product ) return;

        $payload = [
            'entity'       => 'product',
            'external_id'  => (string) $product->get_id(),
            'data'         => [
                'name'        => $product->get_name(),
                'sku'         => $product->get_sku(),
                'price'       => (float) $product->get_price(),
                'regular_price' => (float) $product->get_regular_price(),
                'sale_price'  => (float) $product->get_sale_price(),
                'status'      => $product->get_status(),
                'stock_status' => $product->get_stock_status(),
                'stock_qty'   => $product->get_stock_quantity(),
                'categories'  => wp_get_post_terms( $post_id, 'product_cat', [ 'fields' => 'names' ] ),
                'url'         => get_permalink( $post_id ),
                'image'       => wp_get_attachment_url( $product->get_image_id() ),
            ],
        ];

        if ( class_exists( 'Ecom360_EventQueue' ) ) {
            $queue = new Ecom360_EventQueue( $this->settings );
            $queue->publish( 'sync', 'product_save', $payload, '/api/v1/sync/products' );
        }
    }

    /**
     * Category saved.
     */
    public function on_category_save( $term_id, $tt_id = 0 ): void {
        $term = get_term( $term_id, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) return;

        $payload = [
            'entity'       => 'category',
            'external_id'  => (string) $term_id,
            'data'         => [
                'name'        => $term->name,
                'slug'        => $term->slug,
                'parent_id'   => $term->parent ? (string) $term->parent : null,
                'count'       => $term->count,
            ],
        ];

        if ( class_exists( 'Ecom360_EventQueue' ) ) {
            $queue = new Ecom360_EventQueue( $this->settings );
            $queue->publish( 'sync', 'category_save', $payload, '/api/v1/sync/categories' );
        }
    }

    /**
     * Customer profile updated.
     */
    public function on_customer_save( $user_id, $old_user_data ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $payload = [
            'entity'       => 'customer',
            'external_id'  => (string) $user_id,
            'data'         => [
                'email'      => $user->user_email,
                'first_name' => get_user_meta( $user_id, 'first_name', true ),
                'last_name'  => get_user_meta( $user_id, 'last_name', true ),
                'role'       => implode( ',', $user->roles ),
            ],
        ];

        if ( class_exists( 'Ecom360_EventQueue' ) ) {
            $queue = new Ecom360_EventQueue( $this->settings );
            $queue->publish( 'sync', 'customer_save', $payload, '/api/v1/sync/customers' );
        }
    }

    /**
     * Stock level changed.
     */
    public function on_stock_change( $product ): void {
        if ( ! $product instanceof \WC_Product ) return;

        $payload = [
            'entity'       => 'inventory',
            'external_id'  => (string) $product->get_id(),
            'data'         => [
                'sku'          => $product->get_sku(),
                'stock_qty'    => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
            ],
        ];

        if ( class_exists( 'Ecom360_EventQueue' ) ) {
            $queue = new Ecom360_EventQueue( $this->settings );
            $queue->publish( 'sync', 'inventory_update', $payload, '/api/v1/sync/inventory' );
        }
    }

    /**
     * Wishlist add (YITH).
     */
    public function on_wishlist_add( $product_id, $wishlist_id, $user_id ): void {
        $product = wc_get_product( $product_id );
        $this->send( 'wishlist_add', [
            'product_id'   => (string) $product_id,
            'product_name' => $product ? $product->get_name() : '',
            'price'        => $product ? (float) $product->get_price() : 0,
        ]);
    }

    /**
     * Wishlist add (TI WooCommerce Wishlist).
     */
    public function on_wishlist_add_ti( $data, $product_data ): void {
        $product_id = $product_data['product_id'] ?? 0;
        $product = wc_get_product( $product_id );
        $this->send( 'wishlist_add', [
            'product_id'   => (string) $product_id,
            'product_name' => $product ? $product->get_name() : '',
            'price'        => $product ? (float) $product->get_price() : 0,
        ]);
    }

    /* ──────────────────────────── Helpers ──────────────────────────────── */

    /**
     * Send a single event — uses event queue if available, otherwise direct HTTP.
     */
    private function send( string $event_type, array $metadata = [], ?array $customer = null ): void {
        $payload = [
            'event_type' => $event_type,
            'url'        => home_url( $_SERVER['REQUEST_URI'] ?? '/' ),
            'page_title' => wp_get_document_title(),
            'session_id' => $this->get_session_id(),
            'metadata'   => $metadata,
            'timezone'   => wp_timezone_string(),
            'language'   => get_bloginfo( 'language' ),
        ];

        if ( $customer ) {
            $payload['customer_identifier'] = $customer;
        } elseif ( is_user_logged_in() ) {
            $payload['customer_identifier'] = [
                'type'  => 'email',
                'value' => wp_get_current_user()->user_email,
            ];
        }

        // Use event queue for non-blocking <1ms writes if available
        if ( class_exists( 'Ecom360_EventQueue' ) ) {
            $queue = new Ecom360_EventQueue( $this->settings );
            $queue->publish( 'tracking', $event_type, $payload );
            return;
        }

        // Fallback: direct HTTP (fire-and-forget)
        wp_remote_post( $this->endpoint, [
            'timeout'     => 5,
            'blocking'    => false,
            'headers'     => [
                'Content-Type'    => 'application/json',
                'X-Ecom360-Key'   => $this->api_key,
                'Accept'          => 'application/json',
            ],
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        ]);
    }

    /**
     * Return the WooCommerce session ID, or generate one.
     */
    private function get_session_id(): string {
        if ( function_exists( 'WC' ) && WC()->session ) {
            return 'wc_' . WC()->session->get_customer_id();
        }

        if ( ! empty( $_COOKIE['ecom360_sid'] ) ) {
            return sanitize_text_field( $_COOKIE['ecom360_sid'] );
        }

        return 'srv_' . wp_generate_uuid4();
    }

    private function get_cart_total(): float {
        return ( function_exists( 'WC' ) && WC()->cart )
            ? (float) WC()->cart->get_cart_contents_total()
            : 0.0;
    }

    private function get_cart_item_count(): int {
        return ( function_exists( 'WC' ) && WC()->cart )
            ? (int) WC()->cart->get_cart_contents_count()
            : 0;
    }

    private function get_product_category( $product ): string {
        $ids = $product->get_category_ids();
        if ( empty( $ids ) ) return 'Uncategorized';
        $term = get_term( $ids[0], 'product_cat' );
        return ( $term && ! is_wp_error( $term ) ) ? $term->name : 'Uncategorized';
    }

    /**
     * Extract customer identifier from a WC_Order.
     */
    private function customer_from_order( $order ): ?array {
        $email = $order->get_billing_email();
        return $email ? [ 'type' => 'email', 'value' => $email ] : null;
    }

    /**
     * Build line-item metadata for an order.
     */
    private function order_items( $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $items[] = [
                'product_id' => (string) $item->get_product_id(),
                'name'       => $item->get_name(),
                'quantity'   => (int) $item->get_quantity(),
                'price'      => (float) ( $product ? $product->get_price() : 0 ),
                'subtotal'   => (float) $item->get_subtotal(),
                'sku'        => $product ? $product->get_sku() : '',
            ];
        }
        return $items;
    }
}

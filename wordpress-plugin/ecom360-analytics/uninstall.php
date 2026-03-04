<?php
/**
 * Uninstall script – cleans up plugin data when uninstalled via WP admin.
 *
 * @package Ecom360_Analytics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove plugin options
delete_option( 'ecom360_analytics_settings' );

// Remove per-user cart snapshots
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_ecom360_cart_snapshot'" );

// Remove order meta flags
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_ecom360_purchase_tracked', '_ecom360_completed_tracked')" );

// HPOS (WC orders table) — safe to run even if HPOS is not active
if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
    $orders_table = $wpdb->prefix . 'wc_orders_meta';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$orders_table}'" ) === $orders_table ) {
        $wpdb->query( "DELETE FROM {$orders_table} WHERE meta_key IN ('_ecom360_purchase_tracked', '_ecom360_completed_tracked')" );
    }
}

<?php
/**
 * Database Installer — creates custom tables on activation.
 *
 * Tables: abandoned_carts, popup_captures, push_subscriptions, event_queue, sync_logs
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_Database {

    const DB_VERSION = '1.0.0';

    /**
     * Create all custom tables.
     */
    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ─── Abandoned Carts ───
        $table = $wpdb->prefix . 'ecom360_abandoned_carts';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(100) DEFAULT '',
            customer_id bigint(20) unsigned DEFAULT NULL,
            customer_email varchar(200) DEFAULT '',
            customer_name varchar(200) DEFAULT '',
            grand_total decimal(12,4) DEFAULT 0,
            items_count int(11) DEFAULT 0,
            items_json longtext DEFAULT NULL,
            status varchar(30) DEFAULT 'active',
            recovery_token varchar(100) DEFAULT NULL,
            coupon_code varchar(50) DEFAULT NULL,
            email_sent tinyint(1) DEFAULT 0,
            email_sent_at datetime DEFAULT NULL,
            synced tinyint(1) DEFAULT 0,
            last_activity_at datetime DEFAULT NULL,
            abandoned_at datetime DEFAULT NULL,
            recovered_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY customer_id (customer_id),
            KEY status (status),
            KEY recovery_token (recovery_token),
            KEY synced (synced),
            KEY customer_email (customer_email(100))
        ) {$charset};";
        dbDelta( $sql );

        // ─── Popup Captures ───
        $table = $wpdb->prefix . 'ecom360_popup_captures';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(100) DEFAULT '',
            customer_id bigint(20) unsigned DEFAULT NULL,
            name varchar(200) DEFAULT '',
            email varchar(200) DEFAULT '',
            phone varchar(50) DEFAULT '',
            dob date DEFAULT NULL,
            extra_data longtext DEFAULT NULL,
            page_url varchar(500) DEFAULT '',
            synced tinyint(1) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY email (email(100)),
            KEY synced (synced)
        ) {$charset};";
        dbDelta( $sql );

        // ─── Push Subscriptions ───
        $table = $wpdb->prefix . 'ecom360_push_subscriptions';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned DEFAULT NULL,
            endpoint varchar(500) DEFAULT '',
            subscription_data text DEFAULT NULL,
            token varchar(500) DEFAULT '',
            provider varchar(30) DEFAULT 'firebase',
            user_agent varchar(500) DEFAULT '',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY token (token(100))
        ) {$charset};";
        dbDelta( $sql );

        // ─── Event Queue ───
        $table = $wpdb->prefix . 'ecom360_event_queue';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(20) DEFAULT 'event',
            event_type varchar(100) DEFAULT '',
            payload longtext DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            attempts tinyint(3) DEFAULT 0,
            error_message text DEFAULT NULL,
            processed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY status_created (status, created_at)
        ) {$charset};";
        dbDelta( $sql );

        // ─── Sync Logs ───
        $table = $wpdb->prefix . 'ecom360_sync_logs';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_type varchar(50) DEFAULT '',
            status varchar(20) DEFAULT 'running',
            records_synced int(11) DEFAULT 0,
            records_failed int(11) DEFAULT 0,
            error_message text DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entity_type (entity_type),
            KEY status (status)
        ) {$charset};";
        dbDelta( $sql );

        update_option( 'ecom360_db_version', self::DB_VERSION );
    }

    /**
     * Drop all custom tables on uninstall.
     */
    public static function uninstall(): void {
        global $wpdb;
        $tables = [
            'ecom360_abandoned_carts',
            'ecom360_popup_captures',
            'ecom360_push_subscriptions',
            'ecom360_event_queue',
            'ecom360_sync_logs',
        ];
        foreach ( $tables as $t ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" );
        }
        delete_option( 'ecom360_db_version' );
    }
}

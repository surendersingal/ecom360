<?php
/**
 * Event Queue — WP equivalent of Magento's EventQueuePublisher + ProcessEventQueueCron.
 *
 * Observers insert events here (<1ms DB insert) instead of making direct HTTP calls.
 * A WP-Cron consumer processes them in batches with retry logic.
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_EventQueue {

    const MAX_BATCH        = 200;  // Max items per cron run
    const API_BATCH_SIZE   = 50;   // Max items per API call
    const MAX_ATTEMPTS     = 3;
    const CLEANUP_HOURS    = 24;

    /**
     * Publish an event to the queue (fast DB insert, <1ms).
     *
     * @param string $type 'event' or 'sync'
     * @param string $event_type e.g. 'add_to_cart', 'purchase'
     * @param array  $payload Event data
     */
    public static function publish( string $type, string $event_type, array $payload ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_event_queue';

        $wpdb->insert( $table, [
            'type'       => $type,
            'event_type' => $event_type,
            'payload'    => wp_json_encode( $payload ),
            'status'     => 'pending',
            'attempts'   => 0,
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    /**
     * Process pending events (called by WP-Cron every minute).
     */
    public static function process(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_event_queue';

        $settings = Ecom360_Settings::get();
        if ( empty( $settings['api_key'] ) || empty( $settings['endpoint'] ) ) {
            return;
        }

        $endpoint = rtrim( $settings['endpoint'], '/' );
        $api_key  = $settings['api_key'];
        $secret   = $settings['secret_key'] ?? '';

        // Claim pending items (mark as processing to prevent overlap)
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'processing' WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
            self::MAX_BATCH
        ) );

        $items = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'processing' ORDER BY id ASC LIMIT " . self::MAX_BATCH,
            ARRAY_A
        );

        if ( empty( $items ) ) {
            self::cleanup();
            return;
        }

        // Separate tracking events from sync items
        $tracking = [];
        $syncs    = [];
        foreach ( $items as $item ) {
            if ( $item['type'] === 'sync' ) {
                $syncs[] = $item;
            } else {
                $tracking[] = $item;
            }
        }

        // Process tracking events in batches
        if ( ! empty( $tracking ) ) {
            $chunks = array_chunk( $tracking, self::API_BATCH_SIZE );
            foreach ( $chunks as $chunk ) {
                $events = array_map( function( $item ) {
                    return json_decode( $item['payload'], true );
                }, $chunk );

                $response = wp_remote_post( $endpoint . '/api/v1/collect/batch', [
                    'timeout' => 15,
                    'headers' => [
                        'Content-Type'  => 'application/json',
                        'X-Ecom360-Key' => $api_key,
                    ],
                    'body' => wp_json_encode( [ 'events' => $events ] ),
                ] );

                $success = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;
                $ids = wp_list_pluck( $chunk, 'id' );

                if ( $success ) {
                    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table} SET status = 'done', processed_at = NOW() WHERE id IN ({$placeholders})",
                        ...$ids
                    ) );
                } else {
                    self::mark_failed( $ids );
                }
            }
        }

        // Process sync items individually
        foreach ( $syncs as $item ) {
            $payload = json_decode( $item['payload'], true );
            $sync_endpoint = $payload['_sync_endpoint'] ?? '';
            unset( $payload['_sync_endpoint'] );

            if ( empty( $sync_endpoint ) ) {
                $wpdb->update( $table, [
                    'status'        => 'failed',
                    'error_message' => 'Missing sync endpoint',
                    'processed_at'  => current_time( 'mysql' ),
                ], [ 'id' => $item['id'] ] );
                continue;
            }

            $response = wp_remote_post( $endpoint . $sync_endpoint, [
                'timeout' => 15,
                'headers' => [
                    'Content-Type'     => 'application/json',
                    'X-Ecom360-Key'    => $api_key,
                    'X-Ecom360-Secret' => $secret,
                ],
                'body' => wp_json_encode( $payload ),
            ] );

            $success = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;

            if ( $success ) {
                $wpdb->update( $table, [
                    'status'       => 'done',
                    'processed_at' => current_time( 'mysql' ),
                ], [ 'id' => $item['id'] ] );
            } else {
                self::mark_failed( [ $item['id'] ] );
            }
        }

        self::cleanup();
    }

    /**
     * Mark items as failed with retry logic.
     */
    private static function mark_failed( array $ids ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_event_queue';

        foreach ( $ids as $id ) {
            $item = $wpdb->get_row( $wpdb->prepare(
                "SELECT attempts FROM {$table} WHERE id = %d", $id
            ), ARRAY_A );

            $attempts = ( $item['attempts'] ?? 0 ) + 1;
            $new_status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';

            $wpdb->update( $table, [
                'status'   => $new_status,
                'attempts' => $attempts,
            ], [ 'id' => $id ] );
        }
    }

    /**
     * Clean up completed items older than 24h.
     */
    private static function cleanup(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ecom360_event_queue';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::CLEANUP_HOURS * 3600 );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE status = 'done' AND processed_at < %s",
            $cutoff
        ) );
    }
}

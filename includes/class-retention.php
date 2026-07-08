<?php
defined( 'ABSPATH' ) || exit;

/**
 * Remote backup retention: after each successful upload, prune old remote
 * files so only the configured number of backups are kept.
 *
 * Per-site safety: providers store files in a per-site subfolder
 * (see ensure_folder), so retention on one site cannot touch another's files.
 */
class RSD_RB_Retention {

    /**
     * Prune old remote backups, keeping only the last N.
     * Called automatically by the upload worker after each successful upload.
     */
    public static function prune( RB_Provider $adapter ): void {
        $keep = RSD_RB_Settings::get_retention_count();

        try {
            $files = $adapter->list_backups();
        } catch ( RuntimeException $e ) {
            RSD_RB_Logger::error( 'Retention: could not list remote backups — ' . $e->getMessage() );
            return;
        }

        if ( count( $files ) <= $keep ) {
            return;
        }

        // Sort oldest first (ascending created_at).
        usort( $files, static function ( array $a, array $b ): int {
            return $a['created_at'] <=> $b['created_at'];
        } );

        $to_delete = array_slice( $files, 0, count( $files ) - $keep );

        foreach ( $to_delete as $file ) {
            try {
                if ( $adapter->delete_remote( $file['id'] ) ) {
                    RSD_RB_Logger::info( 'Retention: deleted old remote backup "' . $file['name'] . '" (' . $file['id'] . ').' );
                    self::update_location_after_remote_delete( $file['name'], $file['id'] );
                }
            } catch ( RuntimeException $e ) {
                RSD_RB_Logger::error( 'Retention: failed to delete "' . $file['name'] . '" — ' . $e->getMessage() );
            }
        }
    }

    private static function update_location_after_remote_delete( string $filename, string $remote_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, filepath FROM `{$table}` WHERE filename = %s OR remote_id = %s LIMIT 1",
                $filename,
                $remote_id
            ),
            ARRAY_A
        );

        if ( ! $job ) {
            return;
        }

        $local_exists = ! empty( $job['filepath'] ) && file_exists( $job['filepath'] );
        RSD_RB_Queue::update_location(
            (int) $job['id'],
            $local_exists ? RSD_RB_Queue::LOCATION_LOCAL : RSD_RB_Queue::LOCATION_NONE
        );
    }
}

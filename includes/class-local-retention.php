<?php
defined( 'ABSPATH' ) || exit;

/**
 * Local backup retention: after each successful upload, prune old local
 * copies so only the configured number of most-recent, confirmed-uploaded
 * backups remain on this server. Mirrors RSD_RB_Retention (remote side).
 *
 * Only ever considers jobs already at location = 'both' — i.e. a verified
 * remote copy already exists — so a local file is never deleted before it
 * is safely backed up.
 */
class RSD_RB_Local_Retention {

    /**
     * Prune old local backups, keeping only the last N (by detection time).
     * Called automatically by the upload worker after each successful upload.
     */
    public static function prune(): void {
        $keep = RSD_RB_Settings::get_local_retention_count();

        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, filename, filepath, manifest_id FROM `{$table}` WHERE location = %s ORDER BY created_at DESC",
                RSD_RB_Queue::LOCATION_BOTH
            ),
            ARRAY_A
        );

        if ( ! $jobs || count( $jobs ) <= $keep ) {
            return;
        }

        $to_delete = array_slice( $jobs, $keep );

        foreach ( $to_delete as $job ) {
            if ( empty( $job['filepath'] ) || ! file_exists( $job['filepath'] ) ) {
                continue;
            }

            if ( @unlink( $job['filepath'] ) ) {
                RSD_RB_Queue::update_location( (int) $job['id'], RSD_RB_Queue::LOCATION_REMOTE );
                RSD_RB_Manifest::mark_backup_cleaned( (int) ( $job['manifest_id'] ?? 0 ) );
                RSD_RB_Logger::info( 'Local retention: deleted local backup "' . $job['filename'] . '" (already confirmed uploaded).' );
            } else {
                RSD_RB_Logger::warning( 'Local retention: failed to delete local backup "' . $job['filename'] . '".' );
            }
        }
    }
}

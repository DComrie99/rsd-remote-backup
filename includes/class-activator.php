<?php
defined( 'ABSPATH' ) || exit;

class RSD_RB_Activator {

    public static function activate(): void {
        self::create_table();
        self::create_manifest_table();
        self::schedule_scan();
        // Generate a REST API key if one doesn't exist yet.
        // Stored as a SHA-256 hash; raw key placed in a 1-hour transient for admin display.
        if ( '' === get_option( 'rsd_rb_api_key', '' ) ) {
            RSD_RB_Rest_Api::generate_and_store_key();
        }

        // Register the OneDrive callback rewrite rule and flush so the endpoint
        // resolves immediately on a fresh install without a manual permalink save.
        add_rewrite_rule( '^rsd-rb-onedrive/?$', 'index.php?rsd_rb_onedrive_callback=1', 'top' );
        flush_rewrite_rules();
        RSD_RB_Logger::info( 'Plugin activated.' );
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'rsd_rb_scan' );
        RSD_RB_Logger::info( 'Plugin deactivated; scan schedule cleared.' );
    }

    /**
     * Run on every plugins_loaded when the stored DB schema version doesn't
     * match the current plugin version.  dbDelta() safely adds new columns and
     * indexes without touching existing data, so this is safe to call on every
     * update (zip upload without deactivate/reactivate).
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'rsd_rb_db_version' ) === RSD_RB_VERSION ) {
            return;
        }
        self::create_table(); // dbDelta handles ADD COLUMN idempotently.
        self::create_manifest_table();
        self::schedule_scan();
    }

    // -----------------------------------------------------------------

    private static function create_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . RSD_RB_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            filename    VARCHAR(255)    NOT NULL,
            filepath    TEXT            NOT NULL,
            filesize    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            provider    VARCHAR(32)     NOT NULL,
            status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
            location    VARCHAR(10)     NOT NULL DEFAULT 'local',
            session_url TEXT            NULL,
            upload_path TEXT            NULL,
            bytes_sent  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            remote_id   VARCHAR(255)    NULL,
            attempts    INT UNSIGNED    NOT NULL DEFAULT 0,
            last_error  TEXT            NULL,
            compression_meta TEXT       NULL,
            manifest_id BIGINT UNSIGNED NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status      (status),
            KEY idx_location    (location),
            KEY idx_filename    (filename(191)),
            KEY idx_manifest_id (manifest_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'rsd_rb_db_version', RSD_RB_VERSION );
    }

    /**
     * Durable, permanent-retention record of each backup's full lifecycle —
     * separate from rsd_rb_jobs (which is the live upload queue: atomic
     * claim/retry/resumable byte offsets). This table is the source of truth
     * a future restore phase will query, so its columns are not pruned or
     * repurposed — see backup-compression-pipeline.md.
     */
    private static function create_manifest_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . RSD_RB_MANIFEST_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            original_filename     VARCHAR(255)    NOT NULL,
            provider              VARCHAR(32)     NOT NULL,
            local_backup_path     TEXT            NULL,
            local_zip_path        TEXT            NULL,
            compression_enabled   TINYINT(1)      NOT NULL DEFAULT 0,
            compression_method    VARCHAR(32)     NULL,
            original_size_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            compressed_size_bytes BIGINT UNSIGNED NULL,
            compression_ratio     DECIMAL(6,4)    NULL,
            compression_time_ms   INT UNSIGNED    NULL,
            original_checksum     VARCHAR(64)     NOT NULL,
            compressed_checksum   VARCHAR(64)     NULL,
            remote_path           VARCHAR(255)    NULL,
            remote_is_compressed  TINYINT(1)      NOT NULL DEFAULT 0,
            upload_status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
            upload_attempts       INT UNSIGNED    NOT NULL DEFAULT 0,
            local_zip_deleted     TINYINT(1)      NOT NULL DEFAULT 0,
            local_backup_deleted  TINYINT(1)      NOT NULL DEFAULT 0,
            status                VARCHAR(20)     NOT NULL DEFAULT 'detected',
            download_status       VARCHAR(20)     NOT NULL DEFAULT 'not_started',
            download_attempts     INT UNSIGNED    NOT NULL DEFAULT 0,
            staged_path           TEXT            NULL,
            staged_at             DATETIME        NULL,
            created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status            (status),
            KEY idx_upload_status     (upload_status),
            KEY idx_original_filename (original_filename(191)),
            KEY idx_download_status   (download_status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private static function schedule_scan(): void {
        $frequency = get_option( 'rsd_rb_scan_frequency', 'rsd_rb_every_15_minutes' );

        if ( wp_next_scheduled( 'rsd_rb_scan' ) ) {
            return;
        }

        // wp_schedule_event() silently returns false/WP_Error if $frequency
        // isn't a currently-registered cron schedule (e.g. if this ever runs
        // before RSD_RB_Plugin::add_cron_schedules() has registered the
        // custom 'rsd_rb_every_15_minutes' interval for this request) — with
        // no exception thrown and nothing logged by WP core itself, so a
        // failure here can silently leave the entire automatic scan/upload
        // pipeline dead. Check the return value explicitly so this class of
        // failure is never silent again.
        $result = wp_schedule_event( time(), $frequency, 'rsd_rb_scan' );

        if ( is_wp_error( $result ) || false === $result ) {
            RSD_RB_Logger::error( sprintf(
                'Activator: failed to schedule rsd_rb_scan (frequency="%s") — %s',
                $frequency,
                is_wp_error( $result ) ? $result->get_error_message() : 'wp_schedule_event() returned false (unrecognized interval, or another plugin blocked it via the "schedule_event" filter).'
            ) );
        } else {
            RSD_RB_Logger::info( 'Activator: scheduled rsd_rb_scan (frequency="' . $frequency . '").' );
        }
    }
}

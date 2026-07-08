<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers all plugin settings via the Settings API and handles sanitisation.
 */
class RSD_RB_Settings {

    const GROUP   = 'rsd_rb_settings';
    const SECTION = 'rsd_rb_main';

    /**
     * Backup source definitions keyed by slug.
     * Each entry: [ 'label' => string, 'dir' => string (relative to WP_CONTENT_DIR), 'ext' => string (no dot) ]
     */
    const BACKUP_SOURCES = array(
        'ai1wm' => array(
            'label' => 'All-In-One WP Migration (.wpress)',
            'dir'   => 'ai1wm-backups',
            'ext'   => 'wpress',
        ),
    );

    public static function register(): void {
        // --- Option registrations ---
        register_setting( self::GROUP, 'rsd_rb_backup_source',    array( 'sanitize_callback' => array( __CLASS__, 'sanitize_backup_source' ) ) );
        register_setting( self::GROUP, 'rsd_rb_provider',         array( 'sanitize_callback' => array( __CLASS__, 'sanitize_provider' ) ) );
        register_setting( self::GROUP, 'rsd_rb_scan_frequency',   array( 'sanitize_callback' => array( __CLASS__, 'sanitize_frequency' ) ) );
        register_setting( self::GROUP, 'rsd_rb_folder_name',      array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( self::GROUP, 'rsd_rb_retention_count',  array( 'sanitize_callback' => array( __CLASS__, 'sanitize_positive_int' ) ) );
        register_setting( self::GROUP, 'rsd_rb_delete_local',     array( 'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ) ) );
        register_setting( self::GROUP, 'rsd_rb_time_budget',      array( 'sanitize_callback' => array( __CLASS__, 'sanitize_time_budget' ) ) );
        register_setting( self::GROUP, 'rsd_rb_compress_enabled', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ) ) );
        register_setting( self::GROUP, 'rsd_rb_max_concurrent_uploads', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_max_concurrent_uploads' ) ) );

        register_setting( self::GROUP, 'rsd_rb_google_client_id',     array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( self::GROUP, 'rsd_rb_google_client_secret', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_google_client_secret' ) ) );
        register_setting( self::GROUP, 'rsd_rb_od_client_id',         array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( self::GROUP, 'rsd_rb_od_client_secret',     array( 'sanitize_callback' => array( __CLASS__, 'sanitize_od_client_secret' ) ) );
        register_setting( self::GROUP, 'rsd_rb_od_account_type',      array( 'sanitize_callback' => array( __CLASS__, 'sanitize_od_account_type' ) ) );

        register_setting( self::GROUP, 'rsd_rb_license_key', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_license_key' ) ) );
    }

    // -----------------------------------------------------------------
    // Sanitisers

    public static function sanitize_backup_source( $value ): string {
        return array_key_exists( $value, self::BACKUP_SOURCES ) ? $value : 'ai1wm';
    }

    public static function sanitize_provider( $value ): string {
        return in_array( $value, array( 'google-drive', 'onedrive' ), true ) ? $value : 'google-drive';
    }

    public static function sanitize_frequency( $value ): string {
        $allowed = array_keys( wp_get_schedules() );
        return in_array( $value, $allowed, true ) ? $value : 'rsd_rb_every_15_minutes';
    }

    public static function sanitize_positive_int( $value ): int {
        $int = (int) $value;
        return $int > 0 ? $int : 7;
    }

    public static function sanitize_time_budget( $value ): int {
        $int = (int) $value;
        return $int >= 10 ? $int : 60;
    }

    public static function sanitize_max_concurrent_uploads( $value ): int {
        $int = (int) $value;
        return $int >= 1 && $int <= 10 ? $int : 2;
    }

    public static function sanitize_od_account_type( $value ): string {
        return in_array( $value, array( 'consumers', 'organizations', 'common' ), true ) ? $value : 'consumers';
    }

    public static function sanitize_bool( $value ): bool {
        return (bool) $value;
    }

    public static function sanitize_google_client_secret( $value ): string {
        $value = sanitize_text_field( $value );
        if ( '' === $value ) {
            // Blank submission = keep existing stored value unchanged.
            return (string) get_option( 'rsd_rb_google_client_secret', '' );
        }
        return RSD_RB_Crypto::encrypt( $value );
    }

    public static function sanitize_od_client_secret( $value ): string {
        $value = sanitize_text_field( $value );
        if ( '' === $value ) {
            return (string) get_option( 'rsd_rb_od_client_secret', '' );
        }
        return RSD_RB_Crypto::encrypt( $value );
    }

    public static function sanitize_license_key( $value ): string {
        // Strip any whitespace/line-breaks a copy-paste might introduce —
        // the base64(payload).base64(signature) format never contains any.
        return sanitize_text_field( preg_replace( '/\s+/', '', (string) $value ) );
    }

    // -----------------------------------------------------------------
    // Accessors

    public static function get_backup_source(): string {
        return (string) get_option( 'rsd_rb_backup_source', 'ai1wm' );
    }

    /**
     * Returns the resolved config for the active backup source:
     *   [ 'label', 'dir' (absolute path), 'ext' (no dot) ]
     */
    public static function get_backup_source_config(): array {
        $key    = self::get_backup_source();
        $source = self::BACKUP_SOURCES[ $key ] ?? self::BACKUP_SOURCES['ai1wm'];
        return array(
            'label' => $source['label'],
            'dir'   => trailingslashit( WP_CONTENT_DIR ) . $source['dir'],
            'ext'   => $source['ext'],
        );
    }

    public static function get_provider(): string {
        return get_option( 'rsd_rb_provider', 'google-drive' );
    }

    public static function get_folder_name(): string {
        $default = 'WP Backups / ' . wp_parse_url( home_url(), PHP_URL_HOST );
        return get_option( 'rsd_rb_folder_name', $default );
    }

    public static function get_retention_count(): int {
        return (int) get_option( 'rsd_rb_retention_count', 7 );
    }

    public static function get_time_budget(): int {
        return (int) get_option( 'rsd_rb_time_budget', 60 );
    }

    /** Max number of upload jobs allowed to be actively transferring at once, across all providers. */
    public static function get_max_concurrent_uploads(): int {
        return (int) get_option( 'rsd_rb_max_concurrent_uploads', 2 );
    }

    public static function get_delete_local(): bool {
        return (bool) get_option( 'rsd_rb_delete_local', false );
    }

    /**
     * Whether to compress the backup file before upload. Defaults on since it
     * only ever reduces transfer time; the admin-facing benchmark is what lets
     * a site owner judge whether it's worth the CPU cost on their specific host.
     */
    public static function get_compress_enabled(): bool {
        return (bool) get_option( 'rsd_rb_compress_enabled', true );
    }

    public static function get_google_client_id(): string {
        return (string) get_option( 'rsd_rb_google_client_id', '' );
    }

    public static function get_google_client_secret(): string {
        $stored = (string) get_option( 'rsd_rb_google_client_secret', '' );
        if ( '' === $stored ) {
            return '';
        }
        try {
            return RSD_RB_Crypto::decrypt( $stored );
        } catch ( Exception $e ) {
            // Stored value is legacy plaintext — return as-is until user re-saves.
            return $stored;
        }
    }

    public static function get_od_client_id(): string {
        return (string) get_option( 'rsd_rb_od_client_id', '' );
    }

    public static function get_od_client_secret(): string {
        $stored = (string) get_option( 'rsd_rb_od_client_secret', '' );
        if ( '' === $stored ) {
            return '';
        }
        try {
            return RSD_RB_Crypto::decrypt( $stored );
        } catch ( Exception $e ) {
            // Stored value is legacy plaintext — return as-is until user re-saves.
            return $stored;
        }
    }

    public static function get_license_key(): string {
        return (string) get_option( 'rsd_rb_license_key', '' );
    }
}

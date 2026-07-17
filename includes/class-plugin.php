<?php
defined( 'ABSPATH' ) || exit;

/**
 * Central singleton: wires up all hooks and coordinates sub-components.
 */
class RSD_RB_Plugin {

    private static ?RSD_RB_Plugin $instance = null;

    /** @var \YahnisElsts\PluginUpdateChecker\v5\Vcs\PluginUpdateChecker|null Set in register_update_checker(); null if !is_admin(). */
    private $update_checker = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_hooks();
        $this->register_update_checker();
    }

    private function register_hooks(): void {
        // Register custom cron interval FIRST — maybe_upgrade() below can call
        // wp_schedule_event() with this interval (via schedule_scan(), on a
        // version bump), and wp_schedule_event() validates the recurrence
        // against the currently-registered cron_schedules at the moment it's
        // called. Registering the filter after that call meant the interval
        // was never recognized yet, so wp_schedule_event() silently failed —
        // found via a live site where rsd_rb_scan ended up permanently
        // unscheduled (wp_next_scheduled() found nothing) despite WP-Cron and
        // Action Scheduler both being otherwise healthy; maybe_upgrade() only
        // retries scheduling on the next version bump, so a single failed
        // attempt here could leave the whole automatic scan/upload pipeline
        // silently dead until the next release.
        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );

        // Run DB migrations if the schema version is behind the plugin version.
        RSD_RB_Activator::maybe_upgrade();

        // REST API
        add_action( 'rest_api_init', array( 'RSD_RB_Rest_Api', 'register_routes' ) );

        // OneDrive clean callback endpoint (path-based, no query string — required by Entra).
        add_action( 'init',              array( $this, 'register_onedrive_rewrite' ) );
        add_filter( 'query_vars',        array( $this, 'add_onedrive_query_var' ) );
        add_action( 'template_redirect', array( $this, 'handle_onedrive_callback' ) );

        // Settings & admin UI
        add_action( 'admin_init',            array( 'RSD_RB_Settings',   'register' ) );
        add_action( 'admin_menu',            array( 'RSD_RB_Admin_Page', 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( 'RSD_RB_Admin_Page', 'enqueue_assets' ) );
        add_action( 'admin_head',            array( 'RSD_RB_Admin_Page', 'menu_icon_style' ) );

        // Maintenance screen (own top-level menu — separate from RSD Backup)
        add_action( 'admin_menu',            array( 'RSD_RB_Maintenance_Page', 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( 'RSD_RB_Maintenance_Page', 'enqueue_assets' ) );

        // OAuth flow
        add_action( 'admin_init', array( $this, 'handle_oauth_connect' ) );
        add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );  // Google Drive only
        add_action( 'admin_init', array( $this, 'handle_oauth_disconnect' ) );

        // Admin actions (manual triggers, log clear)
        add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );

        // Maintenance screen actions (e.g. delete all comments)
        add_action( 'admin_init', array( $this, 'handle_maintenance_actions' ) );

        // Disk Usage tab's live progress poll (logged-in admin only)
        add_action( 'wp_ajax_rsd_rb_disk_scan_tick', array( $this, 'ajax_disk_scan_tick' ) );

        // Disk Usage tab's in-place tree navigation (logged-in admin only)
        add_action( 'wp_ajax_rsd_rb_disk_scan_view', array( $this, 'ajax_disk_scan_view' ) );

        // Disk Usage tab's per-file delete (logged-in admin only)
        add_action( 'wp_ajax_rsd_rb_disk_file_delete', array( $this, 'ajax_disk_file_delete' ) );

        // Background scan (WP-Cron)
        add_action( 'rsd_rb_scan', array( $this, 'run_scan' ) );

        // Upload worker — Action Scheduler async hook
        add_action( RSD_RB_Upload_Worker::AS_HOOK, array( $this, 'run_upload_worker' ) );

        // Upload worker — WP-Cron fallback hook
        add_action( RSD_RB_Upload_Worker::CRON_HOOK, array( $this, 'run_upload_worker' ) );

        // Download worker (download & stage for restore) — AS hook + WP-Cron fallback
        add_action( RSD_RB_Download_Worker::AS_HOOK, array( $this, 'run_download_worker' ) );
        add_action( RSD_RB_Download_Worker::CRON_HOOK, array( $this, 'run_download_worker' ) );

        // WP-Cron health diagnostic — see record_cron_heartbeat() docblock.
        add_action( 'shutdown', array( $this, 'record_cron_heartbeat' ) );
    }

    /**
     * Records that a WP-Cron request reached PHP shutdown, and surfaces any
     * fatal error that happened during it. Added to diagnose sites where
     * wp-cron.php returns a normal 200 response (so it's clearly reachable)
     * but scheduled jobs never advance — a 200 only proves the HTTP request
     * completed, not that WordPress's cron batch ran every due hook to
     * completion. If some OTHER plugin's cron hook fatals partway through the
     * same batch, everything scheduled after it in that request — including
     * this plugin's own upload continuation — silently never runs, and that
     * fatal would normally only show up in the server's PHP error log, which
     * isn't always accessible. The 'shutdown' action still fires even after a
     * fatal error, so this surfaces it directly in our own admin log instead.
     *
     * Deliberately scoped to DOING_CRON only — this isn't meant to catch
     * fatals on normal page loads, just to answer "is WP-Cron actually
     * completing its batch on this site."
     */
    public function record_cron_heartbeat(): void {
        if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
            return;
        }

        update_option( 'rsd_rb_last_cron_heartbeat', time(), false );

        $error = error_get_last();
        $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
        if ( $error && in_array( $error['type'], $fatal_types, true ) ) {
            RSD_RB_Logger::error( sprintf(
                'WP-Cron: fatal error during this cron run — %s in %s:%d. This can silently stop OTHER due cron jobs (including upload continuations) from running for the rest of that batch, even though wp-cron.php itself returns a normal response.',
                $error['message'],
                basename( $error['file'] ),
                $error['line']
            ) );
        }
    }

    // -------------------------------------------------------------------------
    // Self-hosted update checker (public GitHub releases — see admin/README)

    private function register_update_checker(): void {
        if ( ! is_admin() ) {
            return;
        }

        require_once RSD_RB_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

        $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/DComrie99/rsd-remote-backup/',
            RSD_RB_FILE,
            RSD_RB_SLUG
        );
        // Use an uploaded release zip asset, not GitHub's auto-generated source archive
        // (which lacks the required top-level "rsd-remote-backup/" folder name).
        $update_checker->getVcsApi()->enableReleaseAssets();

        $this->update_checker = $update_checker;
    }

    /** Null if the current request isn't is_admin() — register_update_checker() only builds it there. */
    public function get_update_checker() {
        return $this->update_checker;
    }

    // -------------------------------------------------------------------------
    // OneDrive clean callback endpoint

    public function register_onedrive_rewrite(): void {
        add_rewrite_rule( '^rsd-rb-onedrive/?$', 'index.php?rsd_rb_onedrive_callback=1', 'top' );
    }

    public function add_onedrive_query_var( array $vars ): array {
        $vars[] = 'rsd_rb_onedrive_callback';
        return $vars;
    }

    /**
     * Handles the OneDrive OAuth callback at https://SITE/rsd-rb-onedrive/
     * (path-based URL with no query string, required by Microsoft Entra for personal accounts).
     * Microsoft appends ?code=…&state=… which are fine as returned parameters.
     */
    public function handle_onedrive_callback(): void {
        if ( ! get_query_var( 'rsd_rb_onedrive_callback' ) ) {
            return;
        }

        // The callback must only be processed by a logged-in admin.
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to access this page.', 'rsd-remote-backup' ),
                403
            );
        }

        $redirect = admin_url( 'admin.php?page=rsd-remote-backup' );

        // User denied access.
        if ( isset( $_GET['error'] ) ) {
            $error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
            RSD_RB_Logger::warning( 'OneDrive OAuth callback: access denied by user (' . $error . ').' );
            wp_redirect( add_query_arg( 'rb_notice', 'oauth_denied', $redirect ) );
            exit;
        }

        $code  = isset( $_GET['code'] )  ? sanitize_text_field( wp_unslash( $_GET['code'] ) )  : '';
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

        try {
            RSD_RB_OAuth::validate_state( 'onedrive', $state );
            $provider = new RSD_RB_Provider_OneDrive();
            $provider->exchange_code( $code );
            wp_redirect( add_query_arg( 'rb_notice', 'oauth_connected', $redirect ) );
            exit;
        } catch ( RuntimeException $e ) {
            RSD_RB_Logger::error( 'OneDrive OAuth callback error: ' . $e->getMessage() );
            wp_redirect( add_query_arg( 'rb_notice', 'oauth_error', $redirect ) );
            exit;
        }
    }

    // -------------------------------------------------------------------------

    public static function add_cron_schedules( array $schedules ): array {
        $schedules['rsd_rb_every_15_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 minutes', 'rsd-remote-backup' ),
        );
        return $schedules;
    }

    // -------------------------------------------------------------------------
    // OAuth — connect

    public function handle_oauth_connect(): void {
        if ( ! isset( $_GET['rb_oauth'] ) || 'connect' !== $_GET['rb_oauth'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $provider_key = isset( $_GET['provider'] ) ? sanitize_key( $_GET['provider'] ) : '';
        $nonce        = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'rsd_rb_connect_' . $provider_key ) ) {
            wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
        }

        $provider = $this->get_provider( $provider_key );
        if ( ! $provider ) {
            wp_die( esc_html__( 'Unknown provider.', 'rsd-remote-backup' ) );
        }

        // OneDrive requires pretty permalinks: the registered redirect URI must be
        // path-based (no query string). Entra rejects query-string URIs for personal
        // accounts. Block the connect flow early and show a clear notice.
        if ( 'onedrive' === $provider_key && '' === get_option( 'permalink_structure' ) ) {
            wp_redirect( add_query_arg( 'rb_notice', 'od_no_permalinks', $redirect ) );
            exit;
        }

        $state    = RSD_RB_OAuth::create_state( $provider_key );
        $auth_url = $provider->get_authorize_url( $state );

        wp_redirect( $auth_url );
        exit;
    }

    // -------------------------------------------------------------------------
    // OAuth — callback

    public function handle_oauth_callback(): void {
        if ( ! isset( $_GET['rb_oauth'] ) || 'callback' !== $_GET['rb_oauth'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $provider_key = isset( $_GET['provider'] ) ? sanitize_key( $_GET['provider'] ) : '';
        $code         = isset( $_GET['code'] )     ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        $state        = isset( $_GET['state'] )    ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $redirect     = admin_url( 'admin.php?page=rsd-remote-backup' );

        if ( isset( $_GET['error'] ) ) {
            $error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
            RSD_RB_Logger::warning( 'OAuth callback: user denied access for ' . $provider_key . ' (' . $error . ').' );
            wp_redirect( add_query_arg( 'rb_notice', 'oauth_denied', $redirect ) );
            exit;
        }

        try {
            RSD_RB_OAuth::validate_state( $provider_key, $state );
            $provider = $this->get_provider( $provider_key );
            if ( ! $provider ) {
                throw new RuntimeException( 'Unknown provider: ' . $provider_key );
            }
            $provider->exchange_code( $code );
            wp_redirect( add_query_arg( 'rb_notice', 'oauth_connected', $redirect ) );
            exit;
        } catch ( RuntimeException $e ) {
            RSD_RB_Logger::error( 'OAuth callback error: ' . $e->getMessage() );
            wp_redirect( add_query_arg( 'rb_notice', 'oauth_error', $redirect ) );
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // OAuth — disconnect

    public function handle_oauth_disconnect(): void {
        if ( ! isset( $_GET['rb_oauth'] ) || 'disconnect' !== $_GET['rb_oauth'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $provider_key = isset( $_GET['provider'] ) ? sanitize_key( $_GET['provider'] ) : '';
        $nonce        = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'rsd_rb_disconnect_' . $provider_key ) ) {
            wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
        }

        $provider = $this->get_provider( $provider_key );
        if ( $provider ) {
            $provider->disconnect();
        }

        wp_redirect( add_query_arg( 'rb_notice', 'oauth_disconnected', admin_url( 'admin.php?page=rsd-remote-backup' ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Admin actions (manual triggers + log)

    public function handle_admin_actions(): void {
        if ( ! isset( $_GET['rb_action'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action   = sanitize_key( $_GET['rb_action'] );
        $nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        $redirect = admin_url( 'admin.php?page=rsd-remote-backup' );

        switch ( $action ) {

            case 'clear_log':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_clear_log' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                RSD_RB_Logger::clear();
                wp_redirect( add_query_arg( 'rb_notice', 'log_cleared', $redirect ) );
                exit;

            case 'download_log':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_download_log' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                // Full stored log (not the display-truncated view), oldest first —
                // for sites where the admin has no cPanel/FTP/DB access and this is
                // the only way to get the raw log off the server.
                $lines = RSD_RB_Logger::get_lines_chronological();
                nocache_headers();
                header( 'Content-Type: text/plain; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename="rsd-remote-backup-log-' . gmdate( 'Y-m-d-His' ) . '.txt"' );
                header( 'Content-Length: ' . strlen( implode( "\n", $lines ) . "\n" ) );
                echo implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                exit;

            case 'upload_now':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_upload_now' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                // Run a scan first to pick up any unqueued files, then schedule all pending jobs.
                RSD_RB_Backup_Scanner::run();
                $provider = RSD_RB_Settings::get_provider();
                $count    = RSD_RB_Upload_Worker::schedule_all_pending( $provider );
                RSD_RB_Logger::info( 'Manual upload trigger: ' . $count . ' job(s) scheduled.' );
                wp_redirect( add_query_arg( 'rb_notice', 'upload_triggered', $redirect ) );
                exit;

            case 'scan_files':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_scan_files' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                // Detect + record new/changed backups (manifest + pending job rows) without
                // scheduling any upload — deliberately does not call schedule_all_pending(),
                // unlike 'upload_now', for an admin who wants visibility into what's on disk
                // without committing to starting a transfer yet. Runs before the existing raw
                // directory listing below, same as the WP-Cron scan tick's own ordering.
                $queued = RSD_RB_Backup_Scanner::run();
                update_option( 'rsd_rb_last_scan', wp_date( 'c' ), false );
                $found  = RSD_RB_Backup_Scanner::log_all_files();
                wp_redirect( add_query_arg( array( 'rb_notice' => 'files_scanned_' . $found . '_queued_' . $queued, 'rb_tab' => 'status' ), $redirect ) );
                exit;

            case 'test_connection':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_test_connection' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                $notice = $this->test_connection();
                wp_redirect( add_query_arg( 'rb_notice', $notice, $redirect ) );
                exit;

            case 'run_transient_diag':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_run_transient_diag' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                // Writes now, deliberately read back on the NEXT request (after this
                // redirect) rather than in the same PHP process — that's what actually
                // mirrors the OAuth-state and API-key-reveal failure mode we're
                // investigating: both write a transient then redirect and expect it to
                // still be there. A same-request round trip would pass even on a site
                // where the object cache doesn't persist across requests.
                $probe_token = wp_generate_password( 12, false );
                set_transient( 'rsd_rb_diag_probe_transient', $probe_token, 5 * MINUTE_IN_SECONDS );
                update_option( 'rsd_rb_diag_probe_option', $probe_token, false );
                // Raw object-cache API, bypassing the Transients wrapper entirely — separates
                // "the cache backend itself doesn't persist" from "something about how
                // Transients specifically uses it doesn't persist."
                wp_cache_set( 'rsd_rb_diag_probe', $probe_token, 'rsd-rb-diag', 5 * MINUTE_IN_SECONDS );
                RSD_RB_Logger::info( 'Transient diagnostic probe set: ' . $probe_token );
                wp_redirect( add_query_arg( array( 'rb_notice' => 'transient_diag_set', 'rb_tab' => 'status' ), $redirect ) );
                exit;

            case 'force_update_check':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_force_update_check' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                // Bypasses PUC's own 12-hour (admin_init) / 1-hour (Plugins page) throttle —
                // its StateStore persists via update_site_option(), a real DB write, so this
                // is independent of the transient-persistence question above; it exists to
                // rule out "just hasn't rechecked yet" before suspecting anything deeper.
                $checker = $this->get_update_checker();
                $errors  = array();
                if ( $checker ) {
                    $checker->checkForUpdates();
                    // PUC's collected API errors (e.g. the outbound request to api.github.com
                    // itself failing) only live on this in-memory checker instance — they're
                    // gone by the next page load unless we persist them here. Stored as a
                    // plain option (confirmed reliable on this site by the transient-diagnostic
                    // option control above), not a transient.
                    foreach ( $checker->getLastRequestApiErrors() as $entry ) {
                        $error = $entry['error'];
                        $msg   = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;
                        if ( ! empty( $entry['url'] ) ) {
                            $msg .= ' (' . $entry['url'] . ')';
                        }
                        $errors[] = $msg;
                    }
                }
                update_option( 'rsd_rb_diag_update_check_errors', $errors, false );
                RSD_RB_Logger::info( 'Manual update check forced.' . ( $errors ? ' Errors: ' . implode( ' | ', $errors ) : ' No API errors reported.' ) );
                wp_redirect( add_query_arg( array( 'rb_notice' => 'update_check_forced', 'rb_tab' => 'status' ), $redirect ) );
                exit;

            case 'regenerate_api_key':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_regenerate_api_key' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                RSD_RB_Rest_Api::generate_and_store_key();
                RSD_RB_Logger::info( 'REST API key regenerated.' );
                wp_redirect( add_query_arg( 'rb_notice', 'api_key_regenerated', $redirect . '#tab-license' ) );
                exit;

            case 'resync':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_resync' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                $result = RSD_RB_Rest_Api::run_resync();
                $notice = 'resync_' . $result['updated'] . '_' . $result['created'] . '_' . $result['orphaned'] . '_' . $result['duplicates_removed'] . '_' . $result['backfilled'];
                wp_redirect( add_query_arg( array( 'rb_notice' => $notice, 'rb_tab' => 'status' ), $redirect ) );
                exit;

            case 'reset_stalled':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_reset_stalled' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                $reset    = RSD_RB_Queue::reset_stalled();
                $provider = RSD_RB_Settings::get_provider();
                RSD_RB_Upload_Worker::schedule_all_pending( $provider );
                RSD_RB_Logger::info( 'Manual stall reset: ' . $reset . ' job(s) returned to pending and rescheduled.' );
                wp_redirect( add_query_arg( array( 'rb_notice' => 'stall_reset_' . $reset, 'rb_tab' => 'status' ), $redirect ) );
                exit;

            case 'cancel_pending':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_cancel_pending' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                $cancelled = RSD_RB_Queue::cancel_all_pending();
                RSD_RB_Logger::info( 'Manual cancel: ' . $cancelled . ' pending job(s) cancelled.' );
                wp_redirect( add_query_arg( array( 'rb_notice' => 'pending_cancelled_' . $cancelled, 'rb_tab' => 'status' ), $redirect ) );
                exit;

            case 'retry_failed':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_retry_failed' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                $retried  = RSD_RB_Queue::retry_failed();
                $provider = RSD_RB_Settings::get_provider();
                RSD_RB_Upload_Worker::schedule_all_pending( $provider );
                RSD_RB_Logger::info( 'Manual retry: ' . $retried . ' failed job(s) requeued and rescheduled.' );
                wp_redirect( add_query_arg( array( 'rb_notice' => 'retry_failed_' . $retried, 'rb_tab' => 'status' ), $redirect ) );
                exit;

            case 'run_compression_benchmark':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_run_compression_benchmark' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                $result = RSD_RB_Compressor::benchmark();
                $notice = isset( $result['error'] ) ? 'compression_benchmark_failed' : 'compression_benchmark_done';
                wp_redirect( add_query_arg( array( 'rb_notice' => $notice, 'rb_tab' => 'status' ), $redirect ) );
                exit;

            case 'download_backup':
                $manifest_id  = isset( $_GET['manifest_id'] ) ? (int) $_GET['manifest_id'] : 0;
                $backups_page = admin_url( 'admin.php?page=' . RSD_RB_Admin_Page::BACKUPS_PAGE_SLUG );

                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_download_' . $manifest_id ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }

                $manifest = $manifest_id > 0 ? RSD_RB_Manifest::get( $manifest_id ) : null;
                if ( ! $manifest || RSD_RB_Manifest::UPLOAD_UPLOADED !== $manifest['upload_status'] ) {
                    wp_redirect( add_query_arg( 'rb_notice', 'download_not_available', $backups_page ) );
                    exit;
                }

                RSD_RB_Manifest::mark_downloading( $manifest_id );
                RSD_RB_Download_Worker::schedule( $manifest_id );
                RSD_RB_Logger::info( 'Manual download trigger: manifest #' . $manifest_id . ' scheduled.' );
                wp_redirect( add_query_arg( 'rb_notice', 'download_started', $backups_page ) );
                exit;

            case 'refresh_from_provider':
                $backups_page = admin_url( 'admin.php?page=' . RSD_RB_Admin_Page::BACKUPS_PAGE_SLUG );

                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_refresh_provider' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }

                // Runs the same reconciliation as the Settings tab's "Resync" button —
                // this also discovers pre-existing remote backups this install never
                // processed locally (giving them a manifest row so they appear on this
                // screen) and de-duplicates any job rows a compressed upload's ".gz"
                // name was previously mistaken for a brand new backup.
                try {
                    $result = RSD_RB_Rest_Api::run_resync();
                } catch ( RuntimeException $e ) {
                    RSD_RB_Logger::error( 'Refresh from provider: ' . $e->getMessage() );
                    wp_redirect( add_query_arg( 'rb_notice', 'refresh_failed', $backups_page ) );
                    exit;
                }

                $missing = $result['orphaned_manifest_ids'];
                set_transient( 'rsd_rb_manifest_missing_remote', $missing, 5 * MINUTE_IN_SECONDS );
                wp_redirect( add_query_arg(
                    'rb_notice',
                    'refresh_done_' . count( $missing ) . '_' . $result['created'] . '_' . $result['backfilled'],
                    $backups_page
                ) );
                exit;
        }
    }

    // -------------------------------------------------------------------------
    // Maintenance screen actions

    public function handle_maintenance_actions(): void {
        if ( ! isset( $_GET['rb_maint_action'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action   = sanitize_key( $_GET['rb_maint_action'] );
        $nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        $redirect = admin_url( 'admin.php?page=' . RSD_RB_Maintenance_Page::PAGE_SLUG );

        switch ( $action ) {

            case 'delete_all_comments':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_delete_all_comments' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                $deleted = RSD_RB_Comment_Maintenance::delete_all();
                RSD_RB_Logger::info( 'Maintenance: deleted all comments (' . $deleted . ' removed).' );
                wp_redirect( add_query_arg( 'rb_notice', 'comments_deleted_' . $deleted, $redirect ) );
                exit;

            case 'disk_scan_start':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_disk_scan_start' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                RSD_RB_Disk_Scanner::start();
                wp_redirect( add_query_arg( 'rb_tab', 'disk-usage', $redirect ) );
                exit;

            case 'disk_scan_cancel':
                if ( ! wp_verify_nonce( $nonce, 'rsd_rb_disk_scan_cancel' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'rsd-remote-backup' ) );
                }
                RSD_RB_Disk_Scanner::cancel();
                wp_redirect( add_query_arg( 'rb_tab', 'disk-usage', $redirect ) );
                exit;
        }
    }

    /**
     * Background poll behind the Disk Usage tab's live progress display —
     * runs one chunk and returns the updated counters as JSON, so the page
     * can update in place instead of doing a full reload per chunk (which
     * is what an earlier version of this feature did, and which turned out
     * to hijack navigation away from whatever tab the admin was actually
     * looking at). Same nonce + capability posture as every GET-based
     * action above, just checked via check_ajax_referer() instead.
     */
    public function ajax_disk_scan_tick(): void {
        check_ajax_referer( 'rsd_rb_disk_scan_tick', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }

        RSD_RB_Disk_Scanner::run_chunk();
        $state = RSD_RB_Disk_Scanner::get_state();

        wp_send_json_success(
            array(
                'status'        => $state['status'],
                'files_scanned' => $state['files_scanned'],
                'dirs_scanned'  => $state['dirs_scanned'],
                'error_count'   => count( $state['errors'] ),
            )
        );
    }

    /**
     * Renders the Disk Usage tab's breadcrumb + folder/file table for a
     * given path and returns it as an HTML fragment, so clicking a
     * breadcrumb/folder/file link updates in place instead of doing a full
     * page reload — the same complaint (and fix) as the scan's own live
     * progress display, just for browsing a completed scan's results
     * rather than watching one run. $path is only trusted once confirmed
     * to be something this site's own completed scan actually discovered
     * (is_known_path()) — it arrives as untrusted POST data otherwise.
     */
    public function ajax_disk_scan_view(): void {
        check_ajax_referer( 'rsd_rb_disk_scan_view', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }

        $rsd_rb_disk_path = isset( $_POST['path'] ) ? wp_unslash( $_POST['path'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        if ( ! RSD_RB_Disk_Scanner::is_known_path( $rsd_rb_disk_path ) ) {
            wp_send_json_error( array( 'message' => 'Unknown path' ), 400 );
        }

        $rsd_rb_disk_view = ( isset( $_POST['view'] ) && 'files' === $_POST['view'] ) ? 'files' : 'folder';

        ob_start();
        require RSD_RB_DIR . 'admin/views/partials/disk-usage-browser.php';
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * Deletes a single file from the Disk Usage tab's per-file listing.
     * All the actual safety logic (protected paths, re-verifying the file
     * hasn't changed since it was shown) lives in RSD_RB_File_Deleter —
     * this just wires up the nonce/capability gate and logs the outcome,
     * same as every other admin action in this plugin.
     */
    public function ajax_disk_file_delete(): void {
        check_ajax_referer( 'rsd_rb_disk_file_delete', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }

        $path           = isset( $_POST['path'] ) ? wp_unslash( $_POST['path'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $expected_size  = isset( $_POST['size'] ) ? (int) $_POST['size'] : -1;
        $expected_mtime = ( isset( $_POST['mtime'] ) && '' !== $_POST['mtime'] ) ? (int) $_POST['mtime'] : null;

        $result = RSD_RB_File_Deleter::delete( $path, $expected_size, $expected_mtime );

        if ( $result['success'] ) {
            RSD_RB_Logger::info( 'Maintenance: deleted file via Disk Usage tab: ' . $path );
            wp_send_json_success( $result );
        } else {
            RSD_RB_Logger::info( 'Maintenance: file delete blocked/failed (' . $result['code'] . '): ' . $path );
            wp_send_json_error( $result );
        }
    }

    private function test_connection(): string {
        $provider = $this->get_active_provider();
        if ( ! $provider ) {
            return 'test_no_provider';
        }
        if ( ! $provider->is_connected() ) {
            return 'test_not_connected';
        }
        try {
            // Attempt to refresh/validate the token and list the folder.
            $provider->ensure_folder( RSD_RB_Settings::get_folder_name() );
            RSD_RB_Logger::info( 'Test connection: ' . $provider->label() . ' — OK.' );
            return 'test_ok';
        } catch ( RuntimeException $e ) {
            RSD_RB_Logger::error( 'Test connection failed: ' . $e->getMessage() );
            return 'test_failed';
        }
    }

    // -------------------------------------------------------------------------
    // Cron / AS hooks

    public function run_scan(): void {
        // Unconditional entry log — this is the one call site meant to run
        // completely unattended every 15 minutes via WP-Cron, with nobody
        // watching. Previously the only evidence it ran at all was indirect
        // (a "reset N stalled" line that only appears if something was
        // actually stalled, or the scanner's own internal log lines) — this
        // makes "did the scheduled scan tick even fire" directly answerable
        // from the log, independent of whether it found anything to do.
        RSD_RB_Logger::info( sprintf(
            'Scan: run_scan() invoked (%s).',
            ( defined( 'DOING_CRON' ) && DOING_CRON ) ? 'WP-Cron' : 'direct call'
        ) );

        // Queue-health snapshot before doing anything else this tick — makes
        // "was the concurrency cap full for hours" directly visible by
        // scanning consecutive log entries, without needing the admin UI.
        RSD_RB_Queue::log_queue_snapshot();

        // Reset any stalled uploading jobs before scanning so they get re-scheduled.
        $reset = RSD_RB_Queue::reset_stalled();
        if ( $reset > 0 ) {
            RSD_RB_Logger::warning( 'Scan: reset ' . $reset . ' stalled job(s) to pending.' );
        }

        $queued = RSD_RB_Backup_Scanner::run();
        update_option( 'rsd_rb_last_scan', wp_date( 'c' ), false );

        // Schedule any newly enqueued or just-reset jobs immediately.
        $provider  = RSD_RB_Settings::get_provider();
        $scheduled = RSD_RB_Upload_Worker::schedule_all_pending( $provider );

        // Unconditional exit log with the actual dispatch decision. Unlike every
        // manual admin action that calls schedule_all_pending() (each of which
        // already logs its own "N job(s) scheduled" line right after calling
        // it), this WP-Cron entry point previously discarded both return values
        // completely silently — so even on a site where the scheduled scan was
        // ticking perfectly normally, the log gave no proof of it beyond
        // whatever the scanner itself happened to log.
        RSD_RB_Logger::info( sprintf(
            'Scan: run_scan() complete — %d stalled job(s) reset, %d new file(s) queued, %d job(s) scheduled for upload (provider=%s).',
            $reset,
            $queued,
            $scheduled,
            $provider
        ) );
    }

    /** Called by Action Scheduler or WP-Cron single event. */
    public function run_upload_worker( int $job_id = 0 ): void {
        RSD_RB_Upload_Worker::process( $job_id );
    }

    /** Called by Action Scheduler or WP-Cron single event. */
    public function run_download_worker( int $manifest_id = 0 ): void {
        RSD_RB_Download_Worker::process( $manifest_id );
    }

    // -------------------------------------------------------------------------
    // Provider factory

    public function get_provider( string $key ): ?RB_Provider {
        switch ( $key ) {
            case 'google-drive':
                return new RSD_RB_Provider_Google_Drive();
            case 'onedrive':
                return new RSD_RB_Provider_OneDrive();
            default:
                return null;
        }
    }

    public function get_active_provider(): ?RB_Provider {
        return $this->get_provider( RSD_RB_Settings::get_provider() );
    }
}

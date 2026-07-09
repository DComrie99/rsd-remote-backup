<?php
defined( 'ABSPATH' ) || exit;

class RSD_RB_Admin_Page {

    const PAGE_SLUG         = 'rsd-remote-backup';
    const BACKUPS_PAGE_SLUG = 'rsd-remote-backup-backups';

    public static function add_menu(): void {
        add_menu_page(
            __( 'Red Swirl Design Remote Backup', 'rsd-remote-backup' ),
            __( 'RSD Backup', 'rsd-remote-backup' ), // Top-level sidebar icon label — stays distinct so the two submenu items below ("Settings", "Backups") are unambiguous once expanded.
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' ),
            RSD_RB_URL . 'admin/assets/rsd-logo.png',
            80
        );

        // Explicit submenu for the settings page itself, overriding the label
        // WordPress would otherwise auto-generate from the menu_title above —
        // this is what makes the expanded sidebar read "Settings" / "Backups".
        add_submenu_page(
            self::PAGE_SLUG,
            __( 'Red Swirl Design Remote Backup — Settings', 'rsd-remote-backup' ),
            __( 'Settings', 'rsd-remote-backup' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __( 'RSD Backup — Backups', 'rsd-remote-backup' ),
            __( 'Backups', 'rsd-remote-backup' ),
            'manage_options',
            self::BACKUPS_PAGE_SLUG,
            array( __CLASS__, 'render_backups' )
        );
    }

    public static function menu_icon_style(): void {
        echo '<style>#adminmenu .toplevel_page_rsd-remote-backup .wp-menu-image img{width:20px!important;height:20px!important;padding-top:7px!important;padding-left:0!important;padding-right:0!important;padding-bottom:0!important}</style>';
    }

    public static function enqueue_assets( string $hook ): void {
        $valid_hooks = array(
            'toplevel_page_' . self::PAGE_SLUG,
            self::PAGE_SLUG . '_page_' . self::BACKUPS_PAGE_SLUG,
        );
        if ( ! in_array( $hook, $valid_hooks, true ) ) {
            return;
        }
        wp_enqueue_style(
            'rsd-rb-admin',
            RSD_RB_URL . 'admin/assets/admin.css',
            array(),
            RSD_RB_VERSION
        );
        wp_enqueue_script(
            'rsd-rb-admin',
            RSD_RB_URL . 'admin/assets/admin.js',
            array( 'jquery' ),
            RSD_RB_VERSION,
            true
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'rsd-remote-backup' ) );
        }

        // Persistent (not tied to rb_notice, so it reappears on every load until fixed).
        if ( ! RSD_RB_License::is_valid() ) {
            add_settings_error(
                'RSD_RB', 'license_invalid',
                sprintf(
                    /* translators: %s: link to the License tab */
                    __( 'RSD Remote Backup is not licensed — scanning, uploads, and resync are disabled until a valid license key is entered on the %s.', 'rsd-remote-backup' ),
                    '<a href="' . esc_url( add_query_arg( 'rb_tab', 'license', admin_url( 'admin.php?page=rsd-remote-backup' ) ) ) . '">' . esc_html__( 'License tab', 'rsd-remote-backup' ) . '</a>'
                ),
                'error'
            );
        }

        // Surface rb_notice query-param as a settings error so it renders with settings_errors().
        if ( isset( $_GET['rb_notice'] ) ) {
            switch ( sanitize_key( $_GET['rb_notice'] ) ) {
                case 'oauth_connected':
                    add_settings_error( 'RSD_RB', 'oauth_connected', __( 'Provider connected successfully.', 'rsd-remote-backup' ), 'success' );
                    break;
                case 'oauth_denied':
                    add_settings_error( 'RSD_RB', 'oauth_denied', __( 'Authorization was cancelled by the user.', 'rsd-remote-backup' ), 'warning' );
                    break;
                case 'oauth_error':
                    add_settings_error( 'RSD_RB', 'oauth_error', __( 'Authorization failed — check the log for details.', 'rsd-remote-backup' ), 'error' );
                    break;
                case 'oauth_disconnected':
                    add_settings_error( 'RSD_RB', 'oauth_disconnected', __( 'Provider disconnected.', 'rsd-remote-backup' ), 'warning' );
                    break;
                case 'log_cleared':
                    add_settings_error( 'RSD_RB', 'log_cleared', __( 'Log cleared.', 'rsd-remote-backup' ), 'success' );
                    break;
                case 'upload_triggered':
                    add_settings_error( 'RSD_RB', 'upload_triggered', __( 'Upload jobs scheduled — check the Status tab for progress.', 'rsd-remote-backup' ), 'success' );
                    break;
                case 'test_ok':
                    add_settings_error( 'RSD_RB', 'test_ok', __( 'Connection test passed.', 'rsd-remote-backup' ), 'success' );
                    break;
                case 'test_failed':
                    add_settings_error( 'RSD_RB', 'test_failed', __( 'Connection test failed — check the log for details.', 'rsd-remote-backup' ), 'error' );
                    break;
                case 'test_not_connected':
                    add_settings_error( 'RSD_RB', 'test_not_connected', __( 'Provider is not connected. Authorise it on the Provider tab first.', 'rsd-remote-backup' ), 'warning' );
                    break;
                case 'test_no_provider':
                    add_settings_error( 'RSD_RB', 'test_no_provider', __( 'No provider configured.', 'rsd-remote-backup' ), 'warning' );
                    break;
                case 'api_key_regenerated':
                    add_settings_error( 'RSD_RB', 'api_key_regenerated', __( 'API key regenerated. Update the key in your CRM.', 'rsd-remote-backup' ), 'success' );
                    break;
                case 'transient_diag_set':
                    add_settings_error( 'RSD_RB', 'transient_diag_set', __( 'Diagnostic probe set — see the result in Environment Diagnostics below.', 'rsd-remote-backup' ), 'info' );
                    break;
                case 'compression_benchmark_done':
                    add_settings_error( 'RSD_RB', 'compression_benchmark_done', __( 'Compression benchmark complete — see the Compression section below.', 'rsd-remote-backup' ), 'success' );
                    break;
                case 'compression_benchmark_failed':
                    add_settings_error( 'RSD_RB', 'compression_benchmark_failed', __( 'Compression benchmark could not run — check the log for details.', 'rsd-remote-backup' ), 'error' );
                    break;
                case 'od_no_permalinks':
                    add_settings_error(
                        'RSD_RB', 'od_no_permalinks',
                        __( 'OneDrive requires Pretty Permalinks. Go to Settings → Permalinks, choose any option other than "Plain", and save. Then try connecting again.', 'rsd-remote-backup' ),
                        'error'
                    );
                    break;
                default:
                    $raw = sanitize_text_field( wp_unslash( $_GET['rb_notice'] ) );
                    // resync_N_N_N_N_N (updated_created_orphaned_duplicatesRemoved_backfilled)
                    if ( 1 === preg_match( '/^resync_(\d+)_(\d+)_(\d+)_(\d+)_(\d+)$/', $raw, $m ) ) {
                        add_settings_error(
                            'RSD_RB', 'resync_complete',
                            sprintf(
                                /* translators: 1: updated count 2: created count 3: orphaned count 4: duplicates removed 5: legacy manifests backfilled */
                                __( 'Resync complete: %1$d record(s) updated, %2$d remote-only record(s) added, %3$d orphan(s) found, %4$d duplicate(s) removed, %5$d older backup(s) linked into the Backups screen.', 'rsd-remote-backup' ),
                                (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]
                            ),
                            'success'
                        );
                    }
                    // stall_reset_N
                    if ( 1 === preg_match( '/^stall_reset_(\d+)$/', $raw, $m ) ) {
                        $n = (int) $m[1];
                        add_settings_error(
                            'RSD_RB', 'stall_reset',
                            $n > 0
                                ? sprintf(
                                    /* translators: %d: number of jobs reset */
                                    _n( '%d stalled job reset and rescheduled.', '%d stalled jobs reset and rescheduled.', $n, 'rsd-remote-backup' ),
                                    $n
                                )
                                : __( 'No stalled jobs found.', 'rsd-remote-backup' ),
                            $n > 0 ? 'success' : 'info'
                        );
                    }
                    // pending_cancelled_N
                    if ( 1 === preg_match( '/^pending_cancelled_(\d+)$/', $raw, $m ) ) {
                        $n = (int) $m[1];
                        add_settings_error(
                            'RSD_RB', 'pending_cancelled',
                            $n > 0
                                ? sprintf(
                                    /* translators: %d: number of jobs cancelled */
                                    _n( '%d pending upload cancelled.', '%d pending uploads cancelled.', $n, 'rsd-remote-backup' ),
                                    $n
                                )
                                : __( 'No pending uploads found.', 'rsd-remote-backup' ),
                            $n > 0 ? 'success' : 'info'
                        );
                    }
                    // retry_failed_N
                    if ( 1 === preg_match( '/^retry_failed_(\d+)$/', $raw, $m ) ) {
                        $n = (int) $m[1];
                        add_settings_error(
                            'RSD_RB', 'retry_failed',
                            $n > 0
                                ? sprintf(
                                    /* translators: %d: number of jobs requeued */
                                    _n( '%d failed upload requeued and rescheduled.', '%d failed uploads requeued and rescheduled.', $n, 'rsd-remote-backup' ),
                                    $n
                                )
                                : __( 'No failed uploads with a local file to retry were found.', 'rsd-remote-backup' ),
                            $n > 0 ? 'success' : 'info'
                        );
                    }
                    // files_scanned_N
                    if ( 1 === preg_match( '/^files_scanned_(\d+)$/', $raw, $m ) ) {
                        $n = (int) $m[1];
                        add_settings_error(
                            'RSD_RB', 'files_scanned',
                            sprintf(
                                /* translators: %d: number of backup files found */
                                _n( '%d backup file found — see the log below for details.', '%d backup files found — see the log below for details.', $n, 'rsd-remote-backup' ),
                                $n
                            ),
                            'info'
                        );
                    }
                    break;
            }
        }

        require_once RSD_RB_DIR . 'admin/views/settings-page.php';
    }

    // -------------------------------------------------------------------------
    // Backups (download & stage for restore) screen

    public static function render_backups(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'rsd-remote-backup' ) );
        }

        if ( isset( $_GET['rb_notice'] ) ) {
            self::add_backups_notice( sanitize_text_field( wp_unslash( $_GET['rb_notice'] ) ) );
        }

        require_once RSD_RB_DIR . 'admin/views/backups-page.php';
    }

    private static function add_backups_notice( string $raw ): void {
        switch ( $raw ) {
            case 'download_started':
                add_settings_error(
                    'RSD_RB', 'download_started',
                    __( 'Download started — large backups can take a while; refresh this page to check progress.', 'rsd-remote-backup' ),
                    'success'
                );
                break;
            case 'download_not_available':
                add_settings_error(
                    'RSD_RB', 'download_not_available',
                    __( 'That backup is not in an uploaded state — nothing to download.', 'rsd-remote-backup' ),
                    'warning'
                );
                break;
            case 'refresh_failed':
                add_settings_error(
                    'RSD_RB', 'refresh_failed',
                    __( 'Refresh failed — could not list files from the connected provider. Check the log for details.', 'rsd-remote-backup' ),
                    'error'
                );
                break;
            default:
                // refresh_done_<missing>_<newlyDiscovered>_<legacyBackfilled>
                if ( 1 === preg_match( '/^refresh_done_(\d+)_(\d+)_(\d+)$/', $raw, $m ) ) {
                    $missing    = (int) $m[1];
                    $discovered = (int) $m[2];
                    $backfilled = (int) $m[3];
                    $parts = array();
                    if ( $discovered > 0 ) {
                        $parts[] = sprintf(
                            /* translators: %d: number of newly discovered backups */
                            _n( '%d backup discovered on the remote provider and added to this list.', '%d backups discovered on the remote provider and added to this list.', $discovered, 'rsd-remote-backup' ),
                            $discovered
                        );
                    }
                    if ( $backfilled > 0 ) {
                        $parts[] = sprintf(
                            /* translators: %d: number of older backups linked in */
                            _n( '%d older backup linked in from before this screen existed.', '%d older backups linked in from before this screen existed.', $backfilled, 'rsd-remote-backup' ),
                            $backfilled
                        );
                    }
                    if ( $missing > 0 ) {
                        $parts[] = sprintf(
                            /* translators: %d: number of backups not found remotely */
                            _n( '%d backup not found on the remote provider — see below.', '%d backups not found on the remote provider — see below.', $missing, 'rsd-remote-backup' ),
                            $missing
                        );
                    }
                    add_settings_error(
                        'RSD_RB', 'refresh_done',
                        $parts ? implode( ' ', $parts ) : __( 'Refresh complete — every uploaded backup is confirmed present remotely.', 'rsd-remote-backup' ),
                        $missing > 0 ? 'warning' : 'success'
                    );
                }
                break;
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Return HTML for the connection status badge + action button for a provider.
     *
     * @param string $provider_key 'google-drive' | 'onedrive'
     * @param string $label        Human-readable provider name.
     */
    public static function connection_status_html( string $provider_key, string $label ): string {
        $connected        = RSD_RB_OAuth::has_tokens( $provider_key );
        $permalink_broken = ( 'onedrive' === $provider_key && '' === get_option( 'permalink_structure' ) );

        if ( $connected ) {
            $badge = sprintf(
                '<span class="rsd-rb-status rsd-rb-status--connected">%s</span>',
                esc_html__( 'Connected', 'rsd-remote-backup' )
            );
            $action = sprintf(
                '<a href="%s" class="button button-secondary" onclick="return confirm(\'%s\');">%s</a>',
                esc_url( wp_nonce_url(
                    admin_url( 'admin.php?page=rsd-remote-backup&rb_oauth=disconnect&provider=' . $provider_key ),
                    'rsd_rb_disconnect_' . $provider_key
                ) ),
                esc_js( __( 'Disconnect this provider? You will need to re-authorise to resume uploads.', 'rsd-remote-backup' ) ),
                esc_html__( 'Disconnect', 'rsd-remote-backup' )
            );
        } else {
            $badge = sprintf(
                '<span class="rsd-rb-status rsd-rb-status--disconnected">%s</span>',
                esc_html__( 'Not connected', 'rsd-remote-backup' )
            );

            if ( $permalink_broken ) {
                // Block the connect button — OneDrive OAuth cannot work without pretty permalinks.
                $action = sprintf(
                    '<a href="#" class="button" disabled style="pointer-events:none;opacity:.6;" aria-disabled="true">%s</a>',
                    /* translators: %s = provider label */
                    sprintf( esc_html__( 'Connect %s', 'rsd-remote-backup' ), esc_html( $label ) )
                );
            } else {
                $action = sprintf(
                    '<a href="%s" class="button">%s</a>',
                    esc_url( wp_nonce_url(
                        admin_url( 'admin.php?page=rsd-remote-backup&rb_oauth=connect&provider=' . $provider_key ),
                        'rsd_rb_connect_' . $provider_key
                    ) ),
                    /* translators: %s = provider label */
                    sprintf( esc_html__( 'Connect %s', 'rsd-remote-backup' ), esc_html( $label ) )
                );
            }
        }

        $out = $badge . '&nbsp;' . $action;

        // Permalink health warning — shown whether connected or not.
        if ( $permalink_broken ) {
            $out .= sprintf(
                '<p class="description rsd-rb-warning">%s</p>',
                esc_html__( '⚠ OneDrive requires Pretty Permalinks. Go to Settings → Permalinks, choose any option other than "Plain", and save.', 'rsd-remote-backup' )
            );
        }

        return $out;
    }
}

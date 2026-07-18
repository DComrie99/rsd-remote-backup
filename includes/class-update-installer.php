<?php
defined( 'ABSPATH' ) || exit;

/**
 * Installs an available rsd-remote-backup self-update on demand, via the
 * REST API's POST /self-update — the "kick off the update" counterpart to
 * class-update-inventory.php's read-only reporting.
 *
 * Uses WordPress's own Plugin_Upgrader class with Automatic_Upgrader_Skin —
 * the exact same pair WordPress's background auto-update system
 * (WP_Automatic_Updater) and the admin "Update Now" button use. Neither of
 * those callers is a real logged-in wp-admin user session either (the
 * former runs from WP-Cron), so — like them — this doesn't check
 * current_user_can() itself; there's no WP user to check against over a
 * REST API-key request, same as there's none during a cron-triggered
 * background update.
 */
class RSD_RB_Update_Installer {

    /**
     * @return array{installed:bool, old_version:string, new_version:?string, message?:string, error?:string}
     */
    public static function install_self_update(): array {
        if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
            require_once RSD_RB_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
        }

        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/DComrie99/rsd-remote-backup/',
            RSD_RB_FILE,
            RSD_RB_SLUG
        );
        $checker->getVcsApi()->enableReleaseAssets();

        // Unlike /updates (a cheap, frequently-polled read that deliberately
        // reports last-known state), this action is infrequent and is about to
        // act on what it finds — force a live check rather than installing
        // against a persisted state that might be hours stale.
        $checker->checkForUpdates();
        $update = $checker->getUpdate();

        if ( null === $update ) {
            return array(
                'installed'   => false,
                'old_version' => RSD_RB_VERSION,
                'new_version' => null,
                'message'     => 'No update available.',
            );
        }

        $new_version = $update->version;

        self::load_upgrader_classes();

        // Same constraint WordPress's own background auto-updates run under —
        // a site not configured for direct filesystem writes would otherwise
        // need interactive FTP/SSH credentials nothing here can supply.
        if ( 'direct' !== get_filesystem_method() ) {
            return array(
                'installed'   => false,
                'old_version' => RSD_RB_VERSION,
                'new_version' => $new_version,
                'error'       => 'Server filesystem access method is not "direct" — cannot install automatically. Update manually via wp-admin.',
            );
        }

        $creds = request_filesystem_credentials( '', '', false, false, null );
        if ( false === $creds || ! WP_Filesystem( $creds ) ) {
            return array(
                'installed'   => false,
                'old_version' => RSD_RB_VERSION,
                'new_version' => $new_version,
                'error'       => 'Could not initialize filesystem access.',
            );
        }

        $plugin_file    = plugin_basename( RSD_RB_FILE );
        // Captured BEFORE upgrade() runs — Plugin_Upgrader deactivates the
        // plugin being upgraded as a safety measure whenever the request isn't
        // detected as wp_doing_cron() (a plain REST request never is), so the
        // 'active_plugins' option no longer reflects pre-upgrade state by the
        // time upgrade() returns.
        $was_active         = is_plugin_active( $plugin_file );
        $was_network_active = is_plugin_active_for_network( $plugin_file );

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );
        $result   = $upgrader->upgrade( $plugin_file );

        if ( is_wp_error( $result ) ) {
            RSD_RB_Logger::error( 'Self-update install failed: ' . $result->get_error_message() );
            return array(
                'installed'   => false,
                'old_version' => RSD_RB_VERSION,
                'new_version' => $new_version,
                'error'       => $result->get_error_message(),
            );
        }

        if ( true !== $result ) {
            RSD_RB_Logger::error( 'Self-update install did not complete (unknown reason) attempting ' . RSD_RB_VERSION . ' -> ' . $new_version . '.' );
            return array(
                'installed'   => false,
                'old_version' => RSD_RB_VERSION,
                'new_version' => $new_version,
                'error'       => 'Update did not complete (unknown reason).',
            );
        }

        // Restore the pre-upgrade active state Plugin_Upgrader just cleared —
        // the same step wp-admin's own AJAX "Update Now" handler
        // (wp_ajax_update_plugin() in wp-admin/includes/ajax-actions.php) takes
        // after calling the identical upgrade() method. $silent = true skips
        // re-firing the plugin's own activation hook — this is restoring
        // already-active state post-update, not a fresh activation.
        $reactivate_error = null;
        if ( $was_active ) {
            $activated = activate_plugin( $plugin_file, '', $was_network_active, true );
            if ( is_wp_error( $activated ) ) {
                $reactivate_error = $activated->get_error_message();
                RSD_RB_Logger::error( 'Self-update: reactivation after install failed — ' . $reactivate_error );
            }
        }

        RSD_RB_Logger::info( 'Self-update installed: ' . RSD_RB_VERSION . ' -> ' . $new_version . ' (takes effect on next request).' );

        $response = array(
            'installed'   => true,
            'old_version' => RSD_RB_VERSION,
            'new_version' => $new_version,
            'message'     => 'Updated from ' . RSD_RB_VERSION . ' to ' . $new_version . '. Takes effect on the next request/page load — this request already ran on the old code.',
        );

        if ( null !== $reactivate_error ) {
            $response['error'] = 'Update installed, but reactivation failed — the plugin is now INACTIVE and must be manually reactivated in wp-admin: ' . $reactivate_error;
        }

        return $response;
    }

    private static function load_upgrader_classes(): void {
        if ( ! function_exists( 'request_filesystem_credentials' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'get_filesystem_method' ) ) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if ( ! class_exists( 'Plugin_Upgrader' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }
}

<?php
/**
 * Plugin Name:       Red Swirl Design Remote Backup
 * Plugin URI:        https://github.com/DComrie99/rsd-crm
 * Description:       Uploads All-in-One WP Migration backups to Google Drive or Microsoft OneDrive with chunked, resumable transfers and automatic retention.
 * Version:           0.8.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Red Swirl Design
 * Author URI:        https://www.redswirldesign.co.za
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rsd-remote-backup
 */

defined( 'ABSPATH' ) || exit;

// --- Constants -----------------------------------------------------------

define( 'RSD_RB_VERSION',        '0.8.0' );
define( 'RSD_RB_FILE',           __FILE__ );
define( 'RSD_RB_DIR',            plugin_dir_path( __FILE__ ) );
define( 'RSD_RB_URL',            plugin_dir_url( __FILE__ ) );
define( 'RSD_RB_SLUG',           'rsd-remote-backup' );
define( 'RSD_RB_TABLE',          'rsd_rb_jobs' );
define( 'RSD_RB_MANIFEST_TABLE', 'rsd_rb_backup_manifest' );
define( 'RSD_RB_LOG_OPTION',     'rsd_rb_log' );
define( 'RSD_RB_LOG_MAX',        2000 );

// Optional: define RSD_RB_ENCRYPTION_KEY in wp-config.php for best token security.

// --- Action Scheduler (bundled fallback) ---------------------------------
// Load the bundled copy only if AS is not already available (e.g. via WooCommerce).
// The vendor copy is installed separately — see readme.txt for instructions.
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
    $as_loader = RSD_RB_DIR . 'vendor/action-scheduler/action-scheduler.php';
    if ( file_exists( $as_loader ) ) {
        require_once $as_loader;
    }
    // If neither WooCommerce nor the bundled copy loaded AS, the upload worker
    // falls back gracefully to wp_schedule_single_event.
}

// --- Bootstrap -----------------------------------------------------------

function RSD_RB_init(): void {
    // Core
    require_once RSD_RB_DIR . 'includes/class-logger.php';
    require_once RSD_RB_DIR . 'includes/class-crypto.php';
    require_once RSD_RB_DIR . 'includes/class-oauth.php';
    require_once RSD_RB_DIR . 'includes/class-settings.php';
    require_once RSD_RB_DIR . 'includes/class-license.php';
    require_once RSD_RB_DIR . 'includes/class-activator.php';
    require_once RSD_RB_DIR . 'includes/class-queue.php';
    require_once RSD_RB_DIR . 'includes/class-manifest.php';
    require_once RSD_RB_DIR . 'includes/class-backup-scanner.php';
    require_once RSD_RB_DIR . 'includes/class-retention.php';
    require_once RSD_RB_DIR . 'includes/class-local-retention.php';
    require_once RSD_RB_DIR . 'includes/class-compressor.php';
    require_once RSD_RB_DIR . 'includes/class-upload-worker.php';
    require_once RSD_RB_DIR . 'includes/class-download-worker.php';
    require_once RSD_RB_DIR . 'includes/class-server-stats.php';
    require_once RSD_RB_DIR . 'includes/class-rest-api.php';

    // Provider interface + adapters
    require_once RSD_RB_DIR . 'includes/providers/interface-provider.php';
    require_once RSD_RB_DIR . 'includes/providers/class-provider-google-drive.php';
    require_once RSD_RB_DIR . 'includes/providers/class-provider-onedrive.php';

    // Admin
    if ( is_admin() ) {
        require_once RSD_RB_DIR . 'admin/class-admin-page.php';
    }

    // Kick off the singleton (registers all hooks)
    require_once RSD_RB_DIR . 'includes/class-plugin.php';
    RSD_RB_Plugin::get_instance();
}
add_action( 'plugins_loaded', 'RSD_RB_init' );

// --- Activation / deactivation (load classes directly — plugins_loaded not yet fired) ---

register_activation_hook( __FILE__, static function (): void {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-logger.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-rest-api.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-activator.php';
    RSD_RB_Activator::activate();
} );

register_deactivation_hook( __FILE__, static function (): void {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-logger.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-activator.php';
    RSD_RB_Activator::deactivate();
} );

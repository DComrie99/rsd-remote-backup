<?php
defined( 'ABSPATH' ) || exit;

class RSD_RB_Maintenance_Page {

    const PAGE_SLUG = 'rsd-rb-maintenance';

    /**
     * Hook suffix for this submenu page, as actually returned by
     * add_submenu_page() — see the matching note on RSD_RB_Admin_Page's own
     * $hooks property for why this can't be hand-built from the slug.
     *
     * @var array<int,string>
     */
    private static $hooks = array();

    /**
     * Third submenu under the existing "RSD Backup" top-level menu, alongside
     * "Settings" and "Backups" — not its own top-level icon. Registered as a
     * separate admin_menu callback (rather than folded into
     * RSD_RB_Admin_Page::add_menu()) purely to keep this feature's own menu
     * registration/asset-enqueue/action-handling together in one class.
     */
    public static function add_menu(): void {
        $hook = add_submenu_page(
            RSD_RB_Admin_Page::PAGE_SLUG,
            __( 'RSD Backup — Maintenance', 'rsd-remote-backup' ),
            __( 'Maintenance', 'rsd-remote-backup' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );

        self::$hooks = array_filter( array( $hook ) );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, self::$hooks, true ) ) {
            return;
        }
        // Same handles/assets as the Settings/Backups screens — the tab
        // switcher in admin.js is generic (.rsd-rb-wrap .nav-tab), so it
        // works here unmodified.
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

        if ( isset( $_GET['rb_notice'] ) ) {
            self::add_notice( sanitize_text_field( wp_unslash( $_GET['rb_notice'] ) ) );
        }

        require_once RSD_RB_DIR . 'admin/views/maintenance-page.php';
    }

    private static function add_notice( string $raw ): void {
        if ( 1 === preg_match( '/^comments_deleted_(\d+)$/', $raw, $m ) ) {
            $n = (int) $m[1];
            add_settings_error(
                'RSD_RB', 'comments_deleted',
                $n > 0
                    ? sprintf(
                        /* translators: %d: number of comments deleted */
                        _n( '%d comment deleted.', '%d comments deleted.', $n, 'rsd-remote-backup' ),
                        $n
                    )
                    : __( 'No comments found to delete.', 'rsd-remote-backup' ),
                'success'
            );
        }
    }
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Collects the site's full update inventory for the REST API's /updates
 * endpoint — installed plugins, themes, WP core, and pending translation
 * (language pack) updates. Mirrors the same four categories MainWP's own
 * dashboard "Updates" column sums together, so CRM can reproduce that count
 * for any site with this plugin installed, not just MainWP-managed ones.
 *
 * Sourced entirely from WordPress core APIs — no external network calls of
 * its own beyond whatever WordPress's own periodic update-check cron has
 * already cached (see collect_core()'s docblock for why this deliberately
 * doesn't force a fresh wordpress.org check on every call).
 *
 * Was a plugins-only /plugins endpoint (RSD_RB_Plugin_Inventory) — broadened
 * and renamed before ever being deployed anywhere, so no backward
 * compatibility concern with the old shape/name.
 */
class RSD_RB_Update_Inventory {

    public static function collect(): array {
        self::load_wp_admin_includes();

        return array(
            'site'               => home_url(),
            'checked_at'         => wp_date( 'c' ),
            'core'               => self::collect_core(),
            'plugins'            => self::collect_plugins(),
            'themes'             => self::collect_themes(),
            'translations'       => self::collect_translations(),
            'plugin_self_update' => self::collect_plugin_self_update(),
        );
    }

    /**
     * This plugin's OWN update status — is a newer rsd-remote-backup
     * release available on GitHub. A clearly-labeled field rather than
     * expecting CRM to find rsd-remote-backup's own entry buried in
     * plugins[] by matching its file path.
     *
     * Deliberately builds its own update-checker instance here rather than
     * reusing RSD_RB_Plugin::get_update_checker() — that one is only ever
     * constructed on real wp-admin page loads (is_admin() gate in
     * register_update_checker()), so it would be null during this REST
     * request. Building a second instance is safe: the update-checker
     * library's constructor just registers a handful of hooks that only do
     * anything if the WP-admin actions they hook into actually fire later
     * in the same request (Plugins-screen rendering, an upgrade running,
     * etc.) — none of which happens during a plain API call. Both
     * instances read/write the exact same persisted state (a WP option
     * keyed by plugin slug), so this reports whatever the last real check
     * (triggered by any admin page load) found — not a fresh live check on
     * every API call, same as every other update_available/new_version
     * field on this endpoint.
     */
    private static function collect_plugin_self_update(): array {
        if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
            require_once RSD_RB_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
        }

        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/DComrie99/rsd-remote-backup/',
            RSD_RB_FILE,
            RSD_RB_SLUG
        );
        $checker->getVcsApi()->enableReleaseAssets();

        $update = $checker->getUpdate();

        return array(
            'version'          => RSD_RB_VERSION,
            'update_available' => null !== $update,
            'new_version'      => $update->version ?? null,
        );
    }

    private static function load_wp_admin_includes(): void {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! function_exists( 'get_core_updates' ) || ! function_exists( 'wp_get_translation_updates' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
    }

    /**
     * WordPress's own cached update-check data (site transients, refreshed
     * on its usual WP-Cron schedule) — deliberately NOT forced fresh here.
     * Forcing a live wordpress.org check on every call would add external
     * HTTP latency and a new failure mode to what's otherwise a fully
     * local, fast endpoint, for data this endpoint is expected to be polled
     * for occasionally (nightly), not on a tight loop. A site whose own
     * WP-Cron isn't reliably running (see this plugin's own extensive
     * scan-scheduling fix history) can have stale or entirely unpopulated
     * update-availability data here — reported honestly (false/null)
     * rather than guaranteed fresh. If reliably fresh data is needed,
     * that's a case for polling more often, not for this endpoint forcing
     * a live check on every request.
     */
    private static function collect_core(): array {
        $current = get_bloginfo( 'version' );
        $updates = get_core_updates( array( 'dismissed' => false ) );

        if ( ! is_array( $updates ) || empty( $updates ) || 'upgrade' !== ( $updates[0]->response ?? '' ) ) {
            return array(
                'version'          => $current,
                'update_available' => false,
                'new_version'      => null,
            );
        }

        return array(
            'version'          => $current,
            'update_available' => true,
            'new_version'      => $updates[0]->version,
        );
    }

    private static function collect_plugins(): array {
        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option( 'active_plugins', array() );
        // Network-activated plugins (multisite) aren't in the per-site
        // 'active_plugins' option at all — a plugin can be active for the
        // whole network without appearing there.
        $network_active = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();

        $update_data      = get_site_transient( 'update_plugins' );
        $updates_response = ( is_object( $update_data ) && isset( $update_data->response ) )
            ? $update_data->response
            : array();

        $auto_updates = (array) get_option( 'auto_update_plugins', array() );

        $plugins = array();
        foreach ( $all_plugins as $file => $data ) {
            $update_info = $updates_response[ $file ] ?? null;

            $plugins[] = array(
                'file'                => $file,
                'name'                => $data['Name'],
                'version'             => $data['Version'],
                'author'              => $data['AuthorName'] ?: $data['Author'],
                'plugin_uri'          => $data['PluginURI'],
                'active'              => in_array( $file, $active_plugins, true ),
                'network_active'      => isset( $network_active[ $file ] ),
                'update_available'    => null !== $update_info,
                'new_version'         => $update_info->new_version ?? null,
                'auto_update_enabled' => in_array( $file, $auto_updates, true ),
            );
        }

        return $plugins;
    }

    private static function collect_themes(): array {
        $all_themes        = wp_get_themes();
        $active_stylesheet = get_option( 'stylesheet' );

        $update_data      = get_site_transient( 'update_themes' );
        // Unlike update_plugins, WordPress core stores each theme's update
        // offer as a plain array, not an object — a real, longstanding
        // inconsistency between the two transients' shapes.
        $updates_response = ( is_object( $update_data ) && isset( $update_data->response ) )
            ? $update_data->response
            : array();

        $auto_updates = (array) get_option( 'auto_update_themes', array() );

        $themes = array();
        foreach ( $all_themes as $stylesheet => $theme ) {
            $update_info = $updates_response[ $stylesheet ] ?? null;

            $themes[] = array(
                'stylesheet'          => $stylesheet,
                'name'                => $theme->get( 'Name' ),
                'version'             => $theme->get( 'Version' ),
                'author'              => wp_strip_all_tags( $theme->get( 'Author' ) ),
                'theme_uri'           => $theme->get( 'ThemeURI' ),
                'active'              => $stylesheet === $active_stylesheet,
                'update_available'    => null !== $update_info,
                'new_version'         => $update_info['new_version'] ?? null,
                'auto_update_enabled' => in_array( $stylesheet, $auto_updates, true ),
            );
        }

        return $themes;
    }

    /**
     * Pending translation (language pack) updates for core, plugins, and
     * themes alike. Reuses WordPress's own wp_get_translation_updates()
     * rather than re-deriving this — it already aggregates all three from
     * their respective update transients' own 'translations' entries, the
     * same helper wp-admin's own update-core.php screen uses.
     */
    private static function collect_translations(): array {
        $updates = wp_get_translation_updates();
        if ( ! is_array( $updates ) ) {
            return array();
        }

        return array_map(
            static function ( $update ) {
                return array(
                    'type'     => $update->type ?? null,
                    'slug'     => $update->slug ?? null,
                    'language' => $update->language ?? null,
                    'version'  => $update->version ?? null,
                );
            },
            $updates
        );
    }
}

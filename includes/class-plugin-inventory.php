<?php
defined( 'ABSPATH' ) || exit;

/**
 * Collects the site's installed-plugin inventory for the REST API's
 * /plugins endpoint — name, version, active state, and update
 * availability, sourced entirely from WordPress core APIs (no external
 * network calls of its own).
 *
 * Deliberately its own endpoint/collector, separate from
 * RSD_RB_Server_Stats — this is expected to be polled on a much less
 * frequent cadence (e.g. nightly, to track plugin version changes over
 * time) than site-health stats, so it shouldn't bloat that response on
 * every health-check poll.
 */
class RSD_RB_Plugin_Inventory {

    public static function collect(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option( 'active_plugins', array() );
        // Network-activated plugins (multisite) aren't in the per-site
        // 'active_plugins' option at all — a plugin can be active for the
        // whole network without appearing there.
        $network_active = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();

        // Whatever WordPress's own update-check last found — NOT forced fresh
        // here. Forcing a live wordpress.org check on every call would add an
        // external HTTP dependency (latency, a new failure mode) to what's
        // otherwise a fully local, fast endpoint, for a field this plugin's
        // own consumer doesn't primarily need (the main ask is version +
        // active state, to diff over time) — and this is meant to be polled
        // occasionally, not on a tight loop. On a site whose own WP-Cron
        // isn't running reliably (see this plugin's own scan-scheduling
        // history), this transient can be stale or entirely unpopulated —
        // update_available/new_version simply reflect that honestly (false/
        // null) rather than being guaranteed fresh.
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

        return array(
            'site'       => home_url(),
            'checked_at' => wp_date( 'c' ),
            'plugins'    => $plugins,
        );
    }
}

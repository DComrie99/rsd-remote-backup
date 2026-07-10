<?php
defined( 'ABSPATH' ) || exit;

/**
 * Collects site/server health data for the REST API's /server-stats endpoint.
 *
 * Structured as a small collector registry so a future plugin-specific stat
 * (e.g. WooCommerce, a security plugin) is one new private method + one line
 * in get_plugin_collectors() — each collector is responsible for detecting
 * whether its own plugin is active and returning null if not, so collect()
 * never needs to know which plugins exist.
 */
class RSD_RB_Server_Stats {

    public static function collect(): array {
        $plugins = array();
        foreach ( self::get_plugin_collectors() as $key => $callback ) {
            $result = call_user_func( $callback );
            if ( null !== $result ) {
                $plugins[ $key ] = $result;
            }
        }

        return array(
            'site'      => home_url(),
            'timestamp' => wp_date( 'c' ),
            'core'      => self::collect_core(),
            'plugins'   => $plugins,
        );
    }

    /**
     * @return array<string, callable>
     */
    private static function get_plugin_collectors(): array {
        return array(
            'wp_rocket'       => array( __CLASS__, 'collect_wp_rocket' ),
            'wordfence'       => array( __CLASS__, 'collect_wordfence' ),
            'ai1wm_unlimited' => array( __CLASS__, 'collect_ai1wm_schedule' ),
        );
    }

    private static function collect_core(): array {
        global $wpdb;

        $disk_free  = @disk_free_space( ABSPATH );
        $disk_total = @disk_total_space( ABSPATH );

        return array(
            'php_version'          => phpversion(),
            'wp_version'           => get_bloginfo( 'version' ),
            'mysql_version'        => $wpdb->db_version(),
            'multisite'            => is_multisite(),
            'memory_limit'         => ini_get( 'memory_limit' ),
            'max_execution_time'   => (int) ini_get( 'max_execution_time' ),
            'disk_free_bytes'      => false !== $disk_free ? (int) $disk_free : null,
            'disk_total_bytes'     => false !== $disk_total ? (int) $disk_total : null,
            'active_theme'         => wp_get_theme()->get( 'Name' ),
            'active_plugins_count' => count( (array) get_option( 'active_plugins', array() ) ),
        );
    }

    /**
     * WP Rocket's "Rocket Insights" score is never stored as a single value —
     * WP Rocket itself computes it on demand as AVG(score) across its own
     * per-URL performance_monitoring table (see GlobalScore::calculate_global_score()
     * in WP Rocket 3.20+). Re-implemented here directly against that table
     * rather than depending on WP Rocket's Abilities API (wp-rocket/get-insights-scores),
     * which requires an authenticated wp-admin user and isn't reachable from a
     * REST request authenticated only with this plugin's own API key.
     *
     * Coupled to WP Rocket's internal, undocumented schema — if a future WP
     * Rocket version renames/restructures wpr_performance_monitoring, this
     * will start returning insights_available=false rather than erroring,
     * but the numbers will stop showing up until this is updated to match.
     *
     * @return array|null Null if WP Rocket is not active on this site.
     */
    private static function collect_wp_rocket(): ?array {
        if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
            return null;
        }

        global $wpdb;

        $table  = $wpdb->prefix . 'wpr_performance_monitoring';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

        if ( ! $exists ) {
            return array(
                'version'            => WP_ROCKET_VERSION,
                'insights_available' => false,
            );
        }

        $pages_monitored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $pages_completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );
        $global_score     = $wpdb->get_var(
            "SELECT ROUND(AVG(score)) FROM {$table} WHERE status = 'completed' AND score != 0"
        );

        return array(
            'version'            => WP_ROCKET_VERSION,
            'insights_available' => true,
            'global_score'       => null !== $global_score ? (int) $global_score : null,
            'pages_monitored'    => $pages_monitored,
            'pages_completed'    => $pages_completed,
        );
    }

    /**
     * Reimplements Wordfence's own "Firewall Summary" dashboard widget
     * (wfActivityReport::getBlockedCount(), see lib/wfActivityReport.php and
     * lib/dashboard/widget_localattacks.php in the Wordfence source) — same
     * grouping of blockType values into complex/brute_force/blocklist, same
     * SUM(blockCount) over the same 24h/7d/30d unixday windows, against the
     * same wfBlockedIPLog table Wordfence itself reads for that widget.
     *
     * Table name/case is network-wide (wfDB::networkTable() uses
     * $wpdb->base_prefix, not $wpdb->prefix — relevant on multisite) and its
     * case (CamelCase vs lowercase) depends on the 'wordfence_case' option,
     * set once at table-creation time based on that install's MySQL
     * case-sensitivity — replicated here rather than assumed.
     *
     * Coupled to Wordfence's internal, undocumented schema — if a future
     * Wordfence version changes wfBlockedIPLog's columns/blockType values,
     * this will start returning firewall_summary_available=false rather than
     * erroring, but will need updating to match to keep reporting numbers.
     *
     * @return array|null Null if Wordfence is not active on this site.
     */
    private static function collect_wordfence(): ?array {
        if ( ! defined( 'WORDFENCE_VERSION' ) ) {
            return null;
        }

        global $wpdb;

        $lowercase = (bool) get_option( 'wordfence_case' );
        $table     = $wpdb->base_prefix . ( $lowercase ? 'wfblockediplog' : 'wfBlockedIPLog' );
        $exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

        if ( ! $exists ) {
            return array(
                'version'                    => WORDFENCE_VERSION,
                'firewall_summary_available' => false,
            );
        }

        $groupings = array(
            'complex'     => array( 'fakegoogle', 'badpost', 'country', 'advanced', 'waf' ),
            'brute_force' => array( 'throttle', 'brute' ),
            'blocklist'   => array( 'blacklist', 'manual' ),
        );
        $windows = array( '24h' => 1, '7d' => 7, '30d' => 30 );

        $summary = array();
        $totals  = array( '24h' => 0, '7d' => 0, '30d' => 0 );

        foreach ( $groupings as $group_key => $block_types ) {
            $placeholders = implode( ', ', array_fill( 0, count( $block_types ), '%s' ) );
            $summary[ $group_key ] = array();

            foreach ( $windows as $window_key => $days ) {
                $query = $wpdb->prepare(
                    "SELECT SUM(blockCount) FROM {$table} WHERE unixday >= FLOOR(UNIX_TIMESTAMP(DATE_SUB(NOW(), interval %d day)) / 86400) AND blockType IN ({$placeholders})",
                    array_merge( array( $days ), $block_types )
                );
                $count = (int) $wpdb->get_var( $query );

                $summary[ $group_key ][ $window_key ] = $count;
                $totals[ $window_key ]                += $count;
            }
        }

        return array(
            'version'                    => WORDFENCE_VERSION,
            'firewall_summary_available' => true,
            'firewall_summary'           => $summary,
            'firewall_summary_totals'    => $totals,
        );
    }

    /**
     * All-in-One WP Migration's free/core plugin has no scheduling feature at
     * all — recurring/scheduled backups require the separate paid "Unlimited
     * Extension" (all-in-one-wp-migration-unlimited-extension). It stores every
     * configured schedule as an array of Ai1wmve_Schedule_Event objects in a
     * single option (AI1WMVE_SCHEDULES_OPTIONS = 'ai1wmve_schedule_events'; see
     * lib/vendor/servmask/pro/model/schedule/class-ai1wmve-schedule-event(s).php
     * in the extension's own source).
     *
     * Deliberately reads that option directly via get_option() rather than
     * instantiating Ai1wmve_Schedule_Events — that class's constructor WRITES
     * three default (disabled) schedule events to the database the first time
     * the option doesn't exist yet (create_default_events()), which is an
     * unwanted side effect for what should be a pure read.
     *
     * Deliberately omits Ai1wmve_Schedule_Event::password() (the backup
     * encryption password — base64-encoded, not a secure hash) and
     * excluded_files/excluded_db_tables/sites — none of that belongs in a
     * stats-reporting API.
     *
     * @return array|null Null if the Unlimited Extension is not active on this site.
     */
    private static function collect_ai1wm_schedule(): ?array {
        if ( ! defined( 'AI1WMVE_SCHEDULES_OPTIONS' ) ) {
            return null;
        }

        $version = '';
        if ( defined( 'AI1WMVE_PATH' ) ) {
            $main_file = AI1WMVE_PATH . '/all-in-one-wp-migration-unlimited-extension.php';
            if ( file_exists( $main_file ) ) {
                $version = get_file_data( $main_file, array( 'Version' => 'Version' ) )['Version'];
            }
        }

        $events = get_option( AI1WMVE_SCHEDULES_OPTIONS, array() );
        if ( ! is_array( $events ) ) {
            $events = array();
        }

        $schedules = array();
        foreach ( $events as $event ) {
            // Defensive: skip anything not a real Ai1wmve_Schedule_Event object
            // (e.g. __PHP_Incomplete_Class, if the extension's classes weren't
            // loaded yet when this option was unserialized for some reason).
            // Checked against is_enabled() specifically because it — unlike
            // title()/type()/storage()/retention() — is an explicitly declared
            // method rather than one resolved through the class's __call() magic
            // method; method_exists() does not account for __call, so checking
            // one of the magic-resolved accessors here would wrongly reject
            // every genuine event.
            if ( ! is_object( $event ) || ! method_exists( $event, 'is_enabled' ) ) {
                continue;
            }

            $schedules[] = array(
                'title'     => $event->title(),
                'type'      => $event->type(),
                'enabled'   => $event->is_enabled(),
                'repeating' => (bool) $event->repeating(),
                'period'    => $event->period(),
                'time'      => $event->time(),
                'storage'   => $event->storage(),
                'last_run'  => $event->last_run(),
                'retention' => $event->retention(),
            );
        }

        return array(
            'version'              => '' !== $version ? $version : null,
            'schedules_configured' => count( $schedules ),
            'schedules'            => $schedules,
        );
    }
}

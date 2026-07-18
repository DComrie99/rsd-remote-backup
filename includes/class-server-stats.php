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
            'site'                => home_url(),
            'plugin_version'      => RSD_RB_VERSION,
            'timestamp'           => wp_date( 'c' ),
            'core'                => self::collect_core(),
            'provider_connection' => self::collect_provider_connection(),
            'plugins'             => $plugins,
            'ssl'                 => self::collect_ssl(),
        );
    }

    /**
     * Live, uncached check of the currently configured cloud provider's
     * connection — a real API call every time this endpoint is hit, NOT a
     * reuse of ensure_folder()'s 1-hour folder-existence cache (which would
     * let a connection that went stale mid-cache-window still report
     * connected). Added after a live-site report: a OneDrive connection went
     * stale and reconnecting failed with "no valid secret" until the client
     * secret was manually re-entered — this surfaces that class of failure
     * proactively, on the CRM's own polling cadence, rather than only when
     * the next real upload attempt happens to hit it.
     */
    private static function collect_provider_connection(): array {
        $provider = RSD_RB_Plugin::get_instance()->get_active_provider();

        if ( ! $provider ) {
            return array(
                'provider'   => RSD_RB_Settings::get_provider(),
                'connected'  => false,
                'checked_at' => wp_date( 'c' ),
                'message'    => 'No provider configured.',
            );
        }

        if ( ! $provider->is_connected() ) {
            return array(
                'provider'   => $provider->key(),
                'connected'  => false,
                'checked_at' => wp_date( 'c' ),
                'message'    => 'Not connected — no OAuth tokens stored.',
            );
        }

        try {
            $provider->verify_connection();
            return array(
                'provider'   => $provider->key(),
                'connected'  => true,
                'checked_at' => wp_date( 'c' ),
                'message'    => null,
            );
        } catch ( RuntimeException $e ) {
            RSD_RB_Logger::error( 'Server-stats: provider connection check failed — ' . $e->getMessage() );
            return array(
                'provider'   => $provider->key(),
                'connected'  => false,
                'checked_at' => wp_date( 'c' ),
                'message'    => $e->getMessage(),
            );
        }
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
     * Checks this site's own SSL certificate by connecting to itself over
     * TLS, rather than relying on CRM_API's direct check from its own host.
     * CRM_API's host is Windows 10 LTSC 1809, whose Schannel stack caps at
     * TLS 1.2 with an old cipher list — some modern sites (TLS 1.3-only)
     * can never be checked that way regardless of CRM_API code changes.
     * Running the check here, on the WordPress server itself, sidesteps
     * that entirely: whatever TLS version this server's own stack supports
     * is exactly what a real visitor would get.
     *
     * Not gated behind any plugin-active check (unlike the collectors
     * above) — it's core to every site, so it's returned as its own
     * top-level 'ssl' key rather than folded into 'plugins'.
     *
     * @return array
     */
    private static function collect_ssl(): array {
        $host   = wp_parse_url( home_url(), PHP_URL_HOST );
        $scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );

        if ( ! $host || 'https' !== $scheme ) {
            return array(
                'checked'        => false,
                'status'         => 'unknown',
                'message'        => 'Site is not served over HTTPS.',
                'issued_to'      => null,
                'issuer'         => null,
                'valid_from'     => null,
                'valid_to'       => null,
                'days_remaining' => null,
            );
        }

        $context = stream_context_create(
            array(
                'ssl' => array(
                    'capture_peer_cert' => true,
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                ),
            )
        );

        $client = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ( ! $client ) {
            return array(
                'checked'        => false,
                'status'         => 'unknown',
                'message'        => "Could not connect to {$host}:443 to check the certificate ({$errstr}).",
                'issued_to'      => null,
                'issuer'         => null,
                'valid_from'     => null,
                'valid_to'       => null,
                'days_remaining' => null,
            );
        }

        $params = stream_context_get_params( $client );
        fclose( $client );

        $cert = ! empty( $params['options']['ssl']['peer_certificate'] )
            ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] )
            : false;

        if ( ! $cert ) {
            return array(
                'checked'        => false,
                'status'         => 'unknown',
                'message'        => "No certificate was presented by {$host}.",
                'issued_to'      => null,
                'issuer'         => null,
                'valid_from'     => null,
                'valid_to'       => null,
                'days_remaining' => null,
            );
        }

        $valid_to_ts    = (int) $cert['validTo_time_t'];
        $valid_from_ts  = (int) $cert['validFrom_time_t'];
        $days_remaining = (int) floor( ( $valid_to_ts - time() ) / DAY_IN_SECONDS );
        $issued_to      = $cert['subject']['CN'] ?? $host;
        $issuer         = $cert['issuer']['O'] ?? ( $cert['issuer']['CN'] ?? 'Unknown' );

        // Connecting to ourselves should always match, but a misconfigured
        // cert (wrong vhost served, wildcard mismatch) is real enough to be
        // worth surfacing rather than assumed away.
        $mismatch = ! self::cert_matches_host( $cert, $host );

        if ( $mismatch ) {
            $status  = 'error';
            $message = "Certificate served for {$host} does not match its hostname (issued to {$issued_to}).";
        } elseif ( $days_remaining < 0 ) {
            $status  = 'error';
            $message = "Certificate for {$host} expired on " . gmdate( 'Y-m-d', $valid_to_ts ) . '.';
        } elseif ( $days_remaining <= 14 ) {
            $status  = 'warning';
            $message = "Certificate for {$host} expires in {$days_remaining} day(s), on " . gmdate( 'Y-m-d', $valid_to_ts ) . '.';
        } else {
            $status  = 'ok';
            $message = "Certificate for {$host} is valid until " . gmdate( 'Y-m-d', $valid_to_ts ) . '.';
        }

        return array(
            'checked'        => true,
            'status'         => $status,
            'message'        => $message,
            'issued_to'      => $issued_to,
            'issuer'         => $issuer,
            'valid_from'     => wp_date( 'c', $valid_from_ts ),
            'valid_to'       => wp_date( 'c', $valid_to_ts ),
            'days_remaining' => $days_remaining,
        );
    }

    /**
     * Checks a parsed certificate's subjectAltName DNS entries (falling back
     * to the subject CN if there's no SAN extension at all) against $host,
     * with basic single-level wildcard support (`*.example.com`). Doesn't
     * need to be exhaustive — this guards a rare misconfiguration case, not
     * the primary path (connecting to ourselves should always match).
     *
     * @param array<string, mixed> $cert Parsed certificate from openssl_x509_parse().
     * @param string               $host Hostname to match against.
     */
    private static function cert_matches_host( array $cert, string $host ): bool {
        $names = array();

        if ( ! empty( $cert['extensions']['subjectAltName'] ) ) {
            foreach ( explode( ',', $cert['extensions']['subjectAltName'] ) as $entry ) {
                $entry = trim( $entry );
                if ( 0 === stripos( $entry, 'DNS:' ) ) {
                    $names[] = strtolower( trim( substr( $entry, 4 ) ) );
                }
            }
        } elseif ( ! empty( $cert['subject']['CN'] ) ) {
            $names[] = strtolower( $cert['subject']['CN'] );
        }

        $host = strtolower( $host );

        foreach ( $names as $name ) {
            if ( $name === $host ) {
                return true;
            }

            if ( 0 === strpos( $name, '*.' ) ) {
                $suffix = substr( $name, 1 ); // ".example.com"
                if ( substr( $host, -strlen( $suffix ) ) === $suffix && substr_count( $host, '.' ) === substr_count( $name, '.' ) ) {
                    return true;
                }
            }
        }

        return false;
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
     * Includes the raw `schedule` config (interval/weekday/day/hour/minute/n —
     * e.g. day-of-month for a Monthly schedule) alongside the human-readable
     * `period`/`time` summary, plus the actual run history (`log`): each
     * event's last-run OPTION only ever stores a bare status string (Success/
     * Failed/None), not a timestamp, so it can't answer "did this run when it
     * was supposed to." The separate Ai1wmve_Schedule_Event_Log option (up to
     * 30 records/event, newest first — see
     * lib/vendor/servmask/pro/model/schedule/class-ai1wmve-schedule-event-log.php)
     * DOES have a real timestamp per run, which is what actually lets someone
     * compare "configured to run on the 1st at 01:00" against "when it
     * actually ran" to catch a flaky/stalled scheduler. Read directly via
     * get_option() (same reasoning as the main events option below — the log
     * class's own constructor has no side effect, but bypassing it entirely
     * keeps this consistent with the rest of this method and avoids depending
     * on the extension's classes for anything more than strictly necessary).
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

            $log_key     = Ai1wmve_Schedule_Event::option_key( 'log', $event->event_id() );
            $log_records = get_option( $log_key, array() );
            if ( ! is_array( $log_records ) ) {
                $log_records = array();
            }

            $schedules[] = array(
                'title'     => $event->title(),
                'type'      => $event->type(),
                'enabled'   => $event->is_enabled(),
                'repeating' => (bool) $event->repeating(),
                'period'    => $event->period(),
                'time'      => $event->time(),
                'schedule'  => $event->schedule(),
                'storage'   => $event->storage(),
                'last_run'  => $event->last_run(),
                'retention' => $event->retention(),
                'log'       => array_map(
                    static function ( $record ) {
                        return array(
                            'time'    => isset( $record['time'] ) ? wp_date( 'c', (int) $record['time'] ) : null,
                            'status'  => $record['status'] ?? null,
                            'message' => $record['message'] ?? null,
                        );
                    },
                    $log_records
                ),
            );
        }

        return array(
            'version'              => '' !== $version ? $version : null,
            'schedules_configured' => count( $schedules ),
            'schedules'            => $schedules,
        );
    }
}

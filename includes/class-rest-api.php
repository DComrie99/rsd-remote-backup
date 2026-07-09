<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API for the RSD Remote Backup plugin.
 *
 * Endpoints:
 *   GET  /wp-json/rsd-rb/v1/status        — job queue + site/provider metadata
 *   POST /wp-json/rsd-rb/v1/trigger       — run scanner and schedule pending uploads
 *   POST /wp-json/rsd-rb/v1/resync        — reconcile DB records against remote + local reality
 *   GET  /wp-json/rsd-rb/v1/server-stats  — core WP/server health + plugin-specific stats (e.g. WP Rocket Insights)
 *
 * Authentication: send the raw API key in either header:
 *   X-RSD-API-Key: <key>
 *   Authorization: Bearer <key>
 *
 * The stored value is a SHA-256 hash of the key, so a DB leak does not expose
 * a usable credential. The raw key is shown once in the admin UI for 1 hour
 * after generation or regeneration (see get_reveal_key()).
 *
 * Security properties:
 *   - Key generated with wp_generate_password() (openssl_random_pseudo_bytes).
 *   - Stored as SHA-256 hash; raw key never persisted.
 *   - Compared with hash_equals() to prevent timing attacks.
 *   - HTTPS required; requests over plain HTTP are rejected.
 *   - Response contains no credentials, tokens, or local file paths.
 *   - One key per WordPress site (per-site isolation).
 */
class RSD_RB_Rest_Api {

    const NAMESPACE = 'rsd-rb/v1';

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/status', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'get_status' ),
            'permission_callback' => array( __CLASS__, 'check_api_key' ),
        ) );

        register_rest_route( self::NAMESPACE, '/trigger', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'trigger_upload' ),
            'permission_callback' => array( __CLASS__, 'check_api_key' ),
        ) );

        register_rest_route( self::NAMESPACE, '/resync', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'resync' ),
            'permission_callback' => array( __CLASS__, 'check_api_key' ),
        ) );

        register_rest_route( self::NAMESPACE, '/server-stats', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'get_server_stats' ),
            'permission_callback' => array( __CLASS__, 'check_api_key' ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Key management helpers (used by activator + plugin admin action)

    /**
     * Generate a new raw API key, store its SHA-256 hash, and put the raw value
     * where the admin can copy it from the settings page for the next hour.
     */
    public static function generate_and_store_key(): void {
        $raw    = wp_generate_password( 32, false );
        $hashed = hash( 'sha256', $raw );
        update_option( 'rsd_rb_api_key', $hashed, false );
        // Plain option with a manually-checked expiry, not a transient — see the
        // matching note on RSD_RB_OAuth::create_state(). A transient here would
        // vanish immediately (rather than after the intended 1-hour window) on a
        // site whose external object cache doesn't actually persist values.
        update_option( 'rsd_rb_api_key_reveal', array(
            'key'     => $raw,
            'expires' => time() + HOUR_IN_SECONDS,
        ), false );
    }

    /**
     * Returns the raw key if still within its one-time reveal window, else ''.
     */
    public static function get_reveal_key(): string {
        $stored = get_option( 'rsd_rb_api_key_reveal', null );
        if ( ! is_array( $stored ) || empty( $stored['key'] ) || empty( $stored['expires'] ) ) {
            return '';
        }
        if ( time() > (int) $stored['expires'] ) {
            delete_option( 'rsd_rb_api_key_reveal' ); // opportunistic cleanup, not load-bearing
            return '';
        }
        return (string) $stored['key'];
    }

    // -------------------------------------------------------------------------
    // Authentication

    /**
     * Validate the API key from X-RSD-API-Key or Authorization: Bearer headers.
     *
     * @return true|WP_Error
     */
    public static function check_api_key( WP_REST_Request $request ) {
        // Enforce HTTPS — the key must not travel over plain HTTP.
        if ( ! is_ssl() ) {
            return new WP_Error(
                'rsd_rb_https_required',
                'This endpoint requires HTTPS.',
                array( 'status' => 403 )
            );
        }

        // Extract key from header.
        $key = $request->get_header( 'X-RSD-API-Key' );
        if ( ! $key ) {
            $auth = $request->get_header( 'Authorization' );
            if ( $auth && 0 === strpos( $auth, 'Bearer ' ) ) {
                $key = substr( $auth, 7 );
            }
        }

        $stored = get_option( 'rsd_rb_api_key', '' );

        if ( '' === $stored ) {
            return new WP_Error(
                'rsd_rb_unauthorized',
                'Invalid or missing API key.',
                array( 'status' => 401 )
            );
        }

        // Migration: if the stored value is a legacy plaintext key (not a 64-char
        // lowercase hex SHA-256 hash), re-hash it silently. Existing CRM connections
        // keep working because they send the same raw key, which now hashes correctly.
        if ( ! preg_match( '/^[0-9a-f]{64}$/', $stored ) ) {
            $stored = hash( 'sha256', $stored );
            update_option( 'rsd_rb_api_key', $stored, false );
        }

        if ( ! $key || ! hash_equals( $stored, hash( 'sha256', $key ) ) ) {
            return new WP_Error(
                'rsd_rb_unauthorized',
                'Invalid or missing API key.',
                array( 'status' => 401 )
            );
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // GET /status

    public static function get_status( WP_REST_Request $request ): WP_REST_Response {
        $jobs      = RSD_RB_Queue::get_jobs( null, 100 );
        $next_scan = wp_next_scheduled( 'rsd_rb_scan' );
        $last_scan = get_option( 'rsd_rb_last_scan', null );

        $formatted = array_map( array( __CLASS__, 'format_job' ), $jobs );

        return new WP_REST_Response( array(
            'site'           => home_url(),
            'plugin_version' => RSD_RB_VERSION,
            'provider'       => RSD_RB_Settings::get_provider(),
            'last_scan'      => $last_scan,
            'next_scan'      => $next_scan ? wp_date( 'c', $next_scan ) : null,
            'jobs'           => $formatted,
        ), 200 );
    }

    // -------------------------------------------------------------------------
    // POST /trigger

    public static function trigger_upload( WP_REST_Request $request ): WP_REST_Response {
        if ( ! RSD_RB_License::is_valid() ) {
            return new WP_REST_Response( array( 'error' => 'Plugin is not licensed.' ), 403 );
        }

        $reset = RSD_RB_Queue::reset_stalled();
        if ( $reset > 0 ) {
            RSD_RB_Logger::warning( 'REST trigger: reset ' . $reset . ' stalled job(s) to pending.' );
        }

        RSD_RB_Backup_Scanner::run();
        update_option( 'rsd_rb_last_scan', wp_date( 'c' ), false );

        $provider = RSD_RB_Settings::get_provider();
        $count    = RSD_RB_Upload_Worker::schedule_all_pending( $provider );

        RSD_RB_Logger::info( 'REST API trigger: ' . $count . ' upload job(s) scheduled.' );

        return new WP_REST_Response( array(
            'scheduled'     => $count,
            'stalled_reset' => $reset,
            'message'       => $count . ' upload job(s) scheduled.',
        ), 200 );
    }

    // -------------------------------------------------------------------------
    // GET /server-stats

    public static function get_server_stats( WP_REST_Request $request ): WP_REST_Response {
        if ( ! RSD_RB_License::is_valid() ) {
            return new WP_REST_Response( array( 'error' => 'Plugin is not licensed.' ), 403 );
        }

        return new WP_REST_Response( RSD_RB_Server_Stats::collect(), 200 );
    }

    // -------------------------------------------------------------------------
    // Helpers

    // -------------------------------------------------------------------------
    // POST /resync

    public static function resync( WP_REST_Request $request ): WP_REST_Response {
        if ( ! RSD_RB_License::is_valid() ) {
            return new WP_REST_Response( array( 'error' => 'Plugin is not licensed.' ), 403 );
        }

        try {
            $result = self::run_resync();
        } catch ( RuntimeException $e ) {
            return new WP_REST_Response( array( 'error' => $e->getMessage() ), 502 );
        }

        $all_jobs_fresh = RSD_RB_Queue::get_all_jobs( 1000 );
        $formatted      = array_map( array( __CLASS__, 'format_job' ), $all_jobs_fresh );

        return new WP_REST_Response( array(
            'updated'            => $result['updated'],
            'created'            => $result['created'],
            'orphaned'           => $result['orphaned'],
            'duplicates_removed' => $result['duplicates_removed'],
            'backfilled'         => $result['backfilled'],
            'size_synced'        => $result['size_synced'],
            'jobs'               => $formatted,
        ), 200 );
    }

    /**
     * Core resync logic — shared by the REST endpoint and both admin actions
     * (Settings tab "Resync" and the Backups screen's "Refresh from provider").
     *
     * @return array{updated:int, created:int, orphaned:int, duplicates_removed:int, backfilled:int, size_synced:int, orphaned_manifest_ids:int[]}
     * @throws RuntimeException if the remote listing fails.
     */
    public static function run_resync(): array {
        // Guarded here too (not just in the REST resync() wrapper above) because
        // the admin page's "Resync" and "Refresh from provider" buttons call this
        // shared logic directly, bypassing the REST endpoint entirely.
        if ( ! RSD_RB_License::is_valid() ) {
            RSD_RB_Logger::warning( 'Resync skipped — no valid license.' );
            return array(
                'updated'               => 0,
                'created'               => 0,
                'orphaned'              => 0,
                'duplicates_removed'    => 0,
                'backfilled'            => 0,
                'size_synced'           => 0,
                'orphaned_manifest_ids' => array(),
            );
        }

        // Index local backup files.
        $source      = RSD_RB_Settings::get_backup_source_config();
        $local_raw   = is_dir( $source['dir'] )
            ? glob( trailingslashit( $source['dir'] ) . '*.' . $source['ext'] )
            : array();
        $local_names = array_flip( array_map( 'basename', is_array( $local_raw ) ? $local_raw : array() ) );

        // Fetch all DB jobs and group by provider so we query each provider once.
        $all_jobs         = RSD_RB_Queue::get_all_jobs( 1000 );
        $distinct_providers = array_unique( array_column( $all_jobs, 'provider' ) );

        // Build per-provider remote index: [provider_key => ['by_name'=>[], 'by_id'=>[]]].
        $remote_index = array();
        foreach ( $distinct_providers as $provider_key ) {
            $adapter = RSD_RB_Plugin::get_instance()->get_provider( $provider_key );
            if ( ! $adapter || ! $adapter->is_connected() ) {
                RSD_RB_Logger::warning( 'Resync: provider "' . $provider_key . '" not connected — skipping remote check for its jobs.' );
                continue;
            }
            try {
                $remote_files = $adapter->list_backups();
            } catch ( RuntimeException $e ) {
                RSD_RB_Logger::error( 'Resync: could not list remote files for "' . $provider_key . '" — ' . $e->getMessage() );
                continue;
            }

            RSD_RB_Logger::info( 'Resync: ' . $provider_key . ' returned ' . count( $remote_files ) . ' file(s): '
                . implode( ', ', array_column( $remote_files, 'name' ) ) );

            $by_name = array();
            $by_id   = array();
            foreach ( $remote_files as $rf ) {
                $by_name[ $rf['name'] ] = $rf;
                $by_id[ $rf['id'] ]     = $rf;
            }
            $remote_index[ $provider_key ] = array( 'by_name' => $by_name, 'by_id' => $by_id );
        }

        // Refresh location on every DB record using its own provider's index.
        $updated    = 0;
        $orphaned   = 0;
        $backfilled = 0;
        $orphaned_manifest_ids = array();

        foreach ( $all_jobs as $job ) {
            // A job with no recorded filepath at all (always true for one created
            // via enqueue_remote() — discovered purely on the remote side, with no
            // local file at the time) can never resolve as "local", even once a
            // real file reappears on disk under its filename (e.g. after Download
            // & prepare stages it back into place) — empty() short-circuits before
            // file_exists() is ever reached. Repair it once we can positively
            // confirm where the file actually is.
            if ( empty( $job['filepath'] ) ) {
                $expected_path = trailingslashit( $source['dir'] ) . $job['filename'];
                if ( file_exists( $expected_path ) ) {
                    RSD_RB_Queue::set_filepath( (int) $job['id'], $expected_path );
                    $job['filepath'] = $expected_path;
                }
            }

            // Use the stored filepath for existing records — more reliable than re-globbing.
            $in_local               = ! empty( $job['filepath'] ) && file_exists( $job['filepath'] );
            $effective_manifest_id = (int) ( $job['manifest_id'] ?? 0 );

            // Independent of remote connectivity — the local plain .wpress file,
            // when present, is authoritative for "original size" no matter what
            // any manifest row currently says, and doesn't need list_backups() to
            // confirm anything. Deliberately runs even if the remote listing below
            // fails or the provider isn't connected, so a remote-side hiccup can
            // never block this self-heal (a manifest that was ever created without
            // knowing the true original size, e.g. a compressed backup discovered
            // purely via list_backups(), or a manifest that just lost track of it).
            if ( $in_local && $effective_manifest_id ) {
                RSD_RB_Manifest::sync_from_local_file( $effective_manifest_id, $job['filepath'] );
            }

            $provider_key  = $job['provider'];
            $remote_record = null;

            if ( isset( $remote_index[ $provider_key ] ) ) {
                $by_name   = $remote_index[ $provider_key ]['by_name'];
                $by_id     = $remote_index[ $provider_key ]['by_id'];
                $by_name_match = isset( $by_name[ $job['filename'] ] );
                $by_id_match   = ! empty( $job['remote_id'] ) && isset( $by_id[ $job['remote_id'] ] );
                $in_remote     = $by_name_match || $by_id_match;
                $remote_record = $by_id_match ? $by_id[ $job['remote_id'] ] : ( $by_name_match ? $by_name[ $job['filename'] ] : null );

                // Self-heal: a job matched by its OWN remote_id (assigned only by
                // this job's own upload completing) but sitting in pending/failed
                // with an error is a job a stale duplicate worker run clobbered
                // after the real upload already succeeded — see the "file missing"
                // race documented on RSD_RB_Upload_Worker::schedule(). The remote
                // copy is the proof the upload finished; restore the status a
                // legitimate on_complete() would have set.
                if ( $by_id_match && RSD_RB_Queue::STATUS_COMPLETE !== $job['status'] ) {
                    $stale_status = $job['status'];
                    RSD_RB_Queue::mark_complete( (int) $job['id'], (string) $job['remote_id'], (int) $remote_record['size'] );
                    $job['status'] = RSD_RB_Queue::STATUS_COMPLETE;
                    RSD_RB_Logger::info( 'Resync: job #' . $job['id'] . ' ("' . $job['filename'] . '") was ' . $stale_status . ' with a confirmed remote copy — restored to complete.' );
                }

                if ( ! $in_remote && 'complete' === $job['status'] ) {
                    RSD_RB_Logger::info( sprintf(
                        'Resync: job #%d (%s) not found remotely — DB filename="%s", DB remote_id="%s", by_name_match=%s, by_id_match=%s',
                        $job['id'], $provider_key,
                        $job['filename'],
                        $job['remote_id'] ?? 'NULL',
                        $by_name_match ? 'yes' : 'no',
                        $by_id_match   ? 'yes' : 'no'
                    ) );
                }
            } else {
                // Provider not connected, or its listing failed — cannot verify
                // remote presence this run, so location/backfill (both of which
                // NEED that confirmation) are left for the next successful resync.
                continue;
            }

            // Diagnostic: a job confirmed present remotely but NOT found locally,
            // for a completed backup, is worth seeing the path check for — mirror
            // of the "not found remotely" log above, added to pin down cases where
            // job.filepath doesn't match where a restaged file actually landed
            // (e.g. after Download & prepare). Deliberately avoids logging the raw
            // path/filename strings — a long unbroken filename can trip the
            // logger's secret-redaction heuristic and hide the whole line.
            if ( ! $in_local && $in_remote && RSD_RB_Queue::STATUS_COMPLETE === $job['status'] ) {
                $expected_path = trailingslashit( $source['dir'] ) . $job['filename'];
                RSD_RB_Logger::info( sprintf(
                    'Resync: job #%d not found locally. DB filepath exists=%s (%d chars). Filename-based path exists=%s (%d chars). Paths match=%s.',
                    $job['id'],
                    file_exists( $job['filepath'] ) ? 'yes' : 'no',
                    strlen( $job['filepath'] ),
                    file_exists( $expected_path ) ? 'yes' : 'no',
                    strlen( $expected_path ),
                    $job['filepath'] === $expected_path ? 'yes' : 'no'
                ) );
            }

            $new_location = self::resolve_location( $in_local, $in_remote );
            if ( $new_location !== ( $job['location'] ?? '' ) ) {
                RSD_RB_Queue::update_location( (int) $job['id'], $new_location );
                ++$updated;
            }
            if ( RSD_RB_Queue::LOCATION_NONE === $new_location ) {
                ++$orphaned;
                if ( ! empty( $job['manifest_id'] ) ) {
                    $orphaned_manifest_ids[] = (int) $job['manifest_id'];
                }
            }

            // Backfill a manifest row for a job that finished uploading before
            // this table existed (e.g. every backup uploaded pre-compression) —
            // otherwise it never appears on the Backups screen, which reads only
            // the manifest table. Only once the remote copy is confirmed present.
            if ( empty( $effective_manifest_id ) && RSD_RB_Queue::STATUS_COMPLETE === $job['status'] && $in_remote && $remote_record ) {
                $original_name = self::strip_compression_extension( $remote_record['name'] );
                $is_compressed = $original_name !== $remote_record['name'];
                $effective_manifest_id = RSD_RB_Manifest::create_from_legacy_job(
                    $job,
                    $remote_record['name'],
                    $is_compressed,
                    $is_compressed ? self::guess_compression_method( $remote_record['name'] ) : null
                );
                RSD_RB_Queue::set_manifest_id( (int) $job['id'], $effective_manifest_id );
                ++$backfilled;
                RSD_RB_Logger::info( 'Resync: backfilled manifest #' . $effective_manifest_id . ' for job #' . $job['id'] . ' ("' . $job['filename'] . '") — completed before the Backups screen existed.' );

                // Just linked — sync its size/checksum too rather than waiting for
                // the next resync run to reach it via the check above.
                if ( $in_local ) {
                    RSD_RB_Manifest::sync_from_local_file( $effective_manifest_id, $job['filepath'] );
                }
            }
        }

        // Remove duplicate rows: a job with no local file whose remote_id is
        // already claimed by a different job for the same provider is the same
        // physical remote object tracked twice — e.g. a compressed upload's
        // ".gz" name was previously (incorrectly) treated as a brand new backup
        // by the loop below, alongside the original job that actually uploaded
        // it. Keep whichever job has richer data (a manifest link, or a local
        // filepath); delete the rest.
        $duplicates_removed = self::remove_duplicate_jobs( $all_jobs );
        if ( $duplicates_removed > 0 ) {
            $all_jobs = RSD_RB_Queue::get_all_jobs( 1000 ); // Re-fetch — the set below must not include deleted rows.
        }

        // Insert records (+ a manifest row, so they appear on the Backups screen)
        // for remote files with no DB row at all. A remote name that's just a
        // compressed counterpart of a job we already know about (matched by
        // remote_id, or — as a fallback for jobs missing a remote_id — by
        // stripping a known compression extension and matching the original
        // filename) is NOT a new backup and must not be re-created here.
        $created       = 0;
        $db_filenames   = array_flip( array_column( $all_jobs, 'filename' ) );
        $db_remote_ids  = array_flip( array_filter( array_column( $all_jobs, 'remote_id' ) ) );

        foreach ( $remote_index as $provider_key => $index ) {
            foreach ( $index['by_name'] as $name => $rf ) {
                if ( isset( $db_remote_ids[ $rf['id'] ] ) || isset( $db_filenames[ $name ] ) ) {
                    continue;
                }

                $original_name = self::strip_compression_extension( $name );
                if ( $original_name !== $name && isset( $db_filenames[ $original_name ] ) ) {
                    continue;
                }

                // Authoritative final guard, checked right before creating anything —
                // db_filenames/db_remote_ids above are snapshots and could theoretically
                // be stale. Creating the manifest before confirming the job insert will
                // actually happen risks leaving an orphaned, size-less manifest behind
                // (a compressed backup's manifest never learns its true original size
                // from list_backups() alone — only the local file has that), which then
                // shows up confusingly on the Backups screen with nothing pointing to it.
                if ( RSD_RB_Queue::file_is_known( $original_name, $provider_key ) ) {
                    continue;
                }

                $is_compressed = $original_name !== $name;
                $manifest_id   = RSD_RB_Manifest::create_from_remote(
                    $original_name,
                    $provider_key,
                    (int) $rf['size'],
                    $is_compressed,
                    $is_compressed ? self::guess_compression_method( $name ) : null,
                    $rf['id']
                );
                $inserted = RSD_RB_Queue::enqueue_remote( $original_name, (int) $rf['size'], $provider_key, $rf['id'], $manifest_id );
                if ( $inserted ) {
                    ++$created;
                } else {
                    RSD_RB_Logger::warning( 'Resync: enqueue_remote raced for "' . $original_name . '" — manifest #' . $manifest_id . ' created but left unlinked.' );
                }
            }
        }

        // Repopulate any manifest row still missing its original size by matching
        // its recorded filename directly against the backup source directory.
        // This is what reaches a manifest with NO job link at all (an orphan —
        // e.g. from the create_from_remote() bug fixed in v0.4.4) — the per-job
        // loop above only ever syncs manifests reachable by iterating jobs.
        $size_synced = 0;
        $backup_dir  = trailingslashit( $source['dir'] );
        foreach ( RSD_RB_Manifest::get_needing_size_sync() as $manifest_row ) {
            $candidate = $backup_dir . $manifest_row['original_filename'];
            if ( file_exists( $candidate ) ) {
                RSD_RB_Manifest::sync_from_local_file( (int) $manifest_row['id'], $candidate );
                ++$size_synced;
            }
        }

        RSD_RB_Logger::info( sprintf(
            'Resync: %d record(s) updated, %d duplicate(s) removed, %d remote-only record(s) created, %d legacy manifest(s) backfilled, %d size(s) repopulated from local files, %d orphan(s) found.',
            $updated, $duplicates_removed, $created, $backfilled, $size_synced, $orphaned
        ) );

        return array(
            'updated'               => $updated,
            'created'               => $created,
            'orphaned'              => $orphaned,
            'duplicates_removed'    => $duplicates_removed,
            'backfilled'            => $backfilled,
            'size_synced'           => $size_synced,
            'orphaned_manifest_ids' => $orphaned_manifest_ids,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers

    /** Public: reused by the Backups admin screen, which has no stored location column of its own and derives one live. */
    public static function resolve_location( bool $in_local, bool $in_remote ): string {
        if ( $in_local && $in_remote ) { return RSD_RB_Queue::LOCATION_BOTH; }
        if ( $in_local )               { return RSD_RB_Queue::LOCATION_LOCAL; }
        if ( $in_remote )              { return RSD_RB_Queue::LOCATION_REMOTE; }
        return RSD_RB_Queue::LOCATION_NONE;
    }

    /**
     * Delete jobs that track the exact same remote object (same provider +
     * remote_id) as another job — keeping the one with the most complete data
     * (a manifest link, then a local filepath, then the oldest by id).
     *
     * @param array[] $all_jobs
     * @return int Number of duplicate rows deleted.
     */
    private static function remove_duplicate_jobs( array $all_jobs ): int {
        $groups = array();
        foreach ( $all_jobs as $job ) {
            if ( empty( $job['remote_id'] ) ) {
                continue;
            }
            $key = $job['provider'] . '::' . $job['remote_id'];
            $groups[ $key ][] = $job;
        }

        $removed = 0;
        foreach ( $groups as $group ) {
            if ( count( $group ) < 2 ) {
                continue;
            }
            usort( $group, static function ( $a, $b ) {
                $score = static function ( $j ) {
                    return ( ! empty( $j['manifest_id'] ) ? 2 : 0 ) + ( ! empty( $j['filepath'] ) ? 1 : 0 );
                };
                $diff = $score( $b ) - $score( $a );
                return 0 !== $diff ? $diff : ( (int) $a['id'] - (int) $b['id'] );
            } );

            $keep = array_shift( $group );
            foreach ( $group as $dupe ) {
                RSD_RB_Queue::delete( (int) $dupe['id'] );
                ++$removed;
                RSD_RB_Logger::info( 'Resync: removed duplicate job #' . $dupe['id'] . ' ("' . $dupe['filename'] . '") — same remote object as job #' . $keep['id'] . ' ("' . $keep['filename'] . '").' );
            }
        }

        return $removed;
    }

    /** Strip a known compression extension, e.g. "backup.wpress.gz" → "backup.wpress". Returns the input unchanged if it has none. */
    private static function strip_compression_extension( string $filename ): string {
        foreach ( array( '.gz', '.zip' ) as $ext ) {
            if ( strlen( $filename ) > strlen( $ext ) && substr( $filename, -strlen( $ext ) ) === $ext ) {
                return substr( $filename, 0, -strlen( $ext ) );
            }
        }
        return $filename;
    }

    /**
     * A ".gz"/".zip" extension identifies the on-disk FORMAT, not which specific
     * tool produced it — RSD_RB_Compressor::decompress() dispatches on format,
     * not tool identity, so this is a safe, format-based inference for a backup
     * this plugin never actually compressed itself.
     */
    private static function guess_compression_method( string $remote_name ): ?string {
        if ( '.gz' === substr( $remote_name, -3 ) ) {
            return 'native-gzip';
        }
        if ( '.zip' === substr( $remote_name, -4 ) ) {
            return 'native-zip';
        }
        return null;
    }

    /**
     * Format a job row for the API response.
     * Deliberately excludes: filepath (local path), session_url (resumable upload URL),
     * and — from the manifest — local paths and checksums. Compression reporting is
     * exposed here (rather than only in the flat admin log) so the CRM can query
     * compression effectiveness per-site across the fleet.
     */
    private static function format_job( array $job ): array {
        $filesize    = (int) $job['filesize'];
        $bytes_sent  = (int) $job['bytes_sent'];
        $is_complete = RSD_RB_Queue::STATUS_COMPLETE === $job['status'];
        $pct         = $is_complete ? 100 : ( $filesize > 0 ? round( ( $bytes_sent / $filesize ) * 100 ) : 0 );

        $manifest = ! empty( $job['manifest_id'] ) ? RSD_RB_Manifest::get( (int) $job['manifest_id'] ) : null;

        return array(
            'id'           => (int) $job['id'],
            'filename'     => $job['filename'],
            'status'       => $job['status'],
            'location'     => $job['location'] ?? RSD_RB_Queue::LOCATION_LOCAL,
            'filesize'     => $filesize,
            'bytes_sent'   => $bytes_sent,
            'progress_pct' => $pct,
            'provider'     => $job['provider'],
            'remote_id'    => $job['remote_id'],
            'attempts'     => (int) $job['attempts'],
            'last_error'   => $job['last_error'] ?: null,
            'created_at'   => $job['created_at'],
            'updated_at'   => $job['updated_at'],
            'compression'  => $manifest ? array(
                'pipeline_status'       => $manifest['status'],
                'method'                => $manifest['compression_method'],
                'compressed_size_bytes' => null !== $manifest['compressed_size_bytes'] ? (int) $manifest['compressed_size_bytes'] : null,
                'ratio'                 => null !== $manifest['compression_ratio'] ? (float) $manifest['compression_ratio'] : null,
                'time_ms'               => null !== $manifest['compression_time_ms'] ? (int) $manifest['compression_time_ms'] : null,
                'remote_is_compressed'  => (bool) $manifest['remote_is_compressed'],
            ) : null,
        );
    }
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates chunked, resumable uploads via Action Scheduler (preferred)
 * or a WP-Cron single event (fallback when AS is not available).
 *
 * Per invocation budget: stop after RSD_RB_WORKER_TIME_BUDGET seconds so
 * we finish well inside PHP's max_execution_time.  The next scheduled action
 * picks up from bytes_sent.
 */
class RSD_RB_Upload_Worker {

    /** Fallback seconds per invocation (overridden by rsd_rb_time_budget setting). */
    const TIME_BUDGET = 60;

    /** Seconds to wait between retry attempts (doubles each time). */
    const BACKOFF_BASE = 60;

    /** Seconds to wait before re-checking the concurrent-upload cap. */
    const CONCURRENCY_RETRY_DELAY = 30;

    /** Action Scheduler hook name. */
    const AS_HOOK = 'rsd_rb_process_job';

    /** WP-Cron hook used as AS fallback. */
    const CRON_HOOK = 'rsd_rb_process_job_cron';

    // -------------------------------------------------------------------------
    // Scheduling

    /**
     * Schedule processing of a specific job as soon as possible.
     * Prefers Action Scheduler; falls back to wp_schedule_single_event.
     *
     * Guards against double-scheduling: if a scan/trigger runs again while a
     * job still has a pending or in-progress action outstanding (e.g. two
     * manual /trigger calls before the first worker run finishes), queuing a
     * second one lets it fire after the job already completed and deleted
     * its local file — the stale run then sees "file missing" and flips an
     * already-complete job back to pending.
     */
    public static function schedule( int $job_id ): void {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            $existing = self::find_existing_actions( $job_id );
            if ( ! empty( $existing ) ) {
                foreach ( $existing as $existing_id => $existing_status ) {
                    RSD_RB_Logger::info( sprintf(
                        'Upload worker: job #%d already has Action Scheduler action #%s (status=%s) — not queuing a duplicate. If this status never changes across several ticks, that action is likely stale/stuck (e.g. its worker process died without ever completing it) and is silently blocking this job forever — check Tools -> Scheduled Actions for action #%s.',
                        $job_id,
                        $existing_id,
                        $existing_status,
                        $existing_id
                    ) );
                }
                return;
            }
            $action_id = as_enqueue_async_action( self::AS_HOOK, array( 'job_id' => $job_id ), 'rsd-rb' );
            RSD_RB_Logger::info( 'Upload worker: job #' . $job_id . ' scheduled via Action Scheduler (action #' . $action_id . ', group rsd-rb).' );
        } elseif ( ! wp_next_scheduled( self::CRON_HOOK, array( $job_id ) ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK, array( $job_id ) );
            RSD_RB_Logger::info( 'Upload worker: job #' . $job_id . ' scheduled via WP-Cron single event (Action Scheduler not active) — depends on a page load reaching wp-cron.php after now.' );
        } else {
            RSD_RB_Logger::info( 'Upload worker: job #' . $job_id . ' already has a pending WP-Cron single event — not queuing a duplicate.' );
        }
    }

    /**
     * Look up any pending/in-progress Action Scheduler action(s) for this
     * job, returning [action_id => status]. Uses as_get_scheduled_actions()
     * rather than the boolean as_has_scheduled_action() specifically so the
     * log can report which exact action is blocking re-dispatch and its
     * status — as_has_scheduled_action() alone can't distinguish "a fresh
     * action legitimately in progress" from "a stale action AS never marked
     * complete/failed, stuck reporting in-progress forever."
     */
    private static function find_existing_actions( int $job_id ): array {
        if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
            return array();
        }

        $found = array();
        foreach ( array( 'pending', 'in-progress' ) as $status ) {
            $action_ids = as_get_scheduled_actions( array(
                'hook'   => self::AS_HOOK,
                'args'   => array( 'job_id' => $job_id ),
                'group'  => 'rsd-rb',
                'status' => $status,
            ), 'ids' );
            foreach ( (array) $action_ids as $action_id ) {
                $found[ $action_id ] = $status;
            }
        }
        return $found;
    }

    /**
     * Schedule the next tick of a job that is executing RIGHT NOW — called
     * from inside do_upload() itself when the time budget is hit mid-transfer
     * or EOF is reached without a 'complete' response. Deliberately bypasses
     * schedule()'s as_has_scheduled_action() dedup guard.
     *
     * Found via a live bug report: that guard matches "pending OR
     * in-progress" actions (see schedule()'s own docblock), and the action
     * currently executing this very callback is still "in-progress" — Action
     * Scheduler only marks it complete after the callback returns. So
     * calling schedule() here always found "itself" already scheduled and
     * silently no-opped, permanently orphaning any job that needed more than
     * one tick to finish: it would run for exactly one time-budget window,
     * get marked "Complete" by AS on return, and then nothing would ever run
     * it again. Confirmed directly via Action Scheduler's own log for a
     * stuck job: started via Async Request, ran the full time budget,
     * completed via Async Request — with no successor action ever created.
     *
     * No double-dispatch risk to guard against here — this call site only
     * ever runs once per tick, from within the single action instance it's
     * continuing.
     */
    private static function schedule_continuation( int $job_id ): void {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::AS_HOOK, array( 'job_id' => $job_id ), 'rsd-rb' );
        } else {
            wp_schedule_single_event( time(), self::CRON_HOOK, array( $job_id ) );
        }
    }

    /**
     * Schedule processing of ALL pending jobs (used by manual trigger).
     */
    public static function schedule_all_pending( string $provider ): int {
        if ( ! RSD_RB_License::is_valid() ) {
            RSD_RB_Logger::warning( 'Upload scheduling skipped — no valid license.' );
            return 0;
        }

        $jobs    = RSD_RB_Queue::get_jobs( RSD_RB_Queue::STATUS_PENDING );
        $count   = 0;
        $missing = 0;
        foreach ( $jobs as $job ) {
            if ( $job['provider'] !== $provider ) {
                continue;
            }
            // Only schedule jobs whose file is actually on disk.
            if ( empty( $job['filepath'] ) || ! file_exists( $job['filepath'] ) ) {
                RSD_RB_Queue::mark_file_missing( (int) $job['id'] );
                ++$missing;
                continue;
            }
            self::schedule( (int) $job['id'] );
            ++$count;
        }

        // Fires for every call site (the WP-Cron scan tick, REST /trigger, and
        // every manual admin action) — previously the WP-Cron tick was the one
        // caller that discarded this method's outcome with no log line at all
        // (see RSD_RB_Plugin::run_scan()), so a scan that ran perfectly but
        // found nothing to schedule (or silently failed to schedule anything)
        // was indistinguishable from one that never ran.
        RSD_RB_Logger::info( sprintf(
            'Upload worker: schedule_all_pending(provider=%s) — %d job(s) scheduled, %d marked missing (local file gone), %d pending job(s) total across all providers.',
            $provider,
            $count,
            $missing,
            count( $jobs )
        ) );

        return $count;
    }

    // -------------------------------------------------------------------------
    // Entry point (called by AS or WP-Cron)

    /**
     * Process one upload job.  Safe to call multiple times — claims atomically.
     *
     * @param int $job_id The job to process (0 = claim next pending for active provider).
     */
    public static function process( int $job_id = 0 ): void {
        if ( ! RSD_RB_License::is_valid() ) {
            RSD_RB_Logger::warning( 'Upload worker skipped — no valid license.' );
            return;
        }

        $start       = microtime( true );
        $time_budget = RSD_RB_Settings::get_time_budget();

        // Resolve the job row.
        if ( $job_id > 0 ) {
            $job = RSD_RB_Queue::get_job( $job_id );
            if ( ! $job ) {
                RSD_RB_Logger::warning( 'Upload worker: job #' . $job_id . ' not found.' );
                return;
            }

            // Logged unconditionally, before any early-return below, so the
            // log itself proves whether Action Scheduler is actually invoking
            // this job at all and what state it found — previously the only
            // way to know a tick even happened was inferring it from
            // whatever it logged next, which is ambiguous when a job goes
            // silent (no way to tell "never ticked again" from "ticked but
            // had nothing new to log").
            RSD_RB_Logger::info( sprintf(
                'Upload worker: tick for job #%d — status=%s, attempts=%d, bytes_sent=%s, session=%s.',
                $job_id,
                $job['status'],
                (int) $job['attempts'],
                size_format( (int) $job['bytes_sent'], 2 ),
                empty( $job['session_url'] ) ? 'none' : 'active'
            ) );

            // A stale duplicate action firing after the job already reached a
            // terminal state (e.g. two overlapping scan/trigger calls queued it
            // twice) must be a no-op — otherwise the "local file missing" check
            // below fires on a file this same job already deleted post-upload,
            // and mark_error() would flip an already-complete job back to pending.
            if ( in_array( $job['status'], array( RSD_RB_Queue::STATUS_COMPLETE, RSD_RB_Queue::STATUS_FAILED, RSD_RB_Queue::STATUS_CANCELLED ), true ) ) {
                RSD_RB_Logger::info( 'Upload worker: job #' . $job_id . ' already ' . $job['status'] . ' — ignoring stale duplicate run.' );
                return;
            }

            // A job scheduled by id never goes through claim_next()'s claiming
            // step, so the concurrent-upload cap has to be enforced here instead.
            // start_upload() is a no-op success if this job already holds a slot
            // from an earlier tick (resuming after the time budget was hit).
            if ( RSD_RB_Queue::STATUS_UPLOADING !== $job['status'] && ! RSD_RB_Queue::start_upload( $job_id ) ) {
                RSD_RB_Logger::info( 'Upload worker: job #' . $job_id . ' deferred — concurrent upload cap reached.' );
                self::schedule_delayed( $job_id, self::CONCURRENCY_RETRY_DELAY );
                return;
            }

            $provider = $job['provider'];
        } else {
            $provider = RSD_RB_Settings::get_provider();
            RSD_RB_Logger::info( 'Upload worker: opportunistic tick (job_id=0, provider=' . $provider . ').' );
            $job = RSD_RB_Queue::claim_next( $provider );
            if ( ! $job ) {
                return; // claim_next() already logged why (nothing pending, or cap full).
            }
            $job_id = (int) $job['id'];
        }

        // Load the provider adapter.
        $adapter = RSD_RB_Plugin::get_instance()->get_provider( $provider );
        if ( ! $adapter ) {
            RSD_RB_Queue::mark_error( $job_id, 'Unknown provider: ' . $provider );
            return;
        }

        // Verify the file still exists.
        $filepath = $job['filepath'];
        if ( ! file_exists( $filepath ) ) {
            RSD_RB_Queue::mark_error( $job_id, 'Local file missing: ' . basename( $filepath ) );
            RSD_RB_Logger::error( 'Upload worker: file missing for job #' . $job_id . ' — ' . basename( $filepath ) );
            return;
        }

        try {
            self::do_upload( $adapter, $job, $start, $time_budget );
        } catch ( RuntimeException $e ) {
            $msg = $e->getMessage();
            RSD_RB_Queue::mark_error( $job_id, $msg );

            $refreshed   = RSD_RB_Queue::get_job( $job_id );
            $attempts    = (int) ( $refreshed['attempts'] ?? 1 );
            $manifest_id = (int) ( $refreshed['manifest_id'] ?? $job['manifest_id'] ?? 0 );

            RSD_RB_Manifest::mark_upload_failed( $manifest_id, $attempts );

            // Max attempts reached — job is now terminally failed. Clean up any
            // leftover compressed temp file rather than leaving it stranded.
            if ( RSD_RB_Queue::STATUS_FAILED === ( $refreshed['status'] ?? '' ) ) {
                if ( ! empty( $refreshed['upload_path'] ) && $refreshed['upload_path'] !== $refreshed['filepath'] ) {
                    RSD_RB_Compressor::cleanup( $refreshed['upload_path'] );
                }
                return;
            }

            // Exponential backoff: reschedule after base * 2^attempts seconds.
            $delay = min( self::BACKOFF_BASE * pow( 2, $attempts - 1 ), 3600 );
            self::schedule_delayed( $job_id, (int) $delay );
        }
    }

    // -------------------------------------------------------------------------

    private static function do_upload( RB_Provider $adapter, array $job, float $start, int $time_budget ): void {
        $job_id      = (int) $job['id'];
        $filepath    = $job['filepath']; // Original .wpress — used for the is_compressed check / display only.
        $manifest_id = (int) ( $job['manifest_id'] ?? 0 );

        // Resolve the file to actually stream: the original, or a compressed temp
        // copy. Decided once per job and persisted, so later ticks reuse it. Also
        // records the compression decision/outcome on the manifest (skipped,
        // compressed, or compress_failed-with-raw-fallback).
        $resolve_start = microtime( true );
        list( $upload_path, $compression ) = self::resolve_upload_path( $job, $manifest_id );
        $transfer_size = (int) filesize( $upload_path );

        RSD_RB_Logger::info( sprintf(
            'Upload worker: job #%d resolved upload path in %dms (compressed=%s), transfer size %s.',
            $job_id,
            (int) round( ( microtime( true ) - $resolve_start ) * 1000 ),
            ( $upload_path !== $filepath ? 'yes' : 'no' ),
            size_format( $transfer_size, 2 )
        ) );

        // --- Ensure an upload session exists ---
        $session_url = $job['session_url'] ?? '';
        $bytes_sent  = (int) $job['bytes_sent'];
        $chunk_size  = $adapter instanceof RSD_RB_Provider_Google_Drive
            ? RSD_RB_Provider_Google_Drive::CHUNK_SIZE
            : ( defined( 'RSD_RB_Provider_OneDrive::CHUNK_SIZE' )
                ? RSD_RB_Provider_OneDrive::CHUNK_SIZE
                : 8 * 1024 * 1024 );

        // Name the remote object after the ORIGINAL filename + a clean extension
        // (e.g. "backup.wpress.gz"), not the local temp path's uniqid-suffixed
        // name — this is what a human browsing the OneDrive folder should see.
        // Computed unconditionally (not just when opening the first session)
        // because a session-expiry restart below also needs it.
        $remote_name = basename( $filepath );
        if ( $upload_path !== $filepath && ! empty( $compression['method'] ) ) {
            $remote_name .= RSD_RB_Compressor::extension_for( $compression['method'] );
        }

        if ( empty( $session_url ) ) {
            $session_start = microtime( true );
            $session       = $adapter->begin_upload( $upload_path, $remote_name );
            $session_url   = $session['session_url'];
            $chunk_size    = $session['chunk_size'];
            RSD_RB_Logger::info( sprintf(
                'Upload worker: job #%d began upload session in %dms.',
                $job_id,
                (int) round( ( microtime( true ) - $session_start ) * 1000 )
            ) );
            RSD_RB_Queue::update_progress( $job_id, $session_url, $bytes_sent );
            RSD_RB_Manifest::mark_uploading( $manifest_id );
        }

        // --- Open file at resume offset ---
        $fh = fopen( $upload_path, 'rb' );
        if ( ! $fh ) {
            throw new RuntimeException( 'Cannot open file for reading: ' . basename( $upload_path ) );
        }

        if ( $bytes_sent > 0 ) {
            fseek( $fh, $bytes_sent );
        }

        $session_descriptor = array(
            'session_url' => $session_url,
            'filesize'    => $transfer_size,
        );

        // Tracks whether this tick has already opened one replacement session —
        // see the 401 handling below. Only one auto-restart per tick; a brand
        // new session dying immediately is a real problem, not routine expiry.
        $session_restarted = false;

        // --- Chunked upload loop ---
        while ( ! feof( $fh ) ) {
            // Stop if we're approaching the time budget.
            if ( ( microtime( true ) - $start ) >= $time_budget ) {
                fclose( $fh );
                RSD_RB_Queue::update_progress( $job_id, $session_url, $bytes_sent );
                RSD_RB_Logger::info( sprintf(
                    'Upload worker: job #%d time budget reached at %s / %s — rescheduling.',
                    $job_id,
                    size_format( $bytes_sent, 2 ),
                    size_format( $transfer_size, 2 )
                ) );
                self::schedule_continuation( $job_id );
                return;
            }

            $bytes = fread( $fh, $chunk_size );
            if ( false === $bytes || '' === $bytes ) {
                break;
            }

            try {
                $result = $adapter->upload_chunk( $session_descriptor, $bytes_sent, $bytes );
            } catch ( RuntimeException $e ) {
                if ( $session_restarted || false === strpos( $e->getMessage(), '401' ) ) {
                    throw $e;
                }

                // A 401 on a chunk PUT means the upload SESSION url itself was
                // rejected — not our OAuth token. Neither provider's
                // upload_chunk() sends an Authorization header on the resumable
                // session PUT at all (both createUploadSession/session URLs are
                // pre-authenticated by the provider), so refreshing our token and
                // retrying the identical request — the previous behaviour here —
                // can never succeed. Confirmed via a live incident: three
                // overnight uploads each refreshed their token successfully,
                // retried, hit the exact same 401 again, and repeated that until
                // all 5 attempts were burned and the jobs failed — recoverable
                // only by an admin manually retrying, which happens to clear
                // session_url as a side effect and let them succeed instantly.
                // Do that automatically instead: discard the dead session and
                // open a fresh one, restarting the transfer from byte 0 (a new
                // session cannot resume an old one's offset). Deliberately NOT
                // routed through mark_error()/attempts — this is routine session
                // expiry, not a real failure, same convention as the stale-
                // session hard reset in RSD_RB_Queue::reset_stalled().
                RSD_RB_Logger::warning( sprintf(
                    'Upload worker: job #%d upload session rejected (HTTP 401) — session expired, starting a new one (not counted as a failed attempt).',
                    $job_id
                ) );

                $new_session = $adapter->begin_upload( $upload_path, $remote_name );
                $session_url = $new_session['session_url'];
                $session_descriptor['session_url'] = $session_url;
                $bytes_sent = 0;
                fseek( $fh, 0 );
                RSD_RB_Queue::update_progress( $job_id, $session_url, $bytes_sent );
                $session_restarted = true;
                continue;
            }

            if ( 'complete' === $result['status'] ) {
                fclose( $fh );
                self::on_complete( $job_id, $manifest_id, $result['remote_id'], $filepath, $upload_path, $transfer_size, $result['remote_size'] ?? null, $adapter );
                return;
            }

            $bytes_sent = $result['next_offset'];
            RSD_RB_Queue::update_progress( $job_id, $session_url, $bytes_sent );
        }

        fclose( $fh );

        // If we read to EOF but didn't get a 'complete' response, reschedule.
        RSD_RB_Queue::update_progress( $job_id, $session_url, $bytes_sent );
        self::schedule_continuation( $job_id );
    }

    /**
     * Decide, once per job, what file the upload loop should actually read from.
     * Also drives the manifest's compression-decision step (skipped vs. attempted).
     *
     * Guard: if a session/progress already exists but upload_path was never
     * recorded (a job that started uploading before this feature existed, or an
     * upgrade landing mid-flight), we must NOT compress now — the byte offsets
     * already sent refer to the original file, and switching files mid-stream
     * would corrupt the transfer. Only jobs starting fresh (no session yet, zero
     * bytes sent) are eligible for compression.
     *
     * @return array{0:string,1:?array} [upload_path, compression result-or-null]
     */
    private static function resolve_upload_path( array $job, int $manifest_id ): array {
        $job_id      = (int) $job['id'];
        $filepath    = $job['filepath'];
        $upload_path = $job['upload_path'] ?? '';

        if ( '' !== $upload_path && file_exists( $upload_path ) ) {
            $meta = ! empty( $job['compression_meta'] ) ? json_decode( $job['compression_meta'], true ) : null;
            return array( $upload_path, $meta );
        }

        if ( ! empty( $job['session_url'] ) || (int) $job['bytes_sent'] > 0 ) {
            RSD_RB_Queue::set_upload_path( $job_id, $filepath );
            return array( $filepath, null );
        }

        // First time this job is being processed — decide whether to compress.
        if ( ! RSD_RB_Compressor::is_enabled() ) {
            RSD_RB_Manifest::record_compression_skipped( $manifest_id );
            RSD_RB_Queue::set_upload_path( $job_id, $filepath );
            return array( $filepath, null );
        }

        RSD_RB_Manifest::record_compression_enabled( $manifest_id );
        RSD_RB_Manifest::mark_compressing( $manifest_id );

        $result = RSD_RB_Compressor::compress( $filepath );

        if ( null === $result ) {
            // Enabled but failed (no method, low disk space, or the attempt errored) —
            // fall back to the raw file rather than leaving the backup unuploaded.
            RSD_RB_Manifest::mark_compress_failed( $manifest_id );
            RSD_RB_Queue::set_upload_path( $job_id, $filepath );
            return array( $filepath, null );
        }

        RSD_RB_Manifest::mark_compressed( $manifest_id, $result );
        RSD_RB_Queue::set_upload_path( $job_id, $result['path'], $result );

        return array( $result['path'], $result );
    }

    private static function on_complete( int $job_id, int $manifest_id, ?string $remote_id, string $filepath, string $upload_path, int $transfer_size, ?int $remote_size, RB_Provider $adapter ): void {
        // Integrity check: verify the provider received exactly as many bytes as we
        // streamed — the compressed file's size when compression ran, otherwise the
        // original file's size.
        if ( null !== $remote_size && $remote_size !== $transfer_size ) {
            $msg = sprintf(
                'Upload integrity check failed for job #%d: sent %s vs remote %s — will retry.',
                $job_id,
                size_format( $transfer_size, 2 ),
                size_format( $remote_size, 2 )
            );
            RSD_RB_Logger::error( $msg );
            throw new RuntimeException( $msg );
        }

        $is_compressed = $upload_path !== $filepath;

        RSD_RB_Queue::mark_complete( $job_id, (string) $remote_id, $transfer_size );
        RSD_RB_Queue::update_location( $job_id, RSD_RB_Queue::LOCATION_BOTH );
        RSD_RB_Manifest::mark_uploaded( $manifest_id, (string) $remote_id, $is_compressed );

        // Retention: prune old remote backups.
        RSD_RB_Retention::prune( $adapter );

        // Clean up the temporary compressed copy now that the upload is confirmed —
        // independent of "delete local backup", which governs only the original file.
        // Never delete before this point — the integrity check above must pass first.
        if ( $is_compressed ) {
            RSD_RB_Compressor::cleanup( $upload_path );
        }
        RSD_RB_Manifest::mark_zip_cleaned( $manifest_id ); // No-ops safely if no zip was ever created.

        // Local retention: keep the configured number of most-recent, confirmed-
        // uploaded backups on local disk, deleting older ones now that this job is
        // also confirmed remote. Re-evaluates the whole 'both' set (not just this
        // job), so it self-heals if the kept-count setting changes later too.
        RSD_RB_Local_Retention::prune();

        RSD_RB_Manifest::mark_complete( $manifest_id );
    }

    // -------------------------------------------------------------------------

    private static function schedule_delayed( int $job_id, int $delay_seconds ): void {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + $delay_seconds, self::AS_HOOK, array( 'job_id' => $job_id ), 'rsd-rb' );
        } else {
            wp_schedule_single_event( time() + $delay_seconds, self::CRON_HOOK, array( $job_id ) );
        }
    }
}

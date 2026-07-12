<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages the RSD_RB_jobs table.
 *
 * All status transitions that must be race-free use conditional UPDATE WHERE
 * so overlapping cron ticks cannot double-claim or double-enqueue a job.
 */
class RSD_RB_Queue {

    // Valid status values.
    const STATUS_PENDING   = 'pending';
    const STATUS_UPLOADING = 'uploading';
    const STATUS_COMPLETE  = 'complete';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    // Valid location values.
    const LOCATION_LOCAL  = 'local';
    const LOCATION_REMOTE = 'remote';
    const LOCATION_BOTH   = 'both';
    const LOCATION_NONE   = 'none';

    // Max upload attempts before marking failed.
    const MAX_ATTEMPTS = 5;

    // -------------------------------------------------------------------------
    // Location display helpers — shared by the Upload Queue and Backups admin
    // screens so both render the local/remote badge identically.

    public static function location_label( string $location ): string {
        switch ( $location ) {
            case self::LOCATION_BOTH:   return __( 'Local + Remote', 'rsd-remote-backup' );
            case self::LOCATION_LOCAL:  return __( 'Local only', 'rsd-remote-backup' );
            case self::LOCATION_REMOTE: return __( 'Remote only', 'rsd-remote-backup' );
            default:                    return __( 'Not found', 'rsd-remote-backup' );
        }
    }

    public static function location_badge_class( string $location ): string {
        switch ( $location ) {
            case self::LOCATION_BOTH:   return 'rsd-rb-badge--both';
            case self::LOCATION_LOCAL:  return 'rsd-rb-badge--local';
            case self::LOCATION_REMOTE: return 'rsd-rb-badge--remote';
            default:                    return 'rsd-rb-badge--none';
        }
    }

    // -------------------------------------------------------------------------
    // Enqueue

    /**
     * Add a new job for a file.  No-op if a job already exists for this
     * filename + provider combination (prevents duplicate enqueues if the
     * scanner runs before the DB update propagates).
     *
     * @param int $manifest_id Linked RSD_RB_Manifest row id (0 = none, e.g.
     *                         a remote-only job discovered by resync).
     */
    public static function enqueue( string $filename, string $filepath, int $filesize, string $provider, int $manifest_id = 0 ): bool {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // Guard: check first (cheap), then insert.
        if ( self::file_is_known( $filename, $provider ) ) {
            return false;
        }

        $data    = array(
            'filename' => $filename,
            'filepath' => $filepath,
            'filesize' => $filesize,
            'provider' => $provider,
            'status'   => self::STATUS_PENDING,
            'location' => self::LOCATION_LOCAL,
        );
        $formats = array( '%s', '%s', '%d', '%s', '%s', '%s' );

        if ( $manifest_id > 0 ) {
            $data['manifest_id'] = $manifest_id;
            $formats[]           = '%d';
        }

        $rows = $wpdb->insert( $table, $data, $formats );

        return (bool) $rows;
    }

    // -------------------------------------------------------------------------
    // Claiming

    /**
     * Atomically claim one pending or stalled uploading job.
     * Returns the job row as an associative array, or null if nothing available.
     *
     * "Stalled uploading" = uploading but not updated in >10 minutes (previous
     * PHP process timed out mid-chunk).
     *
     * @return array|null
     */
    public static function claim_next( string $provider ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        if ( ! self::acquire_slot_lock() ) {
            return null;
        }

        try {
            if ( ! self::has_free_upload_slot() ) {
                return null;
            }

            // "updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)" is evaluated
            // entirely by MySQL, using MySQL's own clock — deliberately NOT a
            // PHP-computed gmdate() string. updated_at is populated by the
            // column's own `ON UPDATE CURRENT_TIMESTAMP`, which reflects the
            // DB server/session timezone, not necessarily UTC. Comparing that
            // against a PHP-side UTC timestamp silently breaks stale detection
            // whenever the two clocks disagree — found via a live bug report
            // where a genuinely stalled job never once triggered "Reset
            // Stalled Jobs" no matter how long it sat idle. Keeping both sides
            // of the comparison on MySQL's own clock avoids the mismatch
            // regardless of what timezone the server is actually in.
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $job = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}`
                      WHERE provider = %s
                        AND (
                              status = %s
                              OR ( status = %s AND updated_at < DATE_SUB( NOW(), INTERVAL 10 MINUTE ) AND attempts < %d )
                            )
                      ORDER BY id ASC
                      LIMIT 1",
                    $provider,
                    self::STATUS_PENDING,
                    self::STATUS_UPLOADING,
                    self::MAX_ATTEMPTS
                ),
                ARRAY_A
            );
            // phpcs:enable

            if ( ! $job ) {
                return null;
            }

            // Guarded UPDATE (WHERE ... AND status = the status we just read) is
            // its own atomic claim — no row lock needed on top of the named lock
            // above, which already serializes concurrent claimers against
            // each other without ever touching this row.
            $claimed = $wpdb->update(
                $table,
                array( 'status' => self::STATUS_UPLOADING ),
                array( 'id' => $job['id'], 'status' => $job['status'] ),
                array( '%s' ),
                array( '%d', '%s' )
            );

            if ( ! $claimed ) {
                return null;
            }

            $job['status'] = self::STATUS_UPLOADING;
            return $job;
        } finally {
            self::release_slot_lock();
        }
    }

    /**
     * MySQL named lock guarding the concurrent-upload cap. A named lock is
     * an advisory lock scoped to the lock name only — unlike a row lock, it
     * can never contend with an ordinary UPDATE against a job's row, which
     * is exactly what a row lock here did wrong in practice: with the cap
     * set to 1 and several jobs waiting, each waiting job's cap-check
     * (formerly `SELECT COUNT(*) WHERE status = 'uploading' FOR UPDATE`)
     * locked the one actively-uploading job's row on every retry — every
     * ~30s per waiting job. Under load from multiple waiting jobs this
     * starved the active job's own progress-update queries badly enough
     * that it never got a chance to send a byte, confirmed via a live bug
     * report: the active job sat at 0% indefinitely and never even reached
     * the point of logging anything, while the waiting jobs logged their
     * "deferred" message on schedule. Switching the cap check itself to a
     * plain, lock-free read and only using the named lock to serialize the
     * "check the count, then claim" decision between claimers removes any
     * contention with the active transfer entirely.
     */
    private const SLOT_LOCK_NAME = 'rsd_rb_upload_slot';

    /** Acquire the slot lock (5s timeout). Returns false if another claimer holds it. */
    private static function acquire_slot_lock(): bool {
        global $wpdb;
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::SLOT_LOCK_NAME, 5 ) );
        return '1' === (string) $acquired;
    }

    private static function release_slot_lock(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::SLOT_LOCK_NAME ) );
    }

    /**
     * True if fewer jobs are currently 'uploading' than the configured cap.
     * Counts across all providers — the constraint being protected is the
     * site's own bandwidth/CPU, not any one provider's API.
     *
     * Deliberately a plain, lock-free read — see the SLOT_LOCK_NAME docblock
     * above for why. Callers (claim_next(), start_upload()) already hold
     * the named lock while calling this, which is what actually prevents
     * two concurrent claimers from both reading "under the cap" and both
     * proceeding.
     */
    private static function has_free_upload_slot(): bool {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $in_flight = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s", self::STATUS_UPLOADING )
        );

        return $in_flight < RSD_RB_Settings::get_max_concurrent_uploads();
    }

    /**
     * Atomically transition a job from pending to uploading, subject to the
     * same concurrent-upload cap as claim_next(). Needed because a job
     * scheduled by a specific id (the normal path — see
     * RSD_RB_Upload_Worker::schedule()) never goes through claim_next()'s
     * claiming step, so nothing else enforces the cap for it.
     *
     * Returns true if the job now holds an upload slot — either just
     * acquired, or already held from an earlier tick (resuming after the
     * time budget was hit mid-transfer) — and false if the cap is currently
     * full, in which case the caller should retry later without uploading.
     */
    public static function start_upload( int $job_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $status = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id = %d", $job_id )
        );

        if ( self::STATUS_UPLOADING === $status ) {
            return true;
        }

        if ( self::STATUS_PENDING !== $status ) {
            return false;
        }

        if ( ! self::acquire_slot_lock() ) {
            return false;
        }

        try {
            if ( ! self::has_free_upload_slot() ) {
                return false;
            }

            $claimed = $wpdb->update(
                $table,
                array( 'status' => self::STATUS_UPLOADING ),
                array( 'id' => $job_id, 'status' => self::STATUS_PENDING ),
                array( '%s' ),
                array( '%d', '%s' )
            );

            return (bool) $claimed;
        } finally {
            self::release_slot_lock();
        }
    }

    /**
     * Cancel every job currently sitting in 'pending' — the admin "Cancel
     * Pending Uploads" action, for when a backlog of large files is
     * saturating the site. Complements the concurrent-upload cap above
     * (which throttles how many run at once): this lets an admin give up on
     * the backlog entirely instead of waiting it out.
     *
     * Deliberately scoped to 'pending' only — a job actively 'uploading' has
     * a real PHP process streaming its file right now. There's no safe way
     * to interrupt that process from here, and deleting its temp file out
     * from under an open file handle would corrupt that transfer rather
     * than cancel it. Cancel it after it fails or stalls instead.
     *
     * For each pending job, in order: atomically flips it to 'cancelled'
     * (guarded on status still being 'pending', so a job that raced into
     * 'uploading' — e.g. grabbed a concurrency slot freed a moment earlier —
     * is left alone and its temp file is not touched); only once that
     * succeeds does it unschedule any outstanding Action Scheduler/WP-Cron
     * action (so a queued retry can't fire after cancellation) and delete
     * the job's compressed temp file, if it had one.
     *
     * 'cancelled' is a terminal status like complete/failed: claim_next(),
     * file_is_known() and every "find pending work" query already ignore it,
     * so a cancelled job can never be picked up again, and its filename is
     * free to be re-queued fresh (e.g. by the next scan) if the local file
     * is still on disk.
     *
     * @return int Number of jobs cancelled.
     */
    public static function cancel_all_pending(): int {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        $pending = self::get_jobs( self::STATUS_PENDING, 500 );
        $count   = 0;

        foreach ( $pending as $job ) {
            $job_id = (int) $job['id'];

            $updated = $wpdb->update(
                $table,
                array(
                    'status'     => self::STATUS_CANCELLED,
                    'last_error' => 'Cancelled by admin.',
                ),
                array( 'id' => $job_id, 'status' => self::STATUS_PENDING ),
                array( '%s', '%s' ),
                array( '%d', '%s' )
            );

            if ( ! $updated ) {
                continue;
            }

            if ( function_exists( 'as_unschedule_action' ) ) {
                as_unschedule_action( RSD_RB_Upload_Worker::AS_HOOK, array( 'job_id' => $job_id ), 'rsd-rb' );
            }
            wp_clear_scheduled_hook( RSD_RB_Upload_Worker::CRON_HOOK, array( $job_id ) );

            // Never the original backup file — upload_path only differs from
            // filepath once compression has produced its own temp copy.
            if ( ! empty( $job['upload_path'] ) && $job['upload_path'] !== $job['filepath'] ) {
                RSD_RB_Compressor::cleanup( $job['upload_path'] );
            }

            ++$count;
            RSD_RB_Logger::info( 'Queue: job #' . $job_id . ' cancelled by admin.' );
        }

        return $count;
    }

    /**
     * Requeue every job currently sitting in 'failed' — the admin "Retry
     * Failed Uploads" action, for when a failure's root cause (e.g. an
     * expired/revoked provider token) has since been fixed but attempts
     * were already exhausted. Nothing else in the plugin will ever pick
     * these back up on its own: they don't match claim_next()'s
     * pending/stalled-uploading query (so "Reset Stalled Jobs" finds
     * nothing), the scanner's file_is_known() guard still counts a failed
     * job as known — unlike a cancelled one — so it won't re-enqueue the
     * same filename either, and resync only ever reconciles
     * location/manifest fields, never job status.
     *
     * For each failed job whose local file still exists: defensively
     * deletes any leftover compressed temp file (normally already cleaned
     * up the moment the job originally reached 'failed' — see process()'s
     * catch block — but this covers jobs that failed before that cleanup
     * existed, or a process killed before it ran), then atomically resets
     * attempts/last_error/session_url/bytes_sent/upload_path/
     * compression_meta to a clean slate and flips status back to
     * 'pending' — guarded on status still being 'failed' so this can't
     * clobber a job something else already touched. Clearing upload_path
     * (rather than leaving it for reuse) makes resolve_upload_path() treat
     * the retry as a brand new attempt and decide compression fresh next
     * time, rather than trusting a path that may since have been deleted.
     *
     * A job whose local file is gone is left failed — nothing here can
     * retry an upload with no file left to read.
     *
     * @return int Number of jobs requeued.
     */
    public static function retry_failed(): int {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        $failed = self::get_jobs( self::STATUS_FAILED, 500 );
        $count  = 0;

        foreach ( $failed as $job ) {
            $job_id = (int) $job['id'];

            if ( empty( $job['filepath'] ) || ! file_exists( $job['filepath'] ) ) {
                continue;
            }

            // Never the original backup file — upload_path only differs from
            // filepath once compression has produced its own temp copy.
            if ( ! empty( $job['upload_path'] ) && $job['upload_path'] !== $job['filepath'] ) {
                RSD_RB_Compressor::cleanup( $job['upload_path'] );
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}`
                        SET status = %s, attempts = 0, last_error = NULL,
                            session_url = NULL, bytes_sent = 0,
                            upload_path = NULL, compression_meta = NULL
                      WHERE id = %d AND status = %s",
                    self::STATUS_PENDING,
                    $job_id,
                    self::STATUS_FAILED
                )
            );

            if ( ! $updated ) {
                continue;
            }

            ++$count;
            RSD_RB_Logger::info( 'Queue: job #' . $job_id . ' retried by admin (was failed).' );
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Progress updates

    /** Persist the resumable session URL and current byte offset. */
    public static function update_progress( int $job_id, string $session_url, int $bytes_sent ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . RSD_RB_TABLE,
            array(
                'session_url' => $session_url,
                'bytes_sent'  => $bytes_sent,
            ),
            array( 'id' => $job_id ),
            array( '%s', '%d' ),
            array( '%d' )
        );
    }

    /**
     * Persist the path the upload worker should actually read bytes from (the original
     * file, or a compressed temp copy), and — when compression ran — the size/timing
     * stats produced by RSD_RB_Compressor::compress(). Called once per job, the first
     * time it's processed; later ticks reuse the stored path.
     */
    public static function set_upload_path( int $job_id, string $upload_path, ?array $compression_meta = null ): void {
        global $wpdb;
        $data    = array( 'upload_path' => $upload_path );
        $formats = array( '%s' );
        if ( null !== $compression_meta ) {
            $data['compression_meta'] = wp_json_encode( $compression_meta );
            $formats[]                = '%s';
        }
        $wpdb->update(
            $wpdb->prefix . RSD_RB_TABLE,
            $data,
            array( 'id' => $job_id ),
            $formats,
            array( '%d' )
        );
    }

    /** Mark a job complete, store the remote file id, and set bytes_sent to filesize so progress shows 100%. */
    public static function mark_complete( int $job_id, string $remote_id, int $filesize = 0 ): void {
        global $wpdb;
        $data    = array(
            'status'     => self::STATUS_COMPLETE,
            'remote_id'  => $remote_id,
            'last_error' => null,
        );
        $formats = array( '%s', '%s', '%s' );
        if ( $filesize > 0 ) {
            $data['bytes_sent'] = $filesize;
            $formats[]          = '%d';
        }
        $wpdb->update(
            $wpdb->prefix . RSD_RB_TABLE,
            $data,
            array( 'id' => $job_id ),
            $formats,
            array( '%d' )
        );
        RSD_RB_Logger::info( 'Queue: job #' . $job_id . ' marked complete (remote id: ' . $remote_id . ').' );
    }

    /** Record an error and increment attempts; mark failed if max reached. */
    public static function mark_error( int $job_id, string $error ): void {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // Increment atomically.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET attempts = attempts + 1, last_error = %s, status = CASE WHEN attempts + 1 >= %d THEN %s ELSE %s END WHERE id = %d",
                $error,
                self::MAX_ATTEMPTS,
                self::STATUS_FAILED,
                self::STATUS_PENDING,
                $job_id
            )
        );

        RSD_RB_Logger::error( 'Queue: job #' . $job_id . ' error — ' . $error );
    }

    /**
     * Immediately mark a pending job as failed because its local file is gone.
     * Does NOT increment attempts — the file is simply missing, not an upload error.
     */
    public static function mark_file_missing( int $job_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;
        $wpdb->update(
            $table,
            array(
                'status'     => self::STATUS_FAILED,
                'location'   => self::LOCATION_NONE,
                'last_error' => 'Local file no longer exists — cannot upload.',
            ),
            array( 'id' => $job_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
        RSD_RB_Logger::warning( 'Queue: job #' . $job_id . ' marked failed — local file no longer on disk.' );
    }

    // -------------------------------------------------------------------------
    // Queries

    /**
     * True if any job row exists for this filename + provider — ignoring
     * cancelled jobs, so a filename whose job was cancelled is treated as
     * unknown again and can be picked up fresh (e.g. by the next scan) if
     * the local file is still there.
     */
    public static function file_is_known( string $filename, string $provider ): bool {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE filename = %s AND provider = %s AND status != %s",
                $filename,
                $provider,
                self::STATUS_CANCELLED
            )
        );

        return $count > 0;
    }

    /**
     * Return all jobs, newest first, optionally filtered by status.
     *
     * @param string|null $status Filter to a specific status, or null for all.
     * @param int         $limit  Max rows to return.
     * @return array[]
     */
    public static function get_jobs( ?string $status = null, int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        if ( $status ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT %d",
                    $status,
                    $limit
                ),
                ARRAY_A
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        );
    }

    /** Return a single job by id. */
    public static function get_job( int $job_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $job_id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /** Return all jobs regardless of status (used by resync). */
    public static function get_all_jobs( int $limit = 500 ): array {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        ) ?: array();
    }

    /** Find the job linked to a manifest row, if any (uses idx_manifest_id). */
    public static function get_job_by_manifest_id( int $manifest_id ): ?array {
        if ( empty( $manifest_id ) ) {
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE manifest_id = %d ORDER BY id DESC LIMIT 1", $manifest_id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /** Update the location field for a job. */
    public static function update_location( int $job_id, string $location ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . RSD_RB_TABLE,
            array( 'location' => $location ),
            array( 'id'       => $job_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Find jobs stuck in 'uploading' and reset them to 'pending' so they can
     * be reclaimed and rescheduled.
     *
     * Two thresholds:
     *   > 30 min stalled  → reset to pending, keep session_url (try to resume).
     *   > 24 hours stalled → also clear session_url + bytes_sent (OneDrive sessions
     *                         expire after ~24 h idle; force a fresh upload session).
     *
     * @return int Number of jobs reset.
     */
    public static function reset_stalled(): int {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        // Both thresholds are computed by MySQL itself (DATE_SUB(NOW(), ...)),
        // deliberately not as PHP-side gmdate() strings — updated_at is
        // populated by the column's own `ON UPDATE CURRENT_TIMESTAMP`, which
        // reflects the DB server/session timezone, not necessarily UTC.
        // Comparing that against a PHP-computed UTC timestamp silently breaks
        // stale detection whenever the two clocks disagree (found via a live
        // bug report: a genuinely stalled job never once got caught here, no
        // matter how long it sat idle, on a host whose DB timezone isn't UTC).
        // Keeping both sides on MySQL's own clock avoids the mismatch
        // regardless of what timezone the server actually runs in.

        // Hard reset: stalled >24 h — clear session so worker starts a fresh upload.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hard = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET status = %s, session_url = NULL, bytes_sent = 0
                  WHERE status = %s
                    AND updated_at < DATE_SUB( NOW(), INTERVAL 1 DAY )
                    AND attempts < %d",
                self::STATUS_PENDING,
                self::STATUS_UPLOADING,
                self::MAX_ATTEMPTS
            )
        );

        // Soft reset: stalled 30 min–24 h — keep session_url so we can resume.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $soft = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET status = %s
                  WHERE status = %s
                    AND updated_at < DATE_SUB( NOW(), INTERVAL 30 MINUTE )
                    AND updated_at >= DATE_SUB( NOW(), INTERVAL 1 DAY )
                    AND attempts < %d",
                self::STATUS_PENDING,
                self::STATUS_UPLOADING,
                self::MAX_ATTEMPTS
            )
        );

        // Diagnostic: log every job still 'uploading' after the resets above,
        // with its exact age and attempts count — otherwise "0 stalled jobs
        // found" gives no way to tell a genuinely-fresh job apart from one
        // that's actually been idle for an hour but sits just under the
        // threshold (attempts already maxed, so deliberately excluded from
        // the queries above), or an idle-but-recent one. This is exactly the
        // ambiguity that made a real production stall hard to diagnose
        // without direct database access — this makes it visible from the
        // log alone every time "Reset Stalled Jobs" runs or a scan ticks.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $still_uploading = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, attempts, TIMESTAMPDIFF( MINUTE, updated_at, NOW() ) AS age_minutes
                   FROM `{$table}` WHERE status = %s",
                self::STATUS_UPLOADING
            ),
            ARRAY_A
        );
        foreach ( $still_uploading as $row ) {
            RSD_RB_Logger::info( sprintf(
                'Reset stalled: job #%d still uploading — %d minute(s) since last update, %d attempt(s).',
                (int) $row['id'],
                (int) $row['age_minutes'],
                (int) $row['attempts']
            ) );
        }

        return (int) $hard + (int) $soft;
    }

    /**
     * Insert a job record discovered on the remote provider during resync
     * (no local file — uploaded outside this plugin or after local deletion).
     *
     * @param int $manifest_id Linked RSD_RB_Manifest row id (0 = none).
     */
    public static function enqueue_remote( string $filename, int $filesize, string $provider, string $remote_id, int $manifest_id = 0 ): bool {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_TABLE;

        if ( self::file_is_known( $filename, $provider ) ) {
            return false;
        }

        $data    = array(
            'filename'   => $filename,
            'filepath'   => '',
            'filesize'   => $filesize,
            'provider'   => $provider,
            'status'     => self::STATUS_COMPLETE,
            'location'   => self::LOCATION_REMOTE,
            'bytes_sent' => $filesize,
            'remote_id'  => $remote_id,
        );
        $formats = array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' );

        if ( $manifest_id > 0 ) {
            $data['manifest_id'] = $manifest_id;
            $formats[]           = '%d';
        }

        $rows = $wpdb->insert( $table, $data, $formats );

        return (bool) $rows;
    }

    /** Remove a job row outright — used by resync to delete duplicate rows it created for the same remote object under a different name (see run_resync()). */
    public static function delete( int $job_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . RSD_RB_TABLE, array( 'id' => $job_id ), array( '%d' ) );
    }

    /** Link a job to a manifest row created after the fact — backfilling jobs that completed before the manifest table existed (see RSD_RB_Manifest::create_from_legacy_job()). */
    public static function set_manifest_id( int $job_id, int $manifest_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . RSD_RB_TABLE,
            array( 'manifest_id' => $manifest_id ),
            array( 'id' => $job_id ),
            array( '%d' ),
            array( '%d' )
        );
    }

    /**
     * Repair a job that has no recorded filepath — always true for a job
     * created via enqueue_remote() (discovered purely on the remote side, with
     * no local file at the time). Such a job can never resolve as "local" via
     * file_exists(), even once a real file reappears on disk under its
     * filename (e.g. after Download & prepare stages it back into place),
     * because empty() short-circuits before file_exists() is ever reached.
     * Called by resync once it's confirmed the expected filename-based path
     * now actually exists.
     */
    public static function set_filepath( int $job_id, string $filepath ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . RSD_RB_TABLE,
            array( 'filepath' => $filepath ),
            array( 'id' => $job_id ),
            array( '%s' ),
            array( '%d' )
        );
    }
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Durable per-backup record — one row per detected .wpress file, retained
 * indefinitely (never deleted), independent of rsd_rb_jobs (the live upload
 * queue). This is the record a future restore phase will query, so field
 * names/status values are fixed by backup-compression-pipeline.md — don't
 * rename them without checking back against that doc.
 *
 * Every method takes the manifest id and no-ops on empty/0 — jobs enqueued
 * before this table existed (or discovered via resync, which has no local
 * detection step) have no manifest row, and that's expected.
 */
class RSD_RB_Manifest {

    // Pipeline status (spec: detected → compressing → compressed|compress_failed
    // → uploading → uploaded|upload_failed → cleaned_up → complete).
    const STATUS_DETECTED        = 'detected';
    const STATUS_COMPRESSING     = 'compressing';
    const STATUS_COMPRESSED      = 'compressed';
    const STATUS_COMPRESS_FAILED = 'compress_failed';
    const STATUS_UPLOADING       = 'uploading';
    const STATUS_UPLOADED        = 'uploaded';
    const STATUS_UPLOAD_FAILED   = 'upload_failed';
    const STATUS_CLEANED_UP      = 'cleaned_up';
    const STATUS_COMPLETE        = 'complete';

    // Upload status (narrower — mirrors the current attempt's outcome).
    const UPLOAD_PENDING   = 'pending';
    const UPLOAD_UPLOADING = 'uploading';
    const UPLOAD_UPLOADED  = 'uploaded';
    const UPLOAD_FAILED    = 'failed';

    // Download/staging status — the reverse pipeline (backup-download-restore-staging.md).
    const DOWNLOAD_NOT_STARTED   = 'not_started';
    const DOWNLOAD_DOWNLOADING   = 'downloading';
    const DOWNLOAD_DOWNLOADED    = 'downloaded';
    const DOWNLOAD_VERIFY_FAILED = 'verify_failed';
    const DOWNLOAD_STAGED        = 'staged';
    const DOWNLOAD_FAILED        = 'failed';

    // Soft cap mirroring RSD_RB_Queue::MAX_ATTEMPTS — used only to change the admin
    // screen's rendering ("failed repeatedly"), not to block further manual retries.
    const DOWNLOAD_MAX_ATTEMPTS = 5;

    // -------------------------------------------------------------------------
    // Step 1 — detection

    /**
     * Create the manifest row for a newly detected backup. Computes and stores
     * the original SHA-256 checksum + size immediately, before any further
     * processing, so verification is possible even if later steps fail.
     *
     * @return int The new manifest id, or 0 if the file couldn't be checksummed.
     */
    public static function create( string $original_filename, string $filepath, string $provider ): int {
        $checksum = self::checksum( $filepath );
        $size     = @filesize( $filepath );

        if ( null === $checksum || false === $size ) {
            RSD_RB_Logger::warning( 'Manifest: could not checksum ' . $original_filename . ' — no manifest row created.' );
            return 0;
        }

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . RSD_RB_MANIFEST_TABLE,
            array(
                'original_filename'   => $original_filename,
                'local_backup_path'   => $filepath,
                'provider'            => $provider,
                'original_size_bytes' => $size,
                'original_checksum'   => $checksum,
                'upload_status'       => self::UPLOAD_PENDING,
                'status'              => self::STATUS_DETECTED,
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Create a manifest row for a backup discovered only via resync — this
     * plugin never processed it locally (uploaded before this table existed,
     * from another install, or the local file was already gone by the time
     * resync ran). There's no local file to checksum, so original_checksum /
     * compressed_checksum are left as '' (the columns stay NOT NULL — '' is
     * the "unknown, nothing to verify against yet" sentinel) rather than a
     * value this plugin can't actually vouch for.
     *
     * RSD_RB_Download_Worker treats an empty checksum as "no baseline yet"
     * and backfills it after the first successful download/decompress,
     * rather than refusing to ever download a pre-existing backup that
     * nothing is actually wrong with.
     *
     * @param bool        $is_compressed       Inferred from the remote filename's extension.
     * @param string|null $compression_method  Inferred method (native-gzip/native-zip) if compressed, else null.
     * @return int The new manifest id.
     */
    public static function create_from_remote(
        string $original_filename,
        string $provider,
        int $remote_size_bytes,
        bool $is_compressed,
        ?string $compression_method,
        string $remote_id
    ): int {
        global $wpdb;

        $data = array(
            'original_filename'    => $original_filename,
            'provider'              => $provider,
            'original_checksum'     => '',
            'remote_path'           => $remote_id,
            'remote_is_compressed'  => $is_compressed ? 1 : 0,
            'upload_status'         => self::UPLOAD_UPLOADED,
            'status'                => self::STATUS_COMPLETE,
        );

        if ( $is_compressed ) {
            $data['compressed_size_bytes'] = $remote_size_bytes;
            $data['compression_method']    = $compression_method;
        } else {
            $data['original_size_bytes'] = $remote_size_bytes;
        }

        $wpdb->insert(
            $wpdb->prefix . RSD_RB_MANIFEST_TABLE,
            $data,
            array_fill( 0, count( $data ), '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Backfill a manifest row for a job that completed uploading before this
     * table existed (e.g. every backup uploaded before compression was added
     * in v0.3.9, or before the manifest table itself in v0.3.10) — resync
     * detects these via a confirmed remote match on a job with no manifest_id
     * and calls this so they appear on the Backups screen too.
     *
     * Unlike create_from_remote() (a backup this plugin never touched locally
     * at all), a legacy job's original local file may still be sitting on
     * disk right now — if so, we hash it for a real checksum instead of the
     * '' "unknown" sentinel, since there's no reason not to when we can.
     *
     * @param string      $remote_name        The actual remote object's name (from list_backups()), used to infer compression — job['filename'] is always the pre-compression original and can't tell us this.
     * @param bool        $is_compressed
     * @param string|null $compression_method
     */
    public static function create_from_legacy_job( array $job, string $remote_name, bool $is_compressed, ?string $compression_method ): int {
        $filepath  = $job['filepath'] ?? '';
        $has_local = '' !== $filepath && file_exists( $filepath );
        $checksum  = $has_local ? self::checksum( $filepath ) : null;

        $data = array(
            'original_filename'   => $job['filename'],
            'provider'             => $job['provider'],
            'original_checksum'    => $checksum ?? '',
            'remote_path'          => $job['remote_id'],
            'remote_is_compressed' => $is_compressed ? 1 : 0,
            'upload_status'        => self::UPLOAD_UPLOADED,
            'status'               => self::STATUS_COMPLETE,
        );

        if ( $has_local ) {
            $data['local_backup_path'] = $filepath;
        }

        if ( $is_compressed ) {
            // We know the remote object is compressed (from its name), but not
            // the original/compressed size split — job.filesize recorded
            // whatever this job actually streamed to the provider.
            $data['compressed_size_bytes'] = (int) $job['filesize'];
            $data['compression_method']    = $compression_method;
        } else {
            $data['original_size_bytes'] = (int) $job['filesize'];
        }

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . RSD_RB_MANIFEST_TABLE,
            $data,
            array_fill( 0, count( $data ), '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Sync original_size_bytes (and backfill original_checksum if empty) from
     * an actual local file — the plain .wpress on disk is authoritative for
     * "original size" regardless of what a manifest row currently records,
     * since it IS the uncompressed backup itself. Called by resync whenever a
     * job's local file is confirmed present, so a manifest row that never had
     * this (discovered via resync rather than local detection) — or somehow
     * lost it — self-heals as soon as the real file is available again, e.g.
     * right after a restore-staging download decompresses it back into place.
     *
     * Unlike backfill_checksum(), this DOES overwrite an existing
     * original_size_bytes if it disagrees with the real file — the file on
     * disk is more trustworthy than whatever a manifest row happens to say.
     */
    public static function sync_from_local_file( int $manifest_id, string $filepath ): void {
        if ( empty( $manifest_id ) || ! file_exists( $filepath ) ) {
            return;
        }

        $size = @filesize( $filepath );
        if ( false === $size || $size <= 0 ) {
            return;
        }

        $row = self::get( $manifest_id );
        if ( ! $row ) {
            return;
        }

        $data = array();
        if ( (int) $row['original_size_bytes'] !== $size ) {
            $data['original_size_bytes'] = $size;
        }
        if ( empty( $row['original_checksum'] ) ) {
            $checksum = self::checksum( $filepath );
            if ( null !== $checksum ) {
                $data['original_checksum'] = $checksum;
            }
        }

        if ( ! empty( $data ) ) {
            self::update( $manifest_id, $data );
            RSD_RB_Logger::info( 'Manifest #' . $manifest_id . ': synced original size/checksum from local file ' . basename( $filepath ) . '.' );
        }
    }

    /** Only writes when the field is currently empty — never overwrites a real, previously-verified checksum. */
    public static function backfill_checksum( int $manifest_id, string $field, string $checksum ): void {
        if ( ! in_array( $field, array( 'original_checksum', 'compressed_checksum' ), true ) ) {
            return;
        }
        $row = self::get( $manifest_id );
        if ( ! $row || ! empty( $row[ $field ] ) ) {
            return;
        }
        self::update( $manifest_id, array( $field => $checksum ) );
    }

    // -------------------------------------------------------------------------
    // Step 2 — compression decision / outcome

    /** compression_enabled was false at the time this job was processed — not an error. */
    public static function record_compression_skipped( int $manifest_id ): void {
        self::update( $manifest_id, array(
            'compression_enabled' => 0,
            'compression_method'  => null,
            'status'              => self::STATUS_COMPRESSED,
        ) );
    }

    public static function record_compression_enabled( int $manifest_id ): void {
        self::update( $manifest_id, array( 'compression_enabled' => 1 ) );
    }

    public static function mark_compressing( int $manifest_id ): void {
        self::update( $manifest_id, array( 'status' => self::STATUS_COMPRESSING ) );
    }

    /** @param array $result RSD_RB_Compressor::compress()'s return value. */
    public static function mark_compressed( int $manifest_id, array $result ): void {
        $checksum = self::checksum( $result['path'] );
        $ratio    = $result['original_size'] > 0
            ? round( $result['compressed_size'] / $result['original_size'], 4 )
            : null;

        self::update( $manifest_id, array(
            'local_zip_path'        => $result['path'],
            'compression_method'    => $result['method'],
            'compressed_size_bytes' => $result['compressed_size'],
            'compression_ratio'     => $ratio,
            'compression_time_ms'   => $result['duration_ms'],
            'compressed_checksum'   => $checksum,
            'status'                => self::STATUS_COMPRESSED,
        ) );
    }

    /** Compression was enabled but failed (no method, low disk space, or the attempt errored) — still uploads raw. */
    public static function mark_compress_failed( int $manifest_id ): void {
        self::update( $manifest_id, array( 'status' => self::STATUS_COMPRESS_FAILED ) );
    }

    // -------------------------------------------------------------------------
    // Step 3 — upload outcome

    public static function mark_uploading( int $manifest_id ): void {
        self::update( $manifest_id, array(
            'upload_status' => self::UPLOAD_UPLOADING,
            'status'        => self::STATUS_UPLOADING,
        ) );
    }

    public static function mark_uploaded( int $manifest_id, string $remote_path, bool $remote_is_compressed ): void {
        self::update( $manifest_id, array(
            'upload_status'       => self::UPLOAD_UPLOADED,
            'remote_path'         => $remote_path,
            'remote_is_compressed' => $remote_is_compressed ? 1 : 0,
            'status'              => self::STATUS_UPLOADED,
        ) );
    }

    public static function mark_upload_failed( int $manifest_id, int $attempts ): void {
        self::update( $manifest_id, array(
            'upload_status'   => self::UPLOAD_FAILED,
            'upload_attempts' => $attempts,
            'status'          => self::STATUS_UPLOAD_FAILED,
        ) );
    }

    // -------------------------------------------------------------------------
    // Steps 4-6 — cleanup + completion

    /** Advances status regardless; only flips local_zip_deleted when a zip actually existed. */
    public static function mark_zip_cleaned( int $manifest_id ): void {
        $row = self::get( $manifest_id );
        $had_zip = $row && ! empty( $row['local_zip_path'] );

        $data = array( 'status' => self::STATUS_CLEANED_UP );
        if ( $had_zip ) {
            $data['local_zip_deleted'] = 1;
            $data['local_zip_path']    = null;
        }
        self::update( $manifest_id, $data );
    }

    public static function mark_backup_cleaned( int $manifest_id ): void {
        self::update( $manifest_id, array(
            'local_backup_deleted' => 1,
            'local_backup_path'    => null,
        ) );
    }

    public static function mark_complete( int $manifest_id ): void {
        self::update( $manifest_id, array( 'status' => self::STATUS_COMPLETE ) );
    }

    // -------------------------------------------------------------------------
    // Download & staging (backup-download-restore-staging.md) — the reverse
    // pipeline. Deliberately does not track a byte offset: every retry restarts
    // the download from scratch rather than trusting a partially-written temp
    // file's size, so there is no "resume" state to persist here.

    public static function mark_downloading( int $manifest_id ): void {
        self::update( $manifest_id, array( 'download_status' => self::DOWNLOAD_DOWNLOADING ) );
    }

    public static function mark_downloaded( int $manifest_id ): void {
        self::update( $manifest_id, array( 'download_status' => self::DOWNLOAD_DOWNLOADED ) );
    }

    /** Checksum mismatch (downloaded file or post-decompression) or an unsupported decompression method. */
    public static function mark_verify_failed( int $manifest_id, string $reason ): void {
        self::update( $manifest_id, array( 'download_status' => self::DOWNLOAD_VERIFY_FAILED ) );
        RSD_RB_Logger::error( 'Manifest #' . $manifest_id . ': download verification failed — ' . $reason );
    }

    public static function mark_staged( int $manifest_id, string $staged_path ): void {
        self::update( $manifest_id, array(
            'download_status' => self::DOWNLOAD_STAGED,
            'staged_path'      => $staged_path,
            'staged_at'        => current_time( 'mysql', true ),
        ) );
    }

    public static function mark_download_failed( int $manifest_id, int $attempts ): void {
        self::update( $manifest_id, array(
            'download_status'   => self::DOWNLOAD_FAILED,
            'download_attempts' => $attempts,
        ) );
    }

    // -------------------------------------------------------------------------
    // Queries

    public static function get( int $manifest_id ): ?array {
        if ( empty( $manifest_id ) ) {
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_MANIFEST_TABLE;
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $manifest_id ), ARRAY_A );
        return $row ?: null;
    }

    public static function get_recent( int $limit = 30 ): array {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_MANIFEST_TABLE;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ) ?: array();
    }

    /**
     * Manifest rows with no known original size — used by resync to try
     * repopulating them from a local file matching the recorded filename.
     * Exists specifically to reach rows with NO linked job (e.g. an orphaned
     * row from create_from_remote()) — sync_from_local_file() alone can't
     * reach those since it's only ever called by iterating jobs.
     */
    public static function get_needing_size_sync( int $limit = 500 ): array {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_MANIFEST_TABLE;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE original_size_bytes = 0 LIMIT %d", $limit ),
            ARRAY_A
        ) ?: array();
    }

    /**
     * Rows eligible for the Backups (download & stage) admin screen — only
     * backups that finished uploading have a remote copy to download from.
     */
    public static function get_for_admin_list( int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . RSD_RB_MANIFEST_TABLE;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE upload_status = %s ORDER BY id DESC LIMIT %d",
                self::UPLOAD_UPLOADED,
                $limit
            ),
            ARRAY_A
        ) ?: array();
    }

    // -------------------------------------------------------------------------
    // Helpers

    private static function update( int $manifest_id, array $data ): void {
        if ( empty( $manifest_id ) ) {
            return;
        }
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . RSD_RB_MANIFEST_TABLE,
            $data,
            array( 'id' => $manifest_id ),
            array_fill( 0, count( $data ), '%s' ),
            array( '%d' )
        );
    }

    private static function checksum( string $filepath ): ?string {
        $hash = @hash_file( 'sha256', $filepath );
        return false !== $hash ? $hash : null;
    }
}

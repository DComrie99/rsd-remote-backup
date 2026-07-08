<?php
defined( 'ABSPATH' ) || exit;

/**
 * Scans the configured backup source directory for new, fully-written backup
 * files and enqueues them for upload.
 *
 * Stability check (two-pass):
 *   On the first scan a file is seen, we record its size + mtime.
 *   On the next scan, if size AND mtime are unchanged, the file is stable and
 *   safe to enqueue.  This prevents uploading a half-written export.
 *
 * The "seen" state is stored in a WP option (small JSON map) so it persists
 * across PHP requests.
 */
class RSD_RB_Backup_Scanner {

    const SEEN_OPTION = 'rsd_rb_seen_files';

    // -------------------------------------------------------------------------

    public static function run(): void {
        if ( ! RSD_RB_License::is_valid() ) {
            RSD_RB_Logger::warning( 'Backup scanner skipped — no valid license.' );
            return;
        }

        $source     = RSD_RB_Settings::get_backup_source_config();
        $backup_dir = $source['dir'];
        $ext        = $source['ext'];

        if ( ! is_dir( $backup_dir ) ) {
            RSD_RB_Logger::warning( 'Backup scanner: directory not found — ' . $backup_dir );
            return;
        }

        $provider = RSD_RB_Settings::get_provider();
        if ( empty( $provider ) ) {
            RSD_RB_Logger::warning( 'Backup scanner: no provider configured.' );
            return;
        }

        $files   = glob( trailingslashit( $backup_dir ) . '*.' . $ext );
        $files   = is_array( $files ) ? $files : array();
        $seen    = self::get_seen();
        $queued  = 0;

        foreach ( $files as $filepath ) {
            $basename = basename( $filepath );

            // Already in the jobs table — skip.
            if ( RSD_RB_Queue::file_is_known( $basename, $provider ) ) {
                // Clean up from the seen map if it was there.
                unset( $seen[ $basename ] );
                continue;
            }

            $size  = filesize( $filepath );
            $mtime = filemtime( $filepath );

            if ( false === $size || false === $mtime ) {
                RSD_RB_Logger::warning( 'Backup scanner: could not stat ' . $basename . ' — skipping.' );
                continue;
            }

            if ( isset( $seen[ $basename ] ) ) {
                $prev = $seen[ $basename ];

                // Stable if both size and mtime are unchanged since last scan.
                if ( (int) $prev['size'] === $size && (int) $prev['mtime'] === $mtime ) {
                    // Manifest row first — computes the SHA-256 checksum needed for
                    // future restore verification before any other processing happens.
                    $manifest_id = RSD_RB_Manifest::create( $basename, $filepath, $provider );
                    RSD_RB_Queue::enqueue( $basename, $filepath, $size, $provider, $manifest_id );
                    RSD_RB_Logger::info( 'Backup scanner: enqueued ' . $basename . ' (' . size_format( $size, 2 ) . ').' );
                    ++$queued;
                    unset( $seen[ $basename ] );
                    continue;
                }

                // File changed since last scan — update the record and wait another tick.
                $seen[ $basename ] = array( 'size' => $size, 'mtime' => $mtime );
                RSD_RB_Logger::info( 'Backup scanner: ' . $basename . ' still changing — will recheck next tick.' );

            } else {
                // First time we've seen this file — record and wait.
                $seen[ $basename ] = array( 'size' => $size, 'mtime' => $mtime );
                RSD_RB_Logger::info( 'Backup scanner: first sight of ' . $basename . ' — stability check pending.' );
            }
        }

        // Prune entries for files that no longer exist.
        $existing_basenames = array_map( 'basename', $files );
        foreach ( array_keys( $seen ) as $name ) {
            if ( ! in_array( $name, $existing_basenames, true ) ) {
                unset( $seen[ $name ] );
            }
        }

        self::save_seen( $seen );

        // Cancel any pending jobs whose file has since been deleted from disk.
        self::fail_missing_pending_jobs();

        // Defensive: remove any orphaned compressed temp files (e.g. left behind by a
        // PHP crash mid-compression) that no in-flight job still points at.
        RSD_RB_Compressor::sweep_stale_temp_files();

        if ( $queued > 0 ) {
            RSD_RB_Logger::info( 'Backup scanner: scan complete — ' . $queued . ' file(s) enqueued.' );
        } else {
            RSD_RB_Logger::info( 'Backup scanner: scan complete — no new files to enqueue.' );
        }
    }

    /**
     * Walk all pending DB rows; fail any whose stored filepath no longer exists.
     * Runs at the end of every scan so orphaned pending jobs are cleaned up
     * automatically before the next upload trigger.
     */
    private static function fail_missing_pending_jobs(): void {
        $pending = RSD_RB_Queue::get_jobs( RSD_RB_Queue::STATUS_PENDING );
        foreach ( $pending as $job ) {
            if ( ! empty( $job['filepath'] ) && ! file_exists( $job['filepath'] ) ) {
                RSD_RB_Queue::mark_file_missing( (int) $job['id'] );
            }
        }
    }

    // -------------------------------------------------------------------------

    /** Absolute path to the active backup source directory. */
    public static function backup_dir(): string {
        return RSD_RB_Settings::get_backup_source_config()['dir'];
    }

    /**
     * Log every file currently sitting in the two directories this plugin
     * cares about — the configured backup source directory (walked
     * recursively, all subfolders included) AND its own compressed-temp-file
     * directory — a raw, unfiltered directory listing, deliberately
     * independent of file_is_known(), the two-pass stability check, job
     * status, and the configured backup extension. run() only ever reports
     * on files it hasn't already queued, only ever looks for the one
     * configured extension, and never descends into subfolders (AI1WM's own
     * directory is normally flat, but a site may have organised things
     * differently); this exists specifically to spot anything unexpected —
     * a stray temp file, a partial/interrupted upload's leftover compressed
     * copy, a `.wpress`-or-otherwise file buried in a subfolder — on a site
     * where the admin has no direct filesystem access to just look
     * themselves. The "Scan Backup Files" manual action.
     *
     * Filenames are logged via info_unredacted() — never the redacting
     * info() — since a real backup filename is exactly the kind of long
     * alnum/dot run the secret-redaction heuristic swallows whole, and
     * showing the actual filename is the entire point of this feature.
     *
     * @return int Total number of files found across both directories.
     */
    public static function log_all_files(): int {
        $backup_dir = self::backup_dir();
        $temp_dir   = RSD_RB_Compressor::temp_dir();

        $total  = self::log_directory( $backup_dir, 'backup source' );
        $total += self::log_directory( $temp_dir, 'compression temp', array( 'index.php', '.htaccess' ) );

        return $total;
    }

    /**
     * Recursively list every file under $dir — all subfolders, no extension
     * filter — logging each one, and return how many were found. $exclude
     * names (e.g. the temp dir's own protective index.php/.htaccess) are
     * skipped entirely — noise on every scan, never a backup or orphaned
     * file. Logged path is relative to $dir so a file's subfolder location
     * is visible, not just its basename.
     */
    private static function log_directory( string $dir, string $label, array $exclude = array() ): int {
        if ( ! is_dir( $dir ) ) {
            RSD_RB_Logger::warning( 'File scan: ' . $label . ' directory not found — ' . $dir );
            return 0;
        }

        $dir   = trailingslashit( $dir );
        $files = self::list_files_recursive( $dir, $exclude );

        RSD_RB_Logger::info( sprintf(
            'File scan: %d file(s) found in %s directory (%s, including subfolders).',
            count( $files ),
            $label,
            $dir
        ) );

        foreach ( $files as $filepath ) {
            $relative = ltrim( substr( $filepath, strlen( $dir ) ), '/\\' );
            $size     = @filesize( $filepath );
            $mtime    = @filemtime( $filepath );

            RSD_RB_Logger::info_unredacted( sprintf(
                'File scan: %s — %s, modified %s.',
                $relative,
                false !== $size ? size_format( $size, 2 ) : 'size unknown',
                false !== $mtime ? gmdate( 'Y-m-d H:i:s', $mtime ) . ' UTC' : 'time unknown'
            ) );
        }

        return count( $files );
    }

    /**
     * Recursively list every file under $dir (all levels), skipping
     * filenames in $exclude, sorted for stable, readable output. Uses SPL's
     * RecursiveDirectoryIterator rather than manual recursion — SKIP_DOTS
     * takes care of '.'/'..', and directory symlinks are not followed
     * (default behaviour), avoiding an infinite loop on a self-referential
     * symlink.
     */
    private static function list_files_recursive( string $dir, array $exclude ): array {
        $found = array();

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } catch ( Exception $e ) {
            RSD_RB_Logger::warning( 'File scan: could not read directory — ' . $e->getMessage() );
            return $found;
        }

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() || in_array( $file->getFilename(), $exclude, true ) ) {
                continue;
            }
            $found[] = $file->getPathname();
        }

        sort( $found );

        return $found;
    }

    // -------------------------------------------------------------------------

    private static function get_seen(): array {
        $data = get_option( self::SEEN_OPTION, array() );
        return is_array( $data ) ? $data : array();
    }

    private static function save_seen( array $seen ): void {
        update_option( self::SEEN_OPTION, $seen, false );
    }
}

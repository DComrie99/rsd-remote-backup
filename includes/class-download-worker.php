<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reverse of RSD_RB_Upload_Worker: downloads an already-uploaded backup back
 * to this server, verifies it, decompresses it if needed, and stages the
 * plain .wpress file into the AI1WM backup directory for a manual restore.
 * See backup-download-restore-staging.md.
 *
 * Dispatched via Action Scheduler (preferred) or a WP-Cron single event
 * (fallback), same as the upload worker, with the same per-invocation time
 * budget so an 800MB+ transfer doesn't blow max_execution_time in one request.
 *
 * Retry model differs from uploads deliberately: there is no bytes-downloaded
 * DB column. Ticks within one unbroken attempt resume via the temp file's
 * on-disk size (unavoidable for any chunked transfer). But a FAILED attempt
 * (an exception was thrown) always deletes its temp file — the next manual
 * "Download & prepare" click starts a completely fresh download rather than
 * trusting the tail of a partially-written file that a caught error already
 * told us something went wrong with.
 */
class RSD_RB_Download_Worker {

    const AS_HOOK   = 'rsd_rb_process_download';
    const CRON_HOOK = 'rsd_rb_process_download_cron';

    // -------------------------------------------------------------------------
    // Scheduling

    public static function schedule( int $manifest_id ): void {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::AS_HOOK, array( 'manifest_id' => $manifest_id ), 'rsd-rb' );
        } else {
            wp_schedule_single_event( time(), self::CRON_HOOK, array( $manifest_id ) );
        }
    }

    // -------------------------------------------------------------------------
    // Entry point (called by AS or WP-Cron)

    public static function process( int $manifest_id ): void {
        $start       = microtime( true );
        $time_budget = RSD_RB_Settings::get_time_budget();

        $manifest = RSD_RB_Manifest::get( $manifest_id );
        if ( ! $manifest ) {
            RSD_RB_Logger::warning( 'Download worker: manifest #' . $manifest_id . ' not found.' );
            return;
        }
        if ( RSD_RB_Manifest::UPLOAD_UPLOADED !== $manifest['upload_status'] ) {
            RSD_RB_Logger::warning( 'Download worker: manifest #' . $manifest_id . ' is not uploaded — nothing to download.' );
            return;
        }

        $adapter = RSD_RB_Plugin::get_instance()->get_provider( $manifest['provider'] );
        if ( ! $adapter ) {
            RSD_RB_Manifest::mark_download_failed( $manifest_id, (int) $manifest['download_attempts'] + 1 );
            RSD_RB_Logger::error( 'Download worker: unknown provider "' . $manifest['provider'] . '" for manifest #' . $manifest_id . '.' );
            return;
        }

        $download_path     = self::download_temp_path( $manifest_id );
        $decompressed_path = self::decompressed_temp_path( $manifest_id );

        try {
            self::do_download( $adapter, $manifest, $download_path, $start, $time_budget );
        } catch ( RuntimeException $e ) {
            RSD_RB_Compressor::cleanup( $download_path );
            RSD_RB_Compressor::cleanup( $decompressed_path );
            RSD_RB_Manifest::mark_download_failed( $manifest_id, (int) $manifest['download_attempts'] + 1 );
            RSD_RB_Logger::error( 'Download worker: manifest #' . $manifest_id . ' failed — ' . $e->getMessage() );
        }
    }

    // -------------------------------------------------------------------------

    private static function do_download( RB_Provider $adapter, array $manifest, string $download_path, float $start, int $time_budget ): void {
        $manifest_id   = (int) $manifest['id'];
        $remote_id     = (string) $manifest['remote_path']; // stores the provider's remote file id, despite the column name
        $expected_size = $manifest['remote_is_compressed']
            ? (int) $manifest['compressed_size_bytes']
            : (int) $manifest['original_size_bytes'];

        // Disk headroom is only checked at the very start of a fresh attempt
        // (no temp file yet) — a mid-attempt reschedule tick is continuing work
        // already accounted for, not starting new consumption.
        if ( ! file_exists( $download_path ) && ! self::check_download_headroom( $expected_size ) ) {
            throw new RuntimeException( 'Insufficient disk space to download and stage this backup (need ~' . size_format( $expected_size * 2, 2 ) . ' free).' );
        }

        $chunk_size = self::chunk_size_for( $adapter );
        $offset     = file_exists( $download_path ) ? (int) filesize( $download_path ) : 0;

        while ( true ) {
            if ( ( microtime( true ) - $start ) >= $time_budget ) {
                RSD_RB_Logger::info( sprintf(
                    'Download worker: manifest #%d time budget reached at %s — rescheduling.',
                    $manifest_id,
                    size_format( $offset, 2 )
                ) );
                self::schedule( $manifest_id );
                return;
            }

            $result = $adapter->download_chunk( $remote_id, $offset, $chunk_size );

            if ( '' === $result['bytes'] && ! $result['eof'] ) {
                // No data and provider doesn't claim EOF — refuse to spin forever.
                throw new RuntimeException( 'Provider returned no data before reporting end of file.' );
            }

            if ( '' !== $result['bytes'] ) {
                $fh = fopen( $download_path, 'ab' );
                if ( ! $fh ) {
                    throw new RuntimeException( 'Cannot open temp file for writing: ' . basename( $download_path ) );
                }
                fwrite( $fh, $result['bytes'] );
                fclose( $fh );
                $offset += strlen( $result['bytes'] );
            }

            if ( $result['eof'] ) {
                break;
            }
        }

        RSD_RB_Manifest::mark_downloaded( $manifest_id );
        self::verify_and_stage( $manifest, $download_path );
    }

    /**
     * Checksum the downloaded file, decompress + re-verify if needed, then move
     * the verified plain .wpress into the AI1WM backup directory. Every failure
     * branch here is a terminal, expected outcome (bad data), not a RuntimeException —
     * it sets download_status = verify_failed directly and cleans up its own temp
     * files, rather than bubbling up to process()'s generic retry-attempt handling.
     *
     * Backups discovered via resync (RSD_RB_Manifest::create_from_remote()) have
     * no recorded checksum — this plugin never had the local file to hash. An
     * empty expected checksum means "no baseline to verify against", not "verify
     * against empty string" — we trust that download and backfill the checksum so
     * every subsequent download of the same backup IS properly verified.
     */
    private static function verify_and_stage( array $manifest, string $download_path ): void {
        $manifest_id    = (int) $manifest['id'];
        $checksum_field = $manifest['remote_is_compressed'] ? 'compressed_checksum' : 'original_checksum';
        $expected       = $manifest[ $checksum_field ];
        $actual         = @hash_file( 'sha256', $download_path );

        if ( false === $actual ) {
            RSD_RB_Compressor::cleanup( $download_path );
            RSD_RB_Manifest::mark_verify_failed( $manifest_id, 'could not checksum the downloaded file.' );
            return;
        }

        if ( ! empty( $expected ) ) {
            if ( ! hash_equals( (string) $expected, $actual ) ) {
                RSD_RB_Compressor::cleanup( $download_path );
                RSD_RB_Manifest::mark_verify_failed( $manifest_id, 'downloaded file checksum mismatch.' );
                return;
            }
        } else {
            RSD_RB_Logger::info( 'Download worker: manifest #' . $manifest_id . ' has no recorded ' . $checksum_field . ' — trusting this download and recording its checksum for future verification.' );
            RSD_RB_Manifest::backfill_checksum( $manifest_id, $checksum_field, $actual );
        }

        $plain_path = $download_path;

        if ( $manifest['remote_is_compressed'] ) {
            $method             = (string) $manifest['compression_method'];
            $decompressed_path  = self::decompressed_temp_path( $manifest_id );

            if ( ! RSD_RB_Compressor::method_available( $method ) ) {
                RSD_RB_Compressor::cleanup( $download_path );
                RSD_RB_Manifest::mark_verify_failed( $manifest_id, 'decompression method "' . $method . '" recorded on this backup is not available on this server.' );
                return;
            }

            if ( ! RSD_RB_Compressor::decompress( $download_path, $decompressed_path, $method ) ) {
                RSD_RB_Compressor::cleanup( $download_path );
                RSD_RB_Compressor::cleanup( $decompressed_path );
                RSD_RB_Manifest::mark_verify_failed( $manifest_id, 'decompression failed.' );
                return;
            }

            $decompressed_actual = @hash_file( 'sha256', $decompressed_path );
            if ( false === $decompressed_actual ) {
                RSD_RB_Compressor::cleanup( $download_path );
                RSD_RB_Compressor::cleanup( $decompressed_path );
                RSD_RB_Manifest::mark_verify_failed( $manifest_id, 'could not checksum the decompressed file.' );
                return;
            }

            if ( ! empty( $manifest['original_checksum'] ) ) {
                if ( ! hash_equals( (string) $manifest['original_checksum'], $decompressed_actual ) ) {
                    RSD_RB_Compressor::cleanup( $download_path );
                    RSD_RB_Compressor::cleanup( $decompressed_path );
                    RSD_RB_Manifest::mark_verify_failed( $manifest_id, 'decompressed file checksum mismatch.' );
                    return;
                }
            } else {
                RSD_RB_Logger::info( 'Download worker: manifest #' . $manifest_id . ' has no recorded original_checksum — trusting the decompressed result and recording its checksum.' );
                RSD_RB_Manifest::backfill_checksum( $manifest_id, 'original_checksum', $decompressed_actual );
            }

            // Compressed temp copy is no longer needed — the decompressed file is what gets staged.
            RSD_RB_Compressor::cleanup( $download_path );
            $plain_path = $decompressed_path;
        }

        self::stage( $manifest_id, (string) $manifest['original_filename'], $plain_path );
    }

    /** Move (not copy) the verified plain file into the common backup directory. */
    private static function stage( int $manifest_id, string $original_filename, string $plain_path ): void {
        $target_dir = trailingslashit( RSD_RB_Settings::get_backup_source_config()['dir'] );
        if ( ! is_dir( $target_dir ) ) {
            wp_mkdir_p( $target_dir );
        }
        $target = $target_dir . $original_filename;

        // Re-staging the same backup (e.g. after a prior restore attempt) should
        // replace any earlier staged copy rather than fail.
        if ( file_exists( $target ) ) {
            @unlink( $target );
        }

        if ( ! @rename( $plain_path, $target ) ) {
            throw new RuntimeException( 'Could not move staged file into place: ' . basename( $target ) );
        }

        RSD_RB_Manifest::mark_staged( $manifest_id, $target );
        RSD_RB_Logger::info( 'Download worker: manifest #' . $manifest_id . ' staged at ' . $target . '.' );
    }

    // -------------------------------------------------------------------------
    // Helpers

    private static function check_download_headroom( int $expected_size ): bool {
        $target_dir = RSD_RB_Settings::get_backup_source_config()['dir'];
        $free       = @disk_free_space( $target_dir );
        if ( false === $free ) {
            return true; // Can't determine — don't block on an unknown.
        }

        $needed = $expected_size * 2; // Room for the download plus, if compressed, the decompressed output.
        if ( $free < $needed ) {
            RSD_RB_Logger::warning( sprintf(
                'Download: insufficient disk space at %s (free %s, need ~%s).',
                $target_dir,
                size_format( $free, 2 ),
                size_format( $needed, 2 )
            ) );
            return false;
        }

        return true;
    }

    private static function chunk_size_for( RB_Provider $adapter ): int {
        if ( $adapter instanceof RSD_RB_Provider_Google_Drive ) {
            return RSD_RB_Provider_Google_Drive::CHUNK_SIZE;
        }
        if ( $adapter instanceof RSD_RB_Provider_OneDrive ) {
            return RSD_RB_Provider_OneDrive::CHUNK_SIZE;
        }
        return 8 * 1024 * 1024;
    }

    private static function download_temp_path( int $manifest_id ): string {
        return RSD_RB_Compressor::ensure_temp_dir() . 'download-' . $manifest_id . '.tmp';
    }

    private static function decompressed_temp_path( int $manifest_id ): string {
        return RSD_RB_Compressor::ensure_temp_dir() . 'download-' . $manifest_id . '.decompressed.tmp';
    }
}

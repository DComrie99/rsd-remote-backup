<?php
defined( 'ABSPATH' ) || exit;

/**
 * Optionally compresses a .wpress file (fast/low compression level) before it
 * is chunked and uploaded, since it's pure upstream bytes saved.
 *
 * Method selection is auto-detected per site (this plugin runs on many
 * different hosts with different exec()/shell_exec() restrictions) and
 * cached for a day:
 *   native gzip binary  → fastest, preferred when exec() is available.
 *   native zip binary   → fallback if gzip isn't on PATH.
 *   PHP ZipArchive      → fallback when exec() is disabled entirely.
 *   none                → compression skipped, original file uploaded as-is.
 */
class RSD_RB_Compressor {

    const METHOD_OPTION         = 'rsd_rb_compression_method';
    const METHOD_CHECKED_OPTION = 'rsd_rb_compression_method_checked_at';
    const BENCHMARK_OPTION      = 'rsd_rb_compression_benchmark';
    const RECHECK_INTERVAL      = DAY_IN_SECONDS;
    const BENCHMARK_SAMPLE_BYTES = 8 * 1024 * 1024; // 8 MiB

    // -------------------------------------------------------------------------
    // Public API

    public static function is_enabled(): bool {
        return RSD_RB_Settings::get_compress_enabled();
    }

    /**
     * Compress a backup file ahead of upload. Caller (RSD_RB_Upload_Worker) is
     * responsible for checking is_enabled() first — a null return from here
     * always means "compression was attempted and failed" (no method, low disk
     * space, or the attempt errored), never "compression was skipped by config".
     * That distinction matters for the manifest's compress_failed vs. skipped state.
     *
     * @return array{path:string,method:string,original_size:int,compressed_size:int,duration_ms:int}|null
     */
    public static function compress( string $filepath ): ?array {
        $method = self::detect_method();
        if ( 'none' === $method ) {
            return null;
        }

        $filesize = @filesize( $filepath );
        if ( false === $filesize || $filesize <= 0 ) {
            return null;
        }

        if ( ! self::check_disk_headroom( $filesize ) ) {
            return null;
        }

        $dir  = self::ensure_temp_dir();
        $dest = $dir . basename( $filepath ) . self::extension_for( $method ) . '.' . uniqid( '', true );

        $start       = microtime( true );
        $ok          = self::do_compress_file( $filepath, $dest, $method );
        $duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( ! $ok ) {
            RSD_RB_Logger::warning( 'Compression: ' . basename( $filepath ) . ' failed via ' . $method . ' — uploading uncompressed.' );
            self::cleanup( $dest );
            return null;
        }

        $compressed_size = (int) filesize( $dest );

        RSD_RB_Logger::info( sprintf(
            'Compression: %s — %s to %s (%d%% smaller) via %s in %dms.',
            basename( $filepath ),
            size_format( $filesize, 2 ),
            size_format( $compressed_size, 2 ),
            $filesize > 0 ? round( ( 1 - $compressed_size / $filesize ) * 100 ) : 0,
            $method,
            $duration_ms
        ) );

        return array(
            'path'            => $dest,
            'method'          => $method,
            'original_size'   => $filesize,
            'compressed_size' => $compressed_size,
            'duration_ms'     => $duration_ms,
        );
    }

    /** Delete a temp file produced by compress()/benchmark(). Safe to call on any path. */
    public static function cleanup( string $path ): void {
        if ( '' === $path || ! file_exists( $path ) ) {
            return;
        }
        if ( ! @unlink( $path ) ) {
            RSD_RB_Logger::warning( 'Compression: could not delete temp file ' . basename( $path ) . '.' );
        }
    }

    // -------------------------------------------------------------------------
    // Capability detection

    /**
     * Determine (and cache for a day) which compression method this server
     * actually supports. Re-probed on demand via $force (the admin "run
     * benchmark" action always forces a fresh check).
     */
    public static function detect_method( bool $force = false ): string {
        $checked_at = (int) get_option( self::METHOD_CHECKED_OPTION, 0 );
        $cached     = (string) get_option( self::METHOD_OPTION, '' );

        if ( ! $force && '' !== $cached && ( time() - $checked_at ) < self::RECHECK_INTERVAL ) {
            return $cached;
        }

        if ( self::binary_exists( 'gzip' ) ) {
            $method = 'native-gzip';
        } elseif ( self::binary_exists( 'zip' ) ) {
            $method = 'native-zip';
        } elseif ( class_exists( 'ZipArchive' ) ) {
            $method = 'ziparchive';
        } else {
            $method = 'none';
        }

        if ( $method !== $cached ) {
            RSD_RB_Logger::info( 'Compression: detected method "' . $method . '" available on this server.' );
        }

        update_option( self::METHOD_OPTION, $method, false );
        update_option( self::METHOD_CHECKED_OPTION, time(), false );

        return $method;
    }

    public static function method_label( string $method ): string {
        switch ( $method ) {
            case 'native-gzip': return __( 'Native gzip binary', 'rsd-remote-backup' );
            case 'native-zip':  return __( 'Native zip binary', 'rsd-remote-backup' );
            case 'ziparchive':  return __( 'PHP ZipArchive', 'rsd-remote-backup' );
            default:            return __( 'Unavailable — uploads will not be compressed', 'rsd-remote-backup' );
        }
    }

    /** Compact form for table cells, e.g. the Upload Queue's Compression column. */
    public static function method_short_label( string $method ): string {
        switch ( $method ) {
            case 'native-gzip': return 'gzip';
            case 'native-zip':  return 'zip';
            case 'ziparchive':  return 'zip (PHP)';
            default:            return $method;
        }
    }

    /** function_exists() already returns false for functions blocked via disable_functions. */
    private static function binary_exists( string $bin ): bool {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }
        @exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null', $out, $code );
        return 0 === $code && ! empty( $out );
    }

    /** Public: the upload worker uses this to build a clean remote filename (basename + extension), not the local temp path's own uniqid-suffixed name. */
    public static function extension_for( string $method ): string {
        return in_array( $method, array( 'native-zip', 'ziparchive' ), true ) ? '.zip' : '.gz';
    }

    private static function do_compress_file( string $src, string $dest, string $method ): bool {
        switch ( $method ) {
            case 'native-gzip':
                @exec( sprintf( 'gzip -1 -c %s > %s 2>/dev/null', escapeshellarg( $src ), escapeshellarg( $dest ) ), $out, $code );
                return 0 === $code && file_exists( $dest ) && filesize( $dest ) > 0;

            case 'native-zip':
                @exec( sprintf( 'zip -1 -j %s %s 2>&1', escapeshellarg( $dest ), escapeshellarg( $src ) ), $out, $code );
                return 0 === $code && file_exists( $dest ) && filesize( $dest ) > 0;

            case 'ziparchive':
                if ( ! class_exists( 'ZipArchive' ) ) {
                    return false;
                }
                $zip = new ZipArchive();
                if ( true !== $zip->open( $dest, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
                    return false;
                }
                $entry = basename( $src );
                if ( ! $zip->addFile( $src, $entry ) ) {
                    $zip->close();
                    return false;
                }
                // Fast/low compression level — only available on PHP 8+; fine to skip on older PHP.
                if ( method_exists( $zip, 'setCompressionName' ) ) {
                    $zip->setCompressionName( $entry, ZipArchive::CM_DEFLATE, 1 );
                }
                $zip->close();
                return file_exists( $dest ) && filesize( $dest ) > 0;

            default:
                return false;
        }
    }

    // -------------------------------------------------------------------------
    // Decompression (download/restore-staging pipeline)

    /**
     * Inverse of compress(). $method MUST be the value frozen on the manifest
     * row at backup time (RSD_RB_Manifest::get()['compression_method']), never
     * the live detect_method() — server capabilities (exec() availability,
     * installed binaries) can change between when a backup was compressed and
     * when it's later restored. Returns false both for a genuine decompression
     * failure and for "this method is no longer available on this host" —
     * callers should check method_available() first to log a specific message
     * for the latter, distinct from a checksum-mismatch failure.
     */
    public static function decompress( string $src, string $dest, string $method ): bool {
        if ( ! self::method_available( $method ) ) {
            return false;
        }

        switch ( $method ) {
            case 'native-gzip':
                @exec( sprintf( 'gzip -dc %s > %s 2>/dev/null', escapeshellarg( $src ), escapeshellarg( $dest ) ), $out, $code );
                return 0 === $code && file_exists( $dest ) && filesize( $dest ) > 0;

            case 'native-zip':
            case 'ziparchive':
                // Both produce a standard zip archive — extraction doesn't depend
                // on which tool originally created it.
                return self::extract_single_entry_zip( $src, $dest );

            default:
                return false;
        }
    }

    /** Whether $method (a value previously recorded on a manifest row) can actually run on this host right now. */
    public static function method_available( string $method ): bool {
        switch ( $method ) {
            case 'native-gzip':
                return self::binary_exists( 'gzip' );
            case 'native-zip':
            case 'ziparchive':
                return class_exists( 'ZipArchive' ) || self::binary_exists( 'unzip' );
            default:
                return false;
        }
    }

    private static function extract_single_entry_zip( string $src, string $dest ): bool {
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( true !== $zip->open( $src ) || $zip->numFiles < 1 ) {
                return false;
            }
            $stream = $zip->getStream( $zip->getNameIndex( 0 ) );
            if ( ! $stream ) {
                $zip->close();
                return false;
            }
            $out = @fopen( $dest, 'wb' );
            if ( ! $out ) {
                fclose( $stream );
                $zip->close();
                return false;
            }
            stream_copy_to_stream( $stream, $out );
            fclose( $stream );
            fclose( $out );
            $zip->close();
            return file_exists( $dest ) && filesize( $dest ) > 0;
        }

        // No ZipArchive — fall back to the native unzip binary, extracting to a scratch dir.
        if ( self::binary_exists( 'unzip' ) ) {
            $scratch = self::ensure_temp_dir() . 'extract-' . uniqid( '', true ) . '/';
            wp_mkdir_p( $scratch );
            @exec( sprintf( 'unzip -o %s -d %s 2>&1', escapeshellarg( $src ), escapeshellarg( $scratch ) ), $out, $code );

            $extracted = 0 === $code ? glob( $scratch . '*' ) : false;
            $ok        = ! empty( $extracted ) && @rename( $extracted[0], $dest );

            self::rrmdir( $scratch );
            return $ok && file_exists( $dest ) && filesize( $dest ) > 0;
        }

        return false;
    }

    private static function rrmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( (array) glob( $dir . '*' ) as $file ) {
            is_dir( $file ) ? self::rrmdir( $file . '/' ) : @unlink( $file );
        }
        @rmdir( $dir );
    }

    // -------------------------------------------------------------------------
    // Disk headroom

    /**
     * The original file is already on disk and already counted as "used", not
     * "free" — so the new free space this needs is just room for the compressed
     * copy, worst case the same size as the original (0% reduction).
     */
    public static function check_disk_headroom( int $filesize ): bool {
        $free = @disk_free_space( trailingslashit( WP_CONTENT_DIR ) );
        if ( false === $free ) {
            return true; // Can't determine — don't block compression on an unknown.
        }

        $needed = $filesize;
        if ( $free < $needed ) {
            RSD_RB_Logger::warning( sprintf(
                'Compression: skipped for insufficient disk headroom (free %s, need ~%s).',
                size_format( $free, 2 ),
                size_format( $needed, 2 )
            ) );
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Temp directory

    public static function temp_dir(): string {
        return trailingslashit( WP_CONTENT_DIR ) . 'rsd-rb-tmp/';
    }

    public static function ensure_temp_dir(): string {
        $dir = self::temp_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $index = $dir . 'index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
        $htaccess = $dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Deny from all\n" );
        }
        return $dir;
    }

    /**
     * Delete stray temp files older than $max_age_seconds. Guards against a PHP
     * crash mid-compression leaving orphaned files. Never deletes a file a
     * pending/uploading job's upload_path still points at.
     */
    public static function sweep_stale_temp_files( int $max_age_seconds = DAY_IN_SECONDS ): int {
        $dir = self::temp_dir();
        if ( ! is_dir( $dir ) ) {
            return 0;
        }

        $active_paths = array();
        foreach ( array( RSD_RB_Queue::STATUS_PENDING, RSD_RB_Queue::STATUS_UPLOADING ) as $status ) {
            foreach ( RSD_RB_Queue::get_jobs( $status, 500 ) as $job ) {
                if ( ! empty( $job['upload_path'] ) ) {
                    $active_paths[] = $job['upload_path'];
                }
            }
        }

        $deleted = 0;
        foreach ( (array) glob( $dir . '*' ) as $file ) {
            if ( ! is_file( $file ) || in_array( basename( $file ), array( 'index.php', '.htaccess' ), true ) ) {
                continue;
            }
            if ( in_array( $file, $active_paths, true ) ) {
                continue;
            }
            $mtime = filemtime( $file );
            if ( false !== $mtime && ( time() - $mtime ) > $max_age_seconds ) {
                if ( @unlink( $file ) ) {
                    ++$deleted;
                }
            }
        }

        if ( $deleted > 0 ) {
            RSD_RB_Logger::info( 'Compression: swept ' . $deleted . ' stale temp file(s).' );
        }

        return $deleted;
    }

    // -------------------------------------------------------------------------
    // Self-benchmark

    /**
     * Time compression of a sample slice of a real backup file and estimate the
     * ratio achieved, so an admin can judge whether compression is worth the CPU
     * cost on this particular host (some hosts have generous bandwidth but
     * strict CPU throttling, where compression could actually hurt).
     *
     * @return array{method:string,sample_size:int,compressed_size:int,ratio:float,duration_ms:int,checked_at:string}|array{error:string}
     */
    public static function benchmark( ?string $sample_source = null ): array {
        if ( null === $sample_source ) {
            $sample_source = self::find_sample_file();
        }

        if ( null === $sample_source || ! file_exists( $sample_source ) ) {
            RSD_RB_Logger::warning( 'Compression benchmark: no backup file available to sample.' );
            return array( 'error' => 'no_sample_available' );
        }

        $method = self::detect_method( true );
        if ( 'none' === $method ) {
            RSD_RB_Logger::warning( 'Compression benchmark: no compression method available on this server.' );
            return array( 'error' => 'no_method_available' );
        }

        $dir         = self::ensure_temp_dir();
        $sample_path = $dir . 'benchmark-sample-' . uniqid( '', true ) . '.tmp';
        $sample_size = self::write_sample( $sample_source, $sample_path, self::BENCHMARK_SAMPLE_BYTES );

        if ( $sample_size <= 0 ) {
            RSD_RB_Logger::warning( 'Compression benchmark: sample file was empty.' );
            self::cleanup( $sample_path );
            return array( 'error' => 'empty_sample' );
        }

        $dest = $sample_path . self::extension_for( $method );

        $start       = microtime( true );
        $ok          = self::do_compress_file( $sample_path, $dest, $method );
        $duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( ! $ok ) {
            RSD_RB_Logger::warning( 'Compression benchmark: compression attempt failed via ' . $method . '.' );
            self::cleanup( $sample_path );
            self::cleanup( $dest );
            return array( 'error' => 'compression_failed' );
        }

        $compressed_size = (int) filesize( $dest );
        $ratio           = $sample_size > 0 ? round( $compressed_size / $sample_size, 3 ) : 1.0;
        $savings_pct      = (int) round( ( 1 - $ratio ) * 100 );

        $result = array(
            'method'          => $method,
            'sample_size'     => $sample_size,
            'compressed_size' => $compressed_size,
            'ratio'           => $ratio,
            'duration_ms'     => $duration_ms,
            'checked_at'      => current_time( 'mysql', true ),
        );

        update_option( self::BENCHMARK_OPTION, $result, false );

        $verdict = $savings_pct >= 15
            ? 'looks worthwhile on this content'
            : ( $savings_pct <= 3
                ? 'little benefit here — consider disabling if CPU is constrained'
                : 'modest benefit' );

        RSD_RB_Logger::info( sprintf(
            'Compression benchmark: %s sample → %s (%d%% smaller) via %s in %dms — %s.',
            size_format( $sample_size, 2 ),
            size_format( $compressed_size, 2 ),
            $savings_pct,
            $method,
            $duration_ms,
            $verdict
        ) );

        self::cleanup( $sample_path );
        self::cleanup( $dest );

        return $result;
    }

    public static function get_last_benchmark(): ?array {
        $value = get_option( self::BENCHMARK_OPTION, null );
        return is_array( $value ) ? $value : null;
    }

    private static function find_sample_file(): ?string {
        $config = RSD_RB_Settings::get_backup_source_config();
        $files  = glob( trailingslashit( $config['dir'] ) . '*.' . $config['ext'] );
        if ( empty( $files ) ) {
            return null;
        }
        usort( $files, static function ( $a, $b ) {
            return filemtime( $b ) <=> filemtime( $a );
        } );
        return $files[0];
    }

    private static function write_sample( string $source, string $dest, int $max_bytes ): int {
        $in = @fopen( $source, 'rb' );
        if ( ! $in ) {
            return 0;
        }
        $out = @fopen( $dest, 'wb' );
        if ( ! $out ) {
            fclose( $in );
            return 0;
        }

        $written = 0;
        while ( $written < $max_bytes && ! feof( $in ) ) {
            $chunk = fread( $in, min( 1024 * 1024, $max_bytes - $written ) );
            if ( false === $chunk || '' === $chunk ) {
                break;
            }
            fwrite( $out, $chunk );
            $written += strlen( $chunk );
        }

        fclose( $in );
        fclose( $out );

        return $written;
    }
}

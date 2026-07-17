<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-off, resumable disk-usage scan of the WordPress install (ABSPATH
 * down), for diagnosing sudden backup/disk-usage growth without needing
 * cPanel's own file manager.
 *
 * Deliberately NOT a periodic background task (no WP-Cron/Action Scheduler
 * hook at all) — this is started manually and advances itself a few
 * seconds at a time purely by the admin screen redirecting to itself (see
 * the Disk Usage tab in admin/views/maintenance-page.php) while that browser
 * tab stays open. If the admin navigates away or closes the tab mid-scan,
 * progress simply pauses (state is fully persisted) and resumes the next
 * time the Disk Usage tab is viewed. Runs at most a few times a year, so
 * this simplicity was chosen deliberately over building the same
 * WP-Cron/Action Scheduler machinery the upload/download workers use.
 */
class RSD_RB_Disk_Scanner {

    const OPTION           = 'rsd_rb_disk_scan_state';
    const CHUNK_SECONDS    = 5;
    const MAX_FILES_LISTED = 500;

    public static function get_state(): array {
        return get_option( self::OPTION, self::default_state() );
    }

    private static function default_state(): array {
        return array(
            'status'        => 'idle', // idle | running | complete
            'root'          => '',
            'stack'         => array(),
            'own_size'      => array(),
            'own_mtime'     => array(),
            'children'      => array(),
            'totals'        => null,
            'mtime_totals'  => null,
            'files_scanned' => 0,
            'dirs_scanned'  => 0,
            'errors'        => array(),
            'started_at'    => 0,
            'completed_at'  => 0,
        );
    }

    public static function start(): void {
        $root = rtrim( ABSPATH, '/\\' );
        update_option(
            self::OPTION,
            array(
                'status'        => 'running',
                'root'          => $root,
                'stack'         => array( array( 't' => 'dir', 'path' => $root ) ),
                'own_size'      => array(),
                'own_mtime'     => array(),
                'children'      => array(),
                'totals'        => null,
                'mtime_totals'  => null,
                'files_scanned' => 0,
                'dirs_scanned'  => 1, // the root itself
                'errors'        => array(),
                'started_at'    => time(),
                'completed_at'  => 0,
            ),
            false
        );
    }

    public static function cancel(): void {
        delete_option( self::OPTION );
    }

    /**
     * Walks as much of the tree as fits in CHUNK_SECONDS, then persists
     * progress and returns. Uses an explicit stack rather than recursive
     * function calls so the walk can stop and resume mid-tree — each call
     * to this method is a brand new PHP request picking up exactly where
     * the previous one left off.
     *
     * The stack holds two kinds of task so the deadline can be checked
     * after every single file/folder, not just between directories: a
     * 'dir' task (not yet listed — scandir() hasn't run on it) and an
     * 'entries' task (already listed, entries not yet all classified). A
     * single directory containing hundreds of thousands of files — exactly
     * the kind of thing this tool exists to find — would otherwise let one
     * unbroken foreach loop blow straight through the time budget with no
     * chance to stop, which is exactly the risk this two-task-type split
     * avoids: interrupting mid-directory just means the remaining,
     * not-yet-classified entries get pushed back as a fresh 'entries' task
     * and picked up again at the start of the next chunk.
     */
    public static function run_chunk(): void {
        $state = self::get_state();
        if ( 'running' !== $state['status'] ) {
            return;
        }

        $deadline  = microtime( true ) + self::CHUNK_SECONDS;
        $stack     = $state['stack'];
        $own_size  = $state['own_size'];
        $own_mtime = $state['own_mtime'];
        $children  = $state['children'];
        $errors    = $state['errors'];
        $files     = $state['files_scanned'];
        $dirs      = $state['dirs_scanned'];

        while ( ! empty( $stack ) ) {
            $task = array_pop( $stack );

            if ( 'dir' === $task['t'] ) {
                $dir     = $task['path'];
                $entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

                if ( false === $entries ) {
                    if ( count( $errors ) < 20 ) {
                        $errors[] = $dir;
                    }
                } else {
                    if ( ! isset( $own_size[ $dir ] ) ) {
                        $own_size[ $dir ] = 0;
                    }
                    if ( ! isset( $children[ $dir ] ) ) {
                        $children[ $dir ] = array();
                    }
                    $entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
                    if ( ! empty( $entries ) ) {
                        $stack[] = array(
                            't'       => 'entries',
                            'dir'     => $dir,
                            'entries' => $entries,
                        );
                    }
                }
            } else { // 'entries'
                $dir     = $task['dir'];
                $entries = $task['entries'];

                while ( ! empty( $entries ) ) {
                    $entry = array_pop( $entries );
                    $path  = $dir . DIRECTORY_SEPARATOR . $entry;

                    if ( is_link( $path ) ) {
                        continue; // Never follow symlinks — avoids traversal cycles.
                    }

                    if ( is_dir( $path ) ) {
                        $children[ $dir ][] = $path;
                        $stack[]            = array( 't' => 'dir', 'path' => $path );
                        ++$dirs;
                    } else {
                        // filesize()/filemtime() on the same path share PHP's own
                        // per-request stat cache, so the second call here is
                        // effectively free — no meaningful extra cost for tracking
                        // both.
                        $size              = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                        $mtime             = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                        $own_size[ $dir ] += ( false !== $size ) ? $size : 0;
                        if ( false !== $mtime && ( ! isset( $own_mtime[ $dir ] ) || $mtime > $own_mtime[ $dir ] ) ) {
                            $own_mtime[ $dir ] = $mtime;
                        }
                        ++$files;
                    }

                    if ( microtime( true ) >= $deadline ) {
                        break;
                    }
                }

                if ( ! empty( $entries ) ) {
                    // Deadline hit mid-directory — save the remainder to resume next chunk.
                    $stack[] = array(
                        't'       => 'entries',
                        'dir'     => $dir,
                        'entries' => $entries,
                    );
                }
            }

            if ( microtime( true ) >= $deadline ) {
                break;
            }
        }

        $state['stack']         = $stack;
        $state['own_size']      = $own_size;
        $state['own_mtime']     = $own_mtime;
        $state['children']      = $children;
        $state['errors']        = $errors;
        $state['files_scanned'] = $files;
        $state['dirs_scanned']  = $dirs;

        if ( empty( $stack ) ) {
            $state['status']       = 'complete';
            $state['completed_at'] = time();
            $state['totals']       = self::compute_totals( $state['root'], $own_size, $children );
            $state['mtime_totals'] = self::compute_mtime_totals( $state['root'], $own_mtime, $children );
        }

        update_option( self::OPTION, $state, false );
    }

    /**
     * Bottom-up recursive-size aggregation over the already-walked tree —
     * pure in-memory array math, no further disk access. Safe as plain
     * (non-chunked) recursion: recursion depth tracks folder NESTING depth
     * (a few dozen at most on any real WordPress install), not total file
     * or folder count.
     *
     * @return array<string,int>
     */
    private static function compute_totals( string $root, array $own_size, array $children ): array {
        $totals = array();
        $visit  = static function ( string $path ) use ( &$visit, &$totals, $own_size, $children ) {
            if ( isset( $totals[ $path ] ) ) {
                return $totals[ $path ];
            }
            $sum = $own_size[ $path ] ?? 0;
            foreach ( $children[ $path ] ?? array() as $child ) {
                $sum += $visit( $child );
            }
            $totals[ $path ] = $sum;
            return $sum;
        };
        $visit( $root );
        return $totals;
    }

    /**
     * Bottom-up "most recently modified file anywhere in this subtree"
     * aggregation — same shape as compute_totals() but taking the max
     * mtime instead of a sum, and null-safe (a folder with no files
     * anywhere beneath it, direct or nested, has no mtime signal at all).
     * Far more useful than a folder's own filesystem mtime for spotting
     * where new/changed content landed — a directory entry's own mtime only
     * changes when something is added/removed/renamed directly inside it,
     * not when a file deep inside is modified, and never propagates to
     * ancestor folders on its own.
     *
     * @return array<string,int|null>
     */
    private static function compute_mtime_totals( string $root, array $own_mtime, array $children ): array {
        $totals = array();
        $visit  = static function ( string $path ) use ( &$visit, &$totals, $own_mtime, $children ) {
            if ( array_key_exists( $path, $totals ) ) {
                return $totals[ $path ];
            }
            $max = $own_mtime[ $path ] ?? null;
            foreach ( $children[ $path ] ?? array() as $child ) {
                $child_max = $visit( $child );
                if ( null !== $child_max && ( null === $max || $child_max > $max ) ) {
                    $max = $child_max;
                }
            }
            $totals[ $path ] = $max;
            return $max;
        };
        $visit( $root );
        return $totals;
    }

    /**
     * Formats a nullable UNIX timestamp using this site's configured date
     * and time format, for consistent "Modified" display wherever a mtime
     * (folder-level or per-file) is shown. Null means no file was found
     * anywhere in that item (an empty folder, or a stat() that failed).
     */
    public static function format_mtime( ?int $mtime ): string {
        if ( null === $mtime ) {
            return '—';
        }
        return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mtime );
    }

    /**
     * Immediate children of $path, each with its own recursive total,
     * sorted biggest first. Only meaningful once the scan has completed
     * (totals are only computed at that point).
     *
     * @return array<int, array{path:string, name:string, size:int, mtime:int|null, is_dir:bool}>
     */
    public static function get_children_with_sizes( string $path ): array {
        $state = self::get_state();
        if ( 'complete' !== $state['status'] || null === $state['totals'] ) {
            return array();
        }

        $rows = array();
        foreach ( $state['children'][ $path ] ?? array() as $child ) {
            $rows[] = array(
                'path'   => $child,
                'name'   => basename( $child ),
                'size'   => $state['totals'][ $child ] ?? 0,
                'mtime'  => $state['mtime_totals'][ $child ] ?? null,
                'is_dir' => true,
            );
        }

        // Loose files directly in this folder, shown as a single synthetic
        // row — mirrors how cPanel's own tool bundles a folder's direct
        // files rather than listing each one individually.
        $own = $state['own_size'][ $path ] ?? 0;
        if ( $own > 0 ) {
            $rows[] = array(
                'path'   => $path,
                'name'   => __( '(files directly in this folder)', 'rsd-remote-backup' ),
                'size'   => $own,
                'mtime'  => $state['own_mtime'][ $path ] ?? null,
                'is_dir' => false,
            );
        }

        usort(
            $rows,
            static function ( $a, $b ) {
                return $b['size'] <=> $a['size'];
            }
        );

        return $rows;
    }

    /**
     * Individual loose files directly inside $path (not subfolders — those
     * are listed via get_children_with_sizes()), with their own sizes,
     * sorted biggest first. Deliberately NOT collected during the main
     * scan/run_chunk() and NOT stored in the persisted state — the scan
     * only ever needs to know each folder's own_size *total*, and recording
     * every individual file's name+size for the whole site would make the
     * persisted option balloon in proportion to total file count (easily
     * tens of thousands of entries) instead of folder count (hundreds).
     * Computed here on demand instead: a single, non-recursive directory
     * listing scoped to just one folder, which stays cheap regardless of
     * how large the rest of the site is. Only callable for a path the main
     * scan actually discovered (see is_known_path()).
     *
     * @return array{files: array<int, array{name:string, path:string, size:int, mtime:int|null}>, total:int, truncated:bool}
     */
    public static function list_files_in( string $path ): array {
        $entries = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( false === $entries ) {
            return array(
                'files'     => array(),
                'total'     => 0,
                'truncated' => false,
            );
        }

        $files = array();
        foreach ( $entries as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if ( is_link( $full ) || is_dir( $full ) ) {
                continue; // Subfolders are listed separately — this is loose files only.
            }
            $size    = @filesize( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $mtime   = @filemtime( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $files[] = array(
                'name'  => $entry,
                'path'  => $full,
                'size'  => ( false !== $size ) ? $size : 0,
                'mtime' => ( false !== $mtime ) ? $mtime : null,
            );
        }

        usort(
            $files,
            static function ( $a, $b ) {
                return $b['size'] <=> $a['size'];
            }
        );

        $total     = count( $files );
        $truncated = $total > self::MAX_FILES_LISTED;
        if ( $truncated ) {
            $files = array_slice( $files, 0, self::MAX_FILES_LISTED );
        }

        return array(
            'files'     => $files,
            'total'     => $total,
            'truncated' => $truncated,
        );
    }

    /**
     * Confirms $path is a real key in the completed scan's own totals map
     * before it's trusted for display — the path arrives as a GET
     * parameter, so this rejects anything the scan itself didn't actually
     * discover rather than trusting it blindly.
     */
    public static function is_known_path( string $path ): bool {
        $state = self::get_state();
        return 'complete' === $state['status'] && isset( $state['totals'][ $path ] );
    }
}

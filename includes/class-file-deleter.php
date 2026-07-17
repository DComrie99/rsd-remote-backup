<?php
defined( 'ABSPATH' ) || exit;

/**
 * Single-file deletion behind the Disk Usage tab's per-file "Delete" link.
 * Deliberately files only — no recursive folder delete. A folder-scale
 * delete needs the same chunked/resumable, timeout-safe machinery the scan
 * itself uses (a single unbounded rmdir() walk risks the exact PHP
 * execution-time problem the scanner was built to avoid), plus a much
 * stronger confirmation gate given the blast radius — that's deliberately
 * deferred to a future pass rather than bundled in here.
 *
 * Two layers of protection, both required before anything is actually
 * unlinked:
 *  1. is_protected() — a hardcoded, always-on blocklist (core WP files,
 *     the active theme, every active plugin, this plugin's own folder)
 *     that refuses deletion regardless of what the admin confirms.
 *  2. delete() re-verifies the target's current size/mtime against what
 *     the admin was actually shown before clicking — the Disk Usage tab's
 *     rows come from either a cached scan (folder totals) or a live
 *     listing (file rows) that could be stale by the time Delete is
 *     clicked, so this guards against deleting a file that has since been
 *     replaced/rotated/regenerated under the same name and path.
 */
class RSD_RB_File_Deleter {

    /**
     * Absolute plugin-relative paths (as stored in the 'active_plugins'
     * option / network-active-plugins site option), used to derive both
     * protected plugin FOLDERS (most plugins) and protected single FILES
     * (a handful of plugins that are just one loose .php file with no
     * subfolder, e.g. hello.php).
     *
     * @return array<int,string>
     */
    private static function get_active_plugin_relative_paths(): array {
        $active = (array) get_option( 'active_plugins', array() );
        if ( is_multisite() ) {
            $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
        }
        return $active;
    }

    /**
     * Directory trees that are entirely off-limits — deleting anything
     * inside these (not just the top folder itself) is refused. Covers
     * core (wp-admin, wp-includes), the active theme (both the child and,
     * if different, its parent template), every active plugin's own
     * folder, and this plugin's own folder.
     *
     * @return array<int,string>
     */
    private static function get_protected_prefixes(): array {
        $root = rtrim( ABSPATH, '/\\' );

        $prefixes = array(
            $root . DIRECTORY_SEPARATOR . 'wp-admin',
            $root . DIRECTORY_SEPARATOR . 'wp-includes',
            rtrim( get_template_directory(), '/\\' ),
            rtrim( get_stylesheet_directory(), '/\\' ),
            rtrim( RSD_RB_DIR, '/\\' ),
        );

        foreach ( self::get_active_plugin_relative_paths() as $relative ) {
            $rel_dir = dirname( str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
            if ( '.' !== $rel_dir ) {
                $prefixes[] = rtrim( WP_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . $rel_dir;
            }
        }

        return array_values( array_unique( $prefixes ) );
    }

    /**
     * Individual files that are off-limits regardless of which folder
     * they're viewed from — wp-config.php, .htaccess, and any active
     * plugin that's a single loose file with no subfolder of its own (so
     * it can't be covered by get_protected_prefixes()).
     *
     * @return array<int,string>
     */
    private static function get_protected_files(): array {
        $root = rtrim( ABSPATH, '/\\' );

        $files = array(
            $root . DIRECTORY_SEPARATOR . 'wp-config.php',
            $root . DIRECTORY_SEPARATOR . '.htaccess',
        );

        foreach ( self::get_active_plugin_relative_paths() as $relative ) {
            $rel_path = str_replace( '/', DIRECTORY_SEPARATOR, $relative );
            if ( '.' === dirname( $rel_path ) ) {
                $files[] = rtrim( WP_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . $rel_path;
            }
        }

        return array_values( array_unique( $files ) );
    }

    /**
     * True if $path is (or is inside) anything this plugin refuses to
     * delete, no matter how the admin confirms.
     */
    public static function is_protected( string $path ): bool {
        $normalized = rtrim( $path, '/\\' );

        if ( in_array( $normalized, self::get_protected_files(), true ) ) {
            return true;
        }

        foreach ( self::get_protected_prefixes() as $prefix ) {
            if ( $normalized === $prefix || 0 === strpos( $normalized, $prefix . DIRECTORY_SEPARATOR ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes a single file, after re-checking everything that could have
     * changed since the admin was shown this row: still inside this site's
     * install, still inside a folder the last scan actually discovered,
     * not protected, still a plain file (not a folder/symlink now), and —
     * the key freshness check — its current size/mtime still match what
     * was displayed when Delete was clicked. Any mismatch refuses the
     * delete rather than guessing.
     *
     * @param int      $expected_size  Size (bytes) shown to the admin at click-time.
     * @param int|null $expected_mtime Modified-time shown to the admin at click-time, if known.
     *
     * @return array{success:bool, code:string, message:string}
     */
    public static function delete( string $path, int $expected_size, ?int $expected_mtime ): array {
        $root = rtrim( ABSPATH, '/\\' );
        $real = realpath( $path );

        if ( false === $real || 0 !== strpos( $real, $root ) ) {
            return array(
                'success' => false,
                'code'    => 'outside_root',
                'message' => __( 'That path is not inside this site\'s WordPress install.', 'rsd-remote-backup' ),
            );
        }

        $parent = dirname( $path );
        if ( ! RSD_RB_Disk_Scanner::is_known_path( $parent ) ) {
            return array(
                'success' => false,
                'code'    => 'unknown_parent',
                'message' => __( 'This file\'s folder was not part of the last scan — rescan and try again.', 'rsd-remote-backup' ),
            );
        }

        if ( self::is_protected( $path ) ) {
            return array(
                'success' => false,
                'code'    => 'protected',
                'message' => __( 'This file is protected (core WordPress files, the active theme, an active plugin, or this plugin itself) and cannot be deleted from here.', 'rsd-remote-backup' ),
            );
        }

        if ( is_link( $path ) || ! is_file( $path ) ) {
            return array(
                'success' => false,
                'code'    => 'not_a_file',
                'message' => __( 'That path is no longer a plain file — it may have already been removed or replaced with a folder. Rescan to see the current state.', 'rsd-remote-backup' ),
            );
        }

        $current_size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $current_mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        $size_changed  = ( false === $current_size || $current_size !== $expected_size );
        $mtime_changed = ( null !== $expected_mtime && ( false === $current_mtime || $current_mtime !== $expected_mtime ) );

        if ( $size_changed || $mtime_changed ) {
            return array(
                'success' => false,
                'code'    => 'changed',
                'message' => __( 'This file has changed since it was last scanned — rescan before deleting it, so you\'re not removing something the scan never actually saw.', 'rsd-remote-backup' ),
            );
        }

        if ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return array(
                'success' => false,
                'code'    => 'unlink_failed',
                'message' => __( 'Delete failed — check file permissions on this host.', 'rsd-remote-backup' ),
            );
        }

        return array(
            'success' => true,
            'code'    => 'deleted',
            'message' => __( 'File deleted.', 'rsd-remote-backup' ),
        );
    }
}

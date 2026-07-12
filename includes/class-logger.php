<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rolling log stored in a WP option (array of lines, capped at RSD_RB_LOG_MAX).
 * Never logs tokens, secrets, or file contents.
 */
class RSD_RB_Logger {

    public static function info( string $message ): void {
        self::write( 'INFO', $message );
    }

    public static function warning( string $message ): void {
        self::write( 'WARN', $message );
    }

    public static function error( string $message ): void {
        self::write( 'ERROR', $message );
    }

    /**
     * Like info(), but never redacts.
     *
     * ONLY use this for content that structurally cannot contain a secret —
     * e.g. filenames read directly off the local filesystem during a
     * directory listing. The redact() heuristic (a 40+ char run of
     * alnum/-_.) reliably swallows a WHOLE long filename, dots included,
     * since nothing in it breaks the match — found via a direct user report
     * that "Scan Backup Files" was hiding the exact filenames it exists to
     * show. Never route anything through here that could contain an API
     * response, URL, or token.
     */
    public static function info_unredacted( string $message ): void {
        self::write( 'INFO', $message, false );
    }

    // -----------------------------------------------------------------

    private static function write( string $level, string $message, bool $should_redact = true ): void {
        if ( $should_redact ) {
            $message = self::redact( $message );
        }

        $log   = get_option( RSD_RB_LOG_OPTION, array() );
        $log[] = sprintf(
            '[%s UTC] %s %s',
            gmdate( 'Y-m-d H:i:s' ),
            $level,
            $message
        );

        // Keep the rolling window.
        if ( count( $log ) > RSD_RB_LOG_MAX ) {
            $log = array_slice( $log, -RSD_RB_LOG_MAX );
        }

        update_option( RSD_RB_LOG_OPTION, $log, false );
    }

    /**
     * Replace only the long token-like substrings a message contains with a
     * fixed placeholder, rather than discarding the whole message.
     *
     * Previously any single 40+ char alphanumeric run (heuristically an OAuth
     * token) blanked the ENTIRE line via looks_like_secret(), even when the
     * long run was actually something harmless — a long AI1WM backup
     * filename, a Google Drive/OneDrive remote file id — destroying the
     * surrounding diagnostic context (which job, which step, what happened)
     * along with it. That cost real debugging time on a live incident where
     * the only log lines at the exact moment something went wrong were fully
     * redacted. Redacting just the matched substring keeps the rest of the
     * message intact while still never persisting anything that actually
     * could be a secret.
     */
    private static function redact( string $message ): string {
        return preg_replace( '/[A-Za-z0-9\-_\.]{40,}/', '[REDACTED]', $message );
    }

    // -----------------------------------------------------------------

    /** Return all log lines, newest first. */
    public static function get_lines(): array {
        return array_reverse( get_option( RSD_RB_LOG_OPTION, array() ) );
    }

    /** Return all log lines in the order they were written (oldest first) — used for the downloadable log file. */
    public static function get_lines_chronological(): array {
        return get_option( RSD_RB_LOG_OPTION, array() );
    }

    /** Wipe the log. */
    public static function clear(): void {
        update_option( RSD_RB_LOG_OPTION, array(), false );
    }
}

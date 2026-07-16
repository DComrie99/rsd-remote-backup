<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bulk comment-table maintenance for badly-hosted, spam-flooded sites.
 */
class RSD_RB_Comment_Maintenance {

    /**
     * Total row count in the comments table, every status included — this
     * is exactly what delete_all() removes, so the admin UI can show the
     * real number before the admin confirms.
     */
    public static function count_all(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
    }

    /**
     * Wipes every comment on this site (any status — approved, pending,
     * spam, trash) via direct SQL rather than looping wp_delete_comment()
     * per row. Deliberate: on hosting bad enough to need this feature at
     * all, a background job working through possibly tens of thousands of
     * rows one at a time would hit exactly the WP-Cron reliability problems
     * already diagnosed elsewhere in this plugin (see the Status tab). A
     * single DELETE (not TRUNCATE — that requires the DROP privilege, which
     * shared-hosting DB users often don't have) finishes in one request
     * regardless of row count, so no progress UI is needed.
     *
     * Trade-off, accepted deliberately: this skips WordPress's per-comment
     * deletion hooks (delete_comment/deleted_comment etc.), so other
     * plugins listening for those (e.g. Akismet's stats) are never notified.
     *
     * @return int Number of comments that existed immediately before deletion.
     */
    public static function delete_all(): int {
        global $wpdb;

        $count = self::count_all();
        if ( 0 === $count ) {
            return 0;
        }

        $wpdb->query( "DELETE FROM {$wpdb->comments}" );
        $wpdb->query( "DELETE FROM {$wpdb->commentmeta}" );
        $wpdb->query( "UPDATE {$wpdb->posts} SET comment_count = 0 WHERE comment_count != 0" );

        // Comment counts are cached (site-wide total under group 'counts',
        // plus each post's own comment_count via the post cache) — clear
        // just those rather than a blanket wp_cache_flush(), which would
        // also drop unrelated cached data other plugins need this request.
        wp_cache_delete( 'comments-0', 'counts' );
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'posts' );
        }

        return $count;
    }
}

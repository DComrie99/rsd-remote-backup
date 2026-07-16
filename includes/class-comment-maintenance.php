<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bulk comment-table maintenance for badly-hosted, spam-flooded sites.
 */
class RSD_RB_Comment_Maintenance {

    /**
     * comment_type values that represent a genuine, front-end visitor
     * comment/pingback/trackback — the only rows this class ever counts or
     * deletes. Deliberately a whitelist, not a blacklist of known plugin-
     * internal types (e.g. WooCommerce's 'order_note').
     *
     * Found via a live site where a raw, unfiltered COUNT(*) reported 531
     * "comments" while wp-admin's own Comments screen showed only 1 —
     * 530 of those rows were something else entirely (most likely
     * WooCommerce order notes, or a similar plugin repurposing this same
     * table) stored under a different comment_type, which wp-admin's own
     * screen already knows to hide but a raw table count/delete does not.
     * A blacklist would only ever cover plugins already known about;
     * whitelisting the handful of comment_type values WordPress core itself
     * assigns to a real comment is safe by construction against any other
     * plugin repurposing this table the same way, known or not — the
     * default for an unrecognized type is "leave it alone," not "delete it."
     */
    const REAL_COMMENT_TYPES = array( '', 'comment', 'pingback', 'trackback' );

    private static function type_where_sql(): string {
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( self::REAL_COMMENT_TYPES ), '%s' ) );
        return $wpdb->prepare( "comment_type IN ($placeholders)", self::REAL_COMMENT_TYPES ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Total row count of genuine comments only (see REAL_COMMENT_TYPES) —
     * exactly what delete_all() removes, so the admin UI can show the real
     * number before the admin confirms.
     */
    public static function count_all(): int {
        global $wpdb;
        $where = self::type_where_sql();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Breakdown of count_all() by comment_approved value, summing to exactly
     * the same total. Exists purely so the admin UI can explain a number
     * that otherwise looks wrong: wp-admin's own Comments screen "All" tab
     * (and its sidebar bubble count) excludes spam and trashed comments by
     * default, so it always reads lower than this total.
     *
     * @return array{approved:int,pending:int,spam:int,trash:int,other:int}
     */
    public static function count_by_status(): array {
        global $wpdb;
        $where = self::type_where_sql();

        $counts = array(
            'approved' => 0,
            'pending'  => 0,
            'spam'     => 0,
            'trash'    => 0,
            'other'    => 0, // e.g. 'post-trashed'
        );

        $rows = $wpdb->get_results( "SELECT comment_approved, COUNT(*) AS c FROM {$wpdb->comments} WHERE {$where} GROUP BY comment_approved", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( (array) $rows as $row ) {
            $n = (int) $row['c'];
            switch ( (string) $row['comment_approved'] ) {
                case '1':
                    $counts['approved'] += $n;
                    break;
                case '0':
                    $counts['pending'] += $n;
                    break;
                case 'spam':
                    $counts['spam'] += $n;
                    break;
                case 'trash':
                    $counts['trash'] += $n;
                    break;
                default:
                    $counts['other'] += $n;
            }
        }

        return $counts;
    }

    /**
     * Raw breakdown of the ENTIRE comments table by comment_type, including
     * types this class never touches — every row on the site accounted for,
     * unlike count_all()/count_by_status() which are already scoped to
     * REAL_COMMENT_TYPES. Exists so the admin UI can show, on any given
     * site, exactly what "other" data (if any) is sharing this table and
     * confirm it's excluded — rather than the admin having to run this same
     * query by hand against the database to answer "what's the rest of it?"
     *
     * @return array<int, array{type:string, count:int, deleted:bool}> Sorted by count, descending.
     */
    public static function count_by_type(): array {
        global $wpdb;

        $rows = $wpdb->get_results( "SELECT comment_type, COUNT(*) AS c FROM {$wpdb->comments} GROUP BY comment_type ORDER BY c DESC", ARRAY_A );

        $out = array();
        foreach ( (array) $rows as $row ) {
            $type   = (string) $row['comment_type'];
            $out[] = array(
                'type'    => $type,
                'count'   => (int) $row['c'],
                'deleted' => in_array( $type, self::REAL_COMMENT_TYPES, true ),
            );
        }

        return $out;
    }

    /**
     * Wipes every genuine comment on this site (see REAL_COMMENT_TYPES; any
     * status — approved, pending, spam, trash) via direct SQL rather than
     * looping wp_delete_comment() per row. Deliberate: on hosting bad enough
     * to need this feature at all, a background job working through
     * possibly tens of thousands of rows one at a time would hit exactly
     * the WP-Cron reliability problems already diagnosed elsewhere in this
     * plugin (see the Status tab). A handful of bulk queries finish in one
     * request regardless of row count, so no progress UI is needed.
     *
     * Deliberately leaves any other plugin's own comment-table rows (e.g.
     * WooCommerce order notes) completely untouched — see REAL_COMMENT_TYPES.
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

        $where = self::type_where_sql();

        // commentmeta must be deleted via a join BEFORE the comments rows
        // themselves are gone — once they are, there's nothing left to join
        // against to know which meta rows belonged to a deleted comment.
        $wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // Recompute rather than blanket-zero — a post can still have
        // comment-table rows attached that this method deliberately never
        // touched (e.g. a WooCommerce order's notes), and zeroing its
        // comment_count unconditionally would make it look like those were
        // lost when they weren't. Mirrors exactly what WordPress core's own
        // wp_update_comment_count_now() checks (comment_approved = '1'),
        // just applied to every post in one query instead of per-post.
        $wpdb->query( "UPDATE {$wpdb->posts} p SET p.comment_count = ( SELECT COUNT(*) FROM {$wpdb->comments} c WHERE c.comment_post_ID = p.ID AND c.comment_approved = '1' )" );

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

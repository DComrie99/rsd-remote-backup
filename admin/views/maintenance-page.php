<?php
defined( 'ABSPATH' ) || exit;

$rsd_rb_comment_count     = RSD_RB_Comment_Maintenance::count_all();
$rsd_rb_comment_by_status = RSD_RB_Comment_Maintenance::count_by_status();
$rsd_rb_comment_by_type   = RSD_RB_Comment_Maintenance::count_by_type();

$rsd_rb_disk_state = RSD_RB_Disk_Scanner::get_state();
$rsd_rb_disk_path  = isset( $_GET['rb_disk_path'] ) ? wp_unslash( $_GET['rb_disk_path'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pure read-only navigation, validated against the scan's own known paths below.
if ( 'complete' === $rsd_rb_disk_state['status'] ) {
    $rsd_rb_disk_path = ( '' !== $rsd_rb_disk_path && RSD_RB_Disk_Scanner::is_known_path( $rsd_rb_disk_path ) )
        ? $rsd_rb_disk_path
        : $rsd_rb_disk_state['root'];
}
$rsd_rb_disk_view = ( isset( $_GET['rb_disk_view'] ) && 'files' === $_GET['rb_disk_view'] ) ? 'files' : 'folder'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pure read-only navigation.
?>
<div class="wrap rsd-rb-wrap">
    <div class="rsd-rb-header">
        <img src="<?php echo esc_url( RSD_RB_URL . 'admin/assets/rsd-logo.png' ); ?>"
             alt="Red Swirl Design"
             class="rsd-rb-header__logo" />
        <h1><?php esc_html_e( 'RSD Maintenance', 'rsd-remote-backup' ); ?></h1>
    </div>

    <?php settings_errors( 'RSD_RB' ); ?>

    <nav class="nav-tab-wrapper">
        <a href="#tab-comments" class="nav-tab nav-tab-active"><?php esc_html_e( 'Comments', 'rsd-remote-backup' ); ?></a>
        <a href="#tab-disk-usage" class="nav-tab"><?php esc_html_e( 'Disk Usage', 'rsd-remote-backup' ); ?></a>
    </nav>

    <!-- ===== Comments tab ===== -->
    <div id="tab-comments" class="rsd-rb-tab">
        <h2><?php esc_html_e( 'Delete All Comments', 'rsd-remote-backup' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'For sites on hosting that gets inundated with spam comments — wipes every genuine comment on this site in one action instead of moderating them one at a time. Does not touch other data that some plugins store in the same underlying table (e.g. WooCommerce order notes) — only real visitor comments/pingbacks/trackbacks are counted or deleted.', 'rsd-remote-backup' ); ?>
        </p>

        <div class="rsd-rb-danger-box">
            <h3><?php esc_html_e( '⚠ This is permanent and cannot be undone', 'rsd-remote-backup' ); ?></h3>
            <p>
                <?php
                printf(
                    /* translators: %s: current total comment count, formatted with thousands separators */
                    esc_html__( 'This site currently has %s comment(s) in total. Clicking the button below deletes every single one — approved, pending, spam, and trash alike. There is no trash or recovery step for this action.', 'rsd-remote-backup' ),
                    '<strong>' . esc_html( number_format_i18n( $rsd_rb_comment_count ) ) . '</strong>'
                );
                ?>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: approved count 2: pending count 3: spam count 4: trash count */
                    esc_html__( 'Breakdown: %1$s approved, %2$s pending, %3$s spam, %4$s trash.', 'rsd-remote-backup' ),
                    esc_html( number_format_i18n( $rsd_rb_comment_by_status['approved'] ) ),
                    esc_html( number_format_i18n( $rsd_rb_comment_by_status['pending'] ) ),
                    esc_html( number_format_i18n( $rsd_rb_comment_by_status['spam'] ) ),
                    esc_html( number_format_i18n( $rsd_rb_comment_by_status['trash'] ) )
                );
                if ( $rsd_rb_comment_by_status['other'] > 0 ) {
                    printf(
                        /* translators: %s: count of comments in other statuses (e.g. post-trashed) */
                        ' ' . esc_html__( '+%s other.', 'rsd-remote-backup' ),
                        esc_html( number_format_i18n( $rsd_rb_comment_by_status['other'] ) )
                    );
                }
                ?>
                <br>
                <em><?php esc_html_e( "If this total looks higher than what the Comments screen shows, that's expected — its default \"All\" view (and the sidebar bubble count) excludes spam and trashed comments. This total already excludes anything that isn't a genuine comment (e.g. WooCommerce order notes) — it's exactly what gets deleted, nothing else.", 'rsd-remote-backup' ); ?></em>
            </p>
            <?php if ( $rsd_rb_comment_count > 0 ) : ?>
                <p>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . RSD_RB_Maintenance_Page::PAGE_SLUG . '&rb_maint_action=delete_all_comments' ), 'rsd_rb_delete_all_comments' ) ); ?>"
                       class="button button-primary rsd-rb-danger-button"
                       onclick="return confirm('<?php
                            echo esc_js( sprintf(
                                /* translators: %d: number of comments about to be deleted */
                                __( 'Permanently delete all %d comment(s) on this site? This includes approved comments and cannot be undone.', 'rsd-remote-backup' ),
                                $rsd_rb_comment_count
                            ) );
                       ?>');">
                        <?php esc_html_e( 'Delete All Comments', 'rsd-remote-backup' ); ?>
                    </a>
                </p>
            <?php else : ?>
                <p><em><?php esc_html_e( 'No comments to delete.', 'rsd-remote-backup' ); ?></em></p>
            <?php endif; ?>
        </div>

        <h3><?php esc_html_e( "What's Actually in This Site's Comments Table", 'rsd-remote-backup' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'Raw breakdown by comment_type — every row on the site, including anything this plugin never touches. Same data as running SELECT comment_type, COUNT(*) FROM wp_comments GROUP BY comment_type yourself.', 'rsd-remote-backup' ); ?>
        </p>
        <table class="widefat striped rsd-rb-jobs-table" style="max-width:560px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'comment_type', 'rsd-remote-backup' ); ?></th>
                    <th><?php esc_html_e( 'Count', 'rsd-remote-backup' ); ?></th>
                    <th><?php esc_html_e( 'Deleted by button above?', 'rsd-remote-backup' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rsd_rb_comment_by_type as $rsd_rb_type_row ) : ?>
                    <tr>
                        <td><code><?php echo '' === $rsd_rb_type_row['type'] ? esc_html__( '(blank)', 'rsd-remote-backup' ) : esc_html( $rsd_rb_type_row['type'] ); ?></code></td>
                        <td><?php echo esc_html( number_format_i18n( $rsd_rb_type_row['count'] ) ); ?></td>
                        <td>
                            <?php if ( $rsd_rb_type_row['deleted'] ) : ?>
                                <span class="rsd-rb-badge rsd-rb-badge--failed"><?php esc_html_e( 'Yes', 'rsd-remote-backup' ); ?></span>
                            <?php else : ?>
                                <span class="rsd-rb-badge rsd-rb-badge--complete"><?php esc_html_e( 'No — left alone', 'rsd-remote-backup' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $rsd_rb_comment_by_type ) ) : ?>
                    <tr><td colspan="3"><em><?php esc_html_e( 'The comments table is empty.', 'rsd-remote-backup' ); ?></em></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div><!-- #tab-comments -->

    <!-- ===== Disk Usage tab ===== -->
    <div id="tab-disk-usage" class="rsd-rb-tab" style="display:none;">
        <h2><?php esc_html_e( 'Disk Usage', 'rsd-remote-backup' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Walks this site\'s files (from the WordPress root down) and reports total size per folder — for tracking down what\'s suddenly eating disk space, without needing to browse the raw file structure in cPanel. A one-off scan, not something that runs on a schedule: start it, leave this browser tab open, and it advances itself in the background until the whole tree is measured — you can freely switch between this screen\'s own tabs while it runs. Closing the browser tab (or navigating elsewhere) just pauses it — come back to this screen later and it picks up where it left off.', 'rsd-remote-backup' ); ?>
        </p>

        <?php if ( 'running' === $rsd_rb_disk_state['status'] ) :
            $rsd_rb_disk_tick_nonce = wp_create_nonce( 'rsd_rb_disk_scan_tick' );
            $rsd_rb_disk_cancel_url = wp_nonce_url(
                admin_url( 'admin.php?page=' . RSD_RB_Maintenance_Page::PAGE_SLUG . '&rb_maint_action=disk_scan_cancel' ),
                'rsd_rb_disk_scan_cancel'
            );
            ?>
            <div class="notice notice-info inline" style="margin:0 0 12px;">
                <p>
                    <?php esc_html_e( 'Scanning… this updates automatically, no need to click anything.', 'rsd-remote-backup' ); ?>
                    <strong><span id="rsd-rb-disk-files"><?php echo esc_html( number_format_i18n( $rsd_rb_disk_state['files_scanned'] ) ); ?></span></strong> <?php esc_html_e( 'files', 'rsd-remote-backup' ); ?>
                    /
                    <strong><span id="rsd-rb-disk-dirs"><?php echo esc_html( number_format_i18n( $rsd_rb_disk_state['dirs_scanned'] ) ); ?></span></strong> <?php esc_html_e( 'folders measured so far.', 'rsd-remote-backup' ); ?>
                </p>
            </div>
            <p>
                <a href="<?php echo esc_url( $rsd_rb_disk_cancel_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel Scan', 'rsd-remote-backup' ); ?></a>
            </p>
            <script>
                ( function () {
                    var filesEl = document.getElementById( 'rsd-rb-disk-files' );
                    var dirsEl  = document.getElementById( 'rsd-rb-disk-dirs' );
                    var nonce   = <?php echo wp_json_encode( $rsd_rb_disk_tick_nonce ); ?>;

                    function tick() {
                        fetch( ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=rsd_rb_disk_scan_tick&nonce=' + encodeURIComponent( nonce )
                        } )
                            .then( function ( r ) { return r.json(); } )
                            .then( function ( res ) {
                                if ( ! res || ! res.success ) {
                                    setTimeout( tick, 5000 ); // transient error — back off and retry.
                                    return;
                                }
                                var data = res.data;
                                if ( filesEl ) { filesEl.textContent = data.files_scanned.toLocaleString(); }
                                if ( dirsEl )  { dirsEl.textContent  = data.dirs_scanned.toLocaleString(); }

                                if ( 'complete' === data.status ) {
                                    // Switch to the completed-scan view (breadcrumb + folder
                                    // table), which is rendered server-side — simplest way to
                                    // get there is one final reload, not per-chunk ones.
                                    window.location.reload();
                                } else {
                                    // No artificial delay — chain immediately. Each chunk is
                                    // already time-boxed server-side (a few seconds), which is
                                    // what naturally paces these updates; waiting on top of that
                                    // would only slow the scan down for no benefit.
                                    tick();
                                }
                            } )
                            .catch( function () {
                                setTimeout( tick, 5000 );
                            } );
                    }

                    tick();
                } )();
            </script>

        <?php elseif ( 'complete' === $rsd_rb_disk_state['status'] ) :
            $rsd_rb_disk_view_nonce = wp_create_nonce( 'rsd_rb_disk_scan_view' );
            $rsd_rb_disk_rescan_url = wp_nonce_url(
                admin_url( 'admin.php?page=' . RSD_RB_Maintenance_Page::PAGE_SLUG . '&rb_maint_action=disk_scan_start' ),
                'rsd_rb_disk_scan_start'
            );
            ?>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: scan duration in human-readable form 2: number of unreadable folders skipped */
                    esc_html__( 'Last scan completed %1$s ago.', 'rsd-remote-backup' ),
                    esc_html( human_time_diff( $rsd_rb_disk_state['completed_at'] ) )
                );
                if ( ! empty( $rsd_rb_disk_state['errors'] ) ) {
                    printf(
                        ' ' . esc_html__( '%d folder(s) could not be read (permissions) and were skipped.', 'rsd-remote-backup' ),
                        count( $rsd_rb_disk_state['errors'] )
                    );
                }
                ?>
                &nbsp;
                <a href="<?php echo esc_url( $rsd_rb_disk_rescan_url ); ?>" class="button button-secondary button-small"><?php esc_html_e( 'Rescan', 'rsd-remote-backup' ); ?></a>
            </p>

            <div id="rsd-rb-disk-browser">
                <?php require RSD_RB_DIR . 'admin/views/partials/disk-usage-browser.php'; ?>
            </div>

            <script>
                ( function () {
                    var container = document.getElementById( 'rsd-rb-disk-browser' );
                    var nonce     = <?php echo wp_json_encode( $rsd_rb_disk_view_nonce ); ?>;

                    // Event delegation on the stable outer container — links inside
                    // get replaced wholesale on every navigation, so binding to each
                    // <a> individually would need re-binding after every update.
                    container.addEventListener( 'click', function ( e ) {
                        var link = e.target.closest( 'a[data-disk-path]' );
                        if ( ! link || ! container.contains( link ) ) {
                            return;
                        }
                        e.preventDefault();

                        var path = link.getAttribute( 'data-disk-path' );
                        var view = link.getAttribute( 'data-disk-view' ) || 'folder';

                        container.style.opacity = '0.5'; // brief feedback while the request is in flight.

                        fetch( ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=rsd_rb_disk_scan_view'
                                + '&nonce=' + encodeURIComponent( nonce )
                                + '&path=' + encodeURIComponent( path )
                                + '&view=' + encodeURIComponent( view )
                        } )
                            .then( function ( r ) { return r.json(); } )
                            .then( function ( res ) {
                                if ( res && res.success ) {
                                    container.innerHTML = res.data.html;
                                }
                                container.style.opacity = '';
                            } )
                            .catch( function () {
                                container.style.opacity = '';
                            } );
                    } );
                } )();
            </script>

        <?php else : /* idle */
            $rsd_rb_disk_start_url = wp_nonce_url(
                admin_url( 'admin.php?page=' . RSD_RB_Maintenance_Page::PAGE_SLUG . '&rb_maint_action=disk_scan_start' ),
                'rsd_rb_disk_scan_start'
            );
            ?>
            <p>
                <a href="<?php echo esc_url( $rsd_rb_disk_start_url ); ?>" class="button button-primary"><?php esc_html_e( 'Start Scan', 'rsd-remote-backup' ); ?></a>
            </p>
        <?php endif; ?>
    </div><!-- #tab-disk-usage -->
</div>

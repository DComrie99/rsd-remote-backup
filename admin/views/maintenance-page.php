<?php
defined( 'ABSPATH' ) || exit;

$rsd_rb_comment_count = RSD_RB_Comment_Maintenance::count_all();
$rsd_rb_comment_by_status = RSD_RB_Comment_Maintenance::count_by_status();
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
    </nav>

    <!-- ===== Comments tab ===== -->
    <div id="tab-comments" class="rsd-rb-tab">
        <h2><?php esc_html_e( 'Delete All Comments', 'rsd-remote-backup' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'For sites on hosting that gets inundated with spam comments — wipes every comment on this site in one action instead of moderating them one at a time.', 'rsd-remote-backup' ); ?>
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
                <em><?php esc_html_e( "If this total looks higher than what the Comments screen shows, that's expected — its default \"All\" view (and the sidebar bubble count) excludes spam and trashed comments. This total is the real row count and exactly what gets deleted.", 'rsd-remote-backup' ); ?></em>
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
    </div><!-- #tab-comments -->
</div>

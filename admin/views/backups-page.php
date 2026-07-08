<?php
defined( 'ABSPATH' ) || exit;

$rows        = RSD_RB_Manifest::get_for_admin_list( 50 );
$missing_ids = get_transient( 'rsd_rb_manifest_missing_remote' );
$missing_ids = is_array( $missing_ids ) ? $missing_ids : array();
// The manifest table has no stored location column of its own (unlike jobs) —
// derive it live the same way resync does, since this screen has no linked
// job for an orphaned row to read a stored value from anyway.
$backup_dir  = trailingslashit( RSD_RB_Settings::get_backup_source_config()['dir'] );

$active_provider = RSD_RB_Plugin::get_instance()->get_active_provider();
$provider_label   = $active_provider ? $active_provider->label() : __( 'provider', 'rsd-remote-backup' );
?>
<div class="wrap rsd-rb-wrap">
    <div class="rsd-rb-header">
        <img src="<?php echo esc_url( RSD_RB_URL . 'admin/assets/rsd-logo.png' ); ?>"
             alt="Red Swirl Design"
             class="rsd-rb-header__logo" />
        <h1><?php esc_html_e( 'Backups', 'rsd-remote-backup' ); ?></h1>
    </div>

    <?php settings_errors( 'RSD_RB' ); ?>

    <p>
        <?php esc_html_e( 'Backups this plugin has uploaded. "Download & prepare" pulls a backup back down, verifies it, decompresses it if needed, and places the plain .wpress file where AI1WM expects it. Restoring is done from the AI1WM screen — this plugin does not trigger the restore itself.', 'rsd-remote-backup' ); ?>
    </p>

    <p>
        <a href="<?php echo esc_url( wp_nonce_url(
            admin_url( 'admin.php?page=' . RSD_RB_Admin_Page::BACKUPS_PAGE_SLUG . '&rb_action=refresh_from_provider' ),
            'rsd_rb_refresh_provider'
        ) ); ?>" class="button">
            <?php
            /* translators: %s: connected provider name, e.g. "Google Drive" */
            printf( esc_html__( 'Refresh from %s', 'rsd-remote-backup' ), esc_html( $provider_label ) );
            ?>
        </a>
    </p>

    <?php if ( empty( $rows ) ) : ?>
        <p><?php esc_html_e( 'No uploaded backups yet.', 'rsd-remote-backup' ); ?></p>
    <?php else : ?>
        <table class="widefat striped rsd-rb-jobs-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Backup', 'rsd-remote-backup' ); ?></th>
                    <th><?php esc_html_e( 'Original size', 'rsd-remote-backup' ); ?></th>
                    <th><?php esc_html_e( 'Compressed size', 'rsd-remote-backup' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'rsd-remote-backup' ); ?></th>
                    <th><?php esc_html_e( 'Location', 'rsd-remote-backup' ); ?></th>
                    <th><?php esc_html_e( 'Staged at', 'rsd-remote-backup' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'rsd-remote-backup' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) :
                    $manifest_id      = (int) $row['id'];
                    $download_status  = $row['download_status'] ?: RSD_RB_Manifest::DOWNLOAD_NOT_STARTED;
                    $attempts         = (int) $row['download_attempts'];
                    $is_downloading   = RSD_RB_Manifest::DOWNLOAD_DOWNLOADING === $download_status;
                    $is_missing       = in_array( $manifest_id, $missing_ids, true );
                    // Backups discovered via resync (never processed locally by this
                    // plugin) have no baseline checksum yet — the first download trusts
                    // and backfills it rather than refusing to ever download them.
                    $checksum_field   = $row['remote_is_compressed'] ? 'compressed_checksum' : 'original_checksum';
                    $not_yet_verified = empty( $row[ $checksum_field ] );

                    $in_local = file_exists( $backup_dir . $row['original_filename'] );
                    $in_remote = RSD_RB_Manifest::UPLOAD_UPLOADED === $row['upload_status'] && ! $is_missing;
                    $location  = RSD_RB_Rest_Api::resolve_location( $in_local, $in_remote );

                    switch ( $download_status ) {
                        case RSD_RB_Manifest::DOWNLOAD_STAGED:
                            $badge_css   = 'rsd-rb-badge--complete';
                            $status_text = __( 'Staged', 'rsd-remote-backup' );
                            break;
                        case RSD_RB_Manifest::DOWNLOAD_DOWNLOADING:
                            $badge_css   = 'rsd-rb-badge--uploading';
                            $status_text = __( 'Downloading…', 'rsd-remote-backup' );
                            break;
                        case RSD_RB_Manifest::DOWNLOAD_FAILED:
                        case RSD_RB_Manifest::DOWNLOAD_VERIFY_FAILED:
                            $badge_css   = 'rsd-rb-badge--failed';
                            $status_text = $attempts >= RSD_RB_Manifest::DOWNLOAD_MAX_ATTEMPTS
                                ? __( 'Failed repeatedly', 'rsd-remote-backup' )
                                : __( 'Failed', 'rsd-remote-backup' );
                            break;
                        default:
                            $badge_css   = 'rsd-rb-badge--pending';
                            $status_text = __( 'Uploaded', 'rsd-remote-backup' );
                            break;
                    }

                    $download_url = wp_nonce_url(
                        admin_url( 'admin.php?page=' . RSD_RB_Admin_Page::BACKUPS_PAGE_SLUG . '&rb_action=download_backup&manifest_id=' . $manifest_id ),
                        'rsd_rb_download_' . $manifest_id
                    );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $row['original_filename'] ); ?></strong><br>
                            <small><?php echo esc_html( $row['created_at'] ); ?></small>
                            <?php if ( $is_missing ) : ?>
                                <br><small class="rsd-rb-warning">⚠ <?php esc_html_e( 'not found on remote provider', 'rsd-remote-backup' ); ?></small>
                            <?php endif; ?>
                            <?php if ( $not_yet_verified ) : ?>
                                <br><small title="<?php esc_attr_e( 'This backup was discovered on the remote provider rather than uploaded by this install, so there is no known-good checksum to verify against yet. The first download is trusted and its checksum is recorded for future verification.', 'rsd-remote-backup' ); ?>">
                                    <?php esc_html_e( 'not yet verified', 'rsd-remote-backup' ); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo ! empty( $row['original_size_bytes'] ) ? esc_html( size_format( (int) $row['original_size_bytes'], 2 ) ) : '—'; ?>
                        </td>
                        <td>
                            <?php echo ! empty( $row['compressed_size_bytes'] ) ? esc_html( size_format( (int) $row['compressed_size_bytes'], 2 ) ) : '—'; ?>
                        </td>
                        <td><span class="rsd-rb-badge <?php echo esc_attr( $badge_css ); ?>"><?php echo esc_html( $status_text ); ?></span></td>
                        <td>
                            <span class="rsd-rb-badge <?php echo esc_attr( RSD_RB_Queue::location_badge_class( $location ) ); ?>">
                                <?php echo esc_html( RSD_RB_Queue::location_label( $location ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo ! empty( $row['staged_at'] ) ? esc_html( $row['staged_at'] ) : '—'; ?>
                        </td>
                        <td>
                            <?php if ( $is_downloading ) : ?>
                                <button type="button" class="button" disabled><?php esc_html_e( 'Downloading…', 'rsd-remote-backup' ); ?></button>
                            <?php else : ?>
                                <a href="<?php echo esc_url( $download_url ); ?>" class="button button-secondary">
                                    <?php esc_html_e( 'Download & prepare', 'rsd-remote-backup' ); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

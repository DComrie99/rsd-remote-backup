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

    <nav class="nav-tab-wrapper">
        <a href="#tab-backups" class="nav-tab nav-tab-active"><?php esc_html_e( 'Backups', 'rsd-remote-backup' ); ?></a>
        <a href="#tab-files"   class="nav-tab"><?php esc_html_e( 'Files', 'rsd-remote-backup' ); ?></a>
        <a href="#tab-queue"   class="nav-tab"><?php esc_html_e( 'Upload Queue', 'rsd-remote-backup' ); ?></a>
    </nav>

    <!-- ===== Backups tab (download & restore-stage already-uploaded backups) ===== -->
    <div id="tab-backups" class="rsd-rb-tab">

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

    </div><!-- #tab-backups -->

    <!-- ===== Files tab — one row per detected backup (manifest-rooted), collapsed
         to the 4 states an admin actually needs: Detected, Uploading, Uploaded,
         Deleted. A file whose location resolves to "none" (gone from both local
         and remote — e.g. pruned by remote retention after local cleanup) is
         Deleted and is filtered out entirely rather than shown, per the whole
         point of this tab: no more rows stuck showing "Detected"/"Uploading"
         for a backup that's actually long gone. -->
    <div id="tab-files" class="rsd-rb-tab" style="display:none;">
        <p>
            <?php esc_html_e( 'Every backup this plugin has detected, with its current location and upload state. Once a backup is gone from both the local server and the remote provider (e.g. pruned by retention) it disappears from this list.', 'rsd-remote-backup' ); ?>
        </p>
        <?php
        $files_dir  = RSD_RB_Backup_Scanner::backup_dir();
        $file_rows  = array();
        foreach ( RSD_RB_Manifest::get_recent( 100 ) as $manifest ) {
            $manifest_id = (int) $manifest['id'];
            $job         = RSD_RB_Queue::get_job_by_manifest_id( $manifest_id );

            if ( $job ) {
                $location = $job['location'] ?? RSD_RB_Queue::LOCATION_LOCAL;
            } else {
                $in_local  = file_exists( trailingslashit( $files_dir ) . $manifest['original_filename'] );
                $in_remote = RSD_RB_Manifest::UPLOAD_UPLOADED === $manifest['upload_status']
                    && ! in_array( $manifest_id, $missing_ids, true );
                $location  = RSD_RB_Rest_Api::resolve_location( $in_local, $in_remote );
            }

            $state = RSD_RB_Manifest::file_state( $manifest, $location );
            if ( RSD_RB_Manifest::FILE_STATE_DELETED === $state ) {
                continue;
            }

            $file_rows[] = array(
                'manifest' => $manifest,
                'job'      => $job,
                'location' => $location,
                'state'    => $state,
            );
            if ( count( $file_rows ) >= 30 ) {
                break;
            }
        }
        ?>
        <?php if ( empty( $file_rows ) ) : ?>
            <p><?php esc_html_e( 'No backups detected yet.', 'rsd-remote-backup' ); ?></p>
        <?php else : ?>
            <table class="widefat striped rsd-rb-jobs-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Backup', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Location', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Provider', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Compression', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Detected', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Last Updated', 'rsd-remote-backup' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $file_rows as $file_row ) :
                        $manifest = $file_row['manifest'];
                        $job      = $file_row['job'];
                        $filesize = ! empty( $manifest['original_size_bytes'] ) ? (int) $manifest['original_size_bytes'] : ( $job ? (int) $job['filesize'] : 0 );
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $manifest['original_filename'] ); ?></strong><br>
                                <small><?php echo esc_html( size_format( $filesize, 2 ) ); ?></small>
                            </td>
                            <td>
                                <span class="rsd-rb-badge <?php echo esc_attr( RSD_RB_Manifest::file_state_badge_class( $file_row['state'] ) ); ?>">
                                    <?php echo esc_html( RSD_RB_Manifest::file_state_label( $file_row['state'] ) ); ?>
                                </span>
                            </td>
                            <td>
                                <span class="rsd-rb-badge <?php echo esc_attr( RSD_RB_Queue::location_badge_class( $file_row['location'] ) ); ?>">
                                    <?php echo esc_html( RSD_RB_Queue::location_label( $file_row['location'] ) ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $manifest['provider'] ); ?></td>
                            <td>
                                <?php if ( ! empty( $manifest['compression_method'] ) ) : ?>
                                    <small title="<?php echo esc_attr( RSD_RB_Compressor::method_label( $manifest['compression_method'] ) ); ?>">
                                        <?php echo esc_html( RSD_RB_Compressor::method_short_label( $manifest['compression_method'] ) ); ?>
                                    </small>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo esc_html( $manifest['created_at'] ); ?></small></td>
                            <td><small><?php echo esc_html( $job ? $job['updated_at'] : $manifest['updated_at'] ); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div><!-- #tab-files -->

    <!-- ===== Upload Queue tab (moved from Settings → Status & Log) — rooted on
         the manifest (a backup file's own detect → compress → upload lifecycle),
         with upload mechanics from its linked job nested in as supporting detail.
         A manifest row with no job (rare — an orphaned discovery-via-remote edge
         case) still shows, just with "—" in the job-only columns. -->
    <div id="tab-queue" class="rsd-rb-tab" style="display:none;">
        <?php
        $manifest_rows    = RSD_RB_Manifest::get_recent( 30 );
        $upload_queue_dir = RSD_RB_Backup_Scanner::backup_dir();
        if ( empty( $manifest_rows ) ) {
            echo '<p>' . esc_html__( 'No backups detected yet.', 'rsd-remote-backup' ) . '</p>';
        } else {
            ?>
            <table class="widefat striped rsd-rb-jobs-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( '#', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Backup', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Provider', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Pipeline Status', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Location', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Upload Progress', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Compression', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Attempts', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Last Error', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Updated', 'rsd-remote-backup' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $manifest_rows as $manifest ) :
                        $manifest_id = (int) $manifest['id'];
                        $job         = RSD_RB_Queue::get_job_by_manifest_id( $manifest_id );
                        $is_missing  = RSD_RB_Manifest::is_missing_locally( $manifest, $upload_queue_dir );
                        $filesize    = ! empty( $manifest['original_size_bytes'] ) ? (int) $manifest['original_size_bytes'] : ( $job ? (int) $job['filesize'] : 0 );
                        $bytes_sent  = $job ? (int) $job['bytes_sent'] : 0;
                        $is_complete = $job && RSD_RB_Queue::STATUS_COMPLETE === $job['status'];
                        // While a compressed job is mid-transfer, bytes_sent tracks the offset into the
                        // smaller compressed file — use its size as the % denominator, not the original.
                        $progress_total = ! empty( $manifest['compressed_size_bytes'] ) ? (int) $manifest['compressed_size_bytes'] : $filesize;
                        $pct            = $is_complete ? 100 : ( $progress_total > 0 ? round( ( $bytes_sent / $progress_total ) * 100 ) : 0 );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $manifest_id ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $manifest['original_filename'] ); ?></strong><br>
                                <small><?php echo esc_html( size_format( $filesize, 2 ) ); ?></small>
                                <?php if ( $is_missing ) : ?>
                                    <br><small class="rsd-rb-warning" title="<?php esc_attr_e( 'The database still has this backup recorded, but its file is no longer in the backup folder and it was never confirmed uploaded — it will not be retried until a matching file reappears.', 'rsd-remote-backup' ); ?>">
                                        ⚠ <?php esc_html_e( 'not found on disk', 'rsd-remote-backup' ); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $manifest['provider'] ); ?></td>
                            <td><span class="rsd-rb-badge <?php echo esc_attr( RSD_RB_Manifest::pipeline_badge_class( $manifest['status'] ) ); ?>"><?php echo esc_html( RSD_RB_Manifest::pipeline_status_label( $manifest['status'] ) ); ?></span></td>
                            <td>
                                <?php if ( $job ) : ?>
                                    <?php $location = $job['location'] ?? RSD_RB_Queue::LOCATION_LOCAL; ?>
                                    <span class="rsd-rb-badge <?php echo esc_attr( RSD_RB_Queue::location_badge_class( $location ) ); ?>">
                                        <?php echo esc_html( RSD_RB_Queue::location_label( $location ) ); ?>
                                    </span>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $job && $progress_total > 0 ) : ?>
                                    <div class="rsd-rb-progress" title="<?php echo esc_attr( size_format( $bytes_sent, 2 ) . ' / ' . size_format( $progress_total, 2 ) ); ?>">
                                        <div class="rsd-rb-progress__bar" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
                                    </div>
                                    <small><?php echo esc_html( $pct . '%' ); ?></small>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $manifest['compression_method'] ) ) : ?>
                                    <small title="<?php echo esc_attr( RSD_RB_Compressor::method_label( $manifest['compression_method'] ) ); ?>">
                                        <?php
                                        // original_size_bytes can legitimately be unknown (0) for a
                                        // compressed backup discovered purely via a remote listing,
                                        // which never learns the true pre-compression size — computing
                                        // a "% smaller" against 0 there would show a meaningless,
                                        // wildly negative number instead of just omitting it.
                                        if ( ! empty( $manifest['original_size_bytes'] ) ) {
                                            printf(
                                                /* translators: 1: method 2: original size 3: compressed size 4: percent smaller 5: duration in ms */
                                                esc_html__( '%1$s: %2$s → %3$s (%4$d%%) in %5$dms', 'rsd-remote-backup' ),
                                                esc_html( RSD_RB_Compressor::method_short_label( $manifest['compression_method'] ) ),
                                                esc_html( size_format( $manifest['original_size_bytes'], 2 ) ),
                                                esc_html( size_format( $manifest['compressed_size_bytes'], 2 ) ),
                                                (int) round( ( 1 - $manifest['compressed_size_bytes'] / $manifest['original_size_bytes'] ) * 100 ),
                                                (int) $manifest['compression_time_ms']
                                            );
                                        } else {
                                            printf(
                                                /* translators: 1: method 2: compressed size 3: duration in ms */
                                                esc_html__( '%1$s: original size unknown → %2$s in %3$dms', 'rsd-remote-backup' ),
                                                esc_html( RSD_RB_Compressor::method_short_label( $manifest['compression_method'] ) ),
                                                esc_html( size_format( $manifest['compressed_size_bytes'], 2 ) ),
                                                (int) $manifest['compression_time_ms']
                                            );
                                        }
                                        ?>
                                    </small>
                                <?php elseif ( RSD_RB_Manifest::STATUS_COMPRESS_FAILED === $manifest['status'] ) : ?>
                                    <small class="rsd-rb-warning" title="<?php esc_attr_e( 'Compression was enabled but failed for this backup — the raw file was uploaded instead.', 'rsd-remote-backup' ); ?>">
                                        <?php esc_html_e( 'failed — uploaded raw', 'rsd-remote-backup' ); ?>
                                    </small>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $job ? $job['attempts'] : 0 ); ?></td>
                            <td>
                                <?php if ( $job && ! empty( $job['last_error'] ) ) : ?>
                                    <span class="rsd-rb-error-snippet" title="<?php echo esc_attr( $job['last_error'] ); ?>">
                                        <?php echo esc_html( wp_trim_words( $job['last_error'], 8, '…' ) ); ?>
                                    </span>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo esc_html( $job ? $job['updated_at'] : $manifest['updated_at'] ); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
        ?>
    </div><!-- #tab-queue -->
</div>

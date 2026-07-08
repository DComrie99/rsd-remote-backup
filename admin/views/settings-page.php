<?php
defined( 'ABSPATH' ) || exit;

$current_provider = RSD_RB_Settings::get_provider();
?>
<div class="wrap rsd-rb-wrap">
    <div class="rsd-rb-header">
        <img src="<?php echo esc_url( RSD_RB_URL . 'admin/assets/rsd-logo.png' ); ?>"
             alt="Red Swirl Design"
             class="rsd-rb-header__logo" />
        <h1><?php esc_html_e( 'Red Swirl Design Remote Backup', 'rsd-remote-backup' ); ?></h1>
    </div>

    <?php settings_errors( 'RSD_RB' ); ?>

    <nav class="nav-tab-wrapper">
        <a href="#tab-general"  class="nav-tab nav-tab-active"><?php esc_html_e( 'General', 'rsd-remote-backup' ); ?></a>
        <a href="#tab-provider" class="nav-tab"><?php esc_html_e( 'Provider', 'rsd-remote-backup' ); ?></a>
        <a href="#tab-license"  class="nav-tab"><?php esc_html_e( 'License', 'rsd-remote-backup' ); ?></a>
        <a href="#tab-status"   class="nav-tab"><?php esc_html_e( 'Status &amp; Log', 'rsd-remote-backup' ); ?></a>
    </nav>

    <form method="post" action="options.php">
        <?php settings_fields( RSD_RB_Settings::GROUP ); ?>

        <!-- ===== General tab ===== -->
        <div id="tab-general" class="rsd-rb-tab">
            <h2><?php esc_html_e( 'General Settings', 'rsd-remote-backup' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Backup Source', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <?php $saved_source = RSD_RB_Settings::get_backup_source(); ?>
                        <select name="rsd_rb_backup_source">
                            <?php foreach ( RSD_RB_Settings::BACKUP_SOURCES as $key => $source ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $saved_source, $key ); ?>>
                                    <?php echo esc_html( $source['label'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Which backup plugin creates the files this plugin should upload.', 'rsd-remote-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Active Provider', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <select name="rsd_rb_provider">
                            <option value="google-drive" <?php selected( $current_provider, 'google-drive' ); ?>><?php esc_html_e( 'Google Drive', 'rsd-remote-backup' ); ?></option>
                            <option value="onedrive"     <?php selected( $current_provider, 'onedrive' ); ?>><?php esc_html_e( 'Microsoft OneDrive', 'rsd-remote-backup' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Scan Frequency', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <select name="rsd_rb_scan_frequency">
                            <?php
                            $saved_freq = get_option( 'rsd_rb_scan_frequency', 'rsd_rb_every_15_minutes' );
                            foreach ( wp_get_schedules() as $key => $schedule ) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr( $key ),
                                    selected( $saved_freq, $key, false ),
                                    esc_html( $schedule['display'] )
                                );
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Upload Time Limit (seconds)', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <input type="number" name="rsd_rb_time_budget" min="10" max="3600"
                               value="<?php echo esc_attr( RSD_RB_Settings::get_time_budget() ); ?>"
                               class="small-text" />
                        <p class="description">
                            <?php
                            $php_limit = (int) ini_get( 'max_execution_time' );
                            if ( $php_limit === 0 ) {
                                esc_html_e( 'How many seconds the upload worker runs per cron invocation before pausing and rescheduling. Your server has no PHP time limit (0 = unlimited), so you can safely set this high.', 'rsd-remote-backup' );
                            } else {
                                printf(
                                    esc_html__( 'How many seconds the upload worker runs per cron invocation before pausing and rescheduling. Your server\'s PHP time limit is %d seconds — keep this value below that.', 'rsd-remote-backup' ),
                                    $php_limit
                                );
                            }
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Max Concurrent Uploads', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <input type="number" name="rsd_rb_max_concurrent_uploads" min="1" max="10"
                               value="<?php echo esc_attr( RSD_RB_Settings::get_max_concurrent_uploads() ); ?>"
                               class="small-text" />
                        <p class="description">
                            <?php esc_html_e( 'How many backup files this plugin will transfer at the same time. Extra jobs wait their turn rather than uploading in parallel — keep this low on shared hosting to avoid saturating bandwidth or CPU.', 'rsd-remote-backup' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Destination Folder', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <input type="text" name="rsd_rb_folder_name"
                               value="<?php echo esc_attr( RSD_RB_Settings::get_folder_name() ); ?>"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Folder created inside your cloud storage. Use a unique name per site to avoid retention conflicts.', 'rsd-remote-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Keep Last N Backups', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <input type="number" name="rsd_rb_retention_count" min="1" max="365"
                               value="<?php echo esc_attr( RSD_RB_Settings::get_retention_count() ); ?>"
                               class="small-text" />
                        <p class="description"><?php esc_html_e( 'Older remote backups beyond this count are deleted automatically after each successful upload.', 'rsd-remote-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Keep Local Backups', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <input type="number" name="rsd_rb_local_retention_count" min="0" max="365"
                               value="<?php echo esc_attr( RSD_RB_Settings::get_local_retention_count() ); ?>"
                               class="small-text" />
                        <p class="description"><?php esc_html_e( 'Number of most recent backups to keep on this server once they are confirmed uploaded. Older confirmed-uploaded backups are deleted automatically. Set to 0 to delete each backup locally as soon as its upload completes.', 'rsd-remote-backup' ); ?></p>
                        <p class="description rsd-rb-warning">
                            <?php esc_html_e( '⚠ Irreversible. A local file is only ever deleted once a verified copy exists in the cloud.', 'rsd-remote-backup' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Compress Before Upload', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rsd_rb_compress_enabled" value="1"
                                   <?php checked( RSD_RB_Settings::get_compress_enabled() ); ?> />
                            <?php esc_html_e( 'Compress the backup file (fast, low compression level) before it is uploaded.', 'rsd-remote-backup' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Benefit varies a lot by content — sites with mostly text/database content compress well, sites dominated by already-compressed media (images, video) see little gain. Some hosts also throttle CPU, which can make compression a net loss. Run the benchmark on the Status tab to check before deciding.', 'rsd-remote-backup' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div><!-- #tab-general -->

        <!-- ===== Provider tab ===== -->
        <div id="tab-provider" class="rsd-rb-tab" style="display:none;">
            <h2><?php esc_html_e( 'Provider Credentials', 'rsd-remote-backup' ); ?></h2>

            <div id="provider-google-drive" class="rsd-rb-provider-section">
                <h3><?php esc_html_e( 'Google Drive', 'rsd-remote-backup' ); ?></h3>
                <details>
                    <summary><?php esc_html_e( 'Setup instructions — click to expand', 'rsd-remote-backup' ); ?></summary>
                    <ol>
                        <li><?php esc_html_e( 'Open Google Cloud Console → Create a project.', 'rsd-remote-backup' ); ?></li>
                        <li><?php esc_html_e( 'Enable the Google Drive API.', 'rsd-remote-backup' ); ?></li>
                        <li><?php esc_html_e( 'OAuth consent screen → External → add scope: .../auth/drive.file.', 'rsd-remote-backup' ); ?></li>
                        <li>
                            <strong class="rsd-rb-warning"><?php esc_html_e( '⚠ IMPORTANT: Publish the consent screen to "In production".', 'rsd-remote-backup' ); ?></strong>
                            <?php esc_html_e( ' "Testing" mode expires refresh tokens after 7 days — the plugin will silently stop uploading without this step.', 'rsd-remote-backup' ); ?>
                        </li>
                        <li><?php esc_html_e( 'Credentials → Create OAuth 2.0 Client ID (Web application).', 'rsd-remote-backup' ); ?></li>
                        <li>
                            <?php esc_html_e( 'Add Authorised redirect URI:', 'rsd-remote-backup' ); ?>
                            <code><?php echo esc_html( admin_url( 'admin.php?page=rsd-remote-backup&rb_oauth=callback&provider=google-drive' ) ); ?></code>
                        </li>
                        <li><?php esc_html_e( 'Copy Client ID and Client Secret below, save, then click Connect.', 'rsd-remote-backup' ); ?></li>
                    </ol>
                </details>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Client ID', 'rsd-remote-backup' ); ?></th>
                        <td><input type="text" name="rsd_rb_google_client_id"
                                   value="<?php echo esc_attr( RSD_RB_Settings::get_google_client_id() ); ?>"
                                   class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Client Secret', 'rsd-remote-backup' ); ?></th>
                        <td><input type="password" name="rsd_rb_google_client_secret"
                                   value=""
                                   placeholder="<?php echo '' !== RSD_RB_Settings::get_google_client_secret() ? esc_attr__( '(saved — leave blank to keep)', 'rsd-remote-backup' ) : esc_attr__( 'Enter client secret', 'rsd-remote-backup' ); ?>"
                                   autocomplete="new-password"
                                   class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Connection', 'rsd-remote-backup' ); ?></th>
                        <td><?php echo RSD_RB_Admin_Page::connection_status_html( 'google-drive', 'Google Drive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    </tr>
                </table>
            </div><!-- #provider-google-drive -->

            <div id="provider-onedrive" class="rsd-rb-provider-section" style="display:none;">
                <h3><?php esc_html_e( 'Microsoft OneDrive', 'rsd-remote-backup' ); ?></h3>
                <details>
                    <summary><?php esc_html_e( 'Setup instructions — click to expand', 'rsd-remote-backup' ); ?></summary>
                    <ol>
                        <li><?php esc_html_e( 'Azure Portal → App registrations → New registration.', 'rsd-remote-backup' ); ?></li>
                        <li><?php esc_html_e( 'Supported account types: "Personal Microsoft accounts" (or "any org + personal" for work/school too).', 'rsd-remote-backup' ); ?></li>
                        <li>
                            <?php esc_html_e( 'Add Redirect URI (Web) — copy exactly, no trailing variation:', 'rsd-remote-backup' ); ?>
                            <code><?php echo esc_html( home_url( '/rsd-rb-onedrive/' ) ); ?></code>
                        </li>
                        <li><?php esc_html_e( 'API permissions → Add: Files.ReadWrite.AppFolder, offline_access.', 'rsd-remote-backup' ); ?></li>
                        <li><?php esc_html_e( 'Certificates & secrets → New client secret → copy immediately.', 'rsd-remote-backup' ); ?></li>
                    </ol>
                </details>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Client ID (Application ID)', 'rsd-remote-backup' ); ?></th>
                        <td><input type="text" name="rsd_rb_od_client_id"
                                   value="<?php echo esc_attr( RSD_RB_Settings::get_od_client_id() ); ?>"
                                   class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Client Secret', 'rsd-remote-backup' ); ?></th>
                        <td><input type="password" name="rsd_rb_od_client_secret"
                                   value=""
                                   placeholder="<?php echo '' !== RSD_RB_Settings::get_od_client_secret() ? esc_attr__( '(saved — leave blank to keep)', 'rsd-remote-backup' ) : esc_attr__( 'Enter client secret', 'rsd-remote-backup' ); ?>"
                                   autocomplete="new-password"
                                   class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Account Type', 'rsd-remote-backup' ); ?></th>
                        <td>
                            <?php $od_acct = get_option( 'rsd_rb_od_account_type', 'consumers' ); ?>
                            <select name="rsd_rb_od_account_type">
                                <option value="consumers"     <?php selected( $od_acct, 'consumers' ); ?>><?php esc_html_e( 'Personal (consumers)', 'rsd-remote-backup' ); ?></option>
                                <option value="organizations" <?php selected( $od_acct, 'organizations' ); ?>><?php esc_html_e( 'Work / School (organizations)', 'rsd-remote-backup' ); ?></option>
                                <option value="common"        <?php selected( $od_acct, 'common' ); ?>><?php esc_html_e( 'Both (common)', 'rsd-remote-backup' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Connection', 'rsd-remote-backup' ); ?></th>
                        <td><?php echo RSD_RB_Admin_Page::connection_status_html( 'onedrive', 'OneDrive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    </tr>
                </table>
            </div><!-- #provider-onedrive -->
        </div><!-- #tab-provider -->

        <!-- ===== License tab ===== -->
        <div id="tab-license" class="rsd-rb-tab" style="display:none;">
            <h2><?php esc_html_e( 'License', 'rsd-remote-backup' ); ?></h2>

            <?php $license_payload = RSD_RB_License::get_payload(); ?>
            <?php if ( $license_payload ) : ?>
                <div class="notice notice-success inline" style="margin:0 0 12px;">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: client name from the license 2: issue date */
                            esc_html__( 'Licensed to %1$s (issued %2$s).', 'rsd-remote-backup' ),
                            esc_html( $license_payload['client'] ?? '—' ),
                            esc_html( $license_payload['issued_at'] ?? '—' )
                        );
                        ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-error inline" style="margin:0 0 12px;">
                    <p><?php esc_html_e( 'No valid license key entered — backups are disabled until one is provided.', 'rsd-remote-backup' ); ?></p>
                </div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'License Key', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <textarea name="rsd_rb_license_key" rows="3" class="large-text code"><?php echo esc_textarea( RSD_RB_Settings::get_license_key() ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Paste the license key provided by Red Swirl Design.', 'rsd-remote-backup' ); ?></p>
                    </td>
                </tr>
            </table>
        </div><!-- #tab-license -->

        <?php submit_button(); ?>
    </form>

    <!-- ===== Status & Log tab (outside form — read-only + manual actions) ===== -->
    <div id="tab-status" class="rsd-rb-tab" style="display:none;">

        <?php
        // Ongoing permalink health check: if OneDrive is connected but pretty permalinks have
        // since been disabled, the callback URL is unreachable and uploads/re-auth will fail.
        if ( 'onedrive' === RSD_RB_Settings::get_provider()
            && RSD_RB_OAuth::has_tokens( 'onedrive' )
            && '' === get_option( 'permalink_structure' ) ) :
        ?>
        <div class="notice notice-error inline" style="margin:12px 0;">
            <p>
                <strong><?php esc_html_e( 'OneDrive uploads are broken — Pretty Permalinks disabled', 'rsd-remote-backup' ); ?></strong><br>
                <?php esc_html_e( 'The OneDrive callback URL requires Pretty Permalinks to resolve. Go to Settings → Permalinks, choose any option other than "Plain", and save. OneDrive uploads will resume once permalinks are re-enabled.', 'rsd-remote-backup' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Manual actions -->
        <h2><?php esc_html_e( 'Manual Actions', 'rsd-remote-backup' ); ?></h2>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=upload_now' ), 'rsd_rb_upload_now' ) ); ?>"
               class="button button-primary">
                <?php esc_html_e( 'Upload Existing Backups Now', 'rsd-remote-backup' ); ?>
            </a>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=scan_files' ), 'rsd_rb_scan_files' ) ); ?>"
               class="button button-secondary">
                <?php esc_html_e( 'Scan Backup Files', 'rsd-remote-backup' ); ?>
            </a>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=resync' ), 'rsd_rb_resync' ) ); ?>"
               class="button button-secondary">
                <?php esc_html_e( 'Resync', 'rsd-remote-backup' ); ?>
            </a>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=reset_stalled' ), 'rsd_rb_reset_stalled' ) ); ?>"
               class="button button-secondary">
                <?php esc_html_e( 'Reset Stalled Jobs', 'rsd-remote-backup' ); ?>
            </a>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=retry_failed' ), 'rsd_rb_retry_failed' ) ); ?>"
               class="button button-secondary">
                <?php esc_html_e( 'Retry Failed Uploads', 'rsd-remote-backup' ); ?>
            </a>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=cancel_pending' ), 'rsd_rb_cancel_pending' ) ); ?>"
               class="button button-secondary"
               onclick="return confirm('<?php echo esc_js( __( 'Cancel every pending upload? This deletes their compressed temp files and cannot be undone — the original backup files on disk are untouched and can be re-queued by the next scan.', 'rsd-remote-backup' ) ); ?>');">
                <?php esc_html_e( 'Cancel Pending Uploads', 'rsd-remote-backup' ); ?>
            </a>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=test_connection' ), 'rsd_rb_test_connection' ) ); ?>"
               class="button button-secondary">
                <?php esc_html_e( 'Test Connection', 'rsd-remote-backup' ); ?>
            </a>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=run_compression_benchmark' ), 'rsd_rb_run_compression_benchmark' ) ); ?>"
               class="button button-secondary">
                <?php esc_html_e( 'Run Compression Benchmark', 'rsd-remote-backup' ); ?>
            </a>
            &nbsp;
            <a href="<?php echo esc_url( add_query_arg( 'rb_tab', 'status', admin_url( 'admin.php?page=rsd-remote-backup' ) ) ); ?>"
               class="button button-secondary">
                <?php esc_html_e( 'Refresh', 'rsd-remote-backup' ); ?>
            </a>
        </p>

        <!-- Compression -->
        <h2><?php esc_html_e( 'Compression', 'rsd-remote-backup' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Setting', 'rsd-remote-backup' ); ?></th>
                <td><?php echo RSD_RB_Settings::get_compress_enabled() ? esc_html__( 'Enabled', 'rsd-remote-backup' ) : esc_html__( 'Disabled', 'rsd-remote-backup' ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Detected Method', 'rsd-remote-backup' ); ?></th>
                <td><?php echo esc_html( RSD_RB_Compressor::method_label( RSD_RB_Compressor::detect_method() ) ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Last Benchmark', 'rsd-remote-backup' ); ?></th>
                <td>
                    <?php
                    $benchmark = RSD_RB_Compressor::get_last_benchmark();
                    if ( empty( $benchmark ) ) :
                    ?>
                        <em><?php esc_html_e( 'Not run yet — click "Run Compression Benchmark" above.', 'rsd-remote-backup' ); ?></em>
                    <?php else :
                        $savings_pct = round( ( 1 - $benchmark['ratio'] ) * 100 );
                    ?>
                        <?php
                        printf(
                            /* translators: 1: sample size 2: compressed size 3: percent smaller 4: method 5: duration in ms 6: when */
                            esc_html__( '%1$s sample → %2$s (%3$d%% smaller) via %4$s in %5$dms — checked %6$s', 'rsd-remote-backup' ),
                            esc_html( size_format( $benchmark['sample_size'], 2 ) ),
                            esc_html( size_format( $benchmark['compressed_size'], 2 ) ),
                            (int) $savings_pct,
                            esc_html( RSD_RB_Compressor::method_label( $benchmark['method'] ) ),
                            (int) $benchmark['duration_ms'],
                            esc_html( $benchmark['checked_at'] )
                        );
                        ?>
                        <p class="description">
                            <?php
                            if ( $savings_pct >= 15 ) {
                                esc_html_e( 'Compression looks worthwhile on this content.', 'rsd-remote-backup' );
                            } elseif ( $savings_pct <= 3 ) {
                                esc_html_e( 'Little benefit from compression here — if this server also throttles CPU, consider disabling it.', 'rsd-remote-backup' );
                            } else {
                                esc_html_e( 'Modest benefit from compression on this content.', 'rsd-remote-backup' );
                            }
                            ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <!-- Server Stats -->
        <h2><?php esc_html_e( 'Server Stats', 'rsd-remote-backup' ); ?></h2>
        <?php
        $server_stats = RSD_RB_Server_Stats::collect();
        $core_stats   = $server_stats['core'];
        $disk_free    = $core_stats['disk_free_bytes'];
        $disk_total   = $core_stats['disk_total_bytes'];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'PHP / WordPress / MySQL', 'rsd-remote-backup' ); ?></th>
                <td><?php echo esc_html( sprintf( 'PHP %s · WordPress %s · MySQL %s', $core_stats['php_version'], $core_stats['wp_version'], $core_stats['mysql_version'] ) ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Disk Space', 'rsd-remote-backup' ); ?></th>
                <td>
                    <?php if ( null !== $disk_free && null !== $disk_total ) : ?>
                        <?php echo esc_html( sprintf( '%s free of %s', size_format( $disk_free, 2 ), size_format( $disk_total, 2 ) ) ); ?>
                    <?php else : ?>
                        <em><?php esc_html_e( 'Unavailable — disk_free_space()/disk_total_space() disabled on this host.', 'rsd-remote-backup' ); ?></em>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Memory Limit / Execution Time', 'rsd-remote-backup' ); ?></th>
                <td><?php echo esc_html( sprintf( '%s memory limit · %s execution time', $core_stats['memory_limit'], $core_stats['max_execution_time'] > 0 ? $core_stats['max_execution_time'] . 's' : esc_html__( 'unlimited', 'rsd-remote-backup' ) ) ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Theme / Active Plugins', 'rsd-remote-backup' ); ?></th>
                <td><?php echo esc_html( sprintf( '%s · %d active plugin(s)%s', $core_stats['active_theme'], $core_stats['active_plugins_count'], $core_stats['multisite'] ? ' · ' . esc_html__( 'Multisite', 'rsd-remote-backup' ) : '' ) ); ?></td>
            </tr>
            <?php if ( isset( $server_stats['plugins']['wp_rocket'] ) ) : $wpr = $server_stats['plugins']['wp_rocket']; ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'WP Rocket', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <?php echo esc_html( sprintf( 'v%s', $wpr['version'] ) ); ?>
                        &nbsp;
                        <?php if ( ! $wpr['insights_available'] ) : ?>
                            <em><?php esc_html_e( 'Rocket Insights not available on this site.', 'rsd-remote-backup' ); ?></em>
                        <?php elseif ( null === $wpr['global_score'] ) : ?>
                            <em><?php esc_html_e( 'Rocket Insights enabled — no completed page tests yet.', 'rsd-remote-backup' ); ?></em>
                        <?php else :
                            $score      = $wpr['global_score'];
                            $score_css  = $score >= 90 ? 'rsd-rb-badge--complete' : ( $score >= 50 ? 'rsd-rb-badge--pending' : 'rsd-rb-badge--failed' );
                        ?>
                            <span class="rsd-rb-badge <?php echo esc_attr( $score_css ); ?>">
                                <?php echo esc_html( sprintf( /* translators: %d: score out of 100 */ __( 'Insights score: %d/100', 'rsd-remote-backup' ), $score ) ); ?>
                            </span>
                            <small>
                                <?php
                                printf(
                                    /* translators: 1: number of completed page tests 2: total pages monitored */
                                    esc_html__( '(%1$d of %2$d monitored page(s) completed)', 'rsd-remote-backup' ),
                                    (int) $wpr['pages_completed'],
                                    (int) $wpr['pages_monitored']
                                );
                                ?>
                            </small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ( isset( $server_stats['plugins']['wordfence'] ) ) : $wf = $server_stats['plugins']['wordfence']; ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Wordfence', 'rsd-remote-backup' ); ?></th>
                    <td>
                        <?php echo esc_html( sprintf( 'v%s', $wf['version'] ) ); ?>
                        <?php if ( ! $wf['firewall_summary_available'] ) : ?>
                            &nbsp;<em><?php esc_html_e( 'Firewall Summary not available on this site.', 'rsd-remote-backup' ); ?></em>
                        <?php else : ?>
                            <table class="widefat striped" style="margin-top:8px;max-width:520px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Firewall Summary', 'rsd-remote-backup' ); ?></th>
                                        <th><?php esc_html_e( 'Complex', 'rsd-remote-backup' ); ?></th>
                                        <th><?php esc_html_e( 'Brute Force', 'rsd-remote-backup' ); ?></th>
                                        <th><?php esc_html_e( 'Blocklist', 'rsd-remote-backup' ); ?></th>
                                        <th><?php esc_html_e( 'Total', 'rsd-remote-backup' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $window_labels = array(
                                        '24h' => __( 'Today', 'rsd-remote-backup' ),
                                        '7d'  => __( 'Week', 'rsd-remote-backup' ),
                                        '30d' => __( 'Month', 'rsd-remote-backup' ),
                                    );
                                    foreach ( $window_labels as $window_key => $label ) :
                                    ?>
                                        <tr>
                                            <th><?php echo esc_html( $label ); ?></th>
                                            <td><?php echo esc_html( number_format_i18n( $wf['firewall_summary']['complex'][ $window_key ] ) ); ?></td>
                                            <td><?php echo esc_html( number_format_i18n( $wf['firewall_summary']['brute_force'][ $window_key ] ) ); ?></td>
                                            <td><?php echo esc_html( number_format_i18n( $wf['firewall_summary']['blocklist'][ $window_key ] ) ); ?></td>
                                            <td><strong><?php echo esc_html( number_format_i18n( $wf['firewall_summary_totals'][ $window_key ] ) ); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="description"><?php esc_html_e( 'Attacks blocked, grouped the same way as the Wordfence dashboard\'s own Firewall Summary widget. "Blocklist" counts may read low/zero on a free (non-Premium) license — real-time blocklist blocking is a paid feature.', 'rsd-remote-backup' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>

        <!-- Job queue -->
        <h2><?php esc_html_e( 'Upload Queue', 'rsd-remote-backup' ); ?></h2>
        <?php
        $jobs = RSD_RB_Queue::get_jobs( null, 30 );
        if ( empty( $jobs ) ) {
            echo '<p>' . esc_html__( 'No jobs in the queue.', 'rsd-remote-backup' ) . '</p>';
        } else {
            ?>
            <table class="widefat striped rsd-rb-jobs-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( '#', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'File', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Provider', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Location', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Progress', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Compression', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Attempts', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Last Error', 'rsd-remote-backup' ); ?></th>
                        <th><?php esc_html_e( 'Updated', 'rsd-remote-backup' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $jobs as $job ) :
                        $filesize    = (int) $job['filesize'];
                        $bytes_sent  = (int) $job['bytes_sent'];
                        $is_complete = RSD_RB_Queue::STATUS_COMPLETE === $job['status'];
                        $manifest    = ! empty( $job['manifest_id'] ) ? RSD_RB_Manifest::get( (int) $job['manifest_id'] ) : null;
                        // While a compressed job is mid-transfer, bytes_sent tracks the offset into the
                        // smaller compressed file — use its size as the % denominator, not the original.
                        $progress_total = ( $manifest && ! empty( $manifest['compressed_size_bytes'] ) ) ? (int) $manifest['compressed_size_bytes'] : $filesize;
                        $pct            = $is_complete ? 100 : ( $progress_total > 0 ? round( ( $bytes_sent / $progress_total ) * 100 ) : 0 );
                        $status_css     = 'rsd-rb-badge--' . esc_attr( $job['status'] );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $job['id'] ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $job['filename'] ); ?></strong><br>
                                <small><?php echo esc_html( size_format( $filesize, 2 ) ); ?></small>
                            </td>
                            <td><?php echo esc_html( $job['provider'] ); ?></td>
                            <td><span class="rsd-rb-badge <?php echo esc_attr( $status_css ); ?>"><?php echo esc_html( $job['status'] ); ?></span></td>
                            <td>
                                <?php
                                $location = $job['location'] ?? RSD_RB_Queue::LOCATION_LOCAL;
                                ?>
                                <span class="rsd-rb-badge <?php echo esc_attr( RSD_RB_Queue::location_badge_class( $location ) ); ?>">
                                    <?php echo esc_html( RSD_RB_Queue::location_label( $location ) ); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ( $progress_total > 0 ) : ?>
                                    <div class="rsd-rb-progress" title="<?php echo esc_attr( size_format( $bytes_sent, 2 ) . ' / ' . size_format( $progress_total, 2 ) ); ?>">
                                        <div class="rsd-rb-progress__bar" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
                                    </div>
                                    <small><?php echo esc_html( $pct . '%' ); ?></small>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $manifest && ! empty( $manifest['compression_method'] ) ) : ?>
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
                                <?php elseif ( $manifest && RSD_RB_Manifest::STATUS_COMPRESS_FAILED === $manifest['status'] ) : ?>
                                    <small class="rsd-rb-warning" title="<?php esc_attr_e( 'Compression was enabled but failed for this backup — the raw file was uploaded instead.', 'rsd-remote-backup' ); ?>">
                                        <?php esc_html_e( 'failed — uploaded raw', 'rsd-remote-backup' ); ?>
                                    </small>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $job['attempts'] ); ?></td>
                            <td>
                                <?php if ( ! empty( $job['last_error'] ) ) : ?>
                                    <span class="rsd-rb-error-snippet" title="<?php echo esc_attr( $job['last_error'] ); ?>">
                                        <?php echo esc_html( wp_trim_words( $job['last_error'], 8, '…' ) ); ?>
                                    </span>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo esc_html( $job['updated_at'] ); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        // Next scan time.
        $next_scan = wp_next_scheduled( 'rsd_rb_scan' );
        if ( $next_scan ) {
            printf(
                '<p class="description">%s <strong>%s</strong></p>',
                esc_html__( 'Next scheduled scan:', 'rsd-remote-backup' ),
                esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_scan ) )
            );
        }
        ?>

        <!-- Log -->
        <h2><?php esc_html_e( 'Recent Log', 'rsd-remote-backup' ); ?></h2>
        <div class="rsd-rb-log">
            <?php
            $lines = RSD_RB_Logger::get_lines();
            if ( empty( $lines ) ) {
                echo '<p>' . esc_html__( 'No log entries yet.', 'rsd-remote-backup' ) . '</p>';
            } else {
                echo '<pre>';
                foreach ( $lines as $line ) {
                    echo esc_html( $line ) . "\n";
                }
                echo '</pre>';
            }
            ?>
        </div>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=download_log' ), 'rsd_rb_download_log' ) ); ?>"
               class="button button-secondary"><?php esc_html_e( 'Download Full Log', 'rsd-remote-backup' ); ?></a>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=clear_log' ), 'rsd_rb_clear_log' ) ); ?>"
               class="button button-secondary"><?php esc_html_e( 'Clear Log', 'rsd-remote-backup' ); ?></a>
        </p>

        <!-- API Access -->
        <h2><?php esc_html_e( 'CRM API Access', 'rsd-remote-backup' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Use these endpoints and key to connect your RSD CRM. Send the key as an HTTP header on every request.', 'rsd-remote-backup' ); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Status endpoint', 'rsd-remote-backup' ); ?></th>
                <td>
                    <code><?php echo esc_html( rest_url( 'rsd-rb/v1/status' ) ); ?></code>
                    <p class="description"><?php esc_html_e( 'GET — returns all jobs, provider, last/next scan time.', 'rsd-remote-backup' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Trigger endpoint', 'rsd-remote-backup' ); ?></th>
                <td>
                    <code><?php echo esc_html( rest_url( 'rsd-rb/v1/trigger' ) ); ?></code>
                    <p class="description"><?php esc_html_e( 'POST — runs the backup scanner and schedules any pending uploads.', 'rsd-remote-backup' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Server stats endpoint', 'rsd-remote-backup' ); ?></th>
                <td>
                    <code><?php echo esc_html( rest_url( 'rsd-rb/v1/server-stats' ) ); ?></code>
                    <p class="description"><?php esc_html_e( 'GET — core WP/server health plus plugin-specific stats (e.g. WP Rocket Insights score).', 'rsd-remote-backup' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Required header', 'rsd-remote-backup' ); ?></th>
                <td><code>X-RSD-API-Key: &lt;key&gt;</code> &nbsp;<?php esc_html_e( 'or', 'rsd-remote-backup' ); ?>&nbsp; <code>Authorization: Bearer &lt;key&gt;</code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'API Key', 'rsd-remote-backup' ); ?></th>
                <td>
                    <?php
                    // The key is stored as a SHA-256 hash and is never shown from the DB.
                    // After generation or regeneration the raw key lives in a transient for
                    // 1 hour so the admin can copy it. After that it is gone for good.
                    $reveal_key = get_transient( 'rsd_rb_api_key_reveal' );
                    $key_is_set = '' !== get_option( 'rsd_rb_api_key', '' );
                    ?>

                    <?php if ( $reveal_key ) : ?>
                        <div class="notice notice-warning inline" style="margin:0 0 8px;">
                            <p><strong><?php esc_html_e( 'Copy this key now — it will not be shown again after 1 hour.', 'rsd-remote-backup' ); ?></strong></p>
                        </div>
                        <input type="text" id="rsd-rb-api-key"
                               value="<?php echo esc_attr( $reveal_key ); ?>"
                               class="regular-text"
                               readonly
                               style="font-family:monospace;" />
                        <button type="button" class="button button-secondary"
                                onclick="(function(){var f=document.getElementById('rsd-rb-api-key');f.select();navigator.clipboard.writeText(f.value).catch(function(){f.select();});})()">
                            <?php esc_html_e( 'Copy', 'rsd-remote-backup' ); ?>
                        </button>
                    <?php elseif ( $key_is_set ) : ?>
                        <span style="font-family:monospace;letter-spacing:2px;">••••••••••••••••••••••••••••••••</span>
                        &nbsp;<em><?php esc_html_e( '(active)', 'rsd-remote-backup' ); ?></em>
                    <?php else : ?>
                        <em><?php esc_html_e( 'No key generated yet.', 'rsd-remote-backup' ); ?></em>
                    <?php endif; ?>

                    &nbsp;
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsd-remote-backup&rb_action=regenerate_api_key' ), 'rsd_rb_regenerate_api_key' ) ); ?>"
                       class="button button-secondary"
                       onclick="return confirm('<?php echo esc_js( __( 'Regenerate the API key? Any CRM connections using the old key will stop working until you update them.', 'rsd-remote-backup' ) ); ?>')">
                        <?php esc_html_e( 'Regenerate', 'rsd-remote-backup' ); ?>
                    </a>
                    <p class="description">
                        <?php esc_html_e( 'The raw key is stored as a SHA-256 hash and never written to the database in plain text. Regenerating immediately invalidates the old key.', 'rsd-remote-backup' ); ?>
                    </p>
                </td>
            </tr>
        </table>

    </div><!-- #tab-status -->

</div><!-- .rsd-rb-wrap -->

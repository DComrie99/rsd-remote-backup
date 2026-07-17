<?php
defined( 'ABSPATH' ) || exit;

/**
 * Breadcrumb + folder/file table for one path in a completed disk scan.
 * Rendered from two places — the normal Maintenance page load, and the
 * rsd_rb_disk_scan_view AJAX handler in class-plugin.php (which captures
 * this file's output via ob_start()/ob_get_clean() and returns it as a JSON
 * "html" field) — so navigating the scanned tree updates in place instead
 * of doing a full page reload per click.
 *
 * Expects $rsd_rb_disk_path (already validated via is_known_path() by the
 * caller) and $rsd_rb_disk_view ('folder'|'files') to be set.
 *
 * Uses an explicit admin_url()-based base for every link rather than the
 * current request's URL — deliberately, since this same markup is also
 * rendered from inside an AJAX request (whose "current URL" is
 * admin-ajax.php, not the Maintenance page), where relying on the current
 * URL would silently generate broken hrefs.
 */

$rsd_rb_disk_state = RSD_RB_Disk_Scanner::get_state();
$rsd_rb_disk_root  = $rsd_rb_disk_state['root'];

$rsd_rb_disk_base_url = admin_url( 'admin.php?page=' . RSD_RB_Maintenance_Page::PAGE_SLUG . '&rb_tab=disk-usage' );

$rsd_rb_disk_rel      = trim( str_replace( $rsd_rb_disk_root, '', $rsd_rb_disk_path ), '/\\' );
$rsd_rb_disk_segments = ( '' === $rsd_rb_disk_rel ) ? array() : preg_split( '#[\\\\/]+#', $rsd_rb_disk_rel );
?>
<p class="rsd-rb-breadcrumb">
    <?php
    $rsd_rb_crumb_path = $rsd_rb_disk_root;
    printf(
        '<a href="%s" data-disk-path="%s" data-disk-view="folder">%s</a>',
        esc_url( add_query_arg( array( 'rb_disk_path' => rawurlencode( $rsd_rb_disk_root ) ), $rsd_rb_disk_base_url ) ),
        esc_attr( $rsd_rb_disk_root ),
        esc_html__( 'Site Root', 'rsd-remote-backup' )
    );
    foreach ( $rsd_rb_disk_segments as $rsd_rb_segment ) {
        $rsd_rb_crumb_path .= DIRECTORY_SEPARATOR . $rsd_rb_segment;
        echo ' / ';
        printf(
            '<a href="%s" data-disk-path="%s" data-disk-view="folder">%s</a>',
            esc_url( add_query_arg( array( 'rb_disk_path' => rawurlencode( $rsd_rb_crumb_path ) ), $rsd_rb_disk_base_url ) ),
            esc_attr( $rsd_rb_crumb_path ),
            esc_html( $rsd_rb_segment )
        );
    }
    ?>
</p>

<?php if ( 'files' === $rsd_rb_disk_view ) :
    $rsd_rb_file_data      = RSD_RB_Disk_Scanner::list_files_in( $rsd_rb_disk_path );
    $rsd_rb_back_to_folder = add_query_arg( array( 'rb_disk_path' => rawurlencode( $rsd_rb_disk_path ) ), $rsd_rb_disk_base_url );
    ?>
    <p>
        <a href="<?php echo esc_url( $rsd_rb_back_to_folder ); ?>" data-disk-path="<?php echo esc_attr( $rsd_rb_disk_path ); ?>" data-disk-view="folder">
            &larr; <?php esc_html_e( 'Back to folder view', 'rsd-remote-backup' ); ?>
        </a>
    </p>

    <?php if ( $rsd_rb_file_data['truncated'] ) : ?>
        <p class="description">
            <?php
            printf(
                /* translators: 1: number of files shown 2: total number of files actually in the folder */
                esc_html__( 'Showing the %1$s largest of %2$s files in this folder (capped to keep this page responsive on folders with an unusually large number of loose files).', 'rsd-remote-backup' ),
                esc_html( number_format_i18n( count( $rsd_rb_file_data['files'] ) ) ),
                esc_html( number_format_i18n( $rsd_rb_file_data['total'] ) )
            );
            ?>
        </p>
    <?php endif; ?>

    <table class="widefat striped rsd-rb-jobs-table" style="max-width:760px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'File', 'rsd-remote-backup' ); ?></th>
                <th><?php esc_html_e( 'Size', 'rsd-remote-backup' ); ?></th>
                <th><?php esc_html_e( 'Modified', 'rsd-remote-backup' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $rsd_rb_file_data['files'] as $rsd_rb_file ) : ?>
                <tr>
                    <td>📄 <?php echo esc_html( $rsd_rb_file['name'] ); ?></td>
                    <td><?php echo esc_html( size_format( $rsd_rb_file['size'], 2 ) ); ?></td>
                    <td><?php echo esc_html( RSD_RB_Disk_Scanner::format_mtime( $rsd_rb_file['mtime'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rsd_rb_file_data['files'] ) ) : ?>
                <tr><td colspan="3"><em><?php esc_html_e( 'No loose files found here (they may have changed since the scan completed).', 'rsd-remote-backup' ); ?></em></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

<?php else :
    $rsd_rb_disk_rows = RSD_RB_Disk_Scanner::get_children_with_sizes( $rsd_rb_disk_path );
    ?>
    <table class="widefat striped rsd-rb-jobs-table" style="max-width:760px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Name', 'rsd-remote-backup' ); ?></th>
                <th><?php esc_html_e( 'Size', 'rsd-remote-backup' ); ?></th>
                <th><?php esc_html_e( 'Modified', 'rsd-remote-backup' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $rsd_rb_disk_rows as $rsd_rb_row ) : ?>
                <tr>
                    <td>
                        <?php if ( $rsd_rb_row['is_dir'] ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( array( 'rb_disk_path' => rawurlencode( $rsd_rb_row['path'] ) ), $rsd_rb_disk_base_url ) ); ?>"
                               data-disk-path="<?php echo esc_attr( $rsd_rb_row['path'] ); ?>" data-disk-view="folder">
                                📁 <?php echo esc_html( $rsd_rb_row['name'] ); ?>
                            </a>
                        <?php else : ?>
                            <a href="<?php echo esc_url( add_query_arg( array( 'rb_disk_path' => rawurlencode( $rsd_rb_row['path'] ), 'rb_disk_view' => 'files' ), $rsd_rb_disk_base_url ) ); ?>"
                               data-disk-path="<?php echo esc_attr( $rsd_rb_row['path'] ); ?>" data-disk-view="files">
                                <em><?php echo esc_html( $rsd_rb_row['name'] ); ?></em>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( size_format( $rsd_rb_row['size'], 2 ) ); ?></td>
                    <td><?php echo esc_html( RSD_RB_Disk_Scanner::format_mtime( $rsd_rb_row['mtime'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rsd_rb_disk_rows ) ) : ?>
                <tr><td colspan="3"><em><?php esc_html_e( 'This folder is empty.', 'rsd-remote-backup' ); ?></em></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
<?php endif; ?>

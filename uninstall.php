<?php
/**
 * Runs when the plugin is deleted (not just deactivated).
 * Drops the jobs table and removes all plugin options.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Cancel all Action Scheduler actions for this plugin (prevents fatal errors post-uninstall).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( '', array(), 'rsd-rb' );
}

// Cancel WP-Cron events.
wp_clear_scheduled_hook( 'rsd_rb_scan' );
wp_clear_scheduled_hook( 'rsd_rb_process_job_cron' );

// Drop jobs table.
$table = $wpdb->prefix . 'rsd_rb_jobs';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove all plugin options.
$options = array(
    'rsd_rb_db_version',
    'rsd_rb_backup_source',
    'rsd_rb_provider',
    'rsd_rb_scan_frequency',
    'rsd_rb_folder_name',
    'rsd_rb_retention_count',
    'rsd_rb_delete_local',
    'rsd_rb_time_budget',
    'rsd_rb_google_client_id',
    'rsd_rb_google_client_secret',
    'rsd_rb_od_client_id',
    'rsd_rb_od_client_secret',
    'rsd_rb_od_account_type',
    'rsd_rb_tokens_google-drive',
    'rsd_rb_tokens_onedrive',
    'rsd_rb_log',
    'rsd_rb_seen_files',
    'rsd_rb_api_key',
    'rsd_rb_last_scan',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

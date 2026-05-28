<?php
/**
 * Removes Native Link Health plugin data.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table                = $wpdb->prefix . 'nlh_link_errors';
$events_table         = $wpdb->prefix . 'nlh_link_events';
$correction_log_table = $wpdb->prefix . 'nlh_correction_log';

$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$events_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$correction_log_table}" );

delete_option( 'nlh_db_version' );
delete_option( 'nlh_ignored_urls' );
delete_option( 'nlh_scan_batch_size' );
delete_option( 'nlh_scan_metrics' );
delete_option( 'nlh_auto_rules' );
delete_option( 'nlh_suggestions' );
delete_option( '_transient_nlh_suggestions' );
delete_option( '_transient_timeout_nlh_suggestions' );
delete_option( '_transient_nlh_seo_audit_results' );
delete_option( '_transient_timeout_nlh_seo_audit_results' );

delete_post_meta_by_key( '_nlh_last_scan' );

$transient_ok_prefix                 = $wpdb->esc_like( '_transient_nlh_ok_' ) . '%';
$transient_ok_timeout_prefix         = $wpdb->esc_like( '_transient_timeout_nlh_ok_' ) . '%';
$transient_retry_prefix              = $wpdb->esc_like( '_transient_nlh_retry_' ) . '%';
$transient_retry_timeout_prefix      = $wpdb->esc_like( '_transient_timeout_nlh_retry_' ) . '%';
$last_ok_option_prefix               = $wpdb->esc_like( 'nlh_last_ok_' ) . '%';

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s",
		$transient_ok_prefix,
		$transient_ok_timeout_prefix,
		$transient_retry_prefix,
		$transient_retry_timeout_prefix,
		$last_ok_option_prefix
	)
);

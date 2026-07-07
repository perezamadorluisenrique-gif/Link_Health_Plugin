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

$link_map_table    = $wpdb->prefix . 'nlh_link_map';
$link_scores_table = $wpdb->prefix . 'nlh_link_scores';

$wpdb->query( "DROP TABLE IF EXISTS {$link_map_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$link_scores_table}" );

delete_option( 'nlh_db_version' );
delete_option( 'nlh_juice_dirty' );
delete_option( 'nlh_juice_computed_at' );
delete_option( 'nlh_health_history' );
delete_option( 'nlh_ignored_urls' );
delete_option( 'nlh_scan_batch_size' );
delete_option( 'nlh_scan_scope' );
delete_option( 'nlh_comment_scan_last_id' );
delete_option( 'nlh_scan_metrics' );
delete_option( 'nlh_auto_rules' );
delete_option( 'nlh_show_welcome' );
delete_option( 'nlh_suggestions' );
delete_option( '_transient_nlh_suggestions' );
delete_option( '_transient_timeout_nlh_suggestions' );
delete_option( '_transient_nlh_seo_audit_results' );
delete_option( '_transient_timeout_nlh_seo_audit_results' );

delete_post_meta_by_key( '_nlh_last_scan' );

$transient_ok_prefix             = $wpdb->esc_like( '_transient_nlh_ok_' ) . '%';
$transient_ok_timeout_prefix     = $wpdb->esc_like( '_transient_timeout_nlh_ok_' ) . '%';
$transient_retry_prefix          = $wpdb->esc_like( '_transient_nlh_retry_' ) . '%';
$transient_retry_timeout_prefix  = $wpdb->esc_like( '_transient_timeout_nlh_retry_' ) . '%';
$transient_fail_prefix           = $wpdb->esc_like( '_transient_nlh_fail_' ) . '%';
$transient_fail_timeout_prefix   = $wpdb->esc_like( '_transient_timeout_nlh_fail_' ) . '%';
$transient_broken_counts_prefix  = $wpdb->esc_like( '_transient_nlh_broken_counts_' ) . '%';
$transient_broken_counts_timeout = $wpdb->esc_like( '_transient_timeout_nlh_broken_counts_' ) . '%';
$last_ok_option_prefix           = $wpdb->esc_like( 'nlh_last_ok_' ) . '%';
$last_soft_option_prefix         = $wpdb->esc_like( 'nlh_last_soft_' ) . '%';

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s
		OR option_name LIKE %s",
		$transient_ok_prefix,
		$transient_ok_timeout_prefix,
		$transient_retry_prefix,
		$transient_retry_timeout_prefix,
		$transient_fail_prefix,
		$transient_fail_timeout_prefix,
		$transient_broken_counts_prefix,
		$transient_broken_counts_timeout,
		$last_ok_option_prefix,
		$last_soft_option_prefix
	)
);

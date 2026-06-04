<?php
/**
 * CSV export support.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports broken-link data.
 */
class NLH_Export {
	/**
	 * Streams broken links as CSV.
	 *
	 * @return void
	 */
	public function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'native-link-health' ) );
		}

		global $wpdb;

		$table = $wpdb->prefix . 'nlh_link_errors';
		$rows  = $wpdb->get_results( "SELECT post_id, raw_url, status_code, error_message, discovered_at, last_checked_at FROM {$table} ORDER BY impact_score DESC, last_checked_at DESC LIMIT 10000" );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=native-link-health-broken-links.csv' );

		$output = fopen( 'php://output', 'w' );

		if ( ! $output ) {
			exit;
		}

		fputcsv(
			$output,
			array(
				__( 'Post ID', 'native-link-health' ),
				__( 'Post Title', 'native-link-health' ),
				__( 'Broken URL', 'native-link-health' ),
				__( 'Status Code', 'native-link-health' ),
				__( 'Error', 'native-link-health' ),
				__( 'Discovered', 'native-link-health' ),
				__( 'Last Checked', 'native-link-health' ),
			)
		);

		// Prime post caches to avoid N+1 queries for each row's title.
		if ( ! empty( $rows ) ) {
			$post_ids = array_unique( wp_list_pluck( $rows, 'post_id' ) );
			_prime_post_caches( $post_ids, true, true );
		}

		foreach ( (array) $rows as $row ) {
			fputcsv(
				$output,
				array(
					(int) $row->post_id,
					get_the_title( (int) $row->post_id ),
					(string) $row->raw_url,
					(int) $row->status_code,
					(string) $row->error_message,
					(string) $row->discovered_at,
					(string) $row->last_checked_at,
				)
			);
		}

		fclose( $output );
		exit;
	}
}

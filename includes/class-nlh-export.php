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
		$rows  = $wpdb->get_results( "SELECT post_id, source_type, raw_url, status_code, error_message, discovered_at, last_checked_at FROM {$table} ORDER BY impact_score DESC, last_checked_at DESC LIMIT 10000" );

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
				__( 'Source', 'native-link-health' ),
				__( 'Source Title', 'native-link-health' ),
				__( 'Broken URL', 'native-link-health' ),
				__( 'Status Code', 'native-link-health' ),
				__( 'Error', 'native-link-health' ),
				__( 'Discovered', 'native-link-health' ),
				__( 'Last Checked', 'native-link-health' ),
			)
		);

		// Prime post caches to avoid N+1 queries for each row's title. Only post
		// records reference real post ids (comment rows hold the comment id).
		if ( ! empty( $rows ) ) {
			$post_ids = array();
			foreach ( $rows as $row ) {
				if ( 'post' === ( $row->source_type ?? 'post' ) ) {
					$post_ids[] = (int) $row->post_id;
				}
			}
			if ( $post_ids ) {
				_prime_post_caches( array_unique( $post_ids ), true, true );
			}
		}

		$scanner = new NLH_Scanner();

		foreach ( (array) $rows as $row ) {
			$source_type = (string) ( $row->source_type ?? 'post' );

			fputcsv(
				$output,
				array(
					(int) $row->post_id,
					$source_type,
					$this->escape_csv_field( $scanner->get_source_group_label( (int) $row->post_id, $source_type ) ),
					$this->escape_csv_field( (string) $row->raw_url ),
					(int) $row->status_code,
					$this->escape_csv_field( (string) $row->error_message ),
					(string) $row->discovered_at,
					(string) $row->last_checked_at,
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Neutralizes CSV formula injection.
	 *
	 * Post titles, URLs, and error messages derive from arbitrary post content,
	 * so a value beginning with =, +, -, @, or a control character (tab/CR) can
	 * be executed as a formula when the file is opened in Excel/Sheets. Prefix
	 * such values with a single quote so spreadsheet apps treat them as text.
	 *
	 * @param string $value Raw field value.
	 * @return string Safe field value.
	 */
	private function escape_csv_field( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}

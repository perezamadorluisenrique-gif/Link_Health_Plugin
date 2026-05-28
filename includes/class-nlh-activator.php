<?php
/**
 * Plugin activation routines.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database setup, defaults, and cron scheduling.
 */
class NLH_Activator {
	/**
	 * Adds the custom 15 minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_cron_schedule( array $schedules ): array {
		if ( ! isset( $schedules['nlh_every_15_min'] ) ) {
			$schedules['nlh_every_15_min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes', 'native-link-health' ),
			);
		}

		return $schedules;
	}

	/**
	 * Runs activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_table();
		self::set_default_options();
		self::upgrade();
		self::schedule_cron();
	}

	/**
	 * Creates or updates the link errors table using dbDelta().
	 *
	 * @return void
	 */
	private static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = $wpdb->prefix . 'nlh_link_errors';
		$sql   = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			raw_url text NOT NULL,
			url_hash varchar(32) NOT NULL,
			status_code smallint(5) NOT NULL DEFAULT 0,
			error_message varchar(255) DEFAULT NULL,
			impact_score tinyint(3) unsigned DEFAULT 0,
			last_ok_at datetime DEFAULT NULL,
			discovered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_checked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash_post (url_hash, post_id),
			KEY post_id (post_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

		dbDelta( $sql );
	}

	/**
	 * Runs versioned database upgrades for existing installs.
	 *
	 * @return void
	 */
	private static function upgrade(): void {
		$stored_version = (string) get_option( 'nlh_db_version', '0' );

		if ( version_compare( $stored_version, NLH_DB_VERSION, '>=' ) ) {
			return;
		}

		if ( version_compare( $stored_version, '2.0', '<' ) ) {
			self::upgrade_to_2_0();
		}

		update_option( 'nlh_db_version', NLH_DB_VERSION );
	}

	/**
	 * Adds v2.0 columns and event/correction tables.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_0(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$errors_table = $wpdb->prefix . 'nlh_link_errors';
		$columns      = $wpdb->get_col( "DESC {$errors_table}", 0 );

		if ( is_array( $columns ) && ! in_array( 'impact_score', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$errors_table} ADD impact_score tinyint(3) unsigned DEFAULT 0 AFTER error_message" );
		}

		if ( is_array( $columns ) && ! in_array( 'last_ok_at', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$errors_table} ADD last_ok_at datetime DEFAULT NULL AFTER impact_score" );
		}

		$events_table = $wpdb->prefix . 'nlh_link_events';
		$events_sql   = "CREATE TABLE {$events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash varchar(32) NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			event_type varchar(20) NOT NULL,
			status_code smallint(5) DEFAULT NULL,
			event_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY url_hash_post (url_hash, post_id),
			KEY event_at (event_at)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

		dbDelta( $events_sql );

		$correction_log_table = $wpdb->prefix . 'nlh_correction_log';
		$correction_log_sql   = "CREATE TABLE {$correction_log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			old_url text,
			new_url text,
			method varchar(20) DEFAULT 'manual',
			applied_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY applied_at (applied_at)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

		dbDelta( $correction_log_sql );
	}

	/**
	 * Sets default options without overwriting administrator choices.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		add_option( 'nlh_ignored_urls', array() );
		add_option( 'nlh_scan_batch_size', NLH_BATCH_SIZE );
		add_option(
			'nlh_scan_metrics',
			array(
				'total_urls_checked'  => 0,
				'total_broken_found'  => 0,
				'total_skipped_valid' => 0,
				'total_retries'       => 0,
				'last_batch_duration' => 0.0,
				'peak_memory_usage'   => 0,
				'last_updated'        => 0,
			)
		);
	}

	/**
	 * Schedules the recurring scanner event.
	 *
	 * @return void
	 */
	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'nlh_run_batch' ) ) {
			wp_schedule_event( time(), 'nlh_every_15_min', 'nlh_run_batch' );
		}
	}
}

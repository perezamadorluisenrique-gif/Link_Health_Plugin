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
		add_option( 'nlh_show_welcome', '1' );
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
			source_type varchar(20) NOT NULL DEFAULT 'post',
			raw_url text NOT NULL,
			url_hash varchar(32) NOT NULL,
			status_code smallint(5) NOT NULL DEFAULT 0,
			error_message varchar(255) DEFAULT NULL,
			impact_score tinyint(3) unsigned DEFAULT 0,
			last_ok_at datetime DEFAULT NULL,
			discovered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_checked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash_post_source (url_hash, post_id, source_type),
			KEY post_id (post_id),
			KEY status_code (status_code)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

		dbDelta( $sql );
	}

	/**
	 * Runs pending database upgrades on an already-active install.
	 *
	 * Plugin file updates do not re-fire the activation hook, so this is hooked
	 * on admin_init to bring existing installs up to the current schema. It is
	 * idempotent: upgrade() returns early when the stored version is current.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( version_compare( (string) get_option( 'nlh_db_version', '0' ), NLH_DB_VERSION, '<' ) ) {
			self::upgrade();
		}
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

		if ( version_compare( $stored_version, '2.1', '<' ) ) {
			self::upgrade_to_2_1();
		}

		if ( version_compare( $stored_version, '2.2', '<' ) ) {
			self::upgrade_to_2_2();
		}

		if ( version_compare( $stored_version, '2.3', '<' ) ) {
			self::upgrade_to_2_3();
		}

		update_option( 'nlh_db_version', NLH_DB_VERSION );
	}

	/**
	 * Adds v2.1 internal link-map and link-juice score tables.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_1(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$map_table = $wpdb->prefix . 'nlh_link_map';
		$map_sql   = "CREATE TABLE {$map_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id bigint(20) unsigned NOT NULL,
			target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_url text NOT NULL,
			url_hash varchar(32) NOT NULL,
			link_type varchar(10) NOT NULL DEFAULT 'internal',
			anchor_text varchar(255) DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY src_hash (source_post_id, url_hash),
			KEY source_post_id (source_post_id),
			KEY target_post_id (target_post_id),
			KEY link_type (link_type)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

		dbDelta( $map_sql );

		$scores_table = $wpdb->prefix . 'nlh_link_scores';
		$scores_sql   = "CREATE TABLE {$scores_table} (
			post_id bigint(20) unsigned NOT NULL,
			pagerank double NOT NULL DEFAULT 0,
			inbound_internal int unsigned NOT NULL DEFAULT 0,
			outbound_internal int unsigned NOT NULL DEFAULT 0,
			outbound_total int unsigned NOT NULL DEFAULT 0,
			computed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (post_id),
			KEY pagerank (pagerank)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

		dbDelta( $scores_sql );
	}

	/**
	 * Adds v2.2 index to nlh_link_errors for post_id+url_hash lookups.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_2(): void {
		global $wpdb;

		$errors_table  = $wpdb->prefix . 'nlh_link_errors';
		$existing_keys = $wpdb->get_results( "SHOW INDEX FROM {$errors_table}" );
		$has_index     = false;
		foreach ( $existing_keys as $key ) {
			if ( 'post_id_url_hash' === $key->Key_name ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$has_index = true;
				break;
			}
		}
		if ( ! $has_index ) {
			$wpdb->query( "ALTER TABLE {$errors_table} ADD INDEX post_id_url_hash (post_id, url_hash)" );
		}
	}

	/**
	 * Adds v2.3 source_type column to nlh_link_errors so broken links living in
	 * comments and navigation menus can be recorded without conflating them with
	 * the post they belong to. The UNIQUE KEY is widened to include source_type.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_3(): void {
		global $wpdb;

		$errors_table = $wpdb->prefix . 'nlh_link_errors';

		// Add source_type column if absent.
		$columns = $wpdb->get_col( "DESC {$errors_table}", 0 );
		if ( is_array( $columns ) && ! in_array( 'source_type', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$errors_table} ADD source_type varchar(20) NOT NULL DEFAULT 'post' AFTER post_id" );
		}

		// Replace the old UNIQUE KEY url_hash_post(url_hash, post_id) with one that
		// includes source_type so a comment and its parent post can both record the
		// same broken URL without conflicting.
		$existing_keys = $wpdb->get_results( "SHOW INDEX FROM {$errors_table}" );
		$has_old_key   = false;
		$has_new_key   = false;
		foreach ( $existing_keys as $key ) {
			if ( 'url_hash_post' === $key->Key_name ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$has_old_key = true;
			}
			if ( 'url_hash_post_source' === $key->Key_name ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$has_new_key = true;
			}
		}
		if ( $has_old_key ) {
			$wpdb->query( "ALTER TABLE {$errors_table} DROP INDEX url_hash_post" );
		}
		if ( ! $has_new_key ) {
			$wpdb->query( "ALTER TABLE {$errors_table} ADD UNIQUE KEY url_hash_post_source (url_hash, post_id, source_type)" );
		}
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

		// Add status_code index for performance.
		$existing_keys  = $wpdb->get_results( "SHOW INDEX FROM {$errors_table}" );
		$has_status_key = false;
		foreach ( $existing_keys as $key ) {
			if ( 'status_code' === $key->Key_name ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$has_status_key = true;
				break;
			}
		}
		if ( ! $has_status_key ) {
			$wpdb->query( "ALTER TABLE {$errors_table} ADD KEY status_code (status_code)" );
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
			'nlh_scan_scope',
			array(
				'post_types' => array(),
				'comments'   => false,
				'menus'      => false,
			)
		);
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

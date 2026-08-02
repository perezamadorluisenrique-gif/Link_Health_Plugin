<?php
/**
 * Plugin Name:       Native Link Health
 * Description:       A lightweight broken link scanner and internal-link authority analyzer for WordPress. Runs locally, never crashes your server, and avoids false positives. No cloud, no accounts.
 * Version:           1.5.6
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Native Link Health contributors
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       native-link-health
 * Domain Path:       /languages
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NLH_VERSION', '1.5.6' );
define( 'NLH_DB_VERSION', '2.3' );
define( 'NLH_BATCH_SIZE', 5 );
define( 'NLH_PLUGIN_FILE', __FILE__ );
define( 'NLH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'NLH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NLH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NLH_TEXT_DOMAIN', 'native-link-health' );
define( 'NLH_UPGRADE_URL', '' );

/*
 * ---------------------------------------------------------------------------
 * Monetization / licensing configuration (Phase 4).
 *
 * Native Link Health is part of a multi-plugin suite. Licensing is handled by
 * Freemius, but the integration ships DISABLED so a fresh install shows no
 * upsells, no "Pro" UI, and makes no external calls. The suite owner flips
 * NLH_FREEMIUS_ENABLED to true and fills the credentials below from the
 * Freemius dashboard before publishing the commercial build. Pricing is set
 * externally in Freemius and is never hardcoded here.
 *
 * Pro features are gated entirely through WordPress filters (see NLH_Pro). The
 * separate Pro plugin overrides those filters; this free core never contains
 * Pro code.
 * ---------------------------------------------------------------------------
 */
if ( ! defined( 'NLH_FREEMIUS_ENABLED' ) ) {
	define( 'NLH_FREEMIUS_ENABLED', false );
}
if ( ! defined( 'NLH_FREEMIUS_PRODUCT_ID' ) ) {
	define( 'NLH_FREEMIUS_PRODUCT_ID', '' );
}
if ( ! defined( 'NLH_FREEMIUS_PUBLIC_KEY' ) ) {
	define( 'NLH_FREEMIUS_PUBLIC_KEY', '' );
}
if ( ! defined( 'NLH_AUTHOR_WP_HANDLE' ) ) {
	define( 'NLH_AUTHOR_WP_HANDLE', '[WP_ORG_USERNAME]' );
}

require_once NLH_PLUGIN_DIR . 'includes/class-nlh-activator.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-deactivator.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-i18n.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-pro.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-freemius.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-scanner.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-link-graph.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-link-recommendations.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-rules-engine.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-seo-audit.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-export.php';
require_once NLH_PLUGIN_DIR . 'admin/class-nlh-admin.php';

register_activation_hook( __FILE__, array( 'NLH_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NLH_Deactivator', 'deactivate' ) );

add_filter( 'cron_schedules', array( 'NLH_Activator', 'add_cron_schedule' ) );

// wp_schedule_event() freezes the interval at scheduling time, so a frequency
// change only takes effect once the booked event is cleared and re-created.
add_action( 'update_option_nlh_scan_frequency', array( 'NLH_Activator', 'reschedule_cron' ) );

/**
 * Returns the capability required to view the plugin's admin screens.
 *
 * Read access covers the dashboards (broken links, SEO audit, Link Juice) and
 * the read-only AJAX endpoints behind them. On agency sites the intended user
 * is often an Editor or a dedicated SEO role rather than an administrator, so
 * this is filterable. Destructive actions route through
 * nlh_get_write_capability() instead — lowering the bar for *looking* is not
 * the same as lowering it for *changing post content*.
 *
 * @since 1.5.6
 * @return string Capability slug.
 */
function nlh_get_capability(): string {
	/**
	 * Filters the capability required to read the plugin's admin screens.
	 *
	 * @since 1.5.6
	 * @param string $capability Default 'manage_options'.
	 */
	$capability = apply_filters( 'nlh_capability', 'manage_options' );

	return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
}

/**
 * Returns the capability required for destructive/write actions.
 *
 * Applies to anything that edits post content or changes configuration:
 * single/bulk corrections, Link Juice re-linking, the ignore list, the rules
 * editor, and the Settings page. Read-only work (dashboards, scans, re-checks,
 * the SEO audit, PageRank recomputation, CSV export of data already visible on
 * the dashboard) stays on nlh_get_capability().
 *
 * This deliberately does NOT inherit from nlh_get_capability(): lowering the
 * bar to let an SEO role *look* at the dashboard must not silently also hand
 * that role bulk find-and-replace over every post on the site. Opening up reads
 * is one decision; opening up writes is a second, separate one, so a site that
 * wants a single gate has to say so by filtering both.
 *
 * @since 1.5.6
 * @return string Capability slug.
 */
function nlh_get_write_capability(): string {
	/**
	 * Filters the capability required for the plugin's destructive actions.
	 *
	 * @since 1.5.6
	 * @param string $capability Default 'manage_options' — independent of nlh_capability.
	 */
	$capability = apply_filters( 'nlh_write_capability', 'manage_options' );

	return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
}

/**
 * Whether the current user may read the plugin's admin screens.
 *
 * @since 1.5.6
 * @return bool
 */
function nlh_current_user_can_read(): bool {
	return current_user_can( nlh_get_capability() );
}

/**
 * Whether the current user may perform the plugin's destructive actions.
 *
 * @since 1.5.6
 * @return bool
 */
function nlh_current_user_can_write(): bool {
	return current_user_can( nlh_get_write_capability() );
}

/**
 * Updates a link in post content with the native WordPress HTML tag processor.
 *
 * @param int    $post_id Post ID.
 * @param string $old_url URL to replace.
 * @param string $new_url Replacement URL.
 * @return bool
 */
function nlh_update_post_link( int $post_id, string $old_url, string $new_url ): bool {
	$scanner = new NLH_Scanner();
	return $scanner->update_post_link( $post_id, $old_url, $new_url );
}

/**
 * Boots plugin runtime hooks.
 *
 * @return void
 */
function nlh_bootstrap(): void {
	$i18n = new NLH_i18n();
	add_action( 'plugins_loaded', array( $i18n, 'load_plugin_textdomain' ) );

	// Initialize the licensing layer. No-op unless the owner enabled Freemius and
	// added the SDK; the free build makes no external calls.
	NLH_Freemius::boot();

	$scanner = new NLH_Scanner();
	add_action( 'nlh_run_batch', array( $scanner, 'run_batch' ) );
	add_action( 'save_post', array( $scanner, 'handle_post_saved' ), 10, 3 );
	add_action( 'deleted_post', 'nlh_cleanup_deleted_post' );
	add_action( 'delete_comment', 'nlh_cleanup_deleted_comment' );

	if ( is_admin() ) {
		// Plugin file updates don't re-run activation, so apply any pending DB
		// schema upgrades (e.g. the v2.1 link-map tables) on existing installs.
		add_action( 'admin_init', array( 'NLH_Activator', 'maybe_upgrade' ) );

		$admin = new NLH_Admin( $scanner );
		$admin->init();
	}
}

nlh_bootstrap();

/**
 * Cleans up link scan data when a post is deleted.
 *
 * @since 1.0.1
 * @param int $post_id Deleted post ID.
 */
function nlh_cleanup_deleted_post( int $post_id ): void {
	global $wpdb;

	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return;
	}

	// Only the post's own link records use this post_id; comment records reuse
	// the post_id column for the comment id, so scope the errors delete by source.
	$wpdb->delete(
		$wpdb->prefix . 'nlh_link_errors',
		array(
			'post_id'     => $post_id,
			'source_type' => 'post',
		),
		array( '%d', '%s' )
	);

	foreach ( array( 'nlh_link_events', 'nlh_correction_log' ) as $table ) {
		$wpdb->delete(
			$wpdb->prefix . $table,
			array( 'post_id' => $post_id ),
			array( '%d' )
		);
	}

	// Drop the post from the internal link graph (both as a source and a
	// target) and remove its cached juice score.
	( new NLH_Link_Graph() )->delete_post( $post_id );
}

/**
 * Cleans up link scan data when a comment is deleted.
 *
 * Comment-sourced broken-link records key the comment id into the post_id
 * column with source_type 'comment', so removal is scoped to that comment.
 *
 * @param int|string $comment_id Deleted comment ID.
 */
function nlh_cleanup_deleted_comment( $comment_id ): void {
	global $wpdb;

	$comment_id = (int) $comment_id;
	if ( $comment_id <= 0 ) {
		return;
	}

	$wpdb->delete(
		$wpdb->prefix . 'nlh_link_errors',
		array(
			'post_id'     => $comment_id,
			'source_type' => 'comment',
		),
		array( '%d', '%s' )
	);
}

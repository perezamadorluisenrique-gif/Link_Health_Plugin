<?php
/**
 * Plugin Name:       Native Link Health
 * Plugin URI:        [PLUGIN_URI]
 * Description:       A lightweight broken link scanner for WordPress. Runs locally, never crashes your server, and avoids false positives. No cloud. No paywalls.
 * Version:           1.0.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            [AUTHOR_NAME]
 * Author URI:        [AUTHOR_URI]
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

define( 'NLH_VERSION', '1.0.1' );
define( 'NLH_DB_VERSION', '2.0' );
define( 'NLH_BATCH_SIZE', 5 );
define( 'NLH_PLUGIN_FILE', __FILE__ );
define( 'NLH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'NLH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NLH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NLH_TEXT_DOMAIN', 'native-link-health' );
define( 'NLH_UPGRADE_URL', '[UPGRADE_URL]' );

require_once NLH_PLUGIN_DIR . 'includes/class-nlh-activator.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-deactivator.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-i18n.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-scanner.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-rules-engine.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-seo-audit.php';
require_once NLH_PLUGIN_DIR . 'includes/class-nlh-export.php';
require_once NLH_PLUGIN_DIR . 'admin/class-nlh-admin.php';

register_activation_hook( __FILE__, array( 'NLH_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NLH_Deactivator', 'deactivate' ) );

add_filter( 'cron_schedules', array( 'NLH_Activator', 'add_cron_schedule' ) );

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

	$scanner = new NLH_Scanner();
	add_action( 'nlh_run_batch', array( $scanner, 'run_batch' ) );
	add_action( 'save_post', array( $scanner, 'handle_post_saved' ), 10, 3 );
	add_action( 'deleted_post', 'nlh_cleanup_deleted_post' );

	if ( is_admin() ) {
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

    foreach ( array( 'nlh_link_errors', 'nlh_link_events', 'nlh_correction_log' ) as $table ) {
        $wpdb->delete(
            $wpdb->prefix . $table,
            array( 'post_id' => $post_id ),
            array( '%d' )
        );
    }
}

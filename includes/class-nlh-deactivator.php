<?php
/**
 * Plugin deactivation routines.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles deactivation cleanup.
 */
class NLH_Deactivator {
	/**
	 * Clears scheduled scan events.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'nlh_run_batch' );
	}
}

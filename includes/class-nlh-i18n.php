<?php
/**
 * Internationalization support.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads plugin translations.
 */
class NLH_i18n {
	/**
	 * Loads the text domain.
	 *
	 * @return void
	 */
	public function load_plugin_textdomain(): void {
		load_plugin_textdomain(
			'native-link-health',
			false,
			dirname( NLH_PLUGIN_BASENAME ) . '/languages/'
		);
	}
}

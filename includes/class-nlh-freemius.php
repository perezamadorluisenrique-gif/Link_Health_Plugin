<?php
/**
 * Freemius SDK bootstrap.
 *
 * Loads and initializes the Freemius SDK ONLY when the suite owner has enabled
 * it (NLH_FREEMIUS_ENABLED) and dropped the real SDK into /vendor/freemius/.
 * Until then this is an inert no-op: the free build makes no external calls and
 * shows no licensing UI.
 *
 * The SDK itself is not bundled in version control — the owner adds it from the
 * Freemius dashboard. See vendor/freemius/README.md.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps Freemius initialization behind the enable flag and SDK presence checks.
 */
class NLH_Freemius {
	/**
	 * Boots the SDK if enabled, present, and configured. Safe to call always.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! defined( 'NLH_FREEMIUS_ENABLED' ) || ! NLH_FREEMIUS_ENABLED ) {
			return;
		}

		// Already booted (e.g. shared SDK across the suite).
		if ( function_exists( 'nlh_fs' ) ) {
			return;
		}

		$sdk = NLH_PLUGIN_DIR . 'vendor/freemius/start.php';

		if ( ! is_readable( $sdk ) || '' === (string) NLH_FREEMIUS_PRODUCT_ID || '' === (string) NLH_FREEMIUS_PUBLIC_KEY ) {
			// Enabled but not yet ready: warn admins instead of fataling.
			add_action( 'admin_notices', array( __CLASS__, 'render_setup_notice' ) );
			return;
		}

		require_once $sdk;

		/**
		 * Exposes the Freemius instance suite-wide.
		 *
		 * @return Freemius
		 */
		function nlh_fs() {
			global $nlh_fs;

			if ( ! isset( $nlh_fs ) ) {
				$nlh_fs = fs_dynamic_init(
					array(
						'id'             => NLH_FREEMIUS_PRODUCT_ID,
						'slug'           => 'native-link-health',
						'type'           => 'plugin',
						'public_key'     => NLH_FREEMIUS_PUBLIC_KEY,
						'is_premium'     => false,
						'has_addons'     => false,
						'has_paid_plans' => true,
						'menu'           => array(
							'slug'    => 'nlh-dashboard',
							'parent'  => array( 'slug' => 'tools.php' ),
							'account' => true,
							'contact' => false,
							'support' => false,
						),
					)
				);
			}

			return $nlh_fs;
		}

		// Initialize, then surface the SDK's pricing URL to the upsell cards so no
		// price or checkout URL is ever hardcoded in core.
		nlh_fs();
		do_action( 'nlh_fs_loaded' );

		add_filter(
			'nlh_pro_upgrade_url',
			static function ( $url ) {
				if ( function_exists( 'nlh_fs' ) && method_exists( nlh_fs(), 'get_upgrade_url' ) ) {
					return nlh_fs()->get_upgrade_url();
				}

				return $url;
			}
		);
	}

	/**
	 * Admin notice shown when Freemius is enabled but not yet configured.
	 *
	 * @return void
	 */
	public static function render_setup_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Native Link Health:', 'native-link-health' ); ?></strong>
				<?php esc_html_e( 'Licensing is enabled but the Freemius SDK and credentials are not configured yet. Add the SDK to /vendor/freemius/ and set NLH_FREEMIUS_PRODUCT_ID and NLH_FREEMIUS_PUBLIC_KEY.', 'native-link-health' ); ?>
			</p>
		</div>
		<?php
	}
}

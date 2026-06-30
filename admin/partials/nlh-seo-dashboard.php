<?php
/**
 * SEO audit page template.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap nlh-wrap">
	<div class="nlh-page-header">
		<h1><?php esc_html_e( 'Native Link Health SEO Audit', 'native-link-health' ); ?></h1>
	</div>

	<div id="nlh-admin-notice" class="notice nlh-notice" hidden></div>

	<div class="nlh-seo-dashboard-actions">
		<button type="button" class="button button-primary" id="nlh-run-seo-audit"><?php esc_html_e( 'Run SEO Audit', 'native-link-health' ); ?></button>
	</div>

	<p class="description nlh-seo-orphan-note">
		<?php esc_html_e( 'Note: this audit treats a page as an orphan only when nothing links to it — not your content, and not your navigation menus. The Link Juice module reports orphans by content links alone, so its orphan count is usually higher.', 'native-link-health' ); ?>
	</p>

	<div id="nlh-seo-results" class="nlh-seo-results"></div>

	<?php
	// Detection of redirect chains is free (above). Turning them into clean 301s
	// is the Pro action — shown only when the monetization layer is enabled.
	NLH_Pro::upsell_card( 'redirect_management' );

	/**
	 * Extension point: the Pro plugin renders its 301 redirect manager here.
	 *
	 * @since 1.3.0
	 */
	do_action( 'nlh_seo_audit_after' );
	?>
</div>

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

	<div id="nlh-seo-results" class="nlh-seo-results"></div>
</div>

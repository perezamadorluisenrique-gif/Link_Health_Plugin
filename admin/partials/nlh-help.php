<?php
/**
 * Unified help section for the Settings page.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="nlh-help">
	<h2><?php esc_html_e( 'How to use Native Link Health', 'native-link-health' ); ?></h2>
	<p class="nlh-help-intro"><?php esc_html_e( 'A quick reference for what each screen does and how the free and Pro features fit together.', 'native-link-health' ); ?></p>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'Overview', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'Native Link Health scans your site for broken links and tracks how authority flows between your own pages, entirely on your own server. The Link Health Score blends two things: 60% how many broken links are currently unresolved, and 40% how well internal authority (Link Juice) flows through your content. A lower score means more broken links, more isolated pages, or both.', 'native-link-health' ); ?></p>
		</div>
	</details>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'Dashboard — broken links', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'The scanner runs automatically every 15 minutes, checking a few posts at a time so it never slows down your site. Use "Scan Now" to check everything immediately instead of waiting. Broken links appear in the list with their error type and impact score.', 'native-link-health' ); ?></p>
			<p><?php esc_html_e( 'Correction Suggestions groups broken links by domain and lets you fix every matching URL at once — it only shows patterns detected automatically from what is already broken.', 'native-link-health' ); ?></p>
			<p>
				<span class="nlh-pro-badge"><?php esc_html_e( 'Pro', 'native-link-health' ); ?></span>
				<?php esc_html_e( 'Bulk Fix & Find-Replace works differently: it lets you replace any URL, anywhere in your content, whether or not it is currently flagged as broken.', 'native-link-health' ); ?>
			</p>
		</div>
	</details>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'SEO Audit', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'Runs 12 checks against your published content: orphan pages, redirect chains and dead-end redirects, mixed content, invalid canonical tags, and redundant links; missing image alt text, image dimension mismatches, and legacy image formats; and title length, meta description length, heading hierarchy, and keyword density. All 12 checks are free. Results are cached for a day — run the audit again any time to refresh them.', 'native-link-health' ); ?></p>
		</div>
	</details>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'Link Juice', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'Maps how authority flows between your own pages through internal links, entirely offline with no external requests. The site graph shows your most-linked pages as larger nodes; click a node to see its connections. Pages are flagged as Orphans (nothing links to them), Dead Ends (they receive links but link out to nothing), or Diluted (they link out to too many pages, spreading their authority thin). Recommendations suggest which pages to link together first.', 'native-link-health' ); ?></p>
			<p>
				<span class="nlh-pro-badge"><?php esc_html_e( 'Pro', 'native-link-health' ); ?></span>
				<?php esc_html_e( 'Insert Link lets you add a suggested link directly from a recommendation card without leaving this page.', 'native-link-health' ); ?>
			</p>
		</div>
	</details>

	<details class="nlh-help-panel">
		<summary><?php esc_html_e( 'Settings (this page)', 'native-link-health' ); ?></summary>
		<div class="nlh-help-panel-body">
			<p><?php esc_html_e( 'Scan Scope controls which content types get scanned beyond posts and pages — turn on Media, Comments, or Navigation Menus here. Batch Size controls how many items the background scanner checks every 15 minutes; lower it on a slow host, raise it on a fast one. Auto-fix Rules let you define JSON rules that automatically rewrite known-bad domains whenever the scanner runs.', 'native-link-health' ); ?></p>
			<p>
				<span class="nlh-pro-badge"><?php esc_html_e( 'Pro', 'native-link-health' ); ?></span>
				<?php esc_html_e( 'Bulk Fix & Find-Replace, Redirect Manager, and Email Notifications add manual bulk editing, 301/302 redirect management, and broken-link email alerts.', 'native-link-health' ); ?>
			</p>
		</div>
	</details>
</div>

<?php
/**
 * Dashboard page template.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$render_card = function ( $row ) {
	$post_id     = absint( $row->post_id );
	$record_id   = absint( $row->id );
	$raw_url     = (string) $row->raw_url;
	$display_url = wp_html_excerpt( $raw_url, 72, '...' );
	$source_type = isset( $row->source_type ) ? (string) $row->source_type : 'post';
	$is_post_src = ( 'post' === $source_type );

	if ( 'comment' === $source_type ) {
		// post_id holds the comment id for comment-sourced records.
		$comment       = get_comment( $post_id );
		$parent_post   = $comment ? (int) $comment->comment_post_ID : 0;
		$parent_title  = $parent_post ? get_the_title( $parent_post ) : '';
		$post_title    = $parent_title
			? sprintf( /* translators: %s: post title. */ __( 'Comment on “%s”', 'native-link-health' ), $parent_title )
			: __( '(comment)', 'native-link-health' );
		$edit_post_url = $comment ? get_edit_comment_link( $post_id ) : '';
		$source_label  = __( 'Comment', 'native-link-health' );
	} elseif ( 'menu' === $source_type ) {
		$post_title    = __( 'Navigation menu', 'native-link-health' );
		$edit_post_url = admin_url( 'nav-menus.php' );
		$source_label  = __( 'Menu', 'native-link-health' );
	} else {
		$post_title    = get_the_title( $post_id );
		$post_title    = $post_title ? $post_title : __( '(no title)', 'native-link-health' );
		$edit_post_url = get_edit_post_link( $post_id, '' );
		$source_label  = '';
	}

	$status_code    = (int) $row->status_code;
	$impact_score   = isset( $row->impact_score ) ? (int) $row->impact_score : 0;
	$discovered     = (string) $row->discovered_at;
	$discovered_ymd = $discovered ? substr( $discovered, 0, 10 ) : '';
	$post           = $is_post_src ? get_post( $post_id ) : null;
	$post_age_days  = $post ? ( time() - (int) get_post_time( 'U', true, $post ) ) / DAY_IN_SECONDS : 9999;
	$is_front       = $is_post_src && (int) get_option( 'page_on_front' ) === $post_id;
	$is_regression  = ! empty( $row->is_regression );
	$error_type     = $this->scanner->classify_error_type( $status_code, (string) $row->error_message );
	$severity       = 'low';

	$state_suffix    = $this->scanner->state_key_suffix( $source_type, $post_id );
	$last_soft       = get_option( 'nlh_last_soft_' . (string) $row->url_hash . '_' . $state_suffix, false );
	$last_soft_label = $last_soft ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_soft ) : '';

	if ( $status_code >= 500 ) {
		$severity = 'critical';
	} elseif ( $status_code >= 400 ) {
		$severity = 410 === $status_code ? 'low' : 'medium';

		if ( 404 === $status_code && $is_front ) {
			$severity = 'critical';
		} elseif ( $post_age_days < 90 && 410 !== $status_code ) {
			$severity = 'high';
		}
	}

	if ( $impact_score >= 85 ) {
		$impact_class = 'critical';
	} elseif ( $impact_score >= 60 ) {
		$impact_class = 'high';
	} elseif ( $impact_score >= 25 ) {
		$impact_class = 'medium';
	} else {
		$impact_class = 'low';
	}
	?>
	<div
		class="nlh-link-card nlh-link-row nlh-severity-<?php echo esc_attr( $severity ); ?>"
		data-record-id="<?php echo esc_attr( (string) $record_id ); ?>"
		data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
		data-url="<?php echo esc_attr( $raw_url ); ?>"
		data-url-hash="<?php echo esc_attr( (string) $row->url_hash ); ?>"
		data-post-title="<?php echo esc_attr( $post_title ); ?>"
		data-error-type="<?php echo esc_attr( $error_type ); ?>"
		data-source="<?php echo esc_attr( $source_type ); ?>"
		data-discovered="<?php echo esc_attr( $discovered_ymd ); ?>"
		data-regression="<?php echo esc_attr( $is_regression ? '1' : '0' ); ?>"
	>
		<div class="nlh-card-body">
			<div class="nlh-card-main">
				<div class="nlh-card-post">
					<?php if ( $edit_post_url ) : ?>
						<a href="<?php echo esc_url( $edit_post_url ); ?>"><?php echo esc_html( $post_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $post_title ); ?>
					<?php endif; ?>
				</div>
				<a href="<?php echo esc_url( $raw_url ); ?>" class="nlh-url-link nlh-card-url" title="<?php echo esc_attr( $raw_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( $display_url ); ?>
				</a>
				<div class="nlh-card-meta">
					<?php
					if ( $source_label ) :
						$source_tooltips = array(
							'comment' => __( 'This broken link was found inside a comment, not in post content.', 'native-link-health' ),
							'menu'    => __( 'This broken link was found in a navigation menu item.', 'native-link-health' ),
						);
						$source_tip      = isset( $source_tooltips[ $source_type ] ) ? $source_tooltips[ $source_type ] : '';
						?>
						<span class="nlh-source-badge nlh-source-<?php echo esc_attr( $source_type ); ?>"<?php echo $source_tip ? ' title="' . esc_attr( $source_tip ) . '"' : ''; ?>><?php echo esc_html( $source_label ); ?></span>
					<?php endif; ?>
					<?php echo wp_kses_post( $this->get_status_badge( $status_code, $error_type ) ); ?>
					<?php if ( $last_soft_label ) : ?>
						<span class="nlh-status-badge nlh-status-unverified" title="<?php esc_attr_e( 'The most recent automatic check could not get a clear answer (rate limited or bot-blocked). The status shown reflects that last check, not a confirmed broken/working state.', 'native-link-health' ); ?>">
							<?php
							printf(
								/* translators: %s: last unverified-check datetime. */
								esc_html__( 'Unverified since %s', 'native-link-health' ),
								esc_html( $last_soft_label )
							);
							?>
						</span>
					<?php endif; ?>
					<?php
					$impact_tips = array(
						'critical' => __( 'Critical impact (≥85): fixing this link should be your top priority — it affects high-traffic or high-authority pages.', 'native-link-health' ),
						'high'     => __( 'High impact (60–84): this broken link noticeably affects your site\'s authority or user experience.', 'native-link-health' ),
						'medium'   => __( 'Medium impact (25–59): worth fixing, but lower priority than high-impact links.', 'native-link-health' ),
						'low'      => __( 'Low impact (<25): minor broken link with little effect on authority or traffic.', 'native-link-health' ),
					);
					$impact_tip  = $impact_tips[ $impact_class ] ?? '';
					?>
					<span class="nlh-impact-pill nlh-impact-<?php echo esc_attr( $impact_class ); ?>"<?php echo $impact_tip ? ' title="' . esc_attr( $impact_tip ) . '"' : ''; ?>>
						<?php
						printf(
							/* translators: %d: impact score. */
							esc_html__( 'Impact %d', 'native-link-health' ),
							(int) $impact_score
						);
						?>
					</span>
					<?php if ( $is_regression ) : ?>
						<span class="nlh-regression-badge" title="<?php esc_attr_e( 'Regression: this link was working before but recently broke — it may indicate a site migration, domain change, or content removal.', 'native-link-health' ); ?>"><?php esc_html_e( 'Regression', 'native-link-health' ); ?></span>
					<?php endif; ?>
					<span class="nlh-error-cell"><?php echo esc_html( (string) $row->error_message ); ?></span>
					<span>
						<?php
						printf(
							/* translators: %s: discovered datetime. */
							esc_html__( 'Found %s', 'native-link-health' ),
							esc_html( $this->format_mysql_datetime( $row->discovered_at, '-' ) )
						);
						?>
					</span>
					<span>
						<?php
						printf(
							/* translators: %s: last-checked datetime. */
							esc_html__( 'Checked %s', 'native-link-health' ),
							esc_html( $this->format_mysql_datetime( $row->last_checked_at, '-' ) )
						);
						?>
					</span>
				</div>
			</div>
			<div class="nlh-card-actions">
				<?php if ( $is_post_src ) : ?>
					<a href="#" class="button button-small nlh-edit-toggle"><?php esc_html_e( 'Edit URL', 'native-link-health' ); ?></a>
					<a href="#" class="button button-small nlh-recheck-url"><?php esc_html_e( 'Re-check', 'native-link-health' ); ?></a>
				<?php endif; ?>
				<a href="#" class="button button-small nlh-ignore-url"><?php esc_html_e( 'Ignore', 'native-link-health' ); ?></a>
				<button type="button" class="button button-small nlh-toggle-timeline"><?php esc_html_e( 'History', 'native-link-health' ); ?></button>
			</div>
		</div>
		<?php if ( $is_post_src ) : ?>
		<div class="nlh-inline-edit-row" hidden>
			<form class="nlh-inline-edit-form">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
				<input type="hidden" name="record_id" value="<?php echo esc_attr( (string) $record_id ); ?>">
				<input type="hidden" name="old_url" value="<?php echo esc_attr( $raw_url ); ?>">
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'New URL', 'native-link-health' ); ?></span>
					<input type="url" name="new_url" value="<?php echo esc_attr( $raw_url ); ?>" class="regular-text" required>
				</label>
				<button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Save & Correct', 'native-link-health' ); ?></button>
				<button type="button" class="button button-small nlh-cancel-edit"><?php esc_html_e( 'Cancel', 'native-link-health' ); ?></button>
			</form>
		</div>
		<?php endif; ?>
		<div class="nlh-timeline-wrapper" hidden>
			<div class="nlh-timeline" data-url-hash="<?php echo esc_attr( (string) $row->url_hash ); ?>" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"></div>
		</div>
	</div>
	<?php
};
?>

<div class="wrap nlh-wrap">
	<div class="nlh-page-header">
		<h1><?php esc_html_e( 'Native Link Health', 'native-link-health' ); ?></h1>
		<form id="nlh-run-now-form" class="nlh-run-now-form" method="post">
			<input type="hidden" name="action" value="nlh_run_now">
			<?php wp_nonce_field( 'nlh_run_now_action', 'nlh_run_now_nonce' ); ?>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Scan mode', 'native-link-health' ); ?></span>
				<select name="scan_mode">
					<option value="quick"><?php esc_html_e( 'Quick Scan', 'native-link-health' ); ?></option>
					<option value="full"><?php esc_html_e( 'Full Scan', 'native-link-health' ); ?></option>
				</select>
			</label>
			<button type="submit" class="button button-primary" id="nlh-run-now">
				<?php esc_html_e( 'Run Scan Now', 'native-link-health' ); ?>
			</button>
		</form>
	</div>

	<?php $this->render_health_overview(); ?>

	<?php $this->render_metrics_panel(); ?>

	<div class="nlh-scan-progress" hidden>
		<div class="nlh-progress-bar"><span class="nlh-progress-bar-fill" style="width: 0%;"></span></div>
		<span class="nlh-progress-text"></span>
	</div>

	<div id="nlh-admin-notice" class="notice nlh-notice" hidden></div>

	<p class="nlh-status-line">
		<?php
		printf(
			/* translators: 1: last scan datetime, 2: next scan datetime, 3: posts scanned count. */
			esc_html__( 'Last scan cycle: %1$s | Next scheduled: %2$s | Posts scanned: %3$d', 'native-link-health' ),
			esc_html( $last_scan ),
			esc_html( $next_scan ),
			absint( $posts_scanned )
		);
		?>
	</p>

	<?php if ( $posts_scanned < $total_posts ) : ?>
	<div class="nlh-scan-incomplete-notice" role="status">
		<span class="dashicons dashicons-clock" aria-hidden="true"></span>
		<div>
			<strong><?php esc_html_e( 'Scan in progress — results may be incomplete', 'native-link-health' ); ?></strong>
			<span>
			<?php
			if ( 0 === $posts_scanned ) {
				esc_html_e( 'No posts have been scanned yet. The background scanner will start automatically, or click "Run Scan Now" for an immediate check.', 'native-link-health' );
			} else {
				printf(
					/* translators: 1: posts scanned so far, 2: total posts to scan. */
					esc_html__( '%1$d of %2$d posts scanned so far. The scanner processes a few posts at a time to keep your site fast — results will fill in as it runs.', 'native-link-health' ),
					absint( $posts_scanned ),
					absint( $total_posts )
				);
			}
			?>
			</span>
		</div>
	</div>
	<?php endif; ?>

	<p class="nlh-gentle-note">
		<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
		<?php esc_html_e( 'Scanning is gentle by design: a few links at a time, 100% on your own server. No cloud, no accounts, and it never spikes your site. Editing a post? Use “Scan Now” in the editor sidebar for an instant check.', 'native-link-health' ); ?>
	</p>

	<div class="nlh-filter-bar" id="nlh-broken-links">
		<div class="nlh-filter-group">
			<label>
				<span><?php esc_html_e( 'Error Type', 'native-link-health' ); ?></span>
				<select id="nlh-filter-error-type">
					<option value="all"><?php esc_html_e( 'All', 'native-link-health' ); ?></option>
					<option value="4xx"><?php esc_html_e( '4xx', 'native-link-health' ); ?></option>
					<option value="5xx"><?php esc_html_e( '5xx', 'native-link-health' ); ?></option>
					<option value="fragment"><?php esc_html_e( 'Missing anchor', 'native-link-health' ); ?></option>
					<option value="dns"><?php esc_html_e( 'DNS', 'native-link-health' ); ?></option>
					<option value="ssl"><?php esc_html_e( 'SSL', 'native-link-health' ); ?></option>
					<option value="timeout"><?php esc_html_e( 'Timeout / connection', 'native-link-health' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Search', 'native-link-health' ); ?></span>
				<input type="search" id="nlh-filter-search" placeholder="<?php esc_attr_e( 'URL or post title', 'native-link-health' ); ?>">
			</label>
		</div>
		<span class="nlh-filter-divider" role="separator"></span>
		<div class="nlh-filter-group">
			<label>
				<span><?php esc_html_e( 'Group By', 'native-link-health' ); ?></span>
				<select id="nlh-group-by" name="nlh_group_by">
					<option value="none" <?php selected( $group_by, 'none' ); ?>><?php esc_html_e( 'No grouping', 'native-link-health' ); ?></option>
					<option value="domain" <?php selected( $group_by, 'domain' ); ?>><?php esc_html_e( 'Group by domain', 'native-link-health' ); ?></option>
					<option value="error_type" <?php selected( $group_by, 'error_type' ); ?>><?php esc_html_e( 'Group by error type', 'native-link-health' ); ?></option>
					<option value="post" <?php selected( $group_by, 'post' ); ?>><?php esc_html_e( 'Group by post', 'native-link-health' ); ?></option>
					<option value="chronological" <?php selected( $group_by, 'chronological' ); ?>><?php esc_html_e( 'Chronological', 'native-link-health' ); ?></option>
				</select>
			</label>
			<div class="nlh-regression-filter" role="group" aria-label="<?php esc_attr_e( 'Regression filter', 'native-link-health' ); ?>">
				<button type="button" class="button nlh-regression-filter-btn <?php echo 'all' === $filter ? 'current' : ''; ?>" data-regression-filter="all" aria-pressed="<?php echo 'all' === $filter ? 'true' : 'false'; ?>"><?php esc_html_e( 'All', 'native-link-health' ); ?></button>
				<button type="button" class="button nlh-regression-filter-btn <?php echo 'new' === $filter ? 'current' : ''; ?>" data-regression-filter="new" aria-pressed="<?php echo 'new' === $filter ? 'true' : 'false'; ?>"><?php esc_html_e( 'New', 'native-link-health' ); ?></button>
				<button type="button" class="button nlh-regression-filter-btn <?php echo 'regression' === $filter ? 'current' : ''; ?>" data-regression-filter="regression" aria-pressed="<?php echo 'regression' === $filter ? 'true' : 'false'; ?>"><?php esc_html_e( 'Regression', 'native-link-health' ); ?></button>
			</div>
		</div>
	</div>

	<?php if ( ! empty( $suggestions ) ) : ?>
		<div class="nlh-suggestions-section">
			<h2><?php esc_html_e( 'Correction Suggestions', 'native-link-health' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Detected automatically from your broken links — approve a suggestion to fix every matching URL at once.', 'native-link-health' ); ?></p>
			<?php foreach ( $suggestions as $suggestion ) : ?>
				<div class="nlh-suggestion-card" data-pattern="<?php echo esc_attr( (string) $suggestion['pattern'] ); ?>" data-type="<?php echo esc_attr( (string) $suggestion['type'] ); ?>">
					<div>
						<strong class="nlh-suggestion-label"><?php echo esc_html( (string) $suggestion['label'] ); ?></strong>
						<span>
							<?php
							printf(
								/* translators: %d: affected URL count. */
								esc_html__( '%d affected URLs', 'native-link-health' ),
								(int) $suggestion['count']
							);
							?>
						</span>
					</div>
					<label>
						<span class="screen-reader-text"><?php esc_html_e( 'Replacement', 'native-link-health' ); ?></span>
						<input type="text" class="regular-text nlh-suggestion-replacement" placeholder="<?php esc_attr_e( 'Replacement domain or URL', 'native-link-health' ); ?>">
					</label>
					<button type="button" class="button button-primary nlh-approve-all"><?php esc_html_e( 'Approve All', 'native-link-health' ); ?></button>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	// Pro touchpoints. Each renders only when the monetization layer is enabled
	// (and the feature is not already licensed); otherwise nothing appears.
	NLH_Pro::upsell_card( 'bulk_fix' );
	NLH_Pro::upsell_card( 'reporting' );

	/**
	 * Extension point: the Pro plugin injects its bulk find-and-replace and
	 * scheduled-report tools here.
	 *
	 * @since 1.3.0
	 */
	do_action( 'nlh_dashboard_tools' );
	?>

	<?php if ( empty( $rows ) && empty( $groups ) ) : ?>
		<div class="nlh-empty-state">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<span class="nlh-empty-state-text"><?php esc_html_e( 'No broken links recorded.', 'native-link-health' ); ?></span>
		</div>
	<?php elseif ( ! empty( $groups ) ) : ?>
		<div class="nlh-groups-container">
			<?php foreach ( $groups as $index => $group ) : ?>
				<div class="nlh-group" aria-expanded="true">
					<button type="button" class="nlh-group-header nlh-group-toggle" aria-expanded="true">
						<span><?php echo esc_html( (string) $group['group_key'] ); ?></span>
						<strong>
							<?php
							printf(
								/* translators: %d: group count. */
								esc_html__( '%d links', 'native-link-health' ),
								(int) $group['count']
							);
							?>
						</strong>
					</button>
					<div class="nlh-group-items">
						<?php foreach ( (array) $group['items'] as $row ) : ?>
							<?php $render_card( $row ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="nlh-cards-container">
			<?php foreach ( $rows as $row ) : ?>
				<?php $render_card( $row ); ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $total_pages > 1 ) : ?>
		<?php
		$nlh_page_url = static fn( int $p ): string => esc_url( add_query_arg( 'paged', $p ) );

		$nlh_visible = array();
		for ( $i = 1; $i <= $total_pages; $i++ ) {
			if ( 1 === $i || $total_pages === $i || abs( $i - $paged ) <= 1 ) {
				$nlh_visible[] = $i;
			}
		}
		$nlh_visible = array_unique( $nlh_visible );
		sort( $nlh_visible );
		?>
	<nav class="nlh-pagination" aria-label="<?php esc_attr_e( 'Navegación de páginas', 'native-link-health' ); ?>">
		<span class="nlh-pagination__counter">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: current page number, 2: total pages */
					__( 'Página %1$s de %2$s', 'native-link-health' ),
					'<strong>' . absint( $paged ) . '</strong>',
					'<strong>' . absint( $total_pages ) . '</strong>'
				),
				array( 'strong' => array() )
			);
			?>
		</span>
		<div class="nlh-pagination__controls">
			<?php if ( $paged > 1 ) : ?>
				<a href="<?php echo $nlh_page_url( $paged - 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() is applied inside the $nlh_page_url closure above. ?>" class="nlh-pagination__btn nlh-pagination__btn--prev">
					<svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M8 2.5L3.5 6.5L8 10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
					<?php esc_html_e( 'Anterior', 'native-link-health' ); ?>
				</a>
			<?php else : ?>
				<span class="nlh-pagination__btn nlh-pagination__btn--prev is-disabled" aria-disabled="true">
					<svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M8 2.5L3.5 6.5L8 10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
					<?php esc_html_e( 'Anterior', 'native-link-health' ); ?>
				</span>
			<?php endif; ?>

			<?php
			$nlh_prev_p = null;
			foreach ( $nlh_visible as $nlh_p ) :
				if ( null !== $nlh_prev_p && $nlh_p - $nlh_prev_p > 1 ) :
					?>
					<span class="nlh-pagination__dots" aria-hidden="true">…</span>
					<?php
				endif;
				if ( $nlh_p === $paged ) :
					?>
					<span class="nlh-pagination__btn is-current" aria-current="page"><?php echo absint( $nlh_p ); ?></span>
					<?php
				else :
					?>
					<a href="<?php echo $nlh_page_url( $nlh_p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() is applied inside the $nlh_page_url closure above. ?>" class="nlh-pagination__btn"><?php echo absint( $nlh_p ); ?></a>
					<?php
				endif;
				$nlh_prev_p = $nlh_p;
			endforeach;
			?>

			<?php if ( $paged < $total_pages ) : ?>
				<a href="<?php echo $nlh_page_url( $paged + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() is applied inside the $nlh_page_url closure above. ?>" class="nlh-pagination__btn nlh-pagination__btn--next">
					<?php esc_html_e( 'Siguiente', 'native-link-health' ); ?>
					<svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M5 2.5L9.5 6.5L5 10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</a>
			<?php else : ?>
				<span class="nlh-pagination__btn nlh-pagination__btn--next is-disabled" aria-disabled="true">
					<?php esc_html_e( 'Siguiente', 'native-link-health' ); ?>
					<svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M5 2.5L9.5 6.5L5 10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
			<?php endif; ?>
		</div>
	</nav>
	<?php endif; ?>
</div>

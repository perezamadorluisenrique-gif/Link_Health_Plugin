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
	$post_id       = absint( $row->post_id );
	$record_id     = absint( $row->id );
	$raw_url       = (string) $row->raw_url;
	$display_url   = wp_html_excerpt( $raw_url, 72, '...' );
	$post_title    = get_the_title( $post_id );
	$post_title    = $post_title ? $post_title : __( '(no title)', 'native-link-health' );
	$edit_post_url = get_edit_post_link( $post_id, '' );
	$status_code   = (int) $row->status_code;
	$impact_score  = isset( $row->impact_score ) ? (int) $row->impact_score : 0;
	$discovered    = (string) $row->discovered_at;
	$discovered_ymd = $discovered ? substr( $discovered, 0, 10 ) : '';
	$post          = get_post( $post_id );
	$post_age_days = $post ? ( time() - (int) get_post_time( 'U', true, $post ) ) / DAY_IN_SECONDS : 9999;
	$is_front      = (int) get_option( 'page_on_front' ) === $post_id;
	$is_regression = ! empty( $row->is_regression );
	$error_type    = 'timeout';
	$severity      = 'low';

	if ( $status_code >= 500 ) {
		$error_type = '5xx';
		$severity   = 'critical';
	} elseif ( $status_code >= 400 ) {
		$error_type = '4xx';
		$severity   = 410 === $status_code ? 'low' : 'medium';

		if ( 404 === $status_code && $is_front ) {
			$severity = 'critical';
		} elseif ( $status_code < 500 && $post_age_days < 90 && 410 !== $status_code ) {
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
					<?php echo wp_kses_post( $this->get_status_badge( $status_code ) ); ?>
					<span class="nlh-impact-pill nlh-impact-<?php echo esc_attr( $impact_class ); ?>">
						<?php
						printf(
							/* translators: %d: impact score. */
							esc_html__( 'Impact %d', 'native-link-health' ),
							$impact_score
						);
						?>
					</span>
					<?php if ( $is_regression ) : ?>
						<span class="nlh-regression-badge"><?php esc_html_e( 'Regression', 'native-link-health' ); ?></span>
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
				</div>
			</div>
			<div class="nlh-card-actions">
				<a href="#" class="button button-small nlh-edit-toggle"><?php esc_html_e( 'Edit URL', 'native-link-health' ); ?></a>
				<a href="#" class="button button-small nlh-recheck-url"><?php esc_html_e( 'Re-check', 'native-link-health' ); ?></a>
				<a href="#" class="button button-small nlh-ignore-url"><?php esc_html_e( 'Ignore', 'native-link-health' ); ?></a>
				<button type="button" class="button button-small nlh-toggle-timeline"><?php esc_html_e( 'History', 'native-link-health' ); ?></button>
			</div>
		</div>
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

	<div class="nlh-filter-bar">
		<div class="nlh-filter-group">
			<label>
				<span><?php esc_html_e( 'Error Type', 'native-link-health' ); ?></span>
				<select id="nlh-filter-error-type">
					<option value="all"><?php esc_html_e( 'All', 'native-link-health' ); ?></option>
					<option value="4xx"><?php esc_html_e( '4xx', 'native-link-health' ); ?></option>
					<option value="5xx"><?php esc_html_e( '5xx', 'native-link-health' ); ?></option>
					<option value="timeout"><?php esc_html_e( 'Timeout', 'native-link-health' ); ?></option>
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
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_text' => __( '&laquo;', 'native-link-health' ),
							'next_text' => __( '&raquo;', 'native-link-health' ),
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<p class="nlh-pro-footer">
		<?php esc_html_e( 'Scanning Custom Post Types is available in Native Link Health Pro.', 'native-link-health' ); ?>
		<a href="<?php echo esc_url( NLH_UPGRADE_URL ); ?>"><?php esc_html_e( 'Upgrade to Pro', 'native-link-health' ); ?></a>
	</p>
</div>

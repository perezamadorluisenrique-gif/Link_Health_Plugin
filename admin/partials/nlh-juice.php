<?php
/**
 * Link juice (internal linking) page template.
 *
 * @package NativeLinkHealth
 *
 * @var array $rows        Score rows joined with post titles.
 * @var array $summary     Summary counts.
 * @var int   $total       Total rows for the active filter.
 * @var int   $total_pages Total pages for the active filter.
 * @var int   $paged       Current page number.
 * @var int   $per_page    Rows per page.
 * @var int   $threshold   Dilution threshold.
 * @var int   $computed_at Last recompute timestamp.
 * @var int   $front_page  Front page ID.
 * @var int   $posts_page  Posts page ID.
 * @var string $orderby    Active sort column.
 * @var string $order      Active sort direction.
 * @var string $filter     Active filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nlh_base_url = menu_page_url( 'nlh-link-juice', false );

/**
 * Builds a juice page URL with the given query overrides.
 *
 * @param array $args Query overrides.
 * @return string
 */
$nlh_juice_url = static function ( array $args ) use ( $nlh_base_url, $orderby, $order, $filter ) {
	$defaults = array(
		'page'       => 'nlh-link-juice',
		'orderby'    => $orderby,
		'order'      => strtolower( $order ),
		'nlh_filter' => $filter,
	);

	return esc_url( add_query_arg( array_merge( $defaults, $args ), $nlh_base_url ) );
};

$nlh_max_pr = 0.0;
if ( ! empty( $rows ) && is_array( $rows ) ) {
	foreach ( $rows as $nlh_row ) {
		$nlh_max_pr = max( $nlh_max_pr, (float) $nlh_row->pagerank );
	}
}

$nlh_filters = array(
	'all'      => array(
		'label' => __( 'All pages', 'native-link-health' ),
		'count' => (int) $summary['total'],
	),
	'orphan'   => array(
		'label' => __( 'Orphans', 'native-link-health' ),
		'count' => (int) $summary['orphans'],
	),
	'dead_end' => array(
		'label' => __( 'Dead ends', 'native-link-health' ),
		'count' => (int) $summary['deadEnds'],
	),
	'diluted'  => array(
		'label' => __( 'Diluted', 'native-link-health' ),
		'count' => (int) $summary['diluted'],
	),
);
?>

<div class="wrap nlh-wrap nlh-juice-wrap">
	<div class="nlh-page-header">
		<h1><?php esc_html_e( 'Link Juice', 'native-link-health' ); ?></h1>
		<div class="nlh-juice-header-actions">
			<?php if ( $computed_at ) : ?>
				<span class="nlh-juice-computed">
					<?php
					printf(
						/* translators: %s: human-readable time difference. */
						esc_html__( 'Last calculated %s ago', 'native-link-health' ),
						esc_html( human_time_diff( $computed_at, time() ) )
					);
					?>
				</span>
			<?php endif; ?>
			<button type="button" class="button button-primary" id="nlh-recompute-juice">
				<span class="dashicons dashicons-update" aria-hidden="true"></span>
				<?php esc_html_e( 'Recalculate', 'native-link-health' ); ?>
			</button>
		</div>
	</div>

	<p class="nlh-juice-intro">
		<?php esc_html_e( 'How internal links pass authority ("link juice") between your pages. Scores are a PageRank-style share of your site\'s total internal authority — calculated entirely offline from your content, with no external requests.', 'native-link-health' ); ?>
	</p>

	<div id="nlh-admin-notice" class="notice nlh-notice" hidden></div>

	<?php if ( ! empty( $is_dirty ) && (int) $summary['total'] > 0 ) : ?>
		<div class="notice notice-warning nlh-juice-stale">
			<p>
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<?php esc_html_e( 'Your content changed since the last calculation, so the scores, diagram and recommendations below may be out of date.', 'native-link-health' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="nlh-recompute-juice-stale">
					<?php esc_html_e( 'Recalculate now', 'native-link-health' ); ?>
				</button>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( 0 === (int) $summary['total'] ) : ?>

		<div class="nlh-juice-empty">
			<span class="dashicons dashicons-share" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'No link data yet', 'native-link-health' ); ?></h2>
			<p><?php esc_html_e( 'Run a full scan from the Link Health dashboard, or click Recalculate to build the internal link map now.', 'native-link-health' ); ?></p>
			<button type="button" class="button button-primary" id="nlh-recompute-juice-empty">
				<?php esc_html_e( 'Recalculate now', 'native-link-health' ); ?>
			</button>
		</div>

	<?php else : ?>

		<div class="nlh-juice-overview">
			<div class="nlh-juice-section-head">
				<div>
					<h2 class="nlh-juice-section-title">
						<span class="dashicons dashicons-networking" aria-hidden="true"></span>
						<?php esc_html_e( 'Site authority map', 'native-link-health' ); ?>
					</h2>
					<p class="nlh-juice-section-sub"><?php esc_html_e( 'See how authority flows through your pages before reviewing the numbers.', 'native-link-health' ); ?></p>
				</div>
				<button type="button" class="button button-small" id="nlh-overview-reset"><?php esc_html_e( 'Reset view', 'native-link-health' ); ?></button>
			</div>
			<div id="nlh-overview-panel" class="nlh-overview-panel">
				<div class="nlh-overview-toolbar">
					<p class="nlh-overview-hint"><?php esc_html_e( 'Bigger nodes hold more authority. Click a node to highlight its connections; drag to pan; use +/− buttons to zoom.', 'native-link-health' ); ?></p>
					<div class="nlh-view-selector" role="group" aria-label="<?php esc_attr_e( 'Graph view', 'native-link-health' ); ?>">
						<button type="button" class="button button-small is-active" data-view="force"><?php esc_html_e( 'Force Graph', 'native-link-health' ); ?></button>
						<button type="button" class="button button-small" data-view="rings"><?php esc_html_e( 'Concentric', 'native-link-health' ); ?></button>
						<button type="button" class="button button-small" data-view="scatter"><?php esc_html_e( 'Scatter', 'native-link-health' ); ?></button>
					</div>
					<div class="nlh-overview-legend" aria-label="<?php esc_attr_e( 'Map legend', 'native-link-health' ); ?>">
						<span class="nlh-legend-item"><span class="nlh-legend-dot nlh-dot-ok"></span><?php esc_html_e( 'Healthy', 'native-link-health' ); ?></span>
						<span class="nlh-legend-item"><span class="nlh-legend-dot nlh-dot-orphan"></span><?php esc_html_e( 'Orphan', 'native-link-health' ); ?></span>
						<span class="nlh-legend-item"><span class="nlh-legend-dot nlh-dot-deadend"></span><?php esc_html_e( 'Dead end', 'native-link-health' ); ?></span>
						<span class="nlh-legend-item"><span class="nlh-legend-dot nlh-dot-diluted"></span><?php esc_html_e( 'Diluted', 'native-link-health' ); ?></span>
						<span class="nlh-legend-item"><span class="nlh-legend-dot nlh-dot-broken"></span><?php esc_html_e( 'Has broken links', 'native-link-health' ); ?></span>
					</div>
				</div>
				<div id="nlh-overview-canvas" class="nlh-overview-canvas" data-loaded="0"></div>
			</div>
		</div>

		<div class="nlh-juice-data-section">
			<h2 class="nlh-juice-section-title">
				<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
				<?php esc_html_e( 'Page-level juice data', 'native-link-health' ); ?>
			</h2>
			<p class="nlh-juice-section-sub"><?php esc_html_e( 'Review which pages receive, keep or dilute internal authority.', 'native-link-health' ); ?></p>
		</div>

		<div class="nlh-metrics-grid nlh-juice-summary nlh-metrics-grid-6">

			<div class="nlh-metric-card"
				title="<?php esc_attr_e( 'Total published pages with link authority data.', 'native-link-health' ); ?>">
				<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
				<span class="nlh-metric-value"><?php echo esc_html( number_format_i18n( (int) $summary['total'] ) ); ?></span>
				<span class="nlh-metric-label"><?php esc_html_e( 'Pages analyzed', 'native-link-health' ); ?></span>
			</div>

			<?php $nlh_hs_color = $health_score >= 70 ? '#2a9d3f' : ( $health_score >= 40 ? '#dba617' : '#d63638' ); ?>
			<div class="nlh-metric-card"
				title="<?php esc_attr_e( 'Global health of your internal link authority (0-100). Higher is better.', 'native-link-health' ); ?>">
				<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
				<span class="nlh-metric-value" style="color:<?php echo esc_attr( $nlh_hs_color ); ?>">
					<?php echo esc_html( $health_score ); ?>/100
				</span>
				<span class="nlh-metric-label"><?php esc_html_e( 'Authority Health', 'native-link-health' ); ?></span>
			</div>

			<div class="nlh-metric-card nlh-juice-card-orphan"
				title="<?php esc_attr_e( 'Pages with zero inbound links from your post/page content. Navigation-menu links are ignored here, so this can differ from the SEO Audit orphan count (which also counts menus).', 'native-link-health' ); ?>">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<span class="nlh-metric-value"><?php echo esc_html( number_format_i18n( (int) $summary['orphans'] ) ); ?></span>
				<span class="nlh-metric-label"><?php esc_html_e( 'Orphans (no inbound)', 'native-link-health' ); ?></span>
			</div>

			<div class="nlh-metric-card nlh-juice-card-deadend"
				title="<?php esc_attr_e( 'Pages with inbound links but no internal outbound links. Authority stops here.', 'native-link-health' ); ?>">
				<span class="dashicons dashicons-editor-break" aria-hidden="true"></span>
				<span class="nlh-metric-value"><?php echo esc_html( number_format_i18n( (int) $summary['deadEnds'] ) ); ?></span>
				<span class="nlh-metric-label"><?php esc_html_e( 'Dead ends (no outbound)', 'native-link-health' ); ?></span>
			</div>

			<div class="nlh-metric-card nlh-juice-card-diluted"
				<?php /* translators: %d: outbound link count threshold. */ ?>
				title="<?php printf( esc_attr__( 'Pages with more than %d outbound links. Link juice spread too thin.', 'native-link-health' ), (int) $threshold ); ?>">
				<span class="dashicons dashicons-filter" aria-hidden="true"></span>
				<span class="nlh-metric-value"><?php echo esc_html( number_format_i18n( (int) $summary['diluted'] ) ); ?></span>
				<span class="nlh-metric-label">
					<?php
					printf(
						/* translators: %d: dilution link threshold. */
						esc_html__( 'Diluted (>%d links)', 'native-link-health' ),
						(int) $threshold
					);
					?>
				</span>
			</div>

			<?php $nlh_has_broken = (int) ( $summary['total_broken'] ?? 0 ) > 0; ?>
			<div class="nlh-metric-card <?php echo $nlh_has_broken ? 'nlh-card-broken' : ''; ?>"
				title="<?php esc_attr_e( 'Broken outbound links detected in the link map. These leak authority and hurt UX.', 'native-link-health' ); ?>">
				<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
				<span class="nlh-metric-value">
					<?php if ( $nlh_has_broken ) : ?>
						<?php echo esc_html( number_format_i18n( (int) $summary['total_broken'] ) ); ?>
					<?php else : ?>
						0
					<?php endif; ?>
				</span>
				<span class="nlh-metric-label">
					<?php
					if ( $nlh_has_broken ) {
						printf(
							/* translators: %d: number of affected pages. */
							esc_html__( 'Broken links (%d pages)', 'native-link-health' ),
							(int) ( $summary['pages_with_broken'] ?? 0 )
						);
					} else {
						esc_html_e( 'No broken links', 'native-link-health' );
					}
					?>
				</span>
				<?php if ( $nlh_has_broken ) : ?>
					<div class="nlh-metric-sub">
						<?php
						echo esc_html(
							sprintf(
							/* translators: 1: 4xx count, 2: 5xx count, 3: timeout count. */
								__( '%1$d 4xx · %2$d 5xx · %3$d timeouts', 'native-link-health' ),
								(int) ( $summary['broken_4xx'] ?? 0 ),
								(int) ( $summary['broken_5xx'] ?? 0 ),
								(int) ( $summary['broken_timeout'] ?? 0 )
							)
						);
						?>
					</div>
				<?php endif; ?>
			</div>

		</div>

		<ul class="subsubsub nlh-juice-filters">
			<?php
			$nlh_i = 0;
			foreach ( $nlh_filters as $nlh_key => $nlh_data ) :
				++$nlh_i;
				$nlh_current = ( $filter === $nlh_key ) ? ' class="current"' : '';
				?>
				<li>
					<a href="
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- closure returns esc_url(); $nlh_key is a hardcoded filter key.
					echo $nlh_juice_url(
						array(
							'nlh_filter' => $nlh_key,
							'paged'      => 1,
						)
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
								"<?php echo $nlh_current; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php echo esc_html( $nlh_data['label'] ); ?>
						<span class="count">(<?php echo esc_html( number_format_i18n( $nlh_data['count'] ) ); ?>)</span>
					</a>
					<?php echo ( $nlh_i < count( $nlh_filters ) ) ? ' | ' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<table class="wp-list-table widefat fixed striped nlh-juice-table">
			<thead>
				<tr>
					<th class="nlh-col-title">
						<a href="
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closure returns esc_url() output.
						echo $nlh_juice_url(
							array(
								'orderby' => 'title',
								'order'   => ( 'title' === $orderby && 'ASC' === $order ) ? 'desc' : 'asc',
							)
						);
						?>
						">
							<?php esc_html_e( 'Page', 'native-link-health' ); ?>
						</a>
					</th>
					<th class="nlh-col-pr">
						<a href="
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closure returns esc_url() output.
						echo $nlh_juice_url(
							array(
								'orderby' => 'pagerank',
								'order'   => ( 'pagerank' === $orderby && 'DESC' === $order ) ? 'asc' : 'desc',
							)
						);
						?>
						">
							<?php esc_html_e( 'Link juice', 'native-link-health' ); ?>
						</a>
					</th>
					<th class="nlh-col-num">
						<a href="
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closure returns esc_url() output.
						echo $nlh_juice_url(
							array(
								'orderby' => 'inbound',
								'order'   => ( 'inbound' === $orderby && 'DESC' === $order ) ? 'asc' : 'desc',
							)
						);
						?>
						">
							<?php esc_html_e( 'Inbound', 'native-link-health' ); ?>
						</a>
					</th>
					<th class="nlh-col-num">
						<a href="
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closure returns esc_url() output.
						echo $nlh_juice_url(
							array(
								'orderby' => 'outbound',
								'order'   => ( 'outbound' === $orderby && 'DESC' === $order ) ? 'asc' : 'desc',
							)
						);
						?>
						">
							<?php esc_html_e( 'Outbound', 'native-link-health' ); ?>
						</a>
					</th>
					<th class="nlh-col-flags"><?php esc_html_e( 'Status', 'native-link-health' ); ?></th>
					<th class="nlh-col-actions"><?php esc_html_e( 'Actions', 'native-link-health' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="6" class="nlh-juice-norows"><?php esc_html_e( 'No pages match this filter.', 'native-link-health' ); ?></td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $rows as $nlh_row ) :
						$nlh_pid       = (int) $nlh_row->post_id;
						$nlh_pr        = (float) $nlh_row->pagerank;
						$nlh_inbound   = (int) $nlh_row->inbound_internal;
						$nlh_out_int   = (int) $nlh_row->outbound_internal;
						$nlh_out_total = (int) $nlh_row->outbound_total;
						$nlh_pct       = $nlh_pr * 100;
						$nlh_bar       = $nlh_max_pr > 0 ? ( $nlh_pr / $nlh_max_pr ) * 100 : 0;
						$nlh_is_struct = in_array( $nlh_pid, array( $front_page, $posts_page ), true );

						$nlh_flags = array();
						if ( 0 === $nlh_inbound && ! $nlh_is_struct ) {
							$nlh_flags[] = array(
								'class' => 'nlh-flag-orphan',
								'label' => __( 'Orphan', 'native-link-health' ),
							);
						}
						if ( $nlh_inbound > 0 && 0 === $nlh_out_int ) {
							$nlh_flags[] = array(
								'class' => 'nlh-flag-deadend',
								'label' => __( 'Dead end', 'native-link-health' ),
							);
						}
						if ( $nlh_out_total > $threshold ) {
							$nlh_flags[] = array(
								'class' => 'nlh-flag-diluted',
								'label' => __( 'Diluted', 'native-link-health' ),
							);
						}
						?>
						<tr data-post-id="<?php echo esc_attr( (string) $nlh_pid ); ?>">
							<td class="nlh-col-title">
								<a href="<?php echo esc_url( (string) get_permalink( $nlh_pid ) ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( get_the_title( $nlh_pid ) ?: __( '(no title)', 'native-link-health' ) ); ?>
								</a>
							</td>
							<td class="nlh-col-pr">
								<div class="nlh-pr-bar" title="<?php echo esc_attr( sprintf( /* translators: %s: percentage. */ __( '%s of total internal authority', 'native-link-health' ), number_format_i18n( $nlh_pct, 2 ) . '%' ) ); ?>">
									<span class="nlh-pr-fill" style="width: <?php echo esc_attr( (string) round( $nlh_bar, 1 ) ); ?>%;"></span>
									<span class="nlh-pr-value"><?php echo esc_html( number_format_i18n( $nlh_pct, 2 ) ); ?>%</span>
								</div>
							</td>
							<td class="nlh-col-num"><?php echo esc_html( number_format_i18n( $nlh_inbound ) ); ?></td>
							<td class="nlh-col-num">
								<?php echo esc_html( number_format_i18n( $nlh_out_total ) ); ?>
								<?php if ( $nlh_out_int !== $nlh_out_total ) : ?>
									<span class="nlh-out-internal" title="<?php esc_attr_e( 'internal links', 'native-link-health' ); ?>">(<?php echo esc_html( number_format_i18n( $nlh_out_int ) ); ?>&nbsp;<?php esc_html_e( 'int.', 'native-link-health' ); ?>)</span>
								<?php endif; ?>
							</td>
							<td class="nlh-col-flags">
								<?php if ( empty( $nlh_flags ) ) : ?>
									<span class="nlh-flag nlh-flag-ok"><?php esc_html_e( 'Healthy', 'native-link-health' ); ?></span>
								<?php else : ?>
									<?php foreach ( $nlh_flags as $nlh_flag ) : ?>
										<span class="nlh-flag <?php echo esc_attr( $nlh_flag['class'] ); ?>"><?php echo esc_html( $nlh_flag['label'] ); ?></span>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
							<td class="nlh-col-actions">
								<button type="button" class="button button-small nlh-juice-toggle" aria-expanded="false"><?php esc_html_e( 'Manage links', 'native-link-health' ); ?></button>
								<?php $nlh_edit = get_edit_post_link( $nlh_pid, 'raw' ); ?>
								<?php if ( $nlh_edit ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $nlh_edit ); ?>"><?php esc_html_e( 'Edit', 'native-link-health' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
						<tr class="nlh-juice-details-row" hidden>
							<td colspan="6">
								<div class="nlh-juice-details" data-loaded="0"><?php esc_html_e( 'Loading...', 'native-link-health' ); ?></div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<?php
			$nlh_juice_page_url = static fn( int $p ): string => $nlh_juice_url( array( 'paged' => $p ) );

			$nlh_visible = array();
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				if ( 1 === $i || $total_pages === $i || abs( $i - $paged ) <= 1 ) {
					$nlh_visible[] = $i;
				}
			}
			$nlh_visible = array_unique( $nlh_visible );
			sort( $nlh_visible );
			?>
			<nav class="nlh-pagination" aria-label="<?php esc_attr_e( 'Page navigation', 'native-link-health' ); ?>">
				<span class="nlh-pagination__counter">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: current page number, 2: total pages */
							__( 'Page %1$s of %2$s', 'native-link-health' ),
							'<strong>' . absint( $paged ) . '</strong>',
							'<strong>' . absint( $total_pages ) . '</strong>'
						),
						array( 'strong' => array() )
					);
					?>
				</span>
				<div class="nlh-pagination__controls">
					<?php if ( $paged > 1 ) : ?>
						<a href="<?php echo $nlh_juice_page_url( $paged - 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() is applied inside the $nlh_juice_url/$nlh_juice_page_url closures above. ?>" class="nlh-pagination__btn nlh-pagination__btn--prev">
							<svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M8 2.5L3.5 6.5L8 10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<?php esc_html_e( 'Previous', 'native-link-health' ); ?>
						</a>
					<?php else : ?>
						<span class="nlh-pagination__btn nlh-pagination__btn--prev is-disabled" aria-disabled="true">
							<svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M8 2.5L3.5 6.5L8 10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<?php esc_html_e( 'Previous', 'native-link-health' ); ?>
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
							<a href="<?php echo $nlh_juice_page_url( $nlh_p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() is applied inside the $nlh_juice_url/$nlh_juice_page_url closures above. ?>" class="nlh-pagination__btn"><?php echo absint( $nlh_p ); ?></a>
							<?php
						endif;
						$nlh_prev_p = $nlh_p;
					endforeach;
					?>

					<?php if ( $paged < $total_pages ) : ?>
						<a href="<?php echo $nlh_juice_page_url( $paged + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() is applied inside the $nlh_juice_url/$nlh_juice_page_url closures above. ?>" class="nlh-pagination__btn nlh-pagination__btn--next">
							<?php esc_html_e( 'Next', 'native-link-health' ); ?>
							<svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M5 2.5L9.5 6.5L5 10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</a>
					<?php else : ?>
						<span class="nlh-pagination__btn nlh-pagination__btn--next is-disabled" aria-disabled="true">
							<?php esc_html_e( 'Next', 'native-link-health' ); ?>
							<svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true"><path d="M5 2.5L9.5 6.5L5 10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</span>
					<?php endif; ?>
				</div>
			</nav>
		<?php endif; ?>

		<?php if ( ! empty( $recommendations ) ) : ?>
			<div class="nlh-juice-recs">
				<h2 class="nlh-juice-section-title">
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
					<?php esc_html_e( 'Recommended next steps', 'native-link-health' ); ?>
				</h2>
				<p class="nlh-juice-section-sub"><?php esc_html_e( 'Fix these pages first to improve how authority flows through your site.', 'native-link-health' ); ?></p>
				<div class="nlh-recs-list">
					<?php
					$nlh_rec_icons = array(
						'hoarder' => 'dashicons-lock',
						'orphan'  => 'dashicons-warning',
						'diluted' => 'dashicons-filter',
					);
					foreach ( $recommendations as $nlh_rec ) :
						$nlh_icon = $nlh_rec_icons[ $nlh_rec['type'] ] ?? 'dashicons-info';
						?>
						<div class="nlh-rec-card nlh-rec-<?php echo esc_attr( $nlh_rec['severity'] ); ?>">
							<span class="nlh-rec-icon dashicons <?php echo esc_attr( $nlh_icon ); ?>" aria-hidden="true"></span>
							<div class="nlh-rec-body">
								<div class="nlh-rec-head">
									<span class="nlh-rec-title"><?php echo esc_html( $nlh_rec['title'] ); ?></span>
									<a class="nlh-rec-page" href="<?php echo esc_url( (string) $nlh_rec['post']['permalink'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $nlh_rec['post']['title'] ?: __( '(no title)', 'native-link-health' ) ); ?></a>
								</div>
								<p class="nlh-rec-msg"><?php echo esc_html( $nlh_rec['message'] ); ?></p>
								<?php if ( ! empty( $nlh_rec['suggestions'] ) ) : ?>
									<div class="nlh-rec-suggest">
										<span class="nlh-rec-suggest-label">
											<?php echo 'link_from' === $nlh_rec['action'] ? esc_html__( 'Link from:', 'native-link-health' ) : esc_html__( 'Link to:', 'native-link-health' ); ?>
										</span>
										<?php foreach ( $nlh_rec['suggestions'] as $nlh_sug ) : ?>
											<a class="nlh-rec-chip<?php echo ! empty( $nlh_sug['related'] ) ? ' is-related' : ''; ?>" href="<?php echo esc_url( (string) ( $nlh_sug['edit'] ?: $nlh_sug['permalink'] ) ); ?>" title="<?php echo ! empty( $nlh_sug['related'] ) ? esc_attr__( 'Topically related', 'native-link-health' ) : esc_attr__( 'High-authority page', 'native-link-health' ); ?>">
												<?php echo esc_html( $nlh_sug['title'] ?: __( '(no title)', 'native-link-health' ) ); ?>
											</a>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<div class="nlh-rec-actions">
									<?php if ( 'review' === $nlh_rec['action'] ) : ?>
										<button type="button" class="button button-small nlh-rec-jump" data-post-id="<?php echo esc_attr( (string) $nlh_rec['post']['id'] ); ?>"><?php esc_html_e( 'Manage links', 'native-link-health' ); ?></button>
									<?php endif; ?>
									<?php if ( $nlh_rec['post']['edit'] ) : ?>
										<a class="button button-small" href="<?php echo esc_url( (string) $nlh_rec['post']['edit'] ); ?>"><?php esc_html_e( 'Edit this page', 'native-link-health' ); ?></a>
									<?php endif; ?>
									<?php
									/**
									 * Extension point: the Pro plugin adds its one-click
									 * "insert this link" button per recommendation here.
									 * Core only deep-links to the editor (advisory).
									 *
									 * @since 1.3.0
									 * @param array $nlh_rec The recommendation data.
									 */
									do_action( 'nlh_recommendation_actions', $nlh_rec );
									?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php NLH_Pro::upsell_card( 'link_insertion' ); ?>

	<?php endif; ?>
</div>

<?php
/**
 * Admin UI, settings, assets, and AJAX handlers.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles wp-admin integration.
 */
class NLH_Admin {
	/**
	 * Scanner dependency.
	 *
	 * @var NLH_Scanner
	 */
	private NLH_Scanner $scanner;

	/**
	 * Constructor.
	 *
	 * @param NLH_Scanner $scanner Scanner instance.
	 */
	public function __construct( NLH_Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_nlh_correct_url', array( $this, 'ajax_correct_url' ) );
		add_action( 'wp_ajax_nlh_recheck_url', array( $this, 'ajax_recheck_url' ) );
		add_action( 'wp_ajax_nlh_ignore_url', array( $this, 'ajax_ignore_url' ) );
		add_action( 'wp_ajax_nlh_run_now', array( $this, 'ajax_run_now' ) );
		add_action( 'wp_ajax_nlh_bulk_correct', array( $this, 'ajax_bulk_correct' ) );
		add_action( 'wp_ajax_nlh_run_seo_audit', array( $this, 'ajax_run_seo_audit' ) );
		add_action( 'wp_ajax_nlh_get_timeline', array( $this, 'ajax_get_timeline' ) );
		add_action( 'wp_ajax_nlh_recompute_juice', array( $this, 'ajax_recompute_juice' ) );
		add_action( 'wp_ajax_nlh_juice_details', array( $this, 'ajax_juice_details' ) );
		add_action( 'wp_ajax_nlh_juice_graph', array( $this, 'ajax_juice_graph' ) );
		add_action( 'wp_ajax_nlh_juice_relink', array( $this, 'ajax_juice_relink' ) );
		add_action( 'wp_ajax_nlh_juice_broken_details', array( $this, 'ajax_juice_broken_details' ) );
		add_action( 'admin_post_nlh_export_csv', array( $this, 'handle_export_csv' ) );
		add_filter( 'plugin_action_links_' . NLH_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'wp_ajax_nlh_scan_post', array( $this, 'ajax_scan_post' ) );
		add_action( 'admin_notices', array( $this, 'show_welcome_notice' ) );
		add_action( 'wp_ajax_nlh_dismiss_welcome', array( $this, 'ajax_dismiss_welcome' ) );
		add_action( 'in_admin_header', array( $this, 'hide_third_party_notices' ), 1000 );
	}

	/**
	 * Whether the current request renders one of the plugin's admin screens.
	 *
	 * @return bool
	 */
	private function is_nlh_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		/**
		 * Filters the admin page slugs treated as plugin screens (and thus
		 * shielded from third-party admin notices). NLH Pro appends its own
		 * screens here.
		 *
		 * @since 1.5.2
		 * @param string[] $screens Page slugs from $_GET['page'].
		 */
		$screens = apply_filters(
			'nlh_shielded_screens',
			array( 'nlh-dashboard', 'nlh-settings', 'nlh-seo-audit', 'nlh-link-juice' )
		);

		return in_array( $page, $screens, true );
	}

	/**
	 * Removes third-party admin notices from the plugin's own screens.
	 *
	 * Other plugins' promo/nag notices visually pollute the NLH pages (and
	 * their inline JS can throw console errors there), so on NLH screens
	 * every admin-notice callback that does not belong to NLH/NLH Pro is
	 * unhooked. Runs on in_admin_header — after all plugins have registered
	 * their notices, right before admin-header.php fires the notice hooks.
	 * WP core settings errors are unaffected (options-head.php is included
	 * directly by admin-header.php, not via these hooks).
	 *
	 * @return void
	 */
	public function hide_third_party_notices(): void {
		if ( ! $this->is_nlh_screen() ) {
			return;
		}

		global $wp_filter;

		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					if ( ! $this->is_native_notice_callback( $callback['function'] ) ) {
						unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] );
					}
				}
			}
		}
	}

	/**
	 * Whether an admin-notice callback belongs to NLH, NLH Pro, or WP core's
	 * settings-errors renderer (the only outsider worth keeping).
	 *
	 * @param callable|string|array $callback The hooked callback.
	 * @return bool
	 */
	private function is_native_notice_callback( $callback ): bool {
		if ( is_string( $callback ) ) {
			return str_starts_with( $callback, 'nlh' ) || 'settings_errors' === $callback;
		}

		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

			return str_starts_with( $class, 'NLH' );
		}

		if ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
			$file       = wp_normalize_path( (string) $reflection->getFileName() );

			return false !== strpos( $file, '/plugins/native-link-health' );
		}

		return false;
	}

	/**
	 * Registers dashboard, SEO audit, and settings pages.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		add_management_page(
			__( 'Link Health', 'native-link-health' ),
			__( 'Link Health', 'native-link-health' ),
			'manage_options',
			'nlh-dashboard',
			array( $this, 'render_dashboard_page' )
		);

		add_management_page(
			__( 'SEO Audit', 'native-link-health' ),
			__( 'SEO Audit', 'native-link-health' ),
			'manage_options',
			'nlh-seo-audit',
			array( $this, 'render_seo_page' )
		);

		add_management_page(
			__( 'Link Juice', 'native-link-health' ),
			__( 'Link Juice', 'native-link-health' ),
			'manage_options',
			'nlh-link-juice',
			array( $this, 'render_juice_page' )
		);

		add_options_page(
			__( 'Link Health', 'native-link-health' ),
			__( 'Link Health', 'native-link-health' ),
			'manage_options',
			'nlh-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers Settings API sections and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'nlh_settings',
			'nlh_scan_batch_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_batch_size' ),
				'default'           => NLH_BATCH_SIZE,
			)
		);

		register_setting(
			'nlh_settings',
			'nlh_auto_rules',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			'nlh_settings',
			'nlh_scan_scope',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_scan_scope' ),
				'default'           => array(
					'post_types' => array(),
					'comments'   => false,
					'menus'      => false,
				),
			)
		);

		add_settings_section(
			'nlh_scan_settings',
			__( 'Scan Settings', 'native-link-health' ),
			array( $this, 'render_settings_section' ),
			'nlh-settings'
		);

		add_settings_field(
			'nlh_scan_scope',
			__( 'Scan Scope', 'native-link-health' ),
			array( $this, 'render_scan_scope_field' ),
			'nlh-settings',
			'nlh_scan_settings'
		);

		add_settings_field(
			'nlh_scan_batch_size',
			__( 'Batch Size', 'native-link-health' ),
			array( $this, 'render_batch_size_field' ),
			'nlh-settings',
			'nlh_scan_settings'
		);

		add_settings_section(
			'nlh_automation_settings',
			__( 'Automation', 'native-link-health' ),
			'__return_false',
			'nlh-settings'
		);

		add_settings_field(
			'nlh_auto_rules',
			__( 'Auto-fix Rules', 'native-link-health' ),
			array( $this, 'render_auto_rules_field' ),
			'nlh-settings',
			'nlh_automation_settings'
		);

		// NOTE: the Scan Frequency, Ignored Domains and Email Notifications
		// fields were unregistered in 1.2.0. They had no backend and rendered as
		// dead "Available in Pro" inputs. Their render_* methods are kept
		// (unregistered) as starting points for the email/scheduling phases.
	}

	/**
	 * Enqueues admin assets only on plugin pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			$this->maybe_enqueue_meta_box_script();
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $page, array( 'nlh-dashboard', 'nlh-settings', 'nlh-seo-audit', 'nlh-link-juice' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'nlh-admin',
			NLH_PLUGIN_URL . 'admin/css/nlh-admin.css',
			array(),
			filemtime( NLH_PLUGIN_DIR . 'admin/css/nlh-admin.css' )
		);

		if ( 'nlh-link-juice' === $page ) {
			wp_enqueue_script(
				'nlh-juice',
				NLH_PLUGIN_URL . 'admin/js/nlh-juice.js',
				array(),
				filemtime( NLH_PLUGIN_DIR . 'admin/js/nlh-juice.js' ),
				true
			);

			wp_localize_script(
				'nlh-juice',
				'nlh_juice',
				array(
					'url'                 => admin_url( 'admin-ajax.php' ),
					'nonce'               => wp_create_nonce( 'nlh_ajax_nonce' ),
					'brokenDetailsAction' => 'nlh_juice_broken_details',
					'i18n'                => array(
						'recomputing'   => __( 'Recalculating link juice...', 'native-link-health' ),
						'recomputeDone' => __( 'Link juice recalculated.', 'native-link-health' ),
						'loading'       => __( 'Loading...', 'native-link-health' ),
						'relinking'     => __( 'Updating link...', 'native-link-health' ),
						'relinked'      => __( 'Link updated.', 'native-link-health' ),
						'error'         => __( 'Request failed.', 'native-link-health' ),
						'confirmRelink' => __( 'Re-point this link to the new URL?', 'native-link-health' ),
						'noInbound'     => __( 'No internal pages link here yet.', 'native-link-health' ),
						'noOutbound'    => __( 'This page has no outgoing links.', 'native-link-health' ),
						'inboundTitle'  => __( 'Linked from', 'native-link-health' ),
						'outboundTitle' => __( 'Links to', 'native-link-health' ),
						'newUrl'        => __( 'New URL', 'native-link-health' ),
						'repoint'       => __( 'Re-point', 'native-link-health' ),
						'edit'          => __( 'Edit post', 'native-link-health' ),
						'external'      => __( 'external', 'native-link-health' ),
						'tabFlow'       => __( 'Flow', 'native-link-health' ),
						'tabLinks'      => __( 'Manage links', 'native-link-health' ),
						'flowIn'        => __( 'Receives juice from', 'native-link-health' ),
						'flowOut'       => __( 'Passes juice to', 'native-link-health' ),
						'flowNoIn'      => __( 'Nothing links here', 'native-link-health' ),
						'flowNoOut'     => __( 'Links to nothing', 'native-link-health' ),
						/* translators: %d: count of additional items not shown. */
						'andMore'       => __( '+%d more', 'native-link-health' ),
						'showOverview'  => __( 'Show site overview', 'native-link-health' ),
						'hideOverview'  => __( 'Hide site overview', 'native-link-health' ),
						'overviewHint'  => __( 'Bigger, brighter nodes hold more authority. Click a page to focus on what links to and from it.', 'native-link-health' ),
						/* translators: 1: number of pages shown, 2: total page count. */
						'overviewCap'   => __( 'Showing the top %1$d of %2$d pages by authority.', 'native-link-health' ),
						'legendOrphan'  => __( 'Orphan', 'native-link-health' ),
						'legendDead'    => __( 'Dead end', 'native-link-health' ),
						'legendDiluted' => __( 'Diluted', 'native-link-health' ),
						'legendOk'      => __( 'Healthy', 'native-link-health' ),
						'legendBroken'  => __( 'Has broken links', 'native-link-health' ),
						'resetView'     => __( 'Reset view', 'native-link-health' ),
						'brokenLinks'   => __( 'Broken links', 'native-link-health' ),
						'noBroken'      => __( 'No broken links found.', 'native-link-health' ),
						'zoomIn'        => __( 'Zoom in', 'native-link-health' ),
						'zoomOut'       => __( 'Zoom out', 'native-link-health' ),
						'ringHighest'   => __( 'Top authority', 'native-link-health' ),
						'ringHigh'      => __( 'High authority', 'native-link-health' ),
						'ringMid'       => __( 'Medium authority', 'native-link-health' ),
						'ringLow'       => __( 'Lower authority', 'native-link-health' ),
						'scatterTitle'  => __( 'Bubble size = authority (link juice) · Click a bubble to see its connections', 'native-link-health' ),
						'scatterX'      => __( 'Inbound links (links this page receives)', 'native-link-health' ),
						'scatterY'      => __( 'Outbound links (links this page sends)', 'native-link-health' ),
					),
				)
			);

			return;
		}

		wp_enqueue_script(
			'nlh-admin',
			NLH_PLUGIN_URL . 'admin/js/nlh-admin.js',
			array(),
			filemtime( NLH_PLUGIN_DIR . 'admin/js/nlh-admin.js' ),
			true
		);

		wp_localize_script(
			'nlh-admin',
			'nlh_ajax',
			array(
				'url'         => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'nlh_ajax_nonce' ),
				'runNowNonce' => wp_create_nonce( 'nlh_run_now_action' ),
				'serverToday' => wp_date( 'Y-m-d' ),
				'i18n'        => array(
					'working'              => __( 'Working...', 'native-link-health' ),
					'scanQueued'           => __( 'Scan queued.', 'native-link-health' ),
					'confirmIgnore'        => __( 'Ignore this URL permanently?', 'native-link-health' ),
					'error'                => __( 'Request failed.', 'native-link-health' ),
					'unknown'              => __( 'Unknown', 'native-link-health' ),
					'transportBadges'      => $this->get_transport_badge_labels(),
					'transportTooltips'    => $this->get_transport_badge_tooltips(),
					'showHistory'          => __( 'History', 'native-link-health' ),
					'hideHistory'          => __( 'Hide History', 'native-link-health' ),
					'noHistory'            => __( 'No history recorded.', 'native-link-health' ),
					'seoRunning'           => __( 'Running audit...', 'native-link-health' ),
					'auditComplete'        => __( 'SEO audit complete.', 'native-link-health' ),
					/* translators: 1: scanned post count, 2: total post count. */
					'progress'             => __( 'Scanned %1$d of %2$d posts.', 'native-link-health' ),
					/* translators: %s: human-readable duration, e.g. "2m 30s". */
					'eta'                  => __( 'About %s remaining.', 'native-link-health' ),
					'eventBroken'          => __( 'Broken', 'native-link-health' ),
					'eventFixed'           => __( 'Fixed', 'native-link-health' ),
					'eventRegression'      => __( 'Regression', 'native-link-health' ),
					'eventIgnored'         => __( 'Ignored', 'native-link-health' ),
					'seoOrphanPages'       => __( 'Orphan pages', 'native-link-health' ),
					'seoRedirectChains'    => __( 'Redirect chains', 'native-link-health' ),
					'seoMixedContent'      => __( 'Mixed content', 'native-link-health' ),
					'seoInvalidCanonicals' => __( 'Invalid canonicals', 'native-link-health' ),
					'seoRedundantLinks'    => __( 'Redundant links', 'native-link-health' ),
					'seoMissingAltText'    => __( 'Images missing alt text', 'native-link-health' ),
					'seoImageDimensionMismatch' => __( 'Image dimension mismatches', 'native-link-health' ),
					'seoLegacyImageFormat' => __( 'Legacy image formats', 'native-link-health' ),
					'seoTitleLength'       => __( 'Title length', 'native-link-health' ),
					'seoMetaDescription'   => __( 'Meta description length', 'native-link-health' ),
					'seoHeadingHierarchy'  => __( 'Heading hierarchy', 'native-link-health' ),
					'seoKeywordDensity'    => __( 'Keyword density', 'native-link-health' ),
					'chronoToday'          => __( 'Today', 'native-link-health' ),
					'chronoYesterday'      => __( 'Yesterday', 'native-link-health' ),
					'chronoThisWeek'       => __( 'This Week', 'native-link-health' ),
					'chronoLastWeek'       => __( 'Last Week', 'native-link-health' ),
					'chronoThisMonth'      => __( 'This Month', 'native-link-health' ),
					'chronoOlder'          => __( 'Older', 'native-link-health' ),
					'chronoLink'           => __( 'link', 'native-link-health' ),
					'chronoLinks'          => __( 'links', 'native-link-health' ),
					'timeout'              => __( 'Request timed out. The scan may have too many links to check.', 'native-link-health' ),
					/* translators: 1: post ID, 2: error message. */
					'scanError'            => __( 'Scan failed at post %1$d: %2$s. You can reload and try again.', 'native-link-health' ),
					/* translators: %d: post ID. */
					'resumeScan'           => __( 'An earlier scan was interrupted at post %d. Resume scanning?', 'native-link-health' ),
				),
			)
		);
	}

	/**
	 * Renders dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'native-link-health' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing; no state change.
		$group_by = isset( $_GET['nlh_group_by'] ) ? sanitize_key( wp_unslash( $_GET['nlh_group_by'] ) ) : 'none';
		$filter   = isset( $_GET['nlh_filter'] ) ? sanitize_key( wp_unslash( $_GET['nlh_filter'] ) ) : 'all';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$data          = $this->get_dashboard_data( $group_by );
		$rows          = $data['rows'];
		$groups        = $data['groups'];
		$suggestions   = $data['suggestions'];
		$total         = $data['total'];
		$paged         = $data['paged'];
		$per_page      = $data['per_page'];
		$total_pages   = $data['total_pages'];
		$last_scan     = $data['last_scan'];
		$next_scan     = $data['next_scan'];
		$posts_scanned = $data['posts_scanned'];
		$total_posts   = $data['total_posts'];

		include NLH_PLUGIN_DIR . 'admin/partials/nlh-dashboard.php';
	}

	/**
	 * Renders SEO audit page.
	 *
	 * @return void
	 */
	public function render_seo_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'native-link-health' ) );
		}

		include NLH_PLUGIN_DIR . 'admin/partials/nlh-seo-dashboard.php';
	}

	/**
	 * Renders the link juice (internal linking) page.
	 *
	 * @return void
	 */
	public function render_juice_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'native-link-health' ) );
		}

		$graph = new NLH_Link_Graph();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing; no state change.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'pagerank';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		$filter  = isset( $_GET['nlh_filter'] ) ? sanitize_key( wp_unslash( $_GET['nlh_filter'] ) ) : 'all';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$per_page = 25;

		$report = $graph->get_report(
			array(
				'orderby'  => $orderby,
				'order'    => $order,
				'filter'   => $filter,
				'paged'    => $paged,
				'per_page' => $per_page,
			)
		);

		$summary         = $graph->get_summary();
		$health_score    = (int) ( $summary['health_score'] ?? 0 );
		$recommendations = ( new NLH_Link_Recommendations() )->get( 8 );
		$threshold       = NLH_Link_Graph::get_dilution_threshold();
		$is_dirty        = NLH_Link_Graph::is_dirty();
		$computed_at     = (int) get_option( 'nlh_juice_computed_at', 0 );
		$front_page      = (int) get_option( 'page_on_front' );
		$posts_page      = (int) get_option( 'page_for_posts' );
		$rows            = $report['rows'];
		$total           = $report['total'];
		$total_pages     = $report['total_pages'];

		include NLH_PLUGIN_DIR . 'admin/partials/nlh-juice.php';
	}

	/**
	 * Renders settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'native-link-health' ) );
		}

		include NLH_PLUGIN_DIR . 'admin/partials/nlh-settings.php';
	}

	/**
	 * Tallies broken records, splitting off those that have been unverifiable
	 * (soft/could-not-verify) for longer than the grace period.
	 *
	 * Long-unverifiable links (e.g. a site permanently behind a Cloudflare
	 * challenge) are real records but cannot be confirmed broken, so they are
	 * excluded from the headline broken count and the Health Score instead of
	 * depressing them forever. They still render in the list, flagged with the
	 * "Unverified since" badge. Nothing is auto-deleted.
	 *
	 * @return array{total:int,confirmed:int,unverifiable:int}
	 */
	private function get_broken_counts(): array {
		global $wpdb;

		$errors_table = $wpdb->prefix . 'nlh_link_errors';
		$rows         = $wpdb->get_results( "SELECT url_hash, post_id, source_type FROM {$errors_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$total        = is_array( $rows ) ? count( $rows ) : 0;
		$unverifiable = 0;
		$cutoff       = time() - $this->scanner->get_unverified_grace_period();

		foreach ( (array) $rows as $row ) {
			$source_type = isset( $row->source_type ) ? (string) $row->source_type : 'post';
			$suffix      = $this->scanner->state_key_suffix( $source_type, (int) $row->post_id );
			$soft_since  = get_option( 'nlh_last_soft_' . (string) $row->url_hash . '_' . $suffix, false );

			if ( false !== $soft_since && (int) $soft_since > 0 && (int) $soft_since < $cutoff ) {
				++$unverifiable;
			}
		}

		return array(
			'total'        => $total,
			'confirmed'    => max( 0, $total - $unverifiable ),
			'unverifiable' => $unverifiable,
		);
	}

	/**
	 * Outputs the unified "Link Health Score" hero: a single headline number that
	 * blends broken-link detection with internal-link authority, plus drill-down
	 * cards into each module (broken links, authority, SEO audit).
	 *
	 * The headline is deliberately transparent: detection (broken links) is
	 * weighted above structure (authority) because finding broken links is the
	 * product's core promise. SEO issues are surfaced as a drill-down rather than
	 * folded into the number, since their orphan check overlaps the authority one.
	 *
	 * @return void
	 */
	public function render_health_overview(): void {
		global $wpdb;

		$counts       = $this->get_broken_counts();
		$broken_total = $counts['confirmed'];

		$metrics      = get_option( 'nlh_scan_metrics', array() );
		$urls_checked = is_array( $metrics ) ? (int) ( $metrics['total_urls_checked'] ?? 0 ) : 0;

		$authority = 0;
		$orphans   = 0;
		$has_graph = class_exists( 'NLH_Link_Graph' );
		if ( $has_graph ) {
			$graph     = new NLH_Link_Graph();
			$authority = (int) $graph->calculate_health_score();
			$summary   = $graph->get_summary();
			$orphans   = (int) ( $summary['orphans'] ?? 0 );
		}

		// Broken-link health: share of all distinct URLs ever checked that are not
		// currently recorded broken. 1.0 (perfect) when nothing has been checked.
		$broken_health = $urls_checked > 0 ? max( 0.0, ( $urls_checked - $broken_total ) / $urls_checked ) : 1.0;

		$scanned_yet = ( $urls_checked > 0 ) || ( $authority > 0 );

		// Weighted blend: 60% detection, 40% authority structure. When the
		// authority graph has never been computed, the headline is detection only.
		if ( $has_graph && $authority > 0 ) {
			$combined = (int) round( ( $broken_health * 0.6 + ( $authority / 100 ) * 0.4 ) * 100 );
		} else {
			$combined = (int) round( $broken_health * 100 );
		}

		if ( $combined >= 90 ) {
			$band  = 'excellent';
			$label = __( 'Excellent', 'native-link-health' );
		} elseif ( $combined >= 70 ) {
			$band  = 'good';
			$label = __( 'Good', 'native-link-health' );
		} elseif ( $combined >= 45 ) {
			$band  = 'fair';
			$label = __( 'Needs work', 'native-link-health' );
		} else {
			$band  = 'poor';
			$label = __( 'Poor', 'native-link-health' );
		}

		$juice_url = admin_url( 'tools.php?page=nlh-link-juice' );
		$seo_url   = admin_url( 'tools.php?page=nlh-seo-audit' );
		?>
		<div class="nlh-health-hero nlh-health-band-<?php echo esc_attr( $band ); ?>">
			<div class="nlh-health-score" role="img"
				aria-label="<?php echo esc_attr( $scanned_yet ? sprintf( /* translators: %d: score 0-100. */ __( 'Link Health Score: %d out of 100.', 'native-link-health' ), $combined ) : __( 'Link Health Score not yet available.', 'native-link-health' ) ); ?>">
				<?php if ( $scanned_yet ) : ?>
					<span class="nlh-health-number"><?php echo esc_html( number_format_i18n( $combined ) ); ?></span>
					<span class="nlh-health-band-label"><?php echo esc_html( $label ); ?></span>
				<?php else : ?>
					<span class="nlh-health-number">&mdash;</span>
					<span class="nlh-health-band-label"><?php esc_html_e( 'Not scanned yet', 'native-link-health' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="nlh-health-detail">
				<h2><?php esc_html_e( 'Link Health Score', 'native-link-health' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'A single read on your site: broken-link detection combined with internal-link authority. Drill into any module below.', 'native-link-health' ); ?>
				</p>
				<div class="nlh-health-drilldowns">
					<a class="nlh-health-drill" href="#nlh-broken-links">
						<span class="dashicons dashicons-editor-unlink" aria-hidden="true"></span>
						<span class="nlh-health-drill-value"><?php echo esc_html( number_format_i18n( $broken_total ) ); ?></span>
						<span class="nlh-health-drill-label"><?php esc_html_e( 'Broken links', 'native-link-health' ); ?></span>
					</a>
					<a class="nlh-health-drill" href="<?php echo esc_url( $juice_url ); ?>">
						<span class="dashicons dashicons-share" aria-hidden="true"></span>
						<span class="nlh-health-drill-value"><?php echo $scanned_yet && $has_graph ? esc_html( number_format_i18n( $authority ) ) : '&mdash;'; ?></span>
						<span class="nlh-health-drill-label"><?php esc_html_e( 'Authority score', 'native-link-health' ); ?></span>
					</a>
					<a class="nlh-health-drill" href="<?php echo esc_url( $juice_url ); ?>">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<span class="nlh-health-drill-value"><?php echo esc_html( number_format_i18n( $orphans ) ); ?></span>
						<span class="nlh-health-drill-label"><?php esc_html_e( 'Orphan pages', 'native-link-health' ); ?></span>
					</a>
					<a class="nlh-health-drill" href="<?php echo esc_url( $seo_url ); ?>">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<span class="nlh-health-drill-value"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></span>
						<span class="nlh-health-drill-label"><?php esc_html_e( 'Run SEO audit', 'native-link-health' ); ?></span>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Outputs the scan metrics panel.
	 *
	 * @return void
	 */
	public function render_metrics_panel(): void {
		$metrics = get_option( 'nlh_scan_metrics', array() );

		if ( ! is_array( $metrics ) ) {
			$metrics = array();
		}

		$cards = array(
			array(
				'icon'  => 'dashicons-admin-links',
				'value' => number_format_i18n( (int) ( $metrics['total_urls_checked'] ?? 0 ) ),
				'label' => __( 'URLs Checked', 'native-link-health' ),
			),
			array(
				'icon'  => 'dashicons-warning',
				'value' => number_format_i18n( (int) ( $metrics['total_broken_found'] ?? 0 ) ),
				'label' => __( 'Broken Links', 'native-link-health' ),
			),
			array(
				'icon'  => 'dashicons-clock',
				'value' => number_format_i18n( (float) ( $metrics['last_batch_duration'] ?? 0 ), 2 ) . 's',
				'label' => __( 'Avg Batch Time', 'native-link-health' ),
			),
			array(
				'icon'  => 'dashicons-performance',
				'value' => size_format( (int) ( $metrics['peak_memory_usage'] ?? 0 ) ),
				'label' => __( 'Peak Memory', 'native-link-health' ),
			),
		);
		?>
		<div class="nlh-metrics-grid">
			<?php foreach ( $cards as $card ) : ?>
				<div class="nlh-metric-card">
					<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>" aria-hidden="true"></span>
					<span class="nlh-metric-value"><?php echo esc_html( $card['value'] ); ?></span>
					<span class="nlh-metric-label"><?php echo esc_html( $card['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Settings section intro.
	 *
	 * @return void
	 */
	public function render_settings_section(): void {
		printf(
			'<p>%s</p>',
			sprintf(
				/* translators: %d: configured batch size. */
				esc_html__( 'The background scanner runs every 15 minutes and processes %d posts per cycle (adjustable below), so it never spikes your server.', 'native-link-health' ),
				(int) $this->scanner->get_batch_size()
			)
		);
	}

	/**
	 * Renders the scan frequency selector.
	 *
	 * Unregistered since 1.2.0 (no backend yet). Kept as a starting point for
	 * the configurable-scheduling phase; not displayed on the settings screen.
	 *
	 * @return void
	 */
	public function render_scan_frequency_field(): void {
		?>
		<select id="nlh_scan_frequency" disabled>
			<option selected><?php esc_html_e( 'Every 15 minutes', 'native-link-health' ); ?></option>
			<option><?php esc_html_e( 'Hourly', 'native-link-health' ); ?> - <?php esc_html_e( 'Pro', 'native-link-health' ); ?></option>
			<option><?php esc_html_e( 'Daily', 'native-link-health' ); ?> - <?php esc_html_e( 'Pro', 'native-link-health' ); ?></option>
			<option><?php esc_html_e( 'Weekly', 'native-link-health' ); ?> - <?php esc_html_e( 'Pro', 'native-link-health' ); ?></option>
		</select>
		<span class="nlh-pro-badge"><?php esc_html_e( 'Available in Pro', 'native-link-health' ); ?></span>
		<p class="description"><?php esc_html_e( 'The free tier uses a fixed 15 minute cron interval.', 'native-link-health' ); ?></p>
		<?php
	}

	/**
	 * Renders the scan-scope selector: extra post types, comments and menus.
	 *
	 * @return void
	 */
	public function render_scan_scope_field(): void {
		$scope = get_option(
			'nlh_scan_scope',
			array(
				'post_types' => array(),
				'comments'   => false,
				'menus'      => false,
			)
		);
		if ( ! is_array( $scope ) ) {
			$scope = array();
		}
		$selected_types = (array) ( $scope['post_types'] ?? array() );
		$cpts           = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);
		?>
		<fieldset>
			<?php if ( ! empty( $cpts ) ) : ?>
				<legend class="screen-reader-text"><?php esc_html_e( 'Additional post types to scan', 'native-link-health' ); ?></legend>
				<p class="description" style="margin-top:0;"><?php esc_html_e( 'Posts and pages are always scanned. Add more content types below.', 'native-link-health' ); ?></p>
				<?php foreach ( $cpts as $cpt ) : ?>
					<label style="display:block;">
						<input type="checkbox" name="nlh_scan_scope[post_types][]" value="<?php echo esc_attr( $cpt->name ); ?>" <?php checked( in_array( $cpt->name, $selected_types, true ) ); ?>>
						<?php echo esc_html( $cpt->labels->name ); ?>
					</label>
				<?php endforeach; ?>
			<?php endif; ?>

			<label style="display:block;">
				<input type="checkbox" name="nlh_scan_scope[post_types][]" value="attachment" <?php checked( in_array( 'attachment', $selected_types, true ) ); ?>>
				<?php esc_html_e( 'Media (attachments)', 'native-link-health' ); ?>
			</label>

			<br>

			<label style="display:block;">
				<input type="checkbox" name="nlh_scan_scope[comments]" value="1" <?php checked( ! empty( $scope['comments'] ) ); ?>>
				<?php esc_html_e( 'Scan approved comment content for broken links', 'native-link-health' ); ?>
			</label>

			<label style="display:block;">
				<input type="checkbox" name="nlh_scan_scope[menus]" value="1" <?php checked( ! empty( $scope['menus'] ) ); ?>>
				<?php esc_html_e( 'Scan navigation menu link URLs', 'native-link-health' ); ?>
			</label>
		</fieldset>
		<?php
	}

	/**
	 * Renders the batch-size number field.
	 *
	 * @return void
	 */
	public function render_batch_size_field(): void {
		$size = $this->scanner->get_batch_size();
		?>
		<input type="number" id="nlh_scan_batch_size" name="nlh_scan_batch_size" class="small-text" min="1" max="100" step="1" value="<?php echo esc_attr( (string) $size ); ?>">
		<p class="description">
			<?php
			printf(
				/* translators: %d: default batch size constant. */
				esc_html__( 'Number of posts scanned per cron cycle (1-100). Defaults to %d.', 'native-link-health' ),
				(int) NLH_BATCH_SIZE
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the ignored domains textarea.
	 *
	 * Unregistered since 1.2.0. Redundant with the live ignore list
	 * (`nlh_ignored_urls`) managed from the dashboard; kept only until a proper
	 * wildcard domain ignore backend is built.
	 *
	 * @return void
	 */
	public function render_ignored_domains_field(): void {
		?>
		<textarea id="nlh_ignored_domains" class="large-text code" rows="6" disabled placeholder="<?php echo esc_attr__( "example.com\n*.example.org", 'native-link-health' ); ?>"></textarea>
		<span class="nlh-pro-badge"><?php esc_html_e( 'Available in Pro', 'native-link-health' ); ?></span>
		<p class="description"><?php esc_html_e( 'Domain ignore lists are reserved for Native Link Health Pro.', 'native-link-health' ); ?></p>
		<?php
	}

	/**
	 * Renders disabled auto-fix rules editor.
	 *
	 * @return void
	 */
	public function render_auto_rules_field(): void {
		$rules = get_option( 'nlh_auto_rules', array() );

		if ( is_string( $rules ) ) {
			$value = $rules;
		} elseif ( is_array( $rules ) && ! empty( $rules ) ) {
			$value = (string) wp_json_encode( $rules, JSON_PRETTY_PRINT );
		} else {
			$value = '';
		}

		// Shown only as a placeholder so an empty form is never saved as a live rule.
		$example = (string) wp_json_encode(
			array(
				array(
					'conditions' => array(
						array(
							'field'    => 'domain',
							'operator' => 'equals',
							'value'    => 'old-domain.example',
						),
					),
					'action'     => array(
						'type'  => 'replace',
						'value' => 'new-domain.example',
					),
				),
			),
			JSON_PRETTY_PRINT
		);
		?>
		<textarea id="nlh_auto_rules" name="nlh_auto_rules" class="large-text code" rows="8" placeholder="<?php echo esc_attr( $example ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Optional JSON list of auto-fix rules applied during scans. Each rule has "conditions" and an "action" (e.g. replace one domain with another). These rewrite post content automatically — leave empty to disable.', 'native-link-health' ); ?></p>
		<?php
	}

	/**
	 * Renders the email notification field.
	 *
	 * Unregistered since 1.2.0 (no backend yet). Kept as a starting point for
	 * the email-alerts/digest phase; not displayed on the settings screen.
	 *
	 * @return void
	 */
	public function render_email_notifications_field(): void {
		?>
		<input type="email" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" disabled>
		<span class="nlh-pro-badge"><?php esc_html_e( 'Available in Pro', 'native-link-health' ); ?></span>
		<p class="description"><?php esc_html_e( 'Email alerts for newly broken links are reserved for Native Link Health Pro.', 'native-link-health' ); ?></p>
		<?php
	}

	/**
	 * Sanitizes integer settings.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_absint( $value ): int {
		return absint( $value );
	}

	/**
	 * Sanitizes the scan batch-size setting.
	 *
	 * Clamped to 1-100 so a stray 0 or an absurdly large value can't stall the
	 * cron batch or make a single run scan the whole site (see get_batch_size()).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_batch_size( $value ): int {
		$size = absint( $value );

		if ( $size < 1 || $size > 100 ) {
			$size = NLH_BATCH_SIZE;
		}

		return $size;
	}

	/**
	 * Sanitizes the scan-scope option.
	 *
	 * Post types are validated against the registered public types (plus
	 * attachment); comments and menus are cast to booleans.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array
	 */
	public function sanitize_scan_scope( $value ): array {
		$defaults = array(
			'post_types' => array(),
			'comments'   => false,
			'menus'      => false,
		);

		if ( ! is_array( $value ) ) {
			return $defaults;
		}

		$allowed   = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$allowed[] = 'attachment';
		$raw_types = array_map( 'sanitize_key', (array) ( $value['post_types'] ?? array() ) );
		$types     = array_values( array_unique( array_filter( $raw_types, static fn( $t ) => in_array( $t, $allowed, true ) ) ) );

		return array(
			'post_types' => $types,
			'comments'   => ! empty( $value['comments'] ),
			'menus'      => ! empty( $value['menus'] ),
		);
	}

	/**
	 * Handles inline URL correction.
	 *
	 * @return void
	 */
	public function ajax_correct_url(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
				$record_id = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;
				$old_url   = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
				$new_url   = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';

				if ( ! $post_id || '' === $old_url || '' === $new_url ) {
						$this->clean_output_buffer();
						wp_send_json_error( array( 'message' => __( 'Missing URL data.', 'native-link-health' ) ), 400 );
				}

				if ( ! nlh_update_post_link( $post_id, $old_url, $new_url ) ) {
					$this->clean_output_buffer();
					wp_send_json_error( array( 'message' => __( 'URL was not found in post content.', 'native-link-health' ) ), 404 );
				}

				global $wpdb;
				$table = $wpdb->prefix . 'nlh_link_errors';

				$this->log_correction( $post_id, $old_url, $new_url, 'manual' );
				$this->scanner->clear_url_cache( $old_url, $post_id );
				$this->scanner->clear_url_cache( $new_url, $post_id );
				delete_post_meta( $post_id, '_nlh_last_scan' );

				if ( $record_id <= 0 ) {
					$record_id = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$table} WHERE post_id = %d AND url_hash = %s LIMIT 1",
							$post_id,
							md5( $old_url )
						)
					);
				}

				$result = $this->scanner->recheck_url( $new_url, $post_id, $record_id );

				$this->clean_output_buffer();
				wp_send_json_success(
					array_merge(
						array( 'message' => __( 'URL corrected and re-checked.', 'native-link-health' ) ),
						$result
					)
				);
			}
		);
	}

	/**
	 * Handles manual URL re-check.
	 *
	 * @return void
	 */
	public function ajax_recheck_url(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$url       = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
				$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
				$record_id = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;

				if ( '' === $url || ! $post_id || ! $record_id ) {
						$this->clean_output_buffer();
						wp_send_json_error( array( 'message' => __( 'Missing re-check data.', 'native-link-health' ) ), 400 );
				}

				$this->clean_output_buffer();
				wp_send_json_success( $this->scanner->recheck_url( $url, $post_id, $record_id ) );
			}
		);
	}

	/**
	 * Handles ignored URL action.
	 *
	 * @return void
	 */
	public function ajax_ignore_url(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$url       = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
				$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
				$record_id = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;

				if ( '' === $url || ! $record_id ) {
						$this->clean_output_buffer();
						wp_send_json_error( array( 'message' => __( 'Missing ignore data.', 'native-link-health' ) ), 400 );
				}

				$ignored = get_option( 'nlh_ignored_urls', array() );

				if ( ! is_array( $ignored ) ) {
					$ignored = array();
				}

				if ( ! in_array( $url, $ignored, true ) ) {
					$ignored[] = $url;
					update_option( 'nlh_ignored_urls', array_values( $ignored ) );
				}

				global $wpdb;
				$table    = $wpdb->prefix . 'nlh_link_errors';
				$url_hash = md5( $url );

				$existing = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table} WHERE url_hash = %s AND post_id = %d LIMIT 1",
						$url_hash,
						$post_id
					)
				);
				if ( ! $existing ) {
					$this->clean_output_buffer();
					wp_send_json_error( array( 'message' => __( 'Record not found.', 'native-link-health' ) ), 404 );
				}

				if ( $post_id > 0 ) {
						$this->scanner->record_link_event( $url_hash, $post_id, 'ignored', 0 );
				}

				$where   = array( 'url_hash' => $url_hash );
				$formats = array( '%s' );
				if ( ! empty( $_POST['post_id'] ) ) {
					$where['post_id'] = $post_id;
					$formats[]        = '%d';
				}
				$wpdb->delete( $table, $where, $formats );
				delete_transient( 'nlh_ok_' . $url_hash );

				$retry_prefix         = $wpdb->esc_like( '_transient_nlh_retry_' . $url_hash . '_' ) . '%';
				$retry_timeout_prefix = $wpdb->esc_like( '_transient_timeout_nlh_retry_' . $url_hash . '_' ) . '%';

				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
						$retry_prefix,
						$retry_timeout_prefix
					)
				);

				$this->clean_output_buffer();
				wp_send_json_success( array( 'message' => __( 'URL ignored.', 'native-link-health' ) ) );
			}
		);
	}

	/**
	 * Runs quick or chunked full scans.
	 *
	 * @return void
	 */
	public function ajax_run_now(): void {
		$this->run_ajax_safe(
			function () {
				check_ajax_referer( 'nlh_run_now_action', 'nonce' );

				if ( ! current_user_can( 'manage_options' ) ) {
						$this->clean_output_buffer();
						wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'native-link-health' ) ), 403 );
				}

				// Prevent PHP timeout during long scans with many HTTP requests.
				if ( function_exists( 'set_time_limit' ) ) {
					set_time_limit( 0 );
				}

				// Raise memory limit for large content processing.
				if ( function_exists( 'wp_raise_memory_limit' ) ) {
					wp_raise_memory_limit( 'admin' );
				}

				$mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'quick';
				$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
				$chunk_size = NLH_BATCH_SIZE;

				$result = $this->scanner->run_full_scan( $chunk_size, $offset );

				$result['message'] = sprintf(
				/* translators: 1: scanned count, 2: total count. */
					__( 'Manual scan progress: %1$d of %2$d posts.', 'native-link-health' ),
					(int) $result['scanned'],
					(int) $result['total']
				);

				if ( ! empty( $result['done'] ) ) {
					$result['message'] = sprintf(
						/* translators: %d: scanned post count. */
						__( 'Manual scan complete. Posts scanned: %d.', 'native-link-health' ),
						(int) $result['scanned']
					);
				}

				$this->clean_output_buffer();
				wp_send_json_success( $result );
			}
		);
	}

	/**
	 * Handles bulk correction suggestions.
	 *
	 * @return void
	 */
	public function ajax_bulk_correct(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$pattern     = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) : '';
				$type        = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'domain_death';
				$replacement = isset( $_POST['replacement'] ) ? esc_url_raw( wp_unslash( $_POST['replacement'] ) ) : '';

				if ( '' === $pattern ) {
						$this->clean_output_buffer();
						wp_send_json_error( array( 'message' => __( 'Missing correction pattern.', 'native-link-health' ) ), 400 );
				}

				global $wpdb;
				$table = $wpdb->prefix . 'nlh_link_errors';
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, post_id, raw_url, url_hash FROM {$table} WHERE raw_url LIKE %s",
						'%://' . $wpdb->esc_like( $pattern ) . '%'
					)
				);
				$count = 0;

				foreach ( (array) $rows as $row ) {
					$old_url = (string) $row->raw_url;
					$new_url = $this->build_bulk_replacement_url( $old_url, $pattern, $replacement, $type );

					if ( $old_url === $new_url ) {
						continue;
					}

					if ( nlh_update_post_link( (int) $row->post_id, $old_url, $new_url ) ) {
						++$count;
						$this->log_correction( (int) $row->post_id, $old_url, $new_url, 'bulk' );
						$this->scanner->clear_url_cache( $old_url, (int) $row->post_id );
						$this->scanner->clear_url_cache( $new_url, (int) $row->post_id );
						$this->scanner->delete_error_record( (int) $row->id, (int) $row->post_id, (string) $row->url_hash );
					}
				}

				delete_transient( 'nlh_suggestions' );

				$this->clean_output_buffer();
				wp_send_json_success(
					array(
						'message' => sprintf(
						/* translators: %d: corrected link count. */
							__( 'Corrected %d links.', 'native-link-health' ),
							$count
						),
						'count'   => $count,
					)
				);
			}
		);
	}

	/**
	 * Runs and caches the SEO audit.
	 *
	 * @return void
	 */
	public function ajax_run_seo_audit(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$audit   = new NLH_SEO_Audit();
				$results = array(
					'orphan_pages'             => $audit->audit_orphan_pages(),
					'redirect_chains'          => $audit->audit_redirect_chains(),
					'mixed_content'            => $audit->audit_mixed_content(),
					'invalid_canonicals'       => $audit->audit_invalid_canonicals(),
					'redundant_links'          => $audit->audit_redundant_links(),
					'missing_alt_text'         => $audit->audit_missing_alt_text(),
					'image_dimension_mismatch' => $audit->audit_image_dimension_mismatch(),
					'legacy_image_format'      => $audit->audit_legacy_image_format(),
					'title_length'             => $audit->audit_title_length(),
					'meta_description'         => $audit->audit_meta_description(),
					'heading_hierarchy'        => $audit->audit_heading_hierarchy(),
					'keyword_density'          => $audit->audit_keyword_density(),
				);

				set_transient( 'nlh_seo_audit_results', $results, DAY_IN_SECONDS );

				$this->clean_output_buffer();
				wp_send_json_success( $results );
			}
		);
	}

	/**
	 * Returns per-link timeline events.
	 *
	 * @return void
	 */
	public function ajax_get_timeline(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$url_hash = isset( $_POST['url_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['url_hash'] ) ) : '';
				$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

				if ( '' === $url_hash || ! $post_id ) {
						$this->clean_output_buffer();
						wp_send_json_error( array( 'message' => __( 'Missing timeline data.', 'native-link-health' ) ), 400 );
				}

				global $wpdb;
				$events = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT event_type, status_code, event_at FROM {$wpdb->prefix}nlh_link_events WHERE url_hash = %s AND post_id = %d ORDER BY event_at ASC",
						$url_hash,
						$post_id
					),
					ARRAY_A
				);

				$this->clean_output_buffer();
				wp_send_json_success( is_array( $events ) ? $events : array() );
			}
		);
	}

	/**
	 * Recomputes the link-juice (PageRank) scores on demand.
	 *
	 * @return void
	 */
	public function ajax_recompute_juice(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				NLH_Link_Graph::clear_broken_counts_cache();

				if ( function_exists( 'wp_raise_memory_limit' ) ) {
						wp_raise_memory_limit( 'admin' );
				}

				// Rebuild the map from current content (offline) then score it, so a
				// recalculation works even before any HTTP scan has run.
				$summary = ( new NLH_Link_Graph() )->rebuild_all();

				$this->clean_output_buffer();
				wp_send_json_success(
					array(
						'message' => sprintf(
						/* translators: 1: node count, 2: edge count. */
							__( 'Recalculated %1$d pages and %2$d internal links.', 'native-link-health' ),
							(int) $summary['nodes'],
							(int) $summary['edges']
						),
						'summary' => $summary,
					)
				);
			}
		);
	}

	/**
	 * Returns all broken links for a post (used by Link Juice overview graph).
	 *
	 * @return void
	 */
	public function ajax_juice_broken_details(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

				if ( ! $post_id ) {
						$this->clean_output_buffer();
						wp_send_json_error(
							array( 'message' => __( 'Missing parameters.', 'native-link-health' ) ),
							400
						);
				}

				$details = ( new NLH_Link_Graph() )->get_broken_links_for_post( $post_id );

				$this->clean_output_buffer();
				wp_send_json_success( $details );
			}
		);
	}

	/**
	 * Returns inbound and outbound link details for one post.
	 *
	 * @return void
	 */
	public function ajax_juice_details(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

				if ( ! $post_id ) {
						$this->clean_output_buffer();
						wp_send_json_error( array( 'message' => __( 'Missing post.', 'native-link-health' ) ), 400 );
				}

				$this->clean_output_buffer();
				wp_send_json_success( ( new NLH_Link_Graph() )->get_flow( $post_id ) );
			}
		);
	}

	/**
	 * Returns the capped node/edge set for the global overview diagram.
	 *
	 * @return void
	 */
	public function ajax_juice_graph(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$this->clean_output_buffer();
				wp_send_json_success( ( new NLH_Link_Graph() )->get_graph( 150 ) );
			}
		);
	}

	/**
	 * Re-points an internal link within a post's content.
	 *
	 * Reuses the broken-link corrector (wp_update_post via the HTML tag
	 * processor), logs the change, and refreshes the link map for the post.
	 *
	 * @return void
	 */
	public function ajax_juice_relink(): void {
		$this->run_ajax_safe(
			function () {
				$this->verify_ajax_request();

				$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
				$old_url = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
				$new_url = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';

				if ( ! $post_id || '' === $old_url || '' === $new_url ) {
						$this->clean_output_buffer();
						wp_send_json_error( array( 'message' => __( 'Missing URL data.', 'native-link-health' ) ), 400 );
				}

				if ( ! nlh_update_post_link( $post_id, $old_url, $new_url ) ) {
					$this->clean_output_buffer();
					wp_send_json_error( array( 'message' => __( 'URL was not found in post content.', 'native-link-health' ) ), 404 );
				}

				$this->log_correction( $post_id, $old_url, $new_url, 'juice' );

				// Refresh the source post's link map so the new target is reflected
				// immediately; scores update on the next recalculation.
				$post = get_post( $post_id );
				if ( $post instanceof WP_Post ) {
					( new NLH_Link_Graph() )->record_post( $post_id, $post->post_content );
				}

				$this->clean_output_buffer();
				wp_send_json_success( array( 'message' => __( 'Link updated. Recalculate to refresh scores.', 'native-link-health' ) ) );
			}
		);
	}

	/**
	 * Streams CSV export.
	 *
	 * @return void
	 */
	public function handle_export_csv(): void {
		check_admin_referer( 'nlh_export_csv_action', 'nlh_export_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'native-link-health' ) );
		}

		$export = new NLH_Export();
		$export->export_csv();
	}

	/**
	 * Adds row action links on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {
		if ( '' === NLH_UPGRADE_URL ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( NLH_UPGRADE_URL ),
			esc_html__( 'Upgrade to Pro', 'native-link-health' )
		);

		return $links;
	}

	/**
	 * Creates escaped status badge markup.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $error_type  Error type key (5xx|4xx|fragment|dns|ssl|timeout).
	 * @return string
	 */
	public function get_status_badge( int $status_code, string $error_type = '' ): string {
		if ( $status_code >= 500 ) {
			return sprintf(
				'<span class="nlh-status-badge nlh-status-5xx" title="%s">%s</span>',
				esc_attr__( 'HTTP 5xx: the server returned an internal error. The destination may be temporarily down or misconfigured.', 'native-link-health' ),
				esc_html( (string) $status_code )
			);
		}

		if ( $status_code >= 400 ) {
			return sprintf(
				'<span class="nlh-status-badge nlh-status-4xx" title="%s">%s</span>',
				esc_attr__( 'HTTP 4xx: client error. 404 = page not found; 410 = permanently gone; 403 = access denied.', 'native-link-health' ),
				esc_html( (string) $status_code )
			);
		}

		if ( $status_code > 0 ) {
			return sprintf( '<span class="nlh-status-badge nlh-status-unknown">%s</span>', esc_html( (string) $status_code ) );
		}

		// No HTTP status: the connection failed at the transport level (SSL/DNS/
		// timeout) or it is a fragment miss. Surface the error TYPE the scanner
		// already classified instead of a meaningless "Unknown" status code, and
		// explain via tooltip why no status code exists.
		$labels   = $this->get_transport_badge_labels();
		$tooltips = $this->get_transport_badge_tooltips();

		if ( isset( $labels[ $error_type ] ) ) {
			return sprintf(
				'<span class="nlh-status-badge nlh-status-conn" title="%2$s">%1$s</span>',
				esc_html( $labels[ $error_type ] ),
				esc_attr( $tooltips[ $error_type ] ?? $tooltips['unknown'] )
			);
		}

		return sprintf(
			'<span class="nlh-status-badge nlh-status-unknown" title="%2$s">%1$s</span>',
			esc_html__( 'Unknown', 'native-link-health' ),
			esc_attr( $tooltips['unknown'] )
		);
	}

	/**
	 * Compact badge labels for transport-level failures that have no HTTP status.
	 *
	 * @since 1.3.1
	 * @return array<string,string>
	 */
	public function get_transport_badge_labels(): array {
		return array(
			'ssl'      => __( 'SSL', 'native-link-health' ),
			'dns'      => __( 'DNS', 'native-link-health' ),
			'timeout'  => __( 'Connection', 'native-link-health' ),
			'fragment' => __( 'Anchor', 'native-link-health' ),
		);
	}

	/**
	 * Tooltip text explaining why a transport-level failure shows no HTTP status
	 * code. Keyed by error type, plus an 'unknown' fallback.
	 *
	 * @since 1.3.1
	 * @return array<string,string>
	 */
	public function get_transport_badge_tooltips(): array {
		return array(
			'ssl'      => __( 'No HTTP status code: the secure (HTTPS) connection failed — the site\'s SSL/TLS certificate could not be negotiated, so the server never sent a response.', 'native-link-health' ),
			'dns'      => __( 'No HTTP status code: the domain name could not be resolved to a server, so no server was ever reached.', 'native-link-health' ),
			'timeout'  => __( 'No HTTP status code: the server did not return a valid response (connection refused, reset, empty reply, or timed out).', 'native-link-health' ),
			'fragment' => __( 'The page loads fine, but the #anchor this link points to does not exist on the target page — so there is no HTTP error, just a missing section.', 'native-link-health' ),
			'unknown'  => __( 'No HTTP status code: the link could not be reached, so the server never returned a response to classify.', 'native-link-health' ),
		);
	}

	/**
	 * Formats a MySQL datetime for display.
	 *
	 * @param string|null $mysql_datetime MySQL datetime.
	 * @param string      $fallback Fallback text.
	 * @return string
	 */
	public function format_mysql_datetime( ?string $mysql_datetime, string $fallback = '-' ): string {
		if ( empty( $mysql_datetime ) || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return $fallback;
		}

		$timestamp = strtotime( $mysql_datetime );

		if ( ! $timestamp ) {
			return $fallback;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Discards any stray output (warnings, notices) before sending JSON.
	 * Call before every wp_send_json_success/error to prevent JSON parse errors.
	 */
	private function clean_output_buffer(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Wraps a callback in output-buffer cleanup so stray PHP notices
	 * don't corrupt the JSON response. Any \Throwable is caught and
	 * returned as a JSON error.
	 *
	 * @param callable $callback The AJAX logic to run.
	 * @return void
	 */
	private function run_ajax_safe( callable $callback ): void {
		try {
			$callback();
		} catch ( \Throwable $e ) {
			if ( ob_get_level() ) {
				ob_clean();
			}
			wp_send_json_error(
				array(
					'message' => __( 'Server error: ', 'native-link-health' ) . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Verifies nonce and capability for every AJAX handler in this class.
	 *
	 * @param string $action Nonce action name.
	 * @return void
	 */
	private function verify_ajax_request( string $action = 'nlh_ajax_nonce' ): void {
		check_ajax_referer( $action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->clean_output_buffer();
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'native-link-health' ) ), 403 );
		}
	}

	/**
	 * Returns dashboard rows and status metadata.
	 *
	 * @param string $group_by Grouping mode.
	 * @return array
	 */
	private function get_dashboard_data( string $group_by = 'none' ): array {
		global $wpdb;

		$table       = $wpdb->prefix . 'nlh_link_errors';
		$per_page    = 20;
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset      = ( $paged - 1 ) * $per_page;
		$total       = 0;
		$total_pages = 1;
		$rows        = array();
		$groups      = array();

		if ( in_array( $group_by, array( 'domain', 'error_type', 'post' ), true ) ) {
			$grouped     = $this->scanner->get_grouped_errors( $group_by, $paged, $per_page );
			$groups      = $grouped['groups'];
			$total       = (int) $grouped['total'];
			$total_pages = (int) $grouped['total_pages'];

			foreach ( $groups as $group_index => $group ) {
				$groups[ $group_index ]['items'] = $this->add_regression_flags( (array) $group['items'] );
			}
		} else {
			$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$total_pages = max( 1, (int) ceil( $total / $per_page ) );
			$rows        = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, post_id, source_type, raw_url, url_hash, status_code, error_message, impact_score, discovered_at, last_checked_at FROM {$table} ORDER BY impact_score DESC, last_checked_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
			$rows        = $this->add_regression_flags( is_array( $rows ) ? $rows : array() );
		}

		$last_scan_ts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_nlh_last_scan'
			)
		);

		$posts_scanned = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_nlh_last_scan'
			)
		);

		$next_scan_ts = wp_next_scheduled( 'nlh_run_batch' );

		$total_posts = 0;
		foreach ( $this->scanner->get_scan_post_types() as $pt ) {
			$counts = wp_count_posts( $pt );
			if ( $counts ) {
				// Attachments are stored as 'inherit', never 'publish'.
				$total_posts += 'attachment' === $pt
					? (int) ( $counts->inherit ?? 0 )
					: (int) ( $counts->publish ?? 0 );
			}
		}

		return array(
			'rows'          => $rows,
			'groups'        => $groups,
			'suggestions'   => $this->scanner->suggest_corrections(),
			'total'         => $total,
			'paged'         => $paged,
			'per_page'      => $per_page,
			'total_pages'   => $total_pages,
			'last_scan'     => $last_scan_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan_ts ) : __( 'Never', 'native-link-health' ),
			'next_scan'     => $next_scan_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_scan_ts ) : __( 'Not scheduled', 'native-link-health' ),
			'posts_scanned' => $posts_scanned,
			'total_posts'   => $total_posts,
		);
	}

	/**
	 * Adds regression flags to dashboard rows.
	 *
	 * @param array $rows Error rows.
	 * @return array
	 */
	private function add_regression_flags( array $rows ): array {
		global $wpdb;

		$hashes = array_unique( wp_list_pluck( $rows, 'url_hash' ) );
		if ( empty( $hashes ) ) {
			return $rows;
		}
		$placeholders      = array_fill( 0, count( $hashes ), '%s' );
		$regression_hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT url_hash FROM {$wpdb->prefix}nlh_link_events WHERE url_hash IN (" . implode( ',', $placeholders ) . ") AND event_type = 'regression'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$hashes
			)
		);
		$regression_map    = array_fill_keys( $regression_hashes, true );

		foreach ( $rows as $row ) {
			$row->is_regression = isset( $regression_map[ $row->url_hash ] );
		}

		return $rows;
	}

	/**
	 * Logs a correction row.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @param string $method Method.
	 * @return void
	 */
	private function log_correction( int $post_id, string $old_url, string $new_url, string $method ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'nlh_correction_log',
			array(
				'post_id'    => $post_id,
				'old_url'    => $old_url,
				'new_url'    => $new_url,
				'method'     => sanitize_key( $method ),
				'applied_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Builds a replacement URL for suggestion approvals.
	 *
	 * @param string $old_url Old URL.
	 * @param string $pattern Matched pattern.
	 * @param string $replacement Replacement value.
	 * @param string $type Suggestion type.
	 * @return string
	 */
	private function build_bulk_replacement_url( string $old_url, string $pattern, string $replacement, string $type ): string {
		if ( '' === $replacement ) {
			return '';
		}

		if ( 'path_pattern' === $type ) {
			$parts = wp_parse_url( $old_url );

			if ( empty( $parts['scheme'] ) ) {
				return str_replace( $pattern, untrailingslashit( $replacement ), $old_url );
			}

			return str_replace( $parts['scheme'] . '://' . $pattern, untrailingslashit( $replacement ), $old_url );
		}

		$parts = wp_parse_url( $old_url );

		if ( empty( $parts['host'] ) ) {
			return $old_url;
		}

		$new_host = (string) wp_parse_url( $replacement, PHP_URL_HOST );

		if ( '' === $new_host ) {
			$new_host = preg_replace( '#^https?://#', '', $replacement );
			$new_host = trim( (string) $new_host, '/' );
		}

		$parts['host'] = $new_host;

		return ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' ) .
			$parts['host'] .
			( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) .
			( isset( $parts['path'] ) ? $parts['path'] : '' ) .
			( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) .
			( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Enqueues the meta box script when on a post edit screen for a scanned post type.
	 *
	 * @return void
	 */
	private function maybe_enqueue_meta_box_script(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, $this->scanner->get_scan_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'nlh-meta-box',
			NLH_PLUGIN_URL . 'admin/js/nlh-meta-box.js',
			array(),
			NLH_VERSION,
			true
		);

		wp_localize_script(
			'nlh-meta-box',
			'nlh_meta_box',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'nlh_scan_post_action' ),
				'i18n'     => array(
					'scanning' => __( 'Scanning…', 'native-link-health' ),
					'scanNow'  => __( 'Scan Now', 'native-link-health' ),
					'done'     => __( 'Scan complete.', 'native-link-health' ),
					'error'    => __( 'Scan failed.', 'native-link-health' ),
				),
			)
		);
	}

	/**
	 * Registers the Link Health meta box on all scanned post types.
	 *
	 * @return void
	 */
	public function register_meta_boxes(): void {
		foreach ( $this->scanner->get_scan_post_types() as $post_type ) {
			add_meta_box(
				'nlh-scan-meta-box',
				__( 'Link Health', 'native-link-health' ),
				array( $this, 'render_scan_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the Link Health sidebar meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_scan_meta_box( WP_Post $post ): void {
		$last_scan     = (int) get_post_meta( $post->ID, '_nlh_last_scan', true );
		$dashboard_url = admin_url( 'tools.php?page=nlh-dashboard' );
		?>
		<div id="nlh-meta-box-wrap">
			<p class="description">
				<?php if ( $last_scan > 0 ) : ?>
					<?php
					printf(
						/* translators: %s: human-readable time since last scan (e.g. "5 minutes"). */
						esc_html__( 'Last scanned: %s ago.', 'native-link-health' ),
						esc_html( human_time_diff( $last_scan ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'This post has not been scanned yet.', 'native-link-health' ); ?>
				<?php endif; ?>
			</p>
			<p>
				<button type="button"
					class="button button-secondary nlh-scan-post-btn"
					data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
					<?php esc_html_e( 'Scan Now', 'native-link-health' ); ?>
				</button>
				<span class="nlh-scan-post-status" style="display:none; margin-left:6px;"></span>
			</p>
			<p>
				<a href="<?php echo esc_url( $dashboard_url ); ?>" class="description">
					<?php esc_html_e( 'View all broken links →', 'native-link-health' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Triggers an on-demand scan of a single post from the editor meta box.
	 *
	 * @return void
	 */
	public function ajax_scan_post(): void {
		$this->run_ajax_safe(
			function () {
				check_ajax_referer( 'nlh_scan_post_action', 'nonce' );

				$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

				if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
						$this->clean_output_buffer();
						wp_send_json_error( array( 'message' => __( 'Invalid post.', 'native-link-health' ) ), 400 );
				}

				if ( function_exists( 'set_time_limit' ) ) {
					set_time_limit( 0 );
				}

				$result = $this->scanner->scan_post_now( $post_id );

				if ( isset( $result['error'] ) ) {
					$this->clean_output_buffer();
					wp_send_json_error( array( 'message' => $result['error'] ) );
				}

				$result['message'] = __( 'Scan complete.', 'native-link-health' );
				$this->clean_output_buffer();
				wp_send_json_success( $result );
			}
		);
	}

	/**
	 * Displays a one-time welcome notice after plugin activation.
	 *
	 * @return void
	 */
	public function show_welcome_notice(): void {
		if ( ! get_option( 'nlh_show_welcome' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dashboard_url = admin_url( 'tools.php?page=nlh-dashboard' );
		$nonce         = wp_create_nonce( 'nlh_dismiss_welcome' );
		?>
		<div class="notice notice-info is-dismissible nlh-welcome-notice"
			data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<strong><?php esc_html_e( 'Native Link Health is active!', 'native-link-health' ); ?></strong>
				<?php esc_html_e( 'Your first scan will start automatically via WP-Cron. To scan immediately,', 'native-link-health' ); ?>
				<a href="<?php echo esc_url( $dashboard_url ); ?>">
					<?php esc_html_e( 'open the dashboard', 'native-link-health' ); ?>
				</a>
				<?php esc_html_e( 'and click "Run Scan Now".', 'native-link-health' ); ?>
			</p>
		</div>
		<script>
		(function () {
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('.nlh-welcome-notice .notice-dismiss');
				if (!btn) return;
				var notice = btn.closest('.nlh-welcome-notice');
				var nonce  = notice ? notice.dataset.nonce : '';
				if (!nonce) return;
				var body = new URLSearchParams();
				body.append('action', 'nlh_dismiss_welcome');
				body.append('nonce', nonce);
				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				});
			});
		}());
		</script>
		<?php
	}

	/**
	 * Marks the welcome notice as dismissed.
	 *
	 * @return void
	 */
	public function ajax_dismiss_welcome(): void {
		check_ajax_referer( 'nlh_dismiss_welcome', 'nonce' );

		if ( current_user_can( 'manage_options' ) ) {
			delete_option( 'nlh_show_welcome' );
		}

		wp_die();
	}
}

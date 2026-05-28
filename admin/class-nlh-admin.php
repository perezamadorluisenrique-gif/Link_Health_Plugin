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
		add_action( 'admin_post_nlh_export_csv', array( $this, 'handle_export_csv' ) );
		add_filter( 'plugin_action_links_' . NLH_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
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
				'sanitize_callback' => array( $this, 'sanitize_absint' ),
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

		add_settings_section(
			'nlh_scan_settings',
			__( 'Scan Settings', 'native-link-health' ),
			array( $this, 'render_settings_section' ),
			'nlh-settings'
		);

		add_settings_field(
			'nlh_scan_frequency',
			__( 'Scan Frequency', 'native-link-health' ),
			array( $this, 'render_scan_frequency_field' ),
			'nlh-settings',
			'nlh_scan_settings'
		);

		add_settings_field(
			'nlh_ignored_domains',
			__( 'Ignored URLs', 'native-link-health' ),
			array( $this, 'render_ignored_domains_field' ),
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

		add_settings_field(
			'nlh_email_notifications',
			__( 'Email Notifications', 'native-link-health' ),
			array( $this, 'render_email_notifications_field' ),
			'nlh-settings',
			'nlh_automation_settings'
		);
	}

	/**
	 * Enqueues admin assets only on plugin pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( ! in_array( $page, array( 'nlh-dashboard', 'nlh-settings', 'nlh-seo-audit' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'nlh-admin',
			NLH_PLUGIN_URL . 'admin/css/nlh-admin.css',
			array(),
			NLH_VERSION
		);

		wp_enqueue_script(
			'nlh-admin',
			NLH_PLUGIN_URL . 'admin/js/nlh-admin.js',
			array(),
			NLH_VERSION,
			true
		);

		wp_localize_script(
			'nlh-admin',
			'nlh_ajax',
			array(
				'url'         => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'nlh_ajax_nonce' ),
				'runNowNonce' => wp_create_nonce( 'nlh_run_now_action' ),
				'i18n'        => array(
					'working'       => __( 'Working...', 'native-link-health' ),
					'scanQueued'    => __( 'Scan queued.', 'native-link-health' ),
					'confirmIgnore' => __( 'Ignore this URL permanently?', 'native-link-health' ),
					'error'         => __( 'Request failed.', 'native-link-health' ),
					'unknown'       => __( 'Unknown', 'native-link-health' ),
					'showHistory'   => __( 'History', 'native-link-health' ),
					'hideHistory'   => __( 'Hide History', 'native-link-health' ),
					'noHistory'     => __( 'No history recorded.', 'native-link-health' ),
					'seoRunning'    => __( 'Running audit...', 'native-link-health' ),
					'auditComplete' => __( 'SEO audit complete.', 'native-link-health' ),
					'progress'      => __( 'Scanned %1$d of %2$d posts.', 'native-link-health' ),
					'eventBroken'         => __( 'Broken', 'native-link-health' ),
					'eventFixed'          => __( 'Fixed', 'native-link-health' ),
					'eventRegression'     => __( 'Regression', 'native-link-health' ),
					'eventIgnored'        => __( 'Ignored', 'native-link-health' ),
					'seoOrphanPages'      => __( 'Orphan pages', 'native-link-health' ),
					'seoRedirectChains'   => __( 'Redirect chains', 'native-link-health' ),
					'seoMixedContent'     => __( 'Mixed content', 'native-link-health' ),
					'seoInvalidCanonicals' => __( 'Invalid canonicals', 'native-link-health' ),
					'seoRedundantLinks'   => __( 'Redundant links', 'native-link-health' ),
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

		$group_by      = isset( $_GET['nlh_group_by'] ) ? sanitize_key( wp_unslash( $_GET['nlh_group_by'] ) ) : 'none';
		$filter        = isset( $_GET['nlh_filter'] ) ? sanitize_key( wp_unslash( $_GET['nlh_filter'] ) ) : 'all';
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
		echo '<p>' . esc_html__( 'The free scanner runs every 15 minutes and processes 5 posts per cycle.', 'native-link-health' ) . '</p>';
	}

	/**
	 * Renders disabled scan frequency selector.
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
	 * Renders disabled ignored domains textarea.
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

		if ( empty( $rules ) ) {
			$rules = array(
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
			);
		}
		?>
		<textarea id="nlh_auto_rules" class="large-text code" rows="8" disabled><?php echo esc_textarea( wp_json_encode( $rules, JSON_PRETTY_PRINT ) ); ?></textarea>
		<span class="nlh-pro-badge"><?php esc_html_e( 'Available in Pro', 'native-link-health' ); ?></span>
		<p class="description"><?php esc_html_e( 'Automatic rule editing is reserved for Native Link Health Pro. The engine is available for stored rules.', 'native-link-health' ); ?></p>
		<?php
	}

	/**
	 * Renders disabled email notification field.
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
	 * Handles inline URL correction.
	 *
	 * @return void
	 */
	public function ajax_correct_url(): void {
		$this->verify_ajax_request();

		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$record_id = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;
		$old_url   = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
		$new_url   = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';

		if ( ! $post_id || '' === $old_url || '' === $new_url ) {
			wp_send_json_error( array( 'message' => __( 'Missing URL data.', 'native-link-health' ) ), 400 );
		}

		if ( ! nlh_update_post_link( $post_id, $old_url, $new_url ) ) {
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

		wp_send_json_success(
			array_merge(
				array( 'message' => __( 'URL corrected and re-checked.', 'native-link-health' ) ),
				$result
			)
		);
	}

	/**
	 * Handles manual URL re-check.
	 *
	 * @return void
	 */
	public function ajax_recheck_url(): void {
		$this->verify_ajax_request();

		$url       = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$record_id = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;

		if ( '' === $url || ! $post_id || ! $record_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing re-check data.', 'native-link-health' ) ), 400 );
		}

		wp_send_json_success( $this->scanner->recheck_url( $url, $post_id, $record_id ) );
	}

	/**
	 * Handles ignored URL action.
	 *
	 * @return void
	 */
	public function ajax_ignore_url(): void {
		$this->verify_ajax_request();

		$url       = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$record_id = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;

		if ( '' === $url || ! $record_id ) {
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

		if ( $post_id > 0 ) {
			$this->scanner->record_link_event( $url_hash, $post_id, 'ignored', 0 );
		}

		$where   = array( 'url_hash' => $url_hash );
		$formats = array( '%s' );
		if ( ! empty( $_POST['post_id'] ) ) {
			$where['post_id'] = $post_id;
			$formats[] = '%d';
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

		wp_send_json_success( array( 'message' => __( 'URL ignored.', 'native-link-health' ) ) );
	}

	/**
	 * Runs quick or chunked full scans.
	 *
	 * @return void
	 */
	public function ajax_run_now(): void {
		check_ajax_referer( 'nlh_run_now_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'native-link-health' ) ), 403 );
		}

		$mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'quick';
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$chunk_size = 'full' === $mode ? 50 : 5;
		$result     = $this->scanner->run_full_scan( $chunk_size, $offset );

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

		wp_send_json_success( $result );
	}

	/**
	 * Handles bulk correction suggestions.
	 *
	 * @return void
	 */
	public function ajax_bulk_correct(): void {
		$this->verify_ajax_request();

		$pattern     = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) : '';
		$type        = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'domain_death';
		$replacement = isset( $_POST['replacement'] ) ? esc_url_raw( wp_unslash( $_POST['replacement'] ) ) : '';

		if ( '' === $pattern ) {
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

	/**
	 * Runs and caches the SEO audit.
	 *
	 * @return void
	 */
	public function ajax_run_seo_audit(): void {
		$this->verify_ajax_request();

		$audit   = new NLH_SEO_Audit();
		$results = array(
			'orphan_pages'       => $audit->audit_orphan_pages(),
			'redirect_chains'    => $audit->audit_redirect_chains(),
			'mixed_content'      => $audit->audit_mixed_content(),
			'invalid_canonicals' => $audit->audit_invalid_canonicals(),
			'redundant_links'    => $audit->audit_redundant_links(),
		);

		set_transient( 'nlh_seo_audit_results', $results, DAY_IN_SECONDS );

		wp_send_json_success( $results );
	}

	/**
	 * Returns per-link timeline events.
	 *
	 * @return void
	 */
	public function ajax_get_timeline(): void {
		$this->verify_ajax_request();

		$url_hash = isset( $_POST['url_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['url_hash'] ) ) : '';
		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( '' === $url_hash || ! $post_id ) {
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

		wp_send_json_success( is_array( $events ) ? $events : array() );
	}

	/**
	 * Streams CSV export.
	 *
	 * @return void
	 */
	public function handle_export_csv(): void {
		check_admin_referer( 'nlh_export_csv_action', 'nlh_export_nonce' );
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
	 * @param int $status_code HTTP status code.
	 * @return string
	 */
	public function get_status_badge( int $status_code ): string {
		$class = 'nlh-status-unknown';
		$label = $status_code > 0 ? (string) $status_code : __( 'Unknown', 'native-link-health' );

		if ( $status_code >= 400 && $status_code < 500 ) {
			$class = 'nlh-status-4xx';
		} elseif ( $status_code >= 500 ) {
			$class = 'nlh-status-5xx';
		}

		return sprintf(
			'<span class="nlh-status-badge %1$s">%2$s</span>',
			esc_attr( $class ),
			esc_html( $label )
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
	 * Verifies common AJAX security requirements.
	 *
	 * @return void
	 */
	private function verify_ajax_request(): void {
		check_ajax_referer( 'nlh_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
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
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset      = ( $paged - 1 ) * $per_page;
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
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
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, post_id, raw_url, url_hash, status_code, error_message, impact_score, discovered_at, last_checked_at FROM {$table} ORDER BY impact_score DESC, last_checked_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
			$rows = $this->add_regression_flags( is_array( $rows ) ? $rows : array() );
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
		$placeholders     = array_fill( 0, count( $hashes ), '%s' );
		$regression_hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT url_hash FROM {$wpdb->prefix}nlh_link_events WHERE url_hash IN (" . implode( ',', $placeholders ) . ") AND event_type = 'regression'",
				$hashes
			)
		);
		$regression_map   = array_fill_keys( $regression_hashes, true );

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
}

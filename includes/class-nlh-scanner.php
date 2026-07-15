<?php
/**
 * Link scanner and post-content updater.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans post content and manages link health records.
 */
class NLH_Scanner {
	/**
	 * Error message stored for links whose IDN/IRI hostname cannot be converted
	 * to ASCII (no intl extension, or a host idn_to_ascii() rejects). These links
	 * cannot be verified over HTTP, so rather than dropping them silently they are
	 * surfaced in the dashboard. The 'IDN:' prefix routes them to the 'dns' error
	 * type in classify_error_type().
	 *
	 * @since 1.3.2
	 * @var string
	 */
	const IDN_UNVERIFIABLE_MESSAGE = 'IDN: hostname could not be converted to ASCII — link not verified';

	/**
	 * Writes an internal diagnostic message to the PHP error log, but only when
	 * WP_DEBUG is on — production sites never accumulate log noise from here.
	 *
	 * @since 1.5.1
	 * @param string $message Message (without the plugin prefix).
	 * @return void
	 */
	private static function log_debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Native Link Health: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional, WP_DEBUG-gated diagnostic.
		}
	}

	/**
	 * Runs one fixed-size scanner batch.
	 *
	 * @return void
	 */
	public function run_batch(): void {
		// A user-initiated full scan takes priority: yield this cron tick so the
		// two never probe the same URLs at once (which would waste requests and
		// undercount the broken-confirmation counter). The flag self-expires, so
		// an abandoned manual scan can't deadlock the cron permanently.
		if ( get_transient( 'nlh_manual_scan_active' ) ) {
			return;
		}

		$this->scan_posts( $this->get_batch_posts() );

		// Extended scope (opt-in via the Scan Scope setting). These run after the
		// post batch and self-skip when their scope flag is off.
		$this->scan_comments();
		$this->scan_nav_menus();
	}

	/**
	 * Returns post types included in link scans.
	 *
	 * @return string[]
	 */
	public function get_scan_post_types(): array {
		$scope      = get_option( 'nlh_scan_scope', array() );
		$extra      = is_array( $scope ) ? (array) ( $scope['post_types'] ?? array() ) : array();
		$post_types = apply_filters( 'nlh_scan_post_types', array_merge( array( 'post', 'page' ), $extra ) );

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) ) );
	}

	/**
	 * Post statuses to include when querying scannable content.
	 *
	 * Attachments are stored with post_status 'inherit', never 'publish', so a
	 * publish-only query silently excludes them and the "Media (attachments)"
	 * Scan Scope opt-in would scan nothing (the pre-1.5.1 behavior). 'inherit'
	 * is added only when attachment is actually in scope; revisions cannot leak
	 * in because every query here also filters by the scanned post types.
	 *
	 * @since 1.5.1
	 * @param string[] $post_types The post types being queried.
	 * @return string[]
	 */
	private function get_scan_post_statuses( array $post_types ): array {
		return in_array( 'attachment', $post_types, true )
			? array( 'publish', 'inherit' )
			: array( 'publish' );
	}

	/**
	 * Runs an immediate scan in chunks.
	 *
	 * @param int $chunk_size Number of posts to scan.
	 * @param int $offset Offset for this chunk.
	 * @return array
	 */
	public function run_full_scan( int $chunk_size = 50, int $offset = 0 ): array {
		$chunk_size = max( 1, $chunk_size );
		$offset     = max( 0, $offset );
		$post_types = $this->get_scan_post_types();

		// Signal that a manual scan is in flight so the cron batch yields. Renewed
		// each chunk (short TTL) and cleared on completion below; if the user
		// navigates away mid-scan the flag simply expires.
		set_transient( 'nlh_manual_scan_active', time(), 5 * MINUTE_IN_SECONDS );

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'posts_per_page'         => $chunk_size,
				'offset'                 => $offset,
				'post_status'            => $this->get_scan_post_statuses( $post_types ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$scanned = $this->scan_posts( $query->posts, true );
		$total   = (int) $query->found_posts;
		$next    = $offset + $scanned;
		$done    = $next >= $total || 0 === $scanned;

		if ( $done ) {
			delete_transient( 'nlh_manual_scan_active' );

			// The internal link map is now fully refreshed for every post, so
			// recompute the link-juice (PageRank) scores in one pass.
			if ( class_exists( 'NLH_Link_Graph' ) ) {
				( new NLH_Link_Graph() )->compute_pagerank();
			} else {
				self::log_debug( 'NLH_Link_Graph class not found — link juice features disabled in ' . __METHOD__ );
			}
		}

		return array(
			'done'    => $done,
			'scanned' => $next,
			'total'   => $total,
			'next'    => $next,
		);
	}

	/**
	 * Marks a saved post as needing a fresh scan.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @param bool    $update Whether this is an existing post update.
	 * @return void
	 */
	public function handle_post_saved( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->get_scan_post_types(), true ) ) {
			return;
		}

		// Keep the internal link map fresh on every save (offline, no HTTP) so
		// the juice report reflects edits immediately. Scores are recomputed by
		// the next full scan or the manual "Recalculate" action.
		if ( class_exists( 'NLH_Link_Graph' ) ) {
			( new NLH_Link_Graph() )->record_post( $post_id, $post->post_content );
		} else {
			self::log_debug( 'NLH_Link_Graph class not found — link juice features disabled in ' . __METHOD__ );
		}

		if ( $update ) {
			delete_post_meta( $post_id, '_nlh_last_scan' );
			// Anchor fixes are validated against post content, not over HTTP, so
			// clear any now-resolved fragment errors right away instead of making
			// the author wait for the next cron batch. Broken HTTP links still
			// re-verify on the next scan (meta cleared above).
			$this->heal_post_fragments( $post_id, $post->post_content );
			return;
		}

		delete_post_meta( $post_id, '_nlh_last_scan' );

		foreach ( $this->extract_urls( $post->post_content ) as $url ) {
			$this->clear_url_cache( $url, $post_id );
		}
	}

	/**
	 * Re-validates a post's fragment links and clears any that now resolve.
	 *
	 * Mirrors the fragment handling in scan_posts() but performs no HTTP work,
	 * so it is safe to run inline on save_post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Post content.
	 * @return void
	 */
	private function heal_post_fragments( int $post_id, string $content ): void {
		$self_base = untrailingslashit( explode( '#', (string) get_permalink( $post_id ), 2 )[0] );

		$fragment_urls = array();
		foreach ( $this->extract_urls( $content ) as $candidate ) {
			if ( ( isset( $candidate[0] ) && '#' === $candidate[0] ) || $this->is_same_page_fragment( $candidate, $self_base ) ) {
				$fragment_urls[] = $candidate;
			}
		}

		$fragment_urls    = array_values( $fragment_urls );
		$broken_fragments = $this->validate_fragments( $content, $fragment_urls, $post_id );

		$this->reconcile_fragments( $post_id, $fragment_urls, $broken_fragments );
	}

	/**
	 * Deletes fragment error records whose anchors now resolve.
	 *
	 * A fragment is "fixed" when it appears in the post's candidate list but no
	 * longer in the broken set. Matching on the exact stored error message keeps
	 * this from touching HTTP-error records (DNS/timeout) that also store
	 * status_code 0.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $fragment_urls All fragment candidates in the post.
	 * @param string[] $broken_fragments Fragment candidates still unresolved.
	 * @return void
	 */
	private function reconcile_fragments( int $post_id, array $fragment_urls, array $broken_fragments ): void {
		global $wpdb;

		$now_valid = array_values( array_diff( $fragment_urls, $broken_fragments ) );

		if ( empty( $now_valid ) ) {
			return;
		}

		$table = $wpdb->prefix . 'nlh_link_errors';

		foreach ( $now_valid as $url ) {
			$url_hash  = md5( $url );
			$record_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE post_id = %d AND url_hash = %s AND error_message = %s LIMIT 1",
					$post_id,
					$url_hash,
					'Missing anchor fragment'
				)
			);

			if ( $record_id <= 0 ) {
				continue;
			}

			$wpdb->delete( $table, array( 'id' => $record_id ), array( '%d' ) );
			delete_transient( 'nlh_retry_' . $url_hash . '_' . $post_id );
			$this->record_link_event( $url_hash, $post_id, 'fixed', 0 );
		}
	}

	/**
	 * Calculates a simple priority score for a link's host post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public function calculate_impact_score( int $post_id ): int {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		$score = 0;

		if ( 'page' === $post->post_type ) {
			$score += 20;
		} elseif ( 'post' === $post->post_type ) {
			$score += 10;
		}

		$post_timestamp = get_post_time( 'U', true, $post );
		$age_days       = $post_timestamp ? ( time() - $post_timestamp ) / DAY_IN_SECONDS : 9999;

		if ( $age_days < 30 ) {
			$score += 20;
		} elseif ( $age_days < 90 ) {
			$score += 10;
		}

		if ( 'publish' === $post->post_status ) {
			$score += 10;
		}

		if ( (int) get_option( 'page_on_front' ) === $post_id ) {
			$score += 30;
		}

		if ( (int) get_option( 'page_for_posts' ) === $post_id ) {
			$score += 20;
		}

		$comment_count = (int) $post->comment_count;

		if ( $comment_count > 50 ) {
			$score += 20;
		} elseif ( $comment_count > 10 ) {
			$score += 10;
		}

		return min( 100, $score );
	}

	/**
	 * Scans all links in a single post immediately, bypassing cron.
	 *
	 * @param int $post_id Post to scan.
	 * @return array Keys: scanned (int), post_id (int), last_scan (int timestamp), or error (string).
	 */
	public function scan_post_now( int $post_id ): array {
		$post = get_post( $post_id );

		$scan_types = $this->get_scan_post_types();

		if ( ! $post || ! in_array( $post->post_type, $scan_types, true ) || ! in_array( $post->post_status, $this->get_scan_post_statuses( $scan_types ), true ) ) {
			return array( 'error' => __( 'Post not found or not scannable.', 'native-link-health' ) );
		}

		set_transient( 'nlh_manual_scan_active', time(), 5 * MINUTE_IN_SECONDS );
		$this->scan_posts( array( $post ), true );
		delete_transient( 'nlh_manual_scan_active' );

		return array(
			'scanned'   => 1,
			'post_id'   => $post_id,
			'last_scan' => (int) get_post_meta( $post_id, '_nlh_last_scan', true ),
		);
	}

	/**
	 * Scans a list of posts.
	 *
	 * @param WP_Post[] $posts Posts to scan.
	 * @param bool      $force Whether cached OK/retry results should be ignored.
	 * @return int Number of posts scanned.
	 */
	private function scan_posts( array $posts, bool $force = false ): int {
		global $wpdb;

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return 0;
		}

		$started_at   = microtime( true );
		$counters     = array(
			'checked'       => 0,
			'broken'        => 0,
			'skipped_valid' => 0,
			'retries'       => 0,
		);
		$table        = $wpdb->prefix . 'nlh_link_errors';
		$ignored      = get_option( 'nlh_ignored_urls', array() );
		$rules_engine = class_exists( 'NLH_Rules_Engine' ) ? new NLH_Rules_Engine( $this ) : null;
		if ( class_exists( 'NLH_Link_Graph' ) ) {
			$link_graph = new NLH_Link_Graph();
		} else {
			self::log_debug( 'NLH_Link_Graph class not found — link juice features disabled in ' . __METHOD__ );
			$link_graph = null;
		}

		if ( ! is_array( $ignored ) ) {
			$ignored = array();
		}

		foreach ( $posts as $post ) {
			$idn_unverifiable = array();
			$urls             = $this->extract_urls( $post->post_content, $idn_unverifiable );
			// IDN-unverifiable URLs must stay in the "current" set or the upsert
			// below would be deleted as stale on the very same pass.
			$this->delete_stale_records( $post->ID, array_merge( $urls, $idn_unverifiable ) );

			// Build the internal link map as a side effect of this traversal.
			// Pure content parsing + url_to_postid(): no HTTP, independent of the
			// broken-link probe pipeline below.
			if ( $link_graph ) {
				$link_graph->record_post( (int) $post->ID, $post->post_content );
			}

			// Same-page deep links (#anchor, or an absolute URL pointing back to
			// this very post) are validated against the post's own anchors, not
			// fetched over HTTP.
			$self_base = untrailingslashit( explode( '#', (string) get_permalink( $post ), 2 )[0] );

			foreach ( $urls as $url ) {
				if ( isset( $url[0] ) && '#' === $url[0] ) {
					continue;
				}

				if ( $this->is_same_page_fragment( $url, $self_base ) ) {
					continue;
				}

				$this->check_and_record_url( $url, (int) $post->ID, 'post', $force, $ignored, $rules_engine, $counters );
			}

			// Surface links whose IDN host could not be converted (otherwise dropped
			// silently). No HTTP probe — recorded directly with a dedicated message.
			$this->record_idn_unverifiable( $idn_unverifiable, (int) $post->ID, 'post', $counters );

			$fragment_urls = array();
			foreach ( $urls as $candidate ) {
				if ( ( isset( $candidate[0] ) && '#' === $candidate[0] ) || $this->is_same_page_fragment( $candidate, $self_base ) ) {
					$fragment_urls[] = $candidate;
				}
			}
			$fragment_urls    = array_values( $fragment_urls );
			$broken_fragments = $this->validate_fragments( $post->post_content, $fragment_urls, $post->ID );
			foreach ( $broken_fragments as $frag_url ) {
				$frag_hash        = md5( $frag_url );
				$frag_transient   = 'nlh_retry_' . $frag_hash . '_' . $post->ID;
				$existing_frag_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table} WHERE post_id = %d AND url_hash = %s LIMIT 1",
						$post->ID,
						$frag_hash
					)
				);

				if ( 0 === $existing_frag_id ) {
					$impact_score = $this->calculate_impact_score( (int) $post->ID );
					$wpdb->replace(
						$table,
						array(
							'post_id'         => $post->ID,
							'raw_url'         => $frag_url,
							'url_hash'        => $frag_hash,
							'status_code'     => 0,
							'error_message'   => 'Missing anchor fragment',
							'impact_score'    => $impact_score,
							'discovered_at'   => current_time( 'mysql', true ),
							'last_checked_at' => current_time( 'mysql', true ),
						),
						array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
					);
					++$counters['broken'];
				}

				set_transient( $frag_transient, true, HOUR_IN_SECONDS );
			}

			// Clear records for fragments whose anchor now exists, so a fixed
			// anchor heals on the next scan just like a recovered HTTP link does.
			$this->reconcile_fragments( (int) $post->ID, $fragment_urls, $broken_fragments );

			update_post_meta( $post->ID, '_nlh_last_scan', time() );
		}

		$this->update_scan_metrics(
			array(
				'total_urls_checked'  => $counters['checked'],
				'total_broken_found'  => $counters['broken'],
				'total_skipped_valid' => $counters['skipped_valid'],
				'total_retries'       => $counters['retries'],
				'last_batch_duration' => microtime( true ) - $started_at,
				'peak_memory_usage'   => memory_get_peak_usage( true ),
				'last_updated'        => time(),
			)
		);

		return count( $posts );
	}

	/**
	 * Builds the source-scoped suffix for retry/fail/last-ok state keys.
	 *
	 * Post records keep the historical `{post_id}` suffix so existing caches on
	 * upgraded installs stay valid; comment and menu records get a source prefix
	 * so they never collide with a post that happens to share the same URL hash
	 * and numeric id (e.g. a comment on post 5 vs. post 5 itself; menus use 0).
	 *
	 * @param string $source_type 'post' | 'comment' | 'menu'.
	 * @param int    $post_id     Owning post id (0 for menus).
	 * @return string
	 */
	public function state_key_suffix( string $source_type, int $post_id ): string {
		return 'post' === $source_type ? (string) $post_id : $source_type . '_' . $post_id;
	}

	/**
	 * Runs the conservative broken-link pipeline for a single URL and records or
	 * clears its error record. Shared by post, comment, and navigation-menu scans.
	 *
	 * Behaviour mirrors the original inline post pipeline: ok-cache + retry-backoff
	 * skips, soft/rate-limit handling, the multi-probe confirmation gate for brand
	 * new failures, discovered_at preservation, regression/fixed event logging, and
	 * the rules engine (post sources only). State keys are scoped by source_type so
	 * a comment or menu item never collides with the post sharing the same URL.
	 *
	 * @param string                $url          Absolute URL to check.
	 * @param int                   $post_id      Owning post (0 for menus).
	 * @param string                $source_type  'post' | 'comment' | 'menu'.
	 * @param bool                  $force        Skip caches and the confirmation gate.
	 * @param array                 $ignored      Ignored URL list.
	 * @param NLH_Rules_Engine|null $rules_engine Rules engine, if available.
	 * @param array                 $counters     By-ref tally (checked/broken/skipped_valid/retries).
	 * @return void
	 */
	private function check_and_record_url( string $url, int $post_id, string $source_type, bool $force, array $ignored, ?NLH_Rules_Engine $rules_engine, array &$counters ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'nlh_link_errors';

		if ( in_array( $url, $ignored, true ) ) {
			return;
		}

		$url_hash        = md5( $url );
		$state_suffix    = $this->state_key_suffix( $source_type, $post_id );
		$transient_ok    = 'nlh_ok_' . $url_hash;
		$transient_retry = 'nlh_retry_' . $url_hash . '_' . $state_suffix;

		if ( ! $force && get_transient( $transient_ok ) ) {
			++$counters['skipped_valid'];
			return;
		}

		if ( ! $force && get_transient( $transient_retry ) ) {
			++$counters['retries'];
			return;
		}

		++$counters['checked'];
		try {
			$response = $this->head_request( $url );
		} catch ( \Throwable $e ) {
			$response = new WP_Error( 'http_request_failed', $e->getMessage() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );

		// Rate-limit (429), configured "soft" codes (e.g. 999 from LinkedIn, or
		// 401/403 bot-blocks), and Cloudflare-style anti-bot challenges all mean
		// "could not verify", not "broken". Back off and retry instead of flagging.
		if ( 429 === $code || in_array( $code, $this->get_soft_status_codes(), true ) || $this->is_bot_challenge_response( $response, $code ) ) {
			++$counters['retries'];
			set_transient( $transient_retry, true, HOUR_IN_SECONDS );

			// A soft/challenge result neither confirms nor clears an existing
			// broken record, but it IS a check that just happened. Touch
			// last_checked_at AND refresh the stored status/message to the real
			// "could not verify" state: leaving the old (possibly stale, now
			// factually wrong) error in place makes the dashboard lie about why
			// the link is flagged. The row stays on the books, but as an honest
			// "unverifiable" instead of a frozen hard error. The %d update below
			// is a no-op when no broken record exists (soft never creates one).
			$soft_message = __( 'Could not verify (anti-bot challenge or rate limit).', 'native-link-health' );
			$wpdb->update(
				$table,
				array(
					'status_code'     => $code ?: 0,
					'error_message'   => sanitize_text_field( $soft_message ),
					'last_checked_at' => current_time( 'mysql', true ),
				),
				array(
					'post_id'     => $post_id,
					'url_hash'    => $url_hash,
					'source_type' => $source_type,
				),
				array( '%d', '%s', '%s' ),
				array( '%d', '%s', '%s' )
			);
			// Stamp when the unverifiable streak began (preserved across repeated
			// soft checks, cleared on the next hard ok/broken confirmation) so the
			// dashboard's "Unverified since" is meaningful and long-unverifiable
			// records can be excluded from the broken count / health score.
			$this->mark_unverified_since( $url_hash, $state_suffix );
			return;
		}

		if ( is_wp_error( $response ) || $code >= 400 || $code < 100 ) {
			$existing    = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, discovered_at FROM {$table} WHERE post_id = %d AND url_hash = %s AND source_type = %s LIMIT 1",
					$post_id,
					$url_hash,
					$source_type
				)
			);
			$existing_id = (int) ( $existing->id ?? 0 );

			// Conservative confirmation: a brand-new failure can be a transient
			// network blip, a momentarily slow server, or a flaky DNS lookup.
			// Require it to fail repeatedly (with a backoff between probes) before
			// recording it. Links already on record, and forced manual scans, are
			// exempt so genuine breakage is neither delayed nor hidden.
			if ( 0 === $existing_id && ! $force ) {
				$fail_key   = 'nlh_fail_' . $url_hash . '_' . $state_suffix;
				$fail_count = (int) get_transient( $fail_key ) + 1;

				if ( $fail_count < $this->get_broken_confirm_threshold() ) {
					set_transient( $fail_key, $fail_count, WEEK_IN_SECONDS );
					set_transient( $transient_retry, true, HOUR_IN_SECONDS );
					++$counters['retries'];
					return;
				}

				delete_transient( $fail_key );
			}

			++$counters['broken'];

			$error = is_wp_error( $response )
				? $response->get_error_message()
				: 'HTTP ' . $code;

			$last_ok_option = 'nlh_last_ok_' . $url_hash . '_' . $state_suffix;
			$last_ok        = get_option( $last_ok_option, false );
			$event_type     = false === $last_ok ? 'broken' : 'regression';

			if ( false !== $last_ok ) {
				delete_option( $last_ok_option );
			}

			if ( 0 === $existing_id || 'regression' === $event_type ) {
				$this->record_link_event( $url_hash, $post_id, $event_type, $code ?: 0 );
			}

			// Prevent stale OK cache — re-check this URL sooner.
			delete_transient( 'nlh_ok_' . $url_hash );

			// A hard confirmation supersedes any earlier soft/could-not-verify flag.
			delete_option( 'nlh_last_soft_' . $url_hash . '_' . $state_suffix );

			// Navigation links are sitewide and high-impact; posts are scored by
			// authority; comments (post_id holds the comment id) carry no weight.
			if ( 'menu' === $source_type ) {
				$impact_score = 50;
			} elseif ( 'post' === $source_type ) {
				$impact_score = $this->calculate_impact_score( $post_id );
			} else {
				$impact_score = 0;
			}

			// Preserve original discovery date if this URL was already tracked.
			$discovered_at = ( $existing && $existing->discovered_at )
				? $existing->discovered_at
				: current_time( 'mysql', true );

			$wpdb->replace(
				$table,
				array(
					'post_id'         => $post_id,
					'source_type'     => $source_type,
					'raw_url'         => $url,
					'url_hash'        => $url_hash,
					'status_code'     => $code ?: 0,
					'error_message'   => sanitize_text_field( $error ),
					'impact_score'    => $impact_score,
					'discovered_at'   => $discovered_at,
					'last_checked_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
			);

			// Auto-fix rules rewrite post content, so only run them for post sources.
			if ( $rules_engine && 'post' === $source_type ) {
				$action = $rules_engine->evaluate_rules( $url, $post_id, $code ?: 0, (string) $error );

				if ( $action ) {
					$rules_engine->apply_action( $action, $url, $post_id );
				}
			}

			// Extension point: a brand-new broken record was just written (it
			// cleared the confirmation gate). The Pro email/alerts layer hooks this
			// to notify the admin. Core never sends mail itself.
			if ( 0 === $existing_id ) {
				/**
				 * Fires when a newly broken link is recorded for the first time.
				 *
				 * @since 1.3.0
				 * @param string $url         The broken URL.
				 * @param int    $post_id     Owning post id (comment id for comments, 0 for menus).
				 * @param string $source_type 'post' | 'comment' | 'menu'.
				 * @param int    $code        HTTP status code (0 when none).
				 * @param string $error       Human-readable error message.
				 */
				do_action( 'nlh_link_broken_recorded', $url, $post_id, $source_type, $code ?: 0, (string) $error );
			}
		} else {
			delete_transient( 'nlh_fail_' . $url_hash . '_' . $state_suffix );
			set_transient( $transient_ok, true, 2 * DAY_IN_SECONDS );
			update_option( 'nlh_last_ok_' . $url_hash . '_' . $state_suffix, time(), false );
			delete_option( 'nlh_last_soft_' . $url_hash . '_' . $state_suffix );

			// If this URL was previously recorded broken, log the recovery so the
			// timeline reflects cron-detected fixes too.
			$was_broken = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE post_id = %d AND url_hash = %s AND source_type = %s LIMIT 1",
					$post_id,
					$url_hash,
					$source_type
				)
			);

			$wpdb->delete(
				$table,
				array(
					'post_id'     => $post_id,
					'url_hash'    => $url_hash,
					'source_type' => $source_type,
				),
				array( '%d', '%s', '%s' )
			);

			if ( $was_broken > 0 ) {
				$this->record_link_event( $url_hash, $post_id, 'fixed', $code );
			}
		}
	}

	/**
	 * Scans approved comment content for broken links, one cursor-tracked batch
	 * per cron tick. Records are keyed by comment id (source_type 'comment') so a
	 * comment is cleaned independently of its siblings on the same post and can be
	 * deep-linked from the dashboard.
	 *
	 * @param bool $force Skip caches and the confirmation gate.
	 * @return int Number of comments scanned this run.
	 */
	public function scan_comments( bool $force = false ): int {
		global $wpdb;

		$scope = get_option( 'nlh_scan_scope', array() );
		if ( empty( $scope['comments'] ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return 0;
		}

		$batch_size = 20;
		$last_id    = (int) get_option( 'nlh_comment_scan_last_id', 0 );

		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_content FROM {$wpdb->comments}
				 WHERE comment_approved = '1' AND comment_ID > %d
				 ORDER BY comment_ID ASC LIMIT %d",
				$last_id,
				$batch_size
			)
		);

		if ( empty( $comments ) ) {
			// Reached the end of the table — restart the cursor next tick.
			update_option( 'nlh_comment_scan_last_id', 0, false );
			return 0;
		}

		$ignored = get_option( 'nlh_ignored_urls', array() );
		if ( ! is_array( $ignored ) ) {
			$ignored = array();
		}
		$counters = array(
			'checked'       => 0,
			'broken'        => 0,
			'skipped_valid' => 0,
			'retries'       => 0,
		);
		$scanned  = 0;

		foreach ( $comments as $comment ) {
			$comment_id       = (int) $comment->comment_ID;
			$idn_unverifiable = array();
			$urls             = $this->extract_urls( (string) $comment->comment_content, $idn_unverifiable );
			$checkable        = $idn_unverifiable;

			foreach ( $urls as $url ) {
				if ( isset( $url[0] ) && '#' === $url[0] ) {
					continue;
				}
				$checkable[] = $url;
				$this->check_and_record_url( $url, $comment_id, 'comment', $force, $ignored, null, $counters );
			}

			$this->record_idn_unverifiable( $idn_unverifiable, $comment_id, 'comment', $counters );

			// Heal records for URLs the author removed from this comment (the IDN
			// rows above are kept because they are seeded into $checkable).
			$this->delete_stale_records( $comment_id, $checkable, 'comment' );

			update_option( 'nlh_comment_scan_last_id', $comment_id, false );
			++$scanned;
		}

		return $scanned;
	}

	/**
	 * Scans navigation menu item URLs for broken external links. Internal links
	 * (resolvable to a post) are validated by resolution rather than HTTP. Records
	 * use post_id 0 with source_type 'menu'.
	 *
	 * @param bool $force Skip caches and the confirmation gate.
	 * @return int Number of menu URLs examined.
	 */
	public function scan_nav_menus( bool $force = false ): int {
		$scope = get_option( 'nlh_scan_scope', array() );
		if ( empty( $scope['menus'] ) ) {
			return 0;
		}

		$menus = wp_get_nav_menus();
		if ( empty( $menus ) || is_wp_error( $menus ) ) {
			return 0;
		}

		$ignored = get_option( 'nlh_ignored_urls', array() );
		if ( ! is_array( $ignored ) ) {
			$ignored = array();
		}
		$counters         = array(
			'checked'       => 0,
			'broken'        => 0,
			'skipped_valid' => 0,
			'retries'       => 0,
		);
		$checkable        = array();
		$idn_unverifiable = array();
		$examined         = 0;

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( empty( $items ) || is_wp_error( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$url = isset( $item->url ) ? trim( (string) $item->url ) : '';
				if ( '' === $url || '#' === $url || 0 !== strpos( $url, 'http' ) ) {
					continue;
				}

				++$examined;

				// Internal links resolve to a post; a missing target is a content
				// concern handled elsewhere, not a broken external link.
				if ( url_to_postid( $url ) > 0 ) {
					continue;
				}

				// Hash on the same raw string check_and_record_url() records, so the
				// stale-cleanup below matches stored rows.
				$checkable[] = $url;

				// A menu URL with an unconvertible IDN host can't be probed; surface
				// it like content links instead of letting it fail as a generic error.
				if ( $this->is_unconvertible_idn_url( $url ) ) {
					$idn_unverifiable[] = $url;
					continue;
				}

				$this->check_and_record_url( $url, 0, 'menu', $force, $ignored, null, $counters );
			}
		}

		$this->record_idn_unverifiable( array_values( array_unique( $idn_unverifiable ) ), 0, 'menu', $counters );

		// Heal records for menu URLs that no longer exist (item edited/removed). An
		// empty set clears every remaining menu record.
		$this->delete_stale_records( 0, array_values( array_unique( $checkable ) ), 'menu' );

		return $examined;
	}

	/**
	 * Returns grouped broken-link records for dashboard views.
	 *
	 * @param string $group_by Grouping mode.
	 * @param int    $paged Page number.
	 * @param int    $per_page Groups per page.
	 * @return array
	 */
	public function get_grouped_errors( string $group_by, int $paged, int $per_page ): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'nlh_link_errors';
		$paged  = max( 1, $paged );
		$offset = ( $paged - 1 ) * $per_page;
		$groups = array();

		if ( 'domain' === $group_by ) {
			$rows = $wpdb->get_results( "SELECT id, post_id, source_type, raw_url, url_hash, status_code, error_message, impact_score, discovered_at, last_checked_at FROM {$table} ORDER BY impact_score DESC, last_checked_at DESC LIMIT 1000" );
			$map  = array();

			foreach ( (array) $rows as $row ) {
				$domain = $this->normalize_domain( (string) $row->raw_url );

				if ( '' === $domain ) {
					$domain = __( 'Unknown domain', 'native-link-health' );
				}

				if ( ! isset( $map[ $domain ] ) ) {
					$map[ $domain ] = array(
						'group_key' => $domain,
						'count'     => 0,
						'items'     => array(),
					);
				}

				++$map[ $domain ]['count'];

				if ( count( $map[ $domain ]['items'] ) < 50 ) {
					$map[ $domain ]['items'][] = $row;
				}
			}

			$groups = array_values( $map );
			usort(
				$groups,
				static function ( $a, $b ) {
					return $b['count'] <=> $a['count'];
				}
			);
		} elseif ( 'error_type' === $group_by ) {
			// Grouped in PHP (not SQL) so the bucket reflects the real failure
			// mode — DNS, SSL, timeout/connection, missing anchors — instead of
			// the old status-code-only CASE that mislabeled everything < 400 as
			// "timeout".
			$rows   = $wpdb->get_results( "SELECT id, post_id, source_type, raw_url, url_hash, status_code, error_message, impact_score, discovered_at, last_checked_at FROM {$table} ORDER BY impact_score DESC, last_checked_at DESC LIMIT 1000" );
			$labels = $this->get_error_type_labels();
			$map    = array();

			foreach ( (array) $rows as $row ) {
				$type = $this->classify_error_type( (int) $row->status_code, (string) $row->error_message );
				$key  = $labels[ $type ] ?? $type;

				if ( ! isset( $map[ $key ] ) ) {
					$map[ $key ] = array(
						'group_key' => $key,
						'count'     => 0,
						'items'     => array(),
					);
				}

				++$map[ $key ]['count'];

				if ( count( $map[ $key ]['items'] ) < 50 ) {
					$map[ $key ]['items'][] = $row;
				}
			}

			$groups = array_values( $map );
			usort(
				$groups,
				static function ( $a, $b ) {
					return $b['count'] <=> $a['count'];
				}
			);
		} elseif ( 'post' === $group_by ) {
			// Group on (post_id, source_type): for comment records post_id holds the
			// comment id, so grouping on post_id alone would mix a comment's records
			// into an unrelated post's group and label them with that post's title.
			$group_rows = $wpdb->get_results(
				"SELECT post_id AS group_key, source_type, COUNT(*) AS count, MAX(discovered_at) AS latest
				FROM {$table}
				GROUP BY post_id, source_type
				ORDER BY count DESC"
			);

			foreach ( (array) $group_rows as $group_row ) {
				$source_type = (string) $group_row->source_type;
				$items       = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, post_id, source_type, raw_url, url_hash, status_code, error_message, impact_score, discovered_at, last_checked_at
						FROM {$table}
						WHERE post_id = %d AND source_type = %s
						ORDER BY impact_score DESC, last_checked_at DESC
						LIMIT 50",
						(int) $group_row->group_key,
						$source_type
					)
				);

				$groups[] = array(
					'group_key' => $this->get_source_group_label( (int) $group_row->group_key, $source_type ),
					'count'     => (int) $group_row->count,
					'items'     => is_array( $items ) ? $items : array(),
				);
			}
		}

		$total_groups = count( $groups );

		return array(
			'groups'      => array_slice( $groups, $offset, $per_page ),
			'total'       => $total_groups,
			'total_pages' => max( 1, (int) ceil( $total_groups / $per_page ) ),
		);
	}

	/**
	 * Builds a human-readable group/row label for a record owner, aware that
	 * comment records store the comment id in post_id and menu records use 0.
	 *
	 * @since 1.5.1
	 * @param int    $post_id     Record post_id column (comment id for comments, 0 for menus).
	 * @param string $source_type 'post' | 'comment' | 'menu'.
	 * @return string
	 */
	public function get_source_group_label( int $post_id, string $source_type ): string {
		if ( 'menu' === $source_type ) {
			return __( 'Navigation menu', 'native-link-health' );
		}

		if ( 'comment' === $source_type ) {
			$comment      = get_comment( $post_id );
			$parent_title = $comment ? get_the_title( (int) $comment->comment_post_ID ) : '';

			return $parent_title
				/* translators: %s: post title. */
				? sprintf( __( 'Comment on “%s”', 'native-link-health' ), $parent_title )
				: __( '(comment)', 'native-link-health' );
		}

		$title = get_the_title( $post_id );

		/* translators: %d: post ID. */
		return $title ? $title : sprintf( __( 'Post #%d', 'native-link-health' ), $post_id );
	}

	/**
	 * Creates correction suggestions from repeated broken-link patterns.
	 *
	 * @return array
	 */
	public function suggest_corrections(): array {
		$cached = get_transient( 'nlh_suggestions' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table       = $wpdb->prefix . 'nlh_link_errors';
		$urls        = $wpdb->get_col( "SELECT DISTINCT raw_url FROM {$table}" );
		$domains     = array();
		$path_groups = array();

		foreach ( (array) $urls as $url ) {
			$url    = (string) $url;
			$domain = $this->normalize_domain( $url );

			if ( '' !== $domain ) {
				$domains[ $domain ][] = $url;
			}

			$path_prefix = $this->get_path_prefix( $url );

			if ( '' !== $path_prefix ) {
				$path_groups[ $path_prefix ][] = $url;
			}
		}

		$suggestions = array();

		foreach ( $domains as $domain => $domain_urls ) {
			if ( count( $domain_urls ) >= 5 ) {
				$suggestions[] = array(
					'type'    => 'domain_death',
					'pattern' => $domain,
					'count'   => count( $domain_urls ),
					'urls'    => array_slice( $domain_urls, 0, 20 ),
					/* translators: %s: domain name. */
					'label'   => sprintf( __( 'Multiple broken links point to %s.', 'native-link-health' ), $domain ),
				);
			}
		}

		foreach ( $path_groups as $prefix => $prefix_urls ) {
			if ( count( $prefix_urls ) >= 3 ) {
				$suggestions[] = array(
					'type'    => 'path_pattern',
					'pattern' => $prefix,
					'count'   => count( $prefix_urls ),
					'urls'    => array_slice( $prefix_urls, 0, 20 ),
					/* translators: %s: URL path prefix. */
					'label'   => sprintf( __( 'Repeated broken path pattern: %s.', 'native-link-health' ), $prefix ),
				);
			}
		}

		set_transient( 'nlh_suggestions', $suggestions, HOUR_IN_SECONDS );

		return $suggestions;
	}

	/**
	 * Records a link lifecycle event.
	 *
	 * @param string $url_hash URL hash.
	 * @param int    $post_id Post ID.
	 * @param string $event_type Event type.
	 * @param int    $status_code HTTP status code.
	 * @return void
	 */
	public function record_link_event( string $url_hash, int $post_id, string $event_type, int $status_code = 0 ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'nlh_link_events',
			array(
				'url_hash'    => $url_hash,
				'post_id'     => $post_id,
				'event_type'  => sanitize_key( $event_type ),
				'status_code' => $status_code ?: null,
				'event_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Returns posts for the recurring batch, prioritizing never-scanned content.
	 *
	 * Never-scanned posts come first, ordered by most-recently-modified so fresh
	 * and just-edited content (the most likely to contain a new broken link, and
	 * the content a site owner cares about first) reaches "first value" fastest.
	 * Any remaining slots are filled with the stalest already-scanned posts so the
	 * whole library keeps rotating through re-checks.
	 *
	 * @return WP_Post[]
	 */
	private function get_batch_posts(): array {
		$batch_size = $this->get_batch_size();
		$post_types = $this->get_scan_post_types();
		$statuses   = $this->get_scan_post_statuses( $post_types );

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'posts_per_page' => $batch_size,
				'post_status'    => $statuses,
				'meta_query'     => array(
					array(
						'key'     => '_nlh_last_scan',
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		if ( count( $posts ) >= $batch_size ) {
			return $posts;
		}

		$remaining = $batch_size - count( $posts );
		$scanned   = get_posts(
			array(
				'post_type'      => $post_types,
				'posts_per_page' => $remaining,
				'meta_key'       => '_nlh_last_scan',
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'post_status'    => $statuses,
			)
		);

		return array_merge( $posts, $scanned );
	}

	/**
	 * Re-checks one URL immediately.
	 *
	 * @param string $url URL to check.
	 * @param int    $post_id Post ID.
	 * @param int    $record_id Error record ID.
	 * @return array
	 */
	public function recheck_url( string $url, int $post_id, int $record_id = 0 ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'nlh_link_errors';
		$url      = esc_url_raw( $url );
		$url_hash = md5( $url );

		// Re-check is post-only (the dashboard hides it for comment/menu rows), but
		// key state options through state_key_suffix() so they stay identical to the
		// batch scanner's keys — future-proofing against a comment/menu re-check.
		$state_suffix = $this->state_key_suffix( 'post', $post_id );

		// Apply the same safety gate as the batch scanner: reject non-http(s)
		// schemes, the site's own root, and localhost/private hosts (SSRF).
		if ( ! $this->is_scannable_url( $url ) ) {
			return array(
				'status'      => 'error',
				'status_code' => 0,
				'error'       => __( 'This URL cannot be checked (blocked, internal system endpoint, or invalid).', 'native-link-health' ),
			);
		}

		try {
			$response = $this->head_request( $url );
		} catch ( \Throwable $e ) {
			$response = new WP_Error( 'http_request_failed', $e->getMessage() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );

		// Mirror the batch scanner: 429, configured "soft" codes (e.g. LinkedIn 999,
		// or 401/403 bot-blocks) and Cloudflare-style challenges mean "could not
		// verify", not "broken". Without this, a manual re-check would wrongly flag.
		if ( 429 === $code || in_array( $code, $this->get_soft_status_codes(), true ) || $this->is_bot_challenge_response( $response, $code ) ) {
			set_transient( 'nlh_retry_' . $url_hash . '_' . $state_suffix, true, HOUR_IN_SECONDS );

			if ( $record_id > 0 ) {
				// Refresh the stale (now factually wrong) status/message on the
				// record to the real "could not verify" state so the dashboard
				// stops implying an old hard error, and stamp the unverifiable
				// streak start (preserved, not bumped, so its age is meaningful).
				$soft_message = __( 'Could not verify (anti-bot challenge or rate limit).', 'native-link-health' );
				$wpdb->update(
					$table,
					array(
						'status_code'     => $code ?: 0,
						'error_message'   => sanitize_text_field( $soft_message ),
						'last_checked_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $record_id ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
				$this->mark_unverified_since( $url_hash, $state_suffix );
			}

			return array(
				'status'      => 'rate_limited',
				'status_code' => $code,
				'error'       => __( 'Could not verify (rate limited or bot-blocked); retry scheduled.', 'native-link-health' ),
			);
		}

		if ( is_wp_error( $response ) || $code >= 400 || $code < 100 ) {
			$error = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;

			$discovered_at = current_time( 'mysql', true );

			if ( $record_id > 0 ) {
				$existing_discovered_at = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT discovered_at FROM {$table} WHERE id = %d AND post_id = %d AND url_hash = %s",
						$record_id,
						$post_id,
						$url_hash
					)
				);

				if ( $existing_discovered_at ) {
					$discovered_at = $existing_discovered_at;
				}
			}

			$impact_score    = $this->calculate_impact_score( $post_id );
			$replace_data    = array(
				'post_id'         => $post_id,
				'raw_url'         => $url,
				'url_hash'        => $url_hash,
				'status_code'     => $code ?: 0,
				'error_message'   => sanitize_text_field( $error ),
				'impact_score'    => $impact_score,
				'discovered_at'   => $discovered_at,
				'last_checked_at' => current_time( 'mysql', true ),
			);
			$replace_formats = array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );

			if ( $record_id > 0 ) {
				$replace_data    = array_merge( array( 'id' => $record_id ), $replace_data );
				$replace_formats = array_merge( array( '%d' ), $replace_formats );
			}

			$event_type = false === get_option( 'nlh_last_ok_' . $url_hash . '_' . $state_suffix, false ) ? 'broken' : 'regression';
			delete_option( 'nlh_last_ok_' . $url_hash . '_' . $state_suffix );
			delete_option( 'nlh_last_soft_' . $url_hash . '_' . $state_suffix );
			$this->record_link_event( $url_hash, $post_id, $event_type, $code ?: 0 );
			$wpdb->replace( $table, $replace_data, $replace_formats );
			$saved_record_id = $record_id > 0 ? $record_id : (int) $wpdb->insert_id;

			return array(
				'status'      => 'broken',
				'status_code' => $code ?: 0,
				'error'       => sanitize_text_field( $error ),
				'error_type'  => $this->classify_error_type( $code ?: 0, $error ),
				'record_id'   => $saved_record_id,
				'url'         => $url,
			);
		}

		set_transient( 'nlh_ok_' . $url_hash, true, 2 * DAY_IN_SECONDS );
		update_option( 'nlh_last_ok_' . $url_hash . '_' . $state_suffix, time(), false );
		delete_option( 'nlh_last_soft_' . $url_hash . '_' . $state_suffix );
		$this->record_link_event( $url_hash, $post_id, 'fixed', $code );

		$this->delete_error_record( $record_id, $post_id, $url_hash );

		return array(
			'status'      => 'ok',
			'status_code' => $code,
			'error'       => '',
			'record_id'   => 0,
			'url'         => $url,
		);
	}

	/**
	 * Updates one href in post content.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $old_url URL to replace.
	 * @param string $new_url Replacement URL.
	 * @return bool
	 */
	public function update_post_link( int $post_id, string $old_url, string $new_url ): bool {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return false;
		}

		if ( $old_url === $new_url ) {
			return true;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		$processor = new WP_HTML_Tag_Processor( $post->post_content );
		$updated   = false;

		while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
			if ( $processor->get_attribute( 'href' ) === $old_url ) {
				$processor->set_attribute( 'href', $new_url );
				$updated = true;
			}
		}

		if ( $updated ) {
			// Temporarily unhook handle_post_saved to avoid redundant re-scan.
			remove_action( 'save_post', array( $this, 'handle_post_saved' ), 10, 3 );
			try {
				$result = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $processor->get_updated_html(),
					),
					true
				);
			} finally {
				add_action( 'save_post', array( $this, 'handle_post_saved' ), 10, 3 );
			}

			return ! is_wp_error( $result );
		}

		return false;
	}

	/**
	 * Deletes one error record.
	 *
	 * @param int    $record_id Error record ID.
	 * @param int    $post_id Post ID.
	 * @param string $url_hash URL MD5 hash.
	 * @return void
	 */
	public function delete_error_record( int $record_id, int $post_id = 0, string $url_hash = '' ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'nlh_link_errors';

		if ( $record_id > 0 ) {
			$wpdb->delete( $table, array( 'id' => $record_id ), array( '%d' ) );
			return;
		}

		if ( $post_id > 0 && '' !== $url_hash ) {
			$wpdb->delete(
				$table,
				array(
					'post_id'  => $post_id,
					'url_hash' => $url_hash,
				),
				array( '%d', '%s' )
			);
		}
	}

	/**
	 * Clears OK and retry caches for one URL.
	 *
	 * @param string $url     URL whose scanner cache should be cleared.
	 * @param int    $post_id Post ID for last_ok option cleanup.
	 * @return void
	 */
	public function clear_url_cache( string $url, int $post_id = 0 ): void {
		global $wpdb;

		$url_hash = md5( esc_url_raw( $url ) );

		delete_transient( 'nlh_ok_' . $url_hash );
		delete_transient( 'nlh_fail_' . $url_hash . '_' . $post_id );

		$retry_prefix         = $wpdb->esc_like( '_transient_nlh_retry_' . $url_hash . '_' ) . '%';
		$retry_timeout_prefix = $wpdb->esc_like( '_transient_timeout_nlh_retry_' . $url_hash . '_' ) . '%';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$retry_prefix,
				$retry_timeout_prefix
			)
		);

		$last_ok_option = 'nlh_last_ok_' . $url_hash . '_' . $post_id;
		delete_option( $last_ok_option );
		delete_option( 'nlh_last_soft_' . $url_hash . '_' . $post_id );
	}

	/**
	 * Extracts valid absolute links from post content.
	 *
	 * @param string   $content           Post content.
	 * @param string[] $idn_unverifiable  By-ref accumulator for IDN hostnames that could not be converted to ASCII.
	 * @return string[]
	 */
	private function extract_urls( string $content, array &$idn_unverifiable = array() ): array {
		$processor = new WP_HTML_Tag_Processor( $content );
		$urls      = array();
		$charset   = get_bloginfo( 'charset' );

		$tag_map = array(
			'A'      => 'href',
			'LINK'   => 'href',
			'IMG'    => 'src',
			'SCRIPT' => 'src',
			'SOURCE' => 'src',
			'VIDEO'  => 'src',
			'AUDIO'  => 'src',
			'IFRAME' => 'src',
			'OBJECT' => 'data',
			'EMBED'  => 'src',
			'AREA'   => 'href',
		);

		// Lazy-load plugins defer the real resource URL into data-* attributes,
		// leaving src/srcset empty or set to a placeholder. Without these the
		// real (and possibly broken) media URLs would never be checked.
		$lazy_attrs    = array( 'data-src', 'data-lazy-src', 'data-original' );
		$lazy_loadable = array( 'IMG', 'SOURCE', 'IFRAME', 'VIDEO' );

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();

			// Responsive images expose their real candidates via srcset, not src
			// (including the lazy-loaded data-srcset variant).
			if ( 'IMG' === $tag || 'SOURCE' === $tag ) {
				foreach ( array( 'srcset', 'data-srcset' ) as $srcset_attr ) {
					$srcset = $processor->get_attribute( $srcset_attr );
					if ( is_string( $srcset ) && '' !== $srcset ) {
						foreach ( $this->parse_srcset( $srcset ) as $candidate ) {
							$this->collect_url( $candidate, $urls, $charset, $idn_unverifiable );
						}
					}
				}
			}

			// Video poster frames are real images that can rot like any other.
			if ( 'VIDEO' === $tag ) {
				$this->collect_url( $processor->get_attribute( 'poster' ), $urls, $charset, $idn_unverifiable );
			}

			if ( in_array( $tag, $lazy_loadable, true ) ) {
				foreach ( $lazy_attrs as $lazy_attr ) {
					$this->collect_url( $processor->get_attribute( $lazy_attr ), $urls, $charset, $idn_unverifiable );
				}
			}

			$attr = $tag_map[ $tag ] ?? null;
			if ( null === $attr ) {
				continue;
			}
			$this->collect_url( $processor->get_attribute( $attr ), $urls, $charset, $idn_unverifiable );
		}

		// Bare URLs in text content. Strip code/pre/script/style blocks and HTML
		// comments first so documentation examples are not treated as live links.
		$text = preg_replace( '#<(script|style|code|pre)\b[^>]*>.*?</\1>#is', ' ', $content );
		$text = preg_replace( '#<!--.*?-->#s', ' ', (string) $text );

		preg_match_all( '/https?:\/\/[^\s<>"\'`\]]++/i', (string) $text, $matches );
		foreach ( $matches[0] as $raw ) {
			$raw = trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, $charset ) );
			$raw = $this->trim_url_punctuation( $raw );
			if ( '' === $raw || 0 === strpos( $raw, 'http://www.w3.org/2000/svg' ) ) {
				continue;
			}
			if ( $this->is_scannable_url( $raw ) ) {
				$urls[] = esc_url_raw( $raw );
			} elseif ( $this->is_unconvertible_idn_url( $raw ) ) {
				$idn_unverifiable[] = $raw;
			}
		}

		$idn_unverifiable = array_values( array_unique( $idn_unverifiable ) );

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Normalizes one extracted attribute value and appends it to the URL list.
	 *
	 * @param mixed    $val              Raw attribute value.
	 * @param string[] $urls             URL accumulator (by reference).
	 * @param string   $charset          Site charset for entity decoding.
	 * @param string[] $idn_unverifiable Accumulator for URLs rejected only because
	 *                                   their IDN host cannot be converted to ASCII.
	 * @return void
	 */
	private function collect_url( $val, array &$urls, string $charset, array &$idn_unverifiable ): void {
		if ( ! is_string( $val ) ) {
			return;
		}

		$val = trim( html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, $charset ) );

		if ( '' === $val ) {
			return;
		}

		if ( '#' === $val[0] ) {
			$urls[] = $val;
			return;
		}

		if ( $this->is_relative_url( $val ) ) {
			$val = $this->resolve_relative_url( $val );
		} elseif ( 0 === strpos( $val, '//' ) ) {
			// Protocol-relative URL ("//host/path"): resolve the scheme here so
			// the same absolute value is used for both the scannability check
			// and the stored/requested URL below — wp_remote_head() rejects a
			// schemeless URL outright.
			$val = ( is_ssl() ? 'https:' : 'http:' ) . $val;
		}

		if ( isset( $val[0] ) && '#' === $val[0] ) {
			$urls[] = $val;
		} elseif ( $this->is_scannable_url( $val ) ) {
			$urls[] = esc_url_raw( $val );
		} elseif ( $this->is_unconvertible_idn_url( $val ) ) {
			$idn_unverifiable[] = $val;
		}
	}

	/**
	 * Splits a srcset attribute into its candidate URLs.
	 *
	 * @param string $srcset Raw srcset attribute value.
	 * @return string[]
	 */
	private function parse_srcset( string $srcset ): array {
		$urls = array();

		foreach ( explode( ',', $srcset ) as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			$candidate = preg_split( '/\s+/', $part )[0] ?? '';

			if ( '' !== $candidate ) {
				$urls[] = $candidate;
			}
		}

		return $urls;
	}

	/**
	 * Trims trailing sentence punctuation and unbalanced parentheses from a
	 * bare URL captured in text content (e.g. "see https://example.com." or
	 * "https://en.wikipedia.org/wiki/Foo_(bar)").
	 *
	 * @param string $url Raw matched URL.
	 * @return string
	 */
	private function trim_url_punctuation( string $url ): string {
		$url = rtrim( $url, ".,;:!?'\"" );

		// Drop a trailing ")" only when it has no matching "(" in the URL.
		while ( '' !== $url && ')' === substr( $url, -1 ) && substr_count( $url, '(' ) < substr_count( $url, ')' ) ) {
			$url = rtrim( substr( $url, 0, -1 ), ".,;:!?'\"" );
		}

		return $url;
	}

	/**
	 * Checks whether a URL-like href should be scanned.
	 *
	 * WordPress content can contain IRIs, such as paths with accented
	 * characters. PHP's FILTER_VALIDATE_URL rejects those, so validation uses
	 * an ASCII request form while the original href remains the stored value.
	 *
	 * @param mixed $url Raw href value.
	 * @return bool
	 */
	private function is_scannable_url( $url ): bool {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		// Skip the site's own home/root URL: checking it is pointless and risks a
		// self-request loop. Genuine internal content links (e.g. /old-page/) ARE
		// still checked so broken internal links surface.
		$url_no_frag  = explode( '#', $url, 2 )[0];
		$home_no_frag = explode( '#', home_url( '/' ), 2 )[0];
		if ( untrailingslashit( $url_no_frag ) === untrailingslashit( $home_no_frag ) ) {
			return false;
		}

		$site_host = strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $site_host && $url_host && $site_host === $url_host ) {
			/**
			 * Filters whether internal links (same host as the site) are scanned.
			 *
			 * Loopback HTTP requests are needed to detect broken internal links.
			 * Return false to disable them on hosts where loopback is unreliable.
			 *
			 * @since 1.0.2
			 * @param bool $scan_internal Whether to scan internal links.
			 */
			if ( ! apply_filters( 'nlh_scan_internal_links', true ) ) {
				return false;
			}

			// Never request WordPress system endpoints: they are auth-protected
			// and/or can trigger request loops.
			$url_path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
			foreach ( array( '/wp-admin', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-json' ) as $system_path ) {
				if ( 0 === strpos( $url_path, $system_path ) ) {
					return false;
				}
			}
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return false;
		}

		// Block requests to localhost and private IPs (SSRF protection).
		$url_host = strtolower( $parts['host'] );
		if ( in_array( $url_host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return false;
		}
		if ( filter_var( $url_host, FILTER_VALIDATE_IP ) ) {
			// Check private/reserved ranges: 10.x.x.x, 172.16-31.x.x, 192.168.x.x.
			if ( ! filter_var( $url_host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		// An IRI host we cannot reliably convert to ASCII (no intl extension, or
		// an unconvertible IDN) must NOT be guessed at: stripping its non-ASCII
		// bytes would silently rewrite it to a different real domain and produce
		// a false result. Skip it instead.
		if ( null === $this->idn_host_to_ascii( (string) $parts['host'] ) ) {
			return false;
		}

		$prepared = $this->prepare_url_for_request( $url );
		if ( false !== filter_var( $prepared, FILTER_VALIDATE_URL ) ) {
			return true;
		}
		// FILTER_VALIDATE_URL rejects percent-encoded hostnames
		// (e.g. "https://iranin%C3%BAnlado.usa"), but cURL and
		// wp_safe_remote_head handle them correctly per RFC 3986.
		$prepared_parts = wp_parse_url( $prepared );
		return ! empty( $prepared_parts['scheme'] ) && ! empty( $prepared_parts['host'] );
	}

	/**
	 * Converts a host to ASCII (punycode) for IDN/IRI hostnames.
	 *
	 * @param string $host Host component, possibly containing non-ASCII bytes.
	 * @return string|null ASCII host, the original host if already ASCII, or
	 *                     null when it contains non-ASCII bytes that cannot be
	 *                     reliably converted.
	 */
	private function idn_host_to_ascii( string $host ): ?string {
		if ( '' === $host || ! preg_match( '/[^\x00-\x7F]/', $host ) ) {
			return $host;
		}

		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );

			if ( false !== $ascii && '' !== $ascii ) {
				return $ascii;
			}
		}

		return null;
	}

	/**
	 * Whether a URL's only disqualifier is an IDN/IRI host that cannot be
	 * converted to ASCII. Such links pass every other is_scannable_url() gate but
	 * cannot be probed over HTTP, so they are surfaced in the dashboard (with the
	 * IDN_UNVERIFIABLE_MESSAGE) instead of being silently dropped.
	 *
	 * @since 1.3.2
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_unconvertible_idn_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		return null === $this->idn_host_to_ascii( (string) $parts['host'] );
	}

	/**
	 * Records links whose IDN host could not be converted to ASCII.
	 *
	 * These have no HTTP state, so they bypass the confirmation gate and the soft/
	 * ok caches entirely. There is also nothing to self-heal: the record persists
	 * until the link is fixed or removed from the source (handled by the caller's
	 * delete_stale_records() pass, which must keep these URLs in its "current" set).
	 * discovered_at is preserved across rescans so the dashboard age stays accurate.
	 *
	 * @since 1.3.2
	 * @param string[] $urls        URLs with unconvertible IDN hosts.
	 * @param int      $post_id     Owning post (comment ID for comments, 0 for menus).
	 * @param string   $source_type 'post' | 'comment' | 'menu'.
	 * @param array    $counters    By-ref tally (broken count incremented for new rows).
	 * @return void
	 */
	private function record_idn_unverifiable( array $urls, int $post_id, string $source_type, array &$counters ): void {
		global $wpdb;

		if ( empty( $urls ) ) {
			return;
		}

		$table = $wpdb->prefix . 'nlh_link_errors';
		$now   = current_time( 'mysql', true );

		foreach ( array_unique( $urls ) as $url ) {
			$hash     = md5( $url );
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE url_hash = %s AND post_id = %d AND source_type = %s LIMIT 1",
					$hash,
					$post_id,
					$source_type
				)
			);

			if ( $existing > 0 ) {
				// Already on record — refresh the check timestamp only so the
				// preserved discovered_at keeps reflecting the first sighting.
				$wpdb->update(
					$table,
					array( 'last_checked_at' => $now ),
					array( 'id' => $existing ),
					array( '%s' ),
					array( '%d' )
				);
				continue;
			}

			if ( 'post' === $source_type ) {
				$impact = $this->calculate_impact_score( $post_id );
			} elseif ( 'menu' === $source_type ) {
				$impact = 50;
			} else {
				$impact = 0;
			}

			$wpdb->replace(
				$table,
				array(
					'post_id'         => $post_id,
					'raw_url'         => $url,
					'url_hash'        => $hash,
					'status_code'     => 0,
					'error_message'   => self::IDN_UNVERIFIABLE_MESSAGE,
					'source_type'     => $source_type,
					'impact_score'    => $impact,
					'discovered_at'   => $now,
					'last_checked_at' => $now,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
			);
			++$counters['broken'];
		}
	}

	/**
	 * Checks whether a URL is relative (no scheme/host).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function is_relative_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return false;
		}
		return false === wp_parse_url( $url, PHP_URL_SCHEME );
	}

	/**
	 * Resolves a relative URL against the site home URL.
	 *
	 * @param string $url Relative URL.
	 * @return string
	 */
	private function resolve_relative_url( string $url ): string {
		// Root-relative URLs ("/foo/") are relative to the domain origin and the
		// path already contains any subdirectory, so resolving against home_url()
		// (which includes the subdir) would double it on a subdirectory install
		// ("/wpprueba/x" -> ".../wpprueba/wpprueba/x") and produce a false 404.
		if ( 0 === strpos( $url, '/' ) ) {
			$parts  = (array) wp_parse_url( home_url() );
			$scheme = $parts['scheme'] ?? ( is_ssl() ? 'https' : 'http' );
			$host   = $parts['host'] ?? '';
			$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

			return $scheme . '://' . $host . $port . $url;
		}

		return home_url( $url );
	}

	/**
	 * Removes broken-link records for links no longer present in a post.
	 *
	 * @param int      $post_id     Post ID.
	 * @param string[] $urls        Current URLs found in post content.
	 * @param string   $source_type Source type (post|comment|menu).
	 * @return void
	 */
	private function delete_stale_records( int $post_id, array $urls, string $source_type = 'post' ): void {
		global $wpdb;

		$table        = $wpdb->prefix . 'nlh_link_errors';
		$state_suffix = $this->state_key_suffix( $source_type, $post_id );

		if ( empty( $urls ) ) {
			$stale_hashes = $wpdb->get_col(
				$wpdb->prepare( "SELECT url_hash FROM {$table} WHERE post_id = %d AND source_type = %s", $post_id, $source_type )
			);
			foreach ( $stale_hashes as $stale_hash ) {
				delete_option( 'nlh_last_ok_' . $stale_hash . '_' . $state_suffix );
				delete_option( 'nlh_last_soft_' . $stale_hash . '_' . $state_suffix );
			}
			$wpdb->delete(
				$table,
				array(
					'post_id'     => $post_id,
					'source_type' => $source_type,
				),
				array( '%d', '%s' )
			);
			return;
		}

		$hashes       = array_map( 'md5', $urls );
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		$params       = array_merge( array( $post_id, $source_type ), $hashes );

		$stale_hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT url_hash FROM {$table} WHERE post_id = %d AND source_type = %s AND url_hash NOT IN ({$placeholders})",
				$params
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND source_type = %s AND url_hash NOT IN ({$placeholders})",
				$params
			)
		);

		foreach ( $stale_hashes as $stale_hash ) {
			delete_option( 'nlh_last_ok_' . $stale_hash . '_' . $state_suffix );
			delete_option( 'nlh_last_soft_' . $stale_hash . '_' . $state_suffix );
		}
	}

	/**
	 * Updates scan metrics from actual database state.
	 *
	 * @param array $batch_metrics Metrics for this batch.
	 * @return void
	 */
	private function update_scan_metrics( array $batch_metrics ): void {
		global $wpdb;

		$table_errors = $wpdb->prefix . 'nlh_link_errors';
		$table_events = $wpdb->prefix . 'nlh_link_events';

		$metrics = get_option( 'nlh_scan_metrics', array() );

		if ( ! is_array( $metrics ) ) {
			$metrics = array();
		}

		// Broken links: exact current count from DB (reflects reality, never inflates).
		$metrics['total_broken_found'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_errors}" );

		// URLs checked: unique URLs ever recorded across errors + events.
		// Grows only when new URLs appear, never from re-scans.
		$metrics['total_urls_checked'] = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT url_hash) FROM (
				SELECT url_hash FROM {$table_errors}
				UNION
				SELECT url_hash FROM {$table_events}
			) AS unique_urls"
		);

		// Performance counters: cumulative across all scans.
		$metrics['total_skipped_valid'] = (int) ( $metrics['total_skipped_valid'] ?? 0 ) + (int) ( $batch_metrics['total_skipped_valid'] ?? 0 );
		$metrics['total_retries']       = (int) ( $metrics['total_retries'] ?? 0 ) + (int) ( $batch_metrics['total_retries'] ?? 0 );

		$metrics['last_batch_duration'] = (float) ( $batch_metrics['last_batch_duration'] ?? 0 );
		$metrics['peak_memory_usage']   = (int) ( $batch_metrics['peak_memory_usage'] ?? 0 );
		$metrics['last_updated']        = (int) ( $batch_metrics['last_updated'] ?? time() );

		update_option( 'nlh_scan_metrics', $metrics );
	}

	/**
	 * Sends the HEAD request used by scanner operations.
	 *
	 * @param string $url URL to check.
	 * @return array|WP_Error
	 */
	private function head_request( string $url ) {
		/**
		 * Filters the user-agent sent for link checking requests.
		 *
		 * A browser-like user-agent avoids false 403s from Google,
		 * Cloudflare, and other bot-detection systems.
		 *
		 * @since 1.0.1
		 * @param string $user_agent The HTTP User-Agent header value.
		 */
		$user_agent = apply_filters(
			'nlh_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
		);

		/**
		 * Filters the HTTP timeout (in seconds) for link-checking requests.
		 *
		 * Reduce to 8–10 on sites with many dead domains to speed up scans.
		 * The anti-false-positive gate (2 consecutive failures) keeps short
		 * timeouts from producing false positives on slow-but-valid servers.
		 *
		 * @since 1.3.1
		 * @param int $timeout Timeout in seconds. Default 10.
		 */
		$args = array(
			'timeout'     => apply_filters( 'nlh_http_timeout', 10 ),
			'redirection' => 5,
			'user-agent'  => $user_agent,
		);

		/**
		 * Filters the additional HTTP headers sent with link-checking requests.
		 *
		 * Some WAF/CDN-protected sites (e.g. w3.org, Cloudflare) check Accept and
		 * Accept-Language alongside the User-Agent and return a false 403 to
		 * requests that omit them. Sending browser-like headers on both the HEAD
		 * probe and its GET fallback avoids those false positives.
		 *
		 * @since 1.3.2
		 * @param array $headers Header name => value pairs.
		 */
		$args['headers'] = apply_filters(
			'nlh_http_headers',
			array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.5',
			)
		);

		$prepared_url = $this->prepare_url_for_request( $url );

		// Use non-safe HTTP functions because wp_safe_remote_head() rejects
		// non-resolvable hosts (e.g. defunct domains with broken DNS), which
		// are precisely the broken links we need to detect. The URL has
		// already passed is_scannable_url() which excludes self-referencing
		// URLs and non-http/https schemes.
		$response = wp_remote_head( $prepared_url, $args );

		// Fall back to GET when the server rejects or mishandles HEAD: a 405
		// (method not allowed), 501 (not implemented), or a 400/403 that some
		// servers/CDNs return only for HEAD bot probes, no usable status
		// (code < 100, e.g. a server that drops HEAD), or an outright error.
		// This avoids flagging links as broken when only HEAD is refused.
		$head_code = (int) wp_remote_retrieve_response_code( $response );
		if ( is_wp_error( $response ) || in_array( $head_code, array( 400, 403, 405, 501 ), true ) || $head_code < 100 ) {
			$response = wp_remote_get( $prepared_url, $args );
		}

		return $response;
	}

	/**
	 * Returns status codes treated as "could not verify" rather than broken.
	 *
	 * 999 is LinkedIn's non-standard bot-block code. Sites that want to stop
	 * flagging Cloudflare/Google 403s or auth-gated 401s can add them here:
	 * add_filter( 'nlh_soft_status_codes', fn() => array( 999, 403, 401 ) );
	 *
	 * @since 1.0.2
	 * @return int[]
	 */
	private function get_soft_status_codes(): array {
		/**
		 * Filters the HTTP status codes that should not be recorded as broken.
		 *
		 * @since 1.0.2
		 * @param int[] $codes Status codes to treat as soft (retry, not broken).
		 */
		$codes = apply_filters( 'nlh_soft_status_codes', array( 999 ) );

		return array_values( array_filter( array_map( 'intval', (array) $codes ) ) );
	}

	/**
	 * Detects an anti-bot challenge response (e.g. Cloudflare's "Just a moment…"
	 * interstitial). These are served as 403/503/429 to non-browser clients that
	 * cannot execute the challenge JavaScript, so they are a bot-block — NOT a
	 * broken link. The page is perfectly reachable in a real browser, which makes
	 * recording it a false positive. Treated like a soft status code: back off and
	 * retry rather than flag.
	 *
	 * Identified by Cloudflare's `Cf-Mitigated: challenge` header, or a
	 * `Server: cloudflare` response whose body carries a known challenge marker
	 * (the GET fallback in head_request() supplies the body, since HEAD has none).
	 *
	 * @since 1.3.2
	 * @param array|WP_Error $response The HTTP response.
	 * @param int            $code     Parsed status code.
	 * @return bool
	 */
	private function is_bot_challenge_response( $response, int $code ): bool {
		if ( is_wp_error( $response ) || ! in_array( $code, array( 403, 429, 503 ), true ) ) {
			return false;
		}

		$is_challenge = false;

		$mitigated = wp_remote_retrieve_header( $response, 'cf-mitigated' );
		if ( is_string( $mitigated ) && false !== stripos( $mitigated, 'challenge' ) ) {
			$is_challenge = true;
		}

		if ( ! $is_challenge ) {
			$server = wp_remote_retrieve_header( $response, 'server' );
			if ( is_string( $server ) && false !== stripos( $server, 'cloudflare' ) ) {
				$body    = (string) wp_remote_retrieve_body( $response );
				$markers = array( 'Just a moment', 'cf-browser-verification', 'challenge-platform', '__cf_chl', '/cdn-cgi/challenge-platform' );
				foreach ( $markers as $marker ) {
					if ( false !== stripos( $body, $marker ) ) {
						$is_challenge = true;
						break;
					}
				}
			}
		}

		/**
		 * Filters whether a response is treated as a bot-challenge (could-not-verify)
		 * rather than a broken link.
		 *
		 * @since 1.3.2
		 * @param bool           $is_challenge Whether the response looks like a challenge.
		 * @param array|WP_Error $response     The HTTP response.
		 * @param int            $code         Parsed status code.
		 */
		return (bool) apply_filters( 'nlh_is_bot_challenge', $is_challenge, $response, $code );
	}

	/**
	 * Number of consecutive failed probes required before a previously-unseen
	 * link is recorded as broken. Guards against transient network blips,
	 * momentarily slow servers, and flaky DNS producing false positives.
	 *
	 * @since 1.0.3
	 * @return int
	 */
	private function get_broken_confirm_threshold(): int {
		/**
		 * Filters how many consecutive failures confirm a new broken link.
		 *
		 * @since 1.0.3
		 * @param int $threshold Consecutive failures required (minimum 1).
		 */
		$threshold = (int) apply_filters( 'nlh_broken_confirm_threshold', 2 );

		return max( 1, $threshold );
	}

	/**
	 * Records when a broken record's "could not verify" (soft) streak began.
	 *
	 * Unlike the last-checked timestamp, this is written once at the start of the
	 * streak and left untouched by subsequent soft probes, so its age reflects how
	 * long the link has been unverifiable. It is cleared on the next hard ok/broken
	 * confirmation (see check_and_record_url / recheck_url), which restarts the
	 * streak on the next soft result.
	 *
	 * @since 1.3.3
	 * @param string $url_hash     MD5 of the URL.
	 * @param string $state_suffix Source-scoped key suffix (state_key_suffix()).
	 * @return void
	 */
	private function mark_unverified_since( string $url_hash, string $state_suffix ): void {
		$option = 'nlh_last_soft_' . $url_hash . '_' . $state_suffix;

		if ( false === get_option( $option, false ) ) {
			update_option( $option, time(), false );
		}
	}

	/**
	 * How long a record may stay unverifiable (soft) before it is excluded from
	 * the broken-link count and the Health Score.
	 *
	 * A link that has only ever returned soft results (Cloudflare challenge, 429,
	 * 999) for longer than this window is treated as "cannot be verified" rather
	 * than "broken", so a permanently bot-blocked site does not depress the score
	 * forever. The record is never auto-deleted — it still shows in the dashboard
	 * flagged as unverifiable.
	 *
	 * @since 1.3.3
	 * @return int Grace period in seconds (minimum 1 day).
	 */
	public function get_unverified_grace_period(): int {
		/**
		 * Filters the unverifiable grace period, in seconds.
		 *
		 * @since 1.3.3
		 * @param int $seconds Grace period before an unverifiable record is
		 *                     excluded from the broken count / health score.
		 */
		$seconds = (int) apply_filters( 'nlh_unverified_grace_period', 30 * DAY_IN_SECONDS );

		return max( DAY_IN_SECONDS, $seconds );
	}

	/**
	 * Number of posts the cron batch scans per cycle.
	 *
	 * Reads the admin-configurable nlh_scan_batch_size option, falling back to the
	 * NLH_BATCH_SIZE constant when unset or out of range (e.g. a stale stored 0).
	 *
	 * @since 1.3.3
	 * @return int Batch size (1..100).
	 */
	public function get_batch_size(): int {
		$size = (int) get_option( 'nlh_scan_batch_size', NLH_BATCH_SIZE );

		if ( $size < 1 || $size > 100 ) {
			$size = NLH_BATCH_SIZE;
		}

		return $size;
	}

	/**
	 * Classifies a failure into a meaningful error type.
	 *
	 * Replaces the old status-code-only bucketing, which lumped DNS, SSL,
	 * connection, timeout and missing-anchor failures together under "timeout".
	 *
	 * @since 1.0.3
	 * @param int    $status_code   HTTP status code (0 when there is none).
	 * @param string $error_message Stored error message.
	 * @return string One of: 5xx, 4xx, fragment, dns, ssl, timeout.
	 */
	public function classify_error_type( int $status_code, string $error_message = '' ): string {
		if ( $status_code >= 500 ) {
			return '5xx';
		}

		if ( $status_code >= 400 ) {
			return '4xx';
		}

		$msg = strtolower( $error_message );

		if ( false !== strpos( $msg, 'fragment' ) || false !== strpos( $msg, 'anchor' ) ) {
			return 'fragment';
		}

		// An IDN host that could not be converted to ASCII means the name can't be
		// resolved/probed — the closest semantic bucket is DNS.
		if ( 0 === strpos( $msg, 'idn:' ) ) {
			return 'dns';
		}

		if ( false !== strpos( $msg, 'ssl' ) || false !== strpos( $msg, 'certificate' ) ) {
			return 'ssl';
		}

		if (
			false !== strpos( $msg, 'resolve host' )
			|| false !== strpos( $msg, 'could not resolve' )
			|| false !== strpos( $msg, 'name or service not known' )
			|| false !== strpos( $msg, 'name resolution' )
		) {
			return 'dns';
		}

		// Timeouts plus any remaining transport-level failure (connection
		// refused/reset) fold into a single compact network bucket.
		return 'timeout';
	}

	/**
	 * Human-readable labels for the error-type groups.
	 *
	 * @since 1.0.3
	 * @return array<string,string>
	 */
	private function get_error_type_labels(): array {
		return array(
			'5xx'      => __( 'Server errors (5xx)', 'native-link-health' ),
			'4xx'      => __( 'Client errors (4xx)', 'native-link-health' ),
			'fragment' => __( 'Missing anchors', 'native-link-health' ),
			'dns'      => __( 'DNS failures', 'native-link-health' ),
			'ssl'      => __( 'SSL errors', 'native-link-health' ),
			'timeout'  => __( 'Timeouts & connection errors', 'native-link-health' ),
		);
	}

	/**
	 * Whether a URL is a deep link back into the same post (e.g. an absolute
	 * permalink ending in #section). Such links are validated against the
	 * post's own anchors rather than fetched over HTTP.
	 *
	 * @since 1.0.3
	 * @param string $url       Candidate URL.
	 * @param string $self_base The post permalink without its fragment, untrailingslashed.
	 * @return bool
	 */
	private function is_same_page_fragment( string $url, string $self_base ): bool {
		if ( '' === $self_base || '' === $url || '#' === $url[0] ) {
			return false;
		}

		$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );
		if ( empty( $fragment ) ) {
			return false;
		}

		return untrailingslashit( explode( '#', $url, 2 )[0] ) === $self_base;
	}

	/**
	 * Converts IRIs to an ASCII URL safe for WP HTTP requests.
	 *
	 * @param string $url Raw href URL.
	 * @return string
	 */
	private function prepare_url_for_request( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( isset( $parts['host'] ) && preg_match( '/[^\x00-\x7F]/', $parts['host'] ) ) {
			// Convert to punycode when possible. Callers that scan reach this
			// only for convertible hosts (is_scannable_url() rejects the rest);
			// if conversion is unavailable here the host is left intact and the
			// trailing percent-encoding pass handles it for the HTTP layer.
			$ascii = $this->idn_host_to_ascii( $parts['host'] );
			if ( null !== $ascii ) {
				$parts['host'] = $ascii;
			}
			$userinfo = '';
			if ( isset( $parts['user'] ) ) {
				$userinfo = $parts['user'] . ( isset( $parts['pass'] ) ? ':' . $parts['pass'] : '' ) . '@';
			}

			$url = $parts['scheme'] . '://' . $userinfo . $parts['host'] .
				( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) .
				( isset( $parts['path'] ) ? $parts['path'] : '' ) .
				( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) .
				( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		}
		// On malformed UTF-8 the /u flag makes preg_replace_callback return null;
		// fall back to the original URL so the request is not silently emptied
		// (which would surface as a false "broken" result).
		$encoded = preg_replace_callback( '/[^\x00-\x7F]/u', fn( $m ) => rawurlencode( $m[0] ), $url );

		return is_string( $encoded ) ? $encoded : $url;
	}

	/**
	 * Normalizes a URL host for grouping.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize_domain( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = strtolower( preg_replace( '/^www\./', '', $host ) );

		return $host;
	}

	/**
	 * Returns a domain plus two-level path prefix.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function get_path_prefix( string $url ): string {
		$domain = $this->normalize_domain( $url );
		$path   = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );

		if ( '' === $domain || '' === $path ) {
			return '';
		}

		$parts = array_slice( array_filter( explode( '/', $path ) ), 0, 2 );

		return $domain . '/' . implode( '/', $parts );
	}

	/**
	 * Validates URL fragments against id attributes in post content.
	 *
	 * @param string   $content Post content.
	 * @param string[] $urls    Extracted URLs.
	 * @param int      $post_id Post ID.
	 * @return string[] URLs whose fragments do not match any id.
	 */
	public function validate_fragments( string $content, array $urls, int $post_id ): array {
		$ids    = array();
		$broken = array();

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$processor = new WP_HTML_Tag_Processor( $content );
		while ( $processor->next_tag() ) {
			$id = $processor->get_attribute( 'id' );
			if ( is_string( $id ) && '' !== $id ) {
				$ids[] = strtolower( $id );
			}
		}

		// Also collect named anchors: <a name="fragment">.
		$proc2 = new WP_HTML_Tag_Processor( $content );
		while ( $proc2->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$name = $proc2->get_attribute( 'name' );
			if ( is_string( $name ) && '' !== $name ) {
				$ids[] = strtolower( $name );
			}
		}

		$ids = array_unique( $ids );

		// When a post has no *explicit* anchorable targets at all, many
		// themes/blocks generate heading IDs at render time that are absent from
		// the stored content. Flagging every fragment here would be a false
		// positive, so skip fragment validation for such posts entirely. This
		// guard must consider only explicit id/name anchors — heading slugs are
		// added afterwards so they never suppress this safety check.
		if ( empty( $ids ) ) {
			return array();
		}

		// Themes and table-of-contents plugins commonly generate heading anchors
		// at render time from the slugified heading text (e.g. "## My Section"
		// becomes id="my-section"). Those IDs are absent from the stored content,
		// so add each heading's slug as an additional valid target.
		if ( preg_match_all( '/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $content, $heading_matches ) ) {
			foreach ( $heading_matches[1] as $heading_html ) {
				$slug = sanitize_title( wp_strip_all_tags( $heading_html ) );
				if ( '' !== $slug ) {
					$ids[] = $slug;
				}
			}
		}

		$ids = array_unique( $ids );

		foreach ( $urls as $url ) {
			$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );
			if ( null === $fragment || '' === $fragment ) {
				continue;
			}

			$normalized = strtolower( rawurldecode( (string) $fragment ) );

			// Always-valid fragments: "#top"/"#" are browser defaults that scroll
			// to the document top, and #respond/#comments/#comment-NNN are
			// comment anchors WordPress renders dynamically (absent from stored
			// content). Treating these as broken would be a false positive.
			if ( in_array( $normalized, array( 'top', 'respond', 'comments' ), true ) || 0 === strpos( $normalized, 'comment-' ) ) {
				continue;
			}

			if ( ! in_array( $normalized, $ids, true ) ) {
				$broken[] = $url;
			}
		}

		return $broken;
	}
}

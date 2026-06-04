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
	 * Runs one fixed-size scanner batch.
	 *
	 * @return void
	 */
	public function run_batch(): void {
		$this->scan_posts( $this->get_batch_posts() );
	}

	/**
	 * Returns post types included in link scans.
	 *
	 * @return string[]
	 */
	public function get_scan_post_types(): array {
		$post_types = apply_filters( 'nlh_scan_post_types', array( 'post', 'page' ) );

		return array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
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

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'posts_per_page'         => $chunk_size,
				'offset'                 => $offset,
				'post_status'            => 'publish',
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

		if ( $update ) {
			delete_post_meta( $post_id, '_nlh_last_scan' );
			return;
		}

		delete_post_meta( $post_id, '_nlh_last_scan' );

		foreach ( $this->extract_urls( $post->post_content ) as $url ) {
			$this->clear_url_cache( $url, $post_id );
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

		$started_at             = microtime( true );
		$urls_checked_in_batch  = 0;
		$broken_found_in_batch  = 0;
		$skipped_valid_in_batch = 0;
		$retries_in_batch       = 0;
		$table                  = $wpdb->prefix . 'nlh_link_errors';
		$ignored                = get_option( 'nlh_ignored_urls', array() );
		$rules_engine           = class_exists( 'NLH_Rules_Engine' ) ? new NLH_Rules_Engine( $this ) : null;

		if ( ! is_array( $ignored ) ) {
			$ignored = array();
		}

		foreach ( $posts as $post ) {
			$urls = $this->extract_urls( $post->post_content );
			$this->delete_stale_records( $post->ID, $urls );

			foreach ( $urls as $url ) {
				if ( isset( $url[0] ) && '#' === $url[0] ) {
					continue;
				}

				if ( in_array( $url, $ignored, true ) ) {
					continue;
				}

				$url_hash        = md5( $url );
				$transient_ok    = 'nlh_ok_' . $url_hash;
				$transient_retry = 'nlh_retry_' . $url_hash . '_' . $post->ID;

				if ( ! $force && get_transient( $transient_ok ) ) {
					++$skipped_valid_in_batch;
					continue;
				}

				if ( ! $force && get_transient( $transient_retry ) ) {
					++$retries_in_batch;
					continue;
				}

				++$urls_checked_in_batch;
				try {
					$response = $this->head_request( $url );
				} catch ( \Throwable $e ) {
					$response = new WP_Error( 'http_request_failed', $e->getMessage() );
				}
				$code     = (int) wp_remote_retrieve_response_code( $response );

				if ( 429 === $code ) {
					++$retries_in_batch;
					set_transient( $transient_retry, true, HOUR_IN_SECONDS );
					continue;
				}

				if ( is_wp_error( $response ) || ( $code >= 400 ) ) {
					++$broken_found_in_batch;

					$error = is_wp_error( $response )
						? $response->get_error_message()
						: 'HTTP ' . $code;

					$existing_id = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$table} WHERE post_id = %d AND url_hash = %s LIMIT 1",
							$post->ID,
							$url_hash
						)
					);

					$last_ok_option = 'nlh_last_ok_' . $url_hash . '_' . $post->ID;
					$last_ok        = get_option( $last_ok_option, false );
					$event_type     = false === $last_ok ? 'broken' : 'regression';

					if ( false !== $last_ok ) {
						delete_option( $last_ok_option );
					}

					if ( 0 === $existing_id || 'regression' === $event_type ) {
						$this->record_link_event( $url_hash, (int) $post->ID, $event_type, $code ?: 0 );
					}

					// Prevent stale OK cache — re-check this URL sooner.
					delete_transient( 'nlh_ok_' . $url_hash );

					$impact_score = $this->calculate_impact_score( (int) $post->ID );

					// Preserve original discovery date if this URL was already tracked.
					$existing_discovered = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT discovered_at FROM {$table} WHERE post_id = %d AND url_hash = %s",
							$post->ID,
							$url_hash
						)
					);
					$discovered_at = $existing_discovered ?: current_time( 'mysql', true );

					$wpdb->replace(
						$table,
						array(
							'post_id'         => $post->ID,
							'raw_url'         => $url,
							'url_hash'        => $url_hash,
							'status_code'     => $code ?: 0,
							'error_message'   => sanitize_text_field( $error ),
							'impact_score'    => $impact_score,
							'discovered_at'   => $discovered_at,
							'last_checked_at' => current_time( 'mysql', true ),
						),
						array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
					);

					if ( $rules_engine ) {
						$action = $rules_engine->evaluate_rules( $url, (int) $post->ID, $code ?: 0, (string) $error );

						if ( $action ) {
							$rules_engine->apply_action( $action, $url, (int) $post->ID );
						}
					}
				} else {
					set_transient( $transient_ok, true, 2 * DAY_IN_SECONDS );
					update_option( 'nlh_last_ok_' . $url_hash . '_' . $post->ID, time(), false );

					$wpdb->delete(
						$table,
						array(
							'post_id'  => $post->ID,
							'url_hash' => $url_hash,
						),
						array( '%d', '%s' )
					);
				}
			}

			$fragment_urls    = array_values( array_filter( $urls, fn( $u ) => isset( $u[0] ) && '#' === $u[0] ) );
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
					++$broken_found_in_batch;
				}

				set_transient( $frag_transient, true, HOUR_IN_SECONDS );
			}

			update_post_meta( $post->ID, '_nlh_last_scan', time() );
		}

		$this->update_scan_metrics(
			array(
				'total_urls_checked'  => $urls_checked_in_batch,
				'total_broken_found'  => $broken_found_in_batch,
				'total_skipped_valid' => $skipped_valid_in_batch,
				'total_retries'       => $retries_in_batch,
				'last_batch_duration' => microtime( true ) - $started_at,
				'peak_memory_usage'   => memory_get_peak_usage( true ),
				'last_updated'        => time(),
			)
		);

		return count( $posts );
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
			$rows = $wpdb->get_results( "SELECT id, post_id, raw_url, url_hash, status_code, error_message, impact_score, discovered_at, last_checked_at FROM {$table} ORDER BY impact_score DESC, last_checked_at DESC LIMIT 1000" );
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
			$group_rows = $wpdb->get_results(
				"SELECT
					CASE WHEN status_code >= 500 THEN '5xx' WHEN status_code >= 400 THEN '4xx' ELSE 'timeout' END AS group_key,
					COUNT(*) AS count,
					MAX(discovered_at) AS latest
				FROM {$table}
				GROUP BY group_key
				ORDER BY count DESC"
			);

			foreach ( (array) $group_rows as $group_row ) {
				$items = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, post_id, raw_url, url_hash, status_code, error_message, impact_score, discovered_at, last_checked_at
						FROM {$table}
						WHERE CASE WHEN status_code >= 500 THEN '5xx' WHEN status_code >= 400 THEN '4xx' ELSE 'timeout' END = %s
						ORDER BY impact_score DESC, last_checked_at DESC
						LIMIT 50",
						$group_row->group_key
					)
				);

				$groups[] = array(
					'group_key' => $group_row->group_key,
					'count'     => (int) $group_row->count,
					'items'     => is_array( $items ) ? $items : array(),
				);
			}
		} elseif ( 'post' === $group_by ) {
			$group_rows = $wpdb->get_results(
				"SELECT post_id AS group_key, COUNT(*) AS count, MAX(discovered_at) AS latest
				FROM {$table}
				GROUP BY post_id
				ORDER BY count DESC"
			);

			foreach ( (array) $group_rows as $group_row ) {
				$items = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, post_id, raw_url, url_hash, status_code, error_message, impact_score, discovered_at, last_checked_at
						FROM {$table}
						WHERE post_id = %d
						ORDER BY impact_score DESC, last_checked_at DESC
						LIMIT 50",
						(int) $group_row->group_key
					)
				);

				$title    = get_the_title( (int) $group_row->group_key );
				$groups[] = array(
					'group_key' => $title ? $title : sprintf( __( 'Post #%d', 'native-link-health' ), (int) $group_row->group_key ),
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
	 * @return WP_Post[]
	 */
	private function get_batch_posts(): array {
		$posts = get_posts(
			array(
				'post_type'      => $this->get_scan_post_types(),
				'posts_per_page' => NLH_BATCH_SIZE,
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'     => '_nlh_last_scan',
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( count( $posts ) >= NLH_BATCH_SIZE ) {
			return $posts;
		}

		$remaining = NLH_BATCH_SIZE - count( $posts );
		$scanned   = get_posts(
			array(
				'post_type'      => $this->get_scan_post_types(),
				'posts_per_page' => $remaining,
				'meta_key'       => '_nlh_last_scan',
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'post_status'    => 'publish',
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
		try {
			$response = $this->head_request( $url );
		} catch ( \Throwable $e ) {
			$response = new WP_Error( 'http_request_failed', $e->getMessage() );
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			set_transient( 'nlh_retry_' . $url_hash . '_' . $post_id, true, HOUR_IN_SECONDS );

			return array(
				'status'      => 'rate_limited',
				'status_code' => 429,
				'error'       => __( 'Rate limited; retry scheduled.', 'native-link-health' ),
			);
		}

		if ( is_wp_error( $response ) || ( $code >= 400 ) ) {
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

			$impact_score = $this->calculate_impact_score( $post_id );
			$replace_data = array(
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

			$event_type = false === get_option( 'nlh_last_ok_' . $url_hash . '_' . $post_id, false ) ? 'broken' : 'regression';
			delete_option( 'nlh_last_ok_' . $url_hash . '_' . $post_id );
			$this->record_link_event( $url_hash, $post_id, $event_type, $code ?: 0 );
			$wpdb->replace( $table, $replace_data, $replace_formats );
			$saved_record_id = $record_id > 0 ? $record_id : (int) $wpdb->insert_id;

			return array(
				'status'      => 'broken',
				'status_code' => $code ?: 0,
				'error'       => sanitize_text_field( $error ),
				'record_id'   => $saved_record_id,
				'url'         => $url,
			);
		}

		set_transient( 'nlh_ok_' . $url_hash, true, 2 * DAY_IN_SECONDS );
		update_option( 'nlh_last_ok_' . $url_hash . '_' . $post_id, time(), false );
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
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $processor->get_updated_html(),
				),
				true
			);
			add_action( 'save_post', array( $this, 'handle_post_saved' ), 10, 3 );

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
	}

	/**
	 * Extracts valid absolute links from post content.
	 *
	 * @param string $content Post content.
	 * @return string[]
	 */
	private function extract_urls( string $content ): array {
		$processor = new WP_HTML_Tag_Processor( $content );
		$urls      = array();

		$tag_map = array(
			'A'      => 'href',
			'LINK'   => 'href',
			'IMG'    => 'src',
			'SCRIPT' => 'src',
			'SOURCE' => 'src',
			'VIDEO'  => 'src',
			'AUDIO'  => 'src',
			'IFRAME' => 'src',
		);

		while ( $processor->next_tag() ) {
			$tag  = $processor->get_tag();
			$attr = $tag_map[ $tag ] ?? null;
			if ( null === $attr ) {
				continue;
			}
			$val = $processor->get_attribute( $attr );
			if ( is_string( $val ) ) {
				$val = trim( html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) );
			}
			if ( is_string( $val ) && isset( $val[0] ) && '#' === $val[0] ) {
				$urls[] = $val;
				continue;
			}
			if ( is_string( $val ) && $this->is_relative_url( $val ) ) {
				$val = $this->resolve_relative_url( $val );
			}
			if ( is_string( $val ) && isset( $val[0] ) && '#' === $val[0] ) {
				$urls[] = $val;
			} elseif ( $this->is_scannable_url( $val ) ) {
				$urls[] = esc_url_raw( (string) $val );
			}
		}

		preg_match_all( '/https?:\/\/[^\s<>"\'`\]\)]+/i', $content, $matches );
		foreach ( $matches[0] as $raw ) {
			$raw = trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) );
			if ( 0 === strpos( $raw, 'http://www.w3.org/2000/svg' ) ) {
				continue;
			}
			if ( $this->is_scannable_url( $raw ) ) {
				$urls[] = esc_url_raw( $raw );
			}
		}

		return array_values( array_unique( $urls ) );
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

		// Exclude any URL that belongs to this WordPress installation to prevent self-request loops.
		$site_url  = site_url();
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		if ( $site_host && $url_host && strtolower( $site_host ) === strtolower( $url_host ) ) {
			// Only skip if the URL path is under the same WP installation path.
			// This allows co-hosted external services (e.g. example.com:8443/api) to still be scanned.
			$site_path = (string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' );
			$url_path  = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
			if ( 0 === strpos( trailingslashit( $url_path ), trailingslashit( $site_path ) ) ) {
				return false;
			}
		}

		$home = home_url();
		$url_no_frag  = explode( '#', $url, 2 )[0] ?? $url;
		$home_no_frag = explode( '#', $home, 2 )[0] ?? $home;
		if ( $url_no_frag === $home_no_frag ) {
			return false;
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
			// Check private/reserved ranges: 10.x.x.x, 172.16-31.x.x, 192.168.x.x
			if ( ! filter_var( $url_host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
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
		return home_url( $url );
	}

	/**
	 * Removes broken-link records for links no longer present in a post.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $urls Current URLs found in post content.
	 * @return void
	 */
	private function delete_stale_records( int $post_id, array $urls ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'nlh_link_errors';

		if ( empty( $urls ) ) {
			$stale_hashes = $wpdb->get_col(
				$wpdb->prepare( "SELECT url_hash FROM {$table} WHERE post_id = %d", $post_id )
			);
			foreach ( $stale_hashes as $stale_hash ) {
				delete_option( 'nlh_last_ok_' . $stale_hash . '_' . $post_id );
			}
			$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
			return;
		}

		$hashes       = array_map( 'md5', $urls );
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		$params       = array_merge( array( $post_id ), $hashes );

		$stale_hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT url_hash FROM {$table} WHERE post_id = %d AND url_hash NOT IN ({$placeholders})",
				$params
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND url_hash NOT IN ({$placeholders})",
				$params
			)
		);

		foreach ( $stale_hashes as $stale_hash ) {
			delete_option( 'nlh_last_ok_' . $stale_hash . '_' . $post_id );
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

		$args = array(
			'timeout'     => 15,
			'redirection' => 5,
			'user-agent'  => $user_agent,
		);

		$prepared_url = $this->prepare_url_for_request( $url );

		// Use non-safe HTTP functions because wp_safe_remote_head() rejects
		// non-resolvable hosts (e.g. defunct domains with broken DNS), which
		// are precisely the broken links we need to detect. The URL has
		// already passed is_scannable_url() which excludes self-referencing
		// URLs and non-http/https schemes.
		$response = wp_remote_head( $prepared_url, $args );

		if ( is_wp_error( $response ) || 405 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$response = wp_remote_get( $prepared_url, $args );
		}

		return $response;
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
			if ( function_exists( 'idn_to_ascii' ) ) {
				$ascii = idn_to_ascii( $parts['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				if ( false !== $ascii ) {
					$parts['host'] = $ascii;
				} else {
					// IDN conversion failed (e.g. invalid TLD like .usa).
					// Remove non-ASCII instead of percent-encoding the host,
					// which would break wp_safe_remote_head's URL validation.
					$parts['host'] = preg_replace( '/[^\x00-\x7F]/', '', $parts['host'] );
				}
			} else {
				// intl extension not available — remove non-ASCII from host.
				$parts['host'] = preg_replace( '/[^\x00-\x7F]/', '', $parts['host'] );
			}
			$url = $parts['scheme'] . '://' . $parts['host'] .
				( isset( $parts['path'] ) ? $parts['path'] : '' ) .
				( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) .
				( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		}
		return preg_replace_callback( '/[^\x00-\x7F]/u', fn( $m ) => rawurlencode( $m[0] ), $url );
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
				$ids[] = $id;
			}
		}

		// Also collect named anchors: <a name="fragment">
		$proc2 = new WP_HTML_Tag_Processor( $content );
		while ( $proc2->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$name = $proc2->get_attribute( 'name' );
			if ( is_string( $name ) && '' !== $name ) {
				$ids[] = $name;
			}
		}
		$ids = array_unique( $ids );

		if ( empty( $ids ) && ! empty( $urls ) ) {
			return $urls;
		}

		if ( empty( $ids ) ) {
			return array();
		}

		foreach ( $urls as $url ) {
			$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );
			if ( null === $fragment || '' === $fragment ) {
				continue;
			}
			if ( ! in_array( $fragment, $ids, true ) ) {
				$broken[] = $url;
			}
		}

		return $broken;
	}
}

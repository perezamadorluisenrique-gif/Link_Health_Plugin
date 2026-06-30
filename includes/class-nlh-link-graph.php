<?php
/**
 * Internal link graph and "link juice" (PageRank) analysis.
 *
 * Builds a persistent map of internal links between posts and computes a
 * PageRank-style authority score from it. The map is derived purely from post
 * content (no HTTP, no confirmation-threshold/soft-code/SSRF machinery) and is
 * rebuilt per post as a side effect of the scanner's existing traversal and on
 * save_post. Unresolved internal URLs are recorded with target_post_id = 0 and
 * are NEVER treated as broken links — taxonomy/author/date/paginated archives
 * legitimately resolve to 0 via url_to_postid().
 *
 * @package NativeLinkHealth
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records internal link relationships and computes link-juice scores.
 */
class NLH_Link_Graph {
	/**
	 * Map table base name (without prefix).
	 */
	const MAP_TABLE = 'nlh_link_map';

	/**
	 * Scores table base name (without prefix).
	 */
	const SCORES_TABLE = 'nlh_link_scores';

	/**
	 * Rebuilds the outbound link rows for one post from its content.
	 *
	 * Offline only: parses anchors, classifies internal/external, and resolves
	 * internal targets via url_to_postid(). Uses a replace strategy (drop this
	 * post's source rows, insert the fresh set) so it is idempotent.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $content Post content.
	 * @return void
	 */
	public function record_post( int $post_id, string $content ): void {
		global $wpdb;

		if ( $post_id <= 0 || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return;
		}

		$map_table = $wpdb->prefix . self::MAP_TABLE;
		$rows      = $this->extract_links( $content, $post_id );
		$now       = current_time( 'mysql', true );

		// Detect whether this post's links actually changed, so cron re-scans of
		// unchanged content don't keep flagging the scores as stale.
		$previous = $wpdb->get_col(
			$wpdb->prepare( "SELECT url_hash FROM {$map_table} WHERE source_post_id = %d", $post_id )
		);
		$current  = wp_list_pluck( $rows, 'url_hash' );
		sort( $previous );
		sort( $current );
		if ( $previous !== $current ) {
			self::mark_dirty();
		}

		$wpdb->delete( $map_table, array( 'source_post_id' => $post_id ), array( '%d' ) );

		foreach ( $rows as $row ) {
			$wpdb->replace(
				$map_table,
				array(
					'source_post_id' => $post_id,
					'target_post_id' => $row['target_post_id'],
					'target_url'     => $row['target_url'],
					'url_hash'       => $row['url_hash'],
					'link_type'      => $row['link_type'],
					'anchor_text'    => $row['anchor_text'],
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Flags the computed scores as stale (content changed since last recompute).
	 *
	 * @return void
	 */
	public static function mark_dirty(): void {
		update_option( 'nlh_juice_dirty', 1, false );
	}

	/**
	 * Whether the link map has changed since the last score recomputation.
	 *
	 * @return bool
	 */
	public static function is_dirty(): bool {
		return (bool) get_option( 'nlh_juice_dirty', 0 );
	}

	/**
	 * Removes a deleted post from the graph, both as a source and a target.
	 *
	 * Dropping target rows keeps the graph consistent; reporting "links to
	 * deleted content" is the broken-link scanner's job, not the juice map's.
	 *
	 * @param int $post_id Deleted post ID.
	 * @return void
	 */
	public function delete_post( int $post_id ): void {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return;
		}

		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$scores_table = $wpdb->prefix . self::SCORES_TABLE;

		$wpdb->delete( $map_table, array( 'source_post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $map_table, array( 'target_post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $scores_table, array( 'post_id' => $post_id ), array( '%d' ) );

		self::mark_dirty();
	}

	/**
	 * Extracts and classifies the anchor links of one post.
	 *
	 * @param string $content        Source content.
	 * @param int    $source_post_id Source post ID (to skip self-links).
	 * @return array<int,array<string,mixed>> Deduped rows keyed numerically.
	 */
	private function extract_links( string $content, int $source_post_id ): array {
		$processor = new WP_HTML_Tag_Processor( $content );
		$charset   = get_bloginfo( 'charset' );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$found     = array();

		while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$href = $processor->get_attribute( 'href' );

			if ( ! is_string( $href ) || '' === $href ) {
				continue;
			}

			$href       = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, $charset ) );
			$normalized = $this->normalize_href( $href );

			if ( null === $normalized ) {
				continue; // Fragment-only, mailto, tel, javascript, data, etc.
			}

			$url_host    = strtolower( (string) wp_parse_url( $normalized, PHP_URL_HOST ) );
			$is_internal = ( '' !== $site_host && $site_host === $url_host );
			$target_id   = 0;

			if ( $is_internal ) {
				// WordPress system endpoints (admin/login/cron/REST/feed) are not
				// content links and pass no juice — skip them so they don't clutter
				// the map or inflate dilution counts. A reader who pastes the
				// wp-admin edit URL of a page instead of its permalink lands here.
				if ( $this->is_system_endpoint( $normalized ) ) {
					continue;
				}

				// 0 here means "not a single post" (archive/taxonomy/etc.), NOT broken.
				$target_id = $this->resolve_internal_target( $normalized );

				// A self-link transfers no juice elsewhere; ignore it in the graph.
				if ( $target_id === $source_post_id && $target_id > 0 ) {
					continue;
				}
			}

			$url_hash = md5( $normalized );

			// Dedup repeated identical links within the same post.
			$found[ $url_hash ] = array(
				'target_post_id' => $target_id,
				'target_url'     => $normalized,
				'url_hash'       => $url_hash,
				'link_type'      => $is_internal ? 'internal' : 'external',
				'anchor_text'    => null,
			);
		}

		return array_values( $found );
	}

	/**
	 * Normalizes an href to an absolute http(s) URL, or null if it is not a
	 * juice-bearing hyperlink (fragment-only, mailto/tel/javascript/data).
	 *
	 * @param string $href Raw href value (already entity-decoded).
	 * @return string|null
	 */
	private function normalize_href( string $href ): ?string {
		if ( '' === $href || '#' === $href[0] ) {
			return null;
		}

		// Protocol-relative (//host/path).
		if ( 0 === strpos( $href, '//' ) ) {
			$href = ( is_ssl() ? 'https:' : 'http:' ) . $href;
		}

		$scheme = wp_parse_url( $href, PHP_URL_SCHEME );

		if ( false === $scheme || null === $scheme ) {
			if ( 0 === strpos( $href, '/' ) ) {
				// Root-relative (relative to the domain origin). The path already
				// includes any subdirectory, so resolve against scheme://host only —
				// using home_url() would double the prefix on a subdirectory install
				// (e.g. "/wpprueba/page/" -> ".../wpprueba/wpprueba/page/").
				return $this->site_origin() . $href;
			}

			// Path-relative URLs are rare in WP content; best-effort against home.
			return home_url( '/' . ltrim( $href, '/' ) );
		}

		$scheme = strtolower( (string) $scheme );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		return $href;
	}

	/**
	 * Returns the site origin (scheme://host[:port]) without any path.
	 *
	 * @return string
	 */
	private function site_origin(): string {
		$parts  = (array) wp_parse_url( home_url() );
		$scheme = $parts['scheme'] ?? ( is_ssl() ? 'https' : 'http' );
		$host   = $parts['host'] ?? '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . $host . $port;
	}

	/**
	 * Whether an internal URL points at a WordPress system endpoint that is not
	 * a content page (admin, login, cron, xmlrpc, REST, feed).
	 *
	 * @param string $url Absolute internal URL.
	 * @return bool
	 */
	private function is_system_endpoint( string $url ): bool {
		$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );

		// Strip the install's subdirectory prefix so endpoints match at the start
		// of the WP-relative path (a subdir install lives at /wpprueba/...).
		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		if ( '' === $path ) {
			$path = '/';
		}

		// Match on a path-segment boundary so a real page slug like "wp-admin-tips"
		// or "wp-json-notes" is NOT mistaken for a system endpoint.
		foreach ( array( '/wp-admin', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-json' ) as $needle ) {
			if ( $path === $needle || 0 === strpos( $path, $needle . '/' ) ) {
				return true;
			}
		}

		// Trailing /feed/ (or /feed) is a syndication endpoint, not a page.
		return (bool) preg_match( '#/feed/?$#', $path );
	}

	/**
	 * Resolves an internal URL to a post ID, tolerating an http/https scheme
	 * mismatch against the site's configured home scheme.
	 *
	 * A 0 result is legitimate (taxonomy/author/date/paginated archives) and is
	 * NOT treated as broken — it just means the edge has no single post target.
	 *
	 * @param string $url Absolute internal URL.
	 * @return int
	 */
	private function resolve_internal_target( string $url ): int {
		$clean     = explode( '#', $url, 2 )[0];
		$target_id = (int) url_to_postid( $clean );

		if ( 0 === $target_id ) {
			// A link written with the "wrong" scheme (e.g. https on an http site,
			// common with mixed content) won't match permalinks; retry on the
			// home scheme before giving up.
			$home_scheme = strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) );
			$alt         = preg_replace( '#^https?://#i', $home_scheme . '://', $clean );

			if ( is_string( $alt ) && $alt !== $clean ) {
				$target_id = (int) url_to_postid( $alt );
			}
		}

		return $target_id;
	}

	/**
	 * Rebuilds the entire link map from current content, then recomputes scores.
	 *
	 * Fully offline (content parse + url_to_postid only). This makes the manual
	 * "Recalculate" action self-sufficient: it does not depend on a prior HTTP
	 * broken-link scan having run.
	 *
	 * @return array{nodes:int,edges:int}
	 */
	public function rebuild_all(): array {
		$post_types = apply_filters( 'nlh_scan_post_types', array( 'post', 'page' ) );
		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );

		$page       = 1;
		$chunk_size = 100;

		do {
			$query = new WP_Query(
				array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'posts_per_page'         => $chunk_size,
					'fields'                 => 'ids',
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $query->posts as $post_id ) {
				$post = get_post( (int) $post_id );
				if ( $post instanceof WP_Post ) {
					$this->record_post( (int) $post_id, $post->post_content );
				}
			}

			++$page;
		} while ( ! empty( $query->posts ) && count( $query->posts ) === $chunk_size );

		return $this->compute_pagerank();
	}

	/**
	 * Recomputes PageRank and inbound/outbound counts for every node.
	 *
	 * Nodes are all published posts of the scanned post types, so genuine
	 * orphans (zero inbound) surface even if never linked. Graph edges are the
	 * distinct internal (source -> target) pairs in the map.
	 *
	 * @return array{nodes:int,edges:int} Summary of the computed graph.
	 */
	public function compute_pagerank(): array {
		global $wpdb;

		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$scores_table = $wpdb->prefix . self::SCORES_TABLE;

		$node_ids = $this->get_node_ids();
		$n        = count( $node_ids );

		if ( 0 === $n ) {
			$wpdb->query( "TRUNCATE TABLE {$scores_table}" );
			update_option( 'nlh_juice_computed_at', time(), false );
			update_option( 'nlh_juice_dirty', 0, false );
			return array(
				'nodes' => 0,
				'edges' => 0,
			);
		}

		$node_set = array_fill_keys( $node_ids, true );

		$edges_raw = $wpdb->get_results(
			"SELECT DISTINCT source_post_id, target_post_id FROM {$map_table}
			 WHERE link_type = 'internal' AND target_post_id > 0",
			ARRAY_A
		);

		$out        = array(); // source_id to list of target_ids.
		$inbound    = array_fill_keys( $node_ids, 0 );
		$edge_count = 0;

		foreach ( (array) $edges_raw as $edge ) {
			$source = (int) $edge['source_post_id'];
			$target = (int) $edge['target_post_id'];

			if ( $source === $target || ! isset( $node_set[ $source ], $node_set[ $target ] ) ) {
				continue;
			}

			$out[ $source ][] = $target;
			++$inbound[ $target ];
			++$edge_count;
		}

		$damping  = max( 0.5, min( 0.99, (float) apply_filters( 'nlh_pagerank_damping', 0.85 ) ) );
		$max_iter = 50;
		$epsilon  = 1e-6;
		$pr       = array_fill_keys( $node_ids, 1.0 / $n );

		for ( $iteration = 0; $iteration < $max_iter; $iteration++ ) {
			$next = array_fill_keys( $node_ids, ( 1.0 - $damping ) / $n );

			// Redistribute dangling-node mass (no outbound internal edges) so the
			// total probability mass stays normalized to 1.
			$dangling = 0.0;
			foreach ( $node_ids as $node ) {
				if ( empty( $out[ $node ] ) ) {
					$dangling += $pr[ $node ];
				}
			}
			$dangling_share = $damping * $dangling / $n;

			foreach ( $node_ids as $node ) {
				$next[ $node ] += $dangling_share;
			}

			foreach ( $out as $source => $targets ) {
				$share = $damping * $pr[ $source ] / count( $targets );
				foreach ( $targets as $target ) {
					$next[ $target ] += $share;
				}
			}

			$delta = 0.0;
			foreach ( $node_ids as $node ) {
				$delta += abs( $next[ $node ] - $pr[ $node ] );
			}

			$pr = $next;

			if ( $delta < $epsilon ) {
				break;
			}
		}

		$out_internal = array();
		foreach ( $out as $source => $targets ) {
			$out_internal[ $source ] = count( $targets );
		}

		$out_total_rows = $wpdb->get_results(
			"SELECT source_post_id, COUNT(*) AS total FROM {$map_table} GROUP BY source_post_id",
			ARRAY_A
		);
		$out_total      = array();
		foreach ( (array) $out_total_rows as $row ) {
			$out_total[ (int) $row['source_post_id'] ] = (int) $row['total'];
		}

		$wpdb->query( "TRUNCATE TABLE {$scores_table}" );
		$now = current_time( 'mysql', true );

		foreach ( $node_ids as $node ) {
			$wpdb->replace(
				$scores_table,
				array(
					'post_id'           => $node,
					'pagerank'          => $pr[ $node ],
					'inbound_internal'  => (int) ( $inbound[ $node ] ?? 0 ),
					'outbound_internal' => (int) ( $out_internal[ $node ] ?? 0 ),
					'outbound_total'    => (int) ( $out_total[ $node ] ?? 0 ),
					'computed_at'       => $now,
				),
				array( '%d', '%f', '%d', '%d', '%d', '%s' )
			);
		}

		update_option( 'nlh_juice_computed_at', time(), false );
		update_option( 'nlh_juice_dirty', 0, false );

		// Record one health-score data point per day so trends (a Pro report) have
		// real history to draw on. The snapshot itself is free, lightweight data.
		$this->record_health_snapshot();

		return array(
			'nodes' => $n,
			'edges' => $edge_count,
		);
	}

	/**
	 * Appends today's Link Health Score to the rolling history, one point per day,
	 * capped to roughly a quarter of daily samples. Stored as a plain option (no
	 * table): a free data trail the Pro trends/report feature reads from.
	 *
	 * @return void
	 */
	private function record_health_snapshot(): void {
		$history = get_option( 'nlh_health_history', array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$today = wp_date( 'Y-m-d' );
		$score = $this->calculate_health_score();

		// One entry per day: overwrite today's if it already exists.
		$history[ $today ] = $score;

		// Keep the most recent ~90 days.
		if ( count( $history ) > 90 ) {
			ksort( $history );
			$history = array_slice( $history, -90, null, true );
		}

		update_option( 'nlh_health_history', $history, false );
	}

	/**
	 * Returns the recorded Link Health Score history (date => score).
	 *
	 * @return array<string,int>
	 */
	public function get_health_history(): array {
		$history = get_option( 'nlh_health_history', array() );

		if ( ! is_array( $history ) ) {
			return array();
		}

		ksort( $history );

		return array_map( 'intval', $history );
	}

	/**
	 * Returns all node IDs (published posts of scanned post types).
	 *
	 * @return int[]
	 */
	private function get_node_ids(): array {
		global $wpdb;

		$post_types = apply_filters( 'nlh_scan_post_types', array( 'post', 'page' ) );
		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );

		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ({$placeholders}) AND post_status = 'publish'
				 ORDER BY ID ASC",
					$post_types
				)
			)
		);
	}

	/**
	 * Returns the dilution threshold (outbound links above which a page is
	 * considered to dilute its outgoing authority too thinly).
	 *
	 * @return int
	 */
	public static function get_dilution_threshold(): int {
		return max( 1, (int) apply_filters( 'nlh_dilution_threshold', 100 ) );
	}

	/**
	 * Returns a page of computed score rows joined with post titles.
	 *
	 * @param array $args {
	 *     @type string $orderby  Sort column (pagerank|inbound|outbound).
	 *     @type string $order    ASC|DESC.
	 *     @type string $filter   all|orphan|dead_end|diluted.
	 *     @type int    $paged    Page number (1-based).
	 *     @type int    $per_page Rows per page.
	 * }
	 * @return array{rows:array,total:int,total_pages:int}
	 */
	public function get_report( array $args = array() ): array {
		global $wpdb;

		$scores_table = $wpdb->prefix . self::SCORES_TABLE;

		$orderby  = $args['orderby'] ?? 'pagerank';
		$order    = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$filter   = $args['filter'] ?? 'all';
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$per_page = max( 1, (int) ( $args['per_page'] ?? 25 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$order_columns = array(
			'pagerank' => 's.pagerank',
			'inbound'  => 's.inbound_internal',
			'outbound' => 's.outbound_total',
			'title'    => 'p.post_title',
		);
		$order_sql     = $order_columns[ $orderby ] ?? 's.pagerank';

		$threshold = self::get_dilution_threshold();
		$excluded  = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );

		$where = '1=1';
		if ( 'orphan' === $filter ) {
			$where = 's.inbound_internal = 0';
			if ( $excluded ) {
				$where .= ' AND s.post_id NOT IN (' . implode( ',', array_map( 'intval', $excluded ) ) . ')';
			}
		} elseif ( 'dead_end' === $filter ) {
			$where = 's.inbound_internal > 0 AND s.outbound_internal = 0';
		} elseif ( 'diluted' === $filter ) {
			$where = $wpdb->prepare( 's.outbound_total > %d', $threshold );
		}

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$scores_table} s
			 INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id
			 WHERE {$where}"
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.post_id, s.pagerank, s.inbound_internal, s.outbound_internal, s.outbound_total,
						p.post_title, p.post_type
				 FROM {$scores_table} s
				 INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id
				 WHERE {$where}
				 ORDER BY {$order_sql} {$order}, s.post_id ASC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		return array(
			'rows'        => is_array( $rows ) ? $rows : array(),
			'total'       => $total,
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * Returns summary counts for the dashboard header cards.
	 *
	 * @return array<string,int>
	 */
	public function get_summary(): array {
		global $wpdb;

		$scores_table = $wpdb->prefix . self::SCORES_TABLE;
		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$errors_table = $wpdb->prefix . 'nlh_link_errors';
		$threshold    = self::get_dilution_threshold();
		$excluded     = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
		$exclude_sql  = $excluded ? ' AND post_id NOT IN (' . implode( ',', array_map( 'intval', $excluded ) ) . ')' : '';

		return array(
			'total'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scores_table}" ),
			'orphans'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scores_table} WHERE inbound_internal = 0{$exclude_sql}" ),
			'deadEnds'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scores_table} WHERE inbound_internal > 0 AND outbound_internal = 0" ),
			'diluted'           => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$scores_table} WHERE outbound_total > %d", $threshold ) ),
			'total_broken'      => (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT e.url_hash)
				 FROM {$errors_table} e
				 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash"
			),
			'pages_with_broken' => (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT e.post_id)
				 FROM {$errors_table} e
				 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash"
			),
			'broken_4xx'        => (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT e.url_hash)
				 FROM {$errors_table} e
				 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
				 WHERE e.status_code >= 400 AND e.status_code < 500"
			),
			'broken_5xx'        => (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT e.url_hash)
				 FROM {$errors_table} e
				 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
				 WHERE e.status_code >= 500"
			),
			'broken_timeout'    => (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT e.url_hash)
				 FROM {$errors_table} e
				 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
				 WHERE e.status_code = 0"
			),
			'health_score'      => $this->calculate_health_score(),
		);
	}

	/**
	 * Returns the posts that link to a given target (inbound links).
	 *
	 * @param int $post_id Target post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_inbound( int $post_id ): array {
		global $wpdb;

		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$scores_table = $wpdb->prefix . self::SCORES_TABLE;
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT m.source_post_id, p.post_title, s.pagerank
				 FROM {$map_table} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.source_post_id
				 LEFT JOIN {$scores_table} s ON s.post_id = m.source_post_id
				 WHERE m.target_post_id = %d
				 ORDER BY s.pagerank DESC, p.post_title ASC",
				$post_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'post_id'   => (int) $row->source_post_id,
				'title'     => $row->post_title,
				'pagerank'  => (float) $row->pagerank,
				'edit'      => get_edit_post_link( (int) $row->source_post_id, 'raw' ),
				'permalink' => get_permalink( (int) $row->source_post_id ),
			);
		}

		return $out;
	}

	/**
	 * Returns the outbound links of a post (for the relink/repair view).
	 *
	 * @param int $post_id Source post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_outbound( int $post_id ): array {
		global $wpdb;

		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$scores_table = $wpdb->prefix . self::SCORES_TABLE;
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.target_post_id, m.target_url, m.link_type, s.pagerank
				 FROM {$map_table} m
				 LEFT JOIN {$scores_table} s ON s.post_id = m.target_post_id
				 WHERE m.source_post_id = %d
				 ORDER BY m.link_type ASC, s.pagerank DESC, m.target_url ASC",
				$post_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$target_id = (int) $row->target_post_id;
			$out[]     = array(
				'target_post_id' => $target_id,
				'target_url'     => $row->target_url,
				'link_type'      => $row->link_type,
				'pagerank'       => (float) $row->pagerank,
				'title'          => $target_id > 0 ? get_the_title( $target_id ) : '',
			);
		}

		return $out;
	}

	/**
	 * Returns the focal page plus its inbound and outbound neighbours, for the
	 * page-centric flow diagram.
	 *
	 * @param int $post_id Focal post ID.
	 * @return array<string,mixed>
	 */
	public function get_flow( int $post_id ): array {
		global $wpdb;

		$scores_table = $wpdb->prefix . self::SCORES_TABLE;
		$errors_table = $wpdb->prefix . 'nlh_link_errors';
		$score        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pagerank, inbound_internal, outbound_internal, outbound_total
				 FROM {$scores_table} WHERE post_id = %d",
				$post_id
			)
		);

		$outbound = $this->get_outbound( $post_id );

		// Enrich each outbound entry with broken-link status via batch query.
		// The url_hash JOIN works for standard ASCII URLs (see get_broken_link_counts).
		if ( ! empty( $outbound ) ) {
			$url_hashes = array_map( 'md5', array_column( $outbound, 'target_url' ) );
			if ( ! empty( $url_hashes ) ) {
				$placeholders   = implode( ',', array_fill( 0, count( $url_hashes ), '%s' ) );
				$params         = array_merge( array( $post_id ), $url_hashes );
				$broken_rows    = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT url_hash, status_code, error_message, impact_score
						 FROM {$errors_table}
						 WHERE post_id = %d AND url_hash IN ({$placeholders})",
						$params
					),
					ARRAY_A
				);
				$broken_by_hash = array();
				foreach ( (array) $broken_rows as $br ) {
					$broken_by_hash[ $br['url_hash'] ] = $br;
				}
				foreach ( $outbound as &$ob ) {
					$hash = md5( $ob['target_url'] );
					if ( isset( $broken_by_hash[ $hash ] ) ) {
						$ob['is_broken']     = true;
						$ob['status_code']   = (int) $broken_by_hash[ $hash ]['status_code'];
						$ob['error_message'] = $broken_by_hash[ $hash ]['error_message'];
						$ob['impact_score']  = (int) $broken_by_hash[ $hash ]['impact_score'];
					} else {
						$ob['is_broken'] = false;
					}
				}
				unset( $ob );
			}
		}

		return array(
			'focal'    => array(
				'post_id'   => $post_id,
				'title'     => get_the_title( $post_id ),
				'pagerank'  => $score ? (float) $score->pagerank : 0.0,
				'inbound'   => $score ? (int) $score->inbound_internal : 0,
				'outbound'  => $score ? (int) $score->outbound_internal : 0,
				'permalink' => get_permalink( $post_id ),
			),
			'inbound'  => $this->get_inbound( $post_id ),
			'outbound' => $outbound,
		);
	}

	/**
	 * Returns a capped node/edge set for the global overview diagram.
	 *
	 * Nodes are the top pages by PageRank (to keep the picture legible); edges
	 * are the internal links among the included nodes.
	 *
	 * @param int $limit Maximum number of nodes.
	 * @return array{nodes:array,edges:array,total:int,shown:int,threshold:int}
	 */
	public function get_graph( int $limit = 0 ): array {
		global $wpdb;

		if ( $limit <= 0 ) {
			$limit = (int) apply_filters( 'nlh_graph_node_cap', 150 );
		}
		$limit        = max( 10, min( 400, $limit ) );
		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$scores_table = $wpdb->prefix . self::SCORES_TABLE;
		$threshold    = self::get_dilution_threshold();
		$total        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scores_table}" );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.post_id, s.pagerank, s.inbound_internal, s.outbound_internal, s.outbound_total, p.post_title
				 FROM {$scores_table} s
				 INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id
				 ORDER BY s.pagerank DESC, s.post_id ASC
				 LIMIT %d",
				$limit
			)
		);

		$nodes         = array();
		$included      = array();
		$front         = (int) get_option( 'page_on_front' );
		$posts_pg      = (int) get_option( 'page_for_posts' );
		$broken_counts = $this->get_cached_broken_counts();

		foreach ( (array) $rows as $row ) {
			$pid              = (int) $row->post_id;
			$included[ $pid ] = true;
			$broken           = $broken_counts[ $pid ] ?? 0;

			if ( $broken > 0 ) {
				$flag = 'has_broken';
			} elseif ( 0 === (int) $row->inbound_internal && ! in_array( $pid, array( $front, $posts_pg ), true ) ) {
				$flag = 'orphan';
			} elseif ( (int) $row->inbound_internal > 0 && 0 === (int) $row->outbound_internal ) {
				$flag = 'deadend';
			} elseif ( (int) $row->outbound_total > $threshold ) {
				$flag = 'diluted';
			} else {
				$flag = 'ok';
			}

			$nodes[] = array(
				'id'           => $pid,
				'title'        => (string) $row->post_title,
				'pr'           => (float) $row->pagerank,
				'inb'          => (int) $row->inbound_internal,
				'out'          => (int) $row->outbound_internal,
				'flag'         => $flag,
				'broken_count' => $broken,
			);
		}

		$edges = array();
		if ( $included ) {
			$ids          = array_keys( $included );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$params       = array_merge( $ids, $ids );
			$edge_rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT source_post_id, target_post_id FROM {$map_table}
					 WHERE link_type = 'internal' AND target_post_id > 0
					 AND source_post_id IN ({$placeholders}) AND target_post_id IN ({$placeholders})",
					$params
				)
			);
			foreach ( (array) $edge_rows as $edge ) {
				if ( (int) $edge->source_post_id === (int) $edge->target_post_id ) {
					continue;
				}
				$edges[] = array(
					's' => (int) $edge->source_post_id,
					't' => (int) $edge->target_post_id,
				);
			}
		}

		return array(
			'nodes'     => $nodes,
			'edges'     => $edges,
			'total'     => $total,
			'shown'     => count( $nodes ),
			'threshold' => $threshold,
		);
	}

	/**
	 * Returns broken outbound link count per post.
	 *
	 * The JOIN uses url_hash as computed by the link graph (md5 of normalized URL).
	 * For standard ASCII permalinks this matches the scanner's md5(esc_url_raw(url)).
	 *
	 * @param int $min_impact Minimum impact_score (0-100).
	 * @return array<int,int> Keyed by source_post_id.
	 */
	public function get_broken_link_counts( int $min_impact = 0 ): array {
		global $wpdb;

		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$errors_table = $wpdb->prefix . 'nlh_link_errors';

		$impact_where = '';
		if ( $min_impact > 0 ) {
			$impact_where = $wpdb->prepare( ' AND e.impact_score >= %d', $min_impact );
		}

		$rows = $wpdb->get_results(
			"SELECT e.post_id, COUNT(DISTINCT e.url_hash) AS broken_count
			 FROM {$errors_table} e
			 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
			 WHERE 1=1{$impact_where}
			 GROUP BY e.post_id",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ] = (int) $row['broken_count'];
		}
		return $out;
	}

	/**
	 * Broken link count for a single post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public function get_broken_count_for_post( int $post_id ): int {
		global $wpdb;

		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$errors_table = $wpdb->prefix . 'nlh_link_errors';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT e.url_hash)
				 FROM {$errors_table} e
				 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
				 WHERE e.post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * All broken outbound links for a post (used by the overview graph detail panel).
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public function get_broken_links_for_post( int $post_id ): array {
		global $wpdb;

		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$errors_table = $wpdb->prefix . 'nlh_link_errors';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.raw_url, e.status_code, e.error_message, e.impact_score,
				        m.anchor_text, m.target_url
				 FROM {$errors_table} e
				 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
				 WHERE e.post_id = %d
				 ORDER BY e.impact_score DESC
				 LIMIT 20",
				$post_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Detailed broken-link info for a URL hash.
	 *
	 * @param string $url_hash MD5 hash.
	 * @return array|null
	 */
	public function get_broken_link_details_by_url( string $url_hash ): ?array {
		global $wpdb;

		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$errors_table = $wpdb->prefix . 'nlh_link_errors';
		$scores_table = $wpdb->prefix . self::SCORES_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT e.id, e.post_id, e.raw_url, e.status_code, e.error_message,
				        e.impact_score, e.discovered_at, e.last_checked_at,
				        m.target_url, m.anchor_text, m.link_type,
				        p.post_title AS source_title,
				        s.pagerank AS source_pagerank
				 FROM {$errors_table} e
				 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
				 LEFT JOIN {$wpdb->posts} p ON p.ID = e.post_id
				 LEFT JOIN {$scores_table} s ON s.post_id = e.post_id
				 WHERE e.url_hash = %s
				 LIMIT 50",
				$url_hash
			)
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'error_id'        => (int) $row->id,
			'post_id'         => (int) $row->post_id,
			'raw_url'         => $row->raw_url,
			'status_code'     => (int) $row->status_code,
			'error_message'   => $row->error_message,
			'impact_score'    => (int) $row->impact_score,
			'discovered_at'   => $row->discovered_at,
			'last_checked_at' => $row->last_checked_at,
			'target_url'      => $row->target_url,
			'anchor_text'     => $row->anchor_text,
			'source_title'    => $row->source_title,
			'source_pagerank' => (float) $row->source_pagerank,
		);
	}

	/**
	 * Global Authority Health Score (0-100).
	 *
	 * Weights: 40% non-orphan ratio, 30% non-dead-end ratio,
	 * 20% non-diluted ratio, 10% healthy-links ratio.
	 *
	 * @return int 0-100
	 */
	public function calculate_health_score(): int {
		global $wpdb;

		$scores_table = $wpdb->prefix . self::SCORES_TABLE;
		$map_table    = $wpdb->prefix . self::MAP_TABLE;
		$errors_table = $wpdb->prefix . 'nlh_link_errors';

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scores_table}" );
		if ( 0 === $total ) {
			return 0;
		}

		$excluded    = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
		$exclude_sql = $excluded
			? ' AND s.post_id NOT IN (' . implode( ',', array_map( 'intval', $excluded ) ) . ')'
			: '';

		$with_inbound = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scores_table} s WHERE s.inbound_internal > 0{$exclude_sql}" );
		$orphan_ratio = $with_inbound / $total;

		$with_outbound = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scores_table} s WHERE s.outbound_internal > 0" );
		$deadend_ratio = $with_outbound / $total;

		$threshold     = self::get_dilution_threshold();
		$not_diluted   = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$scores_table} s WHERE s.outbound_total <= %d", $threshold )
		);
		$diluted_ratio = $not_diluted / $total;

		$total_broken        = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT e.url_hash)
			 FROM {$errors_table} e
			 INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash"
		);
		$total_links_in_map  = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT url_hash) FROM {$map_table} WHERE link_type = 'internal'"
		);
		$healthy_links_ratio = $total_links_in_map > 0
			? ( $total_links_in_map - $total_broken ) / $total_links_in_map
			: 1.0;

		$score = ( $orphan_ratio * 40 )
			+ ( $deadend_ratio * 30 )
			+ ( $diluted_ratio * 20 )
			+ ( $healthy_links_ratio * 10 );

		return (int) round( min( 100, max( 0, $score ) ) );
	}

	/**
	 * Cached broken counts via transient.
	 *
	 * @param int $min_impact Minimum impact_score (0-100).
	 * @return array<int,int>
	 */
	public function get_cached_broken_counts( int $min_impact = 0 ): array {
		$cache_key = 'nlh_broken_counts_' . $min_impact;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (array) $cached;
		}
		$counts = $this->get_broken_link_counts( $min_impact );
		set_transient( $cache_key, $counts, 5 * MINUTE_IN_SECONDS );
		return $counts;
	}

	/**
	 * Invalidates broken counts transient cache.
	 */
	public static function clear_broken_counts_cache(): void {
		delete_transient( 'nlh_broken_counts_0' );
		delete_transient( 'nlh_broken_counts_20' );
		delete_transient( 'nlh_broken_counts_50' );
	}
}

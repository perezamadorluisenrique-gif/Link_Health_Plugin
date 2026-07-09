<?php
/**
 * SEO audit module.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs lightweight technical SEO audits.
 */
class NLH_SEO_Audit {
	/**
	 * Common stop words (EN + ES) excluded from focus-keyword detection.
	 *
	 * Deliberately duplicated from the similar list in
	 * class-nlh-link-recommendations.php rather than shared, so the two
	 * classes (link relevance vs. keyword density) can evolve independently.
	 *
	 * @var string[]
	 */
	private array $stopwords = array(
		'the',
		'and',
		'for',
		'with',
		'that',
		'this',
		'from',
		'your',
		'you',
		'are',
		'was',
		'has',
		'have',
		'will',
		'can',
		'how',
		'what',
		'why',
		'who',
		'when',
		'where',
		'about',
		'into',
		'over',
		'best',
		'guide',
		'los',
		'las',
		'una',
		'unos',
		'unas',
		'del',
		'con',
		'por',
		'para',
		'que',
		'como',
		'mas',
		'pero',
		'sus',
		'este',
		'esta',
		'esto',
		'son',
		'fue',
		'han',
		'hay',
		'sobre',
		'entre',
		'cuando',
		'donde',
	);

	/**
	 * Finds published posts/pages with no internal inbound links.
	 *
	 * @return array
	 */
	public function audit_orphan_pages(): array {
		$posts      = $this->get_public_posts();
		$all_ids    = wp_list_pluck( $posts, 'ID' );
		$front_page = (int) get_option( 'page_on_front' );
		$posts_page = (int) get_option( 'page_for_posts' );
		$linked_ids = $this->get_nav_menu_linked_ids();

		foreach ( $posts as $post ) {
			foreach ( $this->extract_attribute_values( $post->post_content, 'A', 'href' ) as $href ) {
				$linked_id = url_to_postid( $href );

				if ( $linked_id > 0 ) {
					$linked_ids[] = $linked_id;
				}
			}
		}

		// A page is only an orphan if nothing links to it: not content links,
		// not navigation menus, and it is not the front page or posts page
		// (which WordPress links to structurally).
		$orphans = array_values(
			array_diff(
				$all_ids,
				array_unique( $linked_ids ),
				array_filter( array( $front_page, $posts_page ) )
			)
		);

		return $this->result(
			empty( $orphans ) ? 'pass' : 'warning',
			count( $orphans ),
			$this->format_post_items( $orphans ),
			empty( $orphans ) ? __( 'No orphan pages found.', 'native-link-health' ) : __( 'Pages without internal inbound links were found.', 'native-link-health' )
		);
	}

	/**
	 * Detects internal links that pass through a redirect chain (two or more hops)
	 * or whose redirect ends in an error. Probing is bounded and restricted to the
	 * site's own host (no external hammering, no SSRF), and follows redirects by
	 * hand so the hop count is real — not "informational only".
	 *
	 * Single clean 301 -> 200 redirects are intentionally NOT flagged: one hop is
	 * fine. We only surface what actually hurts: multi-hop chains and redirects to
	 * a dead end. This keeps the no-false-positives promise.
	 *
	 * @return array
	 */
	public function audit_redirect_chains(): array {
		/**
		 * Filters how many distinct internal links the redirect audit probes.
		 *
		 * Bounded by default so the audit stays gentle on large sites.
		 *
		 * @since 1.3.0
		 * @param int $limit Maximum URLs probed per run.
		 */
		$limit     = max( 1, (int) apply_filters( 'nlh_redirect_chain_scan_limit', 50 ) );
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$seen      = array();
		$items     = array();
		$probed    = 0;
		$truncated = false;

		foreach ( $this->get_public_posts() as $post ) {
			foreach ( $this->extract_attribute_values( $post->post_content, 'A', 'href' ) as $href ) {
				$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );

				// Only probe absolute, same-host links once each.
				if ( '' === $host || $host !== $home_host || isset( $seen[ $href ] ) ) {
					continue;
				}
				$seen[ $href ] = true;

				if ( $probed >= $limit ) {
					$truncated = true;
					break 2;
				}
				++$probed;

				$chain = $this->trace_redirects( $href );

				if ( $chain['hops'] >= 2 || ( $chain['hops'] >= 1 && $chain['final_code'] >= 400 ) ) {
					$detail = $chain['final_code'] >= 400
						? sprintf(
							/* translators: 1: hop count, 2: final HTTP status code. */
							__( '%1$d redirect hops, ending in HTTP %2$d.', 'native-link-health' ),
							(int) $chain['hops'],
							(int) $chain['final_code']
						)
						: sprintf(
							/* translators: %d: hop count. */
							__( '%d redirect hops before the final page.', 'native-link-health' ),
							(int) $chain['hops']
						);

					$items[] = $this->format_post_item( (int) $post->ID, $href . ' — ' . $detail );
				}
			}
		}

		$message = empty( $items )
			? __( 'No redirect chains found in your internal links.', 'native-link-health' )
			: __( 'Internal links that go through multiple redirects (or a dead end) were found. Point them straight at the final URL.', 'native-link-health' );

		if ( $truncated ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of links probed. */
				__( 'Checked the first %d internal links; raise nlh_redirect_chain_scan_limit to probe more.', 'native-link-health' ),
				$limit
			);
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'warning',
			count( $items ),
			$items,
			$message
		);
	}

	/**
	 * Follows a URL's redirects one hop at a time (HEAD, GET fallback) up to a
	 * small cap, returning the hop count and the final status code. Loop-safe.
	 *
	 * @param string $url Starting URL (already same-host).
	 * @return array{hops:int,final_code:int}
	 */
	private function trace_redirects( string $url ): array {
		$args = array(
			'timeout'     => 8,
			'redirection' => 0,
			'user-agent'  => apply_filters(
				'nlh_user_agent',
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
			),
		);

		$hops    = 0;
		$current = $url;
		$visited = array();
		$code    = 0;

		for ( $i = 0; $i < 6; $i++ ) {
			if ( isset( $visited[ $current ] ) ) {
				break; // Redirect loop — stop counting.
			}
			$visited[ $current ] = true;

			$response = wp_remote_head( $current, $args );
			$code     = (int) wp_remote_retrieve_response_code( $response );

			if ( is_wp_error( $response ) || in_array( $code, array( 400, 403, 405, 501 ), true ) || $code < 100 ) {
				$response = wp_remote_get( $current, $args );
				$code     = (int) wp_remote_retrieve_response_code( $response );
			}

			if ( is_wp_error( $response ) ) {
				$code = 0;
				break;
			}

			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				break; // Reached a non-redirect: this is the final code.
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( '' === $location ) {
				break;
			}

			// Resolve relative Location headers against the current URL.
			$next = $this->resolve_location( $current, (string) $location );
			if ( '' === $next ) {
				break;
			}

			++$hops;
			$current = $next;
		}

		return array(
			'hops'       => $hops,
			'final_code' => $code,
		);
	}

	/**
	 * Resolves a (possibly relative) Location header against the URL it came from.
	 *
	 * @param string $base     URL the redirect originated from.
	 * @param string $location Raw Location header value.
	 * @return string Absolute URL, or '' if unresolvable.
	 */
	private function resolve_location( string $base, string $location ): string {
		$location = trim( $location );

		if ( '' === $location ) {
			return '';
		}

		if ( wp_parse_url( $location, PHP_URL_SCHEME ) ) {
			return $location;
		}

		$parts = wp_parse_url( $base );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}

		$path = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';

		return $origin . $path . $location;
	}

	/**
	 * Finds HTTP media references on HTTPS sites.
	 *
	 * @return array
	 */
	public function audit_mixed_content(): array {
		if ( ! is_ssl() && 0 !== stripos( home_url(), 'https://' ) ) {
			return $this->result( 'pass', 0, array(), __( 'Site is not served over HTTPS, so mixed content was not checked.', 'native-link-health' ) );
		}

		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			foreach ( $this->extract_src_values( $post->post_content ) as $src ) {
				if ( 0 === stripos( $src, 'http://' ) ) {
					$items[] = $this->format_post_item( (int) $post->ID, $src );
				}
			}
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'fail',
			count( $items ),
			$items,
			empty( $items ) ? __( 'No mixed content references found.', 'native-link-health' ) : __( 'HTTP media references were found on an HTTPS site.', 'native-link-health' )
		);
	}

	/**
	 * Flags canonical links embedded in post content that do not match the permalink.
	 *
	 * @return array
	 */
	public function audit_invalid_canonicals(): array {
		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			$permalink = get_permalink( $post );

			foreach ( $this->extract_attribute_values( $post->post_content, 'LINK', 'href', array( 'rel' => 'canonical' ) ) as $href ) {
				// Cross-domain canonicals (syndication, etc.) are intentional and
				// must not be flagged. Only compare canonicals on the same host.
				$canonical_host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
				$permalink_host = strtolower( (string) wp_parse_url( $permalink, PHP_URL_HOST ) );

				if ( '' !== $canonical_host && $canonical_host !== $permalink_host ) {
					continue;
				}

				if ( untrailingslashit( $href ) !== untrailingslashit( $permalink ) ) {
					$items[] = $this->format_post_item( (int) $post->ID, $href );
				}
			}
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'fail',
			count( $items ),
			$items,
			empty( $items ) ? __( 'No invalid canonical links found in content.', 'native-link-health' ) : __( 'Canonical links that differ from the post permalink were found.', 'native-link-health' )
		);
	}

	/**
	 * Finds repeated hrefs within individual posts.
	 *
	 * @return array
	 */
	public function audit_redundant_links(): array {
		$items = array();

		foreach ( $this->get_public_posts() as $post ) {
			$counts = array_count_values( $this->extract_attribute_values( $post->post_content, 'A', 'href' ) );

			foreach ( $counts as $href => $count ) {
				if ( $count > 1 ) {
					$items[] = $this->format_post_item(
						(int) $post->ID,
						sprintf(
							/* translators: 1: URL, 2: occurrence count. */
							__( '%1$s appears %2$d times.', 'native-link-health' ),
							$href,
							$count
						)
					);
				}
			}
		}

		return $this->result(
			empty( $items ) ? 'pass' : 'warning',
			count( $items ),
			$items,
			empty( $items ) ? __( 'No redundant links found.', 'native-link-health' ) : __( 'Repeated links were found on individual pages.', 'native-link-health' )
		);
	}

	/**
	 * Classifies a measured length against a recommended min/max range.
	 *
	 * @param int $length Measured length.
	 * @param int $min    Recommended minimum.
	 * @param int $max    Recommended maximum.
	 * @return string 'missing', 'short', 'long', or 'ok'.
	 */
	private function classify_length( int $length, int $min, int $max ): string {
		if ( 0 === $length ) {
			return 'missing';
		}

		if ( $length < $min ) {
			return 'short';
		}

		if ( $length > $max ) {
			return 'long';
		}

		return 'ok';
	}

	/**
	 * Auto-detects a "focus keyword" from a post title: the longest word of
	 * at least 4 characters that is not a common stop word. There is no
	 * user-facing focus-keyword field in WordPress core, so this is a
	 * heuristic, not a configured value.
	 *
	 * @param string $title Post title.
	 * @return string Lowercased keyword, or '' if no candidate qualifies.
	 */
	private function extract_focus_keyword( string $title ): string {
		$stop = array_fill_keys( $this->stopwords, true );

		preg_match_all( '/[\p{L}\p{N}]{4,}/u', mb_strtolower( $title ), $matches );
		$candidates = array_values( array_diff( $matches[0], array_keys( $stop ) ) );

		if ( empty( $candidates ) ) {
			return '';
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);

		return $candidates[0];
	}

	/**
	 * Returns public posts/pages.
	 *
	 * @return WP_Post[]
	 */
	private function get_public_posts(): array {
		$all_posts = array();
		$paged     = 1;

		do {
			$posts = get_posts(
				array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'paged'          => $paged,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			$all_posts = array_merge( $all_posts, $posts );
			++$paged;
		} while ( count( $posts ) === 100 );

		return $all_posts;
	}

	/**
	 * Returns post/page IDs linked from any registered navigation menu.
	 *
	 * Pages reachable only through a menu are not orphans, so menu targets
	 * must be counted as inbound links.
	 *
	 * @return int[]
	 */
	private function get_nav_menu_linked_ids(): array {
		$ids = array();

		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );

			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( 'post_type' === $item->type && (int) $item->object_id > 0 ) {
					$ids[] = (int) $item->object_id;
				} elseif ( ! empty( $item->url ) ) {
					$resolved = url_to_postid( $item->url );

					if ( $resolved > 0 ) {
						$ids[] = $resolved;
					}
				}
			}
		}

		return $ids;
	}

	/**
	 * Extracts tag attribute values.
	 *
	 * @param string $content HTML content.
	 * @param string $tag_name Tag name.
	 * @param string $attribute Attribute name.
	 * @param array  $required_attributes Required attributes.
	 * @return string[]
	 */
	private function extract_attribute_values( string $content, string $tag_name, string $attribute, array $required_attributes = array() ): array {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$processor = new WP_HTML_Tag_Processor( $content );
		$values    = array();

		while ( $processor->next_tag( array( 'tag_name' => $tag_name ) ) ) {
			foreach ( $required_attributes as $required_attribute => $required_value ) {
				if ( strtolower( (string) $processor->get_attribute( $required_attribute ) ) !== strtolower( (string) $required_value ) ) {
					continue 2;
				}
			}

			$value = $processor->get_attribute( $attribute );

			if ( is_string( $value ) && '' !== $value ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * Extracts all src attribute values from supported HTML tags.
	 *
	 * @param string $content HTML content.
	 * @return string[]
	 */
	private function extract_src_values( string $content ): array {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$processor = new WP_HTML_Tag_Processor( $content );
		$values    = array();

		while ( $processor->next_tag() ) {
			$value = $processor->get_attribute( 'src' );

			if ( is_string( $value ) && '' !== $value ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * Formats multiple post IDs for JSON output.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array
	 */
	private function format_post_items( array $post_ids ): array {
		return array_map( array( $this, 'format_post_item' ), $post_ids );
	}

	/**
	 * Formats one post item.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $detail Detail text.
	 * @return array
	 */
	private function format_post_item( int $post_id, string $detail = '' ): array {
		return array(
			'post_id' => $post_id,
			'title'   => get_the_title( $post_id ),
			'url'     => get_permalink( $post_id ),
			'detail'  => $detail,
		);
	}

	/**
	 * Builds a normalized audit result.
	 *
	 * @param string $status Status.
	 * @param int    $count Count.
	 * @param array  $items Items.
	 * @param string $message Message.
	 * @return array
	 */
	private function result( string $status, int $count, array $items, string $message ): array {
		return array(
			'status'  => $status,
			'count'   => $count,
			'items'   => $items,
			'message' => $message,
		);
	}
}

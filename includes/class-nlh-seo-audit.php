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
	 * Finds published posts/pages with no internal inbound links.
	 *
	 * @return array
	 */
	public function audit_orphan_pages(): array {
		$posts       = $this->get_public_posts();
		$all_ids     = wp_list_pluck( $posts, 'ID' );
		$front_page  = (int) get_option( 'page_on_front' );
		$linked_ids  = array();

		foreach ( $posts as $post ) {
			foreach ( $this->extract_attribute_values( $post->post_content, 'A', 'href' ) as $href ) {
				$linked_id = url_to_postid( $href );

				if ( $linked_id > 0 ) {
					$linked_ids[] = $linked_id;
				}
			}
		}

		$orphans = array_values( array_diff( $all_ids, array_unique( $linked_ids ), array( $front_page ) ) );

		return $this->result(
			empty( $orphans ) ? 'pass' : 'warning',
			count( $orphans ),
			$this->format_post_items( $orphans ),
			empty( $orphans ) ? __( 'No orphan pages found.', 'native-link-health' ) : __( 'Pages without internal inbound links were found.', 'native-link-health' )
		);
	}

	/**
	 * Redirect chain audit placeholder.
	 *
	 * @return array
	 */
	public function audit_redirect_chains(): array {
		return $this->result( 'warning', 0, array(), __( 'Redirect chain auditing requires HEAD scan history.', 'native-link-health' ) );
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
	 * Returns public posts/pages.
	 *
	 * @return WP_Post[]
	 */
	private function get_public_posts(): array {
		return get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
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

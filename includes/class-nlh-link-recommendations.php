<?php
/**
 * Active link-juice recommendations.
 *
 * Generates prioritized, plain-language suggestions for improving internal
 * link-juice distribution, computed entirely offline from the link map and
 * scores. Suggestions are advisory: they deep-link to the editor (inserting or
 * removing an anchor cannot be done safely with WP_HTML_Tag_Processor — only
 * re-pointing an existing link, which the table already offers).
 *
 * Relevance for "which page to link" cascades: shared taxonomy term -> shared
 * title keyword -> highest authority (linking from a high-juice page is the
 * strongest suggestion regardless).
 *
 * @package NativeLinkHealth
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the recommendation feed for the Link Juice page.
 */
class NLH_Link_Recommendations {
	/**
	 * Common stop words (EN + ES) excluded from title-keyword matching.
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
	 * Returns a prioritized list of recommendations.
	 *
	 * @param int $limit Maximum number of recommendations.
	 * @return array<int,array<string,mixed>>
	 */
	public function get( int $limit = 8 ): array {
		global $wpdb;

		$scores_table = $wpdb->prefix . self::scores_table();
		$rows         = $wpdb->get_results(
			"SELECT s.post_id, s.pagerank, s.inbound_internal, s.outbound_internal, s.outbound_total, p.post_title, p.post_type
			 FROM {$scores_table} s
			 INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id"
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$threshold = NLH_Link_Graph::get_dilution_threshold();
		$front     = (int) get_option( 'page_on_front' );
		$posts_pg  = (int) get_option( 'page_for_posts' );

		$score_by_post = array();
		$pageranks     = array();
		$ids           = array();
		foreach ( $rows as $row ) {
			$pid                   = (int) $row->post_id;
			$ids[]                 = $pid;
			$pageranks[]           = (float) $row->pagerank;
			$score_by_post[ $pid ] = $row;
		}

		$max_pr    = max( $pageranks );
		$median_pr = $this->median( $pageranks );

		$terms_by_post            = $this->build_term_index( $ids );
		$words_by_post            = $this->build_word_index( $score_by_post );
		list( $out_adj, $in_adj ) = $this->build_adjacency();

		$recs = array();

		foreach ( $rows as $row ) {
			$pid       = (int) $row->post_id;
			$pr        = (float) $row->pagerank;
			$inbound   = (int) $row->inbound_internal;
			$out_int   = (int) $row->outbound_internal;
			$out_total = (int) $row->outbound_total;
			$is_struct = in_array( $pid, array( $front, $posts_pg ), true );

			// A) Trapped authority ("juice hoarder"): receives juice but passes
			// none onward. Highest leverage when the page itself ranks well.
			if ( $inbound > 0 && 0 === $out_int && $pr >= $median_pr ) {
				$pool        = $this->candidates_excluding( $ids, $pid, $out_adj[ $pid ] ?? array() );
				$suggestions = $this->find_related( $pid, $pool, 3, $terms_by_post, $words_by_post, $score_by_post );
				$recs[]      = array(
					'type'        => 'hoarder',
					'severity'    => $pr >= ( $median_pr + ( $max_pr - $median_pr ) / 2 ) ? 'high' : 'medium',
					'post'        => $this->post_ref( $pid ),
					'title'       => __( 'Authority is trapped here', 'native-link-health' ),
					'message'     => __( 'This page receives internal authority but does not link out to any other page, so its "juice" stays trapped. Add a few internal links from it to related pages to share that authority.', 'native-link-health' ),
					'action'      => 'link_to',
					'suggestions' => $suggestions,
					'impact'      => 200.0 + ( $max_pr > 0 ? ( $pr / $max_pr ) * 100 : 0 ),
				);
				continue;
			}

			// B) Orphan: nothing links to it, so it gets no authority and is hard
			// to discover. Suggest linking from related, high-authority pages.
			if ( 0 === $inbound && ! $is_struct ) {
				$pool        = $this->candidates_excluding( $ids, $pid, $in_adj[ $pid ] ?? array() );
				$suggestions = $this->find_related( $pid, $pool, 3, $terms_by_post, $words_by_post, $score_by_post );
				$best_pr     = ! empty( $suggestions ) ? (float) $suggestions[0]['pagerank'] : 0.0;
				$recs[]      = array(
					'type'        => 'orphan',
					'severity'    => $best_pr >= $median_pr ? 'medium' : 'low',
					'post'        => $this->post_ref( $pid ),
					'title'       => __( 'No internal links point here', 'native-link-health' ),
					'message'     => __( 'No other page links to this one, so it receives no internal authority and is harder for visitors and search engines to find. Add a link to it from a related page below.', 'native-link-health' ),
					'action'      => 'link_from',
					'suggestions' => $suggestions,
					'impact'      => 100.0 + ( $max_pr > 0 ? ( $best_pr / $max_pr ) * 100 : 0 ),
				);
				continue;
			}

			// C) Diluted: so many outbound links that each passes very little.
			if ( $out_total > $threshold ) {
				$recs[] = array(
					'type'        => 'diluted',
					'severity'    => $out_total > ( $threshold * 2 ) ? 'medium' : 'low',
					'post'        => $this->post_ref( $pid ),
					'title'       => __( 'Authority is spread too thin', 'native-link-health' ),
					'message'     => sprintf(
						/* translators: %d: number of outbound links. */
						__( 'This page has %d outgoing links, so each one passes only a tiny share of authority. Review them in "Manage links" and keep the ones that matter most.', 'native-link-health' ),
						$out_total
					),
					'action'      => 'review',
					'suggestions' => array(),
					'impact'      => 50.0 + min( $out_total / max( 1, $threshold ), 3 ) * 30,
				);
			}
		}

		usort(
			$recs,
			static function ( $a, $b ) {
				return $b['impact'] <=> $a['impact'];
			}
		);

		return array_slice( $recs, 0, max( 1, $limit ) );
	}

	/**
	 * Returns candidate IDs excluding the focal post and an exclusion set.
	 *
	 * @param int[] $ids       All node IDs.
	 * @param int   $focal     Focal post ID.
	 * @param int[] $excluded  IDs to exclude (already linked).
	 * @return int[]
	 */
	private function candidates_excluding( array $ids, int $focal, array $excluded ): array {
		$skip           = array_fill_keys( $excluded, true );
		$skip[ $focal ] = true;

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $skip ) {
					return ! isset( $skip[ $id ] );
				}
			)
		);
	}

	/**
	 * Ranks candidates by relevance to a focal post (cascade) and returns the
	 * top K as suggestion references.
	 *
	 * @param int   $focal         Focal post ID.
	 * @param int[] $candidates    Candidate IDs.
	 * @param int   $k             How many to return.
	 * @param array $terms_by_post Term index.
	 * @param array $words_by_post Word index.
	 * @param array $score_by_post Score rows by post ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function find_related( int $focal, array $candidates, int $k, array $terms_by_post, array $words_by_post, array $score_by_post ): array {
		$focal_terms = $terms_by_post[ $focal ] ?? array();
		$focal_words = $words_by_post[ $focal ] ?? array();

		$scored = array();
		foreach ( $candidates as $cid ) {
			$shared_terms = ! empty( $focal_terms ) ? count( array_intersect( $focal_terms, $terms_by_post[ $cid ] ?? array() ) ) : 0;
			$shared_words = ! empty( $focal_words ) ? count( array_intersect( $focal_words, $words_by_post[ $cid ] ?? array() ) ) : 0;
			$pr           = isset( $score_by_post[ $cid ] ) ? (float) $score_by_post[ $cid ]->pagerank : 0.0;

			$scored[] = array(
				'id'           => $cid,
				'shared_terms' => $shared_terms,
				'shared_words' => $shared_words,
				'pagerank'     => $pr,
			);
		}

		// Cascade: shared taxonomy term -> shared title keyword -> authority.
		usort(
			$scored,
			static function ( $a, $b ) {
				if ( $a['shared_terms'] !== $b['shared_terms'] ) {
					return $b['shared_terms'] <=> $a['shared_terms'];
				}
				if ( $a['shared_words'] !== $b['shared_words'] ) {
					return $b['shared_words'] <=> $a['shared_words'];
				}
				return $b['pagerank'] <=> $a['pagerank'];
			}
		);

		$out = array();
		foreach ( array_slice( $scored, 0, max( 1, $k ) ) as $item ) {
			$ref             = $this->post_ref( (int) $item['id'] );
			$ref['pagerank'] = (float) $item['pagerank'];
			$ref['related']  = $item['shared_terms'] > 0 || $item['shared_words'] > 0;
			$out[]           = $ref;
		}

		return $out;
	}

	/**
	 * Builds a taxonomy-term membership index for the given posts in one query.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int,int[]>
	 */
	private function build_term_index( array $ids ): array {
		global $wpdb;

		$index = array();
		if ( empty( $ids ) ) {
			return $index;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships}
				 WHERE object_id IN ({$placeholders})",
				$ids
			)
		);

		foreach ( (array) $rows as $row ) {
			$index[ (int) $row->object_id ][] = (int) $row->term_taxonomy_id;
		}

		return $index;
	}

	/**
	 * Builds a significant-title-word index from score rows.
	 *
	 * @param array $score_by_post Score rows keyed by post ID (have post_title).
	 * @return array<int,string[]>
	 */
	private function build_word_index( array $score_by_post ): array {
		$stop  = array_fill_keys( $this->stopwords, true );
		$index = array();

		foreach ( $score_by_post as $pid => $row ) {
			$title = strtolower( (string) $row->post_title );
			$title = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $title );
			$words = preg_split( '/\s+/', (string) $title, -1, PREG_SPLIT_NO_EMPTY );
			$keep  = array();

			foreach ( (array) $words as $word ) {
				if ( mb_strlen( $word ) >= 4 && ! isset( $stop[ $word ] ) ) {
					$keep[ $word ] = true;
				}
			}

			$index[ (int) $pid ] = array_keys( $keep );
		}

		return $index;
	}

	/**
	 * Builds in/out adjacency from the internal link map in one query.
	 *
	 * @return array{0:array<int,int[]>,1:array<int,int[]>} [out_adj, in_adj]
	 */
	private function build_adjacency(): array {
		global $wpdb;

		$map_table = $wpdb->prefix . NLH_Link_Graph::MAP_TABLE;
		$rows      = $wpdb->get_results(
			"SELECT DISTINCT source_post_id, target_post_id FROM {$map_table}
			 WHERE link_type = 'internal' AND target_post_id > 0"
		);

		$out = array();
		$in  = array();
		foreach ( (array) $rows as $row ) {
			$s           = (int) $row->source_post_id;
			$t           = (int) $row->target_post_id;
			$out[ $s ][] = $t;
			$in[ $t ][]  = $s;
		}

		return array( $out, $in );
	}

	/**
	 * Builds a display reference for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private function post_ref( int $post_id ): array {
		return array(
			'id'        => $post_id,
			'title'     => get_the_title( $post_id ),
			'edit'      => get_edit_post_link( $post_id, 'raw' ),
			'permalink' => get_permalink( $post_id ),
		);
	}

	/**
	 * Returns the median of a numeric list.
	 *
	 * @param float[] $values Values.
	 * @return float
	 */
	private function median( array $values ): float {
		if ( empty( $values ) ) {
			return 0.0;
		}
		sort( $values );
		$count  = count( $values );
		$middle = (int) floor( ( $count - 1 ) / 2 );

		if ( $count % 2 ) {
			return (float) $values[ $middle ];
		}

		return ( (float) $values[ $middle ] + (float) $values[ $middle + 1 ] ) / 2.0;
	}

	/**
	 * Returns the scores table base name (mirrors NLH_Link_Graph).
	 *
	 * @return string
	 */
	private static function scores_table(): string {
		return NLH_Link_Graph::SCORES_TABLE;
	}
}

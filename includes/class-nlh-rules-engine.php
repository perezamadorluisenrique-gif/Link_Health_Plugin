<?php
/**
 * Rule-based automatic correction engine.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates optional automatic correction rules.
 */
class NLH_Rules_Engine {
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
	 * Returns the first matching rule action.
	 *
	 * @param string $url URL being checked.
	 * @param int    $post_id Post ID.
	 * @param int    $status_code HTTP status code.
	 * @param string $error_message Error message.
	 * @return array|null
	 */
	public function evaluate_rules( string $url, int $post_id, int $status_code, string $error_message ): ?array {
		$rules = get_option( 'nlh_auto_rules', array() );

		if ( is_string( $rules ) ) {
			$decoded = json_decode( $rules, true );
			$rules   = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $rules ) ) {
			return null;
		}

		foreach ( $rules as $rule ) {
			if ( empty( $rule['conditions'] ) || empty( $rule['action'] ) || ! is_array( $rule['conditions'] ) || ! is_array( $rule['action'] ) ) {
				continue;
			}

			$matched = true;

			foreach ( $rule['conditions'] as $condition ) {
				if ( ! $this->matches_condition( (array) $condition, $url, $status_code, $error_message ) ) {
					$matched = false;
					break;
				}
			}

			if ( $matched ) {
				return (array) $rule['action'];
			}
		}

		return null;
	}

	/**
	 * Applies a matching rule action.
	 *
	 * @param array  $action Action definition.
	 * @param string $url Old URL.
	 * @param int    $post_id Post ID.
	 * @return bool
	 */
	public function apply_action( array $action, string $url, int $post_id ): bool {
		if ( 'replace' !== ( $action['type'] ?? '' ) || empty( $action['value'] ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$new_host      = preg_replace( '#^https?://#', '', (string) $action['value'] );
		$parts['host'] = $new_host;
		$new_url       = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : 'https://' ) .
			$parts['host'] .
			( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) .
			( isset( $parts['path'] ) ? $parts['path'] : '' ) .
			( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) .
			( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		$updated       = $this->scanner->update_post_link( $post_id, $url, $new_url );

		if ( $updated ) {
			$this->log_correction( $post_id, $url, $new_url, 'auto' );
		}

		return $updated;
	}

	/**
	 * Logs a correction.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @param string $method Correction method.
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
	 * Tests one condition.
	 *
	 * @param array  $condition Condition.
	 * @param string $url URL.
	 * @param int    $status_code Status code.
	 * @param string $error_message Error message.
	 * @return bool
	 */
	private function matches_condition( array $condition, string $url, int $status_code, string $error_message ): bool {
		$field    = (string) ( $condition['field'] ?? '' );
		$operator = (string) ( $condition['operator'] ?? 'equals' );
		$value    = $condition['value'] ?? '';

		if ( 'domain' === $field ) {
			$actual = (string) wp_parse_url( $url, PHP_URL_HOST );
		} elseif ( 'status_code' === $field ) {
			$actual = $status_code;
		} elseif ( 'url_contains' === $field ) {
			$actual = $url;
		} else {
			$actual = $error_message;
		}

		switch ( $operator ) {
			case 'contains':
				return false !== stripos( (string) $actual, (string) $value );
			case 'in':
				return is_array( $value ) && in_array( (string) $actual, $value, true );
			case 'not_equals':
				return (string) $actual !== (string) $value;
			case 'greater_than':
				return (float) $actual > (float) $value;
			case 'equals':
			default:
				return (string) $actual === (string) $value;
		}
	}
}

<?php
/**
 * Standalone unit tests for the bulk-correction replacement builder.
 *
 * Regression guard for the "Approve All with an empty replacement blanks every
 * matching href" defect: build_bulk_replacement_url() used to return '' for an
 * empty replacement, and ajax_bulk_correct() applies any value that differs
 * from the old URL — so nlh_update_post_link() was called with $new_url = ''.
 * WP_HTML_Tag_Processor::set_attribute( 'href', '' ) writes href="" and reports
 * the update as applied, so the links were silently wiped and their error rows
 * deleted. The builder must therefore never return an empty string.
 *
 * These run without a full WordPress bootstrap: only the few WP functions the
 * tested method touches are stubbed here.
 *
 * Run from the plugin root with the bundled PHP:
 *   php tests/test-bulk-correct.php
 *
 * Exit code is non-zero if any assertion fails, so this is CI-friendly.
 *
 * @package NativeLinkHealth
 */

// The class files guard on ABSPATH; satisfy it without loading WordPress.
define( 'ABSPATH', __DIR__ . '/' );

// Minimal WP stubs — the only WP functions the tested method calls.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) { // phpcs:ignore
		return rtrim( (string) $value, '/\\' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-nlh-scanner.php';
require_once dirname( __DIR__ ) . '/admin/class-nlh-admin.php';

$failures = 0;
$tests    = 0;

/**
 * Tiny assertion helper.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Label.
 * @return void
 */
function nlh_assert( $expected, $actual, string $message ): void {
	global $failures, $tests;
	++$tests;

	if ( $expected === $actual ) {
		echo "  PASS: {$message}\n";
		return;
	}

	++$failures;
	echo "  FAIL: {$message}\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * Invokes a private/protected method via reflection.
 *
 * @param object $object Instance.
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return mixed
 */
function nlh_call_private( object $object, string $method, array $args ) {
	$ref = new ReflectionMethod( $object, $method );
	$ref->setAccessible( true );

	return $ref->invokeArgs( $object, $args );
}

$admin = new NLH_Admin( new NLH_Scanner() );

/**
 * Builds a replacement URL through the private builder.
 *
 * @param string $old_url     Old URL.
 * @param string $pattern     Matched pattern.
 * @param string $replacement Replacement value.
 * @param string $type        Suggestion type.
 * @return string
 */
function nlh_build( string $old_url, string $pattern, string $replacement, string $type = 'domain_death' ): string {
	global $admin;

	return nlh_call_private( $admin, 'build_bulk_replacement_url', array( $old_url, $pattern, $replacement, $type ) );
}

echo "build_bulk_replacement_url() — empty replacement is a no-op, never '':\n";

// The exact shape ajax_bulk_correct() would hit when "Approve All" is clicked
// with the replacement field left blank. Returning $old_url makes the caller's
// `$old_url === $new_url` guard skip the row instead of blanking the href.
$cases = array(
	array( 'https://dead.example/page', 'dead.example', 'domain_death' ),
	array( 'https://dead.example/page?x=1#frag', 'dead.example', 'domain_death' ),
	array( 'http://dead.example:8080/a/b/', 'dead.example', 'domain_death' ),
	array( 'https://site.example/old/path', 'site.example/old', 'path_pattern' ),
	array( '/relative/path', 'relative', 'path_pattern' ),
	array( 'not a url at all', 'not', 'domain_death' ),
);

foreach ( $cases as list( $old_url, $pattern, $type ) ) {
	nlh_assert( $old_url, nlh_build( $old_url, $pattern, '', $type ), "empty replacement is a no-op for {$type}: {$old_url}" );
}

echo "\nbuild_bulk_replacement_url() — real replacements still work:\n";

nlh_assert(
	'https://alive.example/page',
	nlh_build( 'https://dead.example/page', 'dead.example', 'https://alive.example' ),
	'domain swap keeps path'
);

nlh_assert(
	'https://alive.example/page?x=1#frag',
	nlh_build( 'https://dead.example/page?x=1#frag', 'dead.example', 'alive.example' ),
	'bare-host replacement keeps query and fragment'
);

nlh_assert(
	'http://alive.example:8080/a/b/',
	nlh_build( 'http://dead.example:8080/a/b/', 'dead.example', 'http://alive.example' ),
	'domain swap keeps port and trailing slash'
);

nlh_assert(
	'https://site.example/new/path',
	nlh_build( 'https://site.example/old/path', 'site.example/old', 'https://site.example/new', 'path_pattern' ),
	'path pattern rewrite'
);

echo "\n";
echo "Ran {$tests} assertions, {$failures} failure(s).\n";

exit( $failures > 0 ? 1 : 0 );

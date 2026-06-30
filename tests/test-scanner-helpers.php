<?php
/**
 * Standalone unit tests for the pure helpers behind the no-false-positive engine
 * and the URL parser. These run without a full WordPress bootstrap: only the few
 * i18n stubs the tested methods touch are defined here.
 *
 * Run from the plugin root with the bundled PHP:
 *   php tests/test-scanner-helpers.php
 *
 * Exit code is non-zero if any assertion fails, so this is CI-friendly.
 *
 * @package NativeLinkHealth
 */

// The class files guard on ABSPATH; satisfy it without loading WordPress.
define( 'ABSPATH', __DIR__ . '/' );

// Minimal i18n stub — the only WP function the tested methods call.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-nlh-scanner.php';

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

$scanner = new NLH_Scanner();

echo "classify_error_type():\n";
nlh_assert( '5xx', $scanner->classify_error_type( 503, '' ), '503 -> 5xx' );
nlh_assert( '4xx', $scanner->classify_error_type( 404, '' ), '404 -> 4xx' );
nlh_assert( 'fragment', $scanner->classify_error_type( 0, 'Missing anchor fragment' ), 'anchor message -> fragment' );
nlh_assert( 'ssl', $scanner->classify_error_type( 0, 'SSL certificate problem' ), 'ssl message -> ssl' );
nlh_assert( 'dns', $scanner->classify_error_type( 0, 'Could not resolve host: example.test' ), 'dns message -> dns' );
nlh_assert( 'timeout', $scanner->classify_error_type( 0, 'Operation timed out' ), 'timeout message -> timeout' );

echo "\ntrim_url_punctuation():\n";
nlh_assert( 'https://example.com', nlh_call_private( $scanner, 'trim_url_punctuation', array( 'https://example.com.' ) ), 'trailing period stripped' );
nlh_assert( 'https://example.com', nlh_call_private( $scanner, 'trim_url_punctuation', array( 'https://example.com),' ) ), 'unbalanced paren + comma stripped' );
nlh_assert( 'https://en.wikipedia.org/wiki/Foo_(bar)', nlh_call_private( $scanner, 'trim_url_punctuation', array( 'https://en.wikipedia.org/wiki/Foo_(bar)' ) ), 'balanced parens kept' );

echo "\nparse_srcset():\n";
nlh_assert(
	array( 'a.jpg', 'b.jpg' ),
	nlh_call_private( $scanner, 'parse_srcset', array( 'a.jpg 1x, b.jpg 2x' ) ),
	'two candidates with descriptors'
);
nlh_assert(
	array( 'only.jpg' ),
	nlh_call_private( $scanner, 'parse_srcset', array( '  only.jpg  ' ) ),
	'single candidate trimmed'
);

echo "\n";
echo "Ran {$tests} assertions, {$failures} failure(s).\n";

exit( $failures > 0 ? 1 : 0 );

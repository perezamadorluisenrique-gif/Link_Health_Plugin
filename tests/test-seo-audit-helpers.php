<?php
/**
 * Standalone unit tests for the pure helpers behind the SEO audit checks.
 * These run without a full WordPress bootstrap: only the few i18n stubs the
 * tested methods touch are defined here.
 *
 * Run from the plugin root with the bundled PHP:
 *   php tests/test-seo-audit-helpers.php
 *
 * Exit code is non-zero if any assertion fails, so this is CI-friendly.
 *
 * @package NativeLinkHealth
 */

// The class file guards on ABSPATH; satisfy it without loading WordPress.
define( 'ABSPATH', __DIR__ . '/' );

// Minimal i18n stub — the only WP function the tested methods touch.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-nlh-seo-audit.php';

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

$audit = new NLH_SEO_Audit();

echo "classify_length():\n";
nlh_assert( 'missing', nlh_call_private( $audit, 'classify_length', array( 0, 30, 60 ) ), 'zero length -> missing' );
nlh_assert( 'short', nlh_call_private( $audit, 'classify_length', array( 10, 30, 60 ) ), 'below min -> short' );
nlh_assert( 'ok', nlh_call_private( $audit, 'classify_length', array( 45, 30, 60 ) ), 'within range -> ok' );
nlh_assert( 'ok', nlh_call_private( $audit, 'classify_length', array( 30, 30, 60 ) ), 'exactly min -> ok' );
nlh_assert( 'ok', nlh_call_private( $audit, 'classify_length', array( 60, 30, 60 ) ), 'exactly max -> ok' );
nlh_assert( 'long', nlh_call_private( $audit, 'classify_length', array( 61, 30, 60 ) ), 'above max -> long' );

echo "\n{$tests} tests, {$failures} failures.\n";
exit( $failures > 0 ? 1 : 0 );

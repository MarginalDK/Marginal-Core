<?php
/**
 * Test bootstrap.
 *
 * Defines MARGINAL_CORE_TESTS so plugin files skip their ABSPATH guard, and
 * stubs the few WordPress functions that pure code paths reach. Anything
 * needing more of WordPress than this belongs in the manual checklist, not
 * in the unit suite.
 */

declare( strict_types = 1 );

define( 'MARGINAL_CORE_TESTS', true );
define( 'MARGINAL_CORE_DIR', dirname( __DIR__ ) . '/' );

// The updater reads these at call time. Normally marginal-core.php defines
// them; the test suite never loads that file, so it must supply its own.
define( 'MARGINAL_CORE_VERSION', '1.0.0' );
define( 'MARGINAL_CORE_BASENAME', 'marginal-core/marginal-core.php' );
define( 'MARGINAL_CORE_SLUG', 'marginal-core' );

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		return $value;
	}
}

/*
 * Controllable stubs for the updater's WordPress dependencies, so its
 * network, caching and hijack-guard paths are reachable from the suite.
 *
 * Each is backed by a $GLOBALS entry a test sets before calling in, reset
 * per-test in UpdaterTest::setUp() so nothing leaks between tests.
 * `wp_remote_get` also counts its calls, so a test can assert a cached
 * failure makes no further request.
 *
 * The stubbed response mirrors wp_remote_get()'s real shape: an array with
 * 'response' => [ 'code' => ... ] and a 'body' string. There is no real
 * WP_Error here, so a transport failure is represented as an array carrying
 * 'is_wp_error' => true, which the is_wp_error() stub below recognises.
 */

$GLOBALS['marginal_core_test_wp_remote_get_calls']    = 0;
$GLOBALS['marginal_core_test_wp_remote_get_response'] = array();
$GLOBALS['marginal_core_test_transients']             = array();

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		++$GLOBALS['marginal_core_test_wp_remote_get_calls'];

		return $GLOBALS['marginal_core_test_wp_remote_get_response'];
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return is_array( $thing ) && ! empty( $thing['is_wp_error'] );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) && isset( $response['response']['code'] )
			? $response['response']['code']
			: 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) && isset( $response['body'] )
			? (string) $response['body']
			: '';
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( string $key ) {
		return $GLOBALS['marginal_core_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['marginal_core_test_transients'][ $key ] = $value;

		return true;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/modules.php';
require_once __DIR__ . '/../inc/updater.php';

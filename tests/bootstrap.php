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

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		return $value;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/config.php';

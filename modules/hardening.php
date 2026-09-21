<?php
/**
 * Baseline hardening.
 *
 * XML-RPC stays disabled by default: no Marginal client uses Jetpack or the
 * WordPress mobile app, both of which depend on it.
 *
 * The predecessor's DISALLOW_FILE_MODS and IP-whitelist machinery are
 * deliberately absent. That check trusted CF-Connecting-IP unconditionally
 * while allowing 127.0.0.1, so any request could spoof past it; it also
 * fights MainWP's own updates and fails silently when it does.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * Strip the X-Pingback response header.
 *
 * @param mixed $headers
 * @return mixed
 */
function marginal_core_remove_pingback_header( $headers ) {
	if ( ! is_array( $headers ) ) {
		return $headers;
	}

	unset( $headers['X-Pingback'] );

	return $headers;
}

function marginal_core_hardening_boot(): void {
	if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
		define( 'DISALLOW_FILE_EDIT', true );
	}

	add_filter( 'xmlrpc_enabled', '__return_false' );
	add_filter( 'wp_headers', 'marginal_core_remove_pingback_header' );
}

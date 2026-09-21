<?php
/**
 * REST API hardening.
 *
 * Unregisters the user routes for anonymous requests, which closes the
 * easiest user-enumeration path.
 *
 * Known gap, accepted: ?author=N redirects and _embed responses can still
 * leak author slugs. Closing those risks breaking legitimate theme
 * behaviour, which fails the non-intrusive test this plugin is held to.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * @param mixed $endpoints
 * @return mixed
 */
function marginal_core_rest_block_user_endpoints( $endpoints ) {
	if ( ! is_array( $endpoints ) || is_user_logged_in() ) {
		return $endpoints;
	}

	unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

	return $endpoints;
}

function marginal_core_rest_boot(): void {
	add_filter( 'rest_endpoints', 'marginal_core_rest_block_user_endpoints' );
}

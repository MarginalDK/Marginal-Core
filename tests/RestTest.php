<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class RestTest extends TestCase {

	private const USER_ROUTES = array(
		'/wp/v2/users'                => 'users',
		'/wp/v2/users/(?P<id>[\d]+)'  => 'single',
		'/wp/v2/posts'                => 'posts',
	);

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
		$GLOBALS['marginal_core_logged_in'] = false;
	}

	public function test_user_routes_are_removed_for_anonymous_requests(): void {
		$result = marginal_core_rest_block_user_endpoints( self::USER_ROUTES );

		$this->assertArrayNotHasKey( '/wp/v2/users', $result );
		$this->assertArrayNotHasKey( '/wp/v2/users/(?P<id>[\d]+)', $result );
	}

	public function test_other_routes_survive(): void {
		$result = marginal_core_rest_block_user_endpoints( self::USER_ROUTES );

		$this->assertArrayHasKey( '/wp/v2/posts', $result );
	}

	public function test_logged_in_requests_keep_the_user_routes(): void {
		$GLOBALS['marginal_core_logged_in'] = true;

		$this->assertSame( self::USER_ROUTES, marginal_core_rest_block_user_endpoints( self::USER_ROUTES ) );
	}

	public function test_non_array_input_is_returned_unchanged(): void {
		$this->assertFalse( marginal_core_rest_block_user_endpoints( false ) );
	}

	public function test_boot_registers_the_filter(): void {
		marginal_core_rest_boot();

		$this->assertSame(
			array( 'marginal_core_rest_block_user_endpoints' ),
			marginal_core_test_hooks( 'rest_endpoints' )
		);
	}
}

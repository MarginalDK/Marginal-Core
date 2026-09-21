<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class HardeningTest extends TestCase {

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
	}

	public function test_removes_the_pingback_header(): void {
		$headers = array( 'X-Pingback' => 'https://example.test/xmlrpc.php', 'Content-Type' => 'text/html' );

		$this->assertSame(
			array( 'Content-Type' => 'text/html' ),
			marginal_core_remove_pingback_header( $headers )
		);
	}

	public function test_leaves_other_headers_untouched_when_pingback_absent(): void {
		$headers = array( 'Content-Type' => 'text/html' );

		$this->assertSame( $headers, marginal_core_remove_pingback_header( $headers ) );
	}

	public function test_non_array_input_is_returned_unchanged(): void {
		$this->assertNull( marginal_core_remove_pingback_header( null ) );
	}

	public function test_boot_registers_both_filters(): void {
		marginal_core_hardening_boot();

		$this->assertSame( array( '__return_false' ), marginal_core_test_hooks( 'xmlrpc_enabled' ) );
		$this->assertSame( array( 'marginal_core_remove_pingback_header' ), marginal_core_test_hooks( 'wp_headers' ) );
	}

	public function test_boot_disables_the_file_editor(): void {
		marginal_core_hardening_boot();

		$this->assertTrue( defined( 'DISALLOW_FILE_EDIT' ) );
		$this->assertTrue( DISALLOW_FILE_EDIT );
	}
}

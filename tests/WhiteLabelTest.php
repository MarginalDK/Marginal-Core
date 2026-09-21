<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class WhiteLabelTest extends TestCase {

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
	}

	public function test_footer_text_links_to_the_support_url(): void {
		$this->assertStringContainsString( 'https://marginal.dk', marginal_core_admin_footer_text() );
		$this->assertStringContainsString( 'Marginal', marginal_core_admin_footer_text() );
	}

	public function test_login_header_url_is_the_support_url(): void {
		$this->assertSame( 'https://marginal.dk', marginal_core_login_header_url() );
	}

	public function test_login_header_text_names_marginal(): void {
		$this->assertStringContainsString( 'Marginal', marginal_core_login_header_text() );
	}

	public function test_manifest_contains_all_seven_modules(): void {
		$this->assertSame(
			array( 'hardening', 'rest', 'cron-fixes', 'mainwp', 'user-guard', 'widget', 'white-label' ),
			array_keys( marginal_core_modules() )
		);
	}

	public function test_boot_registers_all_three_filters(): void {
		marginal_core_white_label_boot();

		$this->assertSame( array( 'marginal_core_admin_footer_text' ), marginal_core_test_hooks( 'admin_footer_text' ) );
		$this->assertSame( array( 'marginal_core_login_header_url' ), marginal_core_test_hooks( 'login_headerurl' ) );
		$this->assertSame( array( 'marginal_core_login_header_text' ), marginal_core_test_hooks( 'login_headertext' ) );
	}
}

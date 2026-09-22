<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {

	public function test_returns_default_when_constant_absent(): void {
		$this->assertSame(
			'https://marginal.dk',
			marginal_core_config( 'support_url_absent', 'https://marginal.dk' )
		);
	}

	public function test_returns_null_default_when_nothing_given(): void {
		$this->assertNull( marginal_core_config( 'never_defined_key' ) );
	}

	public function test_returns_constant_when_defined(): void {
		define( 'MARGINAL_CORE_SUPPORT_URL_PRESENT', 'https://example.test' );

		$this->assertSame(
			'https://example.test',
			marginal_core_config( 'support_url_present', 'https://marginal.dk' )
		);
	}

	public function test_hyphenated_key_maps_to_underscored_constant(): void {
		// A deliberately fake key: PHP constants are process-wide, so defining
		// a real one here would leak into every later test in the suite.
		define( 'MARGINAL_CORE_FAKE_AGE_DAYS', 14 );

		$this->assertSame( 14, marginal_core_config( 'fake-age-days', 7 ) );
	}

	public function test_parses_comma_separated_list_with_whitespace(): void {
		$this->assertSame(
			[ 'white-label', 'rest' ],
			marginal_core_parse_disabled_modules( ' white-label , rest ' )
		);
	}

	public function test_empty_string_disables_nothing(): void {
		$this->assertSame( [], marginal_core_parse_disabled_modules( '' ) );
	}

	public function test_whitespace_only_disables_nothing(): void {
		$this->assertSame( [], marginal_core_parse_disabled_modules( '  ,  , ' ) );
	}

	public function test_accepts_an_array(): void {
		$this->assertSame( [ 'widget' ], marginal_core_parse_disabled_modules( [ 'widget' ] ) );
	}

	public function test_deduplicates_repeated_slugs(): void {
		$this->assertSame( [ 'rest' ], marginal_core_parse_disabled_modules( 'rest,rest' ) );
	}

	public function test_module_enabled_by_default(): void {
		$this->assertTrue( marginal_core_module_enabled( 'hardening' ) );
	}

	public function test_utm_args_always_carries_the_three_fixed_params(): void {
		$this->assertSame(
			array(
				'utm_source'   => 'client-site.example',
				'utm_medium'   => 'wp-admin',
				'utm_campaign' => 'marginal-core',
				'utm_content'  => 'widget',
			),
			marginal_core_utm_args( 'client-site.example', 'widget' )
		);
	}

	public function test_utm_args_omits_content_when_placement_is_empty(): void {
		$args = marginal_core_utm_args( 'client-site.example', '' );

		$this->assertArrayNotHasKey( 'utm_content', $args );
		$this->assertSame( 'client-site.example', $args['utm_source'] );
		$this->assertSame( 'wp-admin', $args['utm_medium'] );
		$this->assertSame( 'marginal-core', $args['utm_campaign'] );
	}

	public function test_utm_args_includes_content_when_placement_given(): void {
		$this->assertArrayHasKey( 'utm_content', marginal_core_utm_args( 'client-site.example', 'footer' ) );
		$this->assertSame( 'login', marginal_core_utm_args( 'client-site.example', 'login' )['utm_content'] );
	}

	public function test_utm_args_falls_back_to_unknown_for_an_empty_host(): void {
		$args = marginal_core_utm_args( '', 'widget' );

		$this->assertSame( 'unknown', $args['utm_source'] );
		$this->assertNotSame( '', $args['utm_source'] );
	}
}

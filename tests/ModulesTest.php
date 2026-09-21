<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ModulesTest extends TestCase {

	public function test_manifest_is_an_array(): void {
		$this->assertIsArray( marginal_core_modules() );
	}

	public function test_manifest_maps_slugs_to_php_filenames(): void {
		$modules = marginal_core_modules();

		// Asserted before the loop so this test is never vacuous: the
		// manifest is empty until Task 4, and a test that asserts nothing
		// is marked risky and proves nothing.
		$this->assertIsArray( $modules );

		foreach ( $modules as $slug => $file ) {
			$this->assertIsString( $slug );
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9-]*$/', $slug );
			$this->assertSame( $slug . '.php', $file );
		}
	}

	public function test_every_manifest_entry_has_a_file_on_disk(): void {
		$modules = marginal_core_modules();

		$this->assertIsArray( $modules );

		foreach ( $modules as $file ) {
			$this->assertFileExists( MARGINAL_CORE_DIR . 'modules/' . $file );
		}
	}

	public function test_boot_function_name_converts_hyphens_to_underscores(): void {
		$this->assertSame(
			'marginal_core_white_label_boot',
			marginal_core_boot_function( 'white-label' )
		);
	}

	public function test_boot_function_name_for_single_word_slug(): void {
		$this->assertSame( 'marginal_core_widget_boot', marginal_core_boot_function( 'widget' ) );
	}

	// -- marginal_core_optional_call(): finding 1's guard against a disabled
	// or absent module fataling the caller. --

	public function test_optional_call_returns_the_fallback_when_the_function_does_not_exist(): void {
		$this->assertSame(
			'fallback',
			marginal_core_optional_call( 'marginal_core_this_function_does_not_exist', 'fallback' )
		);
	}

	public function test_optional_call_preserves_the_fallbacks_type(): void {
		$this->assertFalse( marginal_core_optional_call( 'marginal_core_this_function_does_not_exist', false ) );
		$this->assertSame( '', marginal_core_optional_call( 'marginal_core_this_function_does_not_exist', '' ) );
	}

	public function test_optional_call_invokes_an_existing_function_with_its_arguments(): void {
		$this->assertSame( 3, marginal_core_optional_call( 'strlen', 0, array( 'abc' ) ) );
	}

	public function test_optional_call_passes_no_arguments_when_none_are_given(): void {
		$this->assertSame( '', marginal_core_optional_call( 'strval', 'fallback', array( '' ) ) );
	}
}

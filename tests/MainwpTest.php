<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class MainwpTest extends TestCase {

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
		$GLOBALS['marginal_core_options'] = array();
	}

	public function test_alphanumeric_id_is_safe(): void {
		$this->assertTrue( marginal_core_is_safe_unique_id( 'aB3xY9zQ' ) );
	}

	public function test_id_with_symbols_is_unsafe(): void {
		$this->assertFalse( marginal_core_is_safe_unique_id( 'aB3x&Y9z' ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( 'has space' ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( 'plus+sign' ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( 'per%cent' ) );
	}

	public function test_short_id_is_unsafe(): void {
		$this->assertFalse( marginal_core_is_safe_unique_id( 'abc' ) );
	}

	public function test_empty_and_non_string_are_unsafe(): void {
		$this->assertFalse( marginal_core_is_safe_unique_id( '' ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( null ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( 12345678 ) );
	}

	public function test_disconnected_and_unsafe_repairs(): void {
		$this->assertSame( 'repair', marginal_core_unique_id_action( false, 'bad&id' ) );
	}

	public function test_disconnected_and_missing_repairs(): void {
		$this->assertSame( 'repair', marginal_core_unique_id_action( false, '' ) );
	}

	public function test_disconnected_and_safe_does_nothing(): void {
		$this->assertSame( 'none', marginal_core_unique_id_action( false, 'aB3xY9zQ' ) );
	}

	public function test_connected_and_unsafe_only_warns(): void {
		$this->assertSame( 'warn', marginal_core_unique_id_action( true, 'bad&id' ) );
	}

	public function test_connected_and_missing_only_warns(): void {
		$this->assertSame( 'warn', marginal_core_unique_id_action( true, '' ) );
	}

	public function test_connected_and_safe_does_nothing(): void {
		$this->assertSame( 'none', marginal_core_unique_id_action( true, 'aB3xY9zQ' ) );
	}

	public function test_unsafe_constant_sourced_id_only_warns_even_when_disconnected(): void {
		// Writing the option cannot override MAINWP_CHILD_UNIQUEID, so a
		// "repair" here would report success and change nothing.
		$this->assertSame( 'warn', marginal_core_unique_id_action( false, 'bad&id', false ) );
	}

	public function test_safe_constant_sourced_id_still_does_nothing(): void {
		$this->assertSame( 'none', marginal_core_unique_id_action( false, 'aB3xY9zQ', false ) );
	}

	public function test_connected_and_non_repairable_warns(): void {
		$this->assertSame( 'warn', marginal_core_unique_id_action( true, 'bad&id', false ) );
	}

	public function test_repairable_defaults_to_true_for_backwards_compatible_calls(): void {
		$this->assertSame( 'repair', marginal_core_unique_id_action( false, 'bad&id' ) );
	}

	public function test_generated_ids_are_safe_and_32_characters(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$id = marginal_core_generate_unique_id();

			$this->assertSame( 32, strlen( $id ) );
			$this->assertTrue( marginal_core_is_safe_unique_id( $id ) );
		}
	}

	public function test_repair_writes_a_safe_id_when_disconnected(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'bad&id';

		marginal_core_mainwp_maybe_repair();

		$this->assertTrue( marginal_core_is_safe_unique_id( get_option( MARGINAL_CORE_MAINWP_ID_OPTION ) ) );
	}

	public function test_connected_site_is_never_touched(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_KEY_OPTION ] = 'a-public-key';
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ]  = 'bad&id';

		marginal_core_mainwp_maybe_repair();

		$this->assertSame( 'bad&id', get_option( MARGINAL_CORE_MAINWP_ID_OPTION ) );
	}

	public function test_safe_id_is_left_alone_when_disconnected(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'aB3xY9zQaB3xY9zQaB3xY9zQaB3xY9zQ';

		marginal_core_mainwp_maybe_repair();

		$this->assertSame( 'aB3xY9zQaB3xY9zQaB3xY9zQaB3xY9zQ', get_option( MARGINAL_CORE_MAINWP_ID_OPTION ) );
	}

	public function test_throttle_prevents_a_second_repair_in_the_same_window(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'bad&id';
		marginal_core_mainwp_maybe_repair();
		$first = get_option( MARGINAL_CORE_MAINWP_ID_OPTION );

		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'bad&again';
		marginal_core_mainwp_maybe_repair();

		$this->assertSame( 'bad&again', get_option( MARGINAL_CORE_MAINWP_ID_OPTION ) );
		$this->assertTrue( marginal_core_is_safe_unique_id( $first ) );
	}

	public function test_unique_id_prefers_the_constant_over_the_option(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'fromOption123456';

		// The constant is not defined in this suite, so the option wins here.
		$this->assertSame( 'fromOption123456', marginal_core_mainwp_unique_id() );
		$this->assertTrue( marginal_core_mainwp_id_is_repairable() );
	}

	public function test_pick_unique_id_prefers_the_constant_when_present(): void {
		// The assertion that actually matters: MAINWP_CHILD_UNIQUEID cannot be
		// define()d inside the suite without poisoning every later test, so
		// this is the only place the constant-wins branch is exercised.
		$this->assertSame(
			'fromConstant1234',
			marginal_core_pick_unique_id( true, 'fromConstant1234', 'fromOption12345678' )
		);
	}

	public function test_pick_unique_id_keeps_an_unsafe_constant_over_a_safe_option(): void {
		// A regression that preferred the option here would look healthy
		// while the site is actually broken, because MainWP is still reading
		// the unsafe constant value.
		$this->assertSame(
			'bad&id',
			marginal_core_pick_unique_id( true, 'bad&id', 'aB3xY9zQaB3xY9zQaB3xY9zQaB3xY9zQ' )
		);
	}

	public function test_pick_unique_id_falls_back_to_the_option_when_no_constant(): void {
		$this->assertSame(
			'fromOption12345678',
			marginal_core_pick_unique_id( false, 'fromConstant1234', 'fromOption12345678' )
		);
	}

	public function test_pick_unique_id_treats_non_string_input_as_empty(): void {
		$this->assertSame( '', marginal_core_pick_unique_id( true, null, 'fromOption12345678' ) );
		$this->assertSame( '', marginal_core_pick_unique_id( false, 'fromConstant1234', null ) );
	}

	public function test_connection_state_reads_the_public_key(): void {
		$this->assertFalse( marginal_core_mainwp_is_connected() );

		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_KEY_OPTION ] = 'a-public-key';

		$this->assertTrue( marginal_core_mainwp_is_connected() );
	}
}

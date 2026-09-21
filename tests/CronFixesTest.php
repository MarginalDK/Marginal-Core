<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class CronFixesTest extends TestCase {

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
	}

	public function test_adds_the_minute_schedule(): void {
		$result = marginal_core_add_minute_schedule( array() );

		$this->assertArrayHasKey( 'minute', $result );
		$this->assertSame( 60, $result['minute']['interval'] );
		$this->assertSame( 'Every Minute', $result['minute']['display'] );
	}

	public function test_preserves_existing_schedules(): void {
		$existing = array( 'hourly' => array( 'interval' => 3600, 'display' => 'Once Hourly' ) );

		$result = marginal_core_add_minute_schedule( $existing );

		$this->assertSame( $existing['hourly'], $result['hourly'] );
	}

	public function test_does_not_overwrite_an_existing_minute_schedule(): void {
		$existing = array( 'minute' => array( 'interval' => 99, 'display' => 'Theirs' ) );

		$this->assertSame( $existing, marginal_core_add_minute_schedule( $existing ) );
	}

	public function test_non_array_input_is_returned_unchanged(): void {
		$this->assertNull( marginal_core_add_minute_schedule( null ) );
	}

	public function test_boot_registers_both_hooks(): void {
		marginal_core_cron_fixes_boot();

		$this->assertSame( array( 'marginal_core_add_minute_schedule' ), marginal_core_test_hooks( 'cron_schedules' ) );
		$this->assertSame( array( 'marginal_core_silence_mainwp_subsite_notices' ), marginal_core_test_hooks( 'admin_init' ) );
	}
}

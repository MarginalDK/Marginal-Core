<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase {

	private const DAY = 86400;

	/**
	 * @return array<string,mixed>
	 */
	private function healthy_facts(): array {
		return array(
			'search_engines_blocked' => false,
			'debug_in_production'    => false,
			'environment'            => 'production',
			'environment_declared'   => true,
			'backup'                 => array( 'state' => 'ok', 'time' => 1000 ),
			'mainwp_connected'       => true,
			'mainwp_unique_id'       => 'aB3xY9zQaB3xY9zQaB3xY9zQaB3xY9zQ',
			'mainwp_id_safe'         => true,
		);
	}

	public function test_known_environments_pass_through(): void {
		foreach ( array( 'local', 'development', 'staging', 'production' ) as $env ) {
			$this->assertSame( $env, marginal_core_normalize_environment( $env ) );
		}
	}

	public function test_unknown_environment_falls_back_to_production(): void {
		$this->assertSame( 'production', marginal_core_normalize_environment( 'banana' ) );
	}

	public function test_empty_and_non_string_fall_back_to_production(): void {
		$this->assertSame( 'production', marginal_core_normalize_environment( '' ) );
		$this->assertSame( 'production', marginal_core_normalize_environment( null ) );
		$this->assertSame( 'production', marginal_core_normalize_environment( 42 ) );
	}

	public function test_environment_is_case_and_whitespace_insensitive(): void {
		$this->assertSame( 'staging', marginal_core_normalize_environment( '  STAGING ' ) );
	}

	public function test_missing_backup_option_reports_missing(): void {
		$this->assertSame( 'missing', marginal_core_backup_status( false, 7, 100 * self::DAY )['state'] );
		$this->assertSame( 'missing', marginal_core_backup_status( array(), 7, 100 * self::DAY )['state'] );
	}

	public function test_failed_backup_reports_failed(): void {
		$backup = array( 'backup_time' => 100 * self::DAY, 'success' => 0 );

		$this->assertSame( 'failed', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_recent_backup_reports_ok(): void {
		$backup = array( 'backup_time' => 99 * self::DAY, 'success' => 1 );

		$this->assertSame( 'ok', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_old_backup_reports_stale(): void {
		$backup = array( 'backup_time' => 80 * self::DAY, 'success' => 1 );

		$this->assertSame( 'stale', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_backup_exactly_at_the_threshold_is_still_ok(): void {
		$backup = array( 'backup_time' => 93 * self::DAY, 'success' => 1 );

		$this->assertSame( 'ok', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_backup_one_second_past_the_threshold_is_stale(): void {
		$backup = array( 'backup_time' => 93 * self::DAY - 1, 'success' => 1 );

		$this->assertSame( 'stale', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_backup_time_is_returned(): void {
		$backup = array( 'backup_time' => 99 * self::DAY, 'success' => 1 );

		$this->assertSame( 99 * self::DAY, marginal_core_backup_status( $backup, 7, 100 * self::DAY )['time'] );
	}

	public function test_success_false_string_with_recent_backup_reports_failed(): void {
		$backup = array( 'backup_time' => 99 * self::DAY, 'success' => 'false' );

		$this->assertSame( 'failed', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_success_null_with_recent_backup_reports_unknown(): void {
		$backup = array( 'backup_time' => 99 * self::DAY, 'success' => null );

		$this->assertSame( 'unknown', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_success_key_absent_with_recent_backup_reports_unknown(): void {
		$backup = array( 'backup_time' => 99 * self::DAY );

		$this->assertSame( 'unknown', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_success_true_boolean_with_recent_backup_reports_ok(): void {
		$backup = array( 'backup_time' => 99 * self::DAY, 'success' => true );

		$this->assertSame( 'ok', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_success_string_1_with_recent_backup_reports_ok(): void {
		$backup = array( 'backup_time' => 99 * self::DAY, 'success' => '1' );

		$this->assertSame( 'ok', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_backup_time_one_second_in_future_reports_invalid(): void {
		$backup = array( 'backup_time' => 100 * self::DAY + 1, 'success' => 1 );

		$this->assertSame( 'invalid', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_backup_time_far_in_future_reports_invalid(): void {
		$backup = array( 'backup_time' => 200 * self::DAY, 'success' => 1 );

		$this->assertSame( 'invalid', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_failed_backup_produces_warning(): void {
		$facts = $this->healthy_facts();
		$facts['backup'] = array( 'state' => 'failed', 'time' => 1000 );

		$this->assertContains( 'backup', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_unknown_backup_produces_warning(): void {
		$facts = $this->healthy_facts();
		$facts['backup'] = array( 'state' => 'unknown', 'time' => 1000 );

		$this->assertContains( 'backup', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_invalid_backup_produces_warning(): void {
		$facts = $this->healthy_facts();
		$facts['backup'] = array( 'state' => 'invalid', 'time' => 1000 );

		$this->assertContains( 'backup', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_unsafe_id_with_no_mainwp_connected_key_warns(): void {
		$facts = $this->healthy_facts();
		unset( $facts['mainwp_connected'] );
		$facts['mainwp_id_safe'] = false;

		$this->assertContains( 'mainwp-id', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_unsafe_nonempty_id_when_disconnected_still_warns(): void {
		// Finding 4: a disconnected site with an unsafe, non-empty ID (the
		// constant-sourced case marginal_core_mainwp_id_is_repairable() can't
		// fix) used to warn nowhere. It must warn regardless of connection
		// state — only the wording changes.
		$facts = $this->healthy_facts();
		$facts['mainwp_connected'] = false;
		$facts['mainwp_unique_id'] = 'bad&id';
		$facts['mainwp_id_safe']   = false;

		$this->assertContains( 'mainwp-id', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_empty_id_when_disconnected_does_not_warn(): void {
		// The momentary, normal state of a disconnected site between admin
		// loads: marginal_core_mainwp_maybe_repair() clears an empty ID on the
		// next one. Warning about it would false-alarm every fresh onboarding.
		$facts = $this->healthy_facts();
		$facts['mainwp_connected'] = false;
		$facts['mainwp_unique_id'] = '';
		$facts['mainwp_id_safe']   = false;

		$this->assertNotContains( 'mainwp-id', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_mainwp_id_warning_text_differs_when_disconnected(): void {
		$connected_facts = $this->healthy_facts();
		$connected_facts['mainwp_unique_id'] = 'bad&id';
		$connected_facts['mainwp_id_safe']   = false;

		$disconnected_facts = $this->healthy_facts();
		$disconnected_facts['mainwp_connected'] = false;
		$disconnected_facts['mainwp_unique_id'] = 'bad&id';
		$disconnected_facts['mainwp_id_safe']   = false;

		$connected_warning    = $this->find_warning( marginal_core_warnings( $connected_facts ), 'mainwp-id' );
		$disconnected_warning = $this->find_warning( marginal_core_warnings( $disconnected_facts ), 'mainwp-id' );

		$this->assertNotNull( $connected_warning );
		$this->assertNotNull( $disconnected_warning );
		$this->assertNotSame( $connected_warning['text'], $disconnected_warning['text'] );
		$this->assertStringContainsString( 'disconnected', $disconnected_warning['text'] );
	}

	/**
	 * @param array<int,array<string,mixed>> $warnings
	 * @return array<string,mixed>|null
	 */
	private function find_warning( array $warnings, string $id ): ?array {
		foreach ( $warnings as $warning ) {
			if ( $id === $warning['id'] ) {
				return $warning;
			}
		}

		return null;
	}

	public function test_healthy_site_has_no_warnings(): void {
		$this->assertSame( array(), marginal_core_warnings( $this->healthy_facts() ) );
	}

	public function test_blocked_search_engines_warns(): void {
		$facts = $this->healthy_facts();
		$facts['search_engines_blocked'] = true;

		$ids = array_column( marginal_core_warnings( $facts ), 'id' );

		$this->assertContains( 'search-engines', $ids );
	}

	public function test_debug_in_production_warns(): void {
		$facts = $this->healthy_facts();
		$facts['debug_in_production'] = true;

		$this->assertContains( 'debug', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_non_production_environment_warns(): void {
		$facts = $this->healthy_facts();
		$facts['environment'] = 'staging';

		$this->assertContains( 'environment', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_stale_backup_warns(): void {
		$facts = $this->healthy_facts();
		$facts['backup'] = array( 'state' => 'stale', 'time' => 1000 );

		$this->assertContains( 'backup', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_unsafe_id_on_a_connected_site_warns_marginal_only(): void {
		$facts = $this->healthy_facts();
		$facts['mainwp_id_safe'] = false;

		$warnings = marginal_core_warnings( $facts );
		$ids      = array_column( $warnings, 'id' );

		$this->assertContains( 'mainwp-id', $ids );

		foreach ( $warnings as $warning ) {
			if ( 'mainwp-id' === $warning['id'] ) {
				$this->assertTrue( $warning['marginal_only'] );
			}
		}
	}

	public function test_unsafe_id_on_a_disconnected_site_warns_marginal_only(): void {
		$facts = $this->healthy_facts();
		$facts['mainwp_connected'] = false;
		$facts['mainwp_unique_id'] = 'bad&id';
		$facts['mainwp_id_safe']   = false;

		$warnings = marginal_core_warnings( $facts );
		$ids      = array_column( $warnings, 'id' );

		$this->assertContains( 'mainwp-id', $ids );

		foreach ( $warnings as $warning ) {
			if ( 'mainwp-id' === $warning['id'] ) {
				$this->assertTrue( $warning['marginal_only'] );
			}
		}
	}

	public function test_undeclared_environment_warns_marginal_only(): void {
		$facts = $this->healthy_facts();
		$facts['environment_declared'] = false;

		$warnings = array_filter(
			marginal_core_warnings( $facts ),
			static function ( array $w ) {
				return 'environment-undeclared' === $w['id'];
			}
		);

		$this->assertCount( 1, $warnings );
		$this->assertTrue( array_values( $warnings )[0]['marginal_only'] );
	}

	public function test_client_view_hides_marginal_only_warnings(): void {
		$warnings = array(
			array( 'id' => 'a', 'level' => 'warn', 'text' => 'x', 'marginal_only' => false ),
			array( 'id' => 'b', 'level' => 'warn', 'text' => 'y', 'marginal_only' => true ),
		);

		$this->assertCount( 1, marginal_core_visible_warnings( $warnings, false ) );
		$this->assertCount( 2, marginal_core_visible_warnings( $warnings, true ) );
	}

	public function test_summary_text_counts(): void {
		$this->assertSame( 'Everything looks good', marginal_core_summary_text( 0 ) );
		$this->assertSame( '1 item needs attention', marginal_core_summary_text( 1 ) );
		$this->assertSame( '3 items need attention', marginal_core_summary_text( 3 ) );
	}

	public function test_patchstack_absent_regardless_of_other_values(): void {
		$this->assertSame( 'absent', marginal_core_patchstack_state( false, 1, 1, 0 ) );
		$this->assertSame( 'absent', marginal_core_patchstack_state( false, 0, 0, 1 ) );
		$this->assertSame( 'absent', marginal_core_patchstack_state( false, null, '', 'yes' ) );
	}

	public function test_patchstack_present_but_not_activated_is_inactive(): void {
		$this->assertSame( 'inactive', marginal_core_patchstack_state( true, 0, 1, 0 ) );
		$this->assertSame( 'inactive', marginal_core_patchstack_state( true, '0', 1, 0 ) );
	}

	public function test_patchstack_activated_firewall_on_paid_licence_is_protected(): void {
		$this->assertSame( 'protected', marginal_core_patchstack_state( true, 1, 1, 0 ) );
	}

	public function test_patchstack_activated_firewall_on_paid_licence_is_protected_with_string_values(): void {
		$this->assertSame( 'protected', marginal_core_patchstack_state( true, '1', '1', '0' ) );
	}

	public function test_patchstack_free_licence_is_monitored_even_with_firewall_flag_on(): void {
		$this->assertSame( 'monitored', marginal_core_patchstack_state( true, 1, 1, 1 ) );
	}

	public function test_patchstack_firewall_off_is_monitored(): void {
		$this->assertSame( 'monitored', marginal_core_patchstack_state( true, 1, 0, 0 ) );
	}

	public function test_patchstack_garbage_activation_values_never_report_protected(): void {
		$this->assertNotSame( 'protected', marginal_core_patchstack_state( true, null, 1, 0 ) );
		$this->assertNotSame( 'protected', marginal_core_patchstack_state( true, '', 1, 0 ) );
		$this->assertNotSame( 'protected', marginal_core_patchstack_state( true, 'yes', 1, 0 ) );

		$this->assertSame( 'inactive', marginal_core_patchstack_state( true, null, 1, 0 ) );
		$this->assertSame( 'inactive', marginal_core_patchstack_state( true, '', 1, 0 ) );
		$this->assertSame( 'inactive', marginal_core_patchstack_state( true, 'yes', 1, 0 ) );
	}
}

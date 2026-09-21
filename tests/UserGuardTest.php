<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class UserGuardTest extends TestCase {

	private const PROTECTED = array( 'marginal' );

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
	}

	private function blocks( string $cap, int $actor = 2, int $target = 1, string $login = 'marginal', bool $enabled = true, bool $cli = false ): bool {
		return marginal_core_guard_blocks( $cap, $actor, $target, $login, self::PROTECTED, $enabled, $cli );
	}

	public function test_blocks_deleting_a_protected_user(): void {
		$this->assertTrue( $this->blocks( 'delete_user' ) );
	}

	public function test_blocks_editing_a_protected_user(): void {
		$this->assertTrue( $this->blocks( 'edit_user' ) );
	}

	public function test_blocks_demoting_a_protected_user(): void {
		$this->assertTrue( $this->blocks( 'promote_user' ) );
	}

	public function test_blocks_removing_a_protected_user_from_a_site(): void {
		$this->assertTrue( $this->blocks( 'remove_user' ) );
	}

	public function test_allows_unrelated_capabilities(): void {
		$this->assertFalse( $this->blocks( 'edit_posts' ) );
	}

	public function test_allows_action_against_an_unprotected_user(): void {
		$this->assertFalse( $this->blocks( 'delete_user', 2, 3, 'client-editor' ) );
	}

	public function test_protected_user_may_edit_itself(): void {
		$this->assertFalse( $this->blocks( 'edit_user', 1, 1, 'marginal' ) );
	}

	public function test_login_matching_is_case_insensitive(): void {
		$this->assertTrue( $this->blocks( 'delete_user', 2, 1, 'Marginal' ) );
	}

	public function test_master_switch_off_allows_everything(): void {
		$this->assertFalse( $this->blocks( 'delete_user', 2, 1, 'marginal', false ) );
	}

	public function test_wp_cli_bypasses_the_guard(): void {
		$this->assertFalse( $this->blocks( 'delete_user', 2, 1, 'marginal', true, true ) );
	}

	public function test_empty_protected_list_blocks_nothing(): void {
		$this->assertFalse(
			marginal_core_guard_blocks( 'delete_user', 2, 1, 'marginal', array(), true, false )
		);
	}

	public function test_map_meta_cap_denies_a_blocked_capability(): void {
		$user             = new stdClass();
		$user->ID         = 1;
		$user->user_login = 'marginal';

		$GLOBALS['marginal_core_users'][1] = $user;

		$this->assertSame(
			array( 'do_not_allow' ),
			marginal_core_guard_map_meta_cap( array( 'delete_users' ), 'delete_user', 2, array( 1 ) )
		);
	}

	public function test_map_meta_cap_passes_through_an_allowed_capability(): void {
		$user             = new stdClass();
		$user->ID         = 3;
		$user->user_login = 'client-editor';

		$GLOBALS['marginal_core_users'][3] = $user;

		$this->assertSame(
			array( 'delete_users' ),
			marginal_core_guard_map_meta_cap( array( 'delete_users' ), 'delete_user', 2, array( 3 ) )
		);
	}

	public function test_map_meta_cap_passes_through_when_no_target_given(): void {
		$this->assertSame(
			array( 'delete_users' ),
			marginal_core_guard_map_meta_cap( array( 'delete_users' ), 'delete_user', 2, array() )
		);
	}

	public function test_row_actions_lose_delete_for_a_protected_user(): void {
		$user             = new stdClass();
		$user->user_login = 'marginal';

		$actions = marginal_core_guard_row_actions(
			array( 'edit' => 'Edit', 'delete' => 'Delete' ),
			$user
		);

		$this->assertArrayNotHasKey( 'delete', $actions );
		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertArrayHasKey( 'marginal', $actions );
	}

	public function test_row_actions_are_untouched_for_other_users(): void {
		$user             = new stdClass();
		$user->user_login = 'client-editor';

		$actions = marginal_core_guard_row_actions(
			array( 'edit' => 'Edit', 'delete' => 'Delete' ),
			$user
		);

		$this->assertArrayHasKey( 'delete', $actions );
		$this->assertArrayNotHasKey( 'marginal', $actions );
	}

	public function test_boot_registers_its_hooks(): void {
		marginal_core_user_guard_boot();

		$this->assertSame( array( 'marginal_core_guard_map_meta_cap' ), marginal_core_test_hooks( 'map_meta_cap' ) );
		$this->assertSame( array( 'marginal_core_guard_row_actions' ), marginal_core_test_hooks( 'user_row_actions' ) );
		$this->assertSame( array( 'marginal_core_guard_block_delete' ), marginal_core_test_hooks( 'delete_user' ) );
	}

	// -- marginal_core_guard_block_delete(): the unconditional backstop -----
	//
	// Registration alone (test_boot_registers_its_hooks above) does not
	// execute this function's body. It is the layer that still holds when
	// map_meta_cap is bypassed by a bulk-delete path that skips a
	// per-target capability check, so its branches need direct coverage.

	public function test_block_delete_dies_for_a_protected_user(): void {
		$user             = new stdClass();
		$user->ID         = 1;
		$user->user_login = 'marginal';

		$GLOBALS['marginal_core_users'][1] = $user;

		$this->expectException( MarginalCoreDied::class );
		$this->expectExceptionMessageMatches( '/Marginal/' );

		marginal_core_guard_block_delete( 1 );
	}

	public function test_block_delete_does_not_die_for_an_unprotected_user(): void {
		$user             = new stdClass();
		$user->ID         = 3;
		$user->user_login = 'client-editor';

		$GLOBALS['marginal_core_users'][3] = $user;

		$died = false;

		try {
			marginal_core_guard_block_delete( 3 );
		} catch ( MarginalCoreDied $e ) {
			$died = true;
		}

		$this->assertFalse( $died, 'Deleting an unprotected user must not be blocked.' );
	}

	public function test_block_delete_does_not_die_when_no_user_matches(): void {
		$died = false;

		try {
			marginal_core_guard_block_delete( 999 );
		} catch ( MarginalCoreDied $e ) {
			$died = true;
		}

		$this->assertFalse( $died, 'A user id with no matching account must not trigger the backstop.' );
	}

	// -- marginal_core_guard_fold(): case-folding for comparison ------------

	public function test_guard_fold_lowercases_an_ascii_login(): void {
		$this->assertSame( 'marginal', marginal_core_guard_fold( 'MARGINAL' ) );
	}

	// -- marginal_core_guard_clean_logins(): "0" is a valid login -----------

	public function test_clean_logins_keeps_the_literal_string_zero(): void {
		$this->assertSame(
			array( '0', 'marginal' ),
			marginal_core_guard_clean_logins( array( '0', '', 'marginal' ) )
		);
	}

	public function test_clean_logins_drops_empty_strings(): void {
		$this->assertSame(
			array( 'marginal' ),
			marginal_core_guard_clean_logins( array( '', 'marginal', '' ) )
		);
	}
}

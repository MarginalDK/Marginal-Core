<?php
/**
 * Protects hand-created Marginal accounts from client deletion.
 *
 * The plugin does not create the account: installing the plugin requires
 * admin access, which requires the account to already exist. It protects
 * what onboarding made.
 *
 * Inert unless a listed login exists, so it costs nothing on sites that do
 * not use it.
 *
 * Documented limitation: code calling $user->set_role() directly bypasses
 * capability checks entirely. No plugin-level defence exists for that.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * Capabilities that are denied when aimed at a protected account.
 */
const MARGINAL_CORE_GUARDED_CAPS = array(
	'delete_user',
	'edit_user',
	'promote_user',
	'remove_user',
);

/**
 * The whole decision, as a pure function.
 *
 * Every input is a parameter so the table is unit-testable without
 * WordPress — which is the only way a silent regression here gets caught,
 * since the failure mode is a client successfully deleting our access.
 *
 * @param string[] $protected_logins
 */
function marginal_core_guard_blocks(
	string $cap,
	int $actor_id,
	int $target_id,
	string $target_login,
	array $protected_logins,
	bool $protection_enabled,
	bool $is_cli
): bool {
	if ( ! $protection_enabled || $is_cli ) {
		return false;
	}

	if ( ! in_array( $cap, MARGINAL_CORE_GUARDED_CAPS, true ) ) {
		return false;
	}

	if ( $actor_id === $target_id ) {
		return false;
	}

	$protected = array_map( 'marginal_core_guard_fold', $protected_logins );

	return in_array( marginal_core_guard_fold( $target_login ), $protected, true );
}

/**
 * Case-fold a login for comparison.
 *
 * mb_strtolower where available: strtolower() is byte-oriented and would
 * leave a non-ASCII login uncased, silently failing the protection match.
 */
function marginal_core_guard_fold( string $login ): string {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $login, 'UTF-8' ) : strtolower( $login );
}

/**
 * Strip empty entries from a raw login list.
 *
 * A bare array_filter() treats the string "0" as falsy and would drop it,
 * so a configured login of literally "0" could never be protected. Only an
 * empty string means "no login here".
 *
 * @param string[] $logins
 * @return string[]
 */
function marginal_core_guard_clean_logins( array $logins ): array {
	return array_values(
		array_filter(
			$logins,
			static function ( $login ) {
				return '' !== $login;
			}
		)
	);
}

/**
 * @return string[]
 */
function marginal_core_protected_logins(): array {
	$logins = marginal_core_config( 'protected_users', array( 'marginal' ) );

	if ( ! is_array( $logins ) ) {
		$logins = array( (string) $logins );
	}

	return marginal_core_guard_clean_logins( array_map( 'strval', $logins ) );
}

function marginal_core_is_protected_login( string $login ): bool {
	$protected = array_map( 'marginal_core_guard_fold', marginal_core_protected_logins() );

	return in_array( marginal_core_guard_fold( $login ), $protected, true );
}

function marginal_core_guard_protection_enabled(): bool {
	return (bool) marginal_core_config( 'protection', true );
}

function marginal_core_guard_is_cli(): bool {
	return defined( 'WP_CLI' ) && WP_CLI;
}

/**
 * Deny guarded capabilities at the capability layer.
 *
 * This is the only layer that holds against both the admin UI and the REST
 * API, which is why the guard lives here rather than in the Users screen.
 *
 * @param string[] $caps
 * @param mixed[]  $args
 * @return string[]
 */
function marginal_core_guard_map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
	if ( ! in_array( $cap, MARGINAL_CORE_GUARDED_CAPS, true ) || empty( $args[0] ) ) {
		return $caps;
	}

	$target = get_userdata( (int) $args[0] );

	if ( ! $target ) {
		return $caps;
	}

	$blocked = marginal_core_guard_blocks(
		$cap,
		$user_id,
		(int) $target->ID,
		(string) $target->user_login,
		marginal_core_protected_logins(),
		marginal_core_guard_protection_enabled(),
		marginal_core_guard_is_cli()
	);

	return $blocked ? array( 'do_not_allow' ) : $caps;
}

/**
 * Remove the Delete action and label the row.
 *
 * Visible and explained, not hidden: a hidden account is what malware
 * installs, and a client who finds one has a trust problem.
 *
 * @param string[] $actions
 * @param mixed    $user
 * @return string[]
 */
function marginal_core_guard_row_actions( array $actions, $user ): array {
	if ( ! isset( $user->user_login ) || ! marginal_core_is_protected_login( (string) $user->user_login ) ) {
		return $actions;
	}

	unset( $actions['delete'], $actions['remove'] );

	$actions['marginal'] = '<span style="color:#64748b;">Managed by Marginal</span>';

	return $actions;
}

/**
 * Last-resort backstop on the deletion path itself.
 *
 * wp_delete_user() fires this before removing anything, and bulk deletion
 * does not always perform a per-target capability check — so the capability
 * layer alone is not provably sufficient here.
 */
function marginal_core_guard_block_delete( int $user_id ): void {
	if ( marginal_core_guard_is_cli() || ! marginal_core_guard_protection_enabled() ) {
		return;
	}

	$user = get_userdata( $user_id );

	if ( ! $user || ! marginal_core_is_protected_login( (string) $user->user_login ) ) {
		return;
	}

	wp_die(
		esc_html( 'This account is managed by Marginal and cannot be deleted. Contact Marginal if it needs to be removed.' ),
		'',
		array( 'back_link' => true )
	);
}

function marginal_core_user_guard_boot(): void {
	add_filter( 'map_meta_cap', 'marginal_core_guard_map_meta_cap', 10, 4 );
	add_filter( 'user_row_actions', 'marginal_core_guard_row_actions', 10, 2 );
	add_action( 'delete_user', 'marginal_core_guard_block_delete', 1, 1 );
	add_action( 'wpmu_delete_user', 'marginal_core_guard_block_delete', 1, 1 );
}

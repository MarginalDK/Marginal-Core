<?php
/**
 * MainWP child connection — read-only.
 *
 * This module never writes a MainWP option. It used to: on a site not yet
 * connected, an "unsafe" unique security ID was rewritten with a fresh
 * alphanumeric one. That was removed because it was both harmful and
 * pointless:
 *
 * - MainWP enforces the unique-ID requirement only when the ID is
 *   *non-empty* (MainWP Child's own `class-mainwp-connect.php:118`). An
 *   empty ID is MainWP's default and means the feature is switched off, not
 *   that something is broken. `marginal_core_is_safe_unique_id()` treats
 *   `''` as unsafe (the regex requires 8-64 characters), so the old repair
 *   wrote a real ID into that slot on every fresh, unconnected site — which
 *   *enables* a requirement the Marginal dashboard knows nothing about, and
 *   the site's first connection attempt then fails on `REG_ERROR3`.
 * - Even on a connected site, MainWP generates its own IDs with
 *   `wp_generate_password( 12, false )` — already alphanumeric
 *   (`class-mainwp-helper.php:603-608`). MainWP never produces a
 *   symbol-bearing ID itself, so a symbol-bearing ID can only come from a
 *   human typing one, a host-set `MAINWP_CHILD_UNIQUEID` constant, or an old
 *   MainWP version — and the constant case was already, correctly, left
 *   untouched. There was nothing left for the write to usefully fix.
 *
 * Keep this module read-only. If a repair is ever reconsidered, it must not
 * write anything when the current ID is empty.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

const MARGINAL_CORE_MAINWP_ID_OPTION   = 'mainwp_child_uniqueId';
const MARGINAL_CORE_MAINWP_KEY_OPTION  = 'mainwp_child_pubkey';
const MARGINAL_CORE_MAINWP_ID_CONSTANT = 'MAINWP_CHILD_UNIQUEID';

/**
 * Whether an ID is safe to send through MainWP's handshake.
 *
 * Pure.
 *
 * @param mixed $id
 */
function marginal_core_is_safe_unique_id( $id ): bool {
	return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9]{8,64}$/', $id );
}

function marginal_core_mainwp_is_connected(): bool {
	$key = get_option( MARGINAL_CORE_MAINWP_KEY_OPTION, '' );

	return ! empty( $key );
}

/**
 * Choose between a constant-supplied and an option-supplied ID.
 *
 * Pure, and separated from the `defined()`/`get_option()` calls precisely so
 * this branch is testable: MAINWP_CHILD_UNIQUEID cannot be defined inside the
 * test suite without changing behaviour for every later test.
 *
 * MainWP reads the constant in preference to the option, so we must too — a
 * value read from the option while MainWP reads the constant is not the
 * value MainWP is actually using, and would misreport the site's real state.
 *
 * @param mixed $constant_value
 * @param mixed $option_value
 */
function marginal_core_pick_unique_id( bool $has_constant, $constant_value, $option_value ): string {
	$value = $has_constant ? $constant_value : $option_value;

	return is_string( $value ) ? $value : '';
}

/**
 * The ID MainWP will actually use, resolved the way MainWP resolves it.
 *
 * MainWP_Helper::get_site_unique_id() prefers the constant over the option, so
 * reading the option alone would report a value the site is not using.
 */
function marginal_core_mainwp_unique_id(): string {
	$has_constant = defined( MARGINAL_CORE_MAINWP_ID_CONSTANT );

	return marginal_core_pick_unique_id(
		$has_constant,
		$has_constant ? constant( MARGINAL_CORE_MAINWP_ID_CONSTANT ) : null,
		get_option( MARGINAL_CORE_MAINWP_ID_OPTION, '' )
	);
}

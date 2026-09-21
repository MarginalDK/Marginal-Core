<?php
/**
 * MainWP child connection.
 *
 * The unique security ID breaks connections when it contains symbols. The
 * repair rule is deliberately conservative: rewriting the ID on a connected
 * site would break that connection, because the dashboard still holds the
 * old value. A live site is therefore only ever flagged, never repaired.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

const MARGINAL_CORE_MAINWP_ID_OPTION   = 'mainwp_child_uniqueId';
const MARGINAL_CORE_MAINWP_KEY_OPTION  = 'mainwp_child_pubkey';
const MARGINAL_CORE_MAINWP_ID_CONSTANT = 'MAINWP_CHILD_UNIQUEID';
const MARGINAL_CORE_MAINWP_THROTTLE    = 'marginal_core_mainwp_checked';

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

/**
 * Decide what to do about the current unique security ID.
 *
 * Pure. 'repair' writes a new ID, 'warn' surfaces a widget row, 'none' does
 * nothing.
 *
 * Two separate reasons forbid repair, and conflating them would hide a real
 * problem behind an apparent success:
 *
 * - $connected: rewriting a working ID disconnects the site, because the
 *   dashboard still holds the old value.
 * - ! $repairable: the effective ID comes from the MAINWP_CHILD_UNIQUEID
 *   constant, which MainWP reads in preference to the option. Writing the
 *   option there changes nothing at all — MainWP goes on using the constant —
 *   so a "repair" would report success and fix nothing. Correcting it means
 *   editing wp-config.php, which is not a plugin's business.
 *
 * @param mixed $id
 */
function marginal_core_unique_id_action( bool $connected, $id, bool $repairable = true ): string {
	if ( marginal_core_is_safe_unique_id( $id ) ) {
		return 'none';
	}

	if ( $connected || ! $repairable ) {
		return 'warn';
	}

	return 'repair';
}

/**
 * 32 alphanumeric characters. wp_generate_password() with both symbol flags
 * off should return exactly that character set, but its result also passes
 * through the `random_password` filter — a security or password-policy
 * plugin commonly injects symbols there. Writing an unvalidated result would
 * make the "repair" report success while leaving MainWP unable to connect,
 * with nothing warning about it.
 *
 * The generated value is therefore checked before use, retried a bounded
 * number of times against the same possibly-filtered source, and, if every
 * attempt is still unsafe, built locally by
 * marginal_core_generate_unique_id_locally() instead — a source no filter can
 * reach.
 */
function marginal_core_generate_unique_id(): string {
	for ( $attempt = 0; $attempt < 5; $attempt++ ) {
		$candidate = wp_generate_password( 32, false, false );

		if ( marginal_core_is_safe_unique_id( $candidate ) ) {
			return $candidate;
		}
	}

	return marginal_core_generate_unique_id_locally();
}

/**
 * Last-resort fallback: 32 alphanumeric characters built without
 * wp_generate_password(), so no `random_password` filter can reach it.
 */
function marginal_core_generate_unique_id_locally(): string {
	$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	$id    = '';

	for ( $i = 0; $i < 32; $i++ ) {
		$id .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}

	return $id;
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
 * value we read from the option while MainWP reads the constant is a value we
 * would "repair" without effect.
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

/**
 * Whether the effective ID is one we could actually change.
 *
 * False when the constant is defined: the option we would write is not the
 * value MainWP reads.
 *
 * Documented limitation: MainWP also passes the ID through a
 * `mainwp_child_unique_id` filter, which no plugin can detect statically. A
 * site filtering that value will see the same "writes nothing" behaviour, and
 * there is no way to know in advance.
 */
function marginal_core_mainwp_id_is_repairable(): bool {
	return ! defined( MARGINAL_CORE_MAINWP_ID_CONSTANT );
}

/**
 * Repair the ID if, and only if, the site is not yet connected.
 *
 * Throttled, and skipped outright once a public key exists — so a connected
 * site costs one option read per admin load and nothing else, forever.
 */
function marginal_core_mainwp_maybe_repair(): void {
	if ( marginal_core_mainwp_is_connected() ) {
		return;
	}

	if ( get_transient( MARGINAL_CORE_MAINWP_THROTTLE ) ) {
		return;
	}

	set_transient( MARGINAL_CORE_MAINWP_THROTTLE, 1, 300 );

	$action = marginal_core_unique_id_action(
		false,
		marginal_core_mainwp_unique_id(),
		marginal_core_mainwp_id_is_repairable()
	);

	if ( 'repair' === $action ) {
		update_option( MARGINAL_CORE_MAINWP_ID_OPTION, marginal_core_generate_unique_id() );
	}
}

function marginal_core_mainwp_boot(): void {
	add_action( 'admin_init', 'marginal_core_mainwp_maybe_repair' );
}

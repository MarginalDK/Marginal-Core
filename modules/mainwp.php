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
 * 32 alphanumeric characters — wp_generate_password with both symbol flags
 * off returns exactly that character set.
 */
function marginal_core_generate_unique_id(): string {
	return wp_generate_password( 32, false, false );
}

function marginal_core_mainwp_is_connected(): bool {
	$key = get_option( MARGINAL_CORE_MAINWP_KEY_OPTION, '' );

	return ! empty( $key );
}

/**
 * The ID MainWP will actually use, resolved the way MainWP resolves it.
 *
 * MainWP_Helper::get_site_unique_id() prefers the constant over the option, so
 * reading the option alone would report a value the site is not using.
 */
function marginal_core_mainwp_unique_id(): string {
	if ( defined( MARGINAL_CORE_MAINWP_ID_CONSTANT ) ) {
		$id = constant( MARGINAL_CORE_MAINWP_ID_CONSTANT );

		return is_string( $id ) ? $id : '';
	}

	$id = get_option( MARGINAL_CORE_MAINWP_ID_OPTION, '' );

	return is_string( $id ) ? $id : '';
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

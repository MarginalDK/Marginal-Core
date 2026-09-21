<?php
/**
 * Status facts and warning conditions for the dashboard widget.
 *
 * Separated from rendering so every condition is unit-testable. Two of these
 * checks — the environment and the backup — were caught during design as
 * silent failures in the reassuring direction: they would have reported
 * "fine" while being blind. Treat that as the standing review question for
 * any row added later.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

// Literal rather than DAY_IN_SECONDS so this file needs no WordPress loaded.
const MARGINAL_CORE_DAY = 86400;

/**
 * Coerce any environment value to one WordPress recognises.
 *
 * Pure. Anything unrecognised becomes 'production', matching core's own
 * behaviour — which is exactly why the undeclared case needs its own,
 * separate signal.
 *
 * @param mixed $raw
 */
function marginal_core_normalize_environment( $raw ): string {
	$allowed = array( 'local', 'development', 'staging', 'production' );
	$value   = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';

	return in_array( $value, $allowed, true ) ? $value : 'production';
}

/**
 * Interpret UpdraftPlus's last-backup record.
 *
 * Pure. $now is injected so freshness is testable at the boundary.
 *
 * 'ok' requires positively confirming success. UpdraftPlus writes `success`
 * as 1 or 0, but through an `updraftplus_save_last_backup` filter, so the
 * shape is not ours to rely on — and every unrecognised shape must land on
 * the loud side, never on "fine".
 *
 * @param mixed $last_backup
 * @return array{state: string, time: int|null}
 */
function marginal_core_backup_status( $last_backup, int $max_age_days, int $now ): array {
	if ( ! is_array( $last_backup ) || empty( $last_backup['backup_time'] ) ) {
		return array( 'state' => 'missing', 'time' => null );
	}

	$time = (int) $last_backup['backup_time'];

	// A record dated in the future is evidence of nothing. Left alone it
	// would read as permanently fresh, because a negative age can never
	// exceed the threshold.
	if ( $time > $now ) {
		return array( 'state' => 'invalid', 'time' => $time );
	}

	if ( ! array_key_exists( 'success', $last_backup ) || null === $last_backup['success'] ) {
		return array( 'state' => 'unknown', 'time' => $time );
	}

	$success = $last_backup['success'];

	if ( 1 !== $success && '1' !== $success && true !== $success ) {
		return array( 'state' => 'failed', 'time' => $time );
	}

	return array(
		'state' => ( $now - $time ) > ( $max_age_days * MARGINAL_CORE_DAY ) ? 'stale' : 'ok',
		'time'  => $time,
	);
}

/**
 * Resolve Patchstack's protection state.
 *
 * Pure. Mirrors Patchstack's own firewall gate rather than inventing a test:
 * the plugin itself runs its firewall only when the licence is activated, the
 * basic firewall is on, and the licence is not the free tier
 * (patchstack.php:343 and includes/mu-plugin.php:14 in Patchstack 2.3.7).
 *
 * A free licence still scans for vulnerabilities but runs no firewall, which
 * is why "installed" and "protected" are not the same answer.
 *
 * @param mixed $activated
 * @param mixed $firewall
 * @param mixed $free
 */
function marginal_core_patchstack_state( bool $present, $activated, $firewall, $free ): string {
	if ( ! $present ) {
		return 'absent';
	}

	if ( 1 !== (int) $activated ) {
		return 'inactive';
	}

	if ( 1 === (int) $firewall && 0 === (int) $free ) {
		return 'protected';
	}

	return 'monitored';
}

/**
 * Every warning the current facts justify.
 *
 * Pure. Warnings render only when something is wrong — a row that always
 * says "fine" becomes wallpaper and stops being read.
 *
 * @param array<string,mixed> $facts
 * @return array<int,array{id: string, level: string, text: string, marginal_only: bool}>
 */
function marginal_core_warnings( array $facts ): array {
	$warnings = array();

	if ( ! empty( $facts['search_engines_blocked'] ) ) {
		$warnings[] = array(
			'id'            => 'search-engines',
			'level'         => 'alert',
			'text'          => 'Search engines are blocked from indexing this site.',
			'marginal_only' => false,
		);
	}

	if ( ! empty( $facts['debug_in_production'] ) ) {
		$warnings[] = array(
			'id'            => 'debug',
			'level'         => 'alert',
			'text'          => 'Debug mode is on in production.',
			'marginal_only' => false,
		);
	}

	$environment = isset( $facts['environment'] ) ? (string) $facts['environment'] : 'production';

	if ( 'production' !== $environment ) {
		$warnings[] = array(
			'id'            => 'environment',
			'level'         => 'notice',
			'text'          => sprintf( 'This is the %s environment, not the live site.', $environment ),
			'marginal_only' => false,
		);
	}

	$backup_state = isset( $facts['backup']['state'] ) ? (string) $facts['backup']['state'] : 'missing';

	if ( 'ok' !== $backup_state ) {
		$texts = array(
			'missing' => 'No backups have been recorded for this site.',
			'failed'  => 'The most recent backup did not complete.',
			'stale'   => 'The most recent backup is older than expected.',
			'unknown' => 'The most recent backup did not record whether it succeeded.',
			'invalid' => 'The most recent backup is dated in the future, so its age cannot be trusted.',
		);

		$warnings[] = array(
			'id'            => 'backup',
			'level'         => 'alert',
			'text'          => $texts[ $backup_state ] ?? $texts['missing'],
			'marginal_only' => false,
		);
	}

	$connected = ! array_key_exists( 'mainwp_connected', $facts ) || ! empty( $facts['mainwp_connected'] );
	$id_safe   = array_key_exists( 'mainwp_id_safe', $facts ) && ! empty( $facts['mainwp_id_safe'] );
	$unique_id = isset( $facts['mainwp_unique_id'] ) ? (string) $facts['mainwp_unique_id'] : '';

	// Unsafe-and-non-empty only: an empty ID is the normal, momentary state of
	// a disconnected site between admin loads — marginal_core_mainwp_maybe_repair()
	// clears it on the next one. Warning about that would spray a false alarm
	// onto every fresh onboarding. A non-empty unsafe ID is different: on a
	// connected site rewriting it would break the connection, and on a
	// disconnected site it means the effective ID is constant-sourced (or the
	// repair has not run yet) — either way, worth a distinct explanation of the
	// remedy, since "connected" and "disconnected" call for different fixes.
	if ( ! $id_safe && '' !== $unique_id ) {
		$warnings[] = array(
			'id'            => 'mainwp-id',
			'level'         => 'warn',
			'text'          => $connected
				? 'The MainWP security ID contains unsafe characters. Repair it at a maintenance window — changing it now would break the connection.'
				: 'The MainWP security ID contains unsafe characters and the site is disconnected. If it is set via MAINWP_CHILD_UNIQUEID in wp-config.php, correct it there — this plugin cannot repair a constant-sourced ID; otherwise it should self-repair on the next admin page load.',
			'marginal_only' => true,
		);
	}

	if ( empty( $facts['environment_declared'] ) ) {
		$warnings[] = array(
			'id'            => 'environment-undeclared',
			'level'         => 'warn',
			'text'          => 'WP_ENVIRONMENT_TYPE is not set, so this site reports as production whether it is or not.',
			'marginal_only' => true,
		);
	}

	return $warnings;
}

/**
 * Filter warnings to those the current viewer should see.
 *
 * Pure.
 *
 * @param array<int,array<string,mixed>> $warnings
 * @return array<int,array<string,mixed>>
 */
function marginal_core_visible_warnings( array $warnings, bool $is_marginal_viewer ): array {
	if ( $is_marginal_viewer ) {
		return array_values( $warnings );
	}

	return array_values(
		array_filter(
			$warnings,
			static function ( array $warning ) {
				return empty( $warning['marginal_only'] );
			}
		)
	);
}

/**
 * Pure.
 */
function marginal_core_summary_text( int $count ): string {
	if ( $count < 1 ) {
		return 'Everything looks good';
	}

	return 1 === $count ? '1 item needs attention' : sprintf( '%d items need attention', $count );
}

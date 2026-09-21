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
 * @param mixed $last_backup
 * @return array{state: string, time: int|null}
 */
function marginal_core_backup_status( $last_backup, int $max_age_days, int $now ): array {
	if ( ! is_array( $last_backup ) || empty( $last_backup['backup_time'] ) ) {
		return array( 'state' => 'missing', 'time' => null );
	}

	$time = (int) $last_backup['backup_time'];

	if ( isset( $last_backup['success'] ) && ! $last_backup['success'] ) {
		return array( 'state' => 'failed', 'time' => $time );
	}

	$age = $now - $time;

	return array(
		'state' => $age > ( $max_age_days * MARGINAL_CORE_DAY ) ? 'stale' : 'ok',
		'time'  => $time,
	);
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
		);

		$warnings[] = array(
			'id'            => 'backup',
			'level'         => 'alert',
			'text'          => $texts[ $backup_state ] ?? $texts['missing'],
			'marginal_only' => false,
		);
	}

	if ( ! empty( $facts['mainwp_connected'] ) && empty( $facts['mainwp_id_safe'] ) ) {
		$warnings[] = array(
			'id'            => 'mainwp-id',
			'level'         => 'warn',
			'text'          => 'The MainWP security ID contains unsafe characters. Repair it at a maintenance window — changing it now would break the connection.',
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

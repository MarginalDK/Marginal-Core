<?php
/**
 * Cron and multisite fixes.
 *
 * Both are pure additions with no client-visible effect, carried from the
 * predecessor unchanged in behaviour.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * Register the 'minute' schedule MainWP's System Monitor expects.
 *
 * Without it WP-Cron logs an error for an unrecognised schedule on every
 * run. Never overwrites an existing entry — another plugin may own it.
 *
 * @param mixed $schedules
 * @return mixed
 */
function marginal_core_add_minute_schedule( $schedules ) {
	if ( ! is_array( $schedules ) ) {
		return $schedules;
	}

	if ( ! isset( $schedules['minute'] ) ) {
		$schedules['minute'] = array(
			'interval' => 60,
			'display'  => 'Every Minute',
		);
	}

	return $schedules;
}

/**
 * Unhook MainWP Child's connection notices on multisite sub-sites, where
 * they are noise: the connection belongs to the network, not the sub-site.
 */
function marginal_core_silence_mainwp_subsite_notices(): void {
	if ( ! is_multisite() || is_main_site() ) {
		return;
	}

	$class = '\MainWP\Child\MainWP_Pages';

	if ( ! class_exists( $class ) || ! method_exists( $class, 'get_instance' ) ) {
		return;
	}

	$instance = $class::get_instance();

	remove_action( 'admin_notices', array( $instance, 'admin_notice' ) );
	remove_action( 'all_admin_notices', array( $instance, 'admin_notice' ) );
}

function marginal_core_cron_fixes_boot(): void {
	add_filter( 'cron_schedules', 'marginal_core_add_minute_schedule' );
	add_action( 'admin_init', 'marginal_core_silence_mainwp_subsite_notices' );
}

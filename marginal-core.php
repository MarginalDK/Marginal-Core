<?php
/**
 * Plugin Name: Marginal Core
 * Plugin URI:  https://github.com/MarginalDK/Marginal-Core
 * Description: Marginal agency baseline — hardening, MainWP fixes, white-labelling.
 * Version:     0.9.0
 * Author:      Marginal
 * Author URI:  https://marginal.dk
 * Update URI:  https://github.com/MarginalDK/Marginal-Core
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * A double load — an mu-plugin shim during a migration, say — must be a
 * silent no-op rather than a fleet-wide fatal from redeclared functions.
 */
if ( defined( 'MARGINAL_CORE_VERSION' ) ) {
	return;
}

define( 'MARGINAL_CORE_VERSION', '0.9.0' );
define( 'MARGINAL_CORE_FILE', __FILE__ );
define( 'MARGINAL_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARGINAL_CORE_BASENAME', plugin_basename( __FILE__ ) );
define( 'MARGINAL_CORE_SLUG', 'marginal-core' );

require_once MARGINAL_CORE_DIR . 'inc/config.php';
require_once MARGINAL_CORE_DIR . 'inc/modules.php';
require_once MARGINAL_CORE_DIR . 'inc/updater.php';

register_activation_hook(
	MARGINAL_CORE_FILE,
	static function () {
		if ( function_exists( 'marginal_core_mainwp_maybe_repair' ) ) {
			marginal_core_mainwp_maybe_repair();
		}
	}
);

marginal_core_load_modules();
marginal_core_updater_boot();

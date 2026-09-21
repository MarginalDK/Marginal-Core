<?php
/**
 * Module manifest and loader.
 *
 * Module files declare functions only and expose a boot function the loader
 * calls. Keeping side effects out of file scope is what lets a test require
 * a module without WordPress loaded.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * Slug => filename. Adding a module is a file plus a line here.
 *
 * @return array<string,string>
 */
function marginal_core_modules(): array {
	return array(
		'hardening'   => 'hardening.php',
		'rest'        => 'rest.php',
		'cron-fixes'  => 'cron-fixes.php',
		'mainwp'      => 'mainwp.php',
		'user-guard'  => 'user-guard.php',
		'widget'      => 'widget.php',
		'white-label' => 'white-label.php',
	);
}

/**
 * Boot-function name for a module slug.
 *
 * Pure.
 */
function marginal_core_boot_function( string $slug ): string {
	return 'marginal_core_' . str_replace( '-', '_', $slug ) . '_boot';
}

/**
 * Require and boot every enabled module.
 *
 * A missing file is skipped rather than fatal: a half-extracted release must
 * degrade to fewer features, never to a white screen on a client site.
 */
function marginal_core_load_modules(): void {
	foreach ( marginal_core_modules() as $slug => $file ) {
		if ( ! marginal_core_module_enabled( $slug ) ) {
			continue;
		}

		$path = MARGINAL_CORE_DIR . 'modules/' . $file;

		if ( ! file_exists( $path ) ) {
			continue;
		}

		require_once $path;

		$boot = marginal_core_boot_function( $slug );

		if ( function_exists( $boot ) ) {
			$boot();
		}
	}
}

/**
 * Call a function belonging to a module that might not be loaded, falling
 * back instead of fataling when it is not.
 *
 * `MARGINAL_CORE_DISABLED_MODULES` can legitimately switch a module off —
 * it is a documented, supported per-site knob — so any code outside that
 * module (the widget reading `mainwp`/`user-guard` facts, say) must not call
 * its functions directly. This is the one seam that keeps that promise: a
 * half-extracted release, or a deliberately disabled module, degrades to a
 * missing fact rather than a white screen.
 *
 * Pure given its inputs: which function names exist is part of the running
 * process, so the same arguments always produce the same result within one
 * request, which is what makes the fallback path unit-testable without
 * loading any module at all.
 *
 * @param string  $function Fully-qualified function name to call if it exists.
 * @param mixed   $fallback Returned unchanged when the function does not exist.
 * @param mixed[] $args     Arguments to pass through when it does.
 * @return mixed
 */
function marginal_core_optional_call( string $function, $fallback, array $args = array() ) {
	return function_exists( $function ) ? call_user_func_array( $function, $args ) : $fallback;
}

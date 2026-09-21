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
		// Modules are appended here as they are implemented.
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

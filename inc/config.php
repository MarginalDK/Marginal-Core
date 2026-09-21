<?php
/**
 * Configuration accessor.
 *
 * Every knob is an optional constant set in the site's wp-config.php, so a
 * fleet-wide update never overwrites a per-site choice. Nothing here writes.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * Read one configuration value.
 *
 * @param string $key     Lower-case key, e.g. 'support_url' or 'support-url'.
 * @param mixed  $default Returned when the constant is not defined.
 * @return mixed
 */
function marginal_core_config( string $key, $default = null ) {
	$const = 'MARGINAL_CORE_' . strtoupper( str_replace( '-', '_', $key ) );
	$value = defined( $const ) ? constant( $const ) : $default;

	return apply_filters( 'marginal_core_config_' . $key, $value );
}

/**
 * Normalise a disabled-modules setting into a list of slugs.
 *
 * Pure. Accepts either a comma-separated string (the wp-config.php form) or
 * an array (the filter form), because both are reachable.
 *
 * @param mixed $raw
 * @return string[]
 */
function marginal_core_parse_disabled_modules( $raw ): array {
	$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
	$parts = array_map( 'trim', array_map( 'strval', $parts ) );
	$parts = array_filter(
		$parts,
		static function ( $slug ) {
			return '' !== $slug;
		}
	);

	return array_values( array_unique( $parts ) );
}

/**
 * Whether a module should load on this site.
 */
function marginal_core_module_enabled( string $slug ): bool {
	$disabled = marginal_core_parse_disabled_modules(
		marginal_core_config( 'disabled_modules', '' )
	);

	return ! in_array( $slug, $disabled, true );
}

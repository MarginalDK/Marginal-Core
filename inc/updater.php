<?php
/**
 * Self-update from GitHub releases.
 *
 * Since WordPress 5.8, core reads the Update URI header, extracts its
 * hostname and hands the check to a filter named after it. Pointing the
 * header at the repository therefore gives every site a real update channel
 * with no credentials and no third-party updater plugin.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

const MARGINAL_CORE_RELEASE_API = 'https://api.github.com/repos/MarginalDK/Marginal-Core/releases/latest';
const MARGINAL_CORE_RELEASE_PAGE = 'https://github.com/MarginalDK/Marginal-Core';
const MARGINAL_CORE_UPDATE_TRANSIENT = 'marginal_core_latest_release';

// Twelve hours. Literal rather than HOUR_IN_SECONDS so this file needs no
// WordPress to be required from a test.
const MARGINAL_CORE_UPDATE_TTL = 43200;

// A failed lookup is cached too, for an hour, so an outage costs one request
// per site per hour rather than one per admin page load.
const MARGINAL_CORE_UPDATE_FAILURE_TTL = 3600;

/**
 * Turn a GitHub release payload into a WordPress update array.
 *
 * Pure: no network, no WordPress, no state. Returns null for every unusable
 * input — malformed payload, no newer version, no zip asset — so the caller
 * handles all degradation with a single null check.
 *
 * @param mixed  $release         Decoded GitHub release payload.
 * @param string $current_version Version currently installed.
 * @param string $plugin_file     Plugin basename, e.g. 'marginal-core/marginal-core.php'.
 * @param string $slug            Plugin slug.
 * @return array<string,string>|null
 */
function marginal_core_release_to_update( $release, string $current_version, string $plugin_file, string $slug ): ?array {
	if ( ! is_array( $release ) ) {
		return null;
	}

	$tag = isset( $release['tag_name'] ) && is_string( $release['tag_name'] ) ? $release['tag_name'] : '';
	$version = ltrim( $tag, 'vV' );

	if ( '' === $version || ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
		return null;
	}

	if ( version_compare( $version, $current_version, '<=' ) ) {
		return null;
	}

	// Package host allow-list: browser_download_url is otherwise trusted
	// verbatim, and WordPress will download and unpack whatever it names over
	// the plugin directory. Requires spoofing api.github.com over TLS to
	// exploit, so this is defence-in-depth rather than the primary guard —
	// but one line closes it. A matching release with no asset on an accepted
	// host is treated the same as one with no zip asset at all: unusable.
	$allowed_hosts = array( 'github.com', 'objects.githubusercontent.com' );

	$package = '';
	$assets  = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();

	foreach ( $assets as $asset ) {
		if ( ! is_array( $asset ) ) {
			continue;
		}

		$name = isset( $asset['name'] ) && is_string( $asset['name'] ) ? $asset['name'] : '';
		$url  = isset( $asset['browser_download_url'] ) && is_string( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '';

		if ( '' === $url || '.zip' !== substr( $name, -4 ) ) {
			continue;
		}

		if ( ! in_array( parse_url( $url, PHP_URL_HOST ), $allowed_hosts, true ) ) {
			continue;
		}

		$package = $url;
		break;
	}

	if ( '' === $package ) {
		return null;
	}

	return array(
		'id'      => 'github.com/MarginalDK/Marginal-Core',
		'slug'    => $slug,
		'plugin'  => $plugin_file,
		'version' => $version,
		'url'     => isset( $release['html_url'] ) && is_string( $release['html_url'] ) ? $release['html_url'] : MARGINAL_CORE_RELEASE_PAGE,
		'package' => $package,
	);
}

/**
 * Fetch the latest release, cached.
 *
 * Returns null on any failure. Never throws, never blocks longer than the
 * timeout, and caches failures so an outage does not cost a request per page
 * load.
 *
 * @return array<string,mixed>|null
 */
function marginal_core_fetch_latest_release(): ?array {
	$cached = get_site_transient( MARGINAL_CORE_UPDATE_TRANSIENT );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	if ( 'none' === $cached ) {
		return null;
	}

	$response = wp_remote_get(
		MARGINAL_CORE_RELEASE_API,
		array(
			'timeout' => 5,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'marginal-core/' . MARGINAL_CORE_VERSION,
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_site_transient( MARGINAL_CORE_UPDATE_TRANSIENT, 'none', MARGINAL_CORE_UPDATE_FAILURE_TTL );
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $body ) ) {
		set_site_transient( MARGINAL_CORE_UPDATE_TRANSIENT, 'none', MARGINAL_CORE_UPDATE_FAILURE_TTL );
		return null;
	}

	set_site_transient( MARGINAL_CORE_UPDATE_TRANSIENT, $body, MARGINAL_CORE_UPDATE_TTL );

	return $body;
}

/**
 * Filter callback for update_plugins_github.com.
 *
 * @param mixed  $update      Existing update payload, returned untouched on failure.
 * @param array  $plugin_data Plugin headers.
 * @param string $plugin_file Plugin basename.
 * @param mixed  $locales     Locales, unused.
 * @return mixed
 */
function marginal_core_check_for_update( $update, $plugin_data, $plugin_file, $locales ) {
	if ( MARGINAL_CORE_BASENAME !== $plugin_file ) {
		return $update;
	}

	$release = marginal_core_fetch_latest_release();

	if ( null === $release ) {
		return $update;
	}

	$new = marginal_core_release_to_update( $release, MARGINAL_CORE_VERSION, $plugin_file, MARGINAL_CORE_SLUG );

	return null === $new ? $update : $new;
}

function marginal_core_updater_boot(): void {
	add_filter( 'update_plugins_github.com', 'marginal_core_check_for_update', 10, 4 );
}

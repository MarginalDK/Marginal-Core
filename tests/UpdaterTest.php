<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['marginal_core_test_wp_remote_get_calls']    = 0;
		$GLOBALS['marginal_core_test_wp_remote_get_response'] = array();
		$GLOBALS['marginal_core_test_transients']             = array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function release( string $tag = 'v1.1.0', string $asset = 'marginal-core-1.1.0.zip' ): array {
		return array(
			'tag_name' => $tag,
			'html_url' => 'https://github.com/MarginalDK/Marginal-Core/releases/tag/' . $tag,
			'assets'   => array(
				array(
					'name'                 => $asset,
					'browser_download_url' => 'https://github.com/MarginalDK/Marginal-Core/releases/download/' . $tag . '/' . $asset,
				),
			),
		);
	}

	public function test_newer_version_produces_an_update(): void {
		$update = marginal_core_release_to_update( $this->release(), '1.0.0', 'marginal-core/marginal-core.php', 'marginal-core' );

		$this->assertIsArray( $update );
		$this->assertSame( '1.1.0', $update['version'] );
		$this->assertSame( 'marginal-core', $update['slug'] );
		$this->assertSame( 'marginal-core/marginal-core.php', $update['plugin'] );
		$this->assertStringEndsWith( '.zip', $update['package'] );
	}

	public function test_leading_v_is_stripped_from_the_tag(): void {
		$update = marginal_core_release_to_update( $this->release( 'v2.0.0', 'x-2.0.0.zip' ), '1.0.0', 'p/p.php', 'p' );

		$this->assertSame( '2.0.0', $update['version'] );
	}

	public function test_tag_without_v_prefix_also_works(): void {
		$update = marginal_core_release_to_update( $this->release( '1.2.0', 'x-1.2.0.zip' ), '1.0.0', 'p/p.php', 'p' );

		$this->assertSame( '1.2.0', $update['version'] );
	}

	public function test_same_version_produces_nothing(): void {
		$this->assertNull( marginal_core_release_to_update( $this->release( 'v1.0.0' ), '1.0.0', 'p/p.php', 'p' ) );
	}

	public function test_older_version_produces_nothing(): void {
		$this->assertNull( marginal_core_release_to_update( $this->release( 'v0.9.0' ), '1.0.0', 'p/p.php', 'p' ) );
	}

	public function test_release_with_no_zip_asset_produces_nothing(): void {
		$release = $this->release();
		$release['assets'][0]['name'] = 'notes.txt';

		$this->assertNull( marginal_core_release_to_update( $release, '1.0.0', 'p/p.php', 'p' ) );
	}

	public function test_release_with_no_assets_produces_nothing(): void {
		$release = $this->release();
		$release['assets'] = array();

		$this->assertNull( marginal_core_release_to_update( $release, '1.0.0', 'p/p.php', 'p' ) );
	}

	public function test_malformed_payload_produces_nothing(): void {
		$this->assertNull( marginal_core_release_to_update( 'not an array', '1.0.0', 'p/p.php', 'p' ) );
		$this->assertNull( marginal_core_release_to_update( null, '1.0.0', 'p/p.php', 'p' ) );
		$this->assertNull( marginal_core_release_to_update( array(), '1.0.0', 'p/p.php', 'p' ) );
	}

	public function test_non_numeric_tag_produces_nothing(): void {
		$this->assertNull( marginal_core_release_to_update( $this->release( 'nightly' ), '1.0.0', 'p/p.php', 'p' ) );
	}

	// -- Finding 2: non-string fields must degrade, not corrupt or warn. --

	public function test_array_shaped_html_url_falls_back_to_the_release_page(): void {
		$release = $this->release();
		$release['html_url'] = array( 'not' => 'a string' );

		$update = marginal_core_release_to_update( $release, '1.0.0', 'p/p.php', 'p' );

		$this->assertIsArray( $update );
		$this->assertSame( MARGINAL_CORE_RELEASE_PAGE, $update['url'] );
	}

	public function test_array_shaped_asset_name_is_skipped(): void {
		$release = $this->release();
		$release['assets'][0]['name'] = array( 'not' => 'a string' );

		$this->assertNull( marginal_core_release_to_update( $release, '1.0.0', 'p/p.php', 'p' ) );
	}

	public function test_array_shaped_asset_download_url_is_skipped(): void {
		$release = $this->release();
		$release['assets'][0]['browser_download_url'] = array( 'not' => 'a string' );

		$this->assertNull( marginal_core_release_to_update( $release, '1.0.0', 'p/p.php', 'p' ) );
	}

	// -- Finding 6: the package URL host must be GitHub's own. --

	public function test_package_on_a_foreign_host_is_rejected(): void {
		$release = $this->release();
		$release['assets'][0]['browser_download_url'] = 'https://evil.example/payload.zip';

		$this->assertNull( marginal_core_release_to_update( $release, '1.0.0', 'p/p.php', 'p' ) );
	}

	public function test_package_on_a_lookalike_host_is_rejected(): void {
		$release = $this->release();
		// Not github.com: a naive substring or suffix check would accept this.
		$release['assets'][0]['browser_download_url'] = 'https://github.com.evil.example/payload.zip';

		$this->assertNull( marginal_core_release_to_update( $release, '1.0.0', 'p/p.php', 'p' ) );
	}

	public function test_package_on_github_com_is_accepted(): void {
		$release = $this->release();
		$release['assets'][0]['browser_download_url'] = 'https://github.com/MarginalDK/Marginal-Core/releases/download/v1.1.0/marginal-core-1.1.0.zip';

		$update = marginal_core_release_to_update( $release, '1.0.0', 'p/p.php', 'p' );

		$this->assertIsArray( $update );
		$this->assertSame( $release['assets'][0]['browser_download_url'], $update['package'] );
	}

	public function test_package_on_objects_githubusercontent_com_is_accepted(): void {
		$release = $this->release();
		$release['assets'][0]['browser_download_url'] = 'https://objects.githubusercontent.com/github-production-release-asset/1/abc.zip';

		$update = marginal_core_release_to_update( $release, '1.0.0', 'p/p.php', 'p' );

		$this->assertIsArray( $update );
		$this->assertSame( $release['assets'][0]['browser_download_url'], $update['package'] );
	}

	// -- Finding 1: network, caching and hijack-guard paths. --

	public function test_uncached_transient_triggers_a_request(): void {
		$GLOBALS['marginal_core_test_wp_remote_get_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( $this->release() ),
		);

		marginal_core_fetch_latest_release();

		$this->assertSame( 1, $GLOBALS['marginal_core_test_wp_remote_get_calls'] );
	}

	public function test_cached_none_transient_skips_the_request_and_returns_null(): void {
		$GLOBALS['marginal_core_test_transients'][ MARGINAL_CORE_UPDATE_TRANSIENT ] = 'none';

		$result = marginal_core_fetch_latest_release();

		$this->assertNull( $result );
		$this->assertSame( 0, $GLOBALS['marginal_core_test_wp_remote_get_calls'] );
	}

	public function test_cached_release_array_skips_the_request_and_is_returned(): void {
		$cached = $this->release();
		$GLOBALS['marginal_core_test_transients'][ MARGINAL_CORE_UPDATE_TRANSIENT ] = $cached;

		$result = marginal_core_fetch_latest_release();

		$this->assertSame( $cached, $result );
		$this->assertSame( 0, $GLOBALS['marginal_core_test_wp_remote_get_calls'] );
	}

	public function test_transport_outage_returns_update_untouched_and_caches_the_failure(): void {
		$GLOBALS['marginal_core_test_wp_remote_get_response'] = array( 'is_wp_error' => true );

		$update = array( 'existing' => true );
		$result = marginal_core_check_for_update( $update, array(), MARGINAL_CORE_BASENAME, null );

		$this->assertSame( $update, $result );
		$this->assertSame( 'none', $GLOBALS['marginal_core_test_transients'][ MARGINAL_CORE_UPDATE_TRANSIENT ] );
	}

	public function test_a_cached_failure_makes_zero_further_requests(): void {
		$GLOBALS['marginal_core_test_transients'][ MARGINAL_CORE_UPDATE_TRANSIENT ] = 'none';

		$update = array( 'existing' => true );
		$result = marginal_core_check_for_update( $update, array(), MARGINAL_CORE_BASENAME, null );

		$this->assertSame( $update, $result );
		$this->assertSame( 0, $GLOBALS['marginal_core_test_wp_remote_get_calls'] );
	}

	public function test_non_200_response_caches_failure_and_returns_update_untouched(): void {
		$GLOBALS['marginal_core_test_wp_remote_get_response'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '',
		);

		$update = array( 'existing' => true );
		$result = marginal_core_check_for_update( $update, array(), MARGINAL_CORE_BASENAME, null );

		$this->assertSame( $update, $result );
		$this->assertSame( 'none', $GLOBALS['marginal_core_test_transients'][ MARGINAL_CORE_UPDATE_TRANSIENT ] );
	}

	public function test_invalid_json_caches_failure_and_returns_update_untouched(): void {
		$GLOBALS['marginal_core_test_wp_remote_get_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'not json{',
		);

		$update = array( 'existing' => true );
		$result = marginal_core_check_for_update( $update, array(), MARGINAL_CORE_BASENAME, null );

		$this->assertSame( $update, $result );
		$this->assertSame( 'none', $GLOBALS['marginal_core_test_transients'][ MARGINAL_CORE_UPDATE_TRANSIENT ] );
	}

	public function test_a_different_plugins_basename_is_untouched_and_makes_no_request(): void {
		$update = array( 'existing' => true );
		$result = marginal_core_check_for_update( $update, array(), 'some-other-plugin/some-other-plugin.php', null );

		$this->assertSame( $update, $result );
		$this->assertSame( 0, $GLOBALS['marginal_core_test_wp_remote_get_calls'] );
	}

	public function test_check_for_update_returns_a_correctly_shaped_update_for_a_newer_release(): void {
		$GLOBALS['marginal_core_test_wp_remote_get_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( $this->release( 'v9.9.9', 'marginal-core-9.9.9.zip' ) ),
		);

		$result = marginal_core_check_for_update( false, array(), MARGINAL_CORE_BASENAME, null );

		$this->assertIsArray( $result );
		$this->assertSame( '9.9.9', $result['version'] );
		$this->assertSame( MARGINAL_CORE_SLUG, $result['slug'] );
		$this->assertSame( MARGINAL_CORE_BASENAME, $result['plugin'] );
		$this->assertStringEndsWith( '.zip', $result['package'] );
	}
}

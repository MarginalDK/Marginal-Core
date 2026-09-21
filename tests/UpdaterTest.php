<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {

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
}

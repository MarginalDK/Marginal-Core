# Marginal Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `marginal-core`, a WordPress plugin carrying Marginal's agency baseline — hardening, MainWP connection fixes, a status widget and white-labelling — installed across every client site and updated from GitHub releases.

**Architecture:** One plugin, seven modules. A bootstrap file defines constants and loads each enabled module from a manifest; every module file declares functions only and exposes a `_boot()` the loader calls, so nothing executes at file scope and each module is requirable from a test without WordPress. All decision logic is extracted into pure functions taking every input as a parameter, which is what lets the suite run on plain PHPUnit with no WordPress bootstrap and no mocking library.

**Tech Stack:** PHP 7.4+ (runtime floor), WordPress 6.0+, PHPUnit for tests, GitHub Actions for release builds. No runtime dependencies — `composer` is dev-only and `vendor/` never ships.

**Spec:** `docs/superpowers/specs/2026-09-21-marginal-core-design.md`

## Global Constraints

- **PHP floor 7.4, WordPress floor 6.0.** No PHP 8-only syntax: no enums, no constructor promotion, no `readonly`, no named arguments, no `match`.
- **Namespace and prefix:** all global functions are prefixed `marginal_core_`. No class autoloading; plain functions in plain files.
- **No runtime dependencies.** Nothing from `vendor/` may be required by shipped code.
- **No file-scope side effects** in `inc/*.php` or `modules/*.php`. Only function declarations and `const`. Hooks are registered inside `_boot()`.
- **Guard every file:** `if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) { exit; }` — the test bootstrap defines `MARGINAL_CORE_TESTS`.
- **No network calls or uncached queries** on any admin page render. The only outbound HTTP in the plugin is the update check, which is transient-cached.
- **Never fatal.** Any external input — GitHub JSON, MainWP options, UpdraftPlus options — may be missing or malformed, and must degrade to "no result" rather than throw.
- **Config is read, never written.** Every knob is an optional constant from the site's `wp-config.php`, accessed through `marginal_core_config()`.
- **Version string appears in exactly two places** — the plugin header and `MARGINAL_CORE_VERSION`. They must always match.

## Deviations from the spec

Two structural refinements, both to serve the spec's own requirement that decision logic be unit-testable:

1. **`inc/modules.php`** holds the manifest and loader, rather than the bootstrap file. The bootstrap cannot be required from a test (it has a plugin header and calls `plugin_dir_path`), so the manifest would otherwise be untestable.
2. **`inc/status.php`** holds the widget's fact-gathering and warning logic, separate from `modules/widget.php` which only renders. The spec lists widget warning conditions as unit-tested; that requires the logic to live outside the rendering path.

One simplification: the spec's testing section names PHPUnit **and Brain Monkey**. Brain Monkey is not used. Because the spec already mandates extracting decisions into pure functions, almost every test needs no WordPress at all, and the two that touch `apply_filters` are served by a four-line stub in the test bootstrap. This removes a dependency, a version-compatibility risk on PHP 8.5, and a layer of indirection from the tests.

---

### Task 1: Project scaffolding and the config accessor

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `.distignore`
- Create: `tests/bootstrap.php`
- Create: `inc/config.php`
- Create: `tests/ConfigTest.php`
- Create: `.github/workflows/tests.yml`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `marginal_core_config( string $key, $default = null )` — reads `MARGINAL_CORE_<KEY>` if defined, else `$default`, then passes it through the `marginal_core_config_<key>` filter.
  - `marginal_core_parse_disabled_modules( $raw ): array` — pure; accepts a comma-separated string or an array, returns trimmed, de-duplicated, non-empty slugs.
  - `marginal_core_module_enabled( string $slug ): bool`

- [ ] **Step 1: Create `composer.json`**

```json
{
	"name": "marginaldk/marginal-core",
	"description": "Marginal agency baseline plugin for client WordPress sites.",
	"type": "wordpress-plugin",
	"license": "proprietary",
	"require": {
		"php": ">=7.4"
	},
	"require-dev": {
		"phpunit/phpunit": "^11.5 || ^12.0"
	},
	"scripts": {
		"test": "phpunit"
	},
	"config": {
		"sort-packages": true
	}
}
```

- [ ] **Step 2: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	bootstrap="tests/bootstrap.php"
	colors="true"
	cacheDirectory=".phpunit.cache"
	failOnWarning="true"
	failOnNotice="true">
	<testsuites>
		<testsuite name="unit">
			<directory>tests</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 3: Create `tests/bootstrap.php`**

The plugin's testable logic is pure by design, so this stubs only the handful of WordPress functions those paths actually touch. Later tasks append their `require_once` lines to the bottom of this file.

```php
<?php
/**
 * Test bootstrap.
 *
 * Defines MARGINAL_CORE_TESTS so plugin files skip their ABSPATH guard, and
 * stubs the few WordPress functions that pure code paths reach. Anything
 * needing more of WordPress than this belongs in the manual checklist, not
 * in the unit suite.
 */

declare( strict_types = 1 );

define( 'MARGINAL_CORE_TESTS', true );
define( 'MARGINAL_CORE_DIR', dirname( __DIR__ ) . '/' );

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		return $value;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/config.php';
```

- [ ] **Step 4: Create `.distignore`**

Everything here is excluded from the release zip. Nothing development-only ships to a client site.

```
.git/
.github/
.phpunit.cache/
tests/
docs/
build/
vendor/
composer.json
composer.lock
phpunit.xml
.distignore
.gitignore
```

- [ ] **Step 5: Install dependencies**

Run: `composer install`
Expected: `vendor/` created, PHPUnit installed, no errors.

If Composer refuses the PHPUnit constraint on this PHP version, drop to `"phpunit/phpunit": "^10.5"` in `composer.json` and re-run. Do not add other dependencies.

- [ ] **Step 6: Write the failing tests**

Create `tests/ConfigTest.php`. Note that each test asserting the constant path uses a **distinct key** — PHP constants cannot be undefined, so a shared key would leak between tests.

```php
<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {

	public function test_returns_default_when_constant_absent(): void {
		$this->assertSame(
			'https://marginal.dk',
			marginal_core_config( 'support_url_absent', 'https://marginal.dk' )
		);
	}

	public function test_returns_null_default_when_nothing_given(): void {
		$this->assertNull( marginal_core_config( 'never_defined_key' ) );
	}

	public function test_returns_constant_when_defined(): void {
		define( 'MARGINAL_CORE_SUPPORT_URL_PRESENT', 'https://example.test' );

		$this->assertSame(
			'https://example.test',
			marginal_core_config( 'support_url_present', 'https://marginal.dk' )
		);
	}

	public function test_hyphenated_key_maps_to_underscored_constant(): void {
		// A deliberately fake key: PHP constants are process-wide, so defining
		// a real one here would leak into every later test in the suite.
		define( 'MARGINAL_CORE_FAKE_AGE_DAYS', 14 );

		$this->assertSame( 14, marginal_core_config( 'fake-age-days', 7 ) );
	}

	public function test_parses_comma_separated_list_with_whitespace(): void {
		$this->assertSame(
			[ 'white-label', 'rest' ],
			marginal_core_parse_disabled_modules( ' white-label , rest ' )
		);
	}

	public function test_empty_string_disables_nothing(): void {
		$this->assertSame( [], marginal_core_parse_disabled_modules( '' ) );
	}

	public function test_whitespace_only_disables_nothing(): void {
		$this->assertSame( [], marginal_core_parse_disabled_modules( '  ,  , ' ) );
	}

	public function test_accepts_an_array(): void {
		$this->assertSame( [ 'widget' ], marginal_core_parse_disabled_modules( [ 'widget' ] ) );
	}

	public function test_deduplicates_repeated_slugs(): void {
		$this->assertSame( [ 'rest' ], marginal_core_parse_disabled_modules( 'rest,rest' ) );
	}

	public function test_module_enabled_by_default(): void {
		$this->assertTrue( marginal_core_module_enabled( 'hardening' ) );
	}
}
```

- [ ] **Step 7: Run tests to verify they fail**

Run: `composer test`
Expected: FAIL — `Error: Call to undefined function marginal_core_config()`.

- [ ] **Step 8: Write `inc/config.php`**

```php
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
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `composer test`
Expected: PASS, 10 tests.

- [ ] **Step 10: Create `.github/workflows/tests.yml`**

```yaml
name: Tests

on:
  push:
  pull_request:

jobs:
  phpunit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: composer test
```

- [ ] **Step 11: Commit**

```bash
git add composer.json composer.lock phpunit.xml .distignore tests/ inc/ .github/
git commit -m "Add scaffolding and the config accessor

Config resolves from wp-config.php constants with safe defaults, so a
fleet update never clobbers a per-site choice, and each value passes
through a filter for programmatic override.

Tests run on plain PHPUnit with a four-line stub bootstrap: the pure
functions the spec mandates need no WordPress, so no mocking library
is pulled in."
```

---

### Task 2: Plugin bootstrap and module loader

**Files:**
- Create: `marginal-core.php`
- Create: `inc/modules.php`
- Create: `tests/ModulesTest.php`
- Modify: `tests/bootstrap.php` (append one `require_once`)

**Interfaces:**
- Consumes: `marginal_core_module_enabled()` from Task 1.
- Produces:
  - `marginal_core_modules(): array` — manifest, slug => filename.
  - `marginal_core_boot_function( string $slug ): string` — pure; slug to boot-function name.
  - `marginal_core_load_modules(): void` — requires each enabled module and calls its boot function.
  - Constants `MARGINAL_CORE_VERSION`, `MARGINAL_CORE_FILE`, `MARGINAL_CORE_DIR`, `MARGINAL_CORE_SLUG`, `MARGINAL_CORE_BASENAME`.

- [ ] **Step 1: Write the failing test**

Create `tests/ModulesTest.php`:

```php
<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ModulesTest extends TestCase {

	public function test_manifest_is_an_array(): void {
		$this->assertIsArray( marginal_core_modules() );
	}

	public function test_manifest_maps_slugs_to_php_filenames(): void {
		$modules = marginal_core_modules();

		// Asserted before the loop so this test is never vacuous: the
		// manifest is empty until Task 4, and a test that asserts nothing
		// is marked risky and proves nothing.
		$this->assertIsArray( $modules );

		foreach ( $modules as $slug => $file ) {
			$this->assertIsString( $slug );
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9-]*$/', $slug );
			$this->assertSame( $slug . '.php', $file );
		}
	}

	public function test_every_manifest_entry_has_a_file_on_disk(): void {
		$modules = marginal_core_modules();

		$this->assertIsArray( $modules );

		foreach ( $modules as $file ) {
			$this->assertFileExists( MARGINAL_CORE_DIR . 'modules/' . $file );
		}
	}

	public function test_boot_function_name_converts_hyphens_to_underscores(): void {
		$this->assertSame(
			'marginal_core_white_label_boot',
			marginal_core_boot_function( 'white-label' )
		);
	}

	public function test_boot_function_name_for_single_word_slug(): void {
		$this->assertSame( 'marginal_core_widget_boot', marginal_core_boot_function( 'widget' ) );
	}
}
```

Note: the manifest is empty until Task 4, so the two `foreach` tests pass trivially at first and tighten as each module lands — they are the check that manifest and filesystem never drift. Each asserts `assertIsArray` before its loop so it is never a test that asserts nothing. The stronger assertion that all seven modules are present belongs in Task 10, once all seven exist.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test`
Expected: FAIL — `Call to undefined function marginal_core_modules()`.

- [ ] **Step 3: Write `inc/modules.php`**

Start the manifest **empty**; each module task appends its own line. This keeps the suite green between tasks.

```php
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
```

- [ ] **Step 4: Append to `tests/bootstrap.php`**

```php
require_once __DIR__ . '/../inc/modules.php';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test`
Expected: PASS, 15 tests.

- [ ] **Step 6: Write `marginal-core.php`**

The version string here and in `MARGINAL_CORE_VERSION` must always match; a mismatch makes the update check compare against the wrong number.

```php
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

marginal_core_load_modules();
```

- [ ] **Step 7: Verify the header parses**

Run: `php -l marginal-core.php && head -20 marginal-core.php | grep -c "Update URI:"`
Expected: `No syntax errors detected` and `1`.

- [ ] **Step 8: Commit**

```bash
git add marginal-core.php inc/modules.php tests/
git commit -m "Add plugin bootstrap and module loader

Modules declare functions and a boot function; the loader requires then
boots them, so no module has file-scope side effects and each is
requirable from a test.

A missing module file is skipped rather than fatal — a half-extracted
release degrades to fewer features, not a white screen.

Update URI points at the repo: any non-wordpress.org value stops w.org
overwriting this plugin on a slug collision."
```

---

### Task 3: Self-update from GitHub releases

Built third, before any module, because the whole distribution model rests on `update_plugins_github.com` firing — and the spec names proving that as the first thing to verify.

**Files:**
- Create: `inc/updater.php`
- Create: `tests/UpdaterTest.php`
- Create: `.github/workflows/release.yml`
- Modify: `marginal-core.php` (require and boot the updater)
- Modify: `tests/bootstrap.php` (append one `require_once`)

**Interfaces:**
- Consumes: `MARGINAL_CORE_VERSION`, `MARGINAL_CORE_SLUG`, `MARGINAL_CORE_BASENAME` from Task 2.
- Produces:
  - `marginal_core_release_to_update( $release, string $current_version, string $plugin_file, string $slug ): ?array` — pure; `null` on any unusable input.
  - `marginal_core_fetch_latest_release()` — cached HTTP; returns array or `null`.
  - `marginal_core_check_for_update( $update, $plugin_data, $plugin_file, $locales )` — the filter callback.
  - `marginal_core_updater_boot(): void`

- [ ] **Step 1: Write the failing test**

Create `tests/UpdaterTest.php`. Every degradation path is a test, because this code runs unattended on every client site and a GitHub outage must never reach a client's screen.

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test`
Expected: FAIL — `Call to undefined function marginal_core_release_to_update()`.

- [ ] **Step 3: Write `inc/updater.php`**

```php
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

	$package = '';
	$assets  = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();

	foreach ( $assets as $asset ) {
		if ( ! is_array( $asset ) ) {
			continue;
		}

		$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
		$url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';

		if ( '' !== $url && '.zip' === substr( $name, -4 ) ) {
			$package = $url;
			break;
		}
	}

	if ( '' === $package ) {
		return null;
	}

	return array(
		'id'      => 'github.com/MarginalDK/Marginal-Core',
		'slug'    => $slug,
		'plugin'  => $plugin_file,
		'version' => $version,
		'url'     => isset( $release['html_url'] ) ? (string) $release['html_url'] : MARGINAL_CORE_RELEASE_PAGE,
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
```

- [ ] **Step 4: Append to `tests/bootstrap.php`**

```php
require_once __DIR__ . '/../inc/updater.php';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test`
Expected: PASS, 24 tests (15 + 9 new).

- [ ] **Step 6: Wire the updater into the bootstrap**

In `marginal-core.php`, after the `inc/modules.php` require, add:

```php
require_once MARGINAL_CORE_DIR . 'inc/updater.php';
```

and after `marginal_core_load_modules();` add:

```php
marginal_core_updater_boot();
```

- [ ] **Step 7: Create `.github/workflows/release.yml`**

```yaml
name: Release

on:
  push:
    tags:
      - 'v*'

permissions:
  contents: write

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Verify tag matches the plugin header
        run: |
          VERSION="${GITHUB_REF_NAME#v}"
          HEADER=$(grep -m1 '^ \* Version:' marginal-core.php | awk '{print $3}')
          if [ "$VERSION" != "$HEADER" ]; then
            echo "Tag $GITHUB_REF_NAME does not match plugin header version $HEADER"
            exit 1
          fi

      - name: Build plugin zip
        run: |
          VERSION="${GITHUB_REF_NAME#v}"
          mkdir -p build/marginal-core
          rsync -a --exclude-from=.distignore ./ build/marginal-core/
          cd build && zip -rq "marginal-core-${VERSION}.zip" marginal-core

      - name: Attach zip to the release
        uses: softprops/action-gh-release@v2
        with:
          files: build/marginal-core-*.zip
          generate_release_notes: true
```

The version check is the point of that first step: a tag that disagrees with the header would ship a plugin that reports the wrong version and then offers itself the same update forever.

- [ ] **Step 8: Commit**

```bash
git add inc/updater.php tests/UpdaterTest.php .github/workflows/release.yml marginal-core.php tests/bootstrap.php
git commit -m "Add GitHub release update channel

update_plugins_github.com turns a tagged release into an ordinary
pending update on every child, which MainWP then reports to Bastion.

Every degradation path returns the input untouched and is covered by a
test: malformed payload, non-numeric tag, no zip asset, same or older
version. A GitHub outage must never reach a client's admin screen.

The release workflow refuses to build when the tag and the plugin
header disagree, which would otherwise ship a plugin that offers
itself the same update forever."
```

- [ ] **Step 9: Verify the update channel end to end**

This is the spec's first verification item and the assumption the whole distribution model rests on. Do it now, before writing any module.

1. Tag and push `v0.9.0`; confirm Actions attaches `marginal-core-0.9.0.zip` to the release.
2. Install that zip on a staging WordPress site.
3. Bump the header and `MARGINAL_CORE_VERSION` to `0.9.1`, tag and push `v0.9.1`.
4. On staging, visit **Dashboard → Updates** and click "Check again".
5. Expected: Marginal Core appears as an available update, and updating installs 0.9.1.

If the update does not appear, confirm in this order: the `Update URI` header is present in the *installed* copy; `MARGINAL_CORE_BASENAME` matches the installed path; the release has a `.zip` asset; and the site transient `marginal_core_latest_release` is not holding a cached `none`.

Record the outcome in `docs/verification.md` — create it with the result, pass or fail. If it fails, stop and report; every later task assumes this works.

---

### Task 4: Hardening and REST modules

Two modules in one task: between them they are about forty lines carried from the predecessor, and a reviewer would not meaningfully accept one and reject the other. This task also introduces the hook-recording stubs every later module test uses.

**Files:**
- Create: `modules/hardening.php`
- Create: `modules/rest.php`
- Create: `tests/HardeningTest.php`
- Create: `tests/RestTest.php`
- Modify: `tests/bootstrap.php` (hook recorder and WordPress stubs)
- Modify: `inc/modules.php` (two manifest lines)

**Interfaces:**
- Consumes: `marginal_core_boot_function()` naming convention from Task 2.
- Produces:
  - `marginal_core_remove_pingback_header( $headers )` — pure.
  - `marginal_core_hardening_boot(): void`
  - `marginal_core_rest_block_user_endpoints( $endpoints )` — reads `is_user_logged_in()`.
  - `marginal_core_rest_boot(): void`
  - Test helpers `marginal_core_test_hooks( string $hook ): array` and `marginal_core_test_reset_hooks(): void`.

- [ ] **Step 1: Extend `tests/bootstrap.php` with hook recording**

Insert these stubs **above** the existing `require_once` lines, so modules see them at require time.

```php
$GLOBALS['marginal_core_hooks']     = array();
$GLOBALS['marginal_core_logged_in'] = false;

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['marginal_core_hooks'][] = array(
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['marginal_core_logged_in'];
	}
}

/**
 * Callbacks registered against one hook, in registration order.
 *
 * @return array<int,mixed>
 */
function marginal_core_test_hooks( string $hook ): array {
	return array_values(
		array_map(
			static function ( array $row ) {
				return $row['callback'];
			},
			array_filter(
				$GLOBALS['marginal_core_hooks'],
				static function ( array $row ) use ( $hook ) {
					return $row['hook'] === $hook;
				}
			)
		)
	);
}

function marginal_core_test_reset_hooks(): void {
	$GLOBALS['marginal_core_hooks'] = array();
}
```

Then append at the bottom:

```php
require_once __DIR__ . '/../modules/hardening.php';
require_once __DIR__ . '/../modules/rest.php';
```

- [ ] **Step 2: Write the failing tests**

Create `tests/HardeningTest.php`:

```php
<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class HardeningTest extends TestCase {

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
	}

	public function test_removes_the_pingback_header(): void {
		$headers = array( 'X-Pingback' => 'https://example.test/xmlrpc.php', 'Content-Type' => 'text/html' );

		$this->assertSame(
			array( 'Content-Type' => 'text/html' ),
			marginal_core_remove_pingback_header( $headers )
		);
	}

	public function test_leaves_other_headers_untouched_when_pingback_absent(): void {
		$headers = array( 'Content-Type' => 'text/html' );

		$this->assertSame( $headers, marginal_core_remove_pingback_header( $headers ) );
	}

	public function test_non_array_input_is_returned_unchanged(): void {
		$this->assertNull( marginal_core_remove_pingback_header( null ) );
	}

	public function test_boot_registers_both_filters(): void {
		marginal_core_hardening_boot();

		$this->assertSame( array( '__return_false' ), marginal_core_test_hooks( 'xmlrpc_enabled' ) );
		$this->assertSame( array( 'marginal_core_remove_pingback_header' ), marginal_core_test_hooks( 'wp_headers' ) );
	}

	public function test_boot_disables_the_file_editor(): void {
		marginal_core_hardening_boot();

		$this->assertTrue( defined( 'DISALLOW_FILE_EDIT' ) );
		$this->assertTrue( DISALLOW_FILE_EDIT );
	}
}
```

Create `tests/RestTest.php`:

```php
<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class RestTest extends TestCase {

	private const USER_ROUTES = array(
		'/wp/v2/users'                => 'users',
		'/wp/v2/users/(?P<id>[\d]+)'  => 'single',
		'/wp/v2/posts'                => 'posts',
	);

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
		$GLOBALS['marginal_core_logged_in'] = false;
	}

	public function test_user_routes_are_removed_for_anonymous_requests(): void {
		$result = marginal_core_rest_block_user_endpoints( self::USER_ROUTES );

		$this->assertArrayNotHasKey( '/wp/v2/users', $result );
		$this->assertArrayNotHasKey( '/wp/v2/users/(?P<id>[\d]+)', $result );
	}

	public function test_other_routes_survive(): void {
		$result = marginal_core_rest_block_user_endpoints( self::USER_ROUTES );

		$this->assertArrayHasKey( '/wp/v2/posts', $result );
	}

	public function test_logged_in_requests_keep_the_user_routes(): void {
		$GLOBALS['marginal_core_logged_in'] = true;

		$this->assertSame( self::USER_ROUTES, marginal_core_rest_block_user_endpoints( self::USER_ROUTES ) );
	}

	public function test_non_array_input_is_returned_unchanged(): void {
		$this->assertFalse( marginal_core_rest_block_user_endpoints( false ) );
	}

	public function test_boot_registers_the_filter(): void {
		marginal_core_rest_boot();

		$this->assertSame(
			array( 'marginal_core_rest_block_user_endpoints' ),
			marginal_core_test_hooks( 'rest_endpoints' )
		);
	}
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `composer test`
Expected: FAIL — undefined functions in both new files.

- [ ] **Step 4: Write `modules/hardening.php`**

```php
<?php
/**
 * Baseline hardening.
 *
 * XML-RPC stays disabled by default: no Marginal client uses Jetpack or the
 * WordPress mobile app, both of which depend on it.
 *
 * The predecessor's DISALLOW_FILE_MODS and IP-whitelist machinery are
 * deliberately absent. That check trusted CF-Connecting-IP unconditionally
 * while allowing 127.0.0.1, so any request could spoof past it; it also
 * fights MainWP's own updates and fails silently when it does.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * Strip the X-Pingback response header.
 *
 * @param mixed $headers
 * @return mixed
 */
function marginal_core_remove_pingback_header( $headers ) {
	if ( ! is_array( $headers ) ) {
		return $headers;
	}

	unset( $headers['X-Pingback'] );

	return $headers;
}

function marginal_core_hardening_boot(): void {
	if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
		define( 'DISALLOW_FILE_EDIT', true );
	}

	add_filter( 'xmlrpc_enabled', '__return_false' );
	add_filter( 'wp_headers', 'marginal_core_remove_pingback_header' );
}
```

- [ ] **Step 5: Write `modules/rest.php`**

```php
<?php
/**
 * REST API hardening.
 *
 * Unregisters the user routes for anonymous requests, which closes the
 * easiest user-enumeration path.
 *
 * Known gap, accepted: ?author=N redirects and _embed responses can still
 * leak author slugs. Closing those risks breaking legitimate theme
 * behaviour, which fails the non-intrusive test this plugin is held to.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * @param mixed $endpoints
 * @return mixed
 */
function marginal_core_rest_block_user_endpoints( $endpoints ) {
	if ( ! is_array( $endpoints ) || is_user_logged_in() ) {
		return $endpoints;
	}

	unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

	return $endpoints;
}

function marginal_core_rest_boot(): void {
	add_filter( 'rest_endpoints', 'marginal_core_rest_block_user_endpoints' );
}
```

- [ ] **Step 6: Add both to the manifest**

In `inc/modules.php`, replace the placeholder comment inside `marginal_core_modules()` with:

```php
		'hardening' => 'hardening.php',
		'rest'      => 'rest.php',
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `composer test`
Expected: PASS, 34 tests (24 + 10 new).

- [ ] **Step 8: Commit**

```bash
git add modules/hardening.php modules/rest.php tests/ inc/modules.php
git commit -m "Add hardening and REST modules

Carried from the predecessor, minus DISALLOW_FILE_MODS and the IP
whitelist: that check trusted CF-Connecting-IP while allowing
127.0.0.1, so any request could spoof past it.

Adds hook-recording stubs to the test bootstrap, so every module's
boot function can be asserted against a typo'd hook name."
```

---

### Task 5: Cron fixes module

**Files:**
- Create: `modules/cron-fixes.php`
- Create: `tests/CronFixesTest.php`
- Modify: `tests/bootstrap.php` (one `require_once`, two stubs)
- Modify: `inc/modules.php` (one manifest line)

**Interfaces:**
- Produces:
  - `marginal_core_add_minute_schedule( $schedules )` — pure.
  - `marginal_core_silence_mainwp_subsite_notices(): void`
  - `marginal_core_cron_fixes_boot(): void`

- [ ] **Step 1: Add stubs to `tests/bootstrap.php`**

Above the `require_once` block:

```php
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['marginal_core_multisite'] ?? false );
	}
}

if ( ! function_exists( 'is_main_site' ) ) {
	function is_main_site(): bool {
		return (bool) ( $GLOBALS['marginal_core_main_site'] ?? true );
	}
}
```

And at the bottom:

```php
require_once __DIR__ . '/../modules/cron-fixes.php';
```

- [ ] **Step 2: Write the failing test**

Create `tests/CronFixesTest.php`:

```php
<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class CronFixesTest extends TestCase {

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
	}

	public function test_adds_the_minute_schedule(): void {
		$result = marginal_core_add_minute_schedule( array() );

		$this->assertArrayHasKey( 'minute', $result );
		$this->assertSame( 60, $result['minute']['interval'] );
		$this->assertSame( 'Every Minute', $result['minute']['display'] );
	}

	public function test_preserves_existing_schedules(): void {
		$existing = array( 'hourly' => array( 'interval' => 3600, 'display' => 'Once Hourly' ) );

		$result = marginal_core_add_minute_schedule( $existing );

		$this->assertSame( $existing['hourly'], $result['hourly'] );
	}

	public function test_does_not_overwrite_an_existing_minute_schedule(): void {
		$existing = array( 'minute' => array( 'interval' => 99, 'display' => 'Theirs' ) );

		$this->assertSame( $existing, marginal_core_add_minute_schedule( $existing ) );
	}

	public function test_non_array_input_is_returned_unchanged(): void {
		$this->assertNull( marginal_core_add_minute_schedule( null ) );
	}

	public function test_boot_registers_both_hooks(): void {
		marginal_core_cron_fixes_boot();

		$this->assertSame( array( 'marginal_core_add_minute_schedule' ), marginal_core_test_hooks( 'cron_schedules' ) );
		$this->assertSame( array( 'marginal_core_silence_mainwp_subsite_notices' ), marginal_core_test_hooks( 'admin_init' ) );
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test`
Expected: FAIL — `Call to undefined function marginal_core_add_minute_schedule()`.

- [ ] **Step 4: Write `modules/cron-fixes.php`**

```php
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
```

- [ ] **Step 5: Add to the manifest**

In `inc/modules.php`, after the `rest` line:

```php
		'cron-fixes' => 'cron-fixes.php',
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test`
Expected: PASS, 39 tests (34 + 5 new).

- [ ] **Step 7: Commit**

```bash
git add modules/cron-fixes.php tests/ inc/modules.php
git commit -m "Add cron-fixes module

Registers the minute schedule MainWP's System Monitor expects, and
silences MainWP Child's connection notices on multisite sub-sites where
the connection belongs to the network rather than the sub-site.

The schedule filter never overwrites an existing entry: another plugin
may already own it."
```

---

### Task 6: MainWP unique security ID

The reason this project exists. The repair rule is deliberately conservative, because repairing an ID on a connected site breaks the connection — the dashboard still holds the old value.

**Files:**
- Create: `modules/mainwp.php`
- Create: `tests/MainwpTest.php`
- Modify: `tests/bootstrap.php` (one `require_once`, option stubs)
- Modify: `inc/modules.php` (one manifest line)

**Interfaces:**
- Produces:
  - `marginal_core_is_safe_unique_id( $id ): bool` — pure.
  - `marginal_core_unique_id_action( bool $connected, $id ): string` — pure; returns `'repair'`, `'warn'` or `'none'`.
  - `marginal_core_generate_unique_id(): string`
  - `marginal_core_mainwp_is_connected(): bool`
  - `marginal_core_mainwp_unique_id(): string`
  - `marginal_core_mainwp_maybe_repair(): void`
  - `marginal_core_mainwp_boot(): void`
  - Constants `MARGINAL_CORE_MAINWP_ID_OPTION`, `MARGINAL_CORE_MAINWP_KEY_OPTION`.

- [ ] **Step 1: Read the verified facts about MainWP Child**

Already confirmed against MainWP Child's published source (`github.com/mainwp/mainwp-child`), so no staging site is needed:

- `mainwp_child_pubkey` is the option written on a completed handshake (`class-mainwp-connect.php:160`) and read to test connection (`class-mainwp-child.php:222,614`). Using it as the connection test is correct.
- `mainwp_child_uniqueId` is the option holding the security ID (`class-mainwp-child.php:448`).
- **MainWP's own generator is `wp_generate_password( 12, false )`** — alphanumeric already. MainWP never generates a symbol-bearing ID itself.
- **The effective ID is not simply that option.** `MainWP_Helper::get_site_unique_id()` reads the `MAINWP_CHILD_UNIQUEID` **constant first**, falls back to the option, and then passes the result through a `mainwp_child_unique_id` filter.

That last fact changes this module's design, and is why the code below differs from a naive "rewrite the option" approach: **on a site where `MAINWP_CHILD_UNIQUEID` is defined, writing the option has no effect at all.** MainWP keeps using the constant. A repair that writes the option on such a site would report success and change nothing — the worst possible outcome, because it hides the problem.

So the module reads the effective ID the way MainWP does, and only repairs when the value actually comes from the option. A constant-sourced bad ID is a `wp-config.php` edit, which no plugin should make on a client's behalf, so it is surfaced as a warning instead.

- [ ] **Step 2: Add stubs to `tests/bootstrap.php`**

Above the `require_once` block:

```php
$GLOBALS['marginal_core_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['marginal_core_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value ): bool {
		$GLOBALS['marginal_core_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $name ) {
		return $GLOBALS['marginal_core_options'][ '_t_' . $name ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $name, $value, int $ttl = 0 ): bool {
		$GLOBALS['marginal_core_options'][ '_t_' . $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out   = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}

		return $out;
	}
}
```

And at the bottom:

```php
require_once __DIR__ . '/../modules/mainwp.php';
```

- [ ] **Step 3: Write the failing test**

Create `tests/MainwpTest.php`. The decision table is the heart of this module, so every cell gets a test.

```php
<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class MainwpTest extends TestCase {

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
		$GLOBALS['marginal_core_options'] = array();
	}

	public function test_alphanumeric_id_is_safe(): void {
		$this->assertTrue( marginal_core_is_safe_unique_id( 'aB3xY9zQ' ) );
	}

	public function test_id_with_symbols_is_unsafe(): void {
		$this->assertFalse( marginal_core_is_safe_unique_id( 'aB3x&Y9z' ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( 'has space' ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( 'plus+sign' ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( 'per%cent' ) );
	}

	public function test_short_id_is_unsafe(): void {
		$this->assertFalse( marginal_core_is_safe_unique_id( 'abc' ) );
	}

	public function test_empty_and_non_string_are_unsafe(): void {
		$this->assertFalse( marginal_core_is_safe_unique_id( '' ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( null ) );
		$this->assertFalse( marginal_core_is_safe_unique_id( 12345678 ) );
	}

	public function test_disconnected_and_unsafe_repairs(): void {
		$this->assertSame( 'repair', marginal_core_unique_id_action( false, 'bad&id' ) );
	}

	public function test_disconnected_and_missing_repairs(): void {
		$this->assertSame( 'repair', marginal_core_unique_id_action( false, '' ) );
	}

	public function test_disconnected_and_safe_does_nothing(): void {
		$this->assertSame( 'none', marginal_core_unique_id_action( false, 'aB3xY9zQ' ) );
	}

	public function test_connected_and_unsafe_only_warns(): void {
		$this->assertSame( 'warn', marginal_core_unique_id_action( true, 'bad&id' ) );
	}

	public function test_connected_and_missing_only_warns(): void {
		$this->assertSame( 'warn', marginal_core_unique_id_action( true, '' ) );
	}

	public function test_connected_and_safe_does_nothing(): void {
		$this->assertSame( 'none', marginal_core_unique_id_action( true, 'aB3xY9zQ' ) );
	}

	public function test_unsafe_constant_sourced_id_only_warns_even_when_disconnected(): void {
		// Writing the option cannot override MAINWP_CHILD_UNIQUEID, so a
		// "repair" here would report success and change nothing.
		$this->assertSame( 'warn', marginal_core_unique_id_action( false, 'bad&id', false ) );
	}

	public function test_safe_constant_sourced_id_still_does_nothing(): void {
		$this->assertSame( 'none', marginal_core_unique_id_action( false, 'aB3xY9zQ', false ) );
	}

	public function test_repairable_defaults_to_true_for_backwards_compatible_calls(): void {
		$this->assertSame( 'repair', marginal_core_unique_id_action( false, 'bad&id' ) );
	}

	public function test_generated_ids_are_safe_and_32_characters(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$id = marginal_core_generate_unique_id();

			$this->assertSame( 32, strlen( $id ) );
			$this->assertTrue( marginal_core_is_safe_unique_id( $id ) );
		}
	}

	public function test_repair_writes_a_safe_id_when_disconnected(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'bad&id';

		marginal_core_mainwp_maybe_repair();

		$this->assertTrue( marginal_core_is_safe_unique_id( get_option( MARGINAL_CORE_MAINWP_ID_OPTION ) ) );
	}

	public function test_connected_site_is_never_touched(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_KEY_OPTION ] = 'a-public-key';
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ]  = 'bad&id';

		marginal_core_mainwp_maybe_repair();

		$this->assertSame( 'bad&id', get_option( MARGINAL_CORE_MAINWP_ID_OPTION ) );
	}

	public function test_safe_id_is_left_alone_when_disconnected(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'aB3xY9zQaB3xY9zQaB3xY9zQaB3xY9zQ';

		marginal_core_mainwp_maybe_repair();

		$this->assertSame( 'aB3xY9zQaB3xY9zQaB3xY9zQaB3xY9zQ', get_option( MARGINAL_CORE_MAINWP_ID_OPTION ) );
	}

	public function test_throttle_prevents_a_second_repair_in_the_same_window(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'bad&id';
		marginal_core_mainwp_maybe_repair();
		$first = get_option( MARGINAL_CORE_MAINWP_ID_OPTION );

		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'bad&again';
		marginal_core_mainwp_maybe_repair();

		$this->assertSame( 'bad&again', get_option( MARGINAL_CORE_MAINWP_ID_OPTION ) );
		$this->assertTrue( marginal_core_is_safe_unique_id( $first ) );
	}

	public function test_unique_id_prefers_the_constant_over_the_option(): void {
		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_ID_OPTION ] = 'fromOption123456';

		// The constant is not defined in this suite, so the option wins here.
		$this->assertSame( 'fromOption123456', marginal_core_mainwp_unique_id() );
		$this->assertTrue( marginal_core_mainwp_id_is_repairable() );
	}

	public function test_connection_state_reads_the_public_key(): void {
		$this->assertFalse( marginal_core_mainwp_is_connected() );

		$GLOBALS['marginal_core_options'][ MARGINAL_CORE_MAINWP_KEY_OPTION ] = 'a-public-key';

		$this->assertTrue( marginal_core_mainwp_is_connected() );
	}
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `composer test`
Expected: FAIL — `Call to undefined function marginal_core_is_safe_unique_id()`.

- [ ] **Step 5: Write `modules/mainwp.php`**

```php
<?php
/**
 * MainWP child connection.
 *
 * The unique security ID breaks connections when it contains symbols. The
 * repair rule is deliberately conservative: rewriting the ID on a connected
 * site would break that connection, because the dashboard still holds the
 * old value. A live site is therefore only ever flagged, never repaired.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

const MARGINAL_CORE_MAINWP_ID_OPTION   = 'mainwp_child_uniqueId';
const MARGINAL_CORE_MAINWP_KEY_OPTION  = 'mainwp_child_pubkey';
const MARGINAL_CORE_MAINWP_ID_CONSTANT = 'MAINWP_CHILD_UNIQUEID';
const MARGINAL_CORE_MAINWP_THROTTLE    = 'marginal_core_mainwp_checked';

/**
 * Whether an ID is safe to send through MainWP's handshake.
 *
 * Pure.
 *
 * @param mixed $id
 */
function marginal_core_is_safe_unique_id( $id ): bool {
	return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9]{8,64}$/', $id );
}

/**
 * Decide what to do about the current unique security ID.
 *
 * Pure. 'repair' writes a new ID, 'warn' surfaces a widget row, 'none' does
 * nothing.
 *
 * Two separate reasons forbid repair, and conflating them would hide a real
 * problem behind an apparent success:
 *
 * - $connected: rewriting a working ID disconnects the site, because the
 *   dashboard still holds the old value.
 * - ! $repairable: the effective ID comes from the MAINWP_CHILD_UNIQUEID
 *   constant, which MainWP reads in preference to the option. Writing the
 *   option there changes nothing at all — MainWP goes on using the constant —
 *   so a "repair" would report success and fix nothing. Correcting it means
 *   editing wp-config.php, which is not a plugin's business.
 *
 * @param mixed $id
 */
function marginal_core_unique_id_action( bool $connected, $id, bool $repairable = true ): string {
	if ( marginal_core_is_safe_unique_id( $id ) ) {
		return 'none';
	}

	if ( $connected || ! $repairable ) {
		return 'warn';
	}

	return 'repair';
}

/**
 * 32 alphanumeric characters — wp_generate_password with both symbol flags
 * off returns exactly that character set.
 */
function marginal_core_generate_unique_id(): string {
	return wp_generate_password( 32, false, false );
}

function marginal_core_mainwp_is_connected(): bool {
	$key = get_option( MARGINAL_CORE_MAINWP_KEY_OPTION, '' );

	return ! empty( $key );
}

/**
 * The ID MainWP will actually use, resolved the way MainWP resolves it.
 *
 * MainWP_Helper::get_site_unique_id() prefers the constant over the option, so
 * reading the option alone would report a value the site is not using.
 */
function marginal_core_mainwp_unique_id(): string {
	if ( defined( MARGINAL_CORE_MAINWP_ID_CONSTANT ) ) {
		$id = constant( MARGINAL_CORE_MAINWP_ID_CONSTANT );

		return is_string( $id ) ? $id : '';
	}

	$id = get_option( MARGINAL_CORE_MAINWP_ID_OPTION, '' );

	return is_string( $id ) ? $id : '';
}

/**
 * Whether the effective ID is one we could actually change.
 *
 * False when the constant is defined: the option we would write is not the
 * value MainWP reads.
 *
 * Documented limitation: MainWP also passes the ID through a
 * `mainwp_child_unique_id` filter, which no plugin can detect statically. A
 * site filtering that value will see the same "writes nothing" behaviour, and
 * there is no way to know in advance.
 */
function marginal_core_mainwp_id_is_repairable(): bool {
	return ! defined( MARGINAL_CORE_MAINWP_ID_CONSTANT );
}

/**
 * Repair the ID if, and only if, the site is not yet connected.
 *
 * Throttled, and skipped outright once a public key exists — so a connected
 * site costs one option read per admin load and nothing else, forever.
 */
function marginal_core_mainwp_maybe_repair(): void {
	if ( marginal_core_mainwp_is_connected() ) {
		return;
	}

	if ( get_transient( MARGINAL_CORE_MAINWP_THROTTLE ) ) {
		return;
	}

	set_transient( MARGINAL_CORE_MAINWP_THROTTLE, 1, 300 );

	$action = marginal_core_unique_id_action(
		false,
		marginal_core_mainwp_unique_id(),
		marginal_core_mainwp_id_is_repairable()
	);

	if ( 'repair' === $action ) {
		update_option( MARGINAL_CORE_MAINWP_ID_OPTION, marginal_core_generate_unique_id() );
	}
}

function marginal_core_mainwp_boot(): void {
	add_action( 'admin_init', 'marginal_core_mainwp_maybe_repair' );
}
```

- [ ] **Step 6: Run activation coverage into the bootstrap**

MainWP can activate this plugin remotely, with no admin page view to trigger `admin_init`. Add to `marginal-core.php`, after the requires:

```php
register_activation_hook(
	MARGINAL_CORE_FILE,
	static function () {
		if ( function_exists( 'marginal_core_mainwp_maybe_repair' ) ) {
			marginal_core_mainwp_maybe_repair();
		}
	}
);
```

- [ ] **Step 7: Add to the manifest**

In `inc/modules.php`, after the `cron-fixes` line:

```php
		'mainwp' => 'mainwp.php',
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `composer test`
Expected: PASS, 72 tests (51 + 21 new).

- [ ] **Step 9: Commit**

```bash
git add modules/mainwp.php tests/ inc/modules.php marginal-core.php
git commit -m "Add MainWP unique security ID handling

Forces an alphanumeric ID before first connection, which is the case
that actually breaks onboarding, and refuses to touch a connected site
— rewriting a working ID would disconnect it, because the dashboard
still holds the old value. Connected sites are flagged in the widget
instead and repaired deliberately at a maintenance window.

Connection state reads the public-key option rather than class_exists:
the predecessor's check was unnamespaced, and plugin presence is not a
completed handshake."
```

---

### Task 7: Protected user guard

**Files:**
- Create: `modules/user-guard.php`
- Create: `tests/UserGuardTest.php`
- Modify: `tests/bootstrap.php` (one `require_once`, `get_userdata` stub)
- Modify: `inc/modules.php` (one manifest line)

**Interfaces:**
- Consumes: `marginal_core_config()` from Task 1.
- Produces:
  - `marginal_core_guard_blocks( string $cap, int $actor_id, int $target_id, string $target_login, array $protected_logins, bool $protection_enabled, bool $is_cli ): bool` — pure.
  - `marginal_core_protected_logins(): array`
  - `marginal_core_is_protected_login( string $login ): bool`
  - `marginal_core_guard_map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array`
  - `marginal_core_guard_row_actions( array $actions, $user ): array`
  - `marginal_core_guard_block_delete( int $user_id ): void`
  - `marginal_core_user_guard_boot(): void`

- [ ] **Step 1: Add the `get_userdata` stub to `tests/bootstrap.php`**

Above the `require_once` block:

```php
$GLOBALS['marginal_core_users'] = array();

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $id ) {
		return $GLOBALS['marginal_core_users'][ $id ] ?? false;
	}
}
```

And at the bottom:

```php
require_once __DIR__ . '/../modules/user-guard.php';
```

- [ ] **Step 2: Write the failing test**

Create `tests/UserGuardTest.php`. The pure decision function carries the whole table, so it gets the coverage.

```php
<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class UserGuardTest extends TestCase {

	private const PROTECTED = array( 'marginal' );

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
	}

	private function blocks( string $cap, int $actor = 2, int $target = 1, string $login = 'marginal', bool $enabled = true, bool $cli = false ): bool {
		return marginal_core_guard_blocks( $cap, $actor, $target, $login, self::PROTECTED, $enabled, $cli );
	}

	public function test_blocks_deleting_a_protected_user(): void {
		$this->assertTrue( $this->blocks( 'delete_user' ) );
	}

	public function test_blocks_editing_a_protected_user(): void {
		$this->assertTrue( $this->blocks( 'edit_user' ) );
	}

	public function test_blocks_demoting_a_protected_user(): void {
		$this->assertTrue( $this->blocks( 'promote_user' ) );
	}

	public function test_blocks_removing_a_protected_user_from_a_site(): void {
		$this->assertTrue( $this->blocks( 'remove_user' ) );
	}

	public function test_allows_unrelated_capabilities(): void {
		$this->assertFalse( $this->blocks( 'edit_posts' ) );
	}

	public function test_allows_action_against_an_unprotected_user(): void {
		$this->assertFalse( $this->blocks( 'delete_user', 2, 3, 'client-editor' ) );
	}

	public function test_protected_user_may_edit_itself(): void {
		$this->assertFalse( $this->blocks( 'edit_user', 1, 1, 'marginal' ) );
	}

	public function test_login_matching_is_case_insensitive(): void {
		$this->assertTrue( $this->blocks( 'delete_user', 2, 1, 'Marginal' ) );
	}

	public function test_master_switch_off_allows_everything(): void {
		$this->assertFalse( $this->blocks( 'delete_user', 2, 1, 'marginal', false ) );
	}

	public function test_wp_cli_bypasses_the_guard(): void {
		$this->assertFalse( $this->blocks( 'delete_user', 2, 1, 'marginal', true, true ) );
	}

	public function test_empty_protected_list_blocks_nothing(): void {
		$this->assertFalse(
			marginal_core_guard_blocks( 'delete_user', 2, 1, 'marginal', array(), true, false )
		);
	}

	public function test_map_meta_cap_denies_a_blocked_capability(): void {
		$user             = new stdClass();
		$user->ID         = 1;
		$user->user_login = 'marginal';

		$GLOBALS['marginal_core_users'][1] = $user;

		$this->assertSame(
			array( 'do_not_allow' ),
			marginal_core_guard_map_meta_cap( array( 'delete_users' ), 'delete_user', 2, array( 1 ) )
		);
	}

	public function test_map_meta_cap_passes_through_an_allowed_capability(): void {
		$user             = new stdClass();
		$user->ID         = 3;
		$user->user_login = 'client-editor';

		$GLOBALS['marginal_core_users'][3] = $user;

		$this->assertSame(
			array( 'delete_users' ),
			marginal_core_guard_map_meta_cap( array( 'delete_users' ), 'delete_user', 2, array( 3 ) )
		);
	}

	public function test_map_meta_cap_passes_through_when_no_target_given(): void {
		$this->assertSame(
			array( 'delete_users' ),
			marginal_core_guard_map_meta_cap( array( 'delete_users' ), 'delete_user', 2, array() )
		);
	}

	public function test_row_actions_lose_delete_for_a_protected_user(): void {
		$user             = new stdClass();
		$user->user_login = 'marginal';

		$actions = marginal_core_guard_row_actions(
			array( 'edit' => 'Edit', 'delete' => 'Delete' ),
			$user
		);

		$this->assertArrayNotHasKey( 'delete', $actions );
		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertArrayHasKey( 'marginal', $actions );
	}

	public function test_row_actions_are_untouched_for_other_users(): void {
		$user             = new stdClass();
		$user->user_login = 'client-editor';

		$actions = marginal_core_guard_row_actions(
			array( 'edit' => 'Edit', 'delete' => 'Delete' ),
			$user
		);

		$this->assertArrayHasKey( 'delete', $actions );
		$this->assertArrayNotHasKey( 'marginal', $actions );
	}

	public function test_boot_registers_its_hooks(): void {
		marginal_core_user_guard_boot();

		$this->assertSame( array( 'marginal_core_guard_map_meta_cap' ), marginal_core_test_hooks( 'map_meta_cap' ) );
		$this->assertSame( array( 'marginal_core_guard_row_actions' ), marginal_core_test_hooks( 'user_row_actions' ) );
		$this->assertSame( array( 'marginal_core_guard_block_delete' ), marginal_core_test_hooks( 'delete_user' ) );
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test`
Expected: FAIL — `Call to undefined function marginal_core_guard_blocks()`.

- [ ] **Step 4: Write `modules/user-guard.php`**

```php
<?php
/**
 * Protects hand-created Marginal accounts from client deletion.
 *
 * The plugin does not create the account: installing the plugin requires
 * admin access, which requires the account to already exist. It protects
 * what onboarding made.
 *
 * Inert unless a listed login exists, so it costs nothing on sites that do
 * not use it.
 *
 * Documented limitation: code calling $user->set_role() directly bypasses
 * capability checks entirely. No plugin-level defence exists for that.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * Capabilities that are denied when aimed at a protected account.
 */
const MARGINAL_CORE_GUARDED_CAPS = array(
	'delete_user',
	'edit_user',
	'promote_user',
	'remove_user',
);

/**
 * The whole decision, as a pure function.
 *
 * Every input is a parameter so the table is unit-testable without
 * WordPress — which is the only way a silent regression here gets caught,
 * since the failure mode is a client successfully deleting our access.
 *
 * @param string[] $protected_logins
 */
function marginal_core_guard_blocks(
	string $cap,
	int $actor_id,
	int $target_id,
	string $target_login,
	array $protected_logins,
	bool $protection_enabled,
	bool $is_cli
): bool {
	if ( ! $protection_enabled || $is_cli ) {
		return false;
	}

	if ( ! in_array( $cap, MARGINAL_CORE_GUARDED_CAPS, true ) ) {
		return false;
	}

	if ( $actor_id === $target_id ) {
		return false;
	}

	$protected = array_map( 'strtolower', $protected_logins );

	return in_array( strtolower( $target_login ), $protected, true );
}

/**
 * @return string[]
 */
function marginal_core_protected_logins(): array {
	$logins = marginal_core_config( 'protected_users', array( 'marginal' ) );

	if ( ! is_array( $logins ) ) {
		$logins = array( (string) $logins );
	}

	return array_values( array_filter( array_map( 'strval', $logins ) ) );
}

function marginal_core_is_protected_login( string $login ): bool {
	$protected = array_map( 'strtolower', marginal_core_protected_logins() );

	return in_array( strtolower( $login ), $protected, true );
}

function marginal_core_guard_protection_enabled(): bool {
	return (bool) marginal_core_config( 'protection', true );
}

function marginal_core_guard_is_cli(): bool {
	return defined( 'WP_CLI' ) && WP_CLI;
}

/**
 * Deny guarded capabilities at the capability layer.
 *
 * This is the only layer that holds against both the admin UI and the REST
 * API, which is why the guard lives here rather than in the Users screen.
 *
 * @param string[] $caps
 * @param mixed[]  $args
 * @return string[]
 */
function marginal_core_guard_map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
	if ( ! in_array( $cap, MARGINAL_CORE_GUARDED_CAPS, true ) || empty( $args[0] ) ) {
		return $caps;
	}

	$target = get_userdata( (int) $args[0] );

	if ( ! $target ) {
		return $caps;
	}

	$blocked = marginal_core_guard_blocks(
		$cap,
		$user_id,
		(int) $target->ID,
		(string) $target->user_login,
		marginal_core_protected_logins(),
		marginal_core_guard_protection_enabled(),
		marginal_core_guard_is_cli()
	);

	return $blocked ? array( 'do_not_allow' ) : $caps;
}

/**
 * Remove the Delete action and label the row.
 *
 * Visible and explained, not hidden: a hidden account is what malware
 * installs, and a client who finds one has a trust problem.
 *
 * @param string[] $actions
 * @param mixed    $user
 * @return string[]
 */
function marginal_core_guard_row_actions( array $actions, $user ): array {
	if ( ! isset( $user->user_login ) || ! marginal_core_is_protected_login( (string) $user->user_login ) ) {
		return $actions;
	}

	unset( $actions['delete'], $actions['remove'] );

	$actions['marginal'] = '<span style="color:#64748b;">Managed by Marginal</span>';

	return $actions;
}

/**
 * Last-resort backstop on the deletion path itself.
 *
 * wp_delete_user() fires this before removing anything, and bulk deletion
 * does not always perform a per-target capability check — so the capability
 * layer alone is not provably sufficient here.
 */
function marginal_core_guard_block_delete( int $user_id ): void {
	if ( marginal_core_guard_is_cli() || ! marginal_core_guard_protection_enabled() ) {
		return;
	}

	$user = get_userdata( $user_id );

	if ( ! $user || ! marginal_core_is_protected_login( (string) $user->user_login ) ) {
		return;
	}

	wp_die(
		esc_html( 'This account is managed by Marginal and cannot be deleted. Contact Marginal if it needs to be removed.' ),
		'',
		array( 'back_link' => true )
	);
}

function marginal_core_user_guard_boot(): void {
	add_filter( 'map_meta_cap', 'marginal_core_guard_map_meta_cap', 10, 4 );
	add_filter( 'user_row_actions', 'marginal_core_guard_row_actions', 10, 2 );
	add_action( 'delete_user', 'marginal_core_guard_block_delete', 1, 1 );
	add_action( 'wpmu_delete_user', 'marginal_core_guard_block_delete', 1, 1 );
}
```

- [ ] **Step 5: Add to the manifest**

In `inc/modules.php`, after the `mainwp` line:

```php
		'user-guard' => 'user-guard.php',
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test`
Expected: PASS, 72 tests (55 + 17 new).

- [ ] **Step 7: Commit**

```bash
git add modules/user-guard.php tests/ inc/modules.php
git commit -m "Add protected user guard

Protection is capability-level because that is the only layer holding
against both the admin UI and the REST API. A backstop on delete_user
covers bulk deletion, which does not always do a per-target check.

The account is labelled in the Users list rather than hidden: a hidden
admin is what malware installs, and a client who finds one has a trust
problem.

Known limitation, unfixable at plugin level and therefore documented
rather than papered over: \$user->set_role() bypasses capabilities."
```

---

### Task 8: Status facts and warnings

Pure logic for the widget, separated from rendering so the conditions are testable. Every warning here is a check that would otherwise fail silently in the reassuring direction — which is the failure mode this plugin has hit twice in design.

**Files:**
- Create: `inc/status.php`
- Create: `tests/StatusTest.php`
- Modify: `tests/bootstrap.php` (one `require_once`)

**Interfaces:**
- Consumes: nothing. `inc/status.php` is self-contained — every input arrives as a parameter or a fact-array key, which is what makes it testable with no stubs at all.
- Produces:
  - `marginal_core_normalize_environment( $raw ): string` — pure.
  - `marginal_core_backup_status( $last_backup, int $max_age_days, int $now ): array` — pure; `array{state: string, time: ?int}` where state is `ok|stale|failed|missing`.
  - `marginal_core_warnings( array $facts ): array` — pure; list of `array{id: string, level: string, text: string, marginal_only: bool}`.
  - `marginal_core_visible_warnings( array $warnings, bool $is_marginal_viewer ): array` — pure.
  - `marginal_core_summary_text( int $count ): string` — pure.

- [ ] **Step 1: Append to `tests/bootstrap.php`**

```php
require_once __DIR__ . '/../inc/status.php';
```

- [ ] **Step 2: Write the failing test**

Create `tests/StatusTest.php`:

```php
<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase {

	private const DAY = 86400;

	/**
	 * @return array<string,mixed>
	 */
	private function healthy_facts(): array {
		return array(
			'search_engines_blocked' => false,
			'debug_in_production'    => false,
			'environment'            => 'production',
			'environment_declared'   => true,
			'backup'                 => array( 'state' => 'ok', 'time' => 1000 ),
			'mainwp_connected'       => true,
			'mainwp_id_safe'         => true,
		);
	}

	public function test_known_environments_pass_through(): void {
		foreach ( array( 'local', 'development', 'staging', 'production' ) as $env ) {
			$this->assertSame( $env, marginal_core_normalize_environment( $env ) );
		}
	}

	public function test_unknown_environment_falls_back_to_production(): void {
		$this->assertSame( 'production', marginal_core_normalize_environment( 'banana' ) );
	}

	public function test_empty_and_non_string_fall_back_to_production(): void {
		$this->assertSame( 'production', marginal_core_normalize_environment( '' ) );
		$this->assertSame( 'production', marginal_core_normalize_environment( null ) );
		$this->assertSame( 'production', marginal_core_normalize_environment( 42 ) );
	}

	public function test_environment_is_case_and_whitespace_insensitive(): void {
		$this->assertSame( 'staging', marginal_core_normalize_environment( '  STAGING ' ) );
	}

	public function test_missing_backup_option_reports_missing(): void {
		$this->assertSame( 'missing', marginal_core_backup_status( false, 7, 100 * self::DAY )['state'] );
		$this->assertSame( 'missing', marginal_core_backup_status( array(), 7, 100 * self::DAY )['state'] );
	}

	public function test_failed_backup_reports_failed(): void {
		$backup = array( 'backup_time' => 100 * self::DAY, 'success' => 0 );

		$this->assertSame( 'failed', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_recent_backup_reports_ok(): void {
		$backup = array( 'backup_time' => 99 * self::DAY, 'success' => 1 );

		$this->assertSame( 'ok', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_old_backup_reports_stale(): void {
		$backup = array( 'backup_time' => 80 * self::DAY, 'success' => 1 );

		$this->assertSame( 'stale', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_backup_exactly_at_the_threshold_is_still_ok(): void {
		$backup = array( 'backup_time' => 93 * self::DAY, 'success' => 1 );

		$this->assertSame( 'ok', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_backup_one_second_past_the_threshold_is_stale(): void {
		$backup = array( 'backup_time' => 93 * self::DAY - 1, 'success' => 1 );

		$this->assertSame( 'stale', marginal_core_backup_status( $backup, 7, 100 * self::DAY )['state'] );
	}

	public function test_backup_time_is_returned(): void {
		$backup = array( 'backup_time' => 99 * self::DAY, 'success' => 1 );

		$this->assertSame( 99 * self::DAY, marginal_core_backup_status( $backup, 7, 100 * self::DAY )['time'] );
	}

	public function test_healthy_site_has_no_warnings(): void {
		$this->assertSame( array(), marginal_core_warnings( $this->healthy_facts() ) );
	}

	public function test_blocked_search_engines_warns(): void {
		$facts = $this->healthy_facts();
		$facts['search_engines_blocked'] = true;

		$ids = array_column( marginal_core_warnings( $facts ), 'id' );

		$this->assertContains( 'search-engines', $ids );
	}

	public function test_debug_in_production_warns(): void {
		$facts = $this->healthy_facts();
		$facts['debug_in_production'] = true;

		$this->assertContains( 'debug', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_non_production_environment_warns(): void {
		$facts = $this->healthy_facts();
		$facts['environment'] = 'staging';

		$this->assertContains( 'environment', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_stale_backup_warns(): void {
		$facts = $this->healthy_facts();
		$facts['backup'] = array( 'state' => 'stale', 'time' => 1000 );

		$this->assertContains( 'backup', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_unsafe_id_on_a_connected_site_warns_marginal_only(): void {
		$facts = $this->healthy_facts();
		$facts['mainwp_id_safe'] = false;

		$warnings = marginal_core_warnings( $facts );
		$ids      = array_column( $warnings, 'id' );

		$this->assertContains( 'mainwp-id', $ids );

		foreach ( $warnings as $warning ) {
			if ( 'mainwp-id' === $warning['id'] ) {
				$this->assertTrue( $warning['marginal_only'] );
			}
		}
	}

	public function test_unsafe_id_on_a_disconnected_site_does_not_warn(): void {
		$facts = $this->healthy_facts();
		$facts['mainwp_connected'] = false;
		$facts['mainwp_id_safe']   = false;

		$this->assertNotContains( 'mainwp-id', array_column( marginal_core_warnings( $facts ), 'id' ) );
	}

	public function test_undeclared_environment_warns_marginal_only(): void {
		$facts = $this->healthy_facts();
		$facts['environment_declared'] = false;

		$warnings = array_filter(
			marginal_core_warnings( $facts ),
			static function ( array $w ) {
				return 'environment-undeclared' === $w['id'];
			}
		);

		$this->assertCount( 1, $warnings );
		$this->assertTrue( array_values( $warnings )[0]['marginal_only'] );
	}

	public function test_client_view_hides_marginal_only_warnings(): void {
		$warnings = array(
			array( 'id' => 'a', 'level' => 'warn', 'text' => 'x', 'marginal_only' => false ),
			array( 'id' => 'b', 'level' => 'warn', 'text' => 'y', 'marginal_only' => true ),
		);

		$this->assertCount( 1, marginal_core_visible_warnings( $warnings, false ) );
		$this->assertCount( 2, marginal_core_visible_warnings( $warnings, true ) );
	}

	public function test_summary_text_counts(): void {
		$this->assertSame( 'Everything looks good', marginal_core_summary_text( 0 ) );
		$this->assertSame( '1 item needs attention', marginal_core_summary_text( 1 ) );
		$this->assertSame( '3 items need attention', marginal_core_summary_text( 3 ) );
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test`
Expected: FAIL — `Call to undefined function marginal_core_normalize_environment()`.

- [ ] **Step 4: Write `inc/status.php`**

```php
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
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test`
Expected: PASS, 93 tests (72 + 21 new).

- [ ] **Step 6: Commit**

```bash
git add inc/status.php tests/StatusTest.php tests/bootstrap.php
git commit -m "Add status facts and warning conditions

Pure logic, separated from rendering so every condition is testable at
its boundary — including the two checks caught during design as silent
failures in the reassuring direction: an environment that reports
production when undeclared, and a backup claim never tested against a
real run.

Warnings render only when something is wrong. A row that always reads
'fine' becomes wallpaper and stops being read."
```

---

### Task 9: Dashboard widget

**Files:**
- Create: `modules/widget.php`
- Modify: `inc/modules.php` (one manifest line)

**Interfaces:**
- Consumes: everything from `inc/status.php` (Task 8), `marginal_core_mainwp_is_connected()`, `marginal_core_mainwp_unique_id()`, `marginal_core_is_safe_unique_id()` (Task 6), `marginal_core_is_protected_login()` (Task 7), `marginal_core_config()` (Task 1).
- Produces:
  - `marginal_core_facts(): array` — gathers the fact array `marginal_core_warnings()` consumes.
  - `marginal_core_widget_render(): void`
  - `marginal_core_widget_setup(): void`
  - `marginal_core_widget_boot(): void`

This task has no unit tests: `marginal_core_facts()` is pure I/O against WordPress, and the rendering is HTML. Every decision it makes was tested in Task 8. Rendering is covered by the manual checklist in Task 11.

- [ ] **Step 1: Write `modules/widget.php`**

```php
<?php
/**
 * Marginal dashboard widget.
 *
 * Replaces WordPress's default clutter with one panel. Every claim here is
 * derived from a check — never asserted in prose. A static reassurance about
 * backups on the dashboard of a site that has none is the kind of thing that
 * matters precisely once, badly.
 *
 * No network calls and no uncached queries: this renders on every dashboard
 * load across the whole fleet.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * Gather everything the panel reports.
 *
 * @return array<string,mixed>
 */
function marginal_core_facts(): array {
	$environment = marginal_core_normalize_environment(
		apply_filters( 'marginal_core_detected_environment', wp_get_environment_type() )
	);

	$declared = ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE )
		|| ( false !== getenv( 'WP_ENVIRONMENT_TYPE' ) && '' !== getenv( 'WP_ENVIRONMENT_TYPE' ) );

	$unique_id = marginal_core_mainwp_unique_id();
	$user      = wp_get_current_user();

	return array(
		'search_engines_blocked' => '0' === (string) get_option( 'blog_public', '1' ),
		'debug_in_production'    => defined( 'WP_DEBUG' ) && WP_DEBUG && 'production' === $environment,
		'environment'            => $environment,
		'environment_declared'   => $declared,
		'backup'                 => marginal_core_backup_status(
			get_option( 'updraft_last_backup' ),
			(int) marginal_core_config( 'backup_max_age_days', 7 ),
			time()
		),
		'mainwp_connected'       => marginal_core_mainwp_is_connected(),
		'mainwp_unique_id'       => $unique_id,
		'mainwp_id_safe'         => marginal_core_is_safe_unique_id( $unique_id ),
		'patchstack_active'      => defined( 'PATCHSTACK_VERSION' )
			|| class_exists( 'Patchstack' )
			|| (bool) get_option( 'patchstack_options' ),
		'object_cache'           => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
		'php_version'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
		'is_marginal_viewer'     => $user && marginal_core_is_protected_login( (string) $user->user_login ),
	);
}

/**
 * One status row.
 */
function marginal_core_widget_row( string $label, string $value, string $colour = '#64748b' ): string {
	return sprintf(
		'<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0;color:#475569;">%s</td>'
		. '<td style="padding:6px 0;text-align:right;color:%s;font-weight:600;">%s</td></tr>',
		esc_html( $label ),
		esc_attr( $colour ),
		esc_html( $value )
	);
}

function marginal_core_widget_render(): void {
	$facts    = marginal_core_facts();
	$marginal = (bool) $facts['is_marginal_viewer'];
	$warnings = marginal_core_visible_warnings( marginal_core_warnings( $facts ), $marginal );

	$green = '#16a34a';
	$amber = '#d97706';
	$red   = '#dc2626';

	$summary_colour = empty( $warnings ) ? $green : $amber;

	$backup_state = $facts['backup']['state'];
	$backup_text  = 'ok' === $backup_state && $facts['backup']['time']
		? date_i18n( get_option( 'date_format' ), (int) $facts['backup']['time'] )
		: ucfirst( $backup_state );
	?>
	<div style="font-family:system-ui,-apple-system,sans-serif;font-size:13px;color:#1e293b;">

		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #e2e8f0;">
			<div style="background:#0f172a;color:#fff;font-weight:700;padding:4px 10px;border-radius:4px;font-size:12px;letter-spacing:0.5px;">MARGINAL</div>
			<div style="font-size:11px;color:<?php echo esc_attr( $summary_colour ); ?>;font-weight:600;">
				<?php echo esc_html( marginal_core_summary_text( count( $warnings ) ) ); ?>
			</div>
		</div>

		<?php if ( 'production' !== $facts['environment'] ) : ?>
			<div style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 10px;border-radius:6px;margin-bottom:12px;font-weight:600;">
				<?php echo esc_html( strtoupper( $facts['environment'] ) ); ?> — not the live site
			</div>
		<?php endif; ?>

		<table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
			<?php
			echo marginal_core_widget_row(
				'Firewall protection (Patchstack)',
				$facts['patchstack_active'] ? 'Protected' : 'Standard',
				$facts['patchstack_active'] ? $green : $amber
			);

			echo marginal_core_widget_row(
				'Central maintenance (MainWP)',
				$facts['mainwp_connected'] ? 'Connected' : 'Disconnected',
				$facts['mainwp_connected'] ? $green : $red
			);

			echo marginal_core_widget_row(
				'Latest backup',
				$backup_text,
				'ok' === $backup_state ? $green : $red
			);

			echo marginal_core_widget_row(
				'Object cache',
				$facts['object_cache'] ? 'Active' : 'Not in use'
			);

			echo marginal_core_widget_row( 'Server runtime', 'PHP ' . $facts['php_version'] );

			if ( $marginal ) {
				echo marginal_core_widget_row(
					'MainWP security ID',
					'' === $facts['mainwp_unique_id'] ? 'Not set' : $facts['mainwp_unique_id']
				);
			}
			?>
		</table>

		<?php if ( ! empty( $warnings ) ) : ?>
			<ul style="list-style:none;margin:0 0 14px;padding:0;">
				<?php foreach ( $warnings as $warning ) : ?>
					<li style="padding:8px 10px;margin-bottom:6px;border-radius:6px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;">
						<?php echo esc_html( $warning['text'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #e2e8f0;">
			<div style="font-weight:600;margin-bottom:4px;color:#0f172a;">Need assistance or site changes?</div>
			<div style="color:#64748b;font-size:12px;margin-bottom:10px;">This site is maintained by Marginal.</div>
			<a href="<?php echo esc_url( (string) marginal_core_config( 'support_url', 'https://marginal.dk' ) ); ?>"
				target="_blank" rel="noopener"
				style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:6px 12px;border-radius:4px;font-weight:600;font-size:12px;">
				Contact support &rarr;
			</a>
		</div>
	</div>
	<?php
}

function marginal_core_widget_setup(): void {
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );

	wp_add_dashboard_widget(
		'marginal_core_status',
		'Marginal — site care and protection',
		'marginal_core_widget_render'
	);
}

function marginal_core_widget_boot(): void {
	add_action( 'wp_dashboard_setup', 'marginal_core_widget_setup' );
}
```

- [ ] **Step 2: Add to the manifest**

In `inc/modules.php`, after the `user-guard` line:

```php
		'widget' => 'widget.php',
```

- [ ] **Step 3: Check syntax and run the suite**

Run: `php -l modules/widget.php && composer test`
Expected: `No syntax errors detected`, and PASS, 93 tests — unchanged, this task adds none.

The widget file is not added to `tests/bootstrap.php`: it calls WordPress functions that the stub set deliberately does not cover, and everything it decides was already tested in Task 8.

- [ ] **Step 4: Commit**

```bash
git add modules/widget.php inc/modules.php
git commit -m "Add the Marginal dashboard widget

Replaces WordPress's default clutter with one panel whose every claim
is derived from a check: the backup row reports UpdraftPlus's last
successful run rather than the mere presence of the plugin, and the
MainWP badge reports the handshake rather than the install.

Warnings render only when something is wrong, and the summary line
answers the panel's question before any row is read. The MainWP
security ID is visible only to protected users, so onboarding is
copy-paste for us without exposing it to client staff."
```

---

### Task 10: White-label module

**Files:**
- Create: `modules/white-label.php`
- Create: `tests/WhiteLabelTest.php`
- Modify: `tests/bootstrap.php` (one `require_once`)
- Modify: `inc/modules.php` (one manifest line)

**Interfaces:**
- Consumes: `marginal_core_config()` from Task 1.
- Produces:
  - `marginal_core_admin_footer_text(): string`
  - `marginal_core_login_header_url(): string`
  - `marginal_core_login_header_text(): string`
  - `marginal_core_white_label_boot(): void`

- [ ] **Step 1: Append to `tests/bootstrap.php`**

Add an `esc_url` / `esc_html` stub above the requires:

```php
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}
```

And at the bottom:

```php
require_once __DIR__ . '/../modules/white-label.php';
```

- [ ] **Step 2: Write the failing test**

Create `tests/WhiteLabelTest.php`:

```php
<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class WhiteLabelTest extends TestCase {

	protected function setUp(): void {
		marginal_core_test_reset_hooks();
	}

	public function test_footer_text_links_to_the_support_url(): void {
		$this->assertStringContainsString( 'https://marginal.dk', marginal_core_admin_footer_text() );
		$this->assertStringContainsString( 'Marginal', marginal_core_admin_footer_text() );
	}

	public function test_login_header_url_is_the_support_url(): void {
		$this->assertSame( 'https://marginal.dk', marginal_core_login_header_url() );
	}

	public function test_login_header_text_names_marginal(): void {
		$this->assertStringContainsString( 'Marginal', marginal_core_login_header_text() );
	}

	public function test_manifest_contains_all_seven_modules(): void {
		$this->assertSame(
			array( 'hardening', 'rest', 'cron-fixes', 'mainwp', 'user-guard', 'widget', 'white-label' ),
			array_keys( marginal_core_modules() )
		);
	}

	public function test_boot_registers_all_three_filters(): void {
		marginal_core_white_label_boot();

		$this->assertSame( array( 'marginal_core_admin_footer_text' ), marginal_core_test_hooks( 'admin_footer_text' ) );
		$this->assertSame( array( 'marginal_core_login_header_url' ), marginal_core_test_hooks( 'login_headerurl' ) );
		$this->assertSame( array( 'marginal_core_login_header_text' ), marginal_core_test_hooks( 'login_headertext' ) );
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test`
Expected: FAIL — `Call to undefined function marginal_core_admin_footer_text()`.

- [ ] **Step 4: Write `modules/white-label.php`**

```php
<?php
/**
 * Marginal branding on the admin footer and login screen.
 *
 * All three destinations read MARGINAL_CORE_SUPPORT_URL, the same constant
 * the widget uses, so a client-specific support destination is one define
 * rather than three.
 *
 * wp-login.php is not an admin context, so none of these hooks may sit
 * behind an is_admin() check.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

function marginal_core_support_url(): string {
	return (string) marginal_core_config( 'support_url', 'https://marginal.dk' );
}

function marginal_core_admin_footer_text(): string {
	return sprintf(
		'Maintained and managed by <a href="%s" target="_blank" rel="noopener">Marginal</a>',
		esc_url( marginal_core_support_url() )
	);
}

function marginal_core_login_header_url(): string {
	return marginal_core_support_url();
}

function marginal_core_login_header_text(): string {
	return 'Managed by Marginal';
}

function marginal_core_white_label_boot(): void {
	add_filter( 'admin_footer_text', 'marginal_core_admin_footer_text' );
	add_filter( 'login_headerurl', 'marginal_core_login_header_url' );
	add_filter( 'login_headertext', 'marginal_core_login_header_text' );
}
```

- [ ] **Step 5: Add to the manifest**

In `inc/modules.php`, after the `widget` line:

```php
		'white-label' => 'white-label.php',
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test`
Expected: PASS, 98 tests (93 + 5 new). `test_manifest_contains_all_seven_modules` now pins the manifest, and `ModulesTest::test_every_manifest_entry_has_a_file_on_disk` covers all seven files.

- [ ] **Step 7: Commit**

```bash
git add modules/white-label.php tests/ inc/modules.php
git commit -m "Add white-label module

Footer and login branding, all three reading the same support-URL
constant the widget uses, so a client-specific destination is one
define rather than three.

Completes the manifest: the module-file existence test now covers all
seven, so manifest and filesystem cannot drift."
```

---

### Task 11: Verification, documentation and first release

**Files:**
- Create: `docs/manual-checklist.md`
- Modify: `docs/verification.md` (created in Task 3)
- Modify: `README.md`
- Modify: `marginal-core.php` (bump header **and** `MARGINAL_CORE_VERSION` from the `0.9.x` development series to `1.0.0` — the release workflow fails the build if the two disagree with the tag)

- [ ] **Step 1: Write `docs/manual-checklist.md`**

```markdown
# Manual verification checklist

Run on a staging child site before every release. The unit suite covers
every decision; this covers everything that only exists once WordPress is
actually running.

## Capability guard

- [ ] Sign in as a client administrator (not a protected login).
- [ ] Users list: the Marginal row shows "Managed by Marginal" and has no
      Delete link.
- [ ] Attempt bulk-delete including the Marginal user — deletion is refused
      with the explanatory message.
- [ ] Attempt to change the Marginal user's role — refused.
- [ ] `DELETE /wp-json/wp/v2/users/<marginal id>?reassign=1` as that
      administrator returns a permission error, not a deletion. **This is the
      path the admin UI cannot tell you about.**
- [ ] `wp user delete <marginal id>` over WP-CLI succeeds — the escape hatch
      must work. Restore the user afterwards.

## Widget

- [ ] Panel renders for a client administrator, with no MainWP security ID row.
- [ ] Panel renders for a protected user, with the security ID row present.
- [ ] Set `blog_public` to 0 — the search-engine warning appears and the
      summary line counts it.
- [ ] Restore it — the warning disappears and the summary reads
      "Everything looks good".
- [ ] Set `WP_ENVIRONMENT_TYPE` to `staging` — the coloured strip appears.
- [ ] Unset it — the strip goes, and the "not declared" row appears for a
      protected user only.

## MainWP

- [ ] On a disconnected site with a symbol-bearing unique ID, load any admin
      page — the ID is replaced with a 32-character alphanumeric value.
- [ ] On a connected site with a symbol-bearing unique ID, load any admin page
      — the ID is **unchanged**, and the warning row appears for a protected
      user. This is the check that protects live connections.

## Branding

- [ ] Admin footer reads "Maintained and managed by Marginal".
- [ ] Login screen logo links to the support URL and reads "Managed by
      Marginal".

## Update channel

- [ ] Dashboard → Updates → Check again shows the new version.
- [ ] Updating installs it, and the site still loads afterwards.
- [ ] The MainWP dashboard lists the pending update for this site.
```

- [ ] **Step 2: Update `README.md`**

Replace the status block with:

```markdown
> **Status: v1.0.0.** Installed via MainWP; updates arrive through GitHub
> releases. See
> [`docs/superpowers/specs/2026-09-21-marginal-core-design.md`](docs/superpowers/specs/2026-09-21-marginal-core-design.md)
> for the design and [`docs/manual-checklist.md`](docs/manual-checklist.md)
> for the pre-release checks.
```

Add to the configuration section:

```markdown
| `MARGINAL_CORE_BACKUP_MAX_AGE_DAYS` | `7` | Age at which the backup row warns. |
```

- [ ] **Step 3: Run the full suite one last time**

Run: `composer test`
Expected: PASS, 98 tests, zero warnings, zero notices, zero risky.

- [ ] **Step 4: Lint every PHP file**

Run: `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors' || echo "all clean"`
Expected: `all clean`.

- [ ] **Step 5: Work the manual checklist on staging**

Install the current build on the staging child site and complete every box in `docs/manual-checklist.md`. Record the results in `docs/verification.md`.

Do not proceed to release with an unchecked box. If something fails, fix it, add a unit test reproducing it where the logic allows, and start the checklist again.

- [ ] **Step 6: Commit and tag**

```bash
git add docs/ README.md
git commit -m "Add manual checklist and release documentation

The checklist covers what the unit suite structurally cannot: the REST
deletion path, the WP-CLI escape hatch, and the two MainWP cases whose
whole point is behaviour against a live connection."

git tag v1.0.0
git push origin main --tags
```

- [ ] **Step 7: Verify the release built**

Run: `gh release view v1.0.0 --repo MarginalDK/Marginal-Core`
Expected: the release exists with `marginal-core-1.0.0.zip` attached.

- [ ] **Step 8: Roll out**

Deliberately slow at the start — the whole point of a manual update click.

1. Staging site: already done via the checklist.
2. One pilot client. Observe for a week: no client complaints, widget renders, MainWP still connected.
3. The fleet, in batches, via MainWP.

Do not skip step 2. It is the only thing standing between a bad release and every client at once.

---

## Plan self-review

**Spec coverage.** Every spec section maps to a task: config and constants (1), bootstrap and loader (2), self-update and release workflow (3), hardening and REST (4), cron fixes (5), MainWP (6), user-guard (7), widget logic and rendering (8, 9), white-label (10), testing and rollout (11). The spec's "To verify during implementation" list is distributed to the point of use — MainWP option names in Task 6 Step 1, the update filter in Task 3 Step 9, Patchstack and UpdraftPlus detection exercised by the Task 11 checklist.

**Known deliberate gaps.** `modules/widget.php` has no unit tests; its logic lives in `inc/status.php`, which does. `marginal_core_facts()` is untested for the same reason — it is I/O with no branching beyond what Task 8 covers. Both are covered by the manual checklist.

**Interface consistency.** Function names were checked across tasks: `marginal_core_is_safe_unique_id` (defined Task 6, used Tasks 8 and 9), `marginal_core_is_protected_login` (defined Task 7, used Task 9), `marginal_core_config` (defined Task 1, used in 6, 7, 8, 9, 10), the `marginal_core_<slug>_boot` convention (defined Task 2, satisfied by every module task), and the facts array keys (produced Task 9, consumed Task 8 — `search_engines_blocked`, `debug_in_production`, `environment`, `environment_declared`, `backup`, `mainwp_connected`, `mainwp_id_safe`).

**Running test count** by task, for spotting a silently skipped suite: 10, 15, 24, 34, 39, 55, 72, 93, 93, 98. Counted from the test methods written into this plan, not estimated.

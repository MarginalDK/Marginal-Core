# Marginal Core — design

A single WordPress plugin carrying Marginal's agency baseline: hardening,
MainWP connection checks, and white-labelling. Installed on essentially every
client site, updated centrally through MainWP.

- Repo: `github.com/MarginalDK/Marginal-Core`
- Plugin slug: `marginal-core`
- Predecessor: a hand-written single file, `marginal-mu.php` v1.4.1. Most of
  its code survives here; the sections below note where it changes and why.

## Purpose

Three problems, in the order they hurt:

1. **MainWP child connections break** when the child's unique security ID
   contains symbols. This plugin detects and reports that, to a Marginal
   viewer, rather than rewriting the ID — see the `mainwp` module below for
   why an automatic repair was built, then deliberately removed.
2. **The same baseline is copy-pasted onto every site** — hardening, cron
   fixes, branding — so it drifts, and fixing one site fixes only that site.
3. **Agency access is deletable.** A client admin can remove the account we
   use to reach the site, and nothing prevents it.

## Constraints

- **Non-intrusive.** This ships to ~99% of client sites. Anything that could
  surprise a client or break an integration must be off by default or absent.
- **Lightweight.** Negligible cost on ordinary visitor requests.
- **Centrally updatable.** Distributed and updated by MainWP like any other
  plugin.
- **No per-site forks.** One artifact, configured per site from outside itself.

## Decisions

| Decision | Rationale |
|---|---|
| Regular plugin, not an mu-plugin | MainWP already installs and updates plugins across the fleet. An mu-plugin would need a bespoke updater, and a bad update would hit every site at once with no rollback path. Accepted cost: a client admin *can* deactivate it. |
| Config lives in `wp-config.php`, never in the plugin | v1.4.1 `define()`s its toggles inside the plugin file, so the next fleet update erases any per-site change. Constants read from outside the artifact fix this. |
| `Update URI` points at the repo | Any non-wordpress.org `Update URI` stops w.org from overwriting this plugin when a slug collides — catastrophic if omitted. Pointing it at the repo rather than `false` additionally routes update checks to our own handler, so every site gains a real update channel instead of none. |
| Public repository | GitHub-driven updates then need no credentials anywhere. A private repo would put a GitHub token on every client site, which is a token to treat as public. The plugin carries conventions, not secrets — all per-site configuration is `wp-config.php` constants — so readability costs nothing we rely on. |
| No user provisioning | Circular: installing the plugin requires admin access, which requires the account to already exist. The plugin protects an account it did not create. |
| No `DISALLOW_FILE_MODS` / IP whitelisting in v1 | Most intrusive feature in the predecessor, fights MainWP's own updates, and fails silently — a site that refuses updates looks identical to a healthy one. Backlogged as opt-in. |
| MainWP unique-ID module is detect-only, not repair | An earlier version rewrote an "unsafe" ID on any site not yet connected. That was a live bug: MainWP enforces the unique-ID requirement only when the stored ID is **non-empty** (`class-mainwp-connect.php:118`), and an empty ID is MainWP's own default — it means the feature is off, not broken. The repair treated empty as unsafe and wrote a real ID into that slot on every fresh site, which *enabled* a requirement the dashboard didn't know about and broke the first connection attempt. It was also never necessary: MainWP generates its own IDs with `wp_generate_password( 12, false )`, already alphanumeric, so a symbol-bearing ID never comes from MainWP itself — only from a human, a `MAINWP_CHILD_UNIQUEID` constant, or an old MainWP version, and the constant case was already correctly left untouched. The module now only reads and flags. |
| No reporting of any kind | Patchstack and MainWP already alert us: MainWP reports plugin status and connection loss per site, Patchstack covers the security side. A second channel would duplicate both and add a cron event, two options and an outbound mail path to every client site. Anything this plugin knows and MainWP does not is surfaced passively in the dashboard widget. |
| Module loading is never context-conditional | Every enabled module loads on every request type. Conditional `require` was considered and rejected: `REST_REQUEST` is not defined at plugin-load time, so a REST request is indistinguishable from a public page view, and the user-protection module would be skipped on exactly the path that can delete users. Under opcache seven small requires cost microseconds; the real cost is hooks firing, which each module gates internally. |

## Non-goals

Explicitly out of scope for v1, listed so they are not rediscovered as
oversights:

- Client capability lockdown (blocking plugin/theme installation).
- Self-update independent of MainWP.
- Per-employee accounts on client sites.
- Any admin settings screen. Configuration is constants only.

## Architecture

```
marginal-core/
  marginal-core.php          bootstrap: header, guard, config, module loader
  inc/
    config.php               constant + filter accessor, safe defaults
    modules.php              module manifest and loader
    status.php               pure widget facts/warnings, unit-tested apart from rendering
    updater.php              GitHub release update channel
  modules/
    hardening.php
    rest.php
    cron-fixes.php
    mainwp.php
    user-guard.php
    widget.php
    white-label.php
  tests/
  .github/workflows/release.yml
```

No namespace, as built: every function is declared at global scope, prefixed
`marginal_core_`. Plain functions only — no classes, no autoloading — which is
also what lets a module file be required directly from a test without
WordPress loaded.

### Bootstrap

```php
/**
 * Plugin Name: Marginal Core
 * Plugin URI:  https://github.com/MarginalDK/Marginal-Core
 * Description: Marginal agency baseline — hardening, MainWP checks, white-labelling.
 * Version:     1.0.0
 * Author:      Marginal
 * Author URI:  https://marginal.dk
 * Update URI:  https://github.com/MarginalDK/Marginal-Core
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
```

The whole file is wrapped in a `MARGINAL_CORE_VERSION` guard, so a double load
(an mu-plugin shim during migration, say) is a silent no-op rather than a
fleet-wide fatal from redeclared functions. The predecessor declares
`marginal_get_request_ip()` and friends unguarded at global scope.

### Module loader

One manifest, one loop:

```php
foreach ( marginal_core_modules() as $slug => $file ) {
    if ( marginal_core_module_enabled( $slug ) ) {
        require MARGINAL_CORE_DIR . "modules/{$file}";
    }
}
```

Adding a module is adding a file and a manifest line. Disabling one for a
single client is a constant in that site's `wp-config.php`, not a fork.

### Configuration

Every knob is an optional constant with a safe default:

| Constant | Default | Effect |
|---|---|---|
| `MARGINAL_CORE_DISABLED_MODULES` | `''` | Comma-separated slugs to skip. |
| `MARGINAL_CORE_PROTECTED_USERS` | `['marginal']` | Logins to guard. |
| `MARGINAL_CORE_PROTECTION` | `true` | Master switch for `user-guard`. |
| `MARGINAL_CORE_SUPPORT_URL` | `https://marginal.dk` | Widget and footer link. |
| `MARGINAL_CORE_BACKUP_MAX_AGE_DAYS` | `7` | Age at which the backup row warns. |

Read through one accessor, which also exposes each value as a filter so a
site-specific mu-plugin can override it programmatically:

```php
function marginal_core_config( string $key, $default = null ) {
    $const = 'MARGINAL_CORE_' . strtoupper( str_replace( '-', '_', $key ) );
    $value = defined( $const ) ? constant( $const ) : $default;
    return apply_filters( "marginal_core_config_{$key}", $value );
}
```

## Modules

### `hardening`

Carried from predecessor §3, with XML-RPC remaining disabled by default —
confirmed acceptable: no client uses Jetpack or the mobile app.

- `DISALLOW_FILE_EDIT` defined if not already, disabling the in-dashboard code
  editor.
- `xmlrpc_enabled` filtered to false.
- `X-Pingback` stripped from response headers.

`DISALLOW_FILE_MODS` and the IP-whitelist machinery are **removed**. The
predecessor's `marginal_get_request_ip()` trusted `HTTP_CF_CONNECTING_IP`
unconditionally while listing `127.0.0.1` as allowed, so any request could
spoof its way past the check. With the feature gone, no IP detection remains
anywhere in the plugin — which removes the predecessor's single worst piece of
code rather than carrying a corrected version of it for no consumer.

### `rest`

Carried unchanged: `/wp/v2/users` and `/wp/v2/users/<id>` are unregistered for
unauthenticated requests, blocking the easiest user-enumeration path.

Known gap, accepted: `?author=N` redirects and `_embed` responses can still
leak author slugs. Closing those risks breaking legitimate theme behaviour,
which fails the non-intrusive test.

### `cron-fixes`

Carried from predecessor §4, both pure additions with no client-visible effect:

- Registers the missing `minute` cron schedule that MainWP's System Monitor
  expects, preventing WP-Cron errors.
- On multisite sub-sites, unhooks MainWP Child's connection notices.

### `mainwp`

The unique security ID is the reason this project exists. The module is
**read-only** — it never writes `mainwp_child_uniqueId` or any other MainWP
option, and that is a property worth preserving deliberately, not an
oversight.

Safe IDs are 32 characters of `[A-Za-z0-9]`, validated against
`/^[A-Za-z0-9]{8,64}$/`.

An earlier version of this module *did* write: on a site not yet connected,
an unsafe ID was regenerated on `admin_init`. That was removed as a live bug,
and the reasoning is worth keeping on record so it is not rediscovered as a
gap and re-added:

- MainWP Child enforces the unique-ID requirement only when the stored ID is
  **non-empty** (`class-mainwp-connect.php:118`, confirmed against
  `github.com/mainwp/mainwp-child`). An empty ID is MainWP's own default and
  simply means the feature is switched off — it is not evidence of anything
  broken.
- The safety regex requires 8-64 characters, so it correctly reports an empty
  ID as "unsafe" in the narrow sense of "not fit to send through the
  handshake". The old repair conflated that with "needs fixing" and wrote a
  fresh 32-character ID into the option on every fresh, disconnected site.
  That *enables* the unique-ID requirement with a value the Marginal
  dashboard has never seen, and the site's very first connection attempt
  then fails with MainWP's `REG_ERROR3`.
- Even setting that bug aside, the write was never useful: MainWP generates
  its own IDs with `wp_generate_password( 12, false )` — already alphanumeric
  (`class-mainwp-helper.php:603-608`). MainWP never produces a symbol-bearing
  ID itself, so one can only come from a human typing it in, a host-set
  `MAINWP_CHILD_UNIQUEID` constant, or an old MainWP version — and the
  constant case was already, correctly, left untouched (writing the option
  there changes nothing MainWP reads).

So the module now only detects an unsafe, non-empty ID and surfaces it as a
widget warning — see `widget` below — regardless of connection state; it
never attempts to fix it.

The current ID is shown in the dashboard widget only when the viewer is one of
`MARGINAL_CORE_PROTECTED_USERS` — so onboarding is copy-paste for us without
exposing the ID to the client's staff.

Connection state is read from the MainWP Child public-key option rather than
`class_exists( 'MainWP_Child' )`. The predecessor's check is unnamespaced while
modern MainWP Child is `\MainWP\Child\MainWP_Child`, so that arm likely never
fires; more importantly, plugin presence is not connection. The badge must
report the handshake, not the install.

### `user-guard`

Protects hand-created Marginal accounts from client deletion. Inert unless a
listed login exists on the site, so it costs nothing on sites that don't use
it.

Protection is capability-level, which is the only layer that holds against both
the admin UI and the REST API:

- `map_meta_cap` denies `delete_user`, `edit_user`, `promote_user` and
  `remove_user` when the target is protected and the actor is someone else.
  `remove_user` is the meta cap WordPress's Users-screen "Remove" action maps
  to on multisite, so that path is covered; a direct call to
  `remove_user_from_blog()` bypassing capability checks is not — the same
  documented limitation as `$user->set_role()` below.
- `delete_user` and `wpmu_delete_user` actions carry a last-resort backstop,
  `marginal_core_guard_block_delete()`, which `wp_die()`s the moment it is
  called for a protected account.
- The Users-list row loses its Delete action and gains a "Managed by Marginal"
  label — visible and explained, not hidden.
- Bulk delete does **not** exclude protected users from the batch; it dies at
  the backstop above the moment it reaches one. Any unprotected user ordered
  before the protected one in the same batch is deleted before the loop
  stops. This is a known sharp edge, not a design goal — see
  `docs/manual-checklist.md`.
- WP-CLI bypasses the guard entirely, as the escape hatch.

The decision itself is extracted as a pure function — actor, target, config in;
boolean out — so it is unit-testable without WordPress.

Documented limitation: code calling `$user->set_role()` directly bypasses
capability checks. No plugin-level defence exists for that, and pretending
otherwise would be worse than naming it.

Password-blocking for the protected account was considered and dropped. It made
sense when the plugin provisioned the account with a password nobody knew; for
a hand-created account it only creates a lockout risk.

### `widget`

Replaces WordPress's default dashboard clutter with one Marginal panel.
Removed: `dashboard_primary`, `dashboard_quick_press`, `dashboard_site_health`.

Two principles govern what goes in it, both learned from the predecessor:

**Warnings appear only when something is wrong.** A row reading "Search
engines: visible" every day becomes wallpaper and stops being read. Rendering
it only when the site is set to noindex makes its presence the alarm, and
keeps the panel short enough to scan on a healthy site.

**Every claim is derived from a check, never asserted in prose.** The
predecessor tells the client their site is "monitored, hardened, and backed
up"; two of those are verified by badges above the sentence and the third is
not checked at all. A static reassurance about backups on the dashboard of a
site that has none is the kind of thing that matters precisely once, badly.

Audience splits in two, and only two: WordPress can distinguish users listed
in `MARGINAL_CORE_PROTECTED_USERS` from everyone else. Colleagues sign in as
the Marginal account, so they see what we see; client staff are administrators
and see the client view.

#### Always shown

- A one-line summary at the top — "Everything looks good", or "2 items need
  attention" — so the panel answers its own question before any row is read.
- Patchstack firewall status.
- MainWP connection state, from the public-key option.
- Backups, from UpdraftPlus: the date of the last successful run, not merely
  whether the plugin exists.
- Object or page cache in use.
- PHP version, as a plain version string.
- Support call to action, linking to `MARGINAL_CORE_SUPPORT_URL`.

#### Shown only when wrong

Each is a real incident that otherwise stays invisible for months, and each
costs one option read:

| Condition | Check |
|---|---|
| Search engines blocked | `blog_public` is `0` |
| Debug output in production | `WP_DEBUG` true and environment is production |
| Declared non-production | `wp_get_environment_type()` returns `local`, `development` or `staging` — rendered as a coloured strip so nobody edits staging believing it is live |
| Backup missing, failed, or stale | `updraft_last_backup`, against `MARGINAL_CORE_BACKUP_MAX_AGE_DAYS` (default 7) |

#### Shown only to protected users

With no alerting channel, the widget is the plugin's only reporting surface:

- The MainWP unique security ID, so onboarding is copy-paste for us without
  exposing it to the client's staff.
- A warning when that ID is unsafe and non-empty, whether the site is
  connected or not — the `mainwp` module is read-only and never repairs it;
  see that section for why. An empty ID does not warn: it is MainWP's own
  default and means the feature is off, not broken.
- "Environment not declared", when `WP_ENVIRONMENT_TYPE` is unset.

That last row exists because `wp_get_environment_type()` detects nothing. It
reads the `WP_ENVIRONMENT_TYPE` constant or environment variable, accepts only
`local`, `development`, `staging` and `production`, and returns `production`
for everything else — including when nothing is set at all. On a staging site
where nobody configured it, the function reports production and the strip
above never fires: a check that fails silently in the reassuring direction.

The fix is not to guess. Hostname patterns like `staging.` or `dev.` would put
a red non-production strip on the live dashboard of any client whose real site
happens to sit at `dev.example.dk`, which is exactly the client-visible
surprise this plugin exists to avoid. Instead the gap itself is reported, to
the only people who can close it, and setting `WP_ENVIRONMENT_TYPE` becomes an
onboarding step the widget audits. A hostname pattern may phrase this row as a
hint — "looks like staging" — but never asserts anything to a client.

Detection is read through a `marginal_core_detected_environment` filter, so a
managed host's authoritative constant can be taught to it later in one line,
without a speculative list of platforms today. Marginal's fleet is mostly
Danish shared hosting, which sets no such constants.

#### Rendering

No network calls, no uncached queries. Every value above is a constant, a
function call, or a single option read, because this renders on every
dashboard load across the whole fleet.

Styles stay inline. At this size an enqueued stylesheet is an extra request for
no benefit, and the widget renders only for logged-in administrators.

### `white-label`

Carried from predecessor §6: admin footer text, login header URL and text. All
three link to `MARGINAL_CORE_SUPPORT_URL`, the same constant the widget uses,
so a client-specific support destination is one define rather than three.

Note for implementation: `wp-login.php` is not an admin context, so this
module's hooks must not sit behind an `is_admin()` check.

## Internationalisation

Added after the initial implementation, reversing the original non-goal.

The 43 user-visible strings carry the `marginal-core` text domain, loaded on
`init` — the call is required, because WordPress only loads translations
automatically for plugins hosted on wordpress.org. Danish is bundled as
`languages/marginal-core-da_DK.po` with its compiled `.mo`, and
`languages/marginal-core.pot` is the template for any further language.

**The site's own language decides.** A Danish admin sees Danish, an English one
sees English — rather than hardcoding Danish and surprising a client who runs
their dashboard in English.

Two details worth keeping if this is ever revisited:

- The admin-footer string is `Maintained and managed by %s`, with the built
  anchor passed in as the placeholder, so no translator ever edits HTML.
- `'Every Minute'`, the cron schedule label, is deliberately NOT translated.
  `cron_schedules` can fire before `init`, and WordPress 6.7 emits a
  `_doing_it_wrong` notice for translations loaded that early. It is an
  internal label visible only in debug tooling.

`.mo` is used rather than the newer `.l10n.php` format, which would require
raising the WordPress floor from 6.0 to 6.5.

## Self-update

Since WordPress 5.8, core parses the `Update URI` header, extracts its
hostname, and hands the update check to a filter named after it. With the
header above that filter is `update_plugins_github.com`:

```php
add_filter( 'update_plugins_github.com', 'marginal_core_check_for_update', 10, 4 );
```

The handler fetches the repository's latest release from the public GitHub
API, compares its tag against `MARGINAL_CORE_VERSION` with `version_compare`,
and on a newer version returns an array carrying `id`, `slug`, `version`,
`url` and `package` — the release's zip asset. Core does everything after
that: the site shows an ordinary "update available", and the MainWP child
reports it to Bastion alongside every other pending update.

The full path is therefore: tag a release, then click update in Bastion once.
No zip uploads, no Git Updater dependency, no credentials on client sites.

Three constraints on the handler, all of which exist because this code runs
unattended on every client site:

- **Never fatal, never block.** Any network failure, rate limit, malformed
  response or missing asset returns `$update` untouched. A GitHub outage must
  degrade to "no update found", never to a broken admin screen.
- **Cached.** Responses are cached in a transient for twelve hours, so the
  fleet does not hammer the API and an admin page load never waits on GitHub.
  WordPress's own update cron already throttles the check; the transient
  covers the manual "check again" path.
- **Version comes from the tag, not the release title.** Tags are `v1.1.0`;
  the leading `v` is stripped before comparison.

WordPress auto-updates are deliberately **not** enabled for this plugin. The
click stays manual in MainWP so a bad release reaches the pilot site rather
than the fleet, which is the whole point of the staged rollout below.

## Testing

Proportionate to a ~300-line plugin, and concentrated where silent failure is
most expensive.

**Unit tests** (PHPUnit + Brain Monkey, no WordPress bootstrap):

- Config resolution: constant set, constant absent, filter override.
- Module enable/disable parsing, including whitespace and empty strings.
- Unique-ID validation, and constant-vs-option precedence when resolving the
  effective ID.
- The protection decision function across actor/target/config combinations.
- Widget warning conditions: each fires when it should and stays absent when
  it should not, and the summary line counts them correctly.
- Environment resolution: declared values, unset, an invalid value falling
  back to production, and the filter overriding all of them.
- Backup freshness against the age threshold, including missing option,
  failed run, and boundary ages.
- Update handler: newer, older and equal versions; `v` prefix stripping;
  and each degradation path — HTTP error, rate limit, malformed JSON,
  release with no zip asset — returning the input unchanged.

**Manual verification checklist**, run on a staging child site before each
release: capability denial through both the admin UI and a REST `DELETE`,
widget rendering, login and footer branding, and a MainWP-driven update of the
plugin itself.

A full `wp-env` integration suite is backlogged. The capability layer is the
one place it would genuinely earn its keep; unit-testing the extracted pure
decision function plus the manual REST check covers most of that value for a
fraction of the rig.

## Distribution and rollout

A tag matching `v*` triggers a GitHub Actions workflow that builds a versioned
zip, excluding `tests/`, `docs/` and dev configuration, and attaches it to the
release.

First installation on a site is MainWP's normal plugin-install flow, from the
public release URL. Every subsequent version arrives through the self-update
channel above and is applied from Bastion like any other plugin update.

Rollout order, deliberately slow at the start:

1. Staging site — full manual checklist.
2. One pilot client, observed for a week.
3. Fleet, in batches, via MainWP.

## Backlog

Each of these is its own module and its own decision, added only when wanted:

- Client capability lockdown — no plugin/theme installation or deletion.
- `DISALLOW_FILE_MODS` with a corrected IP whitelist, opt-in per site.
- Per-employee accounts provisioned from a central roster.
- Authoritative environment detection for managed hosts (Pantheon, Kinsta,
  WP Engine), added per-host through the filter if one appears in the fleet.
- Additional widget rows: backup status, uptime, SSL expiry.

## To verify during implementation

Assumptions taken from reading the predecessor and from MainWP's general
behaviour, each cheap to confirm against an installed copy and each capable of
changing a detail above:

- MainWP Child option names for the unique ID and the public key.
- Whether `MAINWP_CHILD_VERSION` is defined by current MainWP Child.
- Patchstack detection: which constant, class or option is authoritative.
- UpdraftPlus's `updraft_last_backup` option shape — the timestamp and
  success keys the backup row depends on.
- That MainWP's one-click login path is unaffected by `user-guard`.
- That `update_plugins_github.com` fires as expected on the staging site,
  and that the MainWP child reports the resulting update to Bastion. This
  is the one assumption the whole distribution model rests on, so it is
  verified first, before any other module is written.

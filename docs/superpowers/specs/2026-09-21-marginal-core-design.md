# Marginal Core — design

A single WordPress plugin carrying Marginal's agency baseline: hardening,
MainWP connection fixes, and white-labelling. Installed on essentially every
client site, updated centrally through MainWP.

- Repo: `github.com/MarginalDK/Marginal-Core`
- Plugin slug: `marginal-core`
- Predecessor: a hand-written single file, `marginal-mu.php` v1.4.1. Most of
  its code survives here; the sections below note where it changes and why.

## Purpose

Three problems, in the order they hurt:

1. **MainWP child connections break** when the child's unique security ID
   contains symbols.
2. **Every site needs the same baseline** — hardening, cron fixes, branding —
   and today that baseline is copy-pasted, so it drifts.
3. **Nothing tells us when a site stops conforming.** A deactivated plugin or a
   deleted agency account is discovered when we next need it, not when it
   happens.

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
| `Update URI: false` in the header | Without it, a wordpress.org plugin whose slug collides with `marginal-core` can overwrite this plugin on every site. One line, catastrophic if omitted. |
| No user provisioning | Circular: installing the plugin requires admin access, which requires the account to already exist. The plugin protects an account it did not create. |
| No `DISALLOW_FILE_MODS` / IP whitelisting in v1 | Most intrusive feature in the predecessor, fights MainWP's own updates, and fails silently — a site that refuses updates looks identical to a healthy one. Backlogged as opt-in. |
| Conservative unique-ID repair | Regenerating a *working* ID breaks the connection, because the dashboard holds the old value. Repair only before first connection; alert otherwise. |
| Email-only reporting | No new infrastructure, no auth surface, no dashboard-side consumer to build. Accepted limitation documented under Alerts. |
| Module loading is never context-conditional | Every enabled module loads on every request type. Conditional `require` was considered and rejected: `REST_REQUEST` is not defined at plugin-load time, so a REST request is indistinguishable from a public page view, and the user-protection module would be skipped on exactly the path that can delete users. Under opcache seven small requires cost microseconds; the real cost is hooks firing, which each module gates internally. |

## Non-goals

Explicitly out of scope for v1, listed so they are not rediscovered as
oversights:

- Client capability lockdown (blocking plugin/theme installation).
- Self-update independent of MainWP.
- Per-employee accounts on client sites.
- Any admin settings screen. Configuration is constants only.
- Internationalisation. Strings are English; Danish is backlogged.

## Architecture

```
marginal-core/
  marginal-core.php          bootstrap: header, guard, config, module loader
  inc/
    config.php               constant + filter accessor, safe defaults
    request.php              Cloudflare-aware client IP
    alerts.php               deduped wp_mail wrapper
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

Namespace `Marginal\Core`; anything necessarily global is prefixed
`marginal_core_`.

### Bootstrap

```php
/**
 * Plugin Name: Marginal Core
 * Plugin URI:  https://github.com/MarginalDK/Marginal-Core
 * Description: Marginal agency baseline — hardening, MainWP fixes, white-labelling.
 * Version:     1.0.0
 * Author:      Marginal
 * Author URI:  https://marginal.dk
 * Update URI:  false
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
| `MARGINAL_CORE_ALERT_EMAIL` | `alerts@marginal.dk` | Destination for alerts. |
| `MARGINAL_CORE_PROTECTED_USERS` | `['marginal']` | Logins to guard. |
| `MARGINAL_CORE_PROTECTION` | `true` | Master switch for `user-guard`. |
| `MARGINAL_CORE_SUPPORT_URL` | `https://marginal.dk` | Widget and footer link. |

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
spoof its way past the check. `inc/request.php` retains a corrected IP
helper, used by the `tamper_blocked` alert to record where a blocked
attempt came from: `CF-Connecting-IP` is trusted solely when `REMOTE_ADDR`
falls within Cloudflare's published ranges, and `REMOTE_ADDR` alone otherwise.

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

The unique security ID is the reason this project exists, and the repair rule
is deliberately conservative because the failure mode of getting it wrong is
disconnecting a working site.

Safe IDs are 32 characters of `[A-Za-z0-9]`, generated with
`wp_generate_password( 32, false, false )` and validated against
`/^[A-Za-z0-9]{8,64}$/`.

Evaluated once daily on the plugin's cron event:

- **Not yet connected** and the ID is missing or unsafe → write a safe ID
  immediately. This is the onboarding case and the one that actually bites.
- **Already connected** and the ID is unsafe → leave it alone, send one alert.
  The dashboard holds the current value; rewriting it here would break the
  connection. Repair happens deliberately, at a maintenance window.

The current ID is emailed on activation, and shown in the dashboard widget only
when the viewer is one of `MARGINAL_CORE_PROTECTED_USERS` — so onboarding is
copy-paste for us without exposing the ID to the client's staff.

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
- Multisite equivalents `remove_user_from_blog` and `wpmu_delete_user` covered.
- The Users-list row loses its Delete action and gains a "Managed by Marginal"
  label — visible and explained, not hidden.
- Bulk delete excludes protected users.
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

The panel reports, at minimum: Patchstack firewall status, MainWP connection
state (from the public-key option), and PHP version — plus a support call to
action linking to `MARGINAL_CORE_SUPPORT_URL`. It is designed to grow; new rows
are the expected way this plugin gains client-visible value.

Styles stay inline. At this size an enqueued stylesheet is an extra request for
no benefit, and the widget renders only for logged-in admins.

### `white-label`

Carried from predecessor §6: admin footer text, login header URL and text. All
three link to `MARGINAL_CORE_SUPPORT_URL`, the same constant the widget uses,
so a client-specific support destination is one define rather than three.

Note for implementation: `wp-login.php` is not an admin context, so this
module's hooks must not sit behind an `is_admin()` check.

## Alerts

`inc/alerts.php` exposes `marginal_core_alert( $event, $subject, $body )`,
sending via `wp_mail` to `MARGINAL_CORE_ALERT_EMAIL`, deduplicated to one
message per event type per 24 hours using a timestamp map in a single option.

Events in v1:

| Event | Trigger |
|---|---|
| `plugin_deactivated` | Deactivation hook |
| `protected_user_missing` | Daily check; a listed login no longer exists |
| `tamper_blocked` | A denied capability check against a protected user |
| `mainwp_disconnected` | Daily check; public key absent where it was present |
| `mainwp_unsafe_id` | Daily check; unsafe ID on a live connection |

**Accepted limitation:** a site broken badly enough to matter may be broken
badly enough that `wp_mail` fails silently. Events are therefore also appended
to a capped rolling log option surfaced in the widget, so a manual visit still
tells the story. This is the known cost of choosing email over a collector.

State: one daily cron event (`marginal_core_daily`), scheduled on activation
and cleared on deactivation. Two options: the alert timestamp map and the
rolling log.

## Testing

Proportionate to a ~300-line plugin, and concentrated where silent failure is
most expensive.

**Unit tests** (PHPUnit + Brain Monkey, no WordPress bootstrap):

- Config resolution: constant set, constant absent, filter override.
- Module enable/disable parsing, including whitespace and empty strings.
- Unique-ID validation and generation character set.
- The unique-ID repair decision table: connected × safe, connected × unsafe,
  disconnected × safe, disconnected × unsafe, and missing.
- The protection decision function across actor/target/config combinations.
- Alert dedupe windows at the boundary.

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

Installation is MainWP's normal plugin-install flow. **Open item:** if the repo
stays private, release-asset URLs are not publicly fetchable, so install-from-
URL will not work and the zip must be downloaded and uploaded through MainWP
by hand. Decide repo visibility before the first release.

Rollout order, deliberately slow at the start:

1. Staging site — full manual checklist.
2. One pilot client, observed for a week.
3. Fleet, in batches, via MainWP.

## Backlog

Each of these is its own module and its own decision, added only when wanted:

- Client capability lockdown — no plugin/theme installation or deletion.
- `DISALLOW_FILE_MODS` with a corrected IP whitelist, opt-in per site.
- Self-update independent of MainWP (Git Updater or similar).
- A Bastion-hosted event collector, replacing email with a real feed.
- Per-employee accounts provisioned from a central roster.
- Danish translation.
- Additional widget rows: backup status, uptime, SSL expiry.

## To verify during implementation

Assumptions taken from reading the predecessor and from MainWP's general
behaviour, each cheap to confirm against an installed copy and each capable of
changing a detail above:

- MainWP Child option names for the unique ID and the public key.
- Whether `MAINWP_CHILD_VERSION` is defined by current MainWP Child.
- Patchstack detection: which constant, class or option is authoritative.
- That MainWP's one-click login path is unaffected by `user-guard`.
- Cloudflare IP range list and how it is kept current.

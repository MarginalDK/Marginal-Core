# Marginal Core

Marginal's agency baseline for client WordPress sites: hardening, MainWP
connection fixes, and white-labelling, in one plugin installed across the
fleet and updated centrally.

> **Status: v0.9.0, pre-release.** Feature-complete and unit-tested, but not
> yet verified on a live WordPress site. See
> [`docs/superpowers/specs/2026-09-21-marginal-core-design.md`](docs/superpowers/specs/2026-09-21-marginal-core-design.md)
> for the design and [`docs/manual-checklist.md`](docs/manual-checklist.md)
> for the pre-release checks.

## What it does

| Module | Effect |
|---|---|
| `hardening` | Disables the dashboard file editor, XML-RPC, and the `X-Pingback` header |
| `rest` | Blocks unauthenticated user enumeration via the REST API |
| `cron-fixes` | Registers the `minute` schedule MainWP expects; silences MainWP notices on multisite sub-sites |
| `mainwp` | Regenerates the MainWP Child unique security ID when it contains symbols that break the connection — but only on a site that is not yet connected; a connected site is only flagged, never rewritten, since the dashboard would still hold the old value |
| `user-guard` | Prevents client admins from deleting or demoting Marginal's account |
| `widget` | Replaces default dashboard clutter with a Marginal status and support panel: backup freshness, MainWP connection, Patchstack, environment, and several other warning conditions |
| `white-label` | Marginal branding on the login screen and admin footer |

## Design principles

- **Non-intrusive.** It ships to nearly every client site, so anything that
  could surprise a client or break an integration is off by default or absent.
- **Lightweight.** Nothing meaningful runs on ordinary visitor requests.
- **No per-site forks.** One artifact, configured from outside itself.
- **No secrets in this repo.** All per-site configuration lives in that site's
  `wp-config.php`, which is why this repository can be public.

## Configuration

Every option is a constant in the site's `wp-config.php`. All are optional.

```php
define( 'MARGINAL_CORE_DISABLED_MODULES', 'white-label' );
define( 'MARGINAL_CORE_PROTECTED_USERS', [ 'marginal' ] );
define( 'MARGINAL_CORE_PROTECTION', true );
define( 'MARGINAL_CORE_SUPPORT_URL', 'https://marginal.dk' );
define( 'MARGINAL_CORE_BACKUP_MAX_AGE_DAYS', 7 );
```

| Constant | Default | Effect |
|---|---|---|
| `MARGINAL_CORE_DISABLED_MODULES` | (none) | Comma-separated module slugs to skip loading. |
| `MARGINAL_CORE_PROTECTED_USERS` | `[ 'marginal' ]` | Logins the user-guard module protects from deletion or demotion. |
| `MARGINAL_CORE_PROTECTION` | `true` | Turns the user-guard module's protection off entirely when `false`. |
| `MARGINAL_CORE_SUPPORT_URL` | `https://marginal.dk` | Support link used by the widget and the login/footer branding. |
| `MARGINAL_CORE_BACKUP_MAX_AGE_DAYS` | `7` | Age at which the backup row warns. |

Each also resolves through a `marginal_core_config_<key>` filter, so a
site-specific mu-plugin can override it programmatically.

WordPress's own `WP_ENVIRONMENT_TYPE` should also be set on any site that is
not production. Nothing detects this automatically — core defaults to
`production` when the constant is absent — so the dashboard widget reports
undeclared environments to Marginal users as an onboarding check.

```php
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
```

## Updates

The plugin declares this repository as its `Update URI`, so each site checks
GitHub releases directly and reports available updates to the MainWP dashboard
like any other plugin. Releasing is: tag `v*`, let Actions build the zip, then
update the fleet from Bastion.

WordPress auto-updates are deliberately not enabled — the click stays manual so
a bad release reaches the pilot site rather than every client at once.

## Licence

Proprietary. © Marginal.

# Marginal Core

Marginal's agency baseline for client WordPress sites: hardening, MainWP
connection fixes, and white-labelling, in one plugin installed across the
fleet and updated centrally.

> **Status: design stage.** The spec is written and agreed; no plugin code
> exists yet. See
> [`docs/superpowers/specs/2026-09-21-marginal-core-design.md`](docs/superpowers/specs/2026-09-21-marginal-core-design.md).

## What it does

| Module | Effect |
|---|---|
| `hardening` | Disables the dashboard file editor, XML-RPC, and the `X-Pingback` header |
| `rest` | Blocks unauthenticated user enumeration via the REST API |
| `cron-fixes` | Registers the `minute` schedule MainWP expects; silences MainWP notices on multisite sub-sites |
| `mainwp` | Keeps the MainWP Child unique security ID free of symbols that break the connection |
| `user-guard` | Prevents client admins from deleting or demoting Marginal's account |
| `widget` | Replaces default dashboard clutter with a Marginal status and support panel |
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
```

Each also resolves through a `marginal_core_config_<key>` filter, so a
site-specific mu-plugin can override it programmatically.

## Updates

The plugin declares this repository as its `Update URI`, so each site checks
GitHub releases directly and reports available updates to the MainWP dashboard
like any other plugin. Releasing is: tag `v*`, let Actions build the zip, then
update the fleet from Bastion.

WordPress auto-updates are deliberately not enabled — the click stays manual so
a bad release reaches the pilot site rather than every client at once.

## Licence

Proprietary. © Marginal.

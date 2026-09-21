# Verification

This records what has been checked against published source, as distinct
from what the unit suite proves structurally and what still needs a live
WordPress site. It was deferred out of an earlier task and is written here,
at Task 11, before the manual checklist can be run.

## Verified against published source

These were confirmed by reading the relevant plugin/service source directly
(not by exercising a live site), and back the assumptions the `mainwp` and
`widget` modules are built on:

- **MainWP Child's option names.** `mainwp_child_uniqueId` and
  `mainwp_child_pubkey` were confirmed against
  `github.com/mainwp/mainwp-child` as the options actually used to store the
  unique security ID and the connection's public key.

- **MainWP's constant precedence.** `MainWP_Helper::get_site_unique_id()`
  reads the `MAINWP_CHILD_UNIQUEID` constant in preference to the
  `mainwp_child_uniqueId` option, when that constant is defined. This is why
  `marginal_core_mainwp_id_is_repairable()` refuses to "repair" an ID that
  is constant-sourced — writing the option in that case would change nothing
  MainWP actually reads, and reporting success would be false.

- **MainWP's own ID generator.** MainWP generates its unique ID with
  `wp_generate_password( 12, false )` — already alphanumeric (no symbols),
  which is consistent with the plugin's own repair value being alphanumeric
  and with symbol-bearing IDs being something other than MainWP's own
  default (hand-edited, migrated, or otherwise altered).

- **UpdraftPlus's backup success field.** UpdraftPlus writes the `success`
  field of its last-backup record as `(error_count() == 0) ? 1 : 0`. That
  write passes through an `updraftplus_save_last_backup` filter, so the
  shape actually stored is not guaranteed — a third-party filter could
  change it. This is why `marginal_core_backup_status()` treats anything
  other than `1`, `'1'`, or `true` as failure, and an absent or `null`
  `success` key as `unknown` rather than assuming success.

- **The GitHub releases API shape.** `GET /repos/:owner/:repo/releases/latest`
  (and the releases list) returns `tag_name`, `html_url`, and
  `assets[].name` / `assets[].browser_download_url`. A repository with no
  published releases returns HTTP 404 rather than an empty array. The
  self-update code is written to treat 404, and a response with no matching
  `.zip` asset, as "no update available" rather than as an error.

## Verified by the unit suite (structural)

The 137-test suite covers every decision function directly: the unique-ID
safety regex and repair/warn/none decision table, the user-guard capability
and deletion-backstop matrix (including the "0" login and case-folding
edge cases), the backup-status state machine (missing/failed/stale/unknown/
invalid/ok), the environment normalisation and declared/undeclared
distinction, and the warning list and visibility filtering. See the test
suite itself for the exhaustive list — it is not duplicated here.

## Not verified — needs a live WordPress site

None of the following has been exercised against a running WordPress
install, MainWP dashboard, or GitHub release. They are the subject of
`docs/manual-checklist.md` and the Handover steps in the Task 11 brief:

- The capability guard's actual behaviour in wp-admin (Users list, bulk
  delete, role change) and over the REST API (`DELETE
  /wp-json/wp/v2/users/<id>?reassign=1`), and the WP-CLI escape hatch
  (`wp user delete`).
- The dashboard widget's rendering for both a client administrator and a
  protected user, and the live triggering of each warning condition
  (`blog_public`, `WP_ENVIRONMENT_TYPE` present/absent).
- The MainWP module's actual effect on a real `mainwp_child_uniqueId` value,
  on both a disconnected and a connected site, and whether the connection
  survives.
- White-label rendering on the real admin footer and wp-login.php screen.
- The self-update channel end to end: a GitHub release with a `.zip` asset,
  Dashboard → Updates detecting it, installation succeeding, and the MainWP
  dashboard listing the update. This also validates the `Update URI` header,
  `MARGINAL_CORE_BASENAME`, and the `marginal_core_latest_release` transient
  behaving as designed, none of which can be proven without a real install.
- **Patchstack detection.** The `patchstack_active` fact checks for the
  `PATCHSTACK_VERSION` constant, a `Patchstack` class, and a
  `patchstack_options` option, but none of these three signals has been
  confirmed against a real Patchstack installation — they are inferred
  naming conventions, not confirmed from Patchstack's published source. This
  is the one detection in the widget that carries no source-level
  confirmation at all, and it should be checked explicitly the first time a
  Patchstack-protected site runs this plugin.

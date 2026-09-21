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
  `marginal_core_mainwp_unique_id()` reads the constant first — reporting the
  option's value while MainWP is actually using the constant would misreport
  the site's real state.

- **MainWP's own ID generator.** MainWP generates its unique ID with
  `wp_generate_password( 12, false )` — already alphanumeric (no symbols).
  MainWP never produces a symbol-bearing ID itself, so a symbol-bearing ID
  can only come from a human typing one, a host-set `MAINWP_CHILD_UNIQUEID`
  constant, or an old MainWP version.

- **The repair this plugin used to perform was removed, and should not come
  back.** The `mainwp` module used to rewrite an "unsafe" unique ID on any
  site not yet connected. That was a live bug: MainWP enforces the unique-ID
  requirement only when the stored ID is **non-empty**
  (`class-mainwp-connect.php:118` in MainWP Child) — an empty ID is MainWP's
  own default and means the feature is off, not broken. The old repair
  treated an empty ID as unsafe (the safety regex requires 8-64 characters)
  and wrote a real one into that slot on every fresh, unconnected site,
  which *enables* a requirement the Marginal dashboard does not know about —
  the site's first connection attempt then fails with `REG_ERROR3`. Combined
  with the point above (MainWP's own generator is already alphanumeric, so a
  symbol-bearing ID is never something MainWP produced on its own), the
  write was both harmful and pointless, and was deleted rather than fixed.
  The module is now read-only: it detects and reports an unsafe, non-empty
  ID, and never writes `mainwp_child_uniqueId`.

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

The unit suite covers every decision function directly: the unique-ID
safety regex, constant-vs-option precedence, and connection-state read; the
user-guard capability and deletion-backstop matrix (including the "0" login
and case-folding edge cases); the backup-status state machine
(missing/failed/stale/unknown/invalid/ok); the environment normalisation
and declared/undeclared distinction; and the warning list and visibility
filtering, including the unsafe-but-empty MainWP ID staying silent. See the
test suite itself for the exhaustive list — it is not duplicated here.

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
- The MainWP module's detection of a real `mainwp_child_uniqueId` value on
  both a disconnected and a connected site — confirming the warning appears
  (and only appears) when it should. The module writes nothing, so there is
  no connection-survival behaviour left to verify here.
- White-label rendering on the real admin footer and wp-login.php screen.
- The self-update channel end to end: a GitHub release with a `.zip` asset,
  Dashboard → Updates detecting it, installation succeeding, and the MainWP
  dashboard listing the update. This also validates the `Update URI` header,
  `MARGINAL_CORE_BASENAME`, and the `marginal_core_latest_release` transient
  behaving as designed, none of which can be proven without a real install.
- **Patchstack detection — verified against published source (Patchstack
  Security 2.3.7).** The `patchstack` fact (`marginal_core_patchstack_state()`
  in `inc/status.php`, consumed by `modules/widget.php`) checks presence via
  the `P_Core` class (`includes/core.php:12`) and then mirrors Patchstack's
  own firewall gate — `get_option( 'patchstack_license_activated', 0 ) == 1
  && get_option( 'patchstack_basic_firewall', 0 ) == 1 && get_option(
  'patchstack_license_free', 0 ) == 0` — exactly as Patchstack itself applies
  it at `patchstack.php:343` and again at `includes/mu-plugin.php:14`. The
  earlier version of this check tested `defined( 'PATCHSTACK_VERSION' )`, a
  `Patchstack` class, and a `patchstack_options` option; none of the three
  exist in Patchstack 2.3.7, so the badge read "Standard" (unprotected) on
  every site regardless of actual protection. That version's source is on
  file at `/Users/bef/Downloads/patchstack`. The badge now reports four
  states — `absent`, `inactive`, `monitored` (activated but no firewall,
  which is what a free licence gets), and `protected` — instead of a single
  boolean, because a free licence genuinely occupies a third state between
  "not installed" and "protected". Still confirm this live on a real
  Patchstack-protected site per the manual checklist below; source-reading
  does not substitute for seeing the option values as WordPress actually
  stores them.

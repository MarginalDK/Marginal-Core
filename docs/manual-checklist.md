# Manual verification checklist

Run on a staging child site before every release. The unit suite covers
every decision; this covers everything that only exists once WordPress is
actually running.

## Capability guard

- [ ] Sign in as a client administrator (not a protected login).
- [ ] Users list: the Marginal row shows "Managed by Marginal" and has no
      Delete link.
- [ ] Attempt bulk-delete including the Marginal user, with at least one
      other (unprotected) user selected **before** it in the list —
      `marginal_core_guard_block_delete()` calls `wp_die()` the moment it
      reaches the protected account, mid-loop. Any unprotected user ordered
      before it in the batch is **already deleted** by that point; the
      explanatory message stops the loop, it does not undo what already ran.
      Confirm that behaviour, and restore whichever unprotected user(s) got
      deleted in the process.
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
- [ ] On a site with Patchstack actually installed and its firewall running
      (licence activated, basic firewall on, not the free tier), the
      Patchstack row reads "Firewall active" in green. Confirm on a site
      that is only monitoring (free licence, or firewall toggled off) that
      it instead reads "Monitoring only" in amber, not "Firewall active".

## MainWP

This module is read-only: it never writes `mainwp_child_uniqueId` or any
other MainWP option. It only detects and reports.

- [ ] On a site with a symbol-bearing (non-empty, unsafe) unique ID, load any
      admin page — the ID is **unchanged**, and the warning row appears for a
      protected/Marginal viewer, whether the site is connected or not.
- [ ] On that same site, sign in as a client administrator — no MainWP
      security ID row and no warning appear anywhere in the panel.
- [ ] On a disconnected site with an **empty** unique ID (MainWP's own
      default, meaning the unique-ID requirement is off), load any admin
      page — no warning appears. An empty ID is not a fault; see
      `modules/mainwp.php`'s docblock and `docs/verification.md`.

## XML-RPC

- [ ] With hardening active (`xmlrpc_enabled` filtered to `false`),
      `/xmlrpc.php` still answers — it is not blocked at the endpoint level.
      Confirm `pingback.ping` still responds (e.g. via `xmlrpc.php` with a
      `pingback.ping` payload), and that an authenticated method such as
      `wp.getUsersBlogs` is refused. Do not assume XML-RPC is "disabled"
      wholesale when verifying this.
- [ ] Confirm the MainWP connection (handshake via the unique ID and public
      key) still works with hardening active, so a failure there is never
      mistaken for an XML-RPC problem or vice versa.

## Branding

- [ ] Admin footer reads "Maintained and managed by Marginal".
- [ ] Login screen logo links to the support URL and reads "Managed by
      Marginal".

## Update channel

- [ ] Dashboard → Updates → Check again shows the new version.
- [ ] Updating installs it, and the site still loads afterwards.
- [ ] The MainWP dashboard lists the pending update for this site.

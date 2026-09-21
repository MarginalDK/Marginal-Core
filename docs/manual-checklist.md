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
- [ ] On a site with Patchstack actually installed and its firewall running
      (licence activated, basic firewall on, not the free tier), the
      Patchstack row reads "Firewall active" in green. Confirm on a site
      that is only monitoring (free licence, or firewall toggled off) that
      it instead reads "Monitoring only" in amber, not "Firewall active".

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

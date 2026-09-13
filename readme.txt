=== Hakeemify Debloater ===
Contributors: hakeemify
Tags: bloat, debloat, performance, cleanup, optimization
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan, fix and undo site bloat: audits your site against the facts, applies only what you approve, with a recovery point and automatic rollback.

== Description ==

Most optimisation plugins ask you to trust a switch. Debloater asks you to read
a finding.

It scans your site, records what it actually found, and shows you each change it
could make: what the change does, what it might break, how confident it is, and
how to get back. Nothing is applied until you say so, nothing is applied without
a recovery point, and anything that fails verification is rolled back
automatically before you ever see a broken page.

= What it does =

**Scans, and reports facts.** Which core features are loading, how many
revisions and expired transients you have, what your autoloaded option payload
weighs, what your plugins overlap on. Facts, with numbers — not a score and not
a grade.

**Explains every finding.** Each one names the evidence it came from, so you can
disagree with it. A finding you disagree with is a finding you can leave alone.

**Plans before it acts.** You get a preview: every change, its risk level, what
it touches, and what the recovery point will contain. The plan is deterministic
— the same site and the same profile always produce the same plan.

**Takes a recovery point first.** Before anything changes, the current
configuration is captured. Before anything is deleted, the rows themselves are
captured. Destructive operations do not proceed unless that capture completed.

**Verifies, then rolls back if it has to.** After applying, Debloater requests
your own pages and your own REST API. If they stopped working, it puts
everything back and tells you what happened.

**Costs nothing when it is doing nothing.** With no changes selected there are
no hooks registered and no queries added to a front-end request. That is a
measured guarantee, not a claim.

= Three profiles =

* **Safe** — only changes with a small blast radius and a clean way back. This
  is what the "Fix Safe Issues" button applies.
* **Performance** — Safe, plus medium-risk changes that remove work from the
  front end.
* **Maximum** — everything the engine will consider, including high-risk
  changes. Still excludes destructive operations.

Deleting rows is never part of a profile. It is always a separate, explicit
decision.

= What it can change =

Twenty-seven changes at present, across WordPress core (emoji scripts, embeds,
the generator tag, RSD and shortlink headers, jQuery Migrate, heartbeat
interval, revision limits, self-pingbacks, Dashicons for guests), the admin
(dashboard widgets, the welcome panel, the news widget, update nags for
non-administrators, promotional notices), the database (expired transients,
auto-drafts, orphaned meta, old revisions, spam comments, trash, autoloaded
options), WooCommerce (cart fragments and block styles loaded only where they
are needed, admin analytics, marketplace suggestions) and Elementor (Google
Fonts).

= What it will not do =

* No admin notices, no dashboard widget, no upsell in your way.
* No telemetry, no analytics, no AI.
* No outbound network requests, except to your own site during verification.
  Two optional features can reach further, both off by default and both listed
  under "External services" below.
* No claims about speed it did not measure.
* No safety feature behind a paywall.

= External services =

Debloater works completely offline. One optional feature reaches outside your
site, it is not enabled by default, and it sends nothing about your site.

**Plugin release dates, from wordpress.org.** When you tick "check plugin
update dates" before a scan, Debloater asks
`https://api.wordpress.org/plugins/info/1.2/` for the public release dates of
the plugins you have installed, so it can tell you which look abandoned. It
sends the plugin slugs it is asking about and nothing else — no site address,
no user, no site data. The setting is per scan and is not remembered, so it
never happens without you asking that time. This is WordPress's own API:
see the [wordpress.org privacy notice](https://wordpress.org/about/privacy/).

The rules Debloater reasons with — which changes exist, what they touch, how
risky they are — ship inside the plugin and are never fetched. A newer set
arrives when you update the plugin, like any other part of it.

Nothing else leaves your server. There is no telemetry, no analytics, no
licensing call and no usage reporting in this plugin.

= WP-CLI =

    wp debloater scan
    wp debloater findings
    wp debloater preview --profile=safe
    wp debloater apply --profile=safe --yes
    wp debloater rollback --yes
    wp debloater status

Exit codes: 0 applied and verified, 1 error, 2 rolled back, 3 applied with
warnings.

== Installation ==

1. Install and activate.
2. Open **Debloater** in the admin menu.
3. Run a scan.
4. Read the findings. Apply what you agree with.

== Frequently Asked Questions ==

= Will this speed up my site? =

It will remove work your site is doing. Whether that is measurable depends
entirely on what your site was doing to begin with, so Debloater reports the
before-and-after numbers it actually recorded and leaves the conclusion to you.
It will never tell you a change made your site "faster" without a measurement
behind it.

= What happens if a change breaks something? =

After applying, Debloater requests your front page, a post, and your REST API.
If any of those stopped working, it restores the previous state automatically
and reports what failed. You do not have to notice the problem yourself.

= Can I undo a change later? =

Yes. Every apply creates a recovery point, and you can roll back to any of them
from the dashboard or with `wp debloater rollback`.

= Does it delete anything? =

Only if you explicitly ask it to, one operation at a time, after seeing exactly
how many rows are affected. Deletions are never part of "Fix Safe Issues" and
never part of a profile. Before rows are deleted they are backed up, and if that
backup does not complete the deletion does not happen.

= What happens to my data when I uninstall? =

Everything Debloater loads on your site is switched off immediately, and
anything an older version left on disk is removed. Your recovery points and
settings are kept, because the moment somebody deletes a plugin is the moment
they are most likely to need them. If you would rather everything went, turn on
"remove all data on uninstall" in the settings first.

= Where does Debloater write files? =

Almost nowhere, and never any code.

The changes you apply are stored in your database, not compiled into a PHP file
somewhere under wp-content. Nothing is added to must-use plugins.

Two things do get written, both of them data:

* A recovery point that is too large for the database spills into
  `wp-content/uploads/debloater/backups/`, so that a change touching thousands
  of rows can still be undone. The folder is closed to the web.
* `wp debloater export` and `wp debloater profile export` write into
  `wp-content/uploads/debloater/`, also closed to the web, with a random suffix
  on the file name.

Exports go to that folder and nowhere else. `--file=-` prints to standard
output so you can pipe the JSON somewhere yourself, and that is the only value
`--file` takes — a path is refused, and the command tells you where the file
would have gone. Nothing reachable from a browser accepts a path either.

= Does it phone home? =

No. There is no telemetry of any kind. The only request it makes on its own is
to your own site, to check that your site still works after a change. Two other
features can reach outside, both off until you ask for them and neither sending
anything about your site: plugin release dates from wordpress.org, and registry
updates from GitHub. Both are described under "External services" above.

= Is it compatible with my caching plugin? =

Debloater does not cache anything and does not compete with a caching plugin.
It is tested against WooCommerce, Elementor, Contact Form 7, Rank Math,
LiteSpeed Cache and WP Super Cache.

== Screenshots ==

1. The dashboard: findings, each with its evidence and its risk.
2. A preview: every change in the plan, what it touches, and the recovery point
   that will be taken first.
3. A recovery point, and the one-click way back.

== Changelog ==

= 0.3.0 =
* Renamed to Hakeemify Debloater. The plugin folder and text domain change
  with it; your settings, recovery points and `wp debloater` commands do not.
* Nothing is compiled to disk any more. Your selection used to be written to a
  PHP file under wp-content and loaded by a must-use plugin; it is read from
  the database now and the handlers are loaded directly. Nothing about what
  gets applied to your site changes, and an upgrade removes the old files.
* Fixed: a check that loads your dashboard could follow a redirect off your
  site while carrying your sign-in cookie. Signed-in checks are no longer
  redirected at all, and a redirect to another host is reported as a failure.
* Fixed: on a site where WordPress lives in a subdirectory, assets could not
  be traced back to the plugin or theme serving them.
* Fixed: plugin and theme assets loaded with a root-relative URL were reported
  as belonging to WordPress itself.
* `wp debloater export` and `wp debloater profile export` now write into
  `wp-content/uploads/debloater/` by default. `--file` still takes a path, and
  `--file=-` prints.

= 0.2.0 =
* Profiles: save what a site has under a name, export it, and import it
  elsewhere. Importing shows a preview and applies nothing on its own.
* `wp debloater profile` on the command line.
* Registry updates are verified against a key compiled into the plugin, and
  refused when the signature does not check out.
* Fixed: changes could end "verified, with warnings" on sites where nothing was
  wrong, because the verification check was sending the wrong sign-in cookie.
* Fixed: the release archive now builds identically twice from the same code.

= 0.1.1 =
Initial wordpress.org release.

== Upgrade Notice ==

= 0.1.1 =
Initial wordpress.org release.

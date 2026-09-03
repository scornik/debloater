=== Debloater – Scan, Fix & Undo WordPress Bloat ===
Contributors: hakeemify
Tags: performance, optimization, database, cleanup, audit
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audits your site against the facts, then applies only what you approve — with a recovery point first and automatic rollback if anything breaks.

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

**Costs nothing when it is doing nothing.** With no changes selected there is no
generated file, no hooks registered, and no queries added to a front-end
request. That is a measured guarantee, not a claim.

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
* No outbound network requests, except to your own site during verification. The
  one exception is optional and off by default: looking up plugin release dates
  at wordpress.org, which you turn on per scan.
* No claims about speed it did not measure.
* No safety feature behind a paywall.

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

Debloater installs one small must-use plugin
(`wp-content/mu-plugins/debloater-loader.php`) so that your selected changes
can take effect before other plugins load. It is removed when you uninstall.

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

The generated file and the must-use loader are always removed. Your recovery
points and settings are kept, because the moment somebody deletes a plugin is
the moment they are most likely to need them. If you would rather everything
went, turn on "remove all data on uninstall" in the settings first.

= Does it phone home? =

No. There is no telemetry of any kind. The only outbound request it makes on its
own is to your own site, to check that your site still works after a change. The
only other network feature — looking up plugin release dates at wordpress.org —
is off by default and asks again every scan rather than remembering.

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

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.0 =
First release.

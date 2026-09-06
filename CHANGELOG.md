# Changelog

All notable changes to Debloater are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Profiles.** Save the set of changes a site has under a name, export it as a
  file, and import that file on another site. Debloater's own three — Safe,
  Performance and Maximum — are listed alongside anything you save, so the panel
  has something in it on a site that has saved nothing.

  On the command line: `wp debloater profile list|save|export|import|apply`.

  **Importing never applies anything.** It reads the file, tells you which
  changes this site does not have, and opens the ordinary preview with the rest
  ticked. Everything after that is the usual path — the plan, the confirmation,
  the recovery point, the checks afterwards and the way back. A file somebody
  emailed you does not get a shortcut past the screen that shows what it
  touches.

- **Pro: a profiles panel** in place of the "Saved profile" dropdown, with
  Apply, Export, Duplicate, Rename and Delete. Apply opens Debloater's preview
  with the profile's changes ticked; it does not apply them. The profiles
  Debloater ships with can be copied and exported but not renamed or deleted.

## [0.1.1] — 2026-09-04

### Fixed

- **Changes could never finish as fully verified.** The check that loads the
  dashboard signed in as you was sending the wrong sign-in cookie, so WordPress
  answered it with the login form and the check honestly reported that it could
  not tell. Every change therefore ended "verified, with warnings" on sites
  where nothing was wrong. It now sends the cookie the dashboard actually reads,
  and says precisely which of the two things went wrong when one does.

- **The packaging job in CI had been failing for five commits.** It opened the
  release archive by a hard-coded filename that the version bump had changed,
  and nothing checked whether the file opened. It now reads the version and
  checks both archives on both operating systems.

- **The plugin name is now the same in both places it is written.** The readme
  title carried the full title while the plugin header carried the short name,
  which wordpress.org's own checker reports as a mismatch. Both say
  `Debloater`; the tagline is now the short description, which is the line
  wordpress.org displays under a plugin's name.

- **The before/after report showed nothing.** It read the stored measurements
  in a shape nothing writes, so every report said "nothing was measured" even
  when plenty had been. It now reads what the apply actually records.
- **The report opened inside the admin page** instead of as a printable
  document of its own.
- **Changes that were aborted or rolled back were offered a report.** They
  changed nothing, so there was nothing to compare.
- **A site could get permanently stuck on "Another change is already in
  progress".** The apply lock could be stored in a form that never expired, and
  crash recovery steps aside while the lock is held, so the one thing that would
  have cleared it was the one thing it prevented. The lock now carries its own
  expiry, and a lock in the old form is treated as free, so an affected site
  recovers by itself.

### Added

- **A screen for Pro.** Debloater then Pro: scan on a schedule, a saved profile,
  the name that goes on reports, the full drift report, and a printable
  before/after page per change. Four of Pro's five features previously had no
  interface at all.


- **The release zip could not be installed on Linux.** Every entry in it used a
  backslash path separator, written by a Windows-only zip tool, so WordPress
  extracted one flat file instead of a plugin directory and activation failed
  with "Plugin file does not exist."

  The zip is now written in-process by the same code on every platform, with
  forward slashes and explicit directory entries, and CI builds it on both Linux
  and Windows and checks the two agree entry for entry.

### Changed

- The plugin header is `Plugin Name: Debloater`. wordpress.org derives the
  plugin's permanent slug from that header, and the full title belongs in
  readme.txt where it is a display string rather than an identifier.
- Debloater Pro declares `Requires Plugins: debloater`, so WordPress will not
  let it activate without the free plugin.
- readme tags are now `bloat, debloat, performance, cleanup, optimization`.


- **A REST request could take itself down.** `src/Rest/` had no exception
  handling at all, and WordPress does not catch exceptions thrown by a route
  callback — so anything the engine raised became a PHP fatal: an empty body to
  a dashboard waiting for JSON, and a stack trace on any site running with
  `display_errors` on. Every route is now behind one boundary that turns a
  failure into a proper JSON error, escaped, with the route that failed named.

- **Crash recovery could lock you out of the admin.** Recovery from an
  interrupted run happens on every admin page load, and runs precisely when the
  previous request did not finish — the moment the stored state is least likely
  to be well-formed. A failure there would have made every admin page fatal,
  over a run that was already broken before you arrived. It now steps aside: the
  run stays visibly interrupted, and the next page load tries again.

- **A syntax check that was absent where it mattered most.** Generated code was
  checked twice, once in-process and once by running `php -l` in a subprocess.
  The subprocess added nothing the in-process parser did not already catch, and
  it needed `proc_open()`, which a lot of shared hosting disables — so on the
  hosts where a damaged runtime is hardest to recover from, the second check had
  quietly been doing nothing at all. Removed.

- Table names in every query now go through `$wpdb->prepare()` with the `%i`
  identifier placeholder rather than being written into the SQL directly.

- `Tested up to` said 6.8 while the suite runs against 7.1.

### Changed

- **Renamed to Debloater.** The plugin was called WP Debloat. wordpress.org
  treats "wp" as a restricted term and refuses a plugin name or slug that
  carries it, so that name was never submittable
  (`docs/DECISIONS.md` D-0047).

  The new name is **Debloater – Scan, Fix & Undo Site Bloat**, and the
  slug is `debloater`. The subtitle says "Site Bloat" rather than "WordPress
  Bloat": wordpress.org refuses the term "wordpress" anywhere in a plugin name,
  tagline included (D-0052).

  This is a full identifier rename rather than a display-only one: the
  namespace, constants, hook and option prefixes, database tables, capability,
  REST namespace, WP-CLI command, generated paths, kill-switch query variable,
  verification header and must-use loader filename all changed. There are zero
  production installs, so nothing needed migrating and no migration was
  written; `docs/RENAME-MAP.md` records every token, and the deliberate
  non-renames.

  Practical consequences for anyone running a development copy:

  - `wp debloat` is now `wp debloater`. There is no alias.
  - The generated runtime moved from `wp-content/wpdebloat/` to
    `wp-content/debloater/`, and the loader from `wp-debloat-loader.php` to
    `debloater-loader.php`.
  - Tables are `{prefix}debloater_*`, the option is `debloater_state`, and the
    capability is `debloater_manage`.
  - Tweak ids (`core.*`, `db.*`, `admin.*`, `woo.*`, `elementor.*`) are
    unchanged. They identify a change, not a brand.


- The release zip is built from an explicit list of what ships, rather than a
  list of what does not. Repository dotfiles no longer ride along inside it, and
  `composer.json` now travels beside the autoloader it describes.

### Added

- **Apply a single finding.** A finding the engine recommends now has an
  "Apply this change…" button. It opens the same review dialog as "Fix safe
  issues" — same preview, same recovery point, same confirmation — with just
  that one change in it. Findings marked "no action recommended" have no button.


- **`docs/CLOUD-DESIGN.md`** — the design for an agency multi-site dashboard.
  Nothing is built: no infrastructure, no accounts, no code, and
  `cloud.hakeemify.com` does not resolve.

  The decision it is built around is that reporting flows one way. Sites push
  reports; nothing sends commands back. A compromised dashboard could show wrong
  numbers and could not touch a site.


- **Debloater Pro**, a separate plugin for people who manage several sites.
  Scans on a schedule, drift detection between them, a printable before/after
  report an agency can put its own name on, applying a saved profile in one
  step, and registry updates sooner.

  It adds nothing to what Debloater does to a site. Recovery points,
  verification, automatic rollback, risk rules and the refusal to delete without
  a backup are all in the free plugin and stay there — a test asserts that
  activating Pro leaves the generated runtime byte-identical.

  Pro reaches the free plugin only through documented hooks
  (`docs/HOOKS.md`), and needs neither a licensing platform nor a cloud service
  to be installed, built or tested.

- **Extension points**, documented in `docs/HOOKS.md` and each covered by a
  test: `debloater_loaded`, `debloater_scan_complete`,
  `debloater_apply_complete`, `debloater_dashboard_panels` and
  `debloater_registry_origin`.

  The dashboard filter accepts text rather than markup, and strips tags before
  anything reaches the screen. The registry filter can move the update channel
  and cannot relax a single check on it.


- **Phase 0 — architecture and contracts.**
  - Contract value objects for facts, findings, tweaks, plans, probes,
    verification, snapshots, apply results and site context. Each validates in
    its constructor, rejects unknown keys, and round-trips through
    `toArray()` / `fromArray()` without loss.
  - Backed enums for severity, risk, category, decision, probe status, tweak
    kind, snapshot level and status, run type and journal action, so an invalid
    value cannot be constructed at all.
  - `RunState` and `TweakState` enums with their transition tables, driven by
    `RunStateMachine` and `TweakStateMachine`; illegal transitions throw
    `IllegalTransition`.
  - `docs/STATE-MACHINE.md`, generated from those enums by a test that fails if
    the committed document is stale.
  - JSON Schemas for facts, findings, tweaks, compatibility rules, profiles and
    detectors, matching the specification field for field.
  - `Registry\SchemaValidator`: a hand-written JSON Schema draft-07 subset
    validator, so the plugin keeps zero runtime dependencies. An unsupported
    keyword throws rather than being ignored.
  - `Brand`, the single source of product naming.
  - Project tooling: PHPUnit 10, PHPCS (WordPress-Extra + VIP-Go), PHPStan
    level 6.

- **Phase 1 — minimal runtime engine.**
  - Registry loading with schema validation, id indexing and a content-derived
    registry hash. A conflict or requirement naming a tweak that does not exist
    stops the load rather than silently becoming a no-op.
  - The first five tweaks and their handlers: remove the generator tag, the RSD
    link and the shortlink; stop self-pingbacks; stop loading the emoji script.
  - `Apply\Compiler`, which turns a selection into deterministic,
    timestamp-free PHP. Handler paths are resolved and checked into the plugin's
    own directory; parameters are emitted through `var_export` after schema
    validation, so nothing user-supplied reaches generated code as text.
  - `Apply\RuntimeWriter`: syntax check, atomic temp-file-and-rename, and a
    `runtime.lock` recording the runtime, selection and registry hashes. It
    refuses to write anywhere but `wp-content/debloater`.
  - The mu-plugin loader, with a documented `plugins_loaded` fallback for hosts
    where `mu-plugins` is not writable, and a runtime guard providing the
    `DEBLOATER_DISABLE` kill switch and an authenticated `?debloater=off` bypass.
  - `Recommend\DependencyResolver` v1: conflicts resolve in both directions,
    and anything with an unresolved requirement is excluded rather than assumed
    satisfied.
  - `Storage\State`: all plugin state in one option, never autoloaded.
  - `GET debloater/v1/status`, reporting what is actually on disk — including
    when the runtime no longer matches the hash recorded for it.
  - Integration harness on `@wordpress/env`, with the zero-overhead guarantee
    measured rather than assumed: an empty selection registers no hooks and adds
    no database queries to a front-end request.

- **Phase 2 — scanner.**
  - Eleven fact collectors covering the environment, WordPress configuration and
    core features, users, plugins, theme, database, autoloaded options, cron and
    the admin screens. Each owns a namespace and cannot write outside it.
  - Ten detectors for WooCommerce, Elementor and Elementor Pro, Contact Form 7,
    Rank Math, Yoast, LiteSpeed Cache, WP Rocket, WP Super Cache and Wordfence.
    Each records both outcomes, so "not installed" is distinguishable from "not
    looked for", and each recognises more than one signal so a rename does not
    make it blind.
  - Every database query is bounded and indexed, and the scanner's query count
    is declared and asserted rather than hoped for.
  - The scan budget is soft: an over-budget scanner is recorded and its facts
    kept, because interrupting PHP mid-scan would leave a fact set that looks
    complete and is not. A scanner that throws is named in the diagnostics and
    the rest of the scan continues.
  - Runs are recorded in `debloater_runs` with the facts in the payload and the
    registry hash they were produced against.
  - Two facts §5 lists are deliberately absent rather than guessed:
    `wp.dashicons_frontend` outside a front-end request, and the `admin.*`
    counts outside an admin request. An absent fact reads as "not observed",
    which is not the same as zero.

- **Phase 3 — analyzer, findings and score.**
  - Fourteen rules turning facts into findings, each carrying evidence that
    cites the fact it came from. Evidence that names a fact the scan did not
    observe is refused rather than rendered.
  - Severity, risk and confidence stay three separate figures. A finding can be
    low-severity and medium-risk, or high-confidence and refused.
  - Confidence is the rule's base multiplied by penalties for what stands
    between us and a clear view of the site: an unrecognised host, a page
    cache, detected dependents, custom must-use plugins. The numbers are in
    `docs/SCORING.md`.
  - "Don't touch" as a first-class outcome, with the reason always given. A
    change is refused either because something present declares a dependency
    the change would remove, or because of how the site is used — Heartbeat is
    not slowed on a store where several people are editing.
  - The Debloat Score: five sub-scores, penalties by severity, an unweighted
    mean, and no Performance component. A refused finding costs nothing, and
    findings in a category this version does not score are reported rather than
    hidden.
  - Six more tweaks completing the MVP set, and six compatibility rules
    recording what WooCommerce, Elementor, Contact Form 7, LiteSpeed and
    Wordfence actually depend on.
  - `POST debloater/v1/scan` and `GET debloater/v1/findings`. Before any scan,
    the findings endpoint says the site has not been scanned rather than
    returning an empty list that reads like good news.

- **Phase 4 — recommendation engine.**
  - Intent: what the site is for and how much change its owner wants, kept
    separate from what the scanners detected. A WooCommerce install is a fact;
    "this is a store and downtime costs money" is a statement only the owner can
    make.
  - Compatibility resolved against the site rather than in the abstract: a
    dependency declared by a plugin nobody has installed is not a reason to
    refuse anything.
  - Risk raised — never lowered — where this site makes a change more likely to
    go wrong, by one level at most however many reasons apply, and always with
    the reason given.
  - Requirements expressed as conditions on the facts, where a fact the scan
    never observed counts as unresolved rather than satisfied.
  - Preview planning, with the §7.4 invariants enforced in the one place a plan
    can be built, and every excluded change carrying an explanation. A plan that
    silently contains less than expected is worse than one that says why.
  - Three profiles that widen in order. None of them admits a destructive
    change, whatever its own configuration says: deleting rows is never
    something a preset decides on your behalf.
  - `GET debloater/v1/preview`, which computes a plan from a recorded scan and
    changes nothing at all.

- **Phase 5 — snapshots, apply and rollback.**
  - Recovery points before anything changes: the previous configuration always,
    and for a data operation the exact rows it is about to delete, collected
    from the operation itself and read back and checksummed before it is allowed
    to run. A recovery point taken afterwards protects nothing.
  - Rollback that is exact rather than approximate. The runtime comes back
    byte-for-byte, an option that did not exist before is removed again rather
    than left holding a restored value, and a restored transient keeps its
    original expiry instead of being resurrected as live.
  - Three refusals before a restore writes anything: a recovery point from
    another site, one that never completed, and one whose contents no longer
    match its checksum.
  - Applying is ordered so that the reversible half goes first: configuration,
    which is a file swap, before data, which is rows put back one at a time.
  - Large recovery points spill to a gzipped file under
    `wp-content/debloater/backups`, written and read as a stream so neither
    taking nor restoring one needs the whole thing in memory.
  - One change at a time per site, enforced by a lock that a second request
    cannot steal, and a run whose process died is rolled back on the next admin
    page load — without disturbing an apply that is merely still running.
  - Every tweak transition written to the journal is an edge from the documented
    lifecycle, found by asking the state machine rather than assumed by the
    caller.
  - The first data operation: deleting transients that have already expired.
    Removed through WordPress's own API so a persistent object cache sees it,
    and chosen first because it is the operation where proving the recovery path
    costs least if it is wrong.

- **Phase 6 — verification.**
  - After every change the site is asked, over real HTTP, whether it still
    works: the home page as a guest, the newest post, the dashboard as the
    person who made the change, the REST API, the login page, and whether the
    generated runtime is the one actually loaded.
  - A failure rolls the change back without being asked, and says which check
    failed and what it saw.
  - Three outcomes rather than two. "We could not check" is not "it passed": a
    site that cannot make requests to itself keeps its change and is told
    plainly that nothing was verified, and a check that does not apply is
    listed as not tested rather than quietly counted as a pass.
  - The dashboard probe is the one that protects the way back in. A change that
    locks its owner out of their own site is undone automatically.
  - Requests carry a header identifying them, which nothing in the plugin ever
    reads — a site that passed only because it knew it was being checked would
    not have been checked at all.

- **Phase 7 — the command line.**
  - The whole loop from a terminal: `wp debloater scan`, `findings`, `preview`,
    `apply`, `verify`, `rollback`, `snapshots`, `status`, `export`, `import`.
  - Exit codes a deployment script can act on: `0` worked, `1` refused, `2` the
    change was applied and then rolled back because the site failed its checks,
    `3` the change is in place but something could not be checked.
  - Nothing that changes the site happens without `--yes`.
  - Configuration as code. `export` writes what this site has chosen — the
    changes, their parameters and the stated intent — and `import` validates it
    before reading a value out of it. Findings and scores are deliberately not
    included: they describe one site at one moment, and importing another site's
    conclusions would be acting on facts that are not true here.
  - A change named in an imported file that this version does not have is
    reported and skipped; the rest of the file still applies. Importing is not a
    way around the rules — a change this site would refuse is still refused.
  - The command line contains no product logic. It asks the same engine the
    dashboard does, so the two cannot come to different conclusions.

- **Phase 8 — the dashboard.**
  - One admin screen: the score with the category breakdown behind it, the
    findings with filters, a finding in full, and the history of what has been
    changed with a way back from each of it.
  - A finding shows all ten fields every time, including the ones with nothing
    in them. "Nothing on this site was detected as depending on this" and a
    missing section look identical to a reader, and only one of them is a
    statement.
  - Nothing is applied without a confirmation that names what is being applied.
    The token the preview issues is derived from that exact plan, so a site that
    changed while the preview was on screen refuses the apply and says why,
    instead of quietly applying something else.
  - One bundle, on one screen, 6.7 KB of JavaScript. No admin notices, no
    dashboard widget, nothing added to the front end. A plugin that puts its own
    weight on every screen of the admin has lost the argument it is making.
  - Colour is never the only signal: every risk, severity and decision says what
    it is in words.

- **Phase 9 — preview, apply, and what actually changed.**
  - The whole motion in one place: see what would change, confirm it, watch it
    happen, and read what it did.
  - Before and after are measured, not estimated. Requests, scripts,
    stylesheets, bytes in the head, external hosts, autoloaded data, revisions,
    expired transients, scheduled events, admin notices and admin polling — all
    counts, all with units.
  - Nothing is reported as time saved, and the word "faster" appears nowhere. A
    plugin cannot honestly attribute page-load time to its own changes on
    somebody else's host.
  - What could not be measured says so. A site that cannot reach itself is
    reported as unmeasured, never as having fallen to zero, and a metric that
    did not move is still listed — a report that shows only improvements is an
    advertisement.
  - The run screen says what is happening while it happens: taking a recovery
    point, applying, checking the site. If the checks fail, it says which one,
    and that the rollback is complete and the previous configuration restored.


### Added since 0.1.0

- **Phase 17 — where the change list comes from.**
  - The list of changes Debloater knows how to make is now versioned. `wp
    debloater registry` tells you which release your copy is carrying.
  - It can check for a newer one — `wp debloater registry --check-updates` — and
    that is the only thing it sends off your server. It is off by default, and
    off means no request at all.
  - Nothing is installed unless it is signed with the key built into your copy
    of the plugin, and every single file has to match the checksum in that
    signed list. If one file does not, the whole update is rejected rather than
    half-applied.
  - The list is data, never code. Debloater will not download anything but
    JSON, and nothing it downloads is ever executed.
  - `docs/REGISTRY.md` explains how to propose a change to the list, and what
    has to be true before it ships.


- **Fixed: the Debloater screen did not work on a default WordPress.**
  On a site using plain permalinks — which is what WordPress uses out of the box
  — every screen showed "No route was found matching the URL and request
  method." and nothing loaded. This affected every version since the admin
  screen was added. It is fixed, and there is now a test that builds the same
  URLs the screen does and checks they resolve under both permalink settings.

- **Phase 16 — testing it the way you use it.**
  - A browser-driven test suite now opens the real admin screen on a real site
    with WooCommerce, Elementor and Contact Form 7 installed, runs a scan, fixes
    the safe issues, reads the report, buys a product, reaches the checkout,
    submits a form and opens the Elementor editor.
  - It also forces a verification failure on purpose, and checks that the site
    goes back exactly as it was and that the screen says so.
  - None of this ships with the plugin — it is a development tool. `wp debloater
    verify --e2e` tells you how to run it from a checkout.


- **Phase 15 — WooCommerce.**
  - Debloater now works out which of your pages are actually part of the shop,
    and which are not. That matters because WooCommerce's cart-fragments script
    asks your server what is in the cart every time any page loads — including
    the blog and the contact page — and that request can never be cached.
  - It can make that script load only where a cart could appear. **Unless
    something on your site shows a cart away from the shop** — a total in the
    header, a widget in a sidebar — in which case Debloater refuses the change
    outright and tells you which page it saw the cart on. There the script is
    what keeps the total correct, and switching it off would leave a number that
    never updates.
  - The same for WooCommerce's block stylesheets, plus two admin changes: turn
    off Analytics if you read your numbers elsewhere, and hide the panels
    recommending paid extensions. Notices about your own store are untouched.
  - Every one of these is checked against your cart, checkout and account pages
    as a customer sees them. If any of the three stops working, the change is
    undone rather than kept.


- **Phase 14 — Elementor.**
  - Debloater now counts the Elementor widgets your site has registered, which
    plugin registered each one, and how many of them your saved designs actually
    use. On a site with a few addon packs the gap is usually large.
  - It says "potentially unused", and means it. A widget can reach a page through
    a dynamic tag, a shortcode, a theme-builder template or a custom code block
    without the saved design ever naming it, so where Debloater sees any of
    those it says so and lowers its own confidence.
  - It will never switch a widget off. Elementor has no supported way to remove
    another plugin's widget, and doing it unsupported loses the content on every
    page already built with one. What this is for is deciding whether a pack is
    still worth having — a decision only you can make.
  - One change is offered: stop Elementor fetching Google Fonts, using
    Elementor's own supported setting. Your text will fall back to the visitor's
    own fonts, which is visible, so it is marked medium risk and says why.


- **Phase 13 — what your pages actually load.**
  - Debloater now fetches a few of your own pages — the home page and one of
    each kind of content, up to ten — and reads the scripts and stylesheets back
    out of them. Every one is attributed to the plugin, theme or WordPress
    itself that it came from, with its size where the file is on your server.
  - It counts the other servers your pages fetch from, and notices Google Fonts.
  - It reports Contact Form 7 loading its script and stylesheet on pages that
    have no form on them, which is what it does by default.
  - It proposes nothing at all, and there is still no Assets score. This reads a
    sample of your pages, not all of them, and every number says how many it
    looked at. Deciding a script is unnecessary on that basis is how you break
    somebody's contact page.


- **Phase 12 — the admin screens.**
  - Debloater can now tell you who is putting what on your admin screens: which
    plugin registered each notice, each dashboard widget, each menu item.
  - You can take dashboard widgets off, remove the welcome panel, and remove the
    Events and News widget — the one that fetches something over the network
    every time the dashboard loads. Nothing is uninstalled and nothing is
    deleted; unselecting any of it puts everything back.
  - The "WordPress x.y is available" notice can be hidden from people who cannot
    update — authors, editors, shop managers — while whoever *can* update still
    sees it every time. That part is not configurable and never will be.
  - You can also hide the admin notices of specific plugins. Read this bit: it
    hides all of them, not just the marketing. WooCommerce, Yoast and the others
    send upgrade prompts and real warnings down the same channel, and nothing
    tells them apart, so Debloater does not claim to. You choose which plugins,
    one at a time, and it is never something a single click decides for you.
  - Admin joins the score as a sixth sub-score. See docs/SCORING.md, now at
    version 2.0.


- **Phase 11 — the plugin list.**
  - Debloater now notices when two plugins are doing the same job — two page
    caches, two SEO plugins, two backup plugins — and tells you which ones, and
    what running two of that particular kind actually costs. It will not
    deactivate or delete either. Two of something is often deliberate, and a
    plugin that cannot tell a duplicate from a migration in progress has no
    business making that call.
  - It also reports active plugins with no sign of life in two years — worth
    knowing, never a recommendation, since plenty of small plugins are simply
    finished.
  - The date behind that comes from your own server unless you ask otherwise, and
    Debloater says which. `wp debloater scan --check-plugin-updates` is the one
    thing in the whole plugin that sends anything off your server; it asks
    wordpress.org for release dates and nothing else, and it is not remembered.
    The next scan asks again.
  - Where something else on your site — your host's optimizer, or a cache plugin
    — has its own setting for something Debloater found, the finding says so and
    says where to look, so you can use one switch instead of two. It does not
    claim the other tool has already dealt with it: if it had, there would be
    nothing to report.


- **Phase 10 — the database.**
  - Five things Debloater can now remove: old post revisions, abandoned
    auto-drafts, content already in the trash, comments marked as spam, and
    metadata whose owner no longer exists.
  - Every one of them copies each row before deleting it — with its id, its
    dates and its metadata — so putting it back is indistinguishable from never
    having run. There is a test for each that compares whole rows, not counts.
  - None of them can reach "Fix Safe Issues". Deleting data is never something a
    single click decides on your behalf.
  - What counts as "metadata whose owner no longer exists" is written down
    before the code that acts on it, and is deliberately narrow: a row counts
    only when the table WordPress itself looks in has no matching owner.
  - The confirmation says "Create recovery backup & delete", and offers a box
    for "I have my own backup of this site". Ticking it records what you said
    and skips nothing — the backup is taken either way, and a deletion with no
    complete backup is refused with the box ticked exactly as without it.
  - Autoloaded options are reported in full and changed narrowly: the report
    names the largest whatever they are, and the change touches only names on a
    list Debloater maintains.

## [0.1.0] — 2026-09-03

The MVP: scan, findings with their evidence, a plan you can read, a recovery
point before anything changes, verification afterwards, and an undo that puts
the site back exactly.

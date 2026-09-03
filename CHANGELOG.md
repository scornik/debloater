# Changelog

All notable changes to WP Debloat are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
    refuses to write anywhere but `wp-content/wpdebloat`.
  - The mu-plugin loader, with a documented `plugins_loaded` fallback for hosts
    where `mu-plugins` is not writable, and a runtime guard providing the
    `WPDEBLOAT_DISABLE` kill switch and an authenticated `?wpdebloat=off` bypass.
  - `Recommend\DependencyResolver` v1: conflicts resolve in both directions,
    and anything with an unresolved requirement is excluded rather than assumed
    satisfied.
  - `Storage\State`: all plugin state in one option, never autoloaded.
  - `GET wpdebloat/v1/status`, reporting what is actually on disk — including
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
  - Runs are recorded in `wpdebloat_runs` with the facts in the payload and the
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
  - `POST wpdebloat/v1/scan` and `GET wpdebloat/v1/findings`. Before any scan,
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
  - `GET wpdebloat/v1/preview`, which computes a plan from a recorded scan and
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
    `wp-content/wpdebloat/backups`, written and read as a stream so neither
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
  - The whole loop from a terminal: `wp debloat scan`, `findings`, `preview`,
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

- **Phase 11 — the plugin list.**
  - WP Debloat now notices when two plugins are doing the same job — two page
    caches, two SEO plugins, two backup plugins — and tells you which ones, and
    what running two of that particular kind actually costs. It will not
    deactivate or delete either. Two of something is often deliberate, and a
    plugin that cannot tell a duplicate from a migration in progress has no
    business making that call.
  - It also reports active plugins with no sign of life in two years — worth
    knowing, never a recommendation, since plenty of small plugins are simply
    finished.
  - The date behind that comes from your own server unless you ask otherwise, and
    WP Debloat says which. `wp debloat scan --check-plugin-updates` is the one
    thing in the whole plugin that sends anything off your server; it asks
    wordpress.org for release dates and nothing else, and it is not remembered.
    The next scan asks again.
  - Where something else on your site — your host's optimizer, or a cache plugin
    — has its own setting for something WP Debloat found, the finding says so and
    says where to look, so you can use one switch instead of two. It does not
    claim the other tool has already dealt with it: if it had, there would be
    nothing to report.


- **Phase 10 — the database.**
  - Five things WP Debloat can now remove: old post revisions, abandoned
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
    list WP Debloat maintains.

## [0.1.0] — 2026-09-03

The MVP: scan, findings with their evidence, a plan you can read, a recovery
point before anything changes, verification afterwards, and an undo that puts
the site back exactly.

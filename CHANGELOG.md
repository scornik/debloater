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

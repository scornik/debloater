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

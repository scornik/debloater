# BUILD-STATUS.md

Per-phase ledger for the autonomous build (`BUILD-SPEC.md` §21.6). Status values
are `NOT_STARTED`, `IN_PROGRESS`, `BLOCKED`, `COMPLETE`.

## Current

| | |
|---|---|
| **Current phase** | 3 — Analyzer, findings, score |
| **Last completed** | 2 — Scanner (facts only) |
| **Blockers** | none |

## Phases

| Phase | Title | Status | Commit | Tests | Acceptance |
|---|---|---|---|---|---|
| 0 | Architecture and contracts | COMPLETE | `60160e5` | 747 pass / 0 fail | pass |
| 1 | Minimal runtime engine | COMPLETE | `7f5eed7` | 797 unit + 33 integration / 0 fail | pass |
| 2 | Scanner (facts only) | COMPLETE | `phase-2` | 817 unit + 55 integration / 0 fail | pass |
| 3 | Analyzer, findings, score | NOT_STARTED | — | — | — |
| 4 | Recommendation engine | NOT_STARTED | — | — | — |
| 5 | Snapshot, apply, rollback | NOT_STARTED | — | — | — |
| 6 | Verification engine | NOT_STARTED | — | — | — |
| 7 | WP-CLI | NOT_STARTED | — | — | — |
| 8 | React dashboard | NOT_STARTED | — | — | — |
| 9 | Preview and Fix Safe Issues | NOT_STARTED | — | — | — |
| 10 | Database intelligence | NOT_STARTED | — | — | — |
| 11 | Plugin intelligence | NOT_STARTED | — | — | — |
| 12 | Admin intelligence | NOT_STARTED | — | — | — |
| 13 | Asset intelligence | NOT_STARTED | — | — | — |
| 14 | Elementor intelligence | NOT_STARTED | — | — | — |
| 15 | WooCommerce intelligence | NOT_STARTED | — | — | — |
| 16 | Headless verification (E2E) | NOT_STARTED | — | — | — |
| 17 | Registry ecosystem | NOT_STARTED | — | — | — |
| 18 | Release hardening | NOT_STARTED | — | — | — |
| 19 | Pro workflow features | NOT_STARTED | — | — | — |
| 20 | Agency/cloud design | NOT_STARTED | — | — | — |

---

## Phase 0 — Architecture and contracts

**Status:** COMPLETE

### Delivered

- Repository scaffolded to `BUILD-SPEC.md` §4.
- `composer.json` with PSR-4 `WPDebloat\` → `src/` and **zero runtime
  dependencies** (asserted by a test).
- `src/Contracts/*`: 16 contracts and 10 enums, all validating in the
  constructor, all rejecting unknown keys, all round-tripping losslessly
  through `toArray()` / `fromArray()` including via JSON.
- `registry/schemas/*.schema.json`: fact, finding, tweak, compat, profile,
  detector, matching §5–§7 field for field.
- `Registry\SchemaValidator`: hand-written JSON Schema draft-07 subset
  (D-0001); an unsupported keyword throws `UnsupportedSchemaKeyword` rather
  than being ignored.
- `RunState` / `TweakState` enums, `RunStateMachine` / `TweakStateMachine`,
  `IllegalTransition`.
- `docs/STATE-MACHINE.md`, generated from the enums by
  `StateMachineDocTest`, which fails when the committed file is stale.
- `Brand`, the single source of user-visible naming.
- `CLAUDE.md`, `CONVENTIONS.md`, `README.md`, `CHANGELOG.md`, `LICENSE`.

### Exit checklist (§17 Phase 0)

| Criterion | Result |
|---|---|
| `composer test` green | ✅ 747 tests, 2 911 assertions, 0 failures |
| Zero runtime Composer dependencies | ✅ asserted by `RepositoryInvariantsTest` |
| All contracts have valid and invalid tests | ✅ 18 round-trip subjects, 44 invalid-input cases |
| `docs/STATE-MACHINE.md` generated | ✅ generated and verified by test |
| No WordPress-dependent code | ✅ whole suite runs with no WordPress loaded; asserted for `src/Contracts` and `src/Registry` |
| PHPCS clean | ✅ 0 errors, 0 warnings across 55 files |
| PHPStan level 6 clean | ✅ no errors |
| Every legal and illegal state transition tested | ✅ 33 legal, 328 illegal, generated from the enums |
| Decisions recorded before implementation | ✅ D-0001 to D-0004 |

### Known warnings

- PHPCompatibility 9.x cannot parse PHP 8.1 enums and reports valid `$this`
  and `self` usage inside enums as errors. Two sniffs are excluded for that
  reason and only that reason; see D-0004. This removes a small amount of
  genuine PHP-version coverage over enum bodies, to be restored when
  PHPCompatibility ships enum support.
- Local runs execute on PHP 8.2 only (D-0003). The 8.1 and 8.3 legs of the
  matrix in §14 are a CI concern and are not yet exercised.

### Next

Phase 1 — Minimal runtime engine.

---

## Phase 1 — Minimal runtime engine

**Status:** COMPLETE

### Delivered

- `Registry\Loader`, `Registry\Registry`, `Registry\TweakDefinition`: every
  document schema-validated at load, indexed by id, with a content-derived
  registry hash. A dangling conflict or requirement stops the load rather than
  silently turning a safety rule into a no-op.
- Five tweaks and five handlers: `core.remove_generator`, `core.remove_rsd`,
  `core.remove_shortlink`, `core.disable_self_pingbacks`, `core.disable_emojis`.
- `Apply\Compiler`: deterministic, timestamp-free source; handler paths
  realpath-checked into the plugin's own directory; parameters emitted through
  `var_export` after schema validation.
- `Apply\RuntimeWriter`: syntax check, atomic temp-file-and-rename, 0644,
  `runtime.lock` holding the runtime, selection and registry hashes; refuses any
  path outside `wp-content/wpdebloat`.
- `Apply\RuntimeLoader` and `mu-loader/wp-debloat-loader.php`, with the
  documented `plugins_loaded` fallback and the deferred bypass authorisation
  (D-0007).
- `runtime-handlers/runtime-guard.php`: the `WPDEBLOAT_DISABLE` kill switch and
  the authenticated `?wpdebloat=off` bypass.
- `Recommend\DependencyResolver` v1 and `Recommend\Resolution`.
- `Storage\State`: one option, `autoload = no`.
- `Rest\Controller`, `Rest\Routes\StatusRoute`, `Security\Capabilities`.
- `wp-debloat.php` and `Plugin`: activation, deactivation, lazy services.
- `.wp-env.json`, `phpunit-wp.xml.dist`, `tests/bootstrap-integration.php` and
  the integration harness.

### Exit checklist (§17 Phase 1)

| Criterion | Result |
|---|---|
| Compiler snapshots for 0, 1 and 3 tweaks | ✅ `tests/Fixtures/runtime/*.php.txt` |
| Byte-identical output on regeneration | ✅ unit and integration |
| Determinism regardless of selection order | ✅ |
| Parameter escaping into generated code | ✅ including a quote-injection attempt |
| Empty selection registers zero hooks | ✅ asserted by hook-table diff |
| Empty selection adds zero DB queries on a front-end request | ✅ every query captured through the `query` filter |
| One tweak registers only its own hooks | ✅ exactly one hook added, none removed |
| `GET wpdebloat/v1/status` | ✅ 401 anonymous, 403 subscriber, 200 admin |
| Loader fallback documented | ✅ D-0007 |
| Unit suite | ✅ 797 tests, 3 179 assertions |
| Integration suite | ✅ 33 tests, 95 assertions |
| PHPCS | ✅ 0 errors, 0 warnings across 87 files |
| PHPStan level 6 | ✅ no errors |

### Known warnings

- Two PHPUnit versions are in play (D-0008): 10.5 for unit, 9.6 for integration,
  because the WordPress core test suite is not PHPUnit 10 compatible.
- The build machine needed DNS configuration for Docker and for wp-env (D-0009).
  Neither change touches the plugin or CI.
- The `.wp-env.json` stack is vanilla WordPress. The full matrix from §14
  (WooCommerce, Elementor, CF7, Rank Math, LiteSpeed) is added in Phase 2, where
  the scanners and detectors first need it.

### Next

Phase 2 — Scanner (facts only).

---

## Phase 2 — Scanner (facts only)

**Status:** COMPLETE

### Delivered

- Eleven scanners under `src/Scan/Scanners`, each declaring the namespace it
  owns: `EnvironmentScanner` (`env`), `WordPressScanner` and
  `CoreFeatureScanner` (`wp`), `UserScanner` (`users`), `PluginScanner`
  (`plugins`), `ThemeScanner` (`theme`), `DatabaseScanner` and
  `AutoloadScanner` (`db`), `CronScanner` (`cron`), `AdminScanner` (`admin`).
- `Scan\ScanRunner` and `Scan\ScanResult`: soft two-second budget per scanner,
  timings recorded whether or not the budget was exceeded, and a failing
  scanner recorded by name rather than allowed to end the scan.
- `Registry\Detector` plus the ten detectors §17 Phase 2 names. Detectors are
  pure data; PluginScanner evaluates their signals.
- `Storage\Schema` (dbDelta, `wpdebloat_runs`) and
  `Storage\Repositories\RunRepository`.
- `Contracts\Run`, so a run is a validated value rather than a row shape.
- `scan.*` diagnostics added to `registry/schemas/fact.schema.json`.
- `tools/seed-fixture.php` for the development site, and `npm run env:seed`.

### Two additions the specification's key list required

- **`UserScanner`.** §4's directory listing does not name it, but §5 requires
  `users.admin_count` and `users.recent_editors_7d`, and namespace ownership
  means no other scanner may write them. One scanner, two counts, no personal
  data.
- **The `scan` namespace.** §17 Phase 2 requires an over-budget scanner to
  "emit a warning/diagnostic fact". Those facts need somewhere to live and a
  schema entry, so `ScanRunner` owns `scan.*`.

### Exit checklist (§17 Phase 2)

| Criterion | Result |
|---|---|
| Every fact key enumerated in the schema | ✅ a real scan validates with zero violations |
| Unknown keys rejected | ✅ `additionalProperties: false`, asserted against a live scan |
| Scanners produce no opinion strings | ✅ twelve judgement words searched across every fact a scan produces |
| DatabaseScanner query count bounded | ✅ declared as `QUERY_BUDGET` and asserted |
| Seeded counts match exactly | ✅ revisions, trash, auto-drafts, spam, transients, orphan meta, cron, autoload, users |
| Detectors applied in PluginScanner | ✅ both outcomes recorded, ten detectors |
| Soft budget, no forced interruption | ✅ an over-budget scanner still contributes its facts |
| Scan persisted as a run with facts in the payload | ✅ round-trips through storage without loss |
| Scan under 5 s | ✅ well under on the fixture environment |
| Unit suite | ✅ 817 tests, 3 338 assertions |
| Integration suite | ✅ 55 tests, 166 assertions |
| PHPCS | ✅ 0 errors, 0 warnings across 108 files |
| PHPStan level 6 | ✅ no errors |

### Known warnings

- Two facts in §5 cannot be observed from the request a scan runs in and are
  **deliberately absent** rather than guessed: `wp.dashicons_frontend` outside a
  front-end request, and the `admin.*` counts outside an admin request. An
  analyzer rule reads an absent fact as "not observed", which is different from
  a zero. Phase 13's asset scan resolves the first properly.
- `plugins.meta[*].last_updated` needs the wp.org API, which §13 rule 9 makes
  opt-in. Phase 11 adds it behind that opt-in with a low-confidence local
  fallback; until then the key is absent.
- The integration environment is vanilla WordPress. Detector behaviour is
  exercised with stub plugin files, which is exactly what a detector reads;
  the real stack matrix from §14 is a nightly CI concern.

### Next

Phase 3 — Analyzer, findings, Don't Touch, Score.

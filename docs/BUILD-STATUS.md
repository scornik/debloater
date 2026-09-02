# BUILD-STATUS.md

Per-phase ledger for the autonomous build (`BUILD-SPEC.md` §21.6). Status values
are `NOT_STARTED`, `IN_PROGRESS`, `BLOCKED`, `COMPLETE`.

## Current

| | |
|---|---|
| **Current phase** | 5 — Snapshot, apply, rollback |
| **Last completed** | 4 — Recommendation engine |
| **Blockers** | none |

## Phases

| Phase | Title | Status | Commit | Tests | Acceptance |
|---|---|---|---|---|---|
| 0 | Architecture and contracts | COMPLETE | `60160e5` | 747 pass / 0 fail | pass |
| 1 | Minimal runtime engine | COMPLETE | `7f5eed7` | 797 unit + 33 integration / 0 fail | pass |
| 2 | Scanner (facts only) | COMPLETE | `9f47be9` | 817 unit + 55 integration / 0 fail | pass |
| 3 | Analyzer, findings, score | COMPLETE | `3d7944c` | 928 unit + 70 integration / 0 fail | pass |
| 4 | Recommendation engine | COMPLETE | `phase-4` | 959 unit + 82 integration / 0 fail | pass |
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

---

## Phase 3 — Analyzer, findings, Don't Touch, Score

**Status:** COMPLETE

### Delivered

- Fourteen analyzer rules: eleven for the MVP tweak set in §15, three
  informational (`plugins.inactive_present`, `wp.file_editor.enabled`,
  `wp.xmlrpc.enabled`). One rule, one finding id, asserted.
- `Analyze\EvidenceBuilder`: evidence must cite a fact the scan actually
  observed. Citing an unobserved key throws.
- `Analyze\ConfidenceCalculator`: base × penalties, with the multipliers,
  the compounding cap and the floor recorded in `docs/SCORING.md` and D-0010.
- `Analyze\DontTouchRules`: capability dependencies from the compatibility
  registry, plus the situational Heartbeat rule from §17 Phase 3.
- `Analyze\Score`: five sub-scores, severity penalties 0/4/10/20, unweighted
  mean, refusals costing nothing, unscored categories reported rather than
  dropped.
- `Analyze\Analyzer` and `AnalysisResult`, which report rules that could not
  be evaluated instead of leaving them as silence.
- `docs/SCORING.md` v1.0, versioned and shown next to the score.
- Six new tweaks completing the §15 MVP set, with five handlers; the sixth is
  the single data operation, whose DataOperation class arrives in Phase 5.
- Six compatibility rules, `Registry\CompatRule`, and the registry loading and
  hashing that goes with them.
- `POST wpdebloat/v1/scan` and `GET wpdebloat/v1/findings`.

### Exit checklist (§17 Phase 3)

| Criterion | Result |
|---|---|
| One rule class per finding id | ✅ asserted, and duplicates refused |
| Every finding carries fact-cited evidence | ✅ enforced by EvidenceBuilder, asserted per rule |
| Severity and risk independent | ✅ info-severity findings at safe risk, low-severity at medium risk |
| Confidence from base × penalties | ✅ four penalties, capped, floored, deterministic |
| REST dont_touch when a dependent requires rest:public | ✅ capability mapping, exercised through the registry |
| Heartbeat dont_touch when editors ≥ 2 and WooCommerce | ✅ and *not* refused without either condition |
| Score exactly per §12 | ✅ 0/4/10/20, per-id cap, refusals excluded |
| `docs/SCORING.md` v1 committed | ✅ with the numbers and the reasoning |
| Numbers recorded in DECISIONS.md | ✅ D-0010 |
| Seeded site ≥ 12 findings incl. ≥ 1 dont_touch | ✅ 13 findings, 1 refusal on the busy-store fixture |
| Score deterministic and unchanged by a refusal | ✅ |
| Findings persisted in the scan run payload | ✅ round-trips as contracts |
| POST /scan, GET /findings with capability checks | ✅ 401 anonymous, 403 without capability |
| Unit suite | ✅ 928 tests, 3 840 assertions |
| Integration suite | ✅ 70 tests, 260 assertions |
| PHPCS | ✅ 0 errors, 0 warnings across 144 files |
| PHPStan level 6 | ✅ no errors |

### Known warnings

- `wp.dashicons.frontend` is usually **not evaluated**: the fact it needs can
  only be observed on a front-end request. That is reported as "not evaluated"
  rather than as "nothing to do", and Phase 13's asset scan resolves it.
- `db.clean_expired_transients` is in the registry, and its `DataOperation`
  class arrives in Phase 5. A test asserts the handler names a class in the
  right namespace; Phase 5 strengthens that to "the class exists".
- The unit suite gained WordPress i18n stand-ins (`tests/wp-i18n-polyfill.php`).
  They return the untranslated string, exactly as WordPress does with no
  translation loaded, and are only defined when WordPress is absent.

### Next

Phase 4 — Recommendation engine.

---

## Phase 4 — Recommendation engine

**Status:** COMPLETE

### Delivered

- `Recommend\IntentProfile`: site type and priority, persisted, with cautious
  defaults that unlock nothing. Malformed *stored* values fall back; malformed
  *user input* is rejected.
- `Recommend\CompatibilityResolver`: the registry's rules resolved against one
  site, counting only what is actually present, and reporting components with
  no rule at all rather than assuming them harmless.
- `Recommend\RiskEngine`: risk raised one level for detected dependents or an
  unrecognised host, never lowered, never by more than one level, always with a
  reason.
- `Recommend\FactPredicate` and `DependencyResolver` v2: `fact:<key>=<value>`
  requirements, where an unobserved fact is unresolved rather than satisfied.
- `Recommend\RecommendationEngine` and `Recommendations`: findings to tweaks,
  deterministic, keeping the link back to the finding that justified each one.
- `Recommend\PreviewPlanner` and `PlanResult`: the only place a plan can be
  built, enforcing the two §7.4 invariants that need findings and facts, with
  every exclusion carrying a reason.
- `Registry\Profile` and the three shipped profiles. No profile admits a
  destructive tweak, whatever its own flag says.
- `GET wpdebloat/v1/preview`.

### Exit checklist (§17 Phase 4)

| Criterion | Result |
|---|---|
| IntentProfile persisted, defaults `other`/`balanced` | ✅ |
| CompatibilityResolver over `registry/compatibility/*.json` | ✅ six rules, presence-checked |
| Heartbeat 120 s for a quiet blog, 60 s otherwise | ✅ both worked examples |
| RiskEngine raises one level for dependents or unknown host | ✅ and never lowers |
| DependencyResolver v2 with fact predicates | ✅ unobserved ≠ satisfied |
| PreviewPlan with will_change / will_not / snapshot levels | ✅ |
| **Property tests over generated registries** | ✅ 120 seeded cases per invariant, 2 708 assertions |
| No destructive tweak in a safe plan | ✅ property test |
| No two conflicting tweaks in one plan | ✅ property test, both directions, every profile |
| No tweak named by an active dont_touch finding | ✅ property test, every profile |
| No tweak with unresolved requires | ✅ property test, including fact predicates |
| Unit suite | ✅ 959 tests, 6 683 assertions |
| Integration suite | ✅ 82 tests, 317 assertions |
| PHPCS | ✅ 0 errors, 0 warnings across 157 files |
| PHPStan level 6 | ✅ no errors |

### Known warnings

- The preview text is assembled from the tweak descriptions in the registry.
  Phase 9 builds the user-facing preview modal on top of it; a test already
  asserts the text never claims speed, since that is the first place such a
  claim could appear.
- `admitted()` on the engine exists for the CLI and dashboard in Phases 7 and 8
  and is not yet exercised by a caller; it is covered by unit tests.

### Next

Phase 5 — Snapshot, apply, rollback.

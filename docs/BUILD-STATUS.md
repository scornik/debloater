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
- `composer.json` with PSR-4 `Debloater\` → `src/` and **zero runtime
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
  path outside `wp-content/debloater`.
- `Apply\RuntimeLoader` and `mu-loader/debloater-loader.php`, with the
  documented `plugins_loaded` fallback and the deferred bypass authorisation
  (D-0007).
- `runtime-handlers/runtime-guard.php`: the `DEBLOATER_DISABLE` kill switch and
  the authenticated `?debloater=off` bypass.
- `Recommend\DependencyResolver` v1 and `Recommend\Resolution`.
- `Storage\State`: one option, `autoload = no`.
- `Rest\Controller`, `Rest\Routes\StatusRoute`, `Security\Capabilities`.
- `debloater.php` and `Plugin`: activation, deactivation, lazy services.
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
| `GET debloater/v1/status` | ✅ 401 anonymous, 403 subscriber, 200 admin |
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
- `Storage\Schema` (dbDelta, `debloater_runs`) and
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
- `POST debloater/v1/scan` and `GET debloater/v1/findings`.

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
- `GET debloater/v1/preview`.

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

---

## Phase 5 — Snapshot, apply, rollback

**Status:** complete · 2026-09-03

`BUILD-SPEC.md` §17 calls this "the most important engineering phase; correctness
over speed", and it is the first phase in which the plugin changes anything on a
site by itself.

### What exists now

- `Storage\Schema` version 2: `debloater_snapshots`, `debloater_snapshot_items`
  and `debloater_journal`, matching §8 field for field.
- `Journal\Journal`: append-only, one row per transition, with the actor and the
  parameters in force. A failed journal write never fails the work it describes —
  the journal is the record of the work, not the work.
- `Snapshot\SnapshotManager`:
  - **Level A** — the previous selection, the tweak states, the runtime hash, the
    loader mode and the current value of every option a selected tweak declares
    (through the `debloater_tweak_options` filter), including the distinction
    between "the option held null" and "the option did not exist".
  - **Level B** — the exact rows a `DataOperationInterface` yields from
    `collect()`, taken **before** `execute()` touches anything, read back and
    checksummed before the operation is allowed to run.
  - Spill to gzipped newline-delimited JSON above 8 MB (D-0015).
  - `verify()`, which marks a snapshot corrupt on any mismatch and throws.
  - `forget()`, which removes a snapshot, its rows and its file (D-0016).
- `Snapshot\SpillFile`: streaming gzip read and write under
  `wp-content/debloater/backups`, mode 0600, behind an `index.php` and an
  `.htaccess`, with every path resolved and checked against that directory.
- `Snapshot\RollbackManager`: restores both levels. Level A restores the options
  (deleting those that did not exist), the selection and the tweak states, then
  rewrites the runtime and **verifies the rewritten file hashes to what the
  recovery point recorded**, throwing if it does not. Level B verifies the
  snapshot, then puts the rows back through the operation that removed them.
  Three refusals before anything is written: wrong site, not complete, unreadable
  rows.
- `Apply\Lock`: a 60-second transient claimed atomically with `add_option`, which
  refuses to release a lock it does not hold.
- `Apply\ApplyManager`: drives `RunStateMachine` through the §9.2 sequence —
  lock, snapshot **everything** and verify it, apply configuration, then data,
  verify, commit — and rolls back on any failure after applying began. No data
  operation runs without a complete Level B recovery point, checked immediately
  before the deletion rather than only when the snapshot was taken.
- `Apply\TweakLifecycle` and `TweakStateMachine::pathTo()`: every journalled
  tweak transition is an edge from the §9.1 table, found rather than assumed
  (D-0018).
- `Apply\DataOperations\ExpiredTransientsCleanup`: the first data operation.
  Deletes through `delete_transient()` so a persistent object cache sees the
  removal, restores through `$wpdb->replace` so a restored transient keeps its
  original expiry instead of being resurrected as live.
- Crash recovery on `admin_init`: a run left in `APPLYING` or `VERIFYING` with no
  live lock is marked `INTERRUPTED` and rolled back (D-0017).

### Exit checklist (§17 Phase 5)

| Criterion | Result |
|---|---|
| Three tables created via `Storage\Schema` | ✅ schema version 2 |
| Level A: selection, runtime hash, affected option values | ✅ plus tweak states and loader mode |
| Level B: exact rows from `collect()` before `execute()` | ✅ verified before the operation may run |
| Checksum, and spill to gz file above a recorded threshold | ✅ 8 MB, D-0015 |
| `ApplyManager` drives `RunStateMachine` | ✅ every transition recorded on the run |
| `debloater_lock` transient | ✅ atomic claim, 60 s TTL |
| Journal rows on every transition | ✅ and every row is a legal edge, D-0018 |
| Crash recovery for `APPLYING` / `VERIFYING` | ✅ lock-guarded, D-0017 |
| `RollbackManager` for both levels, original ids and timestamps | ✅ transient timeouts restored exactly |
| `ExpiredTransientsCleanup` with batches of 500 | ✅ default batch size 500 |
| **Apply five config tweaks, roll back, byte-identical runtime** | ✅ integration test compares bytes and option values |
| **Transient round-trip restores rows and timeouts exactly** | ✅ raw rows compared, expiry preserved |
| **A corrupt checksum refuses restore** | ✅ tampered config and truncated spill file |
| **A second apply while locked is rejected** | ✅ and does not steal the lock |
| **A simulated crash in APPLYING is rolled back on next boot** | ✅ and a live apply is left alone |
| Unit suite | ✅ 967 tests, 7 054 assertions |
| Integration suite | ✅ 102 tests, 746 assertions |
| PHPCS | ✅ 0 errors, 0 warnings across 170 files |
| PHPStan level 6 | ✅ no errors |

### Known warnings

- `MEASURING_BEFORE` and `MEASURING_AFTER` are traversed but do nothing: the
  Meter arrives in Phase 9. The transitions exist now so the shape of a run does
  not change when it does, and the run records honestly that no measurement was
  taken rather than reporting a delta of zero.
- `VERIFYING` likewise passes straight to `VERIFIED`. Phase 6 puts the probes
  there. Nothing in the plugin currently claims a verification ran.
- Level C — the user's attestation that they have their own external backup — is
  not implemented and is never a substitute for Level B (§12 rule 8).

### Next

Phase 6 — Verification engine.

---

## Phase 6 — Verification engine

**Status:** complete · 2026-09-03

The half of the safety promise Phase 5 could not keep on its own. Phase 5 made
every change undoable; this phase makes the plugin notice, by itself, when a
change needs undoing.

### What exists now

- `Verify\HttpClient`: every loopback request in one place — fifteen-second
  timeout, `sslverify` from the site's own `https_local_ssl_verify` setting, an
  `X-Debloater-Verify: 1` header that nothing in the plugin ever reads, and at
  most three redirects.
- `Verify\ActorSession`: the credential for the two probes that need one. An
  admin request's existing logged-in cookie is forwarded verbatim; from WP-CLI
  or cron a short-lived session is minted for the acting user and destroyed as
  soon as verification ends. A matching REST nonce is produced either way, since
  without one the REST API treats a cookie request as anonymous by design.
- `Verify\Markers`: the fatal, render, dashboard and login markers, in one place
  (D-0019).
- Six probes, exactly as §11 specifies: `home`, `content_page`, `admin`, `rest`,
  `login`, `runtime_loaded`. Each returns a `ProbeResult` with evidence — status
  code, elapsed time, bytes, and whatever marker decided the outcome.
- `Verify\Verifier`: checks loopback once, runs the probes, and lets
  `VerificationResult` do the aggregation. `DEBLOATER_TEST_FAIL_PROBE` forces a
  named probe to fail so the rollback path can be exercised without breaking a
  site to do it.
- `ApplyManager` now really verifies: FAIL goes `VERIFICATION_FAILED →
  ROLLING_BACK → ROLLED_BACK` without asking, WARN commits as
  `VERIFIED_WITH_WARNINGS` with the warnings on the result, PASS commits quietly.
- `Plugin::verify()` runs the same checks on demand, changing nothing.

### Exit checklist (§17 Phase 6)

| Criterion | Result |
|---|---|
| `HttpClient` over `wp_remote_get`, 15 s timeout | ✅ asserted against the constant and the request args |
| `sslverify` honours the site setting | ✅ test flips `https_local_ssl_verify` and checks the arg |
| `X-Debloater-Verify` header | ✅ and nothing reads it |
| Auth cookie for the acting user | ✅ forwarded when present, minted and destroyed otherwise |
| Six probes exactly as §11 describes | ✅ every branch of each covered |
| PASS/WARN/FAIL/UNKNOWN/NOT_TESTED with evidence | ✅ |
| Aggregation: FAIL wins, then WARN/UNKNOWN, NOT_TESTED not counted | ✅ contract test plus an integration test |
| FAIL → `VERIFICATION_FAILED` → `ROLLING_BACK` → `ROLLED_BACK` | ✅ end to end, runtime compared byte for byte |
| WARN → `VERIFIED_WITH_WARNINGS` | ✅ recorded in the run history |
| `DEBLOATER_TEST_FAIL_PROBE` | ✅ own suite, own process |
| Markers decision recorded | ✅ D-0019 |
| Blocked-loopback policy recorded | ✅ D-0020 |
| Fixture fatal markers produce FAIL | ✅ |
| Missing markers produce WARN | ✅ |
| Blocked loopback produces UNKNOWN and a warned run | ✅ |
| Forced `rest` failure rolls back and restores selection and hash exactly | ✅ |
| Unit suite | ✅ 978 tests, 7 133 assertions |
| Integration suite | ✅ 123 tests, 809 assertions |
| Forced-failure suite | ✅ 5 tests, 74 assertions |
| PHPCS | ✅ 0 errors, 0 warnings across 187 files |
| PHPStan level 6 | ✅ no errors |

### Known warnings

- **Probe behaviour is tested against fixture responses, not real loopback.**
  wp-env runs the suite in a container separate from the one serving the site,
  and the site's canonical address (`localhost:8889`) resolves inside the runner
  to the runner itself, so the site genuinely cannot reach itself there. Even
  with the routing fixed, an HTTP request opens its own database connection and
  would not see the test's uncommitted transaction, so a probe could not observe
  the state the test had set up. Fixtures through `pre_http_request` cover every
  branch deterministically; a verification over real HTTP against committed state
  is exercised on the fixture site in Phase 7 (`wp debloater verify`).
- §11's `WP_Error` fatal marker is implemented as its printed forms rather than
  the bare class name, deliberately and with reasons, in D-0019.
- `MEASURING_BEFORE` and `MEASURING_AFTER` are still traversed without doing
  anything; the Meter arrives in Phase 9.
- The later probes §11 lists — `woo_cart`, `woo_checkout`, `woo_account`,
  `cf7_form`, `elementor_editor` — are not implemented. They belong to the
  compatibility work in Phase 12 and would report `NOT_TESTED` on this fixture
  site in any case.

### Next

Phase 7 — WP-CLI.

---

## Phase 7 — WP-CLI

**Status:** complete · 2026-09-03

The whole MVP loop, runnable from a terminal and from a deployment script.

### What exists now

- `Cli\Command`: `scan`, `findings`, `preview`, `apply`, `verify`, `rollback`,
  `snapshots`, `status`, `export`, `import`, registered as `wp debloater` when
  WP-CLI is present and never constructed otherwise.
- `Cli\Io` and `Cli\WpCliIo`: the boundary between the commands and WP-CLI.
  Everything the CLI says goes through it, which is what lets the tests assert
  exit codes without letting a test end the process.
- `Config\ConfigDocument`: configuration as code. Choices travel, conclusions do
  not (D-0022), validated against `schemas/config.schema.json` before a value is
  read out of it.
- `Plugin::previewTweaks()`: a plan from an explicit list of changes, through the
  same planner and therefore the same §7.4 invariants as a profile plan.
- `docs/CLI.md`: every command, every exit code, and the shape of every JSON
  output.
- `tools/cli-e2e.sh` (`npm run test:cli`): the loop through the real `wp` binary
  on the fixture site.

**No product logic lives in the CLI layer.** What to recommend, what may enter a
plan, what to snapshot and whether the site still works are all answered by the
engine, exactly as they are for the dashboard. A CLI that decided anything for
itself would be a second implementation of the rules, and the two would disagree
the first time one of them changed.

### Exit checklist (§17 Phase 7)

| Criterion | Result |
|---|---|
| `wp debloater` with all ten subcommands | ✅ |
| No logic in the CLI layer | ✅ engine calls only; asserted by review and by the shared behaviour with the REST layer |
| `apply` and `rollback` require `--yes` | ✅ tested; without it nothing is written |
| `import --apply` requires `--yes` | ✅ |
| Exit 0 success | ✅ |
| Exit 1 error | ✅ no scan, unknown tweak, unknown risk, missing file, invalid document, missing confirmation |
| Exit 2 verification failed and rolled back | ✅ forced-failure suite, end to end |
| Exit 3 verified with warnings | ✅ blocked loopback |
| `--json` outputs validate against schemas | ✅ facts and findings against the registry schemas; the configuration document against `schemas/config.schema.json`; the rest documented in `docs/CLI.md` and asserted |
| Actor is `cli` | ✅ `Capabilities::currentActor()` returns `cli` under WP-CLI, and every run and journal row carries it |
| Export produces selection + intent + params | ✅ |
| Import validates before use | ✅ schema, then registry, then the planner |
| Full loop through the CLI on the fixture site | ✅ `npm run test:cli` |
| README updated | ✅ |
| Unit suite | ✅ 979 tests, 7 156 assertions |
| Integration suite | ✅ 141 tests, 914 assertions |
| Forced-failure suite | ✅ 8 tests, 85 assertions |
| PHPCS | ✅ 0 errors, 0 warnings across 194 files |
| PHPStan level 6 | ✅ no errors |

### Known warnings

- `--json` is declared in each synopsis as `--format=<format>`, because WP-CLI
  reserves `--json` as its own shorthand and rewrites it before a command sees
  it. Both spellings work; the reasoning is D-0021.
- The fixture site's canonical URL is not routable from inside the CLI
  container, so `verify` there reports UNKNOWN and the loop exits 3 rather than
  0 at the apply step. The e2e script accepts either, because both mean the
  change is in place — 2 would mean it was undone. Real-loopback verification
  against committed state needs a site whose own address resolves from where the
  command runs.
- `wp debloater rollback <snapshot-id>` undoes the whole run that recovery point
  belongs to, not just that one snapshot. Restoring half a run would leave the
  site in a state nothing has a name for.

### Next

Phase 8 — React dashboard.

---

## Phase 8 — React dashboard

**Status:** complete · 2026-09-03

The first phase with a face. Everything the CLI can do, on one admin screen,
through the same engine.

### What exists now

- `admin-ui/` built by `@wordpress/scripts` into a single bundle: **6.7 KB of
  JavaScript gzipped**, plus 1.8 KB of CSS, against a 250 KB budget. React, the
  components and the i18n runtime come from WordPress, so none of them are in it.
- `Admin\Screen`: one top-level menu item, one screen, assets on that screen and
  no other. No admin notices, no dashboard widget, no front-end output — each
  asserted by a test that walks the other admin pages and the notice hooks.
- Four views:
  - **Overview** — the Debloat Score with its sub-scores, counts by risk and by
    decision including "No action recommended", and the two actions: *Fix safe
    issues* and *Review findings*.
  - **Findings** — filterable by risk, category and decision.
  - **Finding** — all ten fields from §17 Phase 8, always in the same order and
    always all of them. A field with nothing in it says so; a section that
    vanished would read as one that was never considered.
  - **Changes & recovery** — the runs, the recovery points, and a restore behind
    an explicit confirmation.
- New REST routes: `POST /apply`, `POST /rollback`, `GET /snapshots`. Every
  state-changing route now needs the capability **and** a valid nonce, checked in
  one place so a new route cannot forget it.
- `Rest\ConfirmationToken`: a token derived from the exact plan or recovery point
  being acted on, so a preview that has gone stale is refused rather than applied
  to something the user has not seen (D-0024).
- `useResource`: one hook, three named states, no state library (D-0023).

### Exit checklist (§17 Phase 8)

| Criterion | Result |
|---|---|
| `admin-ui/` with `@wordpress/scripts`, one bundle | ✅ `npm run build` |
| Enqueued only on our screen | ✅ asserted against sixteen other admin screens |
| Dashboard: score, sub-scores, counts by risk including no-action | ✅ |
| `Fix Safe Issues` and `Review findings` | ✅ and *Fix safe issues* is disabled when nothing is recommended |
| Findings list filtered by risk, category and decision | ✅ `category` added to the findings route |
| Finding detail with all ten fields | ✅ asserted by a Jest test that names each one |
| Runs & snapshots with a restore behind a confirmation token | ✅ |
| REST routes for preview, apply, rollback, status | ✅ plus snapshots |
| `permission_callback` on `debloater_manage` | ✅ every route, supplied centrally |
| Nonce verification on state-changing routes | ✅ including `scan`, which writes a run |
| No state change without confirmation | ✅ the token is required and is bound to the content |
| State-management choice recorded | ✅ D-0023 |
| No admin notices, dashboard widgets or front-end output | ✅ asserted |
| `@wordpress/components` for accessibility | ✅ and colour is never the only signal |
| REST permission tests (401/403) | ✅ anonymous, subscriber, and administrator-without-nonce |
| Jest tests for score and finding rendering | ✅ 12 tests |
| Bundle under 250 KB gzipped | ✅ 8.6 KB total — 3% of the budget |
| Assets not enqueued outside our screen | ✅ |
| Unit suite | ✅ 979 tests, 7 176 assertions |
| Integration suite | ✅ 160 tests, 1 057 assertions |
| Forced-failure suite | ✅ 8 tests, 85 assertions |
| Jest | ✅ 12 tests |
| PHPCS | ✅ 0 errors, 0 warnings across 201 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

### Known warnings

- `build/` is not committed: it is a build artifact, and the repository holds
  sources. `npm run build` produces it, and the enqueue test skips itself with a
  message when it is absent rather than failing for a reason unrelated to the
  code under test.
- The apply flow in the dashboard applies the **safe** profile. The wider
  profiles are reachable from the CLI today; Phase 9 builds the preview modal
  properly, with the profile choice and the live run screen.
- ESLint prints a deprecation warning about `.eslintrc.json`; the flat-config
  migration belongs with a future `@wordpress/scripts` upgrade rather than in a
  phase about the dashboard.

### Next

Phase 9 — Preview and Fix Safe Issues, the MVP milestone.

---

## Phase 9 — Preview, Fix Safe Issues, before and after

**Status:** complete · 2026-09-03 · **MVP v0.1.0**

The phase where the whole thing becomes one motion: look, decide, preview,
confirm, apply, verify, report — and undo, unprompted, if the site says no.

### What exists now

- `Meter\Meter` with all eleven v1 metrics from §12, measured on the home page,
  the newest content page and the dashboard; `PageMetrics` reads them from the
  markup the site actually served rather than from WordPress's own registries.
- `Meter\Comparison`: deltas with units and percentages, and four refusals
  (D-0025) that keep the report honest.
- `MEASURING_BEFORE` and `MEASURING_AFTER` now do something. A metering failure
  is a warning and never stops a run: somebody whose site cannot reach itself
  still gets their change, they just do not get a before-and-after.
- `GET debloater/v1/runs/<id>`: the run with its state history, its verification
  and its measurements, each state labelled in words a person can read while
  waiting.
- The preview modal: the profile, what will change, what will not, what was left
  out and why, which recovery is taken first, and "Nothing will be deleted" when
  the plan is not destructive. The button says *Create snapshot & apply*, because
  that is the order things happen in.
- The live run screen, polling and rendering each transition, then the report:
  "N optimizations applied", the score before → after, and the measured deltas
  with units. On failure: which check failed, "Rollback complete", "Previous
  configuration restored".

### Exit checklist (§17 Phase 9, §14)

| Criterion | Result |
|---|---|
| Meter v1 metrics, measured on home + content page + admin | ✅ all eleven |
| Comparison producing deltas with units | ✅ and percentages, where honest |
| `MEASURING_BEFORE` / `MEASURING_AFTER` wired into the run | ✅ in the history, and in the payload |
| Preview modal from the PreviewPlan | ✅ will change / will not / excluded / snapshot levels |
| "Nothing will be deleted" when nothing is destructive | ✅ |
| `Create snapshot & apply` confirmation | ✅ with the plan-bound token from Phase 8 |
| Live run screen polling `runs/<id>` | ✅ each transition labelled |
| Report: score before → after, deltas, "N optimizations applied" | ✅ |
| Failure report: failing probe, rollback complete, previous configuration restored | ✅ |
| Copy never claims time saved or says "faster" | ✅ asserted over the whole report JSON |
| **§14: scan reports ≥ 12 findings including ≥ 1 dont_touch** | ✅ 
| **§14: Fix Safe Issues snapshots, applies, verifies PASS, reports** | ✅ end to end through REST |
| **§14: forced probe failure rolls back and restores exactly** | ✅ runtime compared byte for byte |
| Unit suite | ✅ 979 tests, 7 200 assertions |
| Integration suite | ✅ 174 tests, 1 203 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.4 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings across 210 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |
| Tag `v0.1.0` | ✅ |

### Known warnings

- The acceptance test seeds its own "full stack" — WooCommerce and Contact Form
  7 in `active_plugins`, two authors who edited this week, revisions, expired
  transients, autoloaded rows and scheduled events — rather than running against
  the wp-env full-stack variant. The refusal it asserts is the real one from
  §17 Phase 3: Heartbeat on a store with collaborators. The stack-matrix runs
  against genuinely installed plugins belong to Phase 12.
- `frontend.*` and `admin.notices` are measured over loopback, so on a site that
  cannot reach itself they are reported as not measured. That is the correct
  behaviour and the reason rule 1 above exists.
- The score in the report comes from a fresh scan after the change. On a large
  site that is a second scan; it is the only honest way to show an "after".

### Next

Phase 10 — Database intelligence, and the first destructive operations.

---

## Phase 10 — Database intelligence

**Status:** complete · 2026-09-03

The first phase where the plugin deletes things a person would miss. Everything
in it exists to make that safe rather than fast.

### What exists now

- Five destructive operations, each with an exact round-trip test:
  `RevisionsCleanup` (keep N per post), `AutoDraftsCleanup`, `TrashCleanup`,
  `SpamCommentsCleanup`, `OrphanMetaCleanup` (post, term, user and comment
  meta).
- `AutoloadReview`: not destructive. It changes the flag that decides whether an
  option is read on every request, for option names on an allowlist the plugin
  maintains — and the `prefixes` parameter can only **narrow** that list, never
  widen it.
- Six analyzer rules, one per operation, each reading facts the scanners already
  produced in Phase 2.
- What counts as an orphan, per meta type, written down before the code existed
  (D-0026). What the Level C attestation is for, and what it deliberately does
  not buy (D-0027).
- The destructive confirmation: *Create recovery backup & delete*, with an
  optional "I have my own backup of this site" checkbox that is recorded and
  changes nothing else.

### Two safety holes found and closed while building it

**Rows could be deleted without ever being backed up.** `collect()` writes the
recovery point and `execute()` then asks the database again for what matches — so
anything that came to match *in between* would be deleted with no backup. On a
busy site that is not hypothetical: a post is trashed, a comment is marked as
spam, a plugin writes metadata a moment before creating its parent. Every
operation now records a **collection ceiling** and will not delete above it; a
ceiling of zero means nothing was collected, so nothing may be deleted.

**A rollback could report success over a data loss.** `restoreRun()` skipped any
snapshot it could not restore. A corrupt Level B therefore meant: configuration
restored, run marked rolled back, "previous configuration restored" shown to the
user — and the deleted rows still gone. It now stops with the reason, and a
failed rollback is reported as a result rather than thrown.

Neither was reachable before this phase, because until now the only data
operation deleted expired transients.

### Exit checklist (§17 Phase 10)

| Criterion | Result |
|---|---|
| `RevisionsCleanup` with `keep_per_post`, default 5 | ✅ per post, not per site |
| `AutoDraftsCleanup` | ✅ |
| `TrashCleanup` | ✅ |
| `SpamCommentsCleanup` | ✅ spam only; the moderation queue is never touched |
| `OrphanMetaCleanup` for post, term, user, comment meta | ✅ |
| "Orphan" defined per type in DECISIONS.md **before coding** | ✅ D-0026, committed on its own first |
| Native deletion functions | ✅ `wp_delete_post`, `wp_delete_post_revision`, `wp_delete_comment`, `delete_metadata_by_mid` |
| All flagged `destructive: true` | ✅ and a test asserts the set has not changed |
| §7.4 invariant extended to the shipped tweaks | ✅ no profile admits any of them, including the widest |
| `AutoloadReview` as info + allowlisted config change | ✅ report is wide, action is narrow |
| Allowlist in the registry | ✅ and a parameter can only narrow it — asserted |
| Destructive confirmation UI with Level C checkbox | ✅ |
| Level C never substitutes for Level B | ✅ D-0027, and a test that ticks the box and still expects the refusal |
| Batched execution | ✅ every operation works in bounded batches |
| **Exact round-trip per operation** | ✅ whole rows compared — ids, dates, meta, term relationships |
| **A destructive apply without a complete Level B is refused** | ✅ |
| Unit suite | ✅ 1 016 tests, 7 443 assertions |
| Integration suite | ✅ 193 tests, 1 279 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings across 227 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

### Known warnings

- Orphan cleanup refuses to run on multisite. User meta is shared across a
  network there, and "no row in this site's tables" is a different question
  (D-0026). The finding still reports what it sees.
- `ExpiredTransientsCleanup` from Phase 5 does not use the collection ceiling.
  Its rows are expired cache entries and the race costs nothing, but it is the
  one operation whose `execute()` can still touch a row that arrived after the
  recovery point. Worth aligning when it is next touched.
- The progress the Run screen shows is per state, not per batch. Batches are
  bounded so a long deletion cannot hold a request open indefinitely, but a
  count of rows processed would be better and needs a place to put it.

### Next

Phase 11 — Plugin intelligence.

---

## Phase 11 — Plugin intelligence

**Status:** complete · 2026-09-03

Four rules about the plugin list, all `info`, none of which proposes anything.
The phase's real subject is the network: it is where Debloater first has a
reason to ask somebody else a question, and most of the work went into making
sure it does not.

### What exists now

- `registry/plugin-categories.json`: forty-eight plugin slugs across the seven
  categories §17 names. A **registry table** — one file holding a lookup, as
  against one file per object — with its own schema and its own loading path
  (D-0030).
- `registry/host-optimizers.json`: optimization layers that offer settings of
  their own for ground Debloater also covers, keyed to the findings they
  overlap.
- `plugins.duplicate_functionality`: two or more active plugins doing the same
  kind of job, named, with what doubling up on that particular kind costs. It
  proposes nothing and there is a test that it cannot start to.
- `plugins.abandoned`: active plugins with no sign of life in two years. Two
  readings, worded and scored differently — see below.
- `plugins.host_optimizer_detected`: which other optimizers are on the site.
- `WpOrgUpdates`: the release-date lookup, off unless this scan was explicitly
  asked for it, with a bounded timeout, a lookup ceiling and a day's cache.
- `wp debloater scan --check-plugin-updates` and `POST /scan {check_plugin_updates}`.

### Two readings of "abandoned", and why they are not the same finding

With the opt-in, the date is the plugin's last release: a claim about the plugin,
confidence 0.9.

Without it — the default — the date is when the plugin's main file last changed
on this server. That is a narrower question and it is wrong in both directions. A
site moved by copying files has every modification time reset to the day of the
move, so a genuinely abandoned plugin looks new; and a plugin whose author has
shipped three releases the site never installed looks abandoned when what is
stale is the installation. Still worth knowing, so it is reported — in different
words, as a statement about this server rather than about the plugin, at
confidence 0.35.

`plugins.update_source` records which reading produced the figure, so the
distinction survives into the evidence rather than living only in a comment.

### The thing this phase got wrong first

§17 asks for findings that overlap a host optimizer to be marked `info` with the
reason "already handled by host". Building it showed the reason is false exactly
when it would be shown: a finding fires because the scan *observed* the thing
happening, so if the other tool had handled it there would be no finding to
downgrade. Marking a real cost as `info` would also understate what the site is
paying.

So the intent is kept and the wording is not. A finding on shared ground gains a
sentence naming the other tool and where its setting lives; it keeps its
severity, its decision and its recommendation, and the choice stays with the
person who now knows there are two places to make it. Recorded as D-0028.

### Exit checklist (§17 Phase 11)

| Criterion | Result |
|---|---|
| `registry/plugin-categories.json` with cache, seo, security, image, forms, backup, analytics | ✅ 48 slugs |
| `plugins.inactive_present` | ✅ shipped in Phase 4, unchanged |
| `plugins.duplicate_functionality`, info, lists the overlap, never disables | ✅ |
| `plugins.abandoned` using wp.org only on opt-in | ✅ |
| Fallback mtime heuristic, low confidence, said so in evidence | ✅ 0.35, and worded as a different claim |
| `plugins.host_optimizer_detected` | ✅ |
| Overlapping findings marked `info` "already handled by host" | ⚠️ **deliberately not** — the claim is false when shown; the intent is met with an added sentence instead (D-0028) |
| All info, no automatic action | ✅ asserted for all three new rules |
| `PluginScanner` facts and the fact schema extended | ✅ `plugins.categories`, `plugins.update_source`, `plugins.host_optimizers`, `plugins.meta[*].file_mtime` |
| **Two SEO and two cache plugins produce duplicate findings** | ✅ |
| **Abandoned detection works with network disabled** | ✅ |
| **No HTTP request during a scan when opt-in is off** | ✅ asserted by counting `pre_http_request`, over both the scan and the analyze |
| Unit suite | ✅ 1 053 tests, 7 607 assertions |
| Integration suite | ✅ 201 tests, 1 332 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| CLI end-to-end | ✅ against the real `wp` binary |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings across 238 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

### Known warnings

- The `covers` lists are two entries long, both emoji removal, because those are
  the two settings whose existence in another product's interface can be pointed
  at with confidence. A longer list would be more useful and less true. Growing
  it is a research task, not a coding one.
- The signal for SiteGround is the constant SG Optimizer defines, so the entry
  fires when that plugin is present rather than when the site is on SiteGround.
  For this purpose that is the better signal; for a host with no plugin
  (WP Engine, Kinsta) there is currently no entry at all, and no claim.
- The wordpress.org path is exercised against a fixture response, never the real
  endpoint. No test in this repository makes an outbound request, and none should
  — but it does mean the shape of a real answer is asserted from documentation
  rather than from observation.
- `ExpiredTransientsCleanup` from Phase 5 still does not use the collection
  ceiling (carried from Phase 10).

### Next

Phase 12 — Admin intelligence.

---

## Phase 12 — Admin intelligence

**Status:** complete · 2026-09-03

The first phase about the people who run the site rather than the people who
visit it.

### What exists now

- **AdminScanner v2.** Notices, dashboard widgets, admin menu items and the
  scripts and styles on our own screen, each attributed to whoever registered
  it. Attribution is by asking the callable where its code lives; what cannot be
  established comes back as `unknown` rather than as a guess.
- **Five config tweaks**, all admin-only, all reversible:
  `admin.remove_dashboard_widgets` (per-widget), `admin.remove_welcome_panel`,
  `admin.remove_wp_news_widget`, `admin.hide_update_nags_non_admins`,
  `admin.suppress_promo_notices`.
- **Five rules**, four recommending and one — the crowded dashboard — reporting
  only, because which widgets are worth keeping is a question about the person.
- `registry/admin-notices.json`, the vendor allowlist, and SCORING.md **v2**
  with Admin as the sixth sub-score.

### The tweak this phase argued with

§17 asks for `admin.suppress_promo_notices` driven by an allowlist of
promotional notice hooks. There are no such hooks. WooCommerce sends an upsell
and "your database needs updating" down the same channel; Yoast routes nearly
everything through one notification centre. A feature built as though marketing
were separable would hide a database-update warning and call it an advert.

So the mechanism is source-based — a callback is removed only when its code
lives inside a plugin directory the user selected — and everything a person
reads says what it actually does: the title is "Hide admin notices from plugins
you choose", the `breaks` list names the warnings that go with it, and the risk
is medium, which keeps it out of "Fix Safe Issues". Recorded as D-0031.

### The dashboard finding that proposes nothing

`admin.remove_dashboard_widgets` exists, works, and is never recommended. The
tweak takes a list of widget ids and the entire question is which ones — an
answer that depends on what a person reads every morning. The widget that looks
most obviously removable from here is sometimes the first thing somebody checks.
So the finding reports what is on the dashboard and who put it there, the
selection screen offers the change with the list ready, and nothing is
preselected.

### Exit checklist (§17 Phase 12)

| Criterion | Result |
|---|---|
| Per-source notices | ✅ |
| Per-source dashboard widgets | ✅ |
| Per-source admin menu items | ✅ via the page's own hook name; `unknown` where there is no callback to reflect |
| Admin script/style counts on our screen load | ✅ with sources |
| `admin.remove_dashboard_widgets` with a per-widget parameter | ✅ |
| `admin.hide_update_nags_non_admins` | ✅ and whoever can update always sees it |
| `admin.remove_welcome_panel` | ✅ |
| `admin.remove_wp_news_widget` | ✅ |
| `admin.suppress_promo_notices` from a registry allowlist | ✅ woocommerce, elementor, yoast, rank-math, jetpack — with D-0031 on what it can and cannot claim |
| Admin sub-score, SCORING.md v2 with a changelog entry | ✅ rubric 2.0 |
| Admin findings carry evidence | ✅ |
| Tweaks reversible | ✅ every one asserted to add no hook it does not remove, and remove none it does not put back |
| Verification passes with every admin tweak applied | ✅ |
| **The plugin emits zero admin notices** | ✅ asserted against its own scanner output |
| Unit suite | ✅ 1 087 tests, 7 837 assertions |
| Integration suite | ✅ 214 tests, 1 438 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| CLI end-to-end | ✅ |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings across 252 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

### Known warnings

- No admin tweak is in any profile, including the four that are `safe`. Changing
  what somebody else sees when they log in should be chosen rather than swept up
  by one click (D-0032).
- Menu-item attribution goes through the page's registered callback. A menu
  entry that links to an existing file rather than registering a page has no
  callback to reflect on and reports `unknown`. That is correct and it is also
  a real gap in coverage.
- The scripts and styles recorded are the ones on the screen the scan ran from,
  which is one screen, not the admin as a whole. The fact says so; a rule that
  read it as a site-wide figure would be wrong.
- `ExpiredTransientsCleanup` from Phase 5 still does not use the collection
  ceiling (carried from Phase 10).

### Next

Phase 13 — Asset intelligence (detection only).

---

## Phase 13 — Asset intelligence (detection only)

**Status:** complete · 2026-09-03

The first scanner that fetches anything, and the first phase whose whole output
is a description with no proposal attached to it.

### What exists now

- **`AssetScanner`.** Fetches the home page plus the most recent entry of each
  public post type, up to ten URLs, five seconds each, over loopback. Parses the
  scripts and stylesheets out of the returned HTML, attributes each to a plugin,
  theme or core by the file it is served from, reads sizes off the disk for
  local files, counts external hosts and notices Google Fonts.
- **`AssetParser`.** Reads assets back out of rendered HTML rather than from
  `wp_scripts()->queue`, because the queue answers a different question: what
  *would* be enqueued on a request shaped like the one we are already in, which
  for a scan is an admin request.
- **`PageSample`.** Chooses the URLs, and is the reason `assets.pages_sampled`
  exists.
- **`Sources`** — Phase 12's `AdminSources`, renamed, because attributing a file
  to its owner was never an admin-specific job. It now answers for URLs too.
- **`assets.cf7.everywhere`**, info, the finding §17 names.

### Nothing here proposes anything

No unloading tweaks, and Assets is still not a sub-score. §17 requires both, and
building it produced a second reason: this reads a **sample**. "No form on the
four pages we looked at" is not "no form anywhere", and a change made on that
basis would break the contact page of any site whose contact page was not in the
sample. Recorded as D-0033.

The Contact Form 7 finding is therefore worded "Of N pages sampled…", capped at
0.75 confidence, and points at Contact Form 7's own `WPCF7_LOAD_JS` constant —
which is a better place to change this than anything Debloater could hook
around it.

### An assertion that was passing for the wrong reason

Phase 11's tests asserted a scan makes **zero** HTTP requests. §13 rule 9 has
always allowed loopback; the zero-request assertion was only equivalent because
nothing had needed loopback yet. Four of them failed on a change entirely within
the rule they were meant to defend. They now assert what the promise actually is
— nothing leaves this server — which is stricter, not weaker (D-0034).

### Exit checklist (§17 Phase 13)

| Criterion | Result |
|---|---|
| Fetch home plus one URL per public post type, max 10, loopback, 5 s each | ✅ |
| Parse script and style handles from the HTML | ✅ including assets printed by hand, which have no handle and are kept rather than dropped |
| Attribute each to plugin, theme or core by source path | ✅ |
| Record byte sizes when available | ✅ read off the disk for local files; `null` for anything on another host, because knowing would need a request nobody asked for |
| Count external hosts | ✅ |
| Detect Google Fonts | ✅ |
| New `assets.*` fact namespace, fact schema extended | ✅ |
| CF7 page-level usage detection | ✅ read from rendered markup, so a shortcode, a block and a page builder all count the same way |
| Finding "CF7 assets loaded on N pages, forms on M", info, with evidence | ✅ |
| **No unloading tweaks in this phase** | ✅ and the registry has none |
| **Attribution accuracy ≥ 95% on the fixture** | ✅ 100% on a nineteen-asset stack |
| **Scan under 10 s** | ⚠️ measured with pages served from fixtures — see below |
| **No network requests beyond loopback** | ✅ asserted per request URL |
| Unit suite | ✅ 1 099 tests, 7 925 assertions |
| Integration suite | ✅ 223 tests, 1 473 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| CLI end-to-end | ✅ |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings across 258 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

### Known warnings

- **The ten-second criterion is measured against fixtures, not the network.**
  wp-env runs the test runner and the web server in separate containers, so the
  site's canonical URL does not resolve to the site from where the tests execute
  and real loopback is impossible here — the same environment limitation
  recorded for Phase 6. What the timing test actually measures is parsing and
  attribution, which is the part this code controls. The network cost is bounded
  by design instead: five seconds per page, eight seconds across the whole asset
  scan, and one loopback check before any of it.
- Attribution cannot see through a CDN. An asset served from a plugin's files
  via a rewriting cache plugin looks like an external host, because from the
  URL that is exactly what it is.
- Sizes are read from disk, so a file served compressed is reported at its
  uncompressed size. That is the honest number for "how much is on the disk" and
  the wrong one for "how much crosses the network"; nothing yet claims the
  latter.
- `ExpiredTransientsCleanup` from Phase 5 still does not use the collection
  ceiling (carried from Phase 10).

### Next

Phase 14 — Elementor intelligence.

---

## Phase 14 — Elementor intelligence

**Status:** complete · 2026-09-03

The phase where the honest number and the impressive number are furthest apart.

### What exists now

- **`ElementorScanner`.** Which widget types are registered and which plugin
  defines each; which types the saved `_elementor_data` actually names; how many
  documents and library templates exist; which font families the designs refer
  to; which experiments are explicitly on or off.
- **`WidgetCatalog`** — an interface with a `LiveWidgetCatalog` that is the only
  file in Debloater naming an Elementor class. Everything else reads the
  database and needs no third-party code loaded at all.
- **`elementor.widgets.audit`**, info: "*N* addon packs, *N* widgets available,
  *N* detected in use, *N* **potentially** unused".
- **`elementor.disable_google_fonts`**, medium risk, using Elementor's own
  supported filter.

### What the audit will not say

The word is "potentially", and a test asserts that "unused" never appears
without it. Four things put a widget on a page without naming it in the saved
design — a dynamic tag, a shortcode widget, a theme-builder template, a custom
code block — so each is recorded as a fact and each takes 0.15 off the finding's
confidence, down to a floor of 0.3.

Nothing disables a widget. Elementor has no supported way to unregister another
plugin's widget type, and doing it unsupported loses the content on every page
already built with one (D-0037).

### Attribution without a list

§17 asks for addon packs to be detected from registry detectors. There are
hundreds of Elementor addon packs and new ones monthly; a list would cover a
dozen and silently misattribute the rest. Instead a widget belongs to whichever
plugin directory its class lives in, through the `Sources` helper Phases 12 and
13 already needed. This covers every addon that exists and needs no maintenance
(D-0036).

### Exit checklist (§17 Phase 14)

| Criterion | Result |
|---|---|
| Detect Elementor, Pro and addons | ✅ Elementor and Pro from detectors; addons by reflection rather than a list (D-0036) |
| Enumerate registered widgets per addon | ✅ through the `WidgetCatalog` interface |
| Scan `_elementor_data` across posts, pages, templates and popups | ✅ every row of that meta key, batched |
| Fonts (families) | ✅ read from the saved designs |
| Experiments | ✅ only those explicitly set; a default has no option row and is absent rather than guessed |
| Audit finding, info, with evidence | ✅ |
| **Wording uses "potentially"** | ✅ and a test refuses "unused" without it |
| Confidence penalised for dynamic tags, shortcodes, templates, custom code | ✅ 0.15 each, floor 0.3, and the reasoning names which ones this site has |
| Supported-filter config tweaks only, medium risk | ✅ one: `elementor/frontend/print_google_fonts` |
| **Never disable widgets automatically** | ✅ the audit has no recommendation and cannot acquire one |
| **Audit reproducible on fixture** | ✅ exact counts on a three-pack fixture |
| Tweak reversible, verification passes | ✅ adds no hook it does not remove |
| Unit suite | ✅ 1 120 tests, 11 029 assertions |
| Integration suite | ✅ 230 tests, 1 498 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| CLI end-to-end | ✅ |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings across 268 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

### Known warnings

- **Elementor is not installed in the test environment.** `.wp-env.json` puts it
  in the development environment only, so the widget catalogue comes from a
  fake. That is the seam the interface exists for, and the safety rules ask for
  third-party integrations to be built behind a tested adapter with fixtures —
  but it does mean `LiveWidgetCatalog` itself is exercised by nothing but its own
  guards. Running the suite against the development environment would close
  that, and is worth doing before release.
- Reading `_elementor_data` with a regular expression rather than a JSON decode
  is a deliberate trade: the documents are large and deeply nested and the only
  thing wanted is a flat list of type names. It would miss a widget type written
  with unusual whitespace inside the JSON, which Elementor does not produce.
- Fonts are read from the saved designs, not from the active kit's typography
  settings. A font set globally and never overridden in a design will not
  appear.
- `ExpiredTransientsCleanup` from Phase 5 still does not use the collection
  ceiling (carried from Phase 10).

### Architecture note

Between the start and the end of this phase, two service-architecture
instructions arrived and the second superseded the first. Neither had been
implemented; the outcome is recorded in D-0035 and committed separately as
`architecture: provider-agnostic licensing, optional Hakeemify Cloud`, ahead of
this phase's commit. No Phase 14 work was affected.

### Next

Phase 15 — WooCommerce intelligence.

---

## Phase 15 — WooCommerce intelligence

**Status:** complete · 2026-09-03

The phase where being wrong is most expensive, so most of it is about not being.

### What exists now

- **`WooCommerceScanner`.** Classifies every sampled page as shop or not-shop
  from the rendered markup, then records which non-shop pages loaded the
  cart-fragments script and the block stylesheets, and which pages show a cart.
  Also whether Analytics and the marketplace suggestions are on.
- **`SampledPages`.** The page fetch, extracted from the asset scanner so both
  scanners read the same bodies from one fetch (D-0038).
- **Four rules and four tweaks**: cart fragments conditional (medium), block
  styles conditional (medium), Analytics off (medium), marketplace suggestions
  hidden (safe).
- **Three probes** — `woo_cart`, `woo_checkout`, `woo_account` — fetching
  WooCommerce's own pages as a guest.

### Classification, and why not conditional tags

§17 says "conditional tags + shortcode/block presence". Conditional tags turned
out to be the wrong instrument: `is_cart()` answers for the request the scan is
already inside, which is an admin request, not for the page being classified.
They would have returned the same answer for every page on the site.

So classification reads the rendered page — the body classes WooCommerce adds,
its block and shortcode markers, its own asset handles. That is what a visitor
receives, which is the thing the question is actually about. Shortcode and block
presence are in there as §17 asks; the conditional tags live in the runtime
handlers, where they run inside the request being decided and are exactly right.

### The refusal this phase exists for

A mini-cart anywhere off the shop makes the cart-fragments finding `dont_touch`.
Most shop themes put a cart total in the header; there the fragments are what
keep it correct, and making them conditional leaves a number that never updates
until the visitor reloads. That is a refusal rather than a warning — there is no
"apply it and see" on a store, and a warning is something a person clicks past
(D-0039).

### Exit checklist (§17 Phase 15)

| Criterion | Result |
|---|---|
| Page classification over the sampled URLs | ✅ from rendered markup; conditional tags run in the handlers where they belong |
| Shortcode and block presence | ✅ part of the marker set |
| Mini-cart detection in headers | ✅ widget, block, cart-contents and cart menu item |
| Finding: cart fragments on non-Woo pages, with the page list | ✅ |
| Finding: Woo Admin/Analytics enabled | ✅ |
| Finding: marketplace/promo notices | ✅ |
| Finding: Woo block styles on non-Woo pages | ✅ |
| `woo.cart_fragments_conditional`, medium, `dont_touch` when a mini-cart is detected | ✅ D-0039 |
| `woo.disable_admin_analytics`, medium | ✅ through `woocommerce_admin_features` |
| `woo.suppress_marketplace_suggestions`, safe | ✅ through WooCommerce's own two filters |
| `woo.block_styles_conditional`, medium | ✅ |
| Probes `woo_cart`, `woo_checkout`, `woo_account` | ✅ as a guest, and listed on both front-end tweaks |
| **Classification ≥ 95% on fixture** | ✅ 100% on a six-page fixture |
| **Checkout probe PASS with all Woo tweaks applied** | ✅ |
| Unit suite | ✅ 1 140 tests, 11 399 assertions |
| Integration suite | ✅ 241 tests, 1 541 assertions |
| Forced-failure suite | ✅ 9 tests, 105 assertions |
| CLI end-to-end | ✅ |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings across 283 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

### Known warnings

- **WooCommerce is not installed in the test environment**, so pages come from
  fixtures and the store is simulated through `active_plugins`. What that cannot
  exercise is WooCommerce's own conditional tags inside the two dequeue
  handlers — the code that decides, per request, whether to drop an asset. The
  classification, the refusal and the probes are all exercised; the handlers'
  conditionals are covered by construction (every test that says "keep" wins)
  rather than by test. Running the suite against the development environment
  would close this, and should happen before release.
- Both dequeue handlers expose a filter — `debloater_woo_page_needs_cart` and
  `debloater_woo_page_needs_block_styles` — because a cart or a WooCommerce
  block inside a template part, a widget area or a page builder is not visible
  from the page being built. A theme that has one must say so. That is a real
  gap, documented in each tweak's `breaks` list rather than hidden.
- Analytics detection reads WooCommerce's disabled-features option and its own
  enabled flag. A store whose Analytics is off by some other means would be
  reported as having it on.
- `ExpiredTransientsCleanup` from Phase 5 still does not use the collection
  ceiling (carried from Phase 10).

### Next

Phase 16 — Headless verification (Playwright, CI only).

---

## Phase 16 — Headless verification (Playwright, CI only)

**Status:** complete · 2026-09-03

The phase that opened the plugin in a browser for the first time, and found that
it did not work.

### What it found

`Screen::bootstrapData()` handed the admin bundle `rest_url( 'debloater/v1' )`
as its API root, and the client joined `/status` onto it. On a site with **plain
permalinks — WordPress's default —** `rest_url()` returns
`…/index.php?rest_route=/debloater/v1`, and that join produces a URL matching no
route. Every screen showed *"No route was found matching the URL and request
method."*

**The plugin had been unusable on a default WordPress install since Phase 8.**
1 140 unit tests and 246 integration tests missed it, including a file of REST
route tests, because every one of them builds a `WP_REST_Request` by hand and
dispatches it. None had ever composed a URL. Fixed, and covered by `RestUrlTest`,
which composes the URL exactly as the client does and dispatches it under both
permalink structures — verified to fail on the pre-fix code before being kept
(D-0041).

That is the entire argument for this phase, made on its first run.

### What exists now

- `tests/E2E`, thirteen scenarios in four files, driving a real browser against
  the wp-env development site — the one carrying WooCommerce 11, Elementor,
  Contact Form 7, Rank Math and LiteSpeed Cache.
- `tools/seed-e2e.php`: a purchasable product, a page with a Contact Form 7
  form, a page with a saved Elementor design.
- `.github/workflows/e2e.yml`: nightly, on a pull request labelled `e2e`, and on
  demand, across PHP 8.1 and 8.3.
- `wp debloater verify --e2e`, which prints how to run the suite rather than
  pretending to run something the plugin package does not contain.

### Two fixtures that were measuring nothing

**A fresh WooCommerce is not open for business.** It ships with "coming soon"
mode on, serving every visitor a launch page. The checkout scenario originally
asserted the cart page did *not* say "your cart is currently empty" — which was
also true of the launch placeholder. It passed while checking nothing. The
assertion is now positive: the product must be named on the page.

**A block theme has no `form.cart`.** The fixture site runs Twenty
Twenty-Five, where add-to-cart is a block. The scenario now uses WooCommerce's
own `?add-to-cart=` URL, so it tests the cart and the checkout rather than the
theme's choice of element (D-0042).

### Exit checklist (§17 Phase 16)

| Criterion | Result |
|---|---|
| Playwright suite under `tests/E2E` | ✅ 13 scenarios |
| Dashboard loads with a real scan | ✅ |
| Fix Safe Issues completes with a report | ✅ preview, recovery, apply, report |
| Forced probe failure shows the rollback report | ✅ and names the probe that failed |
| …and the prior runtime hash is restored | ✅ asserted exactly, plus the lock released |
| Woo: add to cart → reach checkout, all Woo tweaks applied | ✅ |
| Contact Form 7 submit | ✅ the form reports what happened |
| Elementor editor opens | ✅ |
| Runs nightly and on an "e2e" PR label | ✅ workflow added |
| Nothing ships; no infrastructure introduced | ✅ dev-only directory, git-ignored artefacts |
| `wp debloater verify --e2e` stub | ✅ |
| **Local E2E run** | ✅ 13 of 13 |
| Unit suite | ✅ 1 140 tests, 11 399 assertions |
| Integration suite | ✅ 246 tests, 1 561 assertions |
| Forced-failure suite | ✅ 9 tests, 105 assertions |
| CLI end-to-end | ✅ |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings across 284 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |
| **Nightly workflow green on the full stack matrix** | ⚠️ **not verified** — see below |

### Known warnings

- **The nightly workflow has never run.** It cannot: running it means running
  GitHub Actions on the account this repository belongs to, which is an external
  action and not mine to take. The workflow is written, its syntax is valid, and
  every step in it has been run by hand locally except `actions/*`. Whether it
  is green on the matrix is unknown until somebody runs it, and this checklist
  says so rather than assuming.
- Applies in wp-env always return exit **3**, "applied but not verified",
  because the site cannot reach itself over HTTP (D-0009, and the same
  limitation Phase 6 records). The suite lists that code as acceptable for an
  apply, explicitly, rather than ignoring failures. It also means the E2E run
  never exercises a *verified* commit — only the CI matrix on a normal host will.
- Each `wp-env run` costs about twenty seconds before WordPress boots, so the
  CLI-driven scenarios carry raised timeouts. The suite takes roughly fifteen
  minutes locally.
- §14 also asks for unit, integration and static checks on every push. There is
  still no push workflow — only this nightly one. That belongs to Phase 18,
  release hardening, and is recorded here so it is not forgotten.
- `ExpiredTransientsCleanup` from Phase 5 still does not use the collection
  ceiling (carried from Phase 10).

### Next

Phase 17 — Registry ecosystem.

---

## Phase 17 — Registry ecosystem

**Status:** complete · 2026-09-03

### What exists now

- **`registry/manifest.json`** — 58 files with their SHA-256s, and the tag the
  plugin is pinned to. Generated from the files themselves by
  `tools/registry-manifest.php`, so the tag and the contents cannot drift apart.
- **`src/Update/`** — `Manifest`, `SignatureVerifier`, `RegistryOrigin`,
  `RegistryUpdater`, `UpdateCheck`. Ed25519 over the canonical manifest, one
  pinned HTTPS origin, and a refusal at every step (D-0043).
- **`wp debloater registry`** — what this build carries; `--check-updates` asks
  whether a newer signed release exists, and is the only thing that leaves the
  server.
- **`docs/REGISTRY.md`** — the authoring guide, a PR checklist, and a "new
  WordPress release" checklist.
- **`.github/workflows/registry.yml`** — JSON validity, manifest agreement,
  schemas and loader, static analysis, and the plugin's integration suite run
  against the registry.

### Fail closed, on purpose

No signing key exists yet, so `SignatureVerifier::PUBLIC_KEY_HEX` is empty — and
while it is empty **every update check refuses**:

```
$ wp debloater registry --check-updates
No registry signing key is pinned in this build, so there is nothing to check a
signed update against.
```

That is the correct behaviour rather than a placeholder. A test asserts the
constant is empty, so pinning a real key later is a deliberate change to a test
rather than a quiet edit to a constant.

### An invariant that produced a better design

The update code was first written under `src/Registry/Update/` and failed the
Phase 0 rule that `src/Registry` must not call WordPress. The tempting fix was to
loosen the invariant for one file. The invariant was right: `src/Registry` is
*what the registry is*, and fetching a newer one over HTTP is a different
concern. It moved to `src/Update/` (D-0044).

### Exit checklist (§17 Phase 17)

| Criterion | Result |
|---|---|
| `registry/` laid out as a standalone repository | ✅ becomes that repository's root unchanged |
| CI for it: JSON → schema → compat tests → WP matrix | ✅ `registry.yml` |
| Plugin vendors a pinned snapshot with its tag in a manifest | ✅ v0.1.0, 58 files |
| Opt-in update check | ✅ off by default; off means no request, asserted |
| Ed25519 signature over a canonical manifest with sha256 per file | ✅ |
| Every file hash verified before activation | ✅ and one bad hash rejects the whole release |
| JSON only, never executable | ✅ refused by path *and* by parse; handlers stay in the plugin |
| `docs/REGISTRY.md` with PR template and "new WP release" checklist | ✅ |
| **Plugin builds from the pinned tag** | ✅ `wp debloater registry` reports v0.1.0 |
| **Bad hash / invalid signature rejected** | ✅ both, plus wrong product, unknown format, path escape, non-JSON |
| **No network unless opt-in** | ✅ asserted by counting requests |
| Remote publication not required | ✅ prepared, not published (D-0045) |
| Unit suite | ✅ 1 157 tests, 11 707 assertions |
| Integration suite | ✅ 256 tests, 1 585 assertions |
| Forced-failure suite | ✅ 9 tests, 105 assertions |
| CLI end-to-end | ✅ |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings across 291 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

### Known warnings

- **The registry repository does not exist.** Creating a public repository is an
  external act needing a person's decision and credentials, and it publishes
  something that cannot be unpublished. The layout, tooling and CI are ready;
  the publishing is not done, which §17 explicitly allows (D-0045).
- **No release has ever been signed**, because no signing key exists. The
  verification path is fully implemented and fully tested against runtime
  keypairs; what has not happened is a real release. Pinning the public key is a
  release-time step.
- `RegistryUpdater` stages a verified release; it does not activate one.
  Swapping the live registry is a separate act and belongs with the apply
  machinery, not with the download.
- There is still no push-CI workflow for the plugin's own suites (§14). Carried
  from Phase 16; it belongs to Phase 18.
- `ExpiredTransientsCleanup` from Phase 5 still does not use the collection
  ceiling (carried from Phase 10).

### Next

Phase 18 — WordPress.org release hardening.

---

## CI — the workflows have now actually run

**Status:** green · 2026-09-03 · resolves the "not verified" warnings on
Phases 16 and 17

Both phases shipped a workflow and recorded the same known warning: *the
nightly workflow has never run, and running it is an external action*. The
Phase 17 push triggered the registry workflow by itself, it failed, and the
first real runs found four things that no amount of local testing would have.

| Run | Failure | Cause |
|---|---|---|
| Registry #1 | `Could not open input file: tools/phpunit-9.phar` | The PHPUnit 9 phar is git-ignored and downloaded by `npm run test:integration:setup`. The workflow never ran it. |
| Registry #2 | "The PHPUnit Polyfills library is a requirement" | The job installed npm dependencies but never ran `composer install`, and the plugin directory is mapped into the container as-is — so a missing `vendor/` on the runner is a missing one in the container. |
| E2E #1 | 10 of 13 failed: `'debloat' is not a registered wp command` | wp-env activates the plugins listed in `.wp-env.json`; Debloater is *mapped* in rather than listed, so a fresh runner has it installed and inactive. It only looked active locally because an earlier session activated it and the database persisted. |
| E2E #2 | 2 of 13 failed on PHP 8.1: login timed out | See below. |

### The one that was a real bug in the harness

`login()` asked for `wp-login.php` and looked for a form. WordPress renders that
page perfectly happily for somebody who is already signed in — so the check
answered *"is there a login form"* rather than *"am I signed in"*, and the
helper did a full form login on **every** call, over the stored session that
had just been added to make it unnecessary.

It now asks for an admin page and reads the redirect, which is the question it
was always trying to ask. On the slower PHP 8.1 runner that difference was what
pushed two logins past sixty seconds.

Also fixed on the way: `actions/checkout`, `setup-node` and `upload-artifact`
were on versions still running Node 20, which every job warned about.

### Where it stands

| Workflow | Result |
|---|---|
| Registry — `registry (8.1, 8.2, 8.3)` | ✅ JSON validity, manifest agreement, unit suite, PHPCS, PHPStan |
| Registry — `against-wordpress` | ✅ full integration suite on a clean runner |
| End-to-end — `e2e (8.1)` | ✅ 13 of 13 |
| End-to-end — `e2e (8.3)` | ✅ 13 of 13 |

The Phase 16 exit criterion "nightly E2E green on the full stack matrix" is now
**met and observed** rather than assumed, and the same warning on Phase 17 is
withdrawn.

### What this says about the local suites

Nothing they were testing was wrong — 1 157 unit, 256 integration and 13
browser scenarios all passed locally throughout. What they could not see was
everything about the *machine*: a phar that happened to be on disk, a `vendor/`
that happened to be installed, a plugin that happened to be active from an
earlier session. Every one of those is a thing a new contributor would also hit
on their first checkout.

The one still outstanding: §14 asks for unit, integration and static checks on
**every push**, and there is still no workflow for that — only the registry one,
which fires on `registry/**`. It belongs to Phase 18.

---

## Phase 18a – rename to Debloater

**Status:** complete · 2026-09-03 · rename only, no behaviour changed

### Why

Preparing the wordpress.org submission found that the name could not be used.
wordpress.org treats **"wp" as a restricted term** and refuses a plugin name or
slug carrying it, so `WP Debloat` / `wp-debloat` was never submittable. Plugin
Check enforces this, and so does the Readme Validator.

`debloat` on its own is taken by an unrelated unused-CSS plugin. The search
demand in this category is on the word *bloat*, and "WordPress" is not allowed
in a slug – so the word goes in the subtitle instead.

Full decision: `docs/DECISIONS.md` D-0047. Full token mapping:
`docs/RENAME-MAP.md`.

### What changed

| | Old | New |
|---|---|---|
| Display name | WP Debloat | Debloater |
| Full title | – | Debloater – Scan, Fix & Undo WordPress Bloat |
| Slug / text domain | `wp-debloat` | `debloater` |
| Namespace | `WPDebloat\` | `Debloater\` |
| Constants | `WPDEBLOAT_` | `DEBLOATER_` |
| Prefix | `wpdebloat_` | `debloater_` |
| Tables | `{prefix}wpdebloat_*` | `{prefix}debloater_*` |
| Capability | `wpdebloat_manage` | `debloater_manage` |
| REST | `wpdebloat/v1` | `debloater/v1` |
| WP-CLI | `wp debloat` | `wp debloater` |
| Generated runtime | `wp-content/wpdebloat/` | `wp-content/debloater/` |
| Loader | `wp-debloat-loader.php` | `debloater-loader.php` |

A **full identifier rename**, not a display-only one, and no migration was
written because there is nothing to migrate: zero production installs.
`Storage\Schema` was edited directly. That window closes at the first release,
which is the argument for doing it now.

Not renamed: tweak ids (registry data, not brand), the Debloat Score (§1 locked
decision), and `docs/DECISIONS.md` history.

### Verification

| Check | Result |
|---|---|
| Residual `wpdebloat` / `wp-debloat` / `WPDebloat` / `wp debloat` | 0, outside `DECISIONS.md` and `RENAME-MAP.md` |
| Unit suite | OK 1 170 tests, 11 836 assertions |
| Integration suite | OK 274 tests, 4 459 assertions |
| Fail-probe rollback round trip | OK 9 tests, 105 assertions |
| WP-CLI end to end (`wp debloater`) | OK whole loop on the fixture site |
| Runtime overhead, new paths | OK part of the integration suite |
| PHPCS | OK clean |
| PHPStan level 6 | OK no errors |
| ESLint + JS unit | OK clean, 12 tests |
| `npm run plugin-zip` | OK `debloater-0.1.0.zip`, 309 files, 504 KB |
| Plugin Check: restricted term in **slug** | OK none |
| Plugin Check: restricted term in **short name** | OK none |
| Plugin Check: text domain matches slug | OK |

### One finding that needs a person

Plugin Check **does** object to the full display title:

> The plugin name includes a restricted term. Your chosen plugin name –
> "Debloater – Scan, Fix & Undo WordPress Bloat" – contains the restricted
> term "wordpress" which cannot be used at all in your plugin name.

The brief for this phase stated that "WordPress" is permitted in a display name
and forbidden only in a slug. Plugin Check disagrees, and it is the tool
wordpress.org runs at review.

It is a **warning**, not an error, and `Debloater` and `debloater` are both
clean on their own. The title was chosen deliberately for search, so it has been
implemented exactly as specified and this is recorded rather than quietly
changed. Whether to keep it, or fall back to something like
`Debloater – Scan, Fix & Undo Site Bloat`, is a naming decision rather than an
implementation one.

---

## Phase 18 — wordpress.org release hardening

**Status:** complete except one naming decision · 2026-09-04

### What §17 asked for, and where it landed

| Task | Result |
|---|---|
| A test for every §13 rule | Done — `tests/Integration/SecurityRulesTest.php`, one test named per rule number, 15 of 15 |
| §14 performance assertions | Done — the three that were missing added to `RuntimeOverheadTest`: no queries on a front-end request **with** a selection, the runtime reads no registry JSON, and a parse-time budget |
| `readme.txt` | Done, with the screenshot list, FAQ, and requirements that must agree with the header |
| POT file and i18n audit | Done — `languages/debloater.pot`, 517 strings; a test asserts every `__()` uses this plugin's domain and only it |
| `uninstall.php` per §13 rule 10 | Done — runtime and loader always; tables and options only on opt-in, and the two halves are separate functions so the unconditional one cannot be made conditional by accident |
| GPL headers | Done — asserted across header, readme, `composer.json`, `package.json`, `LICENSE` and the mu-loader |
| CHANGELOG | Done |
| Public name and slug via `Brand` | Done — D-0047, and `ReleaseReadinessTest` holds every machine-readable identifier to a single definition |
| `npm run plugin-zip` | Done — `debloater-0.1.0.zip`, 301 files, 520 KB, built from an allow-list |
| Plugin Check clean | **407 findings to 2 warnings, 0 errors.** The two are the title decision below |
| §14 every-push CI | Done — `.github/workflows/ci.yml`, the warning Phases 16 and 17 both carried forward |

### What Plugin Check actually found

Three real defects, none of which any existing test would have caught. Full
reasoning in `docs/DECISIONS.md` D-0048.

**The REST layer had no exception handling at all.** No `try`, no `catch`,
anywhere in `src/Rest/`. WordPress does not catch exceptions from a route
callback, so every engine throw — and the engine throws on purpose, because
every contract refuses rather than coerces — was a PHP fatal on a REST request:
an empty body to a dashboard waiting for JSON, and a stack trace on any site
with `display_errors` on. There is now one boundary, in `Controller::guard()`,
for the same reason there is one permission callback.

**Crash recovery could lock somebody out of the admin.** `recoverOnBoot()` runs
on `admin_init`, on every admin page load, and it runs precisely when the last
request did not finish — so it reads the state least likely to be well-formed. A
throw there would have made every admin page fatal, over a run that was already
broken before anybody arrived. It now swallows: the run stays visibly
interrupted, and the next page load retries, because recovery is idempotent.

**A syntax check that did nothing where it mattered most.** `RuntimeWriter`
shelled out to `php -l` through `proc_open()` as a second opinion on generated
code. `token_get_all( $source, TOKEN_PARSE )`, one line above, already runs the
real parser. And `proc_open` is disabled on much shared hosting — so on exactly
the hosts where a corrupt runtime is hardest to recover from, the safety net had
been quietly absent. Removed rather than annotated.

Also fixed, all genuine: table names now go through `prepare()` with `%i`
(WordPress 6.2+, and this plugin requires 6.5) at twenty call sites; `.gitkeep`
files were shipping inside the zip; `composer.json` was missing beside a shipped
`vendor/`; and `Tested up to` said 6.8 while the suite runs against 7.1.

### The one thing still open

Plugin Check warns that the display title
**"Debloater – Scan, Fix & Undo WordPress Bloat"** contains the restricted term
"wordpress", which it says "cannot be used at all in your plugin name".

`Debloater` and `debloater` are both clean — the rename did what it was for.
Only the tagline draws this. It is a warning rather than an error, the title was
chosen deliberately for search, and changing it is a naming decision rather than
an implementation one. So it stands, and is recorded here rather than quietly
altered.

Dropping "WordPress" from `Brand::TAGLINE` clears it, and is a one-line change.

### Not done, and honestly so

**Screenshot images.** `readme.txt` lists three, numbered as wordpress.org
expects. The PNGs themselves are release assets that live in the wordpress.org
SVN `/assets` directory rather than in the plugin, and uploading them is part of
a submission this build does not perform.

---

## Phase 19 — Pro

**Status:** complete · 2026-09-04

### Goal

A separate `debloater-pro` plugin, extending the free plugin through documented
hooks only, adding workflow and never safety.

### Exit criteria

| Criterion | Result |
|---|---|
| Free plugin fully functional without Pro | Done — 1 185 unit and 309 integration tests run with Pro inactive |
| ...without a licensing platform | Done — the suite asserts `debloater_fs` does not exist, and the adapter treats that as an ordinary answer |
| ...without cloud access | Done — the cloud is off unless a wp-config constant turns it on, and a test asserts a disabled client makes no request at all |
| Pro adds no tweaks | Done — registry ids identical before and after `Pro::boot()`, and the generated runtime is byte-identical |
| Pro adds no safety features | Done — no `RuntimeWriter`, `Compiler` or `SnapshotManager` reachable from `pro/`, asserted by grep |
| No Freemius symbol outside its adapter | Done — the SDK's entry function, its class and `is_paying` appear in exactly one file |
| No cloud host outside the endpoint resolver | Done — `hakeemify.com` appears in `EndpointResolver` and nowhere else |
| A missing or invalid entitlement fails safe | Done — every failure path arrives at `Entitlement::none()`, and bulk apply returns null having changed nothing |
| A cloud outage degrades Pro and changes nothing | Done — a site fingerprint is identical across an outage, and drift still works |
| No remote PHP or JS executes | Done — the client returns decoded data; there is no `eval`, no variable include, no remote enqueue |
| Drift reports added and resolved findings | Done — and reversing the comparison turns one into the other |

### What Pro is

Five things, and every one of them is workflow:

- **Scheduled scans.** A scan reads; it writes a run row and changes nothing.
  That is what makes it safe unattended, and it is the only unattended thing in
  Pro. **Applying on a schedule is deliberately not offered** — an unattended
  change with nobody watching the verification is the thing this whole plugin is
  arranged to avoid.
- **Drift detection.** What appeared, what resolved, and what moved between two
  scans. The third is the interesting one: a `dont_touch` that is no longer
  warranted, or a low-severity finding that has become a high one.
- **A white-label before/after report.** Print-CSS HTML, no PDF library
  (D-0049). Measured deltas only; where nothing was measured it says so.
- **Bulk apply of a saved profile.** The same `preview()` and `apply()` the
  dashboard calls, with the same confirmation token check — it removes clicking,
  not safety.
- **A priority registry channel.** A different HTTPS base, and the same
  signature verification, the same traversal checks, the same ceilings.

Plus multisite groundwork behind `DEBLOATER_PRO_MULTISITE`: network defaults for
selection and intent profile that a site may override, and nothing that applies
anything to any site.

### What Pro is not

It does not make a site safer, and it cannot make one less safe. Recovery
points, verification, automatic rollback, risk rules and the refusal to delete
without a backup are in the free plugin, and a test asserts Pro does not reach
them.

### Two things a person still has to do

- **Freemius is not configured.** The adapter is written and tested against its
  absence, which is also what a free install looks like. Wiring a real account
  is an external act with real credentials, outside this build's boundary.
- **`cloud.hakeemify.com` does not exist.** The client, resolver and response
  types are written and tested; no infrastructure was created. The cloud is
  optional by construction, so nothing waits on it.

---

## Phase 20 — Agency / Cloud design

**Status:** complete · 2026-09-04 · design only, nothing deployed

### Exit criteria

| Criterion | Result |
|---|---|
| `docs/CLOUD-DESIGN.md` produced | Done — 14 sections |
| Multi-site dashboard requirements | Done — R1–R6 |
| Push-only reporting via signed site keys | Done — §2, §4 |
| Data minimisation and retention | Done — §3, §9 |
| Reuses the existing run/report JSON | Done — §3, `Run::toArray()` unchanged |
| Auth model | Done — §4, Ed25519 site keys, magic-link humans |
| Cost at zero and low scale | Done — §10, from measured payload sizes |
| One-host routing and isolation | Done — §5 |
| Licensing / cloud boundary | Done — §6 |
| Secrets and signing-key ownership | Done — §7 |
| Key rotation | Done — §8, including the compromise procedure |
| Backups and monitoring | Done — §9 |
| Migration to a standalone service | Done — §11 |
| Explicit deferrals | Done — §12, eleven items with reasons |
| Nothing deployed | **Confirmed.** No infrastructure, no accounts, no code. `cloud.hakeemify.com` does not resolve. |

### The decision the document is built around

**v1 accepts reports and sends no commands. There is no inbound control path.**

Debloater edits people's sites, and the argument that this is safe rests on a
person having asked for the change and being there when verification runs. A
control channel would mean a change originating where the site owner is not,
watched by nobody, across thirty sites at once — a materially different product
with a materially different risk.

The concrete consequence is already in the shipped code: `CloudServiceClient`
has one method, `get()`, returning decoded data with no path to anything
executable. A compromised Hakeemify Cloud can put wrong numbers on a dashboard.
It cannot touch a site.

Agencies will ask for remote apply anyway, so the document says what the answer
is rather than leaving it to be improvised: **the site initiates, always** — an
agency stages an *intent*, the site collects it on its own schedule, plans,
snapshots, verifies and rolls back locally, and may refuse. That inverts the
trust direction and keeps every safety property where it already works. It is
deferred, and §12 says explicitly that it must not be retrofitted by adding a
command endpoint to v1.

### Two things the codebase settled rather than the design

**Data minimisation is mostly already true.** A full fact set from the Phase 9
fixture — 50 facts across WooCommerce, Contact Form 7 and LiteSpeed — contains
**zero absolute URLs**, because `Scan\Sources` reduces every asset URL to a
source label before it becomes a fact. That was a consequence of invariant 1,
not a privacy measure, and it means the transmitted payload needs subtraction
rather than redesign.

**The cost model is measured, not estimated.** A real scan produces a 30.1 KB
run payload — 12.1 KB of facts, 17.4 KB of analysis across 16 findings. At
5 000 sites reporting weekly that is under 1 GB a month and well under one
request per second, which is what justifies the deliberately boring
infrastructure: managed Postgres, containers that scale to zero, and no
Kubernetes, queue broker or data warehouse.

The first draft said 40 KB from estimation. Measuring changed the figure and
the shape of the argument, and the analysis being the larger half — because
findings carry their evidence in full sentences — is the kind of thing only a
measurement tells you.

### Open questions recorded for a person

Four, in §13: whether the dashboard is Pro or a separate subscription, which
jurisdiction stores the data, whether an agency's client gets access to their
own site's page, and what the free tier is. None is decidable from the code.

---

## Final completion gate — Phases 0–20

**Status:** complete · 2026-09-04 · `docs/FINAL-AUDIT.md`

Every check in `BUILD-SPEC.md` §21.7 ran at the Phase 20 commit. No gate is red;
none went unexecuted.

| | |
|---|---|
| Automated tests | 1 523 across five suites, plus 13 browser scenarios |
| PHPCS / PHPStan L6 / ESLint | clean |
| Plugin Check, shipped tree | 0 errors, 2 warnings |
| Package | `debloater-0.1.0.zip`, 301 files, 522 KB |

### The gate closed a real gap

`ExpiredTransientsCleanup` deleted rows outside its own recovery point — carried
as a known warning since Phase 10. `collect()` wrote a batch into the snapshot;
`execute()` then re-queried and deleted every expired transient it found,
including any that expired in between. Those had no backup.

Small in practical harm — an expired transient is a cache entry the site had
already stopped honouring — and still a breach of invariant 8, which is about
where the recovery point's boundary is rather than what the rows are worth. Two
tests added, both confirmed failing on the pre-fix code.

### Known warnings after the gate

1. The display title draws a `trademarked_term` warning on "WordPress". A naming
   decision, one line in `Brand::TAGLINE`.
2. No release has been signed; the verifier fails closed until a key is pinned.
3. The registry repository is prepared, not published (D-0045).
4. `RegistryUpdater` stages a release; activation is separate.
5. wp-env cannot verify over loopback, so applies there return exit 3 (D-0009).

### Next

Nothing in the specification. What remains needs a person: decide the title,
generate a signing key, and submit to wordpress.org to reserve the slug.

---

## The tagline, after the audit

**2026-09-04 · closes the last known warning**

The final audit left one warning open and said it belonged to a person: Plugin
Check refuses the term "wordpress" anywhere in a plugin name, and the display
title carried it.

The tagline is now **"Scan, Fix & Undo Site Bloat"**, so the title is
**"Debloater – Scan, Fix & Undo Site Bloat"** (D-0052).

**Plugin Check now reports 0 errors and 0 warnings** against the tree that
actually ships.

The interesting part is what was wrong underneath. D-0047 chose the original
title on the stated basis that "WordPress" is permitted in a display name and
forbidden only in a slug — which is what the reference material says, and is
not what the tool does. `ReleaseReadinessTest` had encoded that belief as an
assertion *requiring* the tagline to contain "WordPress". The assertion is now
inverted: the tagline is held to the same restricted-term rule as the name and
the slug, so the mistaken premise cannot come back quietly.

Verified after the change: unit 1 185, integration 302, fail-probe 9, PHPCS
clean, PHPStan level 6 clean, Plugin Check clean, and
`debloater-0.1.0.zip` rebuilt at 301 files / 523 KB.

---

## Phase 18b – portable zip packaging and submission headers

**Status:** complete, with one open naming item · 2026-09-04

### The bug

The shipped zip could not be installed on Linux. Every one of its 302 entries
used a backslash separator, written by `Compress-Archive` under Windows
PowerShell 5.1. Full reasoning in `docs/DECISIONS.md` D-0053.

The part worth carrying forward is *why it was reported as verified*. Python's
`zipfile.namelist()` normalises backslashes on read, so the check that was run
returned zero offending entries on an archive where all 302 were wrong. The
assertion now parses the central directory bytes instead.

### What changed

| | |
|---|---|
| Build | `archiver`, in-process, no branch on platform, no shell |
| Entry names | Joined with a literal `/`; explicit directory entries, parents first |
| Layout | One top-level folder, named for the slug |
| Scripts | `plugin-zip` builds both; `:free` and `:pro` build one |
| Free header | `Plugin Name: Debloater` – the slug is derived from it |
| Pro header | `Requires Plugins: debloater` |
| `Brand::FULL_TITLE` | New constant; the readme title and the admin page both come from it |
| readme Tags | `bloat, debloat, performance, cleanup, optimization` |
| `Tested up to` | 7.1, verified with `wp core check-update` rather than guessed |

### Verification

| Check | Result |
|---|---|
| Packaging suite | 15 of 15 |
| Backslashes in either archive | **0** of 340 and 24 entries |
| Top-level folders | exactly one each |
| WordPress extract and activate | both zips, in the Linux container |
| Unit | 1 186 tests, 12 198 assertions |
| Integration | 302 tests; fail-probe 9 |
| PHPCS / PHPStan L6 / ESLint | clean |
| Plugin Check on the built zip | **0 errors, 1 warning** |

### The open item

Plugin Check reports `mismatched_plugin_name`: the readme title and the header
differ, which is what this phase was asked to make them do. One warning, no
errors, and it needs a naming decision rather than an implementation one. Both
ways to clear it are set out in D-0054.

### A destructive accident, and what now prevents it

The first version of the packaging test ran `wp plugin delete debloater` to
reach a clean install state. This repository is bind-mounted into wp-env as
`wp-content/plugins/debloater`, so WP-CLI deleted the working tree through the
mount, `.git` included. It was restored from the remote; no committed work was
lost.

The test now installs under a throwaway slug and never calls
`wp plugin delete` at all, and `assertNotMapped()` reads `.wp-env.json` and
refuses any operation on a slug that is a mapped directory. That guard has its
own test, because a rule nobody checks is a comment.

---

## Phase 18c – applying one finding, and the duplicate plugin rows

**Status:** complete · 2026-09-04 · reported from a live install

### Applying a single finding

The finding page described a change in full and gave you no way to make it. The
only route to applying anything was "Fix safe issues", which applies a whole
profile – so somebody who agreed with exactly one finding had to accept
everything else in the profile, or nothing.

There is now an **Apply this change…** button on any finding the engine
recommended. It opens the same dialog "Fix safe issues" opens, with the profile
selector hidden, because "how far to go" is a question about a set and answering
it there would silently widen what was asked for.

What it is not is a shortcut. The preview, the recovery point, the destructive
refusal, the attestation and the confirmation token issued for that exact plan
all happen exactly as before, because it is the same component. A second,
simpler "quick apply" written beside it would start identical and drift, and the
half that drifted would be the half with no dialog in front of it.

Findings marked "no action recommended" get no button. The recommendation is the
answer, and a button beside it would invite somebody to overrule a decision they
came to the page to read.

### The duplicate plugin rows

A live install showed twelve plugin entries where there should have been two:
`Debloater` active, plus two inactive `Debloater Loader` rows and two inactive
`Debloater – Scan, Fix & Undo Site Bloat` rows, at paths like
`debloater-0.1.0-1/debloater/debloater.php`.

They are artefacts of the **broken archive**, and the mechanism is worth
recording because it is not obvious.

`get_plugins()` scans the plugins directory one level deep: for each folder it
lists the `.php` files directly inside it. So in a correct install
`debloater/debloater.php` is a plugin and
`debloater/mu-loader/debloater-loader.php` is not, being one level further down.

The broken archive extracted as **flat files whose names contained backslashes**
– `debloater\mu-loader\debloater-loader.php` – and a flat file sits at exactly
the depth that does get scanned. WordPress therefore listed the must-use loader
as a second installable plugin, WordPress named the containing directory after
the zip file because there was no single top-level folder to collapse, and a
site that installed it twice got the `-1` suffix and four phantom rows – each
activatable and deletable on its own.

**The corrected archive cannot do this**, and that is now asserted rather than
argued: the packaging test asks WordPress, through `get_plugins()`, how many
plugins it sees for the installed package, and requires exactly one.

The assertion had to be written twice. Asking `get_plugins( '/<dir>' )` re-roots
the scan and reports the loader every time – a true statement about a directory
and a false one about what WordPress lists. The unscoped call, filtered, is the
list on the screen the bug appeared on.

### Cleaning up an affected site

Nothing in the plugin recreates these rows; they are files on disk. Deactivate
and delete each phantom entry from the plugins screen, or remove the
`debloater-0.1.0*` directories under `wp-content/plugins/`, then install the
corrected zip.

---

## Phase 18d – the Pro screen, and a lock that never let go

**Status:** complete, 2026-09-04, both reported from a live install

### The lock

Every apply refused with "Another change is already in progress on this site",
twenty-five minutes after the last one finished, against a sixty-second TTL.

A deadlock in two halves: the lock could be stored in a shape that never
expired, and crash recovery deliberately steps aside while the lock is held –
so the only thing that could clear it was the one thing it prevented. Full
reasoning in `docs/DECISIONS.md` D-0055.

The expiry now lives inside the value. A lock in the old shape reads as free, so
an affected site recovers on its next request without anybody editing the
database.

### The Pro screen

Also reported: "I don't see any difference between free and Pro." Pro *was*
working – the drift panel was on the dashboard – but four of its five features
had no interface at all. Phase 19 built the engines and wired one surface,
which satisfied the letter of the phase and is not what somebody installing Pro
needs.

**Debloater to Pro** now exists:

| | |
|---|---|
| Scan on a schedule | Off, daily or weekly, with the next run shown |
| Saved profile | Which profile "apply" means |
| Name on reports | The agency name for the white-label report |
| What changed since the last scan | Drift, in full rather than as a summary panel |
| Before and after | A printable report per apply |

Its own screen, not tabs on the free plugin's. The free plugin's filter accepts
**text** and nothing else (`docs/HOOKS.md`), which is right: an extension should
not be able to put controls on somebody else's page. So Pro renders its own and
is responsible for its own escaping and nonces – and a test asserts that having
a page did not become a way around that rule.

Plain PHP and a form post rather than React. Four settings and two buttons do
not need a build step somebody has to maintain forever.

### Verification

| Check | Result |
|---|---|
| Lock | 8 tests, all confirmed failing against the old behaviour |
| Pro screen | 10 tests |
| Unit | 1 186 tests, 12 201 assertions |
| Integration | 320 tests, 4 637 assertions |
| Fail-probe | 9 tests |
| PHPCS / PHPStan L6 | clean |

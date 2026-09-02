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

---

## Phase 5 — Snapshot, apply, rollback

**Status:** complete · 2026-09-03

`BUILD-SPEC.md` §17 calls this "the most important engineering phase; correctness
over speed", and it is the first phase in which the plugin changes anything on a
site by itself.

### What exists now

- `Storage\Schema` version 2: `wpdebloat_snapshots`, `wpdebloat_snapshot_items`
  and `wpdebloat_journal`, matching §8 field for field.
- `Journal\Journal`: append-only, one row per transition, with the actor and the
  parameters in force. A failed journal write never fails the work it describes —
  the journal is the record of the work, not the work.
- `Snapshot\SnapshotManager`:
  - **Level A** — the previous selection, the tweak states, the runtime hash, the
    loader mode and the current value of every option a selected tweak declares
    (through the `wpdebloat_tweak_options` filter), including the distinction
    between "the option held null" and "the option did not exist".
  - **Level B** — the exact rows a `DataOperationInterface` yields from
    `collect()`, taken **before** `execute()` touches anything, read back and
    checksummed before the operation is allowed to run.
  - Spill to gzipped newline-delimited JSON above 8 MB (D-0015).
  - `verify()`, which marks a snapshot corrupt on any mismatch and throws.
  - `forget()`, which removes a snapshot, its rows and its file (D-0016).
- `Snapshot\SpillFile`: streaming gzip read and write under
  `wp-content/wpdebloat/backups`, mode 0600, behind an `index.php` and an
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
| `wpdebloat_lock` transient | ✅ atomic claim, 60 s TTL |
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
  `X-WPDebloat-Verify: 1` header that nothing in the plugin ever reads, and at
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
  `VerificationResult` do the aggregation. `WPDEBLOAT_TEST_FAIL_PROBE` forces a
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
| `X-WPDebloat-Verify` header | ✅ and nothing reads it |
| Auth cookie for the acting user | ✅ forwarded when present, minted and destroyed otherwise |
| Six probes exactly as §11 describes | ✅ every branch of each covered |
| PASS/WARN/FAIL/UNKNOWN/NOT_TESTED with evidence | ✅ |
| Aggregation: FAIL wins, then WARN/UNKNOWN, NOT_TESTED not counted | ✅ contract test plus an integration test |
| FAIL → `VERIFICATION_FAILED` → `ROLLING_BACK` → `ROLLED_BACK` | ✅ end to end, runtime compared byte for byte |
| WARN → `VERIFIED_WITH_WARNINGS` | ✅ recorded in the run history |
| `WPDEBLOAT_TEST_FAIL_PROBE` | ✅ own suite, own process |
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
  is exercised on the fixture site in Phase 7 (`wp debloat verify`).
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
  `snapshots`, `status`, `export`, `import`, registered as `wp debloat` when
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
| `wp debloat` with all ten subcommands | ✅ |
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
- `wp debloat rollback <snapshot-id>` undoes the whole run that recovery point
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
| `permission_callback` on `wpdebloat_manage` | ✅ every route, supplied centrally |
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
- `GET wpdebloat/v1/runs/<id>`: the run with its state history, its verification
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

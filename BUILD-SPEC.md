# WP Debloat v0.4 — Claude Code Build Specification

**Supersedes:** Spec v0.3 + autonomous-build hardening review. Where they differ, this document wins.
**Owner:** Ashik / Hakeemify · **Date:** 2026-09-02 · **Status:** Ready for autonomous Phase 0→20 execution
**Repo (proposed):** `github.com/scornik/wp-debloat` (private until Phase 18)

---

## 0. How to use this document with Claude Code

- The authoritative specification lives at **`BUILD-SPEC.md` in the repository root**. `CLAUDE.md` must point to it. Do not maintain a second editable copy.
- **Autonomous execution is supported.** A single Claude Code invocation may execute Phases 0–20 sequentially, but each phase is a hard gate. The phase prompts in §17 define the unit of work; they do **not** require a new chat/session.
- **Exit criteria are mandatory gates.** Every phase must finish with all applicable tests green, static analysis/lint clean, acceptance criteria satisfied, documentation updated, and a git checkpoint/commit before the next phase starts. Never bypass a failing test or defer required tests.
- **The specification is authoritative.** Do not silently weaken, reinterpret, delete, or replace a requirement to make implementation easier. If a requirement is contradictory or requires an architectural change, stop only the affected work and request a decision.
- **Decisions go to `docs/DECISIONS.md`** with reasoning, before code, whenever a phase raises a choice this spec leaves open (listed in §16).
- **Conventions:** `CONVENTIONS.md` (shared Hakeemify conventions) applies. `BRAND` constant for all user-visible naming. Nothing user-visible is hardcoded.

Autonomous protocol (Claude Code follows for every phase):
1. Read `CLAUDE.md`, the relevant section of root `BUILD-SPEC.md`, and `docs/DECISIONS.md`.
2. Restate the phase goal, dependencies, and exit criteria in the build log, then continue implementation automatically unless a blocking decision is required.
3. Inspect the existing implementation before changing it. Preserve working behavior from completed phases.
4. Implement the current phase with tests. Do not implement future-phase features early except unavoidable dependencies explicitly required by the current phase.
5. Run phase-specific tests **and the complete regression suite**. Also run applicable PHPCS/PHPStan/ESLint/schema validation/build checks.
6. Fix all failures and rerun the affected tests plus the full regression suite. Repeat until green or until a genuine blocking decision is reached.
7. Perform a specification/acceptance review against the current phase. Verify security, rollback, compatibility, and performance invariants where applicable.
8. Update `docs/DECISIONS.md`, `docs/BUILD-STATUS.md`, `docs/TEST-RESULTS.md`, and other required docs.
9. Create a clean git checkpoint: `phase-N: <summary>`.
10. Only after the phase gate passes, automatically begin the next phase.
11. If a phase fails because of an environment limitation that cannot be fixed in code, document it precisely, do not mark the phase complete, and stop.

---

## 1. Locked architectural decisions (from the v2 review)

| # | Decision | Consequence in this spec |
|---|---|---|
| 1 | Score is a **Debloat Score** (configuration/maintenance), not a performance benchmark | No "Performance" sub-score in v1. Claims are always measurable ("requests −31%"), never "faster". §12 |
| 2 | **Scanner → Facts → Analyzer → Findings → Engine → Tweaks** is separate from **Meter → before/after** | Two pipelines, two modules, two tables. §2, §12 |
| 3 | **Three recovery levels** (A config, B data-operation backup, C external backup attestation) | `snapshots` + `snapshot_items` tables; destructive tweaks require Level B. Level C is an optional user attestation, not a WP Debloat snapshot. §8, §9 |
| 4 | **Safe ≠ cannot break.** Risk and **Confidence** are separate dimensions | `risk` enum + `confidence` float on every Finding. §6 |
| 5 | Every Finding carries **Evidence** | `evidence[]` with fact-key provenance. §6 |
| 6 | **Don't Touch** is a first-class decision | `decision: dont_touch` + reason. §6 |
| 7 | **Runtime v1 is simple**: generated `runtime.php` that `require`s selected handlers and calls `register()` | No conditions/environment logic in the runtime until Phase 13+. §10 |
| 8 | **Single-site first.** Multisite is Phase 19+ | Interfaces take an explicit `site_id`-free `Context`; no network options. |
| 9 | Tweak has **lifecycle states**; apply run has a **state machine** | §9 |
| 10 | **Vertical slice first** (Phases 0–9 = MVP v0.1), 10–15 tweaks only | §17 |
| 11 | Verification returns **PASS / WARN / FAIL / UNKNOWN / NOT_TESTED** | §11 |
| 12 | Nothing destructive is ever in "Fix Safe Issues" | Engine invariant, tested. §7.4 |

---

## 2. Final architecture

```
                             WP DEBLOAT
                                 │
                    ┌────────────┴────────────┐
                    │                         │
                 Scanner                 Environment
             (fact collectors)        (host, PHP, WP, cache)
                    └────────────┬────────────┘
                                 ↓
                               FACTS  ──────────────────────────┐
                                 ↓                              │
                             Analyzer                           │
                    (FindingFactory, EvidenceBuilder,           │
                     ConfidenceCalculator, DontTouchRules)      │
                                 ↓                              │
                             FINDINGS                           │
                                 ↓                              │
              ┌──────────────────┴──────────────────┐           │
        Compatibility                          Intent Profile   │
          Resolver                             (wizard answers) │
              └──────────────────┬──────────────────┘           │
                                 ↓                              │
                      Recommendation Engine                     │
                                 ↓                              │
                              TWEAKS  (with params)             │
                                 ↓                              │
                            Risk Engine                         │
                                 ↓                              │
                        Dependency Resolver                     │
                                 ↓                              │
                           PREVIEW PLAN                         │
                                 ↓                              │
                    ┌────────────┴────────────┐                 │
                  Meter                    Snapshot             │
               (baseline)               (Level A / B)           │
                    └────────────┬────────────┘                 │
                                 ↓                              │
                               Apply                            │
                        (config → Compiler → runtime.php)       │
                        (data   → DataOperation with backup)    │
                                 ↓                              │
                              Verify                            │
                                 ↓                              │
                        PASS/WARN ───→ Commit                   │
                        FAIL ────────→ Rollback                 │
                                 ↓                              │
                             Meter (after) ← compares to baseline
                                 ↓
                        BEFORE / AFTER REPORT

Underneath:  Registry { tweaks, compatibility, profiles, detectors, schemas }
Cross-cutting: Journal, Locks, Security, CLI, REST, Admin UI
```

**Hard boundaries**
- Scanner produces facts only. It never names a tweak.
- Analyzer never applies anything. It produces Findings incl. `dont_touch`.
- Engine is deterministic: same facts + same profile + same registry → same plan. No AI, no network.
- Runtime (`runtime.php`) has no knowledge of the registry, the DB, or options. It only registers hooks.
- Meter is never used to compute the Debloat Score. Score comes from Findings; Meter proves deltas.

---

## 3. Stack & conventions

| Item | Value |
|---|---|
| PHP | 8.1+ (CI: 8.1, 8.2, 8.3) |
| WordPress | 6.5+ (CI: latest, latest−1) |
| Namespace | `WPDebloat\` PSR-4 from `src/`. Runtime handlers in `runtime-handlers/` are **not** autoloaded (see §10) |
| Prefix | functions/hooks `wpdebloat_`, options `wpdebloat_`, tables `{$wpdb->prefix}wpdebloat_`, REST `wpdebloat/v1`, CLI `wp debloat`, constants `WPDEBLOAT_` |
| Brand | `WPDebloat\Brand::NAME` etc. from one `Brand` class; text domain `wp-debloat` |
| Build | Composer (dev deps only; no runtime deps), `@wordpress/scripts` for the admin bundle |
| Tests | PHPUnit 10 (unit, no WP), `@wordpress/env` + WP PHPUnit for integration, Playwright (Phase 16) |
| Lint | PHPCS with WordPress-Extra + WordPress-VIP-Go-lite, PHPStan level 6, ESLint via wp-scripts |
| No | frameworks, ORMs, external HTTP by default, telemetry, admin notices outside our screen, frontend output |

---

## 4. Exact folder structure

```
wp-debloat/
├── wp-debloat.php                     # bootstrap: constants, autoload, Plugin::boot()
├── uninstall.php                      # removes tables/options/runtime file only if opt-in set
├── composer.json  package.json  phpunit.xml.dist  phpunit-wp.xml.dist  phpcs.xml.dist  phpstan.neon
├── .wp-env.json                       # WP latest + Woo + Elementor + CF7 + Rank Math + LiteSpeed for tests
├── BUILD-SPEC.md                    # authoritative build specification
├── CLAUDE.md  CONVENTIONS.md  README.md  CHANGELOG.md  LICENSE (GPL-2.0-or-later)
├── docs/
│   ├── DECISIONS.md
│   ├── BUILD-STATUS.md
│   ├── TEST-RESULTS.md
│   ├── SCORING.md                     # public score rubric (Phase 3)
│   ├── REGISTRY.md                    # how to author a tweak/compat/profile/detector
│   └── STATE-MACHINE.md               # generated diagram + transition table
├── src/
│   ├── Plugin.php                     # DI-less service locator, boot sequence, hooks
│   ├── Brand.php
│   ├── Contracts/                     # interfaces + value objects (Phase 0)
│   │   ├── Fact.php  FactSet.php
│   │   ├── Finding.php  Evidence.php  Impact.php  Decision.php
│   │   ├── Tweak.php  TweakParams.php  TweakState.php
│   │   ├── PreviewPlan.php  ApplyResult.php  VerificationResult.php  ProbeResult.php
│   │   ├── Snapshot.php  SnapshotItem.php
│   │   ├── ScannerInterface.php  AnalyzerRuleInterface.php  HandlerInterface.php
│   │   ├── DataOperationInterface.php  ProbeInterface.php  MeterInterface.php
│   │   └── Context.php               # current site context (paths, wp version, user/actor)
│   ├── Registry/
│   │   ├── Registry.php  Loader.php  SchemaValidator.php
│   │   ├── TweakDefinition.php  CompatRule.php  Profile.php  Detector.php
│   ├── Scan/
│   │   ├── ScanRunner.php
│   │   └── Scanners/ WordPressScanner.php PluginScanner.php ThemeScanner.php
│   │       DatabaseScanner.php AutoloadScanner.php CronScanner.php
│   │       CoreFeatureScanner.php AdminScanner.php EnvironmentScanner.php
│   ├── Analyze/
│   │   ├── Analyzer.php  FindingFactory.php  EvidenceBuilder.php  ConfidenceCalculator.php
│   │   ├── DontTouchRules.php  Score.php
│   │   └── Rules/  (one class per finding id, e.g. HeartbeatIntervalRule.php)
│   ├── Recommend/
│   │   ├── IntentProfile.php  RecommendationEngine.php  RiskEngine.php
│   │   ├── CompatibilityResolver.php  DependencyResolver.php  PreviewPlanner.php
│   ├── Apply/
│   │   ├── ApplyManager.php  RunStateMachine.php  Lock.php
│   │   ├── Compiler.php  RuntimeWriter.php  RuntimeLoader.php
│   │   ├── DataOperations/  (ExpiredTransientsCleanup.php, RevisionsCleanup.php … Phase 10)
│   ├── Snapshot/
│   │   ├── SnapshotManager.php  ConfigSnapshot.php  DataSnapshot.php  RollbackManager.php
│   ├── Verify/
│   │   ├── Verifier.php  HttpClient.php
│   │   └── Probes/ HomeProbe.php ContentPageProbe.php AdminProbe.php RestProbe.php
│   │       LoginProbe.php RuntimeLoadedProbe.php  (Woo/Elementor/Forms probes later)
│   ├── Meter/
│   │   ├── Meter.php  Metrics/ (FrontendRequests.php, HeadSize.php, AutoloadSize.php …)
│   │   └── Comparison.php
│   ├── Journal/ Journal.php
│   ├── Storage/ Schema.php (dbDelta) Repositories/ (RunRepository, SnapshotRepository …) State.php
│   ├── Security/ Capabilities.php  Nonces.php  Sanitizer.php
│   ├── Rest/ Controller.php  Routes/ (ScanRoute, FindingsRoute, PreviewRoute, ApplyRoute, RollbackRoute, StatusRoute)
│   ├── Cli/ Command.php  (subcommands per §17 Phase 7)
│   └── Admin/ Menu.php  Page.php  Assets.php
├── runtime-handlers/                  # plain static classes, NO namespace deps, no autoloader
│   ├── core-remove-generator.php  core-remove-rsd.php  core-remove-shortlink.php
│   ├── core-disable-self-pingbacks.php  core-disable-emojis.php  core-disable-embeds.php
│   ├── core-heartbeat-interval.php  core-limit-revisions.php
│   ├── core-disable-dashicons-guests.php  core-remove-jquery-migrate.php
├── registry/                          # JSON only; mirrored to public repo in Phase 17
│   ├── schemas/ fact.schema.json finding.schema.json tweak.schema.json compat.schema.json profile.schema.json detector.schema.json
│   ├── tweaks/   core.*.json
│   ├── compatibility/ *.json
│   ├── profiles/ safe.json performance.json maximum.json
│   └── detectors/ woocommerce.json elementor.json contact-form-7.json litespeed.json …
├── admin-ui/                          # React source → build/admin.js (single bundle)
│   └── src/ (App, screens/Dashboard, screens/Findings, screens/Finding, screens/Preview, screens/Run, api/)
├── mu-loader/ wp-debloat-loader.php   # copied to mu-plugins on activation (see §10)
└── tests/
    ├── Unit/        (no WP; contracts, registry, resolver, compiler, engine, state machine)
    ├── Integration/ (wp-env; scanners, apply, snapshot, verify, CLI)
    ├── Fixtures/    (registry fixtures, fact sets, HTML pages)
    └── E2E/         (Playwright, Phase 16)
```

Generated at runtime (never committed):
```
wp-content/wpdebloat/runtime.php       # compiled runtime (0644, atomic write)
wp-content/wpdebloat/runtime.lock      # sha256 of runtime.php + selection hash
wp-content/wpdebloat/backups/          # Level B overflow files (gz JSON) when > 8 MB
wp-content/mu-plugins/wp-debloat-loader.php
```

---

## 5. Facts

A `FactSet` is one JSON document per scan run. Keys are dot-namespaced; each scanner owns a namespace and may only write into it. Values are scalars, lists, or flat maps. Facts carry no opinions.

```json
{
  "env.wp_version": "6.8.1",
  "env.php_version": "8.2.19",
  "env.host_vendor": "litespeed|siteground|kinsta|wpengine|unknown",
  "env.cache_plugin": "litespeed-cache|wp-rocket|none",
  "env.is_multisite": false,
  "wp.heartbeat_interval": 15,
  "wp.xmlrpc_enabled": true,
  "wp.emojis_enabled": true,
  "wp.embeds_enabled": true,
  "wp.rss_enabled": true,
  "wp.generator_tag": true,
  "wp.rsd_link": true,
  "wp.shortlink": true,
  "wp.self_pingbacks": true,
  "wp.dashicons_frontend": true,
  "wp.jquery_migrate": true,
  "wp.revisions_limit": -1,
  "wp.file_editor_enabled": true,
  "wp.debug": false,
  "users.admin_count": 4,
  "users.recent_editors_7d": 2,
  "plugins.active": ["woocommerce/woocommerce.php", "..."],
  "plugins.inactive": ["..."],
  "plugins.meta": { "woocommerce/woocommerce.php": { "version": "9.9", "last_updated": "2026-08-01" } },
  "plugins.detected": { "woocommerce": true, "elementor": false, "cf7": true, "litespeed": true },
  "theme.active": "storefront", "theme.parent": null,
  "db.size_bytes": 192937984,
  "db.revisions.count": 31421, "db.autodrafts.count": 12, "db.trash.count": 40,
  "db.spam_comments.count": 388, "db.transients.count": 5210, "db.transients.expired": 4832,
  "db.orphan_postmeta.count": 2134, "db.orphan_termmeta.count": 0, "db.orphan_usermeta.count": 0,
  "db.autoload.bytes": 9646080, "db.autoload.top": [ { "name": "plugin_xyz_cache", "bytes": 3251200 } ],
  "cron.events.count": 1823, "cron.events.subminute": [ { "hook": "plugin_xyz_sync", "interval": 30 } ],
  "cron.orphans.count": 382, "cron.disable_wp_cron": false,
  "admin.notices.count": 17, "admin.dashboard_widgets.count": 13, "admin.menu_items.count": 28
}
```
`fact.schema.json` enumerates allowed keys per namespace; unknown keys fail validation in tests. New scanners add keys to the schema in the same PR.

---

## 6. Findings

```json
{
  "id": "wp.heartbeat.aggressive",
  "category": "wordpress",                  // wordpress | configuration | database | plugins | maintenance | admin | assets
  "severity": "low",                        // info | low | medium | high   (how much it matters)
  "risk": "low",                            // safe | low | medium | high  (risk of the recommended change)
  "confidence": 0.91,                       // 0..1, how sure we are the finding + recommendation are correct
  "title": "Heartbeat frequency may be unnecessarily aggressive",
  "summary": "Heartbeat polls every 15 s. With 4 admins and no collaborative editing, 60 s is sufficient.",
  "why": "Heartbeat fires admin-ajax requests on a timer for autosave and post locking …",
  "evidence": [
    { "label": "Current interval", "value": "15 s", "fact": "wp.heartbeat_interval" },
    { "label": "Admin users", "value": 4, "fact": "users.admin_count" },
    { "label": "WooCommerce active", "value": true, "fact": "plugins.detected.woocommerce" }
  ],
  "impact": { "kind": "admin_ajax_requests_per_hour", "estimate": 960, "unit": "requests", "measurable": true },
  "decision": "recommend",                  // recommend | dont_touch | info
  "decision_reason": null,                  // required when dont_touch
  "recommendation": { "tweak_id": "core.heartbeat_interval", "params": { "interval": 60 } },
  "undo": true,
  "requires": [], "conflicts": [],
  "dependencies_detected": 0
}
```

Rules:
- `severity` and `risk` are independent. A high-severity finding can have a `dont_touch` decision.
- `confidence` is computed by `ConfidenceCalculator` from rule-declared base confidence × penalties (unknown host, detected dependents, cache plugin present, custom code detected). Formula documented in `docs/SCORING.md`.
- `dont_touch` findings are shown, counted separately ("⚪ 1 No action recommended"), never in any plan.
- `info` findings (abandoned plugin, duplicate functionality) have no `recommendation`.

---

## 7. Tweaks & registry formats

### 7.1 Tweak (`registry/tweaks/<id>.json`)
```json
{
  "id": "core.heartbeat_interval",
  "schema_version": 1,
  "title": "Set Heartbeat interval",
  "category": "wordpress",
  "kind": "config",                         // config (hooks in runtime) | data (one-shot DataOperation)
  "risk": "low",
  "base_confidence": 0.95,
  "reversible": true,
  "destructive": false,                     // true only for data tweaks that delete rows
  "handler": "runtime-handlers/core-heartbeat-interval.php",   // config: file; data: "WPDebloat\\Apply\\DataOperations\\X"
  "params": { "interval": { "type": "integer", "minimum": 15, "maximum": 120, "default": 60 } },
  "description": "Changes the interval WordPress uses for Heartbeat polling in admin and frontend.",
  "breaks": ["Post-lock notifications become slower with many concurrent editors"],
  "requires": [],                           // tweak ids or fact predicates: "fact:plugins.detected.woocommerce=true"
  "conflicts": ["core.heartbeat_disable"],
  "conditions": [],                         // v1: unused by runtime; used by engine only from Phase 4
  "measurements": ["admin_ajax_requests_per_hour"],
  "probes": ["home", "admin", "rest"],
  "since_wp": "3.6",
  "docs_url": null
}
```
`context` rules (from v0.2) move into **Analyzer rules and the Recommendation Engine**, not into the tweak file, so the runtime stays dumb (decision #7).

### 7.2 Compatibility (`registry/compatibility/<slug>.json`)
```json
{ "subject": "plugin:contact-form-7", "requires": ["rest:public"], "notes": "CF7 submits via REST", "confidence": 0.99 }
```
`requires` vocabulary: `rest:public`, `rest:auth`, `jquery`, `jquery-migrate`, `heartbeat`, `xmlrpc`, `embeds`, `dashicons:frontend`, `cron:wp`. Resolver turns these into `dont_touch` or `dependencies_detected`.

### 7.3 Profile (`registry/profiles/<name>.json`)
```json
{ "id": "safe", "title": "Safe", "include_risk": ["safe", "low"], "exclude_destructive": true, "tweaks": [], "params": {} }
```

### 7.4 Engine invariants (unit-tested)
- "Fix Safe Issues" plan = findings with `decision=recommend` ∧ `risk ∈ {safe, low}` ∧ `destructive=false` ∧ no unresolved `requires` ∧ no `conflicts` with already-selected. Nothing else, ever.
- No tweak enters a plan while any active `dont_touch` finding names it.
- Two tweaks that `conflict` are never in one plan.

### 7.5 Detector (`registry/detectors/<slug>.json`)
```json
{ "id": "woocommerce", "match": { "plugin_file": "woocommerce/woocommerce.php" }, "sets": { "plugins.detected.woocommerce": true } }
```

---

## 8. Database tables & storage

All via `dbDelta` in `Storage\Schema`, versioned by `wpdebloat_state.schema_version`.

```sql
{prefix}wpdebloat_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('scan','apply','rollback','verify','measure') NOT NULL,
  status VARCHAR(32) NOT NULL,              -- state machine state (§9)
  actor VARCHAR(64) NOT NULL,               -- 'user:123' | 'cli' | 'cron'
  started_at DATETIME NOT NULL, finished_at DATETIME NULL,
  plugin_version VARCHAR(20) NOT NULL, registry_hash CHAR(64) NOT NULL,
  payload LONGTEXT NULL,                    -- JSON: facts+findings (scan) | plan (apply) | results
  error TEXT NULL,
  KEY type_status (type, status), KEY started_at (started_at)
)

{prefix}wpdebloat_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  level ENUM('A','B') NOT NULL,             -- A config | B data backup; Level C is external attestation only
  created_at DATETIME NOT NULL,
  site_hash CHAR(64) NOT NULL,              -- sha256(home_url + ABSPATH)
  plugin_version VARCHAR(20) NOT NULL,
  config LONGTEXT NULL,                     -- A: previous selection + runtime hash + affected wp_options values
  items_count INT UNSIGNED DEFAULT 0, bytes BIGINT UNSIGNED DEFAULT 0,
  storage ENUM('db','file') DEFAULT 'db', file_path VARCHAR(255) NULL,
  checksum CHAR(64) NOT NULL,
  status ENUM('pending','complete','restored','expired','corrupt') NOT NULL,
  KEY run_id (run_id), KEY status_created (status, created_at)
)

{prefix}wpdebloat_snapshot_items (         -- Level B rows; exactly what will be deleted
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  object_type VARCHAR(32) NOT NULL,         -- revision | postmeta | comment | commentmeta | transient | cron | option
  object_key VARCHAR(191) NOT NULL,         -- post_id | meta_id | comment_id | option_name | hook+args hash
  payload LONGTEXT NOT NULL,                -- full original row(s) as JSON, sufficient to reinsert
  restored TINYINT(1) DEFAULT 0,
  KEY snapshot_id (snapshot_id), KEY snapshot_type (snapshot_id, object_type)
)

{prefix}wpdebloat_journal (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  tweak_id VARCHAR(100) NOT NULL,
  action ENUM('apply','revert','skip') NOT NULL,
  from_state VARCHAR(32) NOT NULL, to_state VARCHAR(32) NOT NULL,
  params TEXT NULL, at DATETIME NOT NULL, actor VARCHAR(64) NOT NULL,
  KEY run_id (run_id), KEY tweak_id (tweak_id)
)

{prefix}wpdebloat_measurements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  phase ENUM('before','after') NOT NULL,
  metric VARCHAR(64) NOT NULL, context VARCHAR(191) NULL,   -- e.g. url
  value DOUBLE NOT NULL, unit VARCHAR(16) NOT NULL, at DATETIME NOT NULL,
  KEY run_metric (run_id, metric)
)
```

Options (exactly one, `autoload = 'no'`):
```json
"wpdebloat_state": {
  "schema_version": 1,
  "selection": { "core.disable_emojis": {}, "core.heartbeat_interval": { "interval": 60 } },
  "tweak_states": { "core.disable_emojis": "COMMITTED" },
  "intent_profile": { "site_type": "store", "priority": "balanced" },
  "last_scan_run_id": 41, "runtime_hash": "…", "installed_at": "…", "uninstall_cleanup": false
}
```
Transient `wpdebloat_lock` (60 s TTL, refreshed during runs) guarantees one apply/rollback at a time.

Retention: snapshots older than 30 days with status `complete` → `expired` and items purged (daily cron, opt-out). The most recent 3 snapshots are always kept.

---

## 9. State machines

### 9.1 Tweak lifecycle (`TweakState`)
```
DISCOVERED → ELIGIBLE → RECOMMENDED → SELECTED → PREVIEWED → SNAPSHOTTED → APPLIED → VERIFIED → COMMITTED
                 └→ DONT_TOUCH                                          └→ VERIFICATION_FAILED → ROLLED_BACK
                                                                        └→ APPLY_FAILED → ROLLED_BACK
COMMITTED → REVERT_REQUESTED → ROLLED_BACK   (manual undo)
```
Stored per tweak in `wpdebloat_state.tweak_states`; every transition writes a journal row.

### 9.2 Apply run (`RunStateMachine`)
```
IDLE
 → PLANNING        (PreviewPlanner builds PreviewPlan; validates invariants §7.4)
 → PREVIEWED       (plan persisted to runs.payload; awaiting confirmation) 
 → LOCKED          (lock acquired; abort → IDLE if lock held)
 → MEASURING_BEFORE (Meter baseline; failure → WARN, continue)
 → SNAPSHOTTING    (Level A always; Level B for any destructive tweak; failure → ABORTED)
 → APPLYING        (config: Compiler → RuntimeWriter atomic swap; data: DataOperation per tweak)
      failure → APPLY_FAILED → ROLLING_BACK
 → APPLIED
 → VERIFYING       (Verifier runs probes; aggregate = worst of probe results, UNKNOWN counts as WARN)
      FAIL → VERIFICATION_FAILED → ROLLING_BACK → ROLLED_BACK → (release lock) → IDLE
 → VERIFIED | VERIFIED_WITH_WARNINGS
 → MEASURING_AFTER (failure → WARN)
 → COMMITTED       (tweak states → COMMITTED; snapshot stays restorable; lock released)
ABORTED            (any pre-APPLYING failure; nothing changed; lock released)
```
Transition table lives in `docs/STATE-MACHINE.md`, generated from the enum by a test so it never drifts. Illegal transitions throw `IllegalTransition`. The run row's `status` is updated on every transition (crash-safe: a run found in APPLYING/VERIFYING on next boot is auto-rolled-back and marked `INTERRUPTED`).

---

## 10. Runtime v1 (simple by design)

**Generated file** `wp-content/wpdebloat/runtime.php`:
```php
<?php
/* WP Debloat runtime — generated 2026-09-02T18:34:00Z — selection a1b2c3… — DO NOT EDIT */
if ( ! defined( 'ABSPATH' ) || defined( 'WPDEBLOAT_DISABLE' ) ) { return; }
if ( isset( $_GET['wpdebloat'] ) && $_GET['wpdebloat'] === 'off' && \WPDebloat_Runtime_Guard::bypass_allowed() ) { return; }
require_once '/abs/path/wp-debloat/runtime-handlers/core-disable-emojis.php';
WPDebloat_Handler_Core_Disable_Emojis::register( array() );
require_once '/abs/path/wp-debloat/runtime-handlers/core-heartbeat-interval.php';
WPDebloat_Handler_Core_Heartbeat_Interval::register( array( 'interval' => 60 ) );
```
- Handlers are plain, dependency-free static classes with one `register(array $params): void` and one `unregister(): void` (used by tests). No namespaces, no autoloader, no option reads.
- Params are emitted with `var_export` **after** schema validation; user input never reaches the generated code path unvalidated.
- `RuntimeWriter` writes to a temp file, `php -l`-equivalent validates via `token_get_all` + a syntax check subprocess when available, then atomic `rename`. `runtime.lock` holds sha256; `RuntimeLoadedProbe` compares via REST status.
- **Loader**: on activation copy `mu-loader/wp-debloat-loader.php` to `mu-plugins` (loads `runtime.php` if present and hash matches). If `mu-plugins` isn't writable → fallback: main plugin includes `runtime.php` at `plugins_loaded` priority −999 and `RuntimeLoadedProbe` reports `WARN: fallback loader`. Recorded in `DECISIONS.md` (Phase 1).
- Bypass: `?wpdebloat=off` requires a valid nonce for logged-in users with the capability; the constant works for everyone.
- Empty selection → runtime file removed, loader is a no-op. Test asserts zero hooks registered.

---

## 11. Verification

Each probe returns `ProbeResult { probe, status: PASS|WARN|FAIL|UNKNOWN|NOT_TESTED, message, evidence[] }`.

| Probe | Checks | FAIL if | WARN if |
|---|---|---|---|
| `home` | GET `/` as guest | non-2xx, fatal markers (`Fatal error`, `WP_Error`, `There has been a critical error`), empty body | markers missing (title tag, `</html>`) |
| `content_page` | GET newest published post/page | same | — |
| `admin` | GET `/wp-admin/` with cookie of actor | non-2xx/redirect loop, fatal markers | dashboard markers missing |
| `rest` | GET `/wp-json/` and `/wp-json/wp/v2/types` | non-2xx or invalid JSON | 401 when `rest:public` expected |
| `login` | GET `/wp-login.php` | non-2xx | — |
| `runtime_loaded` | GET `/wp-json/wpdebloat/v1/status` | hash mismatch or not loaded when selection non-empty | fallback loader in use |
| later | `woo_cart`, `woo_checkout`, `woo_account`, `cf7_form`, `elementor_editor` | — | — |

Aggregate: FAIL if any FAIL; else WARN if any WARN/UNKNOWN; else PASS. Probes not applicable to the stack report `NOT_TESTED` and are shown ("Checkout: not tested") so confidence is never overstated. HTTP via `wp_remote_get` with `sslverify` honoring site setting, 15 s timeout, `X-WPDebloat-Verify: 1` header (never used to change behavior, only for logs). Loopback failure → all HTTP probes `UNKNOWN`, run ends `VERIFIED_WITH_WARNINGS`, user is told loopback is blocked.

---

## 12. Meter & Debloat Score

**Meter metrics (v1):** `frontend.requests`, `frontend.scripts.count`, `frontend.styles.count`, `frontend.head_bytes`, `frontend.external_hosts`, `db.autoload_bytes`, `db.revisions`, `db.transients_expired`, `cron.events`, `admin.notices`, `admin_ajax_requests_per_hour` (derived from Heartbeat interval × active admins). Measured on `home` + `content_page` + `/wp-admin/` before and after; stored per row. Reported as deltas with units. Never reported as time saved.

**Debloat Score** (`Analyze\Score`, rubric in `docs/SCORING.md`, versioned):
- Sub-scores: **WordPress**, **Configuration**, **Database**, **Plugins**, **Maintenance** (Admin added in Phase 12). No Performance.
- Each sub-score = `100 − min(100, Σ penalty(finding))` over findings in that category with `decision ≠ dont_touch`; penalties by severity: info 0, low 4, medium 10, high 20; capped per finding id.
- Headline = weighted mean (weights in rubric; v1 equal). Score is deterministic from findings; changing the rubric bumps `SCORING.md` version and is shown in the UI.

---

## 13. Security rules (enforced, tested in Phase 18)

1. Capability `wpdebloat_manage` mapped to `manage_options` (filterable). Every REST route, admin action, and AJAX handler checks it in `permission_callback`.
2. Nonces on every state-changing request; REST uses cookie auth + `X-WP-Nonce`.
3. All inbound params validated against JSON schema (`Sanitizer`), then sanitized; unknown keys rejected.
4. Output escaped at the edge (`esc_html`, `esc_attr`, `wp_json_encode`).
5. Generated code: only registry-declared handler paths (resolved and realpath-checked inside plugin dir); params via `var_export` of validated values only.
6. Filesystem: writes only under `wp-content/wpdebloat/` and `mu-plugins/`; atomic writes; 0644; no user-controlled paths.
7. Snapshots: checksum verified before restore; restore refuses on `corrupt`; site_hash must match.
8. Rollback/apply require an explicit confirmation token (UI) or `--yes` (CLI). Destructive data ops additionally require Level B snapshot `complete`.
9. No outbound HTTP except loopback verification; registry fetch (Phase 17) is opt-in.
10. Uninstall removes runtime + loader always; drops tables/options only if `uninstall_cleanup=true`.
11. Kill-switch bypass is read-only and never logs request contents.
12. No PII in journal beyond actor id.
13. **Licensing is provider-agnostic.** Pro entitlement is obtained through an
    `EntitlementProvider` interface; the first implementation targets a
    third-party licensing platform (Freemius). WP Debloat operates no license
    server of its own, and no Hakeemify host is a prerequisite for Pro.
14. **Hakeemify Cloud is optional.** If a cloud service is used it is reachable
    at exactly one host, `cloud.hakeemify.com`, under versioned, product-scoped
    paths (`https://cloud.hakeemify.com/v1/wp-debloat/...`). It exists for
    substantive server-backed functionality only; a cloud endpoint whose real
    purpose is license validation is prohibited. No separate `license.`, `api.`,
    `registry.` or `app.` host is required infrastructure.
15. **Nothing secret ships.** No private signing key, payment-provider secret,
    or global API secret appears in any distributed package. A public
    verification key may be embedded. Free WP Debloat is fully functional with
    no Pro, no licensing platform and no cloud; a service outage never causes
    destructive behaviour; and security fixes are never license-gated.

---

## 14. Testing matrix

| Layer | Tool | Runs on | Covers |
|---|---|---|---|
| Unit | PHPUnit, no WP | every push | contracts, schema validation, registry loader, resolver, engine invariants, risk/confidence, compiler output, state machine transitions |
| Integration | wp-env + WP PHPUnit | every push | scanners, apply/snapshot/rollback round-trip, verifier against local site, CLI commands, REST routes |
| Registry | JSON Schema CI | every push | every JSON in `registry/` valid; every tweak's handler file exists and declares `register/unregister` |
| Stack matrix | wp-env variants | nightly + PR label | vanilla WP; +Woo+Storefront; +Elementor(+Pro if licensed); +CF7; +Rank Math; +LiteSpeed Cache; +WP Super Cache |
| Versions | GH Actions matrix | nightly | PHP 8.1/8.2/8.3 × WP latest/latest−1 |
| E2E | Playwright (Phase 16) | nightly | Fix Safe Issues flow, forced-failure rollback, Woo add-to-cart/checkout, CF7 submit |
| Static | PHPCS, PHPStan L6, ESLint | every push | — |
| Performance | integration | nightly | runtime overhead: hooks registered with empty selection = 0; runtime.php parse time; no queries added on frontend |

**The MVP acceptance test (Phase 9 exit, automated in Integration):** on the wp-env "full" stack with seeded revisions/transients/cron/autoload: scan reports ≥ 12 findings including ≥ 1 `dont_touch`; `Fix Safe Issues` creates a snapshot, applies, verifies PASS, produces a before/after report; a forced probe failure (`WPDEBLOAT_TEST_FAIL_PROBE=rest`) triggers automatic rollback and restores the prior selection and runtime hash exactly.

---

## 15. MVP v0.1 tweak set (the only tweaks until Phase 10)

| Risk | Tweak id | Kind |
|---|---|---|
| safe | `core.remove_generator`, `core.remove_rsd`, `core.remove_shortlink`, `core.disable_emojis`, `core.disable_self_pingbacks`, `core.disable_embeds` | config |
| low | `core.heartbeat_interval`, `core.limit_revisions`, `db.clean_expired_transients` (data, non-destructive-classified: expired only, Level B still taken) | config / data |
| medium | `core.disable_dashicons_guests`, `core.remove_jquery_migrate` | config |

Nothing destructive. `db.clean_expired_transients` is the one data operation in the MVP, deliberately, to prove Level B end-to-end on low-value rows.

---

## 16. Decisions Claude Code must record in DECISIONS.md when reached
- Phase 1: loader strategy and fallback behavior when `mu-plugins` is not writable.
- Phase 3: confidence penalty values; score weights (v1 of SCORING.md).
- Phase 5: Level B spill-to-file threshold; snapshot retention.
- Phase 6: which markers prove `home`/`admin` rendered; loopback-blocked policy.
- Phase 8: React state approach (plain hooks vs. `@wordpress/data`); no external state libs.
- Phase 10: definition of "orphan" per object type; batch sizes.
- Phase 18: public name + wp.org slug.

---

## 17. Phases → executable tasks → Claude Code prompts

Every prompt assumes the session protocol in §0. `[CC]` blocks are copy-paste prompts.

### PHASE 0 — Architecture & contracts
**Goal:** Freeze contracts. No WordPress UI.
**Tasks**
1. Scaffold repo per §4 (empty dirs with `.gitkeep`), `composer.json` (PSR-4 `WPDebloat\` → `src/`, dev: phpunit, phpcs, phpstan), `phpunit.xml.dist`, `.editorconfig`, `CLAUDE.md`, `CONVENTIONS.md` (copy from Hakeemify), `docs/DECISIONS.md`, `docs/BUILD-SPEC.md` (this file).
2. Implement `src/Contracts/*` as final, immutable value objects (constructor promotion, `readonly`), with `fromArray()`/`toArray()` and validation throwing `ContractViolation`.
3. Write `registry/schemas/*.schema.json` for Fact, Finding, Tweak, Compat, Profile, Detector matching §5–§7 exactly.
4. `Registry\SchemaValidator` using `justinrainbow/json-schema` (dev+runtime? → **no runtime deps**: vendor a minimal validator or implement the subset we use; record decision).
5. `TweakState` and `RunState` enums + `RunStateMachine` transition table + `IllegalTransition`.
6. Test that generates `docs/STATE-MACHINE.md` from the enum and fails if the committed file differs.
**Tests:** unit for every contract (valid/invalid fixtures), schema validation of fixtures, all legal/illegal transitions.
**Exit:** `composer test` green; no WP code; `docs/STATE-MACHINE.md` generated.

```
[CC] Phase 0 — Architecture & contracts
Read CLAUDE.md, docs/BUILD-SPEC.md sections 0–4, 5–9, and docs/DECISIONS.md. Restate the Phase 0 goal and exit criteria, then implement Phase 0 as the current phase.
Scaffold the repository exactly as in BUILD-SPEC §4 (create directories with .gitkeep). Create composer.json with PSR-4 "WPDebloat\\" => "src/", dev dependencies for PHPUnit 10, PHPCS (WordPress standards), PHPStan; there must be zero runtime Composer dependencies. Implement every class in src/Contracts as final readonly value objects with fromArray()/toArray() and strict validation that throws WPDebloat\Contracts\ContractViolation. Write registry/schemas/*.schema.json matching BUILD-SPEC §5, §6, §7 field-for-field. Implement TweakState and RunState enums and RunStateMachine with the exact transitions in §9; illegal transitions throw IllegalTransition. Add a test that renders the transition table to docs/STATE-MACHINE.md and fails if the committed file is stale. Decide how to validate JSON schema without a runtime dependency (vendored minimal validator vs. hand-written subset) and record the choice in docs/DECISIONS.md before implementing.
Do not write any WordPress-dependent code in this phase. Run the full test suite, commit as "phase-0: contracts, schemas, state machine", and report the exit checklist: composer test green; zero runtime deps; all contracts have valid+invalid tests; STATE-MACHINE.md generated.
```

### PHASE 1 — Minimal runtime engine
**Goal:** Prove zero overhead when nothing is enabled; correct, idempotent runtime generation.
**Tasks**
1. `Registry\Loader` + `Registry` (loads `registry/tweaks/*.json`, validates, indexes by id; computes `registry_hash`).
2. Five tweak JSONs + five handlers: `core.remove_generator`, `core.remove_rsd`, `core.remove_shortlink`, `core.disable_self_pingbacks`, `core.disable_emojis` (plain static classes per §10).
3. `Recommend\DependencyResolver` v1: conflicts + `requires` on tweak ids only (no fact predicates yet).
4. `Apply\Compiler` (selection → PHP source string, deterministic ordering by id), `RuntimeWriter` (atomic write, syntax check, `runtime.lock`), `RuntimeLoader` + `mu-loader/wp-debloat-loader.php` with fallback; activation hook installs loader.
5. `Storage\State` for `wpdebloat_state` (autoload no).
6. `Rest\Routes\StatusRoute` (`GET wpdebloat/v1/status` → runtime loaded?, hash, loader mode).
**Tests:** unit: compiler output snapshot tests for 0/1/3 tweaks, determinism, param escaping; integration (wp-env): with empty selection `has_action`/`has_filter` for all handler hooks is false and no `wpdebloat` query runs on a frontend request; with 1 tweak only its hooks exist; regenerating twice yields byte-identical file.
**Exit:** overhead test passes; loader fallback documented in DECISIONS.md.

```
[CC] Phase 1 — Minimal runtime engine
Read CLAUDE.md, docs/BUILD-SPEC.md §7.1, §10, §14 and docs/DECISIONS.md. Restate goal and exit criteria, then implement Phase 1 as the current phase.
Implement Registry\Loader and Registry (validate every registry/tweaks/*.json against the schema, index by id, compute a stable registry_hash). Author exactly five tweaks and their handlers: core.remove_generator, core.remove_rsd, core.remove_shortlink, core.disable_self_pingbacks, core.disable_emojis. Handlers live in runtime-handlers/ as dependency-free static classes named WPDebloat_Handler_<Id> with register(array $params): void and unregister(): void; they must not read options or touch the database. Implement Apply\Compiler (deterministic, sorted by id, params via var_export after schema validation), Apply\RuntimeWriter (temp file + syntax check + atomic rename + runtime.lock sha256), Apply\RuntimeLoader and mu-loader/wp-debloat-loader.php with the plugins_loaded fallback described in §10; record the loader decision in docs/DECISIONS.md. Implement Storage\State (single option wpdebloat_state, autoload no) and the REST route GET wpdebloat/v1/status.
Set up .wp-env.json and the integration test harness. Tests required: compiler snapshot tests for 0, 1 and 3 selected tweaks; byte-identical output on regeneration; integration proof that an empty selection registers zero hooks and adds zero DB queries to a frontend request; with one tweak selected only that tweak's hooks exist. Commit as "phase-1: registry, compiler, runtime, loader" and report the exit checklist.
```

### PHASE 2 — Scanner (facts only)
**Tasks**
1. `Contracts\FactSet` writer with namespace ownership enforcement.
2. Scanners: `EnvironmentScanner`, `WordPressScanner`, `PluginScanner` (+ detectors from `registry/detectors/*.json`), `ThemeScanner`, `DatabaseScanner` (counts via indexed queries, no full scans; `LIMIT`ed sampling for autoload top-N), `AutoloadScanner`, `CronScanner`, `CoreFeatureScanner`, `AdminScanner` (notices/widgets/menu counts collected on an admin request via `admin_init` capture).
3. `Scan\ScanRunner` → persists run (`type=scan`) with facts in `payload`; soft-budgeted (target ≤ 2 s per scanner; elapsed time is recorded and an over-budget scanner emits a warning/diagnostic fact; PHP execution is never forcibly interrupted).
4. Detectors: woocommerce, elementor, elementor-pro, contact-form-7, rank-math, yoast, litespeed-cache, wp-rocket, wp-super-cache, wordfence.
**Tests:** every fact key in `fact.schema.json`; scanners produce no opinion strings; DatabaseScanner query count bounded; integration on seeded site returns expected counts (±0).
**Exit:** `FactSet` for the full stack validates; scan < 5 s on the test site under the defined fixture environment; budget overruns must be diagnosed rather than hidden.

```
[CC] Phase 2 — Scanner
Read CLAUDE.md, docs/BUILD-SPEC.md §5, §7.5 and DECISIONS.md. Restate goal and exit criteria, then implement Phase 2 as the current phase.
Implement FactSet with namespace ownership (a scanner may only write keys in its declared namespace; violations throw). Implement the scanners listed in §4 under src/Scan/Scanners producing exactly the keys enumerated in registry/schemas/fact.schema.json (extend the schema in the same commit if you add a key). Scanners emit facts only — no recommendations, no adjectives. DatabaseScanner must use bounded, indexed queries (COUNT with WHERE on post_type/status, transient expiry via option_name prefix; autoload top-N via ORDER BY LENGTH(option_value) LIMIT 20) and must document its query count in a test. Implement registry/detectors/*.json for woocommerce, elementor, elementor-pro, contact-form-7, rank-math, yoast, litespeed-cache, wp-rocket, wp-super-cache, wordfence, and apply them in PluginScanner. Implement Scan\ScanRunner that applies a 2 s soft budget per scanner, records an over-budget diagnostic fact, and persists a run of type=scan with the FactSet JSON as payload (create the wpdebloat_runs table via Storage\Schema/dbDelta now).
Add wp-env seeding scripts for the integration fixture site (revisions, expired transients, orphan postmeta, cron events, large autoloaded option). Tests: schema validation of produced facts; expected counts on the seeded site; query-count bound for DatabaseScanner. Commit as "phase-2: scanners and facts" and report the exit checklist.
```

### PHASE 3 — Analyzer + Findings (incl. Don't Touch, Score)
**Tasks**
1. `AnalyzerRuleInterface { supports(FactSet): bool; analyze(FactSet): ?Finding }`; one rule class per finding id under `Analyze/Rules`.
2. Rules for the MVP set (§15) + info rules: `plugins.inactive_present`, `wp.file_editor_enabled`, `wp.xmlrpc_enabled` (info in MVP; tweak later).
3. `EvidenceBuilder` (fact-key provenance mandatory), `ConfidenceCalculator` (base × penalties), `DontTouchRules` (e.g. `rest` when any detected plugin `requires rest:public`; `heartbeat` when `users.recent_editors_7d ≥ 2 && woocommerce`).
4. `Analyze\Score` per §12 + `docs/SCORING.md` v1.
5. `Analyzer` persists findings into the same scan run payload; `Rest\Routes\ScanRoute` (POST scan) and `FindingsRoute` (GET).
**Tests:** each rule with fixtures (fires / doesn't fire / dont_touch); confidence math; score determinism; Heartbeat example from §6 reproduced exactly.
**Exit:** seeded full-stack site yields ≥ 12 findings incl. ≥ 1 `dont_touch`; SCORING.md committed.

```
[CC] Phase 3 — Analyzer, Findings, Don't Touch, Score
Read CLAUDE.md, docs/BUILD-SPEC.md §6, §12, §15 and DECISIONS.md. Restate goal and exit criteria, then implement Phase 3 as the current phase.
Implement AnalyzerRuleInterface and one rule class per finding for the MVP tweak set in §15 plus three info-only findings (inactive plugins present, file editor enabled, XML-RPC enabled). Every Finding must carry evidence entries with a fact key, a severity, an independent risk, and a confidence computed by ConfidenceCalculator (base confidence from the rule × penalties for: unknown host, cache plugin present, dependencies_detected > 0, custom mu-plugins present). Implement DontTouchRules so that REST becomes dont_touch when any detected plugin has a compatibility rule requiring rest:public, and Heartbeat becomes dont_touch when recent_editors_7d ≥ 2 and WooCommerce is active; dont_touch findings must include decision_reason. Implement Analyze\Score exactly per §12 and write docs/SCORING.md v1 with the penalty table, confidence penalties and weights; record the chosen numbers in DECISIONS.md. Persist findings in the scan run payload and expose POST wpdebloat/v1/scan and GET wpdebloat/v1/findings with capability checks.
Tests: fixture-driven tests per rule (fires, does not fire, dont_touch); the Heartbeat example in §6 must be reproduced field-for-field from its facts; score is deterministic and unchanged when a dont_touch finding is added. Integration: the seeded full-stack site yields at least 12 findings including at least one dont_touch. Commit as "phase-3: analyzer, findings, score" and report the exit checklist.
```

### PHASE 4 — Recommendation Engine
**Tasks**
1. `IntentProfile` (site_type, priority) persisted in state; defaults `other/balanced`.
2. `CompatibilityResolver` (loads `registry/compatibility/*.json`; produces `dependencies_detected` and `dont_touch` inputs).
3. `RecommendationEngine`: Findings + IntentProfile + Compat + Registry → recommended Tweaks with params (e.g. Heartbeat 120 s for blog/1 admin, 60 s for Woo/4 admins).
4. `RiskEngine` (final risk = max(tweak risk, adjustments: +1 level if dependencies_detected > 0 or unknown host).
5. `DependencyResolver` v2: fact predicates in `requires`.
6. `PreviewPlanner` → `PreviewPlan { tweaks[], will_change[], will_not[], destructive:false, snapshot_levels[] }` enforcing §7.4 invariants.
**Tests:** invariants as property tests (random registries/findings → never destructive in safe plan, never conflicting pair, never dont_touch); worked examples from spec.
**Exit:** `wp debloat preview` equivalent produces the §17 Phase 9 preview text from the seeded site.

```
[CC] Phase 4 — Recommendation engine
Read CLAUDE.md, docs/BUILD-SPEC.md §7.2–§7.4 and DECISIONS.md. Restate goal and exit criteria, then implement Phase 4 as the current phase.
Implement IntentProfile (site_type, priority; persisted in wpdebloat_state), CompatibilityResolver over registry/compatibility/*.json (author rules for contact-form-7→rest:public, elementor→jquery, woocommerce→heartbeat and jquery, litespeed-cache→none), RecommendationEngine (Findings + IntentProfile + compatibility + Registry → Tweaks with parameters; implement the Heartbeat parameterization: 120 s for blog with ≤1 admin and no WooCommerce, 60 s otherwise), RiskEngine (final risk = tweak risk raised one level when dependencies_detected > 0 or host is unknown), DependencyResolver v2 with fact predicates ("fact:plugins.detected.woocommerce=true"), and PreviewPlanner producing PreviewPlan with will_change/will_not lists and the required snapshot levels.
The §7.4 invariants must be enforced in PreviewPlanner and covered by property-style tests over generated registries and finding sets: a safe plan never contains a destructive tweak, never contains two conflicting tweaks, never contains a tweak named by an active dont_touch finding, and never contains a tweak with unresolved requires. Add worked-example tests for the blog and store Heartbeat cases. Commit as "phase-4: recommendation engine and preview planning" and report the exit checklist.
```

### PHASE 5 — Snapshot + Apply + Rollback
**Tasks**
1. Tables `snapshots`, `snapshot_items`, `journal` via `Schema`.
2. `SnapshotManager`: Level A (selection, runtime hash, affected option values); Level B (`DataSnapshot` collects exact rows a `DataOperationInterface` declares via `collect(): iterable<SnapshotItem>` before `execute()`); checksum; spill to gz file above threshold (record in DECISIONS.md).
3. `ApplyManager` driving `RunStateMachine` (§9.2) with `Lock`; config tweaks → Compiler/RuntimeWriter; data tweaks → `DataOperation::execute()` in batches.
4. `RollbackManager`: Level A restore (rewrite runtime from previous selection, restore options); Level B restore (reinsert rows via native APIs where they exist: `wp_insert_post` for revisions with original IDs preserved via direct insert fallback; `add_metadata`; `set_transient` with original expiry; cron via `wp_schedule_event`), marks items `restored`.
5. `Journal` writes on every transition. Crash recovery on boot (§9.2).
6. First data op: `ExpiredTransientsCleanup` (`db.clean_expired_transients`).
**Tests:** integration round-trip: apply 5 config tweaks → rollback → runtime + options byte-identical to before; expired-transients op: collect → delete → restore → rows identical incl. timeout options; corrupt checksum refuses restore; concurrent apply blocked by lock; simulated crash mid-APPLYING → auto-rollback on next boot.
**Exit:** all round-trips exact; journal complete.

```
[CC] Phase 5 — Snapshot, apply, rollback
Read CLAUDE.md, docs/BUILD-SPEC.md §8, §9, §10 and DECISIONS.md. Restate goal and exit criteria, then implement Phase 5 as the current phase. This is the most important engineering phase; correctness over speed.
Create the wpdebloat_snapshots, wpdebloat_snapshot_items and wpdebloat_journal tables via Storage\Schema. Implement SnapshotManager with Level A (previous selection, runtime hash, and the current values of every wp_options key a selected tweak touches) and Level B (DataSnapshot that stores the exact rows a DataOperationInterface::collect() yields, with checksum and spill-to-gz-file above a threshold you record in DECISIONS.md). Implement ApplyManager as a driver of RunStateMachine with the wpdebloat_lock transient, Journal rows on every transition, and crash recovery on boot for runs left in APPLYING/VERIFYING (mark INTERRUPTED, auto-rollback). Implement RollbackManager for both levels, restoring data rows through native WordPress APIs where they exist and preserving original IDs and timestamps. Implement the first DataOperation, ExpiredTransientsCleanup (db.clean_expired_transients), with collect()/execute()/restore() in batches of 500.
Integration tests required: apply five config tweaks then rollback yields a byte-identical runtime.php and identical option values; transient cleanup round-trip restores rows and timeouts exactly; a corrupt checksum refuses restore; a second apply while locked is rejected; a simulated crash in APPLYING is rolled back on next boot. Commit as "phase-5: snapshots, apply, rollback" and report the exit checklist.
```

### PHASE 6 — Verification engine
**Tasks:** `Verify\HttpClient` (loopback, admin cookie via `wp_generate_auth_cookie` for actor, timeouts), probes per §11 (`home`, `content_page`, `admin`, `rest`, `login`, `runtime_loaded`), `Verifier` aggregate, wiring into `RunStateMachine` (FAIL → rollback), test hook `WPDEBLOAT_TEST_FAIL_PROBE`.
**Tests:** each probe against wp-env (PASS), fixture HTML for fatal markers (FAIL), missing markers (WARN), loopback blocked (UNKNOWN→VERIFIED_WITH_WARNINGS); forced failure → automatic rollback with identical restore.
**Exit:** the §14 forced-failure acceptance passes.

```
[CC] Phase 6 — Verification engine
Read CLAUDE.md, docs/BUILD-SPEC.md §11, §9.2 and DECISIONS.md. Restate goal and exit criteria, then implement Phase 6 as the current phase.
Implement Verify\HttpClient over wp_remote_get with a 15 s timeout, honoring the site's SSL setting, adding the X-WPDebloat-Verify header, and able to send an auth cookie for the acting user so admin pages can be fetched. Implement the probes home, content_page, admin, rest, login and runtime_loaded exactly as specified in §11, each returning ProbeResult with status PASS/WARN/FAIL/UNKNOWN/NOT_TESTED and evidence. Implement Verifier aggregation (FAIL if any FAIL; WARN if any WARN or UNKNOWN; NOT_TESTED probes listed but not counted) and wire it into RunStateMachine so FAIL transitions to VERIFICATION_FAILED → ROLLING_BACK → ROLLED_BACK and WARN transitions to VERIFIED_WITH_WARNINGS. Add the WPDEBLOAT_TEST_FAIL_PROBE constant for tests. Decide and record in DECISIONS.md which HTML markers prove home and admin rendered, and the policy when loopback is blocked.
Tests: each probe PASS on wp-env; fixture responses with fatal markers produce FAIL; missing markers produce WARN; blocked loopback produces UNKNOWN and a VERIFIED_WITH_WARNINGS run; with WPDEBLOAT_TEST_FAIL_PROBE=rest an apply of the safe plan is rolled back automatically and the prior selection and runtime hash are restored exactly. Commit as "phase-6: verification engine" and report the exit checklist.
```

### PHASE 7 — WP-CLI
**Commands:** `wp debloat scan [--json]`, `findings [--risk=] [--json]`, `preview [--profile=safe|performance|maximum] [--tweaks=a,b]`, `apply [--profile] [--tweaks] --yes`, `verify`, `rollback [<snapshot-id>] --yes`, `snapshots [list|show <id>|delete <id>]`, `status`, `export [--file]`, `import <file> [--apply --yes]`. Actor `cli`; exit codes: 0 ok, 1 error, 2 verification FAIL/rolled back, 3 verification WARN.
**Tests:** integration via `wp-env run cli`; JSON outputs validate against schemas.
**Exit:** whole MVP loop runnable from CLI on the fixture site.

```
[CC] Phase 7 — WP-CLI
Read CLAUDE.md, docs/BUILD-SPEC.md §17 Phase 7 and §13. Restate goal and exit criteria, then implement Phase 7 as the current phase.
Implement src/Cli/Command.php registering `wp debloat` with subcommands scan, findings, preview, apply, verify, rollback, snapshots, status, export, import as specified, using the existing engine classes only (no logic in the CLI layer). apply and rollback require --yes; import --apply requires --yes. Respect exit codes: 0 success, 1 error, 2 verification failed and rolled back, 3 verified with warnings. All --json outputs must validate against the registry schemas or a documented CLI schema. Actor is "cli" in runs and journal. Export produces the config-as-code JSON (selection + intent profile + params) and import validates it before use.
Integration tests run the full loop through the CLI on the fixture site: scan → findings → preview --profile=safe → apply --yes → status → rollback --yes, plus the forced-failure case returning exit code 2. Update README with CLI usage. Commit as "phase-7: wp-cli" and report the exit checklist.
```

### PHASE 8 — React dashboard
**Tasks:** `admin-ui/` with `@wordpress/scripts`; single bundle loaded only on our screen; screens: Dashboard (score, sub-scores, counts by risk incl. ⚪ no-action, `Fix Safe Issues`, `Review findings`), Findings list (filter by risk/category/decision), Finding detail (what we found, why, evidence, impact, recommendation, risk, confidence, dependencies, what will change, undo), Runs/Snapshots (restore). REST routes for preview/apply/rollback/status with nonce. No admin notices anywhere else. Follow `frontend-design` skill guidance for a non-default look; use `@wordpress/components` for accessibility.
**Tests:** REST route permission tests; Jest for score/finding components; bundle size budget (< 250 KB gz); assets not enqueued on other admin pages.
**Exit:** dashboard renders real scan; finding detail shows all ten fields.

```
[CC] Phase 8 — React dashboard
Read CLAUDE.md, docs/BUILD-SPEC.md §4 (admin-ui), §6, §12, §13 and /mnt/skills/public/frontend-design/SKILL.md if available. Restate goal and exit criteria, then implement Phase 8 as the current phase.
Set up admin-ui/ with @wordpress/scripts producing one bundle enqueued exclusively on the WP Debloat admin screen (test that no other admin page enqueues it). Build screens: Dashboard (Debloat Score with sub-scores, counts by risk including "No action recommended", buttons Fix Safe Issues and Review findings), Findings list with filters by risk, category and decision, Finding detail showing all ten fields from §17 Phase 8 (what we found, why it matters, evidence with fact keys, potential impact, recommendation, risk, confidence, dependencies, what will change, undo), and Runs & Snapshots with a Restore action that requires an explicit confirmation token. Add REST routes for preview, apply, rollback with permission_callback on wpdebloat_manage and nonce verification; the UI must not perform any state change without the confirmation step. Record the state-management choice in DECISIONS.md. Do not add admin notices, dashboard widgets, or any frontend output. Keep the design intentional and not generic; use @wordpress/components for accessibility.
Tests: REST permission tests (401/403 for unauthorized), Jest tests for score and finding rendering, bundle size under 250 KB gzipped, and an integration assertion that the bundle is not enqueued outside our screen. Commit as "phase-8: admin dashboard" and report the exit checklist.
```

### PHASE 9 — Preview + Fix Safe Issues (the WinUtil moment)
**Tasks:** Preview modal (will change / will not / "Nothing will be deleted" / snapshot levels) → `Create snapshot & apply` → live run screen streaming state-machine transitions via polling `GET runs/<id>` → success report (score before/after, Meter deltas with units, "N optimizations applied") or failure report (which probe failed, rollback complete, previous configuration restored). Implement `Meter` v1 metrics (§12) and `Comparison` now.
**Tests:** the full §14 acceptance test automated; report never contains the word "faster".
**Exit:** **MVP v0.1 done.** Tag `v0.1.0`.

```
[CC] Phase 9 — Preview and Fix Safe Issues
Read CLAUDE.md, docs/BUILD-SPEC.md §12, §14 (acceptance test) and §17 Phase 9. Restate goal and exit criteria, then implement Phase 9 as the current phase.
Implement Meter with the v1 metrics listed in §12 (measured on home, content_page and /wp-admin/) and Comparison producing deltas with units; wire MEASURING_BEFORE and MEASURING_AFTER into the run. Build the Preview modal from PreviewPlan (will change, will not, "Nothing will be deleted" when no destructive tweak, snapshot levels), the Create snapshot & apply confirmation, the live Run screen polling GET wpdebloat/v1/runs/<id> and rendering each state transition, and the result report: score before → after, metric deltas with units and percentages, "N optimizations applied", or on failure the failing probe, "Rollback complete", "Previous configuration restored". Copy must never claim time saved or use the word "faster".
Automate the MVP acceptance test from §14 as an integration test: on the seeded full stack, Fix Safe Issues creates a snapshot, applies, verifies PASS and produces a report; with WPDEBLOAT_TEST_FAIL_PROBE=rest the same flow rolls back and restores selection and runtime hash exactly. Tag v0.1.0. Commit as "phase-9: preview, fix safe issues, before/after" and report the exit checklist.
```

### PHASE 10 — Database intelligence (destructive ops with Level B)
**Tasks:** DataOperations `RevisionsCleanup` (keep N per post, param), `AutoDraftsCleanup`, `TrashCleanup`, `SpamCommentsCleanup`, `OrphanMetaCleanup` (post/term/user/comment meta with documented orphan definitions), `AutoloadReview` (info + per-option `autoload=no` config tweak for known-safe prefixes only). Findings + rules; `destructive:true`; excluded from Safe plan; UI "Create recovery backup & delete" with Level C checkbox; batch execution with progress; restore tests per type.
**Exit:** every destructive op has an exact round-trip test; none appear in Fix Safe Issues (invariant test extended).

```
[CC] Phase 10 — Database intelligence
Read CLAUDE.md, docs/BUILD-SPEC.md §7.4, §8, §13 and DECISIONS.md. Restate goal and exit criteria, then implement Phase 10 as the current phase.
Implement DataOperations RevisionsCleanup (keep_per_post param, default 5), AutoDraftsCleanup, TrashCleanup, SpamCommentsCleanup and OrphanMetaCleanup for post, term, user and comment meta; define "orphan" per type in DECISIONS.md before coding and use native deletion functions. Mark all as destructive:true in their tweak JSON; extend the §7.4 invariant tests to prove none can enter the Safe plan. Implement AutoloadReview as an info finding listing top contributors plus a config tweak that sets autoload=no only for option names matching an allowlist of known-safe prefixes in registry. Add the destructive confirmation UI ("Create recovery backup & delete", with an optional Level C attestation checkbox "I have an external backup"). Level C never substitutes for the required Level B backup. and batched execution with progress in the Run screen.
Tests: for every destructive operation, collect → execute → restore yields identical rows including IDs, dates and meta; a destructive apply without a complete Level B snapshot is refused. Commit as "phase-10: database intelligence" and report the exit checklist.
```

### PHASE 11 — Plugin intelligence
**Tasks:** rules `plugins.inactive_present`, `plugins.duplicate_functionality` (category map in registry: cache, seo, security, image, forms, backup), `plugins.abandoned` (plugin `last_updated` from wp.org API is **network** → opt-in; fallback: file mtime heuristic marked low confidence), `plugins.host_optimizer_detected` (marks overlapping tweaks as `info` "already handled by host"). All `info`, no automatic action.
**Exit:** duplicate + abandoned findings on fixture; zero network calls unless opt-in.

```
[CC] Phase 11 — Plugin intelligence
Read CLAUDE.md, docs/BUILD-SPEC.md §6, §13 rule 9, and DECISIONS.md. Restate goal and exit criteria, then implement Phase 11 as the current phase.
Add registry/plugin-categories.json mapping plugin slugs to functional categories (cache, seo, security, image, forms, backup, analytics). Implement rules: inactive plugins present (info), duplicate functionality (info; lists the overlapping plugins and recommends reviewing one, never disables), abandoned plugin (info; uses wp.org API only when the user has opted in to registry/network access, otherwise falls back to a low-confidence file-mtime heuristic and says so in evidence), and host optimizer detected (when env.host_vendor applies overlapping tweaks, downgrade those tweak findings to info with reason "already handled by host"). Extend PluginScanner facts and the fact schema as needed.
Tests: fixtures with two SEO plugins and two cache plugins produce duplicate findings; abandoned detection works with network disabled; an assertion that no HTTP request is made during scan when opt-in is off. Commit as "phase-11: plugin intelligence" and report the exit checklist.
```

### PHASE 12 — Admin intelligence
**Tasks:** `AdminScanner` v2 (per-source notices, dashboard widgets, admin menu items, admin scripts/styles counts captured on our screen load); config tweaks: `admin.remove_dashboard_widgets` (per widget param), `admin.hide_update_nags_non_admins`, `admin.remove_welcome_panel`, `admin.remove_wp_news_widget`, `admin.suppress_promo_notices` (allowlisted third-party notice hooks from registry: woocommerce, elementor, yoast, rank-math, jetpack); Admin sub-score added to SCORING.md v2.
**Exit:** admin findings with evidence; tweaks reversible; no notices from us.

```
[CC] Phase 12 — Admin intelligence
Read CLAUDE.md, docs/BUILD-SPEC.md §12 and §17 Phase 12. Restate goal and exit criteria, then implement Phase 12 as the current phase.
Extend AdminScanner to attribute admin notices, dashboard widgets, admin menu items and admin-enqueued scripts/styles to their source plugin, captured during our own admin screen request only. Add config tweaks (handlers in runtime-handlers/, admin-only hooks, no frontend effect): admin.remove_dashboard_widgets with a per-widget parameter, admin.hide_update_nags_non_admins, admin.remove_welcome_panel, admin.remove_wp_news_widget, and admin.suppress_promo_notices driven by an allowlist in registry/admin-notices.json for WooCommerce, Elementor, Yoast, Rank Math and Jetpack. Add Admin as a sub-score and publish SCORING.md v2 with a changelog entry.
Tests: rules fire with correct evidence on the fixture; each tweak registers/unregisters cleanly; verification still passes with all admin tweaks applied; assert the plugin itself emits zero admin notices anywhere. Commit as "phase-12: admin intelligence" and report the exit checklist.
```

### PHASE 13 — Asset intelligence (detection only)
**Tasks:** `AssetScanner`: fetch a sample of URLs (home, one per public post type, up to 10), parse enqueued handles from HTML (`id="…-css"`, `-js`), attribute to plugin/theme by path, count external hosts, detect Google Fonts; page-level usage detection for CF7 (shortcode/block presence) → finding "CF7 assets loaded on N pages, forms on M". No unloading tweaks yet; `assets` sub-score deferred.
**Exit:** attribution accuracy on fixture ≥ 95%; scan stays < 10 s.

```
[CC] Phase 13 — Asset intelligence (detection only)
Read CLAUDE.md, docs/BUILD-SPEC.md §17 Phase 13 and the non-goals in §9 of the v0.2 spec. Restate goal and exit criteria, then implement Phase 13 as the current phase.
Implement AssetScanner that fetches home plus one representative URL per public post type (max 10 URLs, loopback, 5 s each), parses script and style handles from the HTML, attributes each to a plugin, theme or core by source path, records byte sizes when available, external hosts and Google Fonts usage into a new assets.* fact namespace (extend the fact schema). Implement page-level usage detection for Contact Form 7 via shortcode/block presence and produce the finding "CF7 assets loaded on N pages while forms exist on M" as info with evidence. Do not implement any unloading tweaks in this phase.
Tests: attribution accuracy of at least 95% on the fixture stack; total scan time under 10 s in the fixture environment; no network requests beyond loopback. Commit as "phase-13: asset detection" and report the exit checklist.
```

### PHASE 14 — Elementor intelligence
**Tasks:** detect Elementor/Pro/addons; enumerate registered widgets per addon (capture on `elementor/widgets/register` during an admin request); scan `_elementor_data` postmeta across posts/templates/popups for widget types in use; finding "6 addon packs, 147 widgets available, 31 detected in use, 116 **potentially** unused" (info; confidence penalized when dynamic tags/shortcodes/templates/custom code detected); Google Fonts families/weights; experiments. Config tweaks only where Elementor exposes a supported filter (e.g. `elementor/frontend/print_google_fonts`), medium risk. Never disable widgets automatically.
**Exit:** audit reproducible on fixture; wording uses "potentially".

```
[CC] Phase 14 — Elementor intelligence
Read CLAUDE.md, docs/BUILD-SPEC.md §6 and §17 Phase 14. Restate goal and exit criteria, then implement Phase 14 as the current phase.
Implement ElementorScanner: detect Elementor, Elementor Pro and addon plugins from registry/detectors; capture registered widgets per addon via the elementor/widgets/register hook during our admin request; scan _elementor_data postmeta across all posts, pages, templates and popups to collect widget types in use; record fonts (families, weights) and active experiments as facts. Produce the Elementor Audit finding as info with evidence, using the exact wording "potentially unused" and reducing confidence when dynamic tags, shortcodes, theme-builder templates or custom code are detected. Add only supported-filter config tweaks (e.g. disable Elementor Google Fonts via elementor/frontend/print_google_fonts) at medium risk. Never disable widgets automatically.
Tests: a fixture with two addons and a known set of used widgets reproduces the audit counts exactly; confidence is lower when a dynamic tag is present; all Elementor tweaks are reversible and verification passes. Commit as "phase-14: elementor intelligence" and report the exit checklist.
```

### PHASE 15 — WooCommerce intelligence
**Tasks:** page classification (conditional tags + shortcode/block presence) over sampled URLs; mini-cart detection in headers; findings: cart fragments on non-Woo pages, Woo Admin/Analytics enabled, marketplace/promo notices, Woo block styles on non-Woo pages; config tweaks `woo.cart_fragments_conditional` (medium; `dont_touch` when mini-cart detected), `woo.disable_admin_analytics` (medium), `woo.suppress_marketplace_suggestions` (safe), `woo.block_styles_conditional` (medium); probes `woo_cart`, `woo_checkout`, `woo_account`.
**Exit:** classification ≥ 95% on fixture; checkout probe PASS with all Woo tweaks applied.

```
[CC] Phase 15 — WooCommerce intelligence
Read CLAUDE.md, docs/BUILD-SPEC.md §11 and §17 Phase 15. Restate goal and exit criteria, then implement Phase 15 as the current phase.
Implement WooCommerceScanner with page classification (Woo-dependent vs. not) over the sampled URLs using conditional tags, shortcodes and blocks, plus detection of mini-cart widgets/blocks in headers. Add findings: cart fragments loaded on non-Woo pages (evidence: page list), WooCommerce Admin/Analytics enabled, marketplace suggestions and promotional notices present, Woo block styles on non-Woo pages. Add config tweaks woo.cart_fragments_conditional (medium; dont_touch when a mini-cart is detected), woo.disable_admin_analytics (medium), woo.suppress_marketplace_suggestions (safe) and woo.block_styles_conditional (medium). Add probes woo_cart, woo_checkout and woo_account asserting the cart form, checkout form and account page markers render for a guest, and list them on the tweaks' probes arrays.
Tests: classification accuracy ≥ 95% on the fixture; with all Woo tweaks applied the checkout probe PASSES; adding a mini-cart block turns the cart-fragments finding into dont_touch. Commit as "phase-15: woocommerce intelligence" and report the exit checklist.
```

### PHASE 16 — Headless verification (Playwright, CI only)
**Tasks:** `tests/E2E` Playwright suite against wp-env: dashboard + real scan, Fix Safe Issues flow, forced rollback, Woo add-to-cart → checkout, CF7 submit, Elementor editor loads. Not shipped in the plugin; no infrastructure. `wp debloat verify --e2e` stub prints local-run instructions.
**Exit:** nightly E2E green on the full stack matrix.

```
[CC] Phase 16 — Headless verification (CI only)
Read CLAUDE.md, docs/BUILD-SPEC.md §14 and §17 Phase 16. Restate goal and exit criteria, then implement Phase 16 as the current phase.
Add a Playwright suite under tests/E2E running against wp-env in GitHub Actions nightly and on an "e2e" PR label. Scenarios: dashboard loads with a real scan; Fix Safe Issues completes with a report; forced probe failure shows the rollback report and the prior runtime hash is restored; on the Woo fixture, add a product to cart and reach checkout with all Woo tweaks applied; submit a Contact Form 7 form; open the Elementor editor for a page. Nothing from this phase ships inside the plugin and no hosted service is introduced. Add a `wp debloat verify --e2e` stub that prints instructions for running the suite locally.
Exit: nightly workflow green on the full stack matrix. Commit as "phase-16: e2e verification" and report the exit checklist.
```

### PHASE 17 — Registry ecosystem
**Tasks:** split `registry/` into public `scornik/wp-debloat-registry` (`tweaks/ compatibility/ detectors/ profiles/ schemas/ tests/`) with CI (JSON → schema → compat tests → WP matrix → Woo → Elementor); plugin vendors a pinned snapshot; opt-in update check fetching a cryptographically signed manifest (Ed25519 signature over a canonical manifest containing sha256 per file) from a single pinned origin — either the public registry repository's raw URL or `https://cloud.hakeemify.com/v1/wp-debloat/registry/manifest` and `.../registry/files` — JSON only, never executable; `docs/REGISTRY.md` authoring guide, PR template, "new WP release" checklist.
**Exit:** plugin builds from a pinned tag; bad-hash/signature manifest rejected; no network unless opt-in; remote publication is not required for local phase completion.

```
[CC] Phase 17 — Registry ecosystem
Read CLAUDE.md, docs/BUILD-SPEC.md §7, §13 rule 9 and §17 Phase 17. Restate goal and exit criteria, then implement Phase 17 as the current phase.
Prepare registry/ as a separate repository layout (scornik/wp-debloat-registry); create/publish the remote repository only when GitHub credentials and explicit publication authorization are available with tweaks/, compatibility/, detectors/, profiles/, schemas/ and tests/, and a CI workflow that validates JSON, validates against schemas, runs compatibility-rule tests, and runs the plugin's WP/Woo/Elementor integration matrix against the registry. In the plugin, vendor a pinned registry snapshot with its tag recorded in a manifest, and implement an opt-in "check for registry updates" flow that fetches a cryptographically signed manifest (Ed25519 signature over a canonical manifest containing sha256 per file) from a single pinned origin resolved through one endpoint resolver (the registry repository's raw URL, or the optional cloud service at `https://cloud.hakeemify.com/v1/wp-debloat/registry/*`), verifies every file hash before activation, and never executes anything from the registry (JSON only; handlers stay in the plugin). Write docs/REGISTRY.md as an authoring guide with a PR template and a "new WordPress release" checklist.
Tests: plugin builds from the pinned tag; fixtures with a bad hash or invalid Ed25519 signature are rejected; no network call happens unless opt-in is enabled. Commit as "phase-17: registry ecosystem" and report the exit checklist.
```

### PHASE 18 — WordPress.org release hardening
**Tasks:** a test per §13 rule; performance assertions per §14; full compatibility matrix; `readme.txt`, screenshots, POT/i18n audit, `uninstall.php` per §13 rule 10, GPL headers, CHANGELOG; Plugin Check (PCP) clean; public name + slug applied only via `Brand` and build config; `npm run plugin-zip`; repository and WordPress.org submission prepared but **not published automatically**.
**Exit:** §13 tests green; PCP clean; matrix green; zip builds.

```
[CC] Phase 18 — wordpress.org release hardening
Read CLAUDE.md, docs/BUILD-SPEC.md §13, §14 and DECISIONS.md. Restate goal and exit criteria, then implement Phase 18 as the current phase.
Write an explicit test for every rule in §13 (capability and nonce on each route and action, schema-rejected params, escaped output, generated-code path allowlist, filesystem write boundaries, snapshot checksum and site_hash enforcement, confirmation tokens, no outbound HTTP by default, uninstall behavior). Add the performance assertions from §14 (zero hooks and zero added queries with an empty selection; no registry parsing on frontend requests; runtime parse-time budget). Run the full compatibility matrix. Produce readme.txt, a screenshots list, the POT file and an i18n wrapping audit, uninstall.php per §13 rule 10, GPL headers and CHANGELOG, and make the Plugin Check report clean. The public name and slug must already be recorded in DECISIONS.md; apply them only through the Brand class and build config. Add `npm run plugin-zip`.
Exit: all §13 tests green, PCP clean, matrix green, zip builds. Commit as "phase-18: release hardening" and report the exit checklist.
```

### PHASE 19 — Pro (workflow only, never safety)
**Tasks:** separate `wp-debloat-pro` extending the free plugin through documented, tested hooks: scheduled scans + drift detection (diff of findings between runs; surfaced on our screen; optional email), white-label before/after report (print-CSS HTML first; server PDF only if bundled lib size is acceptable), bulk apply of a saved profile, registry priority-update channel; multisite groundwork behind a feature flag (network defaults + per-site overrides for selection and intent profile only).

**Licensing (§13 rule 13).** Entitlement is read through an `EntitlementProvider`
interface with a `FreemiusEntitlementProvider` as the first implementation. No
Freemius call appears anywhere but that adapter, and no part of Pro asks "is the
licence valid" of anything except the interface, so the platform can be replaced
without touching feature code. Development and CI use a fixture provider; live
credentials are never required to build or test.

**Cloud (§13 rule 14).** Any server-backed feature goes through a
`CloudServiceClient` interface with a `HakeemifyCloudClient` implementation
resolving every path from one canonical base, `https://cloud.hakeemify.com`,
under `/v1/wp-debloat/...`. It is optional: with the cloud unreachable, Pro
degrades to its local features and the site is untouched. Licensing and cloud are
separate concerns and neither is implemented in terms of the other.

**Exit:** free plugin fully functional without Pro, without a licensing platform
and without cloud access; Pro adds no tweaks and no safety features (invariant
test); no Freemius symbol outside its adapter; no cloud host outside the
endpoint resolver.

```
[CC] Phase 19 — Pro plugin
Read CLAUDE.md, docs/BUILD-SPEC.md §17 Phase 19 and the Free/Pro table in the v0.2 spec. Restate goal and exit criteria, then implement Phase 19 as the current phase.
Create a separate plugin wp-debloat-pro that depends on the free plugin and extends it only through documented hooks (add and document them in the free plugin as needed, with tests). Implement scheduled scans with drift detection (diff findings between the last two runs, surface on our screen, optional email), a white-label before/after report (print-CSS HTML first; add server-side PDF only if the bundled library stays under a size you record in DECISIONS.md), bulk apply of a saved profile, and a registry priority-update channel. Read entitlement through an EntitlementProvider interface implemented by a FreemiusEntitlementProvider, with cached, offline-tolerant results and a fixture provider for tests; keep every Freemius symbol inside that adapter. Put any server-backed feature behind a CloudServiceClient interface whose HakeemifyCloudClient resolves paths from the single base https://cloud.hakeemify.com under /v1/wp-debloat/, and treat the cloud as optional. Begin multisite support behind a feature flag: network defaults and per-site overrides for selection and intent profile only.
Tests: the free plugin passes its full suite with Pro absent, with no licensing SDK present and with no cloud reachable; with Pro active no new tweaks or safety features appear (invariant test); a missing or invalid entitlement fails safe and never destructively; a cloud outage degrades Pro to local features and changes nothing on the site; no endpoint can cause PHP or JS from a remote to execute on the site; drift detection reports added and resolved findings. Commit as "phase-19: pro workflow features" and report the exit checklist.
```

### PHASE 20 — Agency / Cloud (post-revenue; design only)
**Tasks:** `docs/CLOUD-DESIGN.md`: multi-site dashboard requirements, push-only reporting via signed site keys (no inbound control in v1), data minimization/retention, reuse of run/report JSON, auth model, cost at zero/low scale, explicit deferrals. Also: the one-host architecture and its versioned product-scoped routing, the isolation boundary between licensing (third-party platform) and cloud service (Hakeemify), where secrets and signing keys live, key rotation, retention and backups, and what a migration off Hakeemify WordPress to a standalone service would and would not change in the plugin's contract. No infrastructure, accounts, or code.
**Exit:** design doc reviewed; nothing deployed.

```
[CC] Phase 20 — Agency/Cloud design
Read CLAUDE.md and docs/BUILD-SPEC.md §17 Phase 20. Produce docs/CLOUD-DESIGN.md only: multi-site dashboard requirements, push-only reporting from sites using signed site keys, data minimization and retention, how it reuses the existing run/report JSON, auth model, cost model at zero/low scale, the single-host routing and isolation model, the boundary between third-party licensing and Hakeemify cloud services, secret and signing-key ownership, key rotation, backups and monitoring, the migration path to a standalone service, and an explicit list of what is deferred until revenue. Do not create infrastructure, accounts, or code. Commit as "phase-20: cloud design doc".
```

---

## 18. Definition of done for MVP v0.1 (Phases 0–9)

- Install on the wp-env full stack (WooCommerce, Elementor, Elementor Pro, Contact Form 7, Rank Math, LiteSpeed Cache) with seeded revisions, transients, cron, autoload.
- Dashboard shows a Debloat Score and ≥ 12 findings with at least one "No action recommended".
- Fix Safe Issues → snapshot ✓ → apply ✓ → verify ✓ → report with metric deltas.
- Forced verifier failure → "Verification failed: REST endpoint unavailable" → rollback ✓ → "Previous configuration restored"; runtime hash and selection identical to before.
- Empty selection → zero hooks, zero extra queries, no runtime file.
- All of the above runnable from WP-CLI.

If that works reliably, the foundation of the product exists.

### v0.4 hardening changes
- Root `BUILD-SPEC.md` is the single authoritative specification.
- Added autonomous Phases 0–20 execution with hard per-phase gates and automatic continuation.
- Added explicit stop conditions for genuine human decisions only.
- Added mandatory build/test ledgers and final audit.
- Clarified Level C as optional external-backup attestation; it never replaces Level B.
- Replaced scanner hard timeouts with measurable soft budgets to avoid pretending PHP execution can be safely preempted.
- Strengthened the rule that completed phases are preserved and regression-tested before continuation.



---

## 21. Autonomous master-build protocol

This section governs a **single autonomous Claude Code invocation** that executes all phases.

### 21.1 Authority order

When instructions conflict, use this order:

1. Explicit safety/security constraints in this specification.
2. Locked architectural decisions in §1.
3. Contracts, schemas, invariants, and state machines in §§5–13.
4. Current phase requirements and acceptance criteria in §17.
5. Existing implementation, only where it does not conflict with the above.
6. Claude Code implementation preference.

Claude must never change a higher-level requirement merely because a lower-level implementation is inconvenient.

### 21.2 Phase gate

A phase is `COMPLETE` only when:

- all tasks for the phase are implemented;
- all new tests pass;
- the entire prior regression suite passes;
- applicable static analysis, lint, schema, build and security checks pass;
- all acceptance criteria pass;
- no known blocker is hidden or deferred;
- required documentation is updated;
- `docs/BUILD-STATUS.md` and `docs/TEST-RESULTS.md` are updated;
- a git commit/checkpoint is created.

Only then may the next phase begin.

### 21.3 Automatic continuation

After a successful phase gate, Claude Code must automatically continue to the next phase without asking for permission.

It may stop and ask for human input only when:

- the specification is genuinely contradictory;
- an architectural decision not covered by the spec is required and materially affects completed work;
- credentials, external accounts, production deployment, public release, or a real payment/license account is required;
- a destructive action against a real production site or real user data would be required;
- an environment limitation prevents a required test from being executed and no safe local substitute exists.

A normal coding problem, failing test, refactor, dependency issue, or implementation difficulty is **not** a reason to stop. Diagnose it, fix it, and retest.

### 21.4 Failure handling

Never:

- skip a failing test;
- weaken an assertion merely to make it pass;
- delete a test because implementation is difficult;
- mark a phase complete with known failures;
- silently change a schema or contract to accommodate broken code;
- implement destructive behavior without the required recovery mechanism;
- introduce telemetry or outbound HTTP contrary to §13;
- claim a performance improvement that was not measured.

When a test fails:

`FAIL → diagnose → fix → targeted retest → full regression → repeat`

### 21.5 Checkpoint discipline

Every completed phase gets one clean commit using:

`phase-N: <summary>`

Do not squash completed phase commits during the autonomous run.

At the end, create a final integration/release commit only if the final phase requires one.

### 21.6 Build ledger

`docs/BUILD-STATUS.md` must maintain:

- phase number;
- status (`NOT_STARTED`, `IN_PROGRESS`, `BLOCKED`, `COMPLETE`);
- commit hash;
- test counts/results;
- acceptance result;
- known warnings;
- next phase.

`docs/TEST-RESULTS.md` must maintain a chronological record of major test-suite results and failures/fixes.

### 21.7 External-action boundary

Autonomous coding does not imply autonomous publication or access to external accounts. Claude may prepare all artifacts, adapters, workflows, packages and tests, but must not:

- publish the repository publicly;
- submit the plugin to WordPress.org;
- create or modify production infrastructure;
- send real customer/license emails;
- make real Lemon Squeezy/license-account changes;
- push registry releases to a public remote;
- run destructive operations against a real production WordPress site.

If an external credential or explicit authorization is missing, implement the integration behind a tested adapter/interface with fixtures or mocks, document the remaining external step, and continue with all work that can be completed locally.

### 21.7 Final completion gate

After Phase 20, run a complete repository-wide validation:

- all unit tests;
- all integration tests;
- registry/schema validation;
- PHPCS;
- PHPStan;
- ESLint/Jest;
- wp-env compatibility tests;
- E2E/Playwright where environment permits;
- Plugin Check/release checks where applicable;
- security invariant tests;
- runtime zero-overhead tests;
- rollback/restore tests;
- clean build/package test.

Then produce `docs/FINAL-AUDIT.md` containing:

- completed phases 0–20;
- git commits;
- final test matrix;
- known warnings;
- deferred items;
- release readiness;
- any environment-dependent checks that could not be executed.

Do not declare the project fully complete if any required gate is red or unexecuted without an explicitly documented environment limitation.

# Claims register

Every statement the two plugins make about themselves to a user or a buyer,
checked against the code at **free 0.4.0 / Pro 0.4.0**. `docs/CATALOGUE.md` is
the inventory these verdicts are checked against.

Four verdicts:

- **accurate** — the code does what the words say, and nothing a reader would
  reasonably infer from them is missing.
- **overstated** — the words promise more than the code does. Where the words
  are simply false, the row says **false**.
- **understated** — the code does more than the words admit, in a way a user
  would want to know.
- **unverifiable** — nothing in either repository can confirm or refute it.

Line numbers are at the commit this file was added in.

---

## Sources, and what is not in the repository

| Source | Where | Present |
|---|---|---|
| Free readme: title, short description, description, FAQ | `readme.txt` | yes |
| Free plugin header description | `hakeemify-debloater.php:5` | yes |
| Brand tagline | `src/Brand.php:49` (`TAGLINE`) | yes |
| Free README | `README.md` | yes |
| Features document | `docs/FEATURES.md` | yes |
| Pro readme | `debloater-pro/readme.txt` | yes |
| Pro plugin header description | `debloater-pro/debloater-pro.php:5` | yes |
| Pro README | `debloater-pro/README.md` | yes |
| Pro licence notices shown by the Freemius SDK | `debloater-pro/debloater-pro.php:166-177` | yes |
| **wordpress.org submission text** | — | **not stored anywhere.** The plugin has not been submitted; whatever text is used will be written at submission and is unverifiable here |
| **Freemius plan rows and product description** | — | **not stored anywhere.** The only record is the wording quoted in `docs/GAP-ANALYSIS.md` and Pro `docs/DECISIONS.md`. What the dashboard shows today cannot be read from either repository |

---

## Free plugin

### `readme.txt`

| # | Line | Wording | Verdict | Deciding code |
|---|---|---|---|---|
| F1 | 1 | "Hakeemify Debloater" | accurate | `Brand::NAME`; `ReleaseReadinessTest` pins the title |
| F2 | 5 | "Tested up to: 7.1" | accurate | wp-env `core: null` resolved to WordPress 7.1 (`docs/TEST-RESULTS.md:325`) |
| F3 | 11 | "audits your site against the facts, applies only what you approve, with a recovery point and automatic rollback" | accurate | `ApplyRoute` requires a matching confirmation token; `ApplyManager` takes a recovery point before applying; `RunStateMachine` rolls back on verification FAIL |
| F4 | 21-22 | "anything that fails verification is rolled back automatically **before you ever see a broken page**" | **overstated** | Changes are live from the moment they are applied; verification runs afterwards over loopback, so a visitor can load the broken page in between. When loopback is blocked every HTTP probe is UNKNOWN and the run commits "with warnings" (`src/Verify/Verifier.php`, `docs/DECISIONS.md:1176`) — nothing is rolled back |
| F5 | 26-29 | "Facts, with numbers — **not a score and not a grade**" | **false** | The dashboard shows a Debloat Score and six sub-scores (`src/Analyze/Score.php`, `admin-ui/src/components/Score.js`); `wp debloater status` prints it |
| F6 | 31-32 | "Each one names the evidence it came from" | accurate | `RulesTest::test_every_finding_cites_observed_facts` (over rules that fire on its fixture; see CATALOGUE (a) for the four that do not) |
| F7 | 34-36 | preview shows every change, its risk, what it touches and the recovery point; "the same site and the same profile always produce the same plan" | accurate | `PreviewPlanner::willChange()`, `snapshotLevelsFor()`; `PlanInvariantsTest`, `RecommendationEngineTest` |
| F8 | 38-40 | configuration captured before any change; rows captured before deletion; "Destructive operations do not proceed unless that capture completed" | accurate | `DestructiveRefusalTest`, `ApplyRollbackTest` |
| F9 | 42-44 | "requests your own pages and your own REST API. If they stopped working, it puts everything back" | **overstated** | True when a probe can reach the site and it returns FAIL. When the probe cannot reach the site the change stays (see F4). `LoginProbe` reports WARN, never FAIL |
| F10 | 46-48 | "With no changes selected there are **no hooks registered** and **no queries added** to a front-end request. That is a measured guarantee, not a claim." | **overstated** | The plugin registers hooks on every request regardless of selection: `plugins_loaded` and two `admin_init` (`src/Plugin.php:185-194`), `admin_menu` and `admin_enqueue_scripts` (`src/Admin/Screen.php:73-74`), `rest_api_init` (`src/Rest/Controller.php:63`). `RuntimeOverheadTest::test_an_empty_selection_registers_no_hooks` measures the hooks added by **loading the runtime**, not by the plugin; `test_an_empty_selection_adds_no_queries_to_a_frontend_request` counts only queries containing `debloater`. The measurement is of the runtime, and the sentence is about the plugin |
| F11 | 52-53 | Safe "is what the 'Fix Safe Issues' button applies" | accurate | `Plugin::preview()` routes `safe` to `PreviewPlanner::safePlan()`; `admin-ui/src/components/ApplyDialog.js:91` |
| F12 | 52 | Safe: "only changes with a small blast radius and a clean way back" | accurate | safe or low assessed risk, never destructive (`Tweak::isSafePlanEligible()`) |
| F13 | 54-55 | Performance: "Safe, plus medium-risk changes **that remove work from the front end**" | **overstated** on most hosts | `registry/profiles/performance.json` filters on **assessed** risk (`RecommendationEngine` applies `RiskEngine` first). On any host `src/Scan/HostVendor.php` does not recognise — anything but WP Engine, Kinsta, SiteGround or a LiteSpeed server — every declared-medium tweak is assessed high, so Performance contains none of the front-end tweaks (`core.disable_dashicons_guests`, `core.remove_jquery_migrate`, `elementor.disable_google_fonts`, both WooCommerce asset tweaks). What it adds over Safe there is the low tweaks raised to medium |
| F14 | 56-57 | Maximum: "everything the engine will consider, **including high-risk changes**" | accurate in effect, misleading in cause | No tweak is **declared** high (`registry/tweaks/*.json`: 10 safe, 6 low, 10 medium). On an unrecognised host `RiskEngine` assesses every medium tweak as high, and those reach a plan only through Maximum. On a recognised host with no dependents, Maximum and Performance produce the same plan |
| F15 | 59-60 | "Deleting rows is never part of a profile. It is always a separate, explicit decision." | accurate for **built-in** profiles; **unverifiable** for saved ones | `src/Registry/Profile.php:227` refuses a destructive tweak outright. A **saved** profile is planned through `Plugin::previewTweaks()` (`src/Cli/Command.php:1134`), which applies no destructive filter, and nothing in `src/Config/` refuses a destructive id in an imported file. Applying still needs confirmation and a complete recovery point. No test covers a saved profile holding a destructive id |
| F16 | 64-72 | "Twenty-six changes at present, across…" (list) | accurate | 26 files in `registry/tweaks/`; the list matches |
| F17 | 76 | "No admin notices, no dashboard widget, no upsell in your way." | accurate | no `admin_notices` or `wp_add_dashboard_widget` callback in `src/`; `AdminScanner` only reads those hooks |
| F18 | 77 | "No telemetry, no analytics, no AI." | accurate | `NoRemoteCallsTest` |
| F19 | 78-80 | "Two optional features can reach further, both off by default and **both listed** under 'External services'" | **false** | Since 0.4.0 there is one (plugin release dates). The section below it says "One optional feature" (`:86`) — the readme contradicts itself |
| F20 | 81 | "No claims about speed it did not measure." | accurate | `src/Meter/Comparison.php:26` and the test it names; `admin_ajax_requests_per_hour` is derived, not measured (`src/Meter/Meter.php:360-363`) — a calculation, not a speed claim |
| F21 | 82 | "No safety feature behind a paywall." | accurate | Pro `ProArchitectureTest` |
| F22 | 86-87 | "One optional feature reaches outside your site, it is not enabled by default, and it sends nothing about your site." | accurate | `POST /scan` `check_plugin_updates`, `scan --check-plugin-updates`; sends slugs only (`src/Scan/WpOrgUpdates.php`) |
| F23 | 89-90 | "When you **tick 'check plugin update dates'** before a scan" | **false** | There is no such checkbox. The dashboard posts `/scan` with an empty body; the only ways in are `--check-plugin-updates` and the REST parameter. Nothing in `admin-ui/src/` names `check_plugin_updates` |
| F24 | 98-100 | rules "ship inside the plugin and are never fetched" | accurate | `NoRemoteCallsTest`; `docs/DECISIONS.md` D-0073 |
| F25 | 102-103 | "no licensing call and no usage reporting in this plugin" | accurate | no licensing code in `src/` |
| F26 | 107-112 | the six WP-CLI examples | accurate | `src/Cli/Command.php` accepts each (`apply --profile` at `:345`) |
| F27 | 114-115 | "Exit codes: 0 applied and verified, 1 error, 2 rolled back, 3 applied with warnings." | accurate | `Command::EXIT_*` (`:52-67`) |
| F28 | 128-132 | never says "faster" without a measurement; reports before-and-after numbers | accurate | `src/Meter/`; `BeforeAfterReport` in Pro prints deltas only |
| F29 | 136-138 | "requests your front page, a post, and your REST API. If any of those stopped working, it restores the previous state automatically" | **overstated** | as F9. It also requests wp-admin and the login page, which the answer omits |
| F30 | 142-143 | "Every apply creates a recovery point, and you can roll back to any of them from the dashboard or with `wp debloater rollback`" | accurate | `ApplyManager`; `RollbackRoute` accepts a snapshot id; `rollback [<snapshot-id>]` |
| F31 | 147-148 | "Only if you explicitly ask it to, **one operation at a time, after seeing exactly how many rows are affected**." | **overstated** | Nothing limits a plan to one destructive operation: `--tweaks=`, `POST /apply` with `tweaks`, and saved profiles all plan any number together through `previewTweaks()`. The count a user sees is the scan's fact (`db.trash.count` etc.), not the operation's own: `DataOperationInterface::countAffected()` is implemented by every operation and **called by nothing**. `db.clean_orphan_meta` also deletes orphaned comment meta, which no fact counts |
| F32 | 148-150 | "Deletions are never part of 'Fix Safe Issues' and never part of a profile. Before rows are deleted they are backed up, and if that backup does not complete the deletion does not happen." | accurate for Fix Safe Issues and built-in profiles; see F15 for saved profiles | `PreviewPlanner.php:275`; `DestructiveRefusalTest` |
| F33 | 154-155 | on uninstall the runtime is switched off and leftover files removed | accurate | `uninstall.php:5`; `SecurityRulesTest` |
| F34 | 157-158 | "turn on **'remove all data on uninstall' in the settings** first" | **false** | `uninstall.php:163` honours `uninstall_cleanup` in the state option (`src/Storage/State.php:45`), but no screen, REST route or CLI command sets it. There is no settings screen. The only way is editing the option by hand |
| F35 | 164-165 | "The changes you apply are stored in your database, not compiled into a PHP file … Nothing is added to must-use plugins." | accurate | `debloater_runtime` option; `RuntimeOverheadTest::test_no_php_is_written_under_wp_content`, `test_no_mu_plugin_is_installed` |
| F36 | 169-174 | recovery-point spill and exports under `uploads/debloater/`, closed to the web, random suffix | accurate | `SnapshotSpillTest`, `CliExportPathTest` |
| F37 | 176-179 | `--file=-` is the only value; a path is refused | accurate | `CliExportPathTest` |
| F38 | 183-187 | "Two other features can reach outside … plugin release dates from wordpress.org, **and registry updates from GitHub**" | **false** | Registry fetching was removed in 0.4.0 (D-0073; the readme's own changelog at `:205` says so) |
| F39 | 191-193 | "It is tested against WooCommerce, Elementor, Contact Form 7, Rank Math, LiteSpeed Cache **and WP Super Cache**." | **overstated** | The development environment (`.wp-env.json`) installs WooCommerce, Elementor, Contact Form 7, Rank Math and LiteSpeed Cache. WP Super Cache is installed by no environment and exercised by no test; only its detector exists (`registry/detectors/wp-super-cache.json`) |
| F40 | 195-200 | Screenshots 1–3 | **false as shipped** | no screenshot image exists in either repository; wordpress.org would show the captions with no pictures |

### Plugin header and brand

| # | Where | Wording | Verdict | Deciding code |
|---|---|---|---|---|
| F41 | `hakeemify-debloater.php:5` | "Audits a WordPress site against the facts, then applies only the changes you approve — each with its own risk level, a recovery point taken first, and an automatic rollback if verification fails." | accurate | as F3 |
| F42 | `src/Brand.php:49` | "Scan, Fix & Undo Site Bloat" | accurate | scan, apply, rollback all exist |

### `README.md`

| # | Line | Wording | Verdict | Deciding code |
|---|---|---|---|---|
| F43 | 1 | "# Debloater" | **stale** — the product is "Hakeemify Debloater" (`Brand::NAME`) | — |
| F44 | 6-10 | scans, facts behind each conclusion, risk, confidence, "what will stop working", recovery point, rolls back on failed verification | accurate, with F4's loopback caveat | as F3 |
| F45 | 12-13 | "**Every number it reports is a count it measured** before and after." | **overstated** | Findings carry estimated impacts (`Finding::impact`, labelled estimates in `admin-ui/src/screens/Finding.js`); `admin_ajax_requests_per_hour` is computed from the heartbeat interval and admin count, not measured (`src/Meter/Meter.php`); the score is not a count |
| F46 | 17 | "Early development." | **understated/stale** — 0.4.0, twenty-one phases | `docs/BUILD-STATUS.md` |
| F47 | 32-39 | "Four boundaries are **enforced, not merely intended**": scanner never names a tweak; analyzer never changes anything; deterministic engine; runtime knows nothing of registry or options | **overstated** for one of four | scanner: `ScannerTest::test_facts_contain_no_opinions`; engine: `PlanInvariantsTest`; runtime: `RuntimeOverheadTest::test_the_runtime_reads_no_registry_json`, `LoaderTest`. **No test asserts the analyzer changes nothing** |
| F48 | 38-39 | "The **generated runtime** knows nothing about the registry, the database or options." | accurate in substance, stale in words | nothing is generated since the runtime moved to handlers named in an option; `RuntimeOverheadTest::test_the_runtime_reads_no_registry_json` |
| F49 | 41-42 | "With nothing selected there is **no runtime file**, no hooks and no added database queries. That is asserted by a test" | **overstated** | there is no runtime file in any case (F35), and hooks and queries as F10 |
| F50 | 48 | "Single site (multisite is out of scope for v1)" | **overstated** | Only data operations refuse multisite (`AbstractDataOperation::isSupported()`, `:172`). The plugin activates, scans and applies config tweaks on a network site; nothing tests either behaviour |
| F51 | 55-65 | CLI examples | accurate | `src/Cli/Command.php` |
| F52 | 68-70 | exit codes, "`1` refused" | accurate | as F27 |

### `docs/FEATURES.md`

This file describes itself as "derived from the code … not from memory"
(`:3-4`). It is stale throughout; each untrue line is listed.

| # | Line | Wording | Verdict | Deciding code |
|---|---|---|---|---|
| D1 | 3 | "as they stand at **0.2.0**" | **stale** | 0.4.0 |
| D2 | 23 | "72 facts on a stock install" | **false** | 72 is the dev site with five plugins; a clean install records 65 |
| D3 | 25 | Debloat Score in `src/Meter/`, tested by `MeterTest` | **false** | `src/Analyze/Score.php`, tested in `AnalyzerTest` |
| D4 | 29 | recovery point "taken *before* anything destructive" | **understated** | taken before every apply, destructive or not |
| D5 | 33 | Runtime "Generates a single PHP file implementing the selection" | **false** | no file; handlers named in the `debloater_runtime` option |
| D6 | 50 | schema validation tested by `ScannerTest`, `AnalyzerTest` | **wrong citation** | `LoaderTest`, `RegistrySchemaTest` |
| D7 | 72-74 | filters `debloater_dashboard_panels`, **`debloater_registry_origin`**, `debloater_required_capability` | **false and incomplete** | `debloater_registry_origin` was removed in 0.4.0; `debloater_tweak_options`, `debloater_woo_page_needs_block_styles` and `debloater_woo_page_needs_cart` exist and are not listed |
| D8 | 75 | hooks "Covered by `ExtensionPointsTest`" | **overstated** | it covers the three actions and `debloater_dashboard_panels`; `debloater_required_capability` and both WooCommerce filters have no test |
| D9 | 117-121 | measured metrics include `core.heartbeat_interval` | **false** | the metric is `admin_ajax_requests_per_hour`, and it is derived (`Meter::METRICS`) |
| D10 | 127-129 | Pro extends "only through documented hooks" | **false** | Pro calls `Debloater\Plugin`, `Brand`, `Config\*`, `Contracts\*` and `Security\Capabilities` directly (CATALOGUE (e)) |
| D11 | 135 | report tested by `ProScreenTest` | incomplete | also `ReportEndpointTest` |
| D12 | 140 | multisite groundwork tested by `ProIntegrationTest` | **false** | no test in Pro references `NetworkDefaults`, and nothing calls it |
| D13 | 152-159 | test inventory | **stale** | at the last full gate: free unit 1141, integration 333, Pro units 38, Pro integration 47, registry 45 |
| D14 | 161 | "35 test classes in the free plugin" | **stale** | 65 `*Test.php` files under `tests/` |

---

## Pro

### Plan rows as recorded in the repositories

The storefront text lives in the Freemius dashboard. These are the rows as
quoted in `docs/GAP-ANALYSIS.md` and Pro `docs/DECISIONS.md`, judged against the
code today. What the dashboard actually shows now is **unverifiable** from here.

| # | Row as recorded | Verdict today | Deciding code | Wording to use |
|---|---|---|---|---|
| P1 | "Bulk apply of a saved profile" | **false** — deleted in Pro 0.2.1 | Pro D-0068; `ProArchitectureTest::test_pro_cannot_apply_anything` | "Portable profiles: save a setup once, preview and apply it on every site you manage" |
| P2 | "Priority registry updates" | **false** — withdrawn in Pro 0.3.0; no plugin fetches a registry | Pro D-0078; `EntitlementTest::test_no_plan_sells_a_registry_channel` | remove the row |
| P3 | "White-label before/after reports" | **overstated** — nothing is substituted, and "white-label" is a licence flag here (Pro D-0061) | `Features/BeforeAfterReport.php`; `ReportEndpointTest` | "Before/after reports with your name on them" |
| P4 | "Drift alerts on WordPress & plugin updates" | **overstated** — since 0.4.0 the version diff exists, but nothing is sent; it is a panel you open. Theme updates are not covered (no theme version fact) | `Features/DriftDetector.php`, `Features/DriftReport.php`; `ProIntegrationTest` | "WordPress & plugin version changes between scans, on your dashboard" |
| P5 | "Scheduled scans" | accurate | `Features/ScheduledScans.php`; `ProIntegrationTest` fires the hook and checks a lapsed entitlement | unchanged |

The `agency` plan also unlocks `multisite` (`FreemiusEntitlementProvider::PLANS`,
`:59-65`). If any storefront row mentions multisite or network support, it is
**false**: `NetworkDefaults` has no caller and no test.

### `debloater-pro.php`

| # | Line | Wording | Verdict | Deciding code |
|---|---|---|---|---|
| P6 | 5 | "scans on a schedule, drift detection between them, a printable before/after report, **and applying a saved profile in one step**" | **false** in the last clause | Pro has no apply path (D-0068); a profile opens Debloater's preview, which asks before applying |
| P7 | 5 | "Adds nothing to what Debloater does to a site." | accurate | `ProArchitectureTest` |
| P8 | 16-18 | "Pro extends Debloater only through hooks Debloater documents" | **false** | as D10 |
| P9 | 169 | "%s to access version %s feature updates, version change reports and priority support." | "version change reports" accurate; "**priority support**" **unverifiable** | no support process, SLA or tier exists in either repository |
| P10 | 173 | "You can still use every %s feature you have already set up, but … stop." | **unverifiable**, and in tension with Pro `README.md:132` | whether features keep working on an expired non-blocking licence depends on the SDK's `can_use_premium_code__premium_only()`; `FreemiusEntitlementProvider::entitlement()` switches every feature off when it returns false. No test covers an expired licence |
| P11 | 177 | "When the licence ends, Debloater keeps working and every change you have applied stays applied." | accurate | the runtime is the free plugin's (`debloater_runtime`); `ProArchitectureTest` |

### `debloater-pro/readme.txt`

| # | Line | Wording | Verdict | Deciding code |
|---|---|---|---|---|
| P12 | 9 | "scheduled scans, drift detection, portable profiles and a printable before/after report" | accurate | Pro `src/Features/`, `src/Admin/ProfilesPanel.php` |
| P13 | 16 | "Scans on a schedule, so a site is checked without somebody remembering to." | accurate | `ScheduledScans` |
| P14 | 17-18 | "Drift detection, so **you are told** what changed since the last scan" | **overstated**, mildly | nothing tells anyone; the change is shown when somebody opens the Pro screen or Debloater's dashboard |
| P15 | 18-19 | portable profiles, "take it to every site you manage" | accurate | export/import |
| P16 | 19-20 | "A printable before and after report, with your own name on it." | accurate | `BeforeAfterReport`; `ReportEndpointTest` |
| P17 | 22-23 | "Each site still shows you the preview and asks before anything is applied. Nothing is pushed to a site from somewhere else." | accurate | `ProfilesPanel` links to Debloater's preview; no remote apply |
| P18 | 25-28 | safety stays in Debloater and none of it is behind a licence | accurate | `ProArchitectureTest` |
| P19 | 35-43 | 0.4.0 changelog: WordPress and plugin versions, activations; "Theme versions are not included yet." | accurate | `DriftDetector` |

### `debloater-pro/README.md`

| # | Line | Wording | Verdict | Deciding code |
|---|---|---|---|---|
| P20 | 3-5 | schedule, drift detection, portable profiles, printable report with a name | accurate | as P12 |
| P21 | 13-16 | adds no tweaks, handlers, registry, recovery, verification or rollback | accurate | `ProArchitectureTest` |
| P22 | 24-25 | "Through the hooks Debloater documents … and through nothing else. **Pro does not call into the free plugin's classes**" | **false** | as D10 |
| P23 | 27 | "a separate plugin with `Requires Plugins: debloater`" | **stale** | `Requires Plugins: hakeemify-debloater` (`debloater-pro.php:9`) |
| P24 | 55 | "those **six** tests skip and say so" | **stale** | eight architecture tests depend on the free tree |
| P25 | 91-92 | integration tests "are not run by this repository's CI, which has no WordPress" | **false** | `.github/workflows/ci.yml` runs `Integration (Pro + Debloater)`; the same README lists that job at `:168` |
| P26 | 132 | "**One plan**, annual, auto-renewing." | **unverifiable**, and in tension with code | `FreemiusEntitlementProvider::PLANS` recognises two plan names, `pro` and `agency`, with different features |
| P27 | 132-134 | "Features stop when a licence expires; the free plugin does not" | accurate for the free plugin; see P10 for Pro | — |
| P28 | 136-141 | prices and which rows offer white-label | **unverifiable** | Freemius dashboard |
| P29 | 145-155 | what a white-labelled licence hides and shows | **unverifiable** here | SDK behaviour, recorded in Pro D-0061; no test |

---

## Other repository documents found untrue while checking

Not claims to a buyer, but read by whoever extends or maintains the product.

| File:line | Says | What is true |
|---|---|---|
| `docs/HOOKS.md:5-6` | Pro "reaches the free plugin through these and nothing else" | as D10 |
| `docs/HOOKS.md:6-7` | `ExtensionPointsTest` "asserts each one fires with the documented signature" | `debloater_required_capability`, documented at `:129`, has no test |
| `docs/HOOKS.md:10-12` | `debloater_required_capability` is internal and may change without notice | the same file documents it as a contract at `:129` |
| `docs/HOOKS.md:21-22` | an extension can "point registry updates at a different HTTPS repository" | removed in 0.4.0 |
| `docs/HOOKS.md` (absent) | — | `debloater_woo_page_needs_block_styles` and `debloater_woo_page_needs_cart` are offered to themes in the tweaks' own `breaks` text and are not documented or tested |
| `docs/DECISIONS.md` D-0032 (`:1814`) | admin tweaks are "not in any profile"; "selected individually or not at all" | `admin.remove_welcome_panel`, `admin.remove_wp_news_widget` and `woo.suppress_marketplace_suggestions` enter Fix Safe Issues, Performance and Maximum; nothing filters by category (CATALOGUE (b)) |
| `docs/SCORING.md:38` | Maintenance covers "self-pingbacks, cron" | no rule reads any cron fact |
| `docs/SCORING.md:43-45` | Assets is unscored until the asset scan arrives in Phase 13 | Phase 13 shipped; assets is still unscored |
| `docs/SCORING.md:53-56` | a category with no possible penalty "is a perfect ten awarded for nothing, and it would have pulled the headline up on every site" | the Plugins sub-score is exactly that: every plugins rule has info severity, so it is always 100 |
| `tests/Unit/Analyze/AnalyzerTest.php:114` | `test_a_declared_dependency_refuses_a_finding` | asserts `RECOMMEND` — no refusal — and no test anywhere makes the dependency refusal fire |
| `registry/tweaks/woo.suppress_marketplace_suggestions.json` (`breaks`), `runtime-handlers/woo-suppress-marketplace-suggestions.php` (comment) | hides extension recommendations / marketing notices | also filters `woocommerce_helper_suppress_admin_notices`, which hides WooCommerce Helper notices including available extension updates |
| Pro `docs/DECISIONS.md` D-0050 hook table | drift uses `debloater_scan_complete`; reporting uses `debloater_apply_complete` | Pro hooks neither; it reads runs through `Debloater\Plugin` |

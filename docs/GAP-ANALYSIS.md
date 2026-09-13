# Gap analysis: the build against `BUILD-SPEC.md`

At **free 0.4.0** and **Pro 0.4.0**, checked against `docs/CATALOGUE.md` (what
the code does) and `docs/CLAIMS.md` (what the product says it does). Four
categories:

1. **Specified, and built** — the way §17 describes it.
2. **Built differently** — how, and the decision if there is one.
3. **Specified, and absent.**
4. **Built, and never specified.**

Then the known gaps, which are things wrong or unproven whatever the
specification says.

Written to be checked by somebody else. Nothing here is softened; where
something is unproven it says unproven rather than done.

---

## 1. Specified, and built

| Phase | What | Evidence |
|---|---|---|
| 0 Architecture & contracts | Contracts final, readonly, validating on construction (D-0002) | `ContractValidationTest`, `EnumTest` |
| 3 Analyzer, findings, score | One rule per MVP finding plus the three info findings; evidence, severity, independent risk, confidence; Heartbeat refusal on a collaborative store | `RulesTest`, `AnalyzerTest`. See 2 and 3 for the REST refusal and the score |
| 4 Recommendation engine | Deterministic; RiskEngine raises one level on dependents or an unknown host; DependencyResolver with fact predicates; PreviewPlanner with will-change / will-not and snapshot levels | `PlanInvariantsTest`, `RecommendationEngineTest`, `DependencyResolverTest` |
| 5 Snapshot, apply, rollback | Recovery point before every apply; Level B required before destructive execution | `ApplyRollbackTest`, `DestructiveRefusalTest`, `SnapshotSpillTest` |
| 6 Verification | `home`, `content_page`, `admin`, `rest`, `login`; FAIL rolls back, WARN/UNKNOWN commits with warnings; loopback policy decided (`docs/DECISIONS.md:1165-1195`) | `VerificationTest`, `AdminProbeAuthTest`, `VerificationRollbackTest`. `runtime_loaded` is absent (3) |
| 7 WP-CLI | Twelve subcommands | `CliTest`, `ProfileCliTest`, `tools/cli-e2e.sh`. `wp debloater registry` has no test |
| 8 React dashboard | | `AdminScreenTest`, JS suite |
| 9 Preview + Fix Safe Issues | Confirmation token over the canonical plan; safe plan excludes destructive operations twice (planner and profile) | `WriteRoutesTest`, `PlanInvariantsTest` |
| 10 Database intelligence | Seven data operations, five destructive, each round-tripping exactly | `DestructiveOperationsTest`. Four of the five rules that recommend them have no test (known gaps) |
| 11 Plugin intelligence | 10 detectors, 6 compatibility documents, host optimizers, duplicate categories | `PluginIntelligenceTest`, `DetectorTest` |
| 13 Asset intelligence | Detection only, as specified | `AssetScanTest`, `AssetParserTest` |
| 14 Elementor | Widget audit; Google Fonts tweak | `ElementorAuditTest`, `ElementorScanTest` |
| 15 WooCommerce | Cart fragments and block styles conditional; analytics; marketplace suggestions; shop probes | `WooCommerceScanTest` |
| 16 Headless verification | Playwright, `tests/e2e/dashboard.spec.js`, nightly and on a PR label | `e2e.yml` |
| 18 Release hardening | §13 tests, Plugin Check clean, zip builds, readme, screenshots **list**, POT, `uninstall.php` per §13 rule 10, GPL headers, CHANGELOG | `SecurityRulesTest`, `ReleaseReadinessTest`, packaging job. Submission not done (3); no screenshot images exist (known gaps) |
| 19 Pro, in part | Scheduled scans; entitlement behind an interface with a fixture provider; cloud optional behind `CloudServiceClient`; free plugin works with Pro, SDK and cloud absent; Pro adds no tweaks or safety features | `ProIntegrationTest`, `EntitlementTest`, `ProArchitectureTest` |
| 20 Cloud design | `docs/CLOUD-DESIGN.md` in the Pro repository; design only | — |

**§15's MVP tweak set is complete.** All 11 named tweaks exist. The registry
holds 26; `admin.hide_update_nags_non_admins` was removed in the registry
(`6b5f8dd`).

---

## 2. Built differently

| Phase | Specified | Built | Decision |
|---|---|---|---|
| 1 Runtime | a generated runtime file | handlers named in the autoloaded `debloater_runtime` option; no file is ever written | `docs/DECISIONS.md:3653`; `RuntimeOverheadTest::test_no_php_is_written_under_wp_content` |
| 2 Scanner | the facts later phases need to notice change | flags and counts. `admin.notices` and `admin.dashboard_widgets` are enumerated with their sources, **but only when the scan runs inside wp-admin**; a CLI scan records them empty. REST routes are not enumerated. There is no theme version fact. See known gaps | none |
| 3 DontTouchRules | a dependency on a capability refuses the finding | built (`DontTouchRules::REMOVES_CAPABILITY`), and **inert with the shipped registry**: no compatibility document requires a capability any recommend rule maps to. The REST case the task names is absent (3) | none |
| 3 Score | §12 rubric | built, but the **Plugins sub-score is always 100** — every plugins rule has info severity — and Assets is still unscored though Phase 13 shipped (`docs/SCORING.md:43-45`) | D-0010 (rubric); nothing on the constant 100 |
| 12 Admin tweaks | reversible admin tweaks | `admin.remove_welcome_panel`, `admin.remove_wp_news_widget` and `woo.suppress_marketplace_suggestions` **enter Fix Safe Issues**, contrary to the project's own D-0032 ("not in any profile") | D-0032 says the opposite of the code |
| 17 Registry ecosystem | registry CI runs the plugin's WP/Woo/Elementor matrix | it checks the data that can be checked with nothing installed; the Phase 21 pipeline runs the matrix weekly and on dispatch, not as a gate | D-0045, superseded in reasoning by D-0069 |
| 19 How Pro attaches | "extends the free plugin only through documented hooks" | two hooks (`debloater_loaded`, `debloater_dashboard_panels`) and a URL contract, **plus direct calls into `Debloater\Plugin`, `Brand`, `Config\*`, `Contracts\*` and `Security\Capabilities`** (CATALOGUE (e)). Pro's D-0050 table says drift uses `debloater_scan_complete` and reporting `debloater_apply_complete`; Pro uses neither | none; D-0050 is stale |
| 19 Drift detection | diff of findings between runs, surfaced on our screen | findings diff **and**, since Pro 0.4.0, a separate diff of WordPress and active plugin versions, with activations and deactivations. No theme versions | Pro 0.4.0 changelog |
| 19 Report | white-label before/after report, print CSS first | print-CSS report with the agency's name, the site's name and address; not called white-label, because that word is a licence flag here | Pro D-0061; D-0060 superseded |
| 19 Multisite groundwork | network defaults **and per-site overrides**, behind a flag | `NetworkDefaults` stores and returns defaults behind `DEBLOATER_PRO_MULTISITE`. **Nothing calls it**, there are no per-site overrides, and no test covers it. The free plugin refuses only data operations on multisite (`AbstractDataOperation::isSupported()`) | none |

---

## 3. Specified, and absent

| Phase | Specified | Status | Record |
|---|---|---|---|
| 3 | "REST becomes dont_touch when any detected plugin has a compatibility rule requiring rest:public" (§17 Phase 3; §6's Contact Form 7 example) | **Absent.** `contact-form-7.json` requires `rest:public`; `DontTouchRules` maps `wp.rest.public`; **no rule emits `wp.rest.public`**, so nothing can be refused. `AnalyzerTest:114` `test_a_declared_dependency_refuses_a_finding` asserts no refusal | not recorded before this document |
| 6 | the `runtime_loaded` probe | **Absent.** Eight probes exist; this is not one of them | not recorded before this document |
| 17 | registry CI running the plugin's matrix as a gate | see 2 | D-0069 |
| 18 | wordpress.org submission, prepared | not submitted; an external act needing credentials. The slug to reserve is now `hakeemify-debloater` | `docs/RENAME-MAP.md` |
| 19 | bulk apply of a saved profile | **deleted** (Pro 0.2.1); portable profiles preview and confirm on each site instead. §17 still lists it | Pro D-0068 |
| 19 | registry priority-update channel | **withdrawn** (Pro 0.3.0); no plugin fetches a registry since free 0.4.0 | Pro D-0078; D-0073 |
| 19 | drift "optional email" | **absent**; the report is read on a screen. Deliberate since Pro 0.4.0, which removed "alert" from everything that ships | Pro 0.4.0 changelog |
| 19 | multisite per-site overrides | absent; see 2 | none |

Amending §17 for the rows above is the spec owner's call, not this document's.
Until then the two disagree, and this is where that is written down.

---

## 4. Built, and never specified

| What | Where | Why it exists |
|---|---|---|
| Profiles (save, export, import, apply) | free 0.2.0 | Phase 19c, by request. D-0063 |
| Pro profiles panel | Pro 0.2.0 | Phase 19c-2. D-0064 |
| Autonomous registry pipeline | registry repo | Phase 21, by request. D-0067 |
| Portable zip packaging | both | Phase 19b-1 |
| Freemius SDK integration and licence notices in the product's words | Pro | Phase 19b-2. D-0060, D-0061 |
| Version-discipline check | both | `docs/RELEASING.md` |
| Signed registry releases | registry | D-0059, D-0067. `v0.2.0` signed and tagged at `400fd6a` |
| Version diff between scans | Pro 0.4.0 | the plan row named it and nothing did it |
| Site name and address on the report | Pro 0.3.2 | two clients' printed reports were indistinguishable |
| The display name "Hakeemify Debloater Pro", pinned by `DisplayNameTest` | Pro 0.3.1 | by request, so both plugins read as one product family |
| `debloater_woo_page_needs_block_styles`, `debloater_woo_page_needs_cart` | free runtime handlers | escape hatches for themes the page check cannot see. Undocumented in `docs/HOOKS.md` and untested |

---

## Known gaps, stated plainly

### The scanner does not enumerate what Phase 21 was asked to watch

Phase 21 asked the pipeline to watch admin notices, dashboard widgets, cron
hooks, enqueued handles, autoloaded options and public REST routes.

This section used to say three of those families do not exist in the FactSet.
**That was partly wrong.** `admin.notices` and `admin.dashboard_widgets` are
enumerations, each entry with its source (`AdminScanner`) — but they are filled
only when the scan runs inside wp-admin. `wp debloater scan`, which is what the
pipeline runs, records them empty. REST routes genuinely are not recorded
anywhere. Cron is counted (`cron.events.count`, subminute events listed), and
no rule reads any cron fact.

Consequence: a CLI-driven pipeline still cannot notice "WooCommerce 11.2 added
a dashboard widget". Closing it means either scanning in an admin context from
the CLI or a REST route fact, and either is a change to the plugin.

### Four rules that recommend deleting data have no test

`db.autodrafts.abandoned`, `db.meta.orphaned`, `db.comments.spam` and
`db.trash.pending` do not fire on `Facts::busyStore()`, and the generic tests
in `RulesTest` return early when a rule does not fire, so they pass without
checking anything. `db.autoload.heavy`'s recommend branch is untested for the
same reason. The operations themselves are well tested
(`DestructiveOperationsTest`); the thresholds that decide whether they are
offered are not.

### The dependency refusal cannot fire, and its test is misnamed

See 2 and 3. The mechanism the whole "Don't Touch" idea rests on for plugin
dependencies has no input in the shipped registry that reaches it, and the test
named for it asserts the opposite.

### The readme promises things the code does not do

`docs/CLAIMS.md` lists them with file and line. The ones a user would act on:

- a **"remove all data on uninstall" setting** (`readme.txt:157-158`) — the
  state key exists and `uninstall.php` honours it; nothing sets it;
- a **"check plugin update dates" checkbox** (`:89-90`) — there is none;
- deletions **"one operation at a time, after seeing exactly how many rows are
  affected"** (`:147-148`) — several can share a plan, and
  `DataOperationInterface::countAffected()` is implemented by every operation
  and called by nothing;
- **"not a score"** (`:28-29`) while the dashboard shows one;
- **registry updates from GitHub** (`:186-187`), removed in 0.4.0;
- **tested against WP Super Cache** (`:193`), which no environment installs;
- three **screenshots** with no images.

`readme.txt` ships in the zip, so correcting it is a release.

### `db.clean_orphan_meta` deletes what the scan never counted

The operation's default types are post, term, user **and comment** meta. The
scanner has no orphaned comment-meta fact and the finding does not mention it,
so the count a user is shown is lower than what will be deleted. The rows are
still backed up first.

### A safe-rated tweak hides WooCommerce extension update notices

`woo.suppress_marketplace_suggestions` also returns true from
`woocommerce_helper_suppress_admin_notices`, which in WooCommerce 11.1.0
suppresses the Helper's notices on the Updates screen, including available
extension updates. Its `breaks` line and handler comment describe only
recommendations. It is safe, so it is in Fix Safe Issues. Same class of problem
as D-0077.

### Profiles depend on the host more than the readme says

`src/Scan/HostVendor.php` recognises four hosts. On any other, `RiskEngine`
raises every tweak one level, so **Performance contains none of the declared
medium tweaks** — which are all the front-end ones — and they reach a plan only
through Maximum. The readme describes Performance as the profile that "removes
work from the front end". No committed test pins the contents of any profile's
plan; this was confirmed with a throwaway run over the unit fixtures.

### D-0032 is not enforced

The decision says admin tweaks are in no profile. Three are in Fix Safe Issues
(see 2). Either the decision is superseded or a category filter is added; both
are changes somebody should choose.

### The agency plan unlocks code nothing calls

`FreemiusEntitlementProvider::PLANS['agency']` includes `multisite`.
`NetworkDefaults` has no caller and no test, and
`docs/FEATURES.md:140` says `ProIntegrationTest` covers it. If any storefront
row mentions multisite, it sells nothing.

### Extension points without tests

`debloater_required_capability` (documented in `docs/HOOKS.md:129` and called
internal at `:10-12` of the same file), `debloater_woo_page_needs_block_styles`
and `debloater_woo_page_needs_cart` have no test. `docs/HOOKS.md:21-22` still
offers registry redirection, removed in 0.4.0.

### Elementor Pro cannot be watched for releases

Commercial, not on wordpress.org; the plugin-information API answers 404. Its
updates come from Elementor's own endpoint, which needs a licence key the
pipeline does not have and should not hold. Marked `watch: false` with the
reason. Changes to it are found only when somebody updates the fixture stack by
hand.

### Verification cannot sample assets inside a container

The scanner samples pages over HTTP. Inside wp-env the site's own address
resolves to the container, so `assets.available` is `false` and the asset facts
are absent rather than wrong. `WP_HOME` is a `wp-config` constant in another
process, so no filter reaches it. On a real site this does not apply.

### Pro's feature rows keep needing correction

Three of the four features Pro's plan sold have needed correcting — every one
except scheduled scans — and a fifth was withdrawn. Written down together
because one of these is an oversight and four is a pattern: a row was written
as a promise, and what shipped underneath it was narrower.

| Sold as | What was there | What was done |
|---|---|---|
| Priority registry updates | A filter pointing a check that could not discover a newer release at a repository that was never created | **Withdrawn**, not rebuilt (Pro D-0078) |
| White-label before/after reports | A report that never carried a vendor name to replace, so "white-label" promised a substitution that does not happen — and collided with the licence flag that word already means here (Pro D-0061). It also named no site | **Reworded** to "before/after reports with your name on them"; the site's name and home URL added (Pro 0.3.2) |
| Bulk apply of a saved profile | Built and reachable from nothing | **Deleted** and replaced by portable profiles, which preview and confirm on each site (Pro D-0068) |
| Drift alerts on WordPress & plugin updates | Two scans' **findings** compared, and nothing else. No rule reads a version, so an update was invisible unless it moved an unrelated finding. Nothing was sent | **Built and reworded** (Pro 0.4.0): versions compared in their own block; "alert" removed from everything that ships, with a test. No theme versions |
| Scheduled scans | As described — but the scheduled event had never been fired in a test, nor the entitlement re-check | **Tested** (Pro 0.4.0) |

The wording to use for each row is in `docs/CLAIMS.md` (P1–P5). The rows are
storefront content in the Freemius dashboard, which no test in either
repository can read, and **the current dashboard text is not stored in either
repository** — so whether the corrections have been made there is unknown from
here. The nearest thing available is that the plugin's own description of a
feature is tested: `DisplayNameTest` pins the display name, and
`ReportEndpointTest` asserts the report is what the row says it is.

Pro's own header still says "applying a saved profile in one step"
(`debloater-pro.php:5`), which D-0068 made false.

### Pro integration coverage

Ran only on one machine from the split until `Integration (Pro + Debloater)`
was added to Pro's CI (green). Before that, `ProScreenTest` and
`ProIntegrationTest` were present and **uncollected for four commits**. Pro's
`README.md:91-92` still says CI does not run them.

### ~~`BulkApply` has no interface~~ — resolved, by deletion

Deleted in Pro 0.2.1 (**D-0068**), with a test that fails if any file in Pro
contains `->apply(`, `ConfirmationToken`, `matchesPlan` or `previewTweaks(`.

### ~~The tags are broken~~ — resolved

`v0.1.0` is deleted from the free repository and its remote; it pointed at a
commit from before the Pro split rewrote history. Releases are tagged since
`v0.2.0`; the current tags are free `v0.4.0` and Pro `v0.4.0`. **0.1.1 stays
untagged on purpose**: its content record was found misdated.

### ~~The version check was reading whichever archive was lying about~~ — resolved

`tools/version-discipline.mjs` never asked whether the zip it compared was a
build of the current tree. Fixed in both repositories: the check refuses an
archive older than any file it ships, and says which files.

### The content record misdated itself

`free-plugin-content.json` carried `version: 0.1.1` while its entries were
regenerated twice afterwards. Fixed forward at 0.2.0; the historical claim
cannot be reconstructed without building that commit.

### Pro's source is retrievable from the public free repository

The free repository was briefly public with `pro/` in it. Pro's code remains
reachable by SHA at `699eaced874ba7037f24304874756354a9efabf8` until GitHub
garbage-collects unreachable objects, which needs a support request. Pro is now
public itself, so the exposure no longer reveals anything unpublished; the
request is still owed for hygiene. **Nothing secret was ever committed there.**

### ~~The decision record is duplicated~~ — resolved

`54d19f7` (free) and `a7322aa` (Pro). A test in each repository fails if a
number appears in both.

### The registry pipeline has proposed nothing yet

Its runs have correctly proposed nothing. Every gate is unit-tested and
exercised by a local dry run, but **no real model output has yet been rejected
in production** for missing evidence, and no claimed `safer` has been overruled
outside a fixture.

### Documents that say untrue things

`docs/CLAIMS.md` names every line. By file: `readme.txt` 13 lines,
`README.md` 6, `docs/FEATURES.md` 14 (it is still dated 0.2.0 throughout),
`docs/HOOKS.md` 5, `docs/SCORING.md` 3, `docs/DECISIONS.md` D-0032, Pro
`README.md` 4, Pro `debloater-pro.php` 2, Pro D-0050. None of it changes
behaviour; the readme is the only one users read.

---

## What is left

Ordered by whether it blocks revenue, then by effort.

### Blocks revenue

1. **Upload Pro 0.4.0 to Freemius and correct the storefront.**
   `debloater-pro/dist/debloater-pro-0.4.0.zip` is built. The product name,
   and the plan rows with the wording in `docs/CLAIMS.md` P1–P5, are edited by
   hand in the dashboard; nothing here has credentials for it.
2. **Correct `readme.txt`, then submit to wordpress.org.** The readme has
   thirteen untrue or overstated lines and names screenshots that do not exist; submitting it
   as it stands puts them on the public listing. Hours of forms, then a review
   queue measured in weeks. `hakeemify-debloater` is unreserved until then.

### Does not block revenue, but is owed

3. **Decide D-0032**: enforce it with a category filter, or supersede it.
   Either is a choice about what one click does to other people's dashboards.
4. **Decide the §17 rows in section 3**: bulk apply, the priority channel, drift
   email, per-site overrides, the REST refusal and `runtime_loaded`. Minutes
   each; the spec owner's call.
5. **Tests for what is untested**: the four destructive-recommending rules,
   the dependency refusal (with a fixture registry, since the shipped one cannot
   reach it), the contents of each profile's plan, the three filters and
   `wp debloater registry`.
6. **Request GitHub garbage collection** of the pre-rewrite objects.

### Improves the product

7. **The REST refusal**: a `wp.rest.public` rule, or deleting the mapping and
   the `rest:public` requirement that nothing reads.
8. **Scanner enumerations** the pipeline can see from the CLI: admin notices and
   dashboard widgets outside wp-admin, REST routes, a theme version.
9. **Watch the pipeline's first real proposal**, and read it carefully rather
   than trust it.
10. **Registry CI running the plugin's matrix.** D-0069 costs it at a day.

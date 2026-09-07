# What Debloater and Debloater Pro actually do

Derived from the code and the registry as they stand at **0.2.0**, not from
`BUILD-SPEC.md` and not from memory. Where this disagrees with the
specification, `docs/GAP-ANALYSIS.md` says so rather than this file quietly
matching.

Every row names the class or data file it was read from, so it can be checked.

---

## Free plugin

### The pipeline

A site is scanned into **facts**; facts are analysed into **findings**;
findings and a profile produce a **plan**; a plan is previewed, confirmed,
applied behind a **recovery point**, **verified**, and **committed or rolled
back**. Nothing skips a stage.

| Capability | One line | Where | Reached by | Tested by |
|---|---|---|---|---|
| Scan | Reads the site into a flat FactSet — 72 facts on a stock install | `src/Scan/` | `wp debloater scan`, `POST /scan`, the dashboard | `ScannerTest`, `AssetScanTest`, `AdminIntelligenceTest`, `PluginIntelligenceTest` |
| Analyze | Turns facts into findings, each with a risk band and an explanation | `src/Analyze/` | automatic after a scan | `AnalyzerTest` |
| Debloat Score | A single number with the findings that moved it | `src/Meter/` | dashboard, `wp debloater status` | `MeterTest` |
| Recommend | Facts + profile + registry → a deterministic plan | `src/Recommend/` | preview | `PreviewTest`, `AnalyzerTest` |
| Preview | Shows exactly what would change, and issues a confirmation token for that exact plan | `src/Rest/Routes/PreviewRoute.php` | `wp debloater preview`, `GET /preview`, the dashboard | `PreviewTest`, `WriteRoutesTest` |
| Apply | Executes a confirmed plan, one operation at a time | `src/Apply/` | `wp debloater apply --yes`, `POST /apply` | `ApplyRollbackTest`, `WriteRoutesTest` |
| Recovery point | A snapshot taken *before* anything destructive, checksummed and bound to the site | `src/Snapshot/` | automatic | `ApplyRollbackTest`, `SnapshotSpillTest`, `DestructiveOperationsTest` |
| Verify | Loads real pages as the acting user and checks them | `src/Verify/` | automatic after apply | `VerificationTest`, `AdminProbeAuthTest` |
| Rollback | Restores the recovery point, automatically on verification failure or on request | `src/Apply/`, `RollbackRoute` | automatic, `wp debloater rollback`, `POST /rollback` | `ApplyRollbackTest`, `WriteRoutesTest` |
| Run journal | Every scan and apply recorded with its plan, measurements and outcome | `src/Journal/` | `wp debloater snapshots`, `GET /runs/<id>`, "Changes & recovery" | `ApplyRollbackTest`, `StatusRouteTest` |
| Runtime | Generates a single PHP file implementing the selection; none when the selection is empty | `src/Apply/`, `runtime-handlers/` | automatic | `RuntimeGenerationTest`, `RuntimeOverheadTest` |

### Profiles (0.2.0)

| Capability | One line | Where | Reached by | Tested by |
|---|---|---|---|---|
| Save a profile | Names the changes this site has committed | `src/Config/ProfileStore.php` | Profiles panel, `wp debloater profile save` | `ProfileCliTest`, `ProfileRoutesTest` |
| Export | Writes the profile as a portable JSON file | `src/Config/Profile.php` | panel, `wp debloater profile export` | `ProfileRoutesTest`, `ProfileCliTest` |
| Import | Reads a file, names changes this site lacks, warns on registry mismatch — **and applies nothing** | `src/Rest/Routes/ProfileImportRoute.php` | panel, `wp debloater profile import` | `ProfileImportSafetyTest`, `ProfileRoutesTest` |
| Built-in profiles | `safe`, `performance`, `maximum`, always listed | `registry/profiles/*.json` | everywhere profiles appear | `ProfileRoutesTest` |
| Open by URL | `?page=debloater&debloater_profile=<id>` opens the preview pre-ticked | `admin-ui/src/components/Profiles.js` | a link, including from Pro | `Profiles.test.js` |

### Registry

| Capability | One line | Where | Reached by | Tested by |
|---|---|---|---|---|
| Vendored snapshot | The plugin ships its own copy; a site with no network is missing nothing | `registry/` | automatic | `RegistryPinnedReleaseTest` |
| Signed updates | Opt-in fetch, Ed25519 signature verified against a pinned key before parsing | `src/Update/` | `wp debloater registry` | `RegistryUpdaterTest`, `RegistryPinnedReleaseTest` |
| Schema validation | Every document validated as it loads | `src/Registry/` | automatic | `ScannerTest`, `AnalyzerTest` |

### Interfaces

**Admin screen** (`src/Admin/`, `admin-ui/src/`): Overview with the score and
findings summary, Findings list and detail, Changes & recovery (run history and
rollback), the Profiles panel, and the apply dialog. Extensions may add **text
rows only**, via `debloater_dashboard_panels`.

**WP-CLI** (`src/Cli/Command.php`): `scan`, `findings`, `preview`, `apply`,
`verify`, `registry`, `rollback`, `snapshots`, `status`, `profile`, `export`,
`import`. Covered by `CliTest`, `ProfileCliTest`, and `tools/cli-e2e.sh`
against the real `wp` binary.

**REST** (`debloater/v1`): `POST /scan`, `GET /findings`, `GET /preview`,
`POST /apply`, `POST /rollback`, `GET /runs/<id>`, `GET /snapshots`,
`GET /status`, `GET /profiles`, `POST /profiles/save`, `POST /profiles/import`.
Every state-changing route is capability- and nonce-checked, asserted by
`SecurityRulesTest` and `WriteRoutesTest`.

### Hooks (`docs/HOOKS.md`)

`debloater_loaded`, `debloater_scan_complete`, `debloater_apply_complete`;
filters `debloater_dashboard_panels`, `debloater_registry_origin`,
`debloater_required_capability`. Plus one URL contract,
`?debloater_profile=<id>`. Covered by `ExtensionPointsTest`.

---

## The tweak registry, as data

27 tweaks. **11 safe, 6 low, 10 medium, 0 high.** Five are destructive and
require a Level B recovery point.

| Tweak | Risk | Category | |
|---|---|---|---|
| `admin.hide_update_nags_non_admins` | safe | admin | |
| `admin.remove_dashboard_widgets` | safe | admin | |
| `admin.remove_welcome_panel` | safe | admin | |
| `admin.remove_wp_news_widget` | safe | admin | |
| `admin.suppress_promo_notices` | medium | admin | |
| `core.disable_dashicons_guests` | medium | assets | |
| `core.disable_embeds` | safe | wordpress | |
| `core.disable_emojis` | safe | wordpress | |
| `core.disable_self_pingbacks` | safe | wordpress | |
| `core.heartbeat_interval` | low | wordpress | |
| `core.limit_revisions` | low | database | |
| `core.remove_generator` | safe | wordpress | |
| `core.remove_jquery_migrate` | medium | assets | |
| `core.remove_rsd` | safe | wordpress | |
| `core.remove_shortlink` | safe | wordpress | |
| `db.autoload_off` | low | database | |
| `db.clean_auto_drafts` | low | database | **destructive** |
| `db.clean_expired_transients` | low | database | |
| `db.clean_orphan_meta` | medium | database | **destructive** |
| `db.clean_revisions` | medium | database | **destructive** |
| `db.delete_spam_comments` | low | database | **destructive** |
| `db.empty_trash` | medium | database | **destructive** |
| `elementor.disable_google_fonts` | medium | assets | |
| `woo.block_styles_conditional` | medium | assets | |
| `woo.cart_fragments_conditional` | medium | assets | |
| `woo.disable_admin_analytics` | medium | admin | |
| `woo.suppress_marketplace_suggestions` | safe | admin | |

Supporting data: 10 detectors, 6 compatibility documents, 3 profiles, 9
schemas, plus `admin-notices.json`, `host-optimizers.json` and
`plugin-categories.json`.

**Measured metrics** (`src/Meter/`): `frontend.requests`,
`frontend.head_bytes`, `frontend.scripts.count`, `frontend.styles.count`,
`frontend.external_hosts`, `db.autoload_bytes`, `db.revisions`,
`db.transients_expired`, `cron.events`, `admin.notices`,
`core.heartbeat_interval`. Deltas only — never a speed claim.

---

## Debloater Pro

Separate plugin, separate repository, extending the free plugin only through
documented hooks. **It adds no tweaks and no safety features**, asserted by
`ProArchitectureTest` against both trees.

| Capability | One line | Where | Reached by | Tested by |
|---|---|---|---|---|
| Scheduled scans | Daily or weekly scan; never a scheduled *apply* | `src/Features/ScheduledScans.php` | Pro screen | `ProScreenTest`, `ProIntegrationTest` |
| Drift detection | What changed between the last two scans | `src/Features/DriftDetector.php` | Pro screen, a text panel on the free dashboard | `ProIntegrationTest` |
| Before/after report | A printable document per applied change, with the agency's name on it | `src/Features/BeforeAfterReport.php` | Pro screen → Open | `ProScreenTest` |
| Portable profiles | Save a setup once and take it to every site you manage: apply, export, duplicate, rename, delete, with built-ins always listed. Applying opens Debloater's preview — Pro has no apply path of its own (D-0068) | `src/Admin/ProfilesPanel.php` | Pro screen | `ProProfilesPanelTest`, `ProArchitectureTest` |
| Registry channel | Priority registry updates | `src/Features/RegistryChannel.php` | automatic | `ProIntegrationTest` |
| Licence display | Plan, quota and a way to release the site, on Pro's own screen | `src/Admin/Screen.php` | Pro screen | `ProScreenTest` |
| Entitlement | Freemius behind an interface, cached and offline-tolerant | `src/Entitlement/` | automatic | `EntitlementTest`, `FreemiusIntegrationTest` |
| Cloud client | Optional; with the cloud unreachable Pro degrades to local features | `src/Cloud/` | automatic | `ProIntegrationTest`, `ProArchitectureTest` |
| Multisite groundwork | Network defaults behind a feature flag | `src/Multisite/NetworkDefaults.php` | flag only | `ProIntegrationTest` |

**Pro's screen is server-rendered PHP** with `admin-post.php` form posts, not
React. Its Profiles panel links to Debloater's screen rather than applying
anything itself.

---

## Test inventory

| Suite | Count | Needs |
|---|---|---|
| Free unit | 1191 tests, 12,291 assertions | nothing but PHP |
| Free integration | 334 tests, 4,766 assertions | wp-env |
| Free fail-probe | 9 tests, 105 assertions | wp-env |
| Free JS | 23 tests | Node |
| Free packaging | 13 tests | Node, a `--no-dev` build |
| Pro units + invariants | 26 tests, 196 assertions | PHP, the free plugin checked out |
| Pro integration | 38 tests, 169 assertions | the free plugin's wp-env |
| Registry | 59 tests | Node |

35 test classes in the free plugin. Playwright end-to-end exists
(`e2e.yml`) and runs nightly or on a PR label rather than on every push.

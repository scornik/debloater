# Catalogue: what Hakeemify Debloater checks and what it can do

Derived at **free 0.4.0 / Pro 0.4.0** from the code, the registry and the tests
as they stand — not from `BUILD-SPEC.md`, not from `docs/FEATURES.md`, and not
from memory. Every row names the class, file or registry path it was read from.

Test columns use four words, and nothing softer:

- **named** — a test that builds the triggering input and asserts this item by name.
- **generic** — only the data-provider tests in `tests/Unit/Analyze/RulesTest.php`
  (`test_a_rule_produces_its_own_finding_id`, `test_every_finding_cites_observed_facts`,
  `test_recommended_tweaks_exist`, `test_recommended_parameters_validate`), run
  against `Facts::busyStore()`. Those tests call `addToAssertionCount(1)` and
  return when a rule does not fire, so they only count for rules that fire on
  that fixture.
- **vacuous** — only the generic tests, and the rule does not fire on the
  fixture, so they pass without checking anything.
- **no test** — nothing.

Where a claim elsewhere in the repositories disagrees with this file, it is
listed in `docs/CLAIMS.md` with file and line.

---

## (a) Analyzer rules

33 rules, registered in `src/Analyze/Rules.php` (`Rules::all()`), each in
`src/Analyze/Rules/<Class>.php`. After a rule returns a finding,
`src/Analyze/DontTouchRules.php` may turn a **recommend** into **dont_touch**,
and `src/Analyze/HostOptimizerRules.php` may add reasoning (it never changes
the decision).

**How dont_touch can happen at all** (`DontTouchRules.php`):

1. *Dependency.* `REMOVES_CAPABILITY` (`:56`) maps a finding to a capability;
   if a detected plugin's `registry/compatibility/<slug>.json` lists that
   capability in `requires`, the finding is refused. The map is
   `wp.jquery_migrate.loaded → jquery-migrate`, `wp.dashicons.frontend →
   dashicons:frontend`, `wp.embeds.enabled → embeds`, `wp.xmlrpc.enabled →
   xmlrpc`, `wp.rest.public → rest:public`. **With the shipped registry this
   path cannot fire**: no compatibility document requires `jquery-migrate`,
   `dashicons:frontend` or `embeds`; `wp.xmlrpc.enabled` is an info finding and
   refusal applies to recommend only (`:133`); and no rule emits
   `wp.rest.public`. No test drives it to a refusal —
   `tests/Unit/Analyze/AnalyzerTest.php:114` `test_a_declared_dependency_refuses_a_finding`
   asserts `RECOMMEND`, the opposite of its name.
2. *Affected capability.* `AFFECTS_CAPABILITY` (`:87`) maps
   `wp.heartbeat.aggressive → heartbeat`. It raises `dependencies_detected`,
   which lowers confidence and raises risk (see (b)), and does not refuse.
3. *Situational.* Heartbeat is refused when `users.recent_editors_7d ≥ 2`
   (`COLLABORATIVE_EDITOR_THRESHOLD`, `:96`) and WooCommerce is detected;
   cart fragments are refused when `woo.mini_cart` is true (`:301`). Tested:
   `AnalyzerTest::test_heartbeat_is_refused_on_a_collaborative_store`,
   `WooCommerceScanTest` (mini-cart, `:182`).

Columns: **Sev** default severity, **Risk** the finding's risk, **DT** whether
it can become dont_touch and on what.

| Finding id | Class | Category | Facts read | Fires when | Sev | Risk | DT | Recommends | Test |
|---|---|---|---|---|---|---|---|---|---|
| `plugins.abandoned` | `AbandonedPluginsRule` | plugins | `plugins.active`, `plugins.meta`, `plugins.update_source` | an active plugin's last sign of life (wordpress.org `last_updated`, else file mtime) is older than 730 days; confidence 0.9 with a wordpress.org date, 0.35 on mtime | info | — | no (info) | — | named: `PluginIntelligenceTest` |
| `db.autodrafts.abandoned` | `AutoDraftsRule` | database | `db.autodrafts.count` | count ≥ 25 | low | low | no | `db.clean_auto_drafts` | **no test** (vacuous: does not fire on `busyStore`) |
| `db.autoload.heavy` | `AutoloadRule` | database | `db.autoload.bytes`, `db.autoload.top` | bytes ≥ 512 000 (low) or ≥ 1 048 576 (medium); becomes **info** when no option in `db.autoload.top` matches `AutoloadReview::ALLOWED_PREFIXES` | low / medium / info | low | no | `db.autoload_off` | generic, **info branch only**; the recommend branch has **no test** |
| `woo.cart_fragments.everywhere` | `CartFragmentsRule` | assets | `woo.present`, `woo.fragments_on_other`, `woo.shop_pages`, `woo.pages_sampled`, `woo.mini_cart_pages` | WooCommerce present and fragments loaded on ≥ 1 sampled non-shop page | medium | medium | **yes**: `woo.mini_cart` true | `woo.cart_fragments_conditional` | named: `WooCommerceScanTest` |
| `assets.cf7.everywhere` | `Cf7AssetsRule` | assets | `assets.cf7_asset_pages`, `assets.cf7_form_pages`, `assets.pages_sampled`, `assets.post_types` | CF7 assets on more sampled pages than have a form | info | — | no | — | named: `AssetScanTest` |
| `admin.dashboard_widgets.crowded` | `DashboardWidgetsRule` | admin | `admin.dashboard_widgets`(`.count`) | ≥ 5 widgets (`THRESHOLD`) | info | — | no | — (`admin.remove_dashboard_widgets` is never recommended) | named: `AdminRulesTest` |
| `wp.dashicons.frontend` | `DashiconsFrontendRule` | assets | `wp.dashicons_frontend` | fact true | low | medium | dependency path only — **cannot fire** (above) | `core.disable_dashicons_guests` | named: `RulesTest` |
| `plugins.duplicate_functionality` | `DuplicateFunctionalityRule` | plugins | `plugins.active`, `plugins.categories` | ≥ 2 active plugins in one `registry/plugin-categories.json` category | info | — | no | — | named: `PluginIntelligenceTest` |
| `elementor.widgets.audit` | `ElementorAuditRule` | plugins | `elementor.present`, `.widgets_available`, `.widgets_in_use`, `.packs`, `.widgets`, `.documents`, `.templates`, `.dynamic_tags`, `.shortcodes`, `.custom_code` | Elementor present, ≥ 1 widget available, usage arrays readable; confidence 0.8 minus 0.15 per caveat, floor 0.3 | info | — | no | — | named: `ElementorAuditTest` |
| `wp.embeds.enabled` | `EmbedsRule` (`CoreFeatureRule`) | wordpress | `wp.embeds_enabled` | fact true | low | safe | dependency path only — **cannot fire** | `core.disable_embeds` | named: `RulesTest`, `Integration/AnalyzerTest` |
| `wp.emojis.loaded` | `EmojiScriptRule` (`CoreFeatureRule`) | wordpress | `wp.emojis_enabled` | fact true | low | safe | no (host-optimizer reasoning only) | `core.disable_emojis` | named: `RulesTest`, `PluginIntelligenceTest` |
| `db.transients.expired` | `ExpiredTransientsRule` | database | `db.transients.expired`, `db.transients.count` | ≥ 50 (low), ≥ 1000 (medium) | low / medium | low | no | `db.clean_expired_transients` | named: `RulesTest`, `ScannerTest` |
| `wp.file_editor.enabled` | `FileEditorRule` | configuration | `wp.file_editor_enabled`, `users.admin_count` | fact true | low | — | no (info) | — | named: `RulesTest` |
| `wp.generator.exposed` | `GeneratorTagRule` (`CoreFeatureRule`) | wordpress | `wp.generator_tag` | fact true | low | safe | no | `core.remove_generator` | named: `RulesTest`, `AnalyzerTest` (both) |
| `wp.heartbeat.aggressive` | `HeartbeatIntervalRule` | wordpress | `wp.heartbeat_interval`, `plugins.detected`, `users.admin_count`, `users.recent_editors_7d` | interval < 60 s; proposes 60 on a store or with > 1 admin, else 120 | low | low | **yes**: ≥ 2 recent editors on WooCommerce | `core.heartbeat_interval` | named: `RulesTest`, `AnalyzerTest`, `RecommendationEngineTest` |
| `plugins.host_optimizer_detected` | `HostOptimizerRule` | plugins | `plugins.host_optimizers`, `env.host_vendor` | ≥ 1 named optimizer from `registry/host-optimizers.json` | info | — | no | — | named: `PluginIntelligenceTest` |
| `plugins.inactive_present` | `InactivePluginsRule` | plugins | `plugins.inactive`, `plugins.active` | ≥ 1 inactive plugin | info | — | no | — | named: `RulesTest` |
| `wp.jquery_migrate.loaded` | `JqueryMigrateRule` | assets | `wp.jquery_migrate` | fact true | low | medium | dependency path only — **cannot fire** | `core.remove_jquery_migrate` | named: `RulesTest`, `RecommendationEngineTest` |
| `admin.news_widget.present` | `NewsWidgetRule` | admin | `admin.dashboard_widgets` | `dashboard_primary` present | low | safe | no | `admin.remove_wp_news_widget` | named: `AdminRulesTest` |
| `db.meta.orphaned` | `OrphanMetaRule` | database | `db.orphan_postmeta.count`, `db.orphan_termmeta.count`, `db.orphan_usermeta.count` | sum ≥ 200 | low | medium | no | `db.clean_orphan_meta` | **no test** (vacuous) |
| `admin.notices.from_plugins` | `PluginNoticesRule` | admin | `admin.notices`(`.count`), `admin.notice_vendors` | ≥ 3 notices attributable to allowlisted vendors (`registry/admin-notices.json`) | low | medium | no | `admin.suppress_promo_notices` | named: `AdminRulesTest` |
| `db.revisions.unlimited` | `RevisionsUnlimitedRule` | database | `wp.revisions_limit`, `db.revisions.count`, `db.size_bytes` | limit −1 and count ≥ 200 (medium at ≥ 5000) | low / medium | low | no | `core.limit_revisions` | named: `RulesTest::test_revisions_needs_both_the_setting_and_the_evidence` |
| `wp.rsd.exposed` | `RsdLinkRule` (`CoreFeatureRule`) | wordpress | `wp.rsd_link` | fact true | info | safe | no | `core.remove_rsd` | named: `RulesTest`, `Integration/AnalyzerTest` |
| `wp.self_pingbacks.enabled` | `SelfPingbackRule` (`CoreFeatureRule`) | maintenance | `wp.self_pingbacks` | fact true | low | safe | no | `core.disable_self_pingbacks` | generic for firing; named only for not firing (`RulesTest`) |
| `wp.shortlink.exposed` | `ShortlinkRule` (`CoreFeatureRule`) | wordpress | `wp.shortlink` | fact true | info | safe | no | `core.remove_shortlink` | named: `RulesTest`, `Integration/AnalyzerTest` |
| `db.comments.spam` | `SpamCommentsRule` | database | `db.spam_comments.count` | ≥ 100 (medium at ≥ 2000) | low / medium | low | no | `db.delete_spam_comments` | **no test** (vacuous) |
| `db.revisions.stored` | `StoredRevisionsRule` | database | `db.revisions.count`, `db.size_bytes` | ≥ 500 (medium at ≥ 5000) | low / medium | medium | no | `db.clean_revisions` | generic only |
| `db.trash.pending` | `TrashRule` | database | `db.trash.count` | ≥ 20 | low | medium | no | `db.empty_trash` | **no test** (vacuous) |
| `admin.welcome_panel.visible` | `WelcomePanelRule` | admin | `admin.welcome_panel` | fact true | low | safe | no | `admin.remove_welcome_panel` | named: `AdminRulesTest` |
| `woo.analytics.enabled` | `WooAnalyticsRule` | admin | `woo.present`, `woo.admin_analytics`, `woo.version` | WooCommerce present and analytics on | low | medium | no | `woo.disable_admin_analytics` | generic only |
| `woo.block_styles.everywhere` | `WooBlockStylesRule` | assets | `woo.present`, `woo.block_styles_on_other`, `woo.shop_pages`, `woo.pages_sampled` | WooCommerce present and block styles on ≥ 1 sampled non-shop page | low | medium | no | `woo.block_styles_conditional` | generic only |
| `woo.marketplace.suggestions` | `WooMarketplaceRule` | admin | `woo.present`, `woo.marketplace_suggestions` | WooCommerce present and suggestions on | low | safe | no | `woo.suppress_marketplace_suggestions` | generic only |
| `wp.xmlrpc.enabled` | `XmlRpcRule` | configuration | `wp.xmlrpc_enabled`, `wp.rsd_link` | fact true | low | — | no (info; the dependency map lists it but refusal skips info) | — | named: `RulesTest`, `Integration/AnalyzerTest` |

**Totals.** 33 rules: 24 recommend a tweak, 9 are info-only. Tests: 22 named;
6 generic only (`wp.self_pingbacks.enabled`, `db.revisions.stored`,
`woo.analytics.enabled`, `woo.block_styles.everywhere`,
`woo.marketplace.suggestions`, and the info branch of `db.autoload.heavy`,
whose recommend branch has no test); and **4 with no test at all**
(`db.autodrafts.abandoned`, `db.meta.orphaned`, `db.comments.spam`,
`db.trash.pending`) — four of the five rules that recommend a destructive
operation.

---

## (b) Tweaks

26 tweaks, one JSON document each in `registry/tweaks/<id>.json`. Config
tweaks run through `runtime-handlers/<file>.php`, loaded from the
`debloater_runtime` option by `src/Apply/Runtime.php`; data tweaks are
`src/Apply/DataOperations/<Class>.php`. "Breaks" is the registry's own `breaks`
list, paraphrased.

**Effect test** means a test asserts the change happened on a real
WordPress. Several tweaks are applied in tests whose assertions are about
something else (rollback, plan shape, verification); those are marked
**applied, effect not asserted**.

| Id | Cat. | Risk | Kind | Destr. | Handler | Runtime effect | Can break | Rule | Effect test |
|---|---|---|---|---|---|---|---|---|---|
| `admin.remove_dashboard_widgets` | admin | safe | config | no | `admin-remove-dashboard-widgets.php` | `remove_meta_box` for the chosen ids on `wp_dashboard_setup` 99 | a widget, and any notice only shown in it | **none recommends it** | `AdminIntelligenceTest` |
| `admin.remove_welcome_panel` | admin | safe | config | no | `admin-remove-welcome-panel.php` | removes `wp_welcome_panel` | one-click shortcuts on the panel | `admin.welcome_panel.visible` | `AdminIntelligenceTest` |
| `admin.remove_wp_news_widget` | admin | safe | config | no | `admin-remove-wp-news-widget.php` | `remove_meta_box( 'dashboard_primary' )` on `wp_dashboard_setup` 99 | WordPress news and events on the dashboard | `admin.news_widget.present` | `AdminIntelligenceTest` |
| `admin.suppress_promo_notices` | admin | medium | config | no | `admin-suppress-promo-notices.php` | on `admin_head`, unhooks notice callbacks whose `plugin_basename` belongs to a chosen vendor | operational notices from those plugins too (pending DB update, expiring licence) | `admin.notices.from_plugins` | `AdminIntelligenceTest` |
| `core.disable_dashicons_guests` | assets | medium | config | no | `core-disable-dashicons-guests.php` | dequeues and deregisters `dashicons` for logged-out visitors on `wp_enqueue_scripts` 99 | icon fonts in a theme's menu or search, silently | `wp.dashicons.frontend` | **no test** (registry load only: `LoaderTest`) |
| `core.disable_embeds` | wordpress | safe | config | no | `core-disable-embeds.php` | removes oEmbed discovery links, host JS and the oEmbed route; filters rewrite rules and TinyMCE plugins | other sites' preview cards of your posts | `wp.embeds.enabled` | **no test** (registry load only) |
| `core.disable_emojis` | wordpress | safe | config | no | `core-disable-emojis.php` | removes emoji detection script, styles and staticize filters | fallback glyphs on browsers without emoji fonts | `wp.emojis.loaded` | applied, effect not asserted (`ApplyRollbackTest`, `RuntimeGenerationTest`) |
| `core.disable_self_pingbacks` | wordpress | safe | config | no | `core-disable-self-pingbacks.php` | strips own-site links on `pre_ping` | internal pingback comments | `wp.self_pingbacks.enabled` | `ScannerTest::test_core_features_track_what_is_actually_registered`, `RuntimeOverheadTest` |
| `core.heartbeat_interval` | wordpress | low | config | no | `core-heartbeat-interval.php` | filters `heartbeat_settings` interval | slower post-lock notices; less frequent autosave | `wp.heartbeat.aggressive` | applied, effect not asserted (`RuntimeGenerationTest` checks parameters) |
| `core.limit_revisions` | database | low | config | no | `core-limit-revisions.php` | filters `wp_revisions_to_keep` | older revisions pruned on next save | `db.revisions.unlimited` | **no test** (registry load, `ProfileTest`) |
| `core.remove_generator` | wordpress | safe | config | no | `core-remove-generator.php` | removes `wp_generator`; filters `the_generator` | registry lists nothing | `wp.generator.exposed` | `ScannerTest`, `RuntimeOverheadTest` |
| `core.remove_jquery_migrate` | assets | medium | config | no | `core-remove-jquery-migrate.php` | drops `jquery-migrate` from `jquery`'s dependencies on `wp_default_scripts` | old jQuery code on the front end, silently | `wp.jquery_migrate.loaded` | applied, effect not asserted (rollback and verification tests) |
| `core.remove_rsd` | wordpress | safe | config | no | `core-remove-rsd.php` | removes `rsd_link` | auto-discovery by desktop blogging clients | `wp.rsd.exposed` | `ScannerTest`, `RuntimeOverheadTest` |
| `core.remove_shortlink` | wordpress | safe | config | no | `core-remove-shortlink.php` | removes `wp_shortlink_wp_head` and `wp_shortlink_header` | shortlink discovery for sharing tools | `wp.shortlink.exposed` | applied, effect not asserted (`ApplyRollbackTest`) |
| `db.autoload_off` | database | low | data | no | `AutoloadReview` | sets autoload off on options ≥ 4096 bytes whose names start with an `ALLOWED_PREFIXES` entry; recoverable | registry lists nothing | `db.autoload.heavy` | `DestructiveOperationsTest` |
| `db.clean_auto_drafts` | database | low | data | **yes** | `AutoDraftsCleanup` | deletes `auto-draft` posts older than 30 days (default) | abandoned drafts, permanently | `db.autodrafts.abandoned` | `DestructiveOperationsTest` |
| `db.clean_expired_transients` | database | low | data | no | `ExpiredTransientsCleanup` | deletes expired transient/timeout pairs, restorable row for row | registry lists nothing | `db.transients.expired` | `DestructiveOperationsTest`, `SnapshotSpillTest` |
| `db.clean_orphan_meta` | database | medium | data | **yes** | `OrphanMetaCleanup` | deletes meta rows whose parent is gone, for `post`, `term`, `user` **and `comment`** by default | meta a plugin expected to reuse by id | `db.meta.orphaned` | `DestructiveOperationsTest` |
| `db.clean_revisions` | database | medium | data | **yes** | `RevisionsCleanup` | deletes revisions beyond the newest 5 per post (default, 0–50) | older revisions, permanently | `db.revisions.stored` | `DestructiveOperationsTest` |
| `db.delete_spam_comments` | database | low | data | **yes** | `SpamCommentsCleanup` | deletes spam comments older than 30 days (default) | a false positive in the spam queue | `db.comments.spam` | `DestructiveOperationsTest` |
| `db.empty_trash` | database | medium | data | **yes** | `TrashCleanup` | deletes trashed posts older than 30 days (default) | trashed content, permanently | `db.trash.pending` | `DestructiveOperationsTest`, `DestructiveRefusalTest` |
| `elementor.disable_google_fonts` | assets | medium | config | no | `elementor-disable-google-fonts.php` | returns false from `elementor/frontend/print_google_fonts`; requires `fact:plugins.detected.elementor=true` | fallback fonts; editor and page disagree | **none recommends it** | `ElementorScanTest` |
| `woo.block_styles_conditional` | assets | medium | config | no | `woo-block-styles-conditional.php` | dequeues WooCommerce block styles on pages with no Woo block, unless `debloater_woo_page_needs_block_styles` says otherwise; requires Woo detected | styling of a Woo block the page check cannot see | `woo.block_styles.everywhere` | applied, effect not asserted (`WooCommerceScanTest`: checkout probe passes with it applied; unregisters cleanly) |
| `woo.cart_fragments_conditional` | assets | medium | config | no | `woo-cart-fragments-conditional.php` | dequeues `wc-cart-fragments` away from shop pages, unless `debloater_woo_page_needs_cart`; requires Woo detected | header cart totals stop updating | `woo.cart_fragments.everywhere` | applied, effect not asserted (same) |
| `woo.disable_admin_analytics` | admin | medium | config | no | `woo-disable-admin-analytics.php` | removes `analytics` from `woocommerce_admin_features`; requires Woo detected | Analytics menu; import gap on re-enable | `woo.analytics.enabled` | applied, effect not asserted (same) |
| `woo.suppress_marketplace_suggestions` | admin | safe | config | no | `woo-suppress-marketplace-suggestions.php` | returns false from `woocommerce_allow_marketplace_suggestions` **and** true from `woocommerce_helper_suppress_admin_notices`; requires Woo detected | registry says only "extension recommendations". **Understated**: the second filter also hides WooCommerce's Helper notices on the Updates screen, including available extension updates (WooCommerce 11.1.0 `class-wc-helper.php`) | `woo.marketplace.suggestions` | applied, effect not asserted (same) |

**Totals by risk:** 10 safe, 6 low, 10 medium, **0 high**.
**By category:** wordpress 7, database 8, admin 6, assets 5.
**By kind:** 19 config, 7 data. **Destructive:** 5.
**Effect tests:** 15 asserted, 8 applied without the effect asserted, and
**3 with no behaviour test at all** (`core.disable_dashicons_guests`,
`core.disable_embeds`, `core.limit_revisions`). The effect of
`woo.suppress_marketplace_suggestions` on Helper notices is untested either way.
`tests/Unit/Recommend/ShippedDestructiveTweaksTest.php` checks the registry's
flags and wording for data tweaks, not their effect.
**Never recommended by any rule:** `admin.remove_dashboard_widgets`,
`elementor.disable_google_fonts`. Both are reachable only by selecting them by id.

### Which tweaks a Safe plan can contain

"Fix Safe Issues" and `--profile=safe` do not read `registry/profiles/safe.json`:
`Plugin::preview()` (`src/Plugin.php`) sends the `safe` id to
`PreviewPlanner::safePlan()`, which admits a tweak only if it is not
destructive and its **assessed** risk passes `Risk::isSafePlanEligible()`
(`src/Contracts/Risk.php:47`: safe or low). Only tweaks recommended by a
non-refused finding are candidates.

Assessed risk is raised one level by `src/Recommend/RiskEngine.php` when the
finding has `dependencies_detected > 0` **or** `env.host_vendor` is
`unknown`. `src/Scan/HostVendor.php` recognises only WP Engine, Kinsta,
SiteGround and a LiteSpeed server.

- **Recognised host:** at most **13** — the 9 safe tweaks a rule recommends
  (`admin.remove_welcome_panel`, `admin.remove_wp_news_widget`,
  `core.disable_embeds`, `core.disable_emojis`, `core.disable_self_pingbacks`,
  `core.remove_generator`, `core.remove_rsd`, `core.remove_shortlink`,
  `woo.suppress_marketplace_suggestions`) plus the 4 low non-destructive ones
  (`core.heartbeat_interval`, `core.limit_revisions`, `db.autoload_off`,
  `db.clean_expired_transients`). On a WooCommerce site
  `core.heartbeat_interval` is raised to medium, because
  `registry/compatibility/woocommerce.json` requires `heartbeat`.
- **Any other host:** at most **9** — every low tweak becomes medium, every
  safe tweak becomes low and stays eligible.

The same raise applies to the Performance and Maximum profiles, which filter on
assessed risk (`src/Recommend/RecommendationEngine.php` runs `RiskEngine`
first). On an unrecognised host all ten medium tweaks are assessed **high**, so
**Performance contains none of them** and they reach a plan only through
Maximum; on a recognised host Maximum and Performance are the same plan unless
a dependent raises something.

**Three admin tweaks are in Fix Safe Issues.** `admin.remove_welcome_panel`,
`admin.remove_wp_news_widget` and `woo.suppress_marketplace_suggestions`
(category admin) are safe, recommended by a rule, and admitted by
`safePlan()` and both profiles: nothing in `src/Recommend/` or
`src/Registry/Profile.php` looks at a tweak's category. `docs/DECISIONS.md`
D-0032 says "None of them is in any profile … they are selected individually
or not at all". No test asserts either way.

The statements in this subsection were confirmed by running the analyzer,
engine and planner over the unit fixtures `Facts::freshInstall()` and
`Facts::busyStore()` on a recognised (`kinsta`) and an `unknown` host, in a
throwaway test that was deleted afterwards. On `kinsta` the busy store's
Performance and Maximum plans were identical; on `unknown` Performance held no
declared-medium tweak and Maximum held all of them. That run is not coverage:
**no committed test pins the contents of any profile's plan.**

A saved profile, `--tweaks=` and `POST /apply` with `tweaks` go through
`Plugin::previewTweaks()`, which applies no profile, no safe-only rule and no
destructive filter. **Several destructive operations can be in one plan**
that way; each still needs confirmation and a complete Level B recovery point.

---

## (c) Facts collected that no rule reads

102 facts are declared in `registry/schemas/fact.schema.json`; all 102 are
emitted by a scanner in `src/Scan/Scanners/`. 63 are read by a rule. **39 are
read by no rule.** Of those, some are read by something else; that is noted.

| Fact | Emitted by | Read by anything else |
|---|---|---|
| `admin.menu_items`, `admin.menu_items.count` | `AdminScanner` | no |
| `admin.scripts`, `admin.scripts.count` | `AdminScanner` | no |
| `admin.styles`, `admin.styles.count` | `AdminScanner` | no |
| `admin.update_nag` | `AdminScanner` | no |
| `assets.available`, `assets.unavailable_reason` | `AssetScanner` | no |
| `assets.elapsed_ms`, `assets.pages_offered` | `AssetScanner` | no |
| `assets.external_hosts`, `assets.google_fonts` | `AssetScanner` | no (Meter measures `frontend.external_hosts` separately) |
| `assets.scripts`(`.count`), `assets.styles`(`.count`) | `AssetScanner` | no |
| `cron.disable_wp_cron`, `cron.events.count`, `cron.events.subminute`, `cron.orphans.count` | `CronScanner` | **no rule reads any cron fact**, although `docs/SCORING.md:38` lists cron under Maintenance |
| `elementor.experiments`, `elementor.fonts`, `elementor.pro`, `elementor.version` | `ElementorScanner` | no |
| `env.cache_plugin` | `EnvironmentScanner` | `src/Analyze/ConfidenceCalculator.php` |
| `env.is_multisite`, `env.php_version` | `EnvironmentScanner` | no |
| `env.wp_version` | `EnvironmentScanner` | Pro `src/Features/DriftDetector.php` |
| `scan.elapsed_ms`, `scan.failed`, `scan.over_budget`, `scan.scanner_ms` | `src/Scan/ScanRunner.php` | journal and status output |
| `theme.active` | `ThemeScanner` | `DontTouchRules`, `CompatibilityResolver` |
| `theme.parent` | `ThemeScanner` | no |
| `woo.mini_cart` | `WooCommerceScanner` | `DontTouchRules` (refusal) |
| `woo.other_pages` | `WooCommerceScanner` | no |
| `wp.debug`, `wp.rss_enabled` | `WordPressScanner` / `CoreFeatureScanner` | no |

Also absent rather than unread: **there is no theme version fact** (only
`theme.active` and `theme.parent`), **no REST route enumeration**, and **no
orphaned comment-meta count**, although `db.clean_orphan_meta` deletes orphaned
comment meta by default. `admin.notices` and `admin.dashboard_widgets` are
enumerations with their sources, but `AdminScanner` fills them only when the
scan runs inside wp-admin; a `wp debloater scan` records them empty.

On a clean install the scanner records 65 facts; the dev site with five
plugins records 72.

---

## (d) Supporting machinery

### Detectors — `registry/detectors/*.json`

Ten, each setting `plugins.detected.<slug>` when its plugin file or constant is
present: `contact-form-7`, `elementor`, `elementor-pro`, `litespeed-cache`,
`rank-math`, `woocommerce`, `wordfence`, `wp-rocket`, `wp-super-cache`,
`yoast`. Loaded and validated by `src/Registry/Loader.php`; covered by
`LoaderTest`, `RegistrySchemaTest`, `PluginIntelligenceTest`. `wp-rocket`,
`wp-super-cache`, `yoast`, `rank-math` and `wordfence` have no compatibility
document and no rule or tweak that reads their detection beyond
`plugins.detected`.

### Compatibility rules — `registry/compatibility/*.json`

| Subject | `requires` | What it changes in practice |
|---|---|---|
| `contact-form-7` | `rest:public`, `jquery` | nothing: no rule emits `wp.rest.public`, and no tweak removes `jquery` |
| `elementor` | `jquery`, `rest:auth` | nothing |
| `elementor-pro` | `jquery`, `rest:auth` | nothing |
| `litespeed-cache` | — | nothing |
| `woocommerce` | `heartbeat`, `jquery`, `rest:auth` | raises `wp.heartbeat.aggressive`'s `dependencies_detected`: lower confidence, risk low → medium |
| `wordfence` | `cron:wp`, `rest:auth` | nothing |

Read by `DontTouchRules` and `src/Recommend/CompatibilityResolver.php`.
Schema covered by `RegistrySchemaTest`; the only behavioural effect is covered
by `AnalyzerTest` (heartbeat).

`registry/host-optimizers.json`: LiteSpeed Cache (by detector) and SiteGround
Optimizer (by host vendor), both covering `wp.emojis.loaded`. Adds reasoning
only (`HostOptimizerRules`); `PluginIntelligenceTest`.
`registry/admin-notices.json`: vendor allowlist for `PluginNoticesRule` and
the promo-notice handler; `AdminRulesTest`, `AdminIntelligenceTest`.
`registry/plugin-categories.json`: categories for
`DuplicateFunctionalityRule`; `PluginIntelligenceTest`.

### Verification probes — `src/Verify/Probes/`

Run after every apply by `src/Verify/Verifier.php`; any FAIL rolls back, WARN
or UNKNOWN commits as "verified with warnings".

| Probe | Checks | Test |
|---|---|---|
| `HomeProbe` | front page returns 200 with the expected markers | `VerificationTest` |
| `ContentPageProbe` | a published post or page renders; NOT_TESTED when there is none | `VerificationTest` |
| `AdminProbe` | wp-admin renders for the acting user, with an auth cookie | `AdminProbeAuthTest` |
| `LoginProbe` | `wp-login.php` renders; WARN, not FAIL, on failure | `VerificationTest` |
| `RestProbe` | REST index answers | `VerificationTest` |
| `WooCartProbe`, `WooCheckoutProbe`, `WooAccountProbe` | the shop page renders; only when WooCommerce is present and the page exists | `WooCommerceScanTest` |

§11's `runtime_loaded` probe does not exist. When loopback is blocked the HTTP
probes return UNKNOWN, which commits with warnings rather than rolling back.

### Meter metrics — `src/Meter/Meter.php` `METRICS`

Measured before and after an apply and stored on the run. Covered by
`MeterTest`.

| Metric | Source |
|---|---|
| `frontend.requests`, `frontend.scripts.count`, `frontend.styles.count`, `frontend.head_bytes`, `frontend.external_hosts` | a loopback fetch of the front page (`PageMetrics`) |
| `db.autoload_bytes`, `db.revisions`, `db.transients_expired` | database counts |
| `cron.events` | the cron array |
| `admin.notices` | notice callbacks |
| `admin_ajax_requests_per_hour` | **derived, not measured**: 3600 ÷ heartbeat interval × admin count |

### Scoring inputs — `src/Analyze/Score.php`

Six scored categories: wordpress, configuration, database, plugins,
maintenance, admin. Assets is unscored and reported in
`unscored_categories`. Each finding costs its severity's penalty (info 0,
low 4, medium 10, high 20) in its category, whatever its decision, except
dont_touch which costs 0. Sub-score = 100 − min(100, Σ); headline = mean of
the six. Covered by `tests/Unit/Analyze/AnalyzerTest.php` (rubric penalties;
refused findings cost nothing) and `Integration/AnalyzerTest::test_the_score_records_its_rubric_version`.
There is no test of the headline arithmetic on its own.

What follows from the rule table and is not written anywhere else:

- **The Plugins sub-score is always 100.** Every plugins-category rule has
  info severity. It raises the headline on every site, which is exactly what
  `docs/SCORING.md:53-56` gives as the reason Admin was once left unscored.
- `wp.rsd.exposed` and `wp.shortlink.exposed` recommend a change and cost
  nothing (info severity).
- The configuration sub-score moves only through two info findings with low
  severity (`wp.file_editor.enabled`, `wp.xmlrpc.enabled`), neither of which
  Debloater offers to change.
- Five recommend rules sit in the unscored assets category, so the score
  cannot reflect applying `core.disable_dashicons_guests`,
  `core.remove_jquery_migrate` or either WooCommerce asset tweak.

### WP-CLI — `src/Cli/Command.php`

| Command | Line | Does | Test |
|---|---|---|---|
| `scan [--check-plugin-updates]` | 135 | scans and records a run; the flag asks wordpress.org for release dates | `CliTest`, `tools/cli-e2e.sh` |
| `findings [--risk=<low\|medium\|high>]` | 206 | lists findings from the last scan; `--risk=safe` is refused by WP-CLI's synopsis | `CliTest` |
| `preview [--profile] [--tweaks]` | 316 | builds a plan, changes nothing | `CliTest`, `PreviewTest` |
| `apply [--profile] [--tweaks] [--yes]` | 375 | recovery point, apply, verify; exit 0/1/2/3 | `CliTest`, `ApplyRollbackTest` |
| `verify [--e2e]` | 433 | runs the probes without changing anything | `CliTest` |
| `registry` | 514 | prints the bundled registry's version and counts | **no test** |
| `rollback [<snapshot-id>] [--yes]` | 575 | restores a recovery point | `CliTest` |
| `snapshots list\|show\|delete` | 646 | recovery-point management | `CliTest` |
| `status` | 719 | last scan, score, active runtime | `CliTest`, `StatusRouteTest` |
| `profile list\|save\|export\|import\|apply` | 832 | saved profiles | `ProfileCliTest` |
| `export [--file=<dash>]` | 1224 | configuration document to `uploads/debloater/` or stdout | `CliExportPathTest` |
| `import <file> [--apply --yes]` | 1311 | validates a configuration document; applies only with both flags | `CliTest`, `ProfileImportSafetyTest` |

### REST — `src/Rest/Routes/`, namespace `debloater/v1`

`POST /scan`, `GET /findings`, `GET /preview`, `POST /apply`,
`POST /rollback`, `GET /runs/<id>`, `GET /snapshots`, `GET /status`,
`GET /profiles`, `POST /profiles/save`, `POST /profiles/import`.
Capability and nonce checks: `SecurityRulesTest`, `WriteRoutesTest`.

---

## (e) Free and Pro

### What only Pro does — `debloater-pro/src/`

| Feature | Class | Plan key (`FreemiusEntitlementProvider::PLANS`) | Test |
|---|---|---|---|
| Scheduled scans (daily/weekly scan; never an apply) | `Features/ScheduledScans.php` | `scheduled_scans` (pro, agency) | `ProScreenTest`, `ProIntegrationTest` (hook firing and lapsed entitlement) |
| Drift: findings diff and version diff between the last two scans | `Features/DriftDetector.php`, `Features/DriftReport.php` | `drift_detection` | `ProIntegrationTest` |
| Before/after report with the agency's name, site name and address | `Features/BeforeAfterReport.php` | `white_label_report` (key name only; the word is not used for the feature) | `ProScreenTest`, `ReportEndpointTest` |
| Portable profiles panel (links into Debloater's preview; no apply of its own) | `Admin/ProfilesPanel.php` | `portable_profiles` | `ProProfilesPanelTest`, `ProArchitectureTest` |
| Licence display | `Admin/Screen.php` | — | `ProScreenTest` |
| Entitlement | `Entitlement/*` | — | `EntitlementTest`, `FreemiusIntegrationTest` |
| Cloud client, optional | `Cloud/*` | — | `ProArchitectureTest` |
| Network defaults | `Multisite/NetworkDefaults.php` | `multisite` (agency only) | **no test**. Stores and returns defaults behind `DEBLOATER_PRO_MULTISITE`; **nothing calls `defaults()` or `setDefaults()`**, so the agency plan's `multisite` key unlocks code with no caller |

Everything that touches a site is in the free plugin; Pro adds no tweak,
handler, registry, recovery, verification or rollback
(`ProArchitectureTest`).

### Every extension point, and whether Pro uses it

| Extension point | Kind | Defined at | Pro uses | Test |
|---|---|---|---|---|
| `debloater_loaded` | action | `src/Plugin.php:225` | **yes** — boots Pro (`debloater_pro_boot`) | `ExtensionPointsTest` |
| `debloater_scan_complete` | action | `src/Plugin.php:501` | no | `ExtensionPointsTest` |
| `debloater_apply_complete` | action | `src/Plugin.php:884` | no | `ExtensionPointsTest` |
| `debloater_dashboard_panels` | filter | `src/Admin/Screen.php:177` | **yes** — drift text panel | `ExtensionPointsTest` |
| `debloater_required_capability` | filter | `src/Security/Capabilities.php:75` | no | **no test** |
| `debloater_tweak_options` | filter | `src/Snapshot/SnapshotManager.php:538` | no | `ApplyRollbackTest` |
| `debloater_woo_page_needs_block_styles` | filter | `runtime-handlers/woo-block-styles-conditional.php:90` | no (for themes) | **no test** |
| `debloater_woo_page_needs_cart` | filter | `runtime-handlers/woo-cart-fragments-conditional.php:111` | no (for themes) | **no test** |
| `?page=debloater&debloater_profile=<id>` | URL contract | `admin-ui/src/components/Profiles.js` | **yes** | `ProfileRoutesTest`, `Profiles.test.js` |

**Pro also calls the free plugin's PHP classes directly**, which no hook
document admits:

| Free class | Used by Pro in |
|---|---|
| `Debloater\Plugin` (`scan(false)`, `runs()`, `findingsOf()`, `registry()`) | `Pro.php`, `Features/ScheduledScans.php`, `Features/DriftDetector.php`, `Features/BeforeAfterReport.php` |
| `Debloater\Brand` (`MENU_SLUG`) | `Admin/ProfilesPanel.php`, `Admin/Screen.php` |
| `Debloater\Config\Profile`, `Debloater\Config\ProfileStore` | `Pro.php`, `Admin/ProfilesPanel.php` |
| `Debloater\Contracts\{Finding, Run, RunState, RunType, FactSet, ContractViolation}` | `Pro.php`, `Features/DriftDetector.php`, `Features/DriftReport.php`, `Features/BeforeAfterReport.php`, `Admin/ProfilesPanel.php` |
| `Debloater\Security\Capabilities` (`currentUserCanManage`) | `Admin/ProfilesPanel.php`, `Admin/Screen.php` |

None of these is a documented contract, and none is covered from Pro's side
except through `ProIntegrationTest` exercising the features that use them.

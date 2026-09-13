# Gap analysis: `docs/FEATURES.md` against `BUILD-SPEC.md`

Phase by phase, at **free 0.2.0** and **Pro 0.2.1**. Four verdicts:

- **As specified** — built, and built the way §17 describes it.
- **Differently** — built, not the way the spec says. How, and the decision.
- **Not built** — specified and absent.
- **Not in the spec** — built, and the spec does not mention it.

Written to be checked against the specification by somebody else. Nothing here
is softened; where something is unproven it says unproven rather than done.

---

## Phases 0–9 — the engine and the MVP

| Phase | Verdict | Notes |
|---|---|---|
| 0 Architecture & contracts | As specified | Contracts final, readonly, validating on construction (D-0002) |
| 1 Minimal runtime engine | As specified | Empty selection produces no runtime file and no hooks (`RuntimeOverheadTest`) |
| 2 Scanner | **Differently** | Facts are flags and counts, not enumerations. See "the three families" below |
| 3 Analyzer + findings + score | As specified | Includes Don't Touch and the Debloat Score |
| 4 Recommendation engine | As specified | Deterministic: same facts + profile + registry → same plan |
| 5 Snapshot + apply + rollback | As specified | Level B recovery point before anything destructive |
| 6 Verification | As specified | With a caveat on loopback in containers, below |
| 7 WP-CLI | As specified | Twelve subcommands |
| 8 React dashboard | As specified | |
| 9 Preview + Fix Safe Issues | As specified | Confirmation token over the canonical plan |

**§15's MVP tweak set is complete.** All 11 named tweaks exist. The registry
now holds 27, the other 16 added by phases 10–15, which is what §15 anticipates
when it says "the only tweaks until Phase 10".

---

## Phases 10–16 — intelligence and headless verification

| Phase | Verdict | Notes |
|---|---|---|
| 10 Database intelligence | As specified | 5 destructive operations, each requiring a recovery point |
| 11 Plugin intelligence | As specified | 10 detectors, 6 compatibility documents |
| 12 Admin intelligence | As specified | |
| 13 Asset intelligence | As specified | Detection only, as the spec requires |
| 14 Elementor | As specified | |
| 15 WooCommerce | As specified | Cart fragments and block styles made conditional |
| 16 Headless verification | As specified | Playwright, `tests/e2e/dashboard.spec.js`, nightly and on a PR label |

---

## Phases 17–21

### 17 — Registry ecosystem: **Differently**

Specified: the registry's CI runs "the plugin's WP/Woo/Elementor integration
matrix against the registry".

Built: it does not. The registry's CI checks what data can be checked with
nothing installed.

**D-0045's reason for that expired, and `D-0069` now says so.** D-0045 gave the
plugin being private as the obstacle; the plugin repository is public and the
registry already checks it out with no token. D-0045 carries a note pointing at
D-0069 rather than being rewritten, so what was believed then is still readable.

Still **Differently**, for reasons that are now stated honestly: the matrix costs
about three minutes a job against an `integrity` job that finishes in under one,
and most registry pushes change a line of wording. The Phase 21 pipeline runs the
matrix against the registry weekly and on dispatch, so there is coverage — it is
not a gate. D-0069 estimates a day to make it one.

### 18 — Release hardening: **As specified**, with one exception

§13 tests, Plugin Check clean, matrix green, zip builds. `readme.txt`,
screenshots list, POT, `uninstall.php`, GPL headers, CHANGELOG all present.

**Not done: wordpress.org submission.** Deliberately — it is an external act
needing credentials (`docs/RENAME-MAP.md` step 5). The `debloater` slug is
therefore **not reserved**, and someone else could take it.

### 19 — Pro: **As specified**

Free plugin fully functional without Pro, without a licensing platform and
without cloud access — asserted with Pro absent. Pro adds no tweaks and no
safety features (`ProArchitectureTest`). No Freemius symbol outside its
adapter and its entry point; no cloud host outside the resolver.

Sub-phases 19b (packaging, admin probe, Freemius SDK) and 19c (profiles) were
added beyond §17 and are **not in the spec**. See below.

One §17 task is deliberately **not built**: "bulk apply of a saved profile".
See "Specified, and not built".

### 20 — Cloud design: **As specified**

`docs/CLOUD-DESIGN.md` exists, in the Pro repository. Design only; nothing
deployed.

### 21 — Autonomous registry pipeline: **Not in the spec**

§17 ends at Phase 20. Phase 21 was added by request. It is built, running, and
has completed one successful end-to-end run against a real model.

---

## Built, and not in the specification

| What | Where | Why it exists |
|---|---|---|
| Profiles (save, export, import, apply) | free 0.2.0 | Phase 19c, by request. D-0063 |
| Pro profiles panel | Pro 0.2.0 | Phase 19c-2. D-0064 |
| Autonomous registry pipeline | registry repo | Phase 21. D-0067 |
| Portable zip packaging | both | Phase 19b-1 |
| Freemius SDK integration | Pro | Phase 19b-2. D-0060, D-0061 |
| Version-discipline check | both | This release. `docs/RELEASING.md` |
| Signed registry releases | registry | D-0059, D-0067 |

---

## Specified, and not built

Three things, all deliberate and all recorded.

### Bulk apply of a saved profile (19)

§17 Phase 19 lists it as a task, and `BUILD-SPEC.md` is the authority, so this
is a divergence and not an oversight.

It was built, and it was reachable from nothing: the dropdown that stored its
profile was replaced by the profiles panel in 19c-2, and `apply()` had no caller
outside its own tests. **D-0068** deleted it rather than wiring it up, on the
grounds 19c-2 had already used to refuse the same route — a second path into
applying inside the paid half is a path that can be taken without the free
half's checks. `ProArchitectureTest::test_pro_cannot_apply_anything` fails if
one comes back.

What the task was reaching for — one setup applied across many sites — is
delivered by portable profiles: export, import, preview and confirm on each
site. Cross-site *sync*, where one place pushes to many, remains deferred to the
cloud phase by D-0063.

Amending §17 is the spec owner's call, not this document's. Until then the two
disagree, and this is where that is written down.

### The registry CI does not run the plugin's matrix (17)

Above, and `D-0069`.

### wordpress.org submission (18)

Above. An external act needing credentials.

---

## Known gaps, stated plainly

### The three fact families the scanner does not enumerate

Phase 21 asked the pipeline to watch admin notices, dashboard widgets, cron
hooks, enqueued handles, autoloaded options and public REST routes.

**Three of those six do not exist in the FactSet.** `wp debloater scan --json`
reports flags and counts — `wp.emojis_enabled`, `db.transients.count`,
`cron.events.count` — not enumerations. There is no list of admin notices, no
list of dashboard widgets, and no list of REST routes anywhere in the scanner.

Consequence: the pipeline cannot notice "WooCommerce 11.2 added a dashboard
widget". It can notice a count changing, if a count covers it. Closing this
means new facts in the plugin's scanner, which is a change to the plugin.

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

Three of the four features Pro's plan sells today have had to be corrected, and
a fifth was withdrawn. Written down together because one of these is an
oversight and three is a pattern: a row was written as a promise, and what
shipped underneath it was narrower.

| Sold as | What was there | What was done |
|---|---|---|
| Priority registry updates | A filter pointing a check that could not discover a newer release at a repository that was never created | **Withdrawn**, not rebuilt (Pro D-0078) |
| White-label before/after reports | A report that never carried a vendor name to replace, so "white-label" promised a substitution that does not happen — and collided with the licence flag that word already means here (Pro D-0061). It also named no site, so an agency's reports for two clients differed only by a run id and a timestamp | **Reworded** to "before/after reports with your name on them", in the setting's description and the docs; the site's name and home URL added to the report (Pro 0.3.2) |
| Bulk apply of a saved profile | Built and reachable from nothing | **Deleted** and replaced by portable profiles, which preview and confirm on each site (Pro D-0068) |
| Scheduled scans, drift detection | As described | — |

What each correction had in common: the claim was written from the feature's
intent, and nothing compared it against the code afterwards. The rows are
storefront content in the Freemius dashboard, which no test in either repository
can read — so the check that would have caught all three does not exist here and
cannot. The nearest thing available is that the plugin's own description of a
feature is tested: `DisplayNameTest` pins the display name, and
`ReportEndpointTest` asserts the report is what the row says it is.

### Pro integration coverage

Ran only on one machine from the split until this week. It now runs in Pro's CI
(`Integration (Pro + Debloater)`, green). Before that, `ProScreenTest` and
`ProIntegrationTest` were present and **uncollected for four commits** — not
failing, not skipping, never run.

### ~~`BulkApply` has no interface~~ — resolved, by deletion

Deleted in Pro 0.2.1 (**D-0068**), with a test that fails if any file in Pro
contains `->apply(`, `ConfirmationToken`, `matchesPlan` or `previewTweaks(`.
The copy that promised it is corrected. It leaves §17 and the build disagreeing
about Phase 19; that is recorded above rather than here.

### ~~The tags are broken~~ — resolved

`v0.1.0` is deleted from both the free repository and its remote; it pointed at
`91a66d2`, from before the Pro split rewrote history. Both repositories are
tagged `v0.2.0` at the commit where their version locations moved together, and
`docs/RELEASING.md` in each makes tagging a release step with the reasoning
attached. **0.1.1 stays untagged on purpose**: its content record was later found
misdated, so no commit has a tree that is honestly 0.1.1.

### The version check was reading whichever archive was lying about

`tools/version-discipline.mjs` compares a built zip against the content record.
It never asked whether that zip was a build of the current tree. Deleting
`BulkApply.php` and running it produced "shipped content is unchanged" — the
previous release's zip agreeing with the record it was made from, and neither
of them describing the code.

CI never met it, because CI builds the archive in the job that runs the check.
A person following `docs/RELEASING.md` met it whenever they edited anything
after building, which is the ordinary case. Fixed in both repositories: the
check refuses an archive older than any file it ships, and says which files.

### The content record misdated itself

`free-plugin-content.json` carried `version: 0.1.1` while its entries were
regenerated twice afterwards, so it claimed 0.1.1 shipped content it never
shipped. Fixed forward at 0.2.0; the historical claim cannot be reconstructed
without building that commit.

### Pro's source is retrievable from the public free repository

The free repository was briefly public with `pro/` in it. History rewriting
stopped the publishing; it did not undo it. Pro's code remains reachable by
SHA at `699eaced874ba7037f24304874756354a9efabf8` until GitHub garbage-collects
unreachable objects, which needs a support request. **Nothing secret was ever
committed there** — Pro's CI asserts that on every push — but the source is
readable by anyone who knows the SHA.

### ~~The decision record is duplicated~~ — resolved

`54d19f7` (free) and `a7322aa` (Pro). This repository's `docs/DECISIONS.md` is
the authoritative one; Pro's holds only the eight decisions that are about Pro
alone and points here for the rest, including the Principles. A test in each
repository fails if a number appears in both.

### The registry pipeline has proposed nothing yet

One successful run, which correctly proposed nothing: it was a first-baseline
run with zero candidates. Every gate is unit-tested and exercised by a local
dry run, but **no real model output has yet been rejected in production** for
missing evidence, and no claimed `safer` has been overruled outside a fixture.

---

## What is left

Ordered by whether it blocks revenue, then by effort.

### Blocks revenue

1. **Upload Pro 0.2.1 to Freemius.** `dist/debloater-pro-0.2.1.zip` is built.
   Minutes; needs credentials. Without it nothing can be sold. The Freemius
   dashboard's own description still carries the corrected copy's predecessor
   and has to be edited there by hand — it is not in this repository.
2. **Submit the free plugin to wordpress.org.** The zip is built and Plugin
   Check is clean. Hours of forms, then a review queue measured in weeks. It is
   the funnel Pro sells into, and the `debloater` slug is unreserved until it
   happens.

### Does not block revenue, but is owed

3. **Request GitHub garbage collection** of the pre-rewrite objects, and check
   for forks. **Still open.** Minutes to ask; the answer is not ours to control,
   and until it happens Pro's source is readable by anyone with the SHA.
4. **Decide whether §17's "bulk apply of a saved profile" is amended or
   restored.** D-0068 chose deletion and gave its reasons; the specification
   still lists the task. Minutes, and it is the spec owner's call.

### Improves the product

5. **Enumerating facts for the three missing families.** New scanner facts for
   admin notices, dashboard widgets and REST routes, plus schema and tests.
   Days, and it is what makes the Phase 21 pipeline able to notice most of what
   it was built to notice.
6. **Watch the pipeline's first real proposal.** Nothing to build; the next run
   with a baseline to compare against is the first that can produce one, and it
   should be read carefully rather than trusted.
7. **Registry CI running the plugin's matrix.** `D-0069` costs it at a day and
   says what is actually in the way now that credentials are not.

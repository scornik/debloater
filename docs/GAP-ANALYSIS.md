# Gap analysis: `docs/FEATURES.md` against `BUILD-SPEC.md`

Phase by phase, at **0.2.0**. Four verdicts:

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

Built: it does not. **D-0045** records why — the plugin was private and the
registry public, so that checkout would have needed a credential in a public
workflow. The registry's CI checks what data can be checked with nothing
installed.

**That reason expired and the decision has not been revisited.** The plugin
repository is public now. The Phase 21 pipeline does check the plugin against
the registry, but weekly and as a proposal engine — not as a gate on every
registry push. D-0045 should be amended or superseded; it currently justifies
a limitation by a fact that is no longer true.

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

**Nothing in §17 phases 0–20 is missing**, with the two exceptions named above:
the registry CI does not run the plugin's matrix (17), and wordpress.org
submission has not happened (18).

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

### Pro integration coverage

Ran only on one machine from the split until this week. It now runs in Pro's CI
(`Integration (Pro + Debloater)`, green). Before that, `ProScreenTest` and
`ProIntegrationTest` were present and **uncollected for four commits** — not
failing, not skipping, never run.

### `BulkApply` has no interface

`Features/BulkApply.php` is complete and tested, and **nothing calls
`apply()` except tests**. The dropdown that used to store its profile was
replaced by the profiles panel, which links to Debloater's preview instead. So
Pro ships a feature reachable only from PHP. Either give it an interface or
remove it; shipping it as-is is a claim about capability that no user can
exercise.

### The tags are broken

The free plugin's only tag is `v0.1.0`, pointing at a commit from before the
Pro split rewrote history — not an ancestor of `main`. 0.1.1 was never tagged.
Pro has no tags at all. Nothing depends on them today because the version check
reads the content record, but `git describe` misleads.

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

### The decision record is duplicated

`scornik/debloater` and `scornik/debloater-pro` each hold a full copy of
`docs/DECISIONS.md`, including duplicate `D-0057` and `D-0065`. Amending either
makes them silently disagree, and Principle **P1** cites "D-0057" without
saying which is authoritative.

### The registry pipeline has proposed nothing yet

One successful run, which correctly proposed nothing: it was a first-baseline
run with zero candidates. Every gate is unit-tested and exercised by a local
dry run, but **no real model output has yet been rejected in production** for
missing evidence, and no claimed `safer` has been overruled outside a fixture.

---

## What is left

Ordered by whether it blocks revenue, then by effort.

### Blocks revenue

1. **Upload Pro 0.2.0 to Freemius.** The zip is built. Minutes; needs
   credentials. Without it nothing can be sold.
2. **Submit the free plugin to wordpress.org.** The zip is built and Plugin
   Check is clean. Hours of forms, then a review queue measured in weeks. It is
   the funnel Pro sells into, and the `debloater` slug is unreserved until it
   happens.
3. **Decide what `BulkApply` is.** Pro's feature list promises bulk apply and a
   customer cannot reach it. Either an interface or a removal — an afternoon
   either way, and a support conversation if neither.

### Does not block revenue, but is owed

4. **Amend D-0045.** It justifies the registry CI's limits with a fact that
   stopped being true when the plugin repository went public. Minutes.
5. **Resolve the duplicated decision record.** An hour.
6. **Fix or remove the broken tags.** Minutes to delete, longer to decide.
7. **Request GitHub garbage collection** of the pre-rewrite objects, and check
   for forks. Minutes to ask; the answer is not ours to control.

### Improves the product

8. **Enumerating facts for the three missing families.** New scanner facts for
   admin notices, dashboard widgets and REST routes, plus schema and tests.
   Days, and it is what makes the Phase 21 pipeline able to notice most of what
   it was built to notice.
9. **Watch the pipeline's first real proposal.** Nothing to build; the next run
   with a baseline to compare against is the first that can produce one, and it
   should be read carefully rather than trusted.
10. **Registry CI running the plugin's matrix**, now that both repositories are
    public — closing the gap D-0045 left. A day.

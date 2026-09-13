# RENAME-MAP.md

Two renames, recorded in the order they happened.

**0.3.0 — `Debloater` → `Hakeemify Debloater`** is at the top. It is a much
smaller change than the one below it: four things move and everything else is
deliberately left alone.

**Phase 18a — `WP Debloat` → `Debloater`** is the original, kept in full below.
It renamed nearly every identifier in the plugin, and the reasoning is worth
having when somebody wonders why a prefix and a slug disagree.

---

## 0.3.0 — Hakeemify Debloater

wordpress.org review, round one. See `docs/DECISIONS.md` D-0071.

### What changed

| Kind | Old | New |
|---|---|---|
| Display name | Debloater | Hakeemify Debloater |
| Slug | `debloater` | `hakeemify-debloater` |
| Text domain | `debloater` | `hakeemify-debloater` |
| Plugin folder | `hakeemify-debloater/` (was `debloater/`) | as shipped in the zip |
| Entry file | `debloater.php` | `hakeemify-debloater.php` |
| POT file | `languages/debloater.pot` | `languages/hakeemify-debloater.pot` |
| Pro's dependency | `Requires Plugins: debloater` | `Requires Plugins: hakeemify-debloater` |

### What deliberately did not change

Everything else. The brief was explicit, and the reasoning is that nothing
requires these to match the slug — WordPress does not — while changing them
would move data, break saved links and invalidate every recorded snapshot for
no benefit at all.

| Kind | Value | Why it stays |
|---|---|---|
| Function/hook prefix | `debloater_` | Extensions hook these by name |
| Constant prefix | `DEBLOATER_` | `DEBLOATER_DISABLE` is documented for wp-config |
| Tables | `{prefix}debloater_*` | Renaming a table is a migration, not a rename |
| State option | `debloater_state` | The same, with the same risk |
| Runtime option | `debloater_runtime` | Added in 0.3.0, named for the prefix |
| Capability | `debloater_manage` | Roles on live sites already grant it |
| REST namespace | `debloater/v1` | A published URL contract |
| WP-CLI command | `wp debloater` | Typed by people and written into scripts |
| Admin menu slug | `debloater` | `?page=debloater&debloater_profile=…` is the URL contract Pro uses |
| Kill-switch query | `?debloater=off` | Documented, and typed in an emergency |
| Handler class prefix | `Debloater_Handler_` | Not user-visible, no reason to churn |
| PHP namespace | `Debloater\` | The same |
| Uploads folder | `wp-content/uploads/debloater/` | Named for the prefix, like the rest |

### Not this rename's doing

The **git checkout** is `debloater`, and stays: Pro's `phpstan.neon` resolves
the free plugin as `../debloater`, and the folder that matters to WordPress is
the one inside the zip.

---

## Phase 18a — Debloater

The Phase 18a rename, one row per token. See `docs/DECISIONS.md` D-0047 for why.

Every replacement below was **case-sensitive** and applied as a whole token.
Anything that matched but was not a whole token was reviewed by hand; the
deliberate non-renames are listed at the end.

## Tokens

| Old | New | Where it lives |
|---|---|---|
| `WP Debloat` | `Debloater` | Display name in prose, menu, headers, docs |
| `WPDebloat` | `Debloater` | PHP namespace, runtime handler class prefix, `X-WPDebloat-Verify` |
| `WPDEBLOAT` | `DEBLOATER` | Constants |
| `wpdebloat` | `debloater` | Function/hook prefix, options, transients, tables, generated directory, query var, CSS classes |
| `wp-debloat` | `debloater` | Slug, text domain, filenames, container plugin path |
| `wp debloat` | `wp debloater` | WP-CLI command |
| `wp_debloat` | `debloater` | One test method name |

## Identifiers, in full

| Kind | Old | New |
|---|---|---|
| Display name | WP Debloat | Debloater |
| Full title | — | Debloater – Scan, Fix & Undo Site Bloat |
| Tagline | — | Scan, Fix & Undo Site Bloat |
| Slug | `wp-debloat` | `debloater` |
| Text domain | `wp-debloat` | `debloater` |
| Entry file | `wp-debloat.php` | `debloater.php` |
| PHP namespace | `WPDebloat\` | `Debloater\` |
| Test namespaces | `WPDebloat\Tests\*` | `Debloater\Tests\*` |
| Constant prefix | `WPDEBLOAT_` | `DEBLOATER_` |
| Kill switch constant | `WPDEBLOAT_DISABLE` | `DEBLOATER_DISABLE` |
| Fail-probe constant | `WPDEBLOAT_TEST_FAIL_PROBE` | `DEBLOATER_TEST_FAIL_PROBE` |
| Function/hook prefix | `wpdebloat_` | `debloater_` |
| State option | `wpdebloat_state` | `debloater_state` |
| Lock transient | `wpdebloat_lock` | `debloater_lock` |
| wp.org cache transient | `wpdebloat_wporg_*` | `debloater_wporg_*` |
| Tables | `{prefix}wpdebloat_*` | `{prefix}debloater_*` |
| Capability | `wpdebloat_manage` | `debloater_manage` |
| REST namespace | `wpdebloat/v1` | `debloater/v1` |
| WP-CLI command | `wp debloat` | `wp debloater` |
| Admin menu slug | `wp-debloat` | `debloater` |
| React root element | `#wpdebloat-root` | `#debloater-root` |
| Script handle | `wpdebloat-admin` | `debloater-admin` |
| Kill-switch query | `?wpdebloat=off` | `?debloater=off` |
| Bypass nonce action | `wpdebloat_bypass` | `debloater_bypass` |
| Verification header | `X-WPDebloat-Verify` | `X-Debloater-Verify` |
| Generated directory | `wp-content/wpdebloat/` | `wp-content/debloater/` |
| Generated runtime | `wp-content/wpdebloat/runtime.php` | `wp-content/debloater/runtime.php` — *removed entirely in 0.3.0, D-0070* |
| Runtime lock | `wp-content/wpdebloat/runtime.lock` | `wp-content/debloater/runtime.lock` — *removed in 0.3.0* |
| Spill directory | `wp-content/wpdebloat/backups/` | `wp-content/debloater/backups/` |
| Must-use loader | `mu-plugins/wp-debloat-loader.php` | `mu-plugins/debloater-loader.php` — *removed in 0.3.0* |
| Loader source | `mu-loader/wp-debloat-loader.php` | `mu-loader/debloater-loader.php` — *removed in 0.3.0* |
| Handler class prefix | `WPDebloat_Handler_` | `Debloater_Handler_` |
| Kill-switch guard class | `WPDebloat_Runtime_Guard` | `Debloater_Runtime_Guard` |
| POT file | `languages/wp-debloat.pot` | `languages/debloater.pot` |
| npm package | `wp-debloat` | `debloater` |
| Composer package | `hakeemify/wp-debloat` | `scornik/debloater` |
| Container plugin path | `wp-content/plugins/wp-debloat` | `wp-content/plugins/debloater` |
| Zip | `wp-debloat-<v>.zip` | `debloater-<v>.zip` |
| Registry manifest product | `wp-debloat` | `debloater` |
| Registry repository | `scornik/wp-debloat-registry` | `scornik/debloater-registry` |
| Schema `$id` host | `wp-debloat.hakeemify.com`, `wpdebloat.dev` | `debloater.hakeemify.com`, `debloater.dev` |

## Deliberately not renamed

**Tweak ids** — `core.disable_emojis`, `db.clean_revisions`,
`admin.remove_welcome_panel`, `woo.cart_fragments_conditional`,
`elementor.disable_google_fonts` and the rest. These identify a *change*, not a
brand. They are what a saved selection stores, what every snapshot row points
at, and what the registry is keyed on. Renaming them would be a data format
change dressed up as a rename.

**"Debloat Score"** — the name of the measure, fixed by `BUILD-SPEC.md` §1
locked decision 1. Not part of this brief, and not brand: it is a term of art
the specification defines.

**`docs/DECISIONS.md`** — the history is left exactly as it was, including
D-0046, which recorded the old name. D-0047 supersedes it. A ledger that gets
rewritten when a decision changes is not a ledger.

**Vendored code** — `vendor/` is regenerated, never edited.

**Registry JSON contents** — tweak documents, profiles, detectors and
compatibility data were untouched apart from the `$id` host in
`registry/schemas/*.json`.

## Manual steps, for a person

1. ~~**Rename the GitHub repository:** `scornik/wp-debloat` → `scornik/debloater`.~~
   **Done, 2026-09-05.** Confirmed by request rather than by report:
   `scornik/debloater` resolves, and `scornik/WPDebloat` redirects to it.
2. ~~**Update the git remote.**~~ **Done, 2026-09-05** — `origin` is
   `https://github.com/scornik/debloater.git` rather than relying on the
   redirect, which is a courtesy GitHub offers and not a guarantee.
3. **Update the CI badge** in `README.md` — nothing to do: `README.md` contains
   no GitHub URL and no badge.
4. ~~**Rename the local working directory** from `WP Debloat` to `Debloater`.~~
   **Done, 2026-09-07** — it is `debloater`, which is also what Pro's
   `phpstan.neon` expects as a sibling.
5. **Reserve `hakeemify-debloater` on wordpress.org** by submitting the plugin.
   The slug changed in 0.3.0; `debloater` was never reserved. Submission
   is outside this build's boundary (see D-0045 for the same reasoning about the
   registry repository). Still outstanding.

### Two things the rename does not fix

**The repository is private.** `https://github.com/scornik/debloater` answers
`404` to anyone not signed in as its owner — verified with an anonymous request,
which is what a reviewer and every user makes. It is the plugin's `Plugin URI`,
so that link is dead for everybody but you until the repository is made public
or the header points at something that is.

> **Resolved.** `scornik/debloater` is public, and so, since 2026-09-13, is
> `scornik/debloater-pro`. The registry paragraph below is out of date too: the
> registry repository exists, and since 0.4.0 no plugin fetches from it
> (D-0073).

**`scornik/debloater-registry` does not exist.** `readme.txt` tells users that
optional registry updates come from
`https://raw.githubusercontent.com/scornik/debloater-registry`, and
`RegistryOrigin::DEFAULT_BASE` points there. Nothing is served from it today, so
the feature fails — safely, and doubly: the fetch 404s, and
`SignatureVerifier::PUBLIC_KEY_HEX` is empty so an unsigned registry would be
refused anyway. The disclosure describes a service that is not there yet, which
is honest about intent and wrong about the present.

## The Pro split, 2026-09-05

Pro moved to `scornik/debloater-pro`, and this repository's history was rewritten
so that `pro/` never appears in it. The rewrite is why the commit identifiers in
this file and in `docs/DECISIONS.md` are worth reading carefully.

### The commits this history replaced

| | |
|---|---|
| Last commit before the rewrite | `e3b9cbe13b38b59040c3fd878918251fb7230545` |
| The submission artifact's commit | `699eace` |
| Where that history still exists | `scornik/debloater-pro`, and a local mirror |

**Every SHA written down before today refers to the old history and cannot be
resolved in this repository.** `git filter-repo` rewrites every commit that
follows a removed path, so the identifiers changed even for commits that never
touched `pro/`. Decision entries naming a commit – D-0053 and D-0056 among them
– are describing work that happened, at identifiers that no longer exist here.

That is the cost of the rewrite and it was accepted deliberately: a public
repository that still serves the paid plugin's source to anyone who knows a SHA
is not private, and a dangling identifier in a document is a smaller problem
than that.

### What moved

| Path | Where it went |
|---|---|
| `pro/` | the root of `scornik/debloater-pro` |
| `tests/Pro/` | the same, unchanged path |
| `tests/Integration/ProIntegrationTest.php`, `ProScreenTest.php` | the same |
| `docs/CLOUD-DESIGN.md`, `docs/FINAL-AUDIT.md` | `docs/` there |
| `docs/DECISIONS.md` | both – see below |

`docs/DECISIONS.md` was the one judgement call. Fifty-eight decisions, and only
two of them – D-0035 and D-0050 – are about Pro. Moving the file wholesale, as
the brief asked, would have taken fifty-six decisions about the free plugin out
of the free plugin's repository, and left `CLAUDE.md` pointing at a file that
was not there.

So the file was removed from every commit, as asked, and a free-only edition was
written fresh: the fifty-six, with a note naming the two that are not here and
where they live. The complete file, with all fifty-eight, is in the Pro
repository.

### What the rewrite does not do

**It does not unpublish anything.** This repository was briefly public with
`pro/` in it. Anyone who cloned or forked it in that window has the paid plugin,
and GitHub keeps unreachable objects reachable by SHA for a long time – a
rewritten public repository can still serve an old blob to somebody who knows
its identifier, until GitHub Support is asked to garbage-collect it.

Rewriting history is how you stop *publishing* something. It is not how you
un-publish it. If the exposure window matters, the questions to answer are who
cloned it and whether anything in `pro/` was secret – and the answer to the
second is no: no key, token or store identifier has ever been committed there,
which the Pro repository's CI now asserts on every push.

# RENAME-MAP.md

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
| Generated runtime | `wp-content/wpdebloat/runtime.php` | `wp-content/debloater/runtime.php` |
| Runtime lock | `wp-content/wpdebloat/runtime.lock` | `wp-content/debloater/runtime.lock` |
| Spill directory | `wp-content/wpdebloat/backups/` | `wp-content/debloater/backups/` |
| Must-use loader | `mu-plugins/wp-debloat-loader.php` | `mu-plugins/debloater-loader.php` |
| Loader source | `mu-loader/wp-debloat-loader.php` | `mu-loader/debloater-loader.php` |
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
4. **Rename the local working directory** from `WP Debloat` to `Debloater`. The
   directory name is not referenced by anything — `.wp-env.json` maps `.` — so
   this is cosmetic, but it stops the next person wondering which one is real.
   Still outstanding.
5. **Reserve `debloater` on wordpress.org** by submitting the plugin. Submission
   is outside this build's boundary (see D-0045 for the same reasoning about the
   registry repository). Still outstanding.

### Two things the rename does not fix

**The repository is private.** `https://github.com/scornik/debloater` answers
`404` to anyone not signed in as its owner — verified with an anonymous request,
which is what a reviewer and every user makes. It is the plugin's `Plugin URI`,
so that link is dead for everybody but you until the repository is made public
or the header points at something that is.

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

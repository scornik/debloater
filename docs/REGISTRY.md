# REGISTRY.md

How to add or change something in `registry/`, and what has to be true before it
ships.

The registry is the only part of Debloater that decides *what the plugin
offers to do to a site*. The code decides how; the registry decides what. That
is why it is JSON with schemas and signatures rather than PHP, and why this
document is longer than the change you are probably about to make.

---

## What is in here

```
registry/
├── manifest.json          generated — every file with its SHA-256, and the tag
├── schemas/               the eight schemas everything else is validated against
├── tweaks/                one file per change Debloater can make
├── compatibility/         what a plugin or theme depends on
├── detectors/             how to tell a plugin or theme is present
├── profiles/              which changes each profile selects
├── plugin-categories.json a table: plugin slug → functional category
├── host-optimizers.json   a table: other tools that offer the same settings
└── admin-notices.json     a table: whose admin notices may be hidden
```

**Nothing here is executable, ever.** A tweak names a handler; the handler is a
PHP file that lives in the plugin, not in the registry. That separation is what
makes it safe to update the registry over the network at all, and it is not
negotiable.

---

## The rules a change has to satisfy

1. **It validates.** Every file is checked against its schema, and unknown keys
   are a failure rather than a warning.
2. **The filename is the id.** `tweaks/core.remove_rsd.json` has
   `"id": "core.remove_rsd"`. The loader asserts this.
3. **Every reference resolves.** A `requires` or `conflicts` naming a tweak that
   does not exist fails the load, not silently at runtime.
4. **The handler exists and declares `register()` and `unregister()`.** A tweak
   whose handler cannot be undone is a tweak that cannot be applied.
5. **Risk is honest.** `safe` means it cannot break the site — not that it is
   unlikely to. Anything that changes what a visitor sees, or what another
   plugin does, is at least `medium`.
6. **`breaks` says what actually breaks**, in the words of somebody who would be
   annoyed by it. "May affect some themes" is not an entry; "the cart total in
   your header will stop updating" is.
7. **`probes` name what must still work.** Every WooCommerce change lists
   `woo_cart`, `woo_checkout` and `woo_account`. A change nobody verifies is a
   change nobody should apply.

Run the whole set before opening a pull request:

```bash
npm run test:unit          # schemas, loader, references, invariants
npm run test:integration   # the change against a real WordPress
php tools/registry-manifest.php --check
```

---

## Adding a tweak

1. Write `runtime-handlers/<id-with-dashes>.php`. Read
   `core-remove-generator.php` first — it documents the rules every handler
   follows. The class name is derived from the tweak id: `admin.remove_welcome_panel`
   becomes `Debloater_Handler_Admin_Remove_Welcome_Panel`, and a test asserts it.
2. Write `registry/tweaks/<id>.json` against `schemas/tweak.schema.json`.
3. Add the id and its risk to the pinned lists in
   `tests/Unit/Registry/LoaderTest.php`. They are pinned deliberately: a new
   tweak should be a decision somebody made, not something that appeared.
4. If it is destructive, it also goes in the explicit destructive list, and no
   profile may admit it.
5. Regenerate the manifest: `php tools/registry-manifest.php --tag=<next tag>`.

---

## Reviewing a pull request

Copy this into the PR description and answer it. It is the checklist a reviewer
will use anyway.

```markdown
### What changes on a site that installs this?

<!-- In one sentence, as a person would notice it. -->

### Risk

- [ ] The risk level matches what this actually does
- [ ] `breaks` names the real consequence, not a hedge
- [ ] `probes` cover what would notice if this went wrong
- [ ] If it deletes anything: `destructive: true`, and no profile admits it

### Evidence

- [ ] The handler declares `register()` and `unregister()`, and unregister puts
      back everything register touched
- [ ] Tested against a real site, not only against a fixture
- [ ] `php tools/registry-manifest.php --check` passes
- [ ] The full suite passes

### Compatibility

- [ ] Anything that depends on what this removes has a compatibility rule
- [ ] Checked against the stack matrix: Woo, Elementor, CF7, Rank Math,
      LiteSpeed
```

---

## When WordPress releases a new version

Work through this before claiming support for it.

- [ ] Run the full matrix against the new version.
- [ ] Check every `since_wp` — a tweak whose hook core has removed must be
      retired, not left to fail quietly.
- [ ] Check for renamed option values. WordPress 6.6 changed the autoload column
      from `yes`/`no` to `on`/`off`; the plugin reads it through
      `wp_set_option_autoload()` for exactly this reason, and the next such
      change will look the same.
- [ ] Check the core-feature scanners still see what they claim to.
- [ ] Re-run the E2E suite: `npm run test:e2e`.
- [ ] Update `Tested up to` and cut a registry release.

---

## Releasing the registry

A release is a git tag plus a signed manifest.

```bash
php tools/registry-manifest.php --tag=v1.2.0
php tools/registry-manifest.php --tag=v1.2.0 --sign=/secure/path/ed25519.key
git tag v1.2.0 && git push --tags
```

**The signing key never enters this repository.** The tool refuses a key path
inside the working tree, a repository invariant refuses anything key-shaped in
the package, and the plugin carries only the public half.

The plugin verifies a release like this, and refuses at every step:

| Check | Refusal |
|---|---|
| Did the user ask? | No request at all unless they did |
| Is a public key pinned, and is libsodium present? | Refuse — never "skip the check" |
| Is the signature ours, over the *canonical* manifest? | Refuse |
| Is the manifest for this product, in a format we know? | Refuse |
| Is every path a plain relative `.json`? | Refuse |
| Does every file's SHA-256 match? | Refuse **the whole release** |
| Does every file parse as JSON? | Refuse |

There is no partial update. A registry half from one version and half from
another is a registry nobody tested.

---

## Splitting this into its own repository

The registry is laid out to move to `scornik/debloater-registry` unchanged:
`registry/` becomes the repository root, `manifest.json` sits beside the
directories, and `.github/workflows/registry.yml` in this repository is the CI
that repository needs.

**It has not been published.** Creating a public repository is an external act
that needs a person's decision and their credentials, so the layout and the
workflow are ready and the publishing is not done. Nothing in the plugin depends
on the split having happened: the vendored snapshot is the source of truth until
a signed release replaces it.


---

## Cutting a signed release

The registry is published at `scornik/debloater-registry`. A release is a tag,
a manifest, and a detached signature over that manifest.

The private key is **held offline** and has never been in either repository. It
signs, and nothing else has a copy. If it is lost, the recovery is to generate a
new pair and ship a plugin release pinning the new public half – which is why
losing it is inconvenient rather than fatal, and why it is not kept anywhere
convenient.

### The procedure

1. **Regenerate the manifest** so it describes what is actually on disk:

   ```
   php tools/registry-manifest.php --write
   ```

   `manifest.json` records every file, its SHA-256 and the tag being cut.

2. **Sign it**, on the offline machine, over the file exactly as it will be
   committed:

   ```
   openssl pkeyutl -sign -rawin -inkey registry-signing.key -in manifest.json -out manifest.sig
   ```

   `-rawin` is not optional: Ed25519 signs the message itself, and anything that
   pre-hashes produces a signature this plugin will refuse.

3. **Check it before it leaves the machine**, with the public half:

   ```
   openssl pkeyutl -verify -rawin -pubin -inkey registry-signing.pub \
       -in manifest.json -sigfile manifest.sig
   ```

4. **Commit `manifest.sig` beside `manifest.json`** in the registry repository.
   CI verifies the committed signature against the same public key, so a
   manifest edited without re-signing fails there rather than on somebody's
   site.

5. **Tag** the release with the tag the manifest names. CI checks those agree.

6. **Vendor the snapshot** into the plugin, if the plugin release is meant to
   carry it, and run the plugin's suite.

### The key

| | |
|---|---|
| Algorithm | Ed25519 |
| Public half | pinned in `SignatureVerifier::PUBLIC_KEY_HEX` |
| Fingerprint | `a2179aba…8964caa3` (SHA-256 of the 32 raw bytes) |
| Private half | offline, never committed, never in a package |

The full fingerprint is in `docs/DECISIONS.md` D-0059, and the same value is
asserted in `PinnedSigningKeyTest`. Two places on purpose: a key changed in one
and not the other is a key somebody changed without saying so.

### What is signed, and a mismatch to resolve first

`manifest.sig` covers **`manifest.json` as committed**, byte for byte. That is
what `openssl pkeyutl -sign -rawin` produces, what the registry's CI checks, and
what anybody auditing a release can check with standard tools.

`RegistryUpdater` currently verifies something else – `Manifest::canonical()`,
a re-encoding of the parsed manifest that is about six hundred bytes shorter.
The two byte strings differ, so **no single signature satisfies both**, and the
v0.1.0 signature is refused by the update path even though it is correct.

This does not affect any site: the fetch is opt-in, off by default, and reached
only by running a WP-CLI command. It has to be settled before it does. The
options and the recommendation are in `docs/DECISIONS.md` D-0059.

# Releasing Debloater

Every step, in order. CI refuses a release-shaped state that lies about itself,
but it refuses — it does not fix. Which version comes next is a decision.

---

## The version lives in four places

All four must say the same thing, and `tools/version-discipline.mjs` fails the
build when they disagree:

| Where | Line |
|---|---|
| `debloater.php` | ` * Version:           X.Y.Z` |
| `debloater.php` | `const DEBLOATER_VERSION = 'X.Y.Z';` |
| `readme.txt` | `Stable tag: X.Y.Z` |
| `package.json` | `"version": "X.Y.Z"` |

The constant is the one that gets missed. It sits fifty lines below the header
in the same file, and it is what the plugin reports about itself at runtime --
in the dashboard, in a run record, and to anything asking which version applied
a change. Bumping 0.2.0 missed it; `ReleaseReadinessTest` caught it and the
version check did not, which is why the check now looks at it too.

`readme.txt`'s **Stable tag** is the one wordpress.org actually serves. A
release whose stable tag still names the previous version is a release nobody
receives.

---

## The steps

### 1. Decide the number

Semantic versioning. A new tweak, a new screen or a new command is a minor; a
fix on its own is a patch; anything that changes what an existing selection
does to a site is worth thinking about for longer than this sentence suggests.

### 2. Move all three

Edit the three lines above. Nothing generates them, on purpose: a version
number that appears as a side effect of a build has no author and no meaning.

### 3. Write the changelog

`CHANGELOG.md`, newest first, in the format already there. Write it for
somebody deciding whether to update, not for somebody reading a diff — what
changed on their site, what they have to do, what might surprise them.

`readme.txt` carries its own trimmed changelog for wordpress.org. Keep the
latest entry in step; wordpress.org shows it on the plugin page.

### 4. Run the gate

```bash
composer check              # PHPCS, PHPStan level 6, the unit suite
npm run test:js
npm run lint:js
npm run env:start
npm run test:integration    # the WordPress suites and the fail-probe
npm run test:cli
```

Everything green. Not "green except one" — `docs/DECISIONS.md` **P2** and **P3**
exist because of exactly that sentence.

### 5. Build, and let the check look at it

```bash
composer check:packaging
```

That builds the archive with a production autoloader, runs `tests/Packaging`,
and restores the development autoloader afterwards. Then:

```bash
node tools/version-discipline.mjs
```

It compares the built archive against `tests/Packaging/free-plugin-content.json`
— the record of what shipped and at which version — and refuses if the content
moved while the version did not.

### 6. Re-record what shipped

```bash
node tools/record-shipped-content.mjs --why "0.2.0: <what changed>"
```

The reason goes into the file. It refuses without one, because regenerating
that record to turn a build green is how it stops meaning anything.

Commit the regenerated record with the release.

### 7. Tag

```bash
git tag -a vX.Y.Z -m "Debloater X.Y.Z"
git push --follow-tags
```

**The tags are currently broken and this is worth knowing.** The only tag in
this repository is `v0.1.0`, and it points at `91a66d2` — a commit from before
the Pro split rewrote history. It is not an ancestor of `main`. Version 0.1.1
was never tagged at all.

Nothing depends on it today: the version check reads the content record, not
the tag. But an old tag that resolves to an unrelated tree is worse than no tag,
and the first person to run `git describe` will be misled. Deleting it, or
re-pointing it, is a decision nobody has taken yet.

### 8. Upload

`dist/debloater-X.Y.Z.zip` goes to wordpress.org. That is a person, with
credentials, deliberately — see `docs/DECISIONS.md` D-0045 for the same
reasoning about the registry.

---

## What the check will not do

It will not bump a version, edit a changelog, or decide that a change is
"too small to matter". It refuses, names the files that moved, and stops.

The failure it exists to prevent is quiet: shipping different code under a
version somebody has already installed. The site has no way to know it has
something new, and "what changed in 0.1.1" stops having a single answer.

That happened repeatedly between 0.1.1 and 0.2.0, which is why this file exists.

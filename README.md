# Debloater

Evidence-based WordPress configuration auditing, with safe and reversible
changes.

Debloater scans a site, reports what it actually found with the facts behind
each conclusion, and only then offers changes — each with its own risk level, a
confidence figure, a plain statement of what will stop working, and a recovery
point taken before anything is touched. If verification fails after a change, it
rolls itself back.

It does not guess, it does not phone home, and it never claims a site got
"faster". Every number it reports is a count it measured before and after.

## Status

Early development. See [`docs/BUILD-STATUS.md`](docs/BUILD-STATUS.md) for the
current phase and [`BUILD-SPEC.md`](BUILD-SPEC.md) for the full specification,
which is the authoritative description of the product.

## How it is built

```
Scanner  ->  Facts  ->  Analyzer  ->  Findings  ->  Engine  ->  Tweaks
                                                                  |
        Snapshot (recovery point)  <---------------------------- Plan
                    |
                 Apply  ->  Verify  ->  PASS: commit
                                    ->  FAIL: roll back
```

Four boundaries are enforced, not merely intended:

- The scanner produces facts and never names a tweak.
- The analyzer produces findings and never changes anything.
- The engine is deterministic: the same facts, profile and registry always
  produce the same plan. No AI, no network.
- The generated runtime knows nothing about the registry, the database or
  options. It registers hooks, and that is all.

With nothing selected there is no runtime file, no hooks and no added database
queries. That is asserted by a test, not assumed.

## Requirements

- PHP 8.1 or later
- WordPress 6.5 or later
- Single site (multisite is out of scope for v1)

## Using it from the command line

Everything the plugin does is available as `wp debloater`, driving the same engine
as the dashboard.

```bash
wp debloater scan                                # look, and record what was found
wp debloater findings --risk=low                 # what it concluded, and why
wp debloater preview --profile=safe              # what would change; changes nothing
wp debloater apply --profile=safe --yes          # take a recovery point, apply, verify
wp debloater verify                              # check the site without changing it
wp debloater rollback --yes                      # put it back
wp debloater status                              # what is in place right now
wp debloater snapshots list                      # the recovery points
wp debloater export --file=debloater.json       # configuration as code
wp debloater import debloater.json --apply --yes
```

Exit codes are meant for scripts: `0` worked, `1` refused, `2` the change was
applied and then rolled back because the site failed its checks, `3` the change
is in place but something could not be checked. Full reference in
[`docs/CLI.md`](docs/CLI.md).

## Development

The plugin has **zero runtime Composer dependencies**. Everything below is
development tooling.

```bash
composer install
composer test      # PHPUnit, no WordPress required
composer lint      # PHPCS: WordPress-Extra + VIP-Go
composer analyze   # PHPStan level 6
composer check     # all three
```

If the machine has no native PHP, the same commands run in Docker; see
`docs/DECISIONS.md` D-0003.

### The release archive

```bash
composer check:packaging   # build the zip and run tests/Packaging
```

Separate from `composer check`, and not because it is optional. The archive
must carry a **production** autoloader — one naming no `Tests\` namespace and
no dev package — so `scripts/plugin-zip.mjs` refuses to build against a
development install. Satisfying that with `composer install --no-dev` deletes
PHPUnit, PHPCS and PHPStan from the tree, which is why this had gone three
commits without being run by hand.

So it does not. `composer dump-autoload --no-dev` regenerates the autoloader
and leaves every installed package alone; the nine files under `vendor/` that
the archive actually contains come out byte-identical either way, which was
checked rather than assumed. The development autoloader is put back afterwards
even when the suite fails, and the script says so loudly if it cannot.

It rebuilds the admin UI on the way through, so give it a minute or two. CI
runs the same suite on Linux and Windows for every push, in the `package` job —
a fresh checkout there gets the isolation this cannot have locally.

When a release legitimately changes what ships, re-record it with a reason
attached:

```bash
node tools/record-shipped-content.mjs --why "what changed, and why"
```

The reason is written into `tests/Packaging/free-plugin-content.json`, and the
command refuses to run without one. Regenerating that file to turn a red build
green is exactly how the record stops being worth keeping.

The admin dashboard is a React app in `admin-ui/`, built with
`@wordpress/scripts`:

```bash
npm run build       # produces build/, which the admin screen enqueues
npm run test:js     # Jest
npm run lint:js     # ESLint
npm run test:bundle # the bundle stays under 250 KB gzipped
```

`build/` is a build artifact and is not committed; the dashboard needs
`npm run build` before it will render.

Integration tests run inside `@wordpress/env`:

```bash
npm run env:start
npm run test:integration   # the WordPress suites
npm run test:cli           # the whole loop through the real `wp` binary
```

Debloater Pro, if you have it checked out, runs its own integration suite
against this environment:

```bash
cp .wp-env.override.json.dist .wp-env.override.json
npm run env:start              # restart, so the mapping takes effect
npm run test:integration:pro
```

The mapping is a template rather than a default because wp-env can only map a
directory that exists, and this repository has to start for people who do not
have Pro. See `debloater-pro/docs/DECISIONS.md` D-0065.

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

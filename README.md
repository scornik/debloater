# WP Debloat

Evidence-based WordPress configuration auditing, with safe and reversible
changes.

WP Debloat scans a site, reports what it actually found with the facts behind
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

Everything the plugin does is available as `wp debloat`, driving the same engine
as the dashboard.

```bash
wp debloat scan                                # look, and record what was found
wp debloat findings --risk=low                 # what it concluded, and why
wp debloat preview --profile=safe              # what would change; changes nothing
wp debloat apply --profile=safe --yes          # take a recovery point, apply, verify
wp debloat verify                              # check the site without changing it
wp debloat rollback --yes                      # put it back
wp debloat status                              # what is in place right now
wp debloat snapshots list                      # the recovery points
wp debloat export --file=wp-debloat.json       # configuration as code
wp debloat import wp-debloat.json --apply --yes
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

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

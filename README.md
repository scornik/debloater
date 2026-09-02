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

Integration tests use `@wordpress/env` and arrive with Phase 1.

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

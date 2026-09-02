# CONVENTIONS.md — Hakeemify shared conventions (WP Debloat)

## Naming

| Thing | Convention | Example |
|---|---|---|
| PHP namespace | `WPDebloat\<Layer>` | `WPDebloat\Analyze\Score` |
| Class files | PSR-4, `StudlyCase.php` under `src/` | `src/Registry/Loader.php` |
| Runtime handler files | `kebab-case.php` under `runtime-handlers/` | `core-disable-emojis.php` |
| Runtime handler class | `WPDebloat_Handler_<Studly_Snake_Id>` | `WPDebloat_Handler_Core_Disable_Emojis` |
| Functions / hooks | `wpdebloat_` prefix | `wpdebloat_after_apply` |
| Options | `wpdebloat_` prefix, `autoload = 'no'` | `wpdebloat_state` |
| Tables | `{$wpdb->prefix}wpdebloat_` | `wp_wpdebloat_runs` |
| Constants | `WPDEBLOAT_` | `WPDEBLOAT_VERSION` |
| REST namespace | `wpdebloat/v1` | `wpdebloat/v1/status` |
| CLI | `wp debloat <subcommand>` | `wp debloat preview` |
| Text domain | `wp-debloat` | `__( 'Scan', 'wp-debloat' )` |
| Tweak / finding ids | dot-namespaced, lower snake within segments | `core.heartbeat_interval` |
| Fact keys | dot-namespaced, scanner owns the first segment | `db.autoload.bytes` |

## Branding

Nothing user-visible is hardcoded in feature code. All product naming comes from
`WPDebloat\Brand` (`Brand::NAME`, `Brand::SLUG`, `Brand::TEXT_DOMAIN`). Renaming the
product must require changing exactly one class plus build configuration.

## PHP style

- `declare( strict_types = 1 );` in every `src/` file.
- Value objects are `final readonly` with constructor property promotion.
- Every value object implements `fromArray( array $data ): static` and `toArray(): array`.
- Invalid contract input throws `WPDebloat\Contracts\ContractViolation`; never returns null.
- No static mutable state outside enums and `final` constant holders.
- Runtime handlers are the single exception: no namespace, no `declare`, no autoloading,
  no option reads, no database access.
- Indentation is tabs in PHP (WordPress standard), two spaces in JSON/JS/MD.

## Errors

- Domain failures throw typed exceptions extending `WPDebloat\Contracts\ContractViolation`
  or a layer-specific exception; they are never silently swallowed.
- Anything user-visible is translated and escaped at the edge.

## Determinism

- Anything that feeds generated code or a plan is sorted deterministically (by id, ascending,
  byte comparison) so identical inputs produce byte-identical output.
- `json_encode` for persisted or hashed structures uses
  `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` with sorted keys.

## Tests

- Unit tests: no WordPress, no filesystem outside temp, no network. `tests/Unit`.
- Integration tests: wp-env + WP PHPUnit. `tests/Integration`.
- Every contract has a valid-input test and an invalid-input test.
- Every invariant in `BUILD-SPEC.md` §7.4 and §13 has an explicit test.
- Fixtures live in `tests/Fixtures`; no fixture writes outside its own temp dir.

## Documentation

- `docs/DECISIONS.md` — one entry per decision, `D-NNNN`, dated, with alternatives and reasoning.
- `docs/BUILD-STATUS.md` — per-phase ledger.
- `docs/TEST-RESULTS.md` — chronological test-run ledger.
- Public-facing rubric changes bump the version inside `docs/SCORING.md`.

## Git

- One commit per completed phase: `phase-N: <summary>`.
- Phase commits are never squashed during the autonomous build.

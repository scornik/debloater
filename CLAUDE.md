# CLAUDE.md — WP Debloat

## Authoritative specification

**`BUILD-SPEC.md` in this repository root is the single source of truth.**
There is no second editable copy. Where any other document, comment, or prior
implementation disagrees with `BUILD-SPEC.md`, the specification wins.

Read, in this order, before changing anything:

1. `BUILD-SPEC.md` — the section relevant to the current phase (§17 lists phases).
2. `docs/DECISIONS.md` — decisions already taken; do not re-litigate them.
3. `docs/BUILD-STATUS.md` — which phase is current and what is already green.
4. `CONVENTIONS.md` — shared Hakeemify conventions.

## Authority order (BUILD-SPEC §21.1)

1. Explicit safety/security constraints in `BUILD-SPEC.md` (§13).
2. Locked architectural decisions (§1).
3. Contracts, schemas, invariants, state machines (§§5–13).
4. Current phase requirements and acceptance criteria (§17).
5. Existing implementation, where it does not conflict with the above.
6. Implementation preference.

## Product-safety invariants — never violate

1. Scanner produces facts, not recommendations.
2. Analyzer produces findings, not changes.
3. The Recommendation Engine is deterministic: same facts + profile + registry → same plan.
4. The runtime has no registry, database, or option intelligence.
5. `dont_touch` is respected by planning; such a tweak never enters a plan.
6. Conflicting tweaks never coexist in a plan.
7. Destructive operations never enter "Fix Safe Issues".
8. Required recovery (Level B snapshot) exists before destructive execution.
9. Verification failure triggers rollback.
10. Empty selection produces no runtime hooks and no runtime file.
11. User input never becomes executable generated code without schema validation.
12. Every state-changing endpoint is capability- and nonce-protected.
13. No outbound network activity except loopback verification and opt-in registry updates.
14. Performance claims are measured deltas, never invented. Never say "faster".
15. Safety is never paywalled behind Pro.

## Stack

| Item | Value |
|---|---|
| PHP | 8.1+ (CI 8.1/8.2/8.3) |
| WordPress | 6.5+ |
| Namespace | `WPDebloat\` PSR-4 from `src/` |
| Runtime handlers | `runtime-handlers/`, **not autoloaded**, no namespace, no deps |
| Prefixes | `wpdebloat_` (functions/hooks/options/tables), `WPDEBLOAT_` (constants), `wpdebloat/v1` (REST), `wp debloat` (CLI) |
| Runtime deps | **zero** Composer runtime dependencies |
| Tests | PHPUnit 10 (unit, no WP), `@wordpress/env` + WP PHPUnit (integration), Playwright (Phase 16) |
| Static | PHPCS (WordPress-Extra + VIP-Go), PHPStan level 6, ESLint via `wp-scripts` |

## Local toolchain

This machine has **no native PHP or Composer**. Both run through Docker; see
`docs/DECISIONS.md` D-0003. Helper scripts live outside the repository:

```
docker run --rm -v "<repo>":/app -w /app php:8.2-cli php <args>
docker run --rm -v "<repo>":/app -w /app composer:2 <args>
```

## Phase discipline

Each phase in `BUILD-SPEC.md` §17 is a hard gate (§21.2). A phase is complete only
when every task is implemented, every new test passes, the whole prior regression
suite passes, applicable static/schema/lint checks pass, the acceptance criteria
pass, docs and ledgers are updated, and a `phase-N: <summary>` commit exists.

Never skip a failing test, weaken an assertion to make it pass, delete an
inconvenient test, or mark a phase complete with known failures.

## Do not

- Add Composer runtime dependencies.
- Emit admin notices, dashboard widgets, or any frontend output.
- Add telemetry, analytics, or AI.
- Hardcode user-visible strings — everything goes through `WPDebloat\Brand` and `__()`.
- Introduce frameworks, ORMs, or speculative abstractions.

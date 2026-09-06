# CLAUDE.md — Debloater

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
| Namespace | `Debloater\` PSR-4 from `src/` |
| Runtime handlers | `runtime-handlers/`, **not autoloaded**, no namespace, no deps |
| Prefixes | `debloater_` (functions/hooks/options/tables), `DEBLOATER_` (constants), `debloater/v1` (REST), `wp debloater` (CLI) |
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

## Testing conventions

`CONVENTIONS.md` has the layout rules. These are the ones that were learned the
hard way, each from a test that passed while the thing it named was broken.

**Pin a cross-component contract by its literal value, never by the constant
both sides read.** A test written as
`assertSame( ProfilesPanel::PRESELECT . '=x', $url )` compares the constant with
itself: rename it and both halves of the comparison rename together, so the test
agrees with whatever it became and the other component — which still sends the
old name — silently stops working. Write the literal, and say in a comment who
else depends on it.

The example is `debloater_profile`, the query argument Pro's profiles panel puts
in a URL and `admin-ui/src/components/Profiles.js` reads back
(`docs/HOOKS.md`, "URL contracts"). Both sides assert the string; neither
asserts its own constant. This was found by probe — renaming the constant left
the test green.

Two rules of the same shape, from the same phase:

- **Assert the property, not a value the code derives.** A test that pinned the
  id `ProfileStore` generates from a name was asserting the store's private
  naming rule, not the behaviour under test. Find the row by what it is.
- **A probe that does not bite is a finding, not a formality.** Break what each
  new assertion defends and watch it fail. Every guard in this repository that
  turned out never to have worked — the private-key grep, the entry-point
  invariant, the admin probe's cookie — was found this way and by nothing else.

**A skipped test is a failed test in CI.** A suite that can skip on a missing
secret, path or sibling checkout must have a step that fails the job when the
skip count is not zero. Skipping is a legitimate answer to "this machine cannot
run that"; it is never a legitimate answer in the one environment configured to
run everything, and the two are indistinguishable in a green tick.

Pro's cross-tree invariants are the example — the tests asserting Pro adds no
tweaks, no runtime handlers and no safety features to Debloater. They were gated
on a `FREE_PLUGIN_TOKEN` that was never configured, so they skipped on every run
from the split until `068b4b3` while the job reported success. They printed a
warning saying so, to nobody. Six tests, months of green, nothing checked.

**A check that cannot pass is indistinguishable from a check nobody wrote.**
When adding or repairing a CI job, watch it pass in the runner at least once
before counting it as coverage. Passing locally is not the same claim: the
runner is a different machine, and the whole point of the job is what happens
there.

The packaging job is the example. It ran on Linux and Windows for every push and
had been failing since the split — through six commits, while unit, integration,
static analysis and the registry job stayed green — because the shipped-content
record pinned hashes of files no second machine can reproduce. It had never
passed in CI at all, from the commit that introduced it until `5fa0dc9`. Nobody
looked, because the last thing anybody remembered about that job was that it had
been fixed.

Both rules have the same root: **absence of a failure is not evidence of a
pass.** Ask what the check would do if the thing it guards were broken right
now, and if the honest answer is "nothing, probably", it is not coverage yet.

## Do not

- Add Composer runtime dependencies.
- Emit admin notices, dashboard widgets, or any frontend output.
- Add telemetry, analytics, or AI.
- Hardcode user-visible strings — everything goes through `Debloater\Brand` and `__()`.
- Introduce frameworks, ORMs, or speculative abstractions.

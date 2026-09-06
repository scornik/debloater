# Contributing to Debloater

`BUILD-SPEC.md` is the specification and wins over everything else, including
this file. `CLAUDE.md` has the working rules; `CONVENTIONS.md` has the shared
Hakeemify conventions. This file covers the things that are neither
specification nor style: how to get the suites running, and the ordering rules
that only exist because two repositories are involved.

## Getting set up

```bash
composer install
npm ci
```

There is no native PHP or Composer requirement — everything runs in Docker if
you do not have them; see `docs/DECISIONS.md` D-0003.

```bash
composer check            # PHPCS, PHPStan level 6, the unit suite
composer check:packaging  # the release archive and tests/Packaging
npm run test:js           # Jest
npm run lint:js           # ESLint
```

Integration tests need `@wordpress/env`:

```bash
npm run env:start
npm run test:integration
npm run test:cli
```

`README.md` has the detail on each of these, including why
`composer check:packaging` is separate from `composer check`.

## Two repositories

Debloater is public. **Debloater Pro** is a separate, private repository at
`scornik/debloater-pro`, and it extends this plugin through the hooks and URL
contracts documented in `docs/HOOKS.md`.

Nothing in this repository depends on Pro. The tests, the build and the release
archive all work with no Pro checkout anywhere, and that is a property worth
keeping: a contributor without access to Pro must be able to do everything here.

If you do have Pro checked out beside this repository, its integration suite
runs against this environment:

```bash
cp .wp-env.override.json.dist .wp-env.override.json
npm run env:start              # restart, so the mapping takes effect
npm run test:integration:pro
```

The mapping is a template rather than a default because wp-env can only map a
directory that exists, and `wp-env start` has to work for people who do not have
Pro. Keep your copy a copy: edit only the path, and if the template ever needs
changing, change the template and copy it again. The file you exercise should be
the file that ships — a hand-written override with an extra key once passed
locally and broke CI, because the two had quietly stopped being the same file.

## Push the free repository first

**Pro's CI checks out `scornik/debloater` at `main`, not at a pinned commit.**

So when a change in Pro depends on something new here — a class, a hook, a query
argument — Pro's CI cannot pass until that change is on this repository's
`main`. Push here first. Always, even when the Pro change looks self-contained
and the change here looks trivial.

The commit that added Pro's profiles panel is the example. It uses
`Debloater\Config\Profile` and `Debloater\Config\ProfileStore`, which were
committed here but not yet pushed, so Pro's CI checked out a `main` without them
and reported about forty unknown-class errors — every one meaning "the other
repository has not caught up", and none of them saying so.

Tracking `main` is deliberate. Pinning a commit would let the two drift while
both reported green, which is the failure that workflow exists to prevent. The
cost is this ordering rule, and the rule is cheaper than the drift.

## Before you open a pull request

Read `CLAUDE.md`'s **Testing conventions** — they are short, and each one is
there because something passed while the thing it named was broken. In
particular:

- **Watch every new CI job pass in the runner at least once.** Passing locally
  is a different claim. A job that cannot pass is indistinguishable from a job
  nobody wrote, and this repository has had one of those for six commits.
- **A skipped test is a failed test in CI.** If a suite can skip because a path,
  a secret or a sibling checkout is missing, the job needs a step that fails when
  the skip count is not zero.
- **Break each new assertion and watch it fail.** Every guard here that turned
  out never to have worked was found that way and by nothing else.

## Security

Do not open a public issue for a security problem in a plugin that rewrites what
loads on people's sites. Use GitHub's private vulnerability reporting on this
repository, under **Security -> Report a vulnerability**.

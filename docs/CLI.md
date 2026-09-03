# CLI.md

`wp debloater` — the whole loop from a terminal.

Every command reads and writes through the same engine the dashboard uses. The
CLI decides nothing for itself: what to recommend, what may go into a plan, what
to snapshot, whether the site still works afterwards are all answered in one
place, so the two interfaces cannot disagree.

Runs and journal rows made this way record the actor as `cli`.

Structured output is `--format=json`. WP-CLI accepts `--json` as shorthand for
it, so both spellings work and mean the same thing; the synopsis declares
`--format` because WP-CLI rewrites `--json` before a command ever sees it.

---

## Exit codes

| Code | Meaning |
|---|---|
| `0` | It worked. |
| `1` | It did not run, or it was refused. Nothing changed. |
| `2` | Verification failed and the change was rolled back. |
| `3` | Verification passed with warnings. The change is in place. |

The distinction between `0` and `3` matters in a deployment script: `3` means
the change was applied but something could not be checked — most often that the
site cannot make HTTP requests to itself. `2` means the site was changed and
then put back.

---

## Commands

### `wp debloater scan [--format=json] [--check-plugin-updates]`

Reads the site and analyses what it found. Writes a run; changes nothing else.

```
wp debloater scan
wp debloater scan --json
wp debloater scan --check-plugin-updates
```

`--check-plugin-updates` is the only thing in Debloater that sends anything off
the server: it asks wordpress.org when each active plugin was last released.
Without it the scan stays entirely on the machine and reads staleness from file
dates instead — a weaker answer, reported as one, at a third of the confidence.

The flag is not remembered. There is no setting that, once ticked, makes future
scans reach out; the next scan asks again (docs/DECISIONS.md D-0029).

### `wp debloater findings [--risk=<low|medium|high>] [--format=json]`

Lists the findings from the most recent scan. Exits `1` if there has not been
one.

### `wp debloater preview [--profile=<safe|performance|maximum>] [--tweaks=<ids>] [--format=json]`

Shows what a change would do. Changes nothing at all — this is the command to
run first, and the one to run in CI.

`--tweaks` takes a comma-separated list of tweak ids and plans exactly those.
Naming a tweak asks for it to be *considered*: the same invariants apply, so one
the site refuses is still excluded, with the reason given.

### `wp debloater apply [--profile=<profile>] [--tweaks=<ids>] --yes [--format=json]`

Applies a plan. `--yes` is required.

Takes a recovery point first, applies configuration before data, verifies, and
rolls back automatically if verification fails.

```
wp debloater apply --profile=safe --yes         # 0, 2 or 3
```

### `wp debloater verify [--format=json]`

Checks the site without changing anything: the home page as a guest, the newest
post, the dashboard, the REST API, the login page, and whether the generated
runtime is the one actually loaded.

Exits `0` when everything passed, `3` when something could not be checked, `2`
when a check failed.

### `wp debloater rollback [<snapshot-id>] --yes [--format=json]`

Undoes a change. With no id, the most recent recovery point. `--yes` is
required.

A recovery point belongs to a run, and the whole run is undone — restoring half
of one would leave the site in a state nothing has a name for.

### `wp debloater snapshots [list|show <id>|delete <id>] [--yes] [--format=json]`

Lists recovery points, shows one in full, or deletes one. Deleting requires
`--yes`, because a deleted recovery point means that change can no longer be
undone.

Nothing expires on its own (see `docs/DECISIONS.md` D-0016).

### `wp debloater status [--format=json]`

What Debloater is doing on this site: the selection, the runtime and whether it
matches what was generated, the loader mode, the last scan, and whether an apply
is in progress.

### `wp debloater export [--file=<path>]`

Writes this site's configuration as JSON — configuration as code. Without
`--file`, prints to standard output.

### `wp debloater import <file> [--apply --yes] [--format=json]`

Reads a configuration file. Without `--apply` it validates and reports, changing
nothing. With `--apply --yes` it plans and applies what the file describes.

A change the file names that this version does not have is reported and skipped;
the rest of the file still applies.

---

## JSON output

Every JSON output is an object. Two of them are described by schemas the
plugin ships and validates against:

| Output | Schema |
|---|---|
| `scan --json` → `facts` | `registry/schemas/fact.schema.json` |
| `scan --json` → each entry of `findings`, `findings --json` → each entry of `findings` | `registry/schemas/finding.schema.json` |
| `export`, and the file `import` reads | `schemas/config.schema.json` |

`import` validates its input against that schema **before** reading a single
value out of it (`BUILD-SPEC.md` §13 rule 5).

The remaining shapes are documented here and asserted by the integration suite:

### `scan --json`

```jsonc
{
  "run_id": 12,
  "scanned": "2026-09-03 10:11:12",   // UTC
  "facts": { "env.wp_version": "6.5", "...": "..." },
  "findings": [ /* finding.schema.json */ ],
  "score": {
    "rubric_version": 1,
    "headline": 72,
    "sub_scores": { "...": 0 },
    "counts_by_decision": { "...": 0 },
    "counts_by_risk": { "...": 0 },
    "unscored_categories": [],
    "findings_total": 9
  }
}
```

### `findings --json`

```jsonc
{
  "run_id": 12,
  "risk": "low",          // or null when unfiltered
  "count": 4,
  "findings": [ /* finding.schema.json */ ]
}
```

### `preview --json`

`PlanResult`: `{ "plan": { "tweaks": [...], "will_change": [...], "will_not":
[...], "destructive": false, "snapshot_levels": ["A"] }, "excluded": { "<tweak
id>": "<why>" } }`.

### `apply --json`, `rollback --json`, `import --apply --json`

`ApplyResult`: `{ "run_id": 13, "state": "COMMITTED", "applied": [...],
"skipped": {}, "snapshot_ids": [4, 5], "verification": { ... } | null, "error":
null, "warnings": [...] }`.

`state` is one of the `RunState` values in `docs/STATE-MACHINE.md`.

### `verify --json`

`VerificationResult`: `{ "probes": [ { "probe": "home", "status": "PASS",
"message": "...", "evidence": { ... } } ], "status": "PASS" }`.

`status` is `PASS`, `WARN` or `FAIL`; a probe may also be `UNKNOWN` (it applies
but could not run) or `NOT_TESTED` (it does not apply here). The two are
deliberately different, and `NOT_TESTED` never counts towards the aggregate.

### `status --json`

```jsonc
{
  "plugin_version": "0.1.0",
  "registry_hash": "…64 hex…",
  "selection": ["core.remove_rsd"],
  "selection_count": 1,
  "tweak_states": { "core.remove_rsd": "COMMITTED" },
  "runtime": { "present": true, "hash": "…", "intact": true, "matches_state": true },
  "loader": { "mode": "mu-plugin", "installed": true, "up_to_date": true },
  "last_scan": { "run_id": 12, "at": "2026-09-03 10:11:12", "findings": 9 },
  "lock": { "held": false, "holder": null }
}
```

### `snapshots list --json`

`{ "count": 2, "snapshots": [ /* Snapshot */ ] }`.

---

## A deployment example

```bash
wp debloater scan --json > scan.json
wp debloater preview --profile=safe --json > plan.json

wp debloater apply --profile=safe --yes
case $? in
  0) echo "Applied and verified." ;;
  3) echo "Applied; some checks could not run." ;;
  2) echo "Applied and rolled back: the site did not pass its checks." ; exit 1 ;;
  *) echo "Refused." ; exit 1 ;;
esac
```

Configuration promoted from staging to production:

```bash
# on staging
wp debloater export --file=debloater.json

# on production, in review, then applied
wp debloater import debloater.json
wp debloater import debloater.json --apply --yes
```

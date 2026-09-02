# State machines

<!-- Generated from the RunState and TweakState enums by
     tests/Unit/StateMachine/StateMachineDocTest.php. Do not edit by hand:
     change the enum and run the test suite. -->

Two state machines govern WP Debloat, and they are deliberately separate.
`RunState` tracks what a run is doing; `TweakState` tracks where each
individual tweak stands. One run legitimately ends with some tweaks
`COMMITTED` and others parked at `DONT_TOUCH`, which a single machine could
not express.

Illegal transitions throw `WPDebloat\Contracts\IllegalTransition`. That is
fatal rather than a returned `false` because the machine governs
snapshotting, applying and rollback: a caller that has lost track of where it
is must stop, not carry on guessing, when the next step might write to the
filesystem or delete rows.

## Apply run (`RunState`)

The run state is persisted in `wpdebloat_runs.status` and updated on every
transition, so a crash leaves an accurate record of how far the run got.

| State | Allowed next | Holds lock | Terminal |
|---|---|---|---|
| `IDLE` | `PLANNING` | no | no |
| `PLANNING` | `PREVIEWED`, `ABORTED` | no | no |
| `PREVIEWED` | `LOCKED`, `ABORTED` | no | no |
| `LOCKED` | `MEASURING_BEFORE`, `ABORTED` | yes | no |
| `MEASURING_BEFORE` | `SNAPSHOTTING`, `ABORTED` | yes | no |
| `SNAPSHOTTING` | `APPLYING`, `ABORTED` | yes | no |
| `APPLYING` | `APPLIED`, `APPLY_FAILED`, `INTERRUPTED` | yes | no |
| `APPLIED` | `VERIFYING` | yes | no |
| `APPLY_FAILED` | `ROLLING_BACK` | yes | no |
| `VERIFYING` | `VERIFIED`, `VERIFIED_WITH_WARNINGS`, `VERIFICATION_FAILED`, `INTERRUPTED` | yes | no |
| `VERIFIED` | `MEASURING_AFTER` | yes | no |
| `VERIFIED_WITH_WARNINGS` | `MEASURING_AFTER` | yes | no |
| `VERIFICATION_FAILED` | `ROLLING_BACK` | yes | no |
| `MEASURING_AFTER` | `COMMITTED` | yes | no |
| `COMMITTED` | — | no | yes |
| `ROLLING_BACK` | `ROLLED_BACK` | yes | no |
| `ROLLED_BACK` | `IDLE` | no | no |
| `ABORTED` | — | no | yes |
| `INTERRUPTED` | `ROLLING_BACK` | yes | no |

### Notes

- `ABORTED` is reached by any failure before `APPLYING`. Nothing was changed,
  so no rollback is required and the lock is released.
- `INTERRUPTED` is set by crash recovery at boot for a run found in
  `APPLYING` or `VERIFYING`. Such a run is rolled back automatically.
- Failures during `MEASURING_BEFORE` and `MEASURING_AFTER` are warnings, not
  transitions: the run continues and the warning is recorded on the result.
- `ROLLED_BACK` returns to `IDLE` once the lock is released.

States requiring crash recovery: `APPLYING`, `VERIFYING`.

## Tweak lifecycle (`TweakState`)

Stored per tweak in `wpdebloat_state.tweak_states`. Every transition writes a
row to `wpdebloat_journal`.

| State | Allowed next | Terminal |
|---|---|---|
| `DISCOVERED` | `ELIGIBLE`, `DONT_TOUCH` | no |
| `ELIGIBLE` | `RECOMMENDED`, `DONT_TOUCH` | no |
| `DONT_TOUCH` | — | yes |
| `RECOMMENDED` | `SELECTED`, `DONT_TOUCH` | no |
| `SELECTED` | `PREVIEWED` | no |
| `PREVIEWED` | `SNAPSHOTTED` | no |
| `SNAPSHOTTED` | `APPLIED`, `APPLY_FAILED` | no |
| `APPLIED` | `VERIFIED`, `VERIFICATION_FAILED` | no |
| `APPLY_FAILED` | `ROLLED_BACK` | no |
| `VERIFIED` | `COMMITTED` | no |
| `VERIFICATION_FAILED` | `ROLLED_BACK` | no |
| `COMMITTED` | `REVERT_REQUESTED` | no |
| `REVERT_REQUESTED` | `ROLLED_BACK` | no |
| `ROLLED_BACK` | — | yes |

### Notes

- `DONT_TOUCH` is terminal for this run. It is a decision, not a failure,
  and it is shown to the user as "No action recommended".
- `COMMITTED` is the only state in which a tweak is in effect on the site.
- A committed tweak is undone through `REVERT_REQUESTED` so that a manual
  undo is journalled exactly like an automatic rollback.


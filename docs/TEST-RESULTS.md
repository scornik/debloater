# TEST-RESULTS.md

Chronological record of test-suite runs, failures and their fixes
(`BUILD-SPEC.md` §21.6). Newest phase last.

Unless stated otherwise, every run is on PHP 8.2.33 in the `php:8.2-cli`
container described in `docs/DECISIONS.md` D-0003.

---

## Phase 0 — Architecture and contracts

### Run 1 — first full unit suite

```
vendor/bin/phpunit
```

**Result:** 738 tests, 2 379 assertions, **2 failures**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `RegistrySchemaTest::test_safe_profile_validates` — `/params: expected type object, got array` | `SchemaValidator::matchesType()` used `array_is_list()` alone to tell a JSON object from a JSON array. The empty array is a list under that test, so `{}` — which `profiles/safe.json` uses for `params` — failed `type: object`. | Treat the empty array as satisfying both `array` and `object`. `json_decode('[]')` and `json_decode('{}')` produce the identical PHP value, so nothing downstream can distinguish them either; claiming otherwise would reject valid registry files. | ✅ |
| `SchemaValidatorTest::test_the_empty_array_satisfies_array_and_object` | Same cause. The test was written first and correctly predicted the bug. | Same fix. | ✅ |

### Run 2 — unit suite after the fix

```
vendor/bin/phpunit
```

**Result:** 738 tests, 2 379 assertions, **0 failures**.

### Run 3 — PHPStan level 6

```
vendor/bin/phpstan analyse -c phpstan.neon
```

**Result:** **1 error** (on PHPStan 1.12).

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `SchemaValidator.php:311` — `is_bool()` with `float\|int` always false | Redundant `! is_bool( $value )` guard after `is_int() \|\| is_float()`. Dead code, not a behaviour bug. | Removed the redundant clause. | ✅ |

PHPStan was also upgraded from 1.12 to 2.2 in this phase: 1.x is unmaintained
and warned that `checkGenericClassInNonGenericObjectType` is deprecated.
`szepeviktor/phpstan-wordpress` moved to 2.x with it.

### Run 4 — PHPStan 2.2 level 6

**Result:** **3 errors.**

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `Fact.php:137` — `is_string()` with `string` always true | A guard checking that array keys are `int\|string`. PHP array keys can only ever be `int\|string`, so the branch was unreachable. | Removed the guard. | ✅ |
| `FactSet.php:20` — generic interface without type parameters | `IteratorAggregate` implemented without declaring key and value types. | Added `@implements \IteratorAggregate<string,Fact>`. | ✅ |
| `SchemaValidator.php:748` — comparison always true | `passes()` re-read `$this->violations` after calling `check()`; PHPStan's purity inference could not see the mutation. | Used `check()`'s return value (the number of violations it recorded) instead of re-reading the property. Clearer as well as analysable. | ✅ |

### Run 5 — PHPCS (WordPress-Extra + VIP-Go + PHPCompatibilityWP)

```
vendor/bin/phpcs --standard=phpcs.xml.dist
```

**Result:** **173 errors, 21 warnings across 39 files.**

Triaged into four groups:

| Group | Count | Disposition |
|---|---|---|
| `WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid` | 124 | Excluded. `BUILD-SPEC.md` §17 Phase 0 mandates `fromArray()` / `toArray()`; under §21.1 the specification outranks the convention. See D-0004. |
| `PHPCompatibility` enum false positives (`ForbiddenThisUseContexts`, `SelfOutsideClassScope`) | 32 | Excluded. PHPCompatibility 9.x predates PHP 8.1 enums and misparses every enum method body. Tool limitation, recorded in D-0004 with a removal condition. |
| Formatting (`ArrayDeclarationSpacing`, `MultipleStatementAlignment`) | 23 | **Fixed** with `phpcbf`, plus a trailing-whitespace sweep. |
| Genuine code changes | 8 | **Fixed**: renamed the reserved-word parameter `$case` to `$enum_case`; renamed a fixture payload key `timeout` to `expires_at`, which was being read by a VIP sniff as a 1 756 838 040-second HTTP timeout. |

Remaining exclusions (`CapitalPDangit`, `error_log_var_export`,
`FetchingRemoteData`, plus four test-only rules) are each justified against a
specific specification requirement in D-0004.

### Run 6 — full gate

```
vendor/bin/phpunit
vendor/bin/phpcs  --standard=phpcs.xml.dist
vendor/bin/phpstan analyse -c phpstan.neon
```

| Check | Result |
|---|---|
| PHPUnit (unit) | ✅ **747 tests, 2 911 assertions, 0 failures, 0 skipped** |
| PHPCS | ✅ **0 errors, 0 warnings** across 55 files |
| PHPStan level 6 | ✅ **no errors** |

No test is skipped, marked incomplete or risky; `phpunit.xml.dist` sets
`failOnRisky` and `failOnWarning`.

### Coverage of the phase's required tests

| Requirement (§17 Phase 0) | Where |
|---|---|
| Unit test for every contract, valid and invalid | `RoundTripTest` (18 subjects × 2), `ContractValidationTest` (44 cases) |
| Schema validation of fixtures | `RegistrySchemaTest` (6 schemas, 6 valid fixtures, 9 invalid fixtures) |
| All legal and illegal transitions | `RunStateMachineTest`, `TweakStateMachineTest` — providers generated from the enums, so a new state is covered automatically |
| `STATE-MACHINE.md` generated and non-stale | `StateMachineDocTest` |

### Environment notes

- PHP 8.1 and 8.3 were **not** exercised. Only 8.2 is available locally
  (D-0003); the version matrix in §14 is a CI concern and is not yet set up.
- No integration test exists yet. Phase 0 is specified as containing no
  WordPress-dependent code, so this is expected rather than a gap.

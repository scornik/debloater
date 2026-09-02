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

---

## Phase 1 — Minimal runtime engine

This phase spent most of its debugging time on the environment rather than the
code: the integration suite could not run at all until three separate blockers
were cleared. All three are recorded as decisions, because none of them is
obvious from the outside.

### Run 1 — wp-env refuses to start

```
npx wp-env start
✖ Could not find the current WordPress version in the cache and the network is not available.
```

| Diagnosis | Fix | Retest |
|---|---|---|
| The machine's system resolver is `127.0.0.1` and refuses queries from Node. `dns.lookup()` works, `dns.resolve()` does not, and wp-env uses `dns.resolve('WordPress.org')` as its offline check. Confirmed directly: `dns.resolve` → `ECONNREFUSED`, `dns.lookup` → `66.6.42.252`, and HTTPS to `api.wordpress.org` returns 200. | A preload module setting `dns.setServers(['1.1.1.1','8.8.8.8'])`, applied through `NODE_OPTIONS` for wp-env invocations only (D-0009). | ✅ wp-env proceeds |

### Run 2 — wp-env image build fails

```
target tests-wordpress: failed to solve: process
"/bin/sh -c php /tmp/composer-setup.php ..." did not complete successfully: exit code: 1
```

| Diagnosis | Fix | Retest |
|---|---|---|
| Containers inherited the same `127.0.0.1` resolver, so `curl https://composer.github.io/installer.sig` returned nothing. The Dockerfile's hash-verification step **deletes** the installer on mismatch and still exits 0, so the failure surfaced one line later as a missing file. Confirmed: `docker run curlimages/curl` fetched nothing; the same with `--dns 1.1.1.1` returned `HTTP/2 200`. | `"dns": ["1.1.1.1","8.8.8.8"]` added to `~/.docker/daemon.json`, original backed up (D-0009). | ✅ DNS resolves in builds |

### Run 3 — same failure after the DNS fix

| Diagnosis | Fix | Retest |
|---|---|---|
| The two broken layers were cached from run 2. Docker had cached the `curl` step that produced the corrupt installer. | `docker compose build --no-cache` for the four wp-env images. | ✅ all four built |

### Run 4 — plugin not found

```
Warning: The 'WP' plugin could not be found.
Warning: The 'Debloat' plugin could not be found.
```

| Diagnosis | Fix | Retest |
|---|---|---|
| `"plugins": ["."]` makes wp-env derive the plugin slug from the directory name, which here contains a space ("WP Debloat"), so it was parsed as two plugin names. | Replaced with an explicit `mappings` entry putting the plugin at `wp-content/plugins/wp-debloat`. | ✅ site starts |

### Run 5 — PHPUnit Polyfills missing

```
Error: The PHPUnit Polyfills library is a requirement for running the WP test suite.
```

| Diagnosis | Fix | Retest |
|---|---|---|
| The WordPress test bootstrap requires `yoast/phpunit-polyfills`. Version `^3.0` conflicts with PHPUnit 10; `^2.0` supports 9 and 10 and is what WordPress expects. | Added `yoast/phpunit-polyfills: ^2.0`. | ✅ bootstrap proceeds |

### Run 6 — every integration test errors

```
Tests: 33, Assertions: 0, Errors: 33.
Error: Call to undefined method PHPUnit\Util\Test::parseTestMethodAnnotations()
```

| Diagnosis | Fix | Retest |
|---|---|---|
| The WordPress core test suite is not compatible with PHPUnit 10: `WP_UnitTestCase` calls a method PHPUnit 10 removed. wp-env's own container ships PHPUnit 10.39 and hits the same wall. This is a property of WordPress 6.5, not of this project. | Split the runners (D-0008): PHPUnit 10.5 for unit, a checksum-pinned PHPUnit 9.6.22 phar for integration, with `phpunit-wp.xml.dist` rewritten against the 9.6 schema. | ✅ 33 tests run |

### Run 7 — one integration failure

**Result:** 33 tests, 94 assertions, **1 error**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `RuntimeOverheadTest::test_an_empty_selection_adds_no_queries_to_a_frontend_request` — "Cannot modify header information" | The test fires `template_redirect` to simulate a front-end request. Core's `redirect_canonical` runs on that hook and tries to send headers, which a PHPUnit run has already sent. | Remove `redirect_canonical` for the duration of the measurement. It is core behaviour, not ours, and removing it leaves what is being measured — whether *WP Debloat* adds a query — intact. | ✅ |

A `cacheResultFile` permission warning appeared in the same run: the plugin
directory is mounted from Windows and the container user cannot write there.
Result caching is now off for the integration suite.

### Run 8 — PHPStan without WordPress stubs

**Result:** 84 errors, all "Function `add_action` not found" and similar.

| Fix | Retest |
|---|---|
| `phpstan.neon` now includes `szepeviktor/phpstan-wordpress`'s extension, adds `wp-debloat.php` to the analysed paths, and declares `WPDEBLOAT_DISABLE`, `WPDEBLOAT_LOADER_MODE` and `WP_CLI` as **dynamic** constants — without that, analysis reasons from the placeholder values in the bootstrap file and reports every guard against them as dead code. | ✅ |

Four genuine findings came out of the same pass and were fixed rather than
ignored: `token_get_all()` with `TOKEN_PARSE` throws `ParseError` instead of
returning `false`, so the `false ===` branch was unreachable and the real error
path was missing; `PHP_BINARY` is never an empty string; a redundant
`is_string()` on a constant; and missing iterable value types on the handler
signatures, which were given proper `@param` types rather than an ignore rule.

### Run 9 — PHPCS

**Result:** 45 violations in 16 sources.

| Group | Disposition |
|---|---|
| `$_GET` read without sanitisation in the runtime guard (2) | **Fixed**: both reads now go through `sanitize_key( wp_unslash( … ) )`. |
| Short ternaries in a test (2) | **Fixed**. |
| VIP filesystem restrictions and `NoSilencedErrors` in `RuntimeWriter`/`RuntimeLoader` (23) | **Excluded for those two files**, with the reasoning in `phpcs.xml.dist`: BUILD-SPEC §10 requires an atomic write, and `WP_Filesystem` has no atomic rename; suppression is how the loader discovers that mu-plugins is unwritable and falls back. |
| `proc_open` for `php -l` (1) | Excluded for `RuntimeWriter`, which guards it with `function_exists` and a `disable_functions` check. |
| `json_encode` in `Contracts\Json` (1) | Excluded: contracts must work with no WordPress loaded, so `wp_json_encode` does not exist there. |
| Core hook names and globals in tests (16) | `PrefixAllGlobals` excluded for `tests/`: tests fire core hooks to observe them, they are not defining a plugin API. |

### Run 10 — full gate

| Check | Command | Result |
|---|---|---|
| Unit | `vendor/bin/phpunit` | ✅ **797 tests, 3 179 assertions, 0 failures** |
| Integration | `php tools/phpunit-9.phar -c phpunit-wp.xml.dist` (in `tests-cli`) | ✅ **33 tests, 95 assertions, 0 failures** |
| PHPCS | `vendor/bin/phpcs --standard=phpcs.xml.dist` | ✅ **0 errors, 0 warnings** across 87 files |
| PHPStan level 6 | `vendor/bin/phpstan analyse` | ✅ **no errors** |

Nothing is skipped or marked incomplete in either suite; both configurations set
`failOnRisky` and `failOnWarning`.

### Coverage of the phase's required tests

| Requirement (§17 Phase 1) | Where |
|---|---|
| Compiler snapshots for 0, 1, 3 tweaks | `CompilerTest`, snapshots in `tests/Fixtures/runtime` |
| Byte-identical regeneration | `CompilerTest`, `RuntimeGenerationTest` |
| Parameter escaping | `CompilerTest` — includes a value containing `' ); evil(); //` |
| Empty selection: zero hooks | `RuntimeOverheadTest` — full hook-table diff, not a spot check |
| Empty selection: zero added DB queries | `RuntimeOverheadTest` — every query captured through the `query` filter |
| One tweak: only its hooks | `RuntimeOverheadTest` — exactly one hook added, none removed |
| `GET wpdebloat/v1/status` | `StatusRouteTest` — 401, 403, 200, plus tamper reporting |

### Environment notes

- Integration tests run on WordPress latest under wp-env, on PHP 8.2 only. The
  PHP 8.1/8.3 and WP latest−1 legs of §14's matrix remain a CI concern.
- The wp-env stack is vanilla WordPress. WooCommerce, Elementor, CF7, Rank Math
  and LiteSpeed join it in Phase 2, where the detectors first need them.

---

## Phase 2 — Scanner (facts only)

### Run 1 — unit suite after adding the scanners

**Result:** 817 tests, **1 failure**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `RepositoryInvariantsTest::test_contracts_and_registry_do_not_call_wordpress` — `src/Registry/Detector.php` contains `get_option(` | Real boundary violation, caught by an invariant written in Phase 0. `Detector::matches()` was evaluating its own signals, which means asking WordPress whether a plugin is active, a constant defined, an option present. A registry document class has no business doing that. | Made `Detector` pure data: it exposes `signals()`, and `PluginScanner` — which is allowed to know about WordPress — does the looking. The boundary is better for it, not merely compliant. | ✅ |

### Run 2 — integration suite

**Result:** 55 tests, **2 failures**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `ScannerTest::test_expired_transients_are_distinguished_from_live_ones` — expected 2 new transients, got 4 | **Real bug.** Every transient with an expiry has a companion `_transient_timeout_*` option, and that row also matches the `_transient_%` prefix, so `db.transients.count` was counting every transient twice. A user would have been shown double the number of transients their site actually has. | Excluded the timeout rows: `option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%'`. | ✅ |
| `ScannerTest::test_facts_contain_no_opinions` — a fact contained "bloat" | Test scoping, not a product problem: the plugin's own name ("WP Debloat") appears in `plugins.meta` and `plugins.inactive`, because a scan reports the plugins it finds. | Excluded the fact keys that are lists of other people's product names (`plugins.*`, `theme.*`) before searching. The invariant is about words *this plugin* writes, not names it observed. | ✅ |

### Run 3 — PHPStan level 6

**Result:** **1 error** — `Constant DB_NAME not found` in `DatabaseScanner`.

Defined by `wp-config.php` on every install, so it was added to the analysis
bootstrap alongside `DB_PASSWORD`.

### Run 4 — PHPCS

**Result:** 42 violations in 12 sources.

| Group | Disposition |
|---|---|
| `PreparedSQL.NotPrepared` / `InterpolatedNotPrepared` across the scanners, repository and schema (18) | Every interpolated name is a table or column name from `$wpdb` or from a constant in the class; values are always parameterised, and a table name cannot be a placeholder. Scoped `phpcs:disable`/`enable` blocks with the reasoning inline. The first attempt used single-line `phpcs:ignore`, which does not cover a multi-line statement — the codes now wrap the whole statement. |
| `PrefixAllGlobals` in `tools/seed-fixture.php` (11) | It is a top-level script, so every local reads as a global to the sniff. Excluded for `tools/`, which is a local-only fixture generator that refuses to run unless `WP_ENVIRONMENT_TYPE` is `local`. |
| `NoReservedKeywordParameterNames` — `$match` (1) | **Fixed**: the constructor parameter is now `$signals`. |
| `YodaConditions` (3) | **Fixed** by naming intermediate results. |
| Non-prefixed hook names — `heartbeat_settings`, `xmlrpc_enabled` (2) | These are core's own filters, applied to read their result the way WordPress reads it. Ignored with that reason at each site. |
| `RestrictedVariables` — `$wpdb->users` (1) | Counting orphaned user meta means joining against the users table; nothing else distinguishes an orphan from a live row. Ignored with that reason. |
| Cron interval and `meta_value` in tests and the fixture script (4) | A sub-minute schedule and orphaned meta are the point of the fixture. Excluded for `tests/` and `tools/`. |

### Run 5 — full gate

| Check | Result |
|---|---|
| Unit | ✅ **817 tests, 3 338 assertions, 0 failures** |
| Integration | ✅ **55 tests, 166 assertions, 0 failures** |
| PHPCS | ✅ **0 errors, 0 warnings** across 108 files |
| PHPStan level 6 | ✅ **no errors** |

### What the integration tests actually assert

Counting is only worth testing against data that exists, so each test seeds
exactly what it measures and asserts with no tolerance:

| Fact | Seeded | Asserted |
|---|---|---|
| `db.revisions.count` | three saves of one post | exact delta against `wp_get_post_revisions()` |
| `db.trash.count`, `db.autodrafts.count` | one each | exact delta, and that they are counted separately |
| `db.spam_comments.count` | three spam comments | exact delta |
| `db.transients.count` / `.expired` | one live, one written directly with a past timeout | both counted, only one expired |
| `db.orphan_postmeta.count` | one meta row pointing at post 9999999 | exact delta |
| `db.autoload.bytes` | a 50 KB autoloaded option, then a 50 KB non-autoloaded one | grows by exactly the first, unchanged by the second |
| `cron.events.count`, `.subminute`, `.orphans` | a 30-second event and an unlistened hook | exact delta, hook listed by name |
| `users.admin_count` | three administrators and a subscriber | grows by three |
| `wp.*` features | the real runtime, applied | flip from true to false, and an untouched feature stays true |
| `wp.heartbeat_interval` | a `heartbeat_settings` filter | follows the filter, 15 → 60 |

### Environment notes

- The wp-env test environment resolved to WordPress 7.1 on PHP 8.3. The scanners
  are written against the 6.5+ API surface and pass on it; the version matrix in
  §14 remains a CI concern.
- The full plugin stack (WooCommerce, Elementor, CF7, Rank Math, LiteSpeed) is
  configured in the `development` environment for manual work. The test
  environment stays vanilla and uses stub plugin files for detection, which is
  exactly what a detector reads.

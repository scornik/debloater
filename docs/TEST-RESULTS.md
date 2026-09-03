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

---

## Phase 3 — Analyzer, findings, Don't Touch, Score

### Run 1 — unit suite after adding the analyzer

**Result:** 817 tests, **4 failures**, all in `LoaderTest`.

All four were Phase 1 assertions written when the registry held exactly five
safe config tweaks. Phase 3 completes the §15 MVP set, so risk levels now vary
and one tweak is a data operation with a class handler rather than a file.

The assertions were **re-scoped, not relaxed** — and ended up stronger:

| Was | Now |
|---|---|
| "the five Phase 1 tweak ids" | the eleven MVP ids, pinned as a list |
| "every tweak is SAFE" | the exact risk **per tweak id**, because risk is what decides whether a tweak can reach "Fix Safe Issues" |
| "every tweak is a config tweak" | nothing is destructive, everything is reversible, and there is **exactly one** data tweak |
| "every handler file exists" | every *config* handler file exists and declares register/unregister; every *data* handler names a class in the DataOperations namespace |

### Run 2 — i18n in a suite with no WordPress

**Result:** 928 tests, **many errors** — `Call to undefined function __()`.

The analyzer writes user-visible text, which must be translatable
(CONVENTIONS.md), while the unit suite runs with no WordPress by design.

Fixed with `tests/wp-i18n-polyfill.php`: guarded stand-ins for `__`, `_n`, `_x`,
`esc_html__` and `number_format_i18n` that return the untranslated string —
exactly what WordPress does when no translation is loaded. Deliberately minimal:
a missing function should fail loudly, because it means code that should not
depend on WordPress has started to.

### Run 3 — a real modelling error, caught by a test

**Result:** 928 tests, **2 failures**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `test_heartbeat_is_not_refused_without_collaboration` and `test_refused_findings_do_not_lower_the_score` — Heartbeat was `dont_touch` on a store with one recent editor | **Real bug.** WooCommerce declares a dependency on the `heartbeat` capability — correctly, its checkout keep-alive needs it. The refusal logic treated any declared dependency as a reason to refuse, so `core.heartbeat_interval` was refused on **every WooCommerce site**. But that tweak does not remove Heartbeat; it slows it from 15 s to 60 s. The dependency is still satisfied afterwards. | Split the capability map in two (D-0011): `REMOVES_CAPABILITY` refuses, `AFFECTS_CAPABILITY` only lowers confidence. Heartbeat is in the second. Refusing a change of *degree* is left to situational rules, which can weigh how the site is actually used — which is what §17 Phase 3 specifies. | ✅ |

This is the failure the phase was worth writing tests for. Without it, every
WooCommerce user would have seen "No action recommended" against a change that
is perfectly reasonable on most of their sites.

### Run 4 — PHPStan level 6

**Result:** **9 errors.**

| Failure | Fix |
|---|---|
| `AnalyzerRuleInterface::baseConfidence()` undefined (1) | Base confidence *is* part of the rule contract (§6, "rule-declared base confidence"), so it was added to the interface rather than left on the abstract class. |
| `impact()` never returns null (8) | The concrete core-feature rules always state an impact; the return type was narrowed from `?Impact` to `Impact`, which is valid covariance. |

### Run 5 — integration suite

**Result:** 70 tests, **3 failures**, all weak assertions of my own:

| Failure | Root cause | Fix |
|---|---|---|
| `test_findings_survive_storage` | JSON has one number type, so an impact of `1.0` comes back as `1`. The contracts already handle this — `Assert::float` widens an int, because JSON cannot spell `1.0` — but the test compared raw arrays. | Compare the rebuilt `Finding` contracts, which is the meaningful assertion. |
| `test_findings_can_be_filtered` | The final assertion was tautological (`13 < 13`). | Replaced with a real one: a filter narrows the list, and the three decision buckets account for the whole list exactly. |
| `test_findings_exposes_nothing_sensitive` | `DB_PASSWORD` on a test install is the literal string "password", which appears legitimately in the XML-RPC finding ("try many passwords in one request"). | Check the high-entropy secrets — `AUTH_KEY`, `AUTH_SALT`, `SECURE_AUTH_KEY`, `NONCE_SALT` — rather than words that occur in prose. The same weak check in `StatusRouteTest` was fixed with it. |

### Run 6 — full gate

| Check | Result |
|---|---|
| Unit | ✅ **928 tests, 3 840 assertions, 0 failures** |
| Integration | ✅ **70 tests, 260 assertions, 0 failures** |
| PHPCS | ✅ **0 errors, 0 warnings** across 144 files |
| PHPStan level 6 | ✅ **no errors** |

### The phase exit criterion, measured

BUILD-SPEC §17 Phase 3 requires "≥ 12 findings including ≥ 1 dont_touch" on a
seeded full-stack site. On the `busyStore` fixture — WooCommerce, Contact Form
7, LiteSpeed, three recent editors, 31 421 revisions, 4 832 expired transients —
the analyzer produces **13 findings, one of them refused**: Heartbeat, because
three people edited content last week on a store.

On the bare wp-env test site the same analyzer produces 12 findings and no
refusal, which is the correct answer for a site with nothing installed to
depend on anything.

---

## Phase 4 — Recommendation engine

### Run 1 — the property tests, first attempt

**Result:** 10 tests, **2 failures** — and both were real.

BUILD-SPEC §17 Phase 4 asks for the §7.4 invariants as property tests over
generated registries. The generator builds deliberately awkward ones: conflicts,
chained requirements, fact predicates that may or may not hold, destructive
tweaks, and refusals. 120 seeded cases per invariant, so a failure is
reproducible from the seed in the message.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| seed 4: `gen.tweak_2` is in the plan but its requirement `gen.tweak_1` is not | **Real bug.** Requirements were checked against the *candidate* list, not against the plan. A tweak whose requirement was later excluded — refused, filtered, or dropped itself — still passed, so the plan contained a change depending on something that was not going to happen. | A requirement counts only when the required tweak is in the plan, resolved to a fixed point because excluding one can leave another unmet (D-0014). Each dropped tweak's reason quotes why its requirement is missing, so a chain reads back to its cause. | ✅ |
| seed 5: `gen.tweak_1` is in the safe plan but not the maximum one | **Real design gap.** Conflicts were resolved by lowest tweak id. Under `safe` a medium-risk tweak was filtered out so its partner won; under `maximum` the medium-risk one was admitted first and won instead. A *wider* profile produced a plan missing something the narrower one offered. | Candidates are ordered before anything is decided: non-destructive first, then lower risk, then id (D-0013). Widening a profile now only ever adds. | ✅ |

Both are exactly what property tests are for. Neither would have been found by
an example test, because neither arises from a registry anyone would think to
write by hand.

### Run 2 — PHPStan level 6

**Result:** **3 errors**, all "property is never read, only written".

All three were load-bearing in a first draft and became dead weight when the
design improved:

| Property | Why it died |
|---|---|
| `RiskEngine::$compatibility` | `hasDependents()` now reads the count from the finding, which is where the capability mapping already lives. Keeping the parameter implied a dependency the class does not have, so the constructor takes facts only. |
| `RecommendationEngine::$facts` | Only ever needed to build the RiskEngine. |
| `PreviewPlanner::$findings` | Superseded by the refusal map built from the same loop. Removing it also removes the temptation for a later change to start reading a finding's severity or confidence when deciding what goes in a plan — neither of which has any business doing that. |

A dependency a class does not use is a lie about what it needs, so all three
were removed rather than annotated.

### Run 3 — PHPCS

**Result:** 1 violation — a Yoda-condition complaint in `FactPredicate`. Fixed
by naming the intermediate value.

### Run 4 — full gate

| Check | Result |
|---|---|
| Unit | ✅ **959 tests, 6 683 assertions, 0 failures** |
| Integration | ✅ **82 tests, 317 assertions, 0 failures** |
| PHPCS | ✅ **0 errors, 0 warnings** across 157 files |
| PHPStan level 6 | ✅ **no errors** |

### What the property tests actually check

Per invariant, 120 generated registries of 3–10 tweaks each, with conflicts,
requirements, fact predicates and refusals distributed pseudo-randomly from the
seed:

| Property | Assertion |
|---|---|
| Safe plan, destructive | no destructive tweak, ever |
| Safe plan, risk | every tweak safe or low |
| Any profile, conflicts | no conflicting pair, resolved in both directions |
| Any profile, refusals | no tweak named by an active dont_touch finding |
| Requirements | every planned tweak's requirements are themselves planned, and its fact predicates hold |
| Exclusions | every candidate is either planned or explained, and no reason is blank |
| Determinism | same plan twice, and the same plan from a reversed candidate list |
| Monotonicity | a wider profile never drops what a narrower one allowed |
| Snapshot levels | Level A always, Level B if and only if the plan contains a data tweak |

---

## Phase 5 — Snapshot, apply, rollback

### Run 1 — unit suite after the new state-machine work

```
vendor/bin/phpunit
```

**Result:** 967 tests, 7 050 assertions, **0 failures**. Eight new tests cover
`TweakStateMachine::pathTo()`, including a case that walks every route the §9.1
table offers through a real machine — which throws on an illegal edge, so a route
the machine would refuse cannot pass.

### Run 2 — first integration run of the apply and rollback tests

```
npm run test:integration
```

**Result:** 95 tests, 393 assertions, **1 failure**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `ApplyRollbackTest::test_applying_five_config_tweaks_then_rolling_back_restores_the_runtime_byte_for_byte` — applied ids in a different order | Test error, not a product error. `ApplyResult` sorts `applied` in its constructor so the field is stable across runs; the test asserted the order the ids were listed in. | Sort the expectation. The contract's sorting is the behaviour worth keeping — an unstable list would make every report diff noisy. | ✅ |

### Run 3 — integration suite after the fix

```
npm run test:integration
```

**Result:** 95 tests, 404 assertions, **0 failures**.

### Run 4 — PHPCS

```
vendor/bin/phpcs --standard=phpcs.xml.dist
```

**Result:** 7 errors, 5 warnings in 6 files.

| Violation | Fix |
|---|---|
| `Journal` interpolates the table name into three queries (`InterpolatedNotPrepared` ×3) | Same class-level `phpcs:disable` the two repositories already carry, with the same written justification: a table name cannot be a placeholder, and this one is built from `Schema`'s constants plus `$wpdb->prefix`. |
| `SnapshotRepository::markRestored()` — `UnfinishedPrepare` on an `IN` list built from a count | The single-line `phpcs:ignore` sat above the `$wpdb->query(` line while the violation was reported on the `$wpdb->prepare(` line inside it. Folded into the class-level disable rather than chasing the line number. |
| `SnapshotManager` — one non-Yoda comparison, two single-line associative arrays | Fixed; the arrays by `phpcbf`. |
| Alignment warnings in `Schema`, `TweakStateMachine` | `phpcbf`. |

### Run 5 — PHPStan level 6

```
vendor/bin/phpstan analyse
```

**Result:** 1 error.

| Error | Fix |
|---|---|
| `src/Plugin.php:164` — action callback returns `array<int,int>` but should not return anything | Hooked a `recoverOnBoot(): void` wrapper instead. `recoverInterruptedRuns()` keeps returning the ids, which the tests and the forthcoming CLI want; a hook callback is a different job from an API method and now says so. |

### Run 6 — spill-to-file implementation

The first pass through the phase implemented the 8 MB threshold as a constant and
never used it: `captureData()` always wrote to the items table. That is a
requirement of §17 Phase 5, not an optimisation, so the phase could not be called
complete. `Snapshot\SpillFile` and the streaming collection path were added, with
seven integration tests that cross the real threshold — 160 transients of 64 KB —
rather than lowering it for the test.

```
npm run test:integration
```

**Result:** 102 tests, 746 assertions, **0 failures**.

One implementation detail worth recording: the truncated-file test found that a
damaged gzip stream makes `gzgets()` return `false` **without** `gzeof()` ever
becoming true, so the original read loop would have spun forever on a corrupt
file. It now breaks on `false` and lets the item count and checksum report the
damage, which is what marks the snapshot corrupt.

### Run 7 — a Phase 1 invariant caught the new directory

```
npm run test:integration
```

**Result:** 102 tests, 745 assertions, **1 failure**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `RuntimeGenerationTest::test_generated_files_stay_in_one_directory` — found `backups` beside the runtime | The Phase 1 test asserted that `wp-content/wpdebloat` contains exactly `index.php`, `runtime.lock` and `runtime.php`. Writing the first spilled recovery point creates the `backups/` subdirectory that `BUILD-SPEC.md` §4 places there, so the assertion was now narrower than the specification. | Separate files from directories: the file list is still exactly those three, and `backups` is the only directory permitted beside them. The invariant that matters — nothing generated outside `wp-content/wpdebloat` — is unchanged and still asserted. | ✅ |

The failure only appeared on a second run, because the directory survives on the
container's filesystem while the runtime files are removed after each test. Worth
recording: a test that passes once and fails on the rerun is the same class of
bug as one that fails intermittently, and the rerun is what caught it.

### Run 8 — full regression

```
vendor/bin/phpunit
npm run test:integration
vendor/bin/phpcs --standard=phpcs.xml.dist
vendor/bin/phpstan analyse
```

| Gate | Result |
|---|---|
| Unit | ✅ 967 tests, 7 054 assertions |
| Integration | ✅ 102 tests, 746 assertions |
| PHPCS | ✅ 0 errors, 0 warnings, 170 files |
| PHPStan level 6 | ✅ no errors |

---

## Phase 6 — Verification engine

### Run 1 — can the test environment reach itself at all

Before writing the probe tests, the question had to be answered rather than
assumed:

```
wp-env run tests-cli wp eval 'wp_remote_get( home_url() )'
```

**Result:** `cURL error 7: Failed to connect to localhost:8889`.

The suite runs in the `tests-cli` container; the site is served by
`tests-wordpress`. `home_url()` is `http://localhost:8889`, and inside the runner
`localhost` is the runner. `curl http://tests-wordpress:80/` from the same
container answers `301` — the site is reachable, but only at an address it does
not consider its own, and it redirects to the canonical one that is not routable
from there.

Even with the routing solved, an HTTP request opens its own database connection
and cannot see the uncommitted transaction each WordPress test runs inside, so a
probe would observe a site without the state the test had just created.

**Conclusion, recorded rather than worked around:** probe behaviour is tested
against fixture responses through `pre_http_request`, which covers every branch
deterministically; real-loopback verification against committed state is
exercised on the fixture site in Phase 7. The blocked-loopback path is not a
workaround here — it is the environment's genuine behaviour, and the same
behaviour a host with outbound requests disabled will show.

### Run 2 — first integration run of the verification tests

```
npm run test:integration:main
```

**Result:** 123 tests, 799 assertions, **1 failure**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `VerificationTest::test_blocked_loopback_reports_unknown_and_warns` — `content_page` was `NOT_TESTED`, not `UNKNOWN` | Correct behaviour, incorrect test. A probe that does not apply reports `NOT_TESTED` before reachability is considered, and the test install had no published post for it to fetch. | Create a published post in the test, so every probe applies and the assertion is about the loopback and nothing else. The stronger assertion is the point: all six probes UNKNOWN. | ✅ |

### Run 3 — first run of the forced-failure suite

```
npm run test:integration:fail-probe
```

**Result:** 5 tests, 85 assertions, **1 failure**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `VerificationRollbackTest::test_the_tweaks_are_journalled_as_rolled_back` — the journal recorded `SELECTED → PREVIEWED → SNAPSHOTTED → APPLY_FAILED → ROLLED_BACK` instead of `APPLIED → VERIFICATION_FAILED → ROLLED_BACK` | **A real ordering bug.** `rollBack()` restored the Level A snapshot first, which puts the *recorded* tweak states back, and only then asked the lifecycle where each tweak was. By that point the answer was "nowhere" — the states had been erased — so the journal described a route the run never took. | Capture the tweak states **before** the restore and advance from those explicitly (`TweakLifecycle::statesOf()` and `advanceAllFrom()`). The journal now describes the route each tweak actually travelled. The same fix applies to the manual rollback and to crash recovery, which had the same ordering. | ✅ |

Worth noting what caught this: not an assertion about rollback, which passed
throughout, but an assertion that the *journal* was truthful. The rollback was
always correct; its record of itself was not.

### Run 4 — unit suite with the marker tests

```
vendor/bin/phpunit
```

**Result:** 977 tests, 7 129 assertions, **1 failure**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `MarkersTest::test_a_page_that_talks_about_errors_is_not_an_error_page` — the body `<p>WP_Errors are objects; this post explains them.</p>` was reported as a fatal page | **A real bug, found by a test written to be hostile to the implementation.** §11 lists `WP_Error` as a fatal marker; matched as a bare class name it fires on any page that mentions the class, and the consequence is rolling back a change on a site whose only offence is writing about WordPress. | Match the *printed* forms instead — `WP_Error Object` and `object(WP_Error)` — which is what a page that actually dumped an error contains. Recorded as a deliberate deviation in D-0019, with a test for both directions. | ✅ |

### Run 5 — PHPStan level 6

```
vendor/bin/phpstan analyse
```

**Result:** 4 errors, all `Constant LOGGED_IN_COOKIE not found`.

WordPress defines the cookie constants during `wp-settings.php`, so static
analysis cannot see them. Added to `tests/phpstan-bootstrap.php` and listed in
`dynamicConstantNames`, alongside the other constants whose values must not be
reasoned about from their placeholders.

### Run 6 — PHPCS

```
vendor/bin/phpcs --standard=phpcs.xml.dist
```

**Result:** 22 errors, 2 warnings; 15 fixed automatically.

| Violation | Fix |
|---|---|
| `wp_remote_get()` discouraged in favour of `vip_safe_wp_remote_get()` | Excluded for `Verify\HttpClient` with the reasoning written into `phpcs.xml.dist`: the VIP wrapper exists to give up early on a flaky third-party API, and these requests are the site asking itself whether it still works. It also does not exist outside VIP. |
| `$_COOKIE` flagged by VIP's cache-constraints sniff (×5) | Excluded for `Verify\ActorSession`, likewise documented: the sniff is about page output varying by cookie behind a cache, and nothing here renders anything. The value is validated by `wp_validate_auth_cookie()` before use and never echoed. |
| `https_local_ssl_verify` reported as an unprefixed hook name | Scoped ignore: it is core's own filter, read rather than introduced. |
| `numberposts => -1` in a test | Bounded to 100. |
| Alignment and array formatting | `phpcbf`. |

### Run 7 — full regression

```
vendor/bin/phpunit
npm run test:integration
vendor/bin/phpcs --standard=phpcs.xml.dist
vendor/bin/phpstan analyse
```

| Gate | Result |
|---|---|
| Unit | ✅ 978 tests, 7 133 assertions |
| Integration | ✅ 123 tests, 809 assertions |
| Forced-failure suite | ✅ 5 tests, 74 assertions |
| PHPCS | ✅ 0 errors, 0 warnings, 187 files |
| PHPStan level 6 | ✅ no errors |

---

## Phase 7 — WP-CLI

### Run 1 — integration suite with the command tests

```
npm run test:integration:main
```

**Result:** 141 tests, 914 assertions, **0 failures**. Eighteen new tests drive
the command objects with a recording terminal, covering every exit code, the
`--yes` gates, the schema validation on import, and the whole
scan → apply → status → rollback loop.

### Run 2 — the forced-failure suite

```
npm run test:integration:fail-probe
```

**Result:** 8 tests, 85 assertions, **0 failures**. Three of them are new: `apply`
exits 2 when the change is rolled back, `verify` exits 2 on a failed check, and
the JSON result names the check that failed so a script can report it.

### Run 3 — unit suite

```
vendor/bin/phpunit
```

**Result:** 978 tests, 7 119 assertions, **1 failure**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `RepositoryInvariantsTest::test_registry_schemas_are_valid_json` — found seven schemas where §4 names six | **A Phase 0 invariant doing its job.** The configuration-document schema had been put in `registry/schemas/`, where it does not belong: it describes a CLI document, not registry content. | Moved to `schemas/config.schema.json`, and gave the new directory its own invariant — valid draft-07, with a title and a description — so it is held to the same standard without weakening the count that guards the registry. | ✅ |

The tempting fix was to change `assertCount( 6, ... )` to `7`. That would have
quietly turned an invariant about the registry into a running total of files.

### Run 4 — PHPStan level 6

```
vendor/bin/phpstan analyse
```

**Result:** 7 errors, all `unknown class WP_CLI` in `Cli\WpCliIo`.

WP-CLI is not a dependency of the plugin and is not loaded on a web request. The
stubs were already a dev dependency; added `php-stubs/wp-cli-stubs` to
`scanFiles` so level 6 can reason about the one class that talks to it.

### Run 5 — the loop through a real `wp` binary

```
npm run test:cli
```

**Result:** every command using `--json` failed:

```
Error: Parameter errors:
 unknown --format parameter
```

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `scan --json`, `findings --json`, `preview --json`, `status --json` all exit 1 | **A real bug, invisible to every test written so far.** WP-CLI reserves `--json` as shorthand for `--format=json` and rewrites it during argument parsing. A command that declares `[--json]` in its own synopsis therefore never receives it, and is handed a `--format` it did not declare. | Declare `[--format=<format>]` with the options `table` and `json`. WP-CLI's rewriting then makes `--json` work exactly as §17 describes, and `--format=json` works too. Recorded as D-0021. | ✅ |

Worth stating plainly: the integration suite drives the command objects
directly, which covers every branch of their behaviour and *cannot* catch a
mistake in the synopsis, because WP-CLI is not involved. This is the whole
argument for `tools/cli-e2e.sh` — one slow test against the real binary found a
bug that a hundred fast ones could not see.

The first attempt at that script also failed for a reason of its own: the
fixture site had never had the plugin activated, and the script's "clean slate"
step recorded the resulting error as a failure. Both are fixed — the script
activates the plugin and tolerates having nothing to undo.

### Run 6 — full regression

```
vendor/bin/phpunit
npm run test:integration
npm run test:cli
vendor/bin/phpcs --standard=phpcs.xml.dist
vendor/bin/phpstan analyse
```

| Gate | Result |
|---|---|
| Unit | ✅ 979 tests, 7 156 assertions |
| Integration | ✅ 141 tests, 914 assertions |
| Forced-failure suite | ✅ 8 tests, 85 assertions |
| CLI end to end on the fixture site | ✅ every command, every exit code |
| PHPCS | ✅ 0 errors, 0 warnings, 194 files |
| PHPStan level 6 | ✅ no errors |

---

## Phase 8 — React dashboard

### Run 1 — first build

```
npm run build
```

**Result:** compiled. `index.js` 25 KB raw, `style-index.css` 7.5 KB, plus the
`index.asset.php` manifest.

Worth recording: `@wordpress/scripts` emits the stylesheet as `style-index.css`,
not `index.css`. The enqueue had been written against the name that seemed
obvious, which would have produced an unstyled screen and no error anywhere.

### Run 2 — first Jest run

```
npm run test:js
```

**Result:** 6 tests, **all failing**, for three separate reasons.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `toBeInTheDocument is not a function` | `@testing-library/jest-dom` was not installed or registered. | Installed it and added a setup file to `setupFilesAfterEnv`. | ✅ |
| A third "suite" failed with "must contain at least one test" | `testMatch` was `admin-ui/**/test/*.js`, which matched the style stub as well as the tests. | Matched `*.test.js` only. | ✅ |
| `Cannot find module '@wordpress/components'` | The package is an *external* in the build — it comes from WordPress at runtime — so it was not installed. | Mapped it to a small stub in `jest.config.js`. The stubs render the same roles and accessible names, so a test that looks for a button by its label still finds one; loading the real package would pull its TypeScript source and ESM dependencies into jsdom to prove things about WordPress's components rather than ours. | ✅ |

### Run 3 — Jest, after the setup was right

**Result:** 6 passed, 6 failed.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| Five `Finding` tests failed on `act(...)` warnings | The "what will change" field asks the server what a single change would do, so the component finishes rendering after a promise resolves. The tests asserted against a half-drawn screen. | Render inside `await act( async () => … )` through one helper. | ✅ |

**Result after:** 12 tests, 12 passed.

### Run 4 — integration suite with the admin and write-route tests

```
npm run test:integration:main
```

**Result:** 159 tests, 1 051 assertions, **1 failure**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `AnalyzerTest::test_an_administrator_can_scan` — 403 where 201 was expected | Correct, and caught by a Phase 3 test. `POST /scan` writes a run, so it is a state-changing endpoint, and this phase made every state-changing endpoint require a nonce (§13 rule 12). The test dispatched without one. | Send the nonce, as a real client does — and add a test asserting that scanning *without* one is refused and writes no run. The suite got stricter, not looser. | ✅ |

### Run 5 — ESLint

```
wp-scripts lint-js admin-ui
```

**Result:** 112 errors — 91 formatting, the rest import resolution.

Prettier fixed the formatting. The import errors were `@wordpress/*` packages
being flagged as unresolved and undeclared: true of the *repository*, since the
build treats them as externals, but not true of the *source*, which does import
them. Declared them as devDependencies, which is the accurate statement, and kept
the Jest mapping so the component stub is still what the tests load. Four
remaining JSDoc errors were missing `@param` types on two documented functions.

### Run 6 — bundle budget

```
node tools/bundle-budget.mjs
```

| Asset | Gzipped |
|---|---|
| `index.js` | 6 743 B |
| `style-index.css` | 1 864 B |
| **Total** | **8 607 B — 3% of the 250 KB budget** |

The RTL stylesheet is measured but not counted: it is never loaded alongside the
LTR one, and budgeting for a page that cannot exist would be inventing weight.

### Run 7 — full regression

```
vendor/bin/phpunit
npm run test:integration
npm run test:js
npm run build && node tools/bundle-budget.mjs
vendor/bin/phpcs --standard=phpcs.xml.dist
vendor/bin/phpstan analyse
wp-scripts lint-js admin-ui
```

| Gate | Result |
|---|---|
| Unit | ✅ 979 tests, 7 176 assertions |
| Integration | ✅ 160 tests, 1 057 assertions |
| Forced-failure suite | ✅ 8 tests, 85 assertions |
| Jest | ✅ 12 tests |
| Bundle budget | ✅ 8.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings, 201 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

---

## Phase 9 — Preview, Fix Safe Issues, before and after

### Run 1 — the acceptance test, first attempt

```
npm run test:integration:main
```

**Result:** 174 tests, 1 179 assertions, **1 failure**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `AcceptanceTest::test_a_scan_reports_findings_including_something_to_leave_alone` — zero `dont_touch` findings | The seed produced plenty to find but nothing to refuse. Tracing it through `DontTouchRules`: a refusal needs either a detected component that requires a capability a finding would *remove*, or the situational rule. Of the shipped compatibility rules, none requires `embeds`, `xmlrpc`, `jquery-migrate` or `dashicons:frontend` — the four capabilities the current findings map to — so no compatibility refusal is reachable on any site with today's registry. | Seed the situation §17 Phase 3 describes and the situational rule exists for: WooCommerce active, and two authors who edited content this week. Slowing Heartbeat is then the wrong change *here*, and the plugin says so. | ✅ |

Worth recording rather than fixing: the compatibility registry currently cannot
produce a refusal, because the capabilities its six rules require and the
capabilities the current findings remove do not overlap. That is not a bug in
either half — it is what a six-rule registry against an eleven-tweak MVP looks
like — but it is worth knowing before Phase 12 adds more of both.

### Run 2 — integration suite after the seed was right

**Result:** 174 tests, 1 203 assertions, **0 failures**.

### Run 3 — the other half of §14

```
npm run test:integration:fail-probe
```

**Result:** 9 tests, 102 assertions, **0 failures**. The new one drives Fix Safe
Issues through REST on a site whose `rest` probe is forced to fail, and compares
the runtime bytes and the stored selection against what was there before.

### Run 4 — the rest of the gates

| Gate | Result |
|---|---|
| Unit | ✅ 979 tests, 7 200 assertions |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.4 KB of 250 KB |
| PHPStan level 6 | ✅ no errors |
| PHPCS | ✅ after seven alignment fixes in `Meter` |
| ESLint | ✅ after Prettier formatting |

---

## Phase 10 — Database intelligence

### Run 1 — unit suite after the registry grew

```
vendor/bin/phpunit
```

**Result:** 1 015 tests, **4 errors and 7 failures**, all from assertions
written when the MVP registry was the whole registry.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `AutoloadRule` fatals: `size_format()` undefined (×4) | The unit suite runs without WordPress. | Added `size_format` to the i18n polyfill, beside `number_format_i18n`. | ✅ |
| `RulesTest::test_one_rule_per_finding_id` — expected 14 | A hard-coded count. The invariant is that no two rules share an id; the number only recorded when somebody last updated it. | Derive the count from `Rules::all()` and assert a floor. | ✅ |
| `AnalyzerTest::test_rules_that_cannot_evaluate_are_reported` — expected 14 | Same. | Assert *every* rule reports it could not evaluate, which is the real claim and needs no maintenance. | ✅ |
| `LoaderTest` — MVP tweak set, risks, "nothing is destructive", "exactly one data tweak" | Correct for the MVP; Phase 10 is when they change. | Re-scoped and made stricter: the destructive set is now an explicit list, so a new tweak cannot join it silently, and every data tweak must name a class under `Apply\DataOperations`. | ✅ |

The tempting fix throughout was to raise the numbers. Deriving them instead
means the next phase does not have to.

### Run 2 — the round-trip tests

```
npm run test:integration:main
```

**Result:** 188 tests, **2 errors and 2 failures**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `SnapshotItem::object_type` rejected `meta:post` and `option_autoload` | **The contract was right and the code was wrong.** `object_type` is a closed set fixed in Phase 0, and it already had names for exactly these rows: `postmeta`, `termmeta`, `usermeta`, `commentmeta`, `option`. | Use the contract's vocabulary. `RevisionsCleanup` also now records `revision` rather than `post`, which the contract distinguishes and should: restoring "a post" and restoring "an earlier version of a post" are different promises. | ✅ |
| The revisions round-trip expected every collected row to be deleted | The operation keeps the newest by design, so "everything captured is gone" was the wrong expectation for it. | Compare the rows that should actually go. | ✅ |
| The autoload test expected `'yes'` | WordPress 6.6 renamed these values: `yes`/`no` became `on`/`off`. | The operation now calls `wp_set_option_autoload()` when it exists, so WordPress decides the spelling; the test compares against what was there rather than a literal, and the restore puts back the exact original string. | ✅ |

### Run 3 — the refusal tests, and a bug worth the phase

```
npm run test:integration:main
```

**Result:** 193 tests, **1 failure** — and the failure was the product, not the
test.

`test_an_incomplete_recovery_point_refuses_the_deletion` expected a rollback
from a corrupt Level B snapshot to be refused. It succeeded instead.

`RollbackManager::restoreRun()` skipped any snapshot that was not restorable.
For a corrupt Level B that meant: the configuration went back, the run was
marked `ROLLED_BACK`, the user was shown "previous configuration restored" — and
the rows the change had deleted were still gone. A success message over a data
loss, which is worse than an error.

It now stops with the reason. `ApplyManager::rollbackRun()` catches that and
returns it as a result rather than an exception, because the caller is a person
who asked to undo something and what they need is the reason.

### Run 4 — full regression

| Gate | Result |
|---|---|
| Unit | ✅ 1 016 tests, 7 443 assertions |
| Integration | ✅ 193 tests, 1 279 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings, 227 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

---

## Phase 11 — Plugin intelligence

### Run 1 — unit suite after the new rules landed

```
vendor/bin/phpunit
```

**Result:** 1 031 tests, **2 failures**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `AnalyzerTest::test_a_complete_fact_set_evaluates_every_rule` | The "complete fact set" fixture predates three facts. | Added `plugins.categories`, `plugins.update_source` and `plugins.host_optimizers` to the fixture, and gave the busy store a plugin list with dates. | ✅ |
| `RepositoryInvariantsTest::test_registry_schemas_are_valid_json` — expected 6, found 8 | A count standing in for "the registry object types are these". | Assert the exact set by name instead: the six §4 object types plus the two Phase 11 tables, and nothing else. Stricter, and it does not need editing when the truth changes. | ✅ |

### Run 2 — the contract said no

```
vendor/bin/phpunit
```

**Result:** 1 031 tests, **111 errors**, every one of them
`Fact::plugins.categories: must not nest more than one level below the fact
value`.

Both new facts were shaped as a list of objects with a list inside — a category
with its plugins, an optimizer with the findings it covers. `Fact` has allowed
exactly one level of nesting since Phase 0, so that fact values stay trivially
diffable.

The contract was right. Both facts are now **one row per pair** — one per
classified plugin, one per optimizer-and-finding — which is flat, and is also
what they actually are: small relations, which the rules group for themselves.

### Run 3 — integration, and prose where it should not be

```
npm run test:integration:main
```

**Result:** 201 tests, **2 failures**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `ScannerTest::test_facts_contain_no_opinions` — a fact contained "optimi" | Two things at once. The word is in a product's own name, which the test already exempts elsewhere for plugin and theme names. But the fact *also* carried registry prose: a sentence about what running two page caches costs. | Both. The exemption list gains the two new plugin facts, with the same rationale it already gives. And the prose came out: the category table now carries a **label only**, the reasoning moved into `DuplicateFunctionalityRule` where reasoning belongs, and where an optimizer keeps its settings is read from the registry rather than copied into the facts. A new assertion refuses any sentence-shaped string in a fact set, so this cannot come back under a word the list does not have. | ✅ |
| `PluginIntelligenceTest::test_the_opt_in_looks_up_release_dates` — no requests were made | The fixture install has plugins on disk and none active, so the lookup asked about an empty list. The test would have passed on broken code just as happily. | Activate one first, and assert it is active before asserting the requests. | ✅ |

### Run 4 — a registry entry that pointed at nothing

`PluginIntelligenceTest::test_a_covered_finding_gains_a_sentence_and_keeps_its_weight`
failed on a null. `host-optimizers.json` claimed a setting for
`wp.emojis.enabled` — which is the *fact* key. The finding is
`wp.emojis.loaded`.

Nothing errored. The lookup simply never matched, and the whole feature was a
no-op that looked implemented. `RegistryTablesTest::test_every_covered_finding_id_is_real`
now asserts every `covers` id against `Rules::all()`.

### Run 5 — static analysis

PHPCS objected to `wp_remote_get()` under the VIP standard, which prefers
`vip_safe_wp_remote_get()`. That function exists only on VIP; WP Debloat ships
with zero runtime dependencies and has to work anywhere. What it buys — a bounded
timeout and a graceful failure — this call already has: five seconds, and every
failure path returns null so the scan falls back to the local reading. Ignored
with that reason on the line.

### Run 6 — full regression

| Gate | Result |
|---|---|
| Unit | ✅ 1 053 tests, 7 607 assertions |
| Integration | ✅ 201 tests, 1 332 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| CLI end-to-end (real `wp` binary) | ✅ whole loop on the fixture site |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings, 238 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

---

## Phase 12 — Admin intelligence

### Run 1 — the compiler names the class, not the file

```
vendor/bin/phpunit
```

**Result:** 1 053 tests, **4 failures**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `CompilerTest::test_every_generated_class_exists_in_its_handler_file` ×3 | The generated runtime derives a handler's class name from the *tweak id*, so `admin.remove_wp_news_widget` must define `WPDebloat_Handler_Admin_Remove_Wp_News_Widget`. Three handlers had been named after what they do rather than after their tweak. | Renamed the classes and the files to follow the id, as every existing handler already did. | ✅ |
| `RepositoryInvariantsTest` — the schema set | A new registry table, so a new schema. | Added `admin-notices.schema.json` to the named set. | ✅ |

### Run 2 — the pinned tweak set

**Result:** 2 failures, both the MVP-set invariants from Phase 1, which pin the
shipped tweaks by name and by risk. Extended with the five admin tweaks and
their risks — `medium` for notice suppression, which is what keeps it out of
"Fix Safe Issues", and the reason is in the test.

### Run 3 — the sub-score count

`AnalyzerTest::test_the_v1_sub_scores_are_the_specified_five` failed on six.
Rewritten rather than renumbered: it now asserts the set the rubric names, pins
the rubric version, and keeps the two assertions that actually matter — that
`performance` is not a sub-score and neither is `assets` yet.

### Run 4 — integration, and a handler adding a notice

```
npm run test:integration:main
```

**Result:** 214 tests, **3 errors and 2 failures**.

| Failure | Root cause | Fix | Retest |
|---|---|---|---|
| `admin.hide_update_nags_non_admins left hooks behind after unregister()` | `unregister()` put `update_nag` back on **both** notice hooks whether or not it had removed it from both. On an install where the notice was only on `admin_notices`, undoing the change *added* a notice — a handler whose whole job is to hide one. | Track which hooks it actually removed, and restore only those. | ✅ |
| `test_a_source_outside_the_allowlist_is_refused` expected the wrong exception | The refusal comes from the tweak's own parameter schema, which throws `RuntimeException`. | Assert the refusal and the message. The point is that the allowlist is enforced by schema validation, so nothing further down has to be trusted. | ✅ |
| Three errors: "headers already sent", and one wrong method name | The tests fired `admin_init` and `admin_head`, which send headers the test bootstrap has already printed past. | Drive the handler methods directly; the hook wiring has its own test. | ✅ |

### Run 5 — static analysis

| Finding | Fix |
|---|---|
| `$item->src ?? ''` — `src` is `string\|false`, never null | A registered handle's `src` is `false` when it is an alias with no file of its own. Checked for what it actually is. |
| `array_values()` on something already a list | Removed. |
| `add_action( $hook, 'update_nag', 3 )` returns `void\|false` | Core's own callback, put back exactly as WordPress registered it. Ignored on the line, with that reason. |
| Short ternaries ×5, a reserved keyword as a parameter name, an assignment to `$wp_meta_boxes` | Rewritten. The global assignment is deliberate — clearing the registry so `wp_dashboard_setup()` rebuilds it is the measurement — and is ignored with that reason. |

### Run 6 — full regression

| Gate | Result |
|---|---|
| Unit | ✅ 1 087 tests, 7 837 assertions |
| Integration | ✅ 214 tests, 1 438 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| CLI end-to-end (real `wp` binary) | ✅ |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings, 252 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

---

## Phase 13 — Asset intelligence

### Run 1 — the fixture had one more asset than I counted

`AssetParserTest::test_every_enqueued_asset_is_found` failed on `jquery-blockui`,
which WooCommerce enqueues and which I had left out of the expected list when
writing it. The parser was right and the expectation was wrong; corrected rather
than loosened, because a list is the point of that assertion.

### Run 2 — integration, and an assertion defending the wrong promise

```
npm run test:integration:main
```

**Result:** 223 tests, **7 failures**.

Six of them were Phase 11's network tests, which asserted a scan makes **zero**
HTTP requests. The asset scan makes loopback requests, which §13 rule 9 has
always allowed — the zero-request assertion was equivalent only because nothing
had needed loopback yet.

Rewritten to assert what the promise actually is: every request URL must start
with this site's home URL. That is stricter, not weaker, because it keeps
holding however many loopback requests a later phase adds while still failing on
the thing that matters. The opt-in test now separates off-site requests from
local ones and asserts every off-site one goes to `api.wordpress.org`. Recorded
as D-0034.

The seventh was mine: `$sizes['analytics'] ?? 'missing'` — the null I was
looking for is exactly what `??` treats as an absence, so the test could not
distinguish "no size, correctly" from "no such asset". Replaced with an
`assertArrayHasKey` before the `assertNull`.

### Run 3 — static analysis

| Finding | Fix |
|---|---|
| `'' === $url` after `strtok()` always false | `strtok()` returns `false` or a non-empty string; the empty check was noise. Removed. |
| `WPINC` not found | The class is loaded by the unit suite, which runs with no WordPress. Read through `defined()`/`constant()` with the standard value as the fallback. |

### Run 4 — full regression

| Gate | Result |
|---|---|
| Unit | ✅ 1 099 tests, 7 925 assertions |
| Integration | ✅ 223 tests, 1 473 assertions |
| Forced-failure suite | ✅ 9 tests, 102 assertions |
| CLI end-to-end (real `wp` binary) | ✅ |
| Jest | ✅ 12 tests |
| Bundle | ✅ 10.6 KB of 250 KB |
| PHPCS | ✅ 0 errors, 0 warnings, 258 files |
| PHPStan level 6 | ✅ no errors |
| ESLint | ✅ clean |

### What the ten-second criterion does and does not prove

The exit criterion is a scan under ten seconds, and the test asserts it. What it
measures is parsing and attribution over pages served from fixtures, because
real loopback is impossible in this environment (the Phase 6 limitation: wp-env
puts the runner and the web server in different containers, and the site's
canonical URL does not resolve to the site from the runner).

That is stated rather than glossed. The network cost is bounded by construction
instead of by measurement: one loopback check before anything else, five seconds
per page, eight seconds across the whole asset scan, and `pages_sampled` records
how many pages the budget actually allowed — so a slow site produces a smaller
sample rather than a slower scan, and the smaller sample is visible.

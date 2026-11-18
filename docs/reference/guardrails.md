# Guardrails: what each gate checks

Every automated check on this repository, whether it blocks a merge, and how to run it on your own machine. Workflows live in `.github/workflows/` and run only on the operator's self-hosted runners (`[self-hosted, linux, x64, windows-gpu]`); never a GitHub-hosted runner.

**Required** means branch protection on `main` blocks the merge until the check passes (the seven contexts below, verified against the GitHub API on 2026-10-16). **Advisory** means it can fail without blocking and is labelled so in the workflow.

## Required on every pull request

| Check (job name) | Workflow | What it checks | Run locally |
| --- | --- | --- | --- |
| Laravel Pint Code Style | `linting_tests.yml` | PHP style, `pint.json` (Laravel preset) | `vendor/bin/pint --test` |
| PHPStan (Larastan) | `static_analysis.yml` | Static analysis at level 5 against `phpstan-baseline.neon`: fails on NEW errors only | `composer phpstan` |
| Pest Core (SQLite) | `pest_tests.yml` | Core Unit and most DAM test files on SQLite, plus the DAM test-file gate (`.github/scripts/check-dam-test-gate.php`) | `vendor/bin/pest <path>` for the file you changed; do not run the whole suite |
| Node & Browser Tests | `js_tests.yml` | DAM JavaScript, SphereSeam, browser DOM tests, Studio runtime Python tests | see `js_tests.yml` for the runtime paths the browser suites need |
| Committed Frontend Bundles | `js_tests.yml` | Rebuilds the admin and DAM bundles and fails if the committed ones differ; also runs the Blade-lint self-test (below) | `tools/frontend/build-bundles.sh && node tools/frontend/check-bundles.cjs` |
| Translation Integrity Audit | `translation_tests.yml` | Locale files stay in sync | `php artisan mothership:translations:check` |
| Python Tool Tests | `tools_tests.yml` | Docs, media and RSS tool unit tests, manual and API-doc checks | the commands in `tools_tests.yml` |

Docker Images (`docker_images.yml`) also runs, but only when a pull request touches `dockerfiles/**`, `composer.json`, `composer.lock`, `.dockerignore` or its own files; when it runs it must pass.

## Advisory (never blocks a merge)

| Check | Where | What it checks | Run locally |
| --- | --- | --- | --- |
| Blade Script Lint (advisory) | `js_tests.yml`, job `blade_lint`, runs on every pull request | JavaScript embedded in Blade views (see below) | `node tools/frontend/lint-blade-scripts.cjs` (or `npm run lint:blade`) |
| Rector (dry-run, advisory) | `static_analysis.yml`, manual dispatch only | Refactors Rector would apply | `composer rector-dry` |

## Not on a pull request (nightly 06:00 UTC, or on demand)

These run from `pest_tests.yml` and `playwright_test.yml` on a nightly schedule with a daytime guard, or by hand with `gh workflow run <file> --ref <branch>`. A PostgreSQL-only regression is therefore caught the next morning, not on its pull request: deploy Studio from a nightly-green `main` commit.

- **Pest on PostgreSQL 16** (production database), MySQL, MariaDB and SQL Server legs, plus PostgreSQL + Elasticsearch. PostgreSQL is required in the nightly summary; MySQL, MariaDB and SQL Server are advisory.
- **Playwright** acceptance and E2E shards.
- **Member descriptions (PostgreSQL)**, `member_description_tests.yml`: manual dispatch only.

## PHPStan: level 5 plus a baseline ratchet

`phpstan.neon` analyses `app/` and `packages/Mothership/` at level 5 as PHP 8.4 (`phpVersion: 80400`, so a local PHP 8.5 and the 8.4 runner agree). `phpstan-baseline.neon` records the 909 errors that existed when level 5 was adopted (2026-10-16, 758 entries). CI fails on:

1. any error that is not in the baseline (new code must be clean), and
2. any baseline entry that no longer occurs, so fixing something forces the baseline to shrink in the same change.

One class is ignored by a pattern instead of baselined: `Access to an undefined property` (Eloquent magic attributes, about 1,800 occurrences across about 460 files, which would make the baseline a roughly 15,000-line file). It hides real typos on model properties, so it is the biggest remaining target.

### How to shrink the baseline

1. Pick a file or error class: `grep -n 'path: packages/Mothership/DAM' phpstan-baseline.neon`, or group by `identifier:` to see the dominant classes (Collection generic mismatches, `?->` left of `??`, non-exhaustive `match`, always-true conditions).
2. Fix the code. Do not add casts or widen types only to silence the error.
3. Regenerate: `composer phpstan-baseline`. This rewrites `phpstan-baseline.neon`; review the diff and confirm it only lost entries.
4. Commit the code fix and the smaller baseline together. A diff that adds baseline entries is a regression: fix the new error instead of baselining it.
5. To retire the property ignore: add `@property` docblocks (or `@property-read`) to a model, delete the matching occurrences, and when a package is clean remove the pattern from `phpstan.neon` and run `composer phpstan-baseline` once.

Never raise the level in the same change as other work. To try the next level without committing: `vendor/bin/phpstan analyse -l 6 --no-progress --error-format=raw | wc -l` (level 6 reported about 5,000 errors on 2026-10-16, so it is not baselined yet).

## Blade script lint (advisory)

`tools/frontend/lint-blade-scripts.cjs` has no dependencies (Node `vm` only). It neutralises Blade tokens (`{{ }}`, `{!! !!}`, `@json`, `@lang`, `@php`, `@if` and the other directives, keeping line numbers), then reports:

- `blade-directive-attr`: a Vue event shorthand that is also a Blade directive name, such as `@error="..."`. Blade compiles it as its own `@error` directive, so Vue never sees the attribute (commit `1096e385`; fix: `v-on:error`). Escape with `@@` for a literal `@`.
- `script-syntax`: a `<script>` body that does not parse, as a classic script or, for `type="module"`, as a module body (imports and exports allowed, top-level `await` allowed). `text/x-template` scripts are skipped as scripts, but their attributes are checked.
- `attr-syntax`: a Vue attribute expression (`:x`, `v-bind:x`, `v-if`, `v-else-if`, `v-show`, `v-model`, `@evt`, `v-on:evt`) that does not parse. Props on Blade `<x-...>` components are PHP and are not read.

Run it on the whole DAM views directory (default) or on any files: `node tools/frontend/lint-blade-scripts.cjs [--json] [path ...]`. Exit 1 means findings; the tree was clean (173 files) when it was added. It is advisory because it is a syntax and collision check, not a full lint, and a false positive must never block a merge. To make it required later, drop `continue-on-error` from the `blade_lint` job in `js_tests.yml` and add the job name to branch protection.

Its self-test, `node --test tools/frontend/tests/lint-blade-scripts.test.cjs`, is required: it runs in the Committed Frontend Bundles job and proves the lint still flags the `1096e385` bug (fixtures in `tools/frontend/tests/fixtures/blade-lint/`). Set `BLADE_LINT_HISTORY=1` to also lint the pre-fix `files/index.blade.php` straight from git history.

## PHPUnit configurations

- `phpunit.xml`: the default for Pest and CI; named suites, SQLite.
- `phpunit.mothership.xml`: the operator's local guarded PostgreSQL lane (`vendor/bin/pest -c phpunit.mothership.xml`). It runs the complete suite against a disposable private PostgreSQL on `127.0.0.1:5433` and sets `MOTHERSHIP_REQUIRE_TARGET_GUARD=1`, so the run is refused unless `tests/Support/TestTargetGuard.php` finds the allowlist file (outside the repository, named by `MOTHERSHIP_TEST_TARGET_ALLOWLIST`) matching the live server. CI does not use it: the nightly PostgreSQL legs prove their service container with `.github/scripts/ci-pg-proof.sh` instead. The PostgreSQL race tests in `packages/Mothership/DAM/tests/Feature/*RacePostgresTest.php` name it in their assertions, which is why it stays.
- `phpunit.pgsql.xml` was removed on 2026-10-16: no workflow, composer script, doc, tool or deploy script referenced it, it pointed at a database named `mothership` on port 5433 with no target guard, and its suite list had drifted from `phpunit.xml`.

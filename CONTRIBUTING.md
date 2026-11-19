# Contributing to The Mothership

**The Mothership** is the product name. In code, config keys and file names you will meet the slug `mothership` and the `MOTHERSHIP_` env prefix.

Production runs on the maintainer's Mac. This repository is the only source.

## Start here

1. [README.md](README.md) — the project overview and local setup. Day-to-day development uses host PHP (Homebrew PHP 8.5 + Composer) and SQLite. Docker Compose is an optional full-stack path, labelled in the README.
2. [CONTEXT.md](CONTEXT.md) — the domain vocabulary and the Mothership-versus-upstream package map. Use these terms in code, commits and pull requests.
3. [docs/README.md](docs/README.md) — the index of everything under `docs/`; [docs/CODING_STANDARDS.md](docs/CODING_STANDARDS.md) — the short list of enforceable code rules.
4. [docs/manual/developer/native-widgets.md](docs/manual/developer/native-widgets.md) — how to build a native Home widget.
5. [docs/manual/developer/iframe-tools.md](docs/manual/developer/iframe-tools.md) — how a hosted iframe tool is added to Home.
6. [docs/features/customizable-home.md](docs/features/customizable-home.md) — how Home works.
7. [docs/adr/](docs/adr/) — the architecture decisions already made. Read the relevant one before changing that area.

## Workflow

- Work on a branch and open a pull request. Never push to `main`.
- Only collaborators can open pull requests on this repository.
- One change per pull request.
- Name branches `feat/<topic>`, `fix/<topic>` or `docs/<topic>`.
- In the pull request, say what you ran. Include a screenshot for any visible change.

## Checks

CI truth lives in `.github/workflows/*.yml`. If this section and a workflow disagree, the workflow wins; fix this section in the same pull request.

GitHub Actions runs on the maintainer's self-hosted runners (`[self-hosted, linux, x64, windows-gpu]`, from `vars.CI_RUNS_ON`). **Pushes never trigger a workflow**: a push to a branch with no open pull request runs nothing, and a merge to `main` re-runs nothing. Pull requests run the fast checks. The slow and optional suites run nightly and on demand (operator directive 2026-10-12).

### On every pull request

Branch protection on `main` requires these seven checks (the list is the branch's `required_status_checks`) to pass before a merge.

| Check on the pull request | Workflow | What it runs |
| --- | --- | --- |
| Laravel Pint Code Style | `linting_tests.yml` | `vendor/bin/pint --test` |
| PHPStan (Larastan) | `static_analysis.yml` | `vendor/bin/phpstan analyse` |
| Pest Core (SQLite) | `pest_tests.yml` | The Core Unit suite, the root Feature suite and the DAM test files, on **SQLite**, plus the DAM test-file gate (`.github/scripts/check-dam-test-gate.php`) |
| Node & Browser Tests | `js_tests.yml` | DAM JavaScript, SphereSeam and browser DOM tests, plus the Studio runtime's Python unit tests |
| Committed Frontend Bundles | `js_tests.yml` | `tools/frontend/build-bundles.sh`, then `node tools/frontend/check-bundles.cjs`, which fails when the committed admin or DAM bundle differs from the rebuild |
| Translation Integrity Audit | `translation_tests.yml` | `php artisan mothership:translations:check` |
| Python Tool Tests | `tools_tests.yml` | The Python unit tests for the docs, media, CI and RSS tools, `tools/docs/check_manual.py`, and `tools/docs/build_api.py --check` |

Also on a pull request, but not a required check:

- **Docker Images** (`docker_images.yml`) builds and checks the web, fpm and queue images (including the patched PHP runtime and extensions) and runs `nginx -t` on the nginx image's config. It runs only when the pull request changes `dockerfiles/**`, `composer.json`, `composer.lock`, `.dockerignore`, or the workflow and its check script. When it runs, it must pass.

PHP runtime bases are pinned by version, Debian release and multi-platform digest. For a PHP or Debian security update, refresh all three pins together from the official `library/php` registry, verify both arm64 and amd64 manifests, and run Docker Images CI plus the exact-commit release gate before deployment. `--pull` cannot update a pinned base; it still refreshes the floating Composer build stage. Check the actual FPM and queue binaries after deployment, not only the controller's PHP CLI.

**The DAM test-file gate.** Every `packages/Mothership/DAM/tests/**/*Test.php` file must be named as an argument of a `vendor/bin/pest` line in the `pest` job of `pest_tests.yml`. The gate runs inside **Pest Core (SQLite)** on every pull request and fails when a new DAM test file is not listed. Add your file to the matching step when you add a test.

### Nightly and on demand, never on a pull request

The PostgreSQL leg is **not** a pull request check. Production is PostgreSQL, so a PostgreSQL-only regression is caught the next morning, not on its pull request.

| Check | Workflow | Notes |
| --- | --- | --- |
| Pest Tests (PostgreSQL) | `pest_tests.yml` (`pest` job) | PostgreSQL 16. Nightly (dispatched from the maintainer's Mac at 00:05 America/Chicago) and on dispatch. The leg that proves the PostgreSQL contract. |
| Pest Tests (PostgreSQL + Elasticsearch) | `pest_tests.yml` | Nightly and on dispatch. |
| Pest Tests (MySQL, advisory) | `pest_tests.yml` | Advisory compatibility leg. A red leg is a finding to triage, not a blocker. |
| Installer (MariaDB, advisory), Installer (SQL Server, advisory) | `pest_tests.yml` | A fresh install on those drivers, with no test suite on top. Advisory. |
| Playwright E2E | `playwright_test.yml` | Nightly against `main`, and on dispatch. Never a merge gate. |
| Member descriptions (PostgreSQL) | `member_description_tests.yml` | `workflow_dispatch` only: no schedule, no pull request trigger, not a required check. Starts a disposable PostgreSQL service, runs the installer, then `MemberDescriptionsTest.php` and `MemberDescriptionsLockCommittedTest.php` with `--fail-on-skipped`, and asserts the lock cases actually executed (30-minute timeout). Use it to prove a merged commit's AI member-description locking in minutes without the ~64-minute nightly: `gh workflow run member_description_tests.yml --ref main`, then `ci-verify --run <id>`. The workflow file must be on the default branch before it can be dispatched. |
| Rector (dry-run, advisory) | `static_analysis.yml` | Manual only; a pull request lists it as skipped. |

How the nightly behaves:

- **Trigger.** A scheduled job on the maintainer's Mac dispatches `pest_tests.yml` with `nightly=true` at 00:05 America/Chicago (since 2026-10-15). GitHub's own `schedule` run (cron `23 6 * * *`) is created hours late (11:17-13:21 UTC on every night 2026-10-23 to 2026-10-29, against a 06:23 UTC cron), so its gate defers it as outside the window and its summary is red by design. Read the dispatched "Nightly: Pest Tests" run, not the `schedule` one.
- **Window.** A nightly run is deferred when GitHub creates it outside 00:00-05:55 America/Chicago, or when it duplicates tonight's run on the same commit. The "Nightly summary" job reports each leg as executed/passed, executed/failed, deferred, cancelled or not-run. A deferred night has no nightly coverage; that is reported, not passed.
- **On demand.** `gh workflow run pest_tests.yml --ref <branch>` runs all the legs above and the SQLite leg, with no window. Add `-f nightly=true` for the window. A dispatch shares the branch's concurrency group, so it cancels a Pest run still in progress on that branch's pull request. Start it after that run finishes.
- Every other workflow also accepts `workflow_dispatch`, for example `gh workflow run js_tests.yml --ref <branch>` to check a branch or `main` on demand.
- **Deploy rule.** Deploy Studio from a `main` commit whose dispatched nightly passed, or after an on-demand dispatch of `pest_tests.yml` on that commit (`ci-verify --run <id>`). A merged pull request is not proven on PostgreSQL until then.

The pool has two runners, so a pull request can wait in the queue. All seven required checks run on every pull request, including one that touches only `tools/` or Python: there is no path filter, so such a pull request still waits for a busy pool. Run these on the host, from the repository root, before you push to catch the common failures first:

```bash
vendor/bin/pint --test
```

```bash
composer phpstan
```

```bash
vendor/bin/pest tests/Feature/DocumentationRoutesTest.php
```

Pint and PHPStan run the same analysis as their CI jobs. For Pest, run the files your change touches rather than `composer test`: `composer test` runs every suite in `phpunit.xml`, which is not the same selection as the CI Pest jobs. To reproduce one CI step, run its `vendor/bin/pest` line from `pest_tests.yml`. In a fresh worktree, `tools/testing/local-pest.sh <pest arguments>` does the whole setup and then runs Pest (see `AGENTS.md`, "Run and test", for what it does and the failures it prevents). By hand, Pest needs `composer install --ignore-platform-reqs` (the lock pins PHP 8.4) and a `.env` with `DB_CONNECTION=sqlite` pointing at a migrated and seeded SQLite file. The "Setting Environment" and "Building the schema" steps of the `pest-sqlite` job in `pest_tests.yml` are the exact recipe (`cp .env.example .env`, `php artisan key:generate`, then `php artisan migrate` and `php artisan db:seed`, plus the DAM `DirectoryTableSeeder`).

CI also enforces three things the commands above do not run. Run them when your change touches them: `php .github/scripts/check-dam-test-gate.php` (a new DAM test file must be named in the `pest` job), `node --test $(find packages/Mothership/DAM/tests/js -type f -name '*.test.*')` after any change to bundled JavaScript, and `tools/frontend/build-bundles.sh` after Blade or bundled JavaScript changes.

The documented local run uses SQLite. A change that touches raw SQL, JSON columns, advisory locks or anything else that differs on PostgreSQL is proven by the nightly PostgreSQL leg, or on demand with `gh workflow run pest_tests.yml --ref <branch>`.

`tests/Feature/DocumentationRoutesTest.php` fails when `docs/api/routes.json` no longer matches the registered routes. After you add, rename or remove a route, refresh it and commit the result:

```bash
ROUTE_INVENTORY_WRITE=1 vendor/bin/pest tests/Feature/DocumentationRoutesTest.php
```

A new route or table is tracked in more places than that one, and CI fails on each one you miss. After `docs/api/routes.json`, for a new DAM route classify it by hand in `packages/Mothership/DAM/tests/Fixtures/route-inventory.php`, then regenerate the API inventory in this order:

```bash
python3 tools/docs/classify_routes.py
python3 tools/docs/build_api.py
```

The first writes `docs/api/classifications.json`; the second writes `docs/api/openapi.json` and `docs/api/coverage.json`. The second stops with `source review is stale` while `docs/api/source-review.json` predates the PHP sources. Refreshing that file is a maintainer's sign-off that the contracts were reviewed again, so do not refresh it to get past the error. CI's `build_api.py --check` builds without it, and fails only when the two committed files differ from that build. A new DAM table goes in `Mothership\DAM\Support\DamTables::ALL`.

If you changed a Blade template, a stylesheet or bundled JavaScript, rebuild the committed bundles and commit whatever the script changes. Nothing rebuilds them on deploy, so a stale bundle ships as it is. Run this on the host, from the repository root, with Node 22:

```bash
tools/frontend/build-bundles.sh
```

To reproduce the CI job, run the script on a clean checkout, then run `node tools/frontend/check-bundles.cjs`. It fails if the rebuild changed any committed file, and names the CSS classes the committed bundle lacks.

## Rules

- No secrets in the repository, and never commit a `.env` file.
- No real assets in fixtures. Use made-up files and names.
- Do not edit [LICENSE](LICENSE) or upstream license headers.
- Merged is not deployed. The maintainer deploys to production, following [docs/ops/studio-runtime.md](docs/ops/studio-runtime.md).

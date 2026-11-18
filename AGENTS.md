# AGENTS.md — entrypoint for coding agents

The Mothership is a Laravel DAM with an owned codebase (ADR 0001). Production is
the maintainer's Mac. This file says where things are and what is true; the linked files hold the detail.

## Where things live

- `CONTEXT.md` — glossary (Asset, FileObject, ContentStore, stack, share token, HEIC preview, ...) and the
  Mothership-versus-upstream package map. Use its words in code, commits and PRs.
- `docs/README.md` — index of `docs/`. `docs/CODING_STANDARDS.md` — the enforceable code rules.
  `docs/adr/` — decisions; read the relevant one before changing that area.
- `CONTRIBUTING.md` — workflow, the CI table and the commands below. `README.md` — local setup.
- `packages/Mothership/DAM/` — the product code (see its README). `packages/Mothership/AiAgent/AGENTS.md` documents the
  upstream product-agent classes; it is not a guide to working in this repo.
- The maintainer keeps the project record (task list and log) outside this repository. This repository holds the code
  and design records only.

## Run and test (host PHP, SQLite): local Pest

- No Docker for day-to-day work. In a fresh worktree one command sets up and runs local Pest:
  `tools/testing/local-pest.sh <pest arguments>` (for example `tools/testing/local-pest.sh packages/Mothership/DAM/tests/Feature/CreativeHub/HubResourcePageTest.php`).
  With no arguments it only sets up. It runs `composer install --ignore-platform-reqs`, writes `.env` (`DB_CONNECTION=sqlite`, an
  absolute `DB_DATABASE`), generates the key and migrates and seeds `database/database.sqlite`, each only when missing; it runs
  `php artisan migrate` every time. The recipe is the `pest-sqlite` job in `.github/workflows/pest_tests.yml`.
- Run the Pest files you touched, from the repository root: `vendor/bin/pest <file>` (add `--filter "<name>"` for one case).
  Pest needs explicit file paths (zsh `$T` does not word-split). Do not run the full suite or `composer test`.
- Redis is absent locally. A test that attaches a video queues its preview proxy on Redis and fails with "Connection refused
  [tcp://127.0.0.1:6379]" unless it calls `Queue::fake()`.
- A handful of failures such as `Unknown column 'evidence'` means a stale SQLite file, not a code fault: re-run `tools/testing/local-pest.sh`
  (it migrates) or `tools/testing/local-pest.sh --fresh` to rebuild. Reproduced 2026-10-29: the three `CraftMcpIntegrationTest` failures
  reported after a merge were exactly this, and the file passes 26 of 26 on a migrated database.
- Never symlink `vendor` from another worktree. Under a path with a digit-led segment such as `/Volumes/4TB/...` Pest then
  stops with "[4TB] may not be used as a namespace name" (reproduced 2026-10-29); a real `vendor` in the worktree runs fine there.
  `local-pest.sh` refuses a symlinked `vendor`.
- The host runs PHP 8.5 while the lock and CI pin 8.4; the only 8.5 deprecation the code raised (`PDO::MYSQL_ATTR_SSL_CA`) is fixed, so
  a pass line reads "N passed" with no "deprecated" count. A new "deprecated" count is a real finding.
- Browser tests (`tools/browser/tests`) need `MOTHERSHIP_PLAYWRIGHT_MODULE_PATH` (the `tests/e2e-pw/node_modules/playwright` of any
  checkout where `npm ci` ran there); unset, they stop with that message.
- Before pushing, run what CI enforces for what you changed:
  - always: `vendor/bin/pint --test` and `composer phpstan`
  - any new or renamed DAM test file: `php .github/scripts/check-dam-test-gate.php` (it needs the file named in a `vendor/bin/pest` line of the `pest` job in `pest_tests.yml`)
  - JavaScript: `node --test $(find packages/Mothership/DAM/tests/js -type f -name '*.test.*')`, and for Blade fixtures also the matching `tools/browser/tests/*.test.cjs`
  - Blade, CSS or bundled JS: `tools/frontend/build-bundles.sh`, then commit the result
  - a stale Blade fixture (`tests/Feature/BrowserBladeFixturesTest.php`): regenerate with `BLADE_FIXTURES_WRITE=1 vendor/bin/pest tests/Feature/BrowserBladeFixturesTest.php` and commit it: browser tests load those rendered views, never Blade source
  - routes changed: refresh `docs/api/routes.json` (command in CONTRIBUTING.md)

## CI truth

- Pushes never trigger workflows. Pull requests run only fast checks: Pint, PHPStan, **Pest Core (SQLite)**, Node &
  Browser, Committed Frontend Bundles, Python Tool Tests, Translation Integrity (the seven required checks). They are
  required on every pull request, including one that touches only `tools/` or Python: there is no path filter.
- **PostgreSQL (production's database), MySQL, MariaDB, SQL Server, Elasticsearch and Playwright run nightly and on
  `workflow_dispatch`, never on a PR.** A PostgreSQL-only regression surfaces the next morning.
- **The nightly is started from the operator's Mac, not by GitHub's cron.** A scheduled job there dispatches
  `pest_tests.yml` with `nightly=true` at 00:05 America/Chicago (since 2026-10-15). GitHub's own `schedule` run is created hours
  late (06:23 UTC cron, created 11:17-13:21 UTC on every night 2026-10-23 to 2026-10-29), so its gate defers it as
  "outside-window" and its summary is red by design. Read the dispatched run ("Nightly: Pest Tests", event `workflow_dispatch`),
  not the `schedule` one.
- Deploy production from a `main` commit whose dispatched nightly passed, or after an on-demand run on that commit:
  `gh workflow run pest_tests.yml --ref <branch>` (a dispatch runs every leg and the SQLite leg with no window; it cancels a
  Pest run in progress on the same branch). Read the result with `ci-verify --run <id>`.
- Do not report CI green from a local run, and read results with `ci-verify --pr <N>` (exit 0 pass, 2 pending, 3 did not run).
  Use the operator's self-hosted runners only. Workflows are the authority over this file.

## Working rules

- Branch from `main`, one change per PR, never push to `main`. Keep worktrees under one root of
  your choice; the Claude and Codex desktop apps may create theirs under a sibling root, which is the same repository.
  One writer per checkout.
- Bytes go through `ContentStore`; access goes through `AssetAccessDecision` (a hidden asset answers 404 like a missing
  one); Blade uses `v-on:` not `@` for Vue events; new strings go in all four English locales; dated TODOs
  (`TODO YYYY-MM-DD:`). Details and the rest: `docs/CODING_STANDARDS.md`.
- No secrets in the repo and no real assets in fixtures. Merged is not deployed: the maintainer deploys.
- Prefer `codegraph` or `rg` over plain `grep`/`find` for searches: older agent worktrees may be nested in a checkout.

# The Mothership

The Mothership is an open-source Digital Asset Management (DAM) system featuring a typed compound-asset graph (file roles, versions, renditions, and directed asset relationships).

Contributing? Start with [CONTRIBUTING.md](CONTRIBUTING.md). Working as an agent? Start with [AGENTS.md](AGENTS.md).

## Design Records

- **License**: MIT License (see [LICENSE](LICENSE)).
- **Architecture & Design Records**:
  - [CONTEXT.md](CONTEXT.md) — Domain context, glossary, and the package map.
  - [docs/README.md](docs/README.md) — The index of everything under `docs/`.
  - [docs/adr/](docs/adr/) — Architecture Decision Records. Start with [ADR 0001](docs/adr/0001-owned-codebase.md), the owned-codebase decision.
- **Manuals & operations**:
  - [docs/manual/](docs/manual/) — User, administrator and developer guides (the in-app Help center source).
  - [docs/ops/studio-runtime.md](docs/ops/studio-runtime.md) — The production runtime on the maintainer's Mac: prerequisites, lifecycle, state and verification.
  - [docs/api/](docs/api/) — Generated route and contract inventory.

---

## Development Setup

There are two ways to run the code. **Use host PHP** unless you need the full service stack.

| Path | Use it for | Status |
| --- | --- | --- |
| Host PHP + SQLite | Day-to-day work and running tests. | **Primary.** What the maintainers and CI jobs use. |
| Docker Compose (`compose.dev.yaml`) | A full local stack: Nginx + FPM, PostgreSQL, Redis, Elasticsearch, queue worker, scheduler. | **Optional.** Supported but not exercised by CI (CI only builds the images that share its Dockerfiles). The maintainer's Mac does not run Docker. |

Production is neither: the maintainer's Mac runs the stack on Apple `container` through `tools/studio/runtime.py` ([docs/ops/studio-runtime.md](docs/ops/studio-runtime.md)).

### Host PHP (primary)

Requirements: PHP 8.4 or newer (the maintainer's Mac runs Homebrew PHP 8.5), Composer 2, Node 22 for the frontend tools.

1. Install dependencies. The lock pins PHP 8.4, so a newer PHP needs the platform flag:
   ```bash
   composer install --ignore-platform-reqs
   ```
2. Create the environment and a migrated, seeded SQLite database. The `pest-sqlite` job in [.github/workflows/pest_tests.yml](.github/workflows/pest_tests.yml) ("Setting Environment" and "Building the schema") is the exact recipe: `cp .env.example .env`, set `DB_CONNECTION=sqlite` and `DB_DATABASE` to a SQLite file, `php artisan key:generate`, `php artisan migrate`, `php artisan db:seed`, then seed `Mothership\DAM\Database\Seeders\DirectoryTableSeeder`.
3. Run the tests for the files you changed (never the full suite, see below):
   ```bash
   vendor/bin/pest packages/Mothership/DAM/tests/Feature/AssetTest.php
   ```
   A single case: `vendor/bin/pest packages/Mothership/DAM/tests/Feature/AssetTest.php --filter "<test name>"`.
4. Run the style and static checks CI runs on every pull request:
   ```bash
   vendor/bin/pint --test
   composer phpstan
   ```

What CI runs on a pull request (SQLite only) and what runs nightly (PostgreSQL and the rest) is in [CONTRIBUTING.md](CONTRIBUTING.md#checks). Do not run `composer test` for a normal change: it runs every suite in `phpunit.xml`, which is not the CI selection.

### Docker Compose (optional full stack)

The compose stack bind-mounts the checkout and installs Composer dependencies into `./vendor` on first boot.

1. Configure the environment. Set the admin credentials in `.env` before the first start (optional; the default generates a random password stored in `storage/app/admin-credentials.txt`):
   ```bash
   cp .env.docker .env
   ```
   ```env
   INSTALLER_ADMIN_EMAIL=admin@example.com
   INSTALLER_ADMIN_PASSWORD=your-password-here
   ```
2. Start the stack:
   ```bash
   docker compose -f compose.dev.yaml up -d
   ```
   It starts Nginx + PHP 8.4 FPM at `http://localhost:8000` (Admin at `/admin`), PostgreSQL 16, Redis and Elasticsearch 8, plus the queue worker and scheduler. First boot installs Composer dependencies and runs the migrations.
3. View logs:
   ```bash
   docker compose -f compose.dev.yaml logs -f
   ```
4. If a local port conflicts (PostgreSQL, Redis, Elasticsearch), adjust the `FORWARD_*` port variables in `.env`.

An Apache variant is `compose.dev.apache.yaml`; the header of `compose.dev.yaml` lists every service and profile.

---

## Security

If you discover any security vulnerabilities, please report them privately to the repository maintainers, as described in [SECURITY.md](SECURITY.md).

## License

Mothership is open-source software distributed under the [MIT License](LICENSE).

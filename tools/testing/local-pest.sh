#!/usr/bin/env bash
# One command from a fresh worktree to a local Pest run (host PHP, SQLite, no Docker, no Redis).
#
#   tools/testing/local-pest.sh                      set up only
#   tools/testing/local-pest.sh <pest arguments>     set up if needed, then run vendor/bin/pest with them
#   tools/testing/local-pest.sh --fresh [<args>]     rebuild the SQLite file first
#
# Set up means, each step skipped when it is already done:
#   1. composer install --ignore-platform-reqs  (the lock pins PHP 8.4; the host may run 8.5)
#   2. .env from .env.example, with APP_ENV=testing, DB_CONNECTION=sqlite and an absolute DB_DATABASE
#   3. APP_KEY
#   4. database/database.sqlite, migrated every run and seeded when it has no roles, as the "Building the
#      schema" step of the `pest-sqlite` job in .github/workflows/pest_tests.yml does
#
# Pest needs explicit file paths (never `$T` in zsh, which does not word-split); do not run the full suite.
# An existing .env is never rewritten: if it does not say DB_CONNECTION=sqlite, the script stops and says so.
#
# Log: set LOCAL_PEST_LOG=/path/file.jsonl for one JSONL line per step (ts, run_id, level, event, ctx).
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
cd "$root"

run_id="$$-$(date +%s)"
log() {
    [ -n "${LOCAL_PEST_LOG:-}" ] || return 0
    printf '{"ts":"%s","run_id":"%s","level":"info","loc":"local-pest.sh","event":"%s","ctx":{"detail":"%s"}}\n' \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$run_id" "$1" "${2:-}" >> "$LOCAL_PEST_LOG"
}
say() { printf 'local-pest: %s\n' "$*"; }
die() { printf 'local-pest: %s\n' "$*" >&2; log fail "$*"; exit 1; }

fresh=0
if [ "${1:-}" = "--fresh" ]; then
    fresh=1
    shift
fi

# 1. Dependencies. A `vendor` symlinked from another worktree makes Pest build its test namespace from the
#    absolute path, which fails on a path with a digit-led segment such as /Volumes/4TB/ ("[4TB] may not be
#    used as a namespace name"). Reproduced 2026-10-29; a real in-tree vendor runs fine at the same path.
if [ -L vendor ]; then
    die "vendor is a symlink (to $(readlink vendor)). Pest cannot name tests under a path with a digit-led segment that way; remove the link and re-run to install a real vendor."
fi
if [ ! -f vendor/autoload.php ]; then
    say "composer install --ignore-platform-reqs"
    gtimeout --preserve-status 600s composer install --ignore-platform-reqs --no-interaction --no-progress
    log composer-installed
fi

# 2. Environment.
db="$root/database/database.sqlite"
if [ ! -f .env ]; then
    cp .env.example .env
    sed -i.bak -e 's|^APP_ENV=.*|APP_ENV=testing|' -e 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|' -e "s|^DB_DATABASE=.*|DB_DATABASE=$db|" .env
    rm -f .env.bak
    say ".env created (sqlite at $db)"
    log env-created
elif ! grep -q '^DB_CONNECTION=sqlite' .env; then
    die ".env exists but is not DB_CONNECTION=sqlite; fix it by hand (set DB_CONNECTION=sqlite and an absolute DB_DATABASE) or move it aside."
fi

# 3. Key.
if ! grep -q '^APP_KEY=.\+' .env; then
    php artisan key:generate --force --no-interaction >/dev/null
    log key-generated
fi

# 4. Schema. Resolve DB_DATABASE the way Laravel does (dotenv unquotes and drops inline comments), then build
#    what is missing: migrations every run, the default seed only for a database with no roles, the DAM root
#    directory whenever it is absent. Roles without admins is an interrupted seed: rebuild with --fresh.
database=$(php -r 'require "vendor/autoload.php"; Dotenv\Dotenv::createImmutable(getcwd())->load(); echo $_ENV["DB_DATABASE"] ?? "";')
[ -n "$database" ] || die ".env has no DB_DATABASE"
case "$database" in /*) ;; *) database="$root/$database" ;; esac
if [ "$fresh" = 1 ]; then
    rm -f "$database"
fi
mkdir -p "$(dirname "$database")"
touch "$database"
php artisan migrate --force --no-interaction >/dev/null
log schema-migrated "$database"
count() {
    php -r '$pdo = new PDO("sqlite:".$argv[1]); echo (int) $pdo->query("select count(*) from ".$argv[2])->fetchColumn();' "$database" "$1"
}
if [ "$(count roles)" = 0 ]; then
    say "seeding $database"
    php artisan db:seed --force --no-interaction >/dev/null
    log schema-seeded "$database"
elif [ "$(count admins)" = 0 ]; then
    die "$database has roles but no admins (an interrupted seed). Re-run with --fresh."
fi
if [ "$(count dam_directories)" = 0 ]; then
    php artisan db:seed --force --no-interaction --class='Mothership\DAM\Database\Seeders\DirectoryTableSeeder' >/dev/null
    log directory-seeded "$database"
fi

if [ "$#" -eq 0 ]; then
    say "ready. Run: vendor/bin/pest <file> [--filter \"<name>\"]"
    exit 0
fi

log pest "$*"
exec vendor/bin/pest "$@"

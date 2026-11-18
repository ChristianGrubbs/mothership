#!/bin/sh
# Checks the system layer of the web, fpm and queue images. It runs inside the image, read from stdin, so the
# host needs nothing but a container runtime:
#
#   docker run --rm -i --entrypoint sh -e PG_MAJOR=16 IMAGE -s tools < .github/scripts/check-docker-image.sh
#   docker run --rm -i --entrypoint sh IMAGE -s runtime < .github/scripts/check-docker-image.sh
# REQUIRE_FPM=1 requires the FPM binary; REQUIRE_APACHE=1 requires a loaded php_module.
#
# Modes:
#   runtime     patched PHP branch, required extensions and image codecs (also queue)
#   tools       pg_dump and psql on PG_MAJOR, libpq from Debian, PHP's PostgreSQL
#               extensions, dcraw_emu, and all runtime checks
#   round-trip  dump and restore two tables through a live server with DamUpdater's
#               own flags; needs PGHOST, PGUSER, PGPASSWORD and PGDATABASE
#   all         both (the default, and what .github/workflows/docker_images.yml runs)
#
# PG_MAJOR is the server's major. The client has to equal it, not merely be newer:
# pg_dump 17 writes `SET transaction_timeout`, which a 16 server rejects under
# psql -v ON_ERROR_STOP=1, and pg_dump refuses to dump a newer server than itself.
set -eu

mode=${1:-all}
if [ "$mode" != runtime ]; then
    : "${PG_MAJOR:?set PG_MAJOR to the PostgreSQL server major, e.g. 16}"
fi

failures=0
ok() { echo "ok: $*"; }
fail() {
    echo "FAIL: $*" >&2
    failures=$((failures + 1))
}

# One value from the server, unaligned and without headers or command tags.
query() { psql -X -A -t -q -c "$1"; }

check_php_runtime() {
    if php <<'PHP'
<?php
// Same supported branch, with both PHP2Shell fixes; a floating/cached older base must fail.
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4 || version_compare(PHP_VERSION, '8.4.27', '<')) {
    fwrite(STDERR, 'Unpatched or unexpected PHP runtime: '.PHP_VERSION.PHP_EOL);
    exit(1);
}
foreach (['bcmath', 'calendar', 'exif', 'gd', 'gmp', 'intl', 'pcntl', 'pdo_mysql', 'pdo_pgsql', 'pgsql', 'zip', 'redis'] as $extension) {
    if (! extension_loaded($extension)) {
        fwrite(STDERR, 'Missing PHP extension: '.$extension.PHP_EOL);
        exit(1);
    }
}
// Exercise the thumbnail codec rather than merely listing GD.
$image = imagecreatetruecolor(2, 2);
ob_start();
imagejpeg($image);
$jpeg = ob_get_clean();
if (imagecreatefromstring($jpeg) === false || json_decode(json_encode(['version' => PHP_VERSION]), true)['version'] !== PHP_VERSION) {
    fwrite(STDERR, 'JPEG or JSON round trip failed'.PHP_EOL);
    exit(1);
}
echo 'Patched PHP '.PHP_VERSION.'; required extensions and JPEG round trip passed'.PHP_EOL;
PHP
    then
        ok "PHP runtime and compatibility smoke"
    else
        fail "PHP runtime or compatibility smoke"
    fi
    if command -v php-fpm >/dev/null 2>&1; then
        cli_version=$(php -r 'echo PHP_VERSION;')
        if banner=$(php-fpm -v) && printf '%s\n' "$banner" | head -n 1 | grep -F "PHP $cli_version (fpm-fcgi)"; then
            ok "FPM binary matches the checked PHP runtime"
        else
            fail "FPM binary does not match the checked PHP runtime"
        fi
    elif [ "${REQUIRE_FPM:-0}" = 1 ]; then
        fail "required FPM binary is missing"
    fi
    if [ "${REQUIRE_APACHE:-0}" = 1 ]; then
        if modules=$(apache2ctl -M 2>&1) && printf '%s\n' "$modules" | grep -F 'php_module'; then
            ok "Apache loads php_module"
        else
            fail "Apache PHP module is missing or cannot load"
        fi
    fi
}

check_tools() {
    check_php_runtime
    for tool in pg_dump psql; do
        if ! command -v "$tool" >/dev/null 2>&1; then
            fail "$tool is not installed"
            continue
        fi
        if ! banner=$("$tool" --version 2>&1); then
            fail "$tool --version failed: $banner"
            continue
        fi
        got=$(echo "$banner" | awk '{ print $3 }' | cut -d. -f1)
        if [ "$got" = "$PG_MAJOR" ]; then
            ok "$banner"
        else
            fail "$tool is major '$got', the server is $PG_MAJOR: $banner"
        fi
    done

    # pdo_pgsql and pgsql are built against libpq-dev, so libpq has to stay Debian's.
    # The PGDG repository the client comes from carries a newer libpq of its own.
    for pkg in libpq5 libpq-dev; do
        # shellcheck disable=SC2016 # a dpkg-query format, not a shell expansion
        version=$(dpkg-query -W -f='${db:Status-Abbrev}|${Version}\n' "$pkg" 2>/dev/null | sed -n 's/^ii *|//p')
        case "$version" in
            '') fail "$pkg is not installed" ;;
            *pgdg*) fail "$pkg $version comes from PGDG, not Debian" ;;
            *) ok "$pkg $version" ;;
        esac
    done

    # On Debian, pg_dump and psql are pg_wrapper links that run the newest installed
    # client, so a second client major would silently take over from this one.
    # shellcheck disable=SC2016 # a dpkg-query format, not a shell expansion
    clients=$(dpkg-query -W -f='${db:Status-Abbrev}|${Package}\n' 'postgresql-client-[0-9]*' 2>/dev/null \
        | sed -n 's/^ii *|//p' | sort | tr '\n' ' ')
    if [ "$clients" = "postgresql-client-$PG_MAJOR " ]; then
        ok "postgresql-client-$PG_MAJOR is the only client installed"
    else
        fail "installed PostgreSQL clients are '${clients% }', expected only postgresql-client-$PG_MAJOR"
    fi

    for ext in pdo_pgsql pgsql; do
        if php -r "exit(extension_loaded('$ext') ? 0 : 1);"; then
            ok "php loads $ext"
        else
            fail "php does not load $ext"
        fi
    done
    php --ri pgsql 2>/dev/null | grep -i 'libpq' || true

    # DNG fallback decoder (libraw-bin); both Dockerfiles install it.
    if [ -x /usr/bin/dcraw_emu ]; then
        ok "/usr/bin/dcraw_emu"
    else
        fail "/usr/bin/dcraw_emu is missing"
    fi
}

check_round_trip() {
    # All four, so libpq never falls back to a default database it would then drop tables in.
    : "${PGHOST:?round-trip needs PGHOST}" "${PGUSER:?round-trip needs PGUSER}" "${PGDATABASE:?round-trip needs PGDATABASE}"
    : "${PGPASSWORD?round-trip needs PGPASSWORD}"
    if ! command -v pg_dump >/dev/null 2>&1 || ! command -v psql >/dev/null 2>&1; then
        fail "round trip skipped: pg_dump or psql is not installed"
        return
    fi
    if ! server=$(query 'SHOW server_version_num'); then
        fail "cannot query PostgreSQL at $PGHOST"
        return
    fi
    if [ $((server / 10000)) = "$PG_MAJOR" ]; then
        ok "server $PGHOST is major $PG_MAJOR"
    else
        fail "server $PGHOST is major $((server / 10000)), PG_MAJOR says $PG_MAJOR"
    fi

    # A schema of its own, dropped on exit, so the fixture never touches existing tables
    # in whatever database it is pointed at. Every later connection works inside it.
    rt_schema="rt_check_$(date +%s)_$$"
    work=$(mktemp -d)
    trap 'query "DROP SCHEMA IF EXISTS $rt_schema CASCADE" >/dev/null 2>&1 || true; rm -rf "$work"' EXIT
    if ! query "CREATE SCHEMA $rt_schema" >/dev/null; then
        fail "could not create schema $rt_schema"
        return
    fi
    export PGOPTIONS="-c search_path=$rt_schema"

    if ! psql -X -q -v ON_ERROR_STOP=1 >/dev/null <<'SQL'
CREATE TABLE rt_parent (id bigserial PRIMARY KEY, name text NOT NULL, meta jsonb NOT NULL DEFAULT '{}');
CREATE TABLE rt_child (id bigserial PRIMARY KEY, parent_id bigint NOT NULL REFERENCES rt_parent (id), note text);
CREATE INDEX rt_child_parent_idx ON rt_child (parent_id);
INSERT INTO rt_parent (name, meta) SELECT 'p' || g, jsonb_build_object('n', g) FROM generate_series(1, 50) g;
INSERT INTO rt_child (parent_id, note) SELECT g % 50 + 1, 'c' || g FROM generate_series(1, 200) g;
SQL
    then
        fail "could not seed the round-trip tables"
        return
    fi

    # DamUpdater's backup and restore commands (packages/Mothership/DAM/src/Helpers/DamUpdater.php),
    # with the connection taken from the PG* environment instead of flags and the tables
    # schema-qualified. The restore goes back into the same database, over the live
    # tables, as dam:update:restore does.
    if ! pg_dump -c --if-exists -t "$rt_schema.rt_parent" -t "$rt_schema.rt_child" -f "$work/dump.sql"; then
        fail "pg_dump failed"
        return
    fi
    if ! psql -v ON_ERROR_STOP=1 --single-transaction -f "$work/dump.sql" >/dev/null; then
        fail "psql -v ON_ERROR_STOP=1 --single-transaction could not restore the dump"
        return
    fi

    rows=$(query "SELECT (SELECT count(*) FROM rt_parent) || ' ' || (SELECT count(*) FROM rt_child) || ' ' || (SELECT sum((meta->>'n')::int) FROM rt_parent)")
    if [ "$rows" = "50 200 1275" ]; then
        ok "restored 50 parents, 200 children and their jsonb"
    else
        fail "restored rows read '$rows', expected '50 200 1275'"
    fi
    # -c drops the foreign key before the tables, so check it and the index came back too.
    restored=$(query "SELECT (SELECT count(*) FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'rt_child_parent_idx') || ' ' || (SELECT count(*) FROM pg_constraint WHERE conrelid = 'rt_child'::regclass AND confrelid = 'rt_parent'::regclass AND contype = 'f')")
    if [ "$restored" = "1 1" ]; then
        ok "restored the index and the foreign key"
    else
        fail "restored index and foreign key counts read '$restored', expected '1 1'"
    fi
    # The owned sequence comes back at its dumped position, not at 1.
    next=$(query "INSERT INTO rt_parent (name) VALUES ('after') RETURNING id")
    if [ "$next" = 51 ]; then
        ok "restored sequence continues at 51"
    else
        fail "the next id after restore is '$next', expected 51"
    fi
}

case "$mode" in
    runtime) check_php_runtime ;;
    tools) check_tools ;;
    round-trip) check_round_trip ;;
    all)
        check_tools
        check_round_trip
        ;;
    *)
        echo "unknown mode '$mode' (runtime, tools, round-trip, all)" >&2
        exit 2
        ;;
esac

if [ "$failures" -gt 0 ]; then
    echo "$failures check(s) failed" >&2
    exit 1
fi
echo "all checks passed"

#!/bin/bash
# Migrates, seeds on first run and links storage. Only
# the application container runs this. Set MOTHERSHIP_SKIP_MIGRATIONS=true where the
# deployment owns the schema, since scaled replicas would otherwise race it.
#
# Signing keys and DAM publishing/seeding are outside that flag: they are still
# required when a deployment manages migrations elsewhere.

setup_app() {
    local root="${1:-/var/www/html}"
    local lock_file="${root}/storage/mothership.lock"

    echo "→ Ensuring API signing keys..."
    _mothership_php artisan mothership:passport:keys --no-interaction || return 1

    if [ "${MOTHERSHIP_SKIP_MIGRATIONS:-false}" = "true" ]; then
        echo "→ MOTHERSHIP_SKIP_MIGRATIONS=true — schema is managed outside the container."
    else
        if _mothership_php artisan migrate:status --no-interaction >/dev/null 2>&1; then
            echo "→ Applying pending migrations..."
            _mothership_php artisan migrate --force --no-interaction || return 1
        else
            echo "→ First-time setup — migrating and seeding..."
            _mothership_php artisan migrate --force --no-interaction || return 1
            _mothership_php artisan db:seed --force --no-interaction || return 1
        fi
    fi

    if _dam_root_exists "$root"; then
        echo "→ DAM root directory already exists."
    else
        local dam_status=$?

        if [ "$dam_status" -ne 1 ]; then
            return "$dam_status"
        fi

        echo "→ Publishing DAM resources and seeding the missing root directory..."
        _mothership_php artisan vendor:publish --tag=dam-config --force --no-interaction || return 1
        _mothership_php artisan vendor:publish --tag=dam-defaults --force --no-interaction || return 1
        _mothership_php artisan db:seed --force --no-interaction \
            --class='Mothership\DAM\Database\Seeders\DirectoryTableSeeder' || return 1
    fi

    _mothership_php artisan storage:link --relative --no-interaction >/dev/null 2>&1 || true

    echo 'Your Mothership App is Successfully Installed' > "${root}/storage/installed"
    touch "$lock_file"
}

_dam_root_exists() {
    local root="${1:-/var/www/html}"

    MOTHERSHIP_BOOT_ROOT="$root" _mothership_php -r '
        try {
            $root = getenv("MOTHERSHIP_BOOT_ROOT");
            require $root."/vendor/autoload.php";
            $app = require $root."/bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

            if (! Illuminate\Support\Facades\Schema::hasTable("dam_directories")) {
                exit(1);
            }

            $exists = Illuminate\Support\Facades\DB::table("dam_directories")
                ->whereNull("parent_id")
                ->where("name", "Root")
                ->exists();

            exit($exists ? 0 : 1);
        } catch (Throwable $error) {
            fwrite(STDERR, "Unable to inspect DAM root state: ".$error->getMessage().PHP_EOL);
            exit(2);
        }
    '
}

_mothership_php() {
    "${MOTHERSHIP_PHP_BIN:-php}" "$@"
}

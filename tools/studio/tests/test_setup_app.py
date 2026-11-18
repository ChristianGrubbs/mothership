"""Behaviour of dockerfiles/lib/setup-app.sh, the application container's boot-time setup.

`setup_app` runs with php, curl and sleep replaced by scripts that record every call, so the tests need no
database, network or Elasticsearch. They pin two things: the outcome never depends on Elasticsearch, and the
steps a deployment relies on still run in each supported state. storage/mothership.lock is the container
healthcheck's file, so setup writes it last, only after every step that can stop setup has succeeded.
"""

from __future__ import annotations

import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest


SCRIPT = pathlib.Path(__file__).resolve().parents[3] / "dockerfiles" / "lib" / "setup-app.sh"

# Records every call. A call exits with FAIL_STATUS (default 1) when "<command> <arguments>" contains a
# fragment from FAIL_ON, a comma-separated list.
STUB = """#!/bin/bash
name="$(basename "$0")"
echo "$name $*" >> "$CALLS_LOG"
IFS=',' read -ra fragments <<< "${FAIL_ON:-}"
for fragment in "${fragments[@]}"; do
    if [ -n "$fragment" ] && [[ "$name $*" == *"$fragment"* ]]; then
        exit "${FAIL_STATUS:-1}"
    fi
done
exit 0
"""

INDEXERS = ("mothership:product:index", "mothership:category:index")


class SetupAppTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = pathlib.Path(tempfile.mkdtemp(prefix="setup-app-test-"))
        self.addCleanup(shutil.rmtree, self.tmp, ignore_errors=True)

        self.root = self.tmp / "app"
        (self.root / "storage").mkdir(parents=True)

        bin_dir = self.tmp / "bin"
        bin_dir.mkdir()
        for name in ("php", "curl", "sleep"):
            stub = bin_dir / name
            stub.write_text(STUB)
            stub.chmod(0o755)

        self.bin_dir = bin_dir
        self.log = self.tmp / "calls.log"
        self.log.write_text("")

    def run_setup(self, **env: str) -> subprocess.CompletedProcess[str]:
        environment = {
            "PATH": f"{self.bin_dir}{os.pathsep}{os.environ['PATH']}",
            "MOTHERSHIP_PHP_BIN": str(self.bin_dir / "php"),
            "CALLS_LOG": str(self.log),
            **env,
        }

        return subprocess.run(
            ["bash", "-c", 'source "$1" && setup_app "$2"', "bash", str(SCRIPT), str(self.root)],
            env=environment,
            capture_output=True,
            text=True,
            timeout=60,
        )

    def calls(self) -> list[str]:
        return self.log.read_text().splitlines()

    def artisan(self) -> list[str]:
        return [line.removeprefix("php artisan ") for line in self.calls() if line.startswith("php artisan ")]

    def marker(self, name: str) -> bool:
        return (self.root / "storage" / name).is_file()

    def assert_installed(self) -> None:
        self.assertTrue(self.marker("installed"))
        self.assertTrue(self.marker("mothership.lock"))

    def test_existing_install_applies_migrations_and_marks_the_app_installed(self) -> None:
        result = self.run_setup()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            [
                "mothership:passport:keys --no-interaction",
                "migrate:status --no-interaction",
                "migrate --force --no-interaction",
                "storage:link --relative --no-interaction",
            ],
            self.artisan(),
        )
        self.assert_installed()

    def test_first_install_migrates_and_seeds(self) -> None:
        result = self.run_setup(FAIL_ON="migrate:status")

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            [
                "mothership:passport:keys --no-interaction",
                "migrate:status --no-interaction",
                "migrate --force --no-interaction",
                "db:seed --force --no-interaction",
                "storage:link --relative --no-interaction",
            ],
            self.artisan(),
        )
        self.assert_installed()

    def test_a_missing_dam_root_is_published_and_seeded(self) -> None:
        result = self.run_setup(FAIL_ON="php -r")

        self.assertEqual(0, result.returncode, result.stderr)
        artisan = self.artisan()
        self.assertIn("vendor:publish --tag=dam-config --force --no-interaction", artisan)
        self.assertIn("vendor:publish --tag=dam-defaults --force --no-interaction", artisan)
        self.assertIn(
            "db:seed --force --no-interaction --class=Mothership\\DAM\\Database\\Seeders\\DirectoryTableSeeder",
            artisan,
        )
        self.assert_installed()

    def test_skipping_migrations_leaves_every_other_step_in_place(self) -> None:
        result = self.run_setup(MOTHERSHIP_SKIP_MIGRATIONS="true")

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            ["mothership:passport:keys --no-interaction", "storage:link --relative --no-interaction"],
            self.artisan(),
        )
        self.assert_installed()

    def test_a_failed_migration_stops_setup_before_any_marker_is_written(self) -> None:
        result = self.run_setup(FAIL_ON="migrate --force")

        self.assertNotEqual(0, result.returncode)
        self.assertFalse(self.marker("installed"))
        self.assertFalse(self.marker("mothership.lock"))

    def test_a_dam_probe_error_stops_setup_before_publishing_or_any_marker(self) -> None:
        result = self.run_setup(FAIL_ON="php -r", FAIL_STATUS="2")

        self.assertEqual(2, result.returncode)
        self.assertEqual(
            [
                "mothership:passport:keys --no-interaction",
                "migrate:status --no-interaction",
                "migrate --force --no-interaction",
            ],
            self.artisan(),
        )
        self.assertFalse(self.marker("installed"))
        self.assertFalse(self.marker("mothership.lock"))

    def test_elasticsearch_is_irrelevant_to_setup(self) -> None:
        for enabled in ("true", "false"):
            for fail_on in ("", "curl"):
                with self.subTest(elasticsearch_enabled=enabled, curl_fails=bool(fail_on)):
                    (self.root / "storage" / "installed").unlink(missing_ok=True)
                    (self.root / "storage" / "mothership.lock").unlink(missing_ok=True)
                    self.log.write_text("")

                    result = self.run_setup(ELASTICSEARCH_ENABLED=enabled, FAIL_ON=fail_on)

                    self.assertEqual(0, result.returncode, result.stderr)
                    self.assertEqual([], [line for line in self.calls() if line.startswith("curl")])
                    self.assertEqual([], [a for a in self.artisan() if a.split(" ")[0] in INDEXERS])
                    self.assert_installed()


if __name__ == "__main__":
    unittest.main()

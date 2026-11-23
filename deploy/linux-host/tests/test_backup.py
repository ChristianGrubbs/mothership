"""backup.sh failure paths that once published a damaged set or deleted the wrong folder (docs/ops/linux-host.md)."""
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest

HERE = pathlib.Path(__file__).resolve().parent
SCRIPT = HERE.parent / "backup.sh"
FAKE_BIN = HERE / "fake_bin"


@unittest.skipUnless(sys.platform.startswith("linux"), "backup.sh needs bash 5 and GNU coreutils")
class BackupTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = pathlib.Path(tempfile.mkdtemp())
        self.home = self.tmp / "home"
        state = self.home / "state"
        for folder in ("storage/app", "storage/logs", "looper", "video", "higgsfield-session", "tunnel"):
            (state / folder).mkdir(parents=True)
        (state / "app.env").write_text("APP_KEY=test-only\n")
        (state / "stack.env").write_text("FPM_IMAGE=test\n")
        (state / "storage/app/file.txt").write_text("kept\n")
        (state / "storage/logs/laravel.log").write_text("left out\n")
        self.backups = self.tmp / "back ups"
        self.docker_state = self.tmp / "docker-state"
        self.docker_state.mkdir()
        self.path = f"{FAKE_BIN}:{os.environ['PATH']}"

    def tearDown(self) -> None:
        subprocess.run(["rm", "-rf", str(self.tmp)], check=True)

    def run_backup(self, *args: str, **env: str) -> subprocess.CompletedProcess:
        environment = dict(os.environ, PATH=env.pop("PATH", self.path), MOTHERSHIP_HOME=str(self.home),
                           MOTHERSHIP_BACKUPS=str(self.backups), MOTHERSHIP_BACKUP_RETRY_SECONDS="0",
                           FAKE_DOCKER_STATE=str(self.docker_state), **env)
        return subprocess.run(["bash", str(SCRIPT), *args], env=environment, capture_output=True, timeout=120)

    def published_sets(self) -> list:
        return sorted(p.name for p in self.backups.iterdir() if p.is_dir() and p.name.startswith("20"))

    def test_create_publishes_a_set_that_matches_its_manifest(self) -> None:
        result = self.run_backup("create")
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        set_dir = self.backups / self.published_sets()[0]
        manifest = json.loads((set_dir / "manifest.json").read_text())
        for entry in manifest["files"]:
            self.assertEqual(hashlib.sha256((set_dir / entry["name"]).read_bytes()).hexdigest(), entry["sha256"])
        self.assertEqual(manifest["postgres"]["rows"], 8)
        listing = subprocess.run(["tar", "-tzf", str(set_dir / "state.tar.gz")], capture_output=True, text=True).stdout
        self.assertIn("app.env", listing.split())
        self.assertNotIn("storage/logs/laravel.log", listing)

    def test_stream_publishes_nothing_when_a_step_fails(self) -> None:
        result = self.run_backup("stream", FAKE_DOCKER_CP_FAIL="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.published_sets(), [])
        self.assertEqual(result.stdout, b"")

    def test_pruning_keeps_the_newest_sets_under_a_path_with_spaces(self) -> None:
        for name in ("20200101T000000Z", "20200102T000000Z", "20200103T000000Z", "not a set"):
            (self.backups / name).mkdir(parents=True)
        result = self.run_backup("create", MOTHERSHIP_BACKUP_KEEP="2")
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        sets = self.published_sets()
        self.assertEqual(len(sets), 2)
        self.assertEqual(sets[0], "20200103T000000Z")
        self.assertTrue((self.backups / "not a set").is_dir())

    def test_a_state_archive_that_never_reads_cleanly_is_not_published(self) -> None:
        failing_tar = self.tmp / "failing-tar"
        failing_tar.mkdir()
        (failing_tar / "tar").write_text("#!/bin/sh\necho 'file changed as we read it' >&2\nexit 1\n")
        (failing_tar / "tar").chmod(0o755)
        result = self.run_backup("create", PATH=f"{failing_tar}:{self.path}")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b"kept changing", result.stderr)
        self.assertEqual(self.published_sets(), [])


if __name__ == "__main__":
    unittest.main()

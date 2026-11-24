"""The menu bar app's read of the stack on a Linux host (tools/studio/menubar/Resources/linux-host-status.py)."""

from __future__ import annotations

import importlib.util
import json
import os
import pathlib
import stat
import sys
import tempfile
import unittest

STATUS_PATH = pathlib.Path(__file__).parents[1] / "menubar" / "Resources" / "linux-host-status.py"
SPEC = importlib.util.spec_from_file_location("menubar_linux_host_status", STATUS_PATH)
status = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = status
SPEC.loader.exec_module(status)

# What `stack.sh config --format json` and `stack.sh ps --all --format json` answer, trimmed to the fields read.
CONFIG = {
    "services": {
        "fpm": {"container_name": "mothership-private-fpm"},
        "nginx": {"container_name": "mothership-private-nginx"},
        "queue": {"container_name": "mothership-private-queue"},
        "ai-index-queue": {"container_name": "mothership-private-ai-index-queue"},
        "ai-bridge": {"container_name": "mothership-ai-bridge"},
        "tunnel": {"container_name": "mothership-tunnel"},
    }
}
ROWS = [
    {"Service": "fpm", "Name": "mothership-private-fpm", "State": "running"},
    {"Service": "nginx", "Name": "mothership-private-nginx", "State": "running"},
    {"Service": "queue", "Name": "mothership-private-queue", "State": "exited"},
]


class LinuxHostStatusTest(unittest.TestCase):
    def fake_stack(self) -> tuple[pathlib.Path, pathlib.Path]:
        """A home whose stack.sh answers only when MOTHERSHIP_HOME names that home, as the real wrapper reads it."""
        home = pathlib.Path(tempfile.mkdtemp())
        stack = home / "source/deploy/linux-host/stack.sh"
        stack.parent.mkdir(parents=True)
        (home / "config.json").write_text(json.dumps(CONFIG), encoding="utf-8")
        (home / "ps.json").write_text("\n".join(json.dumps(row) for row in ROWS), encoding="utf-8")
        stack.write_text(
            "#!/bin/sh\n"
            f'[ "$MOTHERSHIP_HOME" = "{home}" ] || exit 1\n'
            'case "$1" in config) cat "$MOTHERSHIP_HOME/config.json" ;; ps) cat "$MOTHERSHIP_HOME/ps.json" ;; *) exit 2 ;; esac\n',
            encoding="utf-8",
        )
        stack.chmod(stack.stat().st_mode | stat.S_IXUSR)
        return home, stack

    def test_wrapper_reads_the_home_it_was_given(self) -> None:
        home, stack = self.fake_stack()
        env = dict(os.environ, MOTHERSHIP_HOME=str(home))
        self.assertEqual(len(status.compose_rows(stack, env)), 3)
        self.assertIsNotNone(status.stack_services(stack, env))
        wrong = dict(os.environ, MOTHERSHIP_HOME="/srv/mothership")
        self.assertIsNone(status.compose_rows(stack, wrong))

    def test_only_the_stacks_own_containers_count_and_a_missing_one_is_stopped(self) -> None:
        home, stack = self.fake_stack()
        env = dict(os.environ, MOTHERSHIP_HOME=str(home))
        found = status.containers(status.stack_services(stack, env), status.compose_rows(stack, env))
        self.assertEqual(found, [
            {"name": "ai_index_queue", "running": False},
            {"name": "fpm", "running": True},
            {"name": "nginx", "running": True},
            {"name": "queue", "running": False},
        ])

    def test_unreadable_container_list_leaves_the_tunnel_to_its_readiness_answer(self) -> None:
        self.assertEqual(status.tunnel(None, 4), {"running": True, "readyConnections": 4})
        self.assertEqual(status.tunnel(None, None), {"running": False, "readyConnections": None})
        self.assertEqual(status.tunnel(ROWS, 4), {"running": False, "readyConnections": 4})

    def test_busy_means_a_command_that_changes_this_project(self) -> None:
        changing = [
            ["bash", "/srv/mothership/source/deploy/linux-host/stack.sh", "up"],
            ["./stack.sh", "stop", "queue"],
            ["docker", "compose", "-p", "mothership", "up", "-d"],
            ["docker", "compose", "--project-name", "mothership", "--file", "compose.yaml", "--env-file", "stack.env",
             "up", "--detach", "--remove-orphans"],
            ["/usr/libexec/docker/cli-plugins/docker-compose", "compose", "--project-name=mothership", "down"],
            ["docker-compose", "-pmothership", "restart", "fpm"],
        ]
        for argv in changing:
            self.assertTrue(status.changes_stack(argv), argv)
        quiet = [
            ["bash", "/srv/mothership/source/deploy/linux-host/stack.sh", "ps", "--all"],
            ["docker", "compose", "-p", "mothership", "ps"],
            ["docker", "compose", "-p", "other", "up", "-d"],
            ["docker", "compose", "up", "-d"],
            ["docker", "stop", "mothership-private-fpm"],
            ["python3", "-", "--home", "/srv/mothership", "--stats"],
        ]
        for argv in quiet:
            self.assertFalse(status.changes_stack(argv), argv)

    def test_uptime_only_while_nginx_runs(self) -> None:
        now = 1_800_000_000.0
        started = "2027-01-15T07:59:00.123456789Z"
        self.assertEqual(status.uptime_from_inspect(f"true {started}\n", now), 60)
        self.assertIsNone(status.uptime_from_inspect(f"false {started}\n", now))
        self.assertIsNone(status.uptime_from_inspect("false 0001-01-01T00:00:00Z", now))
        self.assertIsNone(status.uptime_from_inspect(None, now))


if __name__ == "__main__":
    unittest.main()

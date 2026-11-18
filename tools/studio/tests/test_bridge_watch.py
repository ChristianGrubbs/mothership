from __future__ import annotations

import datetime
import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest

WATCH_PATH = pathlib.Path(__file__).parents[1] / "bridge_watch.py"
SPEC = importlib.util.spec_from_file_location("studio_bridge_watch", WATCH_PATH)
watch = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = watch
SPEC.loader.exec_module(watch)


class BridgeWatchTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = pathlib.Path(tempfile.mkdtemp())
        (self.tmp / "app.env").write_text(
            "MOTHERSHIP_OPTIMIZER_API=http://192.168.164.1:18766\nMOTHERSHIP_AI_API=\"http://192.168.164.1:18767\"\n",
            encoding="utf-8",
        )
        self.launched = []
        self.clock = 1_000_000.0
        self.extra = []
        self.public_ok = False
        self.alive = False

    def args(self):
        return watch.parse_args([
            "--source", str(self.tmp / "source"), "--native", str(self.tmp / "native"),
            "--app-env", str(self.tmp / "app.env"), "--work-dir", str(self.tmp / "work"),
            "--state-file", str(self.tmp / "work" / "state.json"), "--log", str(self.tmp / "watch.jsonl"),
            "--python", "/usr/bin/python3", "--autostart-log", str(self.tmp / "autostart.jsonl"), "--uid", "501",
            *self.extra,
        ])

    def tick(self, ai_up: bool, stack_up: bool = True, advance: float = 300.0) -> None:
        self.clock += advance
        watch.run(
            self.args(),
            probe=lambda host, port: port != 18767 or ai_up,
            stack_up=lambda _bin: stack_up,
            launch=lambda argv: self.launched.append(argv) or 0,
            now=lambda: self.clock,
            public_probe=lambda _url: self.public_ok,
            process_alive=lambda: self.alive,
        )

    def kicks(self):
        return [argv for argv in self.launched if argv[:2] == ["/bin/launchctl", "kickstart"]]

    def autostart_log(self, *events, ts=None):
        stamp = datetime.datetime.fromtimestamp(ts if ts is not None else self.clock, datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        with (self.tmp / "autostart.jsonl").open("a") as handle:
            for event in events:
                handle.write(json.dumps({"ts": stamp, "run_id": "r1", "event": event}) + "\n")

    def events(self):
        return [json.loads(line)["event"] for line in (self.tmp / "watch.jsonl").read_text().splitlines()]

    def test_restarts_only_after_two_failed_checks_through_terminal(self) -> None:
        self.tick(ai_up=False)
        self.assertEqual(self.launched, [])
        self.tick(ai_up=False)
        self.assertEqual(len(self.launched), 1)
        self.assertEqual(self.launched[0][:3], ["/usr/bin/open", "-a", "Terminal"])
        script = pathlib.Path(self.launched[0][3]).read_text(encoding="utf-8")
        self.assertIn("ai_service.py", script)
        self.assertIn("--port 18767", script)
        self.assertIn("--host 192.168.164.1", script)

    def test_a_healthy_check_resets_the_count_and_cooldown_limits_restarts(self) -> None:
        self.tick(ai_up=False)
        self.tick(ai_up=True)
        self.tick(ai_up=False)
        self.assertEqual(self.launched, [])
        self.tick(ai_up=False)
        self.tick(ai_up=False)
        self.assertEqual(len(self.launched), 1, "a second restart waits out the cooldown")
        self.tick(ai_up=False, advance=watch.COOLDOWN_SECONDS)
        self.assertEqual(len(self.launched), 2)

    def test_never_restarts_bridges_while_the_stack_is_down(self) -> None:
        self.public_ok = True
        for _ in range(3):
            self.tick(ai_up=False, stack_up=False)
        self.assertEqual(self.launched, [])
        self.assertEqual(set(self.events()), {"stack.heal_decision"})

    def test_kickstarts_autostart_after_two_public_failures_with_stack_down(self) -> None:
        self.tick(ai_up=True, stack_up=False)
        self.assertEqual(self.kicks(), [])
        self.tick(ai_up=True, stack_up=False)
        self.assertEqual(self.kicks(), [["/bin/launchctl", "kickstart", "gui/501/app.themothership.autostart"]])
        self.assertIn("stack.kickstart_requested", self.events())

    def test_public_ok_or_stack_up_resets_the_count(self) -> None:
        self.tick(ai_up=True, stack_up=False)
        self.public_ok = True
        self.tick(ai_up=True, stack_up=False)
        self.public_ok = False
        self.tick(ai_up=True, stack_up=False)
        self.tick(ai_up=True, stack_up=True)
        self.tick(ai_up=True, stack_up=False)
        self.assertEqual(self.kicks(), [])

    def test_waits_while_an_autostart_run_is_in_progress(self) -> None:
        self.autostart_log("autostart.begin", "container.running")
        self.tick(ai_up=True, stack_up=False, advance=60)
        self.tick(ai_up=True, stack_up=False, advance=60)
        self.assertEqual(self.kicks(), [])
        self.assertIn('"autostart_running"', (self.tmp / "watch.jsonl").read_text())
        self.tick(ai_up=True, stack_up=False, advance=watch.AUTOSTART_MAX_RUN_SECONDS)
        self.assertEqual(len(self.kicks()), 1, "a run silent past the max run time is dead unless its process lives")

    def test_a_stale_run_with_a_live_process_still_blocks(self) -> None:
        self.autostart_log("autostart.begin", ts=self.clock - watch.AUTOSTART_MAX_RUN_SECONDS - 1)
        self.alive = True
        self.tick(ai_up=True, stack_up=False)
        self.tick(ai_up=True, stack_up=False)
        self.assertEqual(self.kicks(), [])

    def test_an_ended_run_does_not_block(self) -> None:
        self.autostart_log("autostart.begin", "tunnel.ready")
        self.alive = True
        self.tick(ai_up=True, stack_up=False)
        self.tick(ai_up=True, stack_up=False)
        self.assertEqual(len(self.kicks()), 1)

    def test_cooldown_and_hourly_cap(self) -> None:
        self.tick(ai_up=True, stack_up=False)
        self.tick(ai_up=True, stack_up=False)
        self.assertEqual(len(self.kicks()), 1)
        self.tick(ai_up=True, stack_up=False)
        self.assertEqual(len(self.kicks()), 1, "cooldown")
        self.assertIn('"cooldown"', (self.tmp / "watch.jsonl").read_text())
        heal = {"failures": 5, "kicks": [self.clock - 3000, self.clock - 2000, self.clock - 1000]}
        self.assertEqual(watch.heal_decision({"_stack": heal}, False, False, self.clock), "hourly_cap")
        heal["kicks"][0] = self.clock - 3700
        self.assertEqual(watch.heal_decision({"_stack": heal}, False, False, self.clock), "kickstart")

    def test_dry_run_simulated_down_never_launches(self) -> None:
        self.extra = ["--dry-run", "--simulate-down"]
        self.tick(ai_up=True, stack_up=True)
        self.tick(ai_up=True, stack_up=True)
        self.assertEqual(self.launched, [])
        events = self.events()
        self.assertIn("watch.simulated_down", events)
        self.assertIn("stack.kickstart_skipped", events)


if __name__ == "__main__":
    unittest.main()

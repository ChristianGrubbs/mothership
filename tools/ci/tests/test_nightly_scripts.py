"""Regression checks for the nightly CI guard and summary (.github/scripts, 2026-10-14).

Each case is a failure mode found in review: a duplicate run reported green while the run it
deferred to had failed, a plain dispatch counted as tonight's nightly, a timed-out job counted
as passed, window arithmetic that moved the cut by an hour on DST days, a gate or a leg admitted
after the 05:55 cut, and a watchdog that died silently on a bad reply.

Run: python3 -m unittest discover -s tools/ci/tests
"""

from __future__ import annotations

import datetime as dt
import http.server
import importlib.util
import io
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from contextlib import redirect_stdout
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[3]
SCRIPTS = ROOT / ".github" / "scripts"
SERVICE = SCRIPTS / "ci_service.sh"


def load(name: str):
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / "{}.py".format(name))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


guard = load("nightly_guard")
summary = load("nightly_summary")


def utc(text: str) -> dt.datetime:
    return dt.datetime.fromisoformat(text).replace(tzinfo=dt.timezone.utc)


class GuardTest(unittest.TestCase):
    def run_guard(self, now: dt.datetime, mode: str = "job", runs=None, **env):
        temp = tempfile.mkdtemp()
        out = pathlib.Path(temp, "out")
        environment = {
            "GITHUB_OUTPUT": str(out), "RUNNER_TEMP": temp, "EVENT_NAME": "schedule", "ITEM_KEY": "k",
            "ITEM_LABEL": "L", "GITHUB_RUN_ID": "200", "GITHUB_SHA": "abc", "GITHUB_REPOSITORY": "o/r",
            "GITHUB_WORKFLOW_REF": "o/r/.github/workflows/p.yml@refs/heads/main", "GITHUB_TOKEN": "t",
        }
        environment.update(env)
        watchdog = []

        def fake_api(path, method="GET"):
            if path == "/actions/runs/200":
                return {"id": 200, "created_at": "2026-10-15T05:30:00Z"}
            return {"workflow_runs": runs or []}

        with mock.patch.dict(os.environ, environment, clear=False), \
                mock.patch.object(guard, "utc_now", return_value=now), \
                mock.patch.object(guard, "api", side_effect=fake_api), \
                mock.patch.object(guard, "start_watchdog",
                                  side_effect=lambda seconds: watchdog.append(seconds)), \
                mock.patch.object(sys, "argv", ["nightly_guard.py"] + ([mode] if mode == "gate" else [])), \
                redirect_stdout(io.StringIO()):
            self.assertEqual(guard.main(), 0)
        values = dict(line.split("=", 1) for line in out.read_text().splitlines())
        return values, watchdog

    def test_plain_dispatch_is_not_a_duplicate_of_tonights_nightly(self):
        runs = [
            {"id": 150, "event": "workflow_dispatch", "display_title": "Playwright Tests", "created_at": "2026-10-15T05:10:00Z"},
        ]
        values, _ = self.run_guard(utc("2026-10-15T05:30:00"), mode="gate", runs=runs)
        self.assertEqual(values["run"], "true")

    def test_earlier_nightly_dispatch_defers_the_cron_run_as_a_duplicate(self):
        runs = [
            {"id": 150, "event": "workflow_dispatch", "display_title": "Nightly: Playwright Tests", "created_at": "2026-10-15T05:10:00Z"},
        ]
        values, _ = self.run_guard(utc("2026-10-15T05:30:00"), mode="gate", runs=runs)
        self.assertEqual((values["run"], values["reason"], values["duplicate_of"]), ("false", "duplicate", "150"))

    def test_gate_defers_a_run_created_after_the_window(self):
        values, _ = self.run_guard(utc("2026-10-15T17:00:00"), mode="gate")  # 12:00 CDT
        self.assertEqual((values["run"], values["reason"]), ("false", "outside-window"))

    def test_watchdog_cuts_at_0555_local_on_spring_forward_night(self):
        # 2026-03-08: 00:00 CST (06:00 UTC); 06:00 CDT is 11:00 UTC, so 05:55 is 10:55 UTC: 295 minutes.
        values, watchdog = self.run_guard(utc("2026-03-08T06:00:00"), MIN_MINUTES="30")
        self.assertEqual(values["run"], "true")
        self.assertEqual(watchdog, [295 * 60])

    def test_watchdog_cuts_at_0555_local_on_fall_back_night(self):
        # 2026-11-01: 00:00 CDT (05:00 UTC); 06:00 CST is 12:00 UTC, so 05:55 is 11:55 UTC: 415 minutes.
        values, watchdog = self.run_guard(utc("2026-11-01T05:00:00"), MIN_MINUTES="30")
        self.assertEqual(values["run"], "true")
        self.assertEqual(watchdog, [415 * 60])

    def test_non_nightly_runs_are_never_held_in_either_mode(self):
        cases = [("workflow_dispatch", "false"), ("workflow_dispatch", ""), ("pull_request", "")]
        for event, nightly in cases:
            for mode in ("gate", "job"):
                with self.subTest(event=event, nightly=nightly, mode=mode):
                    values, watchdog = self.run_guard(utc("2026-10-15T17:00:00"), mode=mode, EVENT_NAME=event,
                                                      NIGHTLY_INPUT=nightly)
                    self.assertEqual((values["run"], values["reason"]), ("true", "not-nightly"))
                    self.assertEqual(watchdog, [])

    def test_gate_closes_at_the_0555_cut_not_at_0600(self):
        # 05:56 CDT: past the cut, so the run starts nothing.
        values, watchdog = self.run_guard(utc("2026-10-15T10:56:00"), mode="gate")
        self.assertEqual((values["run"], values["reason"]), ("false", "outside-window"))
        self.assertEqual(watchdog, [])

    def test_a_leg_picked_up_after_the_cut_defers_before_starting_anything(self):
        # 05:56 CDT, a leg that needs no minimum: it still defers, so its services never start.
        values, watchdog = self.run_guard(utc("2026-10-15T10:56:00"), MIN_MINUTES="0", MARGIN_MINUTES="0")
        self.assertEqual((values["run"], values["reason"]), ("false", "outside-window"))
        self.assertEqual(watchdog, [])

    def test_gate_cut_stays_at_0555_whatever_the_margin(self):
        values, _ = self.run_guard(utc("2026-10-15T10:56:00"), mode="gate", MARGIN_MINUTES="0")
        self.assertEqual((values["run"], values["reason"]), ("false", "outside-window"))

    def test_open_gate_starts_no_watchdog_on_its_own_short_lived_token(self):
        values, watchdog = self.run_guard(utc("2026-10-15T10:00:00"), mode="gate")
        self.assertEqual(values["run"], "true")
        self.assertEqual(watchdog, [])

    def test_job_defers_with_too_little_window_left(self):
        values, watchdog = self.run_guard(utc("2026-10-15T10:40:00"), MIN_MINUTES="30")  # 05:40 CDT
        self.assertEqual((values["run"], values["reason"]), ("false", "insufficient-window"))
        self.assertEqual(watchdog, [])


class SummaryTest(unittest.TestCase):
    def run_summary(self, jobs, totals=(), predecessor=None, **env):
        temp = tempfile.mkdtemp()
        folder = pathlib.Path(temp, "totals")
        folder.mkdir()
        for index, record in enumerate(totals):
            (folder / "{}.json".format(index)).write_text(json.dumps(dict({"schema": 1}, **record)))
        environment = {"TOTALS_DIR": str(folder), "GITHUB_STEP_SUMMARY": os.devnull, "GITHUB_RUN_ID": "200",
                       "GITHUB_REPOSITORY": "o/r", "GITHUB_TOKEN": "t"}
        environment.update(env)

        def fake_api(path):
            if path.startswith("/actions/runs/200/attempts"):
                return {"jobs": jobs}
            return predecessor or {}

        cwd = os.getcwd()
        os.chdir(temp)
        try:
            with mock.patch.dict(os.environ, environment, clear=False), \
                    mock.patch.object(summary, "api", side_effect=fake_api), redirect_stdout(io.StringIO()):
                code = summary.main()
            return code, json.loads(pathlib.Path(temp, "nightly-summary.json").read_text())
        finally:
            os.chdir(cwd)

    SKIPPED = [{"name": "Shard 1", "conclusion": "skipped"}, {"name": "Nightly gate", "conclusion": "success"}]
    DUPLICATE = {"GATE_RUN": "false", "GATE_REASON": "duplicate", "GATE_DUPLICATE_OF": "150"}

    def test_duplicate_is_green_only_after_its_predecessor_passed(self):
        code, result = self.run_summary(self.SKIPPED, predecessor={"status": "completed", "conclusion": "success"}, **self.DUPLICATE)
        self.assertEqual((code, result["verdict"]), (0, "duplicate"))

    def test_duplicate_of_a_failed_run_is_red(self):
        code, result = self.run_summary(self.SKIPPED, predecessor={"status": "completed", "conclusion": "failure"}, **self.DUPLICATE)
        self.assertEqual((code, result["verdict"]), (1, "red"))

    def test_duplicate_of_a_cancelled_run_is_red(self):
        code, _ = self.run_summary(self.SKIPPED, predecessor={"status": "completed", "conclusion": "cancelled"}, **self.DUPLICATE)
        self.assertEqual(code, 1)

    def test_duplicate_of_a_run_still_in_progress_is_red(self):
        code, _ = self.run_summary(self.SKIPPED, predecessor={"status": "in_progress", "conclusion": None}, **self.DUPLICATE)
        self.assertEqual(code, 1)

    def test_gate_deferred_jobs_are_listed_as_deferred_and_red(self):
        code, result = self.run_summary(self.SKIPPED, GATE_RUN="false", GATE_REASON="outside-window")
        self.assertEqual(code, 1)
        self.assertEqual(result["detail"][0]["category"], "deferred")
        self.assertEqual(result["detail"][0]["reason"], "outside-window")

    def test_passing_totals_on_a_timed_out_job_are_not_a_pass(self):
        totals = [{"label": "Shard 1", "status": "executed", "job_status": "success", "advisory": False,
                   "counts": {"planned": 2, "passed": 2, "failed": 0, "not_run": 0}}]
        code, result = self.run_summary([{"name": "Shard 1", "conclusion": "timed_out"}], totals, GATE_RUN="true")
        self.assertEqual(code, 1)
        self.assertEqual(result["detail"][0]["category"], "executed/failed")

    def test_all_passed_is_green(self):
        totals = [{"label": "Shard 1", "status": "executed", "job_status": "success", "advisory": False,
                   "counts": {"planned": 2, "passed": 2, "failed": 0, "not_run": 0}}]
        code, result = self.run_summary([{"name": "Shard 1", "conclusion": "success"}], totals, GATE_RUN="true")
        self.assertEqual((code, result["verdict"]), (0, "green"))


class WatchdogScriptTest(unittest.TestCase):
    """Runs the generated watchdog itself against a stub API (GuardTest mocks it out)."""

    def run_watchdog(self, body: bytes):
        posts = []

        class Stub(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(200)
                self.end_headers()
                self.wfile.write(body)

            def do_POST(self):
                posts.append(self.path)
                self.send_response(202)
                self.end_headers()

            def log_message(self, *args):
                pass

        server = http.server.HTTPServer(("127.0.0.1", 0), Stub)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        temp = pathlib.Path(tempfile.mkdtemp())
        captured = []
        environment = {"GITHUB_API_URL": "http://127.0.0.1:{}".format(server.server_port), "GITHUB_REPOSITORY": "o/r",
                       "GITHUB_RUN_ID": "1", "GITHUB_TOKEN": "t", "RUNNER_TEMP": str(temp)}
        try:
            with mock.patch.dict(os.environ, environment, clear=False):
                with mock.patch.object(guard.subprocess, "Popen", side_effect=lambda args, **kw: captured.append(args)):
                    guard.start_watchdog(0)
                code = subprocess.run(captured[0], timeout=60).returncode
        finally:
            server.shutdown()
            server.server_close()
        return code, sorted(path.name for path in temp.iterdir()), posts

    def test_running_run_is_cancelled_and_marked(self):
        code, files, posts = self.run_watchdog(b'{"status":"in_progress"}')
        self.assertEqual((code, files, posts), (0, ["nightly-window-cut"], ["/repos/o/r/actions/runs/1/cancel"]))

    def test_unreadable_reply_leaves_the_failure_marker(self):
        code, files, posts = self.run_watchdog(b"<html>not json")
        self.assertEqual((code, files, posts), (1, ["nightly-watchdog-failed"], []))


FAKE_DOCKER = r"""#!/usr/bin/env python3
# A stateful stand-in for the docker CLI calls ci_service.sh makes; containers live in STATE.
import fcntl, json, os, subprocess, sys, time
args = sys.argv[1:]
lock = open(os.environ["STATE"] + ".lock", "w")
fcntl.flock(lock, fcntl.LOCK_EX)
state = json.load(open(os.environ["STATE"]))
state["calls"].append(args)
json.dump(state, open(os.environ["STATE"], "w"))
if args[0] == "start" and "daemon_start" in state and os.environ.get("FAKE_DAEMON") != "1":
    # The daemon goes on starting the container after the client is killed; until it finishes the
    # container is busy and `rm -f` cannot remove it.
    state["containers"][args[1]]["starting"] = True
    json.dump(state, open(os.environ["STATE"], "w"))
    subprocess.Popen([sys.executable, __file__] + args, start_new_session=True,
                     env=dict(os.environ, FAKE_DAEMON="1"), stdout=subprocess.DEVNULL)
    fcntl.flock(lock, fcntl.LOCK_UN)
    time.sleep(30)
    sys.exit(0)
if args[0] == "create" and "daemon_create" in state and os.environ.get("FAKE_DAEMON") != "1":
    # The daemon goes on creating the container after the client is killed.
    subprocess.Popen([sys.executable, __file__] + args, start_new_session=True,
                     env=dict(os.environ, FAKE_DAEMON="1"), stdout=subprocess.DEVNULL)
    fcntl.flock(lock, fcntl.LOCK_UN)
    time.sleep(30)
    sys.exit(0)
if os.environ.get("FAKE_DAEMON") == "1":
    fcntl.flock(lock, fcntl.LOCK_UN)
    time.sleep(state["daemon_" + args[0]])
else:
    fcntl.flock(lock, fcntl.LOCK_UN)
    time.sleep(state.get("sleep", {}).get(args[0], 0))  # a slow pull, create, inspect or reap listing
fcntl.flock(lock, fcntl.LOCK_EX)
state = json.load(open(os.environ["STATE"]))
out, code = "", 0
if args[0] == "pull":
    pass
elif args[0] == "start":
    if args[1] in state["containers"]:
        state["containers"][args[1]].update(started=True, starting=False)
elif args[0] == "create":
    labels = [args[i + 1] for i, a in enumerate(args) if a == "--label"]
    cid = "%064x" % (len(state["containers"]) + 1)
    health = state.get("health", ["starting", "healthy"])
    state["containers"][cid] = {"labels": labels, "health": list(health), "created": "2026-10-15T05:00:00.000Z",
                                "port": args[args.index("-p") + 1], "image": args[-1]}
    out = cid + "\n"
elif args[0] == "inspect":
    cid = args[-1]
    c = state["containers"].get(cid)
    if c is None:
        code = 1
    elif "{{.Created}}" in args[2]:
        out = c["created"] + "\n"
    else:
        h = c["health"].pop(0) if len(c["health"]) > 1 else c["health"][0]
        out = "running {}\n".format(h)
elif args[0] == "port":
    out = state.get("port_lines", "0.0.0.0:49153\n[::]:49153\n")
elif args[0] == "ps" and state.get("ps_fail", 0) > 0:
    state["ps_fail"] -= 1
    code = 1
elif args[0] == "ps":
    label = args[args.index("--filter") + 1].split("label=", 1)[1]
    out = "".join(cid + "\n" for cid, c in state["containers"].items() if label in c["labels"])
elif args[0] == "rm":
    for cid in args[2:]:
        if state.get("rm_fail") or state["containers"].get(cid, {}).get("starting"):
            code = 1  # busy: docker refuses and leaves the container
        else:
            state["containers"].pop(cid, None)
elif args[0] == "logs":
    out = "log tail\n"
json.dump(state, open(os.environ["STATE"], "w"))
sys.stdout.write(out)
sys.exit(code)
"""


class ServiceHelperTest(unittest.TestCase):
    """Runs .github/scripts/ci_service.sh against a fake docker: services now start after the guard."""

    def helper(self, *args, tag="200-1-pest-0", **state):
        temp = pathlib.Path(tempfile.mkdtemp())
        (temp / "bin").mkdir()
        docker = temp / "bin" / "docker"
        docker.write_text(FAKE_DOCKER)
        docker.chmod(0o755)
        if not (temp / "state.json").exists():
            (temp / "state.json").write_text(json.dumps(dict({"containers": {}, "calls": []}, **state)))
        return temp, self.call(temp, *args, tag=tag)

    def helper_cut(self, seconds, *args, **state):
        """Like helper, with the nightly cut `seconds` from now."""
        self.cut = str(int(time.time()) + seconds)
        try:
            return self.helper(*args, **state)
        finally:
            self.cut = ""

    cut = ""
    sweep = "4"
    cleanup = "8"

    def call(self, temp, *args, tag="200-1-pest-0"):
        (temp / "env").touch()
        environment = dict(os.environ, PATH="{}{}{}".format(temp / "bin", os.pathsep, os.environ["PATH"]),
                           STATE=str(temp / "state.json"), GITHUB_ENV=str(temp / "env"), CI_SVC_TAG=tag,
                           CI_SVC_DEADLINE=self.cut, CI_SVC_SWEEP_SECONDS=self.sweep,
                           CI_SVC_CLEANUP_SECONDS=self.cleanup)
        return subprocess.run(["bash", str(SERVICE)] + list(args), env=environment, capture_output=True, text=True,
                              timeout=60)

    def state(self, temp):
        return json.loads((temp / "state.json").read_text())

    def test_start_waits_for_health_and_exports_port_and_id(self):
        temp, result = self.helper("start", "DB", "5432", "-e", "POSTGRES_USER=mothership", "--health-cmd=pg_isready",
                                   "postgres:16")
        self.assertEqual(result.returncode, 0, result.stderr)
        exported = dict(line.split("=", 1) for line in (temp / "env").read_text().splitlines())
        self.assertEqual(exported["SVC_DB_PORT"], "49153")
        self.assertRegex(exported["SVC_DB_ID"], r"^[0-9a-f]{64}$")
        run = [call for call in self.state(temp)["calls"] if call[0] == "create"][0]
        self.assertEqual(run[-1], "postgres:16")
        self.assertIn("ci-svc-job=200-1-pest-0", run)
        self.assertTrue(list(self.state(temp)["containers"].values())[0].get("started"))
        self.assertGreaterEqual(sum(call[0] == "inspect" for call in self.state(temp)["calls"]), 2)  # waited

    def test_an_unhealthy_service_fails_the_step(self):
        temp, result = self.helper("start", "DB", "5432", "postgres:16", health=["starting", "unhealthy"])
        self.assertEqual(result.returncode, 1)
        self.assertIn("service.unhealthy", result.stdout)
        self.assertNotIn("SVC_DB_PORT", (temp / "env").read_text())

    def test_stop_removes_only_this_jobs_containers(self):
        temp, _ = self.helper("start", "DB", "5432", "postgres:16")
        self.call(temp, "start", "DB", "5432", "postgres:16", tag="200-1-pest-1")  # the other matrix leg
        result = self.call(temp, "stop")
        self.assertEqual(result.returncode, 0, result.stderr)
        left = self.state(temp)["containers"]
        self.assertEqual([c["labels"][1] for c in left.values()], ["ci-svc-job=200-1-pest-1"])

    def test_start_reaps_containers_a_dead_runner_left_behind(self):
        stale = {"%064x" % 99: {"labels": ["ci-svc=1", "ci-svc-job=old"], "health": ["healthy"],
                                "created": "2026-01-01T00:00:00.000000000Z", "port": "5432", "image": "postgres:16"}}
        temp, result = self.helper("start", "DB", "5432", "postgres:16", containers=stale)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("%064x" % 99, self.state(temp)["containers"])

    def test_ports_take_only_an_ipv4_wildcard_or_loopback_mapping(self):
        for lines, port in [("[::]:49160\n0.0.0.0:49153\n", "49153"),  # reversed order
                            ("[::]:49160\n127.0.0.1:49154\n", "49154"),  # loopback binding
                            ("0.0.0.0:49153\n[::]:49160\n", "49153")]:  # IPv4 and IPv6 ports differ
            temp, result = self.helper("start", "DB", "5432", "postgres:16", port_lines=lines)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("SVC_DB_PORT={}\n".format(port), (temp / "env").read_text())
        for lines in ["[::]:49160\n", "10.0.0.5:49153\n"]:  # IPv6-only, or an address the steps can't use
            temp, result = self.helper("start", "DB", "5432", "postgres:16", port_lines=lines)
            self.assertEqual(result.returncode, 1, lines)
            self.assertIn("service.no_ipv4_port", result.stdout)
            self.assertNotIn("SVC_DB_PORT", (temp / "env").read_text())

    def assert_refused(self, temp, result):
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("service.past_cut", result.stdout)
        self.assertIn("05:55 cut", result.stderr)
        self.assertEqual(self.state(temp)["containers"], {})
        self.assertNotIn("SVC_DB_PORT", (temp / "env").read_text())

    def test_nothing_starts_once_the_cut_has_passed(self):
        temp, result = self.helper_cut(-1, "start", "DB", "5432", "postgres:16")
        self.assert_refused(temp, result)
        self.assertFalse([call for call in self.state(temp)["calls"] if call[0] in ("pull", "create")])

    def test_a_slow_reap_that_crosses_the_cut_starts_nothing(self):
        temp, result = self.helper_cut(1, "start", "DB", "5432", "postgres:16", sleep={"ps": 2})
        self.assert_refused(temp, result)
        self.assertFalse([call for call in self.state(temp)["calls"] if call[0] in ("create", "start")])

    def test_a_pull_that_crosses_the_cut_is_cut_short_and_starts_nothing(self):
        began = time.time()
        temp, result = self.helper_cut(2, "start", "DB", "5432", "postgres:16", sleep={"pull": 30})
        self.assert_refused(temp, result)
        self.assertLess(time.time() - began, 15)  # the pull was bounded by the cut, not left to finish
        self.assertFalse([call for call in self.state(temp)["calls"] if call[0] in ("create", "start")])

    def test_a_run_that_crosses_the_cut_is_cut_short_and_leaves_no_container(self):
        began = time.time()
        temp, result = self.helper_cut(2, "start", "DB", "5432", "postgres:16", sleep={"create": 30})
        self.assert_refused(temp, result)
        self.assertLess(time.time() - began, 20)  # docker create was bounded by the cut too
        self.assertFalse([call for call in self.state(temp)["calls"] if call[0] == "start"])

    def test_a_service_not_healthy_by_the_cut_is_removed(self):
        temp, result = self.helper_cut(3, "start", "DB", "5432", "postgres:16", health=["starting"])
        self.assert_refused(temp, result)
        self.assertTrue([call for call in self.state(temp)["calls"] if call[0] == "start"])

    def test_a_container_the_daemon_creates_after_the_client_was_killed_is_removed(self):
        # It appears ~9 s after the cut, well past three quiet listings, but before the sweep ends.
        self.sweep = "14"
        try:
            temp, result = self.helper_cut(2, "start", "DB", "5432", "postgres:16", daemon_create=11)
        finally:
            self.sweep = "4"
        self.assert_refused(temp, result)
        self.assertFalse([call for call in self.state(temp)["calls"] if call[0] == "start"])
        self.assertGreaterEqual(json.loads(result.stdout.splitlines()[-1])["ctx"]["removed"], 1)

    def test_a_start_the_daemon_finishes_after_the_cut_is_removed_once_it_settles(self):
        temp, result = self.helper_cut(2, "start", "DB", "5432", "postgres:16", daemon_start=6)
        self.assert_refused(temp, result)
        calls = self.state(temp)["calls"]
        start = next(i for i, call in enumerate(calls) if call[0] == "start")
        removals = [i for i, call in enumerate(calls) if call[0] == "rm" and i > start]
        self.assertGreaterEqual(len(removals), 2)  # refused while busy, removed once it settled

    def stop_at(self, temp, cut_offset):
        self.cut = str(int(time.time()) + cut_offset)
        try:
            return self.call(temp, "stop")
        finally:
            self.cut = ""

    def test_a_stop_long_past_the_cut_still_removes(self):
        for offset in (-4, -60, -400):  # at the end of the sweep, past cleanup, and far past (clock jump)
            temp, _ = self.helper("start", "DB", "5432", "postgres:16")
            result = self.stop_at(temp, offset)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(self.state(temp)["containers"], {}, offset)

    def test_a_failed_listing_is_not_counted_as_quiet(self):
        temp, _ = self.helper("start", "DB", "5432", "postgres:16")
        state = self.state(temp)
        state["ps_fail"] = 3  # three failed listings would have looked like three empty ones
        (temp / "state.json").write_text(json.dumps(state))
        self.cleanup = "30"
        try:
            result = self.call(temp, "stop")
        finally:
            self.cleanup = "8"
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.state(temp)["containers"], {})

    def test_a_container_that_cannot_be_removed_fails_the_stop(self):
        temp, _ = self.helper("start", "DB", "5432", "postgres:16", rm_fail=True)
        result = self.stop_at(temp, -1)
        self.assertEqual(result.returncode, 1)
        self.assertIn("service.sweep_incomplete", result.stdout)
        self.assertNotIn("service.stopped", result.stdout)

    def test_a_hung_removal_is_bounded_by_the_budget(self):
        temp, _ = self.helper("start", "DB", "5432", "postgres:16", sleep={"rm": 60})
        began = time.time()
        result = self.stop_at(temp, -1)
        self.assertEqual(result.returncode, 1)
        self.assertLess(time.time() - began, 30)

    def test_a_slow_listing_then_a_hung_removal_stays_within_the_budget(self):
        temp, _ = self.helper("start", "DB", "5432", "postgres:16", sleep={"rm": 60, "ps": 3})
        began = time.time()
        result = self.stop_at(temp, -1)  # budget: 3 s of sweep left plus 8 s of cleanup
        self.assertEqual(result.returncode, 1)
        self.assertLess(time.time() - began, 11 + 3)  # scheduling slack only, not a second budget

    def test_leading_zero_overrides_are_read_as_decimal(self):
        for sweep, cleanup in (("04", "08"), ("009", "010")):
            self.sweep, self.cleanup = sweep, cleanup
            try:
                temp, _ = self.helper("start", "DB", "5432", "postgres:16")
                result = self.stop_at(temp, -1)
            finally:
                self.sweep, self.cleanup = "4", "8"
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(self.state(temp)["containers"], {})

    def test_a_stop_after_the_cut_removes_this_jobs_containers(self):
        temp, _ = self.helper("start", "DB", "5432", "postgres:16")
        self.cut = str(int(time.time()) - 1)
        try:
            result = self.call(temp, "stop")
        finally:
            self.cut = ""
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.state(temp)["containers"], {})

    def test_a_slow_inspect_that_reports_ready_after_the_cut_is_refused(self):
        for health in ("healthy", "none"):
            temp, result = self.helper_cut(3, "start", "DB", "5432", "postgres:16", health=[health],
                                           sleep={"inspect": 5})
            self.assert_refused(temp, result)

    def test_a_start_well_before_the_cut_is_unaffected(self):
        temp, result = self.helper_cut(3600, "start", "DB", "5432", "postgres:16")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("SVC_DB_PORT=49153\n", (temp / "env").read_text())


if __name__ == "__main__":
    unittest.main()

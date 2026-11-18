from __future__ import annotations

import ctypes
import importlib.util
import json
import os
import pathlib
import sys
import signal as signal_module
import tempfile
import unittest
from datetime import datetime, timezone


PROCESS_PATH = pathlib.Path(__file__).parents[1] / "system_metrics_process.py"
SPEC = importlib.util.spec_from_file_location("studio_system_metrics_process", PROCESS_PATH)
process = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = process
SPEC.loader.exec_module(process)


class FakeEventLog:
    """Fake event log for testing."""

    def __init__(self):
        self.events = []

    def write(self, level, event, loc, ctx=None):
        self.events.append({"level": level, "event": event, "loc": loc, "ctx": ctx or {}})


class FakeProcessApi:
    """Fake process API for testing."""

    def __init__(self, *, live=None):
        self.live = live or {}
        self.signals = []
        self.spawned = []
        self._time = datetime(2026, 9, 20, 12, 0, 0, tzinfo=timezone.utc)
        self.still_alive_after_term = False
        self.always_alive = False
        self.monotonic_time = 0.0
        self.sleep_durations = []
        self._term_received = False
        self._kill_received = False
        self.is_live_responses = None

    def is_live(self, *, pid):
        if self.is_live_responses is not None:
            if self.is_live_responses:
                return self.is_live_responses.pop(0)
            raise AssertionError("is_live called more times than scripted")
        if self.always_alive:
            return True
        if self._kill_received:
            return False
        if self._term_received:
            if self.still_alive_after_term:
                return True
            return False
        return pid in self.live

    def command_line(self, *, pid):
        if pid not in self.live:
            return None
        cmd = self.live[pid]
        if isinstance(cmd, tuple):
            return cmd
        if isinstance(cmd, str):
            return tuple(cmd.split())
        return None

    def spawn(self, *, command):
        pid = max(self.live.keys()) + 1 if self.live else 1000
        self.spawned.append((pid, tuple(command)))
        self.live[pid] = tuple(command)
        return pid

    def now(self):
        return self._time

    def signal(self, *, pid, signum):
        self.signals.append((pid, signum))
        if signum == signal_module.SIGTERM:
            self._term_received = True
        elif signum == signal_module.SIGKILL:
            self._kill_received = True

    def monotonic(self):
        return self.monotonic_time

    def sleep(self, duration):
        self.sleep_durations.append(duration)
        self.monotonic_time += duration


class CollectorManagerTest(unittest.TestCase):
    def setUp(self):
        self._root_tmp = tempfile.TemporaryDirectory()
        self._root = pathlib.Path(self._root_tmp.name)
        self._script_path = pathlib.Path(__file__).parents[1] / "system_metrics.py"
        self.addCleanup(self._root_tmp.cleanup)

    def manager(self, *, root=None, api=None, receipt=None):
        if root is None:
            root_path = self._root
        elif isinstance(root, pathlib.Path):
            root_path = root
        else:
            root_path = pathlib.Path(root.name)

        if api is None:
            api = FakeProcessApi()

        manager = process.CollectorManager(
            snapshot_path=root_path / "snapshot.json",
            receipt_path=root_path / "receipt.json",
            script_path=self._script_path,
            interval_seconds=5.0,
            process_api=api,
        )

        if receipt:
            manager.receipt_path.parent.mkdir(parents=True, exist_ok=True)
            with manager.receipt_path.open("w") as f:
                json.dump(receipt, f)

        return manager

    def get_expected_command(self, manager):
        return tuple(manager.expected_command())

    def test_framework_python_reexec_still_owns_its_collector(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        started = manager.start()
        recorded = self.get_expected_command(manager)
        # Homebrew's framework build re-execs: same script and arguments, different argv[0].
        api.live[started.pid] = (
            "/opt/homebrew/Cellar/python@3.14/3.14.7/Frameworks/Python.framework/Versions/3.14/Resources/Python.app/Contents/MacOS/Python",
        ) + recorded[1:]
        self.assertTrue(manager.status(now=api.now()).owned)
        manager.stop()
        self.assertEqual(api.signals, [(started.pid, signal_module.SIGTERM)])

    def test_same_script_under_a_non_python_argv0_is_not_owned(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        started = manager.start()
        recorded = self.get_expected_command(manager)
        api.live[started.pid] = ("/bin/sh",) + recorded[1:]
        self.assertFalse(manager.status(now=api.now()).owned)
        api.live[started.pid] = (recorded[0],) + recorded[1:-1] + ("9.0",)
        self.assertFalse(manager.status(now=api.now()).owned)

    def test_start_refuses_receipt_owned_by_unrelated_live_process(self):
        api = FakeProcessApi(live={412: ("/usr/bin/python3", "unrelated.py")})
        manager = self.manager(api=api)
        receipt_data = {
            "pid": 412,
            "command": list(self.get_expected_command(manager)),
            "started_at": "2026-09-20T12:00:00Z"
        }
        manager = self.manager(api=api, receipt=receipt_data)
        with self.assertRaisesRegex(process.CollectorOwnershipError, "live process command does not match"):
            manager.start()
        self.assertEqual(api.signals, [])

    def test_stop_never_signals_unrelated_live_process(self):
        api = FakeProcessApi(live={412: ("/usr/bin/python3", "unrelated.py")})
        manager = self.manager(api=api)
        receipt_data = {
            "pid": 412,
            "command": list(self.get_expected_command(manager)),
            "started_at": "2026-09-20T12:00:00Z"
        }
        manager = self.manager(api=api, receipt=receipt_data)
        with self.assertRaises(process.CollectorOwnershipError):
            manager.stop()
        self.assertEqual(api.signals, [])

    def test_dead_receipt_is_replaced_and_start_is_idempotent(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        receipt_data = {
            "pid": 99,
            "command": list(self.get_expected_command(manager)),
            "started_at": "2026-09-20T12:00:00Z"
        }
        manager = self.manager(api=api, receipt=receipt_data)
        first = manager.start()
        second = manager.start()
        self.assertEqual(first.pid, second.pid)
        self.assertEqual(len(api.spawned), 1)

    def test_start_replaces_verified_collector_whose_command_drifted(self):
        stale = ("/usr/bin/python3", "system_metrics.py", "--snapshot", "/old/snapshot.json")
        api = FakeProcessApi(live={500: stale})
        manager = self.manager(
            api=api,
            receipt={"pid": 500, "command": list(stale), "started_at": "2026-09-20T12:00:00Z"},
        )
        manager.start()
        self.assertEqual(api.signals, [(500, signal_module.SIGTERM)])
        self.assertEqual(len(api.spawned), 1)
        self.assertEqual(api.spawned[0][1], self.get_expected_command(manager))
        self.assertEqual(tuple(manager._read_receipt().command), self.get_expected_command(manager))

    def test_start_spawns_with_expected_command(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.start()
        self.assertEqual(len(api.spawned), 1)
        pid, cmd = api.spawned[0]
        self.assertEqual(cmd[0], sys.executable)
        self.assertIn("system_metrics.py", cmd[1])
        self.assertIn("--snapshot", cmd)
        self.assertIn("--interval", cmd)
        self.assertIn("5.0", cmd)

    def test_stop_signals_owned_live_process(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        status = manager.start()
        api.signals = []
        manager.stop()
        self.assertEqual(len(api.signals), 1)
        pid, signum = api.signals[0]
        self.assertEqual(pid, status.pid)

    def test_status_reports_live_owned_collector(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.start()
        status = manager.status(now=api.now())
        self.assertIsNotNone(status.pid)
        self.assertTrue(status.running)
        self.assertTrue(status.owned)

    def test_status_reports_dead_collector(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.start()
        pid = manager._read_receipt().pid
        del api.live[pid]
        status = manager.status(now=api.now())
        self.assertEqual(status.pid, pid)
        self.assertFalse(status.running)

    def test_status_reports_snapshot_age(self):
        api = FakeProcessApi()
        root = tempfile.TemporaryDirectory()
        self.addCleanup(root.cleanup)
        root_path = pathlib.Path(root.name)

        manager = self.manager(root=root_path, api=api)
        snapshot = root_path / "snapshot.json"
        snapshot.parent.mkdir(parents=True, exist_ok=True)
        snapshot.write_text('{"test": true}')

        status = manager.status(now=api.now())
        self.assertIsNotNone(status.snapshot_age_seconds)
        self.assertEqual(status.signals["snapshot_available"], True)

    def test_status_reports_no_snapshot(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        status = manager.status(now=api.now())
        self.assertIsNone(status.snapshot_age_seconds)
        self.assertEqual(status.signals["snapshot_available"], False)

    def test_stop_sends_sigterm_then_waits_for_exit(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.start()
        initial_pid = manager._read_receipt().pid
        api.signals = []
        manager.stop()
        self.assertEqual(len(api.signals), 1)
        pid, signum = api.signals[0]
        self.assertEqual(signum, signal_module.SIGTERM)

    def test_stop_sends_sigkill_if_process_still_alive_after_term(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.start()
        initial_pid = manager._read_receipt().pid
        api.signals = []
        api.still_alive_after_term = True
        manager.stop()
        signals = [s for _, s in api.signals]
        self.assertIn(signal_module.SIGTERM, signals)
        self.assertIn(signal_module.SIGKILL, signals)

    def test_stop_fails_closed_if_process_still_alive_after_kill(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.start()
        api.signals = []
        api.always_alive = True
        with self.assertRaises(process.CollectorOwnershipError):
            manager.stop()

    def test_stop_signals_owned_collector_when_receipt_command_differs_from_recomputed_expected(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        spawned = manager.start()
        recorded_command = manager._read_receipt().command
        api.signals = []
        # The manager's live configuration changed after the collector was spawned
        # (e.g. a different --python-executable override on a later invocation),
        # so expected_command() no longer matches what was actually persisted in
        # the receipt. The live argv still exactly matches the receipt's command,
        # so ownership must still be recognized.
        manager.python_executable = "/usr/bin/python3-different-override"
        self.assertNotEqual(tuple(manager.expected_command()), recorded_command)
        manager.stop()
        self.assertEqual(len(api.signals), 1)
        pid, signum = api.signals[0]
        self.assertEqual(pid, spawned.pid)
        self.assertEqual(signum, signal_module.SIGTERM)

    def test_stop_recovers_gracefully_when_process_exits_exactly_at_term_boundary(self):
        log = FakeEventLog()
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.events = log
        manager.stop_timeout_seconds = 0.1
        manager.start()
        api.signals = []
        # Deterministic sequence: alive for the pre-signal check and for the single
        # 0.1s bounded-wait iteration, then dead exactly when the boundary recheck
        # runs immediately after the wait loop exits -- no KILL is ever sent.
        api.is_live_responses = [True, True, False]
        manager.stop()

        self.assertEqual([signum for _, signum in api.signals], [signal_module.SIGTERM])
        exit_events = [e for e in log.events if e["event"] == "exit"]
        self.assertEqual(len(exit_events), 1)
        self.assertEqual(exit_events[0]["ctx"]["reason"], "graceful")
        self.assertIsNone(manager._read_receipt())
        self.assertEqual(api.is_live_responses, [])

    def test_status_parses_snapshot_signals_cpu_memory_gpu(self):
        api = FakeProcessApi()
        root = tempfile.TemporaryDirectory()
        self.addCleanup(root.cleanup)
        root_path = pathlib.Path(root.name)

        manager = self.manager(root=root_path, api=api)
        snapshot = root_path / "snapshot.json"
        snapshot.parent.mkdir(parents=True, exist_ok=True)
        snapshot.write_text(json.dumps({
            "schema_version": 1,
            "collected_at": "2026-09-20T12:00:00Z",
            "signals": {
                "cpu": {"available": True, "percent": 35.5},
                "memory": {"available": True, "used_bytes": 100000, "percent": 50.0},
                "gpu": {"available": False, "error": {"code": "SampleUnavailable"}}
            }
        }))

        status = manager.status(now=api.now())
        self.assertEqual(status.signals.get("cpu"), True)
        self.assertEqual(status.signals.get("memory"), True)
        self.assertEqual(status.signals.get("gpu"), False)

    def test_status_handles_corrupt_snapshot_gracefully(self):
        api = FakeProcessApi()
        root = tempfile.TemporaryDirectory()
        self.addCleanup(root.cleanup)
        root_path = pathlib.Path(root.name)

        manager = self.manager(root=root_path, api=api)
        snapshot = root_path / "snapshot.json"
        snapshot.parent.mkdir(parents=True, exist_ok=True)
        snapshot.write_text("not valid json")

        status = manager.status(now=api.now())
        self.assertEqual(status.signals.get("cpu"), False)
        self.assertEqual(status.signals.get("memory"), False)
        self.assertEqual(status.signals.get("gpu"), False)

    def test_status_handles_missing_snapshot_fields(self):
        api = FakeProcessApi()
        root = tempfile.TemporaryDirectory()
        self.addCleanup(root.cleanup)
        root_path = pathlib.Path(root.name)

        manager = self.manager(root=root_path, api=api)
        snapshot = root_path / "snapshot.json"
        snapshot.parent.mkdir(parents=True, exist_ok=True)
        snapshot.write_text(json.dumps({
            "schema_version": 1,
            "signals": {}
        }))

        status = manager.status(now=api.now())
        self.assertEqual(status.signals.get("cpu"), False)
        self.assertEqual(status.signals.get("memory"), False)
        self.assertEqual(status.signals.get("gpu"), False)

    def test_collector_manager_accepts_event_log_callback(self):
        log = FakeEventLog()
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.events = log
        manager.start()
        self.assertTrue(len(log.events) > 0)

    def test_collector_manager_emits_spawn_event(self):
        log = FakeEventLog()
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.events = log
        manager.start()
        spawn_events = [e for e in log.events if e["event"] == "spawn"]
        self.assertEqual(len(spawn_events), 1)

    def test_event_log_does_not_contain_secrets_or_argv(self):
        log = FakeEventLog()
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.events = log
        manager.start()
        for event in log.events:
            ctx = event.get("ctx", {})
            ctx_str = json.dumps(ctx)
            self.assertNotIn("/opt/homebrew", ctx_str)
            self.assertNotIn("snapshot", ctx_str)
            self.assertNotIn("interval", ctx_str)

    def test_python_executable_defaults_to_sys_executable(self):
        import sys
        api = FakeProcessApi()
        manager = self.manager(api=api)
        expected = manager.expected_command()
        self.assertEqual(expected[0], sys.executable)

    def test_python_executable_override_changes_command(self):
        api = FakeProcessApi()
        manager = self.manager(api=api)
        manager.python_executable = "/usr/bin/python3"
        expected = manager.expected_command()
        self.assertEqual(expected[0], "/usr/bin/python3")


class ProcessApiProductionTest(unittest.TestCase):
    """Production-boundary tests for macOS KERN_PROCARGS2 sysctl parsing."""

    def _patch_and_call(self, buffer_data: bytes) -> tuple[str, ...] | None:
        """Patch ctypes.CDLL and sysctl to return buffer_data, then call ProcessApi.command_line()."""
        import unittest.mock

        class FakeSysctl:
            def __init__(self, buffer):
                self.buffer = buffer
                self.buffer_obj = ctypes.create_string_buffer(buffer)

            def __call__(self, mib, miblen, oldp, oldlenp, newp, newlen):
                # oldlenp is a ctypes.byref() CArgObject, matching production's real
                # pointer semantics; `_obj` is the underlying c_size_t it wraps.
                if oldp is None:
                    oldlenp._obj.value = len(self.buffer)
                    return 0
                if oldlenp._obj.value >= len(self.buffer):
                    ctypes.memmove(oldp, self.buffer_obj, len(self.buffer))
                    return 0
                return -1

        class FakeLibc:
            def __init__(self, buffer):
                self.sysctl = FakeSysctl(buffer)

        api = process.ProcessApi()
        with unittest.mock.patch("studio_system_metrics_process.ctypes.CDLL") as mock_cdll:
            mock_cdll.return_value = FakeLibc(buffer_data)
            return api.command_line(pid=1234)

    def test_command_line_with_execpath_and_spaces_in_all_args(self):
        """Parse correct KERN_PROCARGS2 layout with execpath and spaces in argv."""
        execpath = "/usr/bin/python3"
        cmd = (
            "/usr/bin/python3",
            "/path with spaces/system_metrics.py",
            "--snapshot",
            "/var/snapshot with spaces.json",
            "--interval",
            "5.0",
        )
        argc = len(cmd)
        execpath_bytes = execpath.encode("utf-8") + b"\x00"
        argv_bytes = b"\x00".join(arg.encode("utf-8") for arg in cmd) + b"\x00"
        buffer = argc.to_bytes(4, byteorder="little") + execpath_bytes + argv_bytes

        result = self._patch_and_call(buffer)
        self.assertEqual(result, cmd)
        self.assertIn(" ", result[1])
        self.assertIn(" ", result[3])

    def test_command_line_with_nul_padding_after_execpath(self):
        """Skip NUL padding between execpath and argv correctly."""
        execpath = "/bin/app"
        cmd = ("/bin/app", "--flag")
        argc = len(cmd)
        execpath_bytes = execpath.encode("utf-8") + b"\x00\x00\x00"
        argv_bytes = b"\x00".join(arg.encode("utf-8") for arg in cmd) + b"\x00"
        buffer = argc.to_bytes(4, byteorder="little") + execpath_bytes + argv_bytes

        result = self._patch_and_call(buffer)
        self.assertEqual(result, cmd)

    def test_command_line_first_sizing_call_failure(self):
        """Return None if first sysctl call (sizing) fails."""
        import unittest.mock

        class FailingSysctl:
            def __call__(self, mib, miblen, oldp, oldlenp, newp, newlen):
                return -1

        class FakeLibc:
            def __init__(self):
                self.sysctl = FailingSysctl()

        api = process.ProcessApi()
        with unittest.mock.patch("studio_system_metrics_process.ctypes.CDLL") as mock_cdll:
            mock_cdll.return_value = FakeLibc()
            result = api.command_line(pid=1234)

        self.assertIsNone(result)

    def test_command_line_second_call_failure(self):
        """Return None if second sysctl call (data retrieval) fails."""
        import unittest.mock

        buffer = b"\x01\x00\x00\x00/app\x00/bin/app\x00"

        class SelectiveFailSysctl:
            def __init__(self, buffer):
                self.buffer = buffer
                self.buffer_obj = ctypes.create_string_buffer(buffer)
                self.call_count = 0

            def __call__(self, mib, miblen, oldp, oldlenp, newp, newlen):
                self.call_count += 1
                if oldp is None:
                    oldlenp._obj.value = len(self.buffer)
                    return 0
                return -1

        class FakeLibc:
            def __init__(self, buffer):
                self.sysctl = SelectiveFailSysctl(buffer)

        api = process.ProcessApi()
        with unittest.mock.patch("studio_system_metrics_process.ctypes.CDLL") as mock_cdll:
            mock_cdll.return_value = FakeLibc(buffer)
            result = api.command_line(pid=1234)

        self.assertIsNone(result)

    def test_command_line_malformed_argc_zero(self):
        """Return None for argc=0."""
        buffer = b"\x00\x00\x00\x00/app\x00"
        result = self._patch_and_call(buffer)
        self.assertIsNone(result)

    def test_command_line_malformed_argc_negative(self):
        """Return None for negative argc."""
        buffer = b"\xff\xff\xff\xff/app\x00"
        result = self._patch_and_call(buffer)
        self.assertIsNone(result)

    def test_command_line_missing_execpath_terminator(self):
        """Return None if execpath is missing NUL terminator."""
        argc = 1
        execpath_bytes = b"/app"
        argv_bytes = b"/bin/app\x00"
        buffer = argc.to_bytes(4, byteorder="little") + execpath_bytes + argv_bytes

        result = self._patch_and_call(buffer)
        self.assertIsNone(result)

    def test_command_line_missing_argv_terminator(self):
        """Return None if an argv string is missing NUL terminator."""
        execpath = "/app"
        cmd = ("/bin/app", "--flag")
        argc = len(cmd)
        execpath_bytes = execpath.encode("utf-8") + b"\x00"
        argv_bytes = b"/bin/app\x00--flag"
        buffer = argc.to_bytes(4, byteorder="little") + execpath_bytes + argv_bytes

        result = self._patch_and_call(buffer)
        self.assertIsNone(result)

    def test_command_line_buffer_too_small(self):
        """Return None if buffer is too small to contain argc."""
        buffer = b"\x00\x00"
        result = self._patch_and_call(buffer)
        self.assertIsNone(result)

    def test_command_line_size_returned_correctly(self):
        """Use the byte count from second sysctl call, not original buffer size."""
        import unittest.mock

        full_buffer = b"\x02\x00\x00\x00/app\x00/usr/bin\x00python\x00"
        truncated_size = 20

        class SelectiveTruncateSysctl:
            def __init__(self):
                self.call_count = 0
                self.buffer_obj = ctypes.create_string_buffer(full_buffer)

            def __call__(self, mib, miblen, oldp, oldlenp, newp, newlen):
                self.call_count += 1
                if oldp is None:
                    oldlenp._obj.value = len(full_buffer)
                    return 0
                ctypes.memmove(oldp, self.buffer_obj, truncated_size)
                oldlenp._obj.value = truncated_size
                return 0

        class FakeLibc:
            def __init__(self):
                self.sysctl = SelectiveTruncateSysctl()

        api = process.ProcessApi()
        with unittest.mock.patch("studio_system_metrics_process.ctypes.CDLL") as mock_cdll:
            mock_cdll.return_value = FakeLibc()
            result = api.command_line(pid=1234)

        self.assertIsNone(result)


class ProcessApiMacOSIntegrationTest(unittest.TestCase):
    """Exercises the real libc/sysctl boundary against the current live process.

    Cross-checks against `ps`, an independent macOS source of the kernel's process
    table, rather than `sys.executable`: some interpreter builds re-exec into a
    different on-disk binary after startup (e.g. macOS framework Aqua support),
    which leaves `sys.executable` referring to the original launcher path.
    """

    @unittest.skipUnless(sys.platform == "darwin", "requires macOS KERN_PROCARGS2 sysctl")
    def test_command_line_matches_live_process_executable(self):
        import subprocess

        api = process.ProcessApi()
        pid = os.getpid()
        result = api.command_line(pid=pid)

        self.assertIsNotNone(result)
        self.assertGreater(len(result), 0)

        ps_executable = subprocess.run(
            ["/bin/ps", "-p", str(pid), "-o", "comm="],
            capture_output=True,
            text=True,
            timeout=5,
        ).stdout.strip()

        self.assertTrue(ps_executable)
        self.assertEqual(
            pathlib.Path(result[0]).resolve(),
            pathlib.Path(ps_executable).resolve(),
        )


if __name__ == "__main__":
    unittest.main()

import importlib.util
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "clop_service.py"
SPEC = importlib.util.spec_from_file_location("studio_clop_service", MODULE_PATH)
clop_service = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = clop_service
SPEC.loader.exec_module(clop_service)


class ClopServiceTests(unittest.TestCase):
    def config(self, root, **overrides):
        defaults = dict(
            source=root / "source",
            state=root / "state",
            host="127.0.0.1",
            port=18766,
            clop_bin=root / "bin" / "clop",
            max_bytes=2 * 1024 * 1024 * 1024,
            timeout=900,
        )
        defaults.update(overrides)
        return clop_service.ServiceConfig(**defaults)

    def write_source(self, root):
        source = root / "source" / "tools" / "studio" / "clop-service"
        source.mkdir(parents=True)
        (source / "server.py").write_text("server", encoding="utf-8")
        return source

    def test_private_bridge_and_loopback_binds_are_accepted(self):
        clop_service._validate_private_bind("192.168.64.1")
        clop_service._validate_private_bind("127.0.0.1")

    def test_public_and_unspecified_binds_are_rejected(self):
        for host in ("0.0.0.0", "8.8.8.8", "example.com"):
            with self.subTest(host=host), self.assertRaises(clop_service.ServiceFailure):
                clop_service._validate_private_bind(host)

    def test_deploy_copies_only_the_server_module(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            source = self.write_source(root)
            (source / "unrelated.py").write_text("must not copy", encoding="utf-8")
            config = self.config(root)
            digest = clop_service.deploy(config)
            self.assertEqual(
                sorted(path.name for path in config.runtime.iterdir()),
                ["server.py"],
            )
            self.assertEqual(len(digest), 64)

    def test_owned_process_matches_script_path_not_interpreter_path(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            # The receipt records the Homebrew shim; ps shows the resolved Cellar interpreter.
            command = (
                "/opt/homebrew/Cellar/python@3.14/3.14.0/Frameworks/Python.framework/Versions/3.14/"
                f"Resources/Python.app/Contents/MacOS/Python {config.runtime / 'server.py'}"
            )
            payload = {"pid": 123, "python": "/opt/homebrew/bin/python3"}
            with mock.patch.object(clop_service, "_process_command", return_value=command):
                self.assertEqual(clop_service._owned_process(config, payload), 123)

    def test_status_and_down_find_a_bridge_running_under_a_different_interpreter_path(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            command = f"/opt/homebrew/Cellar/python@3.14/Python.app/Contents/MacOS/Python {config.runtime / 'server.py'}"
            payload = {"pid": 123, "python": "/opt/homebrew/bin/python3", "source_digest": "d"}
            alive = iter([command, command])
            with (
                mock.patch.object(clop_service, "_read_pid", return_value=payload),
                mock.patch.object(clop_service, "_process_command", side_effect=lambda pid: next(alive, "")),
                mock.patch.object(clop_service, "_health", return_value={"ok": True, "clop": "3.5.0"}),
                mock.patch.object(clop_service.os, "kill") as kill,
                mock.patch.object(clop_service.time, "sleep"),
            ):
                found = clop_service.status(config)
                self.assertTrue(found["running"])
                self.assertEqual(found["pid"], 123)
                clop_service.stop(config, clop_service.EventLog(config.state))
            kill.assert_called_once_with(123, clop_service.signal.SIGTERM)

    def test_up_refuses_to_spawn_when_address_already_answers(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.write_source(root)
            config = self.config(root)
            current = {"healthy": False, "running": False, "pid": None}
            with (
                mock.patch.object(clop_service, "status", return_value=current),
                mock.patch.object(clop_service, "_read_pid", return_value=None),
                mock.patch.object(clop_service, "_health", return_value={"ok": True}),
                mock.patch.object(clop_service.subprocess, "Popen") as popen,
            ):
                with self.assertRaisesRegex(clop_service.ServiceFailure, "already running"):
                    clop_service.up(config, clop_service.EventLog(config.state))
            popen.assert_not_called()
            self.assertFalse(config.pid_file.exists())

    def test_repeat_up_on_a_live_bridge_with_a_stale_interpreter_receipt_is_a_noop(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.write_source(root)
            config = self.config(root)
            digest = clop_service.deploy(config)
            clop_service._atomic_json(
                config.pid_file,
                {"pid": 123, "python": "/opt/homebrew/bin/python3", "source_digest": digest},
            )
            command = f"/opt/homebrew/Cellar/python@3.14/Python.app/Contents/MacOS/Python {config.runtime / 'server.py'}"
            with (
                mock.patch.object(clop_service, "_process_command", return_value=command),
                mock.patch.object(clop_service, "_health", return_value={"ok": True}),
                mock.patch.object(clop_service, "stop") as stop,
                mock.patch.object(clop_service.subprocess, "Popen") as popen,
            ):
                result = clop_service.up(config, clop_service.EventLog(config.state))
            self.assertTrue(result["running"])
            self.assertTrue(result["healthy"])
            stop.assert_not_called()
            popen.assert_not_called()

    def test_same_server_basename_at_wrong_path_is_foreign(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            command = "/usr/bin/python3 /tmp/foreign/server.py"
            payload = {"pid": 123, "python": "/usr/bin/python3"}
            with mock.patch.object(clop_service, "_process_command", return_value=command):
                self.assertIsNone(clop_service._owned_process(config, payload))

    def test_dead_process_is_not_owned(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            with mock.patch.object(clop_service, "_process_command", return_value=""):
                self.assertIsNone(clop_service._owned_process(config, {"pid": 123, "python": "/usr/bin/python3"}))

    def test_up_is_idempotent_when_health_and_source_digest_match(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            current = {"healthy": True, "running": True, "pid": 123}
            with (
                mock.patch.object(clop_service, "deploy", return_value="same-digest"),
                mock.patch.object(clop_service, "status", return_value=current),
                mock.patch.object(clop_service, "_read_pid", return_value={"source_digest": "same-digest"}),
                mock.patch.object(clop_service, "stop") as stop,
                mock.patch.object(clop_service.subprocess, "Popen") as popen,
            ):
                result = clop_service.up(config, clop_service.EventLog(config.state))
            self.assertIs(result, current)
            stop.assert_not_called()
            popen.assert_not_called()

    def test_up_requires_the_clop_binary_to_exist(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.write_source(root)
            config = self.config(root)
            current = {"healthy": False, "running": False, "pid": None}
            with (
                mock.patch.object(clop_service, "status", return_value=current),
                mock.patch.object(clop_service, "_read_pid", return_value=None),
            ):
                with self.assertRaises(clop_service.ServiceFailure):
                    clop_service.up(config, clop_service.EventLog(config.state))

    def test_up_restarts_owned_healthy_process_when_source_digest_changes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.write_source(root)
            config = self.config(root)
            config.clop_bin.parent.mkdir(parents=True)
            config.clop_bin.touch()
            current = {"healthy": True, "running": True, "pid": 123}
            restarted = {"healthy": True, "running": True, "pid": 789}
            process = mock.Mock(pid=789)
            process.poll.return_value = None
            with (
                mock.patch.object(clop_service, "status", side_effect=[current, restarted]),
                mock.patch.object(clop_service, "_read_pid", return_value={"source_digest": "old-digest"}),
                mock.patch.object(clop_service, "stop") as stop,
                mock.patch.object(clop_service, "_health", side_effect=[None, {"ok": True}]),
                mock.patch.object(clop_service.subprocess, "Popen", return_value=process) as popen,
            ):
                result = clop_service.up(config, clop_service.EventLog(config.state))
            self.assertIs(result, restarted)
            stop.assert_called_once()
            popen.assert_called_once()

    def test_stop_does_not_signal_a_foreign_pid(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            payload = {"pid": 456, "python": "/usr/bin/python3"}
            with (
                mock.patch.object(clop_service, "_read_pid", return_value=payload),
                mock.patch.object(clop_service, "_process_command", return_value="/tmp/python /tmp/server.py"),
                mock.patch.object(clop_service.os, "kill") as kill,
            ):
                result = clop_service.stop(config, clop_service.EventLog(config.state))
            self.assertFalse(result["running"])
            kill.assert_not_called()

    def test_verify_raises_on_persistent_unhealthy_service(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            clock = iter([0] + [100] * 20)
            with (
                mock.patch.object(clop_service, "_health", return_value=None),
                mock.patch.object(clop_service.time, "monotonic", side_effect=lambda: next(clock)),
                mock.patch.object(clop_service.time, "sleep"),
            ):
                with self.assertRaises(clop_service.ServiceFailure):
                    clop_service.verify(config, timeout=1)

    def test_process_identity_requires_a_python_interpreter_and_the_script_as_the_last_argument(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary) / "Studio Data")
            script = str(config.runtime / "server.py")
            owned = [
                f"/usr/bin/python3 {script}",
                f"/opt/homebrew/Cellar/python@3.14/Python.app/Contents/MacOS/Python -u {script}",
                f"/Applications/My Tools/Python.app/Contents/MacOS/Python -u {script}",
            ]
            foreign = [
                f"tail -f {script}",
                f"/usr/bin/vim {script}",
                f"/usr/bin/python3 {script} --extra",
                f"/usr/bin/python3 /tmp/other{script}",
                f"/usr/bin/python3 -c pass {script}",
                f"/usr/bin/python3 -m http.server {script}",
                f"/usr/bin/python3 -cpass {script}",
            ]
            for command in owned:
                with self.subTest(command=command), mock.patch.object(clop_service, "_process_command", return_value=command):
                    self.assertEqual(clop_service._owned_process(config, {"pid": 9}), 9)
            for command in foreign:
                with self.subTest(command=command), mock.patch.object(clop_service, "_process_command", return_value=command):
                    self.assertIsNone(clop_service._owned_process(config, {"pid": 9}))

    def test_overlapping_up_calls_are_serialized_by_the_state_lock(self):
        import fcntl
        import threading
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            lock_path = config.state / "run" / "clop-service.lock"
            lock_path.parent.mkdir(parents=True)
            entered = threading.Event()
            with lock_path.open("a") as holder:
                fcntl.flock(holder.fileno(), fcntl.LOCK_EX)

                def contender():
                    with clop_service._exclusive(config):
                        entered.set()

                thread = threading.Thread(target=contender)
                thread.start()
                self.assertFalse(entered.wait(0.3))
                fcntl.flock(holder.fileno(), fcntl.LOCK_UN)
            thread.join(5)
            self.assertTrue(entered.is_set())


if __name__ == "__main__":
    unittest.main()

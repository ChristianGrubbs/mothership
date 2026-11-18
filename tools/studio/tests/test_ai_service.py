import importlib.util
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "ai_service.py"
SPEC = importlib.util.spec_from_file_location("studio_ai_service", MODULE_PATH)
ai_service = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = ai_service
SPEC.loader.exec_module(ai_service)


class AiServiceTests(unittest.TestCase):
    def config(self, root, **overrides):
        defaults = dict(
            source=root / "source",
            state=root / "state",
            host="127.0.0.1",
            port=18767,
        )
        defaults.update(overrides)
        return ai_service.ServiceConfig(**defaults)

    def write_source(self, root):
        source = root / "source" / "tools" / "studio" / "ai-service"
        source.mkdir(parents=True)
        (source / "server.py").write_text("server", encoding="utf-8")
        return source

    def test_rejects_public_and_unspecified_binds(self):
        for host in ("0.0.0.0", "8.8.8.8", "not-an-ip"):
            with self.assertRaises(ai_service.ServiceFailure):
                ai_service._validate_private_bind(host)
        ai_service._validate_private_bind("192.168.64.1")
        ai_service._validate_private_bind("127.0.0.1")

    def test_deploy_copies_only_the_server_module(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            source = self.write_source(root)
            (source / "unrelated.py").write_text("must not copy", encoding="utf-8")
            config = self.config(root)
            digest = ai_service.deploy(config)
            self.assertEqual(
                sorted(path.name for path in config.runtime.iterdir()),
                ["server.py"],
            )
            self.assertEqual(len(digest), 64)

    def test_owned_process_matches_script_path_not_interpreter_path(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            command = (
                "/Library/Developer/CommandLineTools/Library/Frameworks/Python3.framework/"
                f"Versions/3.9/Resources/Python.app/Contents/MacOS/Python {config.runtime / 'server.py'}"
            )
            payload = {"pid": 123, "python": "/usr/bin/python3"}
            with mock.patch.object(ai_service, "_process_command", return_value=command):
                self.assertEqual(ai_service._owned_process(config, payload), 123)

    def test_up_refuses_to_spawn_when_address_already_answers(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.write_source(root)
            config = self.config(root)
            current = {"healthy": False, "running": False, "pid": None}
            with (
                mock.patch.object(ai_service, "status", return_value=current),
                mock.patch.object(ai_service, "_read_pid", return_value=None),
                mock.patch.object(ai_service, "_live", return_value={"ok": True}),
                mock.patch.object(ai_service.subprocess, "Popen") as popen,
            ):
                with self.assertRaisesRegex(ai_service.ServiceFailure, "already running"):
                    ai_service.up(config, ai_service.EventLog(config.state))
            popen.assert_not_called()
            self.assertFalse(config.pid_file.exists())

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
                with self.subTest(command=command), mock.patch.object(ai_service, "_process_command", return_value=command):
                    self.assertEqual(ai_service._owned_process(config, {"pid": 9}), 9)
            for command in foreign:
                with self.subTest(command=command), mock.patch.object(ai_service, "_process_command", return_value=command):
                    self.assertIsNone(ai_service._owned_process(config, {"pid": 9}))

    def test_overlapping_up_calls_are_serialized_by_the_state_lock(self):
        import fcntl
        import threading
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            lock_path = config.state / "run" / "ai-service.lock"
            lock_path.parent.mkdir(parents=True)
            entered = threading.Event()
            with lock_path.open("a") as holder:
                fcntl.flock(holder.fileno(), fcntl.LOCK_EX)

                def contender():
                    with ai_service._exclusive(config):
                        entered.set()

                thread = threading.Thread(target=contender)
                thread.start()
                self.assertFalse(entered.wait(0.3))
                fcntl.flock(holder.fileno(), fcntl.LOCK_UN)
            thread.join(5)
            self.assertTrue(entered.is_set())

    def test_startup_liveness_does_not_depend_on_ollama_health(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.write_source(root)
            config = self.config(root)
            process = mock.Mock(pid=789)
            process.poll.return_value = None
            current = {"healthy": False, "running": False, "pid": None}
            with (
                mock.patch.object(ai_service, "status", return_value=current),
                mock.patch.object(ai_service, "_read_pid", return_value=None),
                mock.patch.object(ai_service, "_live", side_effect=[None, {"ok": True}]),
                mock.patch.object(ai_service, "_health", side_effect=AssertionError("up must not wait on /health")),
                mock.patch.object(ai_service.subprocess, "Popen", return_value=process),
            ):
                ai_service.up(config, ai_service.EventLog(config.state))

    def test_same_server_basename_at_wrong_path_is_foreign(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            command = "/usr/bin/python3 /tmp/foreign/server.py"
            payload = {"pid": 123, "python": "/usr/bin/python3"}
            with mock.patch.object(ai_service, "_process_command", return_value=command):
                self.assertIsNone(ai_service._owned_process(config, payload))

    def test_dead_process_is_not_owned(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            with mock.patch.object(ai_service, "_process_command", return_value=""):
                self.assertIsNone(ai_service._owned_process(config, {"pid": 123, "python": "/usr/bin/python3"}))

    def test_up_is_idempotent_when_health_and_source_digest_match(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            current = {"healthy": True, "running": True, "pid": 123}
            with (
                mock.patch.object(ai_service, "deploy", return_value="same-digest"),
                mock.patch.object(ai_service, "status", return_value=current),
                mock.patch.object(ai_service, "_read_pid", return_value={"source_digest": "same-digest"}),
                mock.patch.object(ai_service, "stop") as stop,
                mock.patch.object(ai_service.subprocess, "Popen") as popen,
            ):
                result = ai_service.up(config, ai_service.EventLog(config.state))
            self.assertIs(result, current)
            stop.assert_not_called()
            popen.assert_not_called()

    def test_up_restarts_owned_healthy_process_when_source_digest_changes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.write_source(root)
            config = self.config(root)
            current = {"healthy": True, "running": True, "pid": 123}
            restarted = {"healthy": True, "running": True, "pid": 789}
            process = mock.Mock(pid=789)
            process.poll.return_value = None
            with (
                mock.patch.object(ai_service, "status", side_effect=[current, restarted]),
                mock.patch.object(ai_service, "_read_pid", return_value={"source_digest": "old-digest"}),
                mock.patch.object(ai_service, "stop") as stop,
                mock.patch.object(ai_service, "_live", side_effect=[None, {"ok": True}]),
                mock.patch.object(ai_service.subprocess, "Popen", return_value=process) as popen,
            ):
                result = ai_service.up(config, ai_service.EventLog(config.state))
            self.assertIs(result, restarted)
            stop.assert_called_once()
            popen.assert_called_once()

    def test_stop_does_not_signal_a_foreign_pid(self):
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            payload = {"pid": 456, "python": "/usr/bin/python3"}
            with (
                mock.patch.object(ai_service, "_read_pid", return_value=payload),
                mock.patch.object(ai_service, "_process_command", return_value="/tmp/python /tmp/server.py"),
                mock.patch.object(ai_service.os, "kill") as kill,
            ):
                result = ai_service.stop(config, ai_service.EventLog(config.state))
            self.assertFalse(result["running"])
            kill.assert_not_called()

    def test_verify_requires_both_models(self):
        body = {"ok": True, "ollama": True, "models": {"caption": True, "embed": False}}
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            with (
                mock.patch.object(ai_service, "_health", return_value=body),
                mock.patch.object(ai_service.time, "sleep"),
            ):
                result = ai_service.verify(config, timeout=0)
            self.assertFalse(result["ok"])
            self.assertEqual(result["health"], body)

    def test_verify_succeeds_when_ollama_and_both_models_are_ready(self):
        body = {"ok": True, "ollama": True, "models": {"caption": True, "embed": True}}
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            with mock.patch.object(ai_service, "_health", return_value=body):
                self.assertTrue(ai_service.verify(config, timeout=1)["ok"])

    def test_failed_public_check_fails_verify(self):
        local = {"ok": True, "ollama": True, "models": {"caption": True, "embed": True}}
        with tempfile.TemporaryDirectory() as temporary:
            config = self.config(pathlib.Path(temporary))
            with (
                mock.patch.object(ai_service, "_health", return_value=local),
                mock.patch.object(ai_service, "_fetch_health", return_value=None) as fetch,
            ):
                result = ai_service.verify(config, timeout=1, public_url="https://example.invalid/")
            fetch.assert_called_once_with("https://example.invalid", timeout=10)
            self.assertEqual(result["public"], {"ok": False})
            self.assertFalse(result["ok"])


class FetchHealthUserAgentTests(unittest.TestCase):
    def test_health_check_is_not_refused_as_a_default_python_client(self):
        import json
        import threading
        from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

        class CloudflareLike(BaseHTTPRequestHandler):
            def log_message(self, *args):
                return

            def do_GET(self):
                # Cloudflare's bot protection answers urllib's default agent with 403.
                refused = self.headers.get("User-Agent", "").startswith("Python-urllib")
                body = json.dumps({"error": "forbidden"} if refused else {"ok": True}).encode()
                self.send_response(403 if refused else 200)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        server = ThreadingHTTPServer(("127.0.0.1", 0), CloudflareLike)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        try:
            payload = ai_service._fetch_health(f"http://127.0.0.1:{server.server_port}", timeout=5)
        finally:
            server.shutdown()
            server.server_close()

        self.assertEqual(payload, {"ok": True})


if __name__ == "__main__":
    unittest.main()

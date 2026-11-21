"""The launcher as an agent sees it: real processes over stdio, with tests/fake_container.py as `container`."""
import hashlib
import importlib.util
import json
import os
import queue
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
LAUNCHER = os.path.join(os.path.dirname(HERE), "launcher.py")
MANIFEST = os.path.join(os.path.dirname(HERE), "tools_manifest.json")
FAKE_CONTAINER = os.path.join(HERE, "fake_container.py")
LIBRARY_TOOLS = [
    "library_search", "library_list_folders", "library_create_folder", "library_get_asset",
    "library_list_stack", "library_fetch", "library_save", "library_update", "library_vectorize",
    "library_vectorization",
]
REQUEST_ID = "00000000-0000-4000-8000-000000000001"


def sha256(data):
    return hashlib.sha256(data).hexdigest()


class Session:
    """One agent session talking to one launcher."""

    def __init__(self, env):
        self.process = subprocess.Popen(
            [sys.executable, "-I", LAUNCHER], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=env
        )
        self.replies = queue.Queue()
        self.next_id = 0
        threading.Thread(target=self._read, daemon=True).start()

    def _read(self):
        for line in self.process.stdout:
            self.replies.put(json.loads(line))

    def start(self, method, params=None):
        self.next_id += 1
        message = {"jsonrpc": "2.0", "id": self.next_id, "method": method, "params": params or {}}
        self.process.stdin.write(json.dumps(message).encode("utf-8") + b"\n")
        self.process.stdin.flush()
        return self.next_id

    def wait(self, request_id, timeout=20):
        deadline = time.monotonic() + timeout
        while True:
            reply = self.replies.get(timeout=max(0.01, deadline - time.monotonic()))
            if reply.get("id") == request_id:
                return reply

    def request(self, method, params=None, timeout=20):
        return self.wait(request_id=self.start(method=method, params=params), timeout=timeout)

    def call(self, name, arguments=None, timeout=20):
        return self.request(method="tools/call", params={"name": name, "arguments": arguments or {}}, timeout=timeout)["result"]

    def close(self):
        try:
            self.process.stdin.close()
        except OSError:
            pass
        self.process.wait(timeout=20)
        self.process.stdout.close()


class LauncherCase(unittest.TestCase):
    def setUp(self):
        os.chmod(FAKE_CONTAINER, os.stat(FAKE_CONTAINER).st_mode | stat.S_IXUSR)
        self.root = os.path.realpath(tempfile.mkdtemp(prefix="mcp-launcher-"))
        self.addCleanup(shutil.rmtree, self.root, True)
        self.state = self._folder("state")
        self.staging = os.path.join(self._folder("container-tmp"), "mothership-mcp")
        self.storage = self._folder("storage")
        self.mac = self._folder("mac")
        self.fetch_dir = os.path.join(self.root, "default-fetch")
        self.log_path = os.path.join(self.root, "logs", "launcher.jsonl")
        self.env = dict(
            os.environ,
            MOTHERSHIP_MCP_CONTAINER_BIN=FAKE_CONTAINER,
            MOTHERSHIP_MCP_CONTAINER_STAGING=self.staging,
            MOTHERSHIP_MCP_STATE_DIR=self._folder("launcher-state"),
            MOTHERSHIP_MCP_LAUNCHER_LOG_PATH=self.log_path,
            MOTHERSHIP_MCP_FETCH_DIR=self.fetch_dir,
            MOTHERSHIP_MCP_IDLE_SECONDS="0.5",
            FAKE_CONTAINER_STATE=self.state,
            FAKE_STAGING_ROOT=self.staging,
            FAKE_STORAGE_DIR=self.storage,
            FAKE_MANIFEST=MANIFEST,
        )
        self.env.pop("MOTHERSHIP_MCP_LAUNCHER_LOG", None)

    def _folder(self, name):
        path = os.path.join(self.root, name)
        os.makedirs(path)
        return path

    def session(self, **settings):
        session = Session(env=dict(self.env, **settings))
        self.addCleanup(session.close)
        return session

    def set_flag(self, name):
        open(os.path.join(self.state, name), "w", encoding="utf-8").close()

    def lines(self, name):
        path = os.path.join(self.state, name)
        if not os.path.exists(path):
            return []
        with open(path, "r", encoding="utf-8") as handle:
            return handle.read().splitlines()

    def php_starts(self):
        return len(self.lines("starts"))

    def php_running(self):
        running = os.path.join(self.state, "running")
        return len(os.listdir(running)) if os.path.isdir(running) else 0

    def wait_until(self, check, timeout=10):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if check():
                return True
            time.sleep(0.05)
        return check()

    def mac_file(self, name, data=b"PNG-bytes"):
        path = os.path.join(self.mac, name)
        with open(path, "wb") as handle:
            handle.write(data)
        return path

    def staging_left(self):
        return os.listdir(self.staging) if os.path.isdir(self.staging) else []

    def logged_events(self):
        if not os.path.exists(self.log_path):
            return []
        with open(self.log_path, "r", encoding="utf-8") as handle:
            return [json.loads(line)["event"] for line in handle.read().splitlines()]

    def serve_fetch(self, data):
        with open(os.path.join(self.state, "fetch_bytes"), "wb") as handle:
            handle.write(data)

    def save_arguments(self, path):
        return {"local_path": path, "folder_id": 3, "producer": {"tool": "blender"}, "request_id": REQUEST_ID}


class ProtocolTest(LauncherCase):
    def test_answers_initialize_and_tools_list_without_starting_php(self):
        session = self.session()
        hello = session.request("initialize", {"protocolVersion": "2025-06-18", "capabilities": {}})["result"]
        tools = session.request("tools/list")["result"]["tools"]

        self.assertEqual("2025-06-18", hello["protocolVersion"])
        self.assertEqual("The Mothership", hello["serverInfo"]["name"])
        self.assertIn("library_save", hello["instructions"])
        self.assertEqual(LIBRARY_TOOLS, [tool["name"] for tool in tools])
        time.sleep(0.6)
        self.assertEqual(0, self.php_starts())

    def test_answers_malformed_input_with_a_parse_error_and_keeps_serving(self):
        session = self.session()
        session.process.stdin.write(b"{not json\n")
        session.process.stdin.flush()

        self.assertEqual(-32700, session.replies.get(timeout=10)["error"]["code"])
        self.assertEqual({}, session.request("ping")["result"])

    def test_offers_the_newest_version_when_the_client_asks_for_an_unknown_one(self):
        hello = self.session().request("initialize", {"protocolVersion": "1999-01-01"})["result"]
        self.assertEqual("2025-11-25", hello["protocolVersion"])

    def test_answers_ping_and_rejects_unknown_methods(self):
        session = self.session()
        self.assertEqual({}, session.request("ping")["result"])
        self.assertEqual(-32601, session.request("resources/list")["error"]["code"])


class LifecycleTest(LauncherCase):
    def test_first_call_starts_php_once_and_the_next_call_reuses_it(self):
        session = self.session(MOTHERSHIP_MCP_IDLE_SECONDS="30")
        self.assertEqual("Renders", session.call("library_list_folders")["structuredContent"]["folders"][0]["name"])
        session.call("library_list_folders")
        self.assertEqual(1, self.php_starts())

    def test_stops_php_when_idle_and_starts_it_again_for_the_next_call(self):
        session = self.session()
        session.call("library_list_folders")
        self.assertTrue(self.wait_until(lambda: self.php_running() == 0))

        session.call("library_list_folders")
        self.assertEqual(2, self.php_starts())

    def test_a_server_that_dies_mid_call_is_a_tool_error_and_the_next_call_recovers(self):
        self.set_flag("crash_on_update")
        session = self.session()
        result = session.call("library_update", {"asset_id": 1})
        self.assertTrue(result["isError"])
        self.assertIn("stopped during this call", result["content"][0]["text"])

        self.assertNotIn("isError", session.call("library_list_folders"))
        self.assertEqual(2, self.php_starts())

    def test_a_stopped_stack_is_a_tool_error_and_the_launcher_stays_up(self):
        self.set_flag("stack_down")
        session = self.session()
        result = session.call("library_list_folders")
        self.assertTrue(result["isError"])
        self.assertIn("not answering", result["content"][0]["text"])
        self.assertEqual({}, session.request("ping")["result"])

    def test_shrinks_back_to_a_fresh_process_after_idle_and_keeps_the_conversation(self):
        session = self.session(MOTHERSHIP_MCP_LAUNCHER_LOG="1")
        session.request("initialize", {"protocolVersion": "2025-06-18"})
        session.call("library_list_folders")
        self.assertTrue(self.wait_until(lambda: "launcher.recycle" in self.logged_events()))
        time.sleep(0.3)

        self.assertIsNone(session.process.poll())
        self.assertEqual(LIBRARY_TOOLS, [tool["name"] for tool in session.request("tools/list")["result"]["tools"]])
        self.assertNotIn("isError", session.call("library_list_folders"))
        self.assertEqual(["2025-06-18", "2025-06-18"], self.lines("handshakes"))

    def test_a_message_sent_while_it_shrinks_is_still_answered(self):
        session = self.session(MOTHERSHIP_MCP_IDLE_SECONDS="0.05")
        session.call("library_list_folders")
        for delay in (0.1, 0.2, 0.3, 0.35, 0.4, 0.45, 0.5, 0.6):
            time.sleep(delay)
            self.assertEqual({}, session.request("ping", timeout=10)["result"])
            self.assertNotIn("isError", session.call("library_list_folders"))

    def test_a_call_longer_than_the_idle_period_is_not_interrupted(self):
        session = self.session(MOTHERSHIP_MCP_IDLE_SECONDS="0.1")
        self.assertNotIn("isError", session.call("library_search", {"query": "sleep:1"}))

    def test_php_stops_when_the_agent_leaves(self):
        session = self.session(MOTHERSHIP_MCP_IDLE_SECONDS="30")
        session.call("library_list_folders")
        self.assertEqual(1, self.php_running())

        session.close()
        self.assertTrue(self.wait_until(lambda: self.php_running() == 0))


class CapTest(LauncherCase):
    def test_sessions_queue_so_the_cap_is_never_passed(self):
        sessions = [self.session(MOTHERSHIP_MCP_MAX_SERVERS="1", MOTHERSHIP_MCP_IDLE_SECONDS="0.1") for _ in range(3)]
        pending = [(session, session.start("tools/call", {"name": "library_search", "arguments": {"query": "sleep:0.3"}})) for session in sessions]

        for session, request_id in pending:
            self.assertNotIn("isError", session.wait(request_id=request_id, timeout=30)["result"])
        self.assertEqual(3, self.php_starts())
        self.assertEqual({"1"}, set(self.lines("peaks")))

    def test_a_full_queue_answers_busy_instead_of_hanging(self):
        settings = {"MOTHERSHIP_MCP_MAX_SERVERS": "1", "MOTHERSHIP_MCP_QUEUE_SECONDS": "0.4", "MOTHERSHIP_MCP_IDLE_SECONDS": "30"}
        holder = self.session(**settings)
        holder.call("library_list_folders")

        result = self.session(**settings).call("library_list_folders")
        self.assertTrue(result["isError"])
        self.assertIn("busy", result["content"][0]["text"])
        self.assertEqual(1, self.php_starts())

    def test_a_killed_launcher_gives_its_slot_back(self):
        settings = {"MOTHERSHIP_MCP_MAX_SERVERS": "1", "MOTHERSHIP_MCP_IDLE_SECONDS": "30"}
        holder = self.session(**settings)
        holder.call("library_list_folders")
        holder.process.send_signal(signal.SIGKILL)
        holder.process.wait(timeout=10)

        self.assertNotIn("isError", self.session(**settings).call("library_list_folders"))


class SaveTest(LauncherCase):
    def test_saves_a_mac_file_with_its_own_name_and_clears_the_staged_copy(self):
        path = self.mac_file("it's a $HOME render (1).png", b"PNG-bytes" * 1000)
        result = self.session().call("library_save", self.save_arguments(path=path))

        self.assertEqual(7, result["structuredContent"]["asset_id"])
        saved = json.loads(self.lines("saved.jsonl")[0])
        self.assertEqual("it's a $HOME render (1).png", saved["name"])
        self.assertEqual(sha256(b"PNG-bytes" * 1000), saved["sha256"])
        self.assertEqual([], self.staging_left())

    def test_a_link_saves_under_the_real_file_name_as_the_server_does(self):
        target = self.mac_file("real.png")
        link = os.path.join(self.mac, "link.png")
        os.symlink(target, link)

        self.session().call("library_save", self.save_arguments(path=link))
        self.assertEqual("real.png", json.loads(self.lines("saved.jsonl")[0])["name"])

    def test_refuses_what_the_server_refuses_without_starting_php(self):
        session = self.session(MOTHERSHIP_MCP_MAX_FILE_BYTES="4")
        cases = {
            "relative/render.png": "must be an absolute path",
            os.path.join(self.mac, "missing.png"): "There is no file",
            self.mac: "There is no file",
            self.mac_file("empty.png", b""): "is empty",
            self.mac_file("large.png", b"12345"): "the library limit is",
        }
        for path, expected in cases.items():
            result = session.call("library_save", self.save_arguments(path=path))
            self.assertTrue(result["isError"], path)
            self.assertIn(expected, result["content"][0]["text"])
        self.assertEqual(0, self.php_starts())

    def test_a_copy_that_arrives_different_is_never_saved(self):
        self.set_flag("corrupt_receive")
        result = self.session().call("library_save", self.save_arguments(path=self.mac_file("render.png")))

        self.assertTrue(result["isError"])
        self.assertIn("did not match", result["content"][0]["text"])
        self.assertEqual([], self.lines("saved.jsonl"))
        self.assertNotIn("library_save", self.lines("calls"))
        self.assertEqual([], self.staging_left())


class FetchTest(LauncherCase):
    def destination(self):
        return os.path.join(self.mac, "out")

    def part_files(self):
        return [name for name in os.listdir(self.destination()) if name.endswith(".mothership-part")]

    def test_leaves_a_verified_copy_at_a_mac_path_in_both_answer_forms(self):
        self.serve_fetch(b"PNG-out" * 1000)
        result = self.session().call("library_fetch", {"asset_id": 7, "dest_dir": self.destination()})

        expected = os.path.join(self.destination(), "render.png")
        self.assertEqual(expected, result["structuredContent"]["path"])
        self.assertEqual(expected, json.loads(result["content"][0]["text"])["path"])
        self.assertFalse(result["structuredContent"]["reused"])
        with open(expected, "rb") as handle:
            self.assertEqual(sha256(b"PNG-out" * 1000), sha256(handle.read()))
        self.assertEqual([], self.part_files())
        self.assertEqual([], self.staging_left())

    def test_uses_the_default_folder_when_no_dest_dir_is_named(self):
        self.serve_fetch(b"PNG-out")
        result = self.session().call("library_fetch", {"asset_id": 7})
        self.assertEqual(os.path.join(self.fetch_dir, "render.png"), result["structuredContent"]["path"])

    def test_never_overwrites_a_different_file(self):
        self.serve_fetch(b"PNG-out")
        os.makedirs(self.destination())
        existing = os.path.join(self.destination(), "render.png")
        with open(existing, "wb") as handle:
            handle.write(b"the agent's own work")

        result = self.session().call("library_fetch", {"asset_id": 7, "dest_dir": self.destination()})

        self.assertEqual(os.path.join(self.destination(), "render-2.png"), result["structuredContent"]["path"])
        self.assertEqual("render-2.png", result["structuredContent"]["name"])
        with open(existing, "rb") as handle:
            self.assertEqual(b"the agent's own work", handle.read())

    def test_reuses_an_identical_file(self):
        self.serve_fetch(b"PNG-out")
        os.makedirs(self.destination())
        with open(os.path.join(self.destination(), "render.png"), "wb") as handle:
            handle.write(b"PNG-out")

        result = self.session().call("library_fetch", {"asset_id": 7, "dest_dir": self.destination()})

        self.assertTrue(result["structuredContent"]["reused"])
        self.assertEqual(["render.png"], os.listdir(self.destination()))

    def test_a_copy_that_arrives_different_leaves_nothing_behind(self):
        self.serve_fetch(b"PNG-out")
        self.set_flag("corrupt_cat")
        result = self.session().call("library_fetch", {"asset_id": 7, "dest_dir": self.destination()})

        self.assertTrue(result["isError"])
        self.assertIn("did not match", result["content"][0]["text"])
        self.assertEqual([], os.listdir(self.destination()))
        self.assertEqual([], self.staging_left())

    def test_refuses_a_folder_inside_the_mothership_storage(self):
        self.serve_fetch(b"PNG-out")
        link = os.path.join(self.mac, "storage-link")
        os.symlink(self.storage, link)
        session = self.session()
        for folder in (self.storage, os.path.join(self.storage, "app"), link):
            result = session.call("library_fetch", {"asset_id": 7, "dest_dir": folder})
            self.assertTrue(result["isError"], folder)
            self.assertIn("own storage", result["content"][0]["text"])
        self.assertNotIn("library_fetch", self.lines("calls"))

    def test_refuses_a_relative_folder_without_starting_php(self):
        result = self.session().call("library_fetch", {"asset_id": 7, "dest_dir": "relative/out"})
        self.assertTrue(result["isError"])
        self.assertIn("absolute path", result["content"][0]["text"])
        self.assertEqual(0, self.php_starts())

    def test_passes_a_server_refusal_through_and_clears_staging(self):
        self.set_flag("fetch_refuse")
        result = self.session().call("library_fetch", {"asset_id": 7, "dest_dir": self.destination()})
        self.assertEqual("Not found.", result["content"][0]["text"])
        self.assertEqual([], self.staging_left())


class VectorizeTest(LauncherCase):
    def test_a_one_off_sends_the_mac_file_in_and_clears_the_staged_copy(self):
        path = self.mac_file("logo.png", b"PNG-logo" * 100)
        result = self.session().call("library_vectorize", {"local_path": path, "request_id": REQUEST_ID})

        self.assertEqual("queued", result["structuredContent"]["status"])
        self.assertEqual(sha256(b"PNG-logo" * 100), json.loads(self.lines("saved.jsonl")[0])["sha256"])
        self.assertEqual([], self.staging_left())

    def test_a_filed_conversion_carries_no_file(self):
        self.session().call("library_vectorize", {"asset_id": 7, "format": "svg", "request_id": REQUEST_ID})

        self.assertEqual(["library_vectorize"], self.lines("calls"))
        self.assertEqual([], self.lines("saved.jsonl"))

    def test_a_result_copy_lands_at_a_mac_path_and_a_status_read_copies_nothing(self):
        destination = os.path.join(self.mac, "out")
        self.serve_fetch(b"<svg/>" * 100)
        session = self.session()
        status = session.call("library_vectorization", {"id": REQUEST_ID})
        result = session.call("library_vectorization", {"id": REQUEST_ID, "format": "svg", "dest_dir": destination})

        self.assertNotIn("path", status["structuredContent"])
        expected = os.path.join(destination, "render-vectorized.svg")
        self.assertEqual(expected, result["structuredContent"]["path"])
        with open(expected, "rb") as handle:
            self.assertEqual(sha256(b"<svg/>" * 100), sha256(handle.read()))
        self.assertEqual([], self.staging_left())


class LoggingTest(LauncherCase):
    def run_save_and_fetch(self, **settings):
        self.serve_fetch(b"PNG-out")
        session = self.session(**settings)
        session.call("library_save", self.save_arguments(path=self.mac_file("secret-client-name.png")))
        session.call("library_fetch", {"asset_id": 7, "dest_dir": os.path.join(self.mac, "out")})
        session.close()

    def test_writes_nothing_unless_switched_on(self):
        self.run_save_and_fetch()
        self.assertFalse(os.path.exists(self.log_path))

    def test_logs_structured_events_without_paths_or_names(self):
        self.run_save_and_fetch(MOTHERSHIP_MCP_LAUNCHER_LOG="1")
        with open(self.log_path, "r", encoding="utf-8") as handle:
            text = handle.read()
        events = [json.loads(line) for line in text.splitlines()]

        for event in events:
            self.assertEqual({"ts", "run_id", "level", "event", "loc", "ctx"}, set(event))
        names = {event["event"] for event in events}
        self.assertTrue({"upstream.start", "transfer.in", "transfer.out", "tool.call"} <= names)
        for private in ("secret-client-name", "render.png", self.root):
            self.assertNotIn(private, text)


class CodecTest(unittest.TestCase):
    """The idle stage reads and writes JSON through the C halves of the json module; it must agree with json itself."""

    SAMPLES = [
        None, True, False, 0, -7, 12345678901234567890, 1.5, -0.25, 1e100, "", "plain", 'quote " and \\ slash',
        "line\nbreak\ttab", "café 世界 \U0001f680", [], {}, [1, [2, [3]]],
        {"a": {"b": [None, True, "c"]}, "empty": {}, "list": []},
    ]

    @classmethod
    def setUpClass(cls):
        specification = importlib.util.spec_from_file_location("launcher_under_test", LAUNCHER)
        cls.launcher = importlib.util.module_from_spec(specification)
        specification.loader.exec_module(cls.launcher)

    def test_writes_what_json_reads(self):
        for sample in self.SAMPLES:
            self.assertEqual(sample, json.loads(self.launcher.dumps(sample)))

    def test_reads_what_json_writes(self):
        for sample in self.SAMPLES:
            self.assertEqual(sample, self.launcher.loads(json.dumps(sample).encode("utf-8")))
            self.assertEqual(sample, self.launcher.loads(json.dumps(sample, ensure_ascii=False).encode("utf-8")))

    def test_refuses_what_json_refuses(self):
        for text in (b"{not json", b'{"a":1} trailing', b"NaN", b"", b'"unterminated', b'{"a":}'):
            with self.assertRaises((ValueError, StopIteration), msg=text):
                self.launcher.loads(text)


class ManifestTest(LauncherCase):
    def run_check(self):
        return subprocess.run(
            [sys.executable, "-I", LAUNCHER, "--check-manifest"], env=self.env, stdout=subprocess.PIPE, timeout=30
        ).returncode

    def test_check_passes_when_the_server_publishes_the_same_tools(self):
        self.assertEqual(0, self.run_check())

    def test_check_fails_when_the_server_has_drifted(self):
        self.set_flag("tools_drift")
        self.assertEqual(1, self.run_check())


if __name__ == "__main__":
    unittest.main()

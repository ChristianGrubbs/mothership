"""The launcher as an agent sees it: real processes over stdio, calling tests/fake_mothership.py over HTTP, with
tests/fake_security.py as the Keychain."""
import hashlib
import importlib.util
import json
import os
import queue
import shutil
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import fake_mothership  # noqa: E402

LAUNCHER = os.path.join(os.path.dirname(HERE), "launcher.py")
MANIFEST = os.path.join(os.path.dirname(HERE), "tools_manifest.json")
FAKE_SECURITY = os.path.join(HERE, "fake_security.py")
FAKE_PHP = os.path.join(HERE, "fake_php.py")
LIBRARY_TOOLS = [
    "library_search", "library_list_folders", "library_create_folder", "library_get_asset",
    "library_list_stack", "library_fetch", "library_save", "library_update", "library_vectorize",
    "library_vectorization",
]
REQUEST_ID = "00000000-0000-4000-8000-000000000001"
OTHER_REQUEST_ID = "00000000-0000-4000-8000-000000000002"
ACCOUNT = "launcher-test"


def sha256(data):
    return hashlib.sha256(data).hexdigest()


class Session:
    """One agent session talking to one launcher."""

    def __init__(self, env, stderr_path):
        self.stderr_path = stderr_path
        with open(stderr_path, "wb") as stderr:
            self.process = subprocess.Popen(
                [sys.executable, "-I", LAUNCHER], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=stderr, env=env
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

    def next_reply(self, timeout):
        try:
            return self.replies.get(timeout=max(0.01, timeout))
        except queue.Empty:
            with open(self.stderr_path, "r", encoding="utf-8", errors="replace") as handle:
                stderr = handle.read()[-4000:]
            raise AssertionError("The launcher sent no answer in time. Its stderr:\n" + stderr) from None

    def wait(self, request_id, timeout=20):
        deadline = time.monotonic() + timeout
        while True:
            reply = self.next_reply(timeout=deadline - time.monotonic())
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
        for script in (FAKE_SECURITY, FAKE_PHP):
            os.chmod(script, os.stat(script).st_mode | stat.S_IXUSR)
        self.root = os.path.realpath(tempfile.mkdtemp(prefix="mcp-launcher-"))
        self.addCleanup(shutil.rmtree, self.root, True)
        self.site = fake_mothership.FakeMothership()
        self.addCleanup(self.site.stop)
        self.keychain = os.path.join(self.root, "keychain.json")
        self.temporary = self._folder("tmp")
        self.local = self._folder("local")
        self.fetch_dir = os.path.join(self.root, "default-fetch")
        self.log_path = os.path.join(self.root, "logs", "launcher.jsonl")
        self.env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": self.root,
            "USER": ACCOUNT,
            "TMPDIR": self.temporary,
            "MOTHERSHIP_URL": self.site.url,
            "MOTHERSHIP_MCP_SECURITY_BIN": FAKE_SECURITY,
            "MOTHERSHIP_MCP_STATE_DIR": self._folder("state"),
            "MOTHERSHIP_MCP_LAUNCHER_LOG_PATH": self.log_path,
            "MOTHERSHIP_MCP_FETCH_DIR": self.fetch_dir,
            "MOTHERSHIP_MCP_IDLE_SECONDS": "0.5",
            "MOTHERSHIP_MCP_TIMEOUT_SECONDS": "10",
            "FAKE_KEYCHAIN": self.keychain,
        }
        self.store_credentials(fake_mothership.CREDENTIALS)

    def _folder(self, name):
        path = os.path.join(self.root, name)
        os.makedirs(path)
        return path

    def session(self, **settings):
        self.sessions_started = getattr(self, "sessions_started", 0) + 1
        stderr_path = os.path.join(self.root, "launcher-stderr-%d.txt" % self.sessions_started)
        session = Session(env=dict(self.env, **settings), stderr_path=stderr_path)
        self.addCleanup(session.close)
        return session

    def keychain_items(self):
        if not os.path.exists(self.keychain):
            return {}
        with open(self.keychain, "r", encoding="utf-8") as handle:
            return json.load(handle)

    def set_keychain(self, service, value):
        items = self.keychain_items()
        if value is None:
            items.pop(ACCOUNT + "/" + service, None)
        else:
            items[ACCOUNT + "/" + service] = value
        with open(self.keychain, "w", encoding="utf-8") as handle:
            json.dump(items, handle)

    def store_credentials(self, credentials):
        self.set_keychain("mothership-agent-api", json.dumps(credentials))

    def cached_token(self):
        return self.keychain_items().get(ACCOUNT + "/mothership-agent-api-token")

    def local_file(self, name, data=b"PNG-bytes"):
        path = os.path.join(self.local, name)
        with open(path, "wb") as handle:
            handle.write(data)
        return path

    def logged(self):
        if not os.path.exists(self.log_path):
            return []
        with open(self.log_path, "r", encoding="utf-8") as handle:
            return [json.loads(line) for line in handle.read().splitlines()]

    def wait_until(self, check, timeout=10):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if check():
                return True
            time.sleep(0.05)
        return check()

    def save_arguments(self, path, **extra):
        return dict({"local_path": path, "folder_id": 3, "producer": {"tool": "blender"}, "request_id": REQUEST_ID}, **extra)

    def assert_refused(self, result, expected):
        self.assertTrue(result.get("isError"), result)
        self.assertIn(expected, result["content"][0]["text"])


class ProtocolTest(LauncherCase):
    def test_answers_initialize_and_tools_list_without_touching_the_network(self):
        session = self.session()
        hello = session.request("initialize", {"protocolVersion": "2025-06-18", "capabilities": {}})["result"]
        tools = session.request("tools/list")["result"]["tools"]

        self.assertEqual("2025-06-18", hello["protocolVersion"])
        self.assertEqual("The Mothership", hello["serverInfo"]["name"])
        self.assertIn("library_save", hello["instructions"])
        self.assertEqual(LIBRARY_TOOLS, [tool["name"] for tool in tools])
        self.assertEqual(([], 0), (self.site.tool_calls(), self.site.grants))

    def test_answers_malformed_input_with_a_parse_error_and_keeps_serving(self):
        session = self.session()
        session.process.stdin.write(b"{not json\n")
        session.process.stdin.flush()

        self.assertEqual(-32700, session.next_reply(timeout=10)["error"]["code"])
        self.assertEqual({}, session.request("ping")["result"])

    def test_offers_the_newest_version_when_the_client_asks_for_an_unknown_one(self):
        hello = self.session().request("initialize", {"protocolVersion": "1999-01-01"})["result"]
        self.assertEqual("2025-11-25", hello["protocolVersion"])

    def test_answers_ping_and_rejects_unknown_methods(self):
        session = self.session()
        self.assertEqual({}, session.request("ping")["result"])
        self.assertEqual(-32601, session.request("resources/list")["error"]["code"])

    def test_an_unknown_tool_is_refused_as_the_server_refuses_it_without_a_call(self):
        session = self.session()
        self.assert_refused(session.call("library_delete", {}), "Tool [library_delete] not found.")
        self.assert_refused(session.call({"not": "a name"}, {}), "not found")
        self.assertEqual([], self.site.tool_calls())


class CallTest(LauncherCase):
    def test_a_tool_answers_with_the_route_result_in_both_forms(self):
        result = self.session().call("library_list_folders", {"name": "Ren"})

        self.assertEqual("Renders", result["structuredContent"]["folders"][0]["name"])
        self.assertEqual(result["structuredContent"], json.loads(result["content"][0]["text"]))
        self.assertEqual(["list_folders"], self.site.tool_calls())
        self.assertEqual([{"name": "Ren"}], self.site.json_bodies)

    def test_passes_the_servers_refusal_through_word_for_word(self):
        self.site.script("get_asset", (404, {"error": "Not found."}))
        self.site.script("create_folder", (422, {"error": "The name field format is invalid."}))
        session = self.session()

        self.assertEqual(
            {"content": [{"type": "text", "text": "Not found."}], "isError": True},
            session.call("library_get_asset", {"asset_id": 99}),
        )
        self.assert_refused(session.call("library_create_folder", {"parent_id": 1, "name": "a/b"}), "The name field format is invalid.")

    def test_a_missing_scope_says_which_tool_lacks_it(self):
        self.site.script("save", (403, {"error": "This action is unauthorized"}))
        result = self.session().call("library_save", self.save_arguments(path=self.local_file("render.png")))
        self.assert_refused(result, "lacks the scope library_save needs")

    def test_retries_a_busy_gateway_twice_then_answers(self):
        self.site.script("search", (502, b"<html>Bad gateway</html>", {"Retry-After": "0"}), (429, {"message": "Too Many Attempts."}, {"Retry-After": "0"}))
        result = self.session().call("library_search", {"query": "logo"})

        self.assertNotIn("isError", result)
        self.assertEqual(3, len(self.site.tool_calls("search")))

    def test_gives_up_after_three_tries_with_the_cause(self):
        self.site.script("search", *[(504, b"<html>Gateway timeout</html>", {"Retry-After": "0"})] * 3)
        result = self.session().call("library_search", {})

        self.assert_refused(result, "The Mothership is not answering right now (HTTP 504). Try again shortly.")
        self.assertEqual(3, len(self.site.tool_calls("search")))

    def test_a_busy_refusal_from_the_app_is_passed_on_not_retried(self):
        message = "The Mothership is busy finishing an earlier save. Try again shortly with the same request_id."
        self.site.script("save", (503, {"error": message}))
        result = self.session().call("library_save", self.save_arguments(path=self.local_file("render.png")))

        self.assert_refused(result, message)
        self.assertEqual(1, len(self.site.tool_calls("save")))

    def test_an_unreachable_site_is_a_tool_error_and_the_launcher_stays_up(self):
        session = self.session(MOTHERSHIP_URL="http://127.0.0.1:9")
        result = session.call("library_list_folders")

        self.assert_refused(result, "The Mothership is not answering right now")
        self.assertEqual({}, session.request("ping")["result"])

    def test_without_a_site_address_a_call_is_refused_without_a_request(self):
        result = self.session(MOTHERSHIP_URL="").call("library_list_folders")

        self.assert_refused(result, "MOTHERSHIP_URL is not set")
        self.assertEqual(([], 0), (self.site.tool_calls(), self.site.grants))

    def test_a_site_address_with_an_upper_case_scheme_works(self):
        session = self.session(MOTHERSHIP_URL=self.site.url.replace("http://", "HTTP://") + "/")
        result = session.call("library_list_folders")

        self.assertFalse(result.get("isError"), result)
        self.assertEqual(["list_folders"], self.site.tool_calls())

    def test_never_follows_a_redirect_with_the_bearer(self):
        self.site.script("search", (302, b"", {"Location": self.site.url + "/elsewhere"}))
        result = self.session().call("library_search", {})

        self.assert_refused(result, "redirect (HTTP 302)")
        self.assertEqual(0, self.site.elsewhere_hits)


class SignInTest(LauncherCase):
    def test_signs_in_once_caches_the_token_and_reuses_it(self):
        session = self.session(MOTHERSHIP_MCP_IDLE_SECONDS="30")
        session.call("library_list_folders")
        session.call("library_list_folders")

        self.assertEqual(1, self.site.grants)
        expiry, _, token = self.cached_token().partition(" ")
        self.assertIn(token, self.site.tokens)
        self.assertAlmostEqual(time.time() + 3600, int(expiry), delta=30)

    def test_uses_a_cached_token_without_signing_in(self):
        token = self.site.issue()
        self.set_keychain("mothership-agent-api-token", "%d %s" % (time.time() + 600, token))
        self.session().call("library_list_folders")
        self.assertEqual(1, self.site.grants)

    def test_signs_in_again_when_the_cached_token_is_about_to_expire(self):
        self.set_keychain("mothership-agent-api-token", "%d %s" % (time.time() + 30, self.site.issue()))
        self.session().call("library_list_folders")
        self.assertEqual(2, self.site.grants)

    def test_a_refused_token_takes_one_another_launcher_cached_meanwhile(self):
        session = self.session(MOTHERSHIP_MCP_IDLE_SECONDS="30")
        session.call("library_list_folders")
        self.site.tokens.clear()
        newer = self.site.issue()
        self.set_keychain("mothership-agent-api-token", "%d %s" % (time.time() + 600, newer))

        self.assertNotIn("isError", session.call("library_list_folders"))
        self.assertEqual(2, self.site.grants)

    def test_a_refused_token_signs_in_again_and_retries_once(self):
        session = self.session(MOTHERSHIP_MCP_IDLE_SECONDS="30")
        session.call("library_list_folders")
        self.site.tokens.clear()

        self.assertNotIn("isError", session.call("library_list_folders"))
        self.assertEqual(2, self.site.grants)
        self.assertEqual(3, len(self.site.tool_calls("list_folders")))

    def test_sessions_that_sign_in_together_share_one_grant(self):
        sessions = [self.session() for _ in range(4)]
        pending = [(session, session.start("tools/call", {"name": "library_list_folders", "arguments": {}})) for session in sessions]

        for session, request_id in pending:
            self.assertNotIn("isError", session.wait(request_id=request_id)["result"])
        self.assertEqual(1, self.site.grants)

    def test_refused_credentials_name_the_keychain_item(self):
        self.site.refuse_grant = True
        result = self.session().call("library_list_folders")

        self.assert_refused(result, "refused the library sign-in (HTTP 400)")
        self.assert_refused(result, "mothership-agent-api")
        self.assertEqual([], self.site.tool_calls())

    def test_a_missing_keychain_item_is_named(self):
        self.set_keychain("mothership-agent-api", None)
        result = self.session().call("library_list_folders")

        self.assert_refused(result, "The Keychain item mothership-agent-api is missing")
        self.assertEqual([], self.site.tool_calls())

    def test_incomplete_credentials_say_what_is_missing(self):
        self.store_credentials({"client_id": "client-1", "username": "agent@example.test"})
        self.assert_refused(self.session().call("library_list_folders"), "lacks client_secret, password")

    def test_a_token_refused_after_a_fresh_sign_in_is_reported(self):
        self.site.script("search", (401, {"message": "Unauthenticated."}), (401, {"message": "Unauthenticated."}))
        result = self.session().call("library_search", {})
        self.assert_refused(result, "refused the library credentials (HTTP 401) even after a fresh sign-in")


class SaveTest(LauncherCase):
    def test_sends_the_file_with_its_own_name_and_the_arguments_unchanged(self):
        data = b"PNG-bytes" * 1000
        path = self.local_file("it's a \"$HOME\" render (1).png", data)
        arguments = self.save_arguments(path=path, title="Café render")
        result = self.session().call("library_save", arguments)

        self.assertEqual(7, result["structuredContent"]["asset_id"])
        saved = self.site.saved[0]
        self.assertEqual(("it's a \"$HOME\" render (1).png", sha256(data), len(data)), (saved["name"], saved["sha256"], saved["size"]))
        self.assertEqual(arguments, saved["arguments"])
        self.assertEqual([], os.listdir(self.temporary))

    def test_a_link_saves_under_the_real_file_name_as_the_server_does(self):
        target = self.local_file("real.png")
        link = os.path.join(self.local, "link.png")
        os.symlink(target, link)

        self.session().call("library_save", self.save_arguments(path=link))
        self.assertEqual("real.png", self.site.saved[0]["name"])

    def test_refuses_what_the_server_refuses_without_a_call(self):
        session = self.session(MOTHERSHIP_MCP_MAX_FILE_BYTES="4")
        cases = {
            "relative/render.png": "must be an absolute path",
            os.path.join(self.local, "missing.png"): "There is no file",
            self.local: "There is no file",
            self.local_file("empty.png", b""): "is empty",
            self.local_file("large.png", b"12345"): "the library limit is",
        }
        for path, expected in cases.items():
            self.assert_refused(session.call("library_save", self.save_arguments(path=path)), expected)
        self.assertEqual([], self.site.tool_calls())

    def test_a_local_path_that_is_not_text_goes_to_the_servers_rules(self):
        self.site.script("save", (422, {"error": "The local path field must be a string."}))
        result = self.session().call("library_save", self.save_arguments(path=12))

        self.assert_refused(result, "The local path field must be a string.")
        self.assertEqual([], self.site.saved)

    def test_a_retried_upload_sends_the_same_bytes(self):
        self.site.script("save", (502, b"<html>Bad gateway</html>", {"Retry-After": "0"}))
        data = b"PNG-retry" * 500
        self.session().call("library_save", self.save_arguments(path=self.local_file("render.png", data)))

        self.assertEqual([sha256(data)], [saved["sha256"] for saved in self.site.saved])
        self.assertEqual(2, len(self.site.tool_calls("save")))

    def test_a_save_whose_answer_is_cut_short_is_sent_again_and_replays(self):
        self.site.cut_answers["save"] = 1
        result = self.session().call("library_save", self.save_arguments(path=self.local_file("render.png")))

        self.assertNotIn("isError", result)
        self.assertEqual((7, True), (result["structuredContent"]["asset_id"], result["structuredContent"]["replayed"]))
        self.assertEqual(2, len(self.site.tool_calls("save")))
        self.assertEqual(1, len(self.site.saves_by_request))

    def test_a_name_with_a_backslash_reaches_the_server_whole(self):
        # PHP keeps only what follows the last backslash of an upload's file name, so the name also travels as JSON.
        self.session().call("library_save", self.save_arguments(path=self.local_file("draft\\hero.png")))
        self.assertEqual("draft\\hero.png", self.site.saved[0]["name"])


class FetchTest(LauncherCase):
    def destination(self):
        return os.path.join(self.local, "out")

    def part_files(self):
        return [name for name in os.listdir(self.destination()) if name.endswith(".mothership-part")]

    def test_leaves_a_verified_copy_at_a_local_path_in_both_answer_forms(self):
        self.site.fetch_bytes = b"PNG-out" * 1000
        result = self.session().call("library_fetch", {"asset_id": 7, "dest_dir": self.destination()})

        expected = os.path.join(self.destination(), "render.png")
        self.assertEqual(expected, result["structuredContent"]["path"])
        self.assertEqual(expected, json.loads(result["content"][0]["text"])["path"])
        self.assertEqual(("render.png", False, 7), (result["structuredContent"]["name"], result["structuredContent"]["reused"], result["structuredContent"]["asset_id"]))
        with open(expected, "rb") as handle:
            self.assertEqual(sha256(b"PNG-out" * 1000), sha256(handle.read()))
        self.assertEqual([], self.part_files())
        self.assertEqual([{"asset_id": 7, "dest_dir": self.destination()}], self.site.json_bodies)

    def test_uses_the_default_folder_when_no_dest_dir_is_named(self):
        result = self.session().call("library_fetch", {"asset_id": 7})
        self.assertEqual(os.path.join(self.fetch_dir, "render.png"), result["structuredContent"]["path"])

    def test_never_overwrites_a_different_file(self):
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
        os.makedirs(self.destination())
        with open(os.path.join(self.destination(), "render.png"), "wb") as handle:
            handle.write(b"PNG-out")

        result = self.session().call("library_fetch", {"asset_id": 7, "dest_dir": self.destination()})

        self.assertTrue(result["structuredContent"]["reused"])
        self.assertEqual(["render.png"], os.listdir(self.destination()))

    def test_a_copy_that_arrives_different_leaves_nothing_behind(self):
        self.site.corrupt_copy = True
        result = self.session().call("library_fetch", {"asset_id": 7, "dest_dir": self.destination()})

        self.assert_refused(result, "did not match")
        self.assertEqual([], os.listdir(self.destination()))

    def test_checks_dest_dir_after_the_servers_own_checks(self):
        self.site.script("fetch", (404, {"error": "Not found."}))
        session = self.session()

        self.assert_refused(session.call("library_fetch", {"asset_id": 99, "dest_dir": "relative/out"}), "Not found.")
        self.assert_refused(session.call("library_fetch", {"asset_id": 7, "dest_dir": "relative/out"}), "dest_dir must be an absolute path")

    def test_a_refused_fetch_creates_no_folder(self):
        self.site.script("fetch", (404, {"error": "Not found."}))
        self.assert_refused(self.session().call("library_fetch", {"asset_id": 99, "dest_dir": self.destination()}), "Not found.")
        self.assertFalse(os.path.exists(self.destination()))


class VectorizeTest(LauncherCase):
    def test_a_one_off_sends_the_local_file(self):
        path = self.local_file("logo.png", b"PNG-logo" * 100)
        result = self.session().call("library_vectorize", {"local_path": path, "request_id": REQUEST_ID})

        self.assertEqual("queued", result["structuredContent"]["status"])
        self.assertEqual(("vectorize", "logo.png", sha256(b"PNG-logo" * 100)), (self.site.saved[0]["tool"], self.site.saved[0]["name"], self.site.saved[0]["sha256"]))

    def test_a_filed_conversion_carries_no_file(self):
        self.session().call("library_vectorize", {"asset_id": 7, "format": "svg", "request_id": REQUEST_ID})

        self.assertEqual(["vectorize"], self.site.tool_calls())
        self.assertEqual([], self.site.saved)

    def test_a_conversion_whose_answer_is_cut_short_is_sent_again_and_replays(self):
        path = self.local_file("logo.png")
        for arguments in ({"local_path": path, "request_id": REQUEST_ID}, {"asset_id": 7, "format": "svg", "request_id": OTHER_REQUEST_ID}):
            self.site.cut_answers["vectorize"] = 1
            result = self.session().call("library_vectorize", arguments)

            self.assertNotIn("isError", result)
            self.assertIs(True, result["structuredContent"]["replayed"])
        self.assertEqual(4, len(self.site.tool_calls("vectorize")))
        self.assertEqual({("vectorize", REQUEST_ID), ("vectorize", OTHER_REQUEST_ID)}, set(self.site.saves_by_request))

    def test_a_result_copy_lands_at_a_local_path_and_a_status_read_copies_nothing(self):
        destination = os.path.join(self.local, "out")
        self.site.fetch_bytes = b"<svg/>" * 100
        session = self.session()
        status = session.call("library_vectorization", {"id": REQUEST_ID})
        result = session.call("library_vectorization", {"id": REQUEST_ID, "format": "svg", "dest_dir": destination})

        self.assertNotIn("path", status["structuredContent"])
        expected = os.path.join(destination, "render-vectorized.svg")
        self.assertEqual(expected, result["structuredContent"]["path"])
        with open(expected, "rb") as handle:
            self.assertEqual(sha256(b"<svg/>" * 100), sha256(handle.read()))


class LifecycleTest(LauncherCase):
    def test_shrinks_back_to_a_fresh_process_after_idle_and_keeps_the_conversation(self):
        session = self.session(MOTHERSHIP_MCP_LAUNCHER_LOG="1")
        session.request("initialize", {"protocolVersion": "2025-06-18"})
        session.call("library_list_folders")
        self.assertTrue(self.wait_until(lambda: "launcher.recycle" in [event["event"] for event in self.logged()]))
        time.sleep(0.3)

        self.assertIsNone(session.process.poll())
        self.assertEqual(LIBRARY_TOOLS, [tool["name"] for tool in session.request("tools/list")["result"]["tools"]])
        self.assertNotIn("isError", session.call("library_list_folders"))
        self.assertEqual(1, self.site.grants)

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

    def test_a_call_slower_than_the_timeout_is_reported(self):
        session = self.session(MOTHERSHIP_MCP_TIMEOUT_SECONDS="0.3")
        result = session.call("library_search", {"query": "sleep:2"}, timeout=30)
        self.assert_refused(result, "not answering right now (timed out)")


class LoggingTest(LauncherCase):
    def run_save_and_fetch(self, **settings):
        session = self.session(**settings)
        session.call("library_save", self.save_arguments(path=self.local_file("secret-client-name.png")))
        session.call("library_fetch", {"asset_id": 7, "dest_dir": os.path.join(self.local, "out")})
        session.close()

    def test_writes_nothing_unless_switched_on(self):
        self.run_save_and_fetch()
        self.assertFalse(os.path.exists(self.log_path))

    def test_logs_structured_events_without_paths_names_or_credentials(self):
        self.run_save_and_fetch(MOTHERSHIP_MCP_LAUNCHER_LOG="1")
        with open(self.log_path, "r", encoding="utf-8") as handle:
            text = handle.read()
        events = [json.loads(line) for line in text.splitlines()]

        for event in events:
            self.assertEqual({"ts", "run_id", "level", "event", "loc", "ctx"}, set(event))
        names = {event["event"] for event in events}
        self.assertTrue({"token.grant", "http.response", "transfer.in", "transfer.out", "tool.call"} <= names, names)
        private = ["secret-client-name", "render.png", self.root, *fake_mothership.CREDENTIALS.values(), *self.site.tokens]
        for value in private:
            self.assertNotIn(value, text)


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
    def run_check(self, **settings):
        env = dict(self.env, MOTHERSHIP_MCP_PHP=FAKE_PHP, FAKE_MANIFEST=MANIFEST, **settings)
        return subprocess.run([sys.executable, "-I", LAUNCHER, "--check-manifest"], env=env, stdout=subprocess.PIPE, timeout=30).returncode

    def test_check_passes_when_the_server_publishes_the_same_tools(self):
        self.assertEqual(0, self.run_check())

    def test_check_fails_when_the_server_has_drifted(self):
        self.assertEqual(1, self.run_check(FAKE_TOOLS_DRIFT="1"))


if __name__ == "__main__":
    unittest.main()

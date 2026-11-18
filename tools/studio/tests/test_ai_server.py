import base64
import importlib.util
import json
import pathlib
import threading
import time
import unittest
import urllib.error
import urllib.request
from unittest import mock
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ai_server", ROOT / "ai-service" / "server.py")
ai_server = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ai_server)


class FakeOllama(BaseHTTPRequestHandler):
    calls = []
    mode = "ok"  # ok | slow | not_dict | not_json | content_not_dict
    name_event = "Welcome Week"

    def log_message(self, *args):
        return

    def _reply(self, body):
        data = json.dumps(body).encode()
        self.send_response(200)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        self._reply({"models": [{"name": "qwen3.5:9b"}, {"name": "qwen3-embedding:0.6b"}]})

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        FakeOllama.calls.append((self.path, body))
        if FakeOllama.mode == "slow":
            time.sleep(1.0)
        elif FakeOllama.mode == "not_dict":
            return self._reply(["nope"])
        elif FakeOllama.mode == "not_json":
            data = b"<html>boom</html>"
            self.send_response(200)
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            return
        elif FakeOllama.mode == "content_not_dict" and self.path == "/api/chat":
            return self._reply({"message": {"content": json.dumps(["x"])}})
        if self.path == "/api/chat" and "subject" in body.get("format", {}).get("properties", {}):
            self._reply({"message": {"content": json.dumps({
                "subject": "welcome week campus tour",
                "place": "Student Center",
                "title": "Campus Tour at Welcome Week",
                "event": FakeOllama.name_event,
            })}})
        elif self.path == "/api/chat":
            self._reply({"message": {"content": json.dumps({
                "caption": "A crowd gathers at a campus event.",
                "tags": ["campus-event", "not-in-list", "campus-event"],
                "keywords": ["crowd", " ", "tent"],
                "visible_text": "SALE",
            })}})
        else:
            self._reply({"embeddings": [[0.1] * 1024 for _ in body["input"]]})


def serve(handler):
    server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


class AiServerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ollama = serve(FakeOllama)
        config = ai_server.ServerConfig()
        config.ollama = "http://127.0.0.1:{}".format(cls.ollama.server_port)
        config.max_bytes = 4096
        cls.bridge = serve(ai_server.make_handler(config, ai_server.Ollama(config), ai_server.EventLog()))
        cls.base = "http://127.0.0.1:{}".format(cls.bridge.server_port)

    @classmethod
    def tearDownClass(cls):
        cls.bridge.shutdown()
        cls.bridge.server_close()
        cls.ollama.shutdown()
        cls.ollama.server_close()

    def post(self, path, body):
        request = urllib.request.Request(self.base + path, data=json.dumps(body).encode(),
                                         headers={"Content-Type": "application/json"}, method="POST")
        try:
            with urllib.request.urlopen(request, timeout=5) as response:
                return response.status, json.loads(response.read())
        except urllib.error.HTTPError as error:
            with error:
                return error.code, json.loads(error.read())

    def setUp(self):
        FakeOllama.mode = "ok"

    def tearDown(self):
        FakeOllama.mode = "ok"

    def test_health_reports_models(self):
        with urllib.request.urlopen(self.base + "/health", timeout=5) as response:
            body = json.loads(response.read())
        self.assertEqual(body["models"], {"caption": True, "embed": True})

    def test_live_answers_without_asking_ollama(self):
        with mock.patch.object(ai_server.Ollama, "models", side_effect=AssertionError("must not probe Ollama")):
            with urllib.request.urlopen(self.base + "/live", timeout=5) as response:
                body = json.loads(response.read())
        self.assertEqual(body["ok"], True)

    def test_caption_keeps_only_vocabulary_tags_once(self):
        image = base64.b64encode(b"jpeg").decode()
        status, body = self.post("/caption", {"images": [image], "context": {"file_name": "a.jpg"},
                                              "vocabulary": ["campus-event", "photo"]})
        self.assertEqual(status, 200)
        self.assertEqual(body["tags"], ["campus-event"])
        self.assertEqual(body["keywords"], ["crowd", "tent"])
        self.assertEqual(body["prompt_version"], ai_server.PROMPT_VERSION)
        path, sent = FakeOllama.calls[-1]
        self.assertEqual(path, "/api/chat")
        self.assertFalse(sent["think"])
        self.assertEqual(sent["options"], {"temperature": 0, "num_ctx": 8192})
        self.assertEqual(sent["format"]["properties"]["tags"]["items"]["enum"], ["campus-event", "photo"])

    def test_query_embedding_gets_instruct_prefix_and_documents_do_not(self):
        self.post("/embed", {"texts": ["falcon"], "kind": "query"})
        self.assertTrue(FakeOllama.calls[-1][1]["input"][0].startswith("Instruct:"))
        status, body = self.post("/embed", {"texts": ["doc one", "doc two"], "kind": "document"})
        self.assertEqual(status, 200)
        self.assertEqual(len(body["vectors"]), 2)
        self.assertEqual(FakeOllama.calls[-1][1]["input"], ["doc one", "doc two"])

    def test_rejects_invalid_and_oversized_requests(self):
        self.assertEqual(self.post("/caption", {"images": [], "vocabulary": ["a"]})[0], 400)
        self.assertEqual(self.post("/embed", {"texts": ["x"], "kind": "other"})[0], 400)
        self.assertEqual(self.post("/embed", {"texts": ["x" * 5000]})[0], 413)

    def test_name_is_text_only_and_keeps_only_an_offered_event(self):
        payload = {
            "caption": "A crowd gathers at a campus event.",
            "keywords": ["crowd"], "tags": ["campus-event"], "file_name": "IMG_0042.JPG",
            "captured_at": "2026-09-14 13:22", "campus": "Main Campus", "building": "Student Center",
            "events": [{"title": "Welcome Week", "location": "Student Center (2-SC)", "start": "2026-09-14T11:00", "end": "2026-09-14T14:00"}],
        }
        status, body = self.post("/name", payload)
        self.assertEqual(status, 200)
        self.assertEqual(body["subject"], "welcome week campus tour")
        self.assertEqual(body["event"], "Welcome Week")
        sent = FakeOllama.calls[-1][1]
        self.assertNotIn("images", sent["messages"][0])
        self.assertIn("Welcome Week | Student Center (2-SC)", sent["messages"][0]["content"])
        FakeOllama.name_event = "Made Up Gala"
        try:
            self.assertEqual(self.post("/name", payload)[1]["event"], "")
        finally:
            FakeOllama.name_event = "Welcome Week"

    def test_name_rejects_missing_caption_and_bad_events(self):
        self.assertEqual(self.post("/name", {"caption": " "})[0], 400)
        self.assertEqual(self.post("/name", {"caption": "x", "events": [{"title": ""}]})[0], 400)
        self.assertEqual(self.post("/name", {"caption": "x", "events": "nope"})[0], 400)

    def test_duplicate_vocabulary_is_deduped_in_schema_enum(self):
        status, _ = self.post("/caption", {"images": ["aGk="], "vocabulary": ["a", "b", "a"]})
        self.assertEqual(status, 200)
        self.assertEqual(FakeOllama.calls[-1][1]["format"]["properties"]["tags"]["items"]["enum"], ["a", "b"])

    def test_slow_ollama_is_gateway_timeout(self):
        FakeOllama.mode = "slow"
        config = ai_server.ServerConfig()
        config.ollama = "http://127.0.0.1:{}".format(self.ollama.server_port)
        config.caption_timeout = 0.2
        config.embed_timeout = 0.2
        bridge = serve(ai_server.make_handler(config, ai_server.Ollama(config), ai_server.EventLog()))
        try:
            for path, body in (("/embed", {"texts": ["x"]}),
                               ("/caption", {"images": ["aGk="], "vocabulary": ["a"]})):
                request = urllib.request.Request("http://127.0.0.1:{}{}".format(bridge.server_port, path),
                                                 data=json.dumps(body).encode(), method="POST")
                with self.assertRaises(urllib.error.HTTPError) as caught:
                    urllib.request.urlopen(request, timeout=5)
                with caught.exception:
                    self.assertEqual(caught.exception.code, 504)
                    self.assertEqual(json.loads(caught.exception.read()), {"error": "ollama_timeout"})
        finally:
            bridge.shutdown()
            bridge.server_close()

    def test_malformed_ollama_replies_are_bad_gateway(self):
        for mode in ("not_json", "not_dict", "content_not_dict"):
            FakeOllama.mode = mode
            with self.subTest(mode=mode):
                status, body = self.post("/caption", {"images": ["aGk="], "vocabulary": ["a"]})
                self.assertEqual((status, body), (502, {"error": "ollama_failed"}))
        FakeOllama.mode = "not_dict"
        self.assertEqual(self.post("/embed", {"texts": ["x"]}), (502, {"error": "ollama_failed"}))

    def test_ollama_down_is_bad_gateway(self):
        config = ai_server.ServerConfig()
        config.ollama = "http://127.0.0.1:9"
        bridge = serve(ai_server.make_handler(config, ai_server.Ollama(config), ai_server.EventLog()))
        try:
            request = urllib.request.Request("http://127.0.0.1:{}/embed".format(bridge.server_port),
                                             data=json.dumps({"texts": ["x"]}).encode(), method="POST")
            with self.assertRaises(urllib.error.HTTPError) as caught:
                urllib.request.urlopen(request, timeout=5)
            caught.exception.close()
            self.assertEqual(caught.exception.code, 502)
        finally:
            bridge.shutdown()
            bridge.server_close()


class SlowCaptionOllama(BaseHTTPRequestHandler):
    # The caption stays in flight until the test releases it, so the overlap is certain, not timed.
    caption_entered = threading.Event()
    release_caption = threading.Event()

    def log_message(self, *args):
        return

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        if self.path == "/api/chat":
            SlowCaptionOllama.caption_entered.set()
            SlowCaptionOllama.release_caption.wait(timeout=5)
            reply = {"message": {"content": json.dumps({"caption": "c", "tags": [], "keywords": [], "visible_text": ""})}}
        else:
            reply = {"embeddings": [[0.1] * 4 for _ in body["input"]]}
        data = json.dumps(reply).encode()
        try:
            self.send_response(200)
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
        except BrokenPipeError:
            pass


class ConcurrencyTest(unittest.TestCase):
    def test_a_query_embedding_never_waits_behind_a_caption(self):
        ollama = serve(SlowCaptionOllama)
        config = ai_server.ServerConfig()
        config.ollama = "http://127.0.0.1:{}".format(ollama.server_port)
        bridge = serve(ai_server.make_handler(config, ai_server.Ollama(config), ai_server.EventLog()))
        base = "http://127.0.0.1:{}".format(bridge.server_port)

        def post(path, body):
            request = urllib.request.Request(base + path, data=json.dumps(body).encode(),
                                             headers={"Content-Type": "application/json"}, method="POST")
            with urllib.request.urlopen(request, timeout=5) as response:
                return response.status

        SlowCaptionOllama.caption_entered.clear()
        SlowCaptionOllama.release_caption.clear()
        caption_result = {}

        def run_caption():
            try:
                caption_result["status"] = post("/caption", {
                    "images": [base64.b64encode(b"jpeg").decode()], "vocabulary": ["photo"]})
            except Exception as error:  # surfaced by the assertion below
                caption_result["error"] = error

        caption = threading.Thread(target=run_caption)
        try:
            caption.start()
            self.assertTrue(SlowCaptionOllama.caption_entered.wait(timeout=3), "caption never reached Ollama")
            started = time.monotonic()
            self.assertEqual(post("/embed", {"texts": ["dolphin"], "kind": "query"}), 200)
            self.assertLess(time.monotonic() - started, 1.0)
            self.assertFalse(SlowCaptionOllama.release_caption.is_set())  # the caption was still in flight
        finally:
            SlowCaptionOllama.release_caption.set()
            caption.join(timeout=5)
            for server in (bridge, ollama):
                server.shutdown()
                server.server_close()
        self.assertEqual(caption_result, {"status": 200})


if __name__ == "__main__":
    unittest.main()

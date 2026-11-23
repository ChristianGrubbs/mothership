"""Stands in for The Mothership's site in the launcher tests: /oauth/token and the library tool routes, on 127.0.0.1.

It runs in the test process, so a test scripts answers and reads what arrived directly. The tool routes answer as
LibraryToolController does: {"result": ...}, {"error": ...}, or a copy-out's bytes with X-Library-Result.
"""
import base64
import hashlib
import json
import os
import re
import threading
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CREDENTIALS = {"client_id": "client-1", "client_secret": "client-secret-1", "username": "agent@example.test", "password": "agent-password-1"}
ROUTE_PREFIX = "/api/v1/rest/library/tools/"
VECTORIZATION_ID = "00000000-0000-4000-8000-0000000000aa"


class FakeMothership:
    def __init__(self):
        self.lock = threading.Lock()
        self.grants = 0
        self.tokens = set()
        self.calls = []
        self.saved = []
        self.json_bodies = []
        self.scripts = {}
        self.refuse_grant = False
        self.fetch_bytes = b"PNG-out"
        self.corrupt_copy = False
        # Tool name -> how many of its next answers are cut short after the headers (the call itself is applied).
        self.cut_answers = {}
        # (tool, request_id) -> the first result of a save or vectorize, which a repeat replays.
        self.saves_by_request = {}
        self.elsewhere_hits = 0
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), _Handler)
        self.server.daemon_threads = True
        self.server.fake = self
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    @property
    def url(self):
        return "http://127.0.0.1:%d" % self.server.server_address[1]

    def stop(self):
        self.server.shutdown()
        self.server.server_close()

    def issue(self):
        with self.lock:
            self.grants += 1
            token = "token-%d-%s" % (self.grants, os.urandom(6).hex())
            self.tokens.add(token)
        return token

    def script(self, tool, *answers):
        """Answers for the next calls to one tool, before its normal one: (status, body, headers), body a dict or bytes."""
        self.scripts.setdefault(tool, []).extend(answers)

    def next_script(self, tool):
        with self.lock:
            answers = self.scripts.get(tool)
            return answers.pop(0) if answers else None

    def tool_calls(self, tool=None):
        return [call for call in self.calls if tool is None or call == tool]


class _Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *_arguments):
        pass

    def do_POST(self):  # noqa: N802
        fake = self.server.fake
        body = self.rfile.read(int(self.headers.get("Content-Length") or 0))
        path = urllib.parse.urlsplit(self.path).path
        if path == "/oauth/token":
            return self._grant(fake=fake, body=body)
        if path == "/elsewhere":
            fake.elsewhere_hits += 1
            return self._json(status=200, payload={"result": {"moved": True}})
        if not path.startswith(ROUTE_PREFIX):
            return self._json(status=404, payload={"message": "Not Found"})
        tool = path[len(ROUTE_PREFIX):]
        with fake.lock:
            fake.calls.append(tool)
        authorization = self.headers.get("Authorization", "")
        if not authorization.startswith("Bearer ") or authorization[7:] not in fake.tokens:
            return self._json(status=401, payload={"message": "Unauthenticated."})
        scripted = fake.next_script(tool=tool)
        if scripted is not None:
            status, payload, headers = (tuple(scripted) + ({},))[:3]
            return self._json(status=status, payload=payload, headers=headers)
        arguments, upload = self._arguments(body=body)
        if upload is None:
            fake.json_bodies.append(arguments)
        return self._tool(fake=fake, tool=tool, arguments=arguments, upload=upload)

    def _grant(self, fake, body):
        form = dict(urllib.parse.parse_qsl(body.decode("utf-8")))
        if fake.refuse_grant:
            return self._json(status=400, payload={"error": "invalid_grant", "message": "The user credentials were incorrect."})
        expected = dict(CREDENTIALS, grant_type="password")
        if form != expected:
            return self._json(status=401, payload={"error": "invalid_client", "message": "Client authentication failed"})
        return self._json(status=200, payload={"token_type": "Bearer", "expires_in": 3600, "access_token": fake.issue()})

    def _arguments(self, body):
        """The JSON body, or the multipart fields arguments, upload and file, parsed as PHP's form parser reads them.
        The upload is (name, bytes): the name from the JSON field upload, else what PHP keeps of the file name."""
        kind = self.headers.get("Content-Type", "")
        if kind.startswith("application/json"):
            return json.loads(body.decode("utf-8") or "{}"), None
        boundary = kind.split("boundary=", 1)[1].strip('"').encode("ascii")
        arguments, meta, file_name, data_in = {}, {}, None, None
        for chunk in body.split(b"--" + boundary)[1:-1]:
            head, _, data = chunk[2:].partition(b"\r\n\r\n")
            data = data[:-2]
            disposition = head.decode("utf-8")
            name = _parameter(header=disposition, key="name")
            if name == "arguments":
                arguments = json.loads(data.decode("utf-8"))
            elif name == "upload":
                meta = json.loads(data.decode("utf-8"))
            elif name == "file":
                # PHP keeps only what follows the last / or \ of an upload's file name.
                file_name, data_in = re.split(r"[/\\]", _parameter(header=disposition, key="filename"))[-1], data
        if data_in is None:
            return arguments, None
        return arguments, (meta.get("name") or file_name, data_in)

    def _tool(self, fake, tool, arguments, upload):
        if tool in ("save", "vectorize") and upload is not None:
            file_name, data = upload
            with fake.lock:
                fake.saved.append({"tool": tool, "name": file_name, "sha256": hashlib.sha256(data).hexdigest(), "size": len(data), "arguments": arguments})
            if tool == "vectorize":
                return self._replay(fake=fake, tool=tool, arguments=arguments, first={"id": VECTORIZATION_ID, "status": "queued", "kind": "one_off"})
            first = {"asset_id": 7 + len(fake.saves_by_request), "name": arguments.get("name", file_name), "sha256": hashlib.sha256(data).hexdigest()}
            return self._replay(fake=fake, tool=tool, arguments=arguments, first=first)
        if tool == "vectorize":
            return self._replay(fake=fake, tool=tool, arguments=arguments, first={"id": VECTORIZATION_ID, "status": "queued", "kind": "asset"})
        if tool == "save":
            return self._json(status=422, payload={"error": "Send the file at local_path with the call, as the multipart field file."})
        if tool == "fetch":
            return self._copy(fake=fake, name="render.png")
        if tool == "vectorization" and "format" in arguments:
            return self._copy(fake=fake, name="render-vectorized." + arguments["format"])
        if tool == "vectorization":
            return self._json(status=200, payload={"result": {"id": arguments.get("id"), "status": "completed", "kind": "asset"}})
        if tool == "search" and str(arguments.get("query", "")).startswith("sleep:"):
            time.sleep(float(arguments["query"].split(":")[1]))
        return self._json(status=200, payload={"result": {"folders": [{"id": 1, "name": "Renders"}], "next_after_id": None}})

    def _replay(self, fake, tool, arguments, first):
        """A save or vectorize: the first call with a request_id is applied once, a repeat answers that first result
        with replayed true. The answer is cut short while cut_answers asks for it."""
        with fake.lock:
            key = (tool, arguments.get("request_id"))
            if key in fake.saves_by_request:
                result = dict(fake.saves_by_request[key], replayed=True)
            else:
                fake.saves_by_request[key] = first
                result = dict(first, replayed=False)
            cut = fake.cut_answers.get(tool, 0) > 0
            if cut:
                fake.cut_answers[tool] -= 1
        return self._json(status=200, payload={"result": result}, cut=cut)

    def _copy(self, fake, name):
        data = fake.fetch_bytes
        sha256 = hashlib.sha256(data).hexdigest()
        result = {"path": None, "name": None, "sha256": sha256, "size": len(data), "asset_id": 7, "reused": None, "note": "This is your own copy."}
        header = base64.b64encode(json.dumps({"name": name, "sha256": sha256, "size": len(data), "result": result}).encode("utf-8")).decode("ascii")
        sent = bytearray(data)
        if fake.corrupt_copy and sent:
            sent[0] ^= 0xFF
        self.send_response(200)
        self.send_header("Content-Type", "application/octet-stream")
        self.send_header("Content-Length", str(len(sent)))
        self.send_header("X-Library-Result", header)
        self.end_headers()
        try:
            self.wfile.write(bytes(sent))
        except OSError:
            pass  # The launcher reused an identical file and closed without reading.

    def _json(self, status, payload, headers=None, cut=False):
        data = payload if isinstance(payload, bytes) else json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/html" if isinstance(payload, bytes) else "application/json")
        self.send_header("Content-Length", str(len(data)))
        for key, value in (headers or {}).items():
            self.send_header(key, value)
        self.end_headers()
        if cut:
            # Half the promised body, then the connection drops.
            self.wfile.write(data[: len(data) // 2])
            self.close_connection = True
            return
        self.wfile.write(data)


def _parameter(header, key):
    """One quoted parameter of a Content-Disposition header, with PHP's backslash unescaping."""
    match = re.search(r';\s*%s="((?:[^"\\]|\\.)*)"' % re.escape(key), header)
    return re.sub(r"\\(.)", r"\1", match.group(1)) if match else None

#!/usr/bin/env python3
"""The local side of The Mothership's `mothership` library MCP server, run by each agent session on this machine.

The library itself runs on the site. Each tool call goes to its REST route over HTTPS, which runs the same validation and
operations as the stdio server (`php artisan mcp:start mothership`). Most sessions never touch the library, so this file
is deliberately small: it answers `initialize` and `tools/list` from tools_manifest.json without any network call.

The first `tools/call` loads bridge.py, which signs in with the agent API key in the Keychain and carries files over
HTTPS. After a quiet spell the process replaces itself with a fresh copy of this file and so returns to its idle size.
Python standard library only, 3.9 or later. Contract: docs/api/mothership-mcp.md.

    launcher.py                   serve MCP over stdin and stdout
    launcher.py --write-manifest  regenerate tools_manifest.json from this checkout's stdio server
    launcher.py --check-manifest  exit 1 when tools_manifest.json differs from this checkout's stdio server
"""
import os
import sys
import time

sys.dont_write_bytecode = True  # The deployed checkout stays exactly as the release left it.

# The C halves of the json module: importing json itself pulls in re and enum, which would be most of an idle session's
# memory.
try:
    from _json import encode_basestring_ascii as _quote, make_scanner as _make_scanner
except ImportError:
    from json.encoder import encode_basestring_ascii as _quote
    from json.scanner import py_make_scanner as _make_scanner

HERE = os.path.dirname(os.path.abspath(__file__))
PROTOCOL_KEY = "MOTHERSHIP_MCP_LAUNCHER_PROTOCOL"
RUN_KEY = "MOTHERSHIP_MCP_LAUNCHER_RUN_ID"
POLL_SECONDS = 0.25


def _refuse_constant(name):
    raise ValueError("JSON does not allow " + name)


class _ScanRules:
    strict = True
    object_hook = None
    object_pairs_hook = None
    parse_float = float
    parse_int = int
    parse_constant = staticmethod(_refuse_constant)
    memo = {}

    def __getattr__(self, _name):
        return None  # Hooks that newer Pythons add to the scanner (3.15 added array_hook) stay switched off.


_scan = _make_scanner(_ScanRules())


def loads(data):
    """One JSON document from bytes. Raises ValueError or StopIteration when it is not valid."""
    text = data.decode("utf-8").strip(" \t\r\n")
    try:
        value, end = _scan(text, 0)
    except SystemError:
        # Python 3.10 and 3.11 build the error from json.decoder only when something has already imported it, and this
        # file does not, so the C scanner fails without an exception and the interpreter raises SystemError instead.
        raise ValueError("Not a JSON document") from None
    if end != len(text):
        raise ValueError("Extra data after the JSON document")
    return value


def dumps(value):
    if isinstance(value, str):
        return _quote(value)
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, (int, float)):
        return repr(value)
    if isinstance(value, dict):
        return "{" + ",".join(_quote(str(key)) + ":" + dumps(item) for key, item in value.items()) + "}"
    if isinstance(value, (list, tuple)):
        return "[" + ",".join(dumps(item) for item in value) + "]"
    raise TypeError("Cannot write %s as JSON" % type(value).__name__)


class Log:
    """Redacted JSONL at a fixed path, on only with MOTHERSHIP_MCP_LAUNCHER_LOG=1.

    Events carry operation names, outcomes, HTTP statuses, sizes, counts and timings. Never a path, file name, title,
    argument, credential or byte of content.
    """

    def __init__(self, environ):
        default = os.path.join(os.path.expanduser("~"), "Library/Logs/mothership-mcp-launcher.jsonl")
        enabled = environ.get("MOTHERSHIP_MCP_LAUNCHER_LOG", "") in ("1", "true")
        self.path = environ.get("MOTHERSHIP_MCP_LAUNCHER_LOG_PATH", default) if enabled else None
        self.run_id = environ.get(RUN_KEY) or "%d-%d" % (os.getpid(), int(time.time()))

    def event(self, level, event, loc, ctx):
        if self.path is None:
            return
        now = time.time()
        stamp = time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(now)) + ".%03dZ" % int((now % 1) * 1000)
        line = {"ts": stamp, "run_id": self.run_id, "level": level, "event": event, "loc": loc, "ctx": ctx}
        try:
            os.makedirs(os.path.dirname(self.path), exist_ok=True)
            descriptor = os.open(self.path, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600)
            try:
                os.write(descriptor, (dumps(line) + "\n").encode("utf-8"))
            finally:
                os.close(descriptor)
        except OSError:
            pass


class Launcher:
    """The MCP conversation with one agent session."""

    def __init__(self, environ):
        self.environ = environ
        self.log = Log(environ=environ)
        with open(os.path.join(HERE, "tools_manifest.json"), "rb") as handle:
            manifest = loads(handle.read())
        self.versions = manifest["protocolVersions"]
        self.server_info = manifest["serverInfo"]
        self.instructions = manifest["instructions"]
        self.tools_json = dumps(manifest["tools"])
        self.tool_names = [tool["name"] for tool in manifest["tools"]]
        self.protocol_version = environ.get(PROTOCOL_KEY) or self.versions[0]
        self.worker = None
        self.write_lock = None
        self.select = None
        self.pending = bytearray()

    def serve(self):
        self.log.event("info", "launcher.start", "Launcher.serve", {})
        try:
            while True:
                if self.worker is not None and not self._input_ready():
                    continue
                chunk = os.read(0, 65536)
                if not chunk:
                    return
                self.pending += chunk
                while True:
                    newline = self.pending.find(b"\n")
                    if newline < 0:
                        break
                    line = bytes(self.pending[:newline])
                    del self.pending[: newline + 1]
                    self.handle_line(line=line)
        finally:
            if self.worker is not None:
                self.worker.shutdown()
            self.log.event("info", "launcher.stop", "Launcher.serve", {})

    def _input_ready(self):
        ready, _, _ = self.select([0], [], [], POLL_SECONDS)
        if ready:
            return True
        if not self.pending and self.worker.recyclable():
            self._recycle()
        return False

    def _recycle(self):
        """Replaces this process with a fresh launcher. Unread input stays in the pipe for the new one to read."""
        self.worker.finish()
        self.worker = None
        self.log.event("info", "launcher.recycle", "Launcher._recycle", {})
        os.environ[PROTOCOL_KEY] = self.protocol_version
        os.environ[RUN_KEY] = self.log.run_id
        try:
            os.execv(sys.executable, getattr(sys, "orig_argv", [sys.executable] + sys.argv))
        except OSError:
            self.log.event("warn", "launcher.recycle_failed", "Launcher._recycle", {})

    def send(self, message):
        self._send_text(text=dumps(message))

    def _send_text(self, text):
        data = text.encode("utf-8") + b"\n"
        if self.write_lock is None:
            self._write(data=data)
            return
        with self.write_lock:
            self._write(data=data)

    def _write(self, data):
        try:
            while data:
                data = data[os.write(1, data):]
        except OSError:
            pass

    def handle_line(self, line):
        if not line.strip():
            return
        try:
            message = loads(line)
        except (ValueError, StopIteration, RecursionError):
            self.send({"jsonrpc": "2.0", "id": None, "error": {"code": -32700, "message": "Parse error"}})
            return
        if not isinstance(message, dict):
            self.send({"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": "Invalid Request"}})
            return
        method = message.get("method")
        params = message.get("params") if isinstance(message.get("params"), dict) else {}
        if "id" not in message:
            if method == "notifications/cancelled" and self.worker is not None:
                self.worker.cancel(request_id=params.get("requestId"))
            return
        request_id = message["id"]
        if method == "tools/call":
            self._ensure_worker().submit(request_id=request_id, params=params)
        elif method == "initialize":
            self.send({"jsonrpc": "2.0", "id": request_id, "result": self._initialize(params=params)})
        elif method == "tools/list":
            self._send_text(text='{"jsonrpc":"2.0","id":%s,"result":{"tools":%s}}' % (dumps(request_id), self.tools_json))
        elif method == "ping":
            self.send({"jsonrpc": "2.0", "id": request_id, "result": {}})
        else:
            self.send({"jsonrpc": "2.0", "id": request_id, "error": {"code": -32601, "message": "Method not found"}})

    def _initialize(self, params):
        requested = params.get("protocolVersion")
        self.protocol_version = requested if requested in self.versions else self.versions[0]
        return {
            "protocolVersion": self.protocol_version,
            "capabilities": {"tools": {"listChanged": False}},
            "serverInfo": self.server_info,
            "instructions": self.instructions,
        }

    def _ensure_worker(self):
        if self.worker is None:
            import select
            import signal
            import threading

            sys.path.insert(0, HERE)
            import bridge

            def stop(_signal, _frame):
                raise SystemExit(0)

            signal.signal(signal.SIGTERM, stop)
            signal.signal(signal.SIGINT, stop)
            self.select = select.select
            self.write_lock = threading.Lock()
            self.worker = bridge.Worker(
                settings=bridge.Settings(environ=self.environ),
                log=self.log,
                send=self.send,
                tool_names=self.tool_names,
            )
        return self.worker


def main(arguments):
    if arguments in (["--write-manifest"], ["--check-manifest"]):
        sys.path.insert(0, HERE)
        import bridge

        return bridge.write_manifest() if arguments == ["--write-manifest"] else bridge.check_manifest()
    if arguments:
        print(__doc__, file=sys.stderr)
        return 2
    Launcher(environ=os.environ).serve()
    return 0


if __name__ == "__main__":
    sys.exit(main(arguments=sys.argv[1:]))

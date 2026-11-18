#!/usr/bin/env python3
"""Stands in for Apple's `container` command in the launcher tests.

`exec ... php artisan mcp:start mothership` behaves like the library server; any other `exec` runs on this machine, so
the launcher's real shell scripts are exercised. Control files in FAKE_CONTAINER_STATE switch on failures.
"""
import hashlib
import json
import os
import signal
import sys
import time

STATE = os.environ["FAKE_CONTAINER_STATE"]
STAGING = os.environ["FAKE_STAGING_ROOT"]
SERVER_COMMAND = ["php", "artisan", "mcp:start", "mothership"]


def flag(name):
    return os.path.exists(os.path.join(STATE, name))


def append(name, line):
    with open(os.path.join(STATE, name), "a", encoding="utf-8") as handle:
        handle.write(line + "\n")


def structured(details):
    return {"content": [{"type": "text", "text": json.dumps(details)}], "structuredContent": details}


def refusal(message):
    return {"content": [{"type": "text", "text": message}], "isError": True}


def save(arguments):
    path = arguments["local_path"]
    if not path.startswith(STAGING + "/") or not os.path.isfile(path):
        return refusal("There is no file at local_path.")
    with open(path, "rb") as handle:
        data = handle.read()
    sha256 = hashlib.sha256(data).hexdigest()
    append("saved.jsonl", json.dumps({"name": os.path.basename(path), "sha256": sha256, "size": len(data)}))
    return structured({"asset_id": 7, "sha256": sha256})


def fetch(arguments):
    if flag("fetch_refuse"):
        return refusal("Not found.")
    directory = arguments["dest_dir"]
    if not directory.startswith(STAGING + "/"):
        return refusal("Cannot create dest_dir. Pass a folder this server can write to.")
    with open(os.path.join(STATE, "fetch_bytes"), "rb") as handle:
        data = handle.read()
    os.makedirs(directory, exist_ok=True)
    path = os.path.join(directory, "render.png")
    with open(path, "wb") as handle:
        handle.write(data)
    return structured({
        "path": path, "name": "render.png", "sha256": hashlib.sha256(data).hexdigest(), "size": len(data),
        "asset_id": 7, "reused": False,
    })


def call_tool(name, arguments):
    append("calls", name)
    if name == "library_save":
        return save(arguments)
    if name == "library_fetch":
        return fetch(arguments)
    if name == "library_update" and flag("crash_on_update"):
        os._exit(9)
    if name == "library_search" and str(arguments.get("query", "")).startswith("sleep:"):
        time.sleep(float(arguments["query"].split(":")[1]))
    return structured({"folders": [{"id": 1, "name": "Renders"}], "next_after_id": None})


def serve():
    append("starts", str(os.getpid()))
    running = os.path.join(STATE, "running")
    os.makedirs(running, exist_ok=True)
    marker = os.path.join(running, str(os.getpid()))
    open(marker, "w", encoding="utf-8").close()
    append("peaks", str(len(os.listdir(running))))
    with open(os.environ["FAKE_MANIFEST"], "r", encoding="utf-8") as handle:
        manifest = json.load(handle)
    if flag("tools_drift"):
        manifest["tools"] = manifest["tools"][:-1]
    try:
        for line in sys.stdin:
            message = json.loads(line)
            method = message.get("method")
            if "id" not in message:
                continue
            params = message.get("params") or {}
            if method == "initialize":
                append("handshakes", str(params.get("protocolVersion")))
                result = {
                    "protocolVersion": params.get("protocolVersion"), "capabilities": {"tools": {}},
                    "serverInfo": manifest["serverInfo"], "instructions": manifest["instructions"],
                }
            elif method == "tools/list":
                result = {"tools": manifest["tools"]}
            else:
                result = call_tool(name=params.get("name"), arguments=params.get("arguments") or {})
            sys.stdout.write(json.dumps({"jsonrpc": "2.0", "id": message["id"], "result": result}) + "\n")
            sys.stdout.flush()
    finally:
        os.unlink(marker)


def run_other(command):
    if command[:2] == ["sh", "-c"] and "sha256sum" in command[2] and flag("corrupt_receive"):
        sys.stdin.buffer.read()
        print("0" * 64 + "  -")
        return 0
    if command[0] == "cat" and flag("corrupt_cat"):
        with open(command[1], "rb") as handle:
            data = bytearray(handle.read())
        data[0] ^= 0xFF
        sys.stdout.buffer.write(bytes(data))
        return 0
    os.execvp(command[0], command)
    return 1


def main():
    signal.signal(signal.SIGTERM, lambda _signal, _frame: sys.exit(0))
    arguments = sys.argv[1:]
    if arguments[0] == "inspect":
        mount = {"destination": "/var/www/html/storage", "source": os.environ["FAKE_STORAGE_DIR"]}
        print(json.dumps([{"configuration": {"mounts": [mount]}}]))
        return 0
    if flag("stack_down"):
        return 1
    rest = arguments[1:]
    while rest[0].startswith("-"):
        rest = rest[1:] if rest[0] == "-i" else rest[2:]
    command = rest[1:]
    if command == SERVER_COMMAND:
        serve()
        return 0
    return run_other(command=command)


if __name__ == "__main__":
    sys.exit(main())

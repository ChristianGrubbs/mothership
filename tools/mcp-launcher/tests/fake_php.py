#!/usr/bin/env python3
"""Stands in for `php artisan mcp:start mothership` in the manifest tests: answers initialize and tools/list over stdio
from FAKE_MANIFEST, one tool short when FAKE_TOOLS_DRIFT is set."""
import json
import os
import sys


def main():
    with open(os.environ["FAKE_MANIFEST"], "r", encoding="utf-8") as handle:
        manifest = json.load(handle)
    if os.environ.get("FAKE_TOOLS_DRIFT"):
        manifest["tools"] = manifest["tools"][:-1]
    for line in sys.stdin:
        message = json.loads(line)
        if "id" not in message:
            continue
        if message.get("method") == "initialize":
            result = {
                "protocolVersion": message["params"].get("protocolVersion"), "capabilities": {"tools": {}},
                "serverInfo": manifest["serverInfo"], "instructions": manifest["instructions"],
            }
        else:
            result = {"tools": manifest["tools"]}
        sys.stdout.write(json.dumps({"jsonrpc": "2.0", "id": message["id"], "result": result}) + "\n")
        sys.stdout.flush()
    return 0


if __name__ == "__main__":
    sys.exit(main())

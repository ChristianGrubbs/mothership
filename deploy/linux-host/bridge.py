#!/usr/bin/env python3
"""Start one bridge (`ai` or `video`) from tools/studio, copied into the bridges image.

The AI bridge talks to Ollama on the Windows host. Under Hyper-V's Default Switch that host is
the VM's default gateway, and its address can change when Windows restarts, so it is read from
the routing table at every start unless AI_OLLAMA_URL is set. The container uses the host network.
"""
import os
import socket
import struct
import sys

RTF_GATEWAY = 0x2


def default_gateway() -> str:
    with open("/proc/net/route", encoding="ascii") as table:
        for line in table.readlines()[1:]:
            fields = line.split()
            if fields[1] == "00000000" and int(fields[3], 16) & RTF_GATEWAY:
                return socket.inet_ntoa(struct.pack("<L", int(fields[2], 16)))
    raise SystemExit("bridge: no default gateway in /proc/net/route")


def main() -> None:
    role = sys.argv[1] if len(sys.argv) > 1 else ""
    if role not in {"ai", "video"}:
        raise SystemExit("usage: bridge.py ai|video")
    if role == "ai" and not os.environ.get("AI_OLLAMA_URL"):
        os.environ["AI_OLLAMA_URL"] = "http://{}:{}".format(default_gateway(), os.environ.get("AI_OLLAMA_PORT", "11434"))
        print("bridge: AI_OLLAMA_URL={}".format(os.environ["AI_OLLAMA_URL"]), flush=True)
    server = "/app/{}-service/server.py".format(role)
    os.execv(sys.executable, [sys.executable, "-u", server])


if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""A tiny stand-in for the Studio Clop host service, used only by
OptimizerClientTest.php's memory-safety proof. Built on the same
http.server.ThreadingHTTPServer family the real host service
(tools/studio/clop-service/server.py) uses, rather than PHP's `php -S` dev
server, which mishandles a large streamed POST body's `Expect: 100-continue`
negotiation and corrupts the response — a fidelity choice, not a
convenience one; a stub built on a different HTTP stack than production
could pass this proof for the wrong reason.

Drains the uploaded body in chunks (never buffers it whole) and answers
with a real, finfo-sniffable JPEG header padded out to
OPTIMIZER_STUB_OUTPUT_BYTES, streamed back a chunk at a time.

Usage: python3 optimizer-stub-server.py <port>   (0 = any free port; the bound URL is printed)
"""
from __future__ import annotations

import base64
import os
import sys
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# A real 4x4 JPEG (PHP GD, quality 90) — just the header/structure a real optimized image would have.
JPEG_HEADER = base64.b64decode(
    "/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gOTAK"
    "/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU"
    "/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU"
    "/8AAEQgABAAEAwERAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A/KqgD//Z"
)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, format: str, *args: object) -> None:  # noqa: A002 - stdlib signature
        pass

    def do_GET(self) -> None:
        if self.path == "/health":
            body = b'{"ok":true}'
            self.send_response(HTTPStatus.OK)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        self.send_response(HTTPStatus.NOT_FOUND)
        self.end_headers()

    def do_POST(self) -> None:
        if not self.path.startswith("/optimize"):
            self.send_response(HTTPStatus.NOT_FOUND)
            self.end_headers()
            return

        length = int(self.headers.get("Content-Length", "0"))
        remaining = length

        while remaining > 0:
            chunk = self.rfile.read(min(remaining, 1024 * 1024))
            if not chunk:
                break
            remaining -= len(chunk)

        output_bytes = int(os.environ.get("OPTIMIZER_STUB_OUTPUT_BYTES", str(32 * 1024 * 1024)))
        self.send_response(HTTPStatus.OK)
        self.send_header("X-Clop-Extension", "jpg")
        self.send_header("Content-Type", "image/jpeg")
        self.send_header("Content-Length", str(output_bytes))
        self.end_headers()

        self.wfile.write(JPEG_HEADER)
        remaining = output_bytes - len(JPEG_HEADER)
        chunk = b"\x00" * 65536

        while remaining > 0:
            write = min(remaining, len(chunk))
            self.wfile.write(chunk[:write])
            remaining -= write


class Server(ThreadingHTTPServer):
    daemon_threads = True


def main() -> int:
    port = int(sys.argv[1])
    server = Server(("127.0.0.1", port), Handler)
    print(f"optimizer stub listening on http://127.0.0.1:{server.server_address[1]}", flush=True)
    server.serve_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

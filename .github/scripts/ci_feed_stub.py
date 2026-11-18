#!/usr/bin/env python3
"""Local stand-in for the Signal/Trending feed API during CI (2026-10-14 nightly R1).

Every admin page mounts the announcement ticker, which fetches
/admin/home/service/rss/v1/announcements through HomeServiceController's proxy. Unconfigured, the
proxy calls whatever MOTHERSHIP_HOME_RSS_API names. From the CI runners that answered 401 headers
whose body never completed, so the ticker's request stayed open until its 20-second abort and
every `waitUntil: 'networkidle'` navigation waited about 21 s: most of each Playwright shard's
80 minutes.

The workflows point MOTHERSHIP_HOME_RSS_API and MOTHERSHIP_HOME_TRENDING_API here. This answers
every GET with a complete, empty JSON document, so the ticker settles at once and CI never calls
the public internet.

  python3 ci_feed_stub.py <port>
"""

from __future__ import annotations

import json
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

BODIES = {
    "announcements": {"stories": [], "sources": []},
    "feeds": {"feeds": []},
    "stories": {"stories": []},
    "trending": {"repositories": [], "date": None},
}


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802 (http.server API)
        name = next((key for key in BODIES if "/v1/" + key in self.path), None)
        body = json.dumps(BODIES.get(name, {})).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format: str, *args: object) -> None:  # noqa: A002
        sys.stderr.write("feed-stub " + (format % args) + "\n")


if __name__ == "__main__":
    ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1])), Handler).serve_forever()

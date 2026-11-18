#!/usr/bin/env python3
"""Read-only FreshRSS adapter for the Home Signal news card, the announcement ticker and GitHub Trending."""
from __future__ import annotations

import html
import json
import os
import re
import sqlite3
import time
from announcements import Collector
from trending import Trending
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

DB_PATH = Path(os.environ.get("FRESHRSS_DB", "/data/db.sqlite"))
HOST = os.environ.get("HOST", "0.0.0.0")
PORT = int(os.environ.get("PORT", "8788"))
# MOTHERSHIP_ASSETS_ORIGIN names the app origin allowed to call this adapter. It has no default: unset, no
# remote origin is allowed (only the local preview origins below). ALLOWED_ORIGINS replaces the whole list.
ASSETS_ORIGIN = os.environ.get("MOTHERSHIP_ASSETS_ORIGIN", "").strip()
ALLOWED_ORIGINS = {
    item.strip() for item in os.environ.get(
        "ALLOWED_ORIGINS",
        ",".join(filter(None, [ASSETS_ORIGIN, "http://localhost:4173", "http://localhost:8080", "null"])),
    ).split(",") if item.strip()
}
TOKEN = os.environ.get("MOTHERSHIP_RSS_TOKEN", "")
ANNOUNCEMENTS = Collector(path=os.environ.get("SIGNAL_CACHE", "/state/announcements.json"))
TRENDING = Trending(path=os.environ.get("TRENDING_CACHE", "/state/trending.json"))
MAX_LIMIT = 100
TAG_RE = re.compile(r"<[^>]+>")
SPACE_RE = re.compile(r"\s+")
IMG_RE = re.compile(r"<img[^>]+src=[\"']([^\"']+)", re.I)


def connect() -> sqlite3.Connection:
    uri = f"file:{DB_PATH.as_posix()}?mode=ro"
    db = sqlite3.connect(uri, uri=True, timeout=4)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA query_only=ON")
    return db


def plain_text(value: str | None, limit: int = 280) -> str:
    value = html.unescape(TAG_RE.sub(" ", value or ""))
    value = SPACE_RE.sub(" ", value).strip()
    return value if len(value) <= limit else value[: limit - 1].rsplit(" ", 1)[0] + "…"


def image_from(content: str | None) -> str | None:
    match = IMG_RE.search(content or "")
    return html.unescape(match.group(1)) if match else None


def serialize_story(row: sqlite3.Row) -> dict:
    return {
        "id": str(row["id"]), "feedId": row["feed_id"], "source": row["source"],
        "title": row["title"], "author": (row["author"] or "").lstrip(";"),
        "url": row["link"], "publishedAt": row["date"] * 1000,
        "unread": not bool(row["is_read"]), "saved": bool(row["is_favorite"]),
        "excerpt": plain_text(row["content"]), "image": image_from(row["content"]),
    }


class Handler(BaseHTTPRequestHandler):
    server_version = "MothershipRSS/1.0"

    def log_message(self, fmt: str, *args) -> None:
        print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {self.address_string()} {fmt % args}")

    def _origin(self) -> str | None:
        origin = self.headers.get("Origin")
        return origin if origin in ALLOWED_ORIGINS else None

    def _authorized(self) -> bool:
        supplied = self.headers.get("Authorization", "")
        if TOKEN and supplied == f"Bearer {TOKEN}":
            return True
        # Browser access is limited to the explicit widget origin allowlist.
        # A future same-origin Mothership server proxy should use TOKEN instead.
        return self.headers.get("Origin") in ALLOWED_ORIGINS

    def _json(self, data: object, status: int = 200) -> None:
        body = json.dumps(data, ensure_ascii=False, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "private, max-age=30")
        self.send_header("Vary", "Origin")
        origin = self._origin()
        if origin:
            self.send_header("Access-Control-Allow-Origin", origin)
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "no-referrer")
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self) -> None:
        self.send_response(HTTPStatus.NO_CONTENT)
        origin = self._origin()
        if origin:
            self.send_header("Access-Control-Allow-Origin", origin)
        self.send_header("Access-Control-Allow-Headers", "Authorization, Content-Type")
        self.send_header("Access-Control-Allow-Methods", "GET, OPTIONS")
        self.send_header("Vary", "Origin")
        self.end_headers()

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path in ("/", "/health"):
            try:
                with connect() as db:
                    db.execute("SELECT 1").fetchone()
                return self._json({"ok": True, "service": "mothership-rss", "readOnly": True})
            except Exception as exc:
                return self._json({"ok": False, "error": type(exc).__name__}, 503)
        if not self._authorized():
            return self._json({"error": "unauthorized"}, 401)
        try:
            if parsed.path == "/v1/announcements":
                return self._json(ANNOUNCEMENTS.snapshot())
            if parsed.path == "/v1/trending":
                query = parse_qs(parsed.query)
                if query.get("refresh", ["0"])[0] == "1":
                    snapshot = TRENDING.refresh_now()
                    # A throttled call (forced=False) just rereads whatever is already cached, so its
                    # HTTP status follows the read, never the fetch. A forced call that actually hit
                    # GitHub and failed must not come back 200: refresh_now() overwrites Trending's
                    # error state with this attempt's own outcome (see _fetch_and_merge), so
                    # status=="error" here means *this* fetch failed, not a stale prior failure.
                    if snapshot.get("forced") and snapshot.get("status") == "error":
                        return self._json({"message": "GitHub Trending could not be reached. Please try again shortly."}, 502)
                    return self._json(snapshot)
                return self._json(TRENDING.snapshot())
            match = re.fullmatch(r"/v1/trending/(\d{4}-\d{2}-\d{2})", parsed.path)
            if match:
                day = TRENDING.day(match.group(1))
                return self._json(day) if day else self._json({"error": "not_found"}, 404)
            if parsed.path == "/v1/stories":
                return self.stories(parse_qs(parsed.query))
            return self._json({"error": "not_found"}, 404)
        except (sqlite3.Error, ValueError) as exc:
            return self._json({"error": "query_failed", "detail": str(exc)}, 500)

    def stories(self, query: dict[str, list[str]]) -> None:
        limit = min(MAX_LIMIT, max(1, int(query.get("limit", ["48"])[0])))
        feed_id = query.get("feedId", [""])[0]
        where, params = [], []
        if feed_id:
            where.append("e.id_feed=?"); params.append(int(feed_id))
        clause = " WHERE " + " AND ".join(where) if where else ""
        sql = f"""
          SELECT e.id, e.title, e.author, e.content, e.link, e.date,
                 e.is_read, e.is_favorite, e.id_feed feed_id, f.name source
          FROM entry e JOIN feed f ON f.id=e.id_feed
          {clause} ORDER BY e.date DESC LIMIT ?
        """
        params.append(limit)
        with connect() as db:
            rows = db.execute(sql, params).fetchall()
            total = db.execute("SELECT COUNT(*) FROM entry").fetchone()[0]
        self._json({"stories": [serialize_story(r) for r in rows], "total": total,
                    "count": len(rows), "syncedAt": int(time.time() * 1000)})


if __name__ == "__main__":
    if not DB_PATH.exists():
        raise SystemExit(f"FreshRSS database not found: {DB_PATH}")
    print(f"Mothership RSS adapter listening on http://{HOST}:{PORT} (db={DB_PATH})")
    ANNOUNCEMENTS.start()
    TRENDING.start()
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()

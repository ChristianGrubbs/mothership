#!/usr/bin/env python3
"""Private native AI sidecar for The Mothership smart search (docs/ops/studio-ai.md).

GET /health, POST /caption, POST /name, POST /embed. Stdlib only, Python 3.9+, one Ollama
call at a time. Deployed standalone into the service state directory by
tools/studio/ai_service.py; it must not import anything outside the stdlib.
"""

from __future__ import annotations

import json
import os
import pathlib
import socket
import threading
import urllib.error
import urllib.request
from datetime import datetime, timezone
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import List, Mapping, Optional, Tuple

PROMPT_VERSION = "2026-11-17.1"
VERSION = "1"
QUERY_INSTRUCT = (
    "Instruct: Given a search query, retrieve relevant files from a media library\nQuery: "
)
PROMPT = """You describe one file from a media library so staff can find it by search.
File name: {file_name}
Folder: {folder_path}
Kind: {kind}

Rules:
- Describe only what is visible in the images. Never guess.
- Never state or guess anyone's identity, age, gender, race, health or other sensitive attributes.
- Name a building or place only if its name is legible in the image.
- If there are no people, do not mention people.
- tags: choose only from the allowed list, only when clearly shown; an empty list is fine.
- keywords: up to 12 short search words or phrases for things that are clearly visible.
- visible_text: legible text in the image, verbatim, or an empty string.
- caption: one or two plain sentences."""

NAME_PROMPT_VERSION = "2026-11-18.1"
NAME_PROMPT = """You name one photo or video file for a media library.
You get the AI description of the file and facts from its metadata. Return:
- subject: 2 to 6 plain words naming the event or, if there is none, the main subject. Examples: "product launch keynote", "team offsite hike", "office building exterior", "esports arena gaming".
- place: the building or place shown, 1 to 4 words, or an empty string.
- title: a readable title of at most 10 words, for example "Product Launch Keynote, Main Hall".
- event: the exact title of the calendar event you used, or an empty string.

Rules:
- Calendar events are only candidates that happened around the capture time. Use one only when the description fits it (the same kind of activity, and the place agrees if both are known). Otherwise ignore them all.
- For place, prefer a building named in the visible text, then the GPS building, then the event's location. Never invent a place.
- Never name or guess anyone's identity, age, gender, race, health or other sensitive attributes.
- Do not put dates, camera names or file numbers in subject or title.

Description: {caption}
Keywords: {keywords}
Visible text: {visible_text}
AI tags: {tags}
Original file name: {file_name}
Folder: {folder_path}
Captured: {captured_at}
Campus (from GPS): {campus}
Nearest building (from GPS): {building}
Calendar events at that time:
{events}"""

# Python 3.9: socket.timeout is not TimeoutError (they were merged in 3.10).
_TIMEOUTS = (TimeoutError, socket.timeout)


class InvalidRequest(ValueError):
    def __init__(self, code: str, status: HTTPStatus = HTTPStatus.BAD_REQUEST) -> None:
        super().__init__(code)
        self.code = code
        self.status = status


class OllamaFailure(RuntimeError):
    def __init__(self, code: str, status: HTTPStatus) -> None:
        super().__init__(code)
        self.code = code
        self.status = status


class ServerConfig:
    def __init__(self) -> None:
        self.host = os.environ.get("AI_HOST", "127.0.0.1")
        self.port = int(os.environ.get("AI_PORT", "18767"))
        self.ollama = os.environ.get("AI_OLLAMA_URL", "http://127.0.0.1:11434").rstrip("/")
        self.caption_model = os.environ.get("AI_CAPTION_MODEL", "qwen3.5:9b")
        self.embed_model = os.environ.get("AI_EMBED_MODEL", "qwen3-embedding:0.6b")
        self.max_bytes = int(os.environ.get("AI_MAX_BYTES", str(32 * 1024 * 1024)))
        self.caption_timeout = float(os.environ.get("AI_CAPTION_TIMEOUT", "120"))
        self.embed_timeout = float(os.environ.get("AI_EMBED_TIMEOUT", "30"))
        self.name_timeout = float(os.environ.get("AI_NAME_TIMEOUT", "60"))
        self.num_ctx = int(os.environ.get("AI_NUM_CTX", "8192"))


class EventLog:
    def __init__(self) -> None:
        self.enabled = os.environ.get("AI_SERVICE_LOG", "").lower() in {"1", "true", "yes", "on"}
        default_path = pathlib.Path(__file__).resolve().parent / "events.jsonl"
        self.path = pathlib.Path(os.environ.get("AI_SERVICE_LOG_PATH", str(default_path)))
        self._lock = threading.Lock()

    def write(self, event: str, ctx: Optional[Mapping[str, object]] = None, level: str = "info") -> None:
        if not self.enabled:
            return
        record = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "run_id": str((ctx or {}).get("run_id", "")),
            "level": level,
            "event": event,
            "loc": "studio.ai_service.server",
            "ctx": dict(ctx or {}),
        }
        with self._lock:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            with self.path.open("a", encoding="utf-8") as handle:
                handle.write(json.dumps(record, sort_keys=True) + "\n")


class Ollama:
    """One inference at a time per model. Captions and embeddings use separate locks: a search's
    query embedding (2 s budget in the app) must never queue behind a 6-20 s caption, and Ollama
    serves the two models concurrently."""

    def __init__(self, config: ServerConfig) -> None:
        self.config = config
        self.caption_lock = threading.Lock()
        self.embed_lock = threading.Lock()

    def _post(self, path: str, payload: Mapping[str, object], timeout: float, lock: threading.Lock) -> dict:
        request = urllib.request.Request(
            self.config.ollama + path,
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with lock:
            try:
                with urllib.request.urlopen(request, timeout=timeout) as response:
                    decoded = json.loads(response.read().decode("utf-8"))
            except _TIMEOUTS as error:
                raise OllamaFailure("ollama_timeout", HTTPStatus.GATEWAY_TIMEOUT) from error
            except urllib.error.URLError as error:
                if isinstance(getattr(error, "reason", None), _TIMEOUTS):
                    raise OllamaFailure("ollama_timeout", HTTPStatus.GATEWAY_TIMEOUT) from error
                raise OllamaFailure("ollama_failed", HTTPStatus.BAD_GATEWAY) from error
            except (ValueError, OSError) as error:
                raise OllamaFailure("ollama_failed", HTTPStatus.BAD_GATEWAY) from error
        if not isinstance(decoded, dict):
            raise OllamaFailure("ollama_failed", HTTPStatus.BAD_GATEWAY)
        return decoded

    def models(self) -> Tuple[bool, set]:
        try:
            with urllib.request.urlopen(self.config.ollama + "/api/tags", timeout=3) as response:
                names = {m.get("name", "") for m in json.loads(response.read().decode("utf-8")).get("models", [])}
            return True, names
        except (urllib.error.URLError, ValueError, OSError, AttributeError):
            return False, set()

    def caption(self, images: List[str], context: Mapping[str, str], vocabulary: List[str]) -> dict:
        vocabulary = list(dict.fromkeys(vocabulary))
        schema = {
            "type": "object",
            "properties": {
                "caption": {"type": "string"},
                "tags": {"type": "array", "items": {"type": "string", "enum": vocabulary}},
                "keywords": {"type": "array", "items": {"type": "string"}},
                "visible_text": {"type": "string"},
            },
            "required": ["caption", "tags", "keywords", "visible_text"],
        }
        prompt = PROMPT.format(
            file_name=context.get("file_name", ""),
            folder_path=context.get("folder_path", ""),
            kind=context.get("kind", ""),
        ) + "\nAllowed tags: " + ", ".join(vocabulary)
        body = self._post(
            "/api/chat",
            {
                "model": self.config.caption_model,
                "messages": [{"role": "user", "content": prompt, "images": images}],
                "stream": False,
                "think": False,
                "format": schema,
                "keep_alive": "30m",
                "options": {"temperature": 0, "num_ctx": self.config.num_ctx},
            },
            timeout=self.config.caption_timeout,
            lock=self.caption_lock,
        )
        try:
            parsed = json.loads(body["message"]["content"])
        except (KeyError, TypeError, ValueError) as error:
            raise OllamaFailure("ollama_failed", HTTPStatus.BAD_GATEWAY) from error
        if not isinstance(parsed, dict):
            raise OllamaFailure("ollama_failed", HTTPStatus.BAD_GATEWAY)
        allowed = set(vocabulary)
        tags: List[str] = []
        raw_tags = parsed.get("tags")
        for tag in raw_tags if isinstance(raw_tags, list) else []:
            if isinstance(tag, str) and tag in allowed and tag not in tags:
                tags.append(tag)
        raw_keywords = parsed.get("keywords")
        keywords = [
            k.strip()[:80] for k in (raw_keywords if isinstance(raw_keywords, list) else [])
            if isinstance(k, str) and k.strip()
        ][:12]
        return {
            "caption": str(parsed.get("caption") or "").strip()[:1000],
            "tags": tags,
            "keywords": keywords,
            "visible_text": str(parsed.get("visible_text") or "").strip()[:2000],
            "model": self.config.caption_model,
            "prompt_version": PROMPT_VERSION,
        }

    def name(self, context: Mapping[str, object]) -> dict:
        """Text only: names a file from its existing description, so it shares the caption model and its lock."""
        events = context.get("events") or []
        lines = [
            "- {title} | {location} | {start} to {end}".format(**event) for event in events
        ] or ["(none)"]
        prompt = NAME_PROMPT.format(
            caption=context.get("caption", ""),
            keywords=", ".join(context.get("keywords") or []),
            visible_text=context.get("visible_text", ""),
            tags=", ".join(context.get("tags") or []),
            file_name=context.get("file_name", ""),
            folder_path=context.get("folder_path", ""),
            captured_at=context.get("captured_at", "") or "unknown",
            campus=context.get("campus", "") or "unknown",
            building=context.get("building", "") or "unknown",
            events="\n".join(lines),
        )
        schema = {
            "type": "object",
            "properties": {
                "subject": {"type": "string"},
                "place": {"type": "string"},
                "title": {"type": "string"},
                "event": {"type": "string"},
            },
            "required": ["subject", "place", "title", "event"],
        }
        body = self._post(
            "/api/chat",
            {
                "model": self.config.caption_model,
                "messages": [{"role": "user", "content": prompt}],
                "stream": False,
                "think": False,
                "format": schema,
                "keep_alive": "30m",
                "options": {"temperature": 0, "num_ctx": self.config.num_ctx},
            },
            timeout=self.config.name_timeout,
            lock=self.caption_lock,
        )
        try:
            parsed = json.loads(body["message"]["content"])
        except (KeyError, TypeError, ValueError) as error:
            raise OllamaFailure("ollama_failed", HTTPStatus.BAD_GATEWAY) from error
        if not isinstance(parsed, dict):
            raise OllamaFailure("ollama_failed", HTTPStatus.BAD_GATEWAY)
        titles = {event["title"] for event in events}
        event = str(parsed.get("event") or "").strip()
        return {
            "subject": str(parsed.get("subject") or "").strip()[:120],
            "place": str(parsed.get("place") or "").strip()[:80],
            "title": str(parsed.get("title") or "").strip()[:160],
            # Only an event that was offered counts; a made-up one is dropped.
            "event": event if event in titles else "",
            "model": self.config.caption_model,
            "prompt_version": NAME_PROMPT_VERSION,
        }

    def embed(self, texts: List[str], kind: str) -> dict:
        inputs = [QUERY_INSTRUCT + t if kind == "query" else t for t in texts]
        body = self._post(
            "/api/embed",
            {"model": self.config.embed_model, "input": inputs, "keep_alive": "30m"},
            timeout=self.config.embed_timeout,
            lock=self.embed_lock,
        )
        vectors = body.get("embeddings")
        if not isinstance(vectors, list) or len(vectors) != len(texts):
            raise OllamaFailure("ollama_failed", HTTPStatus.BAD_GATEWAY)
        return {"vectors": vectors, "model": self.config.embed_model}


def validate_caption(payload: object) -> Tuple[List[str], dict, List[str]]:
    if not isinstance(payload, dict):
        raise InvalidRequest("invalid_body")
    images = payload.get("images")
    vocabulary = payload.get("vocabulary")
    context = payload.get("context") or {}
    if not isinstance(images, list) or not 1 <= len(images) <= 4 or not all(isinstance(i, str) and i for i in images):
        raise InvalidRequest("invalid_images")
    if not isinstance(vocabulary, list) or not 1 <= len(vocabulary) <= 200 or not all(isinstance(v, str) and v for v in vocabulary):
        raise InvalidRequest("invalid_vocabulary")
    if not isinstance(context, dict):
        raise InvalidRequest("invalid_context")
    clean = {k: str(context.get(k, ""))[:500] for k in ("file_name", "folder_path", "kind")}
    return images, clean, vocabulary


def validate_name(payload: object) -> dict:
    if not isinstance(payload, dict):
        raise InvalidRequest("invalid_body")
    caption = payload.get("caption")
    if not isinstance(caption, str) or not caption.strip():
        raise InvalidRequest("invalid_caption")
    clean: dict = {"caption": caption.strip()[:2000]}
    for key, cap in (("visible_text", 1000), ("file_name", 255), ("folder_path", 500),
                     ("captured_at", 40), ("campus", 120), ("building", 120)):
        value = payload.get(key, "")
        clean[key] = (value if isinstance(value, str) else "").strip()[:cap]
    for key in ("keywords", "tags"):
        value = payload.get(key) or []
        if not isinstance(value, list):
            raise InvalidRequest("invalid_" + key)
        clean[key] = [v.strip()[:80] for v in value if isinstance(v, str) and v.strip()][:20]
    events = payload.get("events") or []
    if not isinstance(events, list) or len(events) > 12:
        raise InvalidRequest("invalid_events")
    clean["events"] = []
    for event in events:
        if not isinstance(event, dict) or not isinstance(event.get("title"), str) or not event["title"].strip():
            raise InvalidRequest("invalid_events")
        clean["events"].append({
            k: (event.get(k) if isinstance(event.get(k), str) else "").strip()[:160]
            for k in ("title", "location", "start", "end")
        })
    return clean


def validate_embed(payload: object) -> Tuple[List[str], str]:
    if not isinstance(payload, dict):
        raise InvalidRequest("invalid_body")
    texts = payload.get("texts")
    kind = payload.get("kind", "document")
    if kind not in ("document", "query"):
        raise InvalidRequest("invalid_kind")
    if not isinstance(texts, list) or not 1 <= len(texts) <= 64 or not all(isinstance(t, str) and t.strip() for t in texts):
        raise InvalidRequest("invalid_texts")
    return [t[:8000] for t in texts], kind


def make_handler(config: ServerConfig, ollama: Ollama, log: EventLog):
    class Handler(BaseHTTPRequestHandler):
        server_version = "mothership-ai/" + VERSION

        def log_message(self, format: str, *args: object) -> None:  # noqa: A002 - stdlib signature
            return

        def _send(self, status: HTTPStatus, body: Mapping[str, object]) -> None:
            data = json.dumps(body).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def _json_body(self) -> object:
            try:
                length = int(self.headers.get("Content-Length", "0"))
            except ValueError as error:
                raise InvalidRequest("invalid_length") from error
            if length <= 0:
                raise InvalidRequest("invalid_body")
            if length > config.max_bytes:
                raise InvalidRequest("too_large", HTTPStatus.REQUEST_ENTITY_TOO_LARGE)
            try:
                return json.loads(self.rfile.read(length).decode("utf-8"))
            except (UnicodeDecodeError, ValueError) as error:
                raise InvalidRequest("invalid_json") from error

        def do_GET(self) -> None:  # noqa: N802 - stdlib name
            route = self.path.split("?")[0]
            if route == "/live":
                # Liveness only: never touches Ollama, so a stalled Ollama cannot fail startup checks.
                self._send(HTTPStatus.OK, {"ok": True, "version": VERSION})
                return
            if route != "/health":
                self._send(HTTPStatus.NOT_FOUND, {"error": "not_found"})
                return
            up, names = ollama.models()
            self._send(HTTPStatus.OK, {
                "ok": True,
                "version": VERSION,
                "ollama": up,
                "models": {"caption": config.caption_model in names, "embed": config.embed_model in names},
            })

        def do_POST(self) -> None:  # noqa: N802 - stdlib name
            route = self.path.split("?")[0]
            try:
                if route == "/caption":
                    images, context, vocabulary = validate_caption(self._json_body())
                    result = ollama.caption(images=images, context=context, vocabulary=vocabulary)
                    log.write("caption.ok", {"images": len(images), "tags": len(result["tags"])})
                elif route == "/name":
                    context = validate_name(self._json_body())
                    result = ollama.name(context=context)
                    log.write("name.ok", {"events": len(context["events"]), "event_used": bool(result["event"])})
                elif route == "/embed":
                    texts, kind = validate_embed(self._json_body())
                    result = ollama.embed(texts=texts, kind=kind)
                    log.write("embed.ok", {"texts": len(texts), "kind": kind})
                else:
                    self._send(HTTPStatus.NOT_FOUND, {"error": "not_found"})
                    return
            except InvalidRequest as error:
                log.write("request.invalid", {"route": route, "code": error.code}, level="warn")
                self._send(error.status, {"error": error.code})
                return
            except OllamaFailure as error:
                log.write("ollama.failed", {"route": route, "code": error.code}, level="error")
                self._send(error.status, {"error": error.code})
                return
            self._send(HTTPStatus.OK, result)

    return Handler


def main() -> int:
    config = ServerConfig()
    log = EventLog()
    server = ThreadingHTTPServer((config.host, config.port), make_handler(config, Ollama(config), log))
    log.write("server.start", {"host": config.host, "port": config.port})
    try:
        server.serve_forever()
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

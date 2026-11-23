"""The working half of the library MCP launcher, imported by launcher.py on a session's first tool call.

Each tool call goes to The Mothership's REST route for that tool, POST /api/v1/rest/library/tools/<tool>, which runs the
same validation and operations as the stdio server (LibraryTool::perform, LibraryOperations) as the API key's own admin.
This side signs in with the agent API credentials in the Keychain, carries files over HTTPS, and writes copies under the
server's naming rules. Python standard library only. Contract: docs/api/mothership-mcp.md.
"""
from __future__ import annotations

import base64
import hashlib
import http.client
import json
import os
import queue
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

MANIFEST_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tools_manifest.json")
CHECKOUT_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SERVER_COMMAND = ("artisan", "mcp:start", "mothership")
ROUTE_PREFIX = "/api/v1/rest/library/tools/"
TOOL_PREFIX = "library_"
# The deployed stack's PHP upload_max_filesize: the largest file one HTTPS upload carries.
DEFAULT_MAX_FILE_BYTES = 50 * 1024 * 1024
CHUNK_BYTES = 1024 * 1024
# LibraryOperations::MAX_NAME_TRIES: how many name, name-2, name-3 ... a copy tries before giving up.
MAX_NAME_TRIES = 1000
CREDENTIAL_SERVICE = "mothership-agent-api"
TOKEN_SERVICE = "mothership-agent-api-token"
TOKEN_MARGIN_SECONDS = 60
# `security -i` reads one command per line; a longer line is cut off.
KEYCHAIN_LINE_LIMIT = 3900
# One call and two retries for a dropped connection or a busy gateway. Every library tool is idempotent (save and
# vectorize replay their request_id), so a retry never applies a call twice.
ATTEMPTS = 3
RETRY_STATUSES = (429, 502, 503, 504)
MAX_RETRY_SECONDS = 30.0
ERROR_BODY_BYTES = 1024 * 1024
USER_AGENT = "mothership-mcp-launcher/2"
UPLOAD_TOOLS = ("library_save", "library_vectorize")
NOT_ANSWERING = "The Mothership is not answering right now (%s). Try again shortly."


class LauncherFailure(Exception):
    """A refusal or failure the agent can act on; the message becomes the MCP tool error."""


class Settings:
    """Everything configurable, read once from the environment."""

    def __init__(self, environ) -> None:
        # The site's address comes only from the environment: this repository names no site.
        address = (environ.get("MOTHERSHIP_URL") or "").strip().rstrip("/")
        scheme, separator, rest = address.partition("://")
        # A URL scheme is case-insensitive; the launcher compares and sends the lower-case form.
        self.base_url = scheme.lower() + separator + rest if rest else address
        home = os.path.expanduser("~")
        self.state_dir = environ.get("MOTHERSHIP_MCP_STATE_DIR") or os.path.join(home, "Library/Caches/mothership-mcp")
        temporary = environ.get("TMPDIR", "/tmp").rstrip("/") or "/tmp"
        self.fetch_dir = environ.get("MOTHERSHIP_MCP_FETCH_DIR") or temporary + "/mothership-library"
        self.max_file_bytes = _positive_int(
            environ=environ, key="MOTHERSHIP_MCP_MAX_FILE_BYTES", default=DEFAULT_MAX_FILE_BYTES
        )
        self.idle_seconds = _positive_float(environ=environ, key="MOTHERSHIP_MCP_IDLE_SECONDS", default=15.0)
        self.timeout_seconds = _positive_float(environ=environ, key="MOTHERSHIP_MCP_TIMEOUT_SECONDS", default=120.0)
        self.security_bin = environ.get("MOTHERSHIP_MCP_SECURITY_BIN") or "/usr/bin/security"
        self.account = environ.get("USER") or environ.get("LOGNAME") or _login_name()
        self.php = environ.get("MOTHERSHIP_MCP_PHP") or "php"


def _login_name() -> str:
    import pwd

    return pwd.getpwuid(os.getuid()).pw_name


def _positive_int(environ, key: str, default: int) -> int:
    value = environ.get(key, "")
    return int(value) if value.isdigit() and int(value) > 0 else default


def _positive_float(environ, key: str, default: float) -> float:
    try:
        value = float(environ.get(key, ""))
    except ValueError:
        return default
    return value if value > 0 else default


class Keychain:
    """Generic passwords through macOS `security`, as the `mothership` CLI keeps them. Values never reach a command line."""

    def __init__(self, settings: Settings, log) -> None:
        self.settings = settings
        self.log = log

    def read(self, service: str):
        import subprocess

        command = [self.settings.security_bin, "find-generic-password", "-a", self.settings.account, "-s", service, "-w"]
        try:
            done = subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30)
        except (OSError, subprocess.SubprocessError):
            raise LauncherFailure("The Keychain cannot be read right now, so the library cannot sign in. Try again.") from None
        if done.returncode != 0:
            return None
        return done.stdout.decode("utf-8", "replace").strip()

    def write(self, service: str, value: str) -> None:
        """Stores through `security -i` on stdin, so the value never appears in a process listing."""
        import subprocess

        line = "add-generic-password -a %s -s %s -U -X %s\n" % (self.settings.account, service, value.encode("utf-8").hex())
        stored = False
        if len(line) <= KEYCHAIN_LINE_LIMIT:
            try:
                done = subprocess.run(
                    [self.settings.security_bin, "-i"], input=line.encode("utf-8"),
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30,
                )
                stored = done.returncode == 0
            except (OSError, subprocess.SubprocessError):
                stored = False
        if not stored:
            # Only the shared cache: the next call signs in again.
            self.log.event("warn", "keychain.write_failed", "Keychain.write", {"service": service})


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Refuses redirects, so the bearer credential is never sent to another location."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        return None


class Unreachable(LauncherFailure):
    """The site did not answer: no connection, a timeout, or a connection dropped mid-answer."""


class Http:
    """Requests on the configured site only, without following redirects."""

    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self.opener = urllib.request.build_opener(_NoRedirect)

    def open(self, request: urllib.request.Request) -> tuple:
        """Sends one request. Returns (status, headers, response) with the body unread."""
        try:
            response = self.opener.open(request, timeout=self.settings.timeout_seconds)
            return response.status, response.headers, response
        except urllib.error.HTTPError as error:
            return error.code, error.headers, error
        except urllib.error.URLError as error:
            raise Unreachable(NOT_ANSWERING % _cause(error=error.reason)) from None
        except (http.client.HTTPException, OSError) as error:
            raise Unreachable(NOT_ANSWERING % _cause(error=error)) from None


def _cause(error) -> str:
    if isinstance(error, (TimeoutError, OSError)) and "timed out" in str(error):
        return "timed out"
    text = str(error).strip()
    return text[:200] if text else type(error).__name__


def _read_json_object(response):
    """The JSON object of an answer that is not a file (a refusal, a sign-in), or None. Reads at most ERROR_BODY_BYTES."""
    try:
        raw = response.read(ERROR_BODY_BYTES)
    except (http.client.HTTPException, OSError):
        raw = b""
    finally:
        response.close()
    try:
        payload = json.loads(raw.decode("utf-8")) if raw else None
    except ValueError:
        return None
    return payload if isinstance(payload, dict) else None


class Credentials:
    """The bearer for the library's REST routes: the agent API key's password grant, cached in the Keychain like the CLI.

    TOKEN_SERVICE holds "<expires_at> <access_token>", shared with the `mothership` CLI and every other launcher. A refresh
    holds an flock, so launchers that find it stale at the same moment sign in once between them.
    """

    def __init__(self, settings: Settings, log, http: Http) -> None:
        self.settings = settings
        self.log = log
        self.http = http
        self.keychain = Keychain(settings=settings, log=log)
        self.token = None

    def current(self) -> str:
        if self.token is None:
            self.token = self._cached(stale=None) or self.refresh(stale=None)
        return self.token

    def refresh(self, stale) -> str:
        """A token other than stale: one another process has cached since, or a fresh sign-in."""
        with self._lock():
            token = self._cached(stale=stale)
            if token is None:
                token, expires_in = self._grant()
                self.keychain.write(service=TOKEN_SERVICE, value="%d %s" % (int(time.time()) + expires_in, token))
            self.token = token
            return token

    def _cached(self, stale):
        raw = self.keychain.read(service=TOKEN_SERVICE)
        expiry, _, token = (raw or "").partition(" ")
        if not expiry.isdigit() or int(expiry) - TOKEN_MARGIN_SECONDS <= time.time() or not token or token == stale:
            return None
        return token

    def _lock(self):
        settings = self.settings

        class _Held:
            def __enter__(held):  # noqa: N805
                import fcntl

                os.makedirs(settings.state_dir, mode=0o700, exist_ok=True)
                held.descriptor = os.open(os.path.join(settings.state_dir, "token.lock"), os.O_RDWR | os.O_CREAT, 0o600)
                deadline = time.monotonic() + settings.timeout_seconds + 10
                while True:
                    try:
                        fcntl.flock(held.descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                        return held
                    except OSError:
                        # A holder that hangs past one sign-in's timeout is stuck; sign in without it.
                        if time.monotonic() >= deadline:
                            return held
                        time.sleep(0.05)

            def __exit__(held, *_exc):  # noqa: N805
                os.close(held.descriptor)

        return _Held()

    def _grant(self) -> tuple:
        credentials = self._credentials()
        body = urllib.parse.urlencode({
            "grant_type": "password",
            "client_id": credentials["client_id"],
            "client_secret": credentials["client_secret"],
            "username": credentials["username"],
            "password": credentials["password"],
        }).encode("utf-8")
        request = urllib.request.Request(
            self.settings.base_url + "/oauth/token", data=body, method="POST",
            headers={"Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded", "User-Agent": USER_AGENT},
        )
        status, _, response = self.http.open(request=request)
        payload = _read_json_object(response=response)
        self.log.event("info" if status == 200 else "warn", "token.grant", "Credentials._grant", {"status": status})
        token = payload.get("access_token") if payload else None
        if status == 200 and isinstance(token, str) and token:
            expires_in = payload.get("expires_in")
            return token, expires_in if isinstance(expires_in, int) and expires_in > 0 else 3600
        if status >= 500 or status == 429:
            raise LauncherFailure(NOT_ANSWERING % ("HTTP %d" % status))
        if status == 200:
            raise LauncherFailure(NOT_ANSWERING % "its sign-in answer held no access token")
        raise LauncherFailure(
            "The Mothership refused the library sign-in (HTTP %d). Check the agent API credentials in the Keychain item %s."
            % (status, CREDENTIAL_SERVICE)
        )

    def _credentials(self) -> dict:
        raw = self.keychain.read(service=CREDENTIAL_SERVICE)
        if raw is None:
            raise LauncherFailure(
                "The Keychain item %s is missing, so the library cannot sign in. Store the agent API credentials there "
                "(JSON with client_id, client_secret, username and password)." % CREDENTIAL_SERVICE
            )
        try:
            credentials = json.loads(raw)
        except ValueError:
            credentials = None
        if not isinstance(credentials, dict):
            raise LauncherFailure("The Keychain item %s is not a JSON object, so the library cannot sign in." % CREDENTIAL_SERVICE)
        missing = [key for key in ("client_id", "client_secret", "username", "password") if not isinstance(credentials.get(key), (str, int)) or credentials.get(key) == ""]
        if missing:
            raise LauncherFailure("The Keychain item %s lacks %s, so the library cannot sign in." % (CREDENTIAL_SERVICE, ", ".join(missing)))
        return {key: str(credentials[key]) for key in ("client_id", "client_secret", "username", "password")}


class Multipart:
    """A seekable multipart/form-data body over one file, so a retry sends the same bytes without holding them in memory."""

    def __init__(self, fields: list, file_field: str, file_name: str, file_path: str) -> None:
        self.boundary = "mothership" + os.urandom(16).hex()
        self.content_type = "multipart/form-data; boundary=" + self.boundary
        self.parts = []
        for name, value in fields:
            self.parts.append(
                ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n' % (self.boundary, _quoted(text=name))).encode("utf-8")
                + value.encode("utf-8") + b"\r\n"
            )
        head = '--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (
            self.boundary, _quoted(text=file_field), _quoted(text=file_name),
        )
        self.parts += [head.encode("utf-8"), file_path, b"\r\n", ("--%s--\r\n" % self.boundary).encode("utf-8")]
        self.length = sum(os.path.getsize(part) if isinstance(part, str) else len(part) for part in self.parts)
        self.index = 0
        self.handle = None

    def read(self, size: int = -1) -> bytes:
        size = CHUNK_BYTES if size is None or size < 0 else size
        while self.index < len(self.parts):
            part = self.parts[self.index]
            if isinstance(part, bytes):
                self.index += 1
                return part
            if self.handle is None:
                self.handle = open(part, "rb")
            chunk = self.handle.read(size)
            if chunk:
                return chunk
            self.handle.close()
            self.handle = None
            self.index += 1
        return b""

    def close(self) -> None:
        if self.handle is not None:
            self.handle.close()
            self.handle = None


def _quoted(text: str) -> str:
    """A quoted-string for a multipart header, escaped the way PHP's form parser reads it."""
    return text.replace("\\", "\\\\").replace('"', '\\"').replace("\r", " ").replace("\n", " ")


class LocalFiles:
    """The files on this machine: the server's rules for local_path and dest_dir, checked copies in and out."""

    def __init__(self, settings: Settings, log) -> None:
        self.settings = settings
        self.log = log

    def source(self, path: str) -> tuple:
        """The server's rules for local_path, checked before anything is sent. Returns (real path, size)."""
        import stat

        if not path.startswith("/"):
            raise LauncherFailure("local_path must be an absolute path on this machine.")
        real = os.path.realpath(path)
        try:
            details = os.stat(real)
        except OSError:
            raise LauncherFailure("There is no file at local_path.") from None
        if not stat.S_ISREG(details.st_mode):
            raise LauncherFailure("There is no file at local_path.")
        if not os.access(real, os.R_OK):
            raise LauncherFailure("The server cannot read the file at local_path.")
        if details.st_size == 0:
            raise LauncherFailure("The file at local_path is empty, so nothing was saved.")
        limit = self.settings.max_file_bytes
        if details.st_size > limit:
            raise LauncherFailure(
                "The file is %s; the library limit is %s (MOTHERSHIP_MCP_MAX_FILE_BYTES), so nothing was saved."
                % (_megabytes(bytes_count=details.st_size), _megabytes(bytes_count=limit))
            )
        return real, details.st_size

    def snapshot(self, real_path: str, size: int) -> tuple:
        """A private copy of the file to upload, so a tool still writing the original cannot change what is sent.

        Returns (folder, path); the caller removes the folder.
        """
        import shutil
        import tempfile

        folder = tempfile.mkdtemp(prefix="mothership-mcp-")
        path = os.path.join(folder, "upload")
        copied = 0
        try:
            with open(real_path, "rb") as source:
                before = os.fstat(source.fileno())
                descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                with os.fdopen(descriptor, "wb") as target:
                    for chunk in iter(lambda: source.read(CHUNK_BYTES), b""):
                        target.write(chunk)
                        copied += len(chunk)
                after = os.fstat(source.fileno())
        except OSError:
            shutil.rmtree(folder, True)
            raise LauncherFailure("The file at local_path could not be read in full (is the disk full?), so nothing was saved.") from None
        if copied != size or (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns):
            shutil.rmtree(folder, True)
            raise LauncherFailure("The file at local_path changed while it was being read. Save it again once it is finished.")
        return folder, path

    def destination(self, requested) -> str:
        """The server's rules for dest_dir. Returns the real folder, created when missing."""
        directory = requested if requested is not None else self.settings.fetch_dir
        if not directory.startswith("/"):
            raise LauncherFailure("dest_dir must be an absolute path on this machine.")
        try:
            os.makedirs(directory, mode=0o700, exist_ok=True)
        except OSError:
            raise LauncherFailure("Cannot create dest_dir. Pass a folder this server can write to.") from None
        real = os.path.realpath(directory)
        if not os.path.isdir(real) or not os.access(real, os.W_OK):
            raise LauncherFailure("Cannot write to dest_dir. Pass a folder this server can write to.")
        return real

    def bring_out(self, response, name: str, size: int, sha256: str, directory: str) -> tuple:
        """Writes the streamed bytes to directory under the server's naming rules. Returns (path, reused)."""
        base, extension = _split_name(name=name)
        for attempt in range(1, MAX_NAME_TRIES + 1):
            candidate = _numbered(directory=directory, base=base, extension=extension, attempt=attempt)
            if not os.path.lexists(candidate):
                break
            if _identical(path=candidate, size=size, sha256=sha256):
                # Reused without reading the body.
                return candidate, True
        part = self._stream_out(response=response, size=size, sha256=sha256, directory=directory)
        return self._claim_name(part=part, name_parts=(base, extension), size=size, sha256=sha256, directory=directory)

    def _stream_out(self, response, size: int, sha256: str, directory: str) -> str:
        started = time.monotonic()
        part = os.path.join(directory, "." + os.urandom(8).hex() + ".mothership-part")
        try:
            descriptor = os.open(part, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o666)
        except OSError:
            raise LauncherFailure("Cannot write to dest_dir. Pass a folder this server can write to.") from None
        digest = hashlib.sha256()
        written = 0
        try:
            with os.fdopen(descriptor, "wb") as output:
                while written <= size:
                    try:
                        chunk = response.read(CHUNK_BYTES)
                    except (http.client.HTTPException, OSError):
                        chunk = None
                    if not chunk:
                        break
                    digest.update(chunk)
                    output.write(chunk)
                    written += len(chunk)
        except OSError:
            _remove(path=part)
            raise LauncherFailure("Writing the copy failed part way (is the disk full?). Nothing was written.") from None
        if written != size or digest.hexdigest() != sha256:
            _remove(path=part)
            self.log.event("error", "transfer.out", "LocalFiles._stream_out", {"outcome": "sha256_mismatch", "size": size, "received": written})
            raise LauncherFailure("The copy out of The Mothership did not match its recorded sha256, so nothing was written. Try again.")
        self.log.event(
            "info", "transfer.out", "LocalFiles._stream_out",
            {"outcome": "ok", "size": size, "seconds": round(time.monotonic() - started, 3)},
        )
        return part

    def _claim_name(self, part: str, name_parts: tuple, size: int, sha256: str, directory: str) -> tuple:
        """Takes the name with an exclusive create before the copy moves in, so two fetches never replace each other."""
        base, extension = name_parts
        for attempt in range(1, MAX_NAME_TRIES + 1):
            target = _numbered(directory=directory, base=base, extension=extension, attempt=attempt)
            if _identical(path=target, size=size, sha256=sha256):
                _remove(path=part)
                return target, True
            try:
                os.close(os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o666))
            except FileExistsError:
                continue
            except OSError:
                _remove(path=part)
                raise LauncherFailure("Cannot write to dest_dir. Pass a folder this server can write to.") from None
            try:
                os.rename(part, target)
            except OSError:
                _remove(path=part)
                _remove(path=target)
                raise LauncherFailure("Cannot write to dest_dir. Pass a folder this server can write to.") from None
            return target, False
        _remove(path=part)
        raise LauncherFailure("dest_dir already holds too many files named like %s%s. Pass another dest_dir." % (base, extension))


def _megabytes(bytes_count: int) -> str:
    return "{:,.1f} MB".format(bytes_count / 1048576)


def _split_name(name: str) -> tuple:
    """name.ext into (name, .ext); a leading dot is part of the name, as in LibraryOperations::copyOut."""
    dot = name.rfind(".")
    return (name, "") if dot <= 0 else (name[:dot], name[dot:])


def _numbered(directory: str, base: str, extension: str, attempt: int) -> str:
    return os.path.join(directory, base + extension if attempt == 1 else "%s-%d%s" % (base, attempt, extension))


def _identical(path: str, size: int, sha256: str) -> bool:
    if os.path.islink(path) or not os.path.isfile(path) or os.path.getsize(path) != size:
        return False
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(CHUNK_BYTES), b""):
            digest.update(chunk)
    return digest.hexdigest() == sha256


def _remove(path: str) -> None:
    try:
        os.unlink(path)
    except OSError:
        pass


def tool_error(message: str) -> dict:
    return {"content": [{"type": "text", "text": message}], "isError": True}


def structured(result: dict) -> dict:
    """A tool result as the stdio server gives it (Laravel MCP Response::structured): JSON text plus structuredContent."""
    text = json.dumps(result, ensure_ascii=False, separators=(",", ":"))
    return {"content": [{"type": "text", "text": text}], "structuredContent": result}


class Library:
    """One tool call over HTTPS: the request, sign-in and retries, and the files it carries."""

    def __init__(self, settings: Settings, log) -> None:
        self.settings = settings
        self.log = log
        self.http = Http(settings=settings)
        self.credentials = Credentials(settings=settings, log=log, http=self.http)
        self.files = LocalFiles(settings=settings, log=log)

    def call(self, name: str, arguments: dict) -> dict:
        if not self.settings.base_url.startswith(("https://", "http://")):
            raise LauncherFailure(
                "MOTHERSHIP_URL is not set to the site's address (https://...), so the library cannot be reached. "
                "Set it in this MCP server's environment."
            )
        tool = name[len(TOOL_PREFIX):]
        if name in UPLOAD_TOOLS and isinstance(arguments.get("local_path"), str):
            return self._upload(tool=tool, arguments=arguments)
        copies_out = name == "library_fetch" or (name == "library_vectorization" and "format" in arguments)
        body = json.dumps(arguments, ensure_ascii=False).encode("utf-8")

        def build(token: str) -> urllib.request.Request:
            return self._request(tool=tool, token=token, data=body, content_type="application/json")

        status, headers, response, payload = self._exchange(tool=tool, build=build)
        if copies_out and status == 200:
            if response is None:
                raise LauncherFailure("The Mothership answered without a file. Try again.")
            try:
                return self._copy_out(response=response, headers=headers, requested=arguments.get("dest_dir"))
            finally:
                response.close()
        if response is not None:
            response.close()
            raise LauncherFailure("The Mothership answered in a shape the launcher does not know (HTTP %d). Try again." % status)
        return self._answer(tool=tool, status=status, payload=payload)

    def _upload(self, tool: str, arguments: dict) -> dict:
        """library_save, or library_vectorize with local_path: the file goes with the call, its bytes standing in for local_path."""
        import shutil

        real_path, size = self.files.source(path=arguments["local_path"])
        folder, snapshot = self.files.snapshot(real_path=real_path, size=size)
        started = time.monotonic()
        try:
            # The file's own name also goes as JSON: PHP keeps only the part of an upload's file name after its last
            # backslash.
            fields = [
                ("arguments", json.dumps(arguments, ensure_ascii=False)),
                ("upload", json.dumps({"name": os.path.basename(real_path)}, ensure_ascii=False)),
            ]
            bodies = []

            def build(token: str) -> urllib.request.Request:
                body = Multipart(fields=fields, file_field="file", file_name=os.path.basename(real_path), file_path=snapshot)
                bodies.append(body)
                request = self._request(tool=tool, token=token, data=body, content_type=body.content_type)
                request.add_header("Content-Length", str(body.length))
                return request

            try:
                status, _, response, payload = self._exchange(tool=tool, build=build)
            finally:
                for body in bodies:
                    body.close()
            if response is not None:
                response.close()
                raise LauncherFailure("The Mothership answered in a shape the launcher does not know (HTTP %d). Try again." % status)
            self.log.event(
                "info", "transfer.in", "Library._upload",
                {"status": status, "size": size, "seconds": round(time.monotonic() - started, 3)},
            )
            return self._answer(tool=tool, status=status, payload=payload)
        finally:
            shutil.rmtree(folder, True)

    def _copy_out(self, response, headers, requested) -> dict:
        """library_fetch, or library_vectorization with format: the bytes stream back, the result in X-Library-Result."""
        try:
            details = json.loads(base64.b64decode(headers.get("X-Library-Result", ""), validate=True).decode("utf-8"))
            name, sha256, size, result = details["name"], details["sha256"], details["size"], details["result"]
            usable = (
                isinstance(name, str) and name not in ("", ".", "..") and "/" not in name and "\x00" not in name
                and isinstance(sha256, str) and isinstance(size, int) and size >= 0 and isinstance(result, dict)
            )
        except (ValueError, KeyError, TypeError):
            usable = False
        if not usable:
            raise LauncherFailure("The Mothership answered without a usable file. Try again.")
        # After the server's own checks, as the stdio server checks dest_dir after access.
        directory = self.files.destination(requested=requested)
        path, reused = self.files.bring_out(response=response, name=name, size=size, sha256=sha256, directory=directory)
        return structured(result=dict(result, path=path, name=os.path.basename(path), reused=reused))

    def _request(self, tool: str, token: str, data, content_type: str) -> urllib.request.Request:
        return urllib.request.Request(
            self.settings.base_url + ROUTE_PREFIX + urllib.parse.quote(tool, safe=""), data=data, method="POST",
            headers={
                "Accept": "application/json",
                "Authorization": "Bearer " + token,
                "Content-Type": content_type,
                "User-Agent": USER_AGENT,
            },
        )

    def _exchange(self, tool: str, build) -> tuple:
        """Sends one tool call. A dropped connection, an answer cut short or a busy gateway is tried again twice; a refused
        bearer once, after a refresh. Returns (status, headers, response, payload): response is the unread body of a 200
        that is not JSON, payload the JSON object of any other answer.
        """
        refreshed = False
        attempt = 0
        while True:
            attempt += 1
            token = self.credentials.current()
            started = time.monotonic()
            try:
                status, headers, response = self.http.open(request=build(token))
            except Unreachable:
                self.log.event("warn", "http.unreachable", "Library._exchange", {"tool": tool, "attempt": attempt})
                if attempt < ATTEMPTS:
                    time.sleep(_backoff(retry_after=None, attempt=attempt))
                    continue
                raise
            self.log.event(
                "info" if status < 400 else "warn", "http.response", "Library._exchange",
                {"tool": tool, "status": status, "attempt": attempt, "ms": int((time.monotonic() - started) * 1000)},
            )
            json_answer = (headers.get("Content-Type") or "").split(";")[0].strip() == "application/json"
            if status == 200 and not json_answer:
                return status, headers, response, None
            if status == 200:
                try:
                    payload = json.loads(response.read().decode("utf-8"))
                except (http.client.HTTPException, OSError, ValueError):
                    payload = None
                finally:
                    response.close()
                if isinstance(payload, dict):
                    return status, headers, None, payload
                # The answer was cut short after its headers: the call may have been applied, so it is sent again like
                # any dropped connection, and a save or vectorize replays its request_id.
                self.log.event("warn", "http.answer_cut", "Library._exchange", {"tool": tool, "attempt": attempt})
                if attempt < ATTEMPTS:
                    time.sleep(_backoff(retry_after=None, attempt=attempt))
                    continue
                raise Unreachable(NOT_ANSWERING % "its answer was cut short")
            payload = _read_json_object(response=response)
            if status == 401 and not refreshed:
                refreshed = True
                attempt -= 1
                self.credentials.refresh(stale=token)
                continue
            # A 503 carrying the app's own {"error"} is a refusal to pass on; without one it is the gateway.
            app_refusal = status == 503 and isinstance(payload, dict) and isinstance(payload.get("error"), str)
            if status in RETRY_STATUSES and not app_refusal and attempt < ATTEMPTS:
                time.sleep(_backoff(retry_after=headers.get("Retry-After"), attempt=attempt))
                continue
            return status, headers, None, payload

    def _answer(self, tool: str, status: int, payload) -> dict:
        result = payload.get("result") if isinstance(payload, dict) else None
        if status == 200 and isinstance(result, dict):
            return structured(result=result)
        raise LauncherFailure(_refusal(tool=tool, status=status, payload=payload))


def _backoff(retry_after, attempt: int) -> float:
    if retry_after is not None and retry_after.strip().isdigit():
        return min(float(retry_after.strip()), MAX_RETRY_SECONDS)
    return float(2 ** (attempt - 1))


def _refusal(tool: str, status: int, payload) -> str:
    """The text an agent reads for a call the server did not complete."""
    error = payload.get("error") if isinstance(payload, dict) else None
    if isinstance(error, str) and error:
        if status == 403 and error.startswith("This action is unauthorized"):
            return (
                "This action is unauthorized: the library's API key lacks the scope library_%s needs. "
                "docs/api/mothership-mcp.md lists each tool's scope." % tool
            )
        # The app's own refusal: the same words the stdio server gives.
        return error
    if status == 401:
        return (
            "The Mothership refused the library credentials (HTTP 401) even after a fresh sign-in. "
            "Check the agent API key in the Keychain item %s." % CREDENTIAL_SERVICE
        )
    if 300 <= status < 400:
        return "The Mothership answered with a redirect (HTTP %d), which the launcher does not follow. Check MOTHERSHIP_URL." % status
    if status == 413:
        return "The file is larger than The Mothership accepts in one upload, so nothing was saved."
    if status == 429 or status >= 500:
        return NOT_ANSWERING % ("HTTP %d" % status)
    return "The Mothership refused the call (HTTP %d)." % status


class Worker:
    """Runs one session's tool calls in order on its own thread."""

    def __init__(self, settings: Settings, log, send, tool_names) -> None:
        self.settings = settings
        self.log = log
        self.send = send
        self.tool_names = frozenset(tool_names)
        self.library = Library(settings=settings, log=log)
        self.jobs = queue.Queue()
        self.cancelled = set()
        self.closing = False
        self.parked = False
        self.submitted = 0
        self.finished = 0
        self.thread = threading.Thread(target=self._run, daemon=True)
        self.thread.start()

    def submit(self, request_id, params: dict) -> None:
        self.submitted += 1
        self.jobs.put((request_id, params))

    def cancel(self, request_id) -> None:
        self.cancelled.add(request_id)

    def recyclable(self) -> bool:
        """True once the session has been idle for MOTHERSHIP_MCP_IDLE_SECONDS and every call it made is answered."""
        return self.parked and self.submitted == self.finished

    def finish(self) -> None:
        """Ends the idle worker thread so the launcher can shrink back to its idle stage."""
        self.jobs.put(None)
        self.thread.join(timeout=5)

    def shutdown(self) -> None:
        """The agent has gone: a call still running is abandoned with the process."""
        self.closing = True
        self.jobs.put(None)
        self.thread.join(timeout=2)

    def _run(self) -> None:
        while True:
            try:
                job = self.jobs.get(timeout=self.settings.idle_seconds)
            except queue.Empty:
                self.parked = True
                continue
            self.parked = False
            if job is None:
                return
            self._handle(request_id=job[0], params=job[1])
            self.finished += 1

    def _handle(self, request_id, params: dict) -> None:
        if self.closing:
            return
        if request_id in self.cancelled:
            self.cancelled.discard(request_id)
            return
        name = params.get("name")
        known = isinstance(name, str) and name in self.tool_names
        logged_name = name if known else "other"
        arguments = params.get("arguments")
        arguments = arguments if isinstance(arguments, dict) else {}
        started = time.monotonic()
        try:
            if name is None:
                result, outcome = tool_error("Missing [name] parameter."), "refused"
            elif not known:
                result, outcome = tool_error("Tool [%s] not found." % name), "refused"
            else:
                result, outcome = self.library.call(name=name, arguments=arguments), "ok"
        except Unreachable as failure:
            result, outcome = tool_error(str(failure)), "unreachable"
        except LauncherFailure as failure:
            result, outcome = tool_error(str(failure)), "refused"
        except Exception as failure:  # A launcher bug must answer the call, never hang the agent.
            self.log.event("error", "tool.crash", "Worker._handle", {"tool": logged_name, "class": type(failure).__name__})
            result, outcome = tool_error("The library launcher failed during this call. Try again."), "crash"
        self.log.event(
            "info", "tool.call", "Worker._handle",
            {"tool": logged_name, "outcome": outcome, "seconds": round(time.monotonic() - started, 3)},
        )
        if request_id in self.cancelled:
            self.cancelled.discard(request_id)
            return
        self.send({"jsonrpc": "2.0", "id": request_id, "result": result})


def load_manifest() -> dict:
    with open(MANIFEST_PATH, "r", encoding="utf-8") as handle:
        return json.load(handle)


def read_server_manifest(settings: Settings) -> dict:
    """What this checkout's stdio server (`php artisan mcp:start mothership`) publishes, in the manifest's shape."""
    import subprocess

    versions = load_manifest()["protocolVersions"]
    process = subprocess.Popen(
        [settings.php, *SERVER_COMMAND], cwd=CHECKOUT_ROOT,
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
    )
    watchdog = threading.Timer(60.0, process.kill)
    watchdog.daemon = True
    watchdog.start()
    next_id = [0]

    def request(method: str, params: dict) -> dict:
        next_id[0] += 1
        process.stdin.write(json.dumps({"jsonrpc": "2.0", "id": next_id[0], "method": method, "params": params}).encode("utf-8") + b"\n")
        process.stdin.flush()
        for line in process.stdout:
            try:
                message = json.loads(line)
            except ValueError:
                continue
            if isinstance(message, dict) and message.get("id") == next_id[0]:
                if "error" in message:
                    raise LauncherFailure("The stdio server refused %s." % method)
                return message.get("result") or {}
        raise LauncherFailure("The stdio server stopped before it answered %s." % method)

    try:
        hello = request(method="initialize", params={
            "protocolVersion": versions[0], "capabilities": {}, "clientInfo": {"name": "mothership-mcp-launcher", "version": "2"},
        })
        process.stdin.write(b'{"jsonrpc":"2.0","method":"notifications/initialized"}\n')
        tools = []
        cursor = None
        while True:
            page = request(method="tools/list", params={"cursor": cursor} if cursor else {})
            tools += page.get("tools", [])
            cursor = page.get("nextCursor")
            if not cursor:
                break
    finally:
        watchdog.cancel()
        try:
            process.stdin.close()
        except OSError:
            pass
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
        process.stdout.close()
    return {
        "protocolVersions": versions,
        "serverInfo": hello.get("serverInfo", {}),
        "instructions": hello.get("instructions", ""),
        "tools": tools,
    }


def write_manifest() -> int:
    manifest = read_server_manifest(settings=Settings(environ=os.environ))
    with open(MANIFEST_PATH, "w", encoding="utf-8") as handle:
        json.dump(manifest, handle, indent=4, ensure_ascii=False)
        handle.write("\n")
    return 0


def check_manifest() -> int:
    same = read_server_manifest(settings=Settings(environ=os.environ)) == load_manifest()
    print("tools_manifest.json matches this checkout's server" if same else "tools_manifest.json differs from this checkout's server")
    return 0 if same else 1

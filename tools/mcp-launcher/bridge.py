"""The working half of the library MCP launcher, imported by launcher.py on a session's first tool call.

It starts the PHP server inside the FPM container, keeps the machine-wide cap on how many of those run, and carries files
across the container boundary with the server's own rules kept on the Mac side. Contract: docs/api/mothership-mcp.md.
"""
from __future__ import annotations

import json
import os
import queue
import threading
import time

MANIFEST_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tools_manifest.json")
SERVER_COMMAND = ("php", "artisan", "mcp:start", "mothership")
STORAGE_MOUNT = "/var/www/html/storage"
CHUNK_BYTES = 1024 * 1024
# LibraryOperations::MAX_NAME_TRIES: how many name, name-2, name-3 ... a fetch tries before giving up.
MAX_NAME_TRIES = 1000
DEFAULT_MAX_FILE_BYTES = 2 * 1024 * 1024 * 1024
# A slow copy is still a live copy: allow five minutes plus 5 MiB/s before calling a transfer stuck.
TRANSFER_GRACE_SECONDS = 300.0
TRANSFER_MIN_BYTES_PER_SECOND = 5 * 1024 * 1024

# Runs inside the container. Clears staging folders a killed launcher left behind, then receives one file on stdin and
# prints its sha256. Arguments: staging root, staging folder, file name.
RECEIVE_SCRIPT = (
    'umask 077; mkdir -p "$1" || exit 1; '
    'find "$1" -mindepth 1 -maxdepth 1 -type d -mmin +240 -exec rm -rf {} + 2>/dev/null; '
    'mkdir -p "$1/$2" && cat > "$1/$2/$3" && sha256sum < "$1/$2/$3"'
)
DISCARD_SCRIPT = 'rm -rf "$1/$2"'


class LauncherFailure(Exception):
    """A refusal or failure the agent can act on; the message becomes the MCP tool error."""


class UpstreamGone(LauncherFailure):
    """The PHP server stopped answering."""


class Settings:
    """Everything configurable, read once from the environment."""

    def __init__(self, environ) -> None:
        self.container_bin = environ.get("MOTHERSHIP_MCP_CONTAINER_BIN", "/opt/homebrew/bin/container")
        self.container = environ.get("MOTHERSHIP_MCP_CONTAINER", "mothership-private-fpm")
        self.container_user = environ.get("MOTHERSHIP_MCP_CONTAINER_USER", "www-data")
        self.container_workdir = environ.get("MOTHERSHIP_MCP_CONTAINER_WORKDIR", "/var/www/html")
        self.staging_root = environ.get("MOTHERSHIP_MCP_CONTAINER_STAGING", "/tmp/mothership-mcp").rstrip("/")
        home = os.path.expanduser("~")
        self.state_dir = environ.get("MOTHERSHIP_MCP_STATE_DIR", os.path.join(home, "Library/Caches/mothership-mcp"))
        temporary = environ.get("TMPDIR", "/tmp").rstrip("/") or "/tmp"
        self.fetch_dir = environ.get("MOTHERSHIP_MCP_FETCH_DIR") or temporary + "/mothership-library"
        self.max_servers = _positive_int(environ=environ, key="MOTHERSHIP_MCP_MAX_SERVERS", default=2)
        self.max_file_bytes = _positive_int(
            environ=environ, key="MOTHERSHIP_MCP_MAX_FILE_BYTES", default=DEFAULT_MAX_FILE_BYTES
        )
        self.idle_seconds = _positive_float(environ=environ, key="MOTHERSHIP_MCP_IDLE_SECONDS", default=15.0)
        self.hold_seconds = _positive_float(environ=environ, key="MOTHERSHIP_MCP_HOLD_SECONDS", default=60.0)
        self.queue_seconds = _positive_float(environ=environ, key="MOTHERSHIP_MCP_QUEUE_SECONDS", default=120.0)
        self.start_seconds = _positive_float(environ=environ, key="MOTHERSHIP_MCP_START_SECONDS", default=30.0)
        self.call_seconds = _positive_float(environ=environ, key="MOTHERSHIP_MCP_CALL_SECONDS", default=1800.0)

    def exec_command(self, arguments, interactive: bool = False) -> list:
        command = [self.container_bin, "exec"]
        if interactive:
            command.append("-i")
        command += ["--user", self.container_user, "--workdir", self.container_workdir, self.container]
        return command + list(arguments)


def _positive_int(environ, key: str, default: int) -> int:
    value = environ.get(key, "")
    return int(value) if value.isdigit() and int(value) > 0 else default


def _positive_float(environ, key: str, default: float) -> float:
    try:
        value = float(environ.get(key, ""))
    except ValueError:
        return default
    return value if value > 0 else default


class SlotPool:
    """The machine-wide cap on PHP servers: one flock per slot, released by the kernel if a launcher dies."""

    def __init__(self, settings: Settings) -> None:
        self.directory = os.path.join(settings.state_dir, "slots")
        self.size = settings.max_servers
        self.descriptor = None

    def try_acquire(self) -> bool:
        import fcntl

        os.makedirs(self.directory, exist_ok=True)
        for index in range(self.size):
            descriptor = os.open(os.path.join(self.directory, "slot-%d.lock" % index), os.O_RDWR | os.O_CREAT, 0o600)
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except OSError:
                os.close(descriptor)
                continue
            self.descriptor = descriptor
            return True
        return False

    def release(self) -> None:
        if self.descriptor is not None:
            os.close(self.descriptor)
            self.descriptor = None


class Upstream:
    """One PHP server in the container, spoken to over JSON-RPC on its stdin and stdout."""

    def __init__(self, settings: Settings, protocol_version: str) -> None:
        import subprocess

        self.settings = settings
        self.protocol_version = protocol_version
        self.started_at = time.monotonic()
        self.next_id = 0
        self.buffer = bytearray()
        self.process = subprocess.Popen(
            settings.exec_command(arguments=SERVER_COMMAND, interactive=True),
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
        )

    def handshake(self) -> dict:
        reply = self.request(
            method="initialize",
            params={
                "protocolVersion": self.protocol_version,
                "capabilities": {},
                "clientInfo": {"name": "mothership-mcp-launcher", "version": "1"},
            },
            timeout=self.settings.start_seconds,
        )
        self._write({"jsonrpc": "2.0", "method": "notifications/initialized"})
        return reply

    def request(self, method: str, params: dict, timeout: float) -> dict:
        self.next_id += 1
        expected = self.next_id
        self._write({"jsonrpc": "2.0", "id": expected, "method": method, "params": params})
        message = self._read_reply(expected_id=expected, deadline=time.monotonic() + timeout)
        if "error" in message:
            error = message["error"] if isinstance(message["error"], dict) else {}
            raise LauncherFailure(str(error.get("message") or "The library server refused the request."))
        result = message.get("result")
        return result if isinstance(result, dict) else {}

    def alive(self) -> bool:
        return self.process.poll() is None

    def stop(self) -> None:
        import subprocess

        try:
            self.process.stdin.close()
        except OSError:
            pass
        try:
            self.process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            self.process.terminate()
            try:
                self.process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait()
        self.process.stdout.close()

    def _write(self, message: dict) -> None:
        try:
            self.process.stdin.write(json.dumps(message).encode("utf-8") + b"\n")
            self.process.stdin.flush()
        except OSError as error:
            raise UpstreamGone("The library server stopped. Try again.") from error

    def _read_reply(self, expected_id: int, deadline: float) -> dict:
        import select

        descriptor = self.process.stdout.fileno()
        while True:
            newline = self.buffer.find(b"\n")
            if newline >= 0:
                line = bytes(self.buffer[:newline])
                del self.buffer[: newline + 1]
                try:
                    message = json.loads(line)
                except ValueError:
                    continue
                if isinstance(message, dict) and message.get("id") == expected_id:
                    return message
                continue
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise UpstreamGone("The library server did not answer in time. Try again.")
            ready, _, _ = select.select([descriptor], [], [], min(remaining, 1.0))
            if ready:
                chunk = os.read(descriptor, 65536)
                if not chunk:
                    raise UpstreamGone("The library server stopped during this call. Try again.")
                self.buffer += chunk


class ContainerFiles:
    """Moves files across the container boundary and keeps the server's rules on the Mac side."""

    def __init__(self, settings: Settings, log) -> None:
        self.settings = settings
        self.log = log
        self.active = None
        self.storage_source = None

    def kill_active(self) -> None:
        process = self.active
        if process is not None and process.poll() is None:
            process.kill()

    def new_staging(self) -> str:
        return os.urandom(16).hex()

    def staging_path(self, staging: str) -> str:
        return self.settings.staging_root + "/" + staging

    def discard(self, staging: str) -> None:
        import subprocess

        arguments = ["sh", "-c", DISCARD_SCRIPT, "sh", self.settings.staging_root, staging]
        try:
            subprocess.run(
                self.settings.exec_command(arguments=arguments),
                stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                timeout=30,
            )
        except (OSError, subprocess.SubprocessError):
            self.log.event("warn", "staging.discard_failed", "ContainerFiles.discard", {})

    def source(self, path: str) -> tuple:
        """The server's rules for local_path, checked before anything starts. Returns (real path, size)."""
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

    def send_in(self, real_path: str, size: int, staging: str) -> str:
        """Copies a Mac file into the staging folder and proves the bytes arrived. Returns the container path."""
        import hashlib
        import subprocess

        started = time.monotonic()
        name = os.path.basename(real_path)
        command = ["sh", "-c", RECEIVE_SCRIPT, "sh", self.settings.staging_root, staging, name]
        process = subprocess.Popen(
            self.settings.exec_command(arguments=command, interactive=True),
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
        )
        self.active = process
        watchdog = threading.Timer(_transfer_seconds(size=size), process.kill)
        watchdog.daemon = True
        watchdog.start()
        digest = hashlib.sha256()
        sent = 0
        try:
            try:
                with open(real_path, "rb") as handle:
                    before = os.fstat(handle.fileno())
                    while True:
                        chunk = handle.read(CHUNK_BYTES)
                        if not chunk:
                            break
                        digest.update(chunk)
                        process.stdin.write(chunk)
                        sent += len(chunk)
                    after = os.fstat(handle.fileno())
                changed = sent != size or (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns)
                process.stdin.close()
                output = process.stdout.read(256)
                code = process.wait()
            except OSError:
                process.kill()
                process.wait()
                raise LauncherFailure("Copying the file into The Mothership failed, so nothing was saved. Try again.") from None
        finally:
            watchdog.cancel()
            self.active = None
            process.stdout.close()
        if changed:
            raise LauncherFailure("The file at local_path changed while it was being read. Save it again once it is finished.")
        received = output.decode("ascii", "replace").split()[0] if output.strip() else ""
        if code != 0 or received != digest.hexdigest():
            self.log.event("error", "transfer.in", "ContainerFiles.send_in", {"outcome": "sha256_mismatch", "size": size})
            raise LauncherFailure("The copy inside The Mothership did not match the file, so nothing was saved. Try again.")
        self.log.event(
            "info", "transfer.in", "ContainerFiles.send_in",
            {"outcome": "ok", "size": size, "seconds": round(time.monotonic() - started, 3)},
        )
        return self.staging_path(staging=staging) + "/" + name

    def destination(self, requested) -> str:
        """The server's rules for dest_dir that need no container. Returns the real folder, created when missing."""
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

    def refuse_storage(self, directory: str) -> None:
        """dest_dir must sit outside The Mothership's storage, which on this Mac is the container's storage bind."""
        storage = self._storage_source()
        if directory == storage or directory.startswith(storage.rstrip("/") + "/"):
            raise LauncherFailure("dest_dir is inside The Mothership's own storage. Choose a folder outside it.")

    def bring_out(self, container_path: str, name: str, size: int, sha256: str, directory: str) -> tuple:
        """Copies a verified file to directory under the server's naming rules. Returns (path, reused)."""
        base, extension = _split_name(name=name)
        for attempt in range(1, MAX_NAME_TRIES + 1):
            candidate = _numbered(directory=directory, base=base, extension=extension, attempt=attempt)
            if not os.path.lexists(candidate):
                break
            if _identical(path=candidate, size=size, sha256=sha256):
                return candidate, True
        part = self._stream_out(container_path=container_path, size=size, sha256=sha256, directory=directory)
        return self._claim_name(part=part, name_parts=(base, extension), size=size, sha256=sha256, directory=directory)

    def _stream_out(self, container_path: str, size: int, sha256: str, directory: str) -> str:
        import hashlib
        import subprocess

        started = time.monotonic()
        part = os.path.join(directory, "." + os.urandom(8).hex() + ".mothership-part")
        try:
            descriptor = os.open(part, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o666)
        except OSError:
            raise LauncherFailure("Cannot write to dest_dir. Pass a folder this server can write to.") from None
        process = subprocess.Popen(
            self.settings.exec_command(arguments=["cat", container_path]),
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
        )
        self.active = process
        watchdog = threading.Timer(_transfer_seconds(size=size), process.kill)
        watchdog.daemon = True
        watchdog.start()
        digest = hashlib.sha256()
        written = 0
        try:
            with os.fdopen(descriptor, "wb") as output:
                while True:
                    chunk = process.stdout.read(CHUNK_BYTES)
                    if not chunk:
                        break
                    digest.update(chunk)
                    output.write(chunk)
                    written += len(chunk)
            code = process.wait()
        except OSError:
            process.kill()
            process.wait()
            _remove(path=part)
            raise LauncherFailure("Writing the copy failed part way (is the disk full?). Nothing was written.") from None
        finally:
            watchdog.cancel()
            self.active = None
            process.stdout.close()
        if code != 0 or written != size or digest.hexdigest() != sha256:
            _remove(path=part)
            self.log.event("error", "transfer.out", "ContainerFiles._stream_out", {"outcome": "sha256_mismatch", "size": size})
            raise LauncherFailure("The copy out of The Mothership did not match its recorded sha256, so nothing was written. Try again.")
        self.log.event(
            "info", "transfer.out", "ContainerFiles._stream_out",
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

    def _storage_source(self) -> str:
        import subprocess

        if self.storage_source is None:
            try:
                output = subprocess.run(
                    [self.settings.container_bin, "inspect", self.settings.container],
                    stdin=subprocess.DEVNULL,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.DEVNULL,
                    timeout=30,
                    check=True,
                ).stdout
                details = json.loads(output)
                details = details[0] if isinstance(details, list) else details
                configuration = details.get("configuration", details)
                source = next(
                    mount["source"] for mount in configuration["mounts"] if mount.get("destination") == STORAGE_MOUNT
                )
            except (OSError, ValueError, KeyError, IndexError, StopIteration, TypeError, AttributeError, subprocess.SubprocessError):
                raise LauncherFailure("Cannot confirm dest_dir is outside The Mothership's storage, so nothing was copied. Try again.") from None
            self.storage_source = os.path.realpath(source)
        return self.storage_source


def _megabytes(bytes_count: int) -> str:
    return "{:,.1f} MB".format(bytes_count / 1048576)


def _transfer_seconds(size: int) -> float:
    return TRANSFER_GRACE_SECONDS + size / TRANSFER_MIN_BYTES_PER_SECOND


def _split_name(name: str) -> tuple:
    """name.ext into (name, .ext); a leading dot is part of the name, as in LibraryOperations::copyOut."""
    dot = name.rfind(".")
    return (name, "") if dot <= 0 else (name[:dot], name[dot:])


def _numbered(directory: str, base: str, extension: str, attempt: int) -> str:
    return os.path.join(directory, base + extension if attempt == 1 else "%s-%d%s" % (base, attempt, extension))


def _identical(path: str, size: int, sha256: str) -> bool:
    import hashlib

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


def _tool_details(result: dict) -> dict:
    """A tool's JSON result: structuredContent, or the same object as text."""
    structured = result.get("structuredContent")
    if isinstance(structured, dict):
        return structured
    for part in result.get("content") or []:
        if isinstance(part, dict) and part.get("type") == "text":
            try:
                parsed = json.loads(part.get("text", ""))
            except ValueError:
                continue
            if isinstance(parsed, dict):
                return parsed
    return {}


def _with_mac_path(result: dict, updates: dict) -> dict:
    """Replaces the container's answer with the Mac's, in both forms a client may read."""
    if isinstance(result.get("structuredContent"), dict):
        result["structuredContent"].update(updates)
    for part in result.get("content") or []:
        if isinstance(part, dict) and part.get("type") == "text":
            try:
                parsed = json.loads(part.get("text", ""))
            except ValueError:
                continue
            if isinstance(parsed, dict):
                parsed.update(updates)
                part["text"] = json.dumps(parsed)
    return result


class Worker:
    """Runs one session's tool calls in order on its own thread and owns that session's PHP server."""

    def __init__(self, settings: Settings, log, send, protocol_version: str) -> None:
        self.settings = settings
        self.log = log
        self.send = send
        self.protocol_version = protocol_version
        self.slots = SlotPool(settings=settings)
        self.files = ContainerFiles(settings=settings, log=log)
        self.upstream = None
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
        """True once PHP has stopped on idle and every call this session made has been answered."""
        return self.parked and self.submitted == self.finished

    def finish(self) -> None:
        """Ends the idle worker thread so the launcher can shrink back to its idle stage."""
        self.jobs.put(None)
        self.thread.join(timeout=5)

    def shutdown(self) -> None:
        self.closing = True
        self.files.kill_active()
        self.jobs.put(None)
        self.thread.join(timeout=2)
        upstream = self.upstream
        if self.thread.is_alive() and upstream is not None:
            # A call is still running and its agent has gone: end it so the staged copy is cleaned up.
            upstream.process.terminate()
        self.thread.join(timeout=13)

    def _run(self) -> None:
        while True:
            try:
                job = self.jobs.get(timeout=self.settings.idle_seconds)
            except queue.Empty:
                self._stop_upstream(reason="idle")
                self.parked = True
                continue
            self.parked = False
            if job is None:
                self._stop_upstream(reason="shutdown")
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
        logged_name = name if isinstance(name, str) and name.startswith("library_") and len(name) <= 40 else "other"
        arguments = params.get("arguments")
        arguments = arguments if isinstance(arguments, dict) else {}
        started = time.monotonic()
        try:
            result = self._dispatch(request_id=request_id, name=name, arguments=arguments)
            outcome = "refused" if result.get("isError") else "ok"
        except UpstreamGone as failure:
            self._stop_upstream(reason="lost")
            result, outcome = tool_error(str(failure)), "lost"
        except LauncherFailure as failure:
            result, outcome = tool_error(str(failure)), "refused"
        except Exception as failure:  # A launcher bug must answer the call, never hang the agent.
            self.log.event("error", "tool.crash", "Worker._handle", {"tool": logged_name, "class": type(failure).__name__})
            self._stop_upstream(reason="crash")
            result, outcome = tool_error("The library launcher failed during this call. Try again."), "crash"
        self.log.event(
            "info", "tool.call", "Worker._handle",
            {"tool": logged_name, "outcome": outcome, "seconds": round(time.monotonic() - started, 3)},
        )
        if request_id in self.cancelled:
            self.cancelled.discard(request_id)
            return
        self.send({"jsonrpc": "2.0", "id": request_id, "result": result})

    def _dispatch(self, request_id, name, arguments: dict) -> dict:
        # Tools that read a Mac file (local_path) or write one (dest_dir): the file crosses the container boundary here.
        if name in ("library_save", "library_vectorize") and isinstance(arguments.get("local_path"), str):
            return self._save(request_id=request_id, name=name, arguments=arguments)
        if name == "library_fetch" and isinstance(arguments.get("dest_dir", ""), str):
            return self._fetch(request_id=request_id, name=name, arguments=arguments)
        if name == "library_vectorization" and "format" in arguments and isinstance(arguments.get("dest_dir", ""), str):
            return self._fetch(request_id=request_id, name=name, arguments=arguments)
        return self._call(request_id=request_id, name=name, arguments=arguments)

    def _call(self, request_id, name, arguments: dict) -> dict:
        upstream = self._ready_upstream(request_id=request_id)
        return upstream.request(
            method="tools/call", params={"name": name, "arguments": arguments}, timeout=self.settings.call_seconds
        )

    def _save(self, request_id, name: str, arguments: dict) -> dict:
        real_path, size = self.files.source(path=arguments["local_path"])
        upstream = self._ready_upstream(request_id=request_id)
        staging = self.files.new_staging()
        try:
            staged = self.files.send_in(real_path=real_path, size=size, staging=staging)
            return upstream.request(
                method="tools/call",
                params={"name": name, "arguments": dict(arguments, local_path=staged)},
                timeout=self.settings.call_seconds,
            )
        finally:
            self.files.discard(staging=staging)

    def _fetch(self, request_id, name: str, arguments: dict) -> dict:
        directory = self.files.destination(requested=arguments.get("dest_dir"))
        upstream = self._ready_upstream(request_id=request_id)
        self.files.refuse_storage(directory=directory)
        staging = self.files.new_staging()
        inside = self.files.staging_path(staging=staging)
        try:
            result = upstream.request(
                method="tools/call",
                params={"name": name, "arguments": dict(arguments, dest_dir=inside)},
                timeout=self.settings.call_seconds,
            )
            if result.get("isError"):
                return result
            details = _tool_details(result=result)
            container_path = details.get("path")
            if not isinstance(container_path, str) or not container_path.startswith(inside + "/"):
                raise LauncherFailure("The library server answered without a file. Try again.")
            path, reused = self.files.bring_out(
                container_path=container_path,
                name=os.path.basename(container_path),
                size=int(details.get("size", -1)),
                sha256=str(details.get("sha256", "")),
                directory=directory,
            )
            return _with_mac_path(result=result, updates={"path": path, "name": os.path.basename(path), "reused": reused})
        finally:
            self.files.discard(staging=staging)

    def _ready_upstream(self, request_id) -> Upstream:
        upstream = self.upstream
        if upstream is not None and upstream.alive():
            if time.monotonic() - upstream.started_at < self.settings.hold_seconds:
                return upstream
            # A busy session gives its slot up now and then, so a queued session is not starved.
            self._stop_upstream(reason="hold")
            time.sleep(0.5)
        elif upstream is not None:
            self._stop_upstream(reason="exited")
        self._acquire_slot(request_id=request_id)
        try:
            upstream = Upstream(settings=self.settings, protocol_version=self.protocol_version)
            self.upstream = upstream
            upstream.handshake()
        except (OSError, LauncherFailure):
            self._stop_upstream(reason="start_failed")
            raise UpstreamGone("The Mothership is not answering right now, so the library server could not start. Try again shortly.") from None
        self.log.event("info", "upstream.start", "Worker._ready_upstream", {"outcome": "ok"})
        return upstream

    def _acquire_slot(self, request_id) -> None:
        started = time.monotonic()
        while not self.slots.try_acquire():
            if self.closing or request_id in self.cancelled:
                raise LauncherFailure("The call was cancelled.")
            if time.monotonic() - started >= self.settings.queue_seconds:
                self.log.event("warn", "slot.busy", "Worker._acquire_slot", {"waited_seconds": round(time.monotonic() - started, 3)})
                raise LauncherFailure("The library is busy with other agents right now. Try again in a moment.")
            time.sleep(0.2)
        self.log.event("info", "slot.acquired", "Worker._acquire_slot", {"waited_seconds": round(time.monotonic() - started, 3)})

    def _stop_upstream(self, reason: str) -> None:
        upstream, self.upstream = self.upstream, None
        if upstream is not None:
            upstream.stop()
            self.log.event("info", "upstream.stop", "Worker._stop_upstream", {"reason": reason})
        self.slots.release()


def load_manifest() -> dict:
    with open(MANIFEST_PATH, "r", encoding="utf-8") as handle:
        return json.load(handle)


def read_server_manifest(settings: Settings) -> dict:
    """What the running server publishes, in the manifest's shape."""
    versions = load_manifest()["protocolVersions"]
    upstream = Upstream(settings=settings, protocol_version=versions[0])
    try:
        hello = upstream.handshake()
        tools = []
        cursor = None
        while True:
            page = upstream.request(
                method="tools/list", params={"cursor": cursor} if cursor else {}, timeout=settings.start_seconds
            )
            tools += page.get("tools", [])
            cursor = page.get("nextCursor")
            if not cursor:
                break
    finally:
        upstream.stop()
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
    print("tools_manifest.json matches the running server" if same else "tools_manifest.json differs from the running server")
    return 0 if same else 1

#!/usr/bin/env python3
"""System metrics collector process lifecycle management."""

from __future__ import annotations

import ctypes
import fcntl
import json
import os
import pathlib
import signal
import subprocess
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Optional, Protocol


class CollectorOwnershipError(RuntimeError):
    """Raised when collector ownership validation fails."""

    pass


@dataclass(frozen=True)
class CollectorReceipt:
    """Persistent receipt of collector ownership."""

    pid: int
    command: tuple[str, ...]
    started_at: str


@dataclass(frozen=True)
class CollectorStatus:
    """Current collector status."""

    pid: int | None
    running: bool
    owned: bool
    snapshot_age_seconds: float | None
    signals: dict[str, bool]


class EventLog(Protocol):
    """Event logging interface for collector lifecycle."""

    def write(self, level: str, event: str, loc: str, ctx: Optional[dict] = None) -> None:
        """Write an event log entry."""
        ...


class DefaultEventLog:
    """Default no-op event log implementation."""

    def write(self, level: str, event: str, loc: str, ctx: Optional[dict] = None) -> None:
        """Write an event log entry (no-op)."""
        pass


class ProcessApi:
    """Process management API for production and testing."""

    def is_live(self, *, pid: int) -> bool:
        """Check if a process ID is currently running."""
        try:
            os.kill(pid, 0)
            return True
        except (ProcessLookupError, PermissionError):
            return False

    def command_line(self, *, pid: int) -> tuple[str, ...] | None:
        """Read the command line of a live process using sysctl KERN_PROCARGS2."""
        try:
            libc = ctypes.CDLL(None, use_errno=True)
        except (OSError, TypeError):
            return None

        try:
            sysctl = libc.sysctl
            sysctl.argtypes = [
                ctypes.POINTER(ctypes.c_int),
                ctypes.c_uint,
                ctypes.c_void_p,
                ctypes.POINTER(ctypes.c_size_t),
                ctypes.c_void_p,
                ctypes.c_size_t,
            ]
            sysctl.restype = ctypes.c_int

            CTL_KERN = 1
            KERN_PROCARGS2 = 49

            mib = (ctypes.c_int * 3)(CTL_KERN, KERN_PROCARGS2, pid)

            size = ctypes.c_size_t(0)
            ret = sysctl(mib, 3, None, ctypes.byref(size), None, 0)
            if ret != 0 or size.value == 0:
                return None

            buf = ctypes.create_string_buffer(size.value)
            ret = sysctl(mib, 3, buf, ctypes.byref(size), None, 0)
            if ret != 0:
                return None

            data = buf.raw[:size.value]
            if len(data) < 4:
                return None

            argc = int.from_bytes(data[:4], byteorder="little", signed=True)
            if argc <= 0:
                return None

            offset = 4
            execpath_end = data.find(b"\x00", offset)
            if execpath_end == -1:
                return None
            offset = execpath_end + 1

            while offset < len(data) and data[offset:offset+1] == b"\x00":
                offset += 1

            argv: list[str] = []
            for _ in range(argc):
                if offset >= len(data):
                    return None
                end = data.find(b"\x00", offset)
                if end == -1:
                    return None
                arg = data[offset:end].decode("utf-8", errors="replace")
                argv.append(arg)
                offset = end + 1

            return tuple(argv)
        except (AttributeError, OSError, UnicodeDecodeError):
            return None

    def spawn(self, *, command: list[str]) -> int:
        """Spawn a new process and return its PID."""
        process = subprocess.Popen(
            command,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            start_new_session=True,
        )
        return process.pid

    def now(self) -> datetime:
        """Get current UTC time."""
        return datetime.now(timezone.utc)

    def signal(self, *, pid: int, signum: int) -> None:
        """Send a signal to a process."""
        os.kill(pid, signum)

    def monotonic(self) -> float:
        """Get monotonic time for timeout calculations."""
        return time.monotonic()

    def sleep(self, duration: float) -> None:
        """Sleep for a duration."""
        time.sleep(duration)


def _same_collector(*, live: Optional[Sequence[str]], recorded: Sequence[str]) -> bool:
    """Match a live argv to a recorded collector command.

    The script path and every argument after it must match exactly; together they name this
    state root's snapshot file, which an unrelated process reusing the PID cannot carry. The
    interpreter in argv[0] is only required to be a Python: macOS framework builds re-exec into
    `Python.app/Contents/MacOS/Python`, so the live argv[0] never equals the launcher path that
    was spawned and recorded.
    """
    if not live or not recorded or len(live) < 2:
        return False
    if tuple(live[1:]) != tuple(recorded[1:]):
        return False
    return tuple(live) == tuple(recorded) or os.path.basename(live[0]).lower().startswith("python")


class CollectorManager:
    """Manage the lifecycle of the system metrics collector process."""

    def __init__(
        self,
        *,
        snapshot_path: pathlib.Path,
        receipt_path: pathlib.Path,
        script_path: pathlib.Path,
        interval_seconds: float,
        process_api: Optional[ProcessApi] = None,
        events: Optional[EventLog] = None,
        python_executable: Optional[str] = None,
        stop_timeout_seconds: float = 5.0,
    ):
        self.snapshot_path = pathlib.Path(snapshot_path)
        self.receipt_path = pathlib.Path(receipt_path)
        self.script_path = pathlib.Path(script_path)
        self.interval_seconds = interval_seconds
        self.process_api = process_api or ProcessApi()
        self.events = events or DefaultEventLog()
        self.python_executable = python_executable or sys.executable
        self.stop_timeout_seconds = stop_timeout_seconds

    def expected_command(self) -> list[str]:
        """Generate the expected collector command."""
        return [
            self.python_executable,
            str(self.script_path),
            "--snapshot",
            str(self.snapshot_path),
            "--interval",
            str(self.interval_seconds),
        ]

    @property
    def _lock_path(self) -> pathlib.Path:
        """Path to lock file for serializing state transitions."""
        return self.receipt_path.parent / f"{self.receipt_path.name}.lock"

    def _acquire_lock(self) -> object:
        """Acquire exclusive lock on state transition, returns file object."""
        self.receipt_path.parent.mkdir(parents=True, exist_ok=True)
        lock_file = open(self._lock_path, "w", encoding="utf-8")
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX)
        return lock_file

    def _release_lock(self, lock_file: object) -> None:
        """Release lock and close file."""
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)
        lock_file.close()

    def _read_receipt(self) -> Optional[CollectorReceipt]:
        """Read the receipt file if it exists."""
        if not self.receipt_path.exists():
            return None
        try:
            with self.receipt_path.open("r", encoding="utf-8") as handle:
                data = json.load(handle)
                return CollectorReceipt(
                    pid=int(data["pid"]),
                    command=tuple(data["command"]),
                    started_at=str(data["started_at"]),
                )
        except (json.JSONDecodeError, KeyError, ValueError, TypeError):
            return None

    def _write_receipt(self, receipt: CollectorReceipt) -> None:
        """Atomically write the receipt file."""
        self.receipt_path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.receipt_path.with_name(f"{self.receipt_path.name}.{os.getpid()}.tmp")
        try:
            with temporary.open("w", encoding="utf-8") as handle:
                data = {
                    "pid": receipt.pid,
                    "command": list(receipt.command),
                    "started_at": receipt.started_at,
                }
                json.dump(data, handle, separators=(",", ":"))
                handle.write("\n")
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary, self.receipt_path)
        except Exception:
            if temporary.exists():
                temporary.unlink(missing_ok=True)
            raise

    def _remove_dead_receipt(self, receipt: Optional[CollectorReceipt]) -> None:
        """Remove a dead receipt file."""
        if receipt and self.receipt_path.exists():
            self.events.write(
                "info",
                "receipt.removed",
                "CollectorManager._remove_dead_receipt",
                {"pid": receipt.pid},
            )
            self.receipt_path.unlink(missing_ok=True)

    def _require_identity(self, *, receipt: CollectorReceipt) -> None:
        """Verify that the live process matches the command persisted in the receipt.

        Compares against the receipt's own command, not a freshly recomputed
        expected_command(): the manager's configuration (e.g. python_executable)
        may differ from what was in effect when the collector was actually spawned.
        """
        live = self.process_api.command_line(pid=receipt.pid)
        if not _same_collector(live=live, recorded=receipt.command):
            raise CollectorOwnershipError(
                f"live process command does not match expected for PID {receipt.pid}"
            )

    def start(self) -> CollectorStatus:
        """Start the collector, idempotently if already running."""
        lock_fd = self._acquire_lock()
        try:
            receipt = self._read_receipt()
            if receipt and self.process_api.is_live(pid=receipt.pid):
                self._require_identity(receipt=receipt)
                if tuple(receipt.command) == tuple(self.expected_command()):
                    return self.status(now=self.process_api.now())
                # The verified collector predates the current configuration (snapshot path,
                # interval, interpreter); it would keep publishing where nothing reads.
                self.events.write(
                    "info",
                    "config.drift",
                    "CollectorManager.start",
                    {"pid": receipt.pid},
                )
                self._stop_locked()
                receipt = None
            self._remove_dead_receipt(receipt=receipt)
            pid = self.process_api.spawn(command=self.expected_command())
            self.events.write("info", "spawn", "CollectorManager.start", {"pid": pid})
            self._write_receipt(
                CollectorReceipt(
                    pid=pid,
                    command=tuple(self.expected_command()),
                    started_at=self.process_api.now().isoformat(),
                )
            )
            return self.status(now=self.process_api.now())
        finally:
            self._release_lock(lock_fd)

    def stop(self) -> None:
        """Stop the collector if owned, with bounded TERM/KILL logic."""
        lock_fd = self._acquire_lock()
        try:
            self._stop_locked()
        finally:
            self._release_lock(lock_fd)

    def _stop_locked(self) -> None:
        """Stop the owned collector; the caller holds the lifecycle lock."""
        receipt = self._read_receipt()
        if not receipt:
            return
        if not self.process_api.is_live(pid=receipt.pid):
            self._remove_dead_receipt(receipt=receipt)
            return
        self._require_identity(receipt=receipt)
        pid = receipt.pid

        self.process_api.signal(pid=pid, signum=signal.SIGTERM)
        self.events.write("info", "signal.SIGTERM", "CollectorManager.stop", {"pid": pid})

        deadline = self.process_api.monotonic() + self.stop_timeout_seconds
        while self.process_api.monotonic() < deadline:
            self.process_api.sleep(0.1)
            if not self.process_api.is_live(pid=pid):
                self.events.write("info", "exit", "CollectorManager.stop", {"pid": pid, "reason": "graceful"})
                self._remove_dead_receipt(receipt=receipt)
                return

        if not self.process_api.is_live(pid=pid):
            self.events.write("info", "exit", "CollectorManager.stop", {"pid": pid, "reason": "graceful"})
            self._remove_dead_receipt(receipt=receipt)
            return

        try:
            self._require_identity(receipt=receipt)
        except CollectorOwnershipError:
            self._remove_dead_receipt(receipt=receipt)
            raise

        self.process_api.signal(pid=pid, signum=signal.SIGKILL)
        self.events.write("info", "signal.SIGKILL", "CollectorManager.stop", {"pid": pid})

        deadline = self.process_api.monotonic() + self.stop_timeout_seconds
        while self.process_api.monotonic() < deadline:
            self.process_api.sleep(0.1)
            if not self.process_api.is_live(pid=pid):
                self.events.write("info", "exit", "CollectorManager.stop", {"pid": pid, "reason": "force"})
                self._remove_dead_receipt(receipt=receipt)
                return

        raise CollectorOwnershipError(f"collector process {pid} still alive after KILL signal")

    def _parse_snapshot_signals(self) -> dict[str, bool]:
        """Parse snapshot file and extract signal availability."""
        signals: dict[str, bool] = {}
        for signal_name in ("cpu", "memory", "gpu"):
            signals[signal_name] = False

        if not self.snapshot_path.exists():
            return signals

        try:
            with self.snapshot_path.open("r", encoding="utf-8") as handle:
                snapshot = json.load(handle)
            if not isinstance(snapshot, dict):
                return signals

            snapshot_signals = snapshot.get("signals")
            if not isinstance(snapshot_signals, dict):
                return signals

            for signal_name in ("cpu", "memory", "gpu"):
                signal_data = snapshot_signals.get(signal_name)
                if isinstance(signal_data, dict):
                    available = signal_data.get("available")
                    if isinstance(available, bool):
                        signals[signal_name] = available
        except (json.JSONDecodeError, OSError, UnicodeDecodeError):
            pass

        return signals

    def status(self, *, now: datetime) -> CollectorStatus:
        """Report current collector status."""
        receipt = self._read_receipt()
        pid: int | None = None
        running = False
        owned = False
        snapshot_age_seconds: float | None = None
        signals: dict[str, bool] = {}

        if receipt:
            pid = receipt.pid
            running = self.process_api.is_live(pid=pid)
            if running:
                try:
                    self._require_identity(receipt=receipt)
                    owned = True
                except CollectorOwnershipError:
                    owned = False

        if self.snapshot_path.exists():
            stat = self.snapshot_path.stat()
            mtime = datetime.fromtimestamp(stat.st_mtime, tz=timezone.utc)
            snapshot_age_seconds = (now - mtime).total_seconds()
            signals = self._parse_snapshot_signals()
            signals["snapshot_available"] = True
        else:
            for signal_name in ("cpu", "memory", "gpu"):
                signals[signal_name] = False
            signals["snapshot_available"] = False

        return CollectorStatus(
            pid=pid,
            running=running,
            owned=owned,
            snapshot_age_seconds=snapshot_age_seconds,
            signals=signals,
        )

import argparse
import ctypes
import json
import logging
import os
import pathlib
import re
import subprocess
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Mapping, Optional


@dataclass(frozen=True)
class CpuTicks:
    user: int
    system: int
    idle: int
    nice: int


class SampleUnavailable(Exception):
    """Raised when a metric sample cannot be collected."""

    pass


def cpu_percent(*, previous: CpuTicks, current: CpuTicks) -> float:
    """Calculate CPU utilization percentage from tick deltas.

    Args:
        previous: Prior CPU tick snapshot
        current: Current CPU tick snapshot

    Returns:
        CPU utilization as a percentage (0.0-100.0)

    Raises:
        SampleUnavailable: If ticks are non-monotonic or invalid
    """
    old = (previous.user, previous.system, previous.idle, previous.nice)
    new = (current.user, current.system, current.idle, current.nice)
    deltas = tuple(after - before for before, after in zip(old, new))
    if any(delta < 0 for delta in deltas) or sum(deltas) <= 0:
        raise SampleUnavailable("CPU ticks are non-monotonic")
    return round(max(0.0, min(100.0, 100.0 * (deltas[0] + deltas[1] + deltas[3]) / sum(deltas))), 1)


def parse_vm_stat(*, text: str, total_bytes: int) -> dict[str, int | float]:
    """Parse macOS vm_stat output.

    Args:
        text: Output from vm_stat command
        total_bytes: Total system memory in bytes

    Returns:
        Dict with used_bytes, total_bytes, and percent keys
    """
    page_size = 16384
    page_match = re.search(r"page size of (\d+) bytes", text)
    if page_match:
        page_size = int(page_match.group(1))

    # Match Activity Monitor's "Memory Used": app memory + wired + compressor,
    # where app memory = anonymous pages minus purgeable pages. "Pages active"
    # is NOT app memory: on a warm Mac it is dominated by active file cache, so
    # counting it pins the gauge near a constant and hides real app-memory
    # changes. Fall back to the active-page approximation only when vm_stat
    # omits the "Anonymous pages" line.
    fields = {
        "Pages active:": 0,
        "Pages wired down:": 0,
        "Pages occupied by compressor:": 0,
        "Pages purgeable:": 0,
        "Anonymous pages:": None,
    }
    for line in text.split("\n"):
        for key in fields:
            if line.startswith(key):
                match = re.search(r"(\d+)\.", line)
                if match:
                    fields[key] = int(match.group(1))

    wired = fields["Pages wired down:"]
    compressor = fields["Pages occupied by compressor:"]
    if fields["Anonymous pages:"] is not None:
        app_pages = max(0, fields["Anonymous pages:"] - fields["Pages purgeable:"])
        counted_pages = app_pages + wired + compressor
    else:
        counted_pages = fields["Pages active:"] + wired + compressor

    if counted_pages <= 0:
        # Unparseable output: report fully used so the gauge fails loud, not silent.
        used_bytes = total_bytes
    else:
        used_bytes = min(total_bytes, counted_pages * page_size)
    percent = round(100.0 * used_bytes / total_bytes, 1)

    return {
        "used_bytes": used_bytes,
        "total_bytes": total_bytes,
        "percent": percent,
    }


def parse_agx_utilization(*, text: str) -> float:
    """Parse Apple AGX GPU utilization from ioreg output.

    Args:
        text: ioreg output containing PerformanceStatistics

    Returns:
        Device Utilization % as a float
    """
    match = re.search(r'"Device Utilization %"=(\d+(?:\.\d+)?)', text)
    if match:
        return float(match.group(1))
    raise SampleUnavailable("No Device Utilization % found in AGX stats")


def read_cpu_ticks() -> CpuTicks:
    """Read CPU tick counts from macOS host_statistics.

    Returns:
        CpuTicks with current tick counts

    Raises:
        SampleUnavailable: If ticks cannot be read
    """
    try:
        libc = ctypes.CDLL(None)
        mach_host_self = libc.mach_host_self
        mach_host_self.restype = ctypes.c_int
        mach_host_self.argtypes = []

        host = mach_host_self()
        HOST_CPU_LOAD_INFO = 3
        CPU_STATE_USER = 0
        CPU_STATE_SYSTEM = 1
        CPU_STATE_IDLE = 2
        CPU_STATE_NICE = 3

        ticks = (ctypes.c_uint * 4)()
        count = ctypes.c_uint(4)

        host_statistics = libc.host_statistics
        host_statistics.restype = ctypes.c_int
        host_statistics.argtypes = [
            ctypes.c_int,
            ctypes.c_int,
            ctypes.c_void_p,
            ctypes.c_void_p,
        ]

        ret = host_statistics(host, HOST_CPU_LOAD_INFO, ctypes.byref(ticks), ctypes.byref(count))
        if ret != 0:
            raise SampleUnavailable("host_statistics failed")

        return CpuTicks(
            user=int(ticks[CPU_STATE_USER]),
            system=int(ticks[CPU_STATE_SYSTEM]),
            idle=int(ticks[CPU_STATE_IDLE]),
            nice=int(ticks[CPU_STATE_NICE]),
        )
    except Exception as e:
        raise SampleUnavailable(f"Failed to read CPU ticks: {e}")


def read_memory_stats() -> dict[str, int | float]:
    """Read memory statistics from macOS.

    Returns:
        Dict with used_bytes, total_bytes, and percent

    Raises:
        SampleUnavailable: If memory stats cannot be read
    """
    try:
        total_result = subprocess.run(
            ["sysctl", "-n", "hw.memsize"],
            capture_output=True,
            text=True,
            timeout=2,
        )
        if total_result.returncode != 0:
            raise SampleUnavailable("Failed to read hw.memsize")
        total_bytes = int(total_result.stdout.strip())

        vm_result = subprocess.run(
            ["vm_stat"],
            capture_output=True,
            text=True,
            timeout=2,
        )
        if vm_result.returncode != 0:
            raise SampleUnavailable("Failed to read vm_stat")

        return parse_vm_stat(text=vm_result.stdout, total_bytes=total_bytes)
    except Exception as e:
        raise SampleUnavailable(f"Failed to read memory stats: {e}")


def read_gpu_utilization() -> float:
    """Read Apple AGX GPU utilization.

    Returns:
        GPU utilization percentage

    Raises:
        SampleUnavailable: If GPU stats cannot be read
    """
    try:
        result = subprocess.run(
            ["ioreg", "-r", "-c", "AGXAccelerator", "-d", "1"],
            capture_output=True,
            text=True,
            timeout=2,
        )
        if result.returncode != 0:
            raise SampleUnavailable("Failed to run ioreg")
        return parse_agx_utilization(text=result.stdout)
    except Exception as e:
        raise SampleUnavailable(f"Failed to read GPU utilization: {e}")


class MetricReaders:
    """Inject-able metric readers for production use."""

    def __init__(self):
        self.cpu = read_cpu_ticks
        self.memory = read_memory_stats
        self.gpu = read_gpu_utilization


def _validate_memory_stats(stats: dict) -> Optional[dict]:
    """Validate and clamp memory statistics.

    Returns:
        Validated stats or None if invalid.
    """
    try:
        used = stats.get("used_bytes", 0)
        total = stats.get("total_bytes", 0)
        pct = stats.get("percent", 0.0)

        if used < 0 or total <= 0 or used > total:
            return None

        clamped_pct = max(0.0, min(100.0, float(pct)))
        return {
            "used_bytes": int(used),
            "total_bytes": int(total),
            "percent": clamped_pct,
        }
    except (ValueError, TypeError, KeyError):
        return None


def _validate_gpu_percent(percent: float) -> Optional[float]:
    """Validate and clamp GPU percentage.

    Returns:
        Clamped percentage (0.0-100.0) or None if invalid.
    """
    try:
        pct = float(percent)
        return max(0.0, min(100.0, pct))
    except (ValueError, TypeError):
        return None


def _read_signal(reader_value) -> tuple[Optional[object], Optional[dict]]:
    """Safely read a signal and capture errors.

    Handles both exception instances and normal values.

    Returns:
        (value, error_dict) where one is None and the other has data
    """
    if isinstance(reader_value, Exception):
        error_dict = {
            "code": type(reader_value).__name__,
            "message": str(reader_value),
        }
        return None, error_dict
    try:
        if callable(reader_value):
            value = reader_value()
        else:
            value = reader_value
        return value, None
    except Exception as e:
        error_dict = {
            "code": type(e).__name__,
            "message": str(e),
        }
        return None, error_dict


def collect_snapshot(
    *,
    readers: object = None,
    previous_cpu: Optional[CpuTicks] = None,
    collected_at: datetime = None,
) -> tuple[dict[str, object], Optional[CpuTicks]]:
    """Collect a system metrics snapshot.

    Args:
        readers: Object with cpu, memory, gpu attributes/methods
        previous_cpu: Previous CPU ticks for delta calculation
        collected_at: Timestamp for this collection

    Returns:
        (snapshot_dict, current_cpu_ticks)
    """
    if readers is None:
        readers = MetricReaders()
    if collected_at is None:
        collected_at = datetime.now(timezone.utc)

    iso_str = collected_at.isoformat()
    if iso_str.endswith("+00:00"):
        iso_str = iso_str[:-6] + "Z"

    snapshot = {
        "schema_version": 1,
        "collected_at": iso_str,
        "signals": {
            "cpu": {},
            "memory": {},
            "gpu": {},
        },
    }

    current_cpu, cpu_error = _read_signal(readers.cpu)
    if cpu_error:
        snapshot["signals"]["cpu"]["available"] = False
        snapshot["signals"]["cpu"]["error"] = cpu_error
        next_cpu = None
    else:
        current_cpu_ticks = current_cpu
        if previous_cpu is not None:
            try:
                cpu_val = cpu_percent(previous=previous_cpu, current=current_cpu_ticks)
                snapshot["signals"]["cpu"]["available"] = True
                snapshot["signals"]["cpu"]["percent"] = cpu_val
            except SampleUnavailable as e:
                snapshot["signals"]["cpu"]["available"] = False
                snapshot["signals"]["cpu"]["error"] = {
                    "code": "SampleUnavailable",
                    "message": str(e),
                }
        else:
            snapshot["signals"]["cpu"]["available"] = False
            snapshot["signals"]["cpu"]["error"] = {
                "code": "SampleUnavailable",
                "message": "First sample has no previous CPU ticks for delta",
            }
        next_cpu = current_cpu_ticks

    memory_stats, memory_error = _read_signal(readers.memory)
    if memory_error:
        snapshot["signals"]["memory"]["available"] = False
        snapshot["signals"]["memory"]["error"] = memory_error
    else:
        validated = _validate_memory_stats(memory_stats)
        if validated is None:
            snapshot["signals"]["memory"]["available"] = False
            snapshot["signals"]["memory"]["error"] = {
                "code": "ValidationError",
                "message": "Memory stats failed validation",
            }
        else:
            snapshot["signals"]["memory"]["available"] = True
            snapshot["signals"]["memory"].update(validated)

    gpu_util, gpu_error = _read_signal(readers.gpu)
    if gpu_error:
        snapshot["signals"]["gpu"]["available"] = False
        snapshot["signals"]["gpu"]["error"] = gpu_error
    else:
        clamped = _validate_gpu_percent(gpu_util)
        if clamped is None:
            snapshot["signals"]["gpu"]["available"] = False
            snapshot["signals"]["gpu"]["error"] = {
                "code": "ValidationError",
                "message": "GPU percent failed validation",
            }
        else:
            snapshot["signals"]["gpu"]["available"] = True
            snapshot["signals"]["gpu"]["percent"] = clamped

    return snapshot, next_cpu


def atomic_write_snapshot(*, path: pathlib.Path, payload: Mapping[str, object]) -> None:
    """Atomically write a JSON snapshot file.

    Args:
        path: Target file path
        payload: Data to write as JSON
    """
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o770)
    temporary = path.with_name(f"{path.name}.{os.getpid()}.tmp")
    with temporary.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, sort_keys=True, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temporary, path)


_CREDENTIAL_WHOLE_WORDS = frozenset({
    "token", "secret", "password", "passwd", "auth",
    "authorization", "credential", "credentials",
})

_CREDENTIAL_COMPOUND_SUBSTRINGS = frozenset({
    "apikey", "authtoken", "accesstoken", "clientsecret",
    "dbpassword", "refreshtoken", "sessiontoken",
})


def _split_key_words(key: str) -> list[str]:
    """Split a key into lowercase words on camelCase and non-alnum boundaries."""
    spaced = re.sub(r"(?<=[a-z0-9])(?=[A-Z])", "_", key)
    return re.findall(r"[a-z0-9]+", spaced.lower())


def _is_credential_key(key: object) -> bool:
    """Detect credential-shaped keys case-insensitively across naming styles."""
    if not isinstance(key, str):
        return False
    words = _split_key_words(key)
    if not words:
        return False
    if any(word in _CREDENTIAL_WHOLE_WORDS for word in words):
        return True
    if len(words) > 1:
        joined = "".join(words)
        return any(token in joined for token in _CREDENTIAL_COMPOUND_SUBSTRINGS)
    return False


class _JSONLFormatter(logging.Formatter):
    """Format log records as JSONL with typed ctx field."""

    def format(self, record: logging.LogRecord) -> str:
        iso_time = datetime.fromtimestamp(record.created, tz=timezone.utc).isoformat()
        if iso_time.endswith("+00:00"):
            iso_time = iso_time[:-6] + "Z"

        ctx = {}
        if hasattr(record, "run_id"):
            ctx["run_id"] = record.run_id

        event = record.getMessage()
        event = self._redact_credentials(event)

        ctx_data = self._redact_credentials(ctx) if ctx else {}

        payload = {
            "ts": iso_time,
            "run_id": getattr(record, "run_id", ""),
            "level": record.levelname,
            "event": event,
            "loc": f"{record.name}:{record.funcName}:{record.lineno}",
            "ctx": ctx_data,
        }
        return json.dumps(payload, separators=(",", ":"))

    @staticmethod
    def _redact_credentials(data):
        """Recursively redact credential-shaped keys and patterns."""
        if isinstance(data, dict):
            redacted = {}
            for k, v in data.items():
                if _is_credential_key(k):
                    redacted[k] = "***REDACTED***"
                else:
                    redacted[k] = _JSONLFormatter._redact_credentials(v)
            return redacted
        elif isinstance(data, str):
            redacted = data
            redacted = re.sub(r"Bearer\s+\S+", "Bearer ***REDACTED***", redacted)
            redacted = re.sub(r"(key|token|secret|password|passwd|api_key)=\S+", r"\1=***REDACTED***", redacted, flags=re.IGNORECASE)
            return redacted
        elif isinstance(data, (list, tuple)):
            return type(data)(_JSONLFormatter._redact_credentials(v) for v in data)
        else:
            return data


def _setup_logging(env_log_path: Optional[str] = None) -> logging.Logger:
    """Setup JSONL logging if env var is set.

    Args:
        env_log_path: Path to JSONL log file from environment

    Returns:
        Configured logger
    """
    logger = logging.getLogger("studio_metrics")
    for handler in logger.handlers[:]:
        handler.close()
        logger.removeHandler(handler)
    logger.setLevel(logging.INFO)
    logger.propagate = False

    if env_log_path:
        handler = logging.FileHandler(env_log_path)
        formatter = _JSONLFormatter()
        handler.setFormatter(formatter)
        logger.addHandler(handler)
    else:
        logger.addHandler(logging.NullHandler())

    return logger


def sample_loop(
    snapshot_path: pathlib.Path,
    interval: float = 5.0,
    run_id: Optional[str] = None,
    logger: Optional[logging.Logger] = None,
    readers=None,
    sleeper=None,
    stop_flag=None,
    max_iterations: Optional[int] = None,
) -> None:
    """Run the sampling loop.

    Args:
        snapshot_path: Where to write snapshots
        interval: Seconds between samples (minimum 1.0)
        run_id: Run identifier for logging
        logger: Logger instance
        readers: Metric readers instance (defaults to MetricReaders)
        sleeper: Optional sleep function for testing (defaults to time.sleep)
        stop_flag: Optional callable to check stop condition (testing only)
        max_iterations: Optional maximum iterations for testing
    """
    import time

    if interval < 1.0:
        interval = 1.0

    if run_id is None:
        run_id = str(uuid.uuid4())

    if logger is None:
        logger = logging.getLogger("studio_metrics")

    if sleeper is None:
        sleeper = time.sleep

    if readers is None:
        readers = MetricReaders()

    previous_cpu = None
    iteration_count = 0

    while True:
        if max_iterations is not None and iteration_count >= max_iterations:
            break
        if stop_flag and stop_flag():
            break

        iteration_count += 1

        try:
            snapshot, previous_cpu = collect_snapshot(
                readers=readers, previous_cpu=previous_cpu
            )
            atomic_write_snapshot(path=snapshot_path, payload=snapshot)
        except Exception as e:
            logger.error(f"Sampling error: {e}", extra={"run_id": run_id})

        sleeper(interval)


def build_parser() -> argparse.ArgumentParser:
    """Build the argument parser for CLI.

    Returns:
        Configured ArgumentParser
    """
    parser = argparse.ArgumentParser(description="Host Mac system metrics sampler")
    parser.add_argument(
        "--snapshot",
        type=pathlib.Path,
        required=True,
        help="Path to write snapshot JSON",
    )
    parser.add_argument(
        "--interval",
        type=float,
        default=5.0,
        help="Sampling interval in seconds (minimum 1.0, default 5.0)",
    )
    parser.add_argument(
        "--log",
        type=str,
        help="Path to JSONL log file (from env: STUDIO_METRICS_LOG)",
    )
    return parser


def parse_args(argv=None):
    """Parse command-line arguments.

    Args:
        argv: List of arguments (defaults to sys.argv[1:] if None)

    Returns:
        Parsed arguments namespace
    """
    parser = build_parser()
    return parser.parse_args(argv)


def main() -> None:
    """CLI entry point."""
    args = parse_args()

    log_path = args.log or os.environ.get("STUDIO_METRICS_LOG")
    logger = _setup_logging(env_log_path=log_path)
    run_id = str(uuid.uuid4())

    logger.info("Starting metrics sampler", extra={"run_id": run_id})
    sample_loop(
        snapshot_path=args.snapshot,
        interval=args.interval,
        run_id=run_id,
        logger=logger,
    )


if __name__ == "__main__":
    main()

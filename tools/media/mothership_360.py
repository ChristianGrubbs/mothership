#!/usr/bin/env python3
"""Mothership 360 on-demand conversion CLI (Task 6).

Invoked by ``packages/Mothership/DAM/src/Media/CliPanoramaWorker.php`` (the FIXED
protocol authority) as:

    python3 tools/media/mothership_360.py --request '<request-json>'

Reads one JSON request describing a source file (an ordinary equirectangular
video, or a DJI Osmo-style calibration-bearing ``.osv``) and produces a
validated 3840x1920 spherical H.264 proxy, streaming JSONL progress on
stdout. See ``CliPanoramaWorker``'s docblock for the authoritative protocol
— this module conforms to it, not the other way around.

Every stdout line is flushed immediately (``print(..., flush=True)``):
stdout is block-buffered when it is a pipe (not a tty), so without an
explicit flush every line would queue in this process's own buffer and
arrive in one burst at exit, silently defeating incremental progress on the
PHP side, which polls incrementally while this process runs.
"""

from __future__ import annotations

import hashlib
import json
import os
import pathlib
import shutil
import signal
import struct
import subprocess
import sys
import uuid
from datetime import datetime, timezone

# Bootstrap the repo root onto sys.path. This script is normally invoked
# directly (`python3 tools/media/mothership_360.py ...`, no `-m`, no
# PYTHONPATH set up by the PHP caller), in which case Python only puts the
# script's own directory (tools/media) on sys.path — `tools.media.panoforge`
# would not import without this.
_REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
if str(_REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(_REPO_ROOT))

from tools.media.panoforge.core.maps import MapSet, generate_remap_maps  # noqa: E402
from tools.media.panoforge.core.osv import OsvError  # noqa: E402
from tools.media.panoforge.core.osv import extract_metadata as pf_extract_metadata  # noqa: E402
from tools.media.panoforge.core.osv import probe as pf_probe  # noqa: E402
from tools.media.panoforge.core.spherical import SphericalError  # noqa: E402
from tools.media.panoforge.core.spherical import inject_spherical as pf_inject_spherical  # noqa: E402
from tools.media.panoforge.core.stitch import StitchOptions  # noqa: E402
from tools.media.panoforge.core.stitch import build_command as pf_build_command  # noqa: E402

LOC = "tools/media/mothership_360.py"

SUPPORTED_PROTOCOL = 1

# The single recipe/profile this CLI knows how to produce. A request naming
# a different recipe, or carrying known parameter keys with different
# values, cannot be honored by this implementation and is rejected rather
# than silently approximated — per the fixed contract, every recognized
# parameter key "MUST be honored", never merely echoed back.
PROFILE = {
    "recipe": "pano360-h264-4k",
    "width": 3840,
    "height": 1920,
    "video_codec": "h264",
    "pixel_format": "yuv420p",
    "audio_codec": "aac",
    "faststart": True,
    "spherical_metadata": True,
}

ALLOWED_REQUEST_KEYS = {
    "protocol", "rendition_id", "recipe", "source_path", "output_dir", "parameters",
}

_STAGE_ALLOWED = set("abcdefghijklmnopqrstuvwxyz0123456789_-")

# Set by main() for the lifetime of one invocation; used to correlate every
# JSONL line emitted during this run.
_RUN_ID = "unset"

# The currently-running ffmpeg child (or None). Read by the SIGTERM handler
# so it can terminate the child's whole process group on cancellation.
_ACTIVE_PROC: subprocess.Popen | None = None

# Set true by the SIGTERM handler; polled between pipeline stages so a
# cancellation request is honored promptly even outside the ffmpeg loop.
_CANCELLED = False


class CliError(Exception):
    """A CLI-detected failure with a short, contract-shaped reason code."""

    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


# ---------------------------------------------------------------------------
# JSONL emission
# ---------------------------------------------------------------------------

def _sanitize_stage(value: str) -> str:
    value = value.strip().lower()
    value = "".join(ch for ch in value if ch in _STAGE_ALLOWED)
    return value[:64] if value else "unknown"


def _emit(event: str, level: str = "info", **fields) -> None:
    """Write one JSONL line and flush immediately.

    Contract-recognized top-level keys for this event type (``percent``,
    ``stage``, ``path``, ``mime``, ``technical``, ``code``) are passed
    through as top-level JSON keys, exactly where CliPanoramaWorker reads
    them (``$event['percent']``, ``$event['path']``, etc. — never nested).
    Everything else this project's own logging discipline wants (rendition
    id, source basename, checksum prefix, ...) goes under ``ctx``.
    """
    contract_keys = {"percent", "stage", "path", "mime", "technical", "code"}
    top = {k: v for k, v in fields.items() if k in contract_keys}
    ctx = {k: v for k, v in fields.items() if k not in contract_keys}

    line = {
        "ts": datetime.now(timezone.utc).isoformat(),
        "run_id": _RUN_ID,
        "level": level,
        "event": event,
        "loc": LOC,
        **top,
        "ctx": ctx,
    }
    print(json.dumps(line, separators=(",", ":")), flush=True)


def _emit_progress(percent: int, stage: str) -> None:
    _emit("progress", percent=int(percent), stage=_sanitize_stage(stage))


def _emit_result(path: str, mime: str, technical: dict) -> None:
    _emit("result", path=path, mime=mime, technical=technical)


def _emit_error(code: str, **ctx) -> None:
    _emit("error", level="error", code=_sanitize_stage(code), **ctx)


# ---------------------------------------------------------------------------
# Redaction helpers — never echo full source/output paths into logs.
# ---------------------------------------------------------------------------

def _basename(path: str) -> str:
    return os.path.basename(path)


def _checksum_prefix(path: str, length: int = 12) -> str:
    digest = hashlib.sha256()
    try:
        with open(path, "rb") as fh:
            for chunk in iter(lambda: fh.read(1024 * 1024), b""):
                digest.update(chunk)
    except OSError:
        return "unreadable"
    return digest.hexdigest()[:length]


# ---------------------------------------------------------------------------
# Request validation
# ---------------------------------------------------------------------------

def _parse_request(raw: str) -> dict:
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise CliError("invalid_request_json") from exc
    if not isinstance(payload, dict):
        raise CliError("invalid_request_shape")
    return payload


def validate_request(payload: dict) -> dict:
    # Unrecognized top-level keys (beyond ALLOWED_REQUEST_KEYS, kept as
    # documentation of the currently-known shape) are a forward-compatible
    # no-op, never an error -- the same rule the fixed contract states
    # explicitly for unknown `parameters` keys ("the CLI MUST treat any key
    # it does not recognize as a forward-compatible no-op, never an
    # error"), generalized to the request envelope itself so a future
    # additive field doesn't hard-fail every attempt until this CLI is
    # updated to know about it.

    protocol = payload.get("protocol")
    if protocol != SUPPORTED_PROTOCOL:
        raise CliError("unsupported_protocol")

    recipe = payload.get("recipe")
    if recipe != PROFILE["recipe"]:
        raise CliError("recipe_mismatch")

    parameters = payload.get("parameters") or {}
    if not isinstance(parameters, dict):
        raise CliError("profile_mismatch")

    for key in ("width", "height", "video_codec", "pixel_format", "audio_codec", "faststart", "spherical_metadata"):
        if key in parameters and parameters[key] != PROFILE[key]:
            raise CliError("profile_mismatch")

    source_path = payload.get("source_path")
    output_dir = payload.get("output_dir")
    if not isinstance(source_path, str) or not source_path:
        raise CliError("source_path_missing")
    if not isinstance(output_dir, str) or not output_dir:
        raise CliError("output_dir_missing")
    if not os.path.isdir(output_dir):
        raise CliError("output_dir_missing")
    if not os.path.isfile(source_path):
        raise CliError("source_unreadable")

    mode = "osv" if source_path.lower().endswith(".osv") else "video"
    width = int(parameters.get("width", PROFILE["width"]))
    height = int(parameters.get("height", PROFILE["height"]))

    output_dir_real = os.path.realpath(output_dir)
    final_output = os.path.join(output_dir, "preview.mp4")
    intermediate_path = os.path.join(output_dir, "stitched.mp4" if mode == "osv" else "transcoded.mp4")

    source_real = os.path.realpath(source_path)
    for candidate in (final_output, intermediate_path):
        if os.path.realpath(candidate) == source_real:
            raise CliError("source_output_conflict")
        if os.path.dirname(os.path.realpath(candidate)) != output_dir_real:
            raise CliError("output_outside_workdir")

    return {
        "rendition_id": payload.get("rendition_id"),
        "recipe": recipe,
        "source_path": source_path,
        "output_dir": output_dir,
        "mode": mode,
        "width": width,
        "height": height,
        "final_output": final_output,
        "intermediate_path": intermediate_path,
    }


def ensure_calibration(source_path: str, output_dir: str) -> dict:
    try:
        metadata = pf_extract_metadata(source_path, output_dir)
    except OsvError as exc:
        # A malformed/unreadable djmd track (extract_djmd.py failing, e.g.
        # a KeyError deep in maps.py's _radial_model on garbled lens
        # fields) is functionally the same failure as no calibration at
        # all from this CLI's point of view: it cannot stitch. The brief
        # asks for calibration_missing on missing OR invalid calibration.
        raise CliError("calibration_missing") from exc
    calibration = metadata.get("calibration")
    if not calibration or not calibration.get("lenses"):
        raise CliError("calibration_missing")
    return metadata


def probe_source(source_path: str):
    try:
        return pf_probe(source_path)
    except OsvError as exc:
        raise CliError("source_unreadable") from exc


# ---------------------------------------------------------------------------
# FFmpeg command construction
# ---------------------------------------------------------------------------

def build_video_command(source: str, output: str, width: int = 3840, height: int = 1920) -> list[str]:
    return [
        "ffmpeg", "-hide_banner", "-y", "-i", source,
        "-vf", f"scale={width}:{height}:force_original_aspect_ratio=disable",
        "-c:v", "libx264", "-pix_fmt", "yuv420p", "-crf", "20", "-preset", "medium",
        "-c:a", "aac", "-b:a", "192k", "-movflags", "+faststart",
        "-progress", "pipe:1", "-nostats", "-f", "mp4", output,
    ]


def build_osv_command(source: str, output: str, maps: MapSet, out_w: int = 3840) -> list[str]:
    options = StitchOptions(
        out_w=out_w,
        codec="h264",
        encoder="cpu",
        quality=20,
        mode="calibrated",
    )
    command = pf_build_command(source, output, options, maps)
    audio_index = command.index("-c:a")
    command[audio_index + 1] = "aac"
    command[audio_index + 2:audio_index + 2] = ["-b:a", "192k"]
    command[-1:-1] = ["-movflags", "+faststart", "-f", "mp4"]
    return command


def parse_progress(block: dict, duration_s: float) -> int | None:
    if duration_s <= 0:
        return 0
    try:
        out_time_us = int(block.get("out_time_ms", "0"))
    except (TypeError, ValueError):
        return None
    return max(0, min(99, int((out_time_us / 1_000_000) / duration_s * 100)))


# ---------------------------------------------------------------------------
# FFmpeg execution + progress streaming
# ---------------------------------------------------------------------------

def run_ffmpeg(command: list[str], duration_s: float, on_progress) -> int:
    global _ACTIVE_PROC
    proc = subprocess.Popen(
        command,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        bufsize=1,
        start_new_session=True,
    )
    _ACTIVE_PROC = proc
    block: dict = {}
    try:
        for raw_line in proc.stdout:
            line = raw_line.strip()
            if not line:
                continue
            if line.startswith("progress="):
                percent = parse_progress(block, duration_s)
                if percent is not None:
                    on_progress(percent)
                block = {}
                if line == "progress=end":
                    break
                continue
            key, sep, value = line.partition("=")
            if sep:
                block[key] = value
    finally:
        try:
            if proc.stdout is not None:
                proc.stdout.close()
        except OSError:
            pass
        proc.wait()
        _ACTIVE_PROC = None
    return proc.returncode


# ---------------------------------------------------------------------------
# Output validation (faststart via raw box scan, everything else via ffprobe)
# ---------------------------------------------------------------------------

def _iter_top_level_boxes(path: str):
    with open(path, "rb") as fh:
        size_total = os.fstat(fh.fileno()).st_size
        offset = 0
        while offset + 8 <= size_total:
            fh.seek(offset)
            header = fh.read(8)
            if len(header) < 8:
                break
            size = int.from_bytes(header[0:4], "big")
            typ = header[4:8]
            hdr = 8
            if size == 1:
                size64 = fh.read(8)
                if len(size64) < 8:
                    break
                size = int.from_bytes(size64, "big")
                hdr = 16
            elif size == 0:
                size = size_total - offset
            if size < hdr:
                break
            yield typ, offset, size
            offset += size


def _verify_faststart(path: str) -> bool:
    moov_offset = None
    mdat_offset = None
    for typ, offset, _size in _iter_top_level_boxes(path):
        if typ == b"moov" and moov_offset is None:
            moov_offset = offset
        elif typ == b"mdat" and mdat_offset is None:
            mdat_offset = offset
    return moov_offset is not None and mdat_offset is not None and moov_offset < mdat_offset


# ---------------------------------------------------------------------------
# Faststart remux: move `moov` from end-of-file back to front, in place.
#
# The vendored `inject_spherical()` (tools/media/panoforge/core/spherical.py
# `_relocate_moov_to_end`) ALWAYS moves `moov` to the END of the file before
# growing it with the injected v1 uuid / v2 sv3d boxes -- that is how it can
# safely enlarge `moov` without touching any existing track's sample bytes.
# That means every file this CLI's `inject_spherical_metadata()` produces is
# moov-LAST (not faststart) by construction, 100% of the time, in both
# modes. A `-c copy` ffmpeg remux to fix this is not an option: it would
# strip the injected uuid/sv3d boxes right back out.
#
# This is the exact inverse of `_relocate_moov_to_end`, reimplemented here
# (not imported -- `tools/media/panoforge/` is pinned and must not be
# touched) using the same box-header-parsing and stco/co64 offset-patching
# approach, with the sign of the patch reversed: moving moov EARLIER in the
# file shifts everything from the insertion point onward FORWARD by
# moov's size, so any absolute sample offset at or past that point must
# have moov's size ADDED (the vendored code SUBTRACTS moov's size, because
# it moves moov the other direction).
# ---------------------------------------------------------------------------

_CONTAINER_TYPES = {b"trak", b"mdia", b"minf", b"stbl", b"edts", b"udta"}


def _read_box_header(buf: bytes, off: int) -> tuple[bytes, int, int]:
    if off + 8 > len(buf):
        raise CliError("faststart_remux_truncated_box")
    size = int.from_bytes(buf[off:off + 4], "big")
    typ = bytes(buf[off + 4:off + 8])
    hdr = 8
    if size == 1:
        size = int.from_bytes(buf[off + 8:off + 16], "big")
        hdr = 16
    elif size == 0:
        size = len(buf) - off
    if size < hdr:
        raise CliError("faststart_remux_invalid_box")
    return typ, size, hdr


def _iter_boxes(buf: bytes, start: int, end: int):
    off = start
    while off + 8 <= end:
        typ, size, hdr = _read_box_header(buf, off)
        yield typ, off, size, hdr
        off += size


def _patch_sample_offsets_forward(buf: bytearray, start: int, end: int, threshold: int, delta: int) -> None:
    """Adds `delta` to every stco/co64 sample offset >= `threshold`,
    recursing into the same container box types the vendored
    `_patch_sample_offsets` does. Mirrors that function's structure exactly;
    only the comparison direction and the sign of the adjustment differ."""
    for typ, off, size, hdr in list(_iter_boxes(buf, start, end)):
        body = off + hdr
        if typ == b"stco":
            count = int.from_bytes(buf[body + 4:body + 8], "big")
            base = body + 8
            for i in range(count):
                p = base + 4 * i
                v = int.from_bytes(buf[p:p + 4], "big")
                if v >= threshold:
                    v += delta
                struct.pack_into(">I", buf, p, v)
        elif typ == b"co64":
            count = int.from_bytes(buf[body + 4:body + 8], "big")
            base = body + 8
            for i in range(count):
                p = base + 8 * i
                v = int.from_bytes(buf[p:p + 8], "big")
                if v >= threshold:
                    v += delta
                struct.pack_into(">Q", buf, p, v)
        elif typ in _CONTAINER_TYPES:
            _patch_sample_offsets_forward(buf, body, off + size, threshold, delta)


def _remux_moov_to_front(path: str) -> None:
    """Rewrites `path` in place, relocating `moov` from wherever
    `inject_spherical()` left it (always after `mdat`) to immediately after
    `ftyp` (or the very start of the file, if there is no `ftyp`), patching
    every stco/co64 sample offset inside `moov` to match. A no-op if `moov`
    is already in front.

    Guard: the offset-patching below is only correct when `moov` currently
    sits AFTER `mdat` (the only layout `inject_spherical()` ever produces).
    In that case relocating `moov` earlier pushes `mdat` (and everything
    else at or past the insertion point) forward by `moov`'s size, so every
    stco/co64 offset at or past the insertion point genuinely needs
    `moov_size` added.

    If `moov` is already BEFORE `mdat` -- e.g. `ftyp|free|moov|mdat`, not
    reachable from the real injector today but a layout this function's
    docstring otherwise implies it handles generally -- relocating `moov`
    to immediately after `ftyp` only reorders bytes that are already all
    before `mdat` (ftyp + whatever sits between ftyp and moov + moov
    itself); the total length of that region, and therefore `mdat`'s
    absolute file offset, does not change. Patching offsets in that case
    would corrupt every sample pointer by adding `moov_size` to offsets
    that never moved. Since the file is already faststart (`moov` precedes
    `mdat`), there is nothing to fix -- return without touching the file."""
    try:
        with open(path, "rb") as fh:
            data = fh.read()

        boxes = list(_iter_boxes(data, 0, len(data)))
        moov = next((b for b in boxes if b[0] == b"moov"), None)
        if moov is None:
            raise CliError("faststart_remux_moov_missing")
        _, moov_off, moov_size, moov_hdr = moov

        mdat = next((b for b in boxes if b[0] == b"mdat"), None)
        if mdat is not None and moov_off < mdat[1]:
            return  # already faststart (moov precedes mdat) -- nothing to relocate

        ftyp = next((b for b in boxes if b[0] == b"ftyp"), None)
        insert_at = (ftyp[1] + ftyp[2]) if ftyp is not None else 0

        if moov_off == insert_at:
            return  # already in the right place -- nothing to do

        moov_bytes = bytearray(data[moov_off:moov_off + moov_size])
        _patch_sample_offsets_forward(moov_bytes, moov_hdr, moov_size, insert_at, moov_size)

        out = bytearray()
        out += data[:insert_at]
        out += moov_bytes
        out += data[insert_at:moov_off]
        out += data[moov_off + moov_size:]

        with open(path, "wb") as fh:
            fh.write(out)
    except CliError:
        raise
    except (OSError, struct.error, IndexError) as exc:
        raise CliError("faststart_remux_failed") from exc


def validate_output(path: str, expected_width: int, expected_height: int) -> dict:
    proc = subprocess.run(
        ["ffprobe", "-v", "error", "-show_format", "-show_streams", "-print_format", "json", path],
        capture_output=True, text=True, timeout=30,
    )
    if proc.returncode != 0:
        raise CliError("output_probe_failed")
    try:
        data = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        raise CliError("output_probe_unreadable") from exc

    streams = data.get("streams", [])
    video = next((s for s in streams if s.get("codec_type") == "video"), None)
    if video is None:
        raise CliError("output_missing_video_stream")

    width = int(video.get("width") or 0)
    height = int(video.get("height") or 0)
    if width != expected_width or height != expected_height:
        raise CliError("unexpected_output_dimensions")

    codec_name = str(video.get("codec_name") or "")
    codec_lower = codec_name.lower()
    if "h264" not in codec_lower and "avc" not in codec_lower:
        raise CliError("unexpected_video_codec")

    pix_fmt = str(video.get("pix_fmt") or "")
    if pix_fmt.lower() != "yuv420p":
        raise CliError("unexpected_pixel_format")

    if not _verify_faststart(path):
        raise CliError("faststart_verification_failed")

    # Report the audio codec HONESTLY. This CLI's own commands always force
    # `-c:a aac` when a source audio stream exists (never `-c:a copy`), so
    # "aac" and "none" are the only values a correct run should ever
    # produce -- but the whole point of this field is to catch a codec
    # browsers can't decode surviving into the container. Mapping anything
    # unrecognized to "copied" would defeat that: "copied" is a valid enum
    # value on the PHP side, so opus/ac3/mp3 would silently pass. Reporting
    # the real codec name instead means `RenditionPublisher` correctly
    # rejects it (`invalid_audio`) if this ever happens.
    audio_streams = [s for s in streams if s.get("codec_type") == "audio"]
    if not audio_streams:
        audio = "none"
    else:
        audio_codec = str(audio_streams[0].get("codec_name") or "").lower()
        audio = audio_codec if audio_codec else "unknown_audio_codec"

    # Derive `spherical` post-hoc from the actual file, rather than trusting
    # that inject_spherical() not raising means the output is correct --
    # C1 proved those two things can diverge (a successful injection whose
    # result then fails faststart is exactly that: "didn't raise" is not
    # "produced a correct final file"). FFmpeg's mov demuxer surfaces the
    # injected v2 `sv3d` box as an AV_PKT_DATA_SPHERICAL side-data entry
    # ("Spherical Mapping") on the video stream when it parses it.
    side_data = video.get("side_data_list") or []
    spherical = any(
        "spherical" in str(entry.get("side_data_type", "")).lower()
        for entry in side_data
    )
    if not spherical:
        raise CliError("spherical_metadata_verification_failed")

    try:
        duration_s = float((data.get("format") or {}).get("duration") or 0.0)
    except (TypeError, ValueError):
        duration_s = 0.0

    return {
        "width": width,
        "height": height,
        "codec_name": codec_name,
        "pix_fmt": "yuv420p",
        "spherical": True,
        "faststart": True,
        "audio": audio,
        "duration_s": duration_s,
    }


def inject_spherical_metadata(source: str, destination: str) -> None:
    try:
        pf_inject_spherical(source, destination)
    except (SphericalError, OSError) as exc:
        raise CliError("spherical_injection_failed") from exc


# ---------------------------------------------------------------------------
# Cleanup / cancellation
# ---------------------------------------------------------------------------

def _safe_remove(path: str | None) -> None:
    if not path:
        return
    try:
        if os.path.isdir(path):
            shutil.rmtree(path, ignore_errors=True)
        elif os.path.exists(path):
            os.remove(path)
    except OSError:
        pass


def _remove_created_artifacts(paths: list[str], keep_path: str) -> None:
    """Removes exactly the files this CLI itself created during the run
    (the intermediate transcode/stitch, and -- OSV mode -- the extracted
    calibration.json/IMU csvs and remap-map pgms), leaving only the final
    output behind, per the fixed contract's flat-output requirement.

    Deliberately narrow: this does NOT sweep the whole output_dir. The
    staged source file living in the same directory was placed there by
    the caller (CliPanoramaWorker::stageSource()), not by this CLI --
    deleting it is outside this CLI's ownership and would remove the one
    artifact useful for post-mortem debugging a failed-looking success.
    Anything not explicitly tracked as CLI-created is left untouched."""
    keep_real = os.path.realpath(keep_path)
    for path in paths:
        if not path:
            continue
        if os.path.realpath(path) == keep_real:
            continue
        _safe_remove(path)


def _cleanup_partial(to_cleanup: list[str]) -> None:
    for path in to_cleanup:
        _safe_remove(path)


def _handle_sigterm(signum, frame) -> None:  # noqa: ARG001 - signal handler signature
    global _CANCELLED
    _CANCELLED = True
    proc = _ACTIVE_PROC
    if proc is not None and proc.poll() is None:
        try:
            os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        except (ProcessLookupError, PermissionError, OSError):
            pass
        try:
            proc.wait(timeout=1.5)
        except subprocess.TimeoutExpired:
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGKILL)
            except (ProcessLookupError, PermissionError, OSError):
                pass


def _install_signal_handlers() -> None:
    signal.signal(signal.SIGTERM, _handle_sigterm)


def _check_cancelled(to_cleanup: list[str]) -> None:
    if _CANCELLED:
        _cleanup_partial(to_cleanup)
        _emit("cancelled")
        sys.exit(130)


# ---------------------------------------------------------------------------
# Argument parsing (deliberately not argparse: an argparse usage error
# writes to stderr, which the fixed contract requires stay near-silent —
# every failure, including a malformed invocation, must go out as a JSONL
# `error` event on stdout instead)
# ---------------------------------------------------------------------------

def _extract_request_arg(argv: list[str]) -> str | None:
    for i, arg in enumerate(argv):
        if arg == "--request" and i + 1 < len(argv):
            return argv[i + 1]
        if arg.startswith("--request="):
            return arg[len("--request="):]
    return None


# ---------------------------------------------------------------------------
# Main pipeline
# ---------------------------------------------------------------------------

def main(argv: list[str]) -> int:
    global _RUN_ID
    _RUN_ID = str(uuid.uuid4())
    _install_signal_handlers()

    to_cleanup: list[str] = []

    try:
        raw_request = _extract_request_arg(argv)
        if raw_request is None:
            raise CliError("missing_request_argument")

        payload = _parse_request(raw_request)
        ctx = validate_request(payload)
        _check_cancelled(to_cleanup)

        _emit(
            "probe",
            rendition_id=ctx["rendition_id"],
            mode=ctx["mode"],
            source=_basename(ctx["source_path"]),
            checksum=_checksum_prefix(ctx["source_path"]),
        )
        info = probe_source(ctx["source_path"])
        duration_s = float(getattr(info, "duration_s", 0.0) or 0.0)
        _check_cancelled(to_cleanup)

        mapset = None
        created_artifacts: list[str] = []

        def _track(path: str | None) -> None:
            # Registers a CLI-created artifact for cleanup THE MOMENT it is
            # created, in both `created_artifacts` (the on-success sweep
            # list) and `to_cleanup` (the on-failure sweep list). Appending
            # to `to_cleanup` only after every artifact for this run had
            # already been produced (the previous single `.extend()` below)
            # left a window where an early raise -- inside
            # `ensure_calibration`/`generate_remap_maps`, the
            # `mapset.calibrated` check, or `_check_cancelled` -- skipped
            # the `.extend()` entirely and orphaned calibration.json/IMU
            # csvs/remap maps that had already been written to disk.
            if not path:
                return
            created_artifacts.append(path)
            to_cleanup.append(path)

        if ctx["mode"] == "osv":
            metadata = ensure_calibration(ctx["source_path"], ctx["output_dir"])
            calibration = metadata["calibration"]
            _track(os.path.join(ctx["output_dir"], "calibration.json"))
            _track(metadata.get("imu_perframe"))
            _track(metadata.get("imu_highrate"))
            _check_cancelled(to_cleanup)
            mapset = generate_remap_maps(calibration, ctx["width"], ctx["height"], ctx["output_dir"])
            if not mapset.calibrated:
                # Belt-and-braces: generate_remap_maps() falls back to an
                # uncalibrated ideal-lens geometry when calibration.get
                # ("lenses") is falsy. ensure_calibration() already checked
                # this same condition, but defends against calibration
                # being truthy-but-malformed in a way maps.py itself
                # rejects downstream instead of using.
                raise CliError("calibration_missing")
            for path in mapset.xmaps:
                _track(path)
            for path in mapset.ymaps:
                _track(path)
            _track(mapset.blend_mask)
            _emit("maps", calibrated=mapset.calibrated, out_w=mapset.out_w, out_h=mapset.out_h)
            command = build_osv_command(ctx["source_path"], ctx["intermediate_path"], mapset, out_w=ctx["width"])
            stage = "stitching"
        else:
            command = build_video_command(ctx["source_path"], ctx["intermediate_path"], ctx["width"], ctx["height"])
            stage = "transcoding"

        _track(ctx["intermediate_path"])
        _check_cancelled(to_cleanup)
        _emit("ffmpeg_started", mode=ctx["mode"], stage=stage)

        last_percent = {"value": 0}

        def on_progress(percent: int) -> None:
            last_percent["value"] = max(last_percent["value"], percent)
            _emit_progress(last_percent["value"], stage)

        returncode = run_ffmpeg(command, duration_s, on_progress)
        _check_cancelled(to_cleanup)

        if returncode != 0:
            raise CliError("ffmpeg_crashed")
        if not os.path.isfile(ctx["intermediate_path"]):
            raise CliError("ffmpeg_produced_no_output")

        to_cleanup.append(ctx["final_output"])
        inject_spherical_metadata(ctx["intermediate_path"], ctx["final_output"])
        _emit("spherical_metadata", flavor="v1_gspherical_xml+v2_sv3d")
        _check_cancelled(to_cleanup)

        # inject_spherical() always leaves `moov` at the END of the file
        # (see _remux_moov_to_front's docstring) -- move it back in front
        # before anything downstream checks faststart.
        _remux_moov_to_front(ctx["final_output"])
        _check_cancelled(to_cleanup)

        technical = validate_output(ctx["final_output"], ctx["width"], ctx["height"])

        _remove_created_artifacts(created_artifacts, ctx["final_output"])

        _emit_progress(100, "finalizing")
        _emit_result(ctx["final_output"], "video/mp4", technical)
        return 0

    except CliError as exc:
        _cleanup_partial(to_cleanup)
        _emit_error(exc.code)
        return 1
    except SystemExit:
        raise
    except Exception:  # noqa: BLE001 - last-resort guard: stderr MUST stay silent
        _cleanup_partial(to_cleanup)
        _emit_error("unexpected_error")
        return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

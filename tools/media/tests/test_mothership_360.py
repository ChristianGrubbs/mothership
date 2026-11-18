import io
import json
import os
import pathlib
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT))

from tools.media.mothership_360 import (  # noqa: E402
    CliError,
    build_osv_command,
    build_video_command,
    parse_progress,
    validate_output,
    validate_request,
    _verify_faststart,
)
import tools.media.mothership_360 as cli_module  # noqa: E402
from tools.media.panoforge.core.maps import MapSet  # noqa: E402
from tools.media.panoforge.core.osv import OsvInfo  # noqa: E402

FIXTURES = ROOT / "tools/media/tests/fixtures"
BIN_DIR = pathlib.Path(__file__).resolve().parent / "fake_bin"

REQUEST_KEYS = {
    "protocol": 1,
    "rendition_id": 42,
    "recipe": "pano360-h264-4k",
}


def fixture_mapset():
    return MapSet(
        out_w=3840,
        out_h=1920,
        xmaps=["/tmp/x0.pgm", "/tmp/x1.pgm"],
        ymaps=["/tmp/y0.pgm", "/tmp/y1.pgm"],
        blend_mask="/tmp/mask.pgm",
        calibrated=True,
    )


def _make_fake_mp4(path, order=("ftyp", "moov", "mdat")):
    with open(path, "wb") as f:
        for typ in order:
            body = b"x" * 16
            box = (8 + len(body)).to_bytes(4, "big") + typ.encode("ascii")
            f.write(box)
            f.write(body)


def make_request(source_path, output_dir, **overrides):
    payload = {
        **REQUEST_KEYS,
        "source_path": source_path,
        "output_dir": output_dir,
        "parameters": {
            "width": 3840, "height": 1920, "video_codec": "h264",
            "pixel_format": "yuv420p", "audio_codec": "aac",
            "faststart": True, "spherical_metadata": True,
        },
    }
    payload.update(overrides)
    return payload


class BriefRequiredTests(unittest.TestCase):
    """The three tests specified verbatim in task-6-brief.md Step 1."""

    def test_general_video_profile_is_browser_safe(self):
        command = build_video_command("in.mov", "out.mp4", 3840, 1920)
        self.assertIn("libx264", command)
        self.assertIn("yuv420p", command)
        self.assertIn("+faststart", command)
        self.assertIn("aac", command)
        self.assertEqual(command[-1], "out.mp4")

    def test_osv_profile_requires_calibrated_maps(self):
        command = build_osv_command("in.osv", "out.mp4", fixture_mapset())
        graph = command[command.index("-filter_complex") + 1]
        self.assertEqual(graph.count("remap"), 2)
        self.assertIn("maskedmerge", graph)

    def test_progress_is_clamped(self):
        self.assertEqual(parse_progress({"out_time_ms": "5000000"}, 10.0), 50)
        self.assertEqual(parse_progress({"out_time_ms": "20000000"}, 10.0), 99)


class RequestValidationTests(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        self.source = os.path.join(self.work.name, "source.mp4")
        with open(self.source, "wb") as fh:
            fh.write(b"not a real video, just needs to exist")

    def test_unknown_top_level_key_is_forward_compatible_noop(self):
        # The fixed contract says an unrecognized key -- top-level or
        # within `parameters` -- MUST be a no-op, never an error, so a
        # future additive field doesn't hard-fail every attempt until this
        # CLI is updated.
        req = make_request(self.source, self.work.name, extra_field="nope")
        ctx = validate_request(req)
        self.assertEqual(ctx["mode"], "video")

    def test_unsupported_protocol_rejected(self):
        req = make_request(self.source, self.work.name, protocol=99)
        with self.assertRaises(CliError) as ctx:
            validate_request(req)
        self.assertEqual(ctx.exception.code, "unsupported_protocol")

    def test_recipe_mismatch_rejected(self):
        req = make_request(self.source, self.work.name, recipe="something-else")
        with self.assertRaises(CliError) as ctx:
            validate_request(req)
        self.assertEqual(ctx.exception.code, "recipe_mismatch")

    def test_profile_mismatch_rejected(self):
        req = make_request(self.source, self.work.name)
        req["parameters"]["pixel_format"] = "yuv444p"
        with self.assertRaises(CliError) as ctx:
            validate_request(req)
        self.assertEqual(ctx.exception.code, "profile_mismatch")

    def test_unknown_parameter_key_is_forward_compatible_noop(self):
        req = make_request(self.source, self.work.name)
        req["parameters"]["future_knob"] = "whatever"
        ctx = validate_request(req)
        self.assertEqual(ctx["mode"], "video")

    def test_unreadable_source_rejected(self):
        req = make_request(os.path.join(self.work.name, "missing.mp4"), self.work.name)
        with self.assertRaises(CliError) as ctx:
            validate_request(req)
        self.assertEqual(ctx.exception.code, "source_unreadable")

    def test_output_dir_missing_rejected(self):
        req = make_request(self.source, os.path.join(self.work.name, "does-not-exist"))
        with self.assertRaises(CliError) as ctx:
            validate_request(req)
        self.assertEqual(ctx.exception.code, "output_dir_missing")

    def test_source_output_path_equality_rejected(self):
        # Source is literally named preview.mp4 -- collides with the CLI's
        # own chosen final-output filename inside the same directory.
        colliding = os.path.join(self.work.name, "preview.mp4")
        os.replace(self.source, colliding)
        req = make_request(colliding, self.work.name)
        with self.assertRaises(CliError) as ctx:
            validate_request(req)
        self.assertEqual(ctx.exception.code, "source_output_conflict")

    def test_osv_extension_selects_osv_mode(self):
        osv_source = os.path.join(self.work.name, "source.osv")
        os.replace(self.source, osv_source)
        req = make_request(osv_source, self.work.name)
        ctx = validate_request(req)
        self.assertEqual(ctx["mode"], "osv")


class FaststartAndOutputValidationTests(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)

    def test_faststart_true_when_moov_precedes_mdat(self):
        path = os.path.join(self.work.name, "good.mp4")
        _make_fake_mp4(path, order=("ftyp", "moov", "mdat"))
        self.assertTrue(_verify_faststart(path))

    def test_faststart_false_when_mdat_precedes_moov(self):
        path = os.path.join(self.work.name, "bad.mp4")
        _make_fake_mp4(path, order=("ftyp", "mdat", "moov"))
        self.assertFalse(_verify_faststart(path))

    SPHERICAL_SIDE_DATA = [
        {"side_data_type": "Spherical Mapping", "projection": "equirectangular", "yaw": 0, "pitch": 0, "roll": 0},
    ]

    def _run_validate_output_with_fixture(self, fixture_name, order, side_data=None):
        fixture = json.loads((FIXTURES / fixture_name).read_text())
        path = os.path.join(self.work.name, "final.mp4")
        _make_fake_mp4(path, order=order)
        # validate_output checks the FINAL h264 proxy, not the source probe
        # fixture's original codec -- patch the video stream to look like
        # our own encoder's output while keeping the fixture's shape/fields.
        fixture["streams"][0] = {
            **fixture["streams"][0],
            "codec_name": "h264",
            "width": 3840,
            "height": 1920,
            "pix_fmt": "yuv420p",
            "side_data_list": self.SPHERICAL_SIDE_DATA if side_data is None else side_data,
        }
        with patch.object(cli_module.subprocess, "run") as mock_run:
            mock_run.return_value = subprocess.CompletedProcess(
                args=["ffprobe"], returncode=0, stdout=json.dumps(fixture), stderr="",
            )
            technical = validate_output(path, 3840, 1920)
        return technical

    def test_validate_output_parses_video_fixture(self):
        technical = self._run_validate_output_with_fixture("ffprobe-video.json", ("ftyp", "moov", "mdat"))
        self.assertEqual(technical["width"], 3840)
        self.assertEqual(technical["height"], 1920)
        self.assertIn("h264", technical["codec_name"])
        self.assertEqual(technical["pix_fmt"], "yuv420p")
        self.assertIs(technical["spherical"], True)
        self.assertIs(technical["faststart"], True)
        self.assertEqual(technical["audio"], "aac")

    def test_validate_output_parses_osv_fixture_as_silent(self):
        fixture = json.loads((FIXTURES / "ffprobe-osv.json").read_text())
        fixture["streams"] = [fixture["streams"][0]]  # OSV fixture is silent (no audio stream)
        path = os.path.join(self.work.name, "final.mp4")
        _make_fake_mp4(path, order=("ftyp", "moov", "mdat"))
        fixture["streams"][0] = {
            **fixture["streams"][0], "codec_name": "h264", "width": 3840, "height": 1920, "pix_fmt": "yuv420p",
            "side_data_list": self.SPHERICAL_SIDE_DATA,
        }
        with patch.object(cli_module.subprocess, "run") as mock_run:
            mock_run.return_value = subprocess.CompletedProcess(
                args=["ffprobe"], returncode=0, stdout=json.dumps(fixture), stderr="",
            )
            technical = validate_output(path, 3840, 1920)
        self.assertEqual(technical["audio"], "none")

    def test_validate_output_rejects_non_faststart(self):
        fixture = json.loads((FIXTURES / "ffprobe-video.json").read_text())
        fixture["streams"][0] = {
            **fixture["streams"][0], "codec_name": "h264", "width": 3840, "height": 1920, "pix_fmt": "yuv420p",
        }
        path = os.path.join(self.work.name, "final.mp4")
        _make_fake_mp4(path, order=("ftyp", "mdat", "moov"))
        with patch.object(cli_module.subprocess, "run") as mock_run:
            mock_run.return_value = subprocess.CompletedProcess(
                args=["ffprobe"], returncode=0, stdout=json.dumps(fixture), stderr="",
            )
            with self.assertRaises(CliError) as ctx:
                validate_output(path, 3840, 1920)
        self.assertEqual(ctx.exception.code, "faststart_verification_failed")

    def test_validate_output_rejects_missing_spherical_side_data(self):
        # C1/C3 regression: a file that passes faststart/codec/dimension
        # checks but was never actually confirmed spherical (e.g. the
        # injector silently no-op'd, or ffprobe's mov demuxer didn't parse
        # the box) must NOT publish spherical:true just because injection
        # didn't raise.
        with self.assertRaises(CliError) as ctx:
            self._run_validate_output_with_fixture(
                "ffprobe-video.json", ("ftyp", "moov", "mdat"), side_data=[],
            )
        self.assertEqual(ctx.exception.code, "spherical_metadata_verification_failed")

    def test_validate_output_reports_real_codec_for_unsafe_audio(self):
        # C2: a codec browsers can't decode (opus/ac3/mp3/...) surviving
        # into the container must be reported by its real name so
        # RenditionPublisher's enum check (aac|copied|none) rejects it --
        # collapsing it to "copied" would defeat that check entirely.
        fixture = json.loads((FIXTURES / "ffprobe-video.json").read_text())
        fixture["streams"][1] = {**fixture["streams"][1], "codec_name": "opus"}
        path = os.path.join(self.work.name, "final.mp4")
        _make_fake_mp4(path, order=("ftyp", "moov", "mdat"))
        fixture["streams"][0] = {
            **fixture["streams"][0], "codec_name": "h264", "width": 3840, "height": 1920, "pix_fmt": "yuv420p",
            "side_data_list": self.SPHERICAL_SIDE_DATA,
        }
        with patch.object(cli_module.subprocess, "run") as mock_run:
            mock_run.return_value = subprocess.CompletedProcess(
                args=["ffprobe"], returncode=0, stdout=json.dumps(fixture), stderr="",
            )
            technical = validate_output(path, 3840, 1920)
        self.assertEqual(technical["audio"], "opus")
        self.assertNotIn(technical["audio"], ("aac", "copied", "none"))


class MockedPipelineTests(unittest.TestCase):
    """Exercise main()'s full orchestration (event ordering, cleanup,
    cancellation) with the ffmpeg/ffprobe boundary functions faked, so no
    real binaries or media are required."""

    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        cli_module._CANCELLED = False
        cli_module._ACTIVE_PROC = None

    def _capture(self, argv):
        buf = io.StringIO()
        with patch("sys.stdout", buf):
            code = cli_module.main(argv)
        lines = [json.loads(line) for line in buf.getvalue().splitlines() if line.strip()]
        return code, lines

    def _make_video_source(self):
        source = os.path.join(self.work.name, "source.mp4")
        with open(source, "wb") as fh:
            fh.write(b"stand-in source bytes")
        return source

    def test_every_line_is_valid_jsonl_with_required_fields(self):
        source = self._make_video_source()
        request = make_request(source, self.work.name)

        def fake_run_ffmpeg(command, duration_s, on_progress):
            on_progress(10)
            on_progress(50)
            with open(request["source_path"].replace("source.mp4", "transcoded.mp4"), "wb") as fh:
                fh.write(b"x")
            return 0

        with patch.object(cli_module, "pf_probe", return_value=OsvInfo(
            path=source, duration_s=12.5, fps=30.0, width=3840, height=1920,
            creation_time_utc=None, size_bytes=10, audio=True,
        )), patch.object(cli_module, "run_ffmpeg", side_effect=fake_run_ffmpeg), \
                patch.object(cli_module, "inject_spherical_metadata", side_effect=lambda src, dst: pathlib.Path(dst).write_bytes(b"final")), \
                patch.object(cli_module, "_remux_moov_to_front", return_value=None), \
                patch.object(cli_module, "validate_output", return_value={
                    "width": 3840, "height": 1920, "codec_name": "h264", "pix_fmt": "yuv420p",
                    "spherical": True, "faststart": True, "audio": "aac", "duration_s": 12.5,
                }):
            code, lines = self._capture(["--request", json.dumps(request)])

        self.assertEqual(code, 0)
        self.assertGreater(len(lines), 0)
        for line in lines:
            for field in ("ts", "run_id", "level", "event", "loc", "ctx"):
                self.assertIn(field, line, msg=line)
            self.assertIsInstance(line["ctx"], dict)
        run_ids = {line["run_id"] for line in lines}
        self.assertEqual(len(run_ids), 1)

    def test_result_never_precedes_spherical_metadata_and_progress_100(self):
        source = self._make_video_source()
        request = make_request(source, self.work.name)

        def fake_run_ffmpeg(command, duration_s, on_progress):
            on_progress(10)
            on_progress(90)
            with open(os.path.join(self.work.name, "transcoded.mp4"), "wb") as fh:
                fh.write(b"x")
            return 0

        with patch.object(cli_module, "pf_probe", return_value=OsvInfo(
            path=source, duration_s=12.5, fps=30.0, width=3840, height=1920,
            creation_time_utc=None, size_bytes=10, audio=True,
        )), patch.object(cli_module, "run_ffmpeg", side_effect=fake_run_ffmpeg), \
                patch.object(cli_module, "inject_spherical_metadata", side_effect=lambda src, dst: pathlib.Path(dst).write_bytes(b"final")), \
                patch.object(cli_module, "_remux_moov_to_front", return_value=None), \
                patch.object(cli_module, "validate_output", return_value={
                    "width": 3840, "height": 1920, "codec_name": "h264", "pix_fmt": "yuv420p",
                    "spherical": True, "faststart": True, "audio": "aac", "duration_s": 12.5,
                }):
            code, lines = self._capture(["--request", json.dumps(request)])

        self.assertEqual(code, 0)
        events = [line["event"] for line in lines]
        self.assertIn("result", events)
        self.assertIn("spherical_metadata", events)
        result_index = events.index("result")
        spherical_index = events.index("spherical_metadata")
        self.assertLess(spherical_index, result_index)
        # A terminal progress:100 line must precede the result event.
        progress_100_indices = [
            i for i, line in enumerate(lines)
            if line["event"] == "progress" and line.get("percent") == 100
        ]
        self.assertTrue(progress_100_indices, "expected a progress:100 event before result")
        self.assertLess(progress_100_indices[0], result_index)
        # The intermediate transcode is gone; the final output is present.
        # The staged source is NOT this CLI's to delete -- narrowing the
        # cleanup to CLI-created paths only means it must still be there.
        remaining = set(os.listdir(self.work.name))
        self.assertEqual(remaining, {"preview.mp4", "source.mp4"})

    def test_progress_percent_is_monotonic_non_decreasing(self):
        source = self._make_video_source()
        request = make_request(source, self.work.name)

        def fake_run_ffmpeg(command, duration_s, on_progress):
            for p in (5, 40, 30, 90):  # deliberately includes a regression
                on_progress(p)
            with open(os.path.join(self.work.name, "transcoded.mp4"), "wb") as fh:
                fh.write(b"x")
            return 0

        with patch.object(cli_module, "pf_probe", return_value=OsvInfo(
            path=source, duration_s=12.5, fps=30.0, width=3840, height=1920,
            creation_time_utc=None, size_bytes=10, audio=True,
        )), patch.object(cli_module, "run_ffmpeg", side_effect=fake_run_ffmpeg), \
                patch.object(cli_module, "inject_spherical_metadata", side_effect=lambda src, dst: pathlib.Path(dst).write_bytes(b"final")), \
                patch.object(cli_module, "_remux_moov_to_front", return_value=None), \
                patch.object(cli_module, "validate_output", return_value={
                    "width": 3840, "height": 1920, "codec_name": "h264", "pix_fmt": "yuv420p",
                    "spherical": True, "faststart": True, "audio": "aac", "duration_s": 12.5,
                }):
            code, lines = self._capture(["--request", json.dumps(request)])

        self.assertEqual(code, 0)
        percents = [line["percent"] for line in lines if line["event"] == "progress"]
        self.assertEqual(percents, sorted(percents))

    def test_osv_without_calibration_emits_calibration_missing(self):
        source = os.path.join(self.work.name, "source.osv")
        with open(source, "wb") as fh:
            fh.write(b"osv bytes")
        request = make_request(source, self.work.name)

        with patch.object(cli_module, "pf_probe", return_value=OsvInfo(
            path=source, duration_s=8.0, fps=25.0, width=3840, height=3840,
            creation_time_utc=None, size_bytes=10, audio=False,
        )), patch.object(cli_module, "pf_extract_metadata", return_value={
            "calibration": None, "imu_perframe": "x.csv", "imu_highrate": "y.csv",
        }), patch.object(cli_module, "run_ffmpeg") as mock_ffmpeg:
            code, lines = self._capture(["--request", json.dumps(request)])

        self.assertEqual(code, 1)
        mock_ffmpeg.assert_not_called()
        error_lines = [line for line in lines if line["event"] == "error"]
        self.assertEqual(len(error_lines), 1)
        self.assertEqual(error_lines[0]["code"], "calibration_missing")
        self.assertNotIn("result", [line["event"] for line in lines])

    def test_osv_early_failure_after_extract_metadata_leaves_no_calibration_artifacts(self):
        """MINOR fix round 2: `to_cleanup.extend(created_artifacts)` used to
        run only after every OSV-mode artifact for this run had already
        been produced, so a raise inside `generate_remap_maps` (this test),
        the `mapset.calibrated` check, or `_check_cancelled` skipped that
        `.extend()` entirely and left `calibration.json`/IMU csvs on disk
        even though `main()` reported failure. Artifacts must now be
        registered for cleanup THE MOMENT they are created, so an early
        failure still cleans up what was already produced before it."""
        source = os.path.join(self.work.name, "source.osv")
        with open(source, "wb") as fh:
            fh.write(b"osv bytes")
        request = make_request(source, self.work.name)

        calibration_path = os.path.join(self.work.name, "calibration.json")
        imu_perframe_path = os.path.join(self.work.name, "imu_perframe.csv")
        imu_highrate_path = os.path.join(self.work.name, "imu_highrate.csv")

        def fake_extract_metadata(source_path, output_dir):
            # Simulate the real `extract_metadata()`'s side effect of
            # writing calibration.json and IMU csvs into output_dir before
            # returning -- these are the artifacts this test proves get
            # cleaned up after a LATER stage (generate_remap_maps) raises.
            with open(calibration_path, "w") as fh:
                json.dump({"lenses": [{"stub": True}]}, fh)
            with open(imu_perframe_path, "w") as fh:
                fh.write("t,gx,gy,gz\n")
            with open(imu_highrate_path, "w") as fh:
                fh.write("t,gx,gy,gz\n")
            return {
                "calibration": {"lenses": [{"stub": True}]},
                "imu_perframe": imu_perframe_path,
                "imu_highrate": imu_highrate_path,
            }

        with patch.object(cli_module, "pf_probe", return_value=OsvInfo(
            path=source, duration_s=8.0, fps=25.0, width=3840, height=3840,
            creation_time_utc=None, size_bytes=10, audio=False,
        )), patch.object(cli_module, "pf_extract_metadata", side_effect=fake_extract_metadata), \
                patch.object(cli_module, "generate_remap_maps", side_effect=RuntimeError("boom")), \
                patch.object(cli_module, "run_ffmpeg") as mock_ffmpeg:
            code, lines = self._capture(["--request", json.dumps(request)])

        self.assertEqual(code, 1)
        mock_ffmpeg.assert_not_called()
        error_lines = [line for line in lines if line["event"] == "error"]
        self.assertEqual(len(error_lines), 1)
        self.assertEqual(error_lines[0]["code"], "unexpected_error")
        self.assertNotIn("result", [line["event"] for line in lines])

        # The whole point: calibration.json and both IMU csvs, all created
        # before generate_remap_maps() raised, must not survive the failed
        # run. Only the staged source (not this CLI's to remove) remains.
        self.assertFalse(os.path.exists(calibration_path))
        self.assertFalse(os.path.exists(imu_perframe_path))
        self.assertFalse(os.path.exists(imu_highrate_path))
        self.assertEqual(set(os.listdir(self.work.name)), {"source.osv"})

    def test_ffmpeg_crash_emits_error_and_nonzero_exit(self):
        source = self._make_video_source()
        request = make_request(source, self.work.name)

        with patch.object(cli_module, "pf_probe", return_value=OsvInfo(
            path=source, duration_s=12.5, fps=30.0, width=3840, height=1920,
            creation_time_utc=None, size_bytes=10, audio=True,
        )), patch.object(cli_module, "run_ffmpeg", return_value=1):
            code, lines = self._capture(["--request", json.dumps(request)])

        self.assertEqual(code, 1)
        error_lines = [line for line in lines if line["event"] == "error"]
        self.assertEqual(len(error_lines), 1)
        self.assertEqual(error_lines[0]["code"], "ffmpeg_crashed")

    def test_osv_happy_path_drives_build_osv_command_with_real_maps(self):
        """C4: no previous test drove main() through a successful OSV run,
        so build_osv_command()'s index arithmetic against Task 5's
        build_command() output (`command.index("-c:a")`, `command[-1:-1]`)
        was pinned only by BriefRequiredTests's hand-built MapSet fixture,
        never by a real one, and the fake ffmpeg never looked at argv at
        all -- a wrong argument order or a missing filter would have
        passed every existing test. This drives the real OSV pipeline
        (real generate_remap_maps() + real stitch.build_command() against
        the real pinned calibration fixture) through main() and asserts
        concrete properties of the actual argv the ffmpeg-runner receives."""
        source = os.path.join(self.work.name, "source.osv")
        with open(source, "wb") as fh:
            fh.write(b"osv stand-in bytes")
        request = make_request(source, self.work.name)
        calibration = json.loads((FIXTURES / "calibration.json").read_text())
        intermediate_path = os.path.join(self.work.name, "stitched.mp4")

        captured_commands = []

        def fake_run_ffmpeg(command, duration_s, on_progress):
            captured_commands.append(command)
            on_progress(20)
            on_progress(80)
            pathlib.Path(intermediate_path).write_bytes(b"x")
            return 0

        with patch.object(cli_module, "pf_probe", return_value=OsvInfo(
            path=source, duration_s=8.0, fps=25.0, width=3840, height=3840,
            creation_time_utc=None, size_bytes=10, audio=False,
        )), patch.object(cli_module, "pf_extract_metadata", return_value={
            "calibration": calibration,
            "imu_perframe": os.path.join(self.work.name, "imu_perframe.csv"),
            "imu_highrate": os.path.join(self.work.name, "imu_highrate.csv"),
        }), patch.object(cli_module, "run_ffmpeg", side_effect=fake_run_ffmpeg), \
                patch.object(cli_module, "inject_spherical_metadata", side_effect=lambda src, dst: pathlib.Path(dst).write_bytes(b"final")), \
                patch.object(cli_module, "_remux_moov_to_front", return_value=None), \
                patch.object(cli_module, "validate_output", return_value={
                    "width": 3840, "height": 1920, "codec_name": "h264", "pix_fmt": "yuv420p",
                    "spherical": True, "faststart": True, "audio": "none", "duration_s": 8.0,
                }):
            code, lines = self._capture(["--request", json.dumps(request)])

        self.assertEqual(code, 0, msg=lines)
        self.assertIn("result", [line["event"] for line in lines])
        self.assertEqual(len(captured_commands), 1)
        command = captured_commands[0]

        self.assertEqual(command[0], "ffmpeg")
        self.assertEqual(command[-1], intermediate_path)
        self.assertIn("libx264", command)
        self.assertIn("-filter_complex", command)
        graph = command[command.index("-filter_complex") + 1]
        self.assertEqual(graph.count("remap"), 2)
        self.assertIn("maskedmerge", graph)

        audio_index = command.index("-c:a")
        self.assertEqual(command[audio_index + 1], "aac")
        self.assertEqual(command[audio_index + 2], "-b:a")
        self.assertEqual(command[audio_index + 3], "192k")

        self.assertIn("-movflags", command)
        self.assertEqual(command[command.index("-movflags") + 1], "+faststart")
        self.assertIn("-progress", command)
        self.assertEqual(command[command.index("-progress") + 1], "pipe:1")
        self.assertIn("-f", command)
        self.assertEqual(command[command.index("-f") + 1], "mp4")

        # The stitched intermediate, the extracted calibration/IMU
        # artifacts, and the remap maps must all be gone; the staged
        # source is NOT this CLI's to remove.
        remaining = set(os.listdir(self.work.name))
        self.assertEqual(remaining, {"preview.mp4", "source.osv"})


def _write_fake_bin(path, content):
    path.write_text(content)
    path.chmod(0o755)


class RealSubprocessCancellationAndFlushTests(unittest.TestCase):
    """End-to-end tests that launch the actual CLI as a real subprocess
    behind fake `ffmpeg`/`ffprobe` executables on PATH -- no real media, no
    real ffmpeg/ffprobe binaries required, but real pipes/signals so
    flush-per-line and process-group termination are proven, not assumed."""

    @classmethod
    def setUpClass(cls):
        BIN_DIR.mkdir(exist_ok=True)
        _write_fake_bin(BIN_DIR / "ffprobe", (
            "#!/usr/bin/env python3\n"
            "import json, os, sys\n"
            "print(open(os.environ['FFPROBE_FIXTURE']).read())\n"
        ))
        _write_fake_bin(BIN_DIR / "ffmpeg", (
            "#!/usr/bin/env python3\n"
            "import os, sys, time\n"
            "pidfile = os.environ.get('FFMPEG_PIDFILE')\n"
            "if pidfile:\n"
            "    with open(pidfile, 'w') as fh:\n"
            "        fh.write(str(os.getpid()))\n"
            "iterations = int(os.environ.get('FFMPEG_ITERATIONS', '5'))\n"
            "sleep_s = float(os.environ.get('FFMPEG_SLEEP', '0.3'))\n"
            "out_path = sys.argv[-1]\n"
            "for i in range(1, iterations + 1):\n"
            "    print(f'out_time_ms={i * 2000000}')\n"
            "    print('progress=continue')\n"
            "    sys.stdout.flush()\n"
            "    time.sleep(sleep_s)\n"
            "with open(out_path, 'wb') as fh:\n"
            "    fh.write(b'not a real mp4')\n"
            "print('progress=end')\n"
        ))

    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        self.source = os.path.join(self.work.name, "source.mp4")
        with open(self.source, "wb") as fh:
            fh.write(b"stand-in")
        self.env = dict(os.environ)
        self.env["PATH"] = f"{BIN_DIR}{os.pathsep}{self.env.get('PATH', '')}"
        self.env["FFPROBE_FIXTURE"] = str(FIXTURES / "ffprobe-video.json")
        self.pidfile = os.path.join(self.work.name, "ffmpeg.pid")
        self.env["FFMPEG_PIDFILE"] = self.pidfile

    def _launch(self, iterations, sleep_s):
        env = dict(self.env)
        env["FFMPEG_ITERATIONS"] = str(iterations)
        env["FFMPEG_SLEEP"] = str(sleep_s)
        request = make_request(self.source, self.work.name)
        script = str(ROOT / "tools/media/mothership_360.py")
        return subprocess.Popen(
            [sys.executable, script, "--request", json.dumps(request)],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=env,
        )

    def test_progress_lines_arrive_incrementally_not_in_one_final_burst(self):
        proc = self._launch(iterations=4, sleep_s=0.4)
        arrival_times = []
        try:
            deadline = time.time() + 15
            while time.time() < deadline:
                line = proc.stdout.readline()
                if not line:
                    break
                event = json.loads(line)
                if event["event"] == "progress":
                    arrival_times.append(time.time())
                if len(arrival_times) >= 3:
                    break
        finally:
            proc.wait(timeout=10)

        self.assertGreaterEqual(len(arrival_times), 3, "expected at least 3 incremental progress lines")
        gaps = [b - a for a, b in zip(arrival_times, arrival_times[1:])]
        # If stdout were block-buffered, every line would surface back-to-back
        # at process exit (gaps ~0s). A real per-line flush means each gap is
        # close to the fake ffmpeg's own sleep interval.
        for gap in gaps:
            self.assertGreater(gap, 0.15, f"progress lines arrived without an incremental gap: {gaps}")

    def test_sigterm_kills_the_ffmpeg_child_process_group(self):
        proc = self._launch(iterations=50, sleep_s=0.2)
        try:
            deadline = time.time() + 10
            saw_progress = False
            while time.time() < deadline:
                line = proc.stdout.readline()
                if not line:
                    break
                if json.loads(line)["event"] == "progress":
                    saw_progress = True
                    break
            self.assertTrue(saw_progress, "CLI never reported ffmpeg progress before timeout")

            deadline = time.time() + 5
            while not os.path.exists(self.pidfile) and time.time() < deadline:
                time.sleep(0.05)
            self.assertTrue(os.path.exists(self.pidfile), "fake ffmpeg never wrote its pidfile")
            ffmpeg_pid = int(pathlib.Path(self.pidfile).read_text().strip())

            proc.send_signal(signal.SIGTERM)

            remaining = proc.stdout.read()
            exit_code = proc.wait(timeout=10)
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait(timeout=5)

        self.assertEqual(exit_code, 130)
        self.assertIn('"event":"cancelled"', remaining)

        deadline = time.time() + 5
        ffmpeg_alive = True
        while time.time() < deadline:
            try:
                os.kill(ffmpeg_pid, 0)
            except ProcessLookupError:
                ffmpeg_alive = False
                break
            time.sleep(0.1)
        self.assertFalse(ffmpeg_alive, "fake ffmpeg child was not terminated by the CLI's SIGTERM handler")


class RealInjectorRemuxAndVerifierIntegrationTest(unittest.TestCase):
    """C1: composes the REAL vendored `inject_spherical()`, this CLI's REAL
    `_remux_moov_to_front()`, and this CLI's REAL `validate_output()`
    against a genuine ffmpeg-produced MP4 -- no mock touches any of the
    three at this seam. This is exactly the composition every other test
    in this file mocks around (MockedPipelineTests patches out both
    `inject_spherical_metadata` and `validate_output`;
    FaststartAndOutputValidationTests exercises `_verify_faststart` against
    hand-built synthetic boxes, never the real injector's output) -- and
    exactly the composition that was broken: the real injector always
    leaves `moov` last, so the real verifier's faststart check always
    failed, deterministically, before this task's C1 fix.

    Requires real `ffmpeg`/`ffprobe` on PATH; skips explicitly (never
    mocks the seam back out) if they are unavailable."""

    @classmethod
    def setUpClass(cls):
        if shutil.which("ffmpeg") is None or shutil.which("ffprobe") is None:
            raise unittest.SkipTest("real ffmpeg/ffprobe not available in this environment")

    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)

    def test_real_injection_then_remux_then_validate_round_trips(self):
        source = os.path.join(self.work.name, "plain.mp4")
        subprocess.run(
            [
                "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
                "-f", "lavfi", "-i", "testsrc=size=3840x1920:duration=1:rate=5",
                "-c:v", "libx264", "-pix_fmt", "yuv420p", "-movflags", "+faststart",
                source,
            ],
            check=True, timeout=60,
        )

        final_output = os.path.join(self.work.name, "preview.mp4")
        cli_module.inject_spherical_metadata(source, final_output)

        # Reproduce the reviewer's exact isolated proof of the C1 mechanism:
        # right after real injection, moov is last and faststart is false.
        boxes_before = [b[0] for b in cli_module._iter_top_level_boxes(final_output)]
        self.assertEqual(boxes_before[-1], b"moov", f"expected moov last, got {boxes_before}")
        self.assertFalse(cli_module._verify_faststart(final_output))

        cli_module._remux_moov_to_front(final_output)

        boxes_after = [b[0] for b in cli_module._iter_top_level_boxes(final_output)]
        self.assertNotEqual(boxes_after[-1], b"moov")
        self.assertTrue(cli_module._verify_faststart(final_output))

        technical = cli_module.validate_output(final_output, 3840, 1920)
        self.assertTrue(technical["faststart"])
        self.assertTrue(technical["spherical"])
        self.assertEqual(technical["width"], 3840)
        self.assertEqual(technical["height"], 1920)
        self.assertIn("h264", technical["codec_name"])
        self.assertEqual(technical["audio"], "none")

        # The remux must not have corrupted any sample data: the file must
        # still actually decode every frame it had before the remux.
        probe = subprocess.run(
            ["ffprobe", "-v", "error", "-count_frames", "-select_streams", "v:0",
             "-show_entries", "stream=nb_read_frames", "-of", "csv=p=0", final_output],
            capture_output=True, text=True, timeout=30,
        )
        self.assertEqual(probe.returncode, 0)
        self.assertEqual(int(probe.stdout.strip().rstrip(",")), 5)


class MoovBeforeMdatGuardIntegrationTest(unittest.TestCase):
    """Fix round 2, finding (a): `_remux_moov_to_front()`'s offset-patching
    is only correct when `moov` currently sits AFTER `mdat` (the only
    layout the real `inject_spherical()` ever produces). Its docstring
    otherwise advertises general handling, and unguarded it corrupts a
    `ftyp|free|moov|mdat` file -- moov already BEFORE mdat, but not
    directly after `ftyp` -- by adding `moov_size` to every stco/co64
    sample offset >= the ftyp-adjacent insertion point, even though
    those offsets already point at the correct (unmoved) mdat position.
    `_verify_faststart()` still reports True (moov genuinely precedes
    mdat in box order) but the file no longer decodes.

    This layout is unreachable through the CLI's own pipeline today (the
    vendored injector always leaves moov last), so this test builds the
    fixture directly with real ffmpeg + a hand patch, then proves
    `_remux_moov_to_front()` leaves it decodable via real ffmpeg/ffprobe.

    Requires real `ffmpeg`/`ffprobe` on PATH; skips explicitly (never
    mocks the seam back out) if they are unavailable."""

    @classmethod
    def setUpClass(cls):
        if shutil.which("ffmpeg") is None or shutil.which("ffprobe") is None:
            raise unittest.SkipTest("real ffmpeg/ffprobe not available in this environment")

    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)

    def _build_ftyp_free_moov_mdat_fixture(self) -> str:
        """Produces a real ffmpeg faststart clip (ftyp|moov|free|mdat on
        this ffmpeg build), then hand-inserts an extra empty `free` box
        between `ftyp` and `moov` by rewriting the raw bytes -- yielding
        `ftyp|free|moov|free|mdat`: moov still precedes mdat (already
        faststart), but is no longer directly adjacent to ftyp, which is
        exactly the layout the unguarded function mishandles.

        Inserting bytes before mdat shifts mdat's absolute file offset,
        so the moved-in moov copy has its own internal stco/co64 sample
        offsets patched (+len(free_box), unconditionally, via the same
        `_patch_sample_offsets_forward` helper the module itself uses) to
        keep them pointing at the real (now-shifted) sample data. Without
        this the fixture itself would be corrupt before the function
        under test ever touches it."""
        source = os.path.join(self.work.name, "plain.mp4")
        subprocess.run(
            [
                "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
                "-f", "lavfi", "-i", "testsrc=size=320x160:duration=1:rate=10",
                "-c:v", "libx264", "-pix_fmt", "yuv420p", "-movflags", "+faststart",
                source,
            ],
            check=True, timeout=60,
        )

        with open(source, "rb") as fh:
            data = fh.read()
        boxes = list(cli_module._iter_boxes(data, 0, len(data)))
        ftyp = next(b for b in boxes if b[0] == b"ftyp")
        moov = next(b for b in boxes if b[0] == b"moov")
        insert_at = ftyp[1] + ftyp[2]
        _, moov_off, moov_size, moov_hdr = moov
        self.assertEqual(moov_off, insert_at, "sanity: this ffmpeg build puts moov directly after ftyp")

        free_box = (8).to_bytes(4, "big") + b"free"  # empty free box, no body
        moov_bytes = bytearray(data[moov_off:moov_off + moov_size])
        cli_module._patch_sample_offsets_forward(moov_bytes, moov_hdr, moov_size, 0, len(free_box))

        new_data = data[:insert_at] + free_box + bytes(moov_bytes) + data[moov_off + moov_size:]
        fixture_path = os.path.join(self.work.name, "fixture.mp4")
        with open(fixture_path, "wb") as fh:
            fh.write(new_data)
        return fixture_path

    def test_remux_is_a_noop_on_moov_before_mdat_layout_and_stays_decodable(self):
        fixture_path = self._build_ftyp_free_moov_mdat_fixture()

        boxes_before = [b[0] for b in cli_module._iter_top_level_boxes(fixture_path)]
        self.assertEqual(
            boxes_before, [b"ftyp", b"free", b"moov", b"free", b"mdat"],
            f"fixture construction sanity check failed: {boxes_before}",
        )
        self.assertTrue(cli_module._verify_faststart(fixture_path), "fixture must already be faststart")

        # Baseline: the hand-built fixture itself must be genuinely
        # decodable BEFORE the function under test ever touches it --
        # otherwise a failure below wouldn't prove anything about
        # `_remux_moov_to_front()`.
        baseline = subprocess.run(
            ["ffmpeg", "-v", "error", "-i", fixture_path, "-f", "null", "-"],
            capture_output=True, text=True, timeout=30,
        )
        self.assertEqual(baseline.returncode, 0, msg=baseline.stderr)

        cli_module._remux_moov_to_front(fixture_path)

        boxes_after = [b[0] for b in cli_module._iter_top_level_boxes(fixture_path)]
        self.assertEqual(
            boxes_after, boxes_before,
            "moov was already before mdat -- _remux_moov_to_front must not rewrite the file at all",
        )
        self.assertTrue(cli_module._verify_faststart(fixture_path))

        # The real proof: the file must still actually decode, not merely
        # report faststart=True. This is the exact failure mode the
        # reviewer found -- _verify_faststart reporting True on a file
        # with corrupted stco/co64 sample offsets, undecodable with
        # "Invalid NAL unit size".
        decode = subprocess.run(
            ["ffmpeg", "-v", "error", "-i", fixture_path, "-f", "null", "-"],
            capture_output=True, text=True, timeout=30,
        )
        self.assertEqual(decode.returncode, 0, msg=decode.stderr)
        self.assertEqual(decode.stderr, "", "expected no decode errors/warnings on stderr")

        probe = subprocess.run(
            ["ffprobe", "-v", "error", "-count_frames", "-select_streams", "v:0",
             "-show_entries", "stream=nb_read_frames", "-of", "csv=p=0", fixture_path],
            capture_output=True, text=True, timeout=30,
        )
        self.assertEqual(probe.returncode, 0)
        self.assertEqual(int(probe.stdout.strip().rstrip(",")), 10)


if __name__ == "__main__":
    unittest.main()

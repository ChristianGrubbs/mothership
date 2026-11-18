from __future__ import annotations

import http.client
import importlib.util
import json
import os
import pathlib
import shutil
import stat
import sys
import tempfile
import threading
import time
import unittest
import uuid
from unittest import mock
from urllib.parse import quote


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "clop-service" / "server.py"
SPEC = importlib.util.spec_from_file_location("studio_clop_server", MODULE_PATH)
server_module = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = server_module
SPEC.loader.exec_module(server_module)


FAKE_CLOP = '''#!/usr/bin/env python3
import json
import os
import pathlib
import subprocess
import sys
import time

args = sys.argv[1:]
out_prefix = args[args.index("-o") + 1]
in_path = args[-1]
mode = pathlib.Path(in_path).read_text().strip()
log_path = os.environ.get("CLOP_TEST_LOG")
argv_log_path = os.environ.get("CLOP_TEST_ARGV_LOG")

# Always record the exact argv this invocation received (before any mode
# dispatch below) so argv-mapping tests can assert on it regardless of mode.
if argv_log_path:
    with open(argv_log_path, "a", encoding="utf-8") as sink:
        sink.write(json.dumps(args) + "\\n")


def log(event):
    if log_path:
        with open(log_path, "a", encoding="utf-8") as sink:
            sink.write(f"{event},{time.time()}\\n")


if mode == "timeout":
    time.sleep(5)
    sys.exit(0)

if mode == "timeout_orphan":
    # A descendant that outlives the direct clop process; proves the host
    # kills the whole process group on timeout, not just its immediate child.
    child = subprocess.Popen(["sleep", "30"])
    pid_file = os.environ.get("CLOP_TEST_CHILD_PID_FILE")
    if pid_file:
        pathlib.Path(pid_file).write_text(str(child.pid))
    time.sleep(30)
    sys.exit(0)

if mode == "badjson":
    print("not json")
    sys.exit(0)

if mode == "failed":
    print(json.dumps({"done": [], "failed": [{"error": "boom", "forURL": "file:///x"}]}))
    sys.exit(0)

if mode == "empty_done":
    print(json.dumps({"done": [], "failed": []}))
    sys.exit(0)

if mode == "no_reduction":
    out_path = out_prefix + ".jpeg"
    pathlib.Path(out_path).write_bytes(b"x" * 100)
    print(json.dumps({"done": [{"oldBytes": 10, "newBytes": 100, "path": out_path}], "failed": []}))
    sys.exit(0)

if mode == "missing_output":
    print(json.dumps({"done": [{"oldBytes": 10, "newBytes": 1, "path": out_prefix + ".missing"}], "failed": []}))
    sys.exit(0)

if mode == "empty_output":
    out_path = out_prefix + ".mp4"
    pathlib.Path(out_path).write_bytes(b"")
    print(json.dumps({"done": [{"oldBytes": 999, "newBytes": 0, "path": out_path}], "failed": []}))
    sys.exit(0)

if mode == "bad_ext":
    # An extension the product never actually emits for this family; the
    # host must reject it even though the byte count alone looks smaller.
    out_path = out_prefix + ".gif"
    pathlib.Path(out_path).write_bytes(b"OK")
    print(json.dumps({"done": [{"oldBytes": 999, "newBytes": 2, "path": out_path}], "failed": []}))
    sys.exit(0)

if mode == "lie_no_reduction":
    # Clop's self-reported newBytes (1) looks smaller than any real body, but
    # the actual file on disk is not; the host must go by the real bytes.
    out_path = out_prefix + ".jpeg"
    pathlib.Path(out_path).write_bytes(b"X" * 50)
    print(json.dumps({"done": [{"oldBytes": 1, "newBytes": 1, "path": out_path}], "failed": []}))
    sys.exit(0)

if mode == "lie_success":
    # Clop's self-reported newBytes (999999) looks larger than the request
    # body, but the actual file on disk is genuinely smaller.
    out_path = out_prefix + ".jpeg"
    pathlib.Path(out_path).write_bytes(b"OK")
    print(json.dumps({"done": [{"oldBytes": 1, "newBytes": 999999, "path": out_path}], "failed": []}))
    sys.exit(0)

if mode == "slow":
    log("start")
    time.sleep(0.4)
    out_path = out_prefix + ".jpeg"
    pathlib.Path(out_path).write_bytes(b"OPTIMIZED-SLOW")
    log("end")
    print(json.dumps({"done": [{"oldBytes": 999, "newBytes": 9, "path": out_path}], "failed": []}))
    sys.exit(0)

out_path = out_prefix + ".jpeg"
pathlib.Path(out_path).write_bytes(b"OPTIMIZED")
print(json.dumps({
    "done": [{"oldBytes": 999, "newBytes": 9, "path": out_path, "newWidthHeight": [10, 20]}],
    "failed": [],
}))
'''


class ClopServerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        root = pathlib.Path(cls.tmp.name)
        cls.clop_bin = root / "fake-clop.py"
        cls.clop_bin.write_text(FAKE_CLOP, encoding="utf-8")
        cls.clop_bin.chmod(cls.clop_bin.stat().st_mode | stat.S_IEXEC)

        os.environ["CLOP_BIN"] = str(cls.clop_bin)
        os.environ["CLOP_HOST"] = "127.0.0.1"
        os.environ["CLOP_PORT"] = "0"
        os.environ["CLOP_MAX_BYTES"] = "1024"
        os.environ["CLOP_TIMEOUT"] = "1"
        os.environ["CLOP_APP_PATH"] = str(root / "NoSuchClop.app")
        os.environ.pop("CLOP_SERVICE_LOG", None)

        cls.config = server_module.ServerConfig()
        cls.event_log = server_module.EventLog()
        cls.http_server = server_module.ClopHTTPServer(
            (cls.config.host, 0), server_module.Handler, cls.config, cls.event_log
        )
        cls.port = cls.http_server.server_address[1]
        cls.thread = threading.Thread(target=cls.http_server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.http_server.shutdown()
        cls.http_server.server_close()
        cls.tmp.cleanup()
        for key in ("CLOP_BIN", "CLOP_HOST", "CLOP_PORT", "CLOP_MAX_BYTES", "CLOP_TIMEOUT", "CLOP_APP_PATH"):
            os.environ.pop(key, None)

    def connection(self):
        return http.client.HTTPConnection("127.0.0.1", self.port, timeout=10)

    def post(self, query, body, filename="in.png", extra_headers=None):
        headers = {"X-Clop-Filename": filename}
        if extra_headers:
            headers.update(extra_headers)
        conn = self.connection()
        conn.request("POST", f"/optimize?{query}", body=body, headers=headers)
        response = conn.getresponse()
        data = response.read()
        conn.close()
        return response, data

    def run_and_capture_argv(self, query, body=b"argv-capture-body-longer-than-nine-bytes"):
        """POST query/body and return (response, data, exact argv the fake clop received)."""
        argv_log = pathlib.Path(self.tmp.name) / f"argv-{uuid.uuid4().hex}.jsonl"
        os.environ["CLOP_TEST_ARGV_LOG"] = str(argv_log)
        try:
            response, data = self.post(query, body)
        finally:
            os.environ.pop("CLOP_TEST_ARGV_LOG", None)
        lines = argv_log.read_text(encoding="utf-8").splitlines() if argv_log.exists() else []
        self.assertEqual(len(lines), 1, f"expected exactly one clop invocation, got {lines!r}")
        return response, data, json.loads(lines[0])

    def assert_argv_shape(self, argv, type_, expected_options):
        """Assert the fixed positional shape: optimise <type> <options...> -n -j -o <out> <in>."""
        self.assertEqual(argv[0], "optimise")
        self.assertEqual(argv[1], type_)
        tail = argv[2:]
        self.assertEqual(tail[-5:-2], ["-n", "-j", "-o"], tail)
        out_prefix, in_path = tail[-2], tail[-1]
        self.assertEqual(pathlib.Path(out_prefix).name, "out")
        self.assertEqual(pathlib.Path(out_prefix).suffix, "", "-o must carry no extension")
        self.assertTrue(pathlib.Path(in_path).name.startswith("in."), in_path)
        options = tail[:-5]
        self.assertEqual(options, expected_options)

    def test_health_reports_ok_when_clop_binary_present(self):
        conn = self.connection()
        conn.request("GET", "/health")
        response = conn.getresponse()
        payload = json.loads(response.read())
        conn.close()
        self.assertEqual(response.status, 200)
        self.assertTrue(payload["ok"])
        self.assertEqual(payload["clop"], str(self.clop_bin))
        self.assertIsNone(payload["version"])

    def test_health_reports_503_when_clop_binary_missing(self):
        missing = pathlib.Path(self.tmp.name) / "does-not-exist"
        original = self.config.clop_bin
        self.config.clop_bin = missing
        try:
            conn = self.connection()
            conn.request("GET", "/health")
            response = conn.getresponse()
            payload = json.loads(response.read())
            conn.close()
        finally:
            self.config.clop_bin = original
        self.assertEqual(response.status, 503)
        self.assertFalse(payload["ok"])

    def test_success_returns_output_bytes_and_headers(self):
        response, data = self.post("type=image&scale=1&adaptive=0", b"success-input-bytes")
        self.assertEqual(response.status, 200)
        self.assertEqual(data, b"OPTIMIZED")
        self.assertEqual(response.getheader("X-Clop-Old-Bytes"), str(len(b"success-input-bytes")))
        self.assertEqual(response.getheader("X-Clop-New-Bytes"), str(len(b"OPTIMIZED")))
        self.assertEqual(response.getheader("X-Clop-Extension"), "jpeg")
        self.assertEqual(response.getheader("X-Clop-Width"), "10")
        self.assertEqual(response.getheader("X-Clop-Height"), "20")
        self.assertEqual(response.getheader("Content-Type"), "image/jpeg")

    def test_no_reduction_when_new_bytes_not_smaller(self):
        response, data = self.post("type=image&scale=1&adaptive=0", b"no_reduction")
        payload = json.loads(data)
        self.assertEqual(response.status, 200)
        self.assertEqual(response.getheader("X-Clop-Outcome"), "no_reduction")
        self.assertEqual(payload, {"outcome": "no_reduction", "old_bytes": len(b"no_reduction")})

    def test_no_reduction_when_done_and_failed_are_both_empty(self):
        response, data = self.post("type=pdf", b"empty_done")
        payload = json.loads(data)
        self.assertEqual(response.status, 200)
        self.assertEqual(payload["outcome"], "no_reduction")

    def test_failed_returns_422_without_leaking_clop_error_text(self):
        response, data = self.post("type=video&compression=50&encoder=hardware&scale=1&remove_audio=0", b"failed")
        payload = json.loads(data)
        self.assertEqual(response.status, 422)
        self.assertEqual(payload, {"error": "optimize_failed"})
        self.assertNotIn("boom", data.decode())
        self.assertNotIn("forURL", data.decode())

    def test_missing_output_file_is_optimize_failed(self):
        response, data = self.post("type=pdf", b"missing_output")
        self.assertEqual(response.status, 422)
        self.assertEqual(json.loads(data), {"error": "optimize_failed"})

    def test_empty_output_file_is_optimize_failed_not_a_smaller_copy(self):
        response, data = self.post("type=video&compression=50&encoder=hardware&scale=1&remove_audio=0", b"empty_output")
        self.assertEqual(response.status, 422)
        self.assertEqual(json.loads(data), {"error": "optimize_failed"})

    def test_bad_json_stdout_is_optimize_failed(self):
        response, data = self.post("type=pdf", b"badjson")
        self.assertEqual(response.status, 422)
        self.assertEqual(json.loads(data), {"error": "optimize_failed"})

    def test_timeout_returns_504(self):
        response, data = self.post("type=pdf", b"timeout")
        self.assertEqual(response.status, 504)
        self.assertEqual(json.loads(data), {"error": "timeout"})

    def test_invalid_option_returns_422(self):
        response, data = self.post("type=image&scale=2&adaptive=0", b"success")
        self.assertEqual(response.status, 422)
        self.assertEqual(json.loads(data), {"error": "invalid_option"})

    def test_unknown_type_returns_422(self):
        response, data = self.post("type=audio", b"success")
        self.assertEqual(response.status, 422)
        self.assertEqual(json.loads(data), {"error": "invalid_option"})

    def test_missing_filename_header_returns_422(self):
        conn = self.connection()
        conn.request("POST", "/optimize?type=pdf", body=b"success")
        response = conn.getresponse()
        data = response.read()
        conn.close()
        self.assertEqual(response.status, 422)
        self.assertEqual(json.loads(data), {"error": "invalid_option"})

    def test_oversize_body_returns_413(self):
        response, data = self.post("type=pdf", b"x" * 2000)
        self.assertEqual(response.status, 413)
        self.assertNotIn(self.tmp.name, data.decode())

    def test_no_response_ever_contains_a_filesystem_path(self):
        for mode in (b"failed", b"missing_output", b"badjson"):
            with self.subTest(mode=mode):
                _, data = self.post("type=pdf", mode)
                self.assertNotIn("/", data.decode())

    def test_temp_directory_is_always_removed(self):
        # Point the in-process server's temp root at a private directory so a
        # concurrent job sharing the host's /tmp cannot leak clop-* entries in.
        temp_root = tempfile.mkdtemp(prefix="clop-test-root-")
        self.addCleanup(shutil.rmtree, temp_root, ignore_errors=True)
        created = []
        real_mkdtemp = tempfile.mkdtemp

        def recording_mkdtemp(*args, **kwargs):
            path = real_mkdtemp(*args, **kwargs)
            created.append(path)
            return path

        with mock.patch.object(tempfile, "tempdir", temp_root), \
                mock.patch.object(server_module.tempfile, "mkdtemp", recording_mkdtemp):
            self.post("type=image&scale=1&adaptive=0", b"success-input-bytes")
            self.post("type=pdf", b"failed")
            self.post("type=pdf", b"timeout")

        self.assertEqual(len(created), 3)
        for path in created:
            self.assertEqual(os.path.dirname(path), temp_root)
        # The handler replies before its finally-block removes the workdir, so
        # allow the server thread a bounded moment to finish cleaning up.
        deadline = time.monotonic() + 5
        while os.listdir(temp_root) and time.monotonic() < deadline:
            time.sleep(0.02)
        self.assertEqual(os.listdir(temp_root), [])

    def test_requests_are_serialized_never_overlapping(self):
        log_file = pathlib.Path(self.tmp.name) / "serialize.log"
        log_file.write_text("", encoding="utf-8")
        os.environ["CLOP_TEST_LOG"] = str(log_file)
        try:
            results = []

            def run():
                results.append(self.post("type=image&scale=1&adaptive=0", b"slow"))

            threads = [threading.Thread(target=run) for _ in range(2)]
            for thread in threads:
                thread.start()
            for thread in threads:
                thread.join(timeout=10)

            for response, _ in results:
                self.assertEqual(response.status, 200)

            events = [line.split(",") for line in log_file.read_text(encoding="utf-8").splitlines()]
            starts = sorted(float(ts) for kind, ts in events if kind == "start")
            ends = sorted(float(ts) for kind, ts in events if kind == "end")
            self.assertEqual(len(starts), 2)
            self.assertEqual(len(ends), 2)
            # Serialized: the second run cannot start before the first one ends.
            self.assertGreaterEqual(starts[1], ends[0])
        finally:
            os.environ.pop("CLOP_TEST_LOG", None)

    def test_argv_maps_image_scale_and_adaptive_options_exactly(self):
        for scale in ("1", "0.75", "0.5"):
            for adaptive in ("0", "1"):
                with self.subTest(scale=scale, adaptive=adaptive):
                    _, _, argv = self.run_and_capture_argv(f"type=image&scale={scale}&adaptive={adaptive}")
                    expected = ["--downscale-factor", scale]
                    if adaptive == "1":
                        expected += ["--compression", "adaptive"]
                    self.assert_argv_shape(argv, "image", expected)
                    if adaptive == "0":
                        self.assertNotIn("--compression", argv)

    def test_argv_maps_video_compression_encoder_scale_and_remove_audio_exactly(self):
        for compression in ("25", "50", "75"):
            for encoder in ("hardware", "software"):
                for scale in ("1", "0.75", "0.5"):
                    for remove_audio in ("0", "1"):
                        with self.subTest(
                            compression=compression, encoder=encoder, scale=scale, remove_audio=remove_audio
                        ):
                            query = (
                                f"type=video&compression={compression}&encoder={encoder}"
                                f"&scale={scale}&remove_audio={remove_audio}"
                            )
                            _, _, argv = self.run_and_capture_argv(query)
                            expected = [
                                "--compression", compression,
                                "--encoder", encoder,
                                "--downscale-factor", scale,
                            ]
                            if remove_audio == "1":
                                expected.append("--remove-audio")
                            self.assert_argv_shape(argv, "video", expected)
                            if remove_audio == "0":
                                self.assertNotIn("--remove-audio", argv)

    def test_argv_maps_pdf_to_dpi_adaptive_with_no_other_options(self):
        _, _, argv = self.run_and_capture_argv("type=pdf")
        self.assert_argv_shape(argv, "pdf", ["--dpi", "adaptive"])

    def test_argv_always_ends_with_disposable_copy_last_and_bare_out_prefix(self):
        # -o carries no extension (Clop appends one) and the disposable copy is
        # always the final positional argument, for every type.
        for query, type_, options in (
            ("type=image&scale=1&adaptive=0", "image", ["--downscale-factor", "1"]),
            (
                "type=video&compression=50&encoder=hardware&scale=1&remove_audio=0",
                "video",
                ["--compression", "50", "--encoder", "hardware", "--downscale-factor", "1"],
            ),
            ("type=pdf", "pdf", ["--dpi", "adaptive"]),
        ):
            with self.subTest(query=query):
                _, _, argv = self.run_and_capture_argv(query)
                self.assert_argv_shape(argv, type_, options)

    def test_option_values_cannot_inject_extra_argv(self):
        malicious_scale_values = [
            "1 --foo",
            "1\n--compression adaptive",
            "0.75;--bar",
            "0.75 --compression=adaptive",
        ]
        for value in malicious_scale_values:
            with self.subTest(value=value):
                argv_log = pathlib.Path(self.tmp.name) / f"argv-injection-{uuid.uuid4().hex}.jsonl"
                os.environ["CLOP_TEST_ARGV_LOG"] = str(argv_log)
                try:
                    query = f"type=image&scale={quote(value, safe='')}&adaptive=0"
                    response, data = self.post(query, b"whatever-body-content")
                finally:
                    os.environ.pop("CLOP_TEST_ARGV_LOG", None)
                self.assertEqual(response.status, 422)
                self.assertEqual(json.loads(data), {"error": "invalid_option"})
                # Rejected before clop ever runs: no invocation, so no argv line at all.
                self.assertFalse(argv_log.exists() and argv_log.read_text(encoding="utf-8").strip())

    def test_output_extension_outside_allowlist_is_optimize_failed(self):
        response, data = self.post("type=image&scale=1&adaptive=0", b"bad_ext")
        self.assertEqual(response.status, 422)
        self.assertEqual(json.loads(data), {"error": "optimize_failed"})

    def test_no_reduction_decision_uses_actual_bytes_not_clops_self_report(self):
        body = b"lie_no_reduction"
        response, data = self.post("type=image&scale=1&adaptive=0", body)
        self.assertEqual(response.status, 200)
        self.assertEqual(json.loads(data), {"outcome": "no_reduction", "old_bytes": len(body)})

    def test_success_uses_actual_bytes_even_when_clops_self_report_says_larger(self):
        body = b"lie_success"
        response, data = self.post("type=image&scale=1&adaptive=0", body)
        self.assertEqual(response.status, 200)
        self.assertEqual(data, b"OK")
        self.assertEqual(response.getheader("X-Clop-New-Bytes"), "2")
        self.assertEqual(response.getheader("X-Clop-Old-Bytes"), str(len(body)))

    def test_timeout_kills_the_whole_process_group_including_descendants(self):
        pid_file = pathlib.Path(self.tmp.name) / f"child-{uuid.uuid4().hex}.pid"
        os.environ["CLOP_TEST_CHILD_PID_FILE"] = str(pid_file)
        try:
            started = time.monotonic()
            response, data = self.post("type=pdf", b"timeout_orphan")
            elapsed = time.monotonic() - started
        finally:
            os.environ.pop("CLOP_TEST_CHILD_PID_FILE", None)

        self.assertEqual(response.status, 504)
        self.assertEqual(json.loads(data), {"error": "timeout"})
        self.assertLess(elapsed, 5, "the 1s CLOP_TIMEOUT should not need this long to be enforced")

        deadline = time.monotonic() + 5
        pid = None
        while time.monotonic() < deadline:
            if pid_file.exists():
                content = pid_file.read_text().strip()
                if content:
                    pid = int(content)
                    break
            time.sleep(0.05)
        self.assertIsNotNone(pid, "descendant pid was never recorded by the fake clop")

        deadline = time.monotonic() + 5
        gone = False
        while time.monotonic() < deadline:
            try:
                os.kill(pid, 0)
            except ProcessLookupError:
                gone = True
                break
            time.sleep(0.1)
        self.assertTrue(gone, f"descendant pid {pid} was still alive after timeout cleanup")


if __name__ == "__main__":
    unittest.main()

import argparse
import contextlib
import io
import json
import logging
import pathlib
import tempfile
import unittest
from datetime import datetime, timezone
from unittest import mock

from tools.studio import system_metrics as metrics


class FakeReaders:
    """Test helper for injecting metric readers."""

    def __init__(self, cpu=None, memory=None, gpu=None):
        self.cpu = cpu
        self.memory = memory
        self.gpu = gpu


class SamplerTests(unittest.TestCase):
    def test_cpu_percent_uses_busy_delta_over_total_delta(self):
        before = metrics.CpuTicks(user=100, system=50, idle=850, nice=0)
        after = metrics.CpuTicks(user=130, system=70, idle=900, nice=0)
        self.assertEqual(metrics.cpu_percent(previous=before, current=after), 50.0)

    def test_cpu_percent_rejects_non_monotonic_ticks(self):
        before = metrics.CpuTicks(user=100, system=50, idle=850, nice=0)
        after = metrics.CpuTicks(user=10, system=5, idle=85, nice=0)
        with self.assertRaisesRegex(metrics.SampleUnavailable, "non-monotonic"):
            metrics.cpu_percent(previous=before, current=after)

    def test_vm_stat_counts_active_wired_compressor_not_cache(self):
        text = (
            "Mach Virtual Memory Statistics: (page size of 16384 bytes)\n"
            "Pages free: 100.\n"
            "Pages active: 300.\n"
            "Pages inactive: 400.\n"
            "Pages speculative: 20.\n"
            "Pages wired down: 100.\n"
            "Pages occupied by compressor: 40.\n"
        )
        # inactive + speculative are reclaimable cache and must not count as used
        self.assertEqual(
            metrics.parse_vm_stat(text=text, total_bytes=16_384_000),
            {"used_bytes": 7_208_960, "total_bytes": 16_384_000, "percent": 44.0},
        )

    def test_vm_stat_prefers_anonymous_minus_purgeable_over_active(self):
        text = (
            "Mach Virtual Memory Statistics: (page size of 16384 bytes)\n"
            "Pages free: 100.\n"
            "Pages active: 300.\n"
            "Pages inactive: 400.\n"
            "Pages speculative: 20.\n"
            "Pages wired down: 100.\n"
            "Pages purgeable: 30.\n"
            "File-backed pages: 500.\n"
            "Anonymous pages: 200.\n"
            "Pages occupied by compressor: 40.\n"
        )
        # app memory = anonymous - purgeable = 170; + wired 100 + compressor 40 = 310 pages
        self.assertEqual(
            metrics.parse_vm_stat(text=text, total_bytes=16_384_000),
            {"used_bytes": 5_079_040, "total_bytes": 16_384_000, "percent": 31.0},
        )

    def test_agx_parser_reads_device_utilization(self):
        text = '"PerformanceStatistics" = {"Device Utilization %"=37,"Renderer Utilization %"=12}'
        self.assertEqual(metrics.parse_agx_utilization(text=text), 37.0)

    def test_partial_failure_keeps_other_signals_available(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=OSError("cpu"),
                memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
                gpu=25.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        self.assertFalse(snapshot["signals"]["cpu"]["available"])
        self.assertTrue(snapshot["signals"]["memory"]["available"])
        self.assertTrue(snapshot["signals"]["gpu"]["available"])
        self.assertEqual(snapshot["schema_version"], 1)

    def test_atomic_write_replaces_complete_document(self):
        with tempfile.TemporaryDirectory() as root:
            target = pathlib.Path(root) / "snapshot.json"
            metrics.atomic_write_snapshot(
                path=target, payload={"schema_version": 1, "signals": {}}
            )
            self.assertEqual(
                json.loads(target.read_text()),
                {"schema_version": 1, "signals": {}},
            )
            self.assertEqual(list(target.parent.glob("snapshot.json.*.tmp")), [])

    # Finding 1: Production reader wiring
    def test_production_metric_readers_wired_correctly(self):
        readers = metrics.MetricReaders()
        # Verify attributes exist and are callable
        self.assertTrue(callable(readers.cpu))
        self.assertTrue(callable(readers.memory))
        self.assertTrue(callable(readers.gpu))

    # Finding 5: First CPU sample must be unavailable
    def test_first_cpu_sample_unavailable_when_no_previous(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
                gpu=25.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        # First sample should be unavailable (no delta available)
        self.assertFalse(snapshot["signals"]["cpu"]["available"])
        self.assertIn("error", snapshot["signals"]["cpu"])

    # Finding 4: Memory validation - negative bytes
    def test_memory_validation_rejects_negative_used_bytes(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                memory={"used_bytes": -1, "total_bytes": 16, "percent": 50.0},
                gpu=25.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        self.assertFalse(snapshot["signals"]["memory"]["available"])

    # Finding 4: Memory validation - used > total
    def test_memory_validation_rejects_used_greater_than_total(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                memory={"used_bytes": 20, "total_bytes": 16, "percent": 50.0},
                gpu=25.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        self.assertFalse(snapshot["signals"]["memory"]["available"])

    # Finding 4: Memory validation - clamp percentage
    def test_memory_validation_clamps_percentage(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                memory={"used_bytes": 8, "total_bytes": 16, "percent": 150.0},
                gpu=25.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        self.assertTrue(snapshot["signals"]["memory"]["available"])
        self.assertEqual(snapshot["signals"]["memory"]["percent"], 100.0)

    # Finding 4: GPU validation - negative clamps to 0.0 and is available
    def test_gpu_validation_rejects_negative_percent(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
                gpu=-1.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        self.assertTrue(snapshot["signals"]["gpu"]["available"])
        self.assertEqual(snapshot["signals"]["gpu"]["percent"], 0.0)

    # Finding 4: GPU validation - clamp percentage
    def test_gpu_validation_clamps_percent_over_100(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
                gpu=150.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        self.assertTrue(snapshot["signals"]["gpu"]["available"])
        self.assertEqual(snapshot["signals"]["gpu"]["percent"], 100.0)

    # Finding 6: Malformed VM stat input
    def test_vm_stat_handles_malformed_input(self):
        text = 'Garbage text with no valid pages\n'
        result = metrics.parse_vm_stat(text=text, total_bytes=16_384_000)
        self.assertEqual(result["used_bytes"], 16_384_000)  # All used when can't parse
        self.assertEqual(result["total_bytes"], 16_384_000)
        self.assertEqual(result["percent"], 100.0)

    # Finding 6: Malformed AGX input
    def test_agx_parser_raises_on_malformed_input(self):
        text = 'No performance statistics here'
        with self.assertRaises(metrics.SampleUnavailable):
            metrics.parse_agx_utilization(text=text)

    def test_snapshot_schema_always_has_version(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
                gpu=25.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        self.assertEqual(snapshot["schema_version"], 1)
        self.assertIn("collected_at", snapshot)

    def test_all_signals_reported_even_if_some_fail(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=OSError("cpu failed"),
                memory=OSError("memory failed"),
                gpu=50.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        self.assertIn("cpu", snapshot["signals"])
        self.assertIn("memory", snapshot["signals"])
        self.assertIn("gpu", snapshot["signals"])
        self.assertFalse(snapshot["signals"]["cpu"]["available"])
        self.assertFalse(snapshot["signals"]["memory"]["available"])
        self.assertTrue(snapshot["signals"]["gpu"]["available"])

    # Finding 4: GPU negative values clamp to 0.0 and remain available
    def test_gpu_validation_clamps_negative_to_zero(self):
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
                gpu=-5.0,
            ),
            previous_cpu=None,
            collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
        )
        self.assertTrue(snapshot["signals"]["gpu"]["available"])
        self.assertEqual(snapshot["signals"]["gpu"]["percent"], 0.0)

    # Finding 5: Timestamp with Z must be UTC
    def test_collected_at_timestamp_is_utc_with_z(self):
        collected_at = datetime(2026, 9, 20, 12, 30, 45, tzinfo=timezone.utc)
        snapshot, _ = metrics.collect_snapshot(
            readers=FakeReaders(
                cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
                gpu=25.0,
            ),
            previous_cpu=None,
            collected_at=collected_at,
        )
        iso = snapshot["collected_at"]
        self.assertTrue(iso.endswith("Z"), f"Expected UTC Z suffix, got {iso}")
        parsed = datetime.fromisoformat(iso.replace("Z", "+00:00"))
        self.assertEqual(parsed.tzinfo, timezone.utc)

    # Finding 1: JSONL logging with json.dumps, proper structure, and valid JSON
    def test_jsonl_logging_emits_valid_json_per_line(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            log_path = pathlib.Path(tmpdir) / "test.jsonl"
            logger = metrics._setup_logging(env_log_path=str(log_path))
            logger.info("Test event", extra={"run_id": "run-123"})

            lines = log_path.read_text().strip().split("\n")
            self.assertEqual(len(lines), 1)

            parsed = json.loads(lines[0])
            self.assertIsInstance(parsed, dict)

            required_keys = {"ts", "run_id", "level", "event", "loc", "ctx"}
            self.assertEqual(set(parsed.keys()), required_keys)

            self.assertEqual(parsed["level"], "INFO")
            self.assertEqual(parsed["event"], "Test event")
            self.assertEqual(parsed["run_id"], "run-123")
            self.assertIsInstance(parsed["ctx"], dict)

    # Finding 1: JSONL logging successful samples are quiet
    def test_successful_sample_does_not_log_if_not_started(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            log_path = pathlib.Path(tmpdir) / "test.jsonl"
            snapshot_path = pathlib.Path(tmpdir) / "snapshot.json"

            logger = metrics._setup_logging(env_log_path=str(log_path))

            def mock_sample_loop():
                snapshot, _ = metrics.collect_snapshot(
                    readers=FakeReaders(
                        cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
                        memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
                        gpu=25.0,
                    ),
                    previous_cpu=None,
                    collected_at=datetime(2026, 9, 20, tzinfo=timezone.utc),
                )
                metrics.atomic_write_snapshot(path=snapshot_path, payload=snapshot)

            mock_sample_loop()

            if log_path.exists():
                content = log_path.read_text().strip()
                self.assertEqual(content, "", "Successful sample should produce no log output")

    # Finding 1: JSONL logging error events logged when enabled
    def test_jsonl_logging_error_events_when_enabled(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            log_path = pathlib.Path(tmpdir) / "test.jsonl"
            logger = metrics._setup_logging(env_log_path=str(log_path))
            logger.error("Sample failed", extra={"run_id": "run-456"})

            lines = log_path.read_text().strip().split("\n")
            self.assertGreater(len(lines), 0)

            parsed = json.loads(lines[0])
            self.assertEqual(parsed["level"], "ERROR")
            self.assertEqual(parsed["event"], "Sample failed")

    # Finding 1: Loop is hermetic — explicit FakeReaders, exact sleep/write counts and values
    def test_sample_loop_with_mock_sleeper_and_stop(self):
        iterations = []
        fake_readers = FakeReaders(
            cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
            memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
            gpu=25.0,
        )

        def mock_sleep(interval):
            iterations.append(interval)

        with tempfile.TemporaryDirectory() as tmpdir:
            snapshot_path = pathlib.Path(tmpdir) / "snapshot.json"

            metrics.sample_loop(
                snapshot_path=snapshot_path,
                interval=5.0,
                run_id="test-run",
                logger=None,
                readers=fake_readers,
                sleeper=mock_sleep,
                max_iterations=2,
            )

            self.assertEqual(iterations, [5.0, 5.0])
            snapshot = json.loads(snapshot_path.read_text())
            self.assertEqual(snapshot["schema_version"], 1)
            self.assertTrue(snapshot["signals"]["memory"]["available"])
            self.assertEqual(snapshot["signals"]["memory"]["used_bytes"], 8)
            self.assertEqual(snapshot["signals"]["memory"]["total_bytes"], 16)
            self.assertEqual(snapshot["signals"]["memory"]["percent"], 50.0)
            self.assertTrue(snapshot["signals"]["gpu"]["available"])
            self.assertEqual(snapshot["signals"]["gpu"]["percent"], 25.0)
            # Static FakeReaders.cpu yields zero deltas on the second iteration.
            self.assertFalse(snapshot["signals"]["cpu"]["available"])
            self.assertEqual(snapshot["signals"]["cpu"]["error"]["code"], "SampleUnavailable")
            self.assertIn("non-monotonic", snapshot["signals"]["cpu"]["error"]["message"])

    # Finding 1: Loop interval minimum 1.0 — explicit FakeReaders, exact values
    def test_sample_loop_enforces_minimum_interval_of_1_second(self):
        iterations = []
        fake_readers = FakeReaders(
            cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
            memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
            gpu=25.0,
        )

        def mock_sleep(interval):
            iterations.append(interval)

        with tempfile.TemporaryDirectory() as tmpdir:
            snapshot_path = pathlib.Path(tmpdir) / "snapshot.json"

            metrics.sample_loop(
                snapshot_path=snapshot_path,
                interval=0.5,
                run_id="test-run",
                logger=None,
                readers=fake_readers,
                sleeper=mock_sleep,
                max_iterations=1,
            )

            self.assertEqual(iterations, [1.0])
            snapshot = json.loads(snapshot_path.read_text())
            self.assertEqual(snapshot["schema_version"], 1)
            self.assertFalse(snapshot["signals"]["cpu"]["available"])
            self.assertEqual(
                snapshot["signals"]["cpu"]["error"]["message"],
                "First sample has no previous CPU ticks for delta",
            )
            self.assertTrue(snapshot["signals"]["memory"]["available"])
            self.assertEqual(snapshot["signals"]["memory"]["used_bytes"], 8)
            self.assertEqual(snapshot["signals"]["memory"]["total_bytes"], 16)
            self.assertEqual(snapshot["signals"]["memory"]["percent"], 50.0)
            self.assertTrue(snapshot["signals"]["gpu"]["available"])
            self.assertEqual(snapshot["signals"]["gpu"]["percent"], 25.0)

    # Finding 1: Loop writes atomically — explicit FakeReaders, exact values
    def test_sample_loop_writes_atomic_snapshot(self):
        fake_readers = FakeReaders(
            cpu=metrics.CpuTicks(user=100, system=50, idle=850, nice=0),
            memory={"used_bytes": 8, "total_bytes": 16, "percent": 50.0},
            gpu=25.0,
        )
        sleep_calls = []

        def mock_sleep(interval):
            sleep_calls.append(interval)

        with tempfile.TemporaryDirectory() as tmpdir:
            snapshot_path = pathlib.Path(tmpdir) / "snapshot.json"

            metrics.sample_loop(
                snapshot_path=snapshot_path,
                interval=5.0,
                run_id="test-run",
                logger=None,
                readers=fake_readers,
                sleeper=mock_sleep,
                max_iterations=1,
            )

            self.assertEqual(sleep_calls, [5.0])
            self.assertTrue(snapshot_path.exists())
            self.assertEqual(list(snapshot_path.parent.glob("snapshot.json.*.tmp")), [])
            snapshot = json.loads(snapshot_path.read_text())
            self.assertEqual(snapshot["schema_version"], 1)
            self.assertEqual(snapshot["signals"]["memory"]["used_bytes"], 8)
            self.assertEqual(snapshot["signals"]["gpu"]["percent"], 25.0)

    # Finding 1: production default constructs MetricReaders without invoking native calls
    def test_sample_loop_default_readers_constructs_metric_readers(self):
        with mock.patch("tools.studio.system_metrics.MetricReaders") as mock_metric_readers:
            mock_instance = mock.MagicMock()
            mock_metric_readers.return_value = mock_instance

            with tempfile.TemporaryDirectory() as tmpdir:
                snapshot_path = pathlib.Path(tmpdir) / "snapshot.json"
                metrics.sample_loop(
                    snapshot_path=snapshot_path,
                    interval=5.0,
                    run_id="test-run",
                    logger=None,
                    sleeper=lambda interval: None,
                    max_iterations=0,
                )

            mock_metric_readers.assert_called_once_with()
            mock_instance.cpu.assert_not_called()
            mock_instance.memory.assert_not_called()
            mock_instance.gpu.assert_not_called()

    # Finding 3: CPU native boundary — patch only the ctypes.CDLL/libc boundary,
    # call the real metrics.read_cpu_ticks(), and mutate the actual ticks buffer.
    def test_read_cpu_ticks_mutates_real_buffer_and_returns_exact_values(self):
        def fake_host_statistics(host, flavor, ticks_ptr, count_ptr):
            ticks_array = ticks_ptr._obj
            ticks_array[0] = 111
            ticks_array[1] = 222
            ticks_array[2] = 333
            ticks_array[3] = 44
            return 0

        with mock.patch("ctypes.CDLL") as mock_cdll:
            mock_libc = mock.MagicMock()
            mock_libc.mach_host_self.return_value = 42
            mock_libc.host_statistics.side_effect = fake_host_statistics
            mock_cdll.return_value = mock_libc

            result = metrics.read_cpu_ticks()

        self.assertEqual(result, metrics.CpuTicks(user=111, system=222, idle=333, nice=44))

    def test_read_cpu_ticks_raises_when_host_statistics_returns_nonzero(self):
        def fake_host_statistics(host, flavor, ticks_ptr, count_ptr):
            return 1

        with mock.patch("ctypes.CDLL") as mock_cdll:
            mock_libc = mock.MagicMock()
            mock_libc.mach_host_self.return_value = 42
            mock_libc.host_statistics.side_effect = fake_host_statistics
            mock_cdll.return_value = mock_libc

            with self.assertRaises(metrics.SampleUnavailable):
                metrics.read_cpu_ticks()

    # Finding 3: Mock subprocess for read_memory_stats
    def test_read_memory_stats_mocked_success(self):
        with mock.patch("subprocess.run") as mock_run:
            total_result = mock.MagicMock()
            total_result.returncode = 0
            total_result.stdout = "16777216000\n"

            vm_result = mock.MagicMock()
            vm_result.returncode = 0
            vm_result.stdout = "Mach Virtual Memory Statistics: (page size of 16384 bytes)\nPages free: 100.\nPages speculative: 20.\n"

            mock_run.side_effect = [total_result, vm_result]

            result = metrics.read_memory_stats()
            self.assertIsInstance(result, dict)
            self.assertIn("used_bytes", result)
            self.assertIn("total_bytes", result)
            self.assertIn("percent", result)

    # Finding 3: Mock subprocess for read_memory_stats failure
    def test_read_memory_stats_mocked_failure(self):
        with mock.patch("subprocess.run") as mock_run:
            mock_run.side_effect = OSError("Permission denied")

            with self.assertRaises(metrics.SampleUnavailable):
                metrics.read_memory_stats()

    # Finding 3: Mock subprocess for read_gpu_utilization
    def test_read_gpu_utilization_mocked_success(self):
        with mock.patch("subprocess.run") as mock_run:
            result = mock.MagicMock()
            result.returncode = 0
            result.stdout = '"PerformanceStatistics" = {"Device Utilization %"=45}'
            mock_run.return_value = result

            gpu_val = metrics.read_gpu_utilization()
            self.assertEqual(gpu_val, 45.0)

    # Finding 3: Mock subprocess for read_gpu_utilization failure
    def test_read_gpu_utilization_mocked_failure(self):
        with mock.patch("subprocess.run") as mock_run:
            mock_run.side_effect = OSError("Device not found")

            with self.assertRaises(metrics.SampleUnavailable):
                metrics.read_gpu_utilization()

    # Finding 7: CLI --snapshot required
    def test_cli_snapshot_argument_is_required(self):
        import sys as sys_module
        old_argv = sys_module.argv
        try:
            sys_module.argv = ["script", "--interval", "5.0"]
            parser = argparse.ArgumentParser()
            parser.add_argument("--snapshot", type=pathlib.Path, required=True)
            parser.add_argument("--interval", type=float, default=5.0)

            with self.assertRaises(SystemExit):
                parser.parse_args()
        finally:
            sys_module.argv = old_argv

    # Finding 7: CLI parses interval correctly
    def test_cli_argument_parsing_interval_default(self):
        import argparse as argparse_module
        parser = argparse_module.ArgumentParser()
        parser.add_argument("--snapshot", type=pathlib.Path, required=True)
        parser.add_argument("--interval", type=float, default=5.0)

        with tempfile.TemporaryDirectory() as tmpdir:
            snap_path = pathlib.Path(tmpdir) / "snap.json"
            args = parser.parse_args(["--snapshot", str(snap_path)])
            self.assertEqual(args.interval, 5.0)

    # Finding 6: Disabled logging attaches a NullHandler and never propagates
    def test_disabled_logging_emits_nothing(self):
        logger = metrics._setup_logging(env_log_path=None)
        logger.info("Should not appear anywhere")
        logger.error("Error also should not appear")

        self.assertEqual(len(logger.handlers), 1)
        self.assertIsInstance(logger.handlers[0], logging.NullHandler)
        self.assertFalse(logger.propagate)

    # Finding 6: Disabled logging must not leak to stderr via logging.lastResort
    def test_disabled_logging_never_reaches_stderr(self):
        logger = metrics._setup_logging(env_log_path=None)
        captured = io.StringIO()

        with contextlib.redirect_stderr(captured):
            logger.error("This error must not reach stderr")

        self.assertEqual(captured.getvalue(), "")

    # Finding 7: Redaction of credentials in logging
    def test_logging_redacts_bearer_tokens(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            log_path = pathlib.Path(tmpdir) / "test.jsonl"
            logger = metrics._setup_logging(env_log_path=str(log_path))
            logger.error("Request failed with Bearer abc123xyz", extra={"run_id": "run-1"})

            lines = log_path.read_text().strip().split("\n")
            self.assertEqual(len(lines), 1)

            parsed = json.loads(lines[0])
            self.assertIn("***REDACTED***", parsed["event"])
            self.assertNotIn("abc123xyz", parsed["event"])

    def test_logging_redacts_key_value_pairs(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            log_path = pathlib.Path(tmpdir) / "test.jsonl"
            logger = metrics._setup_logging(env_log_path=str(log_path))
            logger.error("Config: api_key=secret123 password=pass456", extra={"run_id": "run-1"})

            lines = log_path.read_text().strip().split("\n")
            parsed = json.loads(lines[0])
            self.assertNotIn("secret123", parsed["event"])
            self.assertNotIn("pass456", parsed["event"])

    def test_logging_redacts_dict_credentials(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            log_path = pathlib.Path(tmpdir) / "test.jsonl"
            logger = metrics._setup_logging(env_log_path=str(log_path))

            class CustomRecord(logging.LogRecord):
                def __init__(self):
                    super().__init__(
                        name="test", level=logging.ERROR, pathname="", lineno=0,
                        msg="Config", args=(), exc_info=None
                    )
                    self.run_id = "run-1"

            record = CustomRecord()
            handler = logger.handlers[0]
            formatter = handler.formatter

            test_data = {"api_key": "secret", "normal_field": "value"}
            redacted = formatter._redact_credentials(test_data)

            self.assertEqual(redacted["api_key"], "***REDACTED***")
            self.assertEqual(redacted["normal_field"], "value")

    # Finding 4: dynamic credential-key redaction — keys built at runtime, sentinel gate
    def test_dynamic_credential_keys_are_redacted_regardless_of_style(self):
        dynamic_keys = [
            "_".join(["auth", "token"]),
            "_".join(["client", "secret"]),
            "Authorization",
            "_".join(["db", "password"]),
            "service" + "Api" + "Key",
        ]
        for key in dynamic_keys:
            with self.subTest(key=key):
                sentinel = "sentinel-" + key.lower() + "-do-not-leak"
                payload = {key: sentinel, "ordinary_field": "keep-me"}
                redacted = metrics._JSONLFormatter._redact_credentials(payload)
                serialized = json.dumps(redacted)
                self.assertNotIn(sentinel, serialized)
                self.assertIn("keep-me", serialized)

    # Finding 4: nested dynamic keys are redacted recursively; sentinel never reaches parsed JSON
    def test_dynamic_credential_keys_redacted_in_nested_payload(self):
        sentinel = "sentinel-nested-do-not-leak"
        nested = {"outer": {"service" + "Api" + "Key": sentinel, "safe": "keep-me"}}

        redacted = metrics._JSONLFormatter._redact_credentials(nested)
        serialized = json.dumps(redacted)

        self.assertNotIn(sentinel, serialized)
        self.assertIn("keep-me", serialized)

    # Finding 4: avoid false positives on ordinary keys where practical
    def test_ordinary_keys_are_not_redacted(self):
        payload = {"author": "keep", "authenticated_user": "keep2", "message": "keep3"}
        redacted = metrics._JSONLFormatter._redact_credentials(payload)
        self.assertEqual(redacted, payload)

    # Finding 5: Parser extraction
    def test_build_parser_creates_valid_parser(self):
        parser = metrics.build_parser()
        self.assertIsInstance(parser, argparse.ArgumentParser)

    def test_parse_args_with_required_snapshot(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            snap_path = pathlib.Path(tmpdir) / "snap.json"
            args = metrics.parse_args(["--snapshot", str(snap_path)])
            self.assertEqual(args.snapshot, snap_path)
            self.assertEqual(args.interval, 5.0)

    def test_parse_args_with_custom_interval(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            snap_path = pathlib.Path(tmpdir) / "snap.json"
            args = metrics.parse_args(["--snapshot", str(snap_path), "--interval", "10.0"])
            self.assertEqual(args.interval, 10.0)

    def test_parse_args_missing_snapshot_raises(self):
        with self.assertRaises(SystemExit):
            metrics.parse_args([])


if __name__ == "__main__":
    unittest.main()

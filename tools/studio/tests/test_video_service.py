import importlib.util
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "video_service.py"
SPEC = importlib.util.spec_from_file_location("studio_video_service", MODULE_PATH)
video_service = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = video_service
SPEC.loader.exec_module(video_service)


# The process-ownership and lifecycle code is shared in shape with clop_service.py and covered by
# test_clop_service.py. These tests cover what is this manager's own: its dependencies, the environment it
# hands the server, and where it deploys from.
class VideoServiceTests(unittest.TestCase):
    def config(self, root, **overrides):
        defaults = dict(
            source=root / "source",
            state=root / "state",
            host="127.0.0.1",
            port=18769,
            ytdlp=root / "bin" / "yt-dlp",
            ffmpeg_dir=root / "bin",
            max_bytes=2 * 1024 * 1024 * 1024,
            timeout=60,
        )
        defaults.update(overrides)
        return video_service.ServiceConfig(**defaults)

    def write_source(self, root):
        source = root / "source" / "tools" / "studio" / "video-service"
        source.mkdir(parents=True)
        (source / "server.py").write_text("server", encoding="utf-8")
        return source

    def start(self, config):
        """Run `up` against a stopped service with the spawn and health check stubbed; returns the Popen mock."""
        process = mock.Mock(pid=789)
        process.poll.return_value = None
        stopped = {"healthy": False, "running": False, "pid": None}
        started = {"healthy": True, "running": True, "pid": 789}
        with (
            mock.patch.object(video_service, "status", side_effect=[stopped, started]),
            mock.patch.object(video_service, "_read_pid", return_value=None),
            mock.patch.object(video_service, "_health", side_effect=[None, {"ok": True}]),
            mock.patch.object(video_service.subprocess, "Popen", return_value=process) as popen,
        ):
            video_service.up(config, video_service.EventLog(config.state))
        return popen

    def test_deploy_takes_the_server_from_the_video_service_folder(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            (self.write_source(root) / "unrelated.py").write_text("must not copy", encoding="utf-8")
            config = self.config(root)
            video_service.deploy(config)
            self.assertEqual(sorted(path.name for path in config.runtime.iterdir()), ["server.py"])
            self.assertEqual(config.runtime, root / "state" / "runtime" / "video-service")

    def test_up_names_the_missing_downloader_or_ffmpeg(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.write_source(root)
            config = self.config(root)
            (root / "bin").mkdir()
            for present, missing in (((), "yt-dlp"), (("yt-dlp",), "ffmpeg")):
                for name in present:
                    (root / "bin" / name).touch()
                with self.subTest(missing=missing), self.assertRaises(video_service.ServiceFailure) as raised:
                    self.start(config)
                self.assertTrue(str(raised.exception).endswith(missing))

    def test_the_server_gets_its_queue_folder_and_a_path_that_reaches_ffmpeg(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.write_source(root)
            config = self.config(root, host="192.168.164.1")
            (root / "bin").mkdir()
            for name in ("yt-dlp", "ffmpeg"):
                (root / "bin" / name).touch()
            popen = self.start(config)
            environment = popen.call_args.kwargs["env"]
            self.assertEqual(environment["VIDEO_DATA"], str(root / "state" / "data"))
            self.assertEqual((environment["VIDEO_HOST"], environment["VIDEO_PORT"]), ("192.168.164.1", "18769"))
            self.assertEqual(environment["VIDEO_YTDLP"], str(root / "bin" / "yt-dlp"))
            # launchd's PATH has neither ffmpeg nor a JavaScript runtime; the manager puts both in reach.
            self.assertEqual(environment["PATH"].split(":")[:2], [str(root / "bin"), "/opt/homebrew/bin"])


if __name__ == "__main__":
    unittest.main()

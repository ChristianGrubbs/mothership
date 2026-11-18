from __future__ import annotations

import http.client
import importlib.util
import ipaddress
import json
import pathlib
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest import mock

MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "video-service" / "server.py"
SPEC = importlib.util.spec_from_file_location("studio_video_server", MODULE_PATH)
server_module = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = server_module
SPEC.loader.exec_module(server_module)

# Stands in for yt-dlp: the URL's path picks the behaviour, so a test never touches the network.
FAKE_YTDLP = '''#!/usr/bin/env python3
import json, os, pathlib, sys, time
args = sys.argv[1:]
if args == ["--version"]:
    print("2026.01.01"); sys.exit(0)
url = args[-1]
if "-J" in args:
    if "/missing" in url:
        sys.stderr.write("ERROR: [generic] Unable to download webpage: HTTP Error 404: Not Found (caused by https://example.test/x)\\n"); sys.exit(1)
    print(json.dumps({"title": "Homecoming highlights", "uploader": "Example Channel", "duration": 75, "extractor_key": "Example", "formats": [
        {"format_id": "a", "acodec": "mp4a", "vcodec": "none", "abr": 128, "filesize": 1000},
        {"format_id": "v1", "vcodec": "avc1", "acodec": "none", "height": 1080, "ext": "mp4", "tbr": 4000, "filesize": 9000},
        {"format_id": "v2", "vcodec": "avc1", "acodec": "mp4a", "height": 360, "ext": "mp4", "tbr": 700, "filesize": 3000},
    ]})); sys.exit(0)
folder = pathlib.Path(args[args.index("-o") + 1]).parent
if "/fail" in url:
    sys.stderr.write("WARNING: noise\\nERROR: [generic] example: Video unavailable\\n"); sys.exit(1)
if "/slow" in url:
    gate = pathlib.Path(os.environ["FAKE_GATE"])
    while not gate.exists():
        time.sleep(0.05)
# Two-stream downloads that pause partway: (plan line, progress lines). The plan lines have the shape yt-dlp
# 2026.10.26 itself prints for each mix of sizes (taken from its template evaluator): a stream with no size keeps
# its record. A video stream (format 137), then its sound (format 140).
TWO = ["150|300|NA|10|15|137", "300|300|NA|10|0|137", "50|100|NA|10|5|140"]
SCENES = {
    "/parts": ('[{"format_id": "137", "filesize": 300}, {"format_id": "140", "filesize": 100}]|400', TWO),
    "/mixed": ('[{"format_id": "137", "filesize_approx": 300}, {"format_id": "140", "filesize": 100}]|NA', TWO),
    "/unsized": ('[{"format_id": "137"}, {"format_id": "140"}]|NA', TWO),
    "/halfsized": ('[{"format_id": "137"}, {"format_id": "140", "filesize": 100}]|NA', TWO),
    # The sound's first report is already past the video's last count: only the format id marks the new stream.
    "/jump": ('[{"format_id": "137", "filesize": 100}, {"format_id": "140", "filesize": 1000}]|1100',
              ["100|100|NA|10|0|137", "200|1000|NA|10|80|140"]),
    # The count starts over inside one stream (a retry): it is still the same stream.
    "/reset": ('[{"format_id": "137", "filesize": 300}, {"format_id": "140", "filesize": 100}]|400',
               ["150|300|NA|10|15|137", "100|300|NA|10|20|137"]),
    # No plan and no format ids: the count starting over is the only sign of a second stream.
    "/anon": ('[]|NA', ["300|300|NA|10|0|NA", "50|100|NA|10|5|NA"]),
}
scene = next((SCENES[key] for key in SCENES if key in url), None)
if scene:
    print("MSPLAN|" + scene[0], flush=True)
    for progress in scene[1]:
        print("MSVID|" + progress, flush=True)
    gate = pathlib.Path(os.environ["FAKE_GATE"])
    while not gate.exists():
        time.sleep(0.05)
else:
    print("MSVID|50|100|NA|10|5|18", flush=True)
print("[Merger] Merging formats", flush=True)
target = folder / ("Clip: one.mp3" if "-x" in args else "Clip: one.mp4")
target.write_bytes(b"0123456789" * 10)
print(str(target), flush=True)
'''


def request(port, method, path, body=None, headers=None):
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
    payload = json.dumps(body).encode() if body is not None else None
    connection.request(method, path, body=payload, headers={"Content-Type": "application/json", **(headers or {})})
    response = connection.getresponse()
    data = response.read()
    connection.close()
    return response, data


class VideoServerCase(unittest.TestCase):
    def setUp(self):
        self.root = pathlib.Path(self.enterContext(tempfile.TemporaryDirectory()))
        self.fake = self.root / "yt-dlp"
        self.fake.write_text(FAKE_YTDLP)
        self.fake.chmod(self.fake.stat().st_mode | stat.S_IEXEC)
        self.gate = self.root / "gate"
        self.enterContext(mock.patch.dict("os.environ", {"FAKE_GATE": str(self.gate)}))

    def config(self, **overrides):
        environ = {
            "VIDEO_HOST": "127.0.0.1", "VIDEO_PORT": "0", "VIDEO_DATA": str(self.root / "data"),
            "VIDEO_YTDLP": str(self.fake), "VIDEO_ALLOW_PRIVATE": "1", "VIDEO_MIN_FREE_BYTES": "0",
            "VIDEO_OPTIMIZER_API": "off",
        }
        environ.update({key: str(value) for key, value in overrides.items()})
        return server_module.ServerConfig(environ)

    def store(self, **overrides):
        config = self.config(**overrides)
        config.data.mkdir(parents=True, exist_ok=True)
        return server_module.JobStore(config, server_module.EventLog(config))

    def serve(self, **overrides):
        server, store = server_module.serve(self.config(**overrides))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()

        def stop():
            self.gate.touch()
            store.stop()
            server.shutdown()
            server.server_close()

        self.addCleanup(stop)
        return server.server_address[1], store

    def wait_for(self, port, owner, job_id, statuses, timeout=10):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            _, data = request(port, "GET", "/jobs/{}?owner={}".format(job_id, owner))
            job = json.loads(data)["job"]
            if job["status"] in statuses:
                return job
            time.sleep(0.05)
        self.fail("job never reached {}: {}".format(statuses, job))


class LinkRules(VideoServerCase):
    def test_only_public_web_links_are_accepted(self):
        public = lambda *_args, **_kwargs: [(None, None, None, None, ("93.184.216.34", 443))]  # noqa: E731
        private = lambda *_args, **_kwargs: [(None, None, None, None, ("192.168.164.1", 80))]  # noqa: E731
        self.assertEqual(server_module.validate_url(" https://example.org/v ", resolver=public), "https://example.org/v")
        for bad in ("file:///etc/passwd", "ftp://example.org/v", "https://user:pw@example.org/v", "example.org/v", "", None,
                    "https://example.org/a b", "--exec=x"):
            with self.assertRaises(server_module.BadRequest, msg=repr(bad)):
                server_module.validate_url(bad, resolver=public)
        with self.assertRaises(server_module.BadRequest):
            server_module.validate_url("http://studio.internal/x", resolver=private)

    def test_choice_ids_map_to_fixed_arguments(self):
        self.assertIn("bv*[height=720]+ba/b[height=720]/bv*[height<=720]+ba/b", server_module.choice_args("v720"))
        self.assertIn("mp3", server_module.choice_args("mp3"))
        for bad in ("", "v", "v720; rm", "--exec", "best "):
            with self.assertRaises(server_module.BadRequest):
                server_module.choice_args(bad)

    def test_ranges(self):
        self.assertIsNone(server_module.parse_range(None, 100))
        self.assertEqual(server_module.parse_range("bytes=10-19", 100), (10, 19))
        self.assertEqual(server_module.parse_range("bytes=90-", 100), (90, 99))
        self.assertEqual(server_module.parse_range("bytes=-10", 100), (90, 99))
        self.assertEqual(server_module.parse_range("bytes=0-500", 100), (0, 99))
        with self.assertRaises(server_module.BadRequest):
            server_module.parse_range("bytes=100-", 100)


class Queue(VideoServerCase):
    def test_people_take_turns_and_each_person_keeps_their_order(self):
        store = self.store()
        ids = {}
        for owner, name in (("1", "a1"), ("1", "a2"), ("1", "a3"), ("2", "b1"), ("3", "c1")):
            job, created = store.add(owner, "https://example.test/" + name, "best", name)
            self.assertTrue(created)
            ids[job["id"]] = name
            time.sleep(0.002)
        self.assertEqual([ids[i] for i in store._queue_order()], ["a1", "b1", "c1", "a2", "a3"])
        # Position is what the person is told: owner 1's second job is fourth in line.
        self.assertEqual([j["position"] for j in store.list("1")], [5, 4, 1])

    def test_a_backlog_is_kept_per_person_and_capped(self):
        store = self.store(VIDEO_USER_BACKLOG=2)
        first, _ = store.add("1", "https://example.test/a", "best", None)
        again, created = store.add("1", "https://example.test/a", "best", None)
        self.assertFalse(created)
        self.assertEqual(again["id"], first["id"])
        store.add("1", "https://example.test/b", "best", None)
        with self.assertRaises(server_module.BadRequest) as raised:
            store.add("1", "https://example.test/c", "best", None)
        self.assertEqual(raised.exception.status, 429)
        store.add("2", "https://example.test/c", "best", None)  # another person is not held back

    def test_a_restart_queues_interrupted_downloads_again_and_keeps_finished_ones(self):
        store = self.store()
        running, _ = store.add("1", "https://example.test/slow", "best", "Interrupted")
        done, _ = store.add("1", "https://example.test/done", "best", "Finished")
        store.jobs[running["id"]].update(status="running", percent=40)
        store.jobs[done["id"]].update(status="done", finished_at=time.time(), filename="x.mp4", stored="x.mp4", size=3)
        store._save()

        reopened = self.store()
        self.assertEqual(reopened.jobs[running["id"]]["status"], "queued")
        self.assertEqual(reopened.jobs[running["id"]]["percent"], 0)
        self.assertEqual(reopened.jobs[done["id"]]["status"], "done")

    def test_finished_files_are_purged_after_the_keep_time(self):
        store = self.store(VIDEO_KEEP_SECONDS=60)
        old, _ = store.add("1", "https://example.test/old", "best", None)
        new, _ = store.add("1", "https://example.test/new", "best", None)
        for job_id, age in ((old["id"], 120), (new["id"], 5)):
            folder = store.config.files / job_id
            folder.mkdir(parents=True)
            (folder / "x.mp4").write_bytes(b"x")
            store.jobs[job_id].update(status="done", finished_at=time.time() - age)
        orphan = store.config.files / ("f" * 32)
        orphan.mkdir()
        self.assertEqual(store.purge_expired(), 1)
        self.assertFalse((store.config.files / old["id"]).exists())
        self.assertTrue((store.config.files / new["id"]).exists())
        self.assertFalse(orphan.exists())
        self.assertEqual([j["id"] for j in store.list("1")], [new["id"]])


class OverHttp(VideoServerCase):
    def test_the_size_list_stays_short_and_defaults_to_full_hd(self):
        ladder = lambda *heights: {"formats": [{"vcodec": "avc1", "acodec": "none", "height": h} for h in heights]}  # noqa: E731
        picks = lambda info: [(c["id"], c["default"]) for c in server_module.build_choices(info)]  # noqa: E731
        self.assertEqual(picks(ladder(2160, 1440, 1080, 720, 480, 360, 240, 144)),
                         [("v2160", False), ("v1080", True), ("v720", False), ("v480", False), ("mp3", False)])
        self.assertEqual(picks(ladder(240, 144)), [("v240", True), ("v144", False), ("mp3", False)])
        self.assertEqual(picks(ladder(1920, 1280)), [("v1920", True), ("v1280", False), ("mp3", False)])
        self.assertEqual(picks({}), [("best", True), ("mp3", False)])
        self.assertEqual(server_module.site_name("Youtube", "https://youtu.be/x"), "YouTube")
        self.assertEqual(server_module.site_name("Generic", "https://Example.org/x"), "example.org")

    def proxy(self, addresses, **overrides):
        """An egress proxy whose DNS is a table: host -> address."""
        config = self.config(**overrides)
        config.data.mkdir(parents=True, exist_ok=True)

        def resolver(host, port, type=0):
            if host in addresses:
                return [(2, 1, 6, "", (addresses[host], port))]
            try:
                ipaddress.ip_address(host)  # an address literal resolves to itself, as with getaddrinfo
            except ValueError:
                raise OSError("unknown host")
            return [(2, 1, 6, "", (host, port))]

        proxy = server_module.EgressProxy(config, server_module.EventLog(config), resolver=resolver)
        threading.Thread(target=proxy.serve_forever, daemon=True).start()
        self.addCleanup(proxy.server_close)
        self.addCleanup(proxy.shutdown)
        return proxy.server_address[1]

    def through(self, port, head):
        with socket.create_connection(("127.0.0.1", port), timeout=5) as client:
            client.sendall(head.encode("ascii"))
            data = b""
            while True:
                chunk = client.recv(4096)
                if not chunk:
                    return data
                data += chunk
                if b"\r\n\r\n" in data and not data.startswith(b"HTTP/1.1 200 Connection"):
                    return data

    def test_downloads_cannot_be_steered_to_a_private_address(self):
        # What the pasted-link check cannot see: a redirect, a media URL or a changed DNS answer. Each is a new
        # connection, and each comes through the proxy.
        port = self.proxy({"inside.test": "10.0.0.5", "rebound.test": "127.0.0.1"}, VIDEO_ALLOW_PRIVATE="0")
        for head in (
            "CONNECT inside.test:443 HTTP/1.1\r\nHost: inside.test:443\r\n\r\n",
            "CONNECT 127.0.0.1:18769 HTTP/1.1\r\n\r\n",
            "CONNECT [::1]:80 HTTP/1.1\r\n\r\n",
            "GET http://rebound.test/latest/meta-data HTTP/1.1\r\nHost: rebound.test\r\n\r\n",
            "GET http://169.254.169.254/ HTTP/1.1\r\n\r\n",
        ):
            with self.subTest(head=head.split("\r\n")[0]):
                self.assertTrue(self.through(port, head).startswith(b"HTTP/1.1 403 "))
        self.assertTrue(self.through(port, "CONNECT nowhere.test:443 HTTP/1.1\r\n\r\n").startswith(b"HTTP/1.1 502 "))
        self.assertTrue(self.through(port, "GET ftp://inside.test/x HTTP/1.1\r\n\r\n").startswith(b"HTTP/1.1 400 "))

    def test_the_proxy_relays_to_the_address_it_checked(self):
        service, _ = self.serve()  # any local HTTP server will do as the destination
        port = self.proxy({"video.test": "127.0.0.1"})
        answer = self.through(port, "GET http://video.test:{}/health HTTP/1.1\r\nHost: video.test\r\nProxy-Connection: keep-alive\r\n\r\n".format(service))
        self.assertTrue(answer.startswith(b"HTTP/1.0 200") or answer.startswith(b"HTTP/1.1 200"), answer[:40])
        with socket.create_connection(("127.0.0.1", port), timeout=5) as client:
            client.sendall("CONNECT video.test:{} HTTP/1.1\r\n\r\n".format(service).encode("ascii"))
            self.assertTrue(client.recv(4096).startswith(b"HTTP/1.1 200 "))
            client.sendall(b"GET /health HTTP/1.0\r\n\r\n")
            self.assertIn(b" 200 ", client.recv(4096))

    def test_the_downloader_is_sent_through_the_proxy(self):
        _, store = self.serve()
        self.assertTrue(store.config.proxy.startswith("http://127.0.0.1:"))
        command = server_module.download_command(store.config, {"choice": "best", "url": "https://example.test/v"}, self.root)
        self.assertEqual(command[command.index("--proxy") + 1], store.config.proxy)
        self.assertEqual(command[-2:], ["--", "https://example.test/v"])
        environment = server_module.downloader_environment(store.config)
        self.assertEqual({environment[name] for name in ("http_proxy", "https_proxy", "HTTPS_PROXY")}, {store.config.proxy})
        self.assertEqual(environment["no_proxy"], "")

    def test_stop_waits_for_a_downloader_that_was_starting_and_kills_it(self):
        store = self.store()
        job, _ = store.add("7", "https://example.test/slow", "best", None)
        running = store.next_job()
        spawning, release, started = threading.Event(), threading.Event(), []
        real_popen = server_module.subprocess.Popen

        def slow_popen(*args, **kwargs):
            spawning.set()  # the worker is past every check and about to start the downloader
            release.wait(10)
            started.append(real_popen(*args, **kwargs))
            return started[0]

        with mock.patch.object(server_module.subprocess, "Popen", slow_popen):
            worker = threading.Thread(target=server_module.run_job, args=(store, running), daemon=True)
            store.workers.append(worker)
            worker.start()
            self.assertTrue(spawning.wait(5))
            stopper = threading.Thread(target=store.stop, daemon=True)
            stopper.start()
            while not store.stopping:
                time.sleep(0.01)
            time.sleep(0.2)
            self.assertTrue(stopper.is_alive())  # stop() saw no process to kill, and still does not return
            release.set()
            stopper.join(15)
        self.assertFalse(stopper.is_alive())
        self.assertFalse(worker.is_alive())
        self.assertIsNotNone(started[0].poll())  # the late downloader is dead before stop() returned
        self.assertEqual(store.processes, {})
        self.assertEqual(store.jobs[job["id"]]["status"], "running")  # queued again by the next start

    def test_cleanup_never_removes_a_download_that_started_while_it_was_looking(self):
        store = self.store()
        leftover = store.config.files / ("f" * 32)
        leftover.mkdir(parents=True)
        late = []
        real_iterdir = pathlib.Path.iterdir

        def iterdir(folder):
            if folder == store.config.files and not late:
                # Cleanup has decided what it knows; now a download is queued and its folder appears.
                late.append(store.add("7", "https://example.test/slow", "best", None)[0]["id"])
                (store.config.files / late[0]).mkdir()
            return real_iterdir(folder)

        with mock.patch.object(pathlib.Path, "iterdir", iterdir):
            store.purge_expired()
        self.assertTrue((store.config.files / late[0]).is_dir())
        self.assertFalse(leftover.exists())

    def test_ffmpeg_only_ever_opens_local_files(self):
        # A manifest can name key or segment addresses ffmpeg would open itself, around the proxy.
        tools = self.root / "tools"
        tools.mkdir()
        for name in ("ffmpeg", "ffprobe"):
            (tools / name).write_text("#!/bin/sh\nprintf '%s\\n' \"$@\"\n")
            (tools / name).chmod(0o755)
        config = self.config(VIDEO_FFMPEG_DIR=tools)
        with mock.patch.object(server_module, "SANDBOX_EXEC", "/nonexistent/sandbox-exec"):  # argument rewriting only
            folder = pathlib.Path(server_module.install_ffmpeg_wrappers(config))
        self.assertEqual(folder, config.data / "bin")
        local = server_module.LOCAL_PROTOCOLS
        self.assertNotRegex(local, "http|tcp|tls|udp|rtmp")
        run = lambda name, *args: subprocess.run([str(folder / name), *args], capture_output=True, text=True, check=True).stdout.split("\n")[:-1]  # noqa: E731
        self.assertEqual(
            run("ffmpeg", "-y", "-protocol_whitelist", "file,http,https,tcp,tls", "-i", "https://example.test/live.m3u8", "-i", "file:a.m4a", "-c", "copy", "out.mp4"),
            ["-y", "-protocol_whitelist", local, "-i", "https://example.test/live.m3u8", "-protocol_whitelist", local, "-i", "file:a.m4a", "-c", "copy", "out.mp4"])
        self.assertEqual(run("ffmpeg", "-bsfs"), ["-bsfs"])
        self.assertEqual(run("ffprobe", "-show_streams", "file:a.mp4"), ["-protocol_whitelist", local, "-show_streams", "file:a.mp4"])
        self.assertEqual(run("ffprobe", "-v", "verbose", "http://127.0.0.1:9/x.ts")[:2], ["-protocol_whitelist", local])
        self.assertEqual(run("ffprobe", "-bsfs"), ["-bsfs"])
        _, store = self.serve(VIDEO_FFMPEG_DIR=tools)
        command = server_module.download_command(store.config, {"choice": "best", "url": "https://example.test/v"}, self.root)
        self.assertEqual(command[command.index("--ffmpeg-location") + 1], str(store.config.data / "bin"))

    @unittest.skipUnless(pathlib.Path(server_module.SANDBOX_EXEC).exists(), "needs the macOS sandbox")
    def test_ffmpeg_cannot_open_a_connection_even_when_its_own_options_allow_it(self):
        # Stands in for ffmpeg told by a concat or playlist file to use the network: it simply tries to connect.
        listener = socket.socket()
        self.addCleanup(listener.close)
        listener.bind(("127.0.0.1", 0))
        listener.listen(1)
        listener.settimeout(0.5)
        tools = self.root / "real"
        tools.mkdir()
        (tools / "ffmpeg").write_text(
            "#!{}\nimport socket, sys\ntry:\n    socket.create_connection(('127.0.0.1', {}), timeout=2)\n    print('connected')\n"
            "except OSError:\n    print('refused')\n".format(sys.executable, listener.getsockname()[1]))
        (tools / "ffmpeg").chmod(0o755)
        self.assertEqual(subprocess.run([str(tools / "ffmpeg")], capture_output=True, text=True).stdout, "connected\n")
        listener.accept()[0].close()
        folder = server_module.install_ffmpeg_wrappers(self.config(VIDEO_FFMPEG_DIR=tools))
        self.assertEqual(subprocess.run([folder + "/ffmpeg", "-i", "pipe:0"], capture_output=True, text=True).stdout, "refused\n")
        with self.assertRaises(socket.timeout):
            listener.accept()

    def test_probe_lists_sizes_largest_first_then_audio(self):
        port, _ = self.serve()
        response, data = request(port, "POST", "/probe", {"url": "https://example.test/watch"})
        self.assertEqual(response.status, 200)
        found = json.loads(data)
        self.assertEqual(found["title"], "Homecoming highlights")
        self.assertEqual([c["id"] for c in found["choices"]], ["v1080", "v360", "mp3"])
        self.assertEqual([c["size"] for c in found["choices"]], [10000, 3000, 1000])

        response, data = request(port, "POST", "/probe", {"url": "https://example.test/missing"})
        self.assertEqual(response.status, 422)
        self.assertNotIn("https://", json.loads(data)["message"])

    def test_a_download_runs_and_only_its_owner_can_fetch_it(self):
        port, store = self.serve()
        response, data = request(port, "POST", "/jobs", {"owner": 7, "url": "https://example.test/watch", "choice": "v360", "title": "Homecoming"})
        self.assertEqual(response.status, 201)
        job = self.wait_for(port, 7, json.loads(data)["job"]["id"], ("done", "failed"))
        self.assertEqual((job["status"], job["percent"], job["size"], job["filename"]), ("done", 100, 100, "Clip one.mp4"))
        self.assertGreater(job["expires_at"], time.time())

        response, body = request(port, "GET", "/files/{}?owner=7".format(job["id"]))
        self.assertEqual((response.status, len(body)), (200, 100))
        self.assertIn('attachment; filename="Clip one.mp4"', response.getheader("Content-Disposition"))
        response, body = request(port, "GET", "/files/{}?owner=7".format(job["id"]), headers={"Range": "bytes=10-19"})
        self.assertEqual((response.status, body, response.getheader("Content-Range")), (206, b"0123456789", "bytes 10-19/100"))

        for path in ("/files/{}?owner=8", "/jobs/{}?owner=8"):
            self.assertEqual(request(port, "GET", path.format(job["id"]))[0].status, 404)
        self.assertEqual(request(port, "DELETE", "/jobs/{}?owner=8".format(job["id"]))[0].status, 404)
        self.assertEqual(json.loads(request(port, "GET", "/jobs?owner=8")[1])["jobs"], [])

        self.assertEqual(request(port, "DELETE", "/jobs/{}?owner=7".format(job["id"]))[0].status, 200)
        self.assertFalse((store.config.files / job["id"]).exists())

    def test_a_failed_download_says_why_and_a_running_one_can_be_cancelled(self):
        port, store = self.serve(VIDEO_WORKERS=1)
        _, data = request(port, "POST", "/jobs", {"owner": 7, "url": "https://example.test/fail", "choice": "best"})
        failed = self.wait_for(port, 7, json.loads(data)["job"]["id"], ("done", "failed"))
        self.assertEqual((failed["status"], failed["error"]), ("failed", "Video unavailable"))

        _, data = request(port, "POST", "/jobs", {"owner": 7, "url": "https://example.test/slow", "choice": "best"})
        slow = json.loads(data)["job"]["id"]
        _, data = request(port, "POST", "/jobs", {"owner": 9, "url": "https://example.test/watch", "choice": "mp3"})
        waiting = json.loads(data)["job"]["id"]
        self.wait_for(port, 7, slow, ("running",))
        self.assertEqual(self.wait_for(port, 9, waiting, ("queued",))["position"], 1)
        # Cancelling the stuck download frees the single worker for the next person.
        self.assertEqual(request(port, "DELETE", "/jobs/{}?owner=7".format(slow))[0].status, 200)
        self.assertEqual(self.wait_for(port, 9, waiting, ("done", "failed"))["filename"], "Clip one.mp3")

    def test_bad_requests_are_refused(self):
        port, _ = self.serve()
        for body in ({"owner": 7, "url": "file:///etc/passwd", "choice": "best"}, {"owner": 7, "url": "https://example.test/x", "choice": "--exec"}):
            self.assertEqual(request(port, "POST", "/jobs", body)[0].status, 422)
        self.assertEqual(request(port, "POST", "/jobs", {"owner": "7; x", "url": "https://example.test/x", "choice": "best"})[0].status, 400)
        self.assertEqual(request(port, "GET", "/jobs")[0].status, 400)
        self.assertEqual(request(port, "GET", "/files/not-an-id?owner=7")[0].status, 404)
        health = json.loads(request(port, "GET", "/health")[1])
        self.assertEqual((health["ok"], health["ytdlp"]), (True, "2026.01.01"))


class Progress(VideoServerCase):
    def partway(self, port, path, downloaded):
        """Start a download whose stand-in pauses after its last progress line; return the job as the queue shows it
        once it reports `downloaded` bytes."""
        _, data = request(port, "POST", "/jobs", {"owner": 7, "url": "https://example.test/" + path, "choice": "v360"})
        job_id = json.loads(data)["job"]["id"]
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            job = json.loads(request(port, "GET", "/jobs/{}?owner=7".format(job_id))[1])["job"]
            if job["downloaded"] == downloaded:
                return job
            time.sleep(0.02)
        self.fail("the download never reported {} bytes: {}".format(downloaded, job))

    def measure(self, path, downloaded):
        port, _ = self.serve()
        job = self.partway(port, path, downloaded)
        self.gate.touch()
        done = self.wait_for(port, 7, job["id"], ("done", "failed"))
        self.assertEqual((done["status"], done["percent"], done["part"], done["parts"]), ("done", 100, None, None))
        return job["percent"], job["downloaded"], job["total"], job["eta"], job["part"], job["parts"]

    def test_the_plan_names_each_stream_or_admits_it_cannot(self):
        plan = server_module.parse_plan
        # The first line is from a real download; the rest are what yt-dlp's template evaluator prints for each mix.
        self.assertEqual(plan('MSPLAN|[{"format_id": "394", "filesize": 4639733, "filesize_approx": 4639708}, '
                              '{"format_id": "258", "filesize": 30767611, "filesize_approx": 30767602}]|35407344'),
                         [4639733, 30767611])
        self.assertEqual(plan('MSPLAN|[{"format_id": "137", "filesize_approx": 300}, {"format_id": "140", "filesize": 100}]|NA'), [300, 100])
        self.assertEqual(plan('MSPLAN|[{"format_id": "137"}, {"format_id": "140", "filesize": 100}]|NA'), [None, 100])
        self.assertEqual(plan('MSPLAN|[{"format_id": "137"}, {"format_id": "140"}]|NA'), [None, None])
        self.assertEqual(plan('MSPLAN|[{"format_id": "a|b", "filesize": 7}]|NA'), [7])
        self.assertEqual(plan("MSPLAN|[]|28526904"), [28526904])  # one file, as yt-dlp prints it
        self.assertEqual(plan("MSPLAN|NA|28526904"), [28526904])
        self.assertEqual(plan("MSPLAN|[]|NA"), [])
        self.assertEqual(plan("MSPLAN|garbage"), [])

    def test_progress_is_measured_against_the_whole_file_when_every_stream_has_a_size(self):
        # 300 bytes of video are in and 50 of the 100 bytes of sound: 350 of 400, not "50%" of the sound alone.
        self.assertEqual(self.measure("parts", 350), (87, 350, 400, 5.0, None, None))

    def test_an_estimated_size_counts_and_keeps_its_stream(self):
        # The video has only an estimate, the sound an exact size: still two streams, 300 then 100.
        self.assertEqual(self.measure("mixed", 350), (87, 350, 400, 5.0, None, None))

    def test_without_sizes_the_measure_is_the_stream_in_hand_and_says_which(self):
        percent, downloaded, total, _eta, part, parts = self.measure("unsized", 50)
        self.assertEqual((percent, downloaded, total, part, parts), (50, 50, 100, 2, 2))

    def test_one_stream_without_a_size_is_enough_to_measure_by_stream(self):
        # The whole cannot be measured, but how many streams there are is still known.
        percent, downloaded, total, _eta, part, parts = self.measure("halfsized", 50)
        self.assertEqual((percent, downloaded, total, part, parts), (50, 50, 100, 2, 2))

    def test_a_new_stream_is_told_by_its_format_not_by_the_count_going_down(self):
        # The sound's first report (200) is past the video's last count (100): 300 of 1100, not 200 of 2000.
        self.assertEqual(self.measure("jump", 300), (27, 300, 1100, 80.0, None, None))

    def test_a_count_that_starts_over_inside_one_stream_is_not_a_new_stream(self):
        # A retry: still stream one, 100 of 400.
        self.assertEqual(self.measure("reset", 100), (25, 100, 400, 30.0, None, None))

    def test_with_no_plan_and_no_format_the_count_starting_over_marks_the_second_stream(self):
        percent, downloaded, total, _eta, part, parts = self.measure("anon", 50)
        self.assertEqual((percent, downloaded, total, part, parts), (50, 50, 100, 2, None))


class LoopbackStreams(BaseHTTPRequestHandler):
    """Serves the two streams of a made-up video: 40,000 bytes of picture and 9,000 of sound."""

    def do_GET(self):
        body = b"\0" * (40000 if "/v." in self.path else 9000)
        self.send_response(200)
        self.send_header("Content-Type", "application/octet-stream")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


@unittest.skipUnless(shutil.which("yt-dlp"), "yt-dlp is not installed here")
class RealDownloader(unittest.TestCase):
    """The plan and progress templates are an agreement with yt-dlp, and the stand-in above cannot break it. So where
    yt-dlp is installed it evaluates both itself, on a made-up video served over loopback: no network, no ffmpeg."""

    def lines(self, video_sizes):
        httpd = ThreadingHTTPServer(("127.0.0.1", 0), LoopbackStreams)
        self.addCleanup(httpd.server_close)
        self.addCleanup(httpd.shutdown)
        threading.Thread(target=httpd.serve_forever, daemon=True).start()
        base = "http://127.0.0.1:{}".format(httpd.server_address[1])
        folder = pathlib.Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, folder, True)
        (folder / "info.json").write_text(json.dumps({
            "id": "x", "title": "t", "extractor": "generic", "extractor_key": "Generic", "webpage_url": base + "/x", "formats": [
                {"format_id": "v", "url": base + "/v.mp4", "ext": "mp4", "vcodec": "avc1", "acodec": "none", "height": 480, "protocol": "http", **video_sizes},
                {"format_id": "a", "url": base + "/a.m4a", "ext": "m4a", "vcodec": "none", "acodec": "mp4a", "protocol": "http", "filesize": 9000},
            ]}))
        # The service's own command line, less the link and the limits; a missing ffmpeg leaves the streams unmerged.
        done = subprocess.run([
            shutil.which("yt-dlp"), "--ignore-config", "--load-info-json", str(folder / "info.json"), "-f", "bv*+ba/b",
            "--no-warnings", "--newline", "--no-quiet", "--progress", "--progress-template", server_module.PROGRESS_TEMPLATE,
            "--print", server_module.PLAN_TEMPLATE, "--no-simulate", "--ffmpeg-location", str(folder / "none"),
            "-o", str(folder / "out.%(ext)s"),
        ], capture_output=True, text=True, timeout=60)
        self.assertEqual(done.returncode, 0, done.stderr)
        return done.stdout.splitlines()

    def plan(self, lines):
        return server_module.parse_plan(next(line for line in lines if line.startswith(server_module.PLAN_PREFIX)))

    def test_yt_dlp_prints_one_size_per_stream_and_names_the_stream_in_every_progress_line(self):
        lines = self.lines({"filesize_approx": 40000})  # the picture has only an estimate, the sound an exact size
        self.assertEqual(self.plan(lines), [40000, 9000])
        progress = [line[len(server_module.PROGRESS_PREFIX):].split("|", 5) for line in lines if line.startswith(server_module.PROGRESS_PREFIX)]
        self.assertTrue(progress)
        self.assertEqual({len(fields) for fields in progress}, {6})
        self.assertEqual(list(dict.fromkeys(fields[5] for fields in progress)), ["v", "a"])

    def test_a_stream_with_no_size_keeps_its_place_in_what_yt_dlp_prints(self):
        self.assertEqual(self.plan(self.lines({})), [None, 9000])


class FakeClop(BaseHTTPRequestHandler):
    """Stands in for the Clop bridge: `mode` picks the answer; the body it was sent is kept for the test to read."""

    mode = "half"
    received = []

    def log_message(self, *_args):
        return

    def _send(self, status, body, headers=None):
        self.send_response(status)
        for key, value in (headers or {}).items():
            self.send_header(key, value)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):  # noqa: N802
        self._send(200, b'{"ok":true}')

    def do_POST(self):  # noqa: N802
        body = self.rfile.read(int(self.headers["Content-Length"]))
        type(self).received.append((self.path, self.headers["X-Clop-Filename"], body))
        if self.mode == "none":
            self._send(200, b'{"outcome":"no_reduction"}', {"X-Clop-Outcome": "no_reduction"})
        elif self.mode == "fail":
            self._send(422, b'{"error":"optimize_failed"}')
        elif self.mode == "short":
            # Promises half the file, sends ten bytes, then drops the connection.
            self.send_response(200)
            self.send_header("X-Clop-Extension", "mp4")
            self.send_header("Content-Length", str(len(body) // 2))
            self.end_headers()
            self.wfile.write(body[:10])
            self.wfile.flush()
            self.close_connection = True
        elif self.mode == "held":
            self.release.wait(10)
            self._send(200, body[: len(body) // 2], {"X-Clop-Extension": "mp4"})
        else:
            self._send(200, body[: len(body) // 2], {"X-Clop-Extension": "mp4"})


class MakeSmaller(VideoServerCase):
    def clop(self, mode="half"):
        handler = type("Clop", (FakeClop,), {"mode": mode, "received": [], "release": threading.Event()})
        server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(lambda: (server.shutdown(), server.server_close()))
        return handler, "http://127.0.0.1:{}".format(server.server_address[1])

    def download(self, port, choice="v360", **extra):
        _, data = request(port, "POST", "/jobs", {"owner": 7, "url": "https://example.test/watch", "choice": choice, **extra})
        return self.wait_for(port, 7, json.loads(data)["job"]["id"], ("done", "failed"))

    def test_a_ticked_download_is_made_smaller_before_it_is_ready(self):
        clop, url = self.clop()
        port, _ = self.serve(VIDEO_OPTIMIZER_API=url)
        self.assertTrue(json.loads(request(port, "GET", "/jobs?owner=7")[1])["optimize"])
        job = self.download(port, optimize=True)
        self.assertEqual((job["size"], job["saved"], job["optimized"], job["can_optimize"]), (50, 50, "smaller", False))
        self.assertEqual(len(request(port, "GET", "/files/{}?owner=7".format(job["id"]))[1]), 50)
        path, filename, body = clop.received[0]
        self.assertEqual((filename, len(body)), ("video.mp4", 100))
        self.assertEqual(path, "/optimize?type=video&compression=75&encoder=software")
        # Audio is never sent: Clop's video mode would not take it.
        audio = self.download(port, choice="mp3", optimize=True)
        self.assertEqual((audio["size"], audio["optimized"], audio["can_optimize"], len(clop.received)), (100, None, False, 1))

    def test_a_video_over_the_limit_is_kept_as_it_is_and_says_so(self):
        clop, url = self.clop()
        port, _ = self.serve(VIDEO_OPTIMIZER_API=url, VIDEO_OPTIMIZE_MAX_BYTES=99)  # the stand-in's file is 100 bytes
        listing = json.loads(request(port, "GET", "/jobs?owner=7")[1])
        self.assertEqual((listing["optimize"], listing["optimize_max"]), (True, 99))
        ticked = self.download(port, optimize=True)
        self.assertEqual((ticked["status"], ticked["size"], ticked["optimized"], ticked["can_optimize"]), ("done", 100, "too_large", False))
        plain = self.download(port)
        self.assertEqual((plain["optimized"], plain["can_optimize"]), (None, False))
        for job in (ticked, plain):
            response, data = request(port, "POST", "/jobs/{}/optimize?owner=7".format(job["id"]))
            self.assertEqual(response.status, 422)
            self.assertIn("over 99 B", json.loads(data)["message"])
        self.assertEqual(clop.received, [])
        self.assertEqual(len(request(port, "GET", "/files/{}?owner=7".format(ticked["id"]))[1]), 100)

    def test_a_video_exactly_at_the_limit_is_still_made_smaller(self):
        clop, url = self.clop()
        port, _ = self.serve(VIDEO_OPTIMIZER_API=url, VIDEO_OPTIMIZE_MAX_BYTES=100)
        job = self.download(port, optimize=True)
        self.assertEqual((job["size"], job["optimized"], len(clop.received)), (50, "smaller", 1))
        self.assertEqual(server_module.ServerConfig({}).optimize_max_bytes, 250 * 1024 * 1024)

    def test_files_take_turns_and_the_clock_starts_when_one_is_handed_over(self):
        clop, url = self.clop("held")
        port, _ = self.serve(VIDEO_OPTIMIZER_API=url)
        first, second = self.download(port), self.download(port)
        for job in (first, second):
            self.assertEqual(request(port, "POST", "/jobs/{}/optimize?owner=7".format(job["id"]))[0].status, 202)
        deadline = time.monotonic() + 5
        while not clop.received and time.monotonic() < deadline:
            time.sleep(0.02)
        time.sleep(0.2)  # long enough for the second file, were it not waiting its turn, to reach the bridge
        self.assertEqual(len(clop.received), 1)
        views = [json.loads(request(port, "GET", "/jobs/{}?owner=7".format(job["id"]))[1])["job"] for job in (first, second)]
        self.assertEqual([view["status"] for view in views], ["optimizing", "optimizing"])
        self.assertAlmostEqual(views[0]["optimize_started_at"], time.time(), delta=10)
        self.assertIsNone(views[1]["optimize_started_at"])
        clop.release.set()
        self.assertEqual([self.wait_for(port, 7, job["id"], ("done",))["size"] for job in (first, second)], [50, 50])
        self.assertEqual(len(clop.received), 2)

    def test_a_finished_download_can_be_made_smaller_once_and_only_by_its_owner(self):
        _, url = self.clop()
        port, _ = self.serve(VIDEO_OPTIMIZER_API=url)
        job = self.download(port)
        self.assertEqual((job["size"], job["can_optimize"]), (100, True))
        self.assertEqual(request(port, "POST", "/jobs/{}/optimize?owner=8".format(job["id"]))[0].status, 404)
        self.assertEqual(request(port, "POST", "/jobs/{}/optimize?owner=7".format(job["id"]))[0].status, 202)
        job = self.wait_for(port, 7, job["id"], ("done",))
        self.assertEqual((job["size"], job["saved"], job["can_optimize"]), (50, 50, False))
        self.assertEqual(request(port, "POST", "/jobs/{}/optimize?owner=7".format(job["id"]))[0].status, 409)

    def test_the_original_is_kept_when_it_cannot_be_made_smaller(self):
        for mode, outcome, again in (("none", "none", False), ("fail", "failed", True)):
            with self.subTest(mode=mode):
                _, url = self.clop(mode)
                port, _ = self.serve(VIDEO_OPTIMIZER_API=url, VIDEO_DATA=self.root / mode)
                job = self.download(port, optimize=True)
                self.assertEqual((job["status"], job["size"], job["saved"], job["optimized"], job["can_optimize"]),
                                 ("done", 100, None, outcome, again))
                self.assertEqual(len(request(port, "GET", "/files/{}?owner=7".format(job["id"]))[1]), 100)

    def test_a_cut_off_answer_never_replaces_the_original(self):
        _, url = self.clop("short")
        port, _ = self.serve(VIDEO_OPTIMIZER_API=url)
        job = self.download(port, optimize=True)
        self.assertEqual((job["status"], job["size"], job["optimized"], job["can_optimize"]), ("done", 100, "failed", True))
        self.assertEqual(request(port, "GET", "/files/{}?owner=7".format(job["id"]))[1], b"0123456789" * 10)

    def test_asking_twice_starts_one_optimizer_and_the_file_is_whole_until_it_is_done(self):
        clop, url = self.clop("held")
        port, store = self.serve(VIDEO_OPTIMIZER_API=url)
        job = self.download(port)
        path = "/jobs/{}/optimize?owner=7".format(job["id"])
        self.assertEqual([request(port, "POST", path)[0].status for _ in range(3)], [202, 202, 202])
        deadline = time.monotonic() + 5
        while not clop.received and time.monotonic() < deadline:
            time.sleep(0.02)
        time.sleep(0.2)  # long enough for a second optimizer, had one been started, to reach the bridge
        self.assertEqual(len(clop.received), 1)
        self.assertEqual((store.config.files / job["id"] / "Clip: one.mp4").read_bytes(), b"0123456789" * 10)
        self.assertEqual(request(port, "GET", "/files/{}?owner=7".format(job["id"]))[0].status, 409)
        clop.release.set()
        self.assertEqual(self.wait_for(port, 7, job["id"], ("done",))["size"], 50)
        self.assertEqual(len(clop.received), 1)

    def test_a_stop_just_after_the_swap_comes_back_with_the_smaller_file_recorded(self):
        port, store = self.serve()
        job = self.download(port)
        folder = store.config.files / job["id"]
        # The smaller file is in place but the service stopped before the queue was saved.
        (folder / "Clip: one.mp4").write_bytes(b"0123456789" * 4)
        (folder / ".optimizing").write_bytes(b"left over")
        store.jobs[job["id"]]["status"] = "optimizing"
        store._save()
        again = server_module.JobStore(store.config, server_module.EventLog(store.config))
        view = again.get(job["id"], "7")
        self.assertEqual((view["status"], view["size"], view["saved"], view["optimized"]), ("done", 40, 60, "smaller"))
        self.assertEqual(sorted(p.name for p in folder.iterdir()), ["Clip: one.mp4"])

    def test_a_stop_around_a_swap_to_another_file_type_leaves_one_readable_file(self):
        port, store = self.serve()
        for recorded, kept in (("Clip: one.mp4", 100), ("Clip: one.mov", 40)):
            with self.subTest(recorded=recorded):
                job = self.download(port)
                folder = store.config.files / job["id"]
                # Both files exist: the stop came either just before or just after the queue named the new one.
                (folder / "Clip: one.mov").write_bytes(b"0123456789" * 4)
                store.jobs[job["id"]].update(stored=recorded, size=kept, status="optimizing" if recorded.endswith("mp4") else "done")
                store._save()
                again = server_module.JobStore(store.config, server_module.EventLog(store.config))
                self.assertEqual(again.file(job["id"], "7")[0].stat().st_size, kept)
                self.assertEqual([p.name for p in folder.iterdir()], [recorded])
                request(port, "DELETE", "/jobs/{}?owner=7".format(job["id"]))

    def test_without_the_optimizer_the_option_is_off_and_a_restart_keeps_the_file(self):
        port, store = self.serve()
        self.assertFalse(json.loads(request(port, "GET", "/jobs?owner=7")[1])["optimize"])
        job = self.download(port, optimize=True)
        self.assertEqual((job["size"], job["optimized"], job["can_optimize"]), (100, None, False))
        response, data = request(port, "POST", "/jobs/{}/optimize?owner=7".format(job["id"]))
        self.assertEqual(response.status, 503)
        # A service stopped while a file was being made smaller comes back with the original, ready to save.
        store.jobs[job["id"]]["status"] = "optimizing"
        store._save()
        again = server_module.JobStore(store.config, server_module.EventLog(store.config))
        self.assertEqual(again.get(job["id"], "7")["status"], "done")
        self.assertEqual(again.file(job["id"], "7")[0].stat().st_size, 100)


if __name__ == "__main__":
    unittest.main()

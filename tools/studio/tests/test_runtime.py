from __future__ import annotations

import concurrent.futures
import contextlib
import dataclasses
import hashlib
import importlib.util
import io
import json
import os
import re
import pathlib
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest import mock
import urllib.error


RUNTIME_PATH = pathlib.Path(__file__).parents[1] / "runtime.py"
LOOPER_DOCKERFILE = pathlib.Path(__file__).parents[1] / "looper.Dockerfile"
SPEC = importlib.util.spec_from_file_location("studio_runtime", RUNTIME_PATH)
runtime = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = runtime
SPEC.loader.exec_module(runtime)
REPO_NGINX = pathlib.Path(__file__).resolve().parents[3] / "dockerfiles" / "nginx.conf"


def nginx_locations(config):
    """The server block's top-level locations in file order, as (modifier, pattern, body)."""
    import re

    locations, depth, current = [], 0, None
    for line in config.splitlines():
        code = re.sub(r'"[^"]*"', '""', line.split("#", 1)[0])
        match = re.match(r'\s*location\s+(=|\^~|~\*|~)?\s*("[^"]*"|\S+)\s*\{', line)
        if match and depth == 1:
            current = [match.group(1) or "", match.group(2).strip('"'), line[match.end():] + "\n"]
            locations.append(current)
        elif current is not None:
            current[2] += line + "\n"
        depth += code.count("{") - code.count("}")
        if depth <= 1:
            current = None
    return [tuple(location) for location in locations]


def nginx_select(locations, uri):
    """nginx's choice: an exact `=` match, else the longest prefix when it is `^~`, else the
    first regex that matches in file order, else that longest prefix."""
    import re

    for location in locations:
        if location[0] == "=" and location[1] == uri:
            return location
    prefixes = [location for location in locations if location[0] in ("", "^~") and uri.startswith(location[1])]
    longest = max(prefixes, key=lambda location: len(location[1]), default=None)
    if longest is not None and longest[0] == "^~":
        return longest
    for location in locations:
        if location[0] in ("~", "~*") and re.search(location[1], uri, re.IGNORECASE if location[0] == "~*" else 0):
            return location
    return longest


class RecordingRunner:
    def __init__(self, inspections):
        self.inspections = inspections
        self.calls = []
        self.redactions = []

    def add_redactions(self, values):
        self.redactions.extend(values)

    def list_container_names(self):
        return set(self.inspections)

    def inspect(self, name):
        return self.inspections[name]

    def inspect_volume(self, name):
        return self.inspections[name]

    def inspect_network(self, name):
        return self.inspections[name]

    def run(self, args, **kwargs):
        self.calls.append(tuple(args))
        return ""


class RecordingRelay:
    def __init__(self):
        self.calls = []

    def start(self, target_host):
        self.calls.append(("start", target_host))

    def stop(self):
        self.calls.append(("stop",))

    def require(self, target_host):
        self.calls.append(("require", target_host))

    def describe(self):
        return "running"


def owned_inspect(state="running", config_digest=None):
    labels = {runtime.OWNER_LABEL: runtime.OWNER_VALUE}
    if config_digest is not None:
        labels[runtime.CONFIG_LABEL] = config_digest
    return [
        {
            "configuration": {
                "labels": labels,
            },
            "status": {"state": state},
        }
    ]


class RuntimeContractTest(unittest.TestCase):
    def make_config(self, root, mailpit_port=None, web_transport="native-publish"):
        return runtime.RuntimeConfig(
            source=pathlib.Path(root) / "source",
            state=pathlib.Path(root) / "state",
            web_port=18090,
            mailpit_port=mailpit_port,
            container_bin="/opt/homebrew/bin/container",
            host_uid=os.getuid(),
            host_gid=os.getgid(),
            web_transport=web_transport,
        )

    def test_plan_orders_dependencies_before_application(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            specs = stack.plan(
                {
                    "postgres": "192.168.64.10",
                    "redis": "192.168.64.11",
                    "elasticsearch": "192.168.64.12",
                    "mailpit": "192.168.64.13",
                    "looper": "192.168.64.14",
                    "fpm": "192.168.64.15",
                }
            )

        self.assertEqual(
            [spec.role for spec in specs],
            [
                "postgres",
                "redis",
                "elasticsearch",
                "mailpit",
                "looper",
                "fpm",
                "queue",
                "panorama_queue",
                "stylepress_queue",
                "vectorization_queue",
                "ai_index_queue",
                "scheduler",
                "nginx",
            ],
        )
        postgres = specs[0]
        self.assertIn("PGDATA=/var/lib/postgresql/data/pgdata", postgres.env)
        self.assertEqual(
            postgres.named_volumes,
            ((runtime.VOLUME_NAMES["postgres"], "/var/lib/postgresql/data"),),
        )

    def plan_application_roles(self, root):
        specs = runtime.Stack(self.make_config(root), runner=RecordingRunner({})).plan(
            {
                "postgres": "192.168.64.10",
                "redis": "192.168.64.11",
                "elasticsearch": "192.168.64.12",
                "mailpit": "192.168.64.13",
                "looper": "192.168.64.14",
                "fpm": "192.168.64.15",
            }
        )
        by_role = {spec.role: spec for spec in specs}
        return [by_role[role] for role in ("fpm", "queue", "vectorization_queue", "ai_index_queue", "scheduler")]

    def write_state_env(self, root, body):
        state = pathlib.Path(root) / "state"
        state.mkdir(parents=True, exist_ok=True)
        env_file = state / "app.env"
        env_file.write_text(body, encoding="utf-8")
        env_file.chmod(0o600)
        return env_file

    def test_plan_pins_loopback_app_url_when_state_env_is_absent(self):
        with tempfile.TemporaryDirectory() as root:
            for spec in self.plan_application_roles(root):
                self.assertIn("APP_URL=http://127.0.0.1:18090", spec.env, spec.role)
                self.assertIn("TRUSTED_PROXIES=127.0.0.1", spec.env, spec.role)

    def test_plan_honours_public_app_url_and_trusted_proxies_from_state_env(self):
        with tempfile.TemporaryDirectory() as root:
            self.write_state_env(
                root,
                "APP_KEY=base64:unused\n"
                "APP_URL=https://mothership.example.com\n"
                "TRUSTED_PROXIES=*\n",
            )
            for spec in self.plan_application_roles(root):
                self.assertIn("APP_URL=https://mothership.example.com", spec.env, spec.role)
                self.assertIn("TRUSTED_PROXIES=*", spec.env, spec.role)
                self.assertNotIn("APP_URL=http://127.0.0.1:18090", spec.env, spec.role)
                self.assertNotIn("TRUSTED_PROXIES=127.0.0.1", spec.env, spec.role)

    def test_plan_falls_back_to_loopback_when_state_env_values_are_blank(self):
        with tempfile.TemporaryDirectory() as root:
            self.write_state_env(root, 'APP_URL=""\nTRUSTED_PROXIES=   \n')
            for spec in self.plan_application_roles(root):
                self.assertIn("APP_URL=http://127.0.0.1:18090", spec.env, spec.role)
                self.assertIn("TRUSTED_PROXIES=127.0.0.1", spec.env, spec.role)

    def test_public_app_url_changes_the_worker_config_digest(self):
        with tempfile.TemporaryDirectory() as root:
            before = self.plan_application_roles(root)[1].digest()
            self.write_state_env(root, "APP_URL=https://mothership.example.com\n")
            after = self.plan_application_roles(root)[1].digest()
        self.assertNotEqual(before, after)

    def test_build_commit_meta_must_match_the_fpm_image_tag(self):
        commit = "c0bbe1976fe85dc75729b70ed7de7b0737b7826e"
        body = '<meta name="mothership-build-commit" content="{}">'.format(commit)
        self.assertEqual(
            runtime.check_build_commit(body=body, fpm_image="mothership-fpm:c0bbe1976fe8"),
            ("match", commit),
        )
        self.assertEqual(
            runtime.check_build_commit(body=body, fpm_image="mothership-fpm:64f58fd7a1b2"),
            ("mismatch", commit),
        )
        # An image built before the build argument existed names no commit; verify warns but accepts it.
        unknown = '<meta name="mothership-build-commit" content="unknown">'
        self.assertEqual(
            runtime.check_build_commit(body=unknown, fpm_image="mothership-fpm:c0bbe1976fe8"),
            ("unknown", "unknown"),
        )
        self.assertEqual(
            runtime.check_build_commit(body="<html></html>", fpm_image="mothership-fpm:c0bbe1976fe8"),
            ("unknown", "unknown"),
        )

    def test_static_urls_accept_local_and_relative_references(self):
        body = (
            '<link href="/themes/admin/app.css">'
            '<script src="http://127.0.0.1:18090/themes/admin/app.js"></script>'
        )
        self.assertEqual(
            runtime.select_static_urls(
                body=body,
                page_url='http://127.0.0.1:18090/admin/login',
                loopback_netloc='127.0.0.1:18090',
            ),
            [
                'http://127.0.0.1:18090/themes/admin/app.css',
                'http://127.0.0.1:18090/themes/admin/app.js',
            ],
        )

    def test_static_urls_accept_configured_public_origin_and_fetch_via_loopback(self):
        body = (
            '<link href="https://mothership.example.com/themes/admin/app.css">'
            '<script src="https://mothership.example.com/themes/admin/app.js"></script>'
        )
        self.assertEqual(
            runtime.select_static_urls(
                body=body,
                page_url='http://127.0.0.1:18090/admin/login',
                loopback_netloc='127.0.0.1:18090',
                extra_netlocs=('mothership.example.com',),
            ),
            [
                'http://127.0.0.1:18090/themes/admin/app.css',
                'http://127.0.0.1:18090/themes/admin/app.js',
            ],
        )

    def test_static_urls_reject_foreign_origins(self):
        body = (
            '<link href="https://cdn.example.net/vendor/app.css">'
            '<script src="https://attacker.invalid/evil.js"></script>'
        )
        self.assertEqual(
            runtime.select_static_urls(
                body=body,
                page_url='http://127.0.0.1:18090/admin/login',
                loopback_netloc='127.0.0.1:18090',
                extra_netlocs=('mothership.example.com',),
            ),
            [],
        )

    def test_static_urls_ignore_non_asset_suffixes_and_deduplicate(self):
        body = (
            '<a href="https://mothership.example.com/admin/dam">library</a>'
            '<link href="/themes/admin/app.css">'
            '<link href="https://mothership.example.com/themes/admin/app.css">'
        )
        self.assertEqual(
            runtime.select_static_urls(
                body=body,
                page_url='http://127.0.0.1:18090/admin/login',
                loopback_netloc='127.0.0.1:18090',
                extra_netlocs=('mothership.example.com',),
            ),
            ['http://127.0.0.1:18090/themes/admin/app.css'],
        )

    def test_feature_workers_match_queue_connections_commands_and_caps(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            specs = runtime.Stack(config, runner=RecordingRunner({})).plan(
                {
                    "postgres": "192.168.64.10",
                    "redis": "192.168.64.11",
                    "elasticsearch": "192.168.64.12",
                    "mailpit": "192.168.64.13",
                    "looper": "192.168.64.14",
                    "fpm": "192.168.64.15",
                }
            )

        by_role = {spec.role: spec for spec in specs}
        self.assertEqual(by_role["queue"].image, config.queue_image)
        self.assertIn(
            "QUEUE_NAMES=webhooks,system,completeness,default,broadcastable",
            by_role["queue"].env,
        )
        self.assertIn("STUDIO_WORKER_COUNT=2", by_role["queue"].env)
        self.assertIn("QUEUE_WORKER_CONNECTION=panorama", by_role["panorama_queue"].env)
        self.assertIn("QUEUE_WORKER_CONNECTION=vectorization", by_role["vectorization_queue"].env)
        self.assertEqual(
            by_role["queue"].command[-8:],
            (
                "artisan", "queue:work",
                "--queue=webhooks,system,completeness,default,broadcastable",
                "--timeout=90", "--tries=3", "--max-jobs=1000",
                "--max-time=3600", "--sleep=0.5",
            ),
        )
        self.assertEqual(
            by_role["vectorization_queue"].command[-8:],
            (
                "artisan", "queue:work", "vectorization", "--queue=vectorization",
                "--timeout=480", "--tries=1", "--memory=512", "--max-jobs=50",
            ),
        )
        self.assertIn("QUEUE_WORKER_CONNECTION=ai-index", by_role["ai_index_queue"].env)
        self.assertEqual(
            by_role["ai_index_queue"].command[-8:],
            (
                "artisan", "queue:work", "ai-index", "--queue=ai-index",
                "--timeout=300", "--tries=4", "--memory=512", "--max-jobs=200",
            ),
        )
        self.assertEqual(by_role["scheduler"].command[-3:], ("artisan", "schedule:work", "--no-interaction"))
        self.assertEqual(
            by_role["stylepress_queue"].command[-8:],
            (
                "artisan", "queue:work", "database", "--queue=stylepress",
                "--timeout=80", "--tries=0", "--sleep=3", "--max-time=3600",
            ),
        )
        for role in (
            "queue", "panorama_queue", "stylepress_queue", "vectorization_queue",
            "ai_index_queue", "scheduler",
        ):
            self.assertEqual(by_role[role].entrypoint, "/bin/bash")
            self.assertEqual(by_role[role].command[0], "/studio-runtime/worker-supervisor.sh")
            self.assertEqual(by_role[role].command[1], "php")
            self.assertEqual(by_role[role].user, "{}:{}".format(config.host_uid, config.host_gid))
        self.assertEqual(
            {role: (by_role[role].cpus, by_role[role].memory) for role in (
                "looper", "queue", "panorama_queue", "stylepress_queue",
                "vectorization_queue", "ai_index_queue", "scheduler",
            )},
            {
                "looper": (2, "4G"),
                "queue": (2, "2G"),
                "panorama_queue": (4, "6G"),
                "stylepress_queue": (1, "1G"),
                "vectorization_queue": (1, "1G"),
                "ai_index_queue": (1, "1G"),
                "scheduler": (1, "1G"),
            },
        )

    def test_application_roles_share_storage_and_higgsfield_session_without_source_bind(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            specs = runtime.Stack(config, runner=RecordingRunner({})).plan(
                {
                    "postgres": "192.168.64.10",
                    "redis": "192.168.64.11",
                    "elasticsearch": "192.168.64.12",
                    "mailpit": "192.168.64.13",
                    "looper": "192.168.64.14",
                    "fpm": "192.168.64.15",
                }
            )

        by_role = {spec.role: spec for spec in specs}
        application_roles = (
            "fpm", "queue", "panorama_queue", "stylepress_queue",
            "vectorization_queue", "ai_index_queue", "scheduler",
        )
        for role in application_roles:
            self.assertTrue(any(m.target == "/var/www/html/storage" for m in by_role[role].mounts), role)
            self.assertFalse(any(m.target == "/var/www/html" for m in by_role[role].mounts), role)
        for role in ("fpm", "stylepress_queue"):
            self.assertTrue(
                any(
                    m.source == config.higgsfield_session
                    and m.target == "/var/lib/mothership/.config/higgsfield"
                    and not m.readonly
                    for m in by_role[role].mounts
                ),
                role,
            )
        self.assertEqual(by_role["stylepress_queue"].workdir, "/var/www/html")
        self.assertNotIn("image_editor_queue", by_role)

    def test_looper_is_private_persistent_and_injected_into_laravel(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            specs = runtime.Stack(config, runner=RecordingRunner({})).plan(
                {
                    "postgres": "192.168.64.10",
                    "redis": "192.168.64.11",
                    "elasticsearch": "192.168.64.12",
                    "mailpit": "192.168.64.13",
                    "looper": "192.168.64.14",
                    "fpm": "192.168.64.15",
                }
            )

        by_role = {spec.role: spec for spec in specs}
        looper = by_role["looper"]
        self.assertEqual(looper.image, config.looper_image)
        self.assertEqual(looper.publishes, ())
        self.assertTrue(any(m.source == config.looper_uploads and m.target == "/app/uploads" for m in looper.mounts))
        for role in ("fpm", "queue", "scheduler"):
            self.assertIn("MOTHERSHIP_HOME_LOOPER_API=http://192.168.64.14:3457", by_role[role].env)

    def test_plan_requires_discovered_looper_ip(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            with self.assertRaisesRegex(runtime.RuntimeFailure, "looper IP"):
                runtime.Stack(config, runner=RecordingRunner({})).plan(
                    {
                        "postgres": "192.168.64.10",
                        "redis": "192.168.64.11",
                        "elasticsearch": "192.168.64.12",
                        "mailpit": "192.168.64.13",
                        "fpm": "192.168.64.15",
                    }
                )

    def test_looper_image_pins_python_requirements_and_installs_ffmpeg_for_arm_runtime(self):
        dockerfile = LOOPER_DOCKERFILE.read_text(encoding="utf-8")

        self.assertIn("tools/widgets/looper/backend/requirements.txt", dockerfile)
        self.assertIn("pip install --no-cache-dir --requirement requirements.txt", dockerfile)
        self.assertIn("apt-get install -y --no-install-recommends ffmpeg", dockerfile)
        self.assertIn("ARG HOST_UID=502", dockerfile)
        self.assertIn("ARG HOST_GID=20", dockerfile)
        self.assertIn('CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "3457"]', dockerfile)

    def test_mutable_data_does_not_change_container_digest(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            config.storage.mkdir(parents=True)
            data = config.storage / "changing.txt"
            data.write_text("one", encoding="utf-8")
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            ips = {
                "postgres": "192.168.64.10", "redis": "192.168.64.11",
                "elasticsearch": "192.168.64.12", "mailpit": "192.168.64.13",
                "looper": "192.168.64.14", "fpm": "192.168.64.15",
            }
            before = {spec.role: spec for spec in stack.plan(ips)}["queue"].digest()
            data.write_text("two", encoding="utf-8")
            after = {spec.role: spec for spec in stack.plan(ips)}["queue"].digest()

        self.assertEqual(before, after)

    def test_prepare_state_normalizes_owned_higgsfield_files(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            config.higgsfield_session.mkdir(parents=True, mode=0o700)
            credential_file = config.higgsfield_session / "fixture.json"
            credential_file.write_text("x", encoding="utf-8")
            credential_file.chmod(0o644)

            runtime.Stack(config, runner=RecordingRunner({}))._prepare_state()

            self.assertEqual(credential_file.stat().st_mode & 0o777, 0o600)

    def test_prepare_state_does_not_follow_higgsfield_symlinks(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            config.higgsfield_session.mkdir(parents=True, mode=0o700)
            outside = pathlib.Path(root) / "outside"
            outside.write_text("x", encoding="utf-8")
            outside.chmod(0o644)
            (config.higgsfield_session / "link").symlink_to(outside)

            runtime.Stack(config, runner=RecordingRunner({}))._prepare_state()

            self.assertEqual(outside.stat().st_mode & 0o777, 0o644)

    def test_configurable_runtime_identity_is_used_by_workers(self):
        with tempfile.TemporaryDirectory() as root:
            config = runtime.RuntimeConfig(
                source=pathlib.Path(root) / "source",
                state=pathlib.Path(root) / "state",
                host_uid=777,
                host_gid=888,
            )
            specs = runtime.Stack(config, runner=RecordingRunner({})).plan(
                {
                    "postgres": "192.168.64.10", "redis": "192.168.64.11",
                    "elasticsearch": "192.168.64.12", "mailpit": "192.168.64.13",
                    "looper": "192.168.64.14", "fpm": "192.168.64.15",
                }
            )

        by_role = {spec.role: spec for spec in specs}
        self.assertEqual(by_role["stylepress_queue"].user, "777:888")
        self.assertEqual(by_role["queue"].user, "777:888")

    def test_gateway_endpoint_must_match_current_private_gateway(self):
        network = runtime.NETWORK_NAME
        runner = RecordingRunner(
            {network: [{"status": {"ipv4Gateway": "192.168.65.1"}}]}
        )
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner)
            key = "MOTHERSHIP_OPTIMIZER_API"
            self.assertEqual(
                stack._gateway_endpoint({key: "http://192.168.65.1:18766"}, key),
                ("192.168.65.1", 18766),
            )
            with self.assertRaisesRegex(runtime.RuntimeFailure, "current private gateway"):
                stack._gateway_endpoint({key: "http://192.168.66.1:18766"}, key)

    def test_gateway_endpoint_rejects_non_http_and_non_ip(self):
        runner = RecordingRunner(
            {runtime.NETWORK_NAME: [{"status": {"ipv4Gateway": "192.168.65.1"}}]}
        )
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner, relay=RecordingRelay())
            key = "MOTHERSHIP_OPTIMIZER_API"
            self.assertIsNone(stack._gateway_endpoint({}, key))
            with self.assertRaisesRegex(runtime.RuntimeFailure, "http URL"):
                stack._gateway_endpoint({key: "https://192.168.65.1:18766"}, key)
            with self.assertRaisesRegex(runtime.RuntimeFailure, "IPv4"):
                stack._gateway_endpoint({key: "http://optimizer.local:18766"}, key)

    def test_verify_requires_higgsfield_credentials_for_the_subscription_driver(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=RecordingRunner({}), relay=RecordingRelay())
            config.higgsfield_session.mkdir(parents=True, mode=0o700)
            (config.higgsfield_session / "config.json").write_text("{}")
            with mock.patch("builtins.print") as output:
                stack._verify_higgsfield_session({})
            output.assert_called_once_with("higgsfield: subscription driver is not configured")
            environment = {"STYLEPRESS_HIGGSFIELD_DRIVER": "subscription"}
            with self.assertRaisesRegex(runtime.RuntimeFailure, "no credentials.json"):
                stack._verify_higgsfield_session(environment)
            credentials = config.higgsfield_session / "credentials.json"
            for rejected in ("", "{", "{}", "[]"):
                credentials.write_text(rejected)
                with self.assertRaisesRegex(runtime.RuntimeFailure, "no credentials.json"):
                    stack._verify_higgsfield_session(environment)
            credentials.write_text('{"fixture": "present"}')
            stack._verify_higgsfield_session(environment)

    def test_private_media_location_outranks_the_static_extension_regex(self):
        rendered = runtime.render_nginx("192.168.64.20")
        self.assertIn("location ^~ /__dam_media/ {", rendered)
        self.assertNotIn("location /__dam_media/ {", rendered)

    def test_private_media_location_is_not_gzipped(self):
        # gzip drops Content-Length and byte ranges: a 294 MB GLB streamed chunked and Range returned 200.
        rendered = runtime.render_nginx("192.168.64.20")
        block = rendered.split("location ^~ /__dam_media/ {", 1)[1].split("}", 1)[0]
        self.assertIn("gzip off;", block)

    def test_remote_media_location_is_internal_and_keeps_the_browser_credentials(self):
        # ADR 0017: nginx fetches a remote blob's signed URL itself; the browser's cookies never reach the store.
        rendered = runtime.render_nginx("192.168.64.20")
        self.assertIn("location ^~ /__dam_remote/ {", rendered)
        block = rendered.split("location ^~ /__dam_remote/ {", 1)[1].split("return 404;", 1)[0]
        for line in (
            "internal;",
            "proxy_set_header Host $dam_remote_host$dam_remote_port;",
            'proxy_set_header Cookie "";',
            'proxy_set_header Authorization "";',
            "proxy_buffering off;",
            "gzip off;",
            "resolver 192.168.164.1 valid=30s ipv6=off;",
            "proxy_ssl_server_name on;",
            "proxy_ssl_name $dam_remote_host;",
        ):
            with self.subTest(line=line):
                self.assertIn(line, block)

    def test_remote_media_regex_accepts_dns_ipv4_and_bracketed_ipv6_authorities(self):
        # PHP emits /__dam_remote/<scheme>/<host[:port]>/<path>; parse_url keeps an IPv6 host's brackets.
        cases = {
            "/__dam_remote/https/s3.example.app/b/k.png": ("https", "s3.example.app", "", "b/k.png"),
            "/__dam_remote/http/storage-host:9010/b/k": ("http", "storage-host", ":9010", "b/k"),
            "/__dam_remote/http/10.0.0.5:9000/b/k": ("http", "10.0.0.5", ":9000", "b/k"),
            "/__dam_remote/http/[::1]/b/k": ("http", "[::1]", "", "b/k"),
            "/__dam_remote/https/[2001:db8::5]:9000/b/k": ("https", "[2001:db8::5]", ":9000", "b/k"),
        }
        configs = {"studio": runtime.render_nginx("192.168.64.20"), "repo": REPO_NGINX.read_text()}
        for name, rendered in configs.items():
            pattern = re.search(r"location ~ (\^/__dam_remote/\S+) \{", rendered).group(1)
            for uri, expected in cases.items():
                with self.subTest(config=name, uri=uri):
                    match = re.match(pattern, uri)
                    self.assertIsNotNone(match)
                    self.assertEqual(tuple(g or "" for g in match.groups()), expected)
            for uri in ("/__dam_remote/ftp/h/k", "/__dam_remote/http/[::1/k", "/__dam_remote/http/h:x/k"):
                with self.subTest(config=name, rejected=uri):
                    self.assertIsNone(re.match(pattern, uri))

    def test_each_namespace_resolves_through_its_own_network_gateway(self):
        self.assertEqual(runtime.namespace_resolver_ip(runtime.PRODUCTION_NAMESPACE), "192.168.164.1")
        self.assertEqual(runtime.namespace_resolver_ip(runtime.STAGE_NAMESPACE), "192.168.165.1")
        stage = runtime.render_nginx(
            "192.168.165.20", resolver_ip=runtime.namespace_resolver_ip(runtime.STAGE_NAMESPACE),
        )
        self.assertIn("resolver 192.168.165.1 valid=30s ipv6=off;", stage)
        self.assertNotIn("192.168.164.1", stage)

    def test_oauth_discovery_reaches_laravel_without_exposing_other_dotfiles(self):
        locations = nginx_locations(runtime.render_nginx("192.168.64.20"))
        discovery = (
            "/.well-known/oauth-protected-resource/mcp",
            "/.well-known/oauth-authorization-server",
        )
        for uri in discovery:
            with self.subTest(uri=uri):
                chosen = nginx_select(locations, uri)
                self.assertEqual(chosen[:2], ("=", uri))
                self.assertIn("rewrite ^ /index.php last;", chosen[2])
                self.assertNotIn("try_files", chosen[2])
        for uri in (
            "/.env", "/.git/config", "/.well-known/other",
            *(path + suffix for path in discovery for suffix in ("/", "/.env", ".json")),
        ):
            with self.subTest(uri=uri):
                chosen = nginx_select(locations, uri)
                self.assertIn("deny all;", chosen[2])
        front_controller = nginx_select(locations, "/index.php")
        self.assertIn("fastcgi_pass 192.168.64.20:9000;", front_controller[2])
        self.assertIn("internal;", front_controller[2])

    def test_web_page_urls_reach_php_unlogged_in_both_nginx_configs(self):
        # A page sibling that lands on any other location either 404s from the static handler
        # (2026-09-23: every Studio preview rendered unstyled with broken images) or reaches PHP
        # with its grant or share token written to the access log.
        # Path-segment stand-ins: 24 alphanumerics fit both the grant and the share-segment shape.
        segment = "Ab" * 12
        admin = f"/admin/dam/pages/65/g/{segment}"
        share = f"/share/{segment}"
        pages = [
            f"{admin}/", admin, f"{admin}/site/_next/static/chunks/app.css", f"{admin}/site/app.js",
            f"{admin}/site/mod.mjs", f"{admin}/logo.png", f"{admin}/art.svg", f"{admin}/fonts/a.woff2",
            f"{admin}/app.wasm", f"{admin}/docs/index.html", f"{admin}/x.php", f"{admin}/.well-known/a.txt",
            f"{admin}/home/tools/app.js", f"{admin}/home/service/hero.png", f"/backoffice/dam/pages/65/g/{segment}/a.css",
            f"{share}/page", f"{share}/page/", f"{share}/page/site/a.css",
            f"{share}/page/42/a.png", f"{share}/page/home/tools/a.js",
        ]
        # The selected-member families of web-pages.php: pin = asset.attachment.file.<64 hex>, grant = id.<64 hex>.
        # A member bundle's own relative resources (flat b.css/b.js, nested, module, font, home-like) must reach PHP too.
        pin = "65.3504.4009." + "3a2b034bc7eef902b39ed374873d3ea0498536a5b93567f2fb0df100bc208d7f"
        grant = "1761350000." + "0f" * 32
        for family in (f"{share}/pf/{pin}", f"{share}/pgf/{grant}/{pin}", f"{share}/pg/{grant}"):
            pages += [family, f"{family}/", f"{family}/index.html", f"{family}/b.css", f"{family}/b.js", f"{family}/m.mjs",
                      f"{family}/site/_next/a.css", f"{family}/fonts/a.woff2", f"{family}/logo.png", f"{family}/home/tools/a.js", f"{family}/x.php"]
        others = {
            "/themes/admin/default/build/assets/app.css": "\\.(jpg|",
            "/__dam_media/bundles/65/0/a.svg": "/__dam_media/",
            "/admin/dam/pages": "/",
            f"{share}/pages": "/",
            # Malformed or undeclared member routes gain no bypass: they stay on the generic static/front-controller handlers
            # (Laravel's own route constraints would 404 them anyway).
            f"{share}/pf/65.3504.4009.{'3a' * 31}/b.css": "\\.(jpg|",       # 62-hex checksum
            f"{share}/pf/65.3504.4009.{'3A' * 32}/b.css": "\\.(jpg|",       # uppercase hex
            f"{share}/pf/65.4009.{'3a' * 32}/b.js": "\\.(jpg|",             # pin missing a segment
            f"{share}/pf/{pin}x/b.css": "\\.(jpg|",                         # trailing junk after the pin
            f"{share}/pgf/{pin}/b.css": "\\.(jpg|",                         # pgf without its grant
            f"{share}/pg/{pin}/b.css": "\\.(jpg|",                          # pg takes a grant, not a pin
            f"{share}/pf/{pin}x": "/",
            f"{share}/pfx/{pin}/b.css": "\\.(jpg|",                         # undeclared sibling prefix
            f"/share/{'Ab' * 2}/pf/{pin}/b.css": "\\.(jpg|",                # token shorter than 20
            f"/admin/dam/pages/65/pf/{pin}/b.css": "\\.(jpg|",              # share families exist only under /share
        }
        configs = {"studio": runtime.render_nginx("192.168.64.20"), "repo": REPO_NGINX.read_text()}
        for name, config in configs.items():
            locations = nginx_locations(config)
            with self.subTest(config=name):
                # The flag defaults to 1, so every other request is still logged.
                self.assertIn("map $host $mothership_log {\n    default 1;\n}", config)
                self.assertIn("access_log /dev/stdout combined if=$mothership_log;", config)
            for uri in pages:
                with self.subTest(config=name, uri=uri):
                    chosen = nginx_select(locations, uri)
                    self.assertIsNotNone(chosen)
                    self.assertIn("set $mothership_log 0;", chosen[2], f"{uri} -> {chosen[1]}")
                    self.assertIn("rewrite ^ /index.php last;", chosen[2])
            for uri, pattern in others.items():
                with self.subTest(config=name, uri=uri):
                    chosen = nginx_select(locations, uri)
                    self.assertIn(pattern, chosen[1])
                    self.assertNotIn("set $mothership_log 0;", chosen[2])

    def test_no_nginx_config_keeps_a_craft_upload_link_location(self):
        # Craft upload links were removed with the hosted Craft MCP (November 2026); /craft/drop/ is an ordinary
        # path again, handled by the front controller like any unknown route.
        configs = {"studio": runtime.render_nginx("192.168.64.20"), "repo": REPO_NGINX.read_text()}
        for name, config in configs.items():
            locations = nginx_locations(config)
            with self.subTest(config=name):
                self.assertIn("client_max_body_size 200M;", config)
                self.assertNotIn("/craft/drop/", config)
                self.assertNotEqual(nginx_select(locations, "/craft/drop/" + "Ab0_-" * 8 + "xyz")[1], "/craft/drop/")

    def _hold_stack(self, root, *, hold):
        config = self.make_config(root)
        stack = runtime.Stack(config, runner=RecordingRunner({}), relay=RecordingRelay())
        if hold:
            marker = config.storage / "framework" / "down"
            marker.parent.mkdir(parents=True, exist_ok=True)
            marker.write_text("{}")
        return stack

    @staticmethod
    def _http_error(code):
        return urllib.error.HTTPError("http://127.0.0.1/up", code, "x", {}, None)

    def test_a_held_start_must_answer_503_and_never_passes_on_a_200(self):
        with tempfile.TemporaryDirectory() as root:
            stack = self._hold_stack(root, hold=True)
            with mock.patch.object(runtime.urllib.request, "urlopen", side_effect=self._http_error(503)):
                stack._require_hold_response("http://127.0.0.1:18090/admin/login")
            with mock.patch.object(runtime.urllib.request, "urlopen", side_effect=self._http_error(302)):
                with self.assertRaisesRegex(runtime.RuntimeFailure, "expected 503"):
                    stack._require_hold_response("http://127.0.0.1:18090/admin/login")
            ok = mock.MagicMock()
            ok.__enter__.return_value.status = 200
            with mock.patch.object(runtime.urllib.request, "urlopen", return_value=ok):
                with self.assertRaisesRegex(runtime.RuntimeFailure, "not paused"):
                    stack._require_hold_response("http://127.0.0.1:18090/up")
            with mock.patch.object(runtime.urllib.request, "urlopen", side_effect=urllib.error.URLError("offline")):
                with self.assertRaisesRegex(runtime.RuntimeFailure, "unavailable"):
                    stack._require_hold_response("http://127.0.0.1:18090/up")

    def test_verify_under_a_hold_reports_held_and_skips_the_login_asset_checks(self):
        import contextlib
        import io

        inspections = {name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()}
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=RecordingRunner(inspections))
            marker = config.storage / "framework" / "down"
            marker.parent.mkdir(parents=True, exist_ok=True)
            marker.write_text("{}")

            class FakeEventLog:
                def __init__(self):
                    self.events = []

                def write(self, level, event, loc, ctx=None):
                    self.events.append(event)

            stack.events = FakeEventLog()

            class OwnedCollectorManager(MockCollectorManager):
                def status(self, *, now):
                    from studio_system_metrics_process import CollectorStatus
                    return CollectorStatus(pid=1234, running=True, owned=True, snapshot_age_seconds=1.0, signals={})

            stack.collector_manager = OwnedCollectorManager()
            fetched = []
            stack._fetch = lambda url: fetched.append(url) or ("", "text/plain")
            probed = []
            stack._require_hold_response = lambda url: probed.append(url)
            stack._container_ip = lambda name: "192.168.64.20"
            stack._require_http = lambda *args, **kwargs: None
            stack._wait_artisan = lambda **kwargs: None
            config.state.mkdir(parents=True, exist_ok=True)
            config.env_file.write_text("")

            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                stack.verify()

            base = "http://127.0.0.1:{}".format(config.web_port)
            self.assertEqual(probed, [base + "/admin/login"])
            self.assertEqual(fetched, [base + "/up"])
            self.assertIn("NOT reopened", stdout.getvalue())
            self.assertIn("verify.held", stack.events.events)
            self.assertNotIn("verify.passed", stack.events.events)

    def bridge_environment(self):
        return {
            "MOTHERSHIP_OPTIMIZER_API": "http://192.168.164.1:18766",
            "MOTHERSHIP_AI_API": "http://192.168.164.1:18767",
        }

    def gateway_runner(self):
        return RecordingRunner({runtime.NETWORK_NAME: [{"status": {"ipv4Gateway": "192.168.164.1"}}]})

    def test_up_starts_each_configured_bridge_through_its_manager_on_the_gateway(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=self.gateway_runner(), relay=RecordingRelay())
            done = mock.Mock(returncode=0, stdout="{}", stderr="")
            with mock.patch.object(runtime.subprocess, "run", return_value=done) as run:
                with contextlib.redirect_stdout(io.StringIO()):
                    stack._start_native_bridges(self.bridge_environment())
            commands = [call.args[0] for call in run.call_args_list]
            self.assertEqual([pathlib.Path(c[1]).name for c in commands], ["clop_service.py", "ai_service.py"])
            for command, port, name in zip(commands, ("18766", "18767"), ("clop", "ai")):
                self.assertEqual(command[2], "up")
                self.assertEqual(command[command.index("--host") + 1], "192.168.164.1")
                self.assertEqual(command[command.index("--port") + 1], port)
                self.assertEqual(
                    pathlib.Path(command[command.index("--state") + 1]),
                    pathlib.Path(root) / "Mothership Native Services" / name,
                )

    def test_video_bridge_starts_from_its_env_key_and_stage_cannot_reach_it(self):
        self.assertIn("MOTHERSHIP_HOME_VIDEO_API=http://127.0.0.1:9", runtime.STAGE_DISABLED_ENV)
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=self.gateway_runner(), relay=RecordingRelay())
            environment = {"MOTHERSHIP_HOME_VIDEO_API": "http://192.168.164.1:18769"}
            done = mock.Mock(returncode=0, stdout="ready", stderr="")
            with mock.patch.object(runtime.subprocess, "run", return_value=done) as run:
                with contextlib.redirect_stdout(io.StringIO()):
                    stack._start_native_bridges(environment)
            command = run.call_args.args[0]
            self.assertEqual(pathlib.Path(command[1]).name, "video_service.py")
            self.assertEqual(pathlib.Path(command[command.index("--state") + 1]).name, "video")
            self.assertEqual(command[command.index("--port") + 1], "18769")
            self.assertNotIn("--app-env", command)

    def test_up_skips_unconfigured_bridges_and_rejects_a_stale_gateway(self):
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=self.gateway_runner(), relay=RecordingRelay())
            with mock.patch.object(runtime.subprocess, "run") as run:
                with contextlib.redirect_stdout(io.StringIO()):
                    stack._start_native_bridges({})
                run.assert_not_called()
                with self.assertRaisesRegex(runtime.RuntimeFailure, "MOTHERSHIP_OPTIMIZER_API host 192.168.64.1"):
                    stack._start_native_bridges({"MOTHERSHIP_OPTIMIZER_API": "http://192.168.64.1:18766"})
                run.assert_not_called()

    def test_an_invalid_bridge_url_does_not_stop_the_other_bridge_from_starting(self):
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=self.gateway_runner(), relay=RecordingRelay())
            done = mock.Mock(returncode=0, stdout="{}", stderr="")
            environment = {
                "MOTHERSHIP_OPTIMIZER_API": "http://192.168.64.1:18766",
                "MOTHERSHIP_AI_API": "http://192.168.164.1:18767",
            }
            with mock.patch.object(runtime.subprocess, "run", return_value=done) as run:
                with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    with self.assertRaisesRegex(runtime.RuntimeFailure, "optimizer .*MOTHERSHIP_OPTIMIZER_API host 192.168.64.1"):
                        stack._start_native_bridges(environment)
            self.assertEqual(run.call_count, 1)
            self.assertEqual(pathlib.Path(run.call_args.args[0][1]).name, "ai_service.py")

    def test_a_malformed_bracketed_bridge_url_is_a_bridge_failure_not_a_crash(self):
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=self.gateway_runner(), relay=RecordingRelay())
            done = mock.Mock(returncode=0, stdout="{}", stderr="")
            environment = {
                "MOTHERSHIP_OPTIMIZER_API": "http://[192.168.164.1:18766",
                "MOTHERSHIP_AI_API": "http://192.168.164.1:18767",
            }
            with mock.patch.object(runtime.subprocess, "run", return_value=done) as run:
                with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    with self.assertRaisesRegex(runtime.RuntimeFailure, r"optimizer \(MOTHERSHIP_OPTIMIZER_API must use a valid"):
                        stack._start_native_bridges(environment)
            self.assertEqual(run.call_count, 1)
            with self.assertRaisesRegex(runtime.RuntimeFailure, r"config invalid: optimizer \(.*; bridge unreachable.*: ai"):
                runner = self.gateway_runner()
                runner.run = mock.Mock(side_effect=runtime.RuntimeFailure("down"))
                runtime.Stack(self.make_config(root), runner=runner, relay=RecordingRelay())._verify_native_bridges(environment)

    def test_verify_still_checks_the_other_bridge_when_one_url_is_invalid(self):
        runner = self.gateway_runner()
        runner.run = mock.Mock(side_effect=runtime.RuntimeFailure("down"))
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner, relay=RecordingRelay())
            environment = {
                "MOTHERSHIP_OPTIMIZER_API": "http://192.168.64.1:18766",
                "MOTHERSHIP_AI_API": "http://192.168.164.1:18767",
            }
            with self.assertRaisesRegex(runtime.RuntimeFailure, r"config invalid: optimizer \(.*; bridge unreachable.*: ai \(192.168.164.1:18767\)"):
                stack._verify_native_bridges(environment)

    def test_up_names_every_bridge_that_failed_to_start(self):
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=self.gateway_runner(), relay=RecordingRelay())
            failed = mock.Mock(returncode=1, stdout="", stderr="clop service: missing native service dependency")
            with mock.patch.object(runtime.subprocess, "run", return_value=failed) as run:
                with contextlib.redirect_stderr(io.StringIO()):
                    with self.assertRaisesRegex(runtime.RuntimeFailure, "optimizer, ai"):
                        stack._start_native_bridges(self.bridge_environment())
            self.assertEqual(run.call_count, 2)

    def test_an_optional_bridge_that_fails_never_fails_the_site(self):
        # An optional bridge backs a feature the app works without; the site must start and verify without it.
        optional = runtime.NativeBridge(label="sample", env_key="SAMPLE_OPTIONAL_API", manager="sample_service.py", state_name="sample", optional=True)
        bridges = mock.patch.object(runtime, "MANAGED_BRIDGES", runtime.MANAGED_BRIDGES + (optional,))
        bridges.start()
        self.addCleanup(bridges.stop)
        environment = {"SAMPLE_OPTIONAL_API": "http://192.168.164.1:18770"}
        failed = mock.Mock(returncode=1, stdout="", stderr="sample service: did not become healthy")
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=self.gateway_runner(), relay=RecordingRelay())
            stderr = io.StringIO()
            for outcome in (failed, subprocess.TimeoutExpired(cmd="sample_service.py", timeout=180)):
                with mock.patch.object(runtime.subprocess, "run", side_effect=[outcome]) as run:
                    with contextlib.redirect_stderr(stderr), contextlib.redirect_stdout(io.StringIO()):
                        stack._start_native_bridges(environment)
                self.assertEqual(run.call_count, 1)
            self.assertIn("sample: optional, the site starts without it", stderr.getvalue())

            # A stale gateway address and an unreachable service are printed, not raised.
            with contextlib.redirect_stderr(stderr), contextlib.redirect_stdout(io.StringIO()):
                stack._start_native_bridges({"SAMPLE_OPTIONAL_API": "http://192.168.64.1:18770"})
                stack._verify_native_bridges({"SAMPLE_OPTIONAL_API": "http://192.168.64.1:18770"})
            runner = self.gateway_runner()
            runner.run = mock.Mock(side_effect=runtime.RuntimeFailure("connection refused"))
            down = runtime.Stack(self.make_config(root), runner=runner, relay=RecordingRelay())
            with contextlib.redirect_stderr(stderr), contextlib.redirect_stdout(io.StringIO()):
                down._verify_native_bridges(environment)
            self.assertIn("sample: unreachable at 192.168.164.1:18770; optional, not a failure", stderr.getvalue())

            # A required bridge beside it still fails the start.
            both = dict(environment, MOTHERSHIP_OPTIMIZER_API="http://192.168.164.1:18766")
            with mock.patch.object(runtime.subprocess, "run", return_value=failed):
                with contextlib.redirect_stderr(io.StringIO()), contextlib.redirect_stdout(io.StringIO()):
                    with self.assertRaisesRegex(runtime.RuntimeFailure, r"failed to start: optimizer$"):
                        stack._start_native_bridges(both)

    def test_verify_names_the_failing_bridge(self):
        runner = self.gateway_runner()

        def run(args, **kwargs):
            if "http://192.168.164.1:18766/health" in args:
                raise runtime.RuntimeFailure("connection refused")
            return ""

        runner.run = mock.Mock(side_effect=run)
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner, relay=RecordingRelay())
            with self.assertRaisesRegex(runtime.RuntimeFailure, r"bridge unreachable .*: optimizer \(192.168.164.1:18766\);") as caught:
                stack._verify_native_bridges(self.bridge_environment())
            self.assertNotIn("ai (", str(caught.exception))
            stack_ok = runtime.Stack(self.make_config(root), runner=self.gateway_runner(), relay=RecordingRelay())
            stack_ok._verify_native_bridges(self.bridge_environment())

    def test_http_failures_are_normalized_as_runtime_failures(self):
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=RecordingRunner({}))
            with mock.patch.object(
                runtime.urllib.request,
                "urlopen",
                side_effect=urllib.error.URLError("offline"),
            ):
                with self.assertRaisesRegex(runtime.RuntimeFailure, "looper health check is unavailable"):
                    stack._require_http("looper", "http://192.168.64.14:3457/health")
                with self.assertRaisesRegex(runtime.RuntimeFailure, "unavailable"):
                    stack._fetch("http://127.0.0.1:18090/up")

    def test_worker_supervisor_restarts_clean_exit_then_stops_on_failure(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = pathlib.Path(root)
            supervisor = root_path / "supervisor.sh"
            supervisor.write_text(runtime.render_worker_supervisor(), encoding="utf-8")
            worker = root_path / "worker.sh"
            marker = root_path / "ran"
            worker.write_text(
                '#!/bin/bash\nif [[ -e "$1" ]]; then exit 7; fi\ntouch "$1"\nexit 0\n',
                encoding="utf-8",
            )
            worker.chmod(0o755)
            environment = dict(
                os.environ,
                STUDIO_WORKER_PID_FILE=str(root_path / "worker.pid"),
                STUDIO_WORKER_MAX_FAILURES="1",
            )
            completed = subprocess.run(
                ["/bin/bash", str(supervisor), str(worker), str(marker)],
                check=False,
                capture_output=True,
                text=True,
                env=environment,
                timeout=10,
            )

        self.assertEqual(completed.returncode, 7)
        self.assertIn("worker recycled with status 0", completed.stdout)
        self.assertIn("worker stopped with status 7 after 1 consecutive failures", completed.stdout)

    def test_worker_supervisor_restarts_memory_limit_exit(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = pathlib.Path(root)
            supervisor = root_path / "supervisor.sh"
            supervisor.write_text(runtime.render_worker_supervisor(), encoding="utf-8")
            worker = root_path / "worker.sh"
            marker = root_path / "ran"
            worker.write_text(
                '#!/bin/bash\nif [[ -e "$1" ]]; then exit 7; fi\ntouch "$1"\nexit 12\n',
                encoding="utf-8",
            )
            worker.chmod(0o755)
            completed = subprocess.run(
                ["/bin/bash", str(supervisor), str(worker), str(marker)],
                check=False,
                capture_output=True,
                text=True,
                env=dict(
                    os.environ,
                    STUDIO_WORKER_PID_FILE=str(root_path / "worker.pid"),
                    STUDIO_WORKER_MAX_FAILURES="1",
                ),
                timeout=10,
            )

        self.assertEqual(completed.returncode, 7)
        self.assertIn("worker recycled with status 12", completed.stdout)

    def test_worker_supervisor_keeps_restarting_rapid_clean_exits(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = pathlib.Path(root)
            supervisor = root_path / "supervisor.sh"
            supervisor.write_text(runtime.render_worker_supervisor(), encoding="utf-8")
            worker = root_path / "worker.sh"
            counter = root_path / "count"
            worker.write_text(
                '#!/bin/bash\ncount=$(cat "$1" 2>/dev/null || echo 0)\n'
                'count=$((count + 1))\necho "$count" > "$1"\n'
                'if (( count >= 3 )); then exit 7; fi\nexit 0\n',
                encoding="utf-8",
            )
            worker.chmod(0o755)
            completed = subprocess.run(
                ["/bin/bash", str(supervisor), str(worker), str(counter)],
                check=False,
                capture_output=True,
                text=True,
                env=dict(
                    os.environ,
                    STUDIO_WORKER_PID_FILE=str(root_path / "worker.pid"),
                    STUDIO_WORKER_MAX_FAILURES="1",
                ),
                timeout=10,
            )

        self.assertEqual(completed.returncode, 7)
        self.assertEqual(completed.stdout.count("worker recycled with status 0"), 2)
        self.assertIn("worker stopped with status 7", completed.stdout)

    def test_worker_supervisor_restarts_after_fatal_until_consecutive_failure_bound(self):
        # Runs 1, 3 and 4 die like a PHP fatal (255); run 2 is a clean recycle, which starts the count again.
        with tempfile.TemporaryDirectory() as root:
            root_path = pathlib.Path(root)
            supervisor = root_path / "supervisor.sh"
            supervisor.write_text(runtime.render_worker_supervisor(), encoding="utf-8")
            worker = root_path / "worker.sh"
            counter = root_path / "count"
            worker.write_text(
                '#!/bin/bash\ncount=$(cat "$1" 2>/dev/null || echo 0)\n'
                'count=$((count + 1))\necho "$count" > "$1"\n'
                'if (( count == 2 )); then exit 0; fi\nexit 255\n',
                encoding="utf-8",
            )
            worker.chmod(0o755)
            completed = subprocess.run(
                ["/bin/bash", str(supervisor), str(worker), str(counter)],
                check=False,
                capture_output=True,
                text=True,
                env=dict(
                    os.environ,
                    STUDIO_WORKER_PID_FILE=str(root_path / "worker.pid"),
                    STUDIO_WORKER_MAX_FAILURES="2",
                    STUDIO_WORKER_FAILURE_BACKOFF="0",
                ),
                timeout=10,
            )
            runs = int(counter.read_text(encoding="utf-8"))

        self.assertEqual(runs, 4)
        self.assertEqual(completed.returncode, 255)
        self.assertEqual(completed.stdout.count("worker failed with status 255 (1/2); restarting in 0s"), 2)
        self.assertIn("worker stopped with status 255 after 2 consecutive failures", completed.stdout)

    def test_worker_supervisor_does_not_count_a_failure_after_a_stable_run(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = pathlib.Path(root)
            supervisor = root_path / "supervisor.sh"
            supervisor.write_text(runtime.render_worker_supervisor(), encoding="utf-8")
            worker = root_path / "worker.sh"
            counter = root_path / "count"
            worker.write_text(
                '#!/bin/bash\ncount=$(cat "$1" 2>/dev/null || echo 0)\n'
                'count=$((count + 1))\necho "$count" > "$1"\n'
                'if (( count == 2 )); then sleep 1; fi\nexit 255\n',
                encoding="utf-8",
            )
            worker.chmod(0o755)
            completed = subprocess.run(
                ["/bin/bash", str(supervisor), str(worker), str(counter)],
                check=False,
                capture_output=True,
                text=True,
                env=dict(
                    os.environ,
                    STUDIO_WORKER_PID_FILE=str(root_path / "worker.pid"),
                    STUDIO_WORKER_MAX_FAILURES="2",
                    STUDIO_WORKER_FAILURE_BACKOFF="0",
                    STUDIO_WORKER_STABLE_SECONDS="1",
                ),
                timeout=10,
            )
            runs = int(counter.read_text(encoding="utf-8"))

        # Run 2 lasted the stable second, so its failure is the first of a new streak: three runs, not two.
        self.assertEqual(runs, 3)
        self.assertEqual(completed.returncode, 255)

    def test_worker_supervisor_forwards_term_and_removes_child_pid(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = pathlib.Path(root)
            supervisor = root_path / "supervisor.sh"
            supervisor.write_text(runtime.render_worker_supervisor(), encoding="utf-8")
            pid_file = root_path / "worker.pid"
            process = subprocess.Popen(
                ["/bin/bash", str(supervisor), "/bin/sleep", "30"],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                env=dict(os.environ, STUDIO_WORKER_PID_FILE=str(pid_file)),
            )
            deadline = runtime.time.monotonic() + 3
            while not pid_file.exists() and runtime.time.monotonic() < deadline:
                runtime.time.sleep(0.05)
            self.assertTrue(pid_file.exists())
            child_pid = int(pid_file.read_text(encoding="utf-8"))
            process.terminate()
            process.communicate(timeout=5)

        self.assertEqual(process.returncode, 143)
        self.assertFalse(pid_file.exists())
        with self.assertRaises(ProcessLookupError):
            os.kill(child_pid, 0)

    def test_worker_supervisor_runs_the_requested_workers_and_stops_them_all(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = pathlib.Path(root)
            supervisor = root_path / "supervisor.sh"
            supervisor.write_text(runtime.render_worker_supervisor(), encoding="utf-8")
            pid_file = root_path / "worker.pid"
            second_pid_file = root_path / "worker.pid.2"
            process = subprocess.Popen(
                ["/bin/bash", str(supervisor), "/bin/sleep", "30"],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                env=dict(os.environ, STUDIO_WORKER_PID_FILE=str(pid_file), STUDIO_WORKER_COUNT="2"),
            )
            deadline = runtime.time.monotonic() + 5
            while not (pid_file.exists() and second_pid_file.exists()) and runtime.time.monotonic() < deadline:
                runtime.time.sleep(0.05)
            self.assertTrue(pid_file.exists())
            self.assertTrue(second_pid_file.exists())
            child_pids = [int(path.read_text(encoding="utf-8")) for path in (pid_file, second_pid_file)]
            self.assertNotEqual(child_pids[0], child_pids[1])
            process.terminate()
            process.communicate(timeout=10)

            self.assertFalse(pid_file.exists())
            self.assertFalse(second_pid_file.exists())

        self.assertEqual(process.returncode, 143)
        for child_pid in child_pids:
            with self.assertRaises(ProcessLookupError):
                os.kill(child_pid, 0)

    def test_worker_supervisor_signals_every_worker_before_waiting_for_any(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = pathlib.Path(root)
            supervisor = root_path / "supervisor.sh"
            supervisor.write_text(runtime.render_worker_supervisor(), encoding="utf-8")
            pid_file = root_path / "worker.pid"
            worker = root_path / "worker.sh"
            # Like a queue worker finishing its job: it notes the signal, then takes a while to exit.
            worker.write_text(
                '#!/bin/bash\n'
                'trap \'touch "$1.$$"; kill "$idle"; sleep 3; exit 0\' TERM\n'
                'sleep 30 &\n'
                'idle=$!\n'
                'wait "$idle"\n',
                encoding="utf-8",
            )
            worker.chmod(0o755)
            marker = root_path / "heard"
            process = subprocess.Popen(
                ["/bin/bash", str(supervisor), str(worker), str(marker)],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                env=dict(os.environ, STUDIO_WORKER_PID_FILE=str(pid_file), STUDIO_WORKER_COUNT="2"),
            )
            second_pid_file = root_path / "worker.pid.2"
            deadline = runtime.time.monotonic() + 5
            while not (pid_file.exists() and second_pid_file.exists()) and runtime.time.monotonic() < deadline:
                runtime.time.sleep(0.05)
            runtime.time.sleep(0.3)
            process.terminate()
            deadline = runtime.time.monotonic() + 2
            while len(list(root_path.glob("heard.*"))) < 2 and runtime.time.monotonic() < deadline:
                runtime.time.sleep(0.05)
            heard_while_stopping = len(list(root_path.glob("heard.*")))
            process.wait(timeout=15)

        self.assertEqual(heard_while_stopping, 2)
        self.assertEqual(process.returncode, 143)

    def test_worker_supervisor_stops_the_group_when_an_extra_worker_gives_up(self):
        with tempfile.TemporaryDirectory() as root:
            root_path = pathlib.Path(root)
            supervisor = root_path / "supervisor.sh"
            supervisor.write_text(runtime.render_worker_supervisor(), encoding="utf-8")
            pid_file = root_path / "worker.pid"
            worker = root_path / "worker.sh"
            worker.write_text(
                '#!/bin/bash\n'
                'if [[ "$STUDIO_WORKER_PID_FILE" == *.2 ]]; then exit 7; fi\n'
                'exec /bin/sleep 30\n',
                encoding="utf-8",
            )
            worker.chmod(0o755)
            completed = subprocess.run(
                ["/bin/bash", str(supervisor), str(worker)],
                check=False,
                capture_output=True,
                text=True,
                env=dict(
                    os.environ,
                    STUDIO_WORKER_PID_FILE=str(pid_file),
                    STUDIO_WORKER_COUNT="2",
                    STUDIO_WORKER_MAX_FAILURES="1",
                ),
                timeout=15,
            )
            self.assertFalse(pid_file.exists())

        self.assertEqual(completed.returncode, 1)
        self.assertIn("an extra worker reached the failure limit", completed.stdout)

    def test_artisan_probe_checks_supervised_child_pid(self):
        runner = RecordingRunner({})
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner, relay=RecordingRelay())
            stack._artisan_probe(role="queue", process="queue:work")

        command = runner.calls[-1]
        self.assertEqual(command[:3], ("exec", runtime.CONTAINER_NAMES["queue"], "php"))
        self.assertIn("/tmp/studio-worker.pid", command[-1])
        self.assertNotIn("/proc/1/cmdline", command[-1])

    def test_verify_worker_probe_uses_bounded_recycle_retry(self):
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=RecordingRunner({}), relay=RecordingRelay())
            with mock.patch.object(stack, "_wait") as wait:
                stack._wait_artisan(role="queue", process="queue:work", timeout=30)

        self.assertEqual(wait.call_args.kwargs["timeout"], 30)

    def test_native_transport_only_publishes_web_and_optional_mailpit_on_loopback(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root, mailpit_port=18091)
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            specs = stack.plan(
                {
                    "postgres": "192.168.64.10",
                    "redis": "192.168.64.11",
                    "elasticsearch": "192.168.64.12",
                    "mailpit": "192.168.64.13",
                    "looper": "192.168.64.14",
                    "fpm": "192.168.64.15",
                }
            )

        published = {spec.role: spec.publishes for spec in specs}
        self.assertEqual(published["nginx"], ("127.0.0.1:18090:80",))
        self.assertEqual(published["mailpit"], ("127.0.0.1:18091:8025",))
        self.assertTrue(all(not ports for role, ports in published.items() if role not in {"nginx", "mailpit"}))
        self.assertTrue(all(port.startswith("127.0.0.1:") for ports in published.values() for port in ports))

    def test_relay_transport_removes_nginx_native_publication(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root, web_transport="relay")
            specs = runtime.Stack(config, runner=RecordingRunner({}), relay=RecordingRelay()).plan(
                {
                    "postgres": "192.168.64.10", "redis": "192.168.64.11",
                    "elasticsearch": "192.168.64.12", "mailpit": "192.168.64.13",
                    "looper": "192.168.64.14", "fpm": "192.168.64.15",
                }
            )

        self.assertEqual({spec.role: spec for spec in specs}["nginx"].publishes, ())

    def test_tcp_relay_preserves_large_concurrent_half_closed_streams(self):
        payload = bytes(range(256)) * 16384
        payload_hash = hashlib.sha256(payload).hexdigest()

        class Handler(socketserver.BaseRequestHandler):
            def handle(self):
                while self.request.recv(65536):
                    pass
                self.request.sendall(payload)
                self.request.shutdown(socket.SHUT_WR)

        backend = socketserver.ThreadingTCPServer(("127.0.0.1", 0), Handler)
        backend_thread = threading.Thread(target=backend.serve_forever, daemon=True)
        backend_thread.start()
        relay = runtime.TcpRelayServer(
            listen_host="127.0.0.1",
            listen_port=0,
            target_host="127.0.0.1",
            target_port=backend.server_address[1],
        )
        relay_thread = threading.Thread(target=relay.serve_forever, daemon=True)
        relay_thread.start()
        self.assertTrue(relay.ready.wait(timeout=2))

        def fetch(_index):
            with socket.create_connection(("127.0.0.1", relay.bound_port), timeout=5) as client:
                client.sendall(b"request")
                client.shutdown(socket.SHUT_WR)
                chunks = []
                while True:
                    chunk = client.recv(1024 * 1024)
                    if not chunk:
                        break
                    chunks.append(chunk)
                body = b"".join(chunks)
                return len(body), hashlib.sha256(body).hexdigest()

        try:
            with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
                results = list(pool.map(fetch, range(4)))
        finally:
            relay.shutdown()
            backend.shutdown()
            relay_thread.join(timeout=2)
            backend_thread.join(timeout=2)
            backend.server_close()

        self.assertEqual(results, [(len(payload), payload_hash)] * 4)

    def test_tcp_relay_reports_capacity_and_releases_slot_when_thread_start_fails(self):
        notices = []
        relay = runtime.TcpRelayServer(
            listen_host="127.0.0.1",
            listen_port=0,
            target_host="127.0.0.1",
            target_port=80,
            on_notice=notices.append,
        )
        client = mock.MagicMock()
        relay._slots = mock.MagicMock()
        relay._slots.acquire.return_value = False
        relay._dispatch(client)
        self.assertIn("capacity_refused", notices)
        client.close.assert_called()

        notices.clear()
        client.reset_mock()
        relay._slots.acquire.return_value = True
        with mock.patch.object(runtime.threading, "Thread") as thread:
            thread.return_value.start.side_effect = RuntimeError("no threads")
            relay._dispatch(client)
        self.assertIn("thread_start_failed", notices)
        client.close.assert_called()
        relay._slots.release.assert_called_once()

    def test_tcp_relay_reports_upstream_connect_failure_without_thread_traceback(self):
        notices = []
        relay = runtime.TcpRelayServer(
            listen_host="127.0.0.1",
            listen_port=0,
            target_host="127.0.0.1",
            target_port=80,
            on_notice=notices.append,
        )
        client = mock.MagicMock()
        relay._slots.acquire()

        with mock.patch.object(runtime.socket, "create_connection", side_effect=OSError("offline")):
            relay._serve_connection(client)

        self.assertEqual(notices, ["upstream_unavailable"])
        client.close.assert_called()
        self.assertTrue(relay._slots.acquire(blocking=False))
        relay._slots.release()

    def test_relay_manager_starts_fresh_then_reuses_matching_process(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root, web_transport="relay")
            manager = runtime.RelayManager(config=config, events=runtime.EventLog(config.state))
            spawned = []

            class FakeProcess:
                pid = 4321

                def __init__(self, command, **_kwargs):
                    spawned.append(command)
                    relay_token = command[command.index("--relay-token") + 1]
                    config.generated.mkdir(parents=True, exist_ok=True)
                    config.relay_record.write_text(
                        json.dumps(
                            {
                                "pid": self.pid,
                                "token": relay_token,
                                "listen_host": "127.0.0.1",
                                "listen_port": config.web_port,
                                "target_host": "192.168.65.22",
                                "target_port": 80,
                                "script": str(manager.script),
                            }
                        ),
                        encoding="utf-8",
                    )

                def poll(self):
                    return None

                def terminate(self):
                    return None

            def command_for(_pid):
                record = json.loads(config.relay_record.read_text(encoding="utf-8"))
                return "{} relay-serve {}".format(manager.script, record["token"])

            with mock.patch.object(runtime.subprocess, "Popen", FakeProcess), \
                    mock.patch.object(manager, "_alive", return_value=True), \
                    mock.patch.object(manager, "_command", side_effect=command_for), \
                    mock.patch.object(runtime.socket, "create_connection", return_value=mock.MagicMock()):
                manager.start("192.168.65.22")
                manager.start("192.168.65.22")

        self.assertEqual(len(spawned), 1)

    def test_relay_manager_discards_reused_pid_without_signaling_it(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root, web_transport="relay")
            config.generated.mkdir(parents=True)
            manager = runtime.RelayManager(config=config, events=runtime.EventLog(config.state))
            config.relay_record.write_text(
                json.dumps(
                    {
                        "pid": 999,
                        "token": "x",
                        "listen_host": "127.0.0.1",
                        "listen_port": config.web_port,
                        "target_host": "192.168.65.22",
                        "target_port": 80,
                        "script": str(manager.script),
                    }
                ),
                encoding="utf-8",
            )
            with mock.patch.object(manager, "_alive", return_value=True), \
                    mock.patch.object(manager, "_command", return_value="/usr/bin/sleep 30"), \
                    mock.patch.object(runtime.os, "kill") as kill:
                manager.stop()

            self.assertFalse(config.relay_record.exists())
            kill.assert_not_called()

    def test_relay_manager_stale_live_pid_starts_fresh_without_signaling_it(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root, web_transport="relay")
            config.generated.mkdir(parents=True)
            manager = runtime.RelayManager(config=config, events=runtime.EventLog(config.state))
            config.relay_record.write_text(
                json.dumps(
                    {
                        "pid": 999,
                        "token": "x",
                        "listen_host": "127.0.0.1",
                        "listen_port": config.web_port,
                        "target_host": "192.168.65.21",
                        "target_port": 80,
                        "script": str(manager.script),
                    }
                ),
                encoding="utf-8",
            )
            spawned = []

            def spawn(command, **_kwargs):
                spawned.append(command)
                relay_token = command[command.index("--relay-token") + 1]
                config.relay_record.write_text(
                    json.dumps(
                        {
                            "pid": 4321,
                            "token": relay_token,
                            "listen_host": "127.0.0.1",
                            "listen_port": config.web_port,
                            "target_host": "192.168.65.22",
                            "target_port": 80,
                            "script": str(manager.script),
                        }
                    ),
                    encoding="utf-8",
                )
                process = mock.MagicMock(pid=4321)
                process.poll.return_value = None
                return process

            def command_for(pid):
                if pid == 999:
                    return "/usr/bin/sleep 30"
                record = json.loads(config.relay_record.read_text(encoding="utf-8"))
                return "{} relay-serve {}".format(manager.script, record["token"])

            with mock.patch.object(runtime.subprocess, "Popen", side_effect=spawn), \
                    mock.patch.object(manager, "_alive", return_value=True), \
                    mock.patch.object(manager, "_command", side_effect=command_for), \
                    mock.patch.object(runtime.socket, "create_connection", return_value=mock.MagicMock()), \
                    mock.patch.object(runtime.os, "kill") as kill:
                manager.start("192.168.65.22")

            self.assertEqual(len(spawned), 1)
            kill.assert_not_called()

    def test_relay_manager_discards_malformed_record_with_clear_status(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root, web_transport="relay")
            config.generated.mkdir(parents=True)
            config.relay_record.write_text("{", encoding="utf-8")
            manager = runtime.RelayManager(config=config, events=runtime.EventLog(config.state))

            status = manager.describe()

            self.assertEqual(status, "stopped (invalid record discarded)")
            self.assertFalse(config.relay_record.exists())

    def test_down_stops_host_relay_before_owned_containers(self):
        inspections = {name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()}
        runner = RecordingRunner(inspections)
        relay = RecordingRelay()
        with tempfile.TemporaryDirectory() as root:
            runtime.Stack(self.make_config(root), runner=runner, relay=relay).down()

        self.assertEqual(relay.calls, [("stop",)])
        self.assertTrue(runner.calls)

    def test_named_network_is_shared_without_broad_port_publication(self):
        args = runtime.network_create_args()

        self.assertEqual(args[:2], ["network", "create"])
        self.assertIn("{}={}".format(runtime.OWNER_LABEL, runtime.OWNER_VALUE), args)
        self.assertEqual(args[-1], runtime.NETWORK_NAME)
        self.assertEqual(args[args.index("--subnet") + 1], runtime.NETWORK_SUBNET)
        self.assertNotIn("--internal", args)

    def _network_lifecycle(self, network_listing, network_inspection=None):
        """Run Stack._ensure_network_and_volumes against a mocked runner; volumes already owned."""
        inspections = {name: owned_inspect() for name in runtime.VOLUME_NAMES.values()}
        if network_inspection is not None:
            inspections[runtime.NETWORK_NAME] = network_inspection
        runner = RecordingRunner(inspections)
        runner.network_names = lambda: set(network_listing)
        runner.volume_names = lambda: set(runtime.VOLUME_NAMES.values())
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner)
            try:
                stack._ensure_network_and_volumes()
            finally:
                self.network_calls = [c for c in runner.calls if c[:1] == ("network",)]
        return runner

    def test_ensure_network_absent_creates_pinned_subnet(self):
        self._network_lifecycle([])

        self.assertEqual(self.network_calls, [tuple(runtime.network_create_args())])
        self.assertIn(("--subnet", runtime.NETWORK_SUBNET), list(zip(self.network_calls[0], self.network_calls[0][1:])))

    def test_ensure_network_owned_legacy_without_subnet_is_kept_not_recreated(self):
        # Current behaviour: an owned network is accepted by label alone; the subnet is NOT
        # verified and a legacy network is never deleted or recreated by `up`.
        runner = self._network_lifecycle([runtime.NETWORK_NAME], owned_inspect())

        self.assertEqual(self.network_calls, [])
        self.assertEqual(runner.calls, [])

    def test_ensure_network_owned_pinned_is_kept_not_recreated(self):
        pinned = owned_inspect()
        pinned[0]["configuration"]["subnet"] = runtime.NETWORK_SUBNET
        runner = self._network_lifecycle([runtime.NETWORK_NAME], pinned)

        self.assertEqual(self.network_calls, [])
        self.assertEqual(runner.calls, [])

    def test_ensure_network_foreign_same_name_is_refused_without_mutation(self):
        foreign = [{"configuration": {"labels": {}}, "status": {}}]
        with self.assertRaisesRegex(runtime.RuntimeFailure, "foreign network uses owned name"):
            self._network_lifecycle([runtime.NETWORK_NAME], foreign)

        self.assertEqual(self.network_calls, [])

    def test_ensure_network_hidden_from_listing_attempts_pinned_create(self):
        # Current behaviour: a network that exists but is absent from `network list` (the
        # persisted-error case) is treated as absent, so create is attempted with the subnet;
        # the runner's real failure ("already exists") would propagate. No special handling.
        self._network_lifecycle([], owned_inspect())

        self.assertEqual(self.network_calls, [tuple(runtime.network_create_args())])

    def test_ensure_network_create_failure_propagates_before_any_volume_or_delete_call(self):
        # The incident: the network exists but is hidden from `network list`, so create is attempted
        # and the runtime answers "already exists". That failure must surface as-is, with volumes
        # absent: no volume is created and nothing is deleted or recreated on the way out.
        runner = RecordingRunner({})

        def failing_run(args, **kwargs):
            runner.calls.append(tuple(args))
            if tuple(args[:2]) == ("network", "create"):
                raise runtime.RuntimeFailure("network {} already exists".format(runtime.NETWORK_NAME))
            return ""

        runner.run = failing_run
        runner.network_names = lambda: set()
        runner.volume_names = lambda: set()
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner)
            with self.assertRaisesRegex(runtime.RuntimeFailure, "already exists"):
                stack._ensure_network_and_volumes()

        self.assertEqual(runner.calls, [tuple(runtime.network_create_args())])
        self.assertFalse([c for c in runner.calls if c[:2] == ("volume", "create")], "no volume created after the failure")
        self.assertFalse([c for c in runner.calls if "delete" in c[:2] or "rm" in c[:2]], "nothing deleted or recreated")

    def test_up_preflight_rejects_running_builder_network(self):
        runner = RecordingRunner({})
        runner.builder_status = lambda: [{"status": {"state": "running"}}]
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner)
            with self.assertRaisesRegex(runtime.RuntimeFailure, "container builder stop"):
                stack._require_builder_stopped()

        self.assertEqual(runner.calls, [])

    def test_up_preflight_accepts_observed_stopped_builder_shape(self):
        runner = RecordingRunner({})
        runner.builder_status = lambda: [
            {
                "configuration": {"id": "buildkit"},
                "status": {"networks": [], "state": "stopped"},
            }
        ]
        with tempfile.TemporaryDirectory() as root:
            runtime.Stack(self.make_config(root), runner=runner)._require_builder_stopped()

        self.assertEqual(runner.calls, [])

    def test_up_preflight_fails_closed_when_builder_state_is_unknown(self):
        runner = RecordingRunner({})
        runner.builder_status = lambda: []
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner)
            with self.assertRaisesRegex(runtime.RuntimeFailure, "did not prove it is stopped"):
                stack._require_builder_stopped()

    def test_inspect_ip_extraction_and_nginx_render_use_runtime_ipv4(self):
        inspect_payload = [
            {
                "status": {
                    "networks": [
                        {"network": "private", "ipv4Address": "192.168.64.44/24"}
                    ]
                }
            }
        ]
        ip = runtime.extract_ipv4(inspect_payload)
        rendered = runtime.render_nginx(ip)

        self.assertEqual(ip, "192.168.64.44")
        self.assertIn("fastcgi_pass 192.168.64.44:9000;", rendered)
        self.assertNotIn("mothership-private-fpm:9000", rendered)

    def test_fpm_uses_baked_code_and_nginx_mounts_static_tree_read_only(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            specs = runtime.Stack(config, runner=RecordingRunner({})).plan(
                {
                    "postgres": "192.168.64.10",
                    "redis": "192.168.64.11",
                    "elasticsearch": "192.168.64.12",
                    "mailpit": "192.168.64.13",
                    "looper": "192.168.64.14",
                    "fpm": "192.168.64.15",
                }
            )

        by_role = {spec.role: spec for spec in specs}
        fpm_mounts = by_role["fpm"].mounts
        nginx_mounts = by_role["nginx"].mounts
        self.assertFalse(any(m.target == "/var/www/html" for m in fpm_mounts))
        self.assertTrue(any(m.target == "/studio-runtime" and m.readonly for m in fpm_mounts))
        self.assertEqual(by_role["fpm"].entrypoint, "/bin/bash")
        self.assertEqual(by_role["fpm"].command, ("/studio-runtime/entrypoint.sh",))
        fpm_args = by_role["fpm"].run_args()
        entrypoint_index = fpm_args.index("--entrypoint")
        image_index = fpm_args.index(by_role["fpm"].image)
        self.assertEqual(fpm_args[entrypoint_index + 1], "/bin/bash")
        self.assertLess(entrypoint_index, image_index)
        entrypoint = runtime.render_fpm_entrypoint()
        self.assertIn("source \"${ROOT}/dockerfiles/lib/setup-app.sh\"", entrypoint)
        self.assertIn("exec 2>&1", entrypoint)
        self.assertIn('chown -R www-data:www-data "${ROOT}/bootstrap/cache"', entrypoint)
        self.assertNotIn('"${ROOT}/storage" "${ROOT}/bootstrap/cache"', entrypoint)
        self.assertNotIn("marker:", entrypoint)
        self.assertNotIn("set -x", entrypoint)
        wait_index = entrypoint.index("\nwait_for_db\n")
        restore_index = entrypoint.index("\nexec 2>&1\n")
        setup_index = entrypoint.index('\nsetup_app "$ROOT"\n')
        self.assertLess(wait_index, restore_index)
        self.assertLess(restore_index, setup_index)
        self.assertTrue(
            any(m.target == "/var/www/html/public" and m.readonly for m in nginx_mounts)
        )
        self.assertTrue(
            any(m.target == "/var/www/html/storage" and m.readonly for m in nginx_mounts)
        )
        rendered = runtime.render_nginx("192.168.64.14")
        self.assertIn("location ^~ /storage/", rendered)
        self.assertIn("alias /var/www/html/storage/app/public/;", rendered)
        self.assertIn("location ~* \\.(php[0-9]?|phtml|pht|phar|html?|shtml|xhtml)$", rendered)

    def test_rendered_nginx_change_changes_container_configuration(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            (config.generated / "nginx").mkdir(parents=True)
            nginx_config = config.generated / "nginx" / "default.conf"
            nginx_config.write_text("one", encoding="utf-8")
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            ips = {
                "postgres": "192.168.64.10",
                "redis": "192.168.64.11",
                "elasticsearch": "192.168.64.12",
                "mailpit": "192.168.64.13",
                "looper": "192.168.64.14",
                "fpm": "192.168.64.15",
            }
            before = {spec.role: spec for spec in stack.plan(ips)}["nginx"].digest()
            nginx_config.write_text("different", encoding="utf-8")
            after = {spec.role: spec for spec in stack.plan(ips)}["nginx"].digest()

        self.assertNotEqual(before, after)

    def test_elasticsearch_volume_initializer_is_owned_and_non_recursive(self):
        args = runtime.elasticsearch_volume_init_args()

        self.assertIn("--rm", args)
        self.assertIn("{}={}".format(runtime.OWNER_LABEL, runtime.OWNER_VALUE), args)
        self.assertIn("{}:/data".format(runtime.VOLUME_NAMES["elasticsearch"]), args)
        self.assertEqual(args[-3:], ["chown", "1000:0", "/data"])
        self.assertNotIn("-R", args)

    def test_elasticsearch_volume_initializer_skips_attached_owned_volume(self):
        name = runtime.CONTAINER_NAMES["elasticsearch"]
        runner = RecordingRunner({name: owned_inspect(state="stopped")})
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner)
            stack._initialize_elasticsearch_volume()

        self.assertEqual(runner.calls, [])

    def test_runner_redacts_registered_secret_from_errors(self):
        runner = runtime.Runner(binary="/bin/true")
        runner.add_redactions(["xy"])

        self.assertEqual(runner._sanitize("failure xy"), "failure [redacted]")

    def test_env_parser_supports_export_and_quotes_without_inline_comment_syntax(self):
        with tempfile.TemporaryDirectory() as root:
            env_file = pathlib.Path(root) / "app.env"
            env_file.write_text(
                'export APP_NAME="The Mothership"\nVALUE=\'x#y\'\n# whole-line comment\n',
                encoding="utf-8",
            )
            values = runtime.read_env(env_file)

        self.assertEqual(values, {"APP_NAME": "The Mothership", "VALUE": "x#y"})

    def test_up_validation_rejects_open_env_mode_then_missing_required_key(self):
        import dataclasses

        with tempfile.TemporaryDirectory() as root:
            # The binary only has to exist; CI runners have no Apple container install.
            container_bin = pathlib.Path(root) / "container"
            container_bin.write_text("", encoding="utf-8")
            config = dataclasses.replace(self.make_config(root), container_bin=str(container_bin))
            (config.source / "public").mkdir(parents=True)
            (config.source / "public" / "index.php").write_text("<?php", encoding="utf-8")
            config.state.mkdir(parents=True)
            config = dataclasses.replace(config, host_gid=config.state.stat().st_gid)
            config.env_file.write_text(
                "APP_KEY=x\nDB_DATABASE=x\nDB_USERNAME=x\n",
                encoding="utf-8",
            )
            config.env_file.chmod(0o644)
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            with self.assertRaisesRegex(runtime.RuntimeFailure, "readable by group or others"):
                stack._validate_up()
            config.env_file.chmod(0o600)
            with self.assertRaisesRegex(runtime.RuntimeFailure, "missing DB_PASSWORD"):
                stack._validate_up()

    def test_same_digest_starts_stopped_container_and_stale_digest_recreates_running(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            ips = {
                "postgres": "192.168.64.10",
                "redis": "192.168.64.11",
                "elasticsearch": "192.168.64.12",
                "mailpit": "192.168.64.13",
                "looper": "192.168.64.14",
                "fpm": "192.168.64.15",
            }
            redis = {spec.role: spec for spec in stack.plan(ips)}["redis"]

            same_runner = RecordingRunner(
                {redis.name: owned_inspect(state="stopped", config_digest=redis.digest())}
            )
            runtime.Stack(config, runner=same_runner)._ensure_container(redis)
            self.assertEqual(same_runner.calls, [("start", redis.name)])

            stale_runner = RecordingRunner(
                {redis.name: owned_inspect(state="running", config_digest="old")}
            )
            runtime.Stack(config, runner=stale_runner)._ensure_container(redis)
            self.assertEqual(stale_runner.calls[0], ("stop", "--time", "10", redis.name))
            self.assertEqual(stale_runner.calls[1], ("delete", redis.name))
            self.assertEqual(stale_runner.calls[2][0], "run")

    def test_down_deletes_only_owned_containers_and_preserves_data_resources(self):
        inspections = {name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()}
        runner = RecordingRunner(inspections)
        with tempfile.TemporaryDirectory() as root:
            runtime.Stack(self.make_config(root), runner=runner).down()

        flattened = [part for call in runner.calls for part in call]
        self.assertEqual(
            [call[-1] for call in runner.calls if call[:2] == ("stop", "--time")],
            list(reversed(tuple(runtime.CONTAINER_NAMES.values()))),
        )
        self.assertFalse(any(token in {"volume", "network", "prune", "rm"} for token in flattened))
        self.assertTrue(all(call[0] in {"stop", "delete"} for call in runner.calls))

    def test_down_removes_an_owned_retired_container(self):
        retired = runtime.RETIRED_CONTAINER_NAMES[0]
        runner = RecordingRunner({retired: owned_inspect()})
        with tempfile.TemporaryDirectory() as root:
            runtime.Stack(self.make_config(root), runner=runner).down()

        self.assertIn(("stop", "--time", "10", retired), runner.calls)
        self.assertIn(("delete", retired), runner.calls)
        self.assertNotIn(retired, runtime.CONTAINER_NAMES.values())

    def test_down_fails_closed_on_foreign_name_collision(self):
        name = runtime.CONTAINER_NAMES["nginx"]
        runner = RecordingRunner(
            {
                name: [
                    {
                        "configuration": {"labels": {runtime.OWNER_LABEL: "someone-else"}},
                        "status": {"state": "running"},
                    }
                ]
            }
        )
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=runner)
            with self.assertRaisesRegex(runtime.RuntimeFailure, "foreign container"):
                stack.down()

        self.assertEqual(runner.calls, [])

    def test_prepare_state_creates_metrics_directories(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            stack._prepare_state()

            self.assertTrue((config.storage / "metrics").exists())
            self.assertTrue((config.generated / "metrics").exists())
            self.assertTrue((config.storage / "metrics").is_dir())
            self.assertTrue((config.generated / "metrics").is_dir())

    def test_container_specs_publish_ports_unchanged(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root, mailpit_port=18091)
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            ips = {
                "postgres": "192.168.64.10",
                "redis": "192.168.64.11",
                "elasticsearch": "192.168.64.12",
                "mailpit": "192.168.64.13",
                "fpm": "192.168.64.14",
                "looper": "192.168.64.15",
            }
            specs = stack.plan(ips)

            published = {spec.role: spec.publishes for spec in specs}
            self.assertEqual(published["nginx"], ("127.0.0.1:18090:80",))
            self.assertEqual(published["mailpit"], ("127.0.0.1:18091:8025",))
            self.assertEqual(published["postgres"], ())
            self.assertEqual(published["redis"], ())
            self.assertEqual(published["elasticsearch"], ())
            self.assertEqual(published["fpm"], ())

    def test_container_specs_mounts_unchanged(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            ips = {
                "postgres": "192.168.64.10",
                "redis": "192.168.64.11",
                "elasticsearch": "192.168.64.12",
                "mailpit": "192.168.64.13",
                "fpm": "192.168.64.14",
                "looper": "192.168.64.15",
            }
            specs = stack.plan(ips)
            by_role = {spec.role: spec for spec in specs}

            postgres = by_role["postgres"]
            self.assertEqual(len(postgres.mounts), 0)
            self.assertEqual(postgres.named_volumes, ((runtime.VOLUME_NAMES["postgres"], "/var/lib/postgresql/data"),))

            redis = by_role["redis"]
            self.assertEqual(len(redis.mounts), 0)
            self.assertEqual(redis.named_volumes, ((runtime.VOLUME_NAMES["redis"], "/data"),))

            elasticsearch = by_role["elasticsearch"]
            self.assertEqual(len(elasticsearch.mounts), 0)
            self.assertEqual(elasticsearch.named_volumes, ((runtime.VOLUME_NAMES["elasticsearch"], "/usr/share/elasticsearch/data"),))

            nginx = by_role["nginx"]
            self.assertTrue(any(m.target == "/var/www/html/public" and m.readonly for m in nginx.mounts))
            self.assertTrue(any(m.target == "/var/www/html/storage" and m.readonly for m in nginx.mounts))
            self.assertTrue(any(m.target == "/etc/nginx/conf.d" and m.readonly for m in nginx.mounts))


    def test_status_exposes_collector_snapshot_age_and_signals(self):
        import io
        import contextlib
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            stack._prepare_state()
            snapshot = config.system_metrics_snapshot
            snapshot.parent.mkdir(parents=True, exist_ok=True)
            snapshot.write_text('{"signals": {"cpu": {"available": true}, "memory": {"available": false}, "gpu": {"available": true}}}')

            f = io.StringIO()
            with contextlib.redirect_stdout(f):
                stack.status()
            output = f.getvalue()

            self.assertIn("collector:", output)
            self.assertIn("age=", output)
            self.assertIn("cpu=", output)
            self.assertIn("memory=", output)
            self.assertIn("gpu=", output)
            self.assertIn("running=", output)
            self.assertIn("owned=", output)

    def test_down_stops_collector_before_deleting_containers(self):
        events = []

        class EventTrackingRunner(RecordingRunner):
            def run(self, args, **kwargs):
                if args[:2] == ("stop", "--time") or args[0] == "delete":
                    events.append(("container", args[0]))
                return super().run(args, **kwargs)

        class EventTrackingCollectorManager(MockCollectorManager):
            def stop(self):
                events.append(("collector", "stop"))
                super().stop()

        inspections = {name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()}
        runner = EventTrackingRunner(inspections)
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=runner)
            stack.collector_manager = EventTrackingCollectorManager()
            stack.down()

            self.assertTrue(any(e[0] == "collector" and e[1] == "stop" for e in events))
            collector_stop_idx = next(i for i, e in enumerate(events) if e[0] == "collector" and e[1] == "stop")
            container_ops = [i for i, e in enumerate(events) if e[0] == "container"]
            self.assertTrue(all(collector_stop_idx < idx for idx in container_ops),
                           "collector must stop before any container operations")

    def test_collector_manager_accepts_runtime_config_python_executable(self):
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=RecordingRunner({}))
            self.assertIsNotNone(stack.collector_manager)

    def test_verify_fails_if_collector_not_running(self):
        inspections = {name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()}
        runner = RecordingRunner(inspections)
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=runner)
            stack.collector_manager = MockCollectorManager()
            stack.collector_manager.status = lambda *, now: MockCollectorManager().status(now=now)
            with self.assertRaisesRegex(runtime.RuntimeFailure, "collector is not running or not owned"):
                stack.verify()

    def test_verify_fails_if_collector_not_owned(self):
        inspections = {name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()}
        runner = RecordingRunner(inspections)
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)

            class UnownedCollectorManager(MockCollectorManager):
                def status(self, *, now):
                    return MockCollectorManager.StatusMock(
                        pid=1234,
                        running=True,
                        owned=False,
                        snapshot_age_seconds=1.0,
                        signals={"cpu": False, "memory": False, "gpu": False}
                    )

            stack = runtime.Stack(config, runner=runner)
            stack.collector_manager = UnownedCollectorManager()
            with self.assertRaisesRegex(runtime.RuntimeFailure, "collector is not running or not owned"):
                stack.verify()

    def test_verify_fails_when_collector_snapshot_is_missing_or_stale(self):
        inspections = {name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()}
        for age in (None, 15.5):
            with tempfile.TemporaryDirectory() as root:
                stack = runtime.Stack(self.make_config(root), runner=RecordingRunner(inspections))
                stack.collector_snapshot_wait_seconds = 0.0

                class SilentCollectorManager(MockCollectorManager):
                    def status(self, *, now):
                        return MockCollectorManager.StatusMock(
                            pid=1234,
                            running=True,
                            owned=True,
                            snapshot_age_seconds=age,
                            signals={"cpu": False, "memory": False, "gpu": False},
                        )

                stack.collector_manager = SilentCollectorManager()
                with self.assertRaisesRegex(runtime.RuntimeFailure, "snapshot is missing or older"):
                    stack.verify()

    def test_verify_fails_when_collector_dies_during_the_snapshot_wait(self):
        import unittest.mock

        inspections = {name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()}
        with tempfile.TemporaryDirectory() as root:
            stack = runtime.Stack(self.make_config(root), runner=RecordingRunner(inspections))
            # First poll: alive without a snapshot. Second poll: dead, leaving a fresh snapshot behind.
            polls = [(True, None), (False, 2.0)]

            class DyingCollectorManager(MockCollectorManager):
                def status(self, *, now):
                    running, age = polls.pop(0) if len(polls) > 1 else polls[0]
                    return MockCollectorManager.StatusMock(
                        pid=1234,
                        running=running,
                        owned=True,
                        snapshot_age_seconds=age,
                        signals={"cpu": True, "memory": True, "gpu": True},
                    )

            stack.collector_manager = DyingCollectorManager()
            with unittest.mock.patch.object(runtime.time, "sleep", lambda seconds: None):
                with self.assertRaisesRegex(runtime.RuntimeFailure, "collector is not running or not owned"):
                    stack.verify()

    def test_verify_reports_collector_signals_availability(self):
        import io
        import contextlib

        inspections = {name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()}
        runner = RecordingRunner(inspections)
        with tempfile.TemporaryDirectory() as root:
            config = self.make_config(root)
            stack = runtime.Stack(config, runner=runner)

            class FakeEventLog:
                def __init__(self):
                    self.events = []

                def write(self, level, event, loc, ctx=None):
                    self.events.append({"level": level, "event": event, "loc": loc, "ctx": ctx or {}})

            fake_events = FakeEventLog()
            stack.events = fake_events

            class OwnedCollectorManager(MockCollectorManager):
                def status(self, *, now):
                    from studio_system_metrics_process import CollectorStatus
                    return CollectorStatus(
                        pid=1234,
                        running=True,
                        owned=True,
                        snapshot_age_seconds=1.0,
                        signals={"cpu": True, "memory": False, "gpu": True}
                    )

            stack.collector_manager = OwnedCollectorManager()

            # Stub the complete HTTP path so verify() is fully hermetic: no real
            # traffic to 127.0.0.1:18090. login_body references one static asset
            # so the static-asset-verification loop also stays within the stub.
            base = "http://127.0.0.1:{}".format(config.web_port)
            login_body = '<html><body><link rel="stylesheet" href="/app.css"></body></html>'
            fetched_urls = []

            def fake_fetch(url):
                fetched_urls.append(url)
                if url == base + "/admin/login":
                    return login_body, "text/html"
                return "", "text/plain"

            stack._fetch = fake_fetch
            # The looper and worker probes belong to other contracts; keep this test on the collector.
            stack._container_ip = lambda name: "192.168.64.20"
            stack._require_http = lambda *args, **kwargs: None
            stack._wait_artisan = lambda **kwargs: None
            config.state.mkdir(parents=True, exist_ok=True)
            config.env_file.write_text("")

            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                stack.verify()

            self.assertIn(base + "/up", fetched_urls)
            self.assertIn(base + "/admin/login", fetched_urls)
            self.assertIn(base + "/app.css", fetched_urls)

            collector_signal_events = [e for e in fake_events.events if e["event"] == "collector.signals"]
            self.assertEqual(len(collector_signal_events), 1)
            event = collector_signal_events[0]
            self.assertEqual(event["level"], "info")
            self.assertEqual(event["loc"], "Stack.verify")
            self.assertEqual(event["ctx"]["cpu"], True)
            self.assertEqual(event["ctx"]["memory"], False)
            self.assertEqual(event["ctx"]["gpu"], True)
            self.assertNotIn("value", str(event["ctx"]))
            self.assertNotIn("percent", str(event["ctx"]))
            self.assertNotIn("bytes", str(event["ctx"]))

            verify_passed_events = [e for e in fake_events.events if e["event"] == "verify.passed"]
            self.assertEqual(len(verify_passed_events), 1)

    def test_main_translates_collector_ownership_error_without_traceback(self):
        import io
        import contextlib
        import unittest.mock

        created_stacks = []

        class FakeEventLogForMain:
            def __init__(self):
                self.events = []

            def write(self, level, event, loc, ctx=None):
                self.events.append({"level": level, "event": event, "loc": loc, "ctx": ctx or {}})

        class FakeStack:
            def __init__(self, config):
                self.config = config
                self.events = FakeEventLogForMain()
                created_stacks.append(self)

            def up(self):
                raise runtime.system_metrics_process.CollectorOwnershipError(
                    "live process command does not match expected for PID 412"
                )

        with tempfile.TemporaryDirectory() as root:
            argv = [
                "--source", str(pathlib.Path(root) / "source"),
                "--state", str(pathlib.Path(root) / "state"),
                "--fpm-image", "mothership-fpm:test",
                "--queue-image", "mothership-q:test",
                "up",
            ]

            stderr = io.StringIO()
            with unittest.mock.patch.object(runtime, "Stack", FakeStack):
                with contextlib.redirect_stderr(stderr):
                    exit_code = runtime.main(argv)

        self.assertEqual(exit_code, 1)
        self.assertIn("error: live process command does not match expected for PID 412", stderr.getvalue())
        self.assertEqual(len(created_stacks), 1)
        failed_events = [e for e in created_stacks[0].events.events if e["event"] == "runtime.failed"]
        self.assertEqual(len(failed_events), 1)
        self.assertEqual(failed_events[0]["level"], "error")
        self.assertEqual(failed_events[0]["ctx"]["command"], "up")
        self.assertIn("does not match expected", failed_events[0]["ctx"]["message"])

    def test_parse_args_python_executable_override_flows_to_expected_command(self):
        with tempfile.TemporaryDirectory() as root:
            source = pathlib.Path(root) / "source"
            state = pathlib.Path(root) / "state"
            args = runtime.parse_args(
                [
                    "--source", str(source),
                    "--state", str(state),
                    "--python-executable", "/usr/bin/python3",
                    "status",
                ]
            )
            self.assertEqual(args.python_executable, "/usr/bin/python3")

            config = runtime.RuntimeConfig(
                source=source,
                state=state,
                python_executable=args.python_executable,
            )
            self.assertEqual(config.system_metrics_python_executable, "/usr/bin/python3")

            stack = runtime.Stack(config, runner=RecordingRunner({}))
            expected = stack.collector_manager.expected_command()
            self.assertEqual(expected[0], "/usr/bin/python3")

    def test_lifecycle_command_without_image_tags_fails_closed(self):
        # The launcher once defaulted to retired b6ec874 images; tags change every deploy.
        with tempfile.TemporaryDirectory() as root, mock.patch.object(runtime, "Stack") as stack:
            code = runtime.main(["--source", root, "--state", root, "--fpm-image", "f:1", "up"])
            self.assertEqual(code, 2)
            stack.assert_not_called()

    def test_parse_args_default_python_executable_flows_to_sys_executable(self):
        with tempfile.TemporaryDirectory() as root:
            source = pathlib.Path(root) / "source"
            state = pathlib.Path(root) / "state"
            args = runtime.parse_args(
                [
                    "--source", str(source),
                    "--state", str(state),
                    "status",
                ]
            )
            self.assertIsNone(args.python_executable)

            config = runtime.RuntimeConfig(
                source=source,
                state=state,
                python_executable=args.python_executable,
            )
            self.assertEqual(config.system_metrics_python_executable, sys.executable)

            stack = runtime.Stack(config, runner=RecordingRunner({}))
            expected = stack.collector_manager.expected_command()
            self.assertEqual(expected[0], sys.executable)

    def test_runtime_reuses_already_loaded_metrics_process_module(self):
        metrics_path = pathlib.Path(__file__).parents[1] / "system_metrics_process.py"
        original_module = sys.modules.get("studio_system_metrics_process")

        def restore():
            if original_module is not None:
                sys.modules["studio_system_metrics_process"] = original_module
            else:
                sys.modules.pop("studio_system_metrics_process", None)

        self.addCleanup(restore)

        preexisting_spec = importlib.util.spec_from_file_location(
            "studio_system_metrics_process", metrics_path
        )
        preexisting_module = importlib.util.module_from_spec(preexisting_spec)
        sys.modules["studio_system_metrics_process"] = preexisting_module
        preexisting_spec.loader.exec_module(preexisting_module)

        fresh_spec = importlib.util.spec_from_file_location(
            "studio_runtime_reload_check", RUNTIME_PATH
        )
        fresh_runtime = importlib.util.module_from_spec(fresh_spec)
        sys.modules[fresh_spec.name] = fresh_runtime
        self.addCleanup(lambda: sys.modules.pop(fresh_spec.name, None))
        fresh_spec.loader.exec_module(fresh_runtime)

        self.assertIs(fresh_runtime.system_metrics_process, preexisting_module)
        self.assertIs(
            fresh_runtime.system_metrics_process.CollectorOwnershipError,
            preexisting_module.CollectorOwnershipError,
        )


class MockCollectorManager:
    class StatusMock:
        def __init__(self, pid, running, owned, snapshot_age_seconds, signals):
            self.pid = pid
            self.running = running
            self.owned = owned
            self.snapshot_age_seconds = snapshot_age_seconds
            self.signals = signals

    def __init__(self):
        self.stop_called = False

    def stop(self):
        self.stop_called = True

    def start(self):
        pass

    def status(self, *, now):
        return MockCollectorManager.StatusMock(
            pid=None,
            running=False,
            owned=False,
            snapshot_age_seconds=None,
            signals={}
        )


class StageNamespaceTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        root = pathlib.Path(self.temporary.name)
        self.config = runtime.RuntimeConfig(
            source=root / "source", state=root / "stage", native_state=root / "stage-native",
            production_state=root / "production", instance="stage", web_only=True,
            web_port=28090, fpm_image="fpm:test", queue_image="queue:test",
        )
        self.config.state.mkdir()
        self.config.production_state.mkdir()
        self.environment = {
            "APP_KEY": "base64:" + runtime.base64.b64encode(bytes(range(32))).decode(),
            "DB_DATABASE": "mothership_stage", "DB_USERNAME": "mothership_stage",
            "DB_" + "PASSWORD": "s", "APP_URL": "http://127.0.0.1:28090",
        }
        self.write_environment()
        (self.config.production_state / "app.env").write_text("APP_KEY=p\nDB_PASSWORD=p\n")
        self.runner = RecordingRunner({})
        self.runner.network_names = mock.Mock(return_value=set())
        self.runner.volume_names = mock.Mock(return_value=set())
        self.relay = RecordingRelay()
        self.stack = runtime.Stack(self.config, runner=self.runner, relay=self.relay)
        self.stack.collector_manager = mock.Mock()
        self.ns = self.config.namespace
        self.ips = {role: "192.168.165.10" for role in runtime.WEB_ROLES}

    def write_environment(self):
        self.config.env_file.write_text("".join(key + "=" + value + "\n" for key, value in self.environment.items()))
        self.config.env_file.chmod(0o600)

    def owned(self, state="running"):
        return {"configuration": {"labels": {runtime.OWNER_LABEL: self.ns.owner}},
                "status": {"state": state, "ipv4Address": "192.168.165.10/24"}}

    def test_all_resource_names_and_paths_are_disjoint_and_immutable(self):
        def names(ns):
            return set(ns.containers.values()) | set(ns.volumes.values()) | set(ns.retired) | {ns.initializer, ns.network}
        production = runtime.PRODUCTION_NAMESPACE
        self.assertFalse(names(self.ns) & names(production))
        self.assertEqual(len(names(self.ns)), len(names(production)))
        self.assertNotEqual(self.ns.owner, production.owner)
        self.assertFalse(runtime.ipaddress.ip_network(self.ns.subnet).overlaps(runtime.ipaddress.ip_network(production.subnet)))
        with self.assertRaises(TypeError):
            self.ns.containers["fpm"] = production.containers["fpm"]
        with self.assertRaises(AttributeError):
            self.ns.owner = production.owner
        self.config.validate()
        self.assertNotEqual(self.config.native_services, self.config.production_state.parent / "Mothership Native Services")

    def test_invalid_namespace_paths_ports_and_modes_fail_closed(self):
        for changes in ({"owner": runtime.OWNER_VALUE}, {"subnet": runtime.NETWORK_SUBNET},
                        {"network": runtime.NETWORK_NAME}, {"subnet": "invalid"}):
            with self.subTest(changes=changes), self.assertRaises(runtime.RuntimeFailure):
                runtime.replace(self.ns, **changes)
        for changes in (
            {"state": self.config.production_state}, {"state": self.config.production_state / "child"},
            {"native_state": self.config.state}, {"native_state": self.config.production_state},
            {"native_state": self.config.production_state.parent / "Mothership Native Services"},
            {"state": self.config.source}, {"production_state": None}, {"native_state": None},
            {"web_port": 18090}, {"web_port": 0}, {"mailpit_port": 28090},
            {"web_only": False}, {"web_transport": "relay"}, {"instance": "other"},
        ):
            with self.subTest(changes=changes), self.assertRaises(runtime.RuntimeFailure):
                runtime.Stack(runtime.replace(self.config, **changes), runner=self.runner)
        alias = self.config.state / "storage"
        alias.symlink_to(self.config.production_state, target_is_directory=True)
        with self.assertRaisesRegex(runtime.RuntimeFailure, "symlinks"):
            self.config.validate()
        alias.unlink()
        os.link(self.config.production_state / "app.env", self.config.state / "linked")
        with self.assertRaisesRegex(runtime.RuntimeFailure, "hardlinks"):
            self.config.validate()

    def test_darwin_symlinked_ancestor_alias_is_accepted(self):
        root = pathlib.Path(self.temporary.name)
        alias = root / "private-tmp"
        alias.symlink_to(root, target_is_directory=True)
        config = dataclasses.replace(
            self.config, state=alias / "stage-alias", native_state=alias / "native-alias",
        )
        config.state.mkdir()
        config.native_state.mkdir()
        config.validate()

    def test_stage_network_accepts_apple_inspect_shape(self):
        self.runner.network_names.return_value = {self.ns.network, "unrelated"}
        self.runner.inspect_network = mock.Mock(side_effect=lambda name: (
            {"configuration": {"labels": {runtime.OWNER_LABEL: self.ns.owner}},
             "status": {"ipv4Subnet": self.ns.subnet, "subnets": [{"subnet": self.ns.subnet,
                       "ipv4Gateway": "192.168.165.1"}]}}
            if name == self.ns.network else {"status": {"ipv4Subnet": "192.168.64.0/24"}}
        ))
        self.stack._stage_preflight()

    def test_stage_network_unknown_foreign_subnet_fails_closed(self):
        self.runner.network_names.return_value = {"unrelated"}
        self.runner.inspect_network = mock.Mock(side_effect=runtime.RuntimeFailure("uninspectable"))
        with self.assertRaisesRegex(runtime.RuntimeFailure, "cannot inspect existing network unrelated"):
            self.stack._stage_preflight(validate_env=False)

    def test_stage_network_ipv6_only_foreign_subnet_fails_closed(self):
        self.runner.network_names.return_value = {"unrelated"}
        self.runner.inspect_network = mock.Mock(return_value={
            "status": {"subnets": [{"subnet": "fd00::/64"}]},
        })
        with self.assertRaisesRegex(runtime.RuntimeFailure, "cannot prove existing network subnet isolation"):
            self.stack._stage_preflight()
        self.assertEqual(self.runner.calls, [])

    def test_stage_cleanup_does_not_need_valid_env_or_foreign_network_inspection(self):
        self.config.env_file.unlink()
        self.runner.network_names.return_value = {"unrelated"}
        self.runner.inspect_network = mock.Mock(side_effect=runtime.RuntimeFailure("uninspectable"))
        self.stack.down()

    def test_alias_appearing_in_storage_blocks_up_but_not_cli_down_or_status(self):
        storage = self.config.storage
        storage.mkdir()
        argv = [
            "--source", str(self.config.source), "--state", str(self.config.state),
            "--production-state", str(self.config.production_state),
            "--native-state", str(self.config.native_state), "--instance", "stage",
            "--web-only", "--fpm-image", "fpm:test",
        ]
        for kind in ("symlink", "hardlink"):
            alias = storage / kind
            if kind == "symlink":
                alias.symlink_to(self.config.production_state / "app.env")
            else:
                os.link(self.config.production_state / "app.env", alias)
            try:
                with self.subTest(kind=kind):
                    stack = runtime.Stack(self.config, runner=self.runner, relay=self.relay)
                    self.config.validate_stage_env()
                    stack.plan(self.ips)
                    with mock.patch.object(runtime, "Runner", return_value=self.runner), \
                         mock.patch.object(runtime, "RelayManager", return_value=self.relay), \
                         contextlib.redirect_stdout(io.StringIO()):
                        self.assertEqual(runtime.main([*argv, "status"]), 0)
                        self.assertEqual(runtime.main([*argv, "down"]), 0)
                    with self.assertRaisesRegex(runtime.RuntimeFailure, "symlinks or hardlinks"):
                        stack.up()
                    self.assertEqual(self.runner.calls, [])
            finally:
                alias.unlink()

    def test_unsanitized_env_is_rejected_without_mutation(self):
        original = dict(self.environment)
        for key, value in (("STYLEPRESS_HIGGSFIELD_DRIVER", "subscription"), ("MOTHERSHIP_AI_API", "http://production"),
                           ("PROVIDER_" + "TOKEN", "x"), ("APP_URL", "https://production"),
                           ("DB_HOST", "192.168.164.2"), ("APP_KEY", "p"), ("DB_PASSWORD", "p"),
                           ("QUEUE_CONNECTION", "sync"), ("APP_KEY", "${PRODUCTION}")):
            self.environment = {**original, key: value}
            self.write_environment()
            with self.subTest(key=key), self.assertRaises(runtime.RuntimeFailure):
                self.stack.up()
            self.assertEqual(self.runner.calls, [])
            self.assertEqual(self.relay.calls, [])
            self.stack.collector_manager.start.assert_not_called()

    def test_stage_plan_is_inspectable_without_runtime_queries_or_secret_output(self):
        self.runner.list_container_names = mock.Mock(side_effect=AssertionError("runtime queried"))
        with contextlib.redirect_stdout(io.StringIO()) as output:
            self.stack.describe_plan()
        plan = json.loads(output.getvalue())
        self.assertEqual(set(plan["containers"]), set(runtime.WEB_ROLES))
        self.assertEqual(plan["external_bridges"], [])
        self.assertNotIn("APP_KEY", output.getvalue())
        specs = self.stack.plan(self.ips)
        self.assertEqual(tuple(spec.role for spec in specs), runtime.WEB_ROLES)
        for spec in specs:
            args = spec.run_args()
            self.assertEqual(args[args.index("--network") + 1], self.ns.network)
            self.assertIn(runtime.OWNER_LABEL + "=" + self.ns.owner, args)
            self.assertFalse(any("higgsfield" in mount.target for mount in spec.mounts))
        fpm = next(spec for spec in specs if spec.role == "fpm")
        self.assertIn("STYLEPRESS_ENABLED=false", fpm.env)
        self.assertIn((self.config.generated / "fpm", "/studio-runtime", True),
                      {(mount.source, mount.target, mount.readonly) for mount in fpm.mounts})
        self.assertFalse(any(mount.target == "/studio-runtime/app.env" for mount in fpm.mounts))
        nginx = next(spec for spec in specs if spec.role == "nginx")
        self.assertEqual(nginx.publishes, ("127.0.0.1:28090:80",))

    def test_foreign_staging_collisions_refuse_all_mutation(self):
        for kind, name in (("container", self.ns.retired[0]), ("container", self.ns.initializer),
                           ("container", self.ns.containers["scheduler"]),
                           ("volume", self.ns.volumes["redis"]), ("network", self.ns.network)):
            self.runner.inspections = {name: owned_inspect()}
            self.runner.volume_names.return_value = {name} if kind == "volume" else set()
            self.runner.network_names.return_value = {name} if kind == "network" else set()
            for verb in (self.stack.up, self.stack.down):
                if kind == "network" and verb == self.stack.down:
                    verb()
                    continue
                with self.subTest(kind=kind, verb=verb.__name__), self.assertRaisesRegex(runtime.RuntimeFailure, "foreign"):
                    verb()
                self.assertEqual(self.runner.calls, [])
                self.assertEqual(self.relay.calls, [])
                self.stack.collector_manager.assert_not_called()

    def test_network_overlap_wrong_subnet_and_unknown_subnet_refused(self):
        for name, subnet, reason in (("foreign", self.ns.subnet, "collides"),
                                     (self.ns.network, runtime.NETWORK_SUBNET, "does not prove"),
                                     ("foreign", None, "cannot prove")):
            inspection = self.owned()
            inspection.pop("status")
            if subnet:
                inspection["configuration"]["subnet"] = subnet
            self.runner.inspections = {name: inspection}
            self.runner.network_names.return_value = {name}
            with self.subTest(name=name, subnet=subnet), self.assertRaisesRegex(runtime.RuntimeFailure, reason):
                self.stack._stage_preflight()
            self.assertEqual(self.runner.calls, [])

    def test_occupied_stage_port_fails_before_mutation(self):
        with mock.patch.object(runtime.socket, "socket") as socket_factory:
            socket_factory.return_value.__enter__.return_value.bind.side_effect = OSError("occupied")
            with self.assertRaisesRegex(runtime.RuntimeFailure, "occupied"):
                self.stack.up()
        self.assertEqual(self.runner.calls, [])

    def test_stage_entrypoint_scrubs_baked_environment_and_parses_as_bash(self):
        self.stack._prepare_state()
        script = self.config.generated / "fpm" / "entrypoint.sh"
        result = subprocess.run(["/bin/bash", "-n", str(script)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(self.config.higgsfield_session.exists())
        # Execute only the generated environment scrub, never the container startup body.
        prefix = script.read_text().split("ROOT=/var/www/html", 1)[0].split("rm -f", 1)[0]
        environment = {**os.environ, "PROVIDER_TOKEN": "x", "MOTHERSHIP_BUILD_COMMIT": "abc"}
        result = subprocess.run(["/bin/bash", "-c", prefix + '\nprintenv'],
                                env=environment, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("PROVIDER_TOKEN", result.stdout)
        self.assertIn("MOTHERSHIP_BUILD_COMMIT=abc", result.stdout)
        generated_env = self.config.generated / "fpm" / "app.env"
        self.assertEqual(generated_env.stat().st_mode & 0o777, 0o600)
        fpm = next(spec for spec in self.stack.plan(self.ips) if spec.role == "fpm")
        self.assertIn((generated_env.parent, "/studio-runtime", True),
                      {(mount.source, mount.target, mount.readonly) for mount in fpm.mounts})
        self.assertFalse(any(mount.target == "/studio-runtime/app.env" for mount in fpm.mounts))
        entrypoint = script.read_text()
        self.assertIn("install -o www-data -g www-data -m 600 /studio-runtime/app.env /var/www/html/.env", entrypoint)
        self.assertNotIn("target=\"/var/www/html/.env\"", entrypoint)

    def test_stage_generated_env_repairs_mode_and_fails_if_chmod_is_denied(self):
        self.stack._prepare_state()
        generated_env = self.config.generated / "fpm" / "app.env"
        original_content = generated_env.read_text(encoding="utf-8")
        generated_env.chmod(0o644)
        self.stack._prepare_state()
        self.assertEqual(generated_env.read_text(encoding="utf-8"), original_content)
        self.assertEqual(generated_env.stat().st_mode & 0o777, 0o600)

        generated_env.chmod(0o644)
        original_chmod = pathlib.Path.chmod

        def deny_generated_env_chmod(path, mode, *args, **kwargs):
            if path == generated_env:
                raise PermissionError("mode change denied")
            return original_chmod(path, mode, *args, **kwargs)

        with mock.patch.object(pathlib.Path, "chmod", new=deny_generated_env_chmod):
            with self.assertRaisesRegex(PermissionError, "mode change denied"):
                self.stack._prepare_state()
        self.assertEqual(generated_env.stat().st_mode & 0o777, 0o644)

    def test_stage_entrypoint_anchor_is_fail_closed(self):
        original = runtime.render_fpm_entrypoint
        for rendered in (original().replace("ROOT=/var/www/html", "ROOT=/var/www/other"),
                         original().replace("ROOT=/var/www/html", "ROOT=/var/www/html\nROOT=/var/www/html")):
            with self.subTest(rendered=rendered[:20]), mock.patch.object(runtime, "render_fpm_entrypoint", return_value=rendered):
                with self.assertRaisesRegex(runtime.RuntimeFailure, "anchor"):
                    self.stack._prepare_state()

    def test_stage_fpm_has_one_value_per_environment_key(self):
        fpm = next(spec for spec in self.stack.plan(self.ips) if spec.role == "fpm")
        keys = [item.split("=", 1)[0] for item in fpm.env]
        self.assertEqual(len(keys), len(set(keys)))
        self.assertEqual([item for item in fpm.env if item.startswith("APP_ENV=")], ["APP_ENV=staging"])

    def test_stage_network_and_volume_creation_uses_only_stage_labels_and_names(self):
        self.stack._ensure_network_and_volumes()
        self.assertEqual(self.runner.calls[0], tuple(runtime.network_create_args(namespace=self.ns)))
        self.assertEqual({call[-1] for call in self.runner.calls[1:]}, set(self.ns.volumes.values()))
        for call in self.runner.calls:
            self.assertIn(runtime.OWNER_LABEL + "=" + self.ns.owner, call)

    def test_duplicate_env_key_and_shared_app_key_are_rejected(self):
        with self.config.env_file.open("a") as handle:
            handle.write("APP_URL=http://127.0.0.1:28090\n")
        with self.assertRaisesRegex(runtime.RuntimeFailure, "duplicate"):
            self.stack.describe_plan()
        self.write_environment()
        (self.config.production_state / "app.env").write_text(self.config.env_file.read_text())
        with self.assertRaisesRegex(runtime.RuntimeFailure, "distinct"):
            self.stack.describe_plan()

    def test_down_and_initializer_cleanup_only_select_stage(self):
        names = tuple(self.ns.containers.values()) + self.ns.retired + (self.ns.initializer,)
        self.runner.inspections = {name: self.owned() for name in names}
        self.runner.inspections.update({name: owned_inspect() for name in runtime.CONTAINER_NAMES.values()})
        self.runner.inspections[runtime.RETIRED_CONTAINER_NAMES[0]] = owned_inspect()
        self.stack.down()
        self.assertEqual({call[-1] for call in self.runner.calls}, set(names))
        self.assertEqual(self.relay.calls, [])
        self.stack.collector_manager.stop.assert_not_called()
        with self.assertRaisesRegex(runtime.RuntimeFailure, "outside"):
            self.stack._delete_containers(runtime.RETIRED_CONTAINER_NAMES, loc="test")
        self.runner.calls.clear()
        self.runner.inspections = {self.ns.initializer: self.owned(state="stopped"),
                                   self.ns.volumes["elasticsearch"]: self.owned()}
        self.stack._initialize_elasticsearch_volume()
        self.assertEqual(self.runner.calls[0], ("delete", self.ns.initializer))
        self.assertEqual(self.runner.calls[1], tuple(runtime.elasticsearch_volume_init_args(namespace=self.ns)))
        self.assertIn(self.ns.network, self.runner.calls[1])

    def test_up_only_starts_stage_web_roles_and_removes_stage_workers(self):
        retired = self.ns.retired[0]
        worker = self.ns.containers["queue"]
        self.runner.inspections = {retired: self.owned(), worker: self.owned(),
                                   runtime.RETIRED_CONTAINER_NAMES[0]: owned_inspect()}
        with contextlib.ExitStack() as patches:
            for method in ("_stage_port_preflight", "_require_builder_stopped", "_prepare_state",
                           "_ensure_network_and_volumes", "_initialize_elasticsearch_volume", "_wait", "_wait_exec"):
                patches.enter_context(mock.patch.object(self.stack, method))
            verification = patches.enter_context(mock.patch.object(self.stack, "verify"))
            patches.enter_context(mock.patch.object(self.stack, "_validate_up", return_value=self.environment))
            patches.enter_context(mock.patch.object(self.stack, "_container_ip", return_value="192.168.165.10"))
            writes = patches.enter_context(mock.patch.object(runtime, "_atomic_write"))
            bridges = patches.enter_context(mock.patch.object(self.stack, "_start_native_bridges"))
            self.stack.up()
        # The stage stack resolves store hostnames through its own gateway, never production's.
        nginx = next(c.kwargs["content"] for c in writes.call_args_list if c.kwargs.get("path", "").name == "default.conf")
        self.assertIn("resolver 192.168.165.1 valid=30s ipv6=off;", nginx)
        self.assertNotIn("192.168.164.1", nginx)
        starts = [call for call in self.runner.calls if call[0] == "run"]
        self.assertEqual({call[call.index("--name") + 1] for call in starts},
                         {self.ns.containers[role] for role in runtime.WEB_ROLES})
        self.assertEqual({call[-1] for call in self.runner.calls if call[0] == "delete"}, {retired, worker})
        self.assertFalse(any("mothership-private" in arg for call in self.runner.calls for arg in call))
        bridges.assert_not_called()
        self.assertEqual(self.relay.calls, [])
        self.stack.collector_manager.start.assert_not_called()
        verification.assert_called_once_with(scan_aliases=False)

    def test_verify_describes_limited_scope_and_rejects_active_workers(self):
        self.runner.inspections = {self.ns.containers[role]: self.owned() for role in runtime.WEB_ROLES}
        with mock.patch.object(self.stack, "_require_http"), mock.patch.object(self.stack, "_fetch", return_value=(
            '<link href="/app.css">', "text/html"
        )), mock.patch.object(self.stack, "_wait_artisan") as workers, contextlib.redirect_stdout(io.StringIO()) as output:
            self.stack.verify()
        self.assertIn("stage web-only", output.getvalue())
        self.assertIn("NOT verified or started", output.getvalue())
        workers.assert_not_called()
        self.stack.collector_manager.status.assert_not_called()
        self.runner.inspections[self.ns.containers["scheduler"]] = self.owned()
        with self.assertRaisesRegex(runtime.RuntimeFailure, "active excluded worker"):
            self.stack.verify()

    def test_production_defaults_remain_exact_and_cli_stage_is_explicit(self):
        args = runtime.parse_args(["--source", "/source", "--state", "/state", "plan"])
        self.assertEqual((args.instance, args.web_port, args.web_only), ("production", 18090, False))
        args = runtime.parse_args(["--source", "/source", "--state", "/state", "--instance", "stage", "--web-only", "plan"])
        self.assertEqual((args.instance, args.web_port, args.web_only), ("stage", 28090, True))
        self.assertEqual(dict(runtime.PRODUCTION_NAMESPACE.containers), runtime.CONTAINER_NAMES)
        self.assertEqual(dict(runtime.PRODUCTION_NAMESPACE.volumes), runtime.VOLUME_NAMES)
        self.assertEqual(runtime.PRODUCTION_NAMESPACE.retired, runtime.RETIRED_CONTAINER_NAMES)
        self.assertEqual(runtime.PRODUCTION_NAMESPACE.initializer, runtime.ELASTICSEARCH_VOLUME_INIT_NAME)
        self.assertNotIn("--network", runtime.elasticsearch_volume_init_args())

    def test_production_digest_and_caps_are_unchanged(self):
        config = runtime.RuntimeConfig(source=pathlib.Path("/source"), state=pathlib.Path("/state"),
                                       fpm_image="fpm:test", queue_image="queue:test")
        stack = runtime.Stack(config, runner=mock.Mock(), relay=mock.Mock())
        specs = {spec.role: spec for spec in stack.plan({role: "127.0.0.1" for role in runtime.WEB_ROLES})}
        self.assertEqual(specs["fpm"].digest(), dataclasses.replace(
            specs["fpm"], network=runtime.NETWORK_NAME, owner=runtime.OWNER_VALUE,
        ).digest())
        self.assertEqual({role: (spec.cpus, spec.memory) for role, spec in specs.items()}, {
            "postgres": (1, "1G"), "redis": (1, "256M"), "elasticsearch": (1, "1G"),
            "mailpit": (1, "256M"), "looper": (2, "4G"), "fpm": (2, "4G"),
            "queue": (2, "2G"), "panorama_queue": (4, "6G"), "stylepress_queue": (1, "1G"),
            "vectorization_queue": (1, "1G"), "ai_index_queue": (1, "1G"), "scheduler": (1, "1G"),
            "nginx": (1, "256M"),
        })

    def test_status_only_inspects_stage_containers_and_never_host_process_records(self):
        self.runner.inspections = {self.ns.containers["fpm"]: self.owned(),
                                   runtime.CONTAINER_NAMES["fpm"]: owned_inspect()}
        with mock.patch.object(self.runner, "inspect", wraps=self.runner.inspect) as inspect:
            with contextlib.redirect_stdout(io.StringIO()) as output:
                self.stack.status()
        self.assertTrue(all(call.args[0] == self.ns.containers["fpm"] for call in inspect.call_args_list))
        self.assertIn("stage web-only", output.getvalue())
        self.assertEqual(self.relay.calls, [])
        self.stack.collector_manager.status.assert_not_called()

    def test_invalid_stage_main_never_logs_into_production_state(self):
        with mock.patch.dict(os.environ, {"STUDIO_LOG": "1"}), contextlib.redirect_stderr(io.StringIO()):
            result = runtime.main([
                "--source", str(self.config.source), "--state", str(self.config.production_state),
                "--production-state", str(self.config.production_state),
                "--native-state", str(self.config.native_state), "--instance", "stage", "--web-only",
                "--fpm-image", "fpm:test", "up",
            ])
        self.assertEqual(result, 1)
        self.assertFalse((self.config.production_state / "events.jsonl").exists())



def listed_image(name, created, digest=None):
    return {"configuration": {"name": name, "creationDate": created,
                              "descriptor": {"digest": digest or "sha256:" + name}}}


def listed_container(name, reference, digest="sha256:unrelated", state="running"):
    return {"configuration": {"id": name, "image": {"reference": reference, "descriptor": {"digest": digest}}},
            "status": {"state": state}}


class ImagePruneTest(unittest.TestCase):
    LIVE = ("mothership-fpm:cccccccccccc", "mothership-q:cccccccccccc")

    def images(self):
        images = []
        for tag, created in (("aaaaaaaaaaaa", "2026-10-24T00:00:00Z"),
                             ("bbbbbbbbbbbb", "2026-10-25T00:00:00Z"),
                             ("cccccccccccc", "2026-10-26T00:00:00Z"),
                             ("999999999999", "2026-10-23T00:00:00Z")):
            images.append(listed_image("mothership-fpm:" + tag, created))
            images.append(listed_image("mothership-q:" + tag, created))
        images += [
            listed_image("mothership-looper:bf4b288", "2026-09-19T21:07:37Z"),
            listed_image("docker.io/library/postgres:16-alpine", "2026-09-17T21:33:33Z"),
            listed_image("craft-figcraft-trial:20261023", "1970-01-01T00:00:00Z"),
            listed_image("docker.io/library/craft-figcraft-trial@sha256:c4c9", "1970-01-01T00:00:00Z"),
        ]
        return images

    def test_keeps_live_and_previous_and_deletes_only_older_fpm_and_queue_tags(self):
        plan = runtime.select_image_prunes(images=self.images(), containers=[], live_images=self.LIVE)

        self.assertEqual(dict(plan.keep), {
            "mothership-fpm:cccccccccccc": "live",
            "mothership-q:cccccccccccc": "live",
            "mothership-fpm:bbbbbbbbbbbb": "previous (rollback)",
            "mothership-q:bbbbbbbbbbbb": "previous (rollback)",
        })
        self.assertEqual(sorted(plan.remove), [
            "mothership-fpm:999999999999", "mothership-fpm:aaaaaaaaaaaa",
            "mothership-q:999999999999", "mothership-q:aaaaaaaaaaaa",
        ])

    def test_skips_a_tag_any_container_references_by_name_or_digest(self):
        containers = [
            listed_container("scratch-stopped", "docker.io/library/mothership-fpm:aaaaaaaaaaaa", state="stopped"),
            listed_container("scratch-digest", "something-else:latest", digest="sha256:mothership-fpm:999999999999"),
        ]
        plan = runtime.select_image_prunes(images=self.images(), containers=containers, live_images=self.LIVE)

        keep = dict(plan.keep)
        self.assertEqual(keep["mothership-fpm:aaaaaaaaaaaa"], "used by container scratch-stopped")
        self.assertEqual(keep["mothership-fpm:999999999999"], "used by container scratch-digest")
        self.assertEqual(sorted(plan.remove), ["mothership-q:999999999999", "mothership-q:aaaaaaaaaaaa"])

    def test_keeps_a_tag_built_after_the_live_one(self):
        images = self.images() + [listed_image("mothership-fpm:dddddddddddd", "2026-10-27T00:00:00Z")]
        plan = runtime.select_image_prunes(images=images, containers=[], live_images=self.LIVE)

        self.assertEqual(dict(plan.keep)["mothership-fpm:dddddddddddd"], "newer than live")
        self.assertEqual(dict(plan.keep)["mothership-fpm:bbbbbbbbbbbb"], "previous (rollback)")

    def test_refuses_when_the_live_tag_is_not_local_or_not_prunable(self):
        with self.assertRaisesRegex(runtime.RuntimeFailure, "not in the local image list"):
            runtime.select_image_prunes(images=self.images(), containers=[],
                                        live_images=("mothership-fpm:eeeeeeeeeeee",))
        with self.assertRaisesRegex(runtime.RuntimeFailure, "not a prunable"):
            runtime.select_image_prunes(images=self.images(), containers=[],
                                        live_images=("mothership-looper:bf4b288",))

    def make_stack(self, root, instance="production"):
        config = runtime.RuntimeConfig(
            source=pathlib.Path(root) / "source",
            state=pathlib.Path(root) / "state",
            web_port=18090,
            mailpit_port=None,
            container_bin="/opt/homebrew/bin/container",
            fpm_image=self.LIVE[0],
            queue_image=self.LIVE[1],
            host_uid=os.getuid(),
            host_gid=os.getgid(),
        )
        runner = RecordingRunner({})
        runner.list_images = self.images
        runner.list_containers = lambda: [
            listed_container(runtime.CONTAINER_NAMES["fpm"], self.LIVE[0]),
            listed_container(runtime.CONTAINER_NAMES["queue"], self.LIVE[1]),
        ]
        return runtime.Stack(config, runner=runner), runner

    def test_prune_is_a_dry_run_unless_apply_and_runs_verify_first(self):
        with tempfile.TemporaryDirectory() as root:
            stack, runner = self.make_stack(root)
            with mock.patch.object(stack, "verify") as verify, contextlib.redirect_stdout(io.StringIO()) as out:
                stack.prune_images(apply=False)
            verify.assert_called_once()
            self.assertEqual(runner.calls, [])
            self.assertIn("would delete mothership-q:aaaaaaaaaaaa", out.getvalue())

            with mock.patch.object(stack, "verify"), contextlib.redirect_stdout(io.StringIO()):
                stack.prune_images(apply=True)
            self.assertEqual(sorted(runner.calls), [
                ("image", "delete", "mothership-fpm:999999999999"),
                ("image", "delete", "mothership-fpm:aaaaaaaaaaaa"),
                ("image", "delete", "mothership-q:999999999999"),
                ("image", "delete", "mothership-q:aaaaaaaaaaaa"),
            ])

    def test_prune_refuses_when_verify_fails_or_the_running_tag_differs(self):
        with tempfile.TemporaryDirectory() as root:
            stack, runner = self.make_stack(root)
            with mock.patch.object(stack, "verify", side_effect=runtime.RuntimeFailure("fpm container is not running")):
                with self.assertRaisesRegex(runtime.RuntimeFailure, "not running"):
                    stack.prune_images(apply=True)
            runner.list_containers = lambda: [
                listed_container(runtime.CONTAINER_NAMES["fpm"], "mothership-fpm:bbbbbbbbbbbb"),
                listed_container(runtime.CONTAINER_NAMES["queue"], self.LIVE[1]),
            ]
            with mock.patch.object(stack, "verify"):
                with self.assertRaisesRegex(runtime.RuntimeFailure, "fpm container runs mothership-fpm:bbbbbbbbbbbb"):
                    stack.prune_images(apply=True)
            self.assertEqual(runner.calls, [])

    def test_parse_args_prune_images_defaults_to_dry_run(self):
        base = ["--source", "/source", "--state", "/state", "--fpm-image", self.LIVE[0], "--queue-image", self.LIVE[1]]
        self.assertFalse(runtime.parse_args(base + ["prune-images"]).apply)
        self.assertTrue(runtime.parse_args(base + ["prune-images", "--apply"]).apply)


if __name__ == "__main__":
    unittest.main()

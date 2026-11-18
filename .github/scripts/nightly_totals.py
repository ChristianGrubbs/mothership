#!/usr/bin/env python3
"""Write one shard's or leg's totals file for the nightly-summary job.

Runs as an `always()` step after the work. It never fails the job: it records what happened.

  nightly_totals.py playwright --results results.json --planned planned.json
  nightly_totals.py junit --dir <folder of JUnit XML files>
  nightly_totals.py status

Common environment: ITEM_KEY, ITEM_LABEL (the job's display name, matched against the jobs API),
ITEM_KIND, ITEM_ADVISORY, JOB_STATUS (job.status), APP_COMMIT (optional, the commit the app under
test reported in its mothership-build-commit meta tag).

Counts: planned, passed, failed, flaky, skipped, not_run. "not_run" is planned work that never
produced a result: tests after Playwright's global timeout or a window cut, or a missing report.
"""

from __future__ import annotations

import argparse
import json
import os
import pathlib
import sys
import xml.etree.ElementTree as ET
from typing import Dict, Iterable, Optional


def walk_tests(suites: Iterable[dict]) -> Iterable[dict]:
    for suite in suites:
        for spec in suite.get("specs", []):
            yield from spec.get("tests", [])
        yield from walk_tests(suite.get("suites", []))


def playwright_counts(results: pathlib.Path, planned: pathlib.Path) -> Dict[str, Optional[int]]:
    planned_total = None
    if planned.is_file():
        try:
            planned_total = sum(1 for _ in walk_tests(json.loads(planned.read_text()).get("suites", [])))
        except ValueError:
            planned_total = None
    # known_failing: tests marked test.fail() for a recorded defect that failed as expected. They count
    # as passed too (the suite is green), but stay visible so a deferred defect is never silent.
    counts = {"planned": planned_total, "passed": 0, "failed": 0, "flaky": 0, "skipped": 0, "not_run": 0, "known_failing": 0}
    if not results.is_file():
        counts["not_run"] = planned_total
        return counts
    try:
        report = json.loads(results.read_text())
    except ValueError:
        counts["not_run"] = planned_total
        return counts
    seen = 0
    for test in walk_tests(report.get("suites", [])):
        seen += 1
        status = test.get("status")
        ran = any(r.get("status") not in (None, "skipped") for r in test.get("results", []))
        if status == "expected":
            counts["passed"] += 1
            if any(a.get("type") == "fail" for a in test.get("annotations", [])):
                counts["known_failing"] += 1
        elif status == "flaky":
            counts["flaky"] += 1
        elif status == "unexpected":
            counts["failed"] += 1
        elif status == "skipped" and test.get("results") and not ran and any(
                a.get("type") in ("skip", "fixme") for a in test.get("annotations", [])):
            counts["skipped"] += 1
        else:
            # No result (global timeout, interrupted worker) or skipped without a skip annotation.
            counts["not_run"] += 1
    if planned_total is not None and planned_total > seen:
        counts["not_run"] += planned_total - seen
    if counts["planned"] is None:
        counts["planned"] = seen
    return counts


def junit_counts(folder: pathlib.Path) -> Dict[str, Optional[int]]:
    counts = {"planned": None, "passed": 0, "failed": 0, "flaky": 0, "skipped": 0, "not_run": None}
    files = sorted(folder.glob("*.xml")) if folder.is_dir() else []
    for path in files:
        try:
            root = ET.parse(path).getroot()
        except ET.ParseError:
            continue
        suites = [root] if root.tag == "testsuite" else list(root.findall("testsuite"))
        for suite in suites:
            tests = int(suite.get("tests", 0))
            failed = int(suite.get("failures", 0)) + int(suite.get("errors", 0))
            skipped = int(suite.get("skipped", 0))
            counts["failed"] += failed
            counts["skipped"] += skipped
            counts["passed"] += max(0, tests - failed - skipped)
    counts["files"] = len(files)
    return counts


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("playwright", "junit", "status"))
    parser.add_argument("--results", type=pathlib.Path)
    parser.add_argument("--planned", type=pathlib.Path)
    parser.add_argument("--dir", type=pathlib.Path)
    args = parser.parse_args()

    folder = pathlib.Path(os.environ.get("RUNNER_TEMP", "/tmp"), "nightly-totals")
    folder.mkdir(parents=True, exist_ok=True)
    target = folder / "{}.json".format(os.environ["ITEM_KEY"])
    if target.is_file() and json.loads(target.read_text()).get("status") == "deferred":
        return 0  # The guard already recorded the deferral.

    counts = None
    if args.mode == "playwright":
        counts = playwright_counts(args.results, args.planned)
    elif args.mode == "junit":
        counts = junit_counts(args.dir)
    window_cut = pathlib.Path(os.environ.get("RUNNER_TEMP", "/tmp"), "nightly-window-cut")
    job_status = os.environ.get("JOB_STATUS", "unknown")
    payload = {
        "schema": 1,
        "key": os.environ["ITEM_KEY"],
        "label": os.environ["ITEM_LABEL"],
        "kind": os.environ.get("ITEM_KIND", args.mode),
        "advisory": os.environ.get("ITEM_ADVISORY", "false") == "true",
        "status": "cancelled" if window_cut.is_file() or job_status == "cancelled" else "executed",
        "reason": "window-closed" if window_cut.is_file() else ("cancelled" if job_status == "cancelled" else ""),
        "job_status": job_status,
        "workflow": os.environ.get("GITHUB_WORKFLOW"),
        "run_id": os.environ.get("GITHUB_RUN_ID"),
        "run_attempt": os.environ.get("GITHUB_RUN_ATTEMPT"),
        "sha": os.environ.get("GITHUB_SHA"),
        "app_commit": os.environ.get("APP_COMMIT") or None,
        "counts": counts,
    }
    target.write_text(json.dumps(payload, indent=2))
    print(json.dumps(payload, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())

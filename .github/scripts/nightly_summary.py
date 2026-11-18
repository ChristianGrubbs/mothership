#!/usr/bin/env python3
"""Nightly summary: one verdict for every shard and leg of this workflow run.

Reads the totals files the jobs uploaded (nightly_guard.py for deferrals, nightly_totals.py for
everything that started) and the run's jobs from the Actions API, then classifies each item:

  executed/passed  ran, and every test it planned passed (flaky-but-passed counts as passed)
  executed/failed  ran, and a test or step failed
  deferred         the guard did not start it (outside the window, not enough window left, or a
                   duplicate of tonight's earlier run)
  cancelled        cancelled after it started (the window closed, or a newer run superseded it)
  not-run          never started, skipped, or started but left planned tests without a result

Per-item test counts also show known_failing: tests marked test.fail() for a recorded defect (they
pass the suite, but a deferred defect stays visible every night).

It fails (red) when any required item is anything but executed/passed. Advisory items (the MySQL,
MariaDB and SQL Server compatibility legs) are listed with their outcome and never turn it red.

When the Nightly gate deferred the whole run (GATE_RUN=false), every job was skipped and is listed
as deferred with the gate's reason. A run deferred as a duplicate is green only when the earlier
run it defers to (GATE_DUPLICATE_OF) has completed successfully; while that run is still going,
or when it failed or was cancelled, this run is red too, so a duplicate never hides missing
evidence.

Writes nightly-summary.json and appends a Markdown table to the job summary.

Environment: GITHUB_TOKEN, GITHUB_REPOSITORY, GITHUB_RUN_ID, GITHUB_RUN_ATTEMPT, GITHUB_SHA,
TOTALS_DIR, IGNORE_JOBS (comma-separated job names that are not part of the nightly, e.g. the PR
leg), SUMMARY_JOB (this job's own name), GATE_JOB, GATE_RUN, GATE_REASON, GATE_DUPLICATE_OF.
"""

from __future__ import annotations

import json
import os
import pathlib
import sys
import urllib.request
from collections import Counter
from typing import Dict, List, Optional

ORDER = ("executed/passed", "executed/failed", "deferred", "cancelled", "not-run")
TEST_KEYS = ("planned", "passed", "failed", "flaky", "skipped", "not_run", "known_failing")


def api(path: str) -> dict:
    request = urllib.request.Request(
        "{}/repos/{}{}".format(os.environ.get("GITHUB_API_URL", "https://api.github.com"),
                               os.environ["GITHUB_REPOSITORY"], path),
        headers={"Authorization": "Bearer {}".format(os.environ["GITHUB_TOKEN"]),
                 "Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28"},
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.loads(response.read())


def predecessor_state(run_id: str) -> Optional[dict]:
    """Status and conclusion of the earlier run a duplicate defers to, or None when unknown."""
    if not run_id:
        return None
    try:
        run = api("/actions/runs/{}".format(run_id))
    except Exception as error:  # noqa: BLE001 — an unreadable predecessor must turn this run red, not crash it
        print("::warning::Could not read run {}: {}".format(run_id, error))
        return None
    return {"id": run_id, "status": run.get("status"), "conclusion": run.get("conclusion"), "url": run.get("html_url")}


def run_jobs() -> List[dict]:
    jobs: List[dict] = []
    page = 1
    while True:
        batch = api("/actions/runs/{}/attempts/{}/jobs?per_page=100&page={}".format(
            os.environ["GITHUB_RUN_ID"], os.environ.get("GITHUB_RUN_ATTEMPT", "1"), page))["jobs"]
        jobs.extend(batch)
        if len(batch) < 100:
            return jobs
        page += 1


FAILED_CONCLUSIONS = ("failure", "timed_out", "startup_failure", "action_required", "stale")


def classify(totals: dict, job: dict, gate_deferred: bool = False) -> str:
    status = totals.get("status") if totals else None
    if status == "deferred":
        return "deferred"
    conclusion = (job or {}).get("conclusion")
    if status == "cancelled" or conclusion == "cancelled":
        return "cancelled"
    if not totals:
        if gate_deferred and conclusion in (None, "skipped"):
            return "deferred"
        # Never started, skipped by an `if`, or died before writing totals.
        return "executed/failed" if conclusion in FAILED_CONCLUSIONS else "not-run"
    counts = totals.get("counts") or {}
    # The job's own API conclusion is final: tests that passed before an upload or teardown step
    # hit the job timeout still leave the job timed_out, and that is not a pass.
    if ((counts.get("failed") or 0) > 0 or totals.get("job_status") == "failure"
            or conclusion in FAILED_CONCLUSIONS or (job and conclusion not in (None, "success"))):
        return "executed/failed"
    if (counts.get("not_run") or 0) > 0:
        return "not-run"
    return "executed/passed"


def main() -> int:
    folder = pathlib.Path(os.environ.get("TOTALS_DIR", "nightly-totals"))
    totals: Dict[str, dict] = {}
    for path in sorted(folder.rglob("*.json")):
        try:
            data = json.loads(path.read_text())
        except ValueError:
            continue
        if data.get("schema") == 1 and data.get("label"):
            totals[data["label"]] = data

    ignore = {name.strip() for name in os.environ.get("IGNORE_JOBS", "").split(",") if name.strip()}
    ignore.add(os.environ.get("SUMMARY_JOB", "Nightly summary"))
    ignore.add(os.environ.get("GATE_JOB", "Nightly gate"))
    jobs = {job["name"]: job for job in run_jobs() if job["name"] not in ignore}
    gate_deferred = os.environ.get("GATE_RUN") == "false"
    gate_reason = os.environ.get("GATE_REASON") or ""
    duplicate_of = os.environ.get("GATE_DUPLICATE_OF") or ""

    items = []
    for label in sorted(set(jobs) | set(totals)):
        record, job = totals.get(label), jobs.get(label)
        advisory = bool(record["advisory"]) if record else "advisory" in label.lower()
        category = classify(record, job, gate_deferred=gate_deferred)
        items.append({
            "label": label,
            "category": category,
            "advisory": advisory,
            "reason": (record or {}).get("reason") or (gate_reason if gate_deferred and category == "deferred" else ""),
            "job_conclusion": (job or {}).get("conclusion"),
            "runner": (job or {}).get("runner_name"),
            "started_at": (job or {}).get("started_at"),
            "completed_at": (job or {}).get("completed_at"),
            "counts": (record or {}).get("counts"),
            "app_commit": (record or {}).get("app_commit"),
        })

    required_bad = [i for i in items if not i["advisory"] and i["category"] != "executed/passed"]
    duplicate = gate_deferred and gate_reason == "duplicate"
    predecessor = predecessor_state(duplicate_of) if duplicate else None
    # A duplicate is green only on the strength of an earlier run that already passed in full.
    covered = bool(predecessor) and predecessor["status"] == "completed" and predecessor["conclusion"] == "success"
    red = bool(required_bad) and not covered
    tests = Counter()
    for item in items:
        for key, value in (item["counts"] or {}).items():
            if isinstance(value, int) and key in TEST_KEYS:
                tests[("advisory" if item["advisory"] else "required", key)] += value

    summary = {
        "schema": 1,
        "workflow": os.environ.get("GITHUB_WORKFLOW"),
        "run_id": os.environ.get("GITHUB_RUN_ID"),
        "run_attempt": os.environ.get("GITHUB_RUN_ATTEMPT"),
        "sha": os.environ.get("GITHUB_SHA"),
        "verdict": "red" if red else ("duplicate" if duplicate else "green"),
        "duplicate_of": predecessor,
        "items": {
            scope: {category: sum(1 for i in items if i["advisory"] == (scope == "advisory") and i["category"] == category)
                    for category in ORDER}
            for scope in ("required", "advisory")
        },
        "tests": {scope: {key: tests[(scope, key)] for key in TEST_KEYS}
                  for scope in ("required", "advisory")},
        "required_not_passed": [i["label"] for i in required_bad],
        "detail": items,
    }
    pathlib.Path("nightly-summary.json").write_text(json.dumps(summary, indent=2))
    print(json.dumps(summary, indent=2, sort_keys=True))

    lines = ["## {} nightly summary: {}".format(os.environ.get("GITHUB_WORKFLOW", ""), summary["verdict"].upper()),
             "", "Commit `{}`, run {} attempt {}.".format(summary["sha"], summary["run_id"], summary["run_attempt"]), "",
             "| Item | Result | Reason | Planned | Passed | Failed | Flaky | Skipped | Not run | Known failing |",
             "|---|---|---|---|---|---|---|---|---|---|"]
    for item in items:
        counts = item["counts"] or {}
        cells = [counts.get(k) for k in TEST_KEYS]
        lines.append("| {}{} | {} | {} | {} |".format(
            item["label"].replace("|", "\\|"), " (advisory)" if item["advisory"] else "", item["category"], item["reason"] or "",
            " | ".join("" if c is None else str(c) for c in cells)))
    with open(os.environ.get("GITHUB_STEP_SUMMARY", os.devnull), "a", encoding="utf-8") as handle:
        handle.write("\n".join(lines) + "\n")

    if duplicate:
        print("::notice::Deferred as a duplicate of run {}: {}".format(duplicate_of, json.dumps(predecessor)))
    if red:
        print("::error::Required nightly items not executed/passed: {}".format(", ".join(summary["required_not_passed"])))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())

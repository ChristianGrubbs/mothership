#!/usr/bin/env python3
"""Overnight window guard for the nightly CI jobs (operator directives 2026-10-12 and 2026-10-14).

A run is "nightly" when GitHub's schedule fired it or when it was dispatched by hand with the
`nightly` input; such runs carry a run-name starting "Nightly:". A plain manual dispatch or a pull request is not nightly and is never held.

Two modes:

`gate` (the run's first job, "Nightly gate", which starts no service containers). For a nightly
run it decides whether the run may start at all:
* outside the window, 00:00 to 05:55 America/Chicago (GitHub has created scheduled events up to
  12 hours late), the run is deferred, and the nightly summary reports it red/deferred: a night
  whose cron fires late has no nightly coverage, visibly;
* when an earlier nightly run of this workflow already started tonight on the same commit (a
  hand-dispatched nightly and the cron), the run is deferred as a duplicate of it;
Every test job needs the gate, so a deferred run starts no job, no database and no browser.
Outputs: run, reason, nightly, duplicate_of.

`job` (default; the first step of every test job, after the gate let the run start):
* outside the window (the job was queued until 05:55 or later) the job is deferred. Its service
  containers start in a later step (.github/scripts/ci_service.sh), so a deferred job starts none;
* with fewer than MIN_MINUTES left before the window closes (minus MARGIN_MINUTES for upload and
  teardown) the job is deferred instead of starting work it cannot finish;
* otherwise it runs, and a watchdog cancels the whole workflow run when the window closes, so no
  job of a nightly run keeps the Windows desktop busy into the day.
A deferred job writes its totals file, so the nightly summary reports it as deferred rather than
as passed. ci-verify independently reports a job that ran only this step as "deferred".
Outputs: run, reason, nightly, budget_minutes, global_timeout_ms (Playwright's --global-timeout),
cut_epoch (the 05:55 cut as Unix seconds for an admitted nightly leg, else empty; the service
helper, .github/scripts/ci_service.sh, starts nothing past it).

All durations are computed in UTC, so the daylight-saving changes do not move the 05:55 cut.
"""

from __future__ import annotations

import datetime as dt
import json
import os
import pathlib
import subprocess
import sys
import urllib.parse
import urllib.request
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

ZONE = "America/Chicago"
WINDOW_END_HOUR = 6
DEFAULT_JOB_MINUTES = 90
NIGHTLY_RUN_PREFIX = "Nightly:"
CUT_MINUTES_BEFORE_CLOSE = 5  # 05:55: watchdogs cancel, and nothing new is admitted


def output(**values: object) -> None:
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as handle:
        for key, value in values.items():
            handle.write("{}={}\n".format(key, value))


def log(level: str, event: str, **ctx: object) -> None:
    # One JSONL line per decision, so a later reader reconstructs the guard from the job log.
    print(json.dumps({"ts": dt.datetime.now(dt.timezone.utc).isoformat(), "level": level, "event": event,
                      "loc": "nightly_guard", "ctx": ctx}, sort_keys=True))


def api(path: str, method: str = "GET") -> object:
    request = urllib.request.Request(
        "{}/repos/{}{}".format(os.environ.get("GITHUB_API_URL", "https://api.github.com"),
                               os.environ["GITHUB_REPOSITORY"], path),
        method=method,
        headers={
            "Authorization": "Bearer {}".format(os.environ["GITHUB_TOKEN"]),
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        body = response.read()
    return json.loads(body) if body else None


def write_totals(status: str, reason: str) -> None:
    folder = pathlib.Path(os.environ.get("RUNNER_TEMP", "/tmp"), "nightly-totals")
    folder.mkdir(parents=True, exist_ok=True)
    payload = {
        "schema": 1,
        "key": os.environ["ITEM_KEY"],
        "label": os.environ["ITEM_LABEL"],
        "kind": os.environ.get("ITEM_KIND", "job"),
        "advisory": os.environ.get("ITEM_ADVISORY", "false") == "true",
        "status": status,
        "reason": reason,
        "workflow": os.environ.get("GITHUB_WORKFLOW"),
        "run_id": os.environ.get("GITHUB_RUN_ID"),
        "run_attempt": os.environ.get("GITHUB_RUN_ATTEMPT"),
        "sha": os.environ.get("GITHUB_SHA"),
        "counts": None,
    }
    (folder / "{}.json".format(os.environ["ITEM_KEY"])).write_text(json.dumps(payload, indent=2))


def is_nightly_run(run: dict) -> bool:
    # The runs API does not expose dispatch inputs; the workflows' run-name marks nightly runs.
    return run["event"] == "schedule" or (
        run["event"] == "workflow_dispatch" and (run.get("display_title") or "").startswith(NIGHTLY_RUN_PREFIX))


def earlier_nightly(window_start: dt.datetime) -> str:
    """Return the id of an earlier nightly run of this workflow on this commit started tonight, or ''."""
    workflow_file = os.environ["GITHUB_WORKFLOW_REF"].split("@", 1)[0].rsplit("/", 1)[-1]
    own_id = int(os.environ["GITHUB_RUN_ID"])
    own = api("/actions/runs/{}".format(own_id))
    created = window_start.astimezone(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    query = urllib.parse.urlencode({"head_sha": os.environ["GITHUB_SHA"], "created": ">=" + created, "per_page": 50})
    runs = api("/actions/workflows/{}/runs?{}".format(workflow_file, query))["workflow_runs"]
    for run in runs:
        if run["id"] == own_id or not is_nightly_run(run):
            continue
        if run["created_at"] < own["created_at"] or (run["created_at"] == own["created_at"] and run["id"] < own_id):
            return str(run["id"])
    return ""


def start_watchdog(seconds: int) -> None:
    """Cancel this workflow run after `seconds`, while this job is still running.

    Detached, but it keeps RUNNER_TRACKING_ID, so the runner reaps it when the job ends: it runs on
    this job's GITHUB_TOKEN, which GitHub revokes at job end anyway. Legs still queued once every
    running leg has finished defer themselves when a runner picks them up (job mode, before any
    service starts). Each API call is retried, and a failure leaves nightly-watchdog-failed beside
    the cut marker rather than vanishing.
    """
    folder = pathlib.Path(os.environ.get("RUNNER_TEMP", "/tmp"))
    script = (
        "import json,os,pathlib,time,urllib.request\n"
        "time.sleep({seconds})\n"
        "base=os.environ.get('GITHUB_API_URL','https://api.github.com')+'/repos/'+os.environ['GITHUB_REPOSITORY']"
        "+'/actions/runs/'+os.environ['GITHUB_RUN_ID']\n"
        "h={{'Authorization':'Bearer '+os.environ['GITHUB_TOKEN'],'Accept':'application/vnd.github+json'}}\n"
        "def call(url,method='GET'):\n"
        "    last=None\n"
        "    for attempt in range(3):\n"
        "        try:\n"
        "            body=urllib.request.urlopen(urllib.request.Request(url,method=method,headers=h),timeout=30).read()\n"
        "            return json.loads(body) if method=='GET' else body\n"
        "        except Exception as error:\n"
        "            last=error; time.sleep(5)\n"
        "    pathlib.Path({failed!r}).write_text(method+' '+url+': '+repr(last)); raise SystemExit(1)\n"
        "try: status=call(base).get('status')\n"
        "except AttributeError as error:\n"
        "    pathlib.Path({failed!r}).write_text('GET '+base+': '+repr(error)); raise SystemExit(1)\n"
        "if status=='completed': raise SystemExit(0)\n"
        "pathlib.Path({marker!r}).write_text(time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()))\n"
        "call(base+'/cancel','POST')\n"
    ).format(seconds=seconds, marker=str(folder / "nightly-window-cut"), failed=str(folder / "nightly-watchdog-failed"))
    subprocess.Popen([sys.executable, "-c", script], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                     stderr=subprocess.DEVNULL, start_new_session=True)


def utc_now() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


def minutes_between(start: dt.datetime, end: dt.datetime) -> float:
    # Aware datetimes sharing one ZoneInfo subtract as wall-clock times; UTC keeps DST days exact.
    return (end.astimezone(dt.timezone.utc) - start.astimezone(dt.timezone.utc)).total_seconds() / 60


def main() -> int:
    gate = len(sys.argv) > 1 and sys.argv[1] == "gate"
    # The Pest steps write one JUnit report per invocation here for nightly_totals.py.
    pathlib.Path(os.environ.get("RUNNER_TEMP", "/tmp"), "junit").mkdir(parents=True, exist_ok=True)
    event = os.environ.get("EVENT_NAME", "")
    nightly = event == "schedule" or (event == "workflow_dispatch" and os.environ.get("NIGHTLY_INPUT") == "true")
    job_minutes = int(os.environ.get("JOB_TIMEOUT_MINUTES") or DEFAULT_JOB_MINUTES)
    if not nightly:
        log("info", "guard.unguarded", trigger_event=event, mode="gate" if gate else "job")
        if gate:
            output(run="true", reason="not-nightly", nightly="false", duplicate_of="")
        else:
            output(run="true", reason="not-nightly", nightly="false", budget_minutes=job_minutes,
                   global_timeout_ms=max(1, job_minutes - 10) * 60_000, cut_epoch="")
        return 0

    try:
        zone = ZoneInfo(ZONE)
    except ZoneInfoNotFoundError:
        # Without tzdata the window cannot be computed; fail loudly rather than guess UTC.
        print("::error::{} did not resolve on this runner (tzdata missing?)".format(ZONE))
        return 1
    now = utc_now().astimezone(zone)
    window_start = now.replace(hour=0, minute=0, second=0, microsecond=0)
    window_end = now.replace(hour=WINDOW_END_HOUR, minute=0, second=0, microsecond=0)
    margin = int(os.environ.get("MARGIN_MINUTES") or 10)
    needed = int(os.environ.get("MIN_MINUTES") or 0)
    # The watchdogs fire here and nothing starts from here on. Fixed, not derived from MARGIN_MINUTES.
    cut_at = window_end - dt.timedelta(minutes=CUT_MINUTES_BEFORE_CLOSE)

    def defer(reason: str, message: str, duplicate_of: str = "") -> int:
        log("info", "guard.deferred", mode="gate" if gate else "job", reason=reason, local=now.isoformat(),
            needed=needed, duplicate_of=duplicate_of)
        print("::notice::Deferred ({}): {}".format(reason, message))
        if gate:
            output(run="false", reason=reason, nightly="true", duplicate_of=duplicate_of)
        else:
            write_totals("deferred", reason)
            output(run="false", reason=reason, nightly="true", budget_minutes=0, global_timeout_ms=0, cut_epoch="")
        return 0

    if now >= cut_at:
        return defer("outside-window", "started {} {}, after the {} cut; the operator's day stays untouched."
                     .format(now.strftime("%H:%M"), now.tzname(), cut_at.strftime("%H:%M")))
    if gate:
        duplicate = earlier_nightly(window_start)
        if duplicate:
            return defer("duplicate", "run {} already started tonight on this commit.".format(duplicate),
                         duplicate_of=duplicate)
        # No watchdog here: this job's token dies with it. Each running leg starts its own watchdog
        # below, and a leg still queued at 05:55 defers itself when it is picked up.
        log("info", "guard.gate_open", local=now.isoformat())
        output(run="true", reason="window-open", nightly="true", duplicate_of="")
        return 0

    budget = int(minutes_between(now, window_end)) - margin
    if budget < needed:
        return defer("insufficient-window", "{} min left before 06:00 {} (after a {} min margin); this job needs {}."
                     .format(budget, now.tzname(), margin, needed))

    # The watchdog keys on the window, not on this job's budget: it cancels the whole run, and the
    # run's other jobs may legitimately still be working after this one's own timeout.
    # Measured again just before spawning, so the time spent above does not push the cut late.
    start_watchdog(seconds=max(0, int(minutes_between(utc_now(), cut_at) * 60)))
    budget = min(budget, job_minutes)
    log("info", "guard.run", local=now.isoformat(), budget_minutes=budget, cut_at=cut_at.isoformat())
    print("::notice::Nightly window open: {} min of budget for this job; the run is cancelled at {} {} at the latest."
          .format(budget, cut_at.strftime("%H:%M"), now.tzname()))
    output(run="true", reason="window-open", nightly="true", budget_minutes=budget,
           global_timeout_ms=max(1, budget - 5) * 60_000, cut_epoch=int(cut_at.timestamp()))
    return 0


if __name__ == "__main__":
    sys.exit(main())

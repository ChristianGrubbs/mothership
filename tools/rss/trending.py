"""GitHub Trending snapshots and a daily archive inside the Umbrel RSS adapter.

RSSHub's trending route needs a GitHub token, and the FreshRSS bridge feed only records a
repository the first time it appears, so neither can say what trended on a given day. This
collector reads github.com/trending directly every INTERVAL and folds each snapshot into a
bucket for the local calendar day. A repository that drops off the list mid-day stays in
that day's bucket, so each past day is the complete set of repositories seen trending.
"""
from __future__ import annotations

import html
import json
import os
import re
import threading
import time
import uuid
from datetime import date, datetime, timedelta
from html.parser import HTMLParser
from pathlib import Path
from zoneinfo import ZoneInfo

from announcements import fetch_text

URL = 'https://github.com/trending'
INTERVAL = 7200
RETRY = 900
RETAIN_DAYS = 90
# Home's Refresh button forces an out-of-cycle fetch, throttled separately from the
# background INTERVAL/RETRY loop so a user cannot hammer github.com/trending.
FORCE_MIN_INTERVAL = 600
ZONE = ZoneInfo(os.environ.get('TRENDING_TZ', 'UTC'))

ARTICLE_RE = re.compile(r'(<article\b[^>]*>)(.*?)</article\s*>', re.S | re.I)
ARTICLE_OPEN_RE = re.compile(r'<article\b', re.I)
ARTICLE_CLOSE_RE = re.compile(r'</article\s*>', re.I)
DOCUMENT_START_RE = re.compile(r'\s*(?:<!--.*?-->\s*|<!DOCTYPE[^>]*>\s*)*<html(?=[\s>])', re.S | re.I)
DOCUMENT_END_RE = re.compile(r'</html>\s*$', re.I)
NAME_RE = re.compile(r'<h2 class="h3 lh-condensed">\s*<a\b[^>]*?\bhref="/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)"')
DESCRIPTION_RE = re.compile(r'<p class="col-9[^"]*">(.*?)</p>', re.S)
LANGUAGE_RE = re.compile(r'itemprop="programmingLanguage">([^<]+)<')
STARS_RE = re.compile(r'href="/[^"]+/stargazers"[^>]*>.*?([\d,]+)\s*</a>', re.S)
TODAY_RE = re.compile(r'([\d,]+) stars today')
# Every repository row links its stargazers, whatever element wraps it: an independent count of rows.
STARGAZERS_RE = re.compile(r'href="/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/stargazers"')
TAG_RE = re.compile(r'<[^>]+>')
SPACE_RE = re.compile(r'\s+')


class StartTag(HTMLParser):
    """Reads one start tag's attributes as a browser does: quoted values cannot spoof an
    attribute, and the first of a repeated attribute wins."""

    def __init__(self, markup):
        super().__init__()
        self.attrs = None
        self.feed(markup)
        self.close()

    def handle_starttag(self, tag, attrs):
        if self.attrs is None:
            self.attrs = {}
            for key, value in attrs:
                self.attrs.setdefault(key, value)


def class_tokens(start_tag):
    return ((StartTag(start_tag).attrs or {}).get('class') or '').split()


def number(match):
    return int(match.group(1).replace(',', '')) if match else None


def parse_trending(body):
    # A complete page with no rows returns []; the collector treats that as a failure, never an
    # empty day. Anything short of a complete page whose every <article> is a recognised
    # repository row rejects the whole snapshot: a partial list would mark the missing
    # repositories off-list and corrupt that day's archive.
    if not DOCUMENT_START_RE.match(body) or not DOCUMENT_END_RE.search(body):
        raise ValueError('Trending page is incomplete')
    articles = ARTICLE_RE.findall(body)
    if not len(articles) == len(ARTICLE_OPEN_RE.findall(body)) == len(ARTICLE_CLOSE_RE.findall(body)):
        raise ValueError('A trending row lost an opening or closing tag')
    rows = []
    for start_tag, block in articles:
        name = NAME_RE.search(block)
        if 'Box-row' not in class_tokens(start_tag) or not name:
            raise ValueError('Trending row layout changed')
        description = DESCRIPTION_RE.search(block)
        language = LANGUAGE_RE.search(block)
        rows.append(dict(
            repo=name.group(1), url='https://github.com/' + name.group(1),
            description=SPACE_RE.sub(' ', html.unescape(TAG_RE.sub(' ', description.group(1)))).strip() if description else '',
            language=html.unescape(language.group(1)).strip() if language else None,
            stars=number(STARS_RE.search(block)), starsToday=number(TODAY_RE.search(block)), rank=len(rows) + 1))
    if set(STARGAZERS_RE.findall(body)) - {row['repo'] for row in rows}:
        raise ValueError('A repository row is outside the recognised layout')
    return rows


class Trending:
    def __init__(self, *, path, fetch=fetch_text, clock=time.time, zone=ZONE):
        self.path, self.fetch, self.clock, self.zone = Path(path), fetch, clock, zone
        self.lock = threading.Lock()
        try:
            self.state = json.loads(self.path.read_text())
        except (OSError, ValueError):
            self.state = {}
        self.state.setdefault('days', {})

    def day_of(self, ms):
        return datetime.fromtimestamp(ms / 1000, self.zone).date().isoformat()

    def due(self, now):
        checked = self.state.get('checkedAt', 0)
        if not checked:
            return True
        wait = RETRY if self.state.get('error') else INTERVAL
        # The first attempt of a new local day closes yesterday's bucket without waiting.
        return now - checked >= wait * 1000 or self.day_of(now) != self.day_of(checked)

    def refresh(self):
        now = int(self.clock() * 1000)
        with self.lock:
            if not self.due(now):
                return
        self._fetch_and_merge(now)

    def refresh_now(self):
        """Force an immediate fetch for a user-initiated Refresh click, independent of the
        background INTERVAL/RETRY cadence. At most one forced fetch runs per
        FORCE_MIN_INTERVAL; a call inside that window is a no-op that just reports when the
        next forced fetch will be allowed, via the same lock the background thread uses so the
        two never race on self.state."""
        now = int(self.clock() * 1000)
        with self.lock:
            last_forced = self.state.get('lastForcedAt', 0)
            wait_left = last_forced + FORCE_MIN_INTERVAL * 1000 - now
            forced = not last_forced or wait_left <= 0
            if forced:
                self.state['lastForcedAt'] = now
        if forced:
            self._fetch_and_merge(now)
        snapshot = self.snapshot()
        with self.lock:
            freshest = self.state.get('lastSuccessAt') or self.state.get('checkedAt') or now
            next_allowed = self.state.get('lastForcedAt', now) + FORCE_MIN_INTERVAL * 1000
        snapshot.update(forced=forced, refreshedAt=freshest, throttledUntil=next_allowed)
        return snapshot

    def _fetch_and_merge(self, now):
        error, count = None, 0
        try:
            rows = parse_trending(self.fetch(URL))
            if not rows:
                raise ValueError('Trending page returned no repositories')
            count = len(rows)
        except Exception as exc:
            error = type(exc).__name__
        with self.lock:
            self.state.update(checkedAt=now, error=error)
            if not error:
                self.merge(now, rows)
                self.state['lastSuccessAt'] = now
            oldest = (date.fromisoformat(self.day_of(now)) - timedelta(days=RETAIN_DAYS)).isoformat()
            self.state['days'] = {day: bucket for day, bucket in self.state['days'].items() if day >= oldest}
            self.path.parent.mkdir(parents=True, exist_ok=True)
            temp = self.path.with_suffix('.tmp')
            temp.write_text(json.dumps(self.state))
            temp.replace(self.path)
        # Optional fixed-path diagnostics, never page bodies.
        if os.environ.get('SIGNAL_DEBUG') == '1':
            record = dict(ts=now, run_id=uuid.uuid4().hex, level='warning' if error else 'info', event='trending.checked',
                          loc='trending.refresh', ctx=dict(error=error, count=count, day=self.day_of(now)))
            with self.path.with_name('events.jsonl').open('a') as output:
                output.write(json.dumps(record) + '\n')

    def merge(self, now, rows):
        bucket = self.state['days'].setdefault(self.day_of(now), dict(repos={}, latest=[], snapshots=0))
        for row in rows:
            previous = bucket['repos'].get(row['repo'], {})
            bucket['repos'][row['repo']] = dict(row, firstSeenAt=previous.get('firstSeenAt', now), lastSeenAt=now,
                                                bestRank=min(row['rank'], previous.get('bestRank', row['rank'])))
        bucket.update(latest=[row['repo'] for row in rows], updatedAt=now, snapshots=bucket['snapshots'] + 1)

    @staticmethod
    def repos(bucket):
        latest = set(bucket.get('latest', []))
        rows = [dict(repo, onList=repo['repo'] in latest) for repo in bucket.get('repos', {}).values()]
        return sorted(rows, key=lambda r: (not r['onList'], r['rank'] if r['onList'] else r['bestRank'], r['repo']))

    def snapshot(self):
        now = int(self.clock() * 1000)
        today = self.day_of(now)
        with self.lock:
            success, days = self.state.get('lastSuccessAt', 0), self.state['days']
            status = 'error' if self.state.get('error') else 'pending' if not success else 'stale' if now - success > 2 * INTERVAL * 1000 else 'ok'
            current = days.get(today, {})
            return dict(date=today, repos=self.repos(current), updatedAt=current.get('updatedAt'), status=status,
                        checkedAt=self.state.get('checkedAt'), lastSuccessAt=success or None, intervalSeconds=INTERVAL,
                        days=[dict(date=day, count=len(days[day]['repos'])) for day in sorted(days, reverse=True)])

    def day(self, value):
        today = self.day_of(int(self.clock() * 1000))
        with self.lock:
            bucket = self.state['days'].get(value)
            if bucket is None:
                return None
            return dict(date=value, repos=self.repos(bucket), updatedAt=bucket.get('updatedAt'), complete=value < today)

    def start(self):
        def loop():
            while True:
                try:
                    self.refresh()
                except OSError:
                    pass  # Last-success age exposes persistence failures as stale.
                time.sleep(60)
        threading.Thread(target=loop, name='github-trending', daemon=True).start()

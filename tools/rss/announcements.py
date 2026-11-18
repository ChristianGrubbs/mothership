"""Free direct provider collection inside the existing Umbrel RSS adapter."""
from __future__ import annotations

import base64
import hashlib
import json
import os
import re
import threading
import time
import uuid
import zlib
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen

INTERVAL = 900
MAX_AGE = 7 * 86400
MAX_BODY = 8 * 1024 * 1024
# A source with no success for this long pushes one ntfy alert, then one a day until it recovers.
ALERT_AFTER = int(os.environ.get('SIGNAL_ALERT_AFTER_HOURS', '6')) * 3600
ALERT_REMIND = 86400
# Base URL of the self-hosted RSSHub that serves the X timeline as RSS, for example
# http://rsshub.internal:1200. There is no built-in address: unset, that one source reports
# "Source address is not set" and the others are unaffected.
RSSHUB_URL = os.environ.get('SIGNAL_RSSHUB_URL', '').strip().rstrip('/')
# What to do about each source's most likely failure, carried in its alert. SIGNAL_RSSHUB_HINT
# appends the deployment's own step, such as the command that stores a new cookie.
HINTS = {
    'sottiaux': ('RSSHub X route: check that SIGNAL_RSSHUB_URL is set. Otherwise the X auth_token cookie '
                 'RSSHub signs in with was most likely revoked: sign in to x.com with the account RSSHub '
                 'uses, copy auth_token and store it on the RSSHub host. '
                 + os.environ.get('SIGNAL_RSSHUB_HINT', '')).strip(),
}


def timestamp(value):
    try:
        date = datetime.fromisoformat(value.replace('Z', '+00:00'))
    except ValueError:
        date = parsedate_to_datetime(value)
    if date.tzinfo is None:
        date = date.replace(tzinfo=timezone.utc)
    return int(date.timestamp() * 1000)


def story(*, title, url, published, source):
    if not title or urlparse(url).scheme != 'https':
        raise ValueError('Invalid story')
    return dict(id=hashlib.sha256(url.encode()).hexdigest()[:24], title=title,
                url=url, publishedAt=timestamp(published), source=source)


def parse_rss(body, *, source):
    root = ET.fromstring(body)
    rows = []
    for item in root.findall('./channel/item'):
        rows.append(story(title=item.findtext('title'), url=item.findtext('link'),
                          published=item.findtext('pubDate') or '', source=source))
    return rows


def walk(value):
    if isinstance(value, dict):
        yield value
        for child in value.values():
            yield from walk(child)
    elif isinstance(value, list):
        for child in value:
            yield from walk(child)


def flight_rows(wire):
    """Yield the payload of each React Flight row ``id:payload\\n`` in a wire string.

    ``id:T<hexlen>,<text>`` rows (inline base64 images since 2026-09-16) are prefixed by
    their UTF-8 byte length and carry no newline, so a plain line split would glue the
    following record rows onto them. Consume those by length and skip them.
    """
    data, pos = wire.encode('utf-8'), 0
    while pos < len(data):
        colon = data.find(b':', pos)
        if colon < 0:
            return
        pos = colon + 1
        if data[pos:pos + 1] == b'T':
            comma = data.find(b',', pos)
            if comma < 0:
                return
            try:
                pos = comma + 1 + int(data[pos + 1:comma], 16)
            except ValueError:
                return
            continue
        end = data.find(b'\n', pos)
        end = len(data) if end < 0 else end
        yield data[pos:end].decode('utf-8', 'replace')
        pos = end + 1


def parse_anthropic(body):
    rows = {}
    # Next's serialized page data includes both the featured launch landing pages
    # and normal /news posts. No execution of upstream JavaScript is needed.
    for match in re.finditer(r'self\.__next_f\.push\((\[.*?\])\)</script>', body, re.S):
        wire = json.loads(match.group(1))
        if len(wire) != 2 or wire[0] != 1 or not isinstance(wire[1], str):
            continue
        for payload in flight_rows(wire[1]):
            if not payload.startswith(('[', '{')):
                continue
            try:
                data = json.loads(payload)
            except ValueError:
                continue
            for item in walk(data):
                published = item.get('publishedOn') or item.get('date')
                if not item.get('title') or not published:
                    continue
                url = item.get('url')
                if not url and isinstance(item.get('slug'), dict):
                    url = '/news/' + item['slug'].get('current', '')
                if not url:
                    continue
                url = urljoin('https://www.anthropic.com', url)
                if urlparse(url).hostname != 'www.anthropic.com':
                    continue
                rows[url] = story(title=item['title'], url=url, published=published, source='Anthropic Direct')
    return list(rows.values())


SOURCES = [
    ('openai', 'OpenAI', 'https://openai.com/news/rss.xml', lambda body: parse_rss(body, source='OpenAI News')),
    ('google-ai', 'Google AI', 'https://blog.google/technology/ai/rss/', lambda body: parse_rss(body, source='Google AI')),
    ('deepmind', 'Google DeepMind', 'https://deepmind.google/blog/rss.xml', lambda body: parse_rss(body, source='Google DeepMind Direct')),
    ('anthropic', 'Anthropic', 'https://www.anthropic.com/news', parse_anthropic),
    # Official feed (linked from /blog since its 2026-10 rebuild). The old /blog hydration
    # scrape matched nothing once the CMS fields stopped being consecutive.
    ('higgsfield', 'Higgsfield', 'https://higgsfield.ai/blog/rss.xml',
     lambda body: parse_rss(body, source='Higgsfield Direct')),
    # Private-network RSSHub; the collector runs server-side beside it, so it needs no public hostname.
    ('sottiaux', 'Tibo (thsottiaux)', RSSHUB_URL + '/twitter/user/thsottiaux',
     lambda body: parse_rss(body, source='Tibo (thsottiaux)')),
]


def fetch_text(url):
    if not urlparse(url).scheme:
        raise ValueError('Source address is not set')
    request = Request(url, headers={'User-Agent': 'Mothership-Signal/1.0', 'Accept': 'text/html,application/rss+xml,application/xml'})
    with urlopen(request, timeout=25) as response:
        return read_body(response)


def ntfy_notify(*, title, message, priority='default', tags=''):
    """Publish to ntfy when NTFY_URL and NTFY_TOPIC are set; False means not sent."""
    url, topic = os.environ.get('NTFY_URL'), os.environ.get('NTFY_TOPIC')
    if not url or not topic:
        return False
    # ntfy headers must be printable ASCII; Unicode belongs in the body only.
    headers = {'User-Agent': 'Mothership-Signal/1.0', 'Title': title.encode('ascii', 'replace').decode(),
               'Priority': priority, 'Tags': tags, 'Click': 'https://mothership.example.com/'}
    user, password = os.environ.get('NTFY_USER'), os.environ.get('NTFY_PASS')
    if user and password:
        headers['Authorization'] = 'Basic ' + base64.b64encode(f'{user}:{password}'.encode()).decode()
    try:
        with urlopen(Request(url.rstrip('/') + '/' + topic, data=message.encode(), headers=headers), timeout=15) as response:
            return 200 <= response.status < 300
    except OSError:
        return False


# Our own ValueError messages; any other exception text may quote feed content.
KNOWN_ERRORS = {'Source returned no dated stories', 'Invalid story', 'Source exceeds size limit',
                'Source address is not set'}


def describe(exc):
    """A failure message safe to store, serve and push: never upstream content."""
    if isinstance(exc, HTTPError):
        return f'HTTP {exc.code}'
    if isinstance(exc, URLError):
        return 'Connection failed: ' + type(exc.reason).__name__
    if isinstance(exc, TimeoutError):
        return 'Timed out'
    if isinstance(exc, ET.ParseError):
        return 'Invalid XML'
    return str(exc) if str(exc) in KNOWN_ERRORS else type(exc).__name__


def utc(ms):
    return datetime.fromtimestamp(ms / 1000, timezone.utc).strftime('%Y-%m-%d %H:%MZ') if ms else 'never'


def read_body(response):
    body = response.read(MAX_BODY + 1)
    # The deepmind.google CDN sometimes serves a cached gzip body without being asked.
    if response.headers.get('Content-Encoding', '').lower() == 'gzip' or body[:2] == b'\x1f\x8b':
        if len(body) > MAX_BODY:
            raise ValueError('Source exceeds size limit')
        body = zlib.decompressobj(16 + zlib.MAX_WBITS).decompress(body, MAX_BODY + 1)
    if len(body) > MAX_BODY:
        raise ValueError('Source exceeds size limit')
    return body.decode('utf-8')


class Collector:
    def __init__(self, *, path, sources=SOURCES, fetch=fetch_text, clock=time.time, notify=ntfy_notify):
        self.path, self.sources, self.fetch, self.clock, self.notify = Path(path), sources, fetch, clock, notify
        self.lock = threading.Lock()
        try:
            self.state = json.loads(self.path.read_text())
        except (OSError, ValueError):
            self.state = {}

    def refresh(self):
        run_id = uuid.uuid4().hex
        for key, label, url, parser in self.sources:
            now = int(self.clock() * 1000)
            with self.lock:
                previous = self.state.get(key, {})
                if now - previous.get('checkedAt', 0) < INTERVAL * 1000:
                    continue
            update = dict(previous, checkedAt=now, label=label, url=url)
            try:
                parsed = parser(self.fetch(url))
                if not parsed:
                    raise ValueError('Source returned no dated stories')
                # Keep recently observed stories if a homepage rolls them off early.
                by_url = {s['url']: s for s in previous.get('stories', [])}
                by_url.update({s['url']: s for s in parsed})
                update.update(stories=[s for s in by_url.values() if now-MAX_AGE*1000 <= s['publishedAt'] <= now+300000],
                              lastSuccessAt=now, error=None, errorDetail=None, failingSince=None, parsedCount=len(parsed))
            except Exception as exc:
                # The message (never a body) is what tells "HTTP Error 403" from an empty feed.
                update.update(error=type(exc).__name__, errorDetail=describe(exc),
                              failingSince=previous.get('failingSince') or now)
            with self.lock:
                self.state[key] = update
                self.save()
            self.log(now, run_id, 'warning' if update.get('error') else 'info', 'source.checked', 'announcements.refresh',
                     dict(source=key, error=update.get('error'), count=update.get('parsedCount', 0)))

    def save(self):
        """Persist state; the caller holds self.lock."""
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temp = self.path.with_suffix('.tmp')
        temp.write_text(json.dumps(self.state))
        temp.replace(self.path)

    def log(self, now, run_id, level, event, loc, ctx):
        # Optional fixed-path diagnostics, never source bodies or credentials.
        if os.environ.get('SIGNAL_DEBUG') == '1':
            record = dict(ts=now, run_id=run_id, level=level, event=event, loc=loc, ctx=ctx)
            with self.path.with_name('events.jsonl').open('a') as output:
                output.write(json.dumps(record)+'\n')

    def alert(self):
        """Push a source that has been down ALERT_AFTER to ntfy, remind daily, and announce recovery.

        Without this a dead source only shows as "Sources 5/6" in the strip, which went
        unnoticed for days four times in 2026-09 and 2026-10.
        """
        now, run_id = int(self.clock() * 1000), uuid.uuid4().hex
        with self.lock:
            rows = [(key, label, url, dict(self.state.get(key, {}))) for key, label, url, _ in self.sources]
        healthy = sum(1 for *_, data in rows if data.get('lastSuccessAt') and not data.get('error'))
        for key, label, url, data in rows:
            since = data.get('lastSuccessAt') or data.get('failingSince')
            if not since:
                continue
            down, alerted = now - since >= ALERT_AFTER * 1000, data.get('alertedAt')
            if down and (not alerted or now - alerted >= ALERT_REMIND * 1000):
                detail = data.get('errorDetail') or data.get('error') or 'no check has succeeded'
                message = (f'{label} has had no successful check since {utc(data.get("lastSuccessAt"))} '
                           f'({detail}). The ticker shows {healthy}/{len(rows)} sources. Source: {url}')
                if key in HINTS:
                    message += '\n' + HINTS[key]
                sent = self.notify(title=f'Signal ticker: {label} down {(now - since) // 3600000}h', message=message,
                                   priority='high', tags='warning')
                value = now if sent else alerted
            elif alerted and not data.get('error'):
                sent = self.notify(title=f'Signal ticker: {label} recovered',
                                   message=f'{label} is collecting again. The ticker shows {healthy}/{len(rows)} sources.',
                                   tags='white_check_mark')
                value = None if sent else alerted
            else:
                continue
            if value != alerted:
                with self.lock:
                    self.state.setdefault(key, {})['alertedAt'] = value
                    self.save()
            self.log(now, run_id, 'warning' if down else 'info', 'source.alerted', 'announcements.alert',
                     dict(source=key, down=down, sent=sent))

    def snapshot(self):
        now = int(self.clock() * 1000)
        sources, stories = [], []
        with self.lock:
            for key, label, url, _ in self.sources:
                data = self.state.get(key, {})
                success = data.get('lastSuccessAt', 0)
                status = 'error' if data.get('error') else 'pending' if not success else 'stale' if now-success > 2*INTERVAL*1000 else 'ok'
                sources.append(dict(id=key, label=label, url=url, status=status,
                                    checkedAt=data.get('checkedAt'), lastSuccessAt=success or None,
                                    parsedCount=data.get('parsedCount', 0), error=data.get('errorDetail') or data.get('error')))
                stories.extend(s for s in data.get('stories', []) if now-MAX_AGE*1000 <= s['publishedAt'] <= now+300000)
        return dict(stories=stories, sources=sources, checkedAt=now, intervalSeconds=INTERVAL)

    def start(self):
        def loop():
            while True:
                try:
                    self.refresh()
                    self.alert()
                except OSError:
                    pass  # Last-success age exposes persistence failures as stale.
                time.sleep(60)
        threading.Thread(target=loop, name='signal-sources', daemon=True).start()

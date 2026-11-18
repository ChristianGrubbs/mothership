import json
import tempfile
import threading
import time
import unittest
from datetime import datetime
from http.server import ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen
from zoneinfo import ZoneInfo

from trending import FORCE_MIN_INTERVAL, INTERVAL, RETAIN_DAYS, RETRY, Trending, parse_trending

FIXTURE = (Path(__file__).parent / 'fixtures' / 'github-trending.html').read_text()
ZONE = ZoneInfo('America/New_York')


def at(*parts):
    return datetime(*parts, tzinfo=ZONE).timestamp()


def page(*repos):
    rows = ''.join(
        f'<article class="Box-row"><h2 class="h3 lh-condensed"><a href="/{repo}" class="Link">{repo}</a></h2>'
        f'<p class="col-9 color-fg-muted my-1">About {repo}</p>'
        f'<a href="/{repo}/stargazers" class="Link"><svg></svg> 1,200</a>'
        f'<span class="d-inline-block float-sm-right"><svg></svg> 50 stars today</span></article>'
        for repo in repos)
    return f'<html><body><main>{rows}</main></body></html>'


def corruptions(body):
    first_end = body.index('</article>') + len('</article>')
    # The last row keeps valid content but moves to another wrapper; article counts stay balanced.
    section = body.replace('<article class="Box-row"><h2 class="h3 lh-condensed"><a href="/b/two"',
                           '<section class="Box-row"><h2 class="h3 lh-condensed"><a href="/b/two"')
    last_close = section.rindex('</article>')
    section = section[:last_close] + '</section>' + section[last_close + len('</article>'):]
    return {
        'repository heading drifted': body.replace('lh-condensed"><a href="/b/two"', 'renamed"><a href="/b/two"'),
        'row lost its Box-row class': body.replace('<article class="Box-row"><h2 class="h3 lh-condensed"><a href="/b/two"',
                                                   '<article class="TrendRow"><h2 class="h3 lh-condensed"><a href="/b/two"'),
        'cut between rows': body[:first_end],
        'cut after the final row': body[:body.rindex('</article>') + len('</article>')],
        'cut inside a row': body[:body.rindex('</article>')],
        'class moved to data-class': body.replace('<article class="Box-row"><h2 class="h3 lh-condensed"><a href="/b/two"',
                                                  '<article data-class="Box-row" class="TrendRow"><h2 class="h3 lh-condensed"><a href="/b/two"'),
        'cut before the first row ends': body[body.index('<h2'):],
        'row lost its closing tag, document intact': body.replace('</article>', '', 1),
        'class spoofed inside a quoted value': body.replace('<article class="Box-row"><h2 class="h3 lh-condensed"><a href="/b/two"',
                                                            '<article data-note=\' class="Box-row"\' class="TrendRow"><h2 class="h3 lh-condensed"><a href="/b/two"'),
        'repeated class attribute, first wins': body.replace('<article class="Box-row"><h2 class="h3 lh-condensed"><a href="/b/two"',
                                                             '<article class="TrendRow" class="Box-row"><h2 class="h3 lh-condensed"><a href="/b/two"'),
        'html-prefixed document opener': body.replace('<html>', '<html-fragment>', 1),
        'row moved to another wrapper': section,
    }


def collector(directory, clock, fetch):
    return Trending(path=Path(directory) / 'trending.json', fetch=fetch, clock=lambda: clock[0], zone=ZONE)


class ParseTest(unittest.TestCase):
    def test_real_rows_and_a_bare_row(self):
        rows = parse_trending(FIXTURE)
        self.assertEqual([r['repo'] for r in rows], ['ayghri/i-have-adhd', 'bilawalsidhu/gods-eye-view', 'example/bare-repo'])
        first = rows[0]
        self.assertEqual(first['url'], 'https://github.com/ayghri/i-have-adhd')
        self.assertEqual(first['description'], 'A skill to stop your coding agent from burying the answer. ADHD-friendly output.')
        self.assertEqual((first['language'], first['stars'], first['starsToday'], first['rank']), ('Python', 39976, 3882, 1))
        bare = rows[2]
        self.assertEqual((bare['description'], bare['language'], bare['stars'], bare['starsToday'], bare['rank']), ('', None, 12, None, 3))

    def test_description_markup_is_reduced_to_text(self):
        body = page('a/b').replace('About a/b', '<g-emoji alias="rocket">🚀</g-emoji> Fast &amp; small')
        self.assertEqual(parse_trending(body)[0]['description'], '🚀 Fast & small')

    def test_changed_layout_yields_nothing(self):
        self.assertEqual(parse_trending('<html><body>We changed everything</body></html>'), [])

    def test_extra_row_classes_and_attributes_still_parse(self):
        body = page('a/one', 'b/two').replace('<article class="Box-row">', '<article class="Box-row is-new" data-id="7">')
        self.assertEqual([r['repo'] for r in parse_trending(body)], ['a/one', 'b/two'])

    def test_any_partial_or_unrecognised_page_rejects_the_whole_snapshot(self):
        for label, corrupt in corruptions(page('a/one', 'b/two')).items():
            with self.subTest(label), self.assertRaises(ValueError):
                parse_trending(corrupt)


class CollectorTest(unittest.TestCase):
    def test_repos_that_drop_off_stay_in_the_day(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, lists = [at(2026, 9, 10, 8, 0)], [page('a/one', 'b/two'), page('b/two', 'c/three')]
            c = collector(directory, clock, lambda url: lists.pop(0))
            c.refresh()
            clock[0] += INTERVAL + 1
            c.refresh()
            today = c.snapshot()
            self.assertEqual(today['date'], '2026-09-10')
            self.assertEqual(today['status'], 'ok')
            self.assertEqual([(r['repo'], r['onList']) for r in today['repos']],
                             [('b/two', True), ('c/three', True), ('a/one', False)])
            self.assertEqual(today['repos'][0]['bestRank'], 1)

    def test_waits_the_interval_between_fetches(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, calls = [at(2026, 9, 10, 8, 0)], []
            c = collector(directory, clock, lambda url: calls.append(url) or page('a/one'))
            c.refresh()
            clock[0] += INTERVAL - 60
            c.refresh()
            self.assertEqual(calls, ['https://github.com/trending'])

    def test_new_day_fetches_at_once_and_archives_yesterday(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, lists = [at(2026, 9, 10, 23, 30)], [page('a/one'), page('b/two')]
            c = collector(directory, clock, lambda url: lists.pop(0))
            c.refresh()
            clock[0] = at(2026, 9, 11, 0, 1)
            c.refresh()
            today = c.snapshot()
            self.assertEqual(today['date'], '2026-09-11')
            self.assertEqual([r['repo'] for r in today['repos']], ['b/two'])
            self.assertEqual(today['days'], [{'date': '2026-09-11', 'count': 1}, {'date': '2026-09-10', 'count': 1}])
            yesterday = c.day('2026-09-10')
            self.assertTrue(yesterday['complete'])
            self.assertEqual([r['repo'] for r in yesterday['repos']], ['a/one'])
            self.assertIsNone(c.day('2026-09-01'))

    def test_failure_keeps_last_good_day_and_retries_sooner(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, offline, calls = [at(2026, 9, 10, 8, 0)], [], []
            def fetch(url):
                calls.append(url)
                if offline: raise OSError('github down')
                return page('a/one')
            c = collector(directory, clock, fetch)
            c.refresh()
            offline.append(True)
            clock[0] += INTERVAL + 1
            c.refresh()
            self.assertEqual(c.snapshot()['status'], 'error')
            self.assertEqual([r['repo'] for r in c.snapshot()['repos']], ['a/one'])
            clock[0] += RETRY - 60
            c.refresh()
            self.assertEqual(len(calls), 2)
            clock[0] += 61
            c.refresh()
            self.assertEqual(len(calls), 3)

    def test_any_partial_page_keeps_the_last_good_snapshot(self):
        good = page('a/one', 'b/two')
        for label, corrupt in corruptions(good).items():
            with self.subTest(label), tempfile.TemporaryDirectory() as directory:
                clock, lists = [at(2026, 9, 10, 8, 0)], [good, corrupt]
                c = collector(directory, clock, lambda url: lists.pop(0))
                c.refresh()
                clock[0] += INTERVAL + 1
                c.refresh()
                today = c.snapshot()
                self.assertEqual(today['status'], 'error')
                self.assertEqual([(r['repo'], r['onList']) for r in today['repos']], [('a/one', True), ('b/two', True)])

    def test_changed_layout_is_an_error_not_an_empty_day(self):
        with tempfile.TemporaryDirectory() as directory:
            c = collector(directory, [at(2026, 9, 10, 8, 0)], lambda url: '<html>new layout</html>')
            c.refresh()
            self.assertEqual(c.snapshot()['status'], 'error')
            self.assertEqual(c.snapshot()['days'], [])

    def test_state_survives_restart_and_old_days_are_pruned(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, lists = [at(2026, 6, 1, 8, 0)], [page('old/repo'), page('new/repo')]
            c = collector(directory, clock, lambda url: lists.pop(0))
            c.refresh()
            clock[0] = at(2026, 6, 1, 8, 0) + (RETAIN_DAYS + 1) * 86400
            c.refresh()
            reloaded = collector(directory, clock, lambda url: self.fail('restart must not refetch'))
            self.assertEqual(len(reloaded.snapshot()['days']), 1)
            self.assertEqual(reloaded.snapshot()['repos'][0]['repo'], 'new/repo')
            self.assertIsNone(reloaded.day('2026-06-01'))
            self.assertEqual(json.loads((Path(directory) / 'trending.json').read_text())['error'], None)


class ForceRefreshTest(unittest.TestCase):
    """Home's Refresh button on the Trending Quick View calls refresh_now(), which must hit
    GitHub at most once per FORCE_MIN_INTERVAL regardless of how often it is clicked."""

    def test_first_click_fetches_immediately(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, calls = [at(2026, 9, 10, 8, 0)], []
            c = collector(directory, clock, lambda url: calls.append(url) or page('a/one'))
            snap = c.refresh_now()
            self.assertEqual(calls, ['https://github.com/trending'])
            self.assertTrue(snap['forced'])
            self.assertEqual(snap['repos'][0]['repo'], 'a/one')
            self.assertEqual(snap['throttledUntil'], int(clock[0] * 1000) + FORCE_MIN_INTERVAL * 1000)

    def test_second_click_inside_the_window_is_throttled_and_does_not_refetch(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, calls = [at(2026, 9, 10, 8, 0)], []
            c = collector(directory, clock, lambda url: calls.append(url) or page('a/one'))
            c.refresh_now()
            clock[0] += FORCE_MIN_INTERVAL - 30
            snap = c.refresh_now()
            self.assertEqual(calls, ['https://github.com/trending'], 'a second forced fetch inside the window never hits GitHub')
            self.assertFalse(snap['forced'])
            self.assertGreater(snap['throttledUntil'], int(clock[0] * 1000))

    def test_click_after_the_window_fetches_again(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, lists = [at(2026, 9, 10, 8, 0)], [page('a/one'), page('b/two')]
            c = collector(directory, clock, lambda url: lists.pop(0))
            c.refresh_now()
            clock[0] += FORCE_MIN_INTERVAL + 1
            snap = c.refresh_now()
            self.assertTrue(snap['forced'])
            self.assertEqual(snap['repos'][0]['repo'], 'b/two')

    def test_forced_refresh_ignores_the_background_due_check(self):
        # A background refresh() that just ran must not block a forced click: the background
        # INTERVAL/RETRY cadence and the forced FORCE_MIN_INTERVAL are independent throttles.
        with tempfile.TemporaryDirectory() as directory:
            clock, calls = [at(2026, 9, 10, 8, 0)], []
            c = collector(directory, clock, lambda url: calls.append(url) or page('a/one'))
            c.refresh()
            snap = c.refresh_now()
            self.assertEqual(len(calls), 2)
            self.assertTrue(snap['forced'])

    def test_throttled_state_survives_restart(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, calls = [at(2026, 9, 10, 8, 0)], []
            c = collector(directory, clock, lambda url: calls.append(url) or page('a/one'))
            c.refresh_now()
            reloaded = collector(directory, clock, lambda url: calls.append(url) or page('b/two'))
            clock[0] += FORCE_MIN_INTERVAL - 30
            snap = reloaded.refresh_now()
            self.assertFalse(snap['forced'], 'the reloaded collector remembers the last forced fetch from disk')
            self.assertEqual(len(calls), 1)

    def test_concurrent_clicks_fetch_github_at_most_once(self):
        with tempfile.TemporaryDirectory() as directory:
            clock = [at(2026, 9, 10, 8, 0)]
            calls, call_lock = [], threading.Lock()

            def fetch(url):
                with call_lock:
                    calls.append(url)
                time.sleep(0.05)
                return page('a/one')

            c = collector(directory, clock, fetch)
            threads = [threading.Thread(target=c.refresh_now) for _ in range(8)]
            for thread in threads:
                thread.start()
            for thread in threads:
                thread.join()
            self.assertEqual(len(calls), 1, 'the lock lets exactly one concurrent forced click reach GitHub')

    def test_forced_fetch_that_raises_reports_error_status_not_success(self):
        # PR #172 review: a forced fetch that failed must never look like a successful one — the
        # server and browser both decide "did this refresh actually work" from forced + status.
        def offline(url):
            raise OSError('github down')

        with tempfile.TemporaryDirectory() as directory:
            c = collector(directory, [at(2026, 9, 10, 8, 0)], offline)
            snap = c.refresh_now()
            self.assertTrue(snap['forced'])
            self.assertEqual(snap['status'], 'error')

    def test_forced_fetch_returning_invalid_html_also_reports_error_status(self):
        with tempfile.TemporaryDirectory() as directory:
            c = collector(directory, [at(2026, 9, 10, 8, 0)], lambda url: '<html>layout changed, no rows</html>')
            snap = c.refresh_now()
            self.assertTrue(snap['forced'])
            self.assertEqual(snap['status'], 'error')

    def test_a_failed_forced_fetch_never_masks_a_real_previous_success(self):
        # A failing forced click must not corrupt the day's already-archived good data; only its
        # own status flips to error.
        with tempfile.TemporaryDirectory() as directory:
            clock, lists = [at(2026, 9, 10, 8, 0)], [page('a/one')]
            c = collector(directory, clock, lambda url: lists.pop(0) if lists else (_ for _ in ()).throw(OSError('github down')))
            c.refresh_now()
            clock[0] += FORCE_MIN_INTERVAL + 1
            snap = c.refresh_now()
            self.assertTrue(snap['forced'])
            self.assertEqual(snap['status'], 'error')
            self.assertEqual([r['repo'] for r in snap['repos']], ['a/one'], 'the last good day is retained, not wiped by the failed click')


class RoutesTest(unittest.TestCase):
    def setUp(self):
        import server
        self.directory = tempfile.TemporaryDirectory()
        # A third page is spare fetch fodder for a test that forces a live refresh through the route.
        clock, lists = [at(2026, 9, 10, 23, 30)], [page('a/one'), page('b/two'), page('c/three')]
        self.trending = collector(self.directory.name, clock, lambda url: lists.pop(0))
        self.trending.refresh()
        clock[0] = at(2026, 9, 11, 9, 0)
        self.trending.refresh()
        self.previous, server.TRENDING = server.TRENDING, self.trending
        self.origins, server.ALLOWED_ORIGINS = server.ALLOWED_ORIGINS, {'https://assets.example.org'}
        self.server = ThreadingHTTPServer(('127.0.0.1', 0), server.Handler)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.base = f'http://127.0.0.1:{self.server.server_address[1]}'
        self.module = server

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.module.TRENDING = self.previous
        self.module.ALLOWED_ORIGINS = self.origins
        self.directory.cleanup()

    def get(self, path, origin='https://assets.example.org'):
        request = Request(self.base + path, headers={'Origin': origin} if origin else {})
        try:
            with urlopen(request, timeout=5) as response:
                return response.status, json.loads(response.read())
        except HTTPError as error:
            with error:
                return error.code, json.loads(error.read())

    def test_today_and_archived_day(self):
        status, today = self.get('/v1/trending')
        self.assertEqual((status, today['date'], today['repos'][0]['repo']), (200, '2026-09-11', 'b/two'))
        status, day = self.get('/v1/trending/2026-09-10')
        self.assertEqual((status, day['repos'][0]['repo'], day['complete']), (200, 'a/one', True))

    def test_unknown_or_malformed_days_and_missing_origin(self):
        self.assertEqual(self.get('/v1/trending/2026-08-01')[0], 404)
        self.assertEqual(self.get('/v1/trending/yesterday')[0], 404)

    def test_refresh_query_param_forces_the_adapter_and_reports_throttling(self):
        status, forced = self.get('/v1/trending?refresh=1')
        self.assertEqual(status, 200)
        self.assertTrue(forced['forced'])
        self.assertIn('refreshedAt', forced)
        self.assertIn('throttledUntil', forced)
        status, throttled = self.get('/v1/trending?refresh=1')
        self.assertEqual(status, 200)
        self.assertFalse(throttled['forced'])
        status, plain = self.get('/v1/trending')
        self.assertEqual(status, 200)
        self.assertNotIn('forced', plain, 'a plain read never carries the forced-refresh hints')
        self.assertEqual(self.get('/v1/trending', origin=None)[0], 401)

    def test_a_forced_refresh_that_fails_is_a_non_2xx_never_a_disguised_success(self):
        # PR #172 review: the route must not return 200 + forced:true for a forced fetch that
        # actually failed, or the browser (which only inspects HTTP status to route to .catch())
        # would show "Refreshed" for a broken fetch.
        def offline(url):
            raise OSError('github down')
        self.trending.fetch = offline
        status, body = self.get('/v1/trending?refresh=1')
        self.assertEqual(status, 502)
        self.assertIn('message', body)
        self.assertNotIn('forced', body, 'a failure response is a plain error, never a disguised snapshot')
        # The failed attempt is recorded; a subsequent plain read honestly reports it as an error too.
        self.assertEqual(self.get('/v1/trending')[1]['status'], 'error')


if __name__ == '__main__': unittest.main()

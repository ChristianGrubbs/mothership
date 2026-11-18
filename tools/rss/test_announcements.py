import gzip
import io
import json
import tempfile
import unittest
from pathlib import Path
from announcements import INTERVAL, RSSHUB_URL, SOURCES, Collector, parse_anthropic, parse_rss, read_body

SOTTIAUX_FEED = '<rss><channel>' \
    '<item><title>All reset for everyone. Enjoy the week with Astra.</title>' \
    '<link>https://x.com/thsottiaux/status/1965000000000000001</link>' \
    '<pubDate>Tue, 09 Sep 2026 17:00:00 GMT</pubDate></item>' \
    '<item><title>Look! A fish!</title>' \
    '<link>https://x.com/thsottiaux/status/1965000000000000002</link>' \
    '<pubDate>Tue, 09 Sep 2026 16:00:00 GMT</pubDate></item>' \
    '</channel></rss>'


class SourcesTest(unittest.TestCase):
    def test_unrequested_gzip_body_is_decompressed(self):
        # deepmind.google intermittently answers with a cached gzip body even without Accept-Encoding.
        response = io.BytesIO(gzip.compress(SOTTIAUX_FEED.encode()))
        response.headers = {'Content-Encoding': 'gzip'}
        self.assertEqual(len(parse_rss(read_body(response), source='Google DeepMind Direct')), 2)

    def test_anthropic_featured_release_outside_news_path(self):
        post = {'title': 'Introducing Claude Fable 5.1 and Claude Mythos 5.1', 'date': '2026-09-01', 'url': '/claude-fable-and-mythos-5-1'}
        wire = '6:' + json.dumps({'posts': [post]}) + '\n'
        page = '<script>self.__next_f.push(' + json.dumps([1, wire]) + ')</script>'
        stories = parse_anthropic(page)
        self.assertEqual(stories[0]['url'], 'https://www.anthropic.com/claude-fable-and-mythos-5-1')
        self.assertEqual(stories[0]['publishedAt'], 1788220800000)

    def test_anthropic_survives_unterminated_flight_text_chunk(self):
        # Since 2026-09-16 the live page emits length-prefixed T chunks (inline base64
        # images) with no trailing newline, so the next row shares its line.
        post = {'title': 'Partnering with Accenture on embedded evaluation', 'publishedOn': '2026-09-18T16:00:00.000Z',
                'slug': {'current': 'accenture-embedded-evaluation'}}
        text = 'data:image/jpeg;base64,/9j/\u00e9\u00e9'  # multibyte: length prefix counts UTF-8 bytes
        wire = '19:T%x,%s' % (len(text.encode('utf-8')), text) + '1a:' + json.dumps({'posts': [post]}) + '\n'
        page = '<script>self.__next_f.push(' + json.dumps([1, wire]) + ')</script>'
        stories = parse_anthropic(page)
        self.assertEqual([s['url'] for s in stories], ['https://www.anthropic.com/news/accenture-embedded-evaluation'])
        self.assertEqual(stories[0]['publishedAt'], 1789747200000)

    def test_higgsfield_reads_the_official_feed(self):
        # Regression 2026-10-17: /blog moved to a SolidStart payload whose post records no
        # longer carry seoPublishDateISO, slug and title consecutively, so the old page
        # scrape returned no stories ("5/6 sources"). The fixture is the live feed, trimmed.
        entry = next(s for s in SOURCES if s[0] == 'higgsfield')
        self.assertEqual(entry[2], 'https://higgsfield.ai/blog/rss.xml')
        feed = (Path(__file__).parent / 'fixtures' / 'higgsfield-blog-rss.xml').read_text()
        stories = entry[3](feed)
        self.assertEqual([s['url'] for s in stories][:2], ['https://higgsfield.ai/blog/higgsfield-community',
                                                           'https://higgsfield.ai/blog/best-ai-tools-motion-designers'])
        self.assertEqual(len(stories), 3)
        self.assertEqual(stories[0]['publishedAt'], 1792250880000)
        # The ticker's provider map keys off this exact story source string.
        self.assertEqual({s['source'] for s in stories}, {'Higgsfield Direct'})
        with tempfile.TemporaryDirectory() as directory:
            c = Collector(path=Path(directory)/'a.json', sources=[entry], fetch=lambda url: feed,
                          clock=lambda: 1792260000)
            c.refresh()
            result = c.snapshot()
            self.assertEqual(result['sources'][0]['status'], 'ok')
            self.assertEqual(len(result['stories']), 3)

    def test_rss_not_truncated_at_100_and_bad_dates_rejected(self):
        item = '<item><title>Introducing a model</title><link>https://openai.com/index/test{}</link><pubDate>Tue, 01 Sep 2026 17:00:00 GMT</pubDate></item>'
        xml = '<rss><channel>' + ''.join(item.format(i) for i in range(110)) + '</channel></rss>'
        self.assertEqual(len(parse_rss(xml, source='OpenAI News')), 110)
        with self.assertRaises(ValueError):
            parse_rss('<rss><channel><item><title>x</title><link>https://openai.com/x</link></item></channel></rss>', source='OpenAI News')

    def test_failure_isolated_cache_retained_and_stale_visible_after_restart(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'announcements.json'
            clock = [1788800000]
            def fetch(url):
                if url == 'bad': raise OSError('offline')
                return '<rss><channel><item><title>Introducing test</title><link>https://openai.com/test</link><pubDate>Tue, 01 Sep 2026 17:00:00 GMT</pubDate></item></channel></rss>'
            sources = [('openai', 'OpenAI', 'good', lambda body: parse_rss(body, source='OpenAI News')),
                       ('google', 'Google', 'bad', lambda body: [])]
            c = Collector(path=path, sources=sources, fetch=fetch, clock=lambda: clock[0])
            c.refresh()
            result = c.snapshot()
            self.assertEqual(len(result['stories']), 1)
            self.assertEqual(result['sources'][1]['status'], 'error')
            clock[0] += 4000
            reloaded = Collector(path=path, sources=sources, fetch=fetch, clock=lambda: clock[0])
            self.assertEqual(reloaded.snapshot()['sources'][0]['status'], 'stale')
            self.assertEqual(len(reloaded.snapshot()['stories']), 1)
            reloaded.fetch = lambda url: (_ for _ in ()).throw(OSError('offline'))
            reloaded.refresh()
            self.assertEqual(len(reloaded.snapshot()['stories']), 1)

    def test_empty_parse_is_failure_not_healthy(self):
        with tempfile.TemporaryDirectory() as directory:
            c = Collector(path=Path(directory)/'a.json', sources=[('a','Anthropic','url',lambda body: [])],fetch=lambda url:'changed HTML',clock=lambda:1788800000)
            c.refresh()
            self.assertEqual(c.snapshot()['sources'][0]['status'], 'error')


    def test_sottiaux_is_a_collected_source_not_an_unavailable_stub(self):
        entry = next(s for s in SOURCES if s[0] == 'sottiaux')
        self.assertEqual(len(SOURCES), 6)
        # The host comes from SIGNAL_RSSHUB_URL; the repository carries no address.
        self.assertEqual(entry[2], RSSHUB_URL + '/twitter/user/thsottiaux')
        # The ticker's provider map keys off this exact story source string.
        self.assertEqual({s['source'] for s in entry[3](SOTTIAUX_FEED)}, {'Tibo (thsottiaux)'})
        with tempfile.TemporaryDirectory() as directory:
            c = Collector(path=Path(directory)/'a.json', sources=[entry], fetch=lambda url: SOTTIAUX_FEED,
                          clock=lambda: 1789000000)
            c.refresh()
            result = c.snapshot()
            self.assertEqual(result['sources'][0]['status'], 'ok')
            self.assertEqual(result['sources'][0]['label'], 'Tibo (thsottiaux)')
            self.assertNotIn('unavailable', [s['status'] for s in result['sources']])
            self.assertEqual(len(result['stories']), 2)

    def test_a_source_with_no_address_fails_with_a_message_that_says_so(self):
        # SIGNAL_RSSHUB_URL unset leaves a path with no host; that must not read as a network fault.
        with tempfile.TemporaryDirectory() as directory:
            entry = ('sottiaux', 'Tibo (thsottiaux)', '/twitter/user/thsottiaux', lambda body: [])
            c = Collector(path=Path(directory)/'a.json', sources=[entry], clock=lambda: 1789000000)
            c.refresh()
            self.assertEqual(c.snapshot()['sources'][0]['error'], 'Source address is not set')

    def test_rsshub_outage_degrades_only_sottiaux_and_retains_its_cached_posts(self):
        with tempfile.TemporaryDirectory() as directory:
            path, clock, offline = Path(directory)/'a.json', [1789000000], []
            def fetch(url):
                if 'twitter' in url:
                    if offline: raise OSError('rsshub down')
                    return SOTTIAUX_FEED
                return '<rss><channel><item><title>Introducing test</title><link>https://openai.com/test</link>' \
                       '<pubDate>Tue, 08 Sep 2026 17:00:00 GMT</pubDate></item></channel></rss>'
            sources = [('openai', 'OpenAI', 'https://openai.com/news/rss.xml', lambda body: parse_rss(body, source='OpenAI News')),
                       next(s for s in SOURCES if s[0] == 'sottiaux')]
            c = Collector(path=path, sources=sources, fetch=fetch, clock=lambda: clock[0])
            c.refresh()
            self.assertEqual(len(c.snapshot()['stories']), 3)
            offline.append(True)
            clock[0] += INTERVAL + 1
            c.refresh()
            result = c.snapshot()
            self.assertEqual(result['sources'][0]['status'], 'ok')
            self.assertEqual(result['sources'][1]['status'], 'error')
            self.assertEqual(len(result['stories']), 3)

    def test_down_source_alerts_once_reminds_daily_and_announces_recovery(self):
        # Regression 2026-11-03: X revoked the RSSHub cookie on 2026-10-28, RSSHub served an
        # empty channel, and the strip read "Sources 5/6" for six days with no alert.
        with tempfile.TemporaryDirectory() as directory:
            clock, empty, sent = [1789000000], [], []
            entry = next(s for s in SOURCES if s[0] == 'sottiaux')
            c = Collector(path=Path(directory)/'a.json', sources=[entry], clock=lambda: clock[0],
                          fetch=lambda url: '<rss><channel></channel></rss>' if empty else SOTTIAUX_FEED,
                          notify=lambda **kw: sent.append(kw) or True)
            def tick(seconds):
                clock[0] += seconds
                c.refresh()
                c.alert()
            tick(0)
            empty.append(True)
            tick(3 * 3600)
            self.assertEqual(sent, [])
            tick(3 * 3600)
            self.assertEqual(len(sent), 1)
            self.assertEqual(sent[0]['title'], 'Signal ticker: Tibo (thsottiaux) down 6h')
            self.assertIn('Source returned no dated stories', sent[0]['message'])
            self.assertIn('auth_token', sent[0]['message'])
            self.assertEqual(c.snapshot()['sources'][0]['error'], 'Source returned no dated stories')
            tick(3600)
            self.assertEqual(len(sent), 1)
            tick(86400)
            self.assertEqual(len(sent), 2)
            empty.clear()
            tick(INTERVAL)
            self.assertEqual(sent[2]['title'], 'Signal ticker: Tibo (thsottiaux) recovered')
            tick(INTERVAL)
            self.assertEqual(len(sent), 3)

    def test_unsent_alert_is_retried(self):
        with tempfile.TemporaryDirectory() as directory:
            clock, sent = [1788800000], []
            c = Collector(path=Path(directory)/'a.json', sources=[('a', 'Anthropic', 'url', lambda body: [])],
                          fetch=lambda url: 'changed HTML', clock=lambda: clock[0],
                          notify=lambda **kw: sent.append(kw) and False)
            c.refresh()
            clock[0] += 7 * 3600
            c.alert()
            c.alert()
            # ntfy unreachable: nothing is recorded as alerted, so every pass tries again.
            self.assertEqual(len(sent), 2)
            self.assertIsNone(c.state['a'].get('alertedAt'))

    def test_no_recovery_notice_while_the_source_is_failing_again(self):
        # Delivered outage, success whose recovery notice failed to send, then a new failure.
        with tempfile.TemporaryDirectory() as directory:
            clock, bad, ntfy_up, sent = [1789000000], [], [True], []
            entry = next(s for s in SOURCES if s[0] == 'sottiaux')
            c = Collector(path=Path(directory)/'a.json', sources=[entry], clock=lambda: clock[0],
                          fetch=lambda url: '<rss><channel></channel></rss>' if bad else SOTTIAUX_FEED,
                          notify=lambda **kw: ntfy_up[0] and (sent.append(kw) or True))
            def tick(seconds):
                clock[0] += seconds
                c.refresh()
                c.alert()
            tick(0)
            bad.append(True)
            tick(7 * 3600)
            self.assertEqual(len(sent), 1)
            bad.clear()
            ntfy_up[0] = False
            tick(INTERVAL)
            bad.append(True)
            ntfy_up[0] = True
            tick(INTERVAL)
            self.assertEqual([m['title'] for m in sent], ['Signal ticker: Tibo (thsottiaux) down 7h'])

    def test_error_detail_never_carries_feed_content(self):
        feed = '<rss><channel><item><title>x</title><link>https://x.com/a</link>' \
               '<pubDate>SECRET-SENTINEL</pubDate></item></channel></rss>'
        with tempfile.TemporaryDirectory() as directory:
            sent = []
            path = Path(directory)/'a.json'
            clock = [1789000000]
            c = Collector(path=path, sources=[next(s for s in SOURCES if s[0] == 'sottiaux')], clock=lambda: clock[0],
                          fetch=lambda url: feed, notify=lambda **kw: sent.append(kw) or True)
            c.refresh()
            clock[0] += 7 * 3600
            c.alert()
            self.assertEqual(c.snapshot()['sources'][0]['error'], 'ValueError')
            self.assertEqual(len(sent), 1)
            self.assertNotIn('SECRET-SENTINEL', path.read_text() + json.dumps(c.snapshot()) + json.dumps(sent))


if __name__ == '__main__': unittest.main()

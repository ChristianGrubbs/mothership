import importlib.util
from pathlib import Path
import tempfile
import unittest
import json

spec = importlib.util.spec_from_file_location(
    'check_manual', Path(__file__).parents[1] / 'check_manual.py'
)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ManualTests(unittest.TestCase):
    def _write_article_fixture(self, root, *, audience='user', file='user/start.md', sources=None):
        article = root / file
        article.parent.mkdir(parents=True, exist_ok=True)
        article.write_text(
            '# Start\n\n'
            '## Before you begin\nReady.\n\n'
            '## Steps\n1. Start.\n\n'
            '## Expected result\nDone.\n\n'
            '## Troubleshooting\nRetry.\n'
        )
        (root / 'source.php').write_text('<?php\n')
        row = dict(
            slug='start',
            title='Start',
            summary='Read this.',
            category='User guide',
            audience=audience,
            file=file,
            sources=['source.php'] if sources is None else sources,
        )
        (root / 'manifest.json').write_text(json.dumps([row]))
        return row

    def test_duplicate_and_missing_file_are_reported(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            row = dict(
                slug='start',
                title='Start',
                summary='Read this.',
                category='User guide',
                audience='user',
                file='user/start.md',
                sources=['missing.php'],
            )
            (root / 'manifest.json').write_text(json.dumps([row, row]))
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('duplicate' in e for e in errors))
            self.assertTrue(any('missing' in e for e in errors))

    def test_valid_fixture_passes(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self._write_article_fixture(root)
            self.assertEqual(module.check(root=root, repo=root), [])

    def test_escaping_article_path_is_reported(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / 'manual'
            root.mkdir()
            (root.parent / 'outside.md').write_text('# Outside\n')
            self._write_article_fixture(root, file='../outside.md')
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('escaping' in e for e in errors))

    def test_invalid_audience_is_reported(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self._write_article_fixture(root, audience='everyone')
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('invalid audience' in e for e in errors))

    def test_source_less_article_is_reported(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self._write_article_fixture(root, sources=[])
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('empty sources' in e for e in errors))

    def test_malformed_manifest_is_reported(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'manifest.json').write_text('{')
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('invalid manifest' in e for e in errors))

    def test_manifest_schema_errors_are_reported(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            rows = [
                {},
                dict(
                    slug=' ',
                    title=123,
                    summary=' ',
                    category='User guide',
                    audience='user',
                    file='user/start.md',
                    sources=None,
                ),
                dict(
                    slug='ok',
                    title='Okay',
                    summary='Read this.',
                    category='User guide',
                    audience='user',
                    file='user/ok.md',
                    sources=[' ', 3],
                ),
            ]
            (root / 'manifest.json').write_text(json.dumps(rows))
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('missing slug' in e for e in errors))
            self.assertTrue(any('empty title' in e for e in errors))
            self.assertTrue(any('empty summary' in e for e in errors))
            self.assertTrue(any('empty sources' in e for e in errors))
            self.assertTrue(any('nonblank strings' in e for e in errors))

    def test_non_list_manifest_is_reported(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'manifest.json').write_text(json.dumps({}))
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('top-level list' in e for e in errors))

    def test_headings_must_be_exact_and_outside_fences(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self._write_article_fixture(root)
            (root / 'user/start.md').write_text(
                '# Start\n\n'
                '```markdown\n'
                '## Before you begin\n'
                '```\n\n'
                '## StepsLater\n\n'
                '## Expected results\n\n'
                '## Troubleshooting\n'
            )
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('missing Before you begin' in e for e in errors))
            self.assertTrue(any('missing Steps' in e for e in errors))
            self.assertTrue(any('missing Expected result' in e for e in errors))

    def test_absolute_source_path_is_reported(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            absolute_source = str(root / 'source.php')
            self._write_article_fixture(root, sources=[absolute_source])
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('absolute source' in e for e in errors))

    def test_shorter_nested_fence_does_not_close_longer_fence(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self._write_article_fixture(root)
            (root / 'user/start.md').write_text(
                '# Start\n\n'
                '````markdown\n'
                '## Before you begin\n'
                '```\n'
                '## Steps\n'
                '## Expected result\n'
                '## Troubleshooting\n'
                '````\n'
            )
            errors = module.check(root=root, repo=root)
            for heading in ('Before you begin', 'Steps', 'Expected result', 'Troubleshooting'):
                self.assertTrue(any(f'missing {heading}' in e for e in errors))

    def test_suffixed_fence_closer_does_not_close_fence(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self._write_article_fixture(root)
            (root / 'user/start.md').write_text(
                '# Start\n\n'
                '```markdown\n'
                '## Before you begin\n'
                '```not-a-closer\n'
                '## Steps\n'
                '## Expected result\n'
                '## Troubleshooting\n'
                '```\n'
            )
            errors = module.check(root=root, repo=root)
            for heading in ('Before you begin', 'Steps', 'Expected result', 'Troubleshooting'):
                self.assertTrue(any(f'missing {heading}' in e for e in errors))

    def test_padded_slug_is_reported_as_invalid(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            self._write_article_fixture(root)
            row = json.loads((root / 'manifest.json').read_text())[0]
            row['slug'] = ' start '
            (root / 'manifest.json').write_text(json.dumps([row]))
            errors = module.check(root=root, repo=root)
            self.assertTrue(any('invalid slug' in e for e in errors))

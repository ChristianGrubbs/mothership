from pathlib import Path
import unittest


class ContentTests(unittest.TestCase):
    def test_library_navigation(self):
        root = Path(__file__).resolve().parents[3]
        text = (root / 'docs/manual/user/library.md').read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'folder',
            'search',
        ):
            self.assertIn(phrase, text)

    def test_uploads_article_documents_resumable_limits(self):
        root = Path(__file__).resolve().parents[3]
        text = (root / 'docs/manual/user/uploads.md').read_text()
        for phrase in (
            '32 MiB',
            '20 GiB',
            '12',
        ):
            self.assertIn(phrase, text)

    def test_metadata_tags_article_documents_core_workflows(self):
        root = Path(__file__).resolve().parents[3]
        text = (root / 'docs/manual/user/metadata-tags.md').read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'metadata',
            'tags',
        ):
            self.assertIn(phrase, text)

    def test_relationships_files_article_documents_core_workflows(self):
        root = Path(__file__).resolve().parents[3]
        text = (root / 'docs/manual/user/relationships-files.md').read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'incoming',
            'outgoing',
            'relationship',
        ):
            self.assertIn(phrase, text)

    def test_access_favorites_article_documents_core_workflows(self):
        root = Path(__file__).resolve().parents[3]
        text = (root / 'docs/manual/user/access-favorites.md').read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'folder',
            'favorites',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_previews_article_documents_media_workflows(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/user/previews.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'preview',
            'original',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_image_editing_article_documents_save_and_export(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/user/image-editing.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'original',
            'export',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_sharing_article_documents_creation_and_revocation(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/user/sharing.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'share',
            'revoke',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_home_article_documents_the_fixed_page(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/user/home.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'Calendar',
            'Send files to a person',
            'Recent uploads',
            'Add your iPhone',
            'Studio admin',
            'Your saved links',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_signal_article_documents_feed_and_offline_states(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/user/signal.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'feed',
            'offline',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_stylepress_article_documents_generation_workflow(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/user/stylepress.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'estimate',
            'generation',
            'reference',
            'select **Generate**',
            'Reuse in StylePress',
            "Account B receives no view of account A's job",
            'does not automatically resubmit',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_video_loop_refiner_article_documents_output_workflow(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/user/video-loop-refiner.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'upload',
            'preview',
            'download',
            '512 MB',
            'MP4, MOV, M4V, WebM, AVI and MKV',
            'Collapse ↙',
            'forbidden response',
            'This widget service is unavailable. Please retry shortly.',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_accounts_roles_article_documents_scoped_access(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/administrator/accounts-roles.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'role',
            'permission',
            'folder',
            'Help',
            'Use Sandbox defaults',
            'Home widgets',
            'All Directories',
            'Manage access',
            'Granted on this folder',
            'Access from parents and roles',
            'Include subfolders',
            'Status',
            'per account',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_integrations_article_documents_scoped_credentials(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/administrator/integrations.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'integration',
            'permission',
            'revoke',
            'Configuration → Integrations',
            'Custom',
            'bearer authentication',
            'This action is unauthorized',
            'Re-Generate Secret Key',
            'Regenerate password',
            'provider connection',
            'STYLEPRESS_HIGGSFIELD_KEY_ID',
            'STYLEPRESS_HIGGSFIELD_KEY_SECRET',
            'MOTHERSHIP_VECTORIZATION_ENABLED',
            'MOTHERSHIP_VECTORIZATION_OWNER_ADMIN_ID',
            'MOTHERSHIP_VECTORIZER_API_ID',
            'MOTHERSHIP_VECTORIZER_API_SECRET',
            'Available vectorizations',
            'settings.users.users.edit',
            'All other accounts default to zero',
            'Balance changed. Reload before saving.',
            'does not automatically retry',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_operations_article_documents_release_recovery(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/administrator/operations.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'release',
            'rollback',
            'queue',
            'full source commit',
            'application image',
            'configuration, route, view and event caches',
            'panorama, image editing and StylePress have dedicated workers',
            'failed job',
            'forward corrective commit',
            'Never delete database or storage volumes as a rollback step',
            'first return the runtime source checkout to the preceding release',
            "serves static files from the checkout's `public/` tree",
            'application, queue and Looper image tags',
            'Pest suites run on the self-hosted runner pool against disposable databases',
            'which need no database',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

    def test_image_vectorization_article_documents_allowance_and_exports(self):
        root = Path(__file__).resolve().parents[3]
        article = root / 'docs/manual/user/image-vectorization.md'
        self.assertTrue(article.is_file(), f'missing article: {article}')
        text = article.read_text()
        for phrase in (
            '## Before you begin',
            '## Steps',
            '## Expected result',
            '## Troubleshooting',
            'allowance',
            'preview',
            'export',
            '20 MiB',
            '33,554,432 pixels',
            '**Convert ▾**',
            'One completed conversion uses one vectorization',
            'New accounts start with zero',
            'configured owner account is unlimited',
            'failed conversion returns its reservation',
            'does not submit another provider request',
            'does not alter or replace the source media',
            'Your recent vectorizations',
            'Download SVG',
            'Download PDF',
            'Copy SVG code',
            'does not automatically repeat the paid request',
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, text)

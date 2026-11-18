<?php

use App\Documentation\Manual;

it('serves a fork article and respects the display name', function () {
    $this->loginWithPermissions(permissions: ['help']);
    config(['documentation.name' => 'The Mothership']);

    $this->get(route('admin.help.docs.show', ['slug' => 'getting-started']))
        ->assertOk()
        ->assertSeeText('The Mothership')
        ->assertSeeText('Getting started');
});

it('serves readers who hold no help permission', function () {
    $this->loginWithPermissions(permissions: ['dashboard']);

    $this->get(route('admin.help.docs.show', ['slug' => 'getting-started']))->assertOk();
    $this->get(route('admin.help.index', ['q' => 'account']))->assertOk();
});

it('redirects guests and rejects unknown articles', function () {
    $this->get('/admin/help/docs/getting-started')->assertRedirect();
    $this->loginAsAdmin();
    $this->get(route('admin.help.docs.show', ['slug' => 'absent-article']))->assertNotFound();
});

it('searches the permitted corpus', function () {
    $this->loginWithPermissions(permissions: ['help']);

    expect(array_column(app(Manual::class)->search('account'), 'slug'))->toContain('getting-started');
    expect(array_filter(app(Manual::class)->entries(), fn (array $entry) => $entry['audience'] === 'administrator'))->toBeEmpty();
});

it('filters administrative content and strips unsafe article HTML', function () {
    $this->loginWithPermissions(permissions: ['help']);
    $root = sys_get_temp_dir().'/docs-'.bin2hex(random_bytes(8));
    mkdir($root);
    $entry = ['slug' => 'private-ops', 'title' => 'Private operations', 'summary' => 'Internal queue setup', 'category' => 'Administration', 'audience' => 'administrator', 'file' => 'ops.md', 'sources' => ['composer.json']];

    try {
        file_put_contents($root.'/ops.md', '# Operations');
        file_put_contents($root.'/manifest.json', json_encode([$entry]));
        config(['documentation.root' => $root]);

        expect(app(Manual::class)->search('queue'))->toBe([]);
        $this->get(route('admin.help.docs.show', ['slug' => 'private-ops']))->assertNotFound();

        $entry['audience'] = 'user';
        file_put_contents($root.'/ops.md', '# Read me'.PHP_EOL.'<script>alert(1)</script>');
        file_put_contents($root.'/manifest.json', json_encode([$entry]));

        expect(app(Manual::class)->html($entry))->not->toContain('<script>');
        $this->get('/admin/help/docs/absent-article')->assertNotFound();
    } finally {
        unlink($root.'/ops.md');
        unlink($root.'/manifest.json');
        rmdir($root);
    }
});

it('records structural documentation diagnostics without the search query', function () {
    $this->loginWithPermissions(permissions: ['help']);
    $path = storage_path('logs/documentation.jsonl');
    $query = 'confidential-query-'.bin2hex(random_bytes(8));
    @unlink($path);
    config(['documentation.log_enabled' => true]);

    try {
        $this->get(route('admin.help.index', ['q' => $query]))->assertOk();

        $records = array_map(json_decode(...), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        expect($records)->each->toHaveKeys(['ts', 'run_id', 'level', 'event', 'loc', 'ctx']);
        expect(json_encode($records))->not->toContain($query);
    } finally {
        @unlink($path);
    }
});

it('does not offer upstream sales on help or home', function () {
    $this->loginAsAdmin();

    foreach (['admin.help.index', 'admin.home.index'] as $name) {
        $this->get(route($name))->assertOk()->assertDontSee('mothership-promo-bar', false)
            ->assertDontSee('mothership.com/cloud-hosting', false)
            ->assertDontSee('mothership.com/pim-migration-services', false);
    }
});

it('renders useful empty search and validates the query', function () {
    $this->loginWithPermissions(permissions: ['help']);

    $this->get(route('admin.help.index', ['q' => 'zzzz-no-match-zzzz']))->assertOk()->assertSeeText('No articles found');
    $this->getJson(route('admin.help.index', ['q' => str_repeat('x', 161)]))->assertUnprocessable();
});

<?php

use App\Documentation\RouteInventory;
use Illuminate\Support\Facades\Route;

it('captures representative integration, browser, and signed routes', function () {
    $rows = app(RouteInventory::class)->capture();

    expect(array_column($rows, 'name'))->toContain(
        'admin.api.dam.assets.index',
        'admin.home.layout',
        'admin.api.dam.assets.private.download',
    );
});

it('assigns every route method, domain, and URI a unique identity', function () {
    $rows = app(RouteInventory::class)->capture();
    $identities = array_column($rows, 'id');

    expect($identities)->toHaveCount(count(array_unique($identities)));
});

it('records excluded middleware for stateless public PWA routes', function () {
    $rows = collect(app(RouteInventory::class)->capture());

    foreach (['GET', 'HEAD'] as $method) {
        foreach (['/app.webmanifest', '/pwa/offline'] as $uri) {
            $row = $rows->firstWhere('id', $method.' * '.$uri);

            expect($row)->not->toBeNull()
                ->and($row['excluded_middleware'])->toContain('web');
        }
    }

    expect($rows->firstWhere('name', 'admin.home.layout'))
        ->not->toHaveKey('excluded_middleware');
});

it('captures the metadata needed to review every registered route', function () {
    foreach (app(RouteInventory::class)->capture() as $row) {
        expect($row)->toHaveKeys([
            'id',
            'method',
            'domain',
            'uri',
            'name',
            'action',
            'middleware',
            'wheres',
            'source',
        ]);
    }
});

it('serializes route constraints as JSON objects whether empty or populated', function () {
    $rows = collect(json_decode(
        json_encode(app(RouteInventory::class)->capture(), JSON_THROW_ON_ERROR),
        flags: JSON_THROW_ON_ERROR,
    ));

    $unconstrained = $rows->firstWhere('name', 'admin.home.layout');
    $constrained = $rows->firstWhere('name', 'admin.catalog.products.index');

    expect($unconstrained->wheres)->toBeObject()
        ->and($constrained->wheres)->toBeObject();
});

it('reports null source for an action defined outside the checkout', function () {
    $temporary = tempnam(sys_get_temp_dir(), 'mothership-route-source-');
    file_put_contents($temporary, '<?php return static fn () => response()->noContent();');

    try {
        Route::get('__documentation/outside-source', require $temporary)
            ->name('documentation.outside-source');

        $row = collect(app(RouteInventory::class)->capture())
            ->firstWhere('name', 'documentation.outside-source');

        expect($row)->not->toBeNull()
            ->and($row['source'])->toBeNull();
    } finally {
        unlink($temporary);
    }
});

it('keeps the tracked route inventory in step with the registered routes', function () {
    $tracked = base_path('docs/api/routes.json');
    $candidate = tempnam(sys_get_temp_dir(), 'routes-');

    try {
        file_put_contents(
            $candidate,
            json_encode(
                app(RouteInventory::class)->capture(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL,
        );

        // Refresh the tracked copy on request only, so a normal run never
        // dirties the worktree: ROUTE_INVENTORY_WRITE=1 vendor/bin/pest <this file>.
        if (getenv('ROUTE_INVENTORY_WRITE') === '1') {
            copy($candidate, $tracked);
        }

        expect(file_get_contents($candidate))->toBe(
            file_get_contents($tracked),
            'docs/api/routes.json is stale; refresh it with ROUTE_INVENTORY_WRITE=1 vendor/bin/pest tests/Feature/DocumentationRoutesTest.php',
        );
    } finally {
        unlink($candidate);
    }
});

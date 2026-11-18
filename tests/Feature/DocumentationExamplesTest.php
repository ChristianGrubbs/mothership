<?php

use App\Documentation\Manual;
use Illuminate\Support\Facades\Route;
use Mothership\DocumentationExample\DocumentationExampleServiceProvider;

function registerDocumentationExample(): void
{
    require_once base_path('examples/documentation-extension/src/ExampleController.php');
    require_once base_path('examples/documentation-extension/src/DocumentationExampleServiceProvider.php');
    app()->register(DocumentationExampleServiceProvider::class);
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

it('keeps documentation examples disabled in production configuration', function () {
    expect(Route::has('admin.documentation-example.index'))->toBeFalse();
    expect(config('dam-home.widgets.docs-count'))->toBeNull();
    expect(config('dam-home.widgets.docs-notes'))->toBeNull();
});

it('serves a deliberately installed extension and enforces permission', function () {
    expect(is_file(base_path('examples/documentation-extension/src/ExampleController.php')))->toBeTrue();
    registerDocumentationExample();
    $this->loginWithPermissions(permissions: ['documentation-example']);
    $this->get('/admin/documentation-example')->assertOk()->assertSeeText('Documentation example');
    $this->getJson('/admin/documentation-example/data')->assertOk()->assertJsonPath('message', 'Extension registered');
});

it('denies extension readers without permission', function () {
    expect(is_file(base_path('examples/documentation-extension/src/ExampleController.php')))->toBeTrue();
    registerDocumentationExample();
    $this->loginWithPermissions(permissions: ['help']);
    $this->getJson('/admin/documentation-example/data')->assertForbidden();
});

it('renders reader-visible article counts only after native activation', function () {
    expect(is_file(base_path('examples/widgets/registry.php')))->toBeTrue();
    $registry = require base_path('examples/widgets/registry.php');
    config(['dam-home.widgets.docs-count' => $registry['docs-count']]);
    $this->loginWithPermissions(permissions: ['help']);
    $this->getJson('/admin/home/data/docs-count')->assertOk()->assertJsonPath('articles', count(app(Manual::class)->entries()));
});

it('injects the hosted iframe bootstrap into the inert notes example', function () {
    expect(is_file(base_path('examples/widgets/registry.php')))->toBeTrue();
    $registry = require base_path('examples/widgets/registry.php');
    config(['dam-home.widgets.docs-notes' => $registry['docs-notes']]);
    $this->loginWithPermissions(permissions: ['help']);
    $this->get('/admin/home/tools/docs-notes/index.html')->assertOk()->assertSee('window.__MOTHERSHIP_FRAME__', false)->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

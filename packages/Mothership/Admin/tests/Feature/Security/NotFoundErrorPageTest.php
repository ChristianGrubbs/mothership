<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;

/**
 * The handler only installs its renderables when debug is off, and the suite runs with
 * APP_DEBUG=true, so the bound instance has to be rebuilt with debug disabled.
 */
beforeEach(function () {
    config(['app.debug' => false]);

    app()->forgetInstance(ExceptionHandler::class);
});

it('renders the styled Mothership 404 page for an unknown web route', function () {
    $response = $this->get('admin/this-route-does-not-exist-'.uniqid());

    $response->assertNotFound();
    $response->assertViewIs('admin::errors.index');
    $response->assertViewHas('errorCode', 404);
    $response->assertSeeText(trans('admin::app.errors.404.title'));
});

it('returns a json 404 body for an unknown route when json is requested', function () {
    $response = $this->getJson('admin/this-route-does-not-exist-'.uniqid());

    $response->assertNotFound();
    $response->assertJson([
        'error'       => trans('admin::app.errors.404.title'),
        'description' => trans('admin::app.errors.404.description'),
    ]);
});

it('keeps the branded 403 and 404 pages while debugging, and the debug output for a json request (VIS-05, FAULT-20)', function () {
    config(['app.debug' => true]);
    app()->forgetInstance(ExceptionHandler::class);
    $this->loginAsAdmin();

    $this->get('admin/this-route-does-not-exist-'.uniqid())
        ->assertNotFound()
        ->assertViewIs('admin::errors.index')
        ->assertSeeText(trans('admin::app.errors.404.title'));

    // JSON keeps the framework's debug body: the branded page is for people, a trace is for a developer's client.
    expect($this->getJson('admin/this-route-does-not-exist-'.uniqid())->assertNotFound()->json())->toHaveKey('exception');
});

it('says a disabled feature is off, not to contact an administrator, even to a full admin (FAULT-15)', function () {
    config(['mothership.pim_enabled' => false]);
    $this->loginAsAdmin();
    $disabled = trans('admin::app.errors.feature-disabled', ['name' => config('documentation.name')]);

    $this->get(route('admin.catalog.products.index'))
        ->assertForbidden()
        ->assertSeeText($disabled)
        ->assertDontSeeText(trans('admin::app.errors.403.description'));

    $this->getJson(route('admin.catalog.products.index'))
        ->assertForbidden()
        ->assertJsonPath('message', $disabled);
});

it('passes a deliberately thrown response through instead of rendering it as a 500 (SETUP-09)', function () {
    Route::get('admin/e-failure-thrown-response', fn () => throw new HttpResponseException(response('kept', 429)))->middleware('web');

    $this->get('admin/e-failure-thrown-response')->assertStatus(429)->assertSeeText('kept');
});

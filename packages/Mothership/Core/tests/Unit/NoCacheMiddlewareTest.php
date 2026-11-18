<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Mothership\Core\Http\Middleware\NoCacheMiddleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

function stylePressRequestNamed(?string $routeName): Request
{
    $request = Request::create('/x');
    if ($routeName !== null) {
        $route = new RoutingRoute('GET', '/x', []);
        $route->name($routeName);
        $request->setRouteResolver(fn () => $route);
    }

    return $request;
}

function stylePressImmutableResponse(): Response
{
    $response = new Response('ok');
    $response->setPrivate();
    $response->setMaxAge(31536000);
    $response->headers->addCacheControlDirective('immutable');

    return $response;
}

it('forces no-store on a response that sets neither public nor immutable', function () {
    $response = (new NoCacheMiddleware)->handle(stylePressRequestNamed(null), function () {
        $response = new Response('ok');
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    });

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('leaves a public response untouched', function () {
    $response = (new NoCacheMiddleware)->handle(stylePressRequestNamed(null), function () {
        $response = new Response('ok');
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    });

    expect((string) $response->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('leaves an immutable, long-lived, private response untouched on the StylePress Home thumbnail route', function () {
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed('admin.home.stylepress.thumbnail'),
        fn () => stylePressImmutableResponse()
    );

    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')
        ->toContain('max-age=31536000')
        ->toContain('immutable')
        ->and($cacheControl)->not->toContain('no-store');
});

it('still forces no-store for an immutable, long-lived, private response on an unrelated authenticated route', function () {
    // The bypass is a named-route allowlist, not "any response that says immutable": a
    // different, unrelated admin route emitting the same directives must not silently pick up a
    // year-long private cache just because it resembles a StylePress thumbnail response.
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed('admin.dam.assets.editor.document'),
        fn () => stylePressImmutableResponse()
    );

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('still forces no-store for an immutable, long-lived, private response with no route bound at all', function () {
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed(null),
        fn () => stylePressImmutableResponse()
    );

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('serves a successful DAM byte route as private, no-cache (revalidated, never no-store, never public)', function (string $routeName) {
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed($routeName),
        fn () => new Response('ok')
    );

    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')
        ->toContain('no-cache')
        ->not->toContain('no-store')
        ->not->toContain('public')
        ->and($response->headers->has('Pragma'))->toBeFalse()
        ->and($response->headers->get('Expires'))->toBeNull();
})->with([
    'admin.dam.file.preview',
    'admin.dam.file.thumbnail',
    'admin.dam.file.asset-file',
    'admin.dam.file.cover-art',
    'admin.dam.assets.download',
    'dam.share.asset_view',
    'dam.share.asset_download',
    'dam.share.thumbnail',
    'dam.share.download',
]);

it('keeps no-store on an error response from a DAM byte route', function (int $status) {
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed('admin.dam.file.preview'),
        fn () => new Response('denied', $status)
    );

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache');
})->with([403, 404, 500]);

it('still forces no-store on a DAM route that is not a byte route', function () {
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed('admin.dam.assets.download_compressed'),
        fn () => new Response('ok')
    );

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('overrides the public default of a BinaryFileResponse on a DAM byte route', function (string $routeName) {
    // response()->file() builds a BinaryFileResponse that is `public` out of the box.
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed($routeName),
        fn () => new BinaryFileResponse(__FILE__)
    );

    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')
        ->toContain('no-cache')
        ->not->toContain('public')
        ->not->toContain('no-store');
})->with(['dam.share.thumbnail', 'dam.share.download', 'admin.dam.file.preview']);

it('keeps the public early return on routes outside the byte allowlist', function () {
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed('some.public.route'),
        fn () => new BinaryFileResponse(__FILE__)
    );

    expect((string) $response->headers->get('Cache-Control'))->toContain('public')->not->toContain('no-store');
});

it('keeps no-store, never public, on an unsatisfiable Range answered by a BinaryFileResponse', function () {
    $request = stylePressRequestNamed('dam.share.thumbnail');
    $request->headers->set('Range', 'bytes=99999999-999999999');

    $response = (new NoCacheMiddleware)->handle($request, function () use ($request) {
        $file = new BinaryFileResponse(__FILE__);
        $file->prepare($request);

        return $file;
    });

    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($response->getStatusCode())->toBe(416)
        ->and($cacheControl)->toContain('no-store')->not->toContain('public');
});

it('lets the thumbnail route keep a private immutable response its controller proved versioned', function () {
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed('admin.dam.file.thumbnail'),
        function () {
            $versioned = new Response('bytes');
            $versioned->setPrivate();
            $versioned->setMaxAge(31536000);
            $versioned->setImmutable();

            return $versioned;
        }
    );

    expect((string) $response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=31536000')->toContain('immutable')
        ->and($response->headers->has('Pragma'))->toBeFalse();
});

it('never lets a public or unversioned response, or another byte route, keep an immutable cache', function (string $routeName, bool $public, bool $immutable) {
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed($routeName),
        function () use ($public, $immutable) {
            $candidate = new Response('bytes');
            $public ? $candidate->setPublic() : $candidate->setPrivate();
            $candidate->setMaxAge(31536000);
            $candidate->setImmutable($immutable);

            return $candidate;
        }
    );

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('immutable')->not->toContain('public');
})->with([
    'public on the thumbnail route'     => ['admin.dam.file.thumbnail', true, true],
    'unversioned thumbnail'             => ['admin.dam.file.thumbnail', false, false],
    'immutable on another byte route'   => ['admin.dam.file.preview', false, true],
    'immutable on a share thumbnail'    => ['dam.share.thumbnail', false, true],
]);

it('keeps no-store on a thumbnail error even when the controller said immutable', function () {
    $response = (new NoCacheMiddleware)->handle(
        stylePressRequestNamed('admin.dam.file.thumbnail'),
        function () {
            $failed = new Response('denied', 403);
            $failed->setPrivate();
            $failed->setMaxAge(31536000);
            $failed->setImmutable();

            return $failed;
        }
    );

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('immutable');
});

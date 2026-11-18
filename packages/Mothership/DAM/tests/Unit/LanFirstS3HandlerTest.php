<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Providers\DAMServiceProvider;
use Mothership\DAM\Support\ChunkUploadLogger;
use Mothership\DAM\Support\LanFirstS3Handler;
use Psr\Http\Message\RequestInterface;

const LAN_ENDPOINT = 'http://192.0.2.10:9010';
const PUBLIC_OBJECT = 'https://s3.example.test/bucket/asset.mp4';

function garageAnswer(): Response
{
    return new Response(403, [], '<Error><Message>Forbidden: Garage does not support anonymous access</Message></Error>');
}

function connectionFailure(RequestInterface $request): array
{
    return ['exception' => new ConnectException('network is unreachable', $request), 'connection_error' => true, 'response' => null];
}

/** Probe requests go to the home-network root; object requests are recorded with the body they carried. */
function routeHandler(Closure $onObject, ?Response $probeAnswer = null, ?Repository $cache = null): object
{
    $recorder = new class
    {
        public array $sent = [];

        public int $probes = 0;

        public LanFirstS3Handler $handler;
    };

    $recorder->handler = new LanFirstS3Handler(
        transport: function (RequestInterface $request, array $options) use ($recorder, $onObject, $probeAnswer) {
            if ($request->getUri()->getPath() === '/') {
                $recorder->probes++;

                return Create::promiseFor($probeAnswer ?? garageAnswer());
            }

            $recorder->sent[] = ['request' => $request, 'options' => $options, 'body' => (string) $request->getBody()];

            return $onObject($request, count($recorder->sent));
        },
        lanEndpoint: LAN_ENDPOINT,
        cache: $cache ?? new Repository(new ArrayStore),
        logger: new ChunkUploadLogger,
    );

    return $recorder;
}

it('sends object traffic to the home network and keeps the signed public host', function () {
    $route = routeHandler(onObject: fn () => Create::promiseFor(new Response(200)));

    ($route->handler)(new Request('GET', PUBLIC_OBJECT), [])->wait();

    $sent = $route->sent[0]['request'];
    expect((string) $sent->getUri())->toBe(LAN_ENDPOINT.'/bucket/asset.mp4')
        ->and($sent->getHeaderLine('Host'))->toBe('s3.example.test');
});

it('stays on the public address when something else answers at the home-network address', function () {
    $route = routeHandler(onObject: fn () => Create::promiseFor(new Response(200)), probeAnswer: new Response(200, [], 'router login'));

    ($route->handler)(new Request('GET', PUBLIC_OBJECT), [])->wait();

    expect((string) $route->sent[0]['request']->getUri())->toBe(PUBLIC_OBJECT);
});

it('falls back to the public address mid-session with the whole body, and stays there without a restart', function () {
    $route = routeHandler(onObject: fn (RequestInterface $request, int $attempt) => $attempt === 1
        ? Create::rejectionFor(connectionFailure($request))
        : Create::promiseFor(new Response(200)));

    $response = ($route->handler)(new Request('PUT', PUBLIC_OBJECT, [], Utils::streamFor('file bytes')), [])->wait();
    ($route->handler)(new Request('GET', PUBLIC_OBJECT), [])->wait();

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $route->sent[0]['request']->getUri())->toStartWith(LAN_ENDPOINT)
        ->and((string) $route->sent[1]['request']->getUri())->toBe(PUBLIC_OBJECT)
        ->and($route->sent[1]['body'])->toBe('file bytes')
        ->and((string) $route->sent[2]['request']->getUri())->toBe(PUBLIC_OBJECT)
        ->and($route->probes)->toBe(1);
});

it('sends a body that cannot be replayed straight to the public address', function () {
    $route = routeHandler(onObject: fn () => Create::promiseFor(new Response(200)));
    $body = new NoSeekStream(Utils::streamFor('file bytes'));

    ($route->handler)(new Request('PUT', PUBLIC_OBJECT, [], $body), [])->wait();

    expect($route->sent)->toHaveCount(1)
        ->and((string) $route->sent[0]['request']->getUri())->toBe(PUBLIC_OBJECT)
        ->and($route->sent[0]['body'])->toBe('file bytes');
});

it('returns to the home network once the route check expires', function () {
    $cache = new Repository(new ArrayStore);
    $route = routeHandler(onObject: fn () => Create::promiseFor(new Response(200)), cache: $cache);
    $cache->put(LanFirstS3Handler::ROUTE_CACHE_KEY, 'tunnel', 30);

    ($route->handler)(new Request('GET', PUBLIC_OBJECT), [])->wait();
    $cache->forget(LanFirstS3Handler::ROUTE_CACHE_KEY);
    ($route->handler)(new Request('GET', PUBLIC_OBJECT), [])->wait();

    expect((string) $route->sent[0]['request']->getUri())->toBe(PUBLIC_OBJECT)
        ->and((string) $route->sent[1]['request']->getUri())->toStartWith(LAN_ENDPOINT);
});

it('does not resend a request the bucket itself refused', function () {
    $route = routeHandler(onObject: fn (RequestInterface $request) => Create::rejectionFor([
        'exception' => new RuntimeException('refused'), 'connection_error' => false, 'response' => new Response(403),
    ]));

    expect(fn () => ($route->handler)(new Request('GET', PUBLIC_OBJECT), [])->wait())->toThrow(Exception::class)
        ->and($route->sent)->toHaveCount(1);
});

it('leaves any other S3 disk on its own endpoint', function () {
    $assetEndpoint = 'https://s3.example.test';

    expect(LanFirstS3Handler::appliesTo(diskEndpoint: $assetEndpoint, assetEndpoint: $assetEndpoint))->toBeTrue()
        ->and(LanFirstS3Handler::appliesTo(diskEndpoint: 'https://s3.amazonaws.com', assetEndpoint: $assetEndpoint))->toBeFalse()
        ->and(LanFirstS3Handler::appliesTo(diskEndpoint: null, assetEndpoint: null))->toBeFalse();
});

it('hands browsers the public address when the home-network route is on', function () {
    config([
        'dam.asset_disk_lan_endpoint'      => LAN_ENDPOINT,
        'filesystems.disks.s3.endpoint'    => 'https://s3.example.test',
        'filesystems.disks.lan-route-test' => [
            'driver'                  => 's3',
            'key'                     => 'test-key',
            'secret'                  => 'test-secret',
            'region'                  => 'garage',
            'bucket'                  => 'bucket',
            'endpoint'                => 'https://s3.example.test',
            'use_path_style_endpoint' => true,
        ],
    ]);
    (new ReflectionMethod(DAMServiceProvider::class, 'routeS3DiskOverHomeNetwork'))
        ->invoke(app()->getProvider(DAMServiceProvider::class));

    $url = Storage::disk('lan-route-test')->temporaryUrl('asset.mp4', now()->addMinutes(5));

    expect($url)->toStartWith('https://s3.example.test/bucket/asset.mp4')
        ->and($url)->not->toContain('192.0.2.10');
});

<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Sends the app's own bucket traffic over the home network while the bucket answers there, and over the public
 * address otherwise.
 *
 * It runs below request signing, so the signed Host header stays the public one. Presigned URLs never pass through
 * here: anything a browser is handed keeps the public address. The route is rechecked every 30 seconds and a dropped
 * home-network connection moves traffic to the public address at once, so leaving the network needs no restart.
 */
final class LanFirstS3Handler
{
    public const ROUTE_CACHE_KEY = 'dam:s3-lan-route:state';

    private const LAST_ROUTE_CACHE_KEY = 'dam:s3-lan-route:last';

    private const ROUTE_TTL_SECONDS = 30;

    private const LAN_CONNECT_TIMEOUT_SECONDS = 1.0;

    private const LAN_STALL_SECONDS = 15;

    private const PROBE_TIMEOUT_SECONDS = 1.0;

    private readonly Closure $transport;

    public function __construct(
        callable $transport,
        private readonly string $lanEndpoint,
        private readonly CacheRepository $cache,
        private readonly ChunkUploadLogger $logger,
    ) {
        $this->transport = $transport(...);
    }

    /** Only the asset bucket has a home-network address; any other S3 disk keeps its own endpoint. */
    public static function appliesTo(mixed $diskEndpoint, mixed $assetEndpoint): bool
    {
        return is_string($diskEndpoint) && $diskEndpoint !== '' && $diskEndpoint === $assetEndpoint;
    }

    public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
    {
        $body = $request->getBody();

        // A body that cannot be replayed gets one attempt, so it takes the address that does not go away.
        if (! $body->isSeekable() || ! $this->lanIsReachable()) {
            return ($this->transport)($request, $options);
        }

        $bodyStart = $body->tell();

        return ($this->transport)($this->onLan(request: $request), $this->lanOptions(options: $options))->otherwise(
            function (mixed $reason) use ($request, $options, $body, $bodyStart): mixed {
                if (! is_array($reason) || ($reason['connection_error'] ?? false) !== true) {
                    return Create::rejectionFor($reason);
                }

                $this->remember(route: 'tunnel', reason: 'connection_failed');
                $body->seek($bodyStart);

                return ($this->transport)($request, $options);
            },
        );
    }

    private function lanIsReachable(): bool
    {
        try {
            $route = $this->cache->get(self::ROUTE_CACHE_KEY);
        } catch (Throwable) {
            return false;
        }

        if ($route === 'lan' || $route === 'tunnel') {
            return $route === 'lan';
        }

        $reachable = $this->probe();
        $this->remember(route: $reachable ? 'lan' : 'tunnel', reason: 'probe');

        return $reachable;
    }

    /** Only this bucket's own answer counts: another network can use the same private address. */
    private function probe(): bool
    {
        try {
            $response = ($this->transport)(new Request('GET', rtrim($this->lanEndpoint, '/').'/'), [
                'connect_timeout' => self::PROBE_TIMEOUT_SECONDS,
                'timeout'         => self::PROBE_TIMEOUT_SECONDS,
                'http_errors'     => false,
            ])->wait();

            return $response->getStatusCode() === 403 && str_contains((string) $response->getBody(), 'Garage');
        } catch (Throwable) {
            return false;
        }
    }

    private function onLan(RequestInterface $request): RequestInterface
    {
        $lan = new Uri($this->lanEndpoint);
        $uri = $request->getUri()->withScheme($lan->getScheme())->withHost($lan->getHost())->withPort($lan->getPort());

        return $request->withUri($uri, preserveHost: true);
    }

    private function lanOptions(array $options): array
    {
        $connectTimeout = (float) ($options['connect_timeout'] ?? 0);
        $options['connect_timeout'] = $connectTimeout > 0
            ? min($connectTimeout, self::LAN_CONNECT_TIMEOUT_SECONDS)
            : self::LAN_CONNECT_TIMEOUT_SECONDS;
        $options['curl'] = ($options['curl'] ?? []) + [
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => self::LAN_STALL_SECONDS,
        ];

        return $options;
    }

    private function remember(string $route, string $reason): void
    {
        try {
            $this->cache->put(self::ROUTE_CACHE_KEY, $route, self::ROUTE_TTL_SECONDS);

            if ($this->cache->get(self::LAST_ROUTE_CACHE_KEY) === $route) {
                return;
            }

            $this->cache->forever(self::LAST_ROUTE_CACHE_KEY, $route);
            $this->logger->write(
                runId: 's3-lan-route',
                level: 'info',
                event: 's3_lan_route.changed',
                loc: self::class,
                ctx: ['route' => $route, 'reason' => $reason],
            );
        } catch (Throwable) {
            // The route choice must never fail a storage request.
        }
    }
}

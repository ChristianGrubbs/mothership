<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Share;
use Mothership\User\Models\Admin;

/**
 * What a page's collector is given (§3.6.6), rendered as JSON beside the script. Null means the page loads no
 * collector at all: analytics off, an admin who opted out, or a browser sending DNT or GPC. Nothing then leaves the
 * browser; ingest still enforces the same rules for a page that was open before the choice changed.
 *
 * Keys are one letter to keep the collector small: e endpoint, t capability, n allowed event names, m session
 * marker (a new one starts a new session), u default surface, o route name (the only "where" an error or vital
 * carries), f/b/q/r flush seconds, flush size, queue cap and events per minute, v vitals sample rate, k a link page's
 * view token and a its asset.
 */
final class CollectorConfig
{
    public function __construct(
        private readonly OptOuts $optOuts,
    ) {}

    /** @return ?array<string, mixed> */
    public function forAdmin(?Admin $admin, Request $request): ?array
    {
        // A page served without a session (the P6b embed player, which sets no cookies) is no admin session's page.
        if (! $admin instanceof Admin || ! $request->hasSession() || ! $this->collecting(request: $request) || $this->optOuts->has((int) $admin->id)) {
            return null;
        }

        return $this->config(scope: EventRegistry::SCOPE_ADMIN, request: $request) + [
            'e' => route('admin.dam.analytics.ingest'),
            't' => IngestToken::issue(scope: EventRegistry::SCOPE_ADMIN, boundTo: (int) $admin->id),
            // A new session is a new session token (logout and expiry replace it): the marker changes, so does the
            // collector's session (§3.6.2). Not the session id, which login rotation also changes mid-session; the
            // token is what every page of one session already shares, so this adds nothing a page differs by.
            'm'      => substr(hash_hmac('sha256', 'dam-analytics-marker|'.$request->session()->token(), (string) config('app.key')), 0, 12),
            'u'      => null,
            'replay' => $this->replay(admin: $admin, request: $request),
        ];
    }

    /** @return ?array<string, mixed> */
    public function forShare(Share $share, Request $request, ?string $viewToken, ?int $assetId): ?array
    {
        if (! self::collecting(request: $request) || ! $share->isActive()) {
            return null;
        }

        return $this->config(scope: EventRegistry::SCOPE_SHARE, request: $request) + [
            'e' => route('dam.analytics.ingest'),
            't' => IngestToken::issue(scope: EventRegistry::SCOPE_SHARE, boundTo: (int) $share->id),
            'm' => 's'.$share->id,
            'u' => 'share',
            'k' => $viewToken ?? (string) Str::uuid(),
            'a' => $assetId,
        ];
    }

    /** Whether a page answered to this request carries the collector (analytics on, no DNT or GPC). */
    public static function collecting(Request $request): bool
    {
        return (bool) config('dam_events.enabled') && ! ActorResolver::privacySignal(request: $request);
    }

    /** @return ?array<string, mixed> */
    private function replay(Admin $admin, Request $request): ?array
    {
        $key = config('dam_events.replay.public_key');
        $host = rtrim((string) config('dam_events.posthog.host'), '/');
        if (! ReplaySession::enabled() || ! is_string($key) || ! preg_match('/\A[a-zA-Z0-9_-]{3,200}\z/', $key)
            || ! str_starts_with($host, 'https://') || filter_var($host, FILTER_VALIDATE_URL) === false
            || parse_url($host, PHP_URL_USER) !== null || parse_url($host, PHP_URL_QUERY) !== null || parse_url($host, PHP_URL_FRAGMENT) !== null
            || ! in_array(parse_url($host, PHP_URL_PATH), [null, ''], true) || ActorResolver::suspectedBot(request: $request)) {
            return null;
        }

        return [
            'public_key'  => $key, 'api_host' => $host,
            'relay'       => route('admin.dam.analytics.replay', ['path' => '']),
            'distinct_id' => PosthogIdentity::distinctId(actor: new AnalyticsActor(type: AnalyticsActor::ADMIN, key: (string) $admin->id)),
            'environment' => app()->environment(),
            'release'     => config('dam_events.posthog.release'),
        ];
    }

    /** @return array<string, mixed> */
    private function config(string $scope, Request $request): array
    {
        $route = (string) $request->route()?->getName();

        return [
            'n' => array_keys(EventRegistry::clientCatalogue($scope)),
            'o' => preg_match('/\A[A-Za-z0-9_.:-]{1,48}\z/', $route) ? $route : 'other',
            'f' => (int) config('dam_events.collector.flush_seconds', 10),
            'b' => min((int) config('dam_events.collector.flush_at', 25), (int) config('dam_events.ingest.max_events', 25)),
            'q' => (int) config('dam_events.collector.queue_cap', 200),
            'r' => (int) config('dam_events.collector.per_minute', 60),
            'v' => (float) config('dam_events.collector.vitals_sample', 0.1),
        ];
    }
}

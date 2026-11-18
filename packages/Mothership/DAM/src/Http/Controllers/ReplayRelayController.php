<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\Analytics\CollectorConfig;
use Mothership\DAM\Support\AnalyticsLog;
use Mothership\User\Models\Admin;
use Throwable;

/** The authenticated SDK's fixed upstream relay. Every upload rechecks privacy; bodies never enter local logs/storage. */
final class ReplayRelayController extends Controller
{
    public function __construct(private readonly CollectorConfig $collector) {}

    public function relay(Request $request, ?string $path = null): Response
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);
        $path = trim((string) $path, '/');
        $config = $this->collector->forAdmin(admin: $admin, request: $request)['replay'] ?? null;
        if ($config === null) {
            return response('', 403)->header('X-Dam-Replay-Enabled', '0');
        }
        $remoteConfig = $path === 'array/'.$config['public_key'].'/config' && $request->isMethod('GET');
        abort_unless($remoteConfig || (in_array($path, ['flags', 's'], true) && $request->isMethod('POST')), 404);
        $body = $request->getContent();
        if (strlen($body) > 2_000_000) {
            return response('', 413);
        }
        $contentType = (string) $request->header('Content-Type', 'text/plain');
        $encoding = (string) $request->header('Content-Encoding', '');
        if (! preg_match('/\A(?:application\/(?:json|x-www-form-urlencoded|octet-stream)|text\/plain)(?:;\s*charset=utf-8)?\z/i', $contentType)
            || ! in_array(strtolower($encoding), ['', 'gzip', 'deflate', 'br'], true)) {
            return response('', 415);
        }
        $query = [];
        foreach (['v', 'ip', '_', 'ver', 'compression', 'lib', 'sdk', 'sdk_version', 'config'] as $name) {
            $value = $request->query($name);
            if (is_string($value) && strlen($value) <= 200) {
                $query[$name] = $value;
            }
        }
        try {
            // Never forward cookies, CSRF, Authorization, Origin, or a client-supplied upstream/redirect target.
            $headers = ['Content-Type' => $contentType, 'Accept' => 'application/json'];
            if ($encoding !== '') {
                $headers['Content-Encoding'] = $encoding;
            }
            $host = $remoteConfig ? match ($config['api_host']) {
                'https://us.i.posthog.com' => 'https://us-assets.i.posthog.com',
                'https://eu.i.posthog.com' => 'https://eu-assets.i.posthog.com',
                default                    => $config['api_host'],
            } : $config['api_host'];
            $upstream = Http::connectTimeout(3)->timeout(10)->withoutRedirecting()->withHeaders($headers)
                ->send(method: $remoteConfig ? 'GET' : 'POST', url: $host.'/'.$path.($remoteConfig ? '' : '/'), options: ['body' => $body, 'query' => $query]);
            if (! $upstream->successful()) {
                AnalyticsLog::write(level: 'error', event: 'replay.relay_failed', ctx: ['status' => $upstream->status()]);

                return response('', 503);
            }

            // Remote settings may enable recording and select its rate/duration, never alternate hosts,
            // scripts, console/network capture, feature flags, or weaker masking.
            $reply = '{}';
            if ($remoteConfig) {
                $recording = $upstream->json('sessionRecording');
                $safe = false;
                if (is_array($recording)) {
                    $safe = ['endpoint' => '/s/'];
                    foreach (['sampleRate' => 1, 'minimumDurationMilliseconds' => 3_600_000] as $name => $max) {
                        $value = $recording[$name] ?? null;
                        if (is_numeric($value) && (float) $value >= 0 && (float) $value <= $max) {
                            $safe[$name] = (float) $value;
                        }
                    }
                }
                $reply = json_encode(['sessionRecording' => $safe, 'hasFeatureFlags' => false], JSON_THROW_ON_ERROR);
            }

            return response($reply, 200, [
                'Content-Type' => 'application/json', 'Cache-Control' => 'no-store',
            ]);
        } catch (Throwable $error) {
            AnalyticsLog::write(level: 'error', event: 'replay.relay_failed', ctx: ['exception' => $error::class]);

            return response('', 503);
        }
    }
}

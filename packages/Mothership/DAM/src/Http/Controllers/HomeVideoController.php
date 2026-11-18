<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\HomeTelemetry;
use Mothership\DAM\Services\HomeWidgetRegistry;

/**
 * The Home "Video Downloader" tool: paste a link, pick a size, save the file to this device.
 *
 * The work is done by the Studio's native video service (tools/studio/video-service, docs/ops/studio-video.md),
 * which keeps one queue per person. This controller is its only caller: it decides who is asking (the signed-in
 * account's id is the queue owner) and passes the rest through, so a download started on one device shows up on
 * every device that account signs in from.
 */
class HomeVideoController extends Controller
{
    public const WIDGET = 'video-downloader';

    public function __construct(private readonly HomeWidgetRegistry $registry) {}

    public function probe(Request $request): JsonResponse
    {
        $this->registry->allowed(self::WIDGET);

        $data = $request->validate(['url' => ['required', 'string', 'max:2048', 'regex:#^https?://\S+$#i']], [
            'url.*' => 'Paste the full link to a video, starting with http:// or https://.',
        ]);

        return $this->relay(fn (PendingRequest $http) => $http->timeout(95)->post($this->api('probe'), ['url' => $data['url']]), 'probe');
    }

    public function index(): JsonResponse
    {
        $this->registry->allowed(self::WIDGET);

        return $this->relay(fn (PendingRequest $http) => $http->timeout(15)->get($this->api('jobs'), ['owner' => $this->owner()]), 'jobs');
    }

    public function store(Request $request): JsonResponse
    {
        $this->registry->allowed(self::WIDGET);

        $data = $request->validate([
            'url'      => ['required', 'string', 'max:2048', 'regex:#^https?://\S+$#i'],
            'choice'   => ['required', 'string', 'regex:/^(best|mp3|v[0-9]{2,5})$/D'],
            'title'    => ['nullable', 'string', 'max:200'],
            'optimize' => ['sometimes', 'boolean'],
        ]);
        $data['optimize'] = (bool) ($data['optimize'] ?? false);

        return $this->relay(fn (PendingRequest $http) => $http->timeout(15)->post($this->api('jobs'), $data + ['owner' => $this->owner()]), 'queue');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->registry->allowed(self::WIDGET);

        return $this->relay(fn (PendingRequest $http) => $http->timeout(30)->delete($this->api('jobs/'.$id.'?owner='.$this->owner())), 'remove');
    }

    /** Makes a finished video smaller on the Studio (its Clop service); the smaller file replaces the kept one. */
    public function optimize(string $id): JsonResponse
    {
        $this->registry->allowed(self::WIDGET);

        return $this->relay(fn (PendingRequest $http) => $http->timeout(30)->post($this->api('jobs/'.$id.'/optimize?owner='.$this->owner())), 'optimize');
    }

    /** Streams a finished file to the browser as an attachment; Range is passed through so a dropped save can resume. */
    public function file(Request $request, string $id)
    {
        $this->registry->allowed(self::WIDGET);

        $headers = $request->header('Range') ? ['Range' => $request->header('Range')] : [];
        try {
            $response = $this->http()->timeout(0)->withHeaders($headers)->withOptions(['stream' => true, 'read_timeout' => 120])
                ->get($this->api('files/'.$id), ['owner' => $this->owner()]);
        } catch (ConnectionException) {
            return $this->unavailable('file');
        }
        if (! $response->successful()) {
            return $this->passJson($response, 'file');
        }
        HomeTelemetry::record(event: 'video.file', ctx: ['admin_id' => auth('admin')->id(), 'job' => $id, 'status' => $response->status()]);
        $out = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Accel-Buffering' => 'no'];
        foreach (['Content-Type', 'Content-Length', 'Content-Range', 'Accept-Ranges', 'Content-Disposition'] as $header) {
            if ($response->header($header)) {
                $out[$header] = $response->header($header);
            }
        }
        $body = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($body): void {
            set_time_limit(0);
            try {
                while (! $body->eof()) {
                    echo $body->read(1048576);
                    flush();
                }
            } finally {
                $body->close();
            }
        }, $response->status(), $out);
    }

    private function relay(callable $call, string $action): JsonResponse
    {
        try {
            return $this->passJson($call($this->http()), $action);
        } catch (ConnectionException) {
            return $this->unavailable($action);
        }
    }

    private function passJson(Response $response, string $action): JsonResponse
    {
        $payload = $response->json();
        $status = $response->status();
        HomeTelemetry::record(event: 'video.'.$action, ctx: ['admin_id' => auth('admin')->id(), 'status' => $status], level: $status >= 500 ? 'warning' : 'info');
        if (! is_array($payload)) {
            return response()->json(['message' => 'The video downloader gave an answer The Mothership could not read. Try again.'], 502);
        }

        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }

    private function unavailable(string $action): JsonResponse
    {
        HomeTelemetry::record(event: 'video.unavailable', ctx: ['action' => $action, 'status' => 503], level: 'warning');

        return response()->json(['message' => 'The video downloader is not answering right now. Your queued downloads are kept; try again in a minute.'], 503);
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(5)->withoutRedirecting()->acceptJson();
    }

    private function api(string $path): string
    {
        return rtrim((string) config('dam-home.video_api'), '/').'/'.$path;
    }

    private function owner(): string
    {
        return (string) auth('admin')->id();
    }
}

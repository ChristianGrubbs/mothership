<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\HomeTelemetry;
use Mothership\DAM\Services\HomeWidgetRegistry;

class HomeServiceController extends Controller
{
    public function proxy(Request $request, string $service, string $path, HomeWidgetRegistry $registry)
    {
        $widget = match ($service) {
            'rss' => 'rss', 'trending' => 'trending', 'looper' => 'looper', default => abort(404)
        };
        $registry->allowed($widget);
        $allowed = match ($service) {
            'rss'      => $request->isMethod('get') && preg_match('#^v1/(announcements|stories)$#D', $path),
            'trending' => $request->isMethod('get') && preg_match('#^v1/trending(?:/\d{4}-\d{2}-\d{2})?$#D', $path),
            'looper'   => ($request->isMethod('get') && preg_match('#^(health|(?:metadata|frame|timeline-samples|match-score|match-candidates|download|static)/[a-zA-Z0-9_.-]+)$#D', $path))
                || ($request->isMethod('post') && in_array($path, ['upload', 'trim'], true)),
        };
        abort_unless($allowed, 404);
        if ($service === 'looper' && ! in_array($path, ['health', 'upload'], true)) {
            $filename = $path === 'trim' ? $request->input('filename', '') : substr($path, strpos($path, '/') + 1);
            $fileId = substr($filename, 0, 36);
            abort_unless(DB::table('dam_home_tool_files')->where('file_id', $fileId)->where('admin_id', auth('admin')->id())->exists(), 403);
        }
        $origin = (in_array($service, ['rss', 'trending'], true) ? config('dam-home.rss_origin') : null) ?: config('app.url');
        $upstream = Http::connectTimeout(5)->timeout(120)->withoutRedirecting()->withHeaders(['Origin' => rtrim($origin, '/')]);
        $base = (string) config('dam-home.'.$service.'_api');
        if (trim($base) === '') {
            HomeTelemetry::record(event: 'service.unavailable', ctx: ['service' => $service, 'status' => 503, 'reason' => 'unconfigured'], level: 'warning');

            return response()->json(['message' => 'This widget service is unavailable. Please retry shortly.'], 503);
        }
        $url = rtrim($base, '/').'/'.$path;
        $binary = $request->isMethod('get') && preg_match('#^(static|download|frame)/#', $path);
        if ($binary) {
            $upstream->withOptions(['stream' => true, 'read_timeout' => 120]);
        }
        try {
            if ($request->hasFile('file')) {
                $request->validate(['file' => 'required|file|max:524288']);
                $file = $request->file('file');
                $response = $upstream->attach('file', fopen($file->getRealPath(), 'rb'), $file->getClientOriginalName())->post($url);
            } elseif ($request->isMethod('post')) {
                $data = $request->validate(['filename' => 'required|string|max:150|regex:/^[a-zA-Z0-9_.-]+$/', 'start_time' => 'sometimes|numeric|min:0', 'cut_time' => 'required|numeric|min:0']);
                $response = $upstream->post($url, $data);
            } else {
                $query = $request->only(['limit', 'feedId', 'state', 'q', 'time', 'count', 'ref_time', 'target_time', 'min_time', 'max_time', 'refresh']);
                $headers = $request->header('Range') ? ['Range' => $request->header('Range')] : [];
                $response = $upstream->withHeaders($headers)->get($url, $query);
            }
        } catch (ConnectionException) {
            HomeTelemetry::record(event: 'service.unavailable', ctx: ['service' => $service, 'status' => 503], level: 'warning');

            return response()->json(['message' => 'This widget service is unavailable. Please retry shortly.'], 503);
        }
        if ($service === 'looper' && $path === 'upload' && $response->successful()) {
            $filename = $response->json('filename');
            abort_unless(is_string($filename) && preg_match('/^[a-f0-9-]{36}\.[a-z0-9]+$/D', $filename), 502);
            DB::table('dam_home_tool_files')->insert(['file_id' => substr($filename, 0, 36), 'admin_id' => auth('admin')->id(), 'created_at' => now()]);
        }
        HomeTelemetry::record(event: 'service.response', ctx: ['service' => $service, 'status' => $response->status()]);
        $headers = ['Content-Type' => $response->header('Content-Type') ?: 'application/json', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        foreach (['Content-Range', 'Accept-Ranges', 'Content-Disposition'] as $header) {
            if ($response->header($header)) {
                $headers[$header] = $response->header($header);
            }
        }
        if ($binary && $response->successful()) {
            $body = $response->toPsrResponse()->getBody();
            $headers['X-Accel-Buffering'] = 'no';
            if ($response->header('Content-Length')) {
                $headers['Content-Length'] = $response->header('Content-Length');
            }

            return response()->stream(function () use ($body): void {
                try {
                    while (! $body->eof()) {
                        echo $body->read(65536);
                        flush();
                    }
                } finally {
                    $body->close();
                }
            }, $response->status(), $headers);
        }

        return response($response->body(), $response->status(), $headers);
    }
}

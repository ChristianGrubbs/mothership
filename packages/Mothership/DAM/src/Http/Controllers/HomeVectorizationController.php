<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\DAM\Vectorization\Allowance;
use Mothership\DAM\Vectorization\Requests;
use Mothership\DAM\Vectorization\Vectorizer;

class HomeVectorizationController extends Controller
{
    private function access(): int
    {
        app(HomeWidgetRegistry::class)->allowed('vectorization');

        return (int) auth('admin')->id();
    }

    /** A result names its source asset when it was made from one; an upload of the viewer's own names none. */
    private function sourceOpen(object $row): bool
    {
        return app(AssetAccessDecision::class)->sourceOpen(context: AccessContext::library(), sourceAssetId: $row->source_asset_id === null ? null : (int) $row->source_asset_id);
    }

    public function index()
    {
        $this->access();

        return response()->view('dam::home.vectorization')
            ->header('Cache-Control', 'private, no-store');
    }

    public function state(Allowance $allowance, Requests $requests, Vectorizer $provider)
    {
        $adminId = $this->access();
        // A result made from a Library asset lists only while the viewer may open that asset (P2 S3 review): the
        // Library's own listing rule, so a hidden, draft or out-of-window source drops its results out too.
        $openSources = Asset::query()->select('dam_assets.id');
        app(AssetAccessDecision::class)->scopeListable(query: $openSources, context: AccessContext::library(AccessContext::PURPOSE_LIST));
        $rows = DB::table('dam_vectorizations')
            ->where('admin_id', $adminId)
            ->where(fn ($source) => $source->whereNull('source_asset_id')->orWhereIn('source_asset_id', $openSources->toBase()))
            ->latest('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'ready'     => $provider->ready(),
            'allowance' => $allowance->read(adminId: $adminId),
            'results'   => $rows->map(fn ($row) => $requests->publicResult(row: $row))->all(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Requests $requests, Vectorizer $provider)
    {
        $adminId = $this->access();
        abort_unless($provider->ready(), 503, 'Image Vectorization is not connected.');
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'image'      => ['required', 'file', 'mimes:png,jpg,jpeg,webp,bmp,gif,tif,tiff', 'max:20480'],
        ]);
        $file = $request->file('image');
        $dimensions = @getimagesize($file->getRealPath());
        abort_unless(
            $dimensions
                && $dimensions[0] > 0
                && $dimensions[1] > 0
                && $dimensions[0] * $dimensions[1] <= 33554432,
            422,
            'Choose an image up to 33,554,432 pixels.',
        );
        $path = 'vectorizations/input/'.Str::uuid();
        $disk = Storage::disk('private');
        $bytes = file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes) && $disk->put($path, $bytes), 503, 'Image could not be stored.');
        $keep = false;

        try {
            $row = $requests->submit(
                adminId: $adminId,
                requestId: $data['request_id'],
                inputPath: $path,
                sha: hash('sha256', $bytes),
            );
            $keep = $row->input_path === $path;

            return response()->json(['result' => $requests->publicResult(row: $row)], 202)
                ->header('Cache-Control', 'private, no-store');
        } finally {
            if (! $keep) {
                $disk->delete($path);
            }
        }
    }

    public function show(string $id, Requests $requests)
    {
        $row = DB::table('dam_vectorizations')
            ->where('admin_id', $this->access())
            ->where('id', $id)
            ->first();
        abort_unless($row && $this->sourceOpen(row: $row), 404);

        return response()->json(['result' => $requests->publicResult(row: $row)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function file(Request $request, string $id, string $format)
    {
        abort_unless(in_array($format, ['svg', 'pdf'], true), 404);
        $row = DB::table('dam_vectorizations')
            ->where('admin_id', $this->access())
            ->where('id', $id)
            ->where('status', 'completed')
            ->first();
        // A result of a Library asset the viewer can no longer open answers like a missing one (P2 S3 review).
        abort_unless($row && $this->sourceOpen(row: $row), 404);
        $disk = Storage::disk('private');
        $path = 'vectorizations/results/'.$id.'/result.'.$format;
        abort_unless($disk->exists($path), 404);
        $headers = [
            'Content-Type'             => $format === 'svg' ? 'image/svg+xml' : 'application/pdf',
            'Cache-Control'            => 'private, no-store',
            'X-Content-Type-Options'   => 'nosniff',
            'Content-Security-Policy'  => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
        ];

        $response = $format === 'svg' && $request->boolean('preview')
            ? response()->file($disk->path($path), $headers)
            : response()->download($disk->path($path), 'vectorized-'.$id.'.'.$format, $headers);

        // Both branches build a Symfony BinaryFileResponse, whose constructor defaults to
        // $public = true and calls setPublic(): that drops the 'private' set above and adds
        // 'public'. A 'public' directive also opts the response out of NoCacheMiddleware's
        // forced no-store entirely, so restore the intent after construction. One person's
        // vectorized result is never a shared-cacheable document.
        return $response->setPrivate();
    }
}

<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Optimization\OptimizationSource;
use Mothership\DAM\Optimization\Optimizer;
use Mothership\DAM\Optimization\Requests;
use Mothership\DAM\Traits\AssetAccessControl;

class AssetOptimizationController extends Controller
{
    use AssetAccessControl;

    /** Fixture-verified allowlist (Product section of the 2026-09-26 Clop Optimize spec). */
    private const TYPES = [
        'image' => ['jpg', 'jpeg', 'png'],
        'video' => ['mp4'],
        'pdf'   => ['pdf'],
    ];

    private function authorizeSource(int $id): Asset
    {
        $asset = $this->damVisibleAssetOrFail($id);
        abort_unless($this->damCanEditAsset($id) && bouncer()->hasPermission('dam.asset.files'), 403);

        return $asset;
    }

    /** The stacked file named by `attachment` (query or body), null for the main file. */
    private function requestedSlot(Request $request): ?int
    {
        $attachmentId = $request->validate(['attachment' => ['nullable', 'integer', 'min:1']])['attachment'] ?? null;

        return $attachmentId === null ? null : (int) $attachmentId;
    }

    /** What this request optimizes: the main file, or one stacked file that must still be a member of the asset. */
    private function source(Asset $asset, ?int $attachmentId): OptimizationSource
    {
        try {
            return OptimizationSource::resolve(asset: $asset, attachmentId: $attachmentId);
        } catch (ContentUnavailable) {
            abort(404, $attachmentId === null ? 'The original file is unavailable.' : 'That file is no longer in this stack.');
        }
    }

    public function state(Request $request, int $id, Requests $requests, Optimizer $provider)
    {
        $asset = $this->authorizeSource($id);
        $attachmentId = $this->requestedSlot(request: $request);
        // The main file's slot asked by number is the main file: its rows carry no slot.
        $slot = $attachmentId === null ? null : $this->source(asset: $asset, attachmentId: $attachmentId)->attachmentId();
        $adminId = (int) auth('admin')->id();
        $row = DB::table('dam_optimizations')
            ->where('asset_id', $id)->where('admin_id', $adminId)
            ->when($slot === null, fn ($query) => $query->whereNull('attachment_id'), fn ($query) => $query->where('attachment_id', $slot))
            ->orderByRaw("CASE WHEN status IN ('queued', 'processing') THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')->orderByDesc('created_at')->orderByDesc('id')->first();

        return response()->json([
            'ready'  => $provider->ready(),
            'result' => $row ? $requests->publicResult(row: $row) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $id, Requests $requests, Optimizer $provider)
    {
        $asset = $this->authorizeSource($id);
        abort_unless($provider->ready(), 503, 'Optimization is currently unavailable.');

        $attachmentId = $this->requestedSlot(request: $request);
        $typeOf = fn (string $extension) => collect(self::TYPES)->search(fn (array $extensions) => in_array($extension, $extensions, true));
        // The main file's type is known before its bytes are looked up; a stacked file's only once its slot is found.
        abort_if($attachmentId === null && $typeOf(strtolower($asset->extension ?? '')) === false, 422, 'Choose a supported image, video or PDF.');
        $source = $this->source(asset: $asset, attachmentId: $attachmentId);
        $type = $typeOf($source->extension);
        abort_if($type === false, 422, 'Choose a supported image, video or PDF.');

        $data = $this->validated(request: $request, type: $type);
        $object = $source->object;

        $maxBytes = (int) config('optimization.max_bytes');
        abort_if($maxBytes > 0 && $object->size > $maxBytes, 422, 'This file is too large to optimize.');

        $row = $requests->submit(
            adminId: (int) auth('admin')->id(),
            requestId: $data['request_id'],
            assetId: $id,
            attachmentId: $source->attachmentId(),
            type: $type,
            options: $data['options'],
            sourceSha256: (string) $object->sha256,
            sourceRevisionId: $source->member === null ? null : (int) $source->member->id,
        );

        return response()->json(['result' => $requests->publicResult(row: $row)], 202)->header('Cache-Control', 'private, no-store');
    }

    /** @return array{request_id: string, options: array<string, string>} */
    private function validated(Request $request, string $type): array
    {
        $requestId = $request->validate(['request_id' => ['required', 'uuid']])['request_id'];

        $options = match ($type) {
            'image' => $request->validate([
                'scale'    => ['required', 'in:1,0.75,0.5'],
                'adaptive' => ['required', 'in:0,1'],
            ]),
            'video' => $request->validate([
                'compression'   => ['required', 'in:25,50,75'],
                'encoder'       => ['required', 'in:hardware,software'],
                'scale'         => ['required', 'in:1,0.75,0.5'],
                'remove_audio'  => ['required', 'in:0,1'],
            ]),
            'pdf' => [],
        };

        return ['request_id' => $requestId, 'options' => $options];
    }
}

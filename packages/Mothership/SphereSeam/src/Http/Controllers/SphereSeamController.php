<?php

namespace Mothership\SphereSeam\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\GenerationAsset;
use Mothership\DAM\Rules\VisibleAsset;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Traits\AssetAccessControl;
use Mothership\SphereSeam\Jobs\GenerateSphereSeamBridge;
use Mothership\SphereSeam\Models\SphereSeamGeneration;
use Mothership\SphereSeam\Services\SphereSeamHiggsfield;
use Symfony\Component\HttpFoundation\HeaderUtils;

class SphereSeamController extends Controller
{
    use AssetAccessControl;

    public function __construct(protected SphereSeamHiggsfield $higgsfield) {}

    public function index()
    {
        $this->authorizeSphereSeam();

        return view('sphere-seam::index', $this->editorData());
    }

    /**
     * Home screen widget: the full editor mounted inside the Home workspace popup, on the
     * chrome-less admin layout (same Vue bundle, asset picker and styles as the full page).
     */
    public function frame()
    {
        $this->authorizeSphereSeam();

        return response()->view('sphere-seam::frame', $this->editorData())->header('Cache-Control', 'private, no-store');
    }

    private function editorData(): array
    {
        return [
            'estimateUrl'     => route('sphereseam.estimate'),
            'generateUrl'     => route('sphereseam.generate'),
            'historyUrl'      => route('sphereseam.history'),
            'assetResolveUrl' => route('sphereseam.assets.resolve'),
            'statusUrl'       => route('sphereseam.status', ['generation' => '__ID__']),
            'retryUrl'        => route('sphereseam.retry', ['generation' => '__ID__']),
            'importUrl'       => route('sphereseam.import', ['generation' => '__ID__']),
            'sdkReady'        => $this->higgsfield->configured(),
            'canCreateAssets' => bouncer()->hasPermission('dam.asset.create'),
            // The browser checks every blob against the same ceilings this controller validates,
            // so an oversized panorama is reported by size before PHP silently discards the body.
            'uploadLimits' => [
                'import'     => $this->uploadKilobytes('import') * 1024,
                'normalized' => $this->uploadKilobytes('normalized') * 1024,
                'bridge'     => $this->uploadKilobytes('bridge') * 1024,
            ],
        ];
    }

    /**
     * The browser geometry module, served from the package like the Home tool files are.
     * On the deployed runtime FPM serves code from its image while nginx serves the checkout,
     * so a vendor:publish into the image's public/ would never reach the browser.
     */
    public function script()
    {
        $this->authorizeSphereSeam();

        return response()->file(__DIR__.'/../../../Resources/assets/sphereseam/sphereseam.js', ['Content-Type' => 'application/javascript', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Streams the generated bridge through the app so the browser can draw it on a canvas
     * without a cross-origin taint. Only the recorded provider result hosts are fetched.
     */
    public function bridge(string $generation)
    {
        $this->authorizeSphereSeam();
        $item = $this->owned($generation);
        abort_unless($item->status === 'completed' && is_string($item->bridge_result_url), 404);
        $host = parse_url($item->bridge_result_url, PHP_URL_HOST);
        $allowed = is_string($host) && parse_url($item->bridge_result_url, PHP_URL_SCHEME) === 'https' && collect((array) config('sphereseam.result_allowed_hosts', []))
            ->contains(fn ($suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix));
        abort_unless($allowed, 404);
        $response = Http::withOptions(['stream' => true])->connectTimeout(5)->timeout(60)->get($item->bridge_result_url);
        abort_unless($response->successful(), 502, 'The generated seam bridge is not available from the provider.');
        $bytes = $response->body();
        abort_if(strlen($bytes) > (int) config('sphereseam.max_result_bytes'), 413, 'The generated seam bridge is too large.');
        $info = @getimagesizefromstring($bytes);
        abort_unless(is_array($info) && in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true), 415, 'The provider returned something other than an image.');

        return response($bytes)->header('Content-Type', $info['mime'])->header('Cache-Control', 'private, max-age=300')->header('X-Content-Type-Options', 'nosniff');
    }

    public function resolveAssets(Request $request): JsonResponse
    {
        $this->authorizeSphereSeam();
        $data = $request->validate(['ids' => ['required', 'array', 'size:1'], 'ids.*' => ['integer']]);
        $asset = $this->visibleImage($data['ids'][0]);

        return response()->json(['item' => [
            'id'         => $asset->id,
            'name'       => $asset->display_name,
            'source_url' => route('sphereseam.assets.source', $asset->id),
            'prompt'     => $this->savedPrompt($asset),
        ]]);
    }

    /**
     * The generation prompt the Details pane shows for this asset, so choosing a DAM image
     * pre-fills "Scene context". Same source and precedence as AssetController::generationPrompt.
     */
    private function savedPrompt(Asset $asset): ?string
    {
        $generation = GenerationAsset::where('asset_id', $asset->id)->orderByDesc('updated_at')->orderByDesc('id')->first();
        $manifest = is_array($generation?->manifest) ? $generation->manifest : [];
        $prompt = collect(['original_prompt', 'enhanced_prompt', 'prompt'])
            ->map(fn ($key) => is_string($manifest[$key] ?? null) ? trim($manifest[$key]) : '')
            ->first(fn ($value) => $value !== '');

        return $prompt === null ? null : mb_substr($prompt, 0, 2000);
    }

    public function assetSource(string $asset)
    {
        $this->authorizeSphereSeam();
        $item = $this->visibleImage($asset);

        try {
            $object = app(ContentStore::class)->current($item);
        } catch (ContentUnavailable) {
            abort(404);
        }

        return app(ContentStore::class)->deliver(
            object: $object,
            filename: $item->file_name,
            disposition: HeaderUtils::DISPOSITION_INLINE,
            mimeType: $item->mime_type ?: ($object->mime_type ?: null),
            headers: ['Cache-Control' => 'private, no-store'],
        );
    }

    public function estimate(Request $request): JsonResponse
    {
        $this->authorizeSphereSeam();
        $data = $this->validateSettings($request);
        try {
            return response()->json(['ok' => true, 'estimate' => $this->higgsfield->estimate(model: $data['model'], prompt: $this->prompt($data['scene_description'] ?? '', (float) ($data['repair_fraction'] ?? 0.4)), ratio: $data['bridge_ratio'], quality: $data['quality'], resolution: $data['resolution'])]);
        } catch (\Throwable $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function generate(Request $request): JsonResponse
    {
        $this->authorizeSphereSeam();
        $data = $this->validateSettings($request) + $request->validate([
            'confirmed'          => ['accepted'],
            // Set when the panorama was built from a DAM image, so the export can be linked back to it.
            // VisibleAsset, not `exists`: a hidden source fails exactly as a missing one does.
            'source_asset_id'    => ['nullable', 'integer', new VisibleAsset],
            // WebP is accepted alongside PNG because the browser now encodes the panorama as
            // lossless WebP: identical pixels, materially fewer bytes, and the difference between
            // an 8192x4096 panorama fitting inside post_max_size and being dropped by PHP.
            // The seam bridge stays PNG — that is the format the provider runs were proven on.
            'normalized'         => ['required', 'file', 'mimes:png,webp', 'max:'.$this->uploadKilobytes('normalized')],
            'bridge'             => ['required', 'file', 'mimes:png', 'max:'.$this->uploadKilobytes('bridge')],
            'strip_width'        => ['required', 'integer', 'min:32', 'max:8192'],
            'repair_width'       => ['required', 'integer', 'min:16', 'max:8192'],
            'feather_width'      => ['required', 'integer', 'min:1', 'max:4096'],
            'normalization_mode' => ['required', 'in:squeeze,pad'],
        ]);
        // Never record a source the requester cannot see: the derived-from link created at import
        // surfaces that asset's existence and title on both Relationships panels. VisibleAsset above
        // refuses it; superadmins bypass inside canAccessAsset(), so this only constrains scoped roles.

        $normalized = $request->file('normalized');
        $bridge = $request->file('bridge');
        [$width, $height] = $this->imageSize($normalized->getRealPath());
        abort_unless($width === $height * 2, 422, 'The normalized panorama must be exact 2:1.');
        [$bridgeWidth, $bridgeHeight] = $this->imageSize($bridge->getRealPath());
        [$ratioWidth, $ratioHeight] = array_map('intval', explode(':', $data['bridge_ratio']));
        abort_unless($bridgeWidth * $ratioHeight === $bridgeHeight * $ratioWidth, 422, 'The bridge image does not match its supported generation ratio.');
        abort_unless($bridgeHeight === $height && $bridgeWidth <= $width, 422, 'The seam bridge must be a full-height crop of the panorama.');
        abort_unless((int) $data['strip_width'] === intdiv($bridgeWidth, 2), 422, 'The seam bridge must be rolled so the wrap seam sits at its centre.');
        abort_unless((int) $data['repair_width'] < $bridgeWidth, 422, 'The repair band must leave locked context on both sides.');
        $repairFraction = round($data['repair_width'] / $bridgeWidth, 3);

        $diskName = (string) config('sphereseam.storage_disk', 'private');
        $id = (string) Str::uuid();
        // Stored under its real extension: the panorama is served straight back to the browser by
        // normalized(), which derives its Content-Type from this path.
        $normalizedPath = $normalized->storeAs("sphereseam/{$id}", 'normalized.'.$this->imageExtension($normalized->getMimeType()), $diskName);
        $bridgePath = $bridge->storeAs("sphereseam/{$id}", 'bridge.png', $diskName);
        abort_unless($normalizedPath && $bridgePath, 500, 'SphereSeam could not store the prepared images.');

        $estimate = null;
        try {
            $estimateData = $this->higgsfield->estimate(model: $data['model'], prompt: $this->prompt($data['scene_description'] ?? '', $repairFraction), ratio: $data['bridge_ratio'], quality: $data['quality'], resolution: $data['resolution']);
            $estimate = $estimateData['credits'] ?? null;
        } catch (\Throwable) {
        }

        $generation = SphereSeamGeneration::create([
            'id'                 => $id,
            'admin_id'           => $this->adminId(),
            'source_asset_id'    => $data['source_asset_id'] ?? null,
            'model'              => $data['model'],
            'bridge_ratio'       => $data['bridge_ratio'],
            'resolution'         => $data['resolution'],
            'quality'            => $data['quality'],
            'normalized_path'    => $normalizedPath,
            'bridge_path'        => $bridgePath,
            'storage_disk'       => $diskName,
            'width'              => $width,
            'height'             => $height,
            'strip_width'        => $data['strip_width'],
            'repair_width'       => $data['repair_width'],
            'feather_width'      => $data['feather_width'],
            'normalization_mode' => $data['normalization_mode'],
            'prompt'             => $this->prompt($data['scene_description'] ?? '', $repairFraction),
            'estimated_credits'  => is_numeric($estimate) ? (float) $estimate : null,
            'status'             => 'queued',
        ]);
        GenerateSphereSeamBridge::dispatch($generation->id);

        return response()->json(['ok' => true, 'generation' => $this->present($generation)], 202);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizeSphereSeam();
        $items = SphereSeamGeneration::where('admin_id', $this->adminId())->latest()->limit(min(60, max(1, $request->integer('limit', 30))))->get();

        return response()->json(['items' => $items->map(fn ($item) => $this->present($item))->values()]);
    }

    public function status(string $generation): JsonResponse
    {
        $this->authorizeSphereSeam();

        return response()->json(['generation' => $this->present($this->owned($generation))]);
    }

    public function normalized(string $generation)
    {
        $this->authorizeSphereSeam();
        $item = $this->owned($generation);
        abort_unless(Storage::disk($item->storage_disk)->exists($item->normalized_path), 404);
        $extension = strtolower(pathinfo($item->normalized_path, PATHINFO_EXTENSION)) === 'webp' ? 'webp' : 'png';

        return Storage::disk($item->storage_disk)->response($item->normalized_path, 'normalized.'.$extension, ['Content-Type' => 'image/'.$extension, 'Cache-Control' => 'private, no-store']);
    }

    public function retry(string $generation): JsonResponse
    {
        $this->authorizeSphereSeam();
        $item = $this->owned($generation);
        // Failed seams, or queued ones nothing picked up within two minutes (a worker outage or a
        // misrouted job), can be re-queued. Nothing submitted to the provider is ever re-submitted:
        // a row that already holds a provider_request_id is only ever polled again.
        $stuck = $item->status === 'queued' && ! $item->provider_request_id && $item->created_at?->lt(now()->subMinutes(2));
        // A row left `generating` past the provider budget was previously unrecoverable — retry()
        // accepted only `failed` and `queued`, so a worker killed mid-poll stranded the row (and
        // the credits behind it) forever. Re-queuing it re-polls the same submission.
        $stalled = $item->status === 'generating' && $item->started_at?->lt(now()->subMinutes(GenerateSphereSeamBridge::BUDGET_MINUTES));
        // A `failed` row whose submission was ambiguous (GenerateSphereSeamBridge::fail) may
        // already be charged with no provider_request_id ever recorded to poll: retrying it would
        // submit a fresh `generate create` on top of that uncertainty, a real duplicate-charge
        // risk. Permanently refused, not just for this attempt.
        abort_if($item->status === 'failed' && $item->submission_ambiguous, 409, 'This seam could not be confirmed with Higgsfield and may already be charged. Check the shared work account before starting a new one; it cannot be retried automatically.');
        abort_unless($item->status === 'failed' || $stuck || $stalled, 409, 'Only failed or stalled generations can be retried.');
        // started_at is the submission claim. Clearing it lets a never-submitted row be submitted
        // once; a row that already holds an id keeps its claim and can only be polled.
        $item->update([
            'status'        => 'queued',
            'error_message' => null,
            'finished_at'   => null,
            'created_at'    => now(),
            'started_at'    => $item->provider_request_id ? now() : null,
        ]);
        GenerateSphereSeamBridge::dispatch($item->id);

        return response()->json(['ok' => true, 'generation' => $this->present($item->fresh())]);
    }

    public function import(Request $request, string $generation): JsonResponse
    {
        $this->authorizeSphereSeam();
        abort_unless(bouncer()->hasPermission('dam.asset.create'), 403);
        $item = $this->owned($generation);
        abort_unless($item->status === 'completed', 409, 'Only completed panoramas can be saved to DAM.');

        // A posted panorama is ALWAYS written. Until 2026-09-16 a generation that already carried a
        // dam_asset_id returned that asset here and never read the upload, so every re-save after
        // the first — a wider export, a recomposed seam — was silently discarded while the browser
        // navigated to the stale asset as though it had saved. Only a request with no file at all
        // means "just show me the asset you already made".
        $file = $request->validate([
            'panorama' => [$item->dam_asset_id ? 'nullable' : 'required', 'file', 'mimes:png,webp', 'max:'.$this->uploadKilobytes('import')],
        ])['panorama'] ?? null;

        if (! $file) {
            return $this->assetResponse(Asset::findOrFail($item->dam_asset_id));
        }

        [$width, $height] = $this->imageSize($file->getRealPath());
        abort_unless($width === $height * 2, 422, 'Only exact 2:1 SphereSeam exports can be saved to DAM.');
        $bytes = file_get_contents($file->getRealPath());
        $mime = $file->getMimeType() ?: 'image/png';
        $extension = $this->imageExtension($mime);

        $sha256 = hash('sha256', $bytes);

        // sharedly(), not DB::transaction(): ADR 0010's fence wants the DAM_ACCESS_MODEL row FOR SHARE as
        // this transaction's first statement, before the generation and asset rows locked below.
        $asset = app(AccessModel::class)->sharedly(function () use ($item, $bytes, $sha256, $mime, $extension, $width, $height): Asset {
            $diskName = Directory::getAssetDisk();
            // Saves of one generation are serialized on its row. Production 2026-09-16: an 18 MB
            // 4K export kept Save clickable for seconds, a second click raced the first, both
            // requests read dam_asset_id as null, and both inserted the same sphere-seam-<id>
            // path -- the loser 500ed on dam_assets_path_unique. The second request now waits
            // here, sees the asset the first one made, and identical bytes are one save.
            $item = SphereSeamGeneration::query()->lockForUpdate()->findOrFail($item->getKey());
            $existing = $item->dam_asset_id ? Asset::lockedForGraph()->find($item->dam_asset_id) : null;
            if ($existing && $this->storedChecksum($existing) === $sha256) {
                return $existing;
            }
            // The panorama goes into a blob, never assets/SphereSeam/... (ADR 0009 slice 3): sha256 is
            // already known, so a local temp file feeds putLocal directly. The Library name is a
            // stable, deterministic name reused across re-saves -- the UUID-per-save workaround is
            // gone, because sha-dedup already makes identical bytes one save and there is no physical
            // path left to collide on.
            $temp = tempnam(sys_get_temp_dir(), 'sphereseam_');
            file_put_contents($temp, $bytes);
            try {
                $object = app(ContentStore::class)->putLocal(disk: $diskName, localPath: $temp, sha256: $sha256, mimeType: $mime, extension: $extension);
            } finally {
                @unlink($temp);
            }
            // A re-save of an EXISTING asset keeps its current folder: only a brand-new import is
            // filed under the exports directory. Re-deriving the exports folder here unconditionally
            // used to snap a moved-then-re-saved asset's path back under exports while its directory
            // pivot still named the moved destination -- path and membership disagreed.
            if ($existing) {
                $directory = null;
                $folderPath = Str::beforeLast($existing->path, '/');
            } else {
                $directory = Directory::find($this->exportsDirectoryId());
                $folderPath = rtrim(Directory::ASSETS_DIRECTORY.'/'.($directory?->generatePath() ?? ''), '/');
            }
            $fileName = LibraryPaths::uniqueFileName($folderPath, 'sphere-seam-'.substr($item->id, 0, 8).'.'.$extension, $existing?->id);
            $path = $folderPath.'/'.$fileName;
            $asset = $existing ?: new Asset;
            $asset->fill([
                'file_name' => $fileName,
                'file_type' => 'image',
                'file_size' => strlen($bytes),
                'path'      => $path,
                'mime_type' => $mime,
                'extension' => $extension,
                'title'     => $asset->title ?: 'SphereSeam panorama',
                'meta_data' => array_merge(is_array($asset->meta_data) ? $asset->meta_data : [], $this->exportMetaData($item, $width, $height)),
            ]);
            $asset->withGraphContent($object);
            $asset->save();

            if ($existing) {
                return $asset;
            }

            if ($directory) {
                $asset->directories()->syncWithoutDetaching([$directory->id]);
                // The saved panorama takes the exports folder's access list (ADR 0010, spec D3).
                app(AccessListWriter::class)->inheritAssets([$asset->id]);
            }
            foreach (['sphereseam_model' => $item->model, 'sphereseam_bridge_ratio' => $item->bridge_ratio, 'higgsfield_request_id' => $item->provider_request_id] as $name => $value) {
                if ($value) {
                    AssetProperty::create(['dam_asset_id' => $asset->id, 'name' => $name, 'type' => 'text', 'language' => 'English', 'value' => $value, 'is_filterable' => $name !== 'higgsfield_request_id']);
                }
            }
            // Link the export back to the DAM image it was built from, so both assets show the
            // pairing in their Relationships panel (outgoing on the export, incoming on the source).
            $sourceId = (int) ($item->source_asset_id ?? 0);
            if ($sourceId > 0 && $sourceId !== $asset->id && Asset::whereKey($sourceId)->exists()) {
                AssetRelationship::firstOrCreate([
                    'from_asset_id' => $asset->id,
                    'to_asset_id'   => $sourceId,
                    'type'          => AssetRelationship::TYPE_DERIVED_FROM,
                ], [
                    'metadata'   => ['source' => 'sphereseam', 'generation_id' => $item->id],
                    'created_by' => $this->adminId(),
                ]);
            }
            $item->update(['dam_asset_id' => $asset->id]);

            return $asset;
        });

        return $this->assetResponse($asset);
    }

    /**
     * The sha256 of the asset's current original, as the asset graph recorded it (one
     * `original` role file per asset; CompoundAssetObserver enforces that).
     */
    private function storedChecksum(Asset $asset): ?string
    {
        $sha256 = AssetFile::query()
            ->join('dam_file_objects', 'dam_file_objects.id', '=', 'dam_asset_files.file_object_id')
            ->where('dam_asset_files.dam_asset_id', $asset->getKey())
            ->where('dam_asset_files.role', AssetFile::ROLE_ORIGINAL)
            ->orderByDesc('dam_asset_files.id')
            ->value('dam_file_objects.sha256');

        return is_string($sha256) && $sha256 !== '' ? strtolower($sha256) : null;
    }

    private function assetResponse(Asset $asset): JsonResponse
    {
        return response()->json(['ok' => true, 'asset' => ['id' => $asset->id, 'url' => route('admin.dam.assets.edit', $asset->id)]]);
    }

    /**
     * The asset Details pane reads meta_data['exif']['Width'|'Height'], with
     * meta_data['exif']['COMPUTED'] as its fallback (AssetController::show). Writing only the flat
     * width/height left every SphereSeam export showing blank dimensions, so the 1920 px preview
     * proxy was the only size cue the operator had — the "1080p" in the original report. Both
     * shapes are written, plus the generation's own size, so a downscaled export is visible.
     */
    private function exportMetaData(SphereSeamGeneration $item, int $width, int $height): array
    {
        return [
            'width'                     => $width,
            'height'                    => $height,
            'exif'                      => ['Width' => $width, 'Height' => $height, 'COMPUTED' => ['Width' => $width, 'Height' => $height]],
            'sphereseam_generation_id'  => $item->id,
            'sphereseam_source_width'   => (int) $item->width,
            'sphereseam_source_height'  => (int) $item->height,
        ];
    }

    private function uploadKilobytes(string $key): int
    {
        return (int) (config('sphereseam.max_upload_kilobytes.'.$key) ?: 32768);
    }

    private function imageExtension(?string $mime): string
    {
        return $mime === 'image/webp' ? 'webp' : 'png';
    }

    private function validateSettings(Request $request): array
    {
        $data = $request->validate([
            'model' => ['required', 'in:'.implode(',', config('sphereseam.models'))],
            // 1:1 verified best on 2026-09-10 (widest band with real context on both sides); 9:16 kept as the narrow option.
            'bridge_ratio' => ['required', 'in:1:1,3:4,9:16,16:27'],
            // "Auto" is resolved in the browser from the bridge size (resolveGenerationSettings);
            // the server only accepts the concrete values the shared subscription CLI takes.
            'resolution'        => ['required', 'in:1k,2k,4k'],
            'quality'           => ['required', 'in:low,medium,high'],
            'scene_description' => ['nullable', 'string', 'max:2000'],
            'repair_fraction'   => ['nullable', 'numeric', 'min:0.1', 'max:0.7'],
        ]);
        abort_if($data['model'] === 'gpt_image_2' && $data['bridge_ratio'] === '16:27', 422, 'GPT Image 2 supports the 9:16 seam bridge.');

        return $data;
    }

    /**
     * Must stay in step with buildPrompt() in Resources/assets/sphereseam/sphereseam.js.
     * The bridge is a rolled crop across the wrap seam; only the central band should change.
     * GPT Image exposes no mask input on Higgsfield, so this wording is the only "lock" —
     * the browser restores every pixel outside the band from the original after generation.
     */
    private function prompt(string $scene, float $repairFraction = 0.4): string
    {
        $percent = (int) round(max(0.1, min(0.7, $repairFraction)) * 100);

        return implode(' ', [
            "The soft blurred vertical band in the centre of this image, about {$percent}% of its width, is an unfinished placeholder that must be painted in.",
            'The image is a crop taken across the horizontal wrap seam of a 360° equirectangular VR panorama.',
            'Its left part is the far-right edge of the panorama and its right part is the far-left edge, so the band is where the panorama wraps around.',
            trim($scene) !== '' ? 'Scene context: '.trim($scene).'.' : 'Infer the environment only from what is visible.',
            'Continue the visible objects, structures, bodies, and surfaces naturally into the placeholder; preserve their geometry, perspective, and occlusion, so anything partly visible at either edge of the band is completed rather than replaced.',
            'Paint plausible content in the middle so the left and right sides become one continuous scene.',
            'Maintain the surrounding exposure and illumination exactly; add no new light source, window, flame, or lamp. Match haze, object scale, lens distortion, texture grain, and material continuity.',
            'Keep every pixel outside the band identical to the input: same framing, same crop, same aspect ratio, no zoom, no rotation, no mirroring, no restyling.',
            'No visible vertical edges or seams anywhere. Do not add text, logos, UI marks, new people, or new focal objects.',
        ]);
    }

    /**
     * The DAM folder saved exports land in. Resolved by name so a fresh environment gets the same
     * folder without an id in its .env, created once if missing, with the flat legacy directory id
     * as the last resort.
     */
    private function exportsDirectoryId(): int
    {
        $fallback = (int) config('sphereseam.dam_directory_id', 1);

        $configured = (int) config('sphereseam.exports_directory_id', 0);
        if ($configured > 0 && Directory::whereKey($configured)->exists()) {
            return $configured;
        }

        $name = trim((string) config('sphereseam.exports_directory_name', ''));
        if ($name === '') {
            return $fallback;
        }

        $root = Directory::whereNull('parent_id')->orderBy('id')->first();
        if (! $root) {
            return $fallback;
        }

        // Scoped to the root's own children deliberately. An unscoped name lookup would file exports
        // into a same-named folder anywhere in the tree — e.g. a client folder — and the export would
        // silently inherit that folder's share and permission audience.
        $find = fn (bool $locking = false) => Directory::where('name', $name)->where('parent_id', $root->id)->orderBy('id')
            ->when($locking, fn ($query) => $query->sharedLock())->first();

        // Fast path, with no lock: once the folder exists — every import after the very first — this
        // contends with nothing. This runs inside the import transaction, so locking the root on every
        // import would briefly block folder creation tree-wide, and could deadlock against someone
        // deleting this folder mid-import: the delete holds the folder, then needs the root's _rgt,
        // while the import holds the root and its pivot insert waits on the folder.
        if ($existing = $find()) {
            return (int) $existing->id;
        }

        // Only when the folder has to be created: lock the root, then re-check, so two concurrent first
        // imports cannot both insert a sibling. The re-check is a locking read so it sees a folder that a
        // competitor committed while this import waited, under MySQL's REPEATABLE READ as well as under
        // PostgreSQL's READ COMMITTED, where a plain read would already be current.
        //
        // Known narrow race, accepted: if a competing import creates the folder and commits while this
        // one waits for the root, and someone then starts deleting that brand-new folder, this import
        // (holding the root) and the delete (holding the folder, wanting the root's _rgt) can deadlock.
        // PostgreSQL detects the cycle and aborts one of the two, so nothing hangs or corrupts. It takes
        // three overlapping operations on a folder that did not exist a moment earlier.
        Directory::whereKey($root->id)->lockForUpdate()->first();
        if ($existing = $find(true)) {
            return (int) $existing->id;
        }

        // Directory::uniqueName() would be redundant here rather than wrong: under the lock the name is
        // known to be free, so it would return it unchanged. It is not a substitute for the lookup
        // above, where an existing folder must be reused rather than duplicated as "(copy)".
        return (int) Directory::create([
            'name'       => $name,
            'parent_id'  => $root->id,
            'created_by' => $this->adminId() ?: null,
        ])->id;
    }

    private function imageSize(string $path): array
    {
        $size = @getimagesize($path);
        abort_unless(is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0, 422, 'Invalid image data.');

        return [(int) $size[0], (int) $size[1]];
    }

    private function present(SphereSeamGeneration $item): array
    {
        return [
            'id'                => $item->id, 'model' => $item->model, 'bridge_ratio' => $item->bridge_ratio,
            'resolution'        => $item->resolution, 'quality' => $item->quality, 'width' => $item->width, 'height' => $item->height,
            'strip_width'       => $item->strip_width, 'repair_width' => $item->repair_width, 'feather_width' => $item->feather_width, 'normalization_mode' => $item->normalization_mode,
            'status'            => $item->status, 'estimated_credits' => $item->estimated_credits,
            'request_id'        => $item->provider_request_id,
            'bridge_result_url' => $item->status === 'completed' && $item->bridge_result_url ? route('sphereseam.bridge', $item->id) : null,
            'normalized_url'    => route('sphereseam.normalized', $item->id), 'error' => $item->error_message,
            'dam_asset_id'      => $item->dam_asset_id, 'created_at' => $item->created_at?->toIso8601String(),
            // The Retry button must not offer a row whose submission could not be confirmed either
            // way: see GenerateSphereSeamBridge::fail() and the matching gate in retry().
            'submission_ambiguous' => (bool) $item->submission_ambiguous,
        ];
    }

    private function owned(string $id): SphereSeamGeneration
    {
        return SphereSeamGeneration::whereKey($id)->where('admin_id', $this->adminId())->firstOrFail();
    }

    /** An image asset the requester may open; a hidden one answers like a missing one (404). */
    private function visibleImage(mixed $id): Asset
    {
        $asset = Asset::whereKey($id)->where('file_type', 'image')->firstOrFail();
        abort_unless($this->damCanAccessAsset($asset->id), 404);

        return $asset;
    }

    private function authorizeSphereSeam(): void
    {
        abort_unless(config('sphereseam.enabled'), 404);
        abort_unless(auth()->guard('admin')->user(), 401);
        abort_unless(bouncer()->hasPermission('sphereseam'), 403);
        // Hidden is not revoked: a role that holds the key but whose Home tool list lacks SphereSeam (the paid tools are
        // opt-in) must not reach the page, generate or retry by calling the routes directly. Full admins are never gated.
        abort_unless(app(HomeWidgetRegistry::class)->permits('sphereseam'), 403);
    }

    private function adminId(): int
    {
        return (int) auth()->guard('admin')->id();
    }
}

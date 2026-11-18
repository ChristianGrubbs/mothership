<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Http\Controllers\Concerns\ServesAcceleratedFiles;
use Mothership\DAM\Http\Controllers\Concerns\StreamsZipDownload;
use Mothership\DAM\Jobs\GenerateGameAssetThumbnail;
use Mothership\DAM\Jobs\GeneratePdfThumbnail;
use Mothership\DAM\Jobs\GenerateVideoThumbnail;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\DngPreviewService;
use Mothership\DAM\Services\HeifPreviewService;
use Mothership\DAM\Services\LargeRasterPreviewService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\PdfPoster;
use Mothership\DAM\Support\PreviewKinds;
use Mothership\DAM\Support\StackFileName;
use Mothership\DAM\Support\StackFileThumbnail;
use Mothership\DAM\Support\ZipEntryNames;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Serves originals, attachments and image derivatives (ADR 0009). A `?path=` or `{path}` is the asset's
 * logical Library path: it decides authorization and names the asset, and the bytes always come from the
 * asset's current original through ContentStore. Derivatives are keyed by that original's sha256.
 */
class FileController
{
    use ServesAcceleratedFiles;
    use StreamsZipDownload;

    private const ASSET_CACHE_SECONDS = 86400;

    /**
     * A derivative served under a URL whose `v` names its content never changes, so the browser keeps it a
     * year without asking again. `private` stays: the bytes are ACL-gated and must not sit in a shared cache.
     */
    private const VERSIONED_CACHE_SECONDS = 31536000;

    /** How much of a Unity text asset the preview modal shows (256 KB). */
    private const TEXT_PREVIEW_BYTES = 262144;

    /** Copies made from another file: current or history depending on the content they were made from. */
    private const DERIVATIVE_ROLES = [AssetFile::ROLE_PROXY, AssetFile::ROLE_THUMBNAIL];

    /**
     * Authorize a read addressed by path and return the asset its Library path names, or null when the
     * path is a blob locator an older URL carried (then every asset attaching that blob must be allowed).
     * The path is only a logical key: the bytes come from readableObject(), never from this path on disk.
     */
    protected function assertPathAllowed(string $path): ?Asset
    {
        $assets = Asset::query()
            ->where(function ($query) use ($path): void {
                $query->where('path', $path)
                    ->orWhereHas('files.fileObject', function ($query) use ($path): void {
                        $query->where('path', $path);
                    });
            })
            ->get();

        abort_if(
            $assets->isEmpty(),
            404,
            trans('dam::app.admin.dam.file.not-found')
        );

        foreach ($assets as $asset) {
            $this->assertAssetAllowed($asset);
        }

        $asset = $assets->firstWhere('path', $path);

        if ($asset === null) {
            $this->assertLocatorNotHistoryOnly($assets, $path);
        }

        return $asset;
    }

    /**
     * The bytes an authorized path read serves: the Library asset's current original, else the blob an older
     * URL named, checked the way every read is checked (own locator, present, recorded size).
     *
     * @throws ContentUnavailable no_original or blob_missing, already recorded to content-store.jsonl
     */
    private function availableObject(?Asset $asset, string $path): FileObject
    {
        $object = $asset !== null
            ? $this->contentStore()->current($asset)
            : FileObject::query()->where('path', $path)->firstOrFail();

        return $this->contentStore()->ensureAvailable($object);
    }

    /**
     * availableObject() for a route that degrades to a placeholder (grid thumbnails): null when the bytes
     * are unavailable, after ContentStore has recorded why, so a missing blob never passes silently.
     */
    private function readableObject(?Asset $asset, string $path): ?FileObject
    {
        try {
            return $this->availableObject($asset, $path);
        } catch (ContentUnavailable) {
            return null;
        }
    }

    private function contentStore(): ContentStore
    {
        return app(ContentStore::class);
    }

    /**
     * The Content-Type of an original: the FileObject's (sniffed at ingest), else the asset's, else the
     * extension's. Never the blob's name.
     */
    private function contentMime(?Asset $asset, FileObject $object): string
    {
        foreach ([$object->mime_type, $asset?->mime_type] as $candidate) {
            $candidate = strtolower(trim(explode(';', (string) $candidate)[0]));

            if ($candidate !== '' && $candidate !== 'application/octet-stream') {
                return $candidate;
            }
        }

        $extension = $this->sourceExtension($asset, $object);

        return ($extension !== '' ? (MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null) : null)
            ?? 'application/octet-stream';
    }

    /** The source's type for tools and the derivative kept-format rule: the asset's extension, then the MIME. */
    private function sourceExtension(?Asset $asset, FileObject $object): string
    {
        $extension = strtolower(trim((string) $asset?->extension));

        if ($extension !== '') {
            return $extension;
        }

        $mime = strtolower(trim(explode(';', (string) ($object->mime_type ?: $asset?->mime_type))[0]));

        return $mime !== '' && $mime !== 'application/octet-stream'
            ? (MimeTypes::getDefault()->getExtensions($mime)[0] ?? '')
            : '';
    }

    /** 'raster' for a GD/vips-decodable image, 'svg', or null for anything else. */
    private function imageKind(?Asset $asset, FileObject $object): ?string
    {
        $mime = $this->contentMime($asset, $object);

        if ($this->sourceExtension($asset, $object) === 'jfif') {
            $mime = 'image/jpeg';
        }

        if ($mime === 'image/svg+xml') {
            return 'svg';
        }

        return Str::startsWith($mime, 'image/') ? 'raster' : null;
    }

    private function downloadName(?Asset $asset, FileObject $object): string
    {
        return (string) ($asset?->file_name ?: $object->sha256);
    }

    /**
     * Stream an original through the read seam with the private asset cache. A remote (S3) delivery is a
     * short-lived redirect, which must not be cached for a day.
     */
    private function deliverOriginal(?Asset $asset, FileObject $object, ?string $mimeType = null)
    {
        $response = $this->contentStore()->deliver(
            object: $object,
            filename: $this->downloadName($asset, $object),
            disposition: HeaderUtils::DISPOSITION_INLINE,
            mimeType: $mimeType ?? $this->contentMime($asset, $object),
        );

        return $response instanceof RedirectResponse ? $response : $this->applyAssetCache($response);
    }

    protected function assertAssetAllowed(Asset $asset): void
    {
        // A file the viewer cannot open answers like one that does not exist, so a path or id probe learns
        // nothing. The seam (P2 S2) decides: the file's own list under `lists` (ADR 0010), any folder holding it
        // under `grants`. P2 S1: `grants` used to ask only the first folder and answer 403 for a file a second
        // folder showed.
        $visible = app(AssetAccessDecision::class)->visible(AccessContext::library(AccessContext::PURPOSE_DOWNLOAD), $asset->id);

        abort_unless($visible, 404, trans('dam::app.admin.dam.file.not-found'));
    }

    public function fetchFile(string $path)
    {
        if (! Auth::check()) {
            return abort(403, trans('dam::app.admin.permissions.unauthorized'));
        }

        abort_unless(
            bouncer()->hasPermission('dam.asset.view'),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );

        $asset = $this->assertPathAllowed($path);
        $object = $asset !== null
            ? $this->contentStore()->current($asset)
            : FileObject::query()->where('path', $path)->first();

        if ($object === null) {
            return response()->json(['error' => trans('dam::app.admin.dam.file.not-found')], 404);
        }

        // deliver() never reads the whole file into memory: a 100 MB+ video would exhaust the worker
        // and could not answer byte-range requests. It hands the blob to nginx, or streams it ranged.
        return $this->deliverOriginal($asset, $object);
    }

    /**
     * Serve an attached proxy/sidecar only after checking its owning asset.
     */
    public function fetchAssetFile(int $asset, int $assetFile)
    {
        if (! Auth::check()) {
            return abort(403, trans('dam::app.admin.permissions.unauthorized'));
        }

        abort_unless(
            bouncer()->hasPermission('dam.asset.view'),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );

        $assetModel = Asset::findOrFail($asset);
        $this->assertAssetAllowed($assetModel);

        $attachment = $assetModel->files()
            ->with(['fileObject', 'attachment'])
            ->whereKey($assetFile)
            ->firstOrFail();

        abort_unless($attachment instanceof AssetFile && $attachment->fileObject, 404);
        $this->assertHistoryReadable($assetModel, $attachment);

        // A stack's picker and file strip draw each file small: `?thumb=1` answers with a cached derivative,
        // never the original. A type with no derivative answers 404 and the tile keeps its type badge.
        if (request()->boolean('thumb') && ! request()->boolean('download')) {
            $thumbnail = $this->stackFileThumbnail($attachment, $assetModel);

            abort_if($thumbnail === null, 404);

            // stackFileThumbnail() has applied the cache policy (immutable only under a matching `v`).
            return $thumbnail;
        }

        // The preview shows a stacked still or DNG through a screen-sized copy (PROD-03, PROD-13), and only that:
        // a type with none, or a copy that could not be made in time, answers 404 and the viewer says
        // "Preview not available". It never falls through to the original's bytes.
        if (request()->boolean('preview') && ! request()->boolean('download')) {
            $preview = $this->stackFilePreview($attachment, $assetModel);

            abort_if($preview === null, 404, trans('dam::app.admin.dam.file.not-found'));

            return $preview instanceof RedirectResponse ? $preview : $this->applyAssetCache($preview);
        }

        // An inline fetch is a preview the page shows; only ?download=1 hands a file over (P1b).
        if (request()->boolean('download') && DamEvents::isNewDownload(request: request())) {
            DamEvents::emit(event: DamEvent::DownloadServed, subjectId: (int) $assetModel->id, subjectType: 'asset', props: ['via' => $attachment->attachment?->is_representative ? 'direct' : 'alternate'], attachmentId: $attachment->attachment_id === null ? null : (int) $attachment->attachment_id);
        }

        $response = $this->contentStore()->deliver(
            object: $attachment->fileObject,
            filename: StackFileName::fileName($attachment, $assetModel),
            disposition: request()->boolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            mimeType: $attachment->fileObject->mime_type ?: null,
        );

        return $response instanceof RedirectResponse ? $response : $this->applyAssetCache($response);
    }

    /**
     * History reads need `dam.asset.files.index` (stacking spec S5, "Rights"). Current files of an entry, the
     * cover and every other slot, keep the per-file download `dam.asset.view` gives (spec S4); only a superseded
     * revision, or a proxy or thumbnail made from one, is history.
     */
    private function assertHistoryReadable(Asset $asset, AssetFile $file): void
    {
        if (bouncer()->hasPermission('dam.asset.files.index')) {
            return;
        }

        abort_if($this->isHistory($asset, $file), 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    /**
     * A blob locator an older URL carried (no Library path): without `dam.asset.files.index` it is readable while
     * at least one row that holds it is current content, and refused when it survives only as history.
     *
     * @param  Collection<int, Asset>  $assets
     */
    private function assertLocatorNotHistoryOnly(Collection $assets, string $path): void
    {
        if (bouncer()->hasPermission('dam.asset.files.index')) {
            return;
        }

        $rows = AssetFile::query()
            ->whereIn('dam_asset_id', $assets->modelKeys())
            ->whereHas('fileObject', fn ($query) => $query->where('path', $path))
            ->get();

        $current = $rows->contains(fn (AssetFile $file): bool => ! $this->isHistory($assets->find($file->dam_asset_id), $file));

        abort_unless($rows->isEmpty() || $current, 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    /**
     * Whether a file row is history: a superseded revision, or a proxy or thumbnail whose renditions were all made
     * from content the entry no longer holds as a current file. A derivative no rendition explains (an upload, an
     * older import) is not provably history and stays readable as before.
     */
    private function isHistory(Asset $asset, AssetFile $file): bool
    {
        if (! $file->is_current) {
            return true;
        }

        if (! in_array($file->role, self::DERIVATIVE_ROLES, true)) {
            return false;
        }

        $sources = $asset->renditions()->where('output_file_object_id', $file->file_object_id)->pluck('source_asset_file_id');

        if ($sources->isEmpty() || $sources->contains(null)) {
            return false;
        }

        $currentContent = AssetFile::query()
            ->where('dam_asset_id', $asset->id)
            ->where('is_current', true)
            ->whereNotIn('role', [...self::DERIVATIVE_ROLES, AssetFile::ROLE_VERSION])
            ->pluck('file_object_id');

        return ! AssetFile::query()->whereIn('id', $sources)->whereIn('file_object_id', $currentContent)->exists();
    }

    /**
     * The small picture of one stacked file, found the way the Library card finds it: a stored picture (custom
     * thumbnail, cover art, poster, 3D capture) is served as it is, a still is resized once into its content-keyed
     * derivative, a DNG gets its preview. Nothing is rendered here that the card does not render.
     */
    private function stackFileThumbnail(AssetFile $attachment, Asset $asset)
    {
        $object = $attachment->fileObject;

        if (! $object instanceof FileObject) {
            return null;
        }

        try {
            $source = StackFileThumbnail::source($attachment, $asset);

            if ($source === null) {
                return null;
            }

            // A picture made from the original is only vouched for while the original is readable (a stat, never a
            // hash), like the card thumbnail: an unavailable one answers 404 with its content_unavailable record,
            // before any cached picture or conditional header is looked at. A custom thumbnail is independent art.
            if (! str_starts_with((string) $source['path'], 'custom-thumbnails/')) {
                $this->contentStore()->ensureAvailable($object);
            }

            // Immutable only when the `v` the URL carries names exactly the bytes about to be sent.
            $version = $this->requestedVersion(StackFileThumbnail::version(source: $source, sha: $object->sha256));

            // No version-only 304 here: a client that holds the bytes is answered 304 by the serving helpers below
            // (applyAssetCache, from the version), but only once the picture is found on disk right now. A stored
            // picture that is gone throws and is forgotten; a cold derivative whose original is unavailable takes the
            // generation/failure path (and its content_unavailable record) exactly like an unconditional request.
            if ($source['path'] !== null) {
                try {
                    return $this->getFileResponse(path: $source['path'], disk: $source['disk'], mimeType: $source['mime'], version: $version);
                } catch (\Throwable $e) {
                    // The picture a payload was told about is gone: stop vouching for it.
                    StackFileThumbnail::forget(disk: $source['disk'], path: $source['path']);

                    throw $e;
                }
            }

            if ($source['kind'] === StackFileThumbnail::KIND_DNG) {
                $path = app(DngPreviewService::class)->ensureForObject($object);

                return $path === null ? null : $this->getFileResponse($path, mimeType: 'image/jpeg', version: $version);
            }

            if ($source['kind'] === StackFileThumbnail::KIND_PDF) {
                $path = PdfPoster::ensure($object);

                return $path === null ? null : $this->getFileResponse($path, mimeType: 'image/jpeg', version: $version);
            }

            $extension = $this->sourceExtension(null, $object);

            return $this->imageDerivative(
                object: $object,
                destination: DerivativeImages::thumbnail($object->sha256, $extension),
                sourceExtension: $extension,
                maxSize: 300,
                version: $version,
            );
        } catch (ContentUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('DAM stacked-file thumbnail failed: '.$e->getMessage(), ['asset_file' => $attachment->id, 'file_object' => $object->id]);
        }

        return null;
    }

    /** The screen-sized copy of one stacked still (1920 px) or DNG the preview shows, or null when it has none. */
    private function stackFilePreview(AssetFile $attachment, Asset $asset)
    {
        if (! StackFileThumbnail::previewable($attachment, $asset)) {
            return null;
        }

        $object = $attachment->fileObject;

        try {
            if (StackFileThumbnail::kindOf($attachment, $asset) === StackFileThumbnail::KIND_DNG) {
                $path = app(DngPreviewService::class)->ensureForObject($object, thumbnail: false);

                return $path === null ? null : $this->getFileResponse($path, mimeType: 'image/jpeg');
            }

            $extension = $this->sourceExtension(null, $object);

            return $this->imageDerivative(
                object: $object,
                destination: DerivativeImages::preview($object->sha256, 1920, $extension),
                sourceExtension: $extension,
                maxSize: 1920,
            );
        } catch (\Throwable $e) {
            Log::warning('DAM stacked-file preview failed: '.$e->getMessage(), ['asset_file' => $attachment->id, 'file_object' => $object->id]);
        }

        return null;
    }

    /**
     * The entry's files as one zip (P3a S4): the selected `files[]` ids, or all of them when none are named.
     * Current revisions only, under the same rights as a single file's download plus the asset zip download's
     * own (`dam.asset.download_compressed`), one unique name per entry.
     */
    public function fetchAssetFilesZip(Request $request, int $asset)
    {
        abort_unless(
            Auth::check() && bouncer()->hasPermission('dam.asset.view') && bouncer()->hasPermission('dam.asset.download_compressed'),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );

        $assetModel = Asset::findOrFail($asset);
        $this->assertAssetAllowed($assetModel);

        $readsHistory = bouncer()->hasPermission('dam.asset.files.index');
        $selected = collect((array) $request->query('files', []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values();
        $files = $assetModel->files()
            ->with(['fileObject', 'attachment'])
            ->where('is_current', true)
            ->when($selected->isNotEmpty(), fn ($query) => $query->whereKey($selected->all()))
            ->get()
            ->filter(fn (AssetFile $file) => $file->fileObject !== null)
            // Zips are built from current revisions only (spec S4). A proxy or thumbnail made from a superseded
            // revision is still flagged current, so without the history key it is left out like a missing file.
            ->filter(fn (AssetFile $file) => $readsHistory || ! $this->isHistory($assetModel, $file))
            ->sortBy(fn (AssetFile $file) => [$file->attachment?->position ?? $file->sort, $file->id])
            ->values();

        // A named id that is not one of this entry's current files answers like a missing file.
        abort_if($files->isEmpty() || ($selected->isNotEmpty() && $files->count() !== $selected->count()), 404);

        // Every file is checked before the response starts (a stat, no stream): a file whose bytes are missing answers
        // 404 like its single download (ContentUnavailable renders it), never a smaller zip and never a half-sent one.
        // Streams open one at a time while the archive is written, so a stack of any size holds one descriptor.
        $store = $this->contentStore();
        $names = [];
        $entries = [];

        foreach ($files as $file) {
            $entries[] = [
                'name'   => $this->uniqueZipName(StackFileName::fileName($file, $assetModel), $names),
                'object' => $store->ensureAvailable($file->fileObject),
            ];
        }

        DamEvents::emit(event: DamEvent::DownloadServed, subjectId: (int) $assetModel->id, subjectType: 'asset', props: ['via' => 'zip', 'files' => $files->count()]);

        return $this->streamZip(
            zipName: pathinfo((string) $assetModel->file_name, PATHINFO_FILENAME).'-files.zip',
            compression: CompressionMethod::STORE,
            fill: function (ZipStream $zip) use ($entries, $store): void {
                foreach ($entries as $entry) {
                    $stream = $store->open($entry['object']);

                    try {
                        $zip->addFileFromStream(fileName: $entry['name'], stream: $stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                }
            },
        );
    }

    /** A zip entry name with no path separators, made unique within this archive (`name (2).ext`). */
    private function uniqueZipName(string $name, array &$taken): string
    {
        return ZipEntryNames::unique(str_replace(['/', '\\', "\0"], '-', $name), $taken);
    }

    public function thumbnail()
    {
        $disk = Directory::getAssetDisk();
        if (! Auth::check()) {
            return abort(403, trans('dam::app.admin.permissions.unauthorized'));
        }

        $path = urldecode(request()->path);
        $asset = $this->assertPathAllowed($path);

        // Checked once, before every branch, so a missing or truncated original always writes its
        // content_unavailable record (ADR 0009 slice 2).
        $object = $this->readableObject($asset, $path);

        // A custom thumbnail is art a user uploaded on its own, independent of the original's bytes, so
        // it is still shown when only the original is gone; the record above is written regardless.
        $stackCover = $asset?->meta_data['stack_cover'] ?? null;
        if ($asset && $object && is_array($stackCover) && ($stackCover['sha256'] ?? null) === $object->sha256
            && $asset->files()->whereKey($stackCover['file_id'] ?? 0)->where('is_current', true)->where('role', AssetFile::ROLE_ORIGINAL)->exists()
            && is_string($stackCover['path'] ?? null) && Storage::disk($disk)->exists($stackCover['path'])) {
            return $this->getFileResponse(path: $stackCover['path'], disk: $disk, mimeType: $stackCover['mime'] ?? null);
        }

        $custom = $asset ? CustomThumbnailService::location($asset) : null;
        if ($custom && Storage::disk($disk)->exists($custom['path'])) {
            return $this->customThumbnailResponse(custom: $custom, disk: $disk);
        }

        // Everything below is derived from the original (DNG previews, captured model views, cover art,
        // posters, image thumbnails), so an unavailable original gets the card's type placeholder, never a
        // cached derivative that would make the asset look healthy.
        if ($object === null) {
            return $this->getDefaultThumbnailImage($path);
        }

        if ($asset && strtolower((string) $asset->extension) === 'dng') {
            $preview = app(DngPreviewService::class)->ensure($asset, thumbnail: true, budgetSeconds: 10);

            return ($preview ? $this->getFileResponse($preview, mimeType: 'image/jpeg') : $this->getDefaultThumbnailImage($path))->setCache(['private' => true, 'max_age' => 0, 'must_revalidate' => true]);
        }

        // A GLB or 3MF thumbnail is captured in a browser (ADR 0006), so unlike
        // video and PDF there is no job to fall back to: either the stored
        // capture is on disk or the card keeps its placeholder.
        if ($asset && ModelThumbnailService::supports($asset)) {
            $captured = $asset->meta_data['thumbnail_path'] ?? null;

            if (is_string($captured) && $captured !== '' && Storage::disk($disk)->exists($captured)) {
                // A forced re-capture overwrites this same file before it records the new revision
                // (ModelThumbnailService::store()), so a revision is not proof of the bytes beside it: this
                // branch stays revalidating (ETag) rather than immutable.
                return $this->getFileResponse(path: $captured, disk: $disk, mimeType: 'image/jpeg');
            }
        }

        if ($asset && $asset->file_type === 'audio') {
            if ($object !== null && $this->isBrowserNavigation()) {
                return redirect()->away($this->assetOpenUrl($path));
            }

            $coverPath = $asset->meta_data['cover_art_path'] ?? null;
            if ($coverPath && Storage::disk($disk)->exists($coverPath)) {
                return $this->getFileResponse($coverPath);
            }
        }

        if ($asset && ($asset->file_type === 'video' || $asset->asset_kind === 'pano360' || AdobeFormats::isPdfRenderable($asset->extension, $asset->mime_type))) {
            // A poster recorded by its job wins; otherwise the one keyed by this asset's own bytes, so a
            // new asset at a reused path never gets the previous asset's poster.
            $cached = $asset->meta_data['thumbnail_path'] ?? ($object ? DerivativeImages::poster($object->sha256) : null);

            if ($cached !== null && Storage::disk($disk)->exists($cached)) {
                return $this->getFileResponse($cached, version: $this->requestedVersion($this->derivativeSha($cached)));
            }

            try {
                $job = AdobeFormats::isPdfRenderable($asset->extension, $asset->mime_type)
                    ? new GeneratePdfThumbnail($asset->id)
                    : new GenerateVideoThumbnail($asset->id);

                dispatch_sync($job);

                $cached = $asset->fresh()->meta_data['thumbnail_path'] ?? $cached;

                if ($cached !== null && Storage::disk($disk)->exists($cached)) {
                    return $this->getFileResponse($cached);
                }
            } catch (\Throwable $e) {
                Log::warning('DAM thumbnail lazy-generation failed: '.$e->getMessage(), ['asset' => $asset->id]);
            }
        }

        // A Blender file or Unity package shows the thumbnail it embeds (no renderer, ADR 0006). The job has
        // already read these bytes when game_asset names their sha, so a missing poster then means the file
        // has none, and it is not read again on every card render (unless those facts say it had one and the
        // poster has since been deleted: then it is made again). The job runs whenever this asset's facts
        // name other bytes, even if a poster exists: another asset with the same bytes may have written it,
        // and this asset's facts would otherwise never be recorded.
        if ($asset && PreviewKinds::hasEmbeddedThumbnail($asset->extension)) {
            $poster = DerivativeImages::poster($object->sha256);
            $storage = Storage::disk($disk);

            $facts = $asset->meta_data['game_asset'] ?? null;

            // Also when the facts say the file has a thumbnail but the poster is gone from storage: it is made again.
            if (($facts['sha256'] ?? null) !== $object->sha256 || (($facts['has_thumbnail'] ?? false) === true && ! $storage->exists($poster))) {
                try {
                    dispatch_sync(new GenerateGameAssetThumbnail($asset->id));
                } catch (\Throwable $e) {
                    Log::warning('DAM thumbnail lazy-generation failed: '.$e->getMessage(), ['asset' => $asset->id]);
                }
            }

            if ($storage->exists($poster)) {
                return $this->getFileResponse(path: $poster, disk: $disk, mimeType: 'image/jpeg', version: $this->requestedVersion($this->derivativeSha($poster)));
            }
        }

        $kind = $object ? $this->imageKind($asset, $object) : null;

        if ($kind === 'raster') {
            $extension = $this->sourceExtension($asset, $object);

            // The derivative is keyed by the original's sha, which is also the URL's `v`: a client that already holds
            // those bytes is answered 304 here, before any staging or decoding (authorization above has already passed).
            $version = $this->requestedVersion($object->sha256);

            if ($version !== null && ($notModified = $this->notModifiedByVersion($version)) !== null) {
                return $notModified;
            }

            try {
                $destination = DerivativeImages::thumbnail($object->sha256, $extension);
                $existed = Storage::disk($disk)->exists($destination);
                $response = $this->imageDerivative(
                    object: $object,
                    destination: $destination,
                    sourceExtension: $extension,
                    maxSize: 300,
                    version: $version,
                );

                // The grid thumbnail of an image format the AI cannot open itself (HEIC, TIFF ...) is the picture it
                // describes: when it is first made, an index run that found no image starts over once.
                if ($asset && ! $existed && ! AiEligibility::sampledDirectly($asset)) {
                    AiIndexDispatcher::derivativeReady((int) $asset->id);
                }

                return $response;
            } catch (\Throwable $e) {
                Log::warning('DAM thumbnail generation failed: '.$e->getMessage(), ['asset' => $asset?->id, 'file_object' => $object->id]);
            }
        } elseif ($kind === 'svg') {
            // An SVG is its own thumbnail, served under the asset CSP; no copy is cached.
            return $this->deliverOriginal($asset, $object, 'image/svg+xml');
        }

        if ($object !== null && $this->isBrowserNavigation()) {
            return redirect()->away($this->assetOpenUrl($path));
        }

        return $this->getDefaultThumbnailImage($path);
    }

    /**
     * A cached thumbnail or preview of an image blob, generated on first request: libvips for an oversized
     * raster, GD otherwise. Keyed by the blob's sha, so it survives moves and is shared by copies.
     */
    private function imageDerivative(FileObject $object, string $destination, string $sourceExtension, int $maxSize, ?string $version = null)
    {
        $disk = Directory::getAssetDisk();
        $mimeType = DerivativeImages::contentType($sourceExtension);

        if (Storage::disk($disk)->exists($destination)) {
            return $this->getFileResponse($destination, $disk, $mimeType, version: $version);
        }

        // One staging (a symlink on a local disk, one download otherwise) feeds libvips or GD.
        $local = $this->contentStore()->localPath($object, $sourceExtension);

        try {
            // HEIC/HEIF (recognised by its bytes) is decoded by libvips only: GD cannot read it.
            if (app(HeifPreviewService::class)->generateIfHeif(
                disk: $disk,
                localSource: $local->path,
                destinationPath: $destination,
                maxSize: $maxSize,
            )) {
                return $this->getFileResponse($destination, $disk, $mimeType, version: $version);
            }

            if (app(LargeRasterPreviewService::class)->generateIfNeeded(
                disk: $disk,
                sourcePath: (string) $object->path,
                destinationPath: $destination,
                maxSize: $maxSize,
                localSource: $local->path,
            )) {
                return $this->getFileResponse($destination, $disk, $mimeType, version: $version);
            }

            $bytes = file_get_contents($local->path);
        } finally {
            $local->release();
        }

        $imageData = DerivativeImages::encodeFor($this->resizeImage($bytes, $maxSize), $sourceExtension);

        Storage::disk($disk)->put($destination, $imageData);

        return $this->applyAssetCache(response($imageData, 200)->header('Content-Type', $mimeType), $version);
    }

    /**
     * A custom thumbnail on the grid route. The `v` names the representation (CustomThumbnailService::servedVersion):
     * - `<revision>.w300` (JPEG/PNG card URLs): a 300 px WebP made on first request, or, when the source is already within
     *   300 px, its raw bytes (permanent, so immutable under that same version). A transient failure (decode, storage,
     *   encode) serves the raw bytes with revalidating semantics and no version, so those bytes never cache as the
     *   revision's WebP and their validator differs from it.
     * - the bare `<revision>`: the stored RAW bytes, immutable (the editor's URL, and every URL cached before the
     *   derivative existed). A GIF is always this.
     * - an absent or wrong `v`: the raw bytes with the one-day revalidating cache, as before the derivative existed.
     * The raw and WebP validators never coincide: each is derived from its own version string.
     */
    private function customThumbnailResponse(array $custom, string $disk): mixed
    {
        $destination = CustomThumbnailService::derivativePath($custom);
        $cardVersion = CustomThumbnailService::servedVersion($custom);

        if ($destination === null || request()->query('v') !== $cardVersion) {
            return $this->getFileResponse(
                path: $custom['path'],
                disk: $disk,
                mimeType: $custom['mime_type'],
                version: $this->requestedVersion($custom['revision'] ?? null),
            );
        }

        $version = $this->requestedVersion($cardVersion);

        $mimeType = DerivativeImages::contentType('webp');
        $storage = Storage::disk($disk);
        $temp = null;

        try {
            // A zero-byte file is an interrupted write, never a derivative.
            if ($storage->exists($destination) && $storage->size($destination) > 0) {
                return $this->getFileResponse(path: $destination, disk: $disk, mimeType: $mimeType, version: $version);
            }

            $bytes = $storage->get($custom['path']);
            $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if ($size === false) {
                throw new \RuntimeException('custom thumbnail cannot be decoded');
            }

            if (max($size[0], $size[1]) <= 300) {
                return $this->getFileResponse(path: $custom['path'], disk: $disk, mimeType: $custom['mime_type'], version: $version);
            }

            $manager = new ImageManager(new Driver);
            $imageData = DerivativeImages::encodeFor($manager->decode($bytes)->scaleDown(width: 300, height: 300), 'webp');
            if ($imageData === '') {
                throw new \RuntimeException('custom thumbnail encode was empty');
            }

            // Publish whole or not at all: write beside the final name, then rename, so a reader never sees a partial file.
            // A publish racing a replacement can leave one orphaned derivative; asset deletion removes only the paths it
            // knows and prunes the folder only when empty, so no automatic sweep reclaims it.
            $temp = dirname($destination).'/'.pathinfo($destination, PATHINFO_FILENAME).'.'.uniqid('', true).'.tmp';
            if ($storage->put($temp, $imageData) !== true) {
                throw new \RuntimeException('custom thumbnail derivative could not be written');
            }

            if ($storage->exists($destination) && $storage->size($destination) > 0) {
                // Another request published first.
                $storage->delete($temp);

                return $this->getFileResponse(path: $destination, disk: $disk, mimeType: $mimeType, version: $version);
            }

            $storage->delete($destination);
            if ($storage->move($temp, $destination) !== true) {
                throw new \RuntimeException('custom thumbnail derivative could not be published');
            }

            return $this->applyAssetCache(response($imageData, 200)->header('Content-Type', $mimeType), $version);
        } catch (\Throwable $e) {
            Log::warning('DAM custom thumbnail derivative failed: '.$e->getMessage(), ['path' => $custom['path']]);

            try {
                if ($temp !== null) {
                    $storage->delete($temp);
                }
            } catch (\Throwable) {
                // The raw fallback below still serves.
            }

            // This URL normally carries the WebP, so the stand-in validates by its own bytes only: no Last-Modified,
            // which an If-Modified-Since-only request could match across the two representations.
            $raw = $storage->get($custom['path']);
            $response = response($raw, 200)->header('Content-Type', $custom['mime_type']);
            $response->setEtag(hash('sha256', (string) $raw));
            $response->headers->set('Cache-Control', 'private, no-cache');
            $response->isNotModified(request());

            return $response;
        }
    }

    private function isBrowserNavigation(): bool
    {
        $accept = (string) request()->header('Accept', '');

        return str_contains($accept, 'text/html');
    }

    /** Opening an asset in the browser goes through the fetch route, which delivers through the read seam. */
    private function assetOpenUrl(string $path): string
    {
        return route('admin.dam.file.fetch', ['path' => $path]);
    }

    /**
     * @param  string|null  $version  the content version the request's `v` proved (requestedVersion()): the response is
     *                                then immutable and its ETag comes from the version, so neither a 304 nor a 200 hashes the file
     */
    private function applyAssetCache($response, ?string $version = null)
    {
        if ($version !== null) {
            $response->setEtag($this->versionEtag($version));

            if ($response instanceof BinaryFileResponse) {
                $response->setAutoLastModified();
            }

            $response->setPrivate();
            $response->setMaxAge(self::VERSIONED_CACHE_SECONDS);
            $response->setImmutable();
            $response->isNotModified(request());

            return $response;
        }

        if ($response instanceof BinaryFileResponse) {
            $response->setAutoEtag();
            $response->setAutoLastModified();
        }

        $response->setPrivate();
        $response->setMaxAge(self::ASSET_CACHE_SECONDS);

        $response->isNotModified(request());

        return $response;
    }

    /**
     * The `v` of this request when it is exactly the version of the bytes about to be served, else null.
     * A URL is only called immutable if its `v` names what this response carries: a stale or invented `v`
     * (or a derivative with no recorded version) keeps the one-day revalidating cache.
     */
    private function requestedVersion(mixed $expected): ?string
    {
        $given = request()->query('v');

        if (! is_string($given) || ! is_string($expected) || $expected === '' || ! hash_equals($expected, $given)) {
            return null;
        }

        return $expected;
    }

    /** The blob sha a derivative path names (`derivatives/<xx>/<sha>/<recipe>.<ext>`), or null for any other layout. */
    private function derivativeSha(string $path): ?string
    {
        return preg_match('#^'.DerivativeImages::ROOT.'/[a-f0-9]{2}/([a-f0-9]{64})/[^/]+$#D', $path, $match) === 1 ? $match[1] : null;
    }

    private function versionEtag(string $version): string
    {
        return '"'.substr(hash('sha256', 'dam-derivative|'.$version), 0, 32).'"';
    }

    /** A 304 when the client already holds this version (If-None-Match), else null. */
    private function notModifiedByVersion(string $version): ?Response
    {
        $probe = $this->applyAssetCache(response('', 200), $version);

        return $probe->getStatusCode() === 304 ? $probe : null;
    }

    /**
     * Serve a derivative, custom thumbnail, cover or DNG preview: files the app writes itself, which are
     * not FileObjects. Originals and attachments go through ContentStore::deliver().
     */
    private function getFileResponse($path, ?string $disk = null, ?string $mimeType = null, ?string $version = null)
    {
        $disk ??= Directory::getAssetDisk();

        if ($disk === Directory::ASSETS_DISK_AWS) {
            $response = $this->contentStore()->deliverStoredFile(disk: $disk, path: $path, mimeType: $mimeType);

            if ($response->isRedirection()) {
                return $response;
            }

            $response = $this->applyAssetCache($response, $version);

            // nginx follows X-Accel-Redirect whatever the status, which would turn this 304 into a full download.
            if ($response->getStatusCode() === Response::HTTP_NOT_MODIFIED) {
                $response->headers->remove('X-Accel-Redirect');
            }

            return $response;
        }

        $absolutePath = Storage::disk($disk)->path($path);

        $headers = AssetHelper::assetResponseHeaders();

        if ($mimeType) {
            $headers['Content-Type'] = $mimeType;
        }

        if ($accelerated = $this->acceleratedFileResponse($path, $absolutePath, $headers, $mimeType)) {
            return $this->applyAssetCache($accelerated, $version);
        }

        return $this->applyAssetCache(response()->file($absolutePath, $headers), $version);
    }

    private function resizeImage($file, $width)
    {
        $manager = new ImageManager(new Driver);

        return $manager->decode($file)->scale(width: $width);
    }

    public function preview()
    {
        if (! Auth::check()) {
            return abort(403, trans('dam::app.admin.permissions.unauthorized'));
        }

        $path = urldecode(request()->path);
        $asset = $this->assertPathAllowed($path);

        // A preview shows the asset itself, so a missing or truncated original is a 404 (ContentUnavailable
        // renders it) before any branch, a cached DNG preview included; the record is written either way.
        $object = $this->availableObject($asset, $path);

        if ($asset && request()->boolean('text') && PreviewKinds::gameAssetKind($asset->extension) === PreviewKinds::KIND_UNITY_YAML) {
            return $this->textHead($object);
        }

        if ($asset && strtolower((string) $asset->extension) === 'dng') {
            $preview = app(DngPreviewService::class)->ensure($asset, budgetSeconds: 10);

            return ($preview ? $this->getFileResponse($preview, mimeType: 'image/jpeg') : $this->getDefaultPreviewImage($path))->setCache(['private' => true, 'max_age' => 0, 'must_revalidate' => true]);
        }

        $customSize = intval(request()->get('size'));

        $maxSize = 1920;
        $customSize = min($maxSize, $customSize);

        // A GIF is served as stored: resizing keeps only the first frame, and decoding every frame of a large
        // animated GIF exhausts memory or time past the catch below (a 500 instead of the placeholder).
        if ($customSize > 0 && $this->contentMime($asset, $object) === 'image/gif') {
            return $this->deliverOriginal($asset, $object);
        }

        if ($customSize > 0 && $this->imageKind($asset, $object) === 'raster') {
            $extension = $this->sourceExtension($asset, $object);

            try {
                return $this->imageDerivative(
                    object: $object,
                    destination: DerivativeImages::preview($object->sha256, $customSize, $extension),
                    sourceExtension: $extension,
                    maxSize: $customSize,
                );
            } catch (\Throwable $e) {
                Log::info('Failed Generating Image preview: '.$e->getMessage());
            }
        } elseif ($this->isSupportedMediaFile($this->contentMime($asset, $object))) {
            return $this->deliverOriginal($asset, $object);
        }

        // Present but not previewable here (an office document, an undecodable image): the type placeholder.
        return $this->getDefaultPreviewImage($path);
    }

    /**
     * The first TEXT_PREVIEW_BYTES of a Unity text asset (`?text=1`), as JSON so nothing in it is ever rendered as
     * markup. A binary-serialized asset (Unity can save either way) is flagged, and its bytes are not sent.
     */
    private function textHead(FileObject $object): JsonResponse
    {
        $stream = $this->contentStore()->open($object);

        try {
            $head = (string) stream_get_contents($stream, self::TEXT_PREVIEW_BYTES + 1);
        } finally {
            fclose($stream);
        }

        $truncated = strlen($head) > self::TEXT_PREVIEW_BYTES;
        $head = substr($head, 0, self::TEXT_PREVIEW_BYTES);
        $binary = str_contains($head, "\0");

        return response()->json([
            'binary'      => $binary,
            'text'        => $binary ? null : mb_scrub($head, 'UTF-8'),
            'truncated'   => ! $binary && $truncated,
            'limit_bytes' => self::TEXT_PREVIEW_BYTES,
        ], options: JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)->header('Cache-Control', 'private, no-store');
    }

    private function isSupportedMediaFile($mimeType)
    {
        return Str::startsWith($mimeType, 'image/') ||
            Str::startsWith($mimeType, 'application/pdf') ||
            Str::startsWith($mimeType, 'video/') ||
            Str::startsWith($mimeType, 'audio/');
    }

    /**
     * A type placeholder: the same unversioned URL later serves the real thumbnail, so it stays `no-cache` (never a
     * max-age), but carries a strong ETag of its bytes so a warm reload is a bodyless 304 rather than a full refetch.
     */
    private function placeholderResponse(string $body, string $mimeType)
    {
        $response = response($body, 200)->header('Content-Type', $mimeType);
        $response->setEtag(hash('sha256', $body));

        return $this->applyAssetCache($response);
    }

    private function getDefaultImage($path, $directoryPrefix)
    {
        $extension = File::extension(basename($path));

        // An Adobe file with no picture of its own shows its application's mark, not the generic document glyph.
        $tile = AdobeFormats::tileSvg(extension: $extension, canvas: $directoryPrefix);

        if ($tile !== null) {
            return $this->placeholderResponse(body: $tile, mimeType: 'image/svg+xml');
        }

        $type = AssetHelper::getFileTypeUsingExtension($extension);
        $placeholderPath = 'dam/'.$directoryPrefix.'/'.$type.'.svg';

        if (Storage::disk('public')->exists($placeholderPath)) {
            $mimeType = Storage::disk('public')->mimeType($placeholderPath);
            $fileContent = Storage::disk('public')->get($placeholderPath);

            return $this->placeholderResponse(body: $fileContent, mimeType: $mimeType);
        }

        // The public copy only exists after `vendor:publish --tag=dam-defaults`, which the
        // deploy never runs; a placeholder added later (model.svg) is served from the package.
        $bundled = realpath(__DIR__.'/../../Resources/assets/images/'.$directoryPrefix.'/'.$type.'.svg');

        if ($bundled !== false && is_file($bundled)) {
            return $this->placeholderResponse(body: (string) file_get_contents($bundled), mimeType: 'image/svg+xml');
        }

        return response()->json(['error' => trans('dam::app.admin.dam.file.not-found')], 404);
    }

    public function coverArt(int $assetId)
    {
        if (! Auth::check()) {
            return abort(403, trans('dam::app.admin.permissions.unauthorized'));
        }

        $disk = Directory::getAssetDisk();
        $asset = Asset::find($assetId);

        if (! $asset) {
            return abort(404);
        }

        $this->assertAssetAllowed($asset);

        // Cover art is extracted from the original, so it is only shown while the original is available:
        // otherwise a recorded 404 (ContentUnavailable renders it).
        $this->availableObject($asset, (string) $asset->path);

        $path = $asset->meta_data['cover_art_path'] ?? null;

        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return abort(404);
        }

        return $this->getFileResponse($path);
    }

    public function getDefaultThumbnailImage($path)
    {
        return $this->getDefaultImage($path, 'grid');
    }

    public function getDefaultPreviewImage($path)
    {
        return $this->getDefaultImage($path, 'preview');
    }
}

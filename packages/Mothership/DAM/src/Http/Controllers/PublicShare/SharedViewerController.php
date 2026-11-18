<?php

namespace Mothership\DAM\Http\Controllers\PublicShare;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Http\Controllers\Concerns\StreamsZipDownload;
use Mothership\DAM\Http\Controllers\WebPageController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Collections\CollectionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\DngPreviewService;
use Mothership\DAM\Services\Merge\MergedSourceLinks;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\Share\SharedFilePreview;
use Mothership\DAM\Services\Share\SharedFiles;
use Mothership\DAM\Services\Share\ShareGate;
use Mothership\DAM\Services\Share\SharePlayback;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\DAM\Services\WebPageMembers;
use Mothership\DAM\Services\WebPageResolver;
use Mothership\DAM\Services\WebPageTelemetry;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\CollectionLog;
use Mothership\DAM\Support\CoverArt;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\PreviewKinds;
use Mothership\DAM\Support\ZipEntryNames;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class SharedViewerController extends Controller
{
    use StreamsZipDownload;

    public function __construct(
        protected ShareRepository $shareRepository,
        protected SharedFiles $sharedFiles,
        protected SharedFilePreview $sharedFilePreview,
    ) {}

    public function show(Request $request, string $token)
    {
        $share = $this->gate()->share($token);

        if (! $share) {
            return $this->renderNotFound();
        }

        if (! $share->isActive()) {
            return $this->renderExpired($share);
        }

        if ($share->share_type === Share::TYPE_ASSET) {
            $asset = $this->linkedAsset($share);

            if (! $asset) {
                return $this->renderNotFound();
            }

            if ($status = $this->unavailableStatus($asset)) {
                return $status === 410 ? $this->renderGone(share: $share, asset: $asset) : $this->renderNotFound();
            }

            return view('dam::share.public.asset', [
                'share'            => $share,
                'asset'            => $asset,
                'sharedFiles'      => $this->sharedFiles->present(share: $share, asset: $asset, token: $token, memberAssetId: null),
                'viewToken'        => $this->recordRenderedVisit(share: $share, request: $request),
            ]);
        }

        if ($share->share_type === Share::TYPE_COLLECTION) {
            return $this->showCollection(share: $share, request: $request);
        }

        $directory = $share->directory;
        if (! $directory) {
            return $this->renderNotFound();
        }

        $assets = $this->subtreeAssetQuery($directory)->visibleCaptures()->withDisplayName()->orderByDesc('updated_at')->paginate(24);

        return view('dam::share.public.directory', [
            'share'      => $share,
            'directory'  => $directory,
            'assets'     => $assets,
            'fileCounts' => $this->sharedFiles->counts($assets->getCollection()),
            'viewToken'  => $this->recordRenderedVisit(share: $share, request: $request),
        ]);
    }

    /**
     * A visit counts only once the page will show the link's file or folder: a gone or missing page can never reach
     * a preview. HEAD (a link unfurler, a checker) is not a visit.
     */
    protected function recordRenderedVisit(Share $share, Request $request): ?string
    {
        return $request->isMethod('HEAD') ? null : $this->shareRepository->recordVisit(share: $share, request: $request);
    }

    public function listAssets(string $token): JsonResponse
    {
        $share = $this->gate()->activeShare($token);

        if (! $share || ! $share->isContainer()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // P4 D2: a collection lists its servable members in position order; a folder its subtree, newest first.
        $listing = $share->share_type === Share::TYPE_COLLECTION
            ? $this->collectionMembers($share)
            : ($share->directory ? $this->subtreeAssetQuery($share->directory)->visibleCaptures()->withDisplayName()->orderByDesc('updated_at') : null);

        if ($listing === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $page = max(1, (int) request('page', 1));
        $perPage = 24;

        $paginator = $listing->simplePaginate($perPage, ['*'], 'page', $page);
        $fileCounts = $this->sharedFiles->counts($paginator->getCollection());

        $data = $paginator->getCollection()->map(fn (Asset $asset) => [
            'id'                  => $asset->id,
            'file_name'           => $asset->file_name,
            'display_name'        => $asset->display_name,
            'file_type'           => $asset->file_type,
            'extension'           => strtolower((string) $asset->extension),
            'file_size_formatted' => $asset->file_size
                ? Number::fileSize((int) $asset->file_size, precision: 1)
                : '',
            'file_count'          => $fileCounts[(int) $asset->id] ?? 0,
            'thumbnail_url'       => route('dam.share.thumbnail', ['token' => $token, 'assetId' => $asset->id]),
            'view_url'            => route('dam.share.asset_view', ['token' => $token, 'assetId' => $asset->id]),
            'placeholder_svg'     => asset('storage/dam/grid/'.match ($asset->file_type) {
                'image'    => 'image.svg',
                'video'    => 'video.svg',
                'audio'    => 'audio.svg',
                'document' => 'file.svg',
                default    => 'unspecified.svg',
            }),
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'has_more'     => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function download(Request $request, string $token, ?int $attachment = null)
    {
        $share = $this->gate()->activeShare($token);

        if (! $share || $share->share_type !== Share::TYPE_ASSET) {
            return $this->renderExpiredOrNotFound($token);
        }

        $asset = $this->linkedAsset($share);
        if (! $asset) {
            return $this->renderNotFound();
        }

        if ($status = $this->unavailableStatus($asset)) {
            return $status === 410 ? $this->renderGone(share: $share, asset: $asset) : $this->renderNotFound();
        }

        // One return for both outcomes, so a check added above it guards an alternate exactly as it guards the default.
        return $attachment === null
            ? $this->streamAsset($asset, $request->query('disposition', 'attachment'), fn () => $this->countDownload(request: $request, share: $share, asset: $asset, via: 'direct'), share: $share)
            : $this->streamSharedFile($asset, $attachment, fn () => $this->countDownload(request: $request, share: $share, asset: $asset, via: 'alternate', attachmentId: $attachment));
    }

    /**
     * Serves a shared web page's bundle (ADR 0007). For a directory share the first path
     * segment is the asset id, mirroring share.asset_view. Views are counted by show()/assetView()
     * on the wrapper page, never here, so the iframe does not double count.
     */
    public function page(WebPageResolver $resolver, string $token, string $path = '')
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);

        $share = $this->gate()->activeShare($token);
        abort_unless($share, 404);

        if ($share->share_type === Share::TYPE_ASSET) {
            $asset = $this->linkedAsset($share);

            if ($asset && ($status = $this->unavailableStatus($asset))) {
                abort($status);
            }

            // P3b-1: a merge source's page is its own bundle only while the slot that came from it holds those bytes.
            if ($asset && ($view = app(MergedSourceLinks::class)->contentView($asset)) !== null) {
                abort_if($view === false || WebPageBundler::rootFor($view) === null, 404);
                $asset = $view;
            }
        } else {
            [$assetId, $rest] = array_pad(explode('/', ltrim($path, '/'), 2), 2, '');
            abort_unless(ctype_digit($assetId), 404);

            $asset = $this->resolveMemberAsset($share, (int) $assetId);
            $path = $rest;
        }

        abort_unless($asset && $asset->file_type === 'web', 404);

        $response = app(WebPageController::class)->serve($asset, $path, $resolver, public: true);

        // Only a served entry is a page view: a 302 to the trailing-slash root is followed by a
        // second request that logs it, and re-resolving would otherwise count the hop as well.
        if ($response->getStatusCode() === 200 && ($resolver->resolve($asset, $path)['is_entry'] ?? false)) {
            WebPageTelemetry::record('web_pages.page_viewed', ['asset_id' => $asset->id, 'entry' => WebPageBundler::ENTRY]);
        }

        return $response;
    }

    /**
     * One stack member's page of a shared web asset (ADR 0007 amendment 2026-10-24). The path carries the whole pin,
     * {asset}.{attachment}.{file}.{FULL checksum}. After the same share gate, expiry, revocation, password and
     * asset-or-container membership checks as page(), the pinned slot must be one of the files this link hands out
     * (SharedFiles, i.e. StackMembers for an ordinary asset) holding exactly that file and checksum; a revised, moved,
     * removed or foreign slot is 404. Nothing is queued before every one of those checks passed. It is not a download
     * route, so a preview-only link renders the page and gains no download or edit access; a merge source never
     * resolves here (its handlers stay as they are) and no asset's cache is reached by matching bytes.
     */
    public function memberPage(WebPageResolver $resolver, WebPageMembers $members, string $token, string $pin, string $path = '')
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);

        $share = $this->gate()->activeShare($token);
        abort_if($share === null, 404);

        [$assetId, $attachmentId, $fileId, $checksum] = explode('.', $pin);

        if ($share->share_type === Share::TYPE_ASSET) {
            $asset = $this->linkedAsset($share);
            abort_unless($asset && (int) $asset->id === (int) $assetId, 404);

            if ($status = $this->unavailableStatus($asset)) {
                abort($status);
            }
        } else {
            $asset = $this->resolveMemberAsset($share, (int) $assetId);
        }

        abort_unless($asset && $asset->file_type === 'web' && ! $asset->isMerged(), 404);

        $file = $this->sharedFiles->find($asset, (int) $attachmentId);
        abort_unless($file !== null
            && (int) $file->id === (int) $fileId
            && hash_equals($members->checksumOf($file), $checksum)
            && $members->eligible($asset, $file), 404);

        $controller = app(WebPageController::class);
        $path = $controller->requestPath($path);
        $resolved = $members->resolve($asset, $file, $path);
        $response = $controller->serve($asset, $path, $resolver, public: true, resolved: $resolved);

        if ($response->getStatusCode() === 200 && ($resolved['is_entry'] ?? false) && ! request()->isMethod('HEAD')) {
            WebPageTelemetry::record('web_pages.page_viewed', ['asset_id' => $asset->id, 'attachment_id' => $file->attachment_id, 'file_id' => $file->id, 'entry' => WebPageBundler::ENTRY]);
        }

        return $response;
    }

    /** The same member page for a password-protected link: EnforceShareGate has checked the page grant in the URL. */
    public function memberPageGranted(WebPageResolver $resolver, WebPageMembers $members, string $token, string $grant, string $pin, string $path = '')
    {
        return $this->memberPage($resolver, $members, $token, $pin, $path);
    }

    public function assetView(string $token, int $assetId)
    {
        $share = $this->gate()->activeShare($token);

        if (! $share || ! $share->isContainer()) {
            return $this->renderExpiredOrNotFound($token);
        }

        if ($share->share_type === Share::TYPE_COLLECTION) {
            return $this->collectionMemberView(share: $share, assetId: $assetId, token: $token);
        }

        $directory = $share->directory;
        if (! $directory) {
            return $this->renderNotFound();
        }

        $asset = $this->subtreeAssetQuery($directory)->where('id', $assetId)->first();
        if (! $asset instanceof Asset) {
            return $this->renderNotFound();
        }

        $prevAssetId = $this->subtreeAssetQuery($directory)->visibleCaptures()
            ->where(fn ($q) => $q
                ->where('updated_at', '<', $asset->updated_at)
                ->orWhere(fn ($q) => $q->where('updated_at', $asset->updated_at)->where('id', '<', $asset->id))
            )
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->value('id');

        $nextAssetId = $this->subtreeAssetQuery($directory)->visibleCaptures()
            ->where(fn ($q) => $q
                ->where('updated_at', '>', $asset->updated_at)
                ->orWhere(fn ($q) => $q->where('updated_at', $asset->updated_at)->where('id', '>', $asset->id))
            )
            ->orderBy('updated_at')->orderBy('id')
            ->value('id');

        return view('dam::share.public.asset', [
            'share'            => $share,
            'asset'            => $asset,
            'prevAssetId'      => $prevAssetId,
            'nextAssetId'      => $nextAssetId,
            'sharedFiles'      => $this->sharedFiles->present(share: $share, asset: $asset, token: $token, memberAssetId: (int) $asset->id),
        ]);
    }

    public function assetDownload(Request $request, string $token, int $assetId, ?int $attachment = null)
    {
        $share = $this->gate()->activeShare($token);

        if (! $share || ! $share->isContainer()) {
            return $this->renderExpiredOrNotFound($token);
        }

        $asset = $this->resolveMemberAsset($share, $assetId);
        if (! $asset) {
            return $this->renderNotFound();
        }

        // One return for both outcomes, so a check added above it guards an alternate exactly as it guards the default.
        return $attachment === null
            ? $this->streamAsset($asset, $request->query('disposition', 'attachment'), fn () => $this->countDownload(request: $request, share: $share, asset: $asset, via: 'direct'), share: $share)
            : $this->streamSharedFile($asset, $attachment, fn () => $this->countDownload(request: $request, share: $share, asset: $asset, via: 'alternate', attachmentId: $attachment));
    }

    public function thumbnail(string $token, int $assetId)
    {
        $share = $this->gate()->activeShare($token);

        if (! $share) {
            return abort(404);
        }

        $asset = $this->resolveAssetForShare($share, $assetId);
        if (! $asset) {
            return abort(404);
        }

        if ($status = $this->unavailableStatus($asset)) {
            return abort($status);
        }

        // P3b-1: a merge source's thumbnail derives from the slot that came from it (MergedSourceLinks); none left: 404.
        if (($view = app(MergedSourceLinks::class)->contentView($asset)) !== null) {
            abort_if($view === false, 404);
            $asset = $view;
        }

        return $this->thumbnailFor($asset, $share);
    }

    /** The thumbnail of an asset a share or collection route has already resolved and checked. */
    protected function thumbnailFor(Asset $asset, ?Share $share = null)
    {
        $disk = Directory::getAssetDisk();

        // Checked once, before every branch: a missing or truncated original always writes its
        // content_unavailable record, and since every other share thumbnail (DNG preview, captured model
        // view, cover art, poster, image thumbnail) is derived from the original, the card then gets its
        // type placeholder rather than a cached derivative that would make the asset look healthy.
        $object = $this->readableObject($asset);

        // The same order as the admin thumbnail (FileController::thumbnail): a custom thumbnail is art a
        // user uploaded on its own, so it still shows when only the original is gone.
        $custom = CustomThumbnailService::location($asset);
        if ($custom && Storage::disk($disk)->exists($custom['path'])) {
            return $this->fileResponse($custom['path'], $custom['mime_type']);
        }

        if ($object === null) {
            return $this->placeholderResponse($asset);
        }

        if (strtolower((string) $asset->extension) === 'dng') {
            $preview = app(DngPreviewService::class)->ensure($asset, thumbnail: true, budgetSeconds: 10);

            return ($preview ? $this->fileResponse($preview) : $this->placeholderResponse($asset))
                ->setCache(['private' => true, 'max_age' => 0, 'must_revalidate' => true]);
        }

        // A GLB, 3MF or STL view is captured in a browser (ADR 0006). A public link serves only the capture
        // path this asset's own original yields, never whatever meta_data names, so a share cannot be
        // pointed at another asset's file.
        if (ModelThumbnailService::supports($asset)) {
            $checksum = app(MergedSourceLinks::class)->modelChecksum($asset) ?? ModelThumbnailService::checksum($asset);
            $captured = $checksum ? ModelThumbnailService::generatedPath($asset, $checksum) : null;

            if ($captured !== null && Storage::disk($disk)->exists($captured)) {
                return $this->fileResponse($captured);
            }
        }

        $coverPath = $this->ownCoverPath(asset: $asset, object: $object);
        if ($coverPath !== null && Storage::disk($disk)->exists($coverPath)) {
            return $this->fileResponse($coverPath);
        }

        if ($asset->file_type === 'video' || $asset->asset_kind === 'pano360' || AdobeFormats::isPdfRenderable($asset->extension, $asset->mime_type)
            || PreviewKinds::hasEmbeddedThumbnail($asset->extension)) {
            // Only the poster keyed by this asset's own original, which is what the poster jobs write. A
            // public link never serves a path taken from meta_data, so it cannot reach another asset's file.
            $cached = DerivativeImages::poster($object->sha256);

            if (Storage::disk($disk)->exists($cached)) {
                return $this->fileResponse($cached);
            }
        }

        // PD8: a preview-only link never serves a GIF's animated thumbnail copy, only its first-frame still.
        if ($share !== null && ! $share->allowsDownload() && $this->isGif(object: $object, asset: $asset)) {
            try {
                return $this->sharedFilePreview->gifThumbnailStill(object: $object);
            } catch (\Throwable $e) {
                Log::warning('DAM share GIF thumbnail still failed: '.$e->getMessage(), ['asset' => $asset->id]);

                return $this->placeholderResponse($asset);
            }
        }

        $kind = $this->imageKind($object);

        if ($kind === 'raster') {
            $extension = strtolower((string) $asset->extension);

            try {
                // A share link keeps its own bounded copy (shareStill()), as its stacked files do, so a thumbnail an
                // admin view upscaled is never what a public link serves. The admin collection view keeps the grid copy.
                return $this->imageDerivative(
                    object: $object,
                    destination: $share !== null
                        ? DerivativeImages::shareStill((string) $object->sha256, 'thumbnail', $extension)
                        : DerivativeImages::thumbnail($object->sha256, $extension),
                    sourceExtension: $extension,
                    maxSize: 300,
                );
            } catch (\Throwable $e) {
                Log::warning('DAM share thumbnail generation failed: '.$e->getMessage(), ['asset' => $asset->id]);
            }
        } elseif ($kind === 'svg') {
            // An SVG is its own thumbnail; no copy is cached.
            return $this->contentStore()->deliver(
                object: $object,
                filename: $asset->file_name,
                disposition: HeaderUtils::DISPOSITION_INLINE,
                mimeType: 'image/svg+xml',
            );
        }

        return $this->placeholderResponse($asset);
    }

    private function isGif(FileObject $object, Asset $asset): bool
    {
        return strtolower(trim(explode(';', (string) ($object->mime_type ?: $asset->mime_type))[0])) === 'image/gif';
    }

    /**
     * The current original of a share's asset, checked like every read (own locator, present, recorded
     * size). Null when there is none or its blob is missing or truncated, but only after ContentStore has
     * recorded the ContentUnavailable to content-store.jsonl, so a placeholder or 404 never hides it.
     */
    private function readableObject(Asset $asset): ?FileObject
    {
        try {
            // P3b-1: a merge source's link reads only the slot that came from it (MergedSourceLinks); false: none left.
            $merged = app(MergedSourceLinks::class)->defaultObject($asset);

            return $merged === false ? null : $this->contentStore()->ensureAvailable($merged ?? $this->contentStore()->current($asset));
        } catch (ContentUnavailable) {
            return null;
        }
    }

    /**
     * The audio asset's cover art, only when meta_data names a cover file (CoverArt::PATTERN) of this asset, or of the
     * asset a copy inherited it from while both still hold the same original bytes. A public link never serves any
     * other path meta_data names, so a share cannot be pointed at another asset's file.
     */
    private function ownCoverPath(Asset $asset, FileObject $object): ?string
    {
        $path = $asset->file_type === 'audio' ? ($asset->meta_data['cover_art_path'] ?? null) : null;

        if (! is_string($path) || preg_match('#^covers/(\d+)[-.]#', $path, $owner) !== 1 || preg_match(CoverArt::PATTERN, $path) !== 1) {
            return null;
        }

        if (CoverArt::ownedBy((int) $asset->id, $path)) {
            return $path;
        }

        $source = Asset::query()->find((int) $owner[1]);

        try {
            return $source !== null && $this->contentStore()->current($source)->sha256 === $object->sha256 ? $path : null;
        } catch (ContentUnavailable) {
            return null;
        }
    }

    private function contentStore(): ContentStore
    {
        return app(ContentStore::class);
    }

    /** 'raster' for a GD-decodable image, 'svg', or null for anything else. */
    private function imageKind(FileObject $object): ?string
    {
        $mime = strtolower(trim(explode(';', (string) $object->mime_type)[0]));

        if ($mime === 'image/svg+xml') {
            return 'svg';
        }

        return Str::startsWith($mime, 'image/') ? 'raster' : null;
    }

    /**
     * The WebP derivative of a HEIC/HEIF blob (libvips only: GD cannot read it), or null for any other blob. Only a blob
     * typed that way is staged for it.
     */
    private function heifDerivative(FileObject $object, string $destination, string $sourceExtension, int $maxSize)
    {
        return $this->sharedFilePreview->heifDerivative(object: $object, destination: $destination, sourceExtension: $sourceExtension, maxSize: $maxSize);
    }

    /**
     * A cached thumbnail of an image blob, generated on first request and keyed by the blob's sha, so it
     * survives moves and is shared by copies.
     */
    private function imageDerivative(FileObject $object, string $destination, string $sourceExtension, int $maxSize)
    {
        return $this->sharedFilePreview->derivative(object: $object, destination: $destination, sourceExtension: $sourceExtension, maxSize: $maxSize);
    }

    public function downloadZip(string $token)
    {
        $share = $this->gate()->activeShare($token);

        if (! $share || ! $share->isContainer()) {
            return $this->renderExpiredOrNotFound($token);
        }

        // P4 D2: a collection's zip holds exactly its servable members, through the seam; download-off already
        // answered 403 in EnforceShareGate.
        if ($share->share_type === Share::TYPE_COLLECTION) {
            $members = $this->collectionMembers($share);

            if ($members === null) {
                return $this->renderNotFound();
            }

            $this->shareRepository->incrementDownload($share);

            return $this->collectionZip(members: $members, name: (string) $share->collection?->name);
        }

        $directory = $share->directory;
        if (! $directory) {
            return $this->renderNotFound();
        }

        $disk = Directory::getAssetDisk();
        $folderBase = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());
        $zipName = Str::slug($directory->name ?: 'download').'.zip';

        if (DamEvents::isNewDownload(request: request())) {
            $this->shareRepository->recordDownload(share: $share, request: request(), subjectId: (int) $directory->id, subjectType: Share::TYPE_DIRECTORY, via: 'zip');
        }

        return $this->buildZipStreamFromAssets(
            $this->subtreeAssetQuery($directory)->select(['id', 'path', 'file_name'])->with('directories'),
            $folderBase,
            $disk,
            $zipName,
        );
    }

    /**
     * Stream a share's asset through the read seam: its current original, never a path rebuilt from the
     * folder tree (ADR 0009). `$onSuccess` (the download counter) only fires once the blob is confirmed
     * present, matching the old existence check that gated it before.
     */
    protected function streamAsset(Asset $asset, string $disposition, ?callable $onSuccess = null, ?Share $share = null)
    {
        $disposition = in_array(strtolower($disposition), ['inline', 'attachment'], true)
            ? strtolower($disposition)
            : 'attachment';

        // Checked before any branch, a cached DNG preview included: a missing or truncated original is a
        // recorded 404, never a derivative served as if the asset were intact.
        $object = $this->readableObject($asset);

        if ($object === null) {
            return abort(404);
        }

        if ($disposition === 'inline' && $this->previewsAsDerivative($asset)) {
            $preview = app(DngPreviewService::class)->ensure(app(MergedSourceLinks::class)->contentView($asset) ?: $asset, budgetSeconds: 10);

            return ($preview ? $this->fileResponse($preview) : $this->placeholderResponse($asset))
                ->setCache(['private' => true, 'max_age' => 0, 'must_revalidate' => true]);
        }

        // A browser cannot show a HEIC/HEIF, so the inline view (the shared viewer, a collection member) gets the bounded
        // WebP copy; a download keeps the original. A decode failure falls through to the original, as before.
        if ($disposition === 'inline' && preg_match('#^image/hei[cf]#i', (string) $object->mime_type) === 1) {
            try {
                $extension = strtolower((string) $asset->extension);
                $derivative = $this->heifDerivative(
                    object: $object,
                    destination: $share !== null
                        ? DerivativeImages::shareStill((string) $object->sha256, 'preview-1920', $extension)
                        : DerivativeImages::preview($object->sha256, 1920, $extension),
                    sourceExtension: $extension,
                    maxSize: 1920,
                );

                if ($derivative !== null) {
                    return $derivative;
                }
            } catch (\Throwable $e) {
                Log::warning('DAM share HEIF preview failed: '.$e->getMessage(), ['asset' => $asset->id]);
            }
        }

        // PD8 (controller ruling 2026-10-19): on a preview-only link the main file's inline view is a GIF's first-frame still,
        // since the animated original would be a saved original under the download-off rule. Downloads-on links keep the original.
        if ($disposition === 'inline' && $share !== null && ! $share->allowsDownload() && $this->isGif(object: $object, asset: $asset)) {
            try {
                return $this->sharedFilePreview->gifPreviewStill(object: $object);
            } catch (\Throwable $e) {
                Log::warning('DAM share GIF still failed: '.$e->getMessage(), ['asset' => $asset->id]);

                return abort(404);
            }
        }

        if ($onSuccess && $disposition === 'attachment') {
            $onSuccess();
        }

        return $this->contentStore()->deliver(
            object: $object,
            filename: $asset->file_name,
            disposition: $disposition,
            mimeType: $object->mime_type ?: $asset->mime_type ?: null,
        );
    }

    /**
     * Streams exactly the file the link names by its slot (OD5: SharedFiles). A slot the link does not hand out for this
     * asset answers 404, never the main file in its place; so does one whose bytes are gone.
     */
    protected function streamSharedFile(Asset $asset, int $attachmentId, callable $onSuccess)
    {
        $file = $this->sharedFiles->find($asset, $attachmentId);

        if ($file === null) {
            return $this->renderNotFound();
        }

        try {
            $object = $this->contentStore()->ensureAvailable($file->fileObject);
        } catch (ContentUnavailable) {
            return abort(404);
        }

        $onSuccess();

        return $this->contentStore()->deliver(
            object: $object,
            filename: $this->sharedFiles->downloadName($asset, $file),
            disposition: HeaderUtils::DISPOSITION_ATTACHMENT,
            mimeType: $object->mime_type ?: null,
        );
    }

    /**
     * One file handed out through a link (P1b). A HEAD request, and a ranged request that resumes part-way through
     * the file, are not a new download.
     */
    private function countDownload(Request $request, Share $share, Asset $asset, string $via, ?int $attachmentId = null): void
    {
        if (! DamEvents::isNewDownload(request: $request)) {
            return;
        }

        $this->shareRepository->recordDownload(share: $share, request: $request, subjectId: (int) $asset->id, subjectType: Share::TYPE_ASSET, via: $via, attachmentId: $attachmentId);
    }

    /** A folder link's files: only those out for use now (P2 S3), so an unavailable file is simply not in it. */
    protected function subtreeAssetQuery(Directory $directory): Builder
    {
        return Asset::query()
            ->whereHas('directories', fn ($q) => $q->whereBetween('_lft', [$directory->_lft, $directory->_rgt]))
            ->availableNow()
            // P3b-1: a merge source is in no folder link while it is merged.
            ->whereNull('dam_assets.merged_into_id');
    }

    /**
     * P2 S3 for an asset link: null when the asset is out for use; 410 (the expired-link page) when it is archived
     * or outside its window, as an expired link is, or in the trash (P2 S4); 404 for a draft or one pending review,
     * which was never out. A link is the same for everyone, so no viewer sees past this, a signed-in owner included.
     */
    protected function unavailableStatus(Asset $asset): ?int
    {
        if ($asset->trashed()) {
            return 410;
        }

        // P3b-1: a merge source's link also closes with its destination (MergedSourceLinks).
        if ($asset->isMerged() && ($status = app(MergedSourceLinks::class)->status($asset)) !== null) {
            return $status;
        }

        return match ($asset->unavailableBecause()) {
            null                                                          => null,
            Asset::AVAILABILITY_DRAFT, Asset::AVAILABILITY_PENDING_REVIEW => 404,
            default                                                       => 410,
        };
    }

    /**
     * An asset link's target, trashed or not (P2 S4): the link stays while its asset is in the trash and answers 410
     * through unavailableStatus(), like an expired link, rather than the 404 an asset that never existed gets.
     */
    protected function linkedAsset(Share $share): ?Asset
    {
        return $share->asset()->withTrashed()->first();
    }

    protected function resolveDirectoryAsset(Share $share, int $assetId): ?Asset
    {
        $directory = $share->directory;
        if (! $directory) {
            return null;
        }

        return $this->subtreeAssetQuery($directory)->where('id', $assetId)->first();
    }

    protected function resolveAssetForShare(Share $share, int $assetId): ?Asset
    {
        if ($share->share_type === Share::TYPE_ASSET) {
            return $share->target_id === $assetId ? $this->linkedAsset($share) : null;
        }

        return $this->resolveMemberAsset($share, $assetId);
    }

    /** A folder link's or a collection link's file by id: only one it holds and serves right now, else null. */
    protected function resolveMemberAsset(Share $share, int $assetId): ?Asset
    {
        if ($share->share_type === Share::TYPE_COLLECTION) {
            return $this->collectionMembers($share)?->where('dam_assets.id', $assetId)->first();
        }

        return $share->share_type === Share::TYPE_DIRECTORY ? $this->resolveDirectoryAsset($share, $assetId) : null;
    }

    /**
     * P4 D2: a public collection's servable members through the seam (`collection:<id>` by its link), in position
     * order. Null when the collection is gone.
     *
     * @return Builder<Asset>|null
     */
    protected function collectionMembers(Share $share): ?Builder
    {
        $collection = $share->collection;

        if ($collection === null) {
            return null;
        }

        return app(CollectionService::class)->servableMembers(
            collection: $collection,
            context: AccessContext::collectionLink(collectionId: (int) $collection->id),
        );
    }

    /** P4 D2: a public collection's landing page, members in position order. */
    protected function showCollection(Share $share, Request $request)
    {
        $collection = $share->collection;
        $members = $this->collectionMembers($share);

        if ($collection === null || $members === null) {
            return $this->renderNotFound();
        }

        $viewToken = $this->recordRenderedVisit(share: $share, request: $request);
        CollectionLog::write('collection.visit', ['collection_id' => (int) $collection->id, 'share_id' => (int) $share->id, 'via' => 'link']);

        $page = $members->paginate(24);

        return view('dam::share.public.directory', [
            'share'      => $share,
            'directory'  => $collection,
            'collection' => $collection,
            'assets'     => $page,
            'fileCounts' => $this->sharedFiles->counts($page->getCollection()),
            'viewToken'  => $viewToken,
        ]);
    }

    /** P4 D2: one member of a public collection, with its neighbours in position order. */
    protected function collectionMemberView(Share $share, int $assetId, string $token)
    {
        $members = $this->collectionMembers($share);
        $asset = $members === null ? null : (clone $members)->where('dam_assets.id', $assetId)->first();

        if (! $asset instanceof Asset) {
            return $this->renderNotFound();
        }

        $ids = $members->pluck('dam_assets.id')->map(fn ($id) => (int) $id)->values()->all();
        $index = array_search((int) $asset->id, $ids, true);

        return view('dam::share.public.asset', [
            'share'            => $share,
            'asset'            => $asset,
            'prevAssetId'      => $index !== false && $index > 0 ? $ids[$index - 1] : null,
            'nextAssetId'      => $index !== false && $index < count($ids) - 1 ? $ids[$index + 1] : null,
            'sharedFiles'      => $this->sharedFiles->present(share: $share, asset: $asset, token: $token, memberAssetId: (int) $asset->id),
        ]);
    }

    /**
     * P4 D2: a zip of a collection's members, flat (a member's folder means nothing to its audience). $members is
     * already the seam's servable set; the caller has checked the link or the viewer and the download right.
     */
    public function collectionZip(Builder $members, string $name)
    {
        // A base no Library path starts with, so every entry is named by its file name alone.
        return $this->buildZipStreamFromAssets(
            $members,
            'collection/members',
            Directory::getAssetDisk(),
            (Str::slug($name) ?: 'collection').'.zip',
        );
    }

    /** P4 D2: a member's thumbnail for a collection route that has already resolved it through the seam. */
    public function memberThumbnail(Asset $asset)
    {
        return $this->thumbnailFor($asset);
    }

    /** P4 D2: a member's original (inline or as a download), for a collection route that has already resolved it. */
    public function memberOriginal(Asset $asset, string $disposition)
    {
        return $this->streamAsset($asset, $disposition);
    }

    /** P4 D2: a member's published proxy, for a collection route that has already resolved it; 404 when it has none. */
    public function memberProxy(Asset $asset)
    {
        return $this->proxyResponse($asset);
    }

    protected function fileResponse(string $path, ?string $mimeType = null)
    {
        return $this->sharedFilePreview->storedFile(path: $path, mimeType: $mimeType);
    }

    protected function placeholderResponse(Asset $asset)
    {
        $type = $asset->file_type ?: 'unspecified';
        $placeholderPath = 'dam/grid/'.$type.'.svg';

        if (Storage::disk('public')->exists($placeholderPath)) {
            $mimeType = Storage::disk('public')->mimeType($placeholderPath);
            $fileContent = Storage::disk('public')->get($placeholderPath);

            return response($fileContent, 200)->header('Content-Type', $mimeType);
        }

        return response('', 404);
    }

    protected function renderNotFound()
    {
        return response()->view('dam::share.public.not-found', [], 404);
    }

    protected function renderExpired(Share $share, bool $notAvailable = false)
    {
        return response()->view('dam::share.public.expired', [
            'share'        => $share,
            'notAvailable' => $notAvailable,
        ], 410);
    }

    /**
     * The 410 page for a live link whose asset is gone from it. An archived asset or a closed window reads as an
     * expired link; a window that has not opened yet never says the link expired, only that the file is not
     * available (P2 S3 review), so the page does not mislead about a link that will start working.
     */
    protected function renderGone(Share $share, Asset $asset)
    {
        return $this->renderExpired(share: $share, notAvailable: $asset->unavailableBecause() === Asset::AVAILABILITY_SCHEDULED);
    }

    protected function renderExpiredOrNotFound(string $token)
    {
        $share = $this->gate()->share($token);
        if ($share && ! $share->isActive()) {
            return $this->renderExpired($share);
        }

        return $this->renderNotFound();
    }

    /**
     * P2 S5b: the original shown inline, for the page's image, PDF and model viewers and a video with no proxy. The
     * same checks and stream as the inline download (it is that answer), but on its own route, so a preview-only
     * link's download routes can answer 403 while this one still shows the file.
     */
    public function preview(Request $request, string $token)
    {
        $this->refuseSavedOriginal(token: $token, assetId: null);
        $request->query->set('disposition', 'inline');

        return $this->download($request, $token);
    }

    /** preview() for one file of a folder link. */
    public function assetPreview(Request $request, string $token, int $assetId)
    {
        $this->refuseSavedOriginal(token: $token, assetId: $assetId);
        $request->query->set('disposition', 'inline');

        return $this->assetDownload($request, $token, $assetId);
    }

    /**
     * Finding 5 (2026-10-27): the image stage's picture. A large JPEG, PNG or WebP original is shown as the link's own
     * screen-sized copy (SharePlayback::displaysCopy(), `share-preview-1920`) instead of the whole file; anything else,
     * or a copy that cannot be made, is preview()'s own answer. The link and lifecycle answers are servedAsset()'s, the
     * ones preview() gives, so this route never opens what preview() would not. The original stays on preview().
     */
    public function display(Request $request, string $token)
    {
        return $this->stagePicture(request: $request, token: $token, assetId: null);
    }

    /** display() for one file of a folder or collection link. */
    public function assetDisplay(Request $request, string $token, int $assetId)
    {
        return $this->stagePicture(request: $request, token: $token, assetId: $assetId);
    }

    private function stagePicture(Request $request, string $token, ?int $assetId)
    {
        $asset = $this->servedAsset(token: $token, assetId: $assetId);
        $object = $asset instanceof Asset ? $this->readableObject($asset) : null;
        $mime = strtolower(trim(explode(';', (string) $object?->mime_type)[0]));

        if ($object !== null && SharePlayback::displaysCopy(mime: $mime, bytes: (int) $object->size)) {
            // Encoded from the bytes' own type, never the extension column, so a mislabelled file keeps WebP.
            $extension = SharePlayback::DISPLAY_MIMES[$mime];

            try {
                return $this->imageDerivative(
                    object: $object,
                    destination: DerivativeImages::shareStill((string) $object->sha256, 'preview-'.SharePlayback::DISPLAY_SIZE, $extension),
                    sourceExtension: $extension,
                    maxSize: SharePlayback::DISPLAY_SIZE,
                )->setCache(['private' => true, 'max_age' => 0, 'must_revalidate' => true]);
            } catch (\Throwable $e) {
                Log::warning('DAM share display copy failed: '.$e->getMessage(), ['asset' => $asset->id]);
            }
        }

        return $assetId === null
            ? $this->preview($request, $token)
            : $this->assetPreview($request, $token, $assetId);
    }

    /**
     * A-contract 3: a preview-only link shows an original inline only when a browser can show it. Any other type would
     * leave as a saved file (ContentStore forces an attachment), which is a download, so it answers 403 like one. The
     * link and lifecycle answers stay download()'s own: this only ever refuses an asset that would otherwise be served.
     */
    private function refuseSavedOriginal(string $token, ?int $assetId): void
    {
        $share = $this->gate()->activeShare($token);

        if ($share === null || $share->allowsDownload()) {
            return;
        }

        $asset = $this->servedAsset(token: $token, assetId: $assetId);

        if (! $asset instanceof Asset) {
            return;
        }

        // Its inline view is a derivative (streamAsset()), never the original bytes, whatever MIME they were stored under.
        if ($this->previewsAsDerivative($asset)) {
            return;
        }

        $object = $this->readableObject($asset);

        abort_if($object !== null && ! AssetHelper::isInlineSafeMime($object->mime_type ?: $asset->mime_type), 403);
    }

    /**
     * Whether the inline view of an asset's original is a derivative rather than the bytes: a DNG shows its extracted
     * JPEG (or the type placeholder), so a preview-only link never hands out the raw file, even one stored under a
     * generic MIME. streamAsset() and refuseSavedOriginal() both ask this, so the two cannot drift apart.
     */
    private function previewsAsDerivative(Asset $asset): bool
    {
        return strtolower((string) $asset->extension) === 'dng';
    }

    /**
     * P2 S6: the asset's published proxy, the file the detail player streams (SharePlayback::proxyFile()), checked
     * like every share read: the link (token, expiry, revocation, password, creator access, through the gate), the
     * asset's availability and trash, and the proxy's own blob. Ranged requests get their 206 from ContentStore. Never
     * the original: an asset with no proxy answers 404 here and its page plays preview() instead.
     */
    public function proxy(string $token)
    {
        return $this->streamProxy(token: $token, assetId: null);
    }

    /** proxy() for one file of a folder link. */
    public function assetProxy(string $token, int $assetId)
    {
        return $this->streamProxy(token: $token, assetId: $assetId);
    }

    /** OD5: one shared file's picture (SharedFilePreview::thumbnail()): 404 when it has none. */
    public function fileThumbnail(string $token, int $attachment)
    {
        return $this->sharedFileThumbnail(token: $token, assetId: null, attachmentId: $attachment);
    }

    public function assetFileThumbnail(string $token, int $assetId, int $attachment)
    {
        return $this->sharedFileThumbnail(token: $token, assetId: $assetId, attachmentId: $attachment);
    }

    /** OD5: one shared file shown in the page (SharedFilePreview::preview()). */
    public function filePreview(string $token, int $attachment)
    {
        return $this->sharedFileView(token: $token, assetId: null, attachmentId: $attachment);
    }

    public function assetFilePreview(string $token, int $assetId, int $attachment)
    {
        return $this->sharedFileView(token: $token, assetId: $assetId, attachmentId: $attachment);
    }

    /** OD5: every file of the linked asset as one zip; download-off already answered 403 in EnforceShareGate. */
    public function filesZip(Request $request, string $token)
    {
        return $this->sharedFilesZip(request: $request, token: $token, assetId: null);
    }

    public function assetFilesZip(Request $request, string $token, int $assetId)
    {
        return $this->sharedFilesZip(request: $request, token: $token, assetId: $assetId);
    }

    /**
     * One file a share route names, with its link and asset, or the answer the link gets instead. The asset's answer
     * comes from servedAsset(): 410 expired, revoked, archived or trashed; 404 missing, pending or outside the folder.
     * Then 404 for a slot SharedFiles does not hand out for that asset.
     *
     * @return array{0: Share, 1: Asset, 2: AssetFile}|Response
     */
    private function servedFile(string $token, ?int $assetId, int $attachmentId): array|Response
    {
        $asset = $this->servedAsset(token: $token, assetId: $assetId);

        if (! $asset instanceof Asset) {
            return $asset;
        }

        $share = $this->gate()->activeShare($token);
        $file = $this->sharedFiles->find($asset, $attachmentId);

        return $share === null || $file === null ? $this->renderNotFound() : [$share, $asset, $file];
    }

    private function sharedFileThumbnail(string $token, ?int $assetId, int $attachmentId)
    {
        $served = $this->servedFile(token: $token, assetId: $assetId, attachmentId: $attachmentId);

        if ($served instanceof Response) {
            return $served;
        }

        [, $asset, $file] = $served;

        return $this->sharedFilePreview->thumbnail(file: $file, asset: $asset) ?? abort(404);
    }

    private function sharedFileView(string $token, ?int $assetId, int $attachmentId)
    {
        $served = $this->servedFile(token: $token, assetId: $assetId, attachmentId: $attachmentId);

        if ($served instanceof Response) {
            return $served;
        }

        [$share, $asset, $file] = $served;

        return $this->sharedFilePreview->preview(share: $share, file: $file, asset: $asset);
    }

    /**
     * Every file is checked before the response starts (a stat, no stream). A file whose bytes are gone answers 404 and
     * counts nothing, never a smaller zip or a half-sent one (the FileController::fetchAssetFilesZip pattern).
     */
    private function sharedFilesZip(Request $request, string $token, ?int $assetId)
    {
        $asset = $this->servedAsset(token: $token, assetId: $assetId);

        if (! $asset instanceof Asset) {
            return $asset;
        }

        $share = $this->gate()->activeShare($token);
        $files = $this->sharedFiles->forAsset($asset);

        if ($share === null || $files->isEmpty()) {
            return $this->renderNotFound();
        }

        $store = $this->contentStore();
        $taken = [];
        $entries = [];

        foreach ($files as $file) {
            $object = $file->fileObject;

            if (! $object instanceof FileObject) {
                return $this->renderNotFound();
            }

            try {
                $object = $store->ensureAvailable($object);
            } catch (ContentUnavailable) {
                return abort(404);
            }

            $entries[] = ['name' => ZipEntryNames::unique($this->sharedFiles->downloadName($asset, $file), $taken), 'object' => $object];
        }

        if (DamEvents::isNewDownload(request: $request)) {
            $this->shareRepository->recordDownload(share: $share, request: $request, subjectId: (int) $asset->id, subjectType: Share::TYPE_ASSET, via: 'zip');
        }

        return $this->streamZip(
            zipName: pathinfo((string) $asset->file_name, PATHINFO_FILENAME).'-files.zip',
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

    /**
     * A password-protected link's web page frame (P2 S5b). The sandboxed frame cannot send the unlock cookie, so its
     * URL carries a page grant, which EnforceShareGate has already checked; otherwise exactly page().
     */
    public function pageGranted(WebPageResolver $resolver, string $token, string $grant, string $path = '')
    {
        return $this->page($resolver, $token, $path);
    }

    protected function streamProxy(string $token, ?int $assetId)
    {
        $asset = $this->servedAsset(token: $token, assetId: $assetId);

        if (! $asset instanceof Asset) {
            return $asset;
        }

        return $this->proxyResponse($asset);
    }

    /** The published proxy of an asset a share or collection route has already resolved and checked. */
    protected function proxyResponse(Asset $asset)
    {
        $file = SharePlayback::proxyFile($asset);

        if ($file === null) {
            return $this->renderNotFound();
        }

        try {
            $object = $this->contentStore()->ensureAvailable($file->fileObject);
        } catch (ContentUnavailable) {
            return abort(404);
        }

        $extension = $file->extension();

        return $this->contentStore()->deliver(
            object: $object,
            filename: pathinfo((string) $asset->file_name, PATHINFO_FILENAME).'-preview'.($extension === '' ? '' : '.'.$extension),
            disposition: HeaderUtils::DISPOSITION_INLINE,
            mimeType: $object->mime_type ?: null,
        );
    }

    /**
     * The asset a share route serves, or the answer the link gets instead: the asset link's own asset (404 for a
     * draft or pending one, 410 for an archived, out-of-window or trashed one), or a folder link's file, which is
     * simply not in it when unavailable. The same resolution download() and assetDownload() make.
     */
    protected function servedAsset(string $token, ?int $assetId): Asset|Response
    {
        $share = $this->gate()->activeShare($token);

        if (! $share || ($assetId === null ? $share->share_type !== Share::TYPE_ASSET : ! $share->isContainer())) {
            return $this->renderExpiredOrNotFound($token);
        }

        if ($assetId !== null) {
            return $this->resolveMemberAsset($share, $assetId) ?? $this->renderNotFound();
        }

        $asset = $this->linkedAsset($share);

        if (! $asset) {
            return $this->renderNotFound();
        }

        if ($status = $this->unavailableStatus($asset)) {
            return $status === 410 ? $this->renderGone(share: $share, asset: $asset) : $this->renderNotFound();
        }

        return $asset;
    }

    /** P2 S5b: the one way from a URL token to its link here, with the creator-access option applied. */
    protected function gate(): ShareGate
    {
        return app(ShareGate::class);
    }
}

<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\AdminApi\Http\Middleware\ScopeMiddleware;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\AnalyticsActor;
use Mothership\DAM\Analytics\UploadEvents;
use Mothership\DAM\ApiDataSource\AssetDataSource;
use Mothership\DAM\Exceptions\AssetDeletionException;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Jobs\BuildWebPageBundle;
use Mothership\DAM\Jobs\GenerateDngPreview;
use Mothership\DAM\Jobs\GenerateGameAssetThumbnail;
use Mothership\DAM\Jobs\GeneratePdfThumbnail;
use Mothership\DAM\Jobs\GenerateVideoThumbnail;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Repositories\AssetPropertyRepository;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Repositories\AssetTagRepository;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetAvailability;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AssetRename;
use Mothership\DAM\Services\AssetRenameException;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Duplicates\ExactDuplicateNotices;
use Mothership\DAM\Services\Duplicates\StackInstead;
use Mothership\DAM\Services\Duplicates\UploadReceipt;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\MetadataFields\FieldValueValidator;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Similarity\SimilarImages;
use Mothership\DAM\Services\Tags\AssetTagWriter;
use Mothership\DAM\Services\VideoProxyStatus;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Support\PreviewKinds;
use Mothership\DAM\Traits\AssetAccessControl;
use Mothership\DAM\Traits\Directory as DirectoryTrait;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AssetController extends Controller
{
    use AssetAccessControl;
    use DirectoryTrait;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetTagRepository $assetTagRepository,
        protected AssetPropertyRepository $assetPropertyRepository,
        protected AssetUploadPublisher $uploadPublisher,
        protected DirectoryRepository $directoryRepository,
        protected MetadataExtractionService $metadataExtractionService,
        protected AssetDeletionService $assetDeletionService,
    ) {}

    public function index(): JsonResponse
    {
        return app(AssetDataSource::class)->toJson();
    }

    public function downloadAndConvertFiles(Request $request)
    {
        $imageUrls = $request->input('files');
        if (empty($imageUrls) || ! is_array($imageUrls)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.invalid-file-format-or-not-provided'),
            ], 422);
        }

        $newImageUrlString = $imageUrls[0];
        $newImageUrl = explode(',', $newImageUrlString);
        $newImageUrl = array_map(function ($url) {
            return trim($url, ' "');
        }, $newImageUrl);

        $files = [];
        $errors = [];

        foreach ($newImageUrl as $url) {
            try {
                if (! $this->isSafeRemoteUrl($url)) {
                    $errors[] = "Blocked URL: $url";

                    continue;
                }

                $path = parse_url($url, PHP_URL_PATH);
                $fileName = basename($path);

                $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                if (! $extension) {
                    $headResponse = Http::withOptions(['allow_redirects' => false])->head($url);
                    $contentType = $headResponse->header('Content-Type');
                    $extension = match ($contentType) {
                        'image/jpeg'      => 'jpg',
                        'image/png'       => 'png',
                        'application/pdf' => 'pdf',
                        'video/mp4'       => 'mp4',
                        default           => 'bin',
                    };
                    $fileName .= '.'.$extension;
                }

                $tempPath = sys_get_temp_dir().'/'.uniqid().'_'.$fileName;

                $response = Http::sink($tempPath)->withOptions(['allow_redirects' => false])->get($url);

                if ($response->failed() || ! file_exists($tempPath) || filesize($tempPath) === 0) {
                    $errors[] = "Failed to download: $url";

                    continue;
                }

                $mimeType = mime_content_type($tempPath);

                $uploadedFile = new UploadedFile(
                    $tempPath,
                    $fileName,
                    $mimeType,
                    UPLOAD_ERR_OK,
                    true
                );
                $files[] = $uploadedFile;
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (! empty($errors)) {
            return response()->json([
                'success'         => false,
                'message'         => trans('dam::app.admin.dam.asset.datagrid.file-process-failed'),
                'errors'          => $errors,
                'files_processed' => count($files),
            ], 422);
        }

        return $files;
    }

    private function isSafeRemoteUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = trim($parts['host'], '[]');

        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
                if (! empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }

            if (empty($ips)) {
                $ips = gethostbynamel($host) ?: [];
            }
        }

        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    public function upload(Request $request): JsonResponse
    {
        // SETUP-07: PHP kept only the first max_file_uploads files of this request; never report a partial batch as done.
        if ($dropped = AssetHelper::droppedFileUploadsMessage()) {
            return response()->json(['success' => false, 'message' => $dropped], 422);
        }

        set_time_limit(0);
        ignore_user_abort(true);

        $request->validate([
            'directory_id' => ['required', new VisibleDirectory],
        ]);

        $maxKb = AssetHelper::getMaxUploadSizeKb();
        $sizeMessage = trans('dam::app.admin.dam.asset.datagrid.file-too-large', [
            'size' => $this->humanReadableSize($maxKb),
        ]);

        $files = [];

        if ($request->has('files') && ! $request->hasFile('files')) {
            $files = $this->downloadAndConvertFiles($request);
            if ($files instanceof JsonResponse) {
                return $files;
            }
        } else {
            $request->validate([
                'files'   => 'required|array',
                'files.*' => 'file|max:'.$maxKb,
            ], [
                'files.*.max'      => $sizeMessage,
                'files.*.uploaded' => $sizeMessage,
                'files.*.file'     => $sizeMessage,
            ]);
            $files = $request->file('files');
        }

        $directoryId = $request->get('directory_id');

        $service = app(DirectoryPermissionService::class);
        if (! $service->canAccess((int) $directoryId)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.permissions.unauthorized'),
            ], 403);
        }

        $directory = $this->directoryRepository->find($directoryId);
        $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());
        $disk = Directory::getAssetDisk();

        $uploadFiles = [];
        $assetIds = [];
        $errors = [];
        $receipts = [];
        $actor = self::apiActor();
        /** @var list<UploadEvents> $publishedUploads published files, settled once they are filed in their folder */
        $publishedUploads = [];

        foreach ($files as $file) {
            if (! ($file instanceof UploadedFile)) {
                $errors[] = trans('dam::app.admin.dam.asset.datagrid.invalid-file');

                continue;
            }

            // P1b: each file is one upload with one attempt and one terminal outcome.
            $uploadEvents = UploadEvents::start(path: 'api', directoryId: (int) $directoryId, actor: $actor);
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();

            if (AssetHelper::isForbiddenFile($extension, $mimeType, $file->getClientOriginalName(), $file->getRealPath())) {
                $errors[] = trans('dam::app.admin.dam.asset.datagrid.file-forbidden-type').': '.$file->getClientOriginalName();
                $uploadEvents->failed(errorCode: 'forbidden_type');

                continue;
            }

            // Folders are metadata (ADR 0009): the one storage write is staging the upload for its blob.
            if (! app(ContentStore::class)->canStage($disk)) {
                $errors[] = trans('dam::app.admin.dam.index.directory.not-writable', [
                    'actionType' => 'write',
                    'type'       => 'directory',
                    'path'       => ContentStore::STAGING,
                ]);
                $uploadEvents->failed(errorCode: 'not_writable');
                break;
            }

            try {
                $originalName = $file->getClientOriginalName();
                $fileType = AssetHelper::getFileType($file);

                // The bytes go straight into their blob; the Library path below names no file.
                $object = $this->uploadPublisher->ingestUploadedFile(file: $file, disk: $disk, mimeType: $mimeType);

                $localFilePath = $file->getRealPath();
                $metaData = $this->metadataExtractionService->extractMetadata($localFilePath, disk: 'local', originalFileName: $originalName);

                // A concurrent upload can take the free name first; the publisher then asks for the next one.
                $asset = $this->uploadPublisher->createUnderFreeName(
                    directoryPath: $directoryPath,
                    fileName: $originalName,
                    create: function (string $fileName) use ($fileType, $object, $mimeType, $extension, $directoryPath, $metaData): Asset {
                        $asset = (new Asset([
                            'file_name' => $fileName,
                            'file_type' => $fileType,
                            'file_size' => (int) $object->size,
                            'mime_type' => $mimeType,
                            'extension' => $extension,
                            'path'      => $directoryPath.'/'.$fileName,
                            'meta_data' => $metaData,
                        ]))->withGraphContent($object);
                        $asset->save();

                        return $asset;
                    },
                );

                $this->attachAudioCoverArt($asset, $localFilePath, $mimeType, $metaData, $disk);

                if ($asset->file_type === 'web') {
                    // This route never runs ProcessAssetUpload, so the bundle is dispatched here
                    // or the page would stay unservable (ADR 0007).
                    BuildWebPageBundle::dispatch($asset->id, WebPageBundler::versionNumber($asset))->afterCommit();
                } elseif ($asset->file_type === 'video') {
                    GenerateVideoThumbnail::dispatch($asset->id)->afterCommit();
                } elseif (AdobeFormats::isPdfRenderable($asset->extension, $asset->mime_type)) {
                    GeneratePdfThumbnail::dispatch($asset->id)->afterCommit();
                }
                if (strtolower((string) $asset->extension) === 'dng') {
                    GenerateDngPreview::dispatch($asset->id)->afterCommit();
                }
                if (PreviewKinds::hasEmbeddedThumbnail($asset->extension)) {
                    GenerateGameAssetThumbnail::dispatch($asset->id)->afterCommit();
                }

                $assetIds[] = $asset->id;
                $uploadFiles[] = $asset;
                // This route never overwrites: a same name gets the next free name, so every file is a new entry.
                $receipts[] = new UploadReceipt(assetId: (int) $asset->id, sha256: (string) $object->sha256, outcome: UploadReceipt::CREATED);
                $publishedUploads[] = $uploadEvents;
            } catch (\Exception $e) {
                $errors[] = trans('dam::app.admin.dam.asset.datagrid.file-upload-failed').': '.$file->getClientOriginalName().' : '.$e->getMessage();
                $uploadEvents->failed(errorCode: 'publish_failed');
            }
        }

        try {
            if ($request->has('directory_id')) {
                $this->mappedWithDirectory($assetIds, $request->get('directory_id'));
            }
        } catch (\Throwable $e) {
            foreach ($publishedUploads as $published) {
                $published->failed(errorCode: 'publish_failed');
            }

            throw $e;
        }

        foreach ($publishedUploads as $published) {
            $published->succeeded();
        }

        $response = [
            'success'    => count($errors) === 0,
            'files'      => $uploadFiles,
            'message'    => count($uploadFiles) > 1
                ? trans('dam::app.admin.dam.asset.datagrid.files-upload-success')
                : trans('dam::app.admin.dam.asset.datagrid.file-upload-success'),
            // P3b-2 (D16 exact): the same notices the Library shows, for files the key's owner can already see.
            'duplicates' => app(ExactDuplicateNotices::class)->notices(
                receipts: $receipts,
                context: AccessContext::api(AccessContext::PURPOSE_LIST),
                canStackInstead: app(ScopeMiddleware::class)->hasPermission(StackInstead::API_SCOPE),
            ),
            // P5c (D16): near-duplicate hints, pairs the key's owner can see both sides of; always present.
            'similar'    => auth('api')->user() instanceof Admin
                ? app(SimilarImages::class)->hints(receipts: $receipts, viewer: auth('api')->user(), context: AccessContext::api(AccessContext::PURPOSE_LIST))
                : [],
        ];

        if (! empty($errors)) {
            $response['errors'] = $errors;
            $response['message'] = trans('dam::app.admin.dam.asset.datagrid.files-upload-failed');
        }

        return response()->json($response, count($errors) === 0 ? 201 : 422);
    }

    public function reUpload(Request $request)
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $maxKb = AssetHelper::getMaxUploadSizeKb();
        $sizeMessage = trans('dam::app.admin.dam.asset.datagrid.file-too-large', [
            'size' => $this->humanReadableSize($maxKb),
        ]);

        $request->validate([
            'file'     => 'required|file|max:'.$maxKb,
            // Not `exists`: a missing id must answer exactly like a hidden one (the 404 below).
            'asset_id' => 'required|integer',
        ], [
            'file.max'      => $sizeMessage,
            'file.uploaded' => $sizeMessage,
            'file.file'     => $sizeMessage,
        ]);

        $file = $request->file('file');
        $assetId = $request->get('asset_id');
        $asset = $this->assetRepository->find($assetId);
        // Hidden answers like missing, so an id probe learns nothing.
        if (! $asset || ! $this->damCanAccessAsset((int) $assetId)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        // Replacing the bytes of someone else's file is changing it: own files only for a restricted role.
        if ($refusal = app(RoleBuilder::class)->refusal($asset)) {
            return response()->json(['success' => false, 'message' => $refusal], 403);
        }

        $directoryId = $asset->directories()->first()->id ?? null;
        $directory = $this->directoryRepository->find($directoryId);
        $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());
        // P1b: the replacement file is one upload with one attempt and one terminal outcome.
        $uploadEvents = UploadEvents::start(path: 'api', directoryId: $directoryId === null ? null : (int) $directoryId, actor: self::apiActor());

        // Folders are metadata (ADR 0009): the one storage write is staging the upload for its blob.
        if (! app(ContentStore::class)->canStage(Directory::getAssetDisk())) {
            $uploadEvents->failed(errorCode: 'not_writable');

            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.index.directory.not-writable', [
                    'type'       => 'file',
                    'actionType' => 'create',
                    'path'       => ContentStore::STAGING,
                ]),
            ], 403);
        }

        try {
            if ($file instanceof UploadedFile) {
                $extension = strtolower($file->getClientOriginalExtension());
                $mimeType = $file->getMimeType();

                if (AssetHelper::isForbiddenFile($extension, $mimeType, $file->getClientOriginalName(), $file->getRealPath())) {
                    $uploadEvents->failed(errorCode: 'forbidden_type');

                    return response()->json([
                        'success' => false,
                        'message' => trans('dam::app.admin.dam.index.directory.not-allowed'),
                    ], 400);
                }

                $disk = Directory::getAssetDisk();
                $originalName = $file->getClientOriginalName();
                // The asset being replaced keeps its own name (NEW-A1); only another asset at that name earns a suffix.
                $uniqueFileName = LibraryPaths::uniqueFileName(directoryPath: $directoryPath, fileName: $originalName, ignoreAssetId: (int) $asset->id);

                $localFilePath = $file->getRealPath();
                $metaData = $this->metadataExtractionService->extractMetadata($localFilePath, disk: 'local', originalFileName: $originalName);

                if (str_starts_with($mimeType ?? '', 'audio/') && $localFilePath && file_exists($localFilePath)) {
                    $coverData = $this->metadataExtractionService->extractCoverArtData($localFilePath);
                    if ($coverData) {
                        $coverPath = $this->metadataExtractionService->storeCoverArt($coverData, $asset->id, $disk);
                        if ($coverPath) {
                            $metaData = array_merge($metaData, ['cover_art_path' => $coverPath]);
                        }
                    }
                }

                $fileType = AssetHelper::getFileType($file);
                // The new bytes go straight into their blob and the observer versions the asset (ADR 0009).
                // Nothing is deleted at the old Library path: a pre-slice-3 file there waits for the slice-4 reclaim.
                $object = $this->uploadPublisher->ingestUploadedFile(file: $file, disk: $disk, mimeType: $mimeType);

                $asset->withGraphContent($object)->update([
                    'file_name' => $uniqueFileName,
                    'file_type' => $fileType,
                    'file_size' => (int) $object->size,
                    'mime_type' => $mimeType,
                    'extension' => $file->getClientOriginalExtension(),
                    'path'      => $directoryPath.'/'.$uniqueFileName,
                    'meta_data' => $metaData,
                ]);

                // A re-upload makes a new version, so the page is served from a new bundle (ADR 0007).
                $reUploaded = $asset->fresh();

                if ($reUploaded && $reUploaded->file_type === 'web') {
                    BuildWebPageBundle::dispatch(
                        $asset->id,
                        WebPageBundler::versionNumber($reUploaded),
                    )->afterCommit();
                }
            }
        } catch (\Throwable $e) {
            $uploadEvents->failed(errorCode: 'publish_failed');

            throw $e;
        }
        $uploadEvents->succeeded();

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.edit.file-re-upload-success'),
            'file'    => $asset,
        ], 201);
    }

    /**
     * The key's owner. An API client is a script by design, so its user agent is no bot signal; a DNT or GPC header
     * and the owner's opt-out still send it to the count-only path. Never throws: a failed lookup counts as the
     * system rather than failing the upload.
     */
    private static function apiActor(): AnalyticsActor
    {
        try {
            $admin = auth('api')->user();

            return $admin instanceof Admin
                ? app(ActorResolver::class)->forAdmin(admin: $admin, request: null)
                    ->withClientSignals(privacySignal: ActorResolver::privacySignal(request: request()), suspectedBot: false)
                : AnalyticsActor::system();
        } catch (\Throwable $error) {
            report($error);

            return AnalyticsActor::system();
        }
    }

    private function attachAudioCoverArt(Asset $asset, ?string $localFilePath, ?string $mimeType, array $metaData, string $disk): void
    {
        if (! str_starts_with($mimeType ?? '', 'audio/')) {
            return;
        }

        if (! $localFilePath || ! file_exists($localFilePath)) {
            return;
        }

        $coverData = $this->metadataExtractionService->extractCoverArtData($localFilePath);
        if (! $coverData) {
            return;
        }

        $coverPath = $this->metadataExtractionService->storeCoverArt($coverData, $asset->id, $disk);
        if (! $coverPath) {
            return;
        }

        $asset->update(['meta_data' => array_merge($metaData, ['cover_art_path' => $coverPath])]);
    }

    protected function humanReadableSize(int $kilobytes): string
    {
        if ($kilobytes >= 1024 * 1024) {
            return round($kilobytes / 1024 / 1024, 2).' GB';
        }

        if ($kilobytes >= 1024) {
            return round($kilobytes / 1024, 2).' MB';
        }

        return $kilobytes.' KB';
    }

    public function show(int $id): JsonResponse
    {
        $asset = $this->assetRepository->find($id);
        $disk = Directory::getAssetDisk();

        // Hidden answers like missing, so an id probe learns nothing.
        if (! $asset || ! $this->damCanAccessAsset((int) $id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        $asset->previewPath = AssetHelper::getPreviewUrl(
            $asset->path,
            $asset->file_size
        );

        if ($asset->file_type === 'image') {
            $metaData = $this->getMetadata($asset->path, $disk);

            if ($metaData['success']) {
                if (isset($metaData['data']['UndefinedTag:0xEA1C'])) {
                    unset($metaData['data']['UndefinedTag:0xEA1C']);
                }

                $asset->embeddedMetaInfo = $metaData['data'] ?? [];
            }
        }

        $asset->resources = $asset->resources()->get();

        $asset->comments = $asset->comments()->orderBy('created_at', 'desc')->get();

        $tags = $this->assetTagRepository->getTagsByAssetId($id);

        $properties = $this->assetPropertyRepository->where('dam_asset_id', $id)->get();
        $fields = app(FieldValues::class)->forAsset((int) $asset->id);

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.datagrid.show-success'),
            'data'    => [
                'asset'    => $asset,
                'tags'     => $tags,
                'property' => $properties,
                // P4a (D9): typed values by field code, and the required fields still empty.
                'fields'           => (object) $fields['values'],
                'missing_required' => $fields['missing_required'],
                // P6 D26: the 1080p preview proxy's status (ready, queued, processing, failed, missing, not_needed).
                'video_proxy' => app(VideoProxyStatus::class)->for($asset),
            ],
        ], 200);
    }

    public function metadata(int $id): JsonResponse
    {
        $asset = $this->assetRepository->find($id);

        // Hidden answers like missing, so an id probe learns nothing.
        if (! $asset || ! $this->damCanAccessAsset((int) $id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        $metaData = $asset->meta_data ?? [];

        if (is_string($metaData)) {
            $decoded = json_decode($metaData, true);
            $metaData = is_array($decoded) ? $decoded : [];
        }

        if (empty($metaData) && $asset->file_type === 'image') {
            $result = $this->getMetadata($asset->path, Directory::getAssetDisk());

            if (! empty($result['success'])) {
                if (isset($result['data']['UndefinedTag:0xEA1C'])) {
                    unset($result['data']['UndefinedTag:0xEA1C']);
                }

                $metaData = $result['data'] ?? [];
            }
        }

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.datagrid.show-success'),
            'data'    => [
                'asset_id'  => $asset->id,
                'file_name' => $asset->file_name,
                'file_type' => $asset->file_type,
                'mime_type' => $asset->mime_type,
                'meta_data' => $metaData,
            ],
        ], 200);
    }

    public function edit(int $id): JsonResponse
    {
        $asset = $this->assetRepository->find($id);
        $disk = Directory::getAssetDisk();

        // Hidden answers like missing, so an id probe learns nothing.
        if (! $asset || ! $this->damCanAccessAsset((int) $id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        $asset->previewPath = AssetHelper::getPreviewUrl(
            $asset->path,
            1356
        );

        if ($asset->file_type === 'image') {
            $metaData = $this->getMetadata($asset->path, $disk);

            if ($metaData['success']) {

                if (isset($metaData['data']['UndefinedTag:0xEA1C'])) {
                    unset($metaData['data']['UndefinedTag:0xEA1C']);
                }

                $asset->embeddedMetaInfo = $metaData['data'] ?? [];
            }
        }

        $asset->comments = $asset->comments()->orderBy('created_at', 'desc')->get();

        $tags = $this->assetTagRepository->all();

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.datagrid.edit-success'),
            'data'    => [
                'asset'    => $asset,
                'comments' => $asset->comments,
                'tags'     => $tags,
            ],
        ], 200);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $asset = Asset::find($id);

        // Hidden answers like missing, so an id probe learns nothing.
        if (! $asset || ! $this->damCanAccessAsset((int) $id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-update'),
            ], 404);
        }

        // extension, mime_type, file_size and file_type describe the stored bytes: they change only with the bytes
        // (upload, re-upload) and are ignored here, as on the details page. A file_name is a rename under the
        // Library's own rule (AssetRename): the extension stays and the Library path follows (UX eval R5, REST).
        $request->validate([
            'file_name' => 'sometimes|'.AssetRename::FILE_NAME_RULE,
            'tags'      => 'array',
            'fields'    => 'sometimes|array',
        ]);

        // Fields, tags and availability are the details of the file: a role limited to its own items edits them only on
        // files it added (the shared writers ask again), and is told in plain words before anything is validated.
        if ($request->hasAny(['fields', 'tags', ...AssetAvailability::FIELDS]) && ($refusal = app(RoleBuilder::class)->refusal($asset, 'own-only-details'))) {
            return response()->json(['success' => false, 'message' => $refusal], 403);
        }

        // P4a (D9): typed field values are checked before anything is written, so a bad value changes nothing.
        $fieldValues = $request->has('fields')
            ? app(FieldValueValidator::class)->validate(values: (array) $request->input('fields'))
            : [];

        // P2 S3: availability and its window, writable like the other fields under this route's
        // api.dam.assets.update scope, the REST form of the editor's permission.
        $availability = array_intersect_key($request->all(), array_flip(AssetAvailability::FIELDS)) === []
            ? []
            : AssetAvailability::validate($request, $asset, partial: true);

        if ($request->has('file_name')) {
            if ($refusal = app(RoleBuilder::class)->refusal($asset)) {
                return response()->json(['success' => false, 'message' => $refusal], 403);
            }

            try {
                app(AssetRename::class)->rename(asset: $asset, fileName: (string) $request->input('file_name'));
            } catch (AssetRenameException $refused) {
                throw ValidationException::withMessages(['file_name' => $refused->reason === AssetRenameException::NAME_TAKEN
                    ? trans('dam::app.admin.dam.index.directory.asset-name-already-exist', ['asset_name' => $refused->conflictingName])
                    : trans('dam::app.admin.dam.index.directory.asset-name-conflict-in-the-same-directory')]);
            }
        }

        $asset->update($availability);
        app(FieldValues::class)->write(asset: $asset, values: $fieldValues, source: 'api');

        if ($request->has('tags')) {
            $invalidTags = array_diff($request->input('tags'), Tag::pluck('id')->toArray());

            if (! empty($invalidTags)) {
                return response()->json([
                    'success' => false,
                    'message' => trans('dam::app.admin.dam.asset.tags.not-found'),
                ], 400);
            }

            // Tags that go are recorded as removals and tags that arrive clear them, as on the asset page (P4 D8).
            app(AssetTagWriter::class)->sync(asset: $asset, tagIds: array_map('intval', (array) $request->input('tags')), actorId: auth()->id());
        }

        return response()->json([
            'success' => true,
            'data'    => $asset,
            'fields'  => (object) app(FieldValues::class)->forAsset((int) $asset->id)['values'],
            'message' => trans('dam::app.admin.dam.asset.datagrid.update-success'),
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $request->validate(['force' => ['sometimes', 'boolean']]);

        $asset = Asset::find($id);

        // Hidden answers like missing, so an id probe learns nothing.
        if (! $asset || ! $this->damCanAccessAsset((int) $id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-destroy'),
            ], 404);
        }

        // The same own-items gate the Library's delete uses: a role limited to what it created cannot trash others' files.
        if (! app(RoleBuilder::class)->canDelete($asset)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.permissions.own-only-delete'),
            ], 403);
        }

        if ($asset->resources()->exists()) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.delete-failed-due-to-attached-resources', ['assetNames' => $asset->file_name]),
            ], 404);
        }

        try {
            // To the trash (P2 S4): restorable for 30 days from the Trash page.
            $analysis = app(AssetTrashService::class)->trash(asset: $asset, force: $request->boolean('force'));
        } catch (AssetDeletionException $exception) {
            return response()->json([
                'success'  => false,
                'message'  => trans('dam::app.admin.dam.asset.delete-blocked'),
                'analysis' => $exception->analysis(),
            ], 422);
        }

        return response()->json([
            'success'  => true,
            'message'  => trans('dam::app.admin.dam.asset.delete-success'),
            'analysis' => $analysis,
        ]);
    }

    /**
     * The signed URL the REST download hands out. It names the admin it was issued to (`by`, covered by the
     * signature), and access is decided again on every use, not only when it was signed: a file that has since
     * become a draft, left its window or been hidden from that admin answers exactly like a missing one (P2 S3
     * review). A URL signed without `by` names nobody, so the file must be out for everyone.
     */
    public function signedUrl(Request $request, int $id)
    {
        $asset = Asset::find($id);

        if (! $asset || ! $this->deliverableNow(request: $request, asset: $asset)) {
            abort(404);
        }

        // P6 D26: a link issued for the web MP4 serves the proxy published when it is used; none by then, 404.
        if ($request->query('variant') === VideoProxyStatus::WEB_MP4_VARIANT) {
            $webMp4 = VideoProxyStatus::webMp4($asset);

            abort_if($webMp4 === null, 404);

            return app(ContentStore::class)->deliver(
                object: $webMp4['object'],
                filename: $webMp4['filename'],
                disposition: HeaderUtils::DISPOSITION_ATTACHMENT,
                mimeType: 'video/mp4',
                sameOrigin: true,
            );
        }

        try {
            $object = app(ContentStore::class)->current($asset);
        } catch (ContentUnavailable) {
            abort(404);
        }

        // Streamed through the app on every disk, never redirected to a blob URL: a fresh blob URL would outlive
        // this 10-minute link by its own lifetime (P2 S3 second re-review).
        return app(ContentStore::class)->deliver(
            object: $object,
            filename: $asset->file_name ?? basename($asset->path),
            disposition: HeaderUtils::DISPOSITION_ATTACHMENT,
            mimeType: $object->mime_type ?: ($asset->mime_type ?: null),
            sameOrigin: true,
        );
    }

    public function download(Request $request, int $id)
    {
        // Existence and visibility in one decision, so a missing, hidden, unavailable or trashed (P2 S4) id gets the
        // same 404 for every caller, a folder-bypass role included (P2 S3 re-review).
        $asset = app(AssetAccessDecision::class)
            ->for(AccessContext::api(AccessContext::PURPOSE_DOWNLOAD), $id)
            ->visibleOrFail();

        // P6 D26: `variant=web_mp4` hands out the published 1080p proxy instead of the original, when there is one.
        $webMp4 = $request->query('variant') === VideoProxyStatus::WEB_MP4_VARIANT;

        try {
            $hasBytes = $webMp4 ? VideoProxyStatus::webMp4($asset) !== null : (bool) app(ContentStore::class)->current($asset);
        } catch (ContentUnavailable) {
            $hasBytes = false;
        }

        if (! $hasBytes) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-or-no-file'),
            ], 404);
        }

        // Always the app's own signed route, on every disk: it re-checks access when used, so a link issued before
        // the file became unavailable stops working (P2 S3 review), and it streams the bytes itself, so nothing
        // outlives its 10 minutes. A direct blob URL handed out here could not be withdrawn.
        $downloadUrl = URL::temporarySignedRoute(
            'admin.api.dam.assets.private.download',
            now()->addMinutes(10),
            ['id' => $asset->id, 'by' => auth()->guard('api')->id() ?? auth()->guard('admin')->id()]
                + ($webMp4 ? ['variant' => VideoProxyStatus::WEB_MP4_VARIANT] : [])
        );

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.datagrid.download-link-ready'),
            'data'    => [
                'download_url' => $downloadUrl,
            ],
        ], 200);
    }

    /** Whether the signed download may deliver now: the issuer still sees the file, or with no issuer, everyone. */
    protected function deliverableNow(Request $request, Asset $asset): bool
    {
        $issuerId = $request->query('by');

        if ($issuerId === null) {
            return $asset->unavailableBecause() === null;
        }

        $issuer = is_numeric($issuerId) ? Admin::find((int) $issuerId) : null;

        return $issuer !== null && app(AssetAccessDecision::class)
            ->for(AccessContext::forAdmin(admin: $issuer, purpose: AccessContext::PURPOSE_DOWNLOAD, via: AccessContext::VIA_API), $asset)
            ->visible;
    }
}

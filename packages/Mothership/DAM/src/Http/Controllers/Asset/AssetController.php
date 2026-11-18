<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Admin\Http\Requests\MassDestroyRequest;
use Mothership\Admin\Http\Requests\MassUpdateRequest;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\UploadEvents;
use Mothership\DAM\DataGrids\Asset\AssetDataGrid;
use Mothership\DAM\Exceptions\AssetDeletionException;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Http\Controllers\Concerns\StreamsZipDownload;
use Mothership\DAM\Jobs\BuildWebPageBundle;
use Mothership\DAM\Jobs\GenerateDngPreview;
use Mothership\DAM\Jobs\GenerateGameAssetThumbnail;
use Mothership\DAM\Jobs\GeneratePdfThumbnail;
use Mothership\DAM\Jobs\GenerateVideoThumbnail;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\GenerationAsset;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\UploadBatch;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Processing\MediaTools;
use Mothership\DAM\Processing\RecipeFailed;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Repositories\AssetTagRepository;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Repositories\DirectoryRolePermissionRepository;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AssetNeighbours;
use Mothership\DAM\Services\AssetRelationshipService;
use Mothership\DAM\Services\AssetRename;
use Mothership\DAM\Services\AssetRenameException;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DetailLayoutService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\DisplayedAspectRatio;
use Mothership\DAM\Services\Duplicates\ExactDuplicateNotices;
use Mothership\DAM\Services\Duplicates\StackInstead;
use Mothership\DAM\Services\Duplicates\UploadReceipt;
use Mothership\DAM\Services\FolderAccessService;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\PanoramaPreviewService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Similarity\SimilarImages;
use Mothership\DAM\Services\StackMemberMetadata;
use Mothership\DAM\Services\StoredImageDimensions;
use Mothership\DAM\Services\VideoProxyStatus;
use Mothership\DAM\Services\WebPageBundler;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\CoverArt;
use Mothership\DAM\Support\ImageEditing;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Support\PreviewKinds;
use Mothership\DAM\Support\ReservedDirectoryNames;
use Mothership\DAM\Support\StackFileName;
use Mothership\DAM\Support\UploadRefusal;
use Mothership\DAM\Support\ZipEntryNames;
use Mothership\DAM\Traits\Directory as DirectoryTrait;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class AssetController extends Controller
{
    use DirectoryTrait;
    use StreamsZipDownload;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetTagRepository $assetTagRepository,
        protected DirectoryRepository $directoryRepository,
        protected MetadataExtractionService $metadataExtractionService,
        protected DirectoryPermissionService $permissionService,
        protected DirectoryRolePermissionRepository $permissionRepository,
        protected AssetDeletionService $assetDeletionService,
        protected PanoramaPreviewService $panoramaPreviewService,
        protected RoleBuilder $roleBuilder,
        protected AssetUploadPublisher $uploadPublisher,
        protected AssetNeighbours $assetNeighbours,
    ) {}

    /**
     * Panorama preview state plus every named action URL, so the edit view
     * and the previous/next-navigated show() payload can drive the same
     * component without duplicating route construction.
     */
    protected function panoramaPreviewPayload(Asset $asset, ?array $playbackSource = null, bool $playbackResolved = false): array
    {
        return $this->panoramaPreviewService->payload($asset, $playbackSource, $playbackResolved);
    }

    /** The raw `directory_id` request value AssetNeighbours expects: a numeric id string, the
     *  literal "all", or null — never pre-resolved here, so the three scopes stay distinct. */
    protected function directoryParam(Request $request): ?string
    {
        $value = $request->query('directory_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The seam's edit rule (P2 S2): under `lists` the file's own list decides (ADR 0010), the rule the Library lists
     * by; under `grants` the folder the asset was first filed in decides, as it always has for this controller.
     */
    protected function canActOnAsset(?Asset $asset): bool
    {
        return $asset !== null && app(AssetAccessDecision::class)->for(AccessContext::library(AccessContext::PURPOSE_EDIT), $asset)->allowed;
    }

    /**
     * The asset when it exists and the viewer may act on it, else null. Callers answer null with their
     * not-found response, so a hidden asset reads exactly like a missing one and an id probe learns nothing.
     */
    protected function visibleAsset(mixed $id): ?Asset
    {
        $asset = Asset::find($id);

        return $asset && $this->canActOnAsset($asset) ? $asset : null;
    }

    public function index()
    {
        if (request()->ajax()) {
            return app(AssetDataGrid::class)->toJson();
        }

        return view('dam::asset.index');
    }

    /**
     * The internal "Copy link" target: sends a viewer to the Library at the file's folder, where the page
     * opens its preview. Hidden answers like missing (404); nothing is written.
     */
    public function open(int $id)
    {
        $asset = $this->visibleAsset($id);

        abort_unless($asset, 404);

        // A folder the viewer cannot open is never named in the address: a per-file grant opens the file only.
        $directoryId = $asset->directories()->pluck('dam_directories.id')
            ->first(fn ($id) => $this->permissionService->canAccess((int) $id));

        return redirect()->route('admin.dam.assets.index', array_filter([
            'directory_id' => $directoryId ? (int) $directoryId : null,
            'open_asset'   => $asset->id,
        ]));
    }

    public function edit(int $id, Request $request)
    {
        $asset = $this->assetRepository->find($id);

        // Hidden answers like missing, as visibleAsset() does for the JSON actions.
        if (! $asset || ! $this->canActOnAsset($asset)) {
            abort(404);
        }

        DamEvents::emit(event: DamEvent::PreviewServed, subjectId: (int) $asset->id, surface: 'details');

        $asset->previewPath = AssetHelper::getPreviewUrl(
            $asset->path,
            1356
        );

        $asset->width = '';
        $asset->height = '';

        if ($asset->file_type === 'image') {
            $metaData = is_array($asset->meta_data) ? $asset->meta_data : [];

            $asset->width = $metaData['exif']['Width'] ?? $metaData['exif']['COMPUTED']['Width'] ?? '';
            $asset->height = $metaData['exif']['Height'] ?? $metaData['exif']['COMPUTED']['Height'] ?? '';
        }

        $asset->comments = $asset->comments()->orderBy('created_at', 'desc')->get();

        $tags = $this->assetTagRepository->all();

        $directory = $asset->directories()->first();
        $directoryAncestors = $directory
            ? $this->directoryRepository->getAncestorPath((int) $directory->id)
            : collect();

        // A Library click-through carries its exact scope explicitly: a numeric id for a
        // browsed folder, or the literal "all" for the Library's all/search scope. A direct
        // link/bookmark carries neither and AssetNeighbours falls back accordingly — see its
        // own docblock for the three distinct scopes and why they are never conflated.
        $navDirectoryParam = $this->directoryParam($request);

        $neighbours = $this->assetNeighbours->forAsset($asset, $navDirectoryParam);
        $asset->previousAssetId = $neighbours['previousId'];
        $asset->nextAssetId = $neighbours['nextId'];
        $assetPosition = $neighbours['position'];
        $assetTotal = $neighbours['total'];

        $bytes = (int) ($asset->file_size ?? 0);
        $fileSize = $bytes >= 1048576
            ? number_format($bytes / 1048576, 2).' MB'
            : ($bytes >= 1024 ? number_format($bytes / 1024, 1).' KB' : ($bytes > 0 ? $bytes.' B' : null));

        $technicalInfo = $this->buildAssetInfo($asset);
        $detailFields = app(DetailLayoutService::class)->fields(asset: $asset, info: $technicalInfo);

        $panoramaPreview = $this->panoramaPreviewPayload($asset);

        $generationPrompt = $this->generationPrompt($asset);
        $hasGenerationDetails = $generationPrompt !== null;

        // The Delete confirm says how many live links to this file stop opening while it is in Trash (UX eval
        // 2026-10-10, PERM-12): a count only, no link details.
        $liveShareLinks = Share::query()->active()->where('share_type', Share::TYPE_ASSET)->where('target_id', $asset->id)->count();

        // Why the details of this file are read-only for the signed-in person (null when they may edit them).
        $detailsNote = app(RoleBuilder::class)->refusal(model: $asset, reason: 'own-only-details');

        return view('dam::asset.edit', compact('detailsNote', 'asset', 'id', 'tags', 'assetPosition', 'assetTotal', 'fileSize', 'directoryAncestors', 'directory', 'technicalInfo', 'detailFields', 'panoramaPreview', 'hasGenerationDetails', 'generationPrompt', 'liveShareLinks'));
    }

    /**
     * Quick view of the recorded generation request for the Details pane.
     *
     * @return array{prompt:string,model:?string,url:string}|null
     */
    protected function generationPrompt(Asset $asset): ?array
    {
        if (! bouncer()->hasPermission('dam.asset.files.index')) {
            return null;
        }
        $generation = GenerationAsset::where('asset_id', $asset->id)->orderByDesc('updated_at')->orderByDesc('id')->first();
        if ($generation === null) {
            return null;
        }
        $manifest = is_array($generation->manifest) ? $generation->manifest : [];
        $prompt = collect(['original_prompt', 'enhanced_prompt', 'prompt'])
            ->map(fn ($key) => is_string($manifest[$key] ?? null) ? trim($manifest[$key]) : '')
            ->first(fn ($value) => $value !== '');

        return [
            'prompt' => $prompt ?? '',
            'model'  => is_string($manifest['model'] ?? null) ? $manifest['model'] : null,
            'url'    => route('admin.dam.assets.generation.view', $asset->id),
        ];
    }

    public function info(int $id): JsonResponse
    {
        abort_unless(
            bouncer()->hasPermission('dam.asset.view'),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );

        $asset = $this->visibleAsset($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-show'),
            ], 404);
        }

        $info = $this->buildAssetInfo($asset);
        $info['detail_fields'] = app(DetailLayoutService::class)->fields(asset: $asset, info: $info);
        unset($info['technical']);
        $info['ai'] = app(AiIndexStore::class)->infoFor(assetId: $asset->id, canReview: bouncer()->hasPermission('dam.asset.tag'));
        $info['ai_eligibility'] = app(AiEligibility::class)->forAsset($asset);
        // P4a (D9): the Details editor's typed fields, their values and the required ones still empty.
        $fields = app(FieldValues::class)->forAsset((int) $asset->id);
        $info['fields'] = $fields['fields'];
        // A typed field's value shows (and is edited) in Details, so Properties lists only the free-text ones.
        $info['properties'] = collect($info['properties'])->filter(fn ($property) => ! $property->field_id)
            ->map(fn ($property) => ['name' => $property->name, 'value' => $property->value])->values()->all();
        $info['missing_required'] = $fields['missing_required'];
        // Someone else's item reads as read-only to a role limited to its own files, with the reason, not a broken form.
        $detailsNote = app(RoleBuilder::class)->refusal(model: $asset, reason: 'own-only-details');
        $info['can_edit_fields'] = bouncer()->hasPermission('dam.asset.update') && $detailsNote === null;
        $info['details_note'] = $detailsNote;
        // A Blender or Unity asset's facts, which its first thumbnail request may only just have read.
        $info['game_asset'] = AssetHelper::gameAssetFacts($asset);

        return response()->json([
            'success' => true,
            ...$info,
        ], options: JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    protected function buildAssetInfo(Asset $asset, ?Directory $directory = null): array
    {
        $original = $asset->files()
            ->where('role', AssetFile::ROLE_ORIGINAL)
            ->with('fileObject')
            ->orderBy('sort')
            ->orderBy('id')
            ->first();
        $fileObject = $original?->fileObject;
        $technical = app(StoredImageDimensions::class)->ensure(
            object: $fileObject, fileType: (string) $asset->file_type, extension: (string) $asset->extension,
            // Legacy originals may still record a pre-migration path; the asset's own locator is current.
            fallback: ['disk' => Directory::getAssetDisk(), 'path' => (string) $asset->path],
        );
        $metaData = is_array($asset->meta_data) ? $asset->meta_data : [];
        $legacy = is_array($metaData['legacy'] ?? null) ? $metaData['legacy'] : [];

        $width = $this->firstInfoValue([
            data_get($technical, 'width'),
            data_get($technical, 'dimensions.width'),
            data_get($technical, 'image.width'),
            data_get($metaData, 'ImageWidth'),
            data_get($metaData, 'exif.Width'),
            data_get($metaData, 'exif.COMPUTED.Width'),
        ]);
        $height = $this->firstInfoValue([
            data_get($technical, 'height'),
            data_get($technical, 'dimensions.height'),
            data_get($technical, 'image.height'),
            data_get($metaData, 'ImageHeight'),
            data_get($metaData, 'exif.Height'),
            data_get($metaData, 'exif.COMPUTED.Height'),
        ]);

        if ((! $width || ! $height) && preg_match('/(\d+)\s*[x\x{00D7}]\s*(\d+)/iu', (string) data_get($technical, 'resolution'), $matches)) {
            $width ??= (int) $matches[1];
            $height ??= (int) $matches[2];
        }

        $durationSeconds = $this->firstInfoValue([
            data_get($technical, 'duration'),
            data_get($technical, 'duration_seconds'),
            data_get($technical, 'video.duration'),
            data_get($technical, 'audio.duration'),
            data_get($metaData, 'Duration'),
        ]);
        $durationSeconds = is_numeric($durationSeconds) ? (float) $durationSeconds : null;

        $codec = $this->firstInfoValue([
            data_get($technical, 'codec'),
            data_get($technical, 'codec_name'),
            data_get($technical, 'video_codec'),
            data_get($technical, 'audio_codec'),
            data_get($technical, 'video.codec'),
            data_get($technical, 'audio.codec'),
        ]);

        $createdBy = data_get($legacy, 'created_by.username');
        if (! $createdBy && is_scalar($legacy['created_by'] ?? null)) {
            $createdBy = (string) $legacy['created_by'];
        }

        $directory ??= $asset->directories()->first();
        $breadcrumb = $directory
            ? $this->directoryRepository->getAncestorPath((int) $directory->id)
                ->map(fn ($item) => ['id' => $item->id, 'name' => $item->name])
                ->values()
                ->all()
            : [];

        $fileSize = (int) ($fileObject?->size ?? $asset->file_size ?? 0);
        $humanFileSize = $this->formatBytes($fileSize);
        $mimeType = $fileObject?->mime_type ?: $asset->mime_type;
        $properties = $asset->properties()->orderBy('name')->orderBy('id')->get(['name', 'value', 'field_id']);

        return [
            'id'                         => $asset->id,
            'file_name'                  => $asset->file_name,
            'title'                      => $asset->title,
            'display_name'               => $asset->display_name,
            'file_type'                  => $asset->file_type,
            'type'                       => $asset->file_type,
            'asset_kind'                 => $asset->asset_kind,
            'kind'                       => $asset->asset_kind,
            'mime_type'                  => $mimeType,
            'extension'                  => $asset->extension,
            'adobe'                      => AdobeFormats::present(extension: $asset->extension, mime: $mimeType),
            'file_size'                  => $fileSize,
            'file_size_human'            => $humanFileSize,
            'size'                       => $humanFileSize,
            'dimensions'                 => $width && $height ? [
                'width'   => (int) $width,
                'height'  => (int) $height,
                'display' => sprintf('%d × %d px', $width, $height),
            ] : null,
            'aspect_ratio'               => app(DisplayedAspectRatio::class)->describe(
                fileType: (string) $asset->file_type, extension: (string) $asset->extension, technical: $technical, metadata: $metaData,
            ),
            'duration'                   => $durationSeconds !== null ? [
                'seconds' => $durationSeconds,
                'display' => $this->formatDuration($durationSeconds),
            ] : null,
            'codec'                       => $codec,
            'created_at'                  => $asset->created_at?->format('d M Y, H:i T'),
            'updated_at'                  => $asset->updated_at?->format('d M Y, H:i T'),
            'created_by'                  => $createdBy,
            'uploaded_by'                 => $asset->creator?->name,
            'legacy_creation_date'        => data_get($legacy, 'creation_date'),
            'folder_path'                 => collect($breadcrumb)->pluck('name')->implode(' / '),
            'directory_breadcrumb'        => $breadcrumb,
            'tags'                        => $asset->tags()->orderBy('name')->get(['dam_tags.id', 'dam_tags.name'])->values()->all(),
            'properties'                  => $properties->values()->all(),
            'technical'                   => $technical,
            'original'                    => $fileObject ? [
                'sha256'    => $fileObject->sha256,
                'size'      => $fileObject->size,
                'mime_type' => $fileObject->mime_type,
            ] : null,
        ];
    }

    protected function firstInfoValue(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function formatBytes(int $bytes): ?string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 2).' MB'
            : ($bytes >= 1024 ? number_format($bytes / 1024, 1).' KB' : ($bytes > 0 ? $bytes.' B' : null));
    }

    protected function formatDuration(float $seconds): string
    {
        $wholeSeconds = (int) round($seconds);
        $hours = intdiv($wholeSeconds, 3600);
        $minutes = intdiv($wholeSeconds % 3600, 60);
        $remainingSeconds = $wholeSeconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds)
            : sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    public function getMetadataById($id)
    {
        $asset = $this->visibleAsset($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-show'),
            ], 404);
        }

        if (request()->has('file')) {
            $fileId = filter_var(request()->query('file'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless($fileId !== false, 404);

            try {
                return response()->json(['success' => true] + app(StackMemberMetadata::class)->read(asset: $asset, fileId: $fileId));
            } catch (ModelNotFoundException $e) {
                throw $e;
            } catch (HttpExceptionInterface $e) {
                throw $e;
            } catch (ContentUnavailable $e) {
                report($e);

                return response()->json([
                    'success' => false,
                    'message' => trans('dam::app.admin.dam.asset.edit.image-source-not-readable'),
                ], 422);
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'success' => false,
                    'message' => trim(trans('dam::app.admin.dam.asset.edit.failed-to-read', ['exception' => ''])),
                ], 422);
            }
        }

        try {
            $metaData = [];

            if ($asset->meta_data) {
                $metaData = is_array($asset->meta_data)
                    ? $asset->meta_data
                    : json_decode($asset->meta_data, true);

                $metaData = $this->flattenExifMetadata($metaData);
            } else {
                // Read through the seam (ADR 0009): the asset's current bytes, never a Storage::exists()
                // check against the Library path, which reports missing on a local disk even when the
                // blob is intact.
                $disk = Directory::getAssetDisk();
                $contentStore = app(ContentStore::class);

                try {
                    $object = $contentStore->current($asset);
                } catch (ContentUnavailable) {
                    throw new \Exception(trans('dam::app.admin.dam.asset.edit.image-source-not-readable'));
                }

                $local = $contentStore->localPath($object, $asset->extension);

                try {
                    $metaData = $this->flattenExifMetadata(
                        $this->metadataExtractionService->extractMetadata($asset->path, $disk, $local->path)
                    );
                } finally {
                    $local->release();
                }

                if ($asset->file_type === 'image') {
                    unset($metaData['UndefinedTag:0xEA1C']);
                }
            }

            return response()->json([
                'success' => true,
                'data'    => $metaData,
            ]);
        } catch (\Exception $e) {
            report($e);

            return [
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.edit.failed-to-read', ['exception' => $e->getMessage()]),
            ];
        }
    }

    private function flattenExifMetadata(array $metaData): array
    {
        if (! isset($metaData['exif']) || ! is_array($metaData['exif'])) {
            return $metaData;
        }

        $flatExif = collect($metaData['exif'])
            ->partition(fn ($v) => ! is_array($v))
            ->pipe(function ($parts) {
                return $parts[0]->all() + $parts[1]->all();
            });

        unset($metaData['exif']);

        return array_merge($flatExif, $metaData);
    }

    public function upload(Request $request)
    {
        // SETUP-07: PHP kept only the first max_file_uploads files of this request; never report a partial batch as done.
        if ($dropped = AssetHelper::droppedFileUploadsMessage()) {
            return response()->json(['success' => false, 'message' => $dropped], 422);
        }

        set_time_limit(0);
        ignore_user_abort(true);

        $maxKb = AssetHelper::getMaxUploadSizeKb();
        $sizeMessage = trans('dam::app.admin.dam.asset.datagrid.file-too-large', [
            'size' => $this->humanReadableSize($maxKb),
        ]);

        $request->validate([
            'files'        => 'required|array',
            'files.*'      => 'file|max:'.$maxKb,
            'session_uuid' => 'nullable|uuid',
            'directory_id' => ['required', new VisibleDirectory],
        ], [
            'files.*.max'      => $sizeMessage,
            'files.*.uploaded' => $sizeMessage,
            'files.*.file'     => $sizeMessage,
        ]);

        $files = $request->file('files');
        $directoryId = $request->get('directory_id');

        if (! $this->permissionService->canAccess((int) $directoryId)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.permissions.unauthorized'),
            ], 403);
        }

        // Web Pages uploads (context=pages) take web pages only: a zip with no index.html would otherwise
        // be filed as a Library document the Web Pages list never shows. Refuse before anything is stored.
        if ($request->input('context') === 'pages') {
            foreach ((array) $files as $file) {
                if ($file instanceof UploadedFile && AssetHelper::getFileType($file) !== 'web') {
                    $message = trans('dam::app.admin.dam.web-pages.browser.not-web-refused', ['name' => $file->getClientOriginalName()]);

                    return response()->json(['success' => false, 'message' => $message, 'errors' => ['files' => [$message]]], 422);
                }
            }
        }

        $directory = $this->directoryRepository->find($directoryId);
        $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());

        $uploadFiles = [];
        $publishedNotes = [];
        $disk = Directory::getAssetDisk();
        $tracker = $this->resolveUploadTracker($request, (int) $directoryId);

        // Folders are metadata (ADR 0009): the one storage write is staging the upload for its blob.
        if (! app(ContentStore::class)->canStage($disk)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.index.directory.not-writable', [
                    'type'       => 'file',
                    'actionType' => 'create',
                    'path'       => ContentStore::STAGING,
                ]),
            ], 500);
        }

        $candidatePaths = collect($files)
            ->filter(fn ($file) => $file instanceof UploadedFile)
            ->map(fn (UploadedFile $file) => $directoryPath.'/'.$file->getClientOriginalName())
            ->all();
        $existingByPath = $candidatePaths
            ? Asset::whereIn('path', $candidatePaths)->get()->keyBy('path')
            : collect();

        // P1b: each file is one upload with one attempt and one terminal outcome.
        $uploadId = null;
        $errorCode = 'publish_failed';
        // A refusal is the uploader's to fix (422 with its reason); anything else is ours (500).
        $refusal = null;
        $refusalReason = null;
        // A closure, not an arrow function: an arrow function captures $uploadId by value when it is made (null), so
        // every event went out without an upload_id, failed validation and was never recorded.
        $uploadEvent = function (DamEvent $event, array $props = []) use (&$uploadId, $directoryId): void {
            DamEvents::emit(
                event: $event, subjectId: (int) $directoryId, surface: 'upload', businessId: $uploadId, attempt: '1',
                props: ['upload_id' => $uploadId, 'path' => 'direct'] + $props,
            );
        };

        try {
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $uploadId = substr(str_replace('-', '', (string) Str::uuid()), 0, 24);
                $errorCode = 'publish_failed';
                $uploadEvent(DamEvent::UploadAttempted, ['attempt' => 1]);

                $extension = strtolower($file->getClientOriginalExtension());
                $mimeType = $file->getMimeType();

                if (AssetHelper::isForbiddenFile($extension, $mimeType, $file->getClientOriginalName(), $file->getRealPath())) {
                    $errorCode = 'forbidden_type';
                    $refusalReason = AssetHelper::webBundleRefusalReason($extension, $file->getRealPath());
                    $refusal = $refusalReason
                        ? UploadRefusal::message($refusalReason)
                        : trans('dam::app.admin.dam.index.directory.not-allowed');

                    throw new \Exception($refusal);
                }

                $asset = $this->uploadPublisher->publishUploadedFile([
                    'directory'     => $directory,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $mimeType,
                    'extension'     => $extension,
                    'source'        => $file,
                    'disk'          => $disk,
                    'tracker'       => $tracker,
                    'existing_asset'=> $existingByPath->get($directoryPath.'/'.$file->getClientOriginalName()),
                ]);

                $uploadFiles[] = $asset;
                $publishedNotes[] = $asset->uploadReceipt === null ? null : ExactDuplicateNotices::publishedNote(
                    receipt: $asset->uploadReceipt,
                    originalName: $file->getClientOriginalName(),
                    publishedName: (string) $asset->file_name,
                );
                $uploadEvent(DamEvent::UploadSucceeded);
                $uploadId = null;
            }

            return response()->json([
                'success'    => true,
                'files'      => $uploadFiles,
                'message'    => count($uploadFiles) > 1
                    ? trans('dam::app.admin.dam.asset.datagrid.files-upload-success')
                    : trans('dam::app.admin.dam.asset.datagrid.file-upload-success'),
                // P3b-2: files already in the Library the uploader can see; always present, empty otherwise.
                'duplicates' => $this->duplicateNotices(ExactDuplicateNotices::receiptsOf($uploadFiles)),
                // P5c (D16): near-duplicate hints, pairs the uploader can see both sides of; always present.
                'similar'    => $this->similarHints(ExactDuplicateNotices::receiptsOf($uploadFiles)),
                // Per file, in `files` order: "Replaced x (new version)." or "Saved as x (1)…", null otherwise.
                'published_notes' => $publishedNotes,
            ], 201);
        } catch (ValidationException $e) {
            if ($uploadId !== null) {
                $uploadEvent(DamEvent::UploadFailed, ['error_code' => 'validation_failed']);
            }

            throw $e;
        } catch (\Exception $e) {
            if ($refusal === null) {
                report($e);
            }

            if ($uploadId !== null) {
                $uploadEvent(DamEvent::UploadFailed, ['error_code' => $errorCode]);
            }

            if ($refusal !== null) {
                return response()->json([
                    'success' => false,
                    'code'    => $errorCode,
                    'message' => $refusal,
                    // The same {code, message} the chunked status poll returns, so a client reads one shape.
                    'reason'  => UploadRefusal::payload($refusalReason) ?? ['code' => 'forbidden_type', 'message' => $refusal],
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.files-upload-failed'),
            ], 500);
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

    public function uploadFolder(Request $request): JsonResponse
    {
        // SETUP-07: PHP kept only the first max_file_uploads files of this request; never report a partial batch as done.
        if ($dropped = AssetHelper::droppedFileUploadsMessage()) {
            return response()->json(['success' => false, 'message' => $dropped], 422);
        }

        abort_unless(
            bouncer()->hasPermission('dam.asset.upload'),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );

        set_time_limit(0);
        ignore_user_abort(true);

        $maxKb = AssetHelper::getMaxUploadSizeKb();
        $sizeMessage = trans('dam::app.admin.dam.asset.datagrid.file-too-large', [
            'size' => $this->humanReadableSize($maxKb),
        ]);

        $request->validate([
            'files'           => 'required|array|min:1',
            'files.*'         => 'file|max:'.$maxKb,
            'session_uuid'    => 'nullable|uuid',
            'relative_paths'  => 'required|array|min:1',
            'relative_paths.*'=> 'string|max:500',
            'directory_id'    => ['required', new VisibleDirectory],
        ], [
            'files.*.max'      => $sizeMessage,
            'files.*.uploaded' => $sizeMessage,
            'files.*.file'     => $sizeMessage,
        ]);

        $files = $request->file('files');
        $relativePaths = $request->input('relative_paths', []);
        $directoryId = (int) $request->input('directory_id');
        $preserveRoot = (bool) $request->input('preserve_root', false);

        if (! $this->permissionService->canAccess($directoryId)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.permissions.unauthorized'),
            ], 403);
        }

        $disk = Directory::getAssetDisk();
        $uploadedAssets = [];
        $skippedCount = 0;
        $tracker = $this->resolveUploadTracker($request, $directoryId);
        // Folders are metadata (ADR 0009): the one storage write is staging each file for its blob.
        $canStage = app(ContentStore::class)->canStage($disk);

        $dirCache = [];

        $createdIds = [];

        $resolveOrCreatePath = function (int $rootId, array $segments) use (&$resolveOrCreatePath, &$dirCache, &$createdIds): int {
            if (empty($segments)) {
                return $rootId;
            }

            $segment = array_shift($segments);
            $cacheKey = $rootId.'/'.$segment;

            if (! isset($dirCache[$cacheKey])) {
                $existing = Directory::where('parent_id', $rootId)
                    ->where('name', $segment)
                    ->first();

                if ($existing) {
                    $dirCache[$cacheKey] = $existing->id;
                } else {
                    $newDir = $this->directoryRepository->create([
                        'name'      => $segment,
                        'parent_id' => $rootId,
                    ]);
                    $dirCache[$cacheKey] = $newDir->id;
                    $createdIds[] = $newDir->id;
                }
            }

            return $resolveOrCreatePath($dirCache[$cacheKey], $segments);
        };

        $assetsByDir = [];
        /** @var UploadEvents|null $uploadEvents the file in flight, failed if the batch throws */
        $uploadEvents = null;
        /** @var list<UploadEvents> $publishedUploads published files, settled once the batch is filed in its folders */
        $publishedUploads = [];

        try {
            $fileJobs = [];

            foreach ($files as $index => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $relativePath = $relativePaths[$index] ?? $file->getClientOriginalName();

                $parts = explode('/', ltrim(str_replace('\\', '/', $relativePath), '/'));
                array_pop($parts);
                if (! $preserveRoot && ! empty($parts)) {
                    array_shift($parts);
                }

                $hasBadSegment = false;
                $forbiddenRe = '/[\\\\\/\:\*\?\"\<\>\|]/u';

                foreach ($parts as $seg) {
                    if ($seg === '.' || $seg === '..' || mb_strlen($seg) > 255 || preg_match($forbiddenRe, $seg) || ReservedDirectoryNames::isReserved($seg)) {
                        $hasBadSegment = true;

                        break;
                    }
                }

                if ($hasBadSegment) {
                    $skippedCount++;
                    UploadEvents::start(path: 'folder', directoryId: $directoryId)->failed(errorCode: 'bad_path');

                    continue;
                }

                $extension = strtolower($file->getClientOriginalExtension());
                $mimeType = $file->getMimeType();

                if (AssetHelper::isForbiddenFile($extension, $mimeType, $file->getClientOriginalName(), $file->getRealPath())) {
                    $skippedCount++;
                    UploadEvents::start(path: 'folder', directoryId: $directoryId)->failed(errorCode: 'forbidden_type');

                    continue;
                }

                $targetDirId = $resolveOrCreatePath($directoryId, $parts);
                $targetDirectory = $this->directoryRepository->find($targetDirId);
                $targetDirPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $targetDirectory->generatePath());

                if (! $canStage) {
                    $skippedCount++;
                    UploadEvents::start(path: 'folder', directoryId: $targetDirId)->failed(errorCode: 'not_writable');

                    continue;
                }

                $originalName = $file->getClientOriginalName();

                $fileJobs[] = [
                    'file'            => $file,
                    'mimeType'        => $mimeType,
                    'targetDirId'     => $targetDirId,
                    'targetDirectory' => $targetDirectory,
                    'targetDirPath'   => $targetDirPath,
                    'originalName'    => $originalName,
                    'existingPath'    => $targetDirPath.'/'.$originalName,
                ];
            }

            $candidatePaths = array_column($fileJobs, 'existingPath');
            $existingByPath = $candidatePaths
                ? Asset::whereIn('path', $candidatePaths)->get()->keyBy('path')
                : collect();
            // P3b-2: each overwritten entry's current bytes, read once before any is replaced.
            $previousSha256 = app(ExactDuplicateNotices::class)->currentSha256($existingByPath->pluck('id')->map(fn ($id) => (int) $id)->all());
            $receipts = [];

            foreach ($fileJobs as $job) {
                $file = $job['file'];
                $mimeType = $job['mimeType'];
                $targetDirId = $job['targetDirId'];
                $targetDirectory = $job['targetDirectory'];
                $targetDirPath = $job['targetDirPath'];
                $originalName = $job['originalName'];
                $existingPath = $job['existingPath'];

                $existingAsset = $existingByPath->get($existingPath);
                // P1b: each file is one upload with one attempt and one terminal outcome.
                $uploadEvents = UploadEvents::start(path: 'folder', directoryId: $targetDirId);

                // A same-name file is replaced only by someone who may re-upload it; anyone else gets a new asset
                // under the next free name, as the single-file upload does (AssetUploadPublisher::mayReplace()).
                if ($existingAsset && ! $this->uploadPublisher->mayReplace(existing: $existingAsset, actorId: auth('admin')->id())) {
                    $existingAsset = null;
                }

                $fileType = AssetHelper::getFileType($file);
                // The bytes go straight into their blob; the Library path below names no file (ADR 0009).
                $object = $this->uploadPublisher->ingestUploadedFile(file: $file, disk: $disk, mimeType: $mimeType);

                $payload = fn (string $fileName): array => [
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'file_size' => (int) $object->size,
                    'mime_type' => $mimeType,
                    'extension' => $file->getClientOriginalExtension(),
                    'path'      => $targetDirPath.'/'.$fileName,
                ];

                // An overwrite is a new version of the same asset; a merge source is never overwritten (P3b-1).
                $asset = $existingAsset
                    ? $this->uploadPublisher->overwriteUnlessMerged(existing: $existingAsset, object: $object, attributes: $payload($originalName), actorId: auth('admin')->id())
                    : null;

                if ($asset !== null) {
                    $this->clearAssetCache($disk, $asset->id);
                } else {
                    // A refused overwrite publishes a new entry, so its receipt reads as created.
                    $existingAsset = null;
                    // A concurrent upload can take the free name first; the publisher then asks for the next one.
                    $asset = $this->uploadPublisher->createUnderFreeName(
                        directoryPath: $targetDirPath,
                        fileName: $originalName,
                        create: function (string $fileName) use ($payload, $object): Asset {
                            $asset = (new Asset($payload($fileName)))->withGraphContent($object);
                            $asset->save();

                            return $asset;
                        },
                    );
                    $assetsByDir[$targetDirId][] = $asset->id;
                }

                $this->queueAssetFinalisation($asset, $tracker);

                $receipts[] = new UploadReceipt(
                    assetId: (int) $asset->id,
                    sha256: (string) $object->sha256,
                    outcome: ExactDuplicateNotices::outcome(
                        replaced: $existingAsset,
                        previousSha256: $existingAsset ? ($previousSha256[(int) $existingAsset->id] ?? null) : null,
                        sha256: (string) $object->sha256,
                    ),
                );
                $uploadedAssets[] = $asset;
                $publishedUploads[] = $uploadEvents;
                $uploadEvents = null;
            }

            foreach ($assetsByDir as $dirId => $ids) {
                $this->mappedWithDirectory($ids, $dirId);
            }

            foreach ($createdIds as $id) {
                $this->autoGrantToCreator($id);
            }

            foreach ($publishedUploads as $published) {
                $published->succeeded();
            }

            $message = $skippedCount > 0
                ? trans_choice('dam::app.admin.dam.index.directory.folder-upload-partial', $skippedCount, ['count' => $skippedCount])
                : trans('dam::app.admin.dam.index.directory.folder-upload-success');

            return response()->json([
                'success'                => true,
                'files'                  => $uploadedAssets,
                'message'                => $message,
                'granted_directory_ids'  => $createdIds,
                // P3b-2: the batch's files already in the Library, in one aggregated list.
                'duplicates'             => $this->duplicateNotices($receipts),
                // P5c (D16): near-duplicate hints for the batch's new files.
                'similar'                => $this->similarHints($receipts),
            ], 201);
        } catch (\Exception $e) {
            report($e);
            foreach ([$uploadEvents, ...$publishedUploads] as $unsettled) {
                $unsettled?->failed(errorCode: 'publish_failed');
            }

            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.index.directory.creation-failed'),
            ], 500);
        }
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
            // Not `exists`: a missing id must answer exactly like a hidden one.
            'asset_id' => 'required|integer',
        ], [
            'file.max'      => $sizeMessage,
            'file.uploaded' => $sizeMessage,
            'file.file'     => $sizeMessage,
        ]);

        $file = $request->file('file');
        $assetId = $request->get('asset_id');
        $asset = $this->visibleAsset($assetId);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        // Replacing the bytes of someone else's file is changing it: own files only for a restricted role.
        if ($refused = $this->ownItemsRefusal($asset)) {
            return $refused;
        }

        $directoryId = $asset?->directories()?->get()[0]?->id;
        $directory = $this->directoryRepository->find($directoryId);
        $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());
        // P1b: the replacement file is one upload with one attempt and one terminal outcome.
        $uploadEvents = UploadEvents::start(path: 'reupload', directoryId: $directoryId === null ? null : (int) $directoryId);

        try {
            $refusal = $this->replaceUploadedFile(file: $file, asset: $asset, directoryPath: $directoryPath);
        } catch (\Throwable $e) {
            $uploadEvents->failed(errorCode: 'publish_failed');

            throw $e;
        }

        if ($refusal !== null) {
            $uploadEvents->failed(errorCode: 'forbidden_type');

            return response()->json([
                'success' => false,
                'message' => $refusal,
            ], 400);
        }

        $uploadEvents->succeeded();

        return response()->json([
            'success' => true,
            'file'    => $asset,
            'message' => trans('dam::app.admin.dam.asset.edit.file-re-upload-success'),
        ], 201);
    }

    /**
     * The bytes of a re-upload: the same asset gets a new version under its own name (NEW-A1, ADR 0009). Returns the
     * refusal message for a forbidden file, which stores nothing, and null once the new version is in place.
     */
    private function replaceUploadedFile(mixed $file, Asset $asset, string $directoryPath): ?string
    {
        if ($file instanceof UploadedFile) {
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();
            if (AssetHelper::isForbiddenFile($extension, $mimeType, $file->getClientOriginalName(), $file->getRealPath())) {
                return trans('dam::app.admin.dam.index.directory.not-allowed', ['fileName' => $file->getClientOriginalName()]);
            }

            $disk = Directory::getAssetDisk();
            $this->clearAssetCache($disk, $asset->id);

            $originalName = $file->getClientOriginalName();
            // The asset being replaced keeps its own name (NEW-A1); only another asset at that name earns a suffix.
            $uniqueFileName = LibraryPaths::uniqueFileName(directoryPath: $directoryPath, fileName: $originalName, ignoreAssetId: (int) $asset->id);

            // Folders are metadata (ADR 0009): the one storage write is staging the upload for its blob.
            if (! app(ContentStore::class)->canStage($disk)) {
                throw new \Exception(trans('dam::app.admin.dam.index.directory.not-writable', [
                    'type'       => 'file',
                    'actionType' => 'create',
                    'path'       => ContentStore::STAGING,
                ]));
            }

            $localFilePath = $file->getRealPath();
            $metaData = $this->metadataExtractionService->extractMetadata($localFilePath, disk: 'local', originalFileName: $originalName);

            if (str_starts_with($file->getMimeType() ?? '', 'audio/') && $localFilePath && file_exists($localFilePath)) {
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

            $this->dispatchThumbnailJob($asset->fresh());
        }

        return null;
    }

    /**
     * P3b-2 (D16 exact): the exact-duplicate notices for one request's uploads, as the session's admin sees the
     * Library. Stack instead is offered only to a role that may attach to an entry and trash one.
     *
     * @param  array<int, UploadReceipt>  $receipts
     */
    protected function duplicateNotices(array $receipts): array
    {
        return app(ExactDuplicateNotices::class)->notices(
            receipts: $receipts,
            context: AccessContext::library(AccessContext::PURPOSE_LIST),
            canStackInstead: StackInstead::allowedInLibrary(),
        );
    }

    /**
     * P5c (D16): near-duplicate hints for one request's uploads, as the session's admin sees the Library.
     *
     * @param  array<int, UploadReceipt>  $receipts
     */
    protected function similarHints(array $receipts): array
    {
        $viewer = auth('admin')->user();

        return $viewer instanceof Admin
            ? app(SimilarImages::class)->hints(receipts: $receipts, viewer: $viewer, context: AccessContext::library(AccessContext::PURPOSE_LIST))
            : [];
    }

    protected function resolveUploadTracker(Request $request, int $directoryId): ?UploadTracker
    {
        $sessionUuid = $request->input('session_uuid');

        if (! $sessionUuid) {
            return null;
        }

        abort_unless(auth('admin')->id() !== null, 403);

        $tracker = UploadTracker::firstOrCreate(['uuid' => $sessionUuid], [
            'user_id'      => auth('admin')->id(),
            'directory_id' => $directoryId,
            'state'        => UploadTracker::STATE_PROCESSING,
            'total_files'  => 0,
            'started_at'   => now(),
        ]);

        abort_unless(
            $tracker->user_id !== null
            && (int) $tracker->user_id === (int) auth('admin')->id()
            && $tracker->directory_id !== null
            && (int) $tracker->directory_id === $directoryId,
            404,
        );

        return $tracker->isActive() ? $tracker : null;
    }

    protected function queueAssetFinalisation(Asset $asset, ?UploadTracker $tracker): void
    {
        $batchId = null;

        if ($tracker) {
            $batchId = UploadBatch::create([
                'upload_tracker_id' => $tracker->id,
                'asset_id'          => $asset->id,
                'state'             => UploadBatch::STATE_PENDING,
            ])->id;
        }

        ProcessAssetUpload::dispatch($asset->id, $batchId);
    }

    protected function dispatchThumbnailJob(?Asset $asset): void
    {
        if (! $asset) {
            return;
        }

        if ($asset->file_type === 'video' || $asset->asset_kind === 'pano360') {
            GenerateVideoThumbnail::dispatch($asset->id)->afterCommit();

            return;
        }

        if (AdobeFormats::isPdfRenderable($asset->extension, $asset->mime_type)) {
            GeneratePdfThumbnail::dispatch($asset->id)->afterCommit();
        }
        if (strtolower((string) $asset->extension) === 'dng') {
            GenerateDngPreview::dispatch($asset->id)->afterCommit();
        }
        if (PreviewKinds::hasEmbeddedThumbnail($asset->extension)) {
            GenerateGameAssetThumbnail::dispatch($asset->id)->afterCommit();
        }
    }

    public function show($id, Request $request): JsonResponse
    {
        $asset = $this->visibleAsset($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-show'),
            ], 404);
        }

        DamEvents::emit(event: DamEvent::PreviewServed, subjectId: (int) $asset->id, surface: 'modal');

        // Playback, the panorama state and the video proxy status each read the asset's attachments through
        // playbackAttachments(); loaded once here they cost no further queries. Hidden so the payload keeps its shape.
        // Ordered by id like the queries this replaces (ContentStore::current() takes the first original in load
        // order; playbackAttachments() re-sorts by sort, id itself).
        $asset->load(['files' => fn ($files) => $files->orderBy('id'), 'files.fileObject'])->makeHidden('files');

        $asset->previewPath = AssetHelper::getPreviewUrl($asset->path, 1356);

        $width = '';
        $height = '';

        if ($asset->file_type === 'image') {
            $metaData = is_array($asset->meta_data) ? $asset->meta_data : [];
            $width = $metaData['exif']['Width'] ?? $metaData['exif']['COMPUTED']['Width'] ?? '';
            $height = $metaData['exif']['Height'] ?? $metaData['exif']['COMPUTED']['Height'] ?? '';
        }

        $playbackSource = $asset->playbackSource();
        $captionSource = $asset->captionSource();
        $mediaUrl = $playbackSource['url'] ?? AssetHelper::getPreviewUrl($asset->path);

        $placeholderSvg = match ($asset->file_type) {
            'video'    => asset('storage/dam/preview/video.svg'),
            'audio'    => asset('storage/dam/preview/audio.svg'),
            'document' => asset('storage/dam/preview/file.svg'),
            default    => asset('storage/dam/preview/unspecified.svg'),
        };

        $coverArtUrl = ($asset->file_type === 'audio' && ! empty($asset->meta_data['cover_art_path']))
            ? route('admin.dam.file.cover-art', $asset->id)
            : null;

        $typeColor = 'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300';

        $bytes = (int) ($asset->file_size ?? 0);
        $fileSize = $bytes >= 1048576
            ? number_format($bytes / 1048576, 2).' MB'
            : ($bytes >= 1024 ? number_format($bytes / 1024, 1).' KB' : ($bytes > 0 ? $bytes.' B' : null));

        $showDirectory = $asset->directories()->first();
        $showAncestors = $showDirectory
            ? $showDirectory->ancestorsAndSelfAndDefaultOrder($showDirectory->id)
            : collect();

        $neighbours = $this->assetNeighbours->forAsset($asset, $this->directoryParam($request));

        $tags = $asset->tags()->get(['dam_tags.id', 'dam_tags.name']);
        $technicalInfo = $this->buildAssetInfo($asset, $showDirectory instanceof Directory ? $showDirectory : null);
        // Counted once; the delete confirm and the strip both read it.
        $stackFileCount = $asset->stackFileCount();

        return response()->json([
            'success'               => true,
            // `adobe` is presentation only (never saved): which Adobe application the file belongs to.
            'asset'                 => tap($asset->append('display_name'), fn (Asset $shown) => $shown->setAttribute('adobe', AdobeFormats::present(extension: $shown->extension, mime: $shown->mime_type))),
            // The delete confirm names a stack's file count for the asset now on screen (G1-11).
            'stackFileCount'        => $stackFileCount,
            'assetPath'             => $asset->getPathWithOutFileSystemRoot() ?? '',
            'directoryBreadcrumb'   => $showAncestors->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->toArray(),
            'mediaUrl'              => $mediaUrl,
            'playbackSource'        => $playbackSource,
            'captionSource'         => $captionSource,
            'filesUrl'              => route('admin.dam.assets.edit', $id).'?files',
            'previewPath'           => $asset->previewPath,
            'placeholderSvg'        => $placeholderSvg,
            'coverArtUrl'           => $coverArtUrl,
            'modelThumbnailUrl'     => ModelThumbnailService::cardUrl($asset),
            'imageEditing'          => ImageEditing::previewFields($asset),
            'pdfThumbnailUrl'       => AssetHelper::pdfThumbnailUrl($asset),
            'gameAsset'             => AssetHelper::gameAssetPreview($asset),
            'typeColor'             => $typeColor,
            'fileSize'              => $fileSize,
            'width'                 => $width,
            'height'                => $height,
            'createdAtFormatted'    => $asset->created_at?->format('d M Y, H:i T'),
            'detailFields'          => app(DetailLayoutService::class)->fields(asset: $asset, info: $technicalInfo),
            'generationPrompt'      => $this->generationPrompt($asset),
            'updatedAtFormatted'    => $asset->updated_at?->format('d M Y, H:i T'),
            'downloadUrl'           => route('admin.dam.assets.download', $id),
            'downloadCompressedUrl' => route('admin.dam.assets.download_compressed', $id),
            // P6 D26: offered only while a published proxy is what plays (playbackSource() picks it first).
            'webMp4Url'             => ($playbackSource['is_proxy'] ?? false) ? route('admin.dam.assets.download', ['id' => $id, 'variant' => VideoProxyStatus::WEB_MP4_VARIANT]) : null,
            'videoProxy'            => $asset->file_type === 'video' ? $this->videoProxyPayload($asset) : null,
            'previousAssetId'       => $neighbours['previousId'],
            'nextAssetId'           => $neighbours['nextId'],
            'assetPosition'         => $neighbours['position'],
            'assetTotal'            => $neighbours['total'],
            'editUrl'               => route('admin.dam.assets.edit', $id),
            // How many files the entry holds as a stack, counted like the Library card's badge: the strip and its no-access note need it.
            'attachmentCount'       => $stackFileCount,
            'tags'                  => $tags,
            // Why the details of this file are read-only for the signed-in person; null when they may edit them.
            'detailsNote'           => app(RoleBuilder::class)->refusal(model: $asset, reason: 'own-only-details'),
            'panoramaPreview'       => $this->panoramaPreviewPayload($asset, $playbackSource, playbackResolved: true),
            'badgeCounts'           => [
                // buildAssetInfo() read every property row of this asset already.
                'properties'       => count($technicalInfo['properties']),
                'comments'         => AssetComments::where('dam_asset_id', $asset->id)->count(),
                'linked-resources' => $asset->resources()->count(),
                'relationships'    => app(AssetRelationshipService::class)->visibleCount(asset: $asset),
                'files'            => $asset->captureFileCount(),
            ],
        ]);
    }

    /**
     * The preview proxy's status for the asset page (P6 D26), with whether this viewer may press Retry: the caller has
     * already found the asset visible, so VideoProxyController's rule reduces to the edit permission.
     */
    protected function videoProxyPayload(Asset $asset): array
    {
        $status = app(VideoProxyStatus::class)->for($asset);

        return $status + ['can_retry' => $status['retryable'] && (bool) bouncer()->hasPermission('dam.asset.edit')];
    }

    public function update(Request $request, $id)
    {
        $asset = $this->visibleAsset($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-update'),
            ], 404);
        }

        // The details form posts here, but nothing it can send is written. The file's descriptors (name, extension,
        // MIME type, size, type, path) describe its bytes: they change only with the bytes (upload, re-upload) or
        // through rename(), which has its own permission. Taking them here let dam.asset.update rename a file, and
        // retype it, with the record no longer matching its bytes (UX eval R5/PERM-04).
        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.datagrid.update-success'),
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $request->validate(['force' => ['sometimes', 'boolean']]);

        $asset = $this->visibleAsset($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-destroy'),
            ], 404);
        }

        // The asset is visible here, so refusing the delete itself reveals nothing.
        if (! $this->roleBuilder->canDelete($asset)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.permissions.unauthorized'),
            ], 403);
        }

        if ($asset->resources()->exists()) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.delete-failed-due-to-attached-resources'),
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

    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $massDestroyRequest->validate(['force' => ['sometimes', 'boolean']]);

        $assetIds = $massDestroyRequest->input('indices');
        $skippedAssetNames = [];
        $skippedForeign = 0;
        $force = $massDestroyRequest->boolean('force');

        try {
            $assets = Asset::whereIn('id', $assetIds)->with('resources')->get()->keyBy('id');
            $deletableAssets = collect();

            foreach ($assetIds as $assetId) {
                $asset = $assets->get($assetId);

                if (! $asset) {
                    continue;
                }

                if (! $this->canActOnAsset($asset)) {
                    continue;
                }

                // Visible but someone else's: skipped, and counted so the answer says so in plain words.
                if (! $this->roleBuilder->canDelete($asset)) {
                    $skippedForeign++;

                    continue;
                }

                if ($asset->resources->isNotEmpty()) {
                    $skippedAssetNames[] = $asset->file_name;

                    continue;
                }

                $deletableAssets->push($asset);
            }

            if ($deletableAssets->isEmpty() && $skippedForeign > 0 && $skippedAssetNames === []) {
                return new JsonResponse([
                    'success' => false,
                    'message' => trans('dam::app.admin.permissions.own-only-mass-none', ['skipped' => $skippedForeign]),
                    'skipped' => $skippedForeign,
                ], 403);
            }

            $analyses = $deletableAssets
                ->mapWithKeys(fn (Asset $asset) => [$asset->id => $this->assetDeletionService->analyze($asset)])
                ->all();

            $hasBlockingDependencies = collect($analyses)
                ->contains(fn (array $analysis) => $analysis['blocking'] !== []);

            if (! $force && $hasBlockingDependencies) {
                return new JsonResponse([
                    'success'  => false,
                    'message'  => trans('dam::app.admin.dam.asset.delete-blocked'),
                    'analysis' => $analyses,
                ], 422);
            }

            foreach ($deletableAssets as $asset) {
                Event::dispatch('dam.asset.delete.before', $asset->id);

                $analyses[$asset->id] = app(AssetTrashService::class)->trash(asset: $asset, force: $force);

                Event::dispatch('dam.asset.delete.after', $asset->id);
            }

            if (! empty($skippedAssetNames)) {
                return new JsonResponse([
                    'message' => trans('dam::app.admin.dam.asset.delete-failed-due-to-attached-resources'),
                ], 404);
            }

            return new JsonResponse([
                'message'  => $skippedForeign > 0
                    ? trans('dam::app.admin.permissions.own-only-mass-delete', ['deleted' => $deletableAssets->count(), 'skipped' => $skippedForeign])
                    : trans('dam::app.admin.dam.asset.datagrid.mass-delete-success'),
                'skipped'  => $skippedForeign,
                'analysis' => $analyses,
            ]);
        } catch (AssetDeletionException $exception) {
            return new JsonResponse([
                'success'  => false,
                'message'  => trans('dam::app.admin.dam.asset.delete-blocked'),
                'analysis' => $exception->analysis(),
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.error-operation'),
            ], 500);
        }
    }

    public function massUpdate(MassUpdateRequest $massUpdateRequest): JsonResponse
    {
        $data = $massUpdateRequest->all();

        $assetIds = $data['indices'];

        foreach ($assetIds as $assetId) {
            Event::dispatch('dam.asset.update.before', $assetId);

            Event::dispatch('dam.asset.update.after', $assetId);
        }

        return new JsonResponse([
            'message' => trans('dam::app.admin.dam.asset.datagrid.mass-update-success'),
        ]);
    }

    public function download(int $id)
    {
        // Visibility before the blob lookup: a hidden asset answers like a missing one, blob or not.
        $asset = $this->visibleAsset($id);

        if (! $asset) {
            abort(404);
        }

        // P6 D26: "Web MP4 (1080p)" is the published preview proxy, through this same authorized path; none, 404.
        if (request()->query('variant') === VideoProxyStatus::WEB_MP4_VARIANT) {
            $webMp4 = VideoProxyStatus::webMp4($asset);

            abort_if($webMp4 === null, 404);

            return app(ContentStore::class)->deliver(
                object: $webMp4['object'],
                filename: $webMp4['filename'],
                disposition: HeaderUtils::DISPOSITION_ATTACHMENT,
                mimeType: 'video/mp4',
            );
        }

        $object = $this->currentDownloadableObject($asset);

        if ($object === null) {
            abort(404);
        }

        // A precondition on the bytes: the 3D viewer and the thumbnail backfill name the sha256 they captured against, so
        // what is downloaded is provably those bytes (or the answer is 409), not whatever the entry holds by the time the
        // request lands. Two reads of the entry's checksum around a download cannot show that (replace B, restore A).
        $expected = request()->query('sha256');

        if (is_string($expected) && $expected !== '') {
            abort_unless(hash_equals(strtolower((string) $object->sha256), strtolower($expected)), 409, 'The file changed. Reopen it and try again.');
        }

        $this->countDownload(assetId: (int) $asset->id, via: 'direct');

        // deliver() names the download after the asset's current file_name, which stays correct
        // after a rename (the Library path segment keeps its original on-disk name).
        return app(ContentStore::class)->deliver(
            object: $object,
            filename: $asset->file_name,
            disposition: HeaderUtils::DISPOSITION_ATTACHMENT,
            mimeType: $object->mime_type ?: $asset->mime_type ?: null,
        );
    }

    public function downloadCompressed(int $id)
    {
        // Visibility before the blob lookup: a hidden asset answers like a missing one, blob or not.
        $asset = $this->visibleAsset($id);

        if (! $asset) {
            abort(404);
        }

        $object = $this->currentDownloadableObject($asset);

        if ($object === null) {
            abort(404);
        }

        // Opened before the response starts, so a missing blob still answers 404 (ContentUnavailable) with no byte sent.
        $stream = app(ContentStore::class)->open($object);
        $entryName = ZipEntryNames::segment((string) $asset->file_name);

        $this->countDownload(assetId: (int) $asset->id, via: 'compressed');

        return $this->streamZip(
            zipName: pathinfo($asset->file_name, PATHINFO_FILENAME).'.zip',
            compression: CompressionMethod::DEFLATE,
            fill: function (ZipStream $zip) use ($stream, $entryName): void {
                try {
                    $zip->addFileFromStream(fileName: $entryName, stream: $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
        );
    }

    /** asset.download.served for one file (P1b); a HEAD or a resumed range is not a new download. */
    private function countDownload(int $assetId, string $via): void
    {
        if (DamEvents::isNewDownload(request: request())) {
            DamEvents::emit(event: DamEvent::DownloadServed, subjectId: $assetId, subjectType: 'asset', props: ['via' => $via]);
        }
    }

    /** An asset's current original, or null when there is none or its blob is missing. */
    private function currentDownloadableObject(Asset $asset): ?FileObject
    {
        try {
            return app(ContentStore::class)->current($asset);
        } catch (ContentUnavailable) {
            return null;
        }
    }

    /** The full bytes of a FileObject's blob, through the read seam. */
    private function readOriginalBytes(FileObject $object): string
    {
        $stream = app(ContentStore::class)->open($object);

        try {
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /** A converted or resized copy; counted as a download once it is actually produced (P1b). */
    public function customDownload(Request $request, int $id)
    {
        $response = $this->customDownloadResponse(request: $request, id: $id);

        if ($response->getStatusCode() === 200) {
            $this->countDownload(assetId: $id, via: 'custom');
        }

        return $response;
    }

    private function customDownloadResponse(Request $request, int $id)
    {
        $format = $request->query('format', null);
        $height = $request->query('height', null);
        $width = $request->query('width', null);

        $asset = $this->visibleAsset($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-download'),
            ], 404);
        }

        if ($request->has('file')) {
            abort_unless(bouncer()->hasPermission('dam.asset.download'), 403);
            $fileId = filter_var($request->query('file'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless($fileId !== false, 404);
            $file = AssetFile::where('dam_asset_id', $asset->id)->whereKey($fileId)->where('is_current', true)
                ->whereHas('attachment', fn ($query) => $query->where('dam_asset_id', $asset->id))
                ->with(['fileObject', 'attachment'])->firstOrFail();
            $object = $file->fileObject;
            abort_unless($object instanceof FileObject, 404);
            $extension = StackFileName::extension(file: $file, asset: $asset);
            $name = StackFileName::fileName(file: $file, asset: $asset);
            // A detached descriptor feeds the existing converter. Selecting a member never changes the stack.
            $asset = clone $asset;
            $asset->fill([
                'file_name' => $name, 'extension' => $extension, 'mime_type' => $object->mime_type,
                'file_type' => AssetHelper::classifyFile(mimeType: (string) $object->mime_type, extension: $extension),
            ]);
        } else {
            $object = $this->currentDownloadableObject($asset);
        }

        if ($object === null) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-download'),
            ], 404);
        }

        // The requested format is the asset's own, and no resize was requested: serve the
        // original bytes untouched rather than round-tripping through Imagick/Intervention,
        // which re-encodes (and can subtly alter) the file even when nothing would actually
        // change. Checked before EITHER re-encode branch below (svg or raster) so it applies
        // uniformly — the svg branch used to run first and always re-encode even a same-format
        // request.
        if ($format && ! $width && ! $height && strtolower((string) $format) === strtolower((string) $asset->extension)) {
            return app(ContentStore::class)->deliver(
                object: $object,
                filename: $asset->file_name,
                disposition: HeaderUtils::DISPOSITION_ATTACHMENT,
                mimeType: $object->mime_type ?: $asset->mime_type ?: null,
            );
        }

        if ($asset->extension === 'svg' && $format) {
            // libvips renders the SVG (librsvg) in the Studio's images, which have no Imagick; Imagick is only the
            // fallback for a machine without vips.
            if ((new ExecutableFinder)->find(app(MediaTools::class)->binary('vips')) !== null) {
                return $this->svgDownloadWithVips(asset: $asset, object: $object, format: strtolower((string) $format), width: $width ? (int) $width : null, height: $height ? (int) $height : null);
            }

            if (! extension_loaded('imagick')) {
                // A genuinely different format was asked for and cannot be produced: this must
                // fail loudly, not silently hand back the original SVG bytes under a filename
                // (and to a caller) that both expect the requested format.
                return response()->json([
                    'message' => trans('dam::app.admin.dam.asset.edit.image-processing-failed', [
                        'message' => 'SVG conversion needs libvips (vips) or the Imagick PHP extension.',
                    ]),
                ], 500);
            }

            // Reserved before the risky Imagick calls below so a failure AFTER allocation (a
            // corrupt/unreadable source, an unsupported target format, etc) still has a path to
            // clean up — deleteFileAfterSend(true) on the success response only ever covers the
            // success path.
            $tempFilePath = null;

            try {
                $svgContent = $this->readOriginalBytes($object);
                $tempFilePath = $this->reservedTempImagePath('svg_', $format);

                $imagick = new \Imagick;
                $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
                $imagick->readImageBlob($svgContent, 'image.svg');
                $imagick->setImageFormat($format);
                $imagick->writeImage($tempFilePath);
                $imagick->clear();

                $fileName = pathinfo($asset->file_name, PATHINFO_FILENAME).'.'.$format;

                return response()->download($tempFilePath, $fileName)->deleteFileAfterSend(true);
            } catch (\ImagickException $e) {
                if ($tempFilePath !== null && is_file($tempFilePath)) {
                    @unlink($tempFilePath);
                }

                Log::warning('SVG conversion failed', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

                return response()->json([
                    'message' => trans('dam::app.admin.dam.asset.edit.image-processing-failed', ['message' => $e->getMessage()]),
                ], 500);
            }
        }

        if ($asset->file_type === 'image' && ($format || $height || $width)) {
            $tempFilePath = null;

            try {
                $fileContent = $this->readOriginalBytes($object);
                $image = (new ImageManager(new Driver))->decode($fileContent);

                if ($width || $height) {
                    $image->scale($width ? (int) $width : null, $height ? (int) $height : null);
                }

                $tempFilePath = $this->reservedTempImagePath('dam_img_', strtolower($format ?: (string) $asset->extension));
                $image->save($tempFilePath);

                if ($format) {
                    $fileName = pathinfo($asset->file_name, PATHINFO_FILENAME).'.'.strtolower($format);
                } else {
                    $fileName = $asset->file_name;
                }

                return response()->download($tempFilePath, $fileName)->deleteFileAfterSend(true);
            } catch (\Exception $e) {
                if ($tempFilePath !== null && is_file($tempFilePath)) {
                    @unlink($tempFilePath);
                }

                return response()->json([
                    'message' => trans('dam::app.admin.dam.asset.edit.image-processing-failed', ['message' => $e->getMessage()]),
                ], 500);
            }
        }

        // A15: always name the download after the asset's current file_name, not the stale
        // on-disk path segment Storage::download() falls back to without one.
        return app(ContentStore::class)->deliver(
            object: $object,
            filename: $asset->file_name,
            disposition: HeaderUtils::DISPOSITION_ATTACHMENT,
            mimeType: $object->mime_type ?: $asset->mime_type ?: null,
        );
    }

    /**
     * A unique temp file path ending in the given extension, for Imagick/Intervention to write
     * to. tempnam() both reserves AND creates a file at its own randomly-generated path; simply
     * appending an extension to that path (the old pattern) creates a SECOND, different path
     * string that Imagick/Intervention then writes to — deleteFileAfterSend() only ever cleans up
     * that second path, leaking the original empty tempnam() file on every single conversion.
     * Renaming the reserved file onto the extension-suffixed path keeps it a single file.
     */
    /**
     * An SVG rendered to $format by libvips: `thumbnail` to the requested box when a size is given (the vector is
     * rendered at that size, not scaled up from a small raster), flattened onto white for a JPEG, which has no alpha.
     * Everything is written in a private work directory that is removed whatever happens; a failure is an explicit
     * error, never the original SVG under the requested name.
     */
    private function svgDownloadWithVips(Asset $asset, FileObject $object, string $format, ?int $width, ?int $height)
    {
        $workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svg_'.bin2hex(random_bytes(8));
        mkdir($workdir, 0700);
        $tools = app(MediaTools::class);

        try {
            $source = $workdir.DIRECTORY_SEPARATOR.'source.svg';
            file_put_contents($source, $this->readOriginalBytes($object));

            if ($width || $height) {
                $sized = $workdir.DIRECTORY_SEPARATOR.'sized.v';
                $tools->mustRun('vips', ['thumbnail', $source, $sized, (string) ($width ?: 100000), ...($height ? ['--height', (string) $height] : [])], $workdir);
                $source = $sized;
            }

            if (in_array($format, ['jpg', 'jpeg'], true)) {
                $flat = $workdir.DIRECTORY_SEPARATOR.'flat.v';
                $tools->mustRun('vips', ['flatten', $source, $flat, '--background', '255'], $workdir);
                $source = $flat;
            }

            $output = $workdir.DIRECTORY_SEPARATOR.'out.'.$format;
            $tools->mustRun('vips', ['copy', $source, $output], $workdir);
            $download = $this->reservedTempImagePath('svg_', $format);
            rename($output, $download);

            return response()->download($download, pathinfo($asset->file_name, PATHINFO_FILENAME).'.'.$format)->deleteFileAfterSend(true);
        } catch (RecipeFailed $failed) {
            Log::warning('SVG conversion failed', ['asset_id' => $asset->id, 'format' => $format, 'error' => $failed->getMessage()]);

            return response()->json([
                'message' => trans('dam::app.admin.dam.asset.edit.image-processing-failed', ['message' => 'The SVG could not be converted to '.strtoupper($format).'.']),
            ], 500);
        } finally {
            foreach (glob($workdir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workdir);
        }
    }

    protected function reservedTempImagePath(string $prefix, string $extension): string
    {
        $reserved = tempnam(sys_get_temp_dir(), $prefix);
        $path = $reserved.'.'.$extension;
        rename($reserved, $path);

        return $path;
    }

    /** The own-items refusal for a rename, move or replace of `$asset`, as the JSON the Library's toast reads; null when allowed. */
    private function ownItemsRefusal(Asset $asset): ?JsonResponse
    {
        $refusal = $this->roleBuilder->refusal($asset);

        return $refusal === null ? null : new JsonResponse(['success' => false, 'message' => $refusal], 403);
    }

    public function rename(Request $request): JsonResponse
    {
        $request->validate([
            'file_name' => AssetRename::FILE_NAME_RULE,
            'title'     => 'nullable|string|max:255',
            // Not `exists`: a 422 for a missing id against a 404 for a hidden one would tell them apart.
            'id'        => 'required|integer',
        ]);

        $asset = $this->visibleAsset($request->input('id'));

        if (! $asset) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.asset-not-found'),
            ], 404);
        }

        if ($refused = $this->ownItemsRefusal($asset)) {
            return $refused;
        }

        try {
            try {
                $result = app(AssetRename::class)->rename(
                    asset: $asset,
                    fileName: (string) $request->input('file_name'),
                    title: $request->has('title') ? (string) $request->input('title') : null,
                );
            } catch (AssetRenameException $refused) {
                return $refused->reason === AssetRenameException::NAME_TAKEN
                    ? new JsonResponse([
                        'message' => trans('dam::app.admin.dam.index.directory.asset-name-already-exist', ['asset_name' => $refused->conflictingName]),
                        'code'    => 'name_taken',
                    ], 404)
                    : new JsonResponse([
                        'message' => trans('dam::app.admin.dam.index.directory.asset-name-conflict-in-the-same-directory'),
                        'code'    => 'name_conflict',
                    ], 422);
            }

            // Saving the name it already has is a no-op, not an error: callers close quietly and skip the reload.
            if (! $result['file_changed'] && ! $result['title_changed']) {
                return new JsonResponse([
                    'message' => trans('dam::app.admin.dam.index.directory.asset-name-unchanged'),
                    'code'    => 'unchanged',
                ]);
            }

            $asset->refresh()->append('display_name');

            return new JsonResponse([
                'data'    => $asset,
                'message' => trans('dam::app.admin.dam.index.directory.asset-renamed-success'),
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => trans('dam::app.admin.dam.index.directory.error-operation'),
            ], 500);
        }
    }

    public function moved(Request $request): JsonResponse
    {
        $request->validate([
            // Not `exists` on the asset: a missing id must answer exactly like a hidden one.
            'move_item_id'  => 'required|integer',
            'new_parent_id' => ['required', 'integer', new VisibleDirectory],
        ]);

        $asset = $this->visibleAsset($request->input('move_item_id'));

        if (! $asset) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.asset-not-found'),
            ], 404);
        }

        if ($refused = $this->ownItemsRefusal($asset)) {
            return $refused;
        }

        $newParentId = (int) $request->input('new_parent_id');
        if (! $this->permissionService->canAccess($newParentId)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.permissions.unauthorized'),
            ], 403);
        }

        $oldDirectory = $asset->directories()->first();
        if (! $oldDirectory) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.asset-not-found'),
            ], 404);
        }

        $directory = $this->directoryRepository->find($newParentId);

        // A tree-structure change (Decision 4, ADR 0009 slice 3): the target's path prefix and the
        // unique name picked for it must not shift under a concurrent folder rename/move. No physical
        // file to move — only the path/file_name columns and the directory pivot change.
        try {
            Directory::withTreeLock(function () use ($asset, $directory, $newParentId) {
                $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());
                $uniqueFileName = $this->generateUniqueFileName($directoryPath, $asset->file_name);
                $newAssetFullPath = $directoryPath.'/'.$uniqueFileName;

                DB::transaction(function () use ($asset, $newParentId, $newAssetFullPath, $uniqueFileName): void {
                    $asset->update([
                        'path'      => $newAssetFullPath,
                        'file_name' => $uniqueFileName,
                    ]);
                    $asset->directories()->sync($newParentId);
                    // A moved file takes its destination's access list and keeps its owner (ADR 0010,
                    // spec "Move"). The tree lock above already holds the fence's row.
                    app(AccessListWriter::class)->adoptForAssets(assetIds: [$asset->id], destinationId: $newParentId, actorId: $this->roleBuilder->actingAdmin()?->id);
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            report($e);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.asset-name-already-exist', ['asset_name' => $asset->file_name]),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.error-operation'),
            ], 500);
        }

        AiIndexDispatcher::lexical([(int) $asset->id]);

        return new JsonResponse([
            'data'    => $asset,
            'message' => trans('dam::app.admin.dam.index.directory.asset-moved-success'),
        ]);
    }

    /**
     * Drop a known asset id's cover-art cache. Never purges sha-keyed derivatives here: a blob many
     * assets can share (ADR 0009) is only reclaimed by AssetDeletionService's reference-counted blob
     * deletion, which already calls DerivativeImages::purgeContent() when a blob is actually unlinked.
     * Purging it opportunistically on one asset's destroy/re-upload would delete another asset's
     * still-live thumbnails, previews and posters whenever the two share a blob.
     */
    private function clearAssetCache(string $disk, ?int $assetId = null): void
    {
        if ($assetId !== null) {
            CoverArt::purgeLegacy(assetId: $assetId, disk: $disk);
        }
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

    private function autoGrantToCreator(int $directoryId): void
    {
        // Under `lists` the owner rule replaces this grant: the creator is the new folder's owner and
        // already opens it, and the folder has its parent's access list (ADR 0010). The legacy grant stays
        // under `grants`, which is still the record until the operator runs dam:access:switch lists.
        if (app(AccessModel::class)->lists()) {
            return;
        }

        $admin = auth()->guard('admin')->user();

        if (! $admin) {
            return;
        }

        $role = $admin->role;

        if (! $role || $role->permission_type !== 'custom') {
            return;
        }

        if (DB::table('dam_role_settings')->where('role_id', $role->id)->where('all_directories', true)->exists()) {
            return;
        }

        if (app(FolderAccessService::class)->grantToCreator(adminId: (int) $admin->id, directoryId: $directoryId)) {
            return;
        }

        $this->permissionRepository->addDirectoryToRole($role->id, $directoryId);
        $this->permissionService->flush();
    }
}

<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mothership\AdminApi\Http\Middleware\ScopeMiddleware;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\DerivativeImages;
use Symfony\Component\HttpFoundation\Response;

/** Bearer-authenticated capture of an asset or one explicitly identified compound file. */
class ModelThumbnailController
{
    public function __construct(
        private AssetAccessDecision $access,
        private RoleBuilder $roles,
        private ModelThumbnailService $thumbnails,
        private ModelFileThumbnailService $fileThumbnails,
        private ContentStore $content,
        private ScopeMiddleware $scopes,
    ) {}

    public function show(int $asset, ?int $file = null): JsonResponse
    {
        [$model, $selected] = $this->model(asset: $asset, file: $file);
        $checksum = $selected->fileObject->sha256;
        $state = $model->meta_data['glb_primary_view'] ?? null;
        $canWrite = $this->scopes->hasPermission('api.dam.assets.model-thumbnails.write')
            && $this->hasEditPermission()
            && $this->access->for(context: AccessContext::api(AccessContext::PURPOSE_EDIT), asset: $model)->allowed
            && $this->roles->refusal(model: $model) === null;

        return response()->json([
            'asset_id'          => $asset,
            'file_id'           => (int) $selected->id,
            'checksum'          => $checksum,
            'size'              => (int) $selected->fileObject->size,
            'revision'          => (int) $selected->revision_number,
            'is_representative' => $selected->role === AssetFile::ROLE_ORIGINAL,
            'extension'         => $selected->extension(),
            'has_thumbnail'     => $file === null ? ModelThumbnailService::hasCapture($model) && $this->picture(asset: $model, file: $selected, compound: false) !== null : ModelFileThumbnailService::exists(sha256: $checksum, assetId: $asset),
            'primary_view'      => is_array($state) && ($state['sha256'] ?? null) === $checksum ? $state : null,
            'can_capture'       => $canWrite,
            'can_replace'       => $canWrite,
            'source_url'        => $this->url(asset: $asset, file: $file, action: 'source', checksum: $checksum),
            'thumbnail_url'     => $this->url(asset: $asset, file: $file, action: 'image', checksum: $checksum),
        ]);
    }

    public function store(Request $request, int $asset, ?int $file = null): JsonResponse
    {
        [$model, $selected, $object] = $this->model(asset: $asset, file: $file, purpose: AccessContext::PURPOSE_EDIT);
        $this->roles->abortUnlessOwn(model: $model, reason: 'own-only-thumbnail');
        $request->validate([
            'image'    => 'required|file|mimetypes:image/png,image/jpeg|max:2048|dimensions:max_width=1024,max_height=1024',
            'checksum' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/i',
            'replace'  => 'sometimes|boolean',
        ]);
        $checksum = strtolower($request->string('checksum')->toString());
        abort_unless(hash_equals($selected->fileObject->sha256, $checksum), 409, 'The model bytes changed. Read the capture metadata again.');

        $result = $file === null
            ? $this->thumbnails->store(asset: $model, upload: $request->file('image'), checksum: $checksum, replace: $request->boolean('replace'))
            : $this->fileThumbnails->store(object: $object, upload: $request->file('image'), assetId: $asset, replace: $request->boolean('replace'));

        return response()->json($result + [
            'checksum'      => $checksum,
            'thumbnail_url' => $this->url(asset: $asset, file: $file, action: 'image', checksum: $checksum),
        ]);
    }

    public function source(Request $request, int $asset, ?int $file = null): Response
    {
        [$model, $selected, $object] = $this->model(asset: $asset, file: $file, purpose: AccessContext::PURPOSE_DOWNLOAD);
        $this->checkChecksum(request: $request, file: $selected);

        return $this->content->deliver(
            object: $object,
            filename: $selected->label ?: $model->file_name,
            headers: ['Cache-Control' => 'private, no-store'],
            sameOrigin: true,
        );
    }

    public function image(Request $request, int $asset, ?int $file = null): Response
    {
        [$model, $selected] = $this->model(asset: $asset, file: $file);
        $this->checkChecksum(request: $request, file: $selected);
        $path = $this->picture(asset: $model, file: $selected, compound: $file !== null);
        abort_if($path === null, 404);

        return Storage::disk(Directory::getAssetDisk())->response($path, 'model-capture.jpg', [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{Asset, AssetFile, FileObject} */
    private function model(int $asset, ?int $file, string $purpose = AccessContext::PURPOSE_PREVIEW): array
    {
        $model = $this->access->for(context: AccessContext::api($purpose), asset: $asset)->allowedOrFail();
        abort_if($purpose === AccessContext::PURPOSE_EDIT && ! $this->hasEditPermission(), 403);
        $query = $model->files()->with('fileObject');
        $selected = $file === null ? $query->where('role', AssetFile::ROLE_ORIGINAL)->orderBy('id')->first() : $query->where('is_current', true)->find($file);
        abort_unless($selected instanceof AssetFile, 404);
        $object = $selected->fileObject;
        abort_unless($object instanceof FileObject, 404);
        $selected->setRelation('asset', $model);
        abort_unless($file === null ? ModelThumbnailService::supports($model) : ModelFileThumbnailService::supports($selected, $model), 422, 'Generated thumbnails cover GLB, 3MF and STL models only.');

        return [$model, $selected, $object];
    }

    private function checkChecksum(Request $request, AssetFile $file): void
    {
        $request->validate(['checksum' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/i']);
        abort_unless(hash_equals($file->fileObject->sha256, strtolower($request->string('checksum')->toString())), 409);
    }

    private function picture(Asset $asset, AssetFile $file, bool $compound): ?string
    {
        if ($compound || CustomThumbnailService::location($asset) !== null || ! empty($asset->meta_data['custom_thumbnail'])) {
            return ModelFileThumbnailService::path(sha256: $file->fileObject->sha256, assetId: $asset->id);
        }

        $checksum = $file->fileObject->sha256;
        $path = $asset->meta_data['thumbnail_path'] ?? null;
        $allowed = [
            ModelThumbnailService::generatedPath(asset: $asset, checksum: $checksum),
            DerivativeImages::modelCapture(sha256: $checksum, assetId: $asset->id),
            DerivativeImages::modelCapture(sha256: $checksum),
        ];

        return is_string($path) && in_array($path, $allowed, true) && ModelFileThumbnailService::isUsable($path) ? $path : null;
    }

    private function hasEditPermission(): bool
    {
        $admin = auth()->guard('api')->user();
        $role = $admin?->role;

        return $admin !== null && ($role === null || $role->permission_type === 'all' || in_array('dam.asset.edit', $role->permissions ?? [], true));
    }

    private function url(int $asset, ?int $file, string $action, string $checksum): string
    {
        return route('admin.api.dam.assets.'.($file === null ? '' : 'files.').'model-thumbnail.'.$action,
            array_filter(['asset' => $asset, 'file' => $file, 'checksum' => $checksum], fn ($value) => $value !== null));
    }
}

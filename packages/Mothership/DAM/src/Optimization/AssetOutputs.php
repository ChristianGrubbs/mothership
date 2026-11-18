<?php

namespace Mothership\DAM\Optimization;

use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\HomeTelemetry;
use Mothership\DAM\Services\Lineage;
use Mothership\User\Models\Admin;
use RuntimeException;
use stdClass;

/** Called under the optimization settlement lock, so publication and status commit together. */
class AssetOutputs
{
    /**
     * Re-check the asset, the checksum of the file optimized (its main file, or the stacked file the row names), and the requesting admin's access, edit
     * and dam.asset.files permissions before attaching. Any of those failing returns null (the caller
     * settles the row to `expired` with no attachment); the original is never touched.
     */
    public function publish(stdClass $row, string $extension, string $localPath): ?int
    {
        $asset = Asset::whereKey($row->asset_id)->lockedForGraph()->first();

        if (! $asset) {
            return null;
        }

        $admin = Admin::query()->find($row->admin_id);

        if (! $admin || ! (bool) $admin->status) {
            return null;
        }

        if (! DirectoryPermissionService::adminCanAccessAsset(adminId: (int) $admin->id, assetId: $asset->id)
            || ! $this->adminHasPermission($admin, 'dam.asset.edit')
            || ! $this->adminHasPermission($admin, 'dam.asset.files')) {
            return null;
        }

        try {
            $source = OptimizationSource::resolve(asset: $asset, attachmentId: $row->attachment_id === null ? null : (int) $row->attachment_id);
        } catch (ContentUnavailable) {
            return null;
        }

        $current = $source->object;

        if (! hash_equals((string) $row->source_sha256, (string) $current->sha256)) {
            return null;
        }

        // $localPath is the optimizer's own staged temp file (Optimizer::optimize()'s "path" outcome
        // field); the job that calls finish() still owns and deletes it afterward. Reading it here via
        // hash_file()/putLocal() streams from disk — this never loads the file into a PHP string, which
        // matters for a large video (round-1 review finding, 2026-09-26).
        $sha = hash_file('sha256', $localPath);
        $size = filesize($localPath);

        if (! $sha || ! $size) {
            throw new RuntimeException('optimization_output_unavailable');
        }

        $mime = mime_content_type($localPath) ?: 'application/octet-stream';
        $object = app(ContentStore::class)->putLocal(
            disk: Directory::getAssetDisk(),
            localPath: $localPath,
            sha256: $sha,
            mimeType: $mime,
            extension: $extension,
            attributes: ['sniffed_type' => $mime],
        );
        $assetFile = AssetFile::firstOrCreate([
            'dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_OUTPUT,
        ], ['label' => (pathinfo($source->fileName, PATHINFO_FILENAME) ?: 'file').'-optimized.'.$extension, 'sort' => 1000]);

        $lineage = app(Lineage::class);
        $lineage->record(
            assetId: $asset->id,
            recipe: Rendition::RECIPE_OPTIMIZE,
            source: $lineage->capturedRevision(assetId: $asset->id, revisionId: $row->source_asset_file_id ?? null) ?? $lineage->revisionWithSha(assetId: $asset->id, sha256: (string) $row->source_sha256, startedAt: $row->created_at ?? null),
            worker: 'optimizer',
            outputFileId: (int) $assetFile->getKey(),
            parameters: ['type' => $row->type, 'optimization_id' => $row->id] + ($source->member === null ? [] : ['attachment_id' => (int) $row->attachment_id]),
        );

        HomeTelemetry::record(event: 'optimization.asset_attached', ctx: [
            'asset_id' => $asset->id, 'generation_id' => $row->id,
        ]);

        return (int) $assetFile->getKey();
    }

    /** Bouncer::hasPermission() checks the session user; this checks $admin explicitly (job context). */
    private function adminHasPermission(Admin $admin, string $permission): bool
    {
        $role = $admin->role;

        if (! $role) {
            return false;
        }

        if ($role->permission_type === 'all') {
            return true;
        }

        return $admin->hasPermission($permission);
    }
}

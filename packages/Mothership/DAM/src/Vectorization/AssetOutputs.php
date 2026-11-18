<?php

namespace Mothership\DAM\Vectorization;

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\HomeTelemetry;
use Mothership\DAM\Services\Lineage;
use RuntimeException;
use stdClass;

/** Called under the conversion settlement lock, so publication and charging commit together. */
class AssetOutputs
{
    public function publish(stdClass $row): void
    {
        if (! $row->output_format) {
            return;
        }

        $asset = Asset::whereKey($row->source_asset_id)->lockedForGraph()->first();
        if (! $asset || ! in_array($row->output_format, ['svg', 'pdf'], true)) {
            throw new RuntimeException('vectorization_asset_unavailable');
        }

        $format = $row->output_format;
        $local = Storage::disk('private')->path('vectorizations/results/'.$row->id.'/result.'.$format);
        $sha = hash_file('sha256', $local);
        $size = filesize($local);
        if (! $sha || ! $size) {
            throw new RuntimeException('vectorization_output_unavailable');
        }
        $mime = $format === 'svg' ? 'image/svg+xml' : 'application/pdf';
        $object = app(ContentStore::class)->putLocal(
            disk: Directory::getAssetDisk(),
            localPath: $local,
            sha256: $sha,
            mimeType: $mime,
            extension: $format,
            attributes: ['sniffed_type' => $mime],
        );
        $output = AssetFile::firstOrCreate([
            'dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_OUTPUT,
        ], ['label' => (pathinfo($asset->file_name, PATHINFO_FILENAME) ?: 'image').'-vectorized.'.$format, 'sort' => 1000]);
        // The revision that was traced, even when a newer one has replaced it since the request.
        $lineage = app(Lineage::class);
        $lineage->record(
            assetId: $asset->id,
            recipe: Rendition::RECIPE_VECTORIZE,
            source: $lineage->capturedRevision(assetId: $asset->id, revisionId: $row->source_asset_file_id ?? null) ?? $lineage->revisionWithSha(assetId: $asset->id, sha256: $row->input_sha ?? null, startedAt: $row->created_at ?? null),
            worker: 'vectorizer',
            outputFileId: (int) $output->getKey(),
            parameters: ['format' => $format, 'vectorization_id' => $row->id],
        );
        HomeTelemetry::record(event: 'vectorization.asset_attached', ctx: [
            'asset_id' => $asset->id, 'generation_id' => $row->id, 'format' => $format,
        ]);
    }
}

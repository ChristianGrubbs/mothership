<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\LineageInput;
use Mothership\DAM\Models\Rendition;

/**
 * Derived-output lineage (P3a S6): every output a worker makes from an entry's content names the revision it
 * was made from, so the Files tab can say "made by Optimize from revision 3" long after that revision stopped
 * being current. One row per output, on the source asset.
 */
class Lineage
{
    /**
     * The revision of the entry's representative slot holding these bytes. Given when the work started, the
     * newest such revision made by then, so work on revision 1 stays on it after A, B, then A again appended
     * revision 3; otherwise (or with none made by then) the current one if it holds them, else the newest.
     */
    public function revisionWithSha(int $assetId, ?string $sha256, CarbonInterface|string|null $startedAt = null): ?AssetFile
    {
        if ($sha256 === null || $sha256 === '') {
            return null;
        }

        $revisions = AssetFile::query()
            ->select('dam_asset_files.*')
            ->join('dam_file_objects as objects', 'objects.id', '=', 'dam_asset_files.file_object_id')
            // Every slot's superseded revisions carry `version`; only the representative slot's are the entry's content.
            ->join('dam_attachments as slots', 'slots.id', '=', 'dam_asset_files.attachment_id')
            ->where('slots.is_representative', true)
            ->where('dam_asset_files.dam_asset_id', $assetId)
            ->where('objects.sha256', $sha256)
            ->orderByDesc('dam_asset_files.revision_number')
            ->get();

        if ($startedAt !== null && $startedAt !== '') {
            $started = Carbon::parse($startedAt);
            $atStart = $revisions->first(fn (AssetFile $revision) => $revision->created_at !== null && $revision->created_at->lte($started));

            if ($atStart !== null) {
                return $atStart;
            }
        }

        return $revisions->firstWhere('is_current', true) ?? $revisions->first();
    }

    /** The revision id captured when the work was requested, if it is still a revision of this entry. */
    public function capturedRevision(int $assetId, int|string|null $revisionId): ?AssetFile
    {
        if ($revisionId === null || $revisionId === '') {
            return null;
        }

        return AssetFile::query()->whereKey((int) $revisionId)->where('dam_asset_id', $assetId)->first();
    }

    /** The entry's current representative revision. */
    public function currentRevision(int $assetId): ?AssetFile
    {
        return AssetFile::query()->where('dam_asset_id', $assetId)->where('role', AssetFile::ROLE_ORIGINAL)->orderBy('id')->first();
    }

    /**
     * Records a finished output: a file of the same entry ($outputFileId) or a separate entry ($outputAssetId).
     * Re-publishing the same output updates its row rather than adding one.
     *
     * @param  list<array{role: string, asset_file_id: ?int, sha256: ?string}>  $inputs  only for outputs made from several, and batch outputs
     */
    public function record(
        int $assetId,
        string $recipe,
        ?AssetFile $source,
        string $worker,
        ?int $outputFileId = null,
        ?int $outputAssetId = null,
        array $parameters = [],
        array $inputs = [],
        ?string $fingerprint = null,
    ): Rendition {
        $rendition = Rendition::query()->firstOrNew([
            'dam_asset_id'         => $assetId,
            'recipe'               => $recipe,
            'output_asset_file_id' => $outputFileId,
            'output_asset_id'      => $outputAssetId,
        ]);
        $rendition->fill([
            'source_asset_file_id' => $source?->getKey(),
            'parameters'           => $parameters ?: null,
            'worker'               => $worker,
            'status'               => Rendition::STATUS_DONE,
            'progress'             => 100,
            'finished_at'          => now(),
            // Batch recipes (P6a): sha256 of recipe, version, parameters and input checksums, so a retry reuses it.
            ...($fingerprint === null ? [] : ['fingerprint' => $fingerprint]),
        ])->save();

        if ($inputs !== []) {
            $rendition->inputs()->delete();

            foreach (array_values($inputs) as $position => $input) {
                LineageInput::query()->create(['rendition_id' => $rendition->id, 'position' => $position, ...$input]);
            }
        }

        HomeTelemetry::record(event: 'lineage.recorded', ctx: [
            'asset_id'    => $assetId, 'rendition_id' => $rendition->id, 'recipe' => $recipe,
            'source_file' => $source?->getKey(), 'output_file' => $outputFileId, 'output_asset' => $outputAssetId,
            'inputs'      => count($inputs),
        ]);

        return $rendition;
    }
}

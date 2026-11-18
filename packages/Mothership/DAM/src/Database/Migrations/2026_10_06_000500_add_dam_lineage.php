<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Derived-output lineage (P3a S6). A rendition stays on its source asset and names the source revision it was
 * made from (source_asset_file_id, same asset, as before); it now also names what it produced: a file of that
 * asset (output_asset_file_id: an optimized or vectorized copy, a preview proxy) or a separate asset
 * (output_asset_id: an image editor save-as-new). An output made from several inputs lists them in
 * dam_lineage_inputs.
 *
 * The two output columns carry no foreign key, like dam_asset_files.attachment_id: both tables already cascade
 * from the asset, and a second path is SQL Server error 1785. AssetFile's deleted hook and
 * Asset::releaseFileOwnership clear them instead.
 *
 * Backfill, exact matches only: a finished proxy rendition names the proxy file holding its output blob, and a
 * published optimization becomes an `optimize` rendition naming its copy and, when a revision of the entry's
 * representative slot still holds the optimized source checksum, that revision. Vectorized copies made before
 * this recorded neither file nor source, so they stay unlinked.
 *
 * Proxies made before this recorded no source either, and playback and the proxy job now keep only a proxy of
 * the entry's current content (Rendition::scopeFromCurrentContent). Until this slice an entry's content could
 * change only by re-upload, so each existing proxy is credited to the entry's current original revision; a
 * later promote or restore then retires it and a new proxy is made. down() leaves these sources in place: the
 * column predates this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dam_renditions', function (Blueprint $table): void {
            $table->unsignedBigInteger('output_asset_file_id')->nullable()->after('output_file_object_id');
            $table->unsignedBigInteger('output_asset_id')->nullable()->after('output_asset_file_id');
            $table->index('output_asset_file_id');
            $table->index('output_asset_id');
        });

        Schema::create('dam_lineage_inputs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rendition_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('role', 32);
            // A revision when the input is one (any asset's); the bytes' checksum either way, so the record
            // still says what went in after that revision or blob is gone.
            $table->unsignedBigInteger('asset_file_id')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('rendition_id')->references('id')->on('dam_renditions')->cascadeOnDelete();
            $table->index('asset_file_id');
        });

        // By id, not offset: the loop fills output_asset_file_id, which the filter reads, so an offset would skip rows.
        DB::table('dam_renditions as r')
            ->whereNotNull('r.output_file_object_id')
            ->whereNull('r.output_asset_file_id')
            ->select(['r.id', 'r.dam_asset_id', 'r.output_file_object_id'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $file = DB::table('dam_asset_files')->where('dam_asset_id', $row->dam_asset_id)
                        ->where('file_object_id', $row->output_file_object_id)->where('role', 'proxy')->min('id');

                    if ($file !== null) {
                        DB::table('dam_renditions')->where('id', $row->id)->update(['output_asset_file_id' => $file]);
                    }
                }
            }, 'r.id', 'id');

        $this->backfillProxySources();

        if (! Schema::hasTable('dam_optimizations')) {
            return;
        }

        DB::table('dam_optimizations')->whereNotNull('asset_file_id')->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $copy = DB::table('dam_asset_files')->where('id', $row->asset_file_id)->where('dam_asset_id', $row->asset_id)->first();

                    if ($copy === null || DB::table('dam_renditions')->where('output_asset_file_id', $copy->id)->exists()) {
                        continue;
                    }

                    $source = DB::table('dam_asset_files as f')
                        ->join('dam_file_objects as o', 'o.id', '=', 'f.file_object_id')
                        ->join('dam_attachments as a', 'a.id', '=', 'f.attachment_id')
                        ->where('a.is_representative', true)
                        ->where('f.dam_asset_id', $row->asset_id)
                        ->where('o.sha256', (string) ($row->source_sha256 ?? ''))
                        ->orderByDesc('f.is_current')
                        ->orderByDesc('f.revision_number')
                        ->value('f.id');

                    DB::table('dam_renditions')->insert([
                        'dam_asset_id'         => $row->asset_id,
                        'source_asset_file_id' => $source,
                        'recipe'               => 'optimize',
                        // `backfilled` lets down() remove only these rows, never an optimize rendition recorded since.
                        'parameters'           => json_encode(['type' => $row->type ?? null, 'source_sha256' => $row->source_sha256 ?? null, 'optimization_id' => $row->id, 'backfilled' => true]),
                        'worker'               => 'optimizer',
                        'status'               => 'done',
                        'progress'             => 100,
                        'output_asset_file_id' => $copy->id,
                        'finished_at'          => $row->updated_at ?? null,
                        'created_at'           => $row->created_at ?? null,
                        'updated_at'           => $row->updated_at ?? null,
                    ]);
                }
            });
    }

    /** Public so a test can run this step without the schema changes around it. */
    public function backfillProxySources(): void
    {
        // By id, as above: the loop fills the column the filter reads.
        DB::table('dam_renditions')
            ->where('recipe', 'preview-h264-1080p')
            ->whereNull('source_asset_file_id')
            ->select(['id', 'dam_asset_id'])
            ->chunkById(500, function ($rows): void {
                // One current original per entry (its unique index).
                $originals = DB::table('dam_asset_files')
                    ->whereIn('dam_asset_id', $rows->pluck('dam_asset_id')->unique()->values()->all())
                    ->where('role', 'original')
                    ->where('is_current', true)
                    ->pluck('id', 'dam_asset_id');

                foreach ($rows as $row) {
                    if (isset($originals[$row->dam_asset_id])) {
                        DB::table('dam_renditions')->where('id', $row->id)->update(['source_asset_file_id' => $originals[$row->dam_asset_id]]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Only the optimize renditions up() backfilled; ones recorded since stay. Filtered here rather than in SQL,
        // since each grammar spells a JSON lookup differently. A re-published backfilled row has lost the marker
        // (Lineage::record replaces its parameters), so it stays as the runtime's own.
        $backfilled = DB::table('dam_renditions')->where('recipe', 'optimize')->select(['id', 'parameters'])->lazyById(500)
            ->filter(fn ($row) => (json_decode((string) $row->parameters, true)['backfilled'] ?? false) === true)
            ->pluck('id');

        foreach ($backfilled->chunk(500) as $ids) {
            DB::table('dam_renditions')->whereIn('id', $ids->all())->delete();
        }

        Schema::dropIfExists('dam_lineage_inputs');

        Schema::table('dam_renditions', function (Blueprint $table): void {
            $table->dropIndex(['output_asset_file_id']);
            $table->dropIndex(['output_asset_id']);
            $table->dropColumn(['output_asset_file_id', 'output_asset_id']);
        });
    }
};

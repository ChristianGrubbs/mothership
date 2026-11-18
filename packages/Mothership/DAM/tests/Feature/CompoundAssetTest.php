<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\AssetDeletionService;

beforeEach(function () {
    $this->loginAsAdmin();
});

it('builds an asset graph with original, sidecar, and a related-to edge', function () {
    $asset = Asset::factory()->create();
    $target = Asset::factory()->create();

    $originalFileObject = FileObject::factory()->create();
    $sidecarFileObject = FileObject::factory()->create();

    AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $originalFileObject->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
        'sort'           => 0,
    ]);

    AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $sidecarFileObject->id,
        'role'           => AssetFile::ROLE_SIDECAR,
        'sort'           => 1,
    ]);

    AssetRelationship::factory()->create([
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $target->id,
        'type'          => AssetRelationship::TYPE_RELATED_TO,
    ]);

    expect($asset->files()->count())->toBe(2);
    expect($asset->fileObjects()->pluck('dam_file_objects.id')->sort()->values()->all())
        ->toBe(collect([$originalFileObject->id, $sidecarFileObject->id])->sort()->values()->all());
    expect($asset->relationshipsFrom()->count())->toBe(1);
    expect($target->relationshipsTo()->count())->toBe(1);

    $graph = $asset->related();
    expect($graph['outgoing'])->toHaveKey(AssetRelationship::TYPE_RELATED_TO);
    expect($graph['incoming'])->toBeEmpty();
});

it('enforces uniqueness on (from_asset_id, to_asset_id, type)', function () {
    $from = Asset::factory()->create();
    $to = Asset::factory()->create();

    AssetRelationship::factory()->create([
        'from_asset_id' => $from->id,
        'to_asset_id'   => $to->id,
        'type'          => AssetRelationship::TYPE_RELATED_TO,
    ]);

    expect(fn () => AssetRelationship::factory()->create([
        'from_asset_id' => $from->id,
        'to_asset_id'   => $to->id,
        'type'          => AssetRelationship::TYPE_RELATED_TO,
    ]))->toThrow(QueryException::class);
});

it('refuses a raw asset deletion instead of cascading relationship edges', function () {
    $assetA = Asset::factory()->create();
    $assetB = Asset::factory()->create();
    $other = Asset::factory()->create();

    $shared = FileObject::factory()->create();

    $fileA = AssetFile::factory()->create([
        'dam_asset_id'   => $assetA->id,
        'file_object_id' => $shared->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
    ]);

    $fileB = AssetFile::factory()->create([
        'dam_asset_id'   => $assetB->id,
        'file_object_id' => $shared->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
    ]);

    $edge = AssetRelationship::factory()->create([
        'from_asset_id' => $assetA->id,
        'to_asset_id'   => $other->id,
        'type'          => AssetRelationship::TYPE_RELATED_TO,
    ]);

    // A delete is a move to the trash now (P2 S4); the raw row delete is the force delete.
    expect(fn () => DB::transaction(fn () => $assetA->forceDelete()))
        ->toThrow(QueryException::class);

    expect(AssetFile::find($fileA->id))->not->toBeNull();
    expect(AssetFile::find($fileB->id))->not->toBeNull();
    expect(AssetRelationship::find($edge->id))->not->toBeNull();
    expect(FileObject::find($shared->id))->not->toBeNull();
});

it('rejects cross-asset version and rendition file ownership through models', function () {
    $owner = Asset::factory()->create();
    $other = Asset::factory()->create();
    $otherFile = AssetFile::factory()->create(['dam_asset_id' => $other->id]);

    expect(fn () => AssetVersion::factory()->create([
        'dam_asset_id'          => $owner->id,
        'primary_asset_file_id' => $otherFile->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => Rendition::factory()->create([
        'dam_asset_id'         => $owner->id,
        'source_asset_file_id' => $otherFile->id,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects cross-asset version file ownership at the database level', function () {
    $owner = Asset::factory()->create();
    $other = Asset::factory()->create();
    $otherFile = AssetFile::factory()->create(['dam_asset_id' => $other->id]);

    expect(fn () => DB::table('dam_asset_versions')->insert([
        'dam_asset_id'          => $owner->id,
        'number'                => 1,
        'primary_asset_file_id' => $otherFile->id,
    ]))->toThrow(QueryException::class);
});

it('rejects cross-asset rendition file ownership at the database level', function () {
    $owner = Asset::factory()->create();
    $other = Asset::factory()->create();
    $otherFile = AssetFile::factory()->create(['dam_asset_id' => $other->id]);

    expect(fn () => DB::table('dam_renditions')->insert([
        'dam_asset_id'         => $owner->id,
        'source_asset_file_id' => $otherFile->id,
        'recipe'               => 'ownership-test',
        'status'               => Rendition::STATUS_QUEUED,
    ]))->toThrow(QueryException::class);
});

it('accepts same-asset version and rendition file ownership', function () {
    $asset = Asset::factory()->create();
    $assetFile = AssetFile::factory()->create(['dam_asset_id' => $asset->id]);

    $version = AssetVersion::factory()->create([
        'dam_asset_id'          => $asset->id,
        'primary_asset_file_id' => $assetFile->id,
    ]);

    $rendition = Rendition::factory()->create([
        'dam_asset_id'         => $asset->id,
        'source_asset_file_id' => $assetFile->id,
    ]);

    expect($version->primary_asset_file_id)->toBe($assetFile->id);
    expect($rendition->source_asset_file_id)->toBe($assetFile->id);
});

it('refuses direct deletion of an AssetFile referenced by a version', function () {
    $asset = Asset::factory()->create();
    $assetFile = AssetFile::factory()->create(['dam_asset_id' => $asset->id]);
    AssetVersion::factory()->create([
        'dam_asset_id'          => $asset->id,
        'primary_asset_file_id' => $assetFile->id,
    ]);

    expect(fn () => DB::transaction(fn () => $assetFile->delete()))->toThrow(QueryException::class);
    expect(AssetFile::find($assetFile->id))->not->toBeNull();
});

it('enforces one current original attachment per asset', function () {
    $asset = Asset::factory()->create();
    AssetFile::factory()->create([
        'dam_asset_id' => $asset->id,
        'role'         => AssetFile::ROLE_ORIGINAL,
    ]);

    expect(fn () => AssetFile::factory()->create([
        'dam_asset_id' => $asset->id,
        'role'         => AssetFile::ROLE_ORIGINAL,
    ]))->toThrow(QueryException::class);
});

it('surfaces custom relationship types as deletion warnings', function () {
    $asset = Asset::factory()->create();
    $other = Asset::factory()->create();
    $edge = AssetRelationship::factory()->create([
        'from_asset_id' => $other->id,
        'to_asset_id'   => $asset->id,
        'type'          => 'client-custom-dependency',
    ]);

    $analysis = app(AssetDeletionService::class)->analyze($asset);

    expect($analysis['blocking'])->toBe([]);
    expect($analysis['warnings'])->toHaveCount(1);
    expect($analysis['warnings'][0]['id'])->toBe($edge->id);
    expect($analysis['warnings'][0]['type'])->toBe('client-custom-dependency');
});

it('uses the explicit asset graph indexes in PostgreSQL query plans at realistic cardinality', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-specific query-plan coverage.');
    }

    $stamp = now();
    $prefix = 'assets/Root/plan-'.str_replace('.', '-', uniqid('', true));
    $assetRows = [];

    for ($i = 0; $i < 150; $i++) {
        $assetRows[] = [
            'file_name'  => "plan-{$i}.bin",
            'file_type'  => 'document',
            'file_size'  => $i + 1,
            'mime_type'  => 'application/octet-stream',
            'extension'  => 'bin',
            'path'       => "{$prefix}/{$i}.bin",
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    DB::table('dam_assets')->insert($assetRows);
    $assetIds = DB::table('dam_assets')->where('path', 'like', $prefix.'/%')->orderBy('id')->pluck('id');
    $targetId = (int) $assetIds->last();
    $objectRows = [];

    foreach ($assetIds->take(149)->values() as $i => $assetId) {
        $objectRows[] = [
            'sha256'    => hash('sha256', $prefix.'-'.$i),
            'size'      => $i + 1,
            'disk'      => 'private',
            'path'      => "{$prefix}/object-{$i}.bin",
            'mime_type' => 'application/octet-stream',
            'created_at'=> $stamp,
        ];
    }

    DB::table('dam_file_objects')->insert($objectRows);
    $objectIds = DB::table('dam_file_objects')->where('path', 'like', $prefix.'/object-%')->orderBy('id')->pluck('id');
    $relationshipRows = [];
    $attachmentRows = [];
    $renditionRows = [];

    foreach ($assetIds->take(149)->values() as $i => $assetId) {
        $relationshipRows[] = [
            'from_asset_id' => $assetId,
            'to_asset_id'   => $targetId,
            'type'          => 'plan-'.$i,
            'created_at'    => $stamp,
            'updated_at'    => $stamp,
        ];
        $attachmentRows[] = [
            'dam_asset_id'   => $assetId,
            'file_object_id' => $objectIds[$i],
            'role'           => AssetFile::ROLE_ORIGINAL,
            'sort'           => 0,
            'created_at'     => $stamp,
        ];
        $renditionRows[] = [
            'dam_asset_id'          => $assetId,
            'recipe'                => 'plan',
            'status'                => Rendition::STATUS_DONE,
            'output_file_object_id' => $objectIds[$i],
            'created_at'            => $stamp,
            'updated_at'            => $stamp,
        ];
    }

    DB::table('dam_asset_relationships')->insert($relationshipRows);
    DB::table('dam_asset_files')->insert($attachmentRows);
    DB::table('dam_renditions')->insert($renditionRows);

    DB::statement('ANALYZE dam_asset_relationships, dam_asset_files, dam_renditions, dam_file_objects');
    DB::statement('SET LOCAL enable_seqscan = off');

    $plans = [
        DB::select('EXPLAIN SELECT * FROM dam_asset_relationships WHERE to_asset_id = ?', [$targetId]),
        DB::select('EXPLAIN SELECT * FROM dam_asset_files WHERE file_object_id = ?', [$objectIds[0]]),
        DB::select('EXPLAIN SELECT * FROM dam_renditions WHERE dam_asset_id = ?', [$assetIds[0]]),
        DB::select('EXPLAIN SELECT * FROM dam_renditions WHERE output_file_object_id = ?', [$objectIds[0]]),
        DB::select('EXPLAIN SELECT * FROM dam_file_objects WHERE disk = ? AND path = ?', ['private', "{$prefix}/object-0.bin"]),
    ];
    $planText = collect($plans)->flatten(1)->map(fn ($row) => $row->{'QUERY PLAN'})->implode("\n");

    expect($planText)->toContain('dam_asset_relationships_to_asset_id_index');
    expect($planText)->toContain('dam_asset_files_file_object_id_index');
    expect($planText)->toContain('dam_renditions_dam_asset_id_index');
    expect($planText)->toContain('dam_renditions_output_file_object_id_index');
    expect($planText)->toContain('dam_file_objects_disk_path_index');
});

it('serializes an asset critical section across two real PostgreSQL connections', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL two-connection concurrency coverage.');
    }

    $baseConnection = config('database.connections.'.config('database.default'));
    config([
        'database.connections.mothership_concurrency_a' => $baseConnection,
        'database.connections.mothership_concurrency_b' => $baseConnection,
    ]);
    DB::purge('mothership_concurrency_a');
    DB::purge('mothership_concurrency_b');
    $connectionA = DB::connection('mothership_concurrency_a');
    $connectionB = DB::connection('mothership_concurrency_b');
    $path = 'assets/Root/concurrency-'.str_replace('.', '-', uniqid('', true)).'.bin';
    $assetId = $connectionA->table('dam_assets')->insertGetId([
        'file_name'  => basename($path),
        'file_type'  => 'document',
        'file_size'  => 1,
        'mime_type'  => 'application/octet-stream',
        'extension'  => 'bin',
        'path'       => $path,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $connectionA->beginTransaction();
        $connectionA->table('dam_assets')->where('id', $assetId)->lockForUpdate()->firstOrFail();
        $connectionB->statement("SET lock_timeout TO '150ms'");

        expect(fn () => $connectionB->table('dam_assets')->where('id', $assetId)->update(['file_size' => 2]))
            ->toThrow(QueryException::class);

        $connectionA->rollBack();

        expect($connectionB->table('dam_assets')->where('id', $assetId)->update(['file_size' => 2]))->toBe(1);
    } finally {
        if ($connectionA->transactionLevel() > 0) {
            $connectionA->rollBack();
        }

        $connectionB->table('dam_assets')->where('id', $assetId)->delete();
        DB::disconnect('mothership_concurrency_a');
        DB::disconnect('mothership_concurrency_b');
    }
});

it('observer links a FileObject and original AssetFile when an asset is created with a path', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $content = 'mothership-observer-test-content';
    $path = 'assets/Root/observer-test.txt';
    Storage::disk($disk)->put($path, $content);

    $asset = Asset::create([
        'file_name' => 'observer-test.txt',
        'file_type' => 'document',
        'file_size' => strlen($content),
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => $path,
    ]);

    $expectedSha256 = hash('sha256', $content);

    $fileObject = FileObject::where('sha256', $expectedSha256)->first();
    expect($fileObject)->not->toBeNull();
    expect($fileObject->size)->toBe(strlen($content));
    expect($fileObject->disk)->toBe($disk);
    expect($fileObject->path)->toBe('assets/.objects/'.substr($expectedSha256, 0, 2).'/'.$expectedSha256);

    $assetFile = AssetFile::where('dam_asset_id', $asset->id)
        ->where('file_object_id', $fileObject->id)
        ->where('role', AssetFile::ROLE_ORIGINAL)
        ->first();

    expect($assetFile)->not->toBeNull();
    expect($assetFile->sort)->toBe(0);
});

it('observer does not fail asset creation when the file is missing from storage', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $asset = Asset::create([
        'file_name' => 'missing.txt',
        'file_type' => 'document',
        'file_size' => 10,
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'path'      => 'assets/Root/missing.txt',
    ]);

    expect($asset->exists)->toBeTrue();
    expect(AssetFile::where('dam_asset_id', $asset->id)->count())->toBe(0);
});

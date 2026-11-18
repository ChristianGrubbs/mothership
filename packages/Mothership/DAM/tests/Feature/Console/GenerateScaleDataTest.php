<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;

beforeEach(function () {
    $this->loginAsAdmin();

    // A fixture dir, never the Library tree (ADR 0009 slice 3): the seeder ingests each distinct
    // seed file once into a blob and shares it across every generated asset.
    $this->sourceDir = sys_get_temp_dir().'/scale-data-fixtures-'.uniqid();
    mkdir($this->sourceDir, 0755, true);
    file_put_contents($this->sourceDir.'/sample.jpg', str_repeat("\0", 1024));
});

afterEach(function () {
    @unlink($this->sourceDir.'/sample.jpg');
    @rmdir($this->sourceDir);
});

it('creates the requested number of directories', function () {
    $existingDirs = Directory::count();

    $this->artisan('dam:generate-scale-data', [
        '--assets'      => 100,
        '--directories' => 20,
        '--chunk'       => 50,
        '--source-dir'  => $this->sourceDir,
    ])->assertSuccessful();

    expect(Directory::count())->toBeGreaterThanOrEqual($existingDirs + 20);
});

it('creates the requested number of assets', function () {
    $existingAssets = DB::table('dam_assets')->count();

    $this->artisan('dam:generate-scale-data', [
        '--assets'      => 80,
        '--directories' => 15,
        '--chunk'       => 40,
        '--source-dir'  => $this->sourceDir,
    ])->assertSuccessful();

    expect(DB::table('dam_assets')->count())->toBe($existingAssets + 80);
});

it('creates pivot records linking each asset to its directory', function () {
    $existingPivots = DB::table('dam_asset_directory')->count();

    $this->artisan('dam:generate-scale-data', [
        '--assets'      => 60,
        '--directories' => 10,
        '--chunk'       => 30,
        '--source-dir'  => $this->sourceDir,
    ])->assertSuccessful();

    expect(DB::table('dam_asset_directory')->count())->toBe($existingPivots + 60);
});

it('sets valid nested-set columns on all new directories', function () {
    $this->artisan('dam:generate-scale-data', [
        '--assets'      => 10,
        '--directories' => 15,
        '--chunk'       => 15,
        '--source-dir'  => $this->sourceDir,
    ])->assertSuccessful();

    expect(Directory::whereNull('_lft')->count())->toBe(0);
    expect(Directory::whereNull('_rgt')->count())->toBe(0);
});

it('creates graph rows sharing one blob per seed file, with no physical file on disk', function () {
    $this->artisan('dam:generate-scale-data', [
        '--assets'      => 20,
        '--directories' => 5,
        '--chunk'       => 20,
        '--source-dir'  => $this->sourceDir,
    ])->assertSuccessful();

    $storageRoot = storage_path('app/private');
    $rows = DB::table('dam_assets')->orderByDesc('id')->limit(20)->get(['id', 'path']);

    $rows->each(function ($row) use ($storageRoot) {
        expect(file_exists($storageRoot.'/'.$row->path))->toBeFalse(
            "Expected no physical file at: {$storageRoot}/{$row->path}"
        );
    });

    $fileObjectIds = DB::table('dam_asset_files')
        ->where('role', 'original')
        ->whereIn('dam_asset_id', $rows->pluck('id'))
        ->pluck('file_object_id');

    // Every generated asset has an original row, and they all share the one blob the single
    // fixture file ingested to (the fan-out C17 asks for, not one blob per asset).
    expect($fileObjectIds)->toHaveCount(20);
    expect($fileObjectIds->unique())->toHaveCount(1);

    $object = FileObject::findOrFail($fileObjectIds->first());
    expect(app(ContentStore::class)->holdsBlob($object))->toBeTrue()
        ->and($object->sha256)->toBe(hash_file('sha256', $this->sourceDir.'/sample.jpg'));
});

it('fails clearly when no --source-dir is given and no FileObjects exist to reuse', function () {
    $this->artisan('dam:generate-scale-data', [
        '--assets'      => 10,
        '--directories' => 2,
        '--chunk'       => 10,
    ])->assertFailed();

    expect(DB::table('dam_assets')->count())->toBe(0);
});

it('dry-run makes no DB or filesystem changes', function () {
    $dirsBefore = Directory::count();
    $assetsBefore = DB::table('dam_assets')->count();
    $pivotsBefore = DB::table('dam_asset_directory')->count();

    $this->artisan('dam:generate-scale-data', [
        '--assets'      => 100,
        '--directories' => 20,
        '--dry-run'     => true,
    ])->assertSuccessful();

    expect(Directory::count())->toBe($dirsBefore);
    expect(DB::table('dam_assets')->count())->toBe($assetsBefore);
    expect(DB::table('dam_asset_directory')->count())->toBe($pivotsBefore);
});

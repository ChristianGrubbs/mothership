<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Database\Seeders\DamDemoDataSeeder;
use Mothership\DAM\Database\Seeders\DirectoryTableSeeder;
use Mothership\DAM\Helpers\DamDemoDataInstaller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());

    $this->damResetAssetTables();

    app(DirectoryTableSeeder::class)->run();
});

function bundledDamDemoAssetCount(): int
{
    return count(File::allFiles(dirname(__DIR__, 2).'/src/Database/Data/demo-assets/Root'));
}

it('seeds 15 directories under root', function () {
    app(DamDemoDataSeeder::class)->run();

    expect(
        DB::table('dam_directories')->whereNotNull('parent_id')->count()
    )->toBe(15);
});

it('seeds every bundled demo asset', function () {
    app(DamDemoDataSeeder::class)->run();

    expect(DB::table('dam_assets')->count())->toBe(bundledDamDemoAssetCount());
});

it('seeds 4 tags', function () {
    app(DamDemoDataSeeder::class)->run();

    expect(DB::table('dam_tags')->count())->toBe(4);
});

it('links every asset to exactly one directory', function () {
    app(DamDemoDataSeeder::class)->run();

    expect(DB::table('dam_asset_directory')->count())->toBe(bundledDamDemoAssetCount());
});

it('attaches every seeded asset to the asset graph as an original', function () {
    app(DamDemoDataSeeder::class)->run();

    expect(
        DB::table('dam_asset_files')->where('role', 'original')->count()
    )->toBe(bundledDamDemoAssetCount());
});

it('ingests bundled demo assets into blobs, writing no on-disk Root tree', function () {
    app(DamDemoDataSeeder::class)->run();

    // No assets/Root physical tree (ADR 0009 slice 3): every bundled file went into a blob instead.
    expect(Storage::disk(Directory::getAssetDisk())->allFiles('assets/Root'))->toBe([]);

    $sourceRoot = dirname(__DIR__, 2).'/src/Database/Data/demo-assets/Root';
    $file = File::allFiles($sourceRoot)[0];
    $asset = Asset::where('file_name', $file->getFilename())->firstOrFail();
    $store = app(ContentStore::class);
    $stream = $store->open($store->current($asset));

    expect(hash('sha256', stream_get_contents($stream)))->toBe(hash_file('sha256', $file->getRealPath()));
    fclose($stream);
});

it('creates correct file_type for image assets', function () {
    app(DamDemoDataSeeder::class)->run();

    expect(
        DB::table('dam_assets')->where('extension', 'jpg')->value('file_type')
    )->toBe('image');
});

it('creates correct file_type for document assets', function () {
    app(DamDemoDataSeeder::class)->run();

    expect(
        DB::table('dam_assets')->where('extension', 'pdf')->value('file_type')
    )->toBe('document');
});

it('assigns at least one tag-to-asset pivot row', function () {
    app(DamDemoDataSeeder::class)->run();

    expect(DB::table('dam_asset_tag')->count())->toBeGreaterThan(0);
});

it('is idempotent — running twice does not double-seed assets', function () {
    app(DamDemoDataSeeder::class)->run();
    app(DamDemoDataSeeder::class)->run();

    expect(DB::table('dam_assets')->count())->toBe(bundledDamDemoAssetCount());
});

it('isAlreadySeeded returns false on fresh database', function () {
    expect(app(DamDemoDataInstaller::class)->isAlreadySeeded())->toBeFalse();
});

it('isAlreadySeeded returns true after seeding', function () {
    app(DamDemoDataSeeder::class)->run();

    expect(app(DamDemoDataInstaller::class)->isAlreadySeeded())->toBeTrue();
});

it('dam:demo-data command seeds successfully on fresh install', function () {
    $this->artisan('dam:demo-data')
        ->assertExitCode(0)
        ->expectsOutputToContain('seeded successfully');

    expect(DB::table('dam_assets')->count())->toBe(bundledDamDemoAssetCount());
});

it('dam:demo-data command reports already seeded when run twice', function () {
    app(DamDemoDataSeeder::class)->run();

    $this->artisan('dam:demo-data')
        ->assertExitCode(0)
        ->expectsOutputToContain('already present');
});

it('dam:demo-data --force clears and re-seeds to same count', function () {
    app(DamDemoDataSeeder::class)->run();

    $this->artisan('dam:demo-data', ['--force' => true])
        ->expectsConfirmation('This will permanently delete all existing DAM assets. Continue?', 'yes')
        ->assertExitCode(0)
        ->expectsOutputToContain('seeded successfully');

    expect(DB::table('dam_assets')->count())->toBe(bundledDamDemoAssetCount());
    expect(DB::table('dam_asset_files')->where('role', 'original')->count())
        ->toBe(bundledDamDemoAssetCount());
    expect(DB::table('dam_directories')->whereNotNull('parent_id')->count())->toBe(15);
});

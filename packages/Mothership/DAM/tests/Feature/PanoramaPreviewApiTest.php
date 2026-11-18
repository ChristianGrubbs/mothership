<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GeneratePanoramaPreview;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\PanoramaPreviewService;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());

    // QUEUE_CONNECTION=sync (phpunit.xml) makes Laravel's SyncQueue run a
    // dispatched job's handle() immediately, inline, the moment dispatch()
    // is called — it does not honor ->afterCommit() the way a real queue
    // connection would, so the job runs even from inside
    // PanoramaPreviewService::request()'s own DB::transaction(). Before
    // Task 4 implemented GeneratePanoramaPreview::handle(), that was
    // harmless (empty stub). Now handle() is the real worker: it locks the
    // rendition, marks it running, and — since Task 6's
    // tools/media/mothership_360.py doesn't exist in this test environment
    // — fails the CLI process and marks the row failed, all synchronously,
    // before the API call this file is testing even returns. That silently
    // mutates rows this file's assertions expect to stay queued (and, for
    // the concurrent-retry test, turns a correctly-rejected second retry
    // into an accepted one, since a failed row is retryable). This file
    // tests the request/cancel/retry/mode orchestration API, not the
    // worker pipeline — Task 4 owns that in GeneratePanoramaPreviewTest.php
    // — so every test here fakes the bus to restore the original "job
    // never actually runs" assumption these specs were written against.
    Bus::fake();
});

function panoramaAssetWithOriginal(string $name, string $mime, int $width = 3840, int $height = 1920): Asset
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $asset = Asset::factory()->create([
        'file_name' => $name,
        'file_type' => $extension === 'osv' ? 'document' : (str_starts_with($mime, 'image/') ? 'image' : 'video'),
        'mime_type' => $mime,
        'extension' => $extension,
        'path'      => 'assets/Root/'.$name,
        'meta_data' => ['ImageWidth' => $width, 'ImageHeight' => $height],
    ]);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, 'source-fixture');
    $object = FileObject::factory()->create([
        'sha256'    => hash('sha256', $name),
        'disk'      => Directory::getAssetDisk(),
        // Suffix-less, matching what CompoundAssetObserver's putStored() actually records on first
        // ingest — the asset's own `extension` column above is the realistic source of truth.
        'path'      => storeFixtureBlob(hash('sha256', $name), 'source-fixture'),
        'mime_type' => $mime,
        'technical' => ['width' => $width, 'height' => $height],
    ]);
    AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $object->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
    ]);

    return $asset;
}

function panoramaAssetInGrantedDirectory(): Asset
{
    $asset = panoramaAssetWithOriginal('room.jpg', 'image/jpeg');
    $asset->directories()->attach(Directory::factory()->create());

    return $asset;
}

it('collapses duplicate preview requests for the same source and profile', function () {
    // Bus::fake() is already set in beforeEach(); asserted against directly
    // here via Bus::assertDispatchedTimes(), which requires the fake.
    config(['mothership.panorama_processing_enabled' => true]);
    $asset = panoramaAssetWithOriginal('flight.osv', 'application/octet-stream');

    $first = $this->postJson(route('admin.dam.assets.panorama.build', $asset))->assertAccepted()->json('rendition.id');
    $second = $this->postJson(route('admin.dam.assets.panorama.build', $asset))->assertAccepted()->json('rendition.id');

    expect($second)->toBe($first)
        ->and(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(1);
    Bus::assertDispatchedTimes(GeneratePanoramaPreview::class, 1);
});

it('allows viewing state but requires edit permission for mutations', function () {
    $asset = panoramaAssetInGrantedDirectory();
    actAsPlaybackRestrictedAdmin($asset->directories()->first(), ['dam.asset.view']);

    $this->getJson(route('admin.dam.assets.panorama.state', $asset))->assertOk();
    $this->postJson(route('admin.dam.assets.panorama.build', $asset))->assertForbidden();
    $this->patchJson(route('admin.dam.assets.panorama.mode', $asset), ['mode' => 'force_flat'])->assertForbidden();
});

it('keeps state and direct playback available while conversion is disabled', function () {
    config(['mothership.panorama_processing_enabled' => false]);
    $asset = panoramaAssetWithOriginal('room.jpg', 'image/jpeg', 4000, 2000);

    $this->getJson(route('admin.dam.assets.panorama.state', $asset))
        ->assertOk()->assertJsonPath('classification.is_panorama', true);
    $this->postJson(route('admin.dam.assets.panorama.build', $asset))->assertStatus(409);
});

it('creates a new rebuild attempt and preserves the completed attempt', function () {
    config(['mothership.panorama_processing_enabled' => true]);
    $asset = panoramaAssetWithOriginal('flight.osv', 'application/octet-stream');
    $done = Rendition::factory()->create([
        'dam_asset_id'         => $asset->id,
        'source_asset_file_id' => $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->value('id'),
        'recipe'               => Rendition::RECIPE_PANORAMA_4K,
        'fingerprint'          => str_repeat('b', 64),
        'status'               => Rendition::STATUS_DONE,
    ]);

    $this->postJson(route('admin.dam.assets.panorama.rebuild', $asset))->assertAccepted();
    expect(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(2)
        ->and($done->refresh()->status)->toBe(Rendition::STATUS_DONE);
});

it('records cancellation and validates the mode enum', function () {
    config(['mothership.panorama_processing_enabled' => true]);
    $asset = panoramaAssetWithOriginal('flight.osv', 'application/octet-stream');
    $rendition = app(PanoramaPreviewService::class)->request($asset);

    $this->postJson(route('admin.dam.assets.panorama.cancel', $asset))->assertOk();
    expect($rendition->refresh()->cancel_requested_at)->not->toBeNull();
    $this->patchJson(route('admin.dam.assets.panorama.mode', $asset), ['mode' => 'invalid'])->assertUnprocessable();
});

it('forbids mutation and state access without directory access', function () {
    $asset = panoramaAssetInGrantedDirectory();
    $otherDirectory = Directory::factory()->create();
    actAsPlaybackRestrictedAdmin($otherDirectory, ['dam.asset.view', 'dam.asset.edit']);

    $this->getJson(route('admin.dam.assets.panorama.state', $asset))->assertNotFound();
    $this->postJson(route('admin.dam.assets.panorama.build', $asset))->assertNotFound();
});

it('retries a failed attempt and a cancelled attempt into new queued rows', function () {
    config(['mothership.panorama_processing_enabled' => true]);

    $failedAsset = panoramaAssetWithOriginal('failed-flight.osv', 'application/octet-stream');
    Rendition::factory()->create([
        'dam_asset_id'         => $failedAsset->id,
        'source_asset_file_id' => $failedAsset->files()->where('role', AssetFile::ROLE_ORIGINAL)->value('id'),
        'recipe'               => Rendition::RECIPE_PANORAMA_4K,
        'fingerprint'          => str_repeat('c', 64),
        'status'               => Rendition::STATUS_FAILED,
        'error'                => 'worker crashed',
    ]);

    $this->postJson(route('admin.dam.assets.panorama.retry', $failedAsset))->assertAccepted();
    expect(Rendition::where('dam_asset_id', $failedAsset->id)->where('status', Rendition::STATUS_QUEUED)->count())->toBe(1);

    $cancelledAsset = panoramaAssetWithOriginal('cancelled-flight.osv', 'application/octet-stream');
    Rendition::factory()->create([
        'dam_asset_id'         => $cancelledAsset->id,
        'source_asset_file_id' => $cancelledAsset->files()->where('role', AssetFile::ROLE_ORIGINAL)->value('id'),
        'recipe'               => Rendition::RECIPE_PANORAMA_4K,
        'fingerprint'          => str_repeat('d', 64),
        'status'               => Rendition::STATUS_CANCELLED,
        'cancel_requested_at'  => now(),
    ]);

    $this->postJson(route('admin.dam.assets.panorama.retry', $cancelledAsset))->assertAccepted();
    expect(Rendition::where('dam_asset_id', $cancelledAsset->id)->where('status', Rendition::STATUS_QUEUED)->count())->toBe(1);
});

it('fingerprints a new attempt differently once the source checksum changes', function () {
    config(['mothership.panorama_processing_enabled' => true]);
    $asset = panoramaAssetWithOriginal('flight.osv', 'application/octet-stream');

    $firstId = $this->postJson(route('admin.dam.assets.panorama.build', $asset))->assertAccepted()->json('rendition.id');
    $firstFingerprint = Rendition::findOrFail($firstId)->fingerprint;

    $originalFile = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->first();
    $replaced = hash('sha256', 'replaced-bytes');
    $originalFile->fileObject->update(['sha256' => $replaced, 'path' => storeFixtureBlob($replaced, 'replaced-bytes')]);

    $secondId = $this->postJson(route('admin.dam.assets.panorama.build', $asset))->assertAccepted()->json('rendition.id');
    $secondFingerprint = Rendition::findOrFail($secondId)->fingerprint;

    expect($secondId)->not->toBe($firstId)
        ->and($secondFingerprint)->not->toBe($firstFingerprint)
        ->and(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(2);
});

it('lets a non-bypass actor holding genuine dam.asset.edit plus directory access build a preview', function () {
    // Every other success path in this file runs as loginAsAdmin() (a
    // super admin where DirectoryPermissionService::bypass() is true), so
    // none of them actually exercises bouncer()->hasPermission('dam.asset.edit')
    // — a misspelled permission string would still pass. This test uses a
    // custom-role actor scoped to exactly one directory and one permission,
    // proving the string the controller checks is the string that grants access.
    config(['mothership.panorama_processing_enabled' => true]);
    $directory = Directory::factory()->create();
    $asset = panoramaAssetWithOriginal('flight.osv', 'application/octet-stream');
    $asset->directories()->attach($directory->id);
    actAsPlaybackRestrictedAdmin($directory, ['dam.asset.edit']);

    $this->postJson(route('admin.dam.assets.panorama.build', $asset))->assertAccepted();

    expect(Rendition::where('dam_asset_id', $asset->id)->where('status', Rendition::STATUS_QUEUED)->count())->toBe(1);
});

it('persists a valid panorama mode change', function () {
    config(['mothership.panorama_processing_enabled' => true]);
    $asset = panoramaAssetWithOriginal('room.jpg', 'image/jpeg');

    $this->patchJson(route('admin.dam.assets.panorama.mode', $asset), ['mode' => Asset::PANORAMA_FORCE_360])
        ->assertOk()
        ->assertJsonPath('asset.panorama_mode', Asset::PANORAMA_FORCE_360);

    expect($asset->fresh()->panorama_mode)->toBe(Asset::PANORAMA_FORCE_360);
});

it('allows a mode change while conversion processing is disabled', function () {
    config(['mothership.panorama_processing_enabled' => false]);
    $asset = panoramaAssetWithOriginal('room.jpg', 'image/jpeg');

    $this->patchJson(route('admin.dam.assets.panorama.mode', $asset), ['mode' => Asset::PANORAMA_FORCE_FLAT])
        ->assertOk()
        ->assertJsonPath('asset.panorama_mode', Asset::PANORAMA_FORCE_FLAT);

    expect($asset->fresh()->panorama_mode)->toBe(Asset::PANORAMA_FORCE_FLAT);
});

it('serializes two concurrent retries of the same failed attempt into one queued row', function () {
    config(['mothership.panorama_processing_enabled' => true]);
    $asset = panoramaAssetWithOriginal('flight.osv', 'application/octet-stream');
    Rendition::factory()->create([
        'dam_asset_id'         => $asset->id,
        'source_asset_file_id' => $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->value('id'),
        'recipe'               => Rendition::RECIPE_PANORAMA_4K,
        'fingerprint'          => str_repeat('e', 64),
        'status'               => Rendition::STATUS_FAILED,
    ]);

    $this->postJson(route('admin.dam.assets.panorama.retry', $asset))->assertAccepted();
    $this->postJson(route('admin.dam.assets.panorama.retry', $asset))->assertStatus(422);

    expect(Rendition::where('dam_asset_id', $asset->id)->where('status', Rendition::STATUS_QUEUED)->count())->toBe(1)
        ->and(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(2);
});

it('accepts a build for a directly playable panorama only when the browser rejected it', function () {
    // A direct_candidate is normally refused: the server decided the browser
    // can play the original, so a proxy would be waste. But the server only
    // reads container metadata — a stripped-metadata HEVC classifies as
    // directly playable and still fails to decode. When the client reports
    // that verdict, conversion is exactly the remedy the feature exists for.
    config(['mothership.panorama_processing_enabled' => true]);
    $asset = panoramaAssetWithOriginal('room.mp4', 'video/mp4');

    expect($asset->panoramaClassification()['direct_candidate'])->toBeTrue();

    $this->postJson(route('admin.dam.assets.panorama.build', $asset))->assertStatus(422);

    $this->postJson(route('admin.dam.assets.panorama.build', $asset), ['browser_rejected' => true])
        ->assertAccepted();

    expect(Rendition::where('dam_asset_id', $asset->id)->where('status', Rendition::STATUS_QUEUED)->count())->toBe(1);
});

it('rejects a non-boolean browser rejection hint', function () {
    config(['mothership.panorama_processing_enabled' => true]);
    $asset = panoramaAssetWithOriginal('room.mp4', 'video/mp4');

    $this->postJson(route('admin.dam.assets.panorama.build', $asset), ['browser_rejected' => 'sure'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('browser_rejected');
});

<?php

use Illuminate\Support\Facades\Schema;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Rendition;

it('persists panorama mode and rendition attempt state', function () {
    expect(Schema::hasColumns('dam_renditions', [
        'fingerprint', 'progress', 'cancel_requested_at', 'started_at', 'finished_at',
    ]))->toBeTrue();

    $asset = Asset::factory()->create(['panorama_mode' => Asset::PANORAMA_FORCE_360]);
    $rendition = Rendition::factory()->create([
        'dam_asset_id'       => $asset->id,
        'fingerprint'        => str_repeat('a', 64),
        'status'             => Rendition::STATUS_CANCELLED,
        'progress'           => 41,
        'cancel_requested_at'=> now(),
    ]);

    expect($asset->refresh()->panorama_mode)->toBe(Asset::PANORAMA_FORCE_360)
        ->and($rendition->refresh()->progress)->toBe(41)
        ->and($rendition->cancel_requested_at)->not->toBeNull();
});

it('rejects invalid panorama and rendition states', function () {
    expect(fn () => Asset::factory()->create(['panorama_mode' => 'spherical-ish']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Rendition::factory()->create(['status' => 'abandoned']))
        ->toThrow(InvalidArgumentException::class);
});

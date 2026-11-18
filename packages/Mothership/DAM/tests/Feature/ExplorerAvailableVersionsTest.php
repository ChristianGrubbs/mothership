<?php

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;

// The OPT / SVG / other-format chips beside a Library card's type badge (AvailableVersions).

beforeEach(function () {
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Versions '.uniqid()]);

    $this->makeAsset = function (string $name, string $extension): Asset {
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => $name, 'file_type' => 'image', 'extension' => $extension]));
        $asset->directories()->attach($this->folder->id);

        return $asset;
    };

    $this->attach = function (Asset $asset, string $role, ?string $label, string $mime = 'application/octet-stream'): void {
        AssetFile::factory()->create([
            'dam_asset_id'   => $asset->id,
            'role'           => $role,
            'label'          => $label,
            'file_object_id' => FileObject::factory()->create(['mime_type' => $mime])->id,
        ]);
    };

    $this->versionsOf = function (Asset $asset): array {
        $rows = $this->getJson('/admin/dam/explorer/data?directory_id='.$this->folder->id)->assertOk()->json('assets');

        return collect($rows)->firstWhere('id', $asset->id)['versions'];
    };
});

it('lists OPT, SVG, then other attached formats, once each', function () {
    $asset = ($this->makeAsset)('logo.png', 'png');
    ($this->attach)($asset, 'output', 'logo-optimized.jpg');
    ($this->attach)($asset, 'output', 'logo-vectorized.svg');
    ($this->attach)($asset, 'alternate', 'logo.psd');
    ($this->attach)($asset, 'alternate', 'logo copy.PSD');
    ($this->attach)($asset, 'source', null, 'application/zip');

    expect(($this->versionsOf)($asset))->toBe([
        ['kind' => 'optimized', 'label' => 'OPT'],
        ['kind' => 'svg', 'label' => 'SVG'],
        ['kind' => 'type', 'label' => 'PSD'],
        ['kind' => 'type', 'label' => 'ZIP'],
    ]);
});

it('ignores the original, proxies, thumbnails, old versions and the asset\'s own format', function () {
    $asset = ($this->makeAsset)('photo.jpeg', 'jpeg');
    ($this->attach)($asset, 'original', 'photo.jpeg');
    ($this->attach)($asset, 'proxy', 'photo-proxy.mp4');
    ($this->attach)($asset, 'thumbnail', 'thumb.webp');
    ($this->attach)($asset, 'version', 'photo-v1.tif');
    ($this->attach)($asset, 'alternate', 'photo-small.jpg');

    expect(($this->versionsOf)($asset))->toBe([]);
});

<?php

declare(strict_types=1);

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\CaptureProfile;
use Mothership\DAM\Models\Directory;

/*
 * A full admin passes the access seam for any id, because the seam asks about rows and a missing one has no row to
 * hide. Every route that names an asset must therefore refuse a missing id itself, with the 404 a hidden asset gets,
 * whatever the payload (valid or incomplete) and before it touches anything.
 */

dataset('missing-asset-calls', function () {
    $image = fn (string $route, array $payload) => [$route, 'post', fn (int $id) => ['id' => $id], $payload];
    $asset = fn (string $route, string $verb, array $payload = []) => [$route, $verb, fn (int $id) => ['asset' => $id], $payload];

    return [
        'image resize'          => $image('admin.dam.assets.image_edit.resize', ['width' => 10, 'height' => 10]),
        'image adjust'          => $image('admin.dam.assets.image_edit.adjust', ['brightness' => 10]),
        'image filters'         => $image('admin.dam.assets.image_edit.filters', ['filter' => 'grayscale']),
        'image transform'       => $image('admin.dam.assets.image_edit.transform', ['rotate' => 90]),
        'image bg color'        => $image('admin.dam.assets.image_edit.bg_color', ['color' => '#ffffff']),
        'image bg upload'       => $image('admin.dam.assets.image_edit.bg_upload', []),
        'image bg ai'           => $image('admin.dam.assets.image_edit.bg_ai', ['prompt' => 'a plain studio wall']),
        'image bg color normal' => $image('admin.dam.assets.image_edit.bg_color_normal', ['color' => '#ffffff']),
        'image bg preview'      => $image('admin.dam.assets.image_edit.bg_preview', ['color' => '#ffffff']),
        'capture show'          => $asset('admin.dam.assets.capture.show', 'get'),
        'capture update'        => $asset('admin.dam.assets.capture.update', 'put', ['profile_id' => 1]),
        'capture ungroup'       => $asset('admin.dam.assets.capture.destroy', 'delete'),
        'file store'            => ['admin.dam.assets.files.store', 'post', fn (int $id) => ['asset' => $id], ['role' => 'sidecar']],
        'file chunks'           => $asset('admin.dam.assets.files.chunks', 'post', ['role' => 'sidecar', 'file_name' => 'a.txt', 'size' => 4]),
        'file destroy'          => ['admin.dam.assets.files.destroy', 'delete', fn (int $id) => ['asset' => $id, 'assetFile' => 1], []],
        'comment create'        => ['admin.dam.asset.comment.store', 'post', fn (int $id) => ['id' => $id], ['comments' => 'hello']],
        'comment resolution'    => ['admin.dam.asset.comment.resolution', 'put', fn (int $id) => ['id' => $id, 'comment' => 1], ['resolved' => true]],
    ];
});

it('answers 404 to a full admin for a missing asset id, with a valid-shape and an empty payload', function (string $route, string $verb, Closure $parameters, array $payload) {
    $this->loginAsAdmin();
    $missing = (int) Asset::withTrashed()->max('id') + 10000;

    foreach ([$payload, []] as $body) {
        $this->json($verb, route($route, $parameters($missing)), $body)->assertNotFound();
    }
})->with('missing-asset-calls');

it('answers 404 to a full admin for a capture-group proposal naming a missing asset id', function () {
    $this->loginAsAdmin();
    $missing = (int) Asset::withTrashed()->max('id') + 10000;
    $folder = Directory::factory()->create();
    $profile = CaptureProfile::query()->create(['name' => 'Raw+JPEG', 'enabled' => true, 'extensions' => ['jpg', 'cr3'], 'priority' => ['jpg', 'cr3']]);

    $this->postJson(route('admin.dam.configuration.capture.apply'), [
        'profile_id' => $profile->id, 'directory_id' => $folder->id,
        'groups'     => [['fingerprint' => str_repeat('a', 64), 'asset_ids' => [$missing]]],
    ])->assertNotFound();
});

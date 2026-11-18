<?php

declare(strict_types=1);

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\FileObject;

beforeEach(function (): void {
    $this->loginAsAdmin();
});

it('reports the displayed aspect ratio from original metadata', function (string $type, array $technical, array $metadata, ?string $ratio): void {
    $asset = Asset::factory()->create([
        'file_type' => $type, 'extension' => $type === 'video' ? 'mp4' : 'jpg', 'meta_data' => $metadata,
    ]);
    $file = FileObject::factory()->create(['technical' => $technical]);
    AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $file->id, 'role' => AssetFile::ROLE_ORIGINAL]);
    $response = $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk();
    if ($ratio === null) {
        $response->assertJsonPath('aspect_ratio', null);
    } else {
        $response->assertJsonPath('aspect_ratio.ratio', $ratio);
        expect($response->json('aspect_ratio.decimal'))->toBeGreaterThan(0);
    }
})->with([
    'landscape'                             => ['image', ['width' => 1920, 'height' => 1080], [], '16:9'],
    'portrait'                              => ['image', ['width' => 1080, 'height' => 1920], [], '9:16'],
    'square'                                => ['image', ['width' => 600, 'height' => 600], [], '1:1'],
    'exif rotation'                         => ['image', [], ['ImageWidth' => 4000, 'ImageHeight' => 3000, 'Orientation' => 6], '3:4'],
    'exif human-readable mirrored rotation' => ['image', [], ['ImageWidth' => 4000, 'ImageHeight' => 3000, 'Orientation' => 'Mirror horizontal and rotate 270 CW'], '3:4'],
    'exif nested rotation'                  => ['image', [], ['exif' => ['ImageWidth' => 4000, 'ImageHeight' => 3000, 'Orientation' => 'Rotate 90 CW']], '3:4'],
    'video rotation'                        => ['video', [], ['ImageWidth' => 1920, 'ImageHeight' => 1080, 'Rotation' => 90], '9:16'],
    'anamorphic pixels'                     => ['video', [], ['ImageWidth' => 720, 'ImageHeight' => 576, 'PixelAspectRatio' => '16:15'], '4:3'],
    'rotated anamorphic'                    => ['video', [], ['ImageWidth' => 720, 'ImageHeight' => 576, 'PixelAspectRatio' => '16:15', 'Rotation' => -90], '3:4'],
    'imported resolution'                   => ['image', [], ['legacy' => ['fields' => ['tech_resolution' => '4096x3072']]], '4:3'],
    'imported video display ratio'          => ['video', [], ['legacy' => ['fields' => ['tech_resolution' => '720x576', 'tech_aspect_ratio' => '16:9']]], '16:9'],
    'zero width is unknown'                 => ['image', ['width' => 0, 'height' => 1080], [], null],
    'missing is unknown'                    => ['image', [], [], null],
]);

it('uses an SVG viewBox when no intrinsic dimensions are recorded', function (): void {
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => 'svg', 'meta_data' => ['ViewBox' => '0 0 150 100']]);
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->assertJsonPath('aspect_ratio.ratio', '3:2');
});

it('makes the ratio selectable in custom layouts and visible on the edit card', function (): void {
    $asset = Asset::factory()->create(['file_type' => 'image', 'meta_data' => ['ImageWidth' => 400, 'ImageHeight' => 300]]);
    $html = $this->get(route('admin.dam.assets.edit', $asset->id).'?properties')->assertOk()->getContent();
    expect(str_contains($html, 'Aspect ratio'))->toBeTrue('Aspect ratio label must be visible');
    expect(str_contains($html, '4:3'))->toBeTrue('Normalized ratio must appear on the edit card');
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => [
        ['match' => '*', 'fields' => ['aspect_ratio'], 'hide_empty' => false],
    ]])->assertOk();
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()
        ->assertJsonPath('detail_fields.0.key', 'aspect_ratio')->assertJsonPath('detail_fields.0.value', '4:3 (1.333)');
});

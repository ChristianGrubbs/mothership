<?php

use Mothership\DAM\Http\Controllers\HomeController;
use Mothership\DAM\Models\Asset;

it('labels 3D model files "3D model" and other files by their type', function (string $fileType, string $extension, string $expected) {
    $asset = new Asset(['file_type' => $fileType, 'extension' => $extension]);

    expect(HomeController::typeLabel($asset))->toBe($expected);
})->with([
    'glb'  => ['document', 'glb', '3D model'],
    'stl'  => ['document', 'STL', '3D model'],
    'fbx'  => ['document', 'fbx', '3D model'],
    'pdf'  => ['document', 'pdf', 'Document'],
    'png'  => ['image', 'png', 'Image'],
    'mp4'  => ['video', 'mp4', 'Video'],
]);

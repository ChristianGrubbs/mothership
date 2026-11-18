<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GeneratePdfThumbnail;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\DerivativeImages;

it('removes all PDF thumbnail temporary files on success and failure', function (bool $valid) {
    Storage::fake(Directory::getAssetDisk());
    $bytes = $valid ? file_get_contents(base_path('tests/e2e-pw/assets/sample.pdf')) : 'invalid PDF';
    // Written before the row is created so the graph observer ingests it as the original (ADR 0009).
    Storage::disk(Directory::getAssetDisk())->put('assets/cleanup.pdf', $bytes);
    $asset = Asset::factory()->create([
        'file_name' => 'cleanup.pdf',
        'file_type' => 'document',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'path'      => 'assets/cleanup.pdf',
    ]);
    $pattern = sys_get_temp_dir().'/dampdf_*';
    $before = glob($pattern);

    try {
        (new GeneratePdfThumbnail($asset->id))->handle();

        expect(Storage::disk(Directory::getAssetDisk())->exists(DerivativeImages::poster(hash('sha256', $bytes))))
            ->toBe($valid);
        expect(array_values(array_diff(glob($pattern), $before)))->toBe([]);
    } finally {
        foreach (array_diff(glob($pattern), $before) as $path) {
            unlink($path);
        }
    }
})->with([true, false]);

<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

/*
 * The details layout renders every tab slot but outputs only the active one, and the Relationships slot (picker)
 * renders before Files. The shared Library lightbox that Files "Open" talks to (dam-open-preview) must therefore be
 * emitted by whichever tab is showing, not spent by a page-wide @once on a slot whose markup is discarded.
 */
it('mounts exactly one shared lightbox on the Files and Relationships tabs and none on the default tab', function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $asset = Asset::factory()->create();
    $mounts = fn (string $query) => substr_count(
        $this->get(route('admin.dam.assets.edit', $asset->id).$query)->assertOk()->getContent(),
        '<v-dam-grid-preview-modal>',
    );

    expect($mounts('?files'))->toBe(1)
        ->and($mounts('?relationships'))->toBe(1)
        ->and($mounts(''))->toBe(0);
});

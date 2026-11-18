<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\WebPagePolicy;

uses(DatabaseTransactions::class);

it('renders the frame with exactly the sandbox token set and never allow-same-origin', function (string $view) {
    $source = file_get_contents(base_path('packages/Mothership/DAM/src/Resources/views/'.$view));
    preg_match_all('/sandbox="([^"]*)"/', $source, $m);

    // Every sandboxed frame in the view (the shared viewer has the historic default stage and a member-page stage) spells
    // out the one constant, never a literal token list.
    expect(array_values(array_unique($m[1])))->toBe(['{{ \Mothership\DAM\Services\WebPagePolicy::SANDBOX }}'])
        ->and(WebPagePolicy::SANDBOX)->toBe('allow-scripts allow-popups allow-forms allow-modals');
})->with([
    'asset/preview-modal/web/frame-script.blade.php',
    'share/public/asset.blade.php',
]);

it('opens the full screen view with the sandboxed frame', function () {
    $this->loginAsAdmin();
    $asset = Asset::factory()->create(['file_type' => 'web', 'file_name' => 'p.html', 'extension' => 'html', 'mime_type' => 'text/html', 'path' => 'assets/Root/p.html']);
    $asset->directories()->attach(1);

    $this->get(route('admin.dam.pages.show', ['id' => $asset->id]))
        ->assertOk()
        ->assertSee('v-dam-web-page-frame', false)
        ->assertSee('sandbox="'.WebPagePolicy::SANDBOX.'"', false);
});

it('routes a web asset to the frame in the preview modal', function () {
    $blade = file_get_contents(base_path('packages/Mothership/DAM/src/Resources/views/asset/preview-modal/viewer-modal.blade.php'));

    expect($blade)->toContain("previewData.file_type === 'web'")->toContain('v-dam-web-page-frame');
});

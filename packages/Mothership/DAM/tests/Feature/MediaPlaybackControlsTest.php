<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

beforeEach(function (): void {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null]);
});

it('serves authenticated audio byte ranges for seeking', function (string $range, int $status, string $contentRange, string $body): void {
    $asset = Asset::factory()->create(['file_type' => 'audio', 'extension' => 'wav', 'mime_type' => 'audio/wav', 'path' => 'assets/range.wav']);
    putAssetContent($asset, 'RIFF0123456789WAVE');
    $response = $this->withHeader('Range', $range)->get(route('admin.dam.file.preview', ['path' => $asset->path]));
    $response->assertStatus($status)->assertHeader('Content-Range', $contentRange);
    if ($status === 206) {
        $response->assertHeader('Content-Length', (string) strlen($body));
        ob_start();
        $response->baseResponse->sendContent();
        $actual = ob_get_clean();
        expect($actual)->toBe($body);
    }
})->with([
    'first bytes'   => ['bytes=0-3', 206, 'bytes 0-3/18', 'RIFF'],
    'seek bytes'    => ['bytes=4-9', 206, 'bytes 4-9/18', '012345'],
    'suffix bytes'  => ['bytes=-4', 206, 'bytes 14-17/18', 'WAVE'],
    'unsatisfiable' => ['bytes=99-100', 416, 'bytes */18', ''],
]);

it('keeps the media endpoint authenticated', function (): void {
    $asset = Asset::factory()->create(['file_type' => 'audio', 'extension' => 'wav', 'path' => 'assets/private.wav']);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, 'RIFF');
    auth('admin')->logout();
    $this->withHeader('Range', 'bytes=0-3')->get(route('admin.dam.file.preview', ['path' => $asset->path]))
        ->assertRedirect(route('admin.session.create'));
});

it('renders consistent PDF audio and video frame controls in both preview entry points', function (): void {
    $asset = Asset::factory()->create(['file_type' => 'document', 'extension' => 'pdf']);
    foreach ([route('admin.dam.assets.edit', $asset->id), route('admin.dam.assets.index')] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach (['data-dam-pdf-viewer', 'Fit width', 'Fit page', 'data-dam-audio-error', 'Copy frame', 'Download frame', 'data-dam-frame-status'] as $control) {
            expect(str_contains($html, $control))->toBeTrue($control.' must be present in each preview entry point');
        }
    }
});

it('autoplays video previews muted with a visible unmute control', function (): void {
    // Shared lab machines: a preview must never start at full volume (UX QA F28, 2026-09-27).
    $html = $this->get(route('admin.dam.assets.index'))->assertOk()->getContent();
    expect($html)->toContain('data-dam-video-autoplay-muted')
        ->toContain('data-dam-video-unmute')
        ->toContain('this.videoIsMuted = true;')
        ->toContain('this.$refs.videoEl.muted = this.videoIsMuted;');
});

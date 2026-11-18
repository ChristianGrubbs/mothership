<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

/*
 * A type placeholder is served from the same unversioned URL as the real thumbnail, so it can never get a max-age.
 * It carries a strong ETag of its bytes instead, so a warm Library reload is a bodyless 304 (perf pass LB-04).
 */

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

function placeholderEtagGet(string $path, array $headers = []): TestResponse
{
    return test()->call('GET', route('admin.dam.file.thumbnail'), ['path' => $path], [], [], array_merge(['HTTP_ACCEPT' => 'image/*'], $headers));
}

function placeholderEtagAsset(string $path): void
{
    Storage::disk(Directory::getAssetDisk())->put($path, 'stl-bytes');
    $asset = Asset::create([
        'file_name' => basename($path), 'file_type' => 'document', 'file_size' => 1,
        'mime_type' => 'application/octet-stream', 'extension' => pathinfo($path, PATHINFO_EXTENSION), 'path' => $path,
    ]);
    $asset->directories()->attach(Directory::whereNull('parent_id')->firstOrFail()->id);
}

it('gives a placeholder a strong ETag and keeps it revalidating', function () {
    placeholderEtagAsset('assets/Root/etag-bracket.stl');
    Storage::fake('public');

    $response = placeholderEtagGet('assets/Root/etag-bracket.stl')->assertOk();
    $etag = $response->headers->get('ETag');

    expect($etag)->toMatch('/^"[a-f0-9]{64}"$/')
        ->and($etag)->toBe('"'.hash('sha256', $response->getContent()).'"')
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')
        ->not->toContain('max-age=31536000')->not->toContain('immutable')->not->toContain('public');
});

it('answers a matching If-None-Match with a bodyless 304 that keeps the headers', function () {
    placeholderEtagAsset('assets/Root/etag-304.stl');
    Storage::fake('public');
    $etag = placeholderEtagGet('assets/Root/etag-304.stl')->assertOk()->headers->get('ETag');

    $again = placeholderEtagGet('assets/Root/etag-304.stl', ['HTTP_IF_NONE_MATCH' => $etag]);

    expect($again->getStatusCode())->toBe(304)
        ->and($again->getContent())->toBe('')
        ->and($again->headers->get('ETag'))->toBe($etag)
        ->and($again->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')->not->toContain('immutable');

    expect(placeholderEtagGet('assets/Root/etag-304.stl', ['HTTP_IF_NONE_MATCH' => '"other"'])->getStatusCode())->toBe(200);
});

it('gives the published public placeholder the ETag of its own bytes', function () {
    placeholderEtagAsset('assets/Root/etag-public.stl');
    Storage::fake('public');
    Storage::disk('public')->put('dam/grid/model.svg', '<svg id="published"/>');

    $response = placeholderEtagGet('assets/Root/etag-public.stl')->assertOk();

    expect($response->getContent())->toBe('<svg id="published"/>')
        ->and($response->headers->get('ETag'))->toBe('"'.hash('sha256', '<svg id="published"/>').'"');
});

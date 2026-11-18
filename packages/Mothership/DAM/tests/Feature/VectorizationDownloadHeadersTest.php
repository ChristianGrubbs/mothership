<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\User\Models\Admin;

/*
|--------------------------------------------------------------------------
| Vectorized result download/preview response headers
|--------------------------------------------------------------------------
|
| The download and preview branches of HomeVectorizationController::file() return a Symfony
| BinaryFileResponse, whose constructor runs setPublic() and therefore strips the 'private'
| directive the controller passes in its header array. A response carrying 'public' also opts
| out of Core's NoCacheMiddleware entirely, so a per-user vectorized result would be emitted as
| a shared-cacheable response intent.
|
| These assertions deliberately run against the real response returned through the full
| middleware stack rather than against the controller's header array, because the defect only
| exists after construction and after the middleware has seen the response.
|
*/

beforeEach(function () {
    Storage::fake('private');

    $this->person = Admin::factory()->create();
    $this->actingAs($this->person, 'admin');

    config([
        'vectorization.enabled'          => true,
        'dam-home.widgets.vectorization' => [
            'title'      => 'Image Vectorization',
            'kind'       => 'tool',
            'entry'      => 'index.html',
            'permission' => 'dam.assets',
            'sizes'      => ['small', 'wide'],
            'size'       => 'small',
            'settings'   => [],
        ],
    ]);

    $this->vectorizationId = (string) Str::uuid();

    DB::table('dam_vectorizations')->insert([
        'id'         => $this->vectorizationId,
        'admin_id'   => $this->person->id,
        'request_id' => (string) Str::uuid(),
        'input_sha'  => hash('sha256', 'vectorization-download-headers'),
        'input_path' => 'vectorizations/input/'.Str::uuid(),
        'charged'    => true,
        'status'     => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Synthetic local fixtures: the route streams these bytes without parsing them, so no
    // provider call (paid or otherwise) is involved in producing a real response.
    $disk = Storage::disk('private');
    $results = 'vectorizations/results/'.$this->vectorizationId;

    $disk->put($results.'/result.svg', <<<'SVG'
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16"><path d="M0 0H16V16H0Z" fill="#000"/></svg>
        SVG);

    $disk->put($results.'/result.pdf', <<<'PDF'
        %PDF-1.7
        1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
        2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
        3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 16 16]>>endobj
        trailer<</Size 4/Root 1 0 R>>
        %%EOF
        PDF);
});

it('marks the svg download private and never shared-cacheable', function () {
    $response = $this->get(route('admin.home.vectorization.file', [
        'id'     => $this->vectorizationId,
        'format' => 'svg',
    ]))->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-store')
        ->and($cacheControl)->not->toContain('public');

    $response->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'");

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('vectorized-'.$this->vectorizationId.'.svg');
});

it('marks the pdf download private and never shared-cacheable', function () {
    $response = $this->get(route('admin.home.vectorization.file', [
        'id'     => $this->vectorizationId,
        'format' => 'pdf',
    ]))->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-store')
        ->and($cacheControl)->not->toContain('public');

    $response->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'");

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('vectorized-'.$this->vectorizationId.'.pdf');
});

it('marks the inline svg preview private and never shared-cacheable', function () {
    // A distinct return path: response()->file() rather than response()->download(), so it needs
    // its own coverage even though both build a BinaryFileResponse.
    $response = $this->get(route('admin.home.vectorization.file', [
        'id'      => $this->vectorizationId,
        'format'  => 'svg',
        'preview' => 1,
    ]))->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-store')
        ->and($cacheControl)->not->toContain('public');

    $response->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'");

    expect((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment');
});

it('still refuses another admin and an unsupported format', function () {
    // Guards the fix against loosening the ownership and format checks that gate the same method.
    $this->actingAs(Admin::factory()->create(), 'admin');

    foreach (['svg', 'pdf'] as $format) {
        $this->get(route('admin.home.vectorization.file', [
            'id'     => $this->vectorizationId,
            'format' => $format,
        ]))->assertNotFound();
    }

    $this->get(route('admin.home.vectorization.file', [
        'id'      => $this->vectorizationId,
        'format'  => 'svg',
        'preview' => 1,
    ]))->assertNotFound();

    $this->actingAs($this->person, 'admin');

    $svgUrl = route('admin.home.vectorization.file', [
        'id'     => $this->vectorizationId,
        'format' => 'svg',
    ]);

    $this->get(Str::beforeLast($svgUrl, '/').'/eps')->assertNotFound();
});

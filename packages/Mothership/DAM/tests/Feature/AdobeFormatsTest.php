<?php

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\AdobeFormats;

/*
 * Which Adobe application a file belongs to is presentation only: it never decides safety or the stored type, and a name
 * alone never makes bytes Adobe's. The marks are inline SVG shipped with the package, so they must stay inert.
 */

it('names the Adobe application from the extension unless the bytes are plainly something else', function (?string $extension, ?string $mime, ?string $key) {
    expect(AdobeFormats::key($extension, $mime))->toBe($key);
})->with([
    'Illustrator saved with PDF compatibility'  => ['ai', 'application/pdf', 'illustrator'],
    'Illustrator template, upper case'          => ['AIT', 'application/pdf', 'illustrator'],
    'Illustrator, generic bytes'                => ['ai', 'application/octet-stream', 'illustrator'],
    'Illustrator, legacy PostScript'            => ['ai', 'application/postscript', 'illustrator'],
    'Illustrator, no stored type'               => ['ai', null, 'illustrator'],
    'Photoshop'                                 => ['psd', 'image/vnd.adobe.photoshop', 'photoshop'],
    'Photoshop large document'                  => ['psb', 'application/octet-stream', 'photoshop'],
    'InDesign'                                  => ['indd', 'application/octet-stream', 'indesign'],
    'InDesign interchange'                      => ['idml', 'application/zip', 'indesign'],
    'InDesign template'                         => ['indt', null, 'indesign'],
    'After Effects project'                     => ['aep', 'application/octet-stream', 'after-effects'],
    'After Effects XML project'                 => ['aepx', 'text/xml', 'after-effects'],
    'After Effects template'                    => ['aet', null, 'after-effects'],
    'Premiere Pro project'                      => ['prproj', 'application/gzip', 'premiere-pro'],
    'Premiere Pro title'                        => ['prtl', null, 'premiere-pro'],
    'XD'                                        => ['xd', 'application/octet-stream', 'xd'],
    'Audition session'                          => ['sesx', 'text/xml', 'audition'],
    'a leading dot is ignored'                  => ['.ai', 'application/pdf', 'illustrator'],
    // Not Adobe's: a PDF is a PDF, EPS is not uniquely Illustrator, .au is generic audio.
    'a genuine PDF'                             => ['pdf', 'application/pdf', null],
    'EPS'                                       => ['eps', 'application/postscript', null],
    'generic AU audio'                          => ['au', 'audio/basic', null],
    'a JPEG named .psd'                         => ['psd', 'image/jpeg', null],
    'a video named .ai'                         => ['ai', 'video/mp4', null],
    'a PDF named .indd'                         => ['indd', 'application/pdf', null],
    'no extension'                              => ['', 'application/pdf', null],
    'an unrelated extension'                    => ['png', 'image/png', null],
]);

it('presents an Adobe file with its application name so Ai is never read as artificial intelligence', function () {
    $ai = AdobeFormats::present('ai', 'application/pdf');

    expect($ai)->toMatchArray(['key' => 'illustrator', 'app' => 'Adobe Illustrator', 'label' => 'Illustrator', 'badge' => 'Ai', 'extension' => 'ai', 'pdf' => true])
        ->and($ai['title'])->toBe('Adobe Illustrator file (.AI)')
        ->and(AdobeFormats::present('psd', 'image/vnd.adobe.photoshop')['pdf'])->toBeFalse()
        ->and(AdobeFormats::present('pdf', 'application/pdf'))->toBeNull();
});

it('sends only Illustrator files that carry PDF bytes through the PDF renderer, and every PDF as before', function () {
    expect(AdobeFormats::isPdfRenderable('ai', 'application/pdf'))->toBeTrue()
        ->and(AdobeFormats::isPdfRenderable('ait', 'application/pdf; charset=binary'))->toBeTrue()
        ->and(AdobeFormats::isPdfRenderable('pdf', 'application/pdf'))->toBeTrue()
        ->and(AdobeFormats::isPdfRenderable('pdf', null))->toBeTrue()
        // A legacy PostScript .ai is not PDF bytes, so the PDF renderer never sees it.
        ->and(AdobeFormats::isPdfRenderable('ai', 'application/postscript'))->toBeFalse()
        ->and(AdobeFormats::isPdfRenderable('ai', 'application/octet-stream'))->toBeFalse()
        ->and(AdobeFormats::isPdfRenderable('indd', 'application/pdf'))->toBeFalse()
        ->and(AdobeFormats::isPdfRenderable('jpg', 'application/pdf'))->toBeFalse();
});

it('ships an inert mark for every application, and an Adobe tile only for Adobe extensions', function () {
    $dir = dirname(__DIR__, 2).'/src/Resources/assets/images/adobe';
    $icons = AdobeFormats::icons();

    expect($icons)->toHaveCount(7);

    foreach (glob($dir.'/*.svg') as $file) {
        $svg = file_get_contents($file);

        expect($svg)->toStartWith('<svg')
            ->and($svg)->not->toMatch('/<script|<foreignObject|<image|<use\b|href=|xlink|@import|url\(|\son[a-z]+=|<!ENTITY|<!DOCTYPE/i');
    }

    foreach ($icons as $uri) {
        expect($uri)->toStartWith('data:image/svg+xml;base64,')->and(strlen($uri))->toBeGreaterThan(400);
    }

    $tile = AdobeFormats::tileSvg('indd');

    expect($tile)->toContain('viewBox="0 0 301 206"')->toContain('Adobe InDesign file')
        ->and(simplexml_load_string($tile))->not->toBeFalse()
        ->and(AdobeFormats::tileSvg('indd', 'preview'))->toContain('viewBox="0 0 256 256"')
        ->and(AdobeFormats::tileSvg('pdf'))->toBeNull()
        ->and(AdobeFormats::tileSvg('eps'))->toBeNull();

    // The viewer's fallback for a selected file: every application has an inline preview tile, no published path needed.
    expect(AdobeFormats::tiles())->toHaveCount(7)->and(array_keys(AdobeFormats::tiles()))->toBe(array_keys($icons));

    foreach (AdobeFormats::tiles() as $uri) {
        expect(simplexml_load_string(base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')))))->not->toBeFalse();
    }
});

it('names the Adobe application in the Library listing and leaves every other file untouched', function () {
    $this->loginAsAdmin();
    $folder = Directory::create(['name' => 'Adobe '.uniqid()]);
    $make = function (string $name, string $extension, string $mime) use ($folder): Asset {
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => $name, 'file_type' => 'document', 'extension' => $extension, 'mime_type' => $mime]));
        $asset->directories()->attach($folder->id);

        return $asset;
    };
    $illustrator = $make('Banner Logo.ai', 'ai', 'application/pdf');
    $pdf = $make('Brochure.pdf', 'pdf', 'application/pdf');
    $photo = $make('shot.jpg', 'jpg', 'image/jpeg');

    $rows = collect($this->getJson('/admin/dam/explorer/data?directory_id='.$folder->id)->assertOk()->json('assets'))->keyBy('id');

    expect($rows[$illustrator->id]['adobe'])->toMatchArray(['key' => 'illustrator', 'title' => 'Adobe Illustrator file (.AI)', 'pdf' => true])
        // The raw type is still what the bytes are.
        ->and($rows[$illustrator->id]['mime_type'])->toBe('application/pdf')
        ->and($rows[$pdf->id]['adobe'])->toBeNull()
        ->and($rows[$photo->id]['adobe'])->toBeNull();
});

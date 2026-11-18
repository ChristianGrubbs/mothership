<?php

/*
 * BlendFileReader reads Blender's embedded thumbnail and datablock counts without Blender. Most fixtures are built
 * byte by byte (GameAssetFixtures), in each header format Blender has written, uncompressed, gzip and zstd; two are
 * real Blender 5.2 saves (tests/Fixtures/blender).
 */

use Illuminate\Support\Facades\File;
use Mothership\DAM\Support\BlendFileReader;

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/blend-reader-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->scratch);
    $this->write = function (string $bytes, string $name = 'scene.blend'): string {
        file_put_contents($path = $this->scratch.'/'.$name, $bytes);

        return $path;
    };
});

afterEach(fn () => File::deleteDirectory($this->scratch));

/** The JPEG's colour at a point, as [r, g, b]. */
function blendJpegPixel(string $jpeg, int $x, int $y): array
{
    $image = imagecreatefromstring($jpeg);
    $rgb = imagecolorat($image, $x, $y);
    imagedestroy($image);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

it('reads the version, thumbnail and datablock counts of each header format', function (string $format, string $version, int $pointerSize, bool $little) {
    $info = (new BlendFileReader)->read(($this->write)(blendFixture(format: $format)));

    expect($info)->not->toBeNull()
        ->and($info['compression'])->toBe(BlendFileReader::COMPRESSION_NONE)
        ->and($info['version'])->toBe($version)
        ->and($info['pointer_size'])->toBe($pointerSize)
        ->and($info['little_endian'])->toBe($little)
        ->and($info['complete'])->toBeTrue()
        ->and($info['thumbnail']['width'])->toBe(16)
        ->and($info['thumbnail']['height'])->toBe(16)
        ->and($info['counts']['scenes'])->toBe(1)
        ->and($info['counts']['objects'])->toBe(2)
        ->and($info['counts']['meshes'])->toBe(0);
})->with([
    'legacy, little-endian, 8-byte pointers' => ['legacy8', '4.2', 8, true],
    'legacy, big-endian, 4-byte pointers'    => ['legacy4be', '2.79', 4, false],
    'Blender 5.x header (LargeBHead8)'       => ['large', '5.0', 8, true],
]);

it('flips the bottom-up thumbnail upright and encodes it as a JPEG', function () {
    $info = (new BlendFileReader)->read(($this->write)(blendFixture(thumbnail: [32, 32])));
    $jpeg = BlendFileReader::thumbnailJpeg($info['thumbnail']);

    expect($jpeg)->toStartWith("\xFF\xD8");
    // The fixture's first (bottom) rows are red, so upright the top is blue.
    [$red, , $blue] = blendJpegPixel($jpeg, 16, 4);
    expect($blue)->toBeGreaterThan(200)->and($red)->toBeLessThan(60);
    [$red, , $blue] = blendJpegPixel($jpeg, 16, 28);
    expect($red)->toBeGreaterThan(200)->and($blue)->toBeLessThan(60);
});

it('streams a gzip-compressed file', function () {
    $info = (new BlendFileReader)->read(($this->write)(gzencode(blendFixture())));

    expect($info['compression'])->toBe(BlendFileReader::COMPRESSION_GZIP)
        ->and($info['version'])->toBe('4.2')
        ->and($info['thumbnail']['width'])->toBe(16)
        ->and($info['counts']['objects'])->toBe(2);
});

it('streams a zstd-compressed file through the zstd CLI', function () {
    $plain = ($this->write)(blendFixture(format: 'large'), 'plain.blend');
    $compressed = $this->scratch.'/zstd.blend';
    exec('zstd -q -o '.escapeshellarg($compressed).' '.escapeshellarg($plain).' 2>&1', $output, $code);
    expect($code)->toBe(0);

    $info = (new BlendFileReader)->read($compressed);

    expect($info['compression'])->toBe(BlendFileReader::COMPRESSION_ZSTD)
        ->and($info['version'])->toBe('5.0')
        ->and($info['thumbnail']['height'])->toBe(16)
        ->and($info['counts']['scenes'])->toBe(1);
})->skip(fn () => trim((string) shell_exec('command -v zstd')) === '', 'the zstd CLI is not installed here');

it('reads a real Blender 5.2 save, its zstd frames to the end, and its 128 px thumbnail', function () {
    $info = (new BlendFileReader)->read(__DIR__.'/../Fixtures/blender/v0502_thumb_zstd.blend');

    // Blender 5 writes several zstd frames and a skippable seek table: `complete` means ENDB, past the first frame.
    expect($info)->toMatchArray([
        'compression'  => BlendFileReader::COMPRESSION_ZSTD,
        'version'      => '5.2',
        'pointer_size' => 8,
        'complete'     => true,
    ])
        ->and($info['thumbnail']['width'])->toBe(128)
        ->and($info['thumbnail']['height'])->toBe(128)
        ->and($info['counts'])->toMatchArray(['scenes' => 1, 'objects' => 3, 'meshes' => 1, 'cameras' => 1, 'lights' => 1]);

    $size = getimagesizefromstring(BlendFileReader::thumbnailJpeg($info['thumbnail']));
    expect([$size[0], $size[1], $size['mime']])->toBe([128, 128, 'image/jpeg']);
})->skip(fn () => trim((string) shell_exec('command -v zstd')) === '', 'the zstd CLI is not installed here');

it('reads a real Blender 5.2 save without a thumbnail as having none', function () {
    $info = (new BlendFileReader)->read(__DIR__.'/../Fixtures/blender/v0502_nothumb_zstd.blend');

    expect($info['thumbnail'])->toBeNull()
        ->and($info['version'])->toBe('5.2')
        ->and($info['complete'])->toBeTrue()
        ->and($info['counts']['objects'])->toBe(3);
})->skip(fn () => trim((string) shell_exec('command -v zstd')) === '', 'the zstd CLI is not installed here');

it('stops after the thumbnail when counts are not wanted', function () {
    $info = (new BlendFileReader)->read(($this->write)(gzencode(blendFixture())), countBlocks: false);

    expect($info['thumbnail']['width'])->toBe(16)
        ->and($info['counts'])->toBeNull()
        ->and($info['complete'])->toBeTrue();
});

it('reads a file saved without a thumbnail', function () {
    $info = (new BlendFileReader)->read(($this->write)(blendFixture(thumbnail: null)));

    expect($info['thumbnail'])->toBeNull()
        ->and($info['counts']['objects'])->toBe(2)
        ->and($info['complete'])->toBeTrue();
});

it('reads a file cut inside its thumbnail without throwing', function () {
    $bytes = blendFixture();
    $info = (new BlendFileReader)->read(($this->write)(substr($bytes, 0, strpos($bytes, 'GLOB') - 100)));

    expect($info['thumbnail'])->toBeNull()
        ->and($info['complete'])->toBeFalse();
});

it('keeps the thumbnail and counts of a file cut before its ENDB', function () {
    $info = (new BlendFileReader)->read(($this->write)(gzencode(blendFixture(endb: false))));

    expect($info['thumbnail']['width'])->toBe(16)
        ->and($info['counts']['objects'])->toBe(2)
        ->and($info['complete'])->toBeFalse();
});

it('bounds a corrupt block length instead of allocating it', function () {
    $bytes = blendFixture(thumbnail: null);
    $glob = strpos($bytes, 'GLOB');
    // GLOB's length claims 2 GB.
    $bytes = substr_replace($bytes, pack('V', 0x7FFFFFF0), $glob + 4, 4);

    $before = memory_get_peak_usage();
    $info = (new BlendFileReader(maxBytes: 1048576))->read(($this->write)(gzencode($bytes)));

    expect($info['complete'])->toBeFalse()
        ->and($info['thumbnail'])->toBeNull()
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1048576);
});

it('rejects a TEST block whose dimensions do not fit its length', function () {
    $bytes = blendFixture(thumbnail: [16, 16]);
    $test = strpos($bytes, 'TEST');
    // Width 4096 is past the side limit; the block is skipped, the rest still read.
    $bytes = substr_replace($bytes, pack('V', 4096), $test + 24, 4);

    $info = (new BlendFileReader)->read(($this->write)($bytes));

    expect($info['thumbnail'])->toBeNull()
        ->and($info['counts']['objects'])->toBe(2)
        ->and($info['complete'])->toBeTrue();
});

it('returns null for a file that is not a .blend', function (string $bytes) {
    expect((new BlendFileReader)->read(($this->write)($bytes)))->toBeNull();
})->with([
    'text'            => 'just some text, not Blender',
    'gzip of text'    => gzencode('not a blend either'),
    'unknown header'  => 'BLENDER99-01v0500'.str_repeat("\0", 64),
    'empty'           => '',
]);

it('returns null for a path that cannot be opened', function () {
    expect((new BlendFileReader)->read($this->scratch.'/missing.blend'))->toBeNull();
});

it('spells versions the way Blender does', function () {
    expect(BlendFileReader::versionString(279))->toBe('2.79')
        ->and(BlendFileReader::versionString(280))->toBe('2.80')
        ->and(BlendFileReader::versionString(306))->toBe('3.6')
        ->and(BlendFileReader::versionString(500))->toBe('5.0');
});

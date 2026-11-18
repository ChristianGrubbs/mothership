<?php

require_once __DIR__.'/../../Support/HubResourceFixtures.php';

use Mothership\DAM\Services\CreativeHub\HubResourceInspector;
use Mothership\DAM\Services\CreativeHub\HubResourceRefused;

/** The refusal reason inspecting $bytes as $name ends in, or null when the file is accepted. */
function hubResourceRefusal(string $bytes, string $name): ?string
{
    try {
        app(HubResourceInspector::class)->inspect(path: hubResourceFile($bytes), originalName: $name);
    } catch (HubResourceRefused $refused) {
        return $refused->reason;
    }

    return null;
}

it('reads a TrueType font from its bytes, whatever its name claims', function () {
    $font = app(HubResourceInspector::class)->inspect(path: hubResourceFile(hubResourceDejaVu()), originalName: 'my/font:file.ttf');

    expect($font)->toMatchArray([
        'kind'            => 'font',
        'format'          => 'ttf',
        'mime_type'       => 'font/ttf',
        'name'            => 'my font file.ttf',
        'family'          => 'DejaVu Sans',
        'subfamily'       => 'Bold Oblique',
        'weight'          => 700,
        'style'           => 'italic',
        'postscript_name' => 'DejaVuSans-BoldOblique',
    ])->and($font['meta'])->toMatchArray(['parsed' => true, 'faces' => 1, 'variable' => false]);
});

it('reads a WOFF font through php-font-lib', function () {
    $font = app(HubResourceInspector::class)->inspect(path: hubResourceFile(hubResourceWoff()), originalName: 'dejavu.woff');

    expect($font)->toMatchArray(['format' => 'woff', 'family' => 'DejaVu Sans', 'weight' => 700, 'style' => 'italic'])
        ->and($font['meta']['parsed'])->toBeTrue();
});

it('accepts a variable font as one face', function () {
    $font = app(HubResourceInspector::class)->inspect(path: hubResourceFile(hubResourceVariable()), originalName: 'DejaVu VF.ttf');

    expect($font)->toMatchArray(['format' => 'ttf', 'family' => 'DejaVu Sans'])
        ->and($font['meta']['variable'])->toBeTrue();
});

// Operator decision 2026-10-19: every collection is refused in v1, whatever its face count or name.
it('refuses font collections by magic and by name', function () {
    expect(hubResourceRefusal(bytes: hubResourceTtc(faces: 1), name: 'one.ttc'))->toBe(HubResourceRefused::COLLECTION)
        ->and(hubResourceRefusal(bytes: hubResourceTtc(faces: 2), name: 'two.otc'))->toBe(HubResourceRefused::COLLECTION)
        ->and(hubResourceRefusal(bytes: hubResourceTtc(faces: 1), name: 'renamed.ttf'))->toBe(HubResourceRefused::COLLECTION)
        ->and(hubResourceRefusal(bytes: hubResourceDejaVu(), name: 'renamed.otc'))->toBe(HubResourceRefused::COLLECTION);
});

it('accepts a WOFF2 on its checked header alone and says it is unparsed', function () {
    $font = app(HubResourceInspector::class)->inspect(path: hubResourceFile(hubResourceWoff2()), originalName: 'Inter Display.woff2');

    expect($font)->toMatchArray(['format' => 'woff2', 'family' => 'Inter Display', 'weight' => null])
        ->and($font['meta'])->toMatchArray(['parsed' => false, 'unparsed_reason' => 'woff2_needs_brotli'])
        ->and(hubResourceRefusal(bytes: hubResourceWoff2(claimedLength: 999), name: 'bad.woff2'))->toBe(HubResourceRefused::UNPARSED)
        ->and(hubResourceRefusal(bytes: hubResourceWoff2(flavor: 'ttcf'), name: 'set.woff2'))->toBe(HubResourceRefused::COLLECTION);
});

it('refuses bytes that are no font or brush, an empty file, and a font that does not parse', function () {
    expect(hubResourceRefusal(bytes: "\x89PNG\r\n\x1a\n".str_repeat("\0", 64), name: 'font.ttf'))->toBe(HubResourceRefused::UNKNOWN_FORMAT)
        ->and(hubResourceRefusal(bytes: '', name: 'empty.ttf'))->toBe(HubResourceRefused::EMPTY)
        ->and(hubResourceRefusal(bytes: "\x00\x01\x00\x00".str_repeat("\xff", 512), name: 'noise.ttf'))->toBe(HubResourceRefused::UNPARSED)
        ->and(hubResourceRefusal(bytes: hubResourceDejaVu(), name: 'payload.php'))->toBe(HubResourceRefused::FORBIDDEN);
});

it('refuses a font or a brush over its size cap', function () {
    config(['dam.hub.resources.font_max_bytes' => 1024, 'dam.hub.resources.brush_max_bytes' => 16]);

    expect(hubResourceRefusal(bytes: hubResourceDejaVu(), name: 'big.ttf'))->toBe(HubResourceRefused::TOO_LARGE)
        ->and(hubResourceRefusal(bytes: hubResourceAbr(version: 6), name: 'big.abr'))->toBe(HubResourceRefused::TOO_LARGE);
});

it('passes Photoshop brushes through on their version word and 8BIM signature', function () {
    $brush = app(HubResourceInspector::class)->inspect(path: hubResourceFile(hubResourceAbr(version: 10)), originalName: 'Inks.abr');

    expect($brush)->toMatchArray(['kind' => 'brush', 'format' => 'abr', 'name' => 'Inks.abr', 'family' => null])
        ->and($brush['meta'])->toMatchArray(['abr_version' => 10, 'abr_subversion' => 2])
        ->and(hubResourceRefusal(bytes: hubResourceAbr(version: 1), name: 'old.abr'))->toBeNull()
        ->and(hubResourceRefusal(bytes: hubResourceAbr(version: 5), name: 'v5.abr'))->toBe(HubResourceRefused::BAD_BRUSH)
        ->and(hubResourceRefusal(bytes: hubResourceAbr(version: 6, signed: false), name: 'unsigned.abr'))->toBe(HubResourceRefused::BAD_BRUSH)
        ->and(hubResourceRefusal(bytes: hubResourceAbr(version: 6), name: 'brush.bin'))->toBe(HubResourceRefused::UNKNOWN_FORMAT);
});

// Review (PR #274): php-font-lib inflates a WOFF into tempnam() files it never removes. Each parser child now gets its
// own temp directory, removed by the parent whatever the child did.
it('leaves no parser temp files behind after a success, a repeat, a refusal and a crash', function () {
    $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $left = fn (): array => array_merge(glob($tmp.'/'.HubResourceInspector::SCRATCH_PREFIX.'*') ?: [], glob($tmp.'/fnt*') ?: []);
    $before = $left();
    $inspector = app(HubResourceInspector::class);

    $inspector->inspect(path: hubResourceFile(hubResourceWoff()), originalName: 'first.woff');
    $inspector->inspect(path: hubResourceFile(hubResourceWoff()), originalName: 'again.woff');
    $refused = hubResourceRefusal(bytes: "\x00\x01\x00\x00".str_repeat("\xff", 512), name: 'noise.ttf');
    // PHP ignores a limit below what the child already uses (~10 MB after autoload); 10M is above that and below the parse.
    config(['dam.hub.resources.inspect_memory_limit' => '10M']);
    $crashed = hubResourceRefusal(bytes: hubResourceWoff(), name: 'crash.woff');

    expect($refused)->toBe(HubResourceRefused::UNPARSED)
        ->and($crashed)->toBe(HubResourceRefused::UNPARSED)
        ->and(array_values(array_diff($left(), $before)))->toBe([]);
});

// Re-review (PR #274): a child that outlives its timeout is killed, and its scratch directory still goes.
it('kills a parser child that times out and removes its scratch files and directory', function () {
    $report = hubResourceFile('');
    config(['dam.hub.resources.inspect_timeout_seconds' => 1]);   // the process timeout is this plus 2 s
    $inspector = app(HubResourceInspector::class);
    // set_time_limit(0) lifts the child's own max_execution_time, so only the parent's process timeout can end it.
    $inspector->overrideParser('<?php
        set_time_limit(0);
        $dir = sys_get_temp_dir();
        file_put_contents($dir."/fnt-hang", "x");
        file_put_contents('.var_export($report, true).', json_encode(["pid" => getmypid(), "dir" => $dir]));
        sleep(30);');

    $started = microtime(true);
    try {
        $inspector->inspect(path: hubResourceFile(hubResourceDejaVu()), originalName: 'hang.ttf');
        $reason = null;
    } catch (HubResourceRefused $refused) {
        $reason = $refused->reason;
    }
    $child = json_decode((string) file_get_contents($report), true);

    expect($reason)->toBe(HubResourceRefused::UNPARSED)
        ->and(microtime(true) - $started)->toBeGreaterThan(2.5)->toBeLessThan(15)
        ->and(str_contains($child['dir'], HubResourceInspector::SCRATCH_PREFIX))->toBeTrue()
        ->and(posix_kill($child['pid'], 0))->toBeFalse()
        ->and(file_exists($child['dir'].'/fnt-hang'))->toBeFalse()
        ->and(is_dir($child['dir']))->toBeFalse();
});

it('refuses to override the parser outside unit tests', function () {
    $environment = app()['env'];
    app()['env'] = 'production';

    try {
        expect(fn () => app(HubResourceInspector::class)->overrideParser('<?php sleep(30);'))->toThrow(LogicException::class);
    } finally {
        app()['env'] = $environment;
    }
});

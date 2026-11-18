<?php

/*
 * UnityPackageReader lists a .unitypackage and picks one editor preview, reading the tar through TarListing's
 * capture. Packages are built byte by byte (GameAssetFixtures); none comes from Unity.
 */

use Illuminate\Support\Facades\File;
use Mothership\DAM\Support\TarListing;
use Mothership\DAM\Support\UnityPackageReader;
use Mothership\DAM\Support\UnityPackageScan;

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/unity-reader-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->scratch);
});

afterEach(fn () => File::deleteDirectory($this->scratch));

it('lists every asset by path and prefers a prefab preview over earlier ones', function () {
    $texture = gameAssetPng(255, 0, 0);
    $prefab = gameAssetPng(0, 0, 255);

    $package = unityPackageWrite($this->scratch, [
        'a1b2c3d4e5f60718293a4b5c6d7e8f90' => ['pathname' => 'Assets/Textures/Rust.png', 'preview' => $texture],
        'b1b2c3d4e5f60718293a4b5c6d7e8f90' => ['pathname' => "Assets/Prefabs/Robot.prefab\n00"],
        'c1b2c3d4e5f60718293a4b5c6d7e8f90' => ['pathname' => 'Assets/Prefabs/Crate.prefab', 'preview' => $prefab],
        'd1b2c3d4e5f60718293a4b5c6d7e8f90' => ['pathname' => 'Assets/Scenes/Main.unity'],
    ]);

    $result = (new UnityPackageReader)->read($package);

    expect($result['asset_count'])->toBe(4)
        ->and($result['truncated'])->toBeFalse()
        ->and($result['complete'])->toBeTrue()
        ->and($result['assets'])->toBe([
            ['path' => 'Assets/Prefabs/Crate.prefab', 'has_preview' => true],
            // The second line Unity sometimes writes is not part of the path.
            ['path' => 'Assets/Prefabs/Robot.prefab', 'has_preview' => false],
            ['path' => 'Assets/Scenes/Main.unity', 'has_preview' => false],
            ['path' => 'Assets/Textures/Rust.png', 'has_preview' => true],
        ])
        ->and($result['preview_png'])->toBe($prefab)
        ->and($result['preview_path'])->toBe('Assets/Prefabs/Crate.prefab');
});

it('falls back to the first preview when no prefab, model or material has one', function () {
    $first = gameAssetPng(10, 200, 10);

    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/Audio/Hit.wav', 'preview' => $first],
        'aa000000000000000000000000000002' => ['pathname' => 'Assets/Textures/Wood.png', 'preview' => gameAssetPng(1, 2, 3)],
    ]);

    expect((new UnityPackageReader)->read($package)['preview_png'])->toBe($first);
});

it('lists a package without any preview', function () {
    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/Scripts/Player.cs'],
    ]);

    $result = (new UnityPackageReader)->read($package);

    expect($result['preview_png'])->toBeNull()
        ->and($result['assets'])->toBe([['path' => 'Assets/Scripts/Player.cs', 'has_preview' => false]]);
});

it('skips an oversize preview but still marks the asset as having one', function () {
    $huge = "\x89PNG\r\n\x1a\n".str_repeat('x', UnityPackageReader::MAX_PREVIEW_BYTES);

    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/Big.prefab', 'preview' => $huge],
    ]);

    $result = (new UnityPackageReader)->read($package);

    expect($result['preview_png'])->toBeNull()
        ->and($result['assets'][0]['has_preview'])->toBeTrue();
});

it('caps the listing and says so', function () {
    $assets = [];

    for ($i = 0; $i < UnityPackageReader::MAX_ENTRIES + 5; $i++) {
        $assets[sprintf('%032x', $i + 1)] = ['pathname' => sprintf('Assets/Item%05d.mat', $i)];
    }

    $result = (new UnityPackageReader)->read(unityPackageWrite($this->scratch, $assets));

    expect($result['asset_count'])->toBe(UnityPackageReader::MAX_ENTRIES + 5)
        ->and($result['assets'])->toHaveCount(UnityPackageReader::MAX_ENTRIES)
        ->and($result['truncated'])->toBeTrue();
});

it('keeps what it read from a truncated package', function () {
    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/One.prefab', 'preview' => gameAssetPng(0, 0, 255)],
        'aa000000000000000000000000000002' => ['pathname' => 'Assets/Two.prefab'],
    ]);
    // Cut the gzip stream short: the tail entry and the trailer are gone.
    $bytes = file_get_contents($package);
    file_put_contents($package, substr($bytes, 0, (int) (strlen($bytes) * 0.8)));

    $result = (new UnityPackageReader)->read($package);

    expect($result)->not->toBeNull()
        ->and($result['complete'])->toBeFalse()
        ->and($result['assets'][0]['path'])->toBe('Assets/One.prefab');
});

it('returns null for something that is not a Unity package', function () {
    $tgz = tarListingWrite($this->scratch, [tarListingHeader('readme.txt', 2), tarListingData('hi')]);
    file_put_contents($notGzip = $this->scratch.'/plain.unitypackage', 'not a tar');

    expect((new UnityPackageReader)->read($tgz))->toBeNull()
        ->and((new UnityPackageReader)->read($notGzip))->toBeNull();
});

it('captures only the entries a caller opts in, and lists the rest as before', function () {
    $tgz = tarListingWrite($this->scratch, [
        tarListingHeader('keep.txt', 5), tarListingData('hello'),
        tarListingHeader('skip.bin', 3), tarListingData('abc'),
    ]);

    $entries = iterator_to_array(TarListing::entries($tgz, fn (string $name): bool => $name === 'keep.txt'), false);

    expect($entries)->toBe([
        ['name' => 'keep.txt', 'size' => 5, 'type' => 'file', 'data' => 'hello'],
        ['name' => 'skip.bin', 'size' => 3, 'type' => 'file'],
    ]);
});

/** A few dozen bytes of PNG whose header names a `$width` x `$height` picture (no real pixel data). */
function unityHugePng(int $width, int $height): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        .$chunk('IDAT', gzcompress('')).$chunk('IEND', '');
}

it('reads a PNG preview\'s size from its header and refuses one too large to decode', function () {
    expect(UnityPackageReader::pngDimensions(gameAssetPng(1, 2, 3, 16)))->toBe([16, 16])
        ->and(UnityPackageReader::pngDimensions(unityHugePng(20000, 20000)))->toBeNull()
        ->and(UnityPackageReader::pngDimensions(unityHugePng(4096, 4097)))->toBeNull()
        ->and(UnityPackageReader::pngDimensions(unityHugePng(4096, 4096)))->toBe([4096, 4096])
        ->and(UnityPackageReader::pngDimensions(unityHugePng(0, 10)))->toBeNull()
        ->and(UnityPackageReader::pngDimensions('GIF89a'.str_repeat("\0", 40)))->toBeNull()
        ->and(UnityPackageReader::pngDimensions(substr(gameAssetPng(1, 2, 3), 0, 20)))->toBeNull();
});

it('never keeps a preview whose header names a huge picture, so it never reaches a decoder', function () {
    $bomb = unityHugePng(20000, 20000);
    expect(strlen($bomb))->toBeLessThan(UnityPackageReader::MAX_PREVIEW_BYTES);

    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/Bomb.prefab', 'preview' => $bomb],
        'aa000000000000000000000000000002' => ['pathname' => 'Assets/Wood.png', 'preview' => $small = gameAssetPng(9, 9, 9)],
    ]);

    $result = (new UnityPackageReader)->read($package);

    // The prefab would rank first, but its preview is refused; the asset still shows it had one.
    expect($result['preview_png'])->toBe($small)
        ->and($result['assets'][0])->toBe(['path' => 'Assets/Bomb.prefab', 'has_preview' => true]);
});

it('keeps only the capped listing while scanning, and still finds the best preview past the cap', function () {
    $assets = [];

    for ($i = 0; $i < 300; $i++) {
        $assets[sprintf('%032x', $i + 1)] = ['pathname' => sprintf('Assets/Tex%04d.png', $i), 'preview' => $i === 0 ? gameAssetPng(1, 1, 1) : null];
    }

    $assets[sprintf('%032x', 999)] = ['pathname' => 'Assets/Late.prefab', 'preview' => $prefab = gameAssetPng(0, 0, 200)];

    $result = (new UnityPackageReader(maxEntries: 10))->read(unityPackageWrite($this->scratch, $assets));

    expect($result['assets'])->toHaveCount(10)
        ->and($result['asset_count'])->toBe(301)
        ->and($result['truncated'])->toBeTrue()
        ->and($result['complete'])->toBeTrue()
        ->and($result['preview_png'])->toBe($prefab)
        ->and($result['preview_path'])->toBe('Assets/Late.prefab');
});

it('stops cleanly at its decompression budget and keeps what came before', function () {
    $png = gameAssetPng(0, 0, 255);
    $package = tarListingWrite($this->scratch, [
        tarListingHeader('aa000000000000000000000000000001/pathname', strlen('Assets/First.prefab')), tarListingData('Assets/First.prefab'),
        tarListingHeader('aa000000000000000000000000000001/preview.png', strlen($png)), tarListingData($png),
        // 64 MB of zeros: a few dozen KB compressed, never captured, only skipped.
        tarListingHeader('aa000000000000000000000000000001/asset', 64 * 1048576), str_repeat("\0", 64 * 1048576),
        tarListingHeader('aa000000000000000000000000000002/pathname', strlen('Assets/Never.mat')), tarListingData('Assets/Never.mat'),
    ]);

    $result = (new UnityPackageReader(maxBytes: 8 * 1048576))->read($package);

    expect($result['complete'])->toBeFalse()
        ->and($result['stopped'])->toBe('budget')
        ->and($result['truncated'])->toBeTrue()
        ->and(array_column($result['assets'], 'path'))->toBe(['Assets/First.prefab'])
        ->and($result['preview_png'])->toBe($png);
});

it('stops at its deadline', function () {
    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/One.prefab'],
    ]);

    // A deadline already past stops at the first inflate step; nothing read yet means no package.
    expect((new UnityPackageReader(timeoutSeconds: -1))->read($package))->toBeNull();
});

it('stops at its deadline while reading zero padding after the gzip stream, and keeps what it read', function () {
    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/One.prefab', 'preview' => $png = gameAssetPng(0, 0, 255)],
    ]);
    // A sparse tail of 100 GB of zeros: no disk used, valid padding, and no byte budget, so only the deadline ends the read.
    $handle = fopen($package, 'r+');
    ftruncate($handle, filesize($package) + 100 * 1073741824);
    fclose($handle);

    $started = microtime(true);
    $result = (new UnityPackageReader(maxBytes: PHP_INT_MAX, timeoutSeconds: 0.5))->read($package);

    expect(microtime(true) - $started)->toBeLessThan(10.0)
        ->and($result['stopped'])->toBe('deadline')
        ->and($result['complete'])->toBeFalse()
        ->and(array_column($result['assets'], 'path'))->toBe(['Assets/One.prefab'])
        ->and($result['preview_png'])->toBe($png);
});

it('counts zero padding after the gzip stream against a byte budget, and only when one is set', function () {
    $tgz = tarListingWrite($this->scratch, [tarListingHeader('a.txt', 2), tarListingData('hi')]);
    file_put_contents($tgz, str_repeat("\0", 8 * 1048576), FILE_APPEND);

    expect(iterator_to_array(TarListing::entries($tgz), false))->toHaveCount(1);
    expect(fn () => iterator_to_array(TarListing::entries($tgz, maxBytes: 1048576), false))->toThrow(RuntimeException::class, 'archive_budget');
});

it('ranks a preview by its GUID\'s pathname even when the package interleaves GUIDs', function () {
    $prefab = gameAssetPng(0, 0, 255);
    $texture = gameAssetPng(255, 0, 0);
    $member = fn (string $name, string $data): array => [tarListingHeader($name, strlen($data)), tarListingData($data)];
    $package = tarListingWrite($this->scratch, array_merge(
        $member('aa000000000000000000000000000001/pathname', 'Assets/Crate.prefab'),
        $member('bb000000000000000000000000000002/pathname', 'Assets/Rust.png'),
        $member('bb000000000000000000000000000002/preview.png', $texture),
        $member('aa000000000000000000000000000001/preview.png', $prefab),
    ));

    $result = (new UnityPackageReader)->read($package);

    expect($result['preview_png'])->toBe($prefab)
        ->and($result['preview_path'])->toBe('Assets/Crate.prefab')
        ->and($result['asset_count'])->toBe(2)
        ->and($result['assets'])->toBe([
            ['path' => 'Assets/Crate.prefab', 'has_preview' => true],
            ['path' => 'Assets/Rust.png', 'has_preview' => true],
        ]);
});

it('re-ranks the chosen preview when its pathname comes after it', function () {
    $prefab = gameAssetPng(0, 0, 255);
    $member = fn (string $name, string $data): array => [tarListingHeader($name, strlen($data)), tarListingData($data)];
    $package = tarListingWrite($this->scratch, array_merge(
        $member('aa000000000000000000000000000001/preview.png', $prefab),
        $member('bb000000000000000000000000000002/pathname', 'Assets/Rust.png'),
        $member('bb000000000000000000000000000002/preview.png', gameAssetPng(255, 0, 0)),
        $member('aa000000000000000000000000000001/pathname', 'Assets/Crate.prefab'),
    ));

    $result = (new UnityPackageReader)->read($package);

    expect($result['preview_png'])->toBe($prefab)
        ->and($result['preview_path'])->toBe('Assets/Crate.prefab');
});

it('ranks a preview past the listing cap by a pathname that came several GUIDs before it', function () {
    // maxEntries 1: A is listed; B (a prefab) and C are only counted. B's preview comes after C's pathname.
    $member = fn (string $name, string $data): array => [tarListingHeader($name, strlen($data)), tarListingData($data)];
    $package = tarListingWrite($this->scratch, array_merge(
        $member('aa000000000000000000000000000001/pathname', 'Assets/Rust.png'),
        $member('aa000000000000000000000000000001/preview.png', gameAssetPng(255, 0, 0)),
        $member('bb000000000000000000000000000002/pathname', 'Assets/Crate.prefab'),
        $member('cc000000000000000000000000000003/pathname', 'Assets/Wood.png'),
        $member('bb000000000000000000000000000002/preview.png', $prefab = gameAssetPng(0, 0, 255)),
    ));

    $result = (new UnityPackageReader(maxEntries: 1))->read($package);

    expect($result['preview_png'])->toBe($prefab)
        ->and($result['asset_count'])->toBe(3)
        ->and($result['assets'])->toHaveCount(1)
        ->and($result['truncated'])->toBeTrue();
});

it('remembers ranks for a bounded number of GUIDs, whatever order a package uses', function () {
    $scan = new UnityPackageScan(maxEntries: 1);
    $png = gameAssetPng(1, 2, 3);
    $scan->pathname($retained = str_repeat('0', 31).'1', 'Assets/Kept.mat', 2);

    // Alternating a fresh GUID with one already seen never grows what is remembered.
    for ($i = 0; $i < 10000; $i++) {
        $scan->preview(sprintf('f%031x', $i), $png);
        $scan->preview($retained, $png);
    }

    // A folder that is not a 32-hex GUID is never counted, ranked or listed.
    $scan->pathname(str_repeat('z', 32), 'Assets/Odd.prefab', 0);
    $scan->preview(str_repeat('a', 33), $png);

    expect($scan->rankedCount())->toBe(1)
        ->and($scan->assetCount)->toBe(1)
        ->and($scan->records)->toHaveCount(1);

    // Distinct pathnames past the hard cap are counted but not ranked, and the scan says it is incomplete.
    for ($i = 0; $i < UnityPackageScan::MAX_RANKED_GUIDS + 500; $i++) {
        $scan->pathname(sprintf('e%031x', $i), 'Assets/Item'.$i.'.mat', 2);
    }

    expect($scan->rankedCount())->toBe(UnityPackageScan::MAX_RANKED_GUIDS)
        ->and($scan->ranksCapped)->toBeTrue()
        ->and($scan->assetCount)->toBe(UnityPackageScan::MAX_RANKED_GUIDS + 501)
        ->and($scan->records)->toHaveCount(1);
});

it('ignores members under any folder that is not a GUID, however long its GNU or PAX name, and keeps none of it', function () {
    $blocks = [];

    for ($i = 0; $i < 80; $i++) {
        // About 900 KB of folder name each, alternately as a GNU `L` record and a PAX `path` record.
        foreach (['pathname' => 'Assets/Huge'.$i.'.prefab', 'preview.png' => gameAssetPng(9, 9, 9)] as $leaf => $data) {
            $name = str_repeat(chr(ord('g') + $i % 10), 900000).$i.'/'.$leaf;
            if ($i % 2 === 0) {
                $blocks[] = tarListingHeader(name: '././@LongLink', size: strlen($name) + 1, type: 'L', magic: 'ustar ', version: " \0");
                $blocks[] = tarListingData($name."\0");
            } else {
                $blocks[] = tarListingHeader(name: 'PaxHeader/x', size: strlen($pax = tarListingPaxRecord('path', $name)), type: 'x');
                $blocks[] = tarListingData($pax);
            }
            $blocks[] = tarListingHeader('short', strlen($data));
            $blocks[] = tarListingData($data);
        }
    }

    $guid = 'aa000000000000000000000000000001';
    foreach (['pathname' => 'Assets/Real.mat', 'preview.png' => $png = gameAssetPng(0, 200, 0)] as $leaf => $data) {
        $blocks[] = tarListingHeader($guid.'/'.$leaf, strlen($data));
        $blocks[] = tarListingData($data);
    }

    $package = tarListingWrite($this->scratch, $blocks);
    unset($blocks, $name, $pax);

    $before = memory_get_usage();
    memory_reset_peak_usage();
    $result = (new UnityPackageReader)->read($package);

    // Kept, those names would be some 70 MB; one at a time, read through, they stay well under that.
    expect(memory_get_peak_usage() - $before)->toBeLessThan(40 * 1048576)
        ->and($result['asset_count'])->toBe(1)
        ->and($result['assets'])->toBe([['path' => 'Assets/Real.mat', 'has_preview' => true]])
        ->and($result['preview_png'])->toBe($png);
});

it('keeps only the first 1 KB of a long pathname, ranking it by the whole one', function () {
    $long = 'Assets/'.str_repeat('Deep/', 790).'Crate.prefab';
    expect(strlen($long))->toBeGreaterThan(3000)->toBeLessThan(4096);

    $package = unityPackageWrite($this->scratch, [
        'aa000000000000000000000000000001' => ['pathname' => 'Assets/Rust.png', 'preview' => gameAssetPng(255, 0, 0)],
        'aa000000000000000000000000000002' => ['pathname' => $long, 'preview' => $prefab = gameAssetPng(0, 0, 255)],
    ]);

    $result = (new UnityPackageReader)->read($package);
    $paths = array_column($result['assets'], 'path');

    expect(max(array_map('strlen', $paths)))->toBe(UnityPackageScan::MAX_PATH_BYTES)
        ->and($result['preview_png'])->toBe($prefab)
        ->and(strlen($result['preview_path']))->toBe(UnityPackageScan::MAX_PATH_BYTES);
});

it('moves a best preview displaced before its pathname came into the pending slot', function () {
    $member = fn (string $name, string $data): array => [tarListingHeader($name, strlen($data)), tarListingData($data)];
    $package = tarListingWrite($this->scratch, array_merge(
        $member('aa000000000000000000000000000001/preview.png', $prefab = gameAssetPng(0, 0, 255)),
        $member('bb000000000000000000000000000002/pathname', 'Assets/Wood.mat'),
        $member('bb000000000000000000000000000002/preview.png', gameAssetPng(120, 80, 40)),
        $member('aa000000000000000000000000000001/pathname', 'Assets/Crate.prefab'),
    ));

    $result = (new UnityPackageReader)->read($package);

    expect($result['preview_png'])->toBe($prefab)
        ->and($result['preview_path'])->toBe('Assets/Crate.prefab')
        ->and($result['assets'])->toBe([
            ['path' => 'Assets/Crate.prefab', 'has_preview' => true],
            ['path' => 'Assets/Wood.mat', 'has_preview' => true],
        ]);
});

it('leaves a listing without a budget or deadline unchanged, and enforces one when asked', function () {
    $tgz = tarListingWrite($this->scratch, [tarListingHeader('big.bin', 20 * 1048576), str_repeat("\0", 20 * 1048576)]);

    expect(iterator_to_array(TarListing::entries($tgz), false))->toBe([['name' => 'big.bin', 'size' => 20 * 1048576, 'type' => 'file']]);
    expect(fn () => iterator_to_array(TarListing::entries($tgz, maxBytes: 1048576), false))->toThrow(RuntimeException::class, 'archive_budget');
});

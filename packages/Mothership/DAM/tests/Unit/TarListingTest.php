<?php

/*
 * TarListing reads the entry headers of the archive dam:storage:backup writes, whichever tar wrote it: the
 * system tar for real (bsdtar on macOS, GNU tar on the Linux runners and in the Studio container), and each
 * header format built byte by byte, so every format runs on every machine.
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\DamUpdater;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\TarListing;

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/tar-listing-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->scratch);
});

afterEach(fn () => File::deleteDirectory($this->scratch));

if (! function_exists('tarListingNfc')) {
    /** @return array<string, array{size: int, type: string}> every entry by its composed (NFC) name */
    function tarListingNfc(string $archive): array
    {
        $entries = [];

        foreach (TarListing::entries($archive) as $entry) {
            $entries[Normalizer::normalize($entry['name'], Normalizer::FORM_C)] = ['size' => $entry['size'], 'type' => $entry['type']];
        }

        return $entries;
    }
}

// A name over 100 bytes, non-ASCII (é and — as UTF-8), four folders deep.
const TAR_LISTING_LONG_PATH = "assets/Very Long Folder Name N\u{00FA}mero Uno/Another Quite Long Sub Folder Name Here/caf\u{00E9}/photo \u{2014} final version with a long file name.jpg";

it('reads the archive the system tar writes for a backup, long and non-ASCII names included', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);
    Storage::disk($disk)->put(TAR_LISTING_LONG_PATH, 'hello');
    Storage::disk($disk)->put('assets/.objects/ab/'.str_repeat('ab', 32), str_repeat('x', 1500));

    $archive = app(DamUpdater::class)->archiveAssetFiles($this->scratch);
    $entries = tarListingNfc($archive);

    expect(strlen(TAR_LISTING_LONG_PATH))->toBeGreaterThan(100)
        ->and($entries[TAR_LISTING_LONG_PATH] ?? null)->toBe(['size' => 5, 'type' => 'file'])
        ->and($entries['assets/.objects/ab/'.str_repeat('ab', 32)] ?? null)->toBe(['size' => 1500, 'type' => 'file'])
        ->and(collect($entries)->where('type', 'directory')->keys()->all())->toContain('assets/');
});

it('reads the ustar and pax formats both GNU tar and bsdtar write', function (string $format) {
    File::ensureDirectoryExists($this->scratch.'/root/'.dirname(TAR_LISTING_LONG_PATH));
    file_put_contents($this->scratch.'/root/'.TAR_LISTING_LONG_PATH, 'hello');
    $archive = $this->scratch.'/'.$format.'.tgz';

    Process::run(['tar', '--format='.$format, '-czf', $archive, '-C', $this->scratch.'/root', 'assets'])->throw();

    expect(tarListingNfc($archive)[TAR_LISTING_LONG_PATH] ?? null)->toBe(['size' => 5, 'type' => 'file']);
})->with(['ustar', 'pax']);

it('reads each header format built byte by byte', function () {
    $longName = 'assets/'.str_repeat('Long Folder/', 12).'file.bin';
    $entries = iterator_to_array(TarListing::entries(tarListingWrite($this->scratch, [
        // POSIX ustar: the name split into prefix and name.
        tarListingHeader(name: 'photo.jpg', size: 3, prefix: 'assets/Root/Deep'),
        tarListingData('abc'),
        // GNU: a long name in an `L` record, and a prefix field that holds other data and must be ignored.
        tarListingHeader(name: '././@LongLink', size: strlen($longName) + 1, type: 'L', magic: 'ustar ', version: " \0"),
        tarListingData($longName."\0"),
        tarListingHeader(name: substr($longName, 0, 100), size: 4, magic: 'ustar ', version: " \0", prefix: 'not a prefix'),
        tarListingData('abcd'),
        // GNU base-256 size.
        tarListingHeader(name: 'assets/base256.bin', size: 0, magic: 'ustar ', version: " \0", sizeField: "\x80".str_repeat("\0", 9)."\x02\x01"),
        tarListingData(str_repeat('z', 513)),
        // PAX: the path and the size override the header's, and the data runs for the PAX size.
        tarListingHeader(name: 'PaxHeader/x', size: strlen($pax = tarListingPaxRecord('path', "assets/caf\u{00E9} \u{2014} pax.txt").tarListingPaxRecord('size', '700')), type: 'x'),
        tarListingData($pax),
        tarListingHeader(name: 'assets/truncated-name', size: 0),
        tarListingData(str_repeat('p', 700)),
        // A directory and a link carry no data, whatever their size field says.
        tarListingHeader(name: 'assets/Root/', size: 0, type: '5'),
        tarListingHeader(name: 'assets/link', size: 99, type: '2'),
        tarListingHeader(name: 'assets/last.txt', size: 2),
        tarListingData('ok'),
    ])), false);

    expect($entries)->toBe([
        ['name' => 'assets/Root/Deep/photo.jpg', 'size' => 3, 'type' => 'file'],
        ['name' => $longName, 'size' => 4, 'type' => 'file'],
        ['name' => 'assets/base256.bin', 'size' => 513, 'type' => 'file'],
        ['name' => "assets/caf\u{00E9} \u{2014} pax.txt", 'size' => 700, 'type' => 'file'],
        ['name' => 'assets/Root/', 'size' => 0, 'type' => 'directory'],
        ['name' => 'assets/link', 'size' => 99, 'type' => 'link'],
        ['name' => 'assets/last.txt', 'size' => 2, 'type' => 'file'],
    ]);
});

if (! function_exists('tarListingDamaged')) {
    /** A copy of `$good` with `$change` applied to its bytes. */
    function tarListingDamaged(string $good, Closure $change): string
    {
        $path = $good.'.'.bin2hex(random_bytes(3));
        file_put_contents($path, $change((string) file_get_contents($good)));

        return $path;
    }
}

it('throws on an archive that is missing, truncated or corrupt, never reading it as a shorter one', function (Closure $damage, string $reason) {
    $good = tarListingWrite($this->scratch, [tarListingHeader(name: 'assets/a.txt', size: 600), tarListingData(str_repeat('a', 600)), tarListingHeader(name: 'assets/b.txt', size: 1), tarListingData('b')]);
    $damaged = $damage($good, $this->scratch);

    expect(iterator_to_array(TarListing::entries($good), false))->toHaveCount(2)
        ->and(fn () => iterator_to_array(TarListing::entries($damaged)))->toThrow(RuntimeException::class, $reason);
})->with([
    'no such file'                       => [fn (string $good, string $scratch) => $scratch.'/missing.tgz', 'archive_unreadable'],
    'the gzip stream cut short'          => [fn (string $good) => tarListingDamaged($good, fn (string $bytes) => substr($bytes, 0, 60)), 'archive_truncated'],
    'the tar cut before its end block'   => [fn (string $good) => tarListingDamaged($good, fn (string $bytes) => gzencode(substr((string) gzdecode($bytes), 0, 1536))), 'archive_truncated'],
    'a tar that is not gzip-compressed'  => [fn (string $good) => tarListingDamaged($good, fn (string $bytes) => (string) gzdecode($bytes)), 'archive_corrupt'],
    'a header checksum that is wrong'    => [fn (string $good) => tarListingDamaged($good, function (string $bytes) {
        $tar = (string) gzdecode($bytes);
        $tar[1536] = 'X'; // inside the second header's name

        return gzencode($tar);
    }), 'archive_corrupt'],
    'a trailer CRC-32 that does not match' => [fn (string $good) => tarListingDamaged($good, function (string $bytes) {
        $bytes[strlen($bytes) - 6] = chr(ord($bytes[strlen($bytes) - 6]) ^ 0xFF);

        return $bytes;
    }), 'archive_corrupt'],
    'a trailer length that does not match' => [fn (string $good) => tarListingDamaged($good, function (string $bytes) {
        $bytes[strlen($bytes) - 2] = chr(ord($bytes[strlen($bytes) - 2]) ^ 0xFF);

        return $bytes;
    }), 'archive_corrupt'],
    'bytes other than zeros after the gzip stream' => [fn (string $good) => tarListingDamaged($good, fn (string $bytes) => $bytes."\0\0junk"), 'archive_corrupt'],
    'a second gzip stream after the first'         => [fn (string $good) => tarListingDamaged($good, fn (string $bytes) => $bytes.gzencode('more')), 'archive_corrupt'],
]);

// Codex review of slice 4, round 1: gzread() returned an empty string at the end of the file whether or not the
// gzip trailer was there, so an archive cut anywhere in its last eight bytes (CRC-32 and length) read as complete.
it('refuses an archive missing any of its gzip trailer\'s eight bytes', function (int $cut) {
    $good = tarListingWrite($this->scratch, [tarListingHeader(name: 'assets/a.txt', size: 600), tarListingData(str_repeat('a', 600))]);
    $cutShort = tarListingDamaged($good, fn (string $bytes) => substr($bytes, 0, -$cut));

    expect(fn () => iterator_to_array(TarListing::entries($cutShort)))->toThrow(RuntimeException::class, 'archive_truncated');
})->with(range(1, 8));

it('accepts zero bytes after the gzip stream, as block padding leaves them', function () {
    $good = tarListingWrite($this->scratch, [tarListingHeader(name: 'assets/a.txt', size: 600), tarListingData(str_repeat('a', 600))]);
    $padded = tarListingDamaged($good, fn (string $bytes) => $bytes.str_repeat("\0", 70_000));

    expect(iterator_to_array(TarListing::entries($padded), false))->toBe([['name' => 'assets/a.txt', 'size' => 600, 'type' => 'file']]);
});

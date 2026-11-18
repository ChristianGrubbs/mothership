<?php

use Mothership\DAM\Support\AttemptDirectory;

/**
 * CliPanoramaWorker had zero direct test coverage — the poll loop and
 * Process interaction need a real subprocess to exercise honestly, but the
 * two properties a Task 4 review specifically flagged as both untested and
 * where the actual bugs lived (the realpath escape/flat-file check, and
 * whether cleanup really removes everything) are pure filesystem logic
 * with no subprocess involved. They were extracted into this class
 * specifically so they could be tested directly, in isolation, without
 * mocking Process or shelling out to anything.
 */
function makeAttemptDirFixture(): string
{
    $dir = sys_get_temp_dir().'/attempt-dir-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    return $dir;
}

afterEach(function () {
    // Belt-and-braces: any fixture directory a test forgot to assert away
    // should not survive to pollute a later test run.
    foreach (glob(sys_get_temp_dir().'/attempt-dir-test-*') ?: [] as $leftover) {
        AttemptDirectory::removeRecursively($leftover);
    }
});

it('accepts a file that lives directly inside the directory', function () {
    $dir = makeAttemptDirFixture();
    $file = $dir.'/output.mp4';
    file_put_contents($file, 'contents');

    $result = AttemptDirectory::realFileFlatWithin($dir, $file);

    expect($result)->toBe(realpath($file));
});

it('rejects a path that escapes the directory entirely', function () {
    $dir = makeAttemptDirFixture();
    $outside = sys_get_temp_dir().'/attempt-dir-test-outside-'.bin2hex(random_bytes(6)).'.mp4';
    file_put_contents($outside, 'contents');

    $result = AttemptDirectory::realFileFlatWithin($dir, $outside);

    expect($result)->toBeFalse();

    @unlink($outside);
});

it('rejects a path escaping via a sibling directory whose name is a prefix of the real one', function () {
    // A naive `str_starts_with($real, $dir)` (no trailing separator) would
    // wrongly accept "/tmp/attempt-1-evil/x" as being "within" "/tmp/attempt-1"
    // — the real implementation requires the DIRECTORY_SEPARATOR boundary.
    $dir = makeAttemptDirFixture();
    $sibling = $dir.'-evil';
    mkdir($sibling, 0700, true);
    $file = $sibling.'/output.mp4';
    file_put_contents($file, 'contents');

    $result = AttemptDirectory::realFileFlatWithin($dir, $file);

    expect($result)->toBeFalse();

    AttemptDirectory::removeRecursively($sibling);
});

it('rejects a path nested in a subdirectory — flat files only', function () {
    $dir = makeAttemptDirFixture();
    mkdir($dir.'/nested');
    $file = $dir.'/nested/output.mp4';
    file_put_contents($file, 'contents');

    $result = AttemptDirectory::realFileFlatWithin($dir, $file);

    expect($result)->toBeFalse();
});

it('rejects a path that does not exist', function () {
    $dir = makeAttemptDirFixture();

    $result = AttemptDirectory::realFileFlatWithin($dir, $dir.'/never-written.mp4');

    expect($result)->toBeFalse();
});

it('rejects a path that is itself a directory, not a file', function () {
    $dir = makeAttemptDirFixture();
    mkdir($dir.'/subdir');

    $result = AttemptDirectory::realFileFlatWithin($dir, $dir.'/subdir');

    expect($result)->toBeFalse();
});

it('removes a directory and everything nested inside it, including subdirectories', function () {
    $dir = makeAttemptDirFixture();
    file_put_contents($dir.'/output.mp4', 'final');
    mkdir($dir.'/2pass-logs');
    file_put_contents($dir.'/2pass-logs/ffmpeg2pass-0.log', 'log line one');
    file_put_contents($dir.'/2pass-logs/ffmpeg2pass-0.log.mbtree', 'mbtree data');
    mkdir($dir.'/2pass-logs/deeper');
    file_put_contents($dir.'/2pass-logs/deeper/segment.tmp', 'partial segment');

    AttemptDirectory::removeRecursively($dir);

    expect(is_dir($dir))->toBeFalse();
});

it('is a safe no-op on a directory that does not exist', function () {
    $dir = sys_get_temp_dir().'/attempt-dir-test-never-created-'.bin2hex(random_bytes(6));

    AttemptDirectory::removeRecursively($dir);

    expect(is_dir($dir))->toBeFalse();
});

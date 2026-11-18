<?php

declare(strict_types=1);

use Mothership\DAM\Support\Sha256File;

/** A tiny executable that prints exactly $output (no extra trailing newline beyond what it contains). */
function makeFakeShaBinary(string $output): string
{
    $path = tempnam(sys_get_temp_dir(), 'fake-sha256sum-');
    file_put_contents($path, "#!/bin/sh\ncat <<'FAKE_SHA_EOF'\n{$output}\nFAKE_SHA_EOF\n");
    chmod($path, 0755);

    return $path;
}

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'sha256file-test-');
    file_put_contents($this->path, str_repeat('sha256file-fixture-bytes-', 200));
});

afterEach(function () {
    @unlink($this->path);
    // A seam test may leave this set if it fails before its own reset; never let it leak into another test.
    Sha256File::$processFactory = null;
});

it('matches hash_file via the native binary once the file meets the threshold', function () {
    if (trim((string) shell_exec('command -v sha256sum')) === '') {
        $this->markTestSkipped('sha256sum is not on PATH.');
    }

    config([
        'dam.explorer.upload.native_sha256_min_bytes' => 1,
        'dam.explorer.upload.sha256_binary'           => 'sha256sum',
    ]);

    $result = Sha256File::hash($this->path);

    expect($result['sha256'])->toBe(hash_file('sha256', $this->path))
        ->and($result['method'])->toBe(Sha256File::METHOD_NATIVE)
        ->and($result['failure_reason'])->toBeNull();
});

it('takes the native path at the exact threshold boundary', function () {
    if (trim((string) shell_exec('command -v sha256sum')) === '') {
        $this->markTestSkipped('sha256sum is not on PATH.');
    }

    config([
        'dam.explorer.upload.native_sha256_min_bytes' => (int) filesize($this->path),
        'dam.explorer.upload.sha256_binary'           => 'sha256sum',
    ]);

    $result = Sha256File::hash($this->path);

    expect($result['sha256'])->toBe(hash_file('sha256', $this->path))
        ->and($result['method'])->toBe(Sha256File::METHOD_NATIVE);
});

it('falls back to hash_file and still matches when the configured binary does not exist', function () {
    config([
        'dam.explorer.upload.native_sha256_min_bytes' => 1,
        'dam.explorer.upload.sha256_binary'           => '/nonexistent/'.bin2hex(random_bytes(6)),
    ]);

    $result = Sha256File::hash($this->path);

    expect($result['sha256'])->toBe(hash_file('sha256', $this->path))
        ->and($result['method'])->toBe(Sha256File::METHOD_PHP)
        ->and($result['failure_reason'])->not->toBeNull();
});

it('uses php below the native threshold without attempting the binary', function () {
    config([
        'dam.explorer.upload.native_sha256_min_bytes' => PHP_INT_MAX,
        'dam.explorer.upload.sha256_binary'           => 'sha256sum',
    ]);

    $result = Sha256File::hash($this->path);

    expect($result['sha256'])->toBe(hash_file('sha256', $this->path))
        ->and($result['method'])->toBe(Sha256File::METHOD_PHP)
        ->and($result['failure_reason'])->toBeNull();
});

it('falls back to hash_file with a reason when Process construction throws', function () {
    // proc_open cannot be disabled at runtime to exercise that guard for real; this seam simulates any
    // throwable during construction/setTimeout/run the same way, since they all share one catch.
    Sha256File::$processFactory = function (array $command): never {
        throw new RuntimeException('proc_open unavailable');
    };

    config([
        'dam.explorer.upload.native_sha256_min_bytes' => 1,
        'dam.explorer.upload.sha256_binary'           => 'sha256sum',
    ]);

    $result = Sha256File::hash($this->path);

    expect($result['sha256'])->toBe(hash_file('sha256', $this->path))
        ->and($result['method'])->toBe(Sha256File::METHOD_PHP)
        ->and($result['failure_reason'])->toBe('native_sha256_process_error');
});

it('accepts a fake binary printing exactly one valid record for the real path, as a control', function () {
    $digest = hash_file('sha256', $this->path);
    $binary = makeFakeShaBinary("{$digest}  {$this->path}");

    config([
        'dam.explorer.upload.native_sha256_min_bytes' => 1,
        'dam.explorer.upload.sha256_binary'           => $binary,
    ]);

    try {
        $result = Sha256File::hash($this->path);

        expect($result['sha256'])->toBe($digest)
            ->and($result['method'])->toBe(Sha256File::METHOD_NATIVE)
            ->and($result['failure_reason'])->toBeNull();
    } finally {
        @unlink($binary);
    }
});

it('falls back to hash_file when the record is right-shaped but the digest itself is not valid hex', function () {
    // Record-shaped (64 characters, the correct separator, the real path) but not a real digest: the last
    // character is non-hex. Every other malformed-output case here still starts with a genuinely valid
    // 64-hex digest, so none of them would catch a regression that loosened the digest character class.
    $badDigest = str_repeat('a', 63).'g';
    $binary = makeFakeShaBinary("{$badDigest}  {$this->path}");

    config([
        'dam.explorer.upload.native_sha256_min_bytes' => 1,
        'dam.explorer.upload.sha256_binary'           => $binary,
    ]);

    try {
        $result = Sha256File::hash($this->path);

        expect($result['sha256'])->toBe(hash_file('sha256', $this->path))
            ->and($result['method'])->toBe(Sha256File::METHOD_PHP)
            ->and($result['failure_reason'])->toBe('native_sha256_unparsable_output');
    } finally {
        @unlink($binary);
    }
});

it('falls back to hash_file when a valid record for the real path is followed by trailing garbage', function () {
    $digest = hash_file('sha256', $this->path);
    // A record that is genuinely correct for the real path, but with the anchor's required \z violated by
    // more text after it: the path check alone would accept this, only the end anchor catches it.
    $binary = makeFakeShaBinary("{$digest}  {$this->path}\nEXTRA GARBAGE AFTER THE RECORD");

    config([
        'dam.explorer.upload.native_sha256_min_bytes' => 1,
        'dam.explorer.upload.sha256_binary'           => $binary,
    ]);

    try {
        $result = Sha256File::hash($this->path);

        expect($result['sha256'])->toBe($digest)
            ->and($result['method'])->toBe(Sha256File::METHOD_PHP)
            ->and($result['failure_reason'])->toBe('native_sha256_unparsable_output');
    } finally {
        @unlink($binary);
    }
});

it('falls back to hash_file when a valid record for the real path is followed by a second record', function () {
    $digest = hash_file('sha256', $this->path);
    // The first line alone is a genuinely correct, complete record for the real path; only the second
    // record after it should make this fail the single-record anchor.
    $binary = makeFakeShaBinary("{$digest}  {$this->path}\n".str_repeat('a', 64)."  {$this->path}.other");

    config([
        'dam.explorer.upload.native_sha256_min_bytes' => 1,
        'dam.explorer.upload.sha256_binary'           => $binary,
    ]);

    try {
        $result = Sha256File::hash($this->path);

        expect($result['sha256'])->toBe($digest)
            ->and($result['method'])->toBe(Sha256File::METHOD_PHP)
            ->and($result['failure_reason'])->toBe('native_sha256_unparsable_output');
    } finally {
        @unlink($binary);
    }
});

it('falls back to hash_file when the digest is real but the path in the record is wrong', function () {
    $digest = hash_file('sha256', $this->path);
    // The digest is genuinely correct for the real file; only the claimed path is wrong, which the path
    // check (not the end anchor) must catch.
    $binary = makeFakeShaBinary("{$digest}  {$this->path}.wrong");

    config([
        'dam.explorer.upload.native_sha256_min_bytes' => 1,
        'dam.explorer.upload.sha256_binary'           => $binary,
    ]);

    try {
        $result = Sha256File::hash($this->path);

        expect($result['sha256'])->toBe($digest)
            ->and($result['method'])->toBe(Sha256File::METHOD_PHP)
            ->and($result['failure_reason'])->toBe('native_sha256_unparsable_output');
    } finally {
        @unlink($binary);
    }
});

<?php

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Mothership\DAM\Services\MetadataExtractionService;

/**
 * Regression cover for the 249 `exiftool failed (exit 1)` errors logged on the deployed
 * runtime between 2026-09-16 and 2026-09-19, 249/249 of them `.glb`.
 *
 * Verified read-only against that runtime with exiftool 13.25: a valid GLB makes exiftool
 * exit 1 *and* still print a well-formed JSON record of ten keys - SourceFile,
 * ExifToolVersion, FileName, Directory, FileSize, FileModifyDate, FileAccessDate,
 * FileInodeChangeDate, FilePermissions and an `Error` key holding the reason. STL behaves
 * identically; 3MF is a ZIP, so exiftool parses it and exits 0. The service was therefore
 * never "unable to read GLB" - it discarded metadata exiftool had already handed it.
 *
 * The stub reproduces that contract exactly - arbitrary stdout, arbitrary exit status - so
 * these cases depend on neither an exiftool binary being installed nor on any particular
 * exiftool version.
 */
function damExiftoolStubDir(): string
{
    static $dir = null;

    if ($dir !== null) {
        return $dir;
    }

    $dir = sys_get_temp_dir().'/dam_exiftool_stub_'.getmypid();
    @mkdir($dir, 0700, true);

    $stub = <<<'SH'
#!/bin/sh
if [ "$1" = "-ver" ]; then echo "13.25"; exit 0; fi
if [ -n "${DAM_TEST_EXIFTOOL_STDOUT:-}" ]; then cat "$DAM_TEST_EXIFTOOL_STDOUT"; fi
exit "${DAM_TEST_EXIFTOOL_EXIT:-1}"
SH;

    file_put_contents($dir.'/exiftool', $stub);
    chmod($dir.'/exiftool', 0755);

    register_shutdown_function(static function () use ($dir): void {
        @unlink($dir.'/exiftool');
        @rmdir($dir);
    });

    return $dir;
}

/**
 * MetadataExtractionService::exiftoolExists() memoises its answer in a function static,
 * so probing a stub through it would strand that cache on `true` for every later test in
 * the process. Overriding the probe keeps the stub's reach inside this file.
 */
function damMetaService(): MetadataExtractionService
{
    return new class extends MetadataExtractionService
    {
        public function exiftoolExists(): bool
        {
            return true;
        }
    };
}

/** Exactly what the stub writes to stdout, byte for byte, and the status it exits with. */
function damExiftoolStdout(string $raw, int $exitCode = 1): void
{
    $file = tempnam(sys_get_temp_dir(), 'dam_exiftool_out_');
    file_put_contents($file, $raw);
    putenv('DAM_TEST_EXIFTOOL_STDOUT='.$file);
    putenv('DAM_TEST_EXIFTOOL_EXIT='.$exitCode);
}

function damExiftoolRecord(array $record, int $exitCode = 1): void
{
    damExiftoolStdout(json_encode([$record]), $exitCode);
}

/** No stdout at all, which is a distinct failure class from unparseable stdout. */
function damExiftoolSilent(int $exitCode = 1): void
{
    putenv('DAM_TEST_EXIFTOOL_STDOUT');
    putenv('DAM_TEST_EXIFTOOL_EXIT='.$exitCode);
}

function damGlbFixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'dam_model_').'.glb';
    // Real glTF binary container header: magic "glTF", version 2, total length.
    $body = str_repeat("\0", 20);
    file_put_contents($path, 'glTF'.pack('V', 2).pack('V', 12 + strlen($body)).$body);

    return $path;
}

/**
 * A fixture whose own path is user content: the asset name carries a person's name and a
 * document description, exactly the shape that must never appear in a log line.
 */
function damIdentifiableGlbFixture(): string
{
    $dir = sys_get_temp_dir().'/dam_probe_'.bin2hex(random_bytes(4));
    @mkdir($dir, 0700, true);
    $path = $dir.'/Jane Doe 2026 transcript scan.glb';
    $body = str_repeat("\0", 20);
    file_put_contents($path, 'glTF'.pack('V', 2).pack('V', 12 + strlen($body)).$body);

    return $path;
}

function damForgetFixture(string $path): void
{
    @unlink($path);
    @rmdir(dirname($path));
}

function damGlbExiftoolRecord(string $sourceFile): array
{
    return [
        'SourceFile'          => $sourceFile,
        'ExifToolVersion'     => 13.25,
        'FileName'            => basename($sourceFile),
        'Directory'           => dirname($sourceFile),
        'FileSize'            => '28 MB',
        'FileModifyDate'      => '2026:09:17 02:31:00+00:00',
        'FileAccessDate'      => '2026:09:17 02:31:00+00:00',
        'FileInodeChangeDate' => '2026:09:17 02:31:00+00:00',
        'FilePermissions'     => '-rw-r--r--',
        'Error'               => 'Unknown file type',
    ];
}

/** Decoded structured lines of one event name. */
function damEventsNamed(TestHandler $handler, string $event): array
{
    $events = [];

    foreach ($handler->getRecords() as $record) {
        $decoded = json_decode((string) $record->message, true);

        if (is_array($decoded) && ($decoded['event'] ?? null) === $event) {
            $events[] = $decoded;
        }
    }

    return $events;
}

function damPartialEvents(TestHandler $handler): array
{
    return damEventsNamed($handler, 'metadata-extraction.partial');
}

function damFailureEvents(TestHandler $handler): array
{
    return damEventsNamed($handler, 'metadata-extraction.failed');
}

function damErrorRecords(TestHandler $handler): array
{
    return array_values(array_filter(
        $handler->getRecords(),
        static fn ($record): bool => $record->level->value >= Level::Error->value,
    ));
}

/**
 * Every failure log the run wrote, as one string, for leak assertions.
 *
 * Scoped to error level on purpose. `extractMetadata()` separately emits a pre-existing
 * `MetadataExtractionService: detected mime "..." for <path>` info line that also carries
 * the asset path; that line lives in a different method, predates this work and was not
 * part of the adjudicated findings, so it is reported as a residual rather than changed
 * here.
 */
function damFailureLogText(TestHandler $handler): string
{
    return implode("\n", array_map(
        static fn ($record): string => (string) $record->message.' '.json_encode($record->context),
        damErrorRecords($handler),
    ));
}

beforeEach(function () {
    $this->originalPath = (string) getenv('PATH');
    putenv('PATH='.damExiftoolStubDir().':'.$this->originalPath);
    putenv('DAM_TEST_EXIFTOOL_EXIT=1');

    $this->logHandler = new TestHandler;
    Log::getLogger()->pushHandler($this->logHandler);
});

afterEach(function () {
    Log::getLogger()->popHandler();
    // The stub must not outlive this file: any later test that shells out to exiftool has
    // to see the real environment again.
    putenv('PATH='.$this->originalPath);
    putenv('DAM_TEST_EXIFTOOL_STDOUT');
    putenv('DAM_TEST_EXIFTOOL_EXIT');
});

it('keeps the basic metadata exiftool returned for a GLB instead of logging an error', function () {
    $glb = damGlbFixture();
    damExiftoolRecord(damGlbExiftoolRecord($glb));

    try {
        $result = damMetaService()->extractMetadata(
            $glb,
            'local',
            $glb,
            originalFileName: 'campus-model.glb',
        );
    } finally {
        @unlink($glb);
    }

    expect($result)->not->toBe([])
        ->and($result['FileSize'])->toBe('28 MB')
        ->and($result['FileName'])->toBe('campus-model.glb')
        ->and($result['FileModifyDate'])->toBe('2026:09:17 02:31:00+00:00')
        ->and($result)->toHaveKey('exif')
        // exiftool's own diagnostic is not asset metadata and must not reach the asset.
        ->and($result)->not->toHaveKey('Error')
        ->and($result['exif'])->not->toHaveKey('Error');

    expect(damErrorRecords($this->logHandler))->toBe([]);
});

it('records the unsupported parser as one structured info line that carries no asset path', function () {
    $glb = damGlbFixture();
    damExiftoolRecord(damGlbExiftoolRecord($glb));

    try {
        damMetaService()->extractMetadata($glb, 'local', $glb, originalFileName: 'campus-model.glb');
    } finally {
        @unlink($glb);
    }

    $events = damPartialEvents($this->logHandler);
    expect($events)->toHaveCount(1);
    $entry = $events[0];

    expect($entry['ctx']['context'])->toBe('model')
        ->and($entry['ctx']['exit_code'])->toBe(1)
        ->and($entry['ctx']['reason'])->toBe('unknown-file-type')
        ->and($entry['ctx']['extension'])->toBe('glb')
        ->and($entry['loc'])->toBe(MetadataExtractionService::class)
        ->and($entry['run_id'])->not->toBeEmpty()
        ->and(json_encode($entry))->not->toContain($glb);
});

it('leaves a successful exit-zero record untouched and logs nothing', function () {
    $glb = damGlbFixture();
    $record = damGlbExiftoolRecord($glb);
    unset($record['Error']);
    $record['FileType'] = 'GLTF';
    damExiftoolRecord($record, 0);

    try {
        $result = damMetaService()->extractMetadata($glb, 'local', $glb, originalFileName: 'campus-model.glb');
    } finally {
        @unlink($glb);
    }

    expect($result['FileSize'])->toBe('28 MB')
        ->and($result['FileType'])->toBe('GLTF')
        ->and($result['FileName'])->toBe('campus-model.glb');

    expect(damPartialEvents($this->logHandler))->toBe([]);
    expect(damErrorRecords($this->logHandler))->toBe([]);
});

it('never copies exiftool diagnostic text or user content into the structured reason', function () {
    $glb = damGlbFixture();
    $secretish = '/srv/dam/Students/Jane Doe 2026 transcript scan.glb';
    $record = damGlbExiftoolRecord($glb);
    $record['Error'] = 'Unknown file type for '.$secretish.' (uploaded by jane.doe@example.edu)';
    damExiftoolRecord($record);

    try {
        $result = damMetaService()->extractMetadata($glb, 'local', $glb, originalFileName: 'campus-model.glb');
    } finally {
        @unlink($glb);
    }

    expect($result['FileSize'])->toBe('28 MB');

    $events = damPartialEvents($this->logHandler);
    expect($events)->toHaveCount(1);

    $serialised = json_encode($events[0]);
    expect($events[0]['ctx']['reason'])->toBe('unknown-file-type')
        ->and($serialised)->not->toContain($secretish)
        ->and($serialised)->not->toContain('Jane Doe')
        ->and($serialised)->not->toContain('jane.doe@example.edu')
        ->and($serialised)->not->toContain($glb);
});

it('maps an unrecognised diagnostic to a bounded code rather than echoing it', function () {
    $glb = damGlbFixture();
    $record = damGlbExiftoolRecord($glb);
    $record['Error'] = 'Something nobody has seen before at /var/private/secret asset.glb';
    damExiftoolRecord($record);

    try {
        damMetaService()->extractMetadata($glb, 'local', $glb, originalFileName: 'campus-model.glb');
    } finally {
        @unlink($glb);
    }

    $events = damPartialEvents($this->logHandler);
    expect($events)->toHaveCount(1);
    expect($events[0]['ctx']['reason'])->toBe('other')
        ->and(json_encode($events[0]))->not->toContain('/var/private/secret');
});

/**
 * Round 2, finding 1. `is_array($decoded[0])` accepted a JSON *list* as a metadata record,
 * and stripping named keys cannot reject one, so `[["not a metadata record"]]` on exit 1
 * scored kept_keys=1 and was reported as a partial success. Record shape is now validated
 * before anything is classified as usable. These fixtures are all syntactically valid JSON
 * - the point is that valid JSON is not the same thing as a metadata object.
 */
it('rejects valid JSON that is not a metadata object', function (string $stdout, int $exitCode) {
    $glb = damGlbFixture();
    damExiftoolStdout($stdout, $exitCode);

    try {
        $result = damMetaService()->extractMetadata($glb, 'local', $glb, originalFileName: 'campus-model.glb');
    } finally {
        @unlink($glb);
    }

    expect($result)->toBe([]);
    expect(damPartialEvents($this->logHandler))->toBe([]);

    $failures = damFailureEvents($this->logHandler);
    expect($failures)->toHaveCount(1)
        ->and($failures[0]['ctx']['failure'])->toBe('invalid-record-shape')
        ->and($failures[0]['ctx']['exit_code'])->toBe($exitCode);
    expect(damErrorRecords($this->logHandler))->toHaveCount(1);
})->with([
    'list-valued first record, non-zero exit' => ['[["not a metadata record"]]', 1],
    'list-valued first record, exit zero'     => ['[["not a metadata record"]]', 0],
    'numerically keyed object'                => ['[{"0":"not a tag name"}]', 1],
    'scalar first record'                     => ['["not a metadata record"]', 1],
    'null first record'                       => ['[null]', 1],
    'boolean first record'                    => ['[true]', 1],
    'top-level object rather than a list'     => ['{"FileSize":"28 MB"}', 1],
    'top-level scalar'                        => ['"not a metadata record"', 1],
    'top-level number'                        => ['42', 0],
]);

it('reports genuinely unparseable output as its own failure class', function (int $exitCode) {
    $glb = damGlbFixture();
    damExiftoolStdout('Error: File format error', $exitCode);

    try {
        $result = damMetaService()->extractMetadata($glb, 'local', $glb);
    } finally {
        @unlink($glb);
    }

    expect($result)->toBe([]);
    expect(damPartialEvents($this->logHandler))->toBe([]);

    $failures = damFailureEvents($this->logHandler);
    expect($failures)->toHaveCount(1)
        ->and($failures[0]['ctx']['failure'])->toBe('unparseable-output')
        ->and($failures[0]['ctx']['exit_code'])->toBe($exitCode);
})->with([
    'non-zero exit' => [1],
    'exit zero'     => [0],
]);

it('reports genuinely empty stdout as its own failure class', function (int $exitCode) {
    $glb = damGlbFixture();
    damExiftoolSilent($exitCode);

    try {
        $result = damMetaService()->extractMetadata($glb, 'local', $glb);
    } finally {
        @unlink($glb);
    }

    expect($result)->toBe([]);
    expect(damPartialEvents($this->logHandler))->toBe([]);

    $failures = damFailureEvents($this->logHandler);
    expect($failures)->toHaveCount(1)
        ->and($failures[0]['ctx']['failure'])->toBe('empty-output')
        ->and($failures[0]['ctx']['exit_code'])->toBe($exitCode);
})->with([
    'non-zero exit' => [1],
    'exit zero'     => [0],
]);

/**
 * A well-formed record that retains nothing after the system and diagnostic keys are
 * removed is the plain failure the caller has always been told about, not a partial.
 */
it('treats a non-zero exit whose record carries nothing usable as the failure it is', function (array $record) {
    $glb = damGlbFixture();
    damExiftoolRecord($record);

    try {
        $result = damMetaService()->extractMetadata($glb, 'local', $glb, originalFileName: 'campus-model.glb');
    } finally {
        @unlink($glb);
    }

    expect($result)->toBe([]);
    expect(damPartialEvents($this->logHandler))->toBe([]);

    $failures = damFailureEvents($this->logHandler);
    expect($failures)->toHaveCount(1)
        ->and($failures[0]['ctx']['failure'])->toBe('no-usable-metadata')
        ->and($failures[0]['ctx']['exit_code'])->toBe(1);
    expect(damErrorRecords($this->logHandler))->toHaveCount(1);
})->with([
    'empty object'    => [[]],
    'diagnostic only' => [[
        'SourceFile' => '/srv/dam/assets/model.glb',
        'Error'      => 'Unknown file type',
    ]],
    'warning only' => [[
        'SourceFile' => '/srv/dam/assets/model.glb',
        'Warning'    => 'Truncated file',
    ]],
    'stripped keys only' => [[
        'SourceFile'          => '/srv/dam/assets/model.glb',
        'ExifToolVersion'     => 13.25,
        'Directory'           => '/srv/dam/assets',
        'FilePermissions'     => '-rw-r--r--',
        'FileAccessDate'      => '2026:09:17 02:31:00+00:00',
        'FileInodeChangeDate' => '2026:09:17 02:31:00+00:00',
    ]],
]);

/**
 * Round 2, finding 2. Every failure branch used to log the temp path verbatim, so the
 * asset name - user content - reached the log on the exact paths where nothing else about
 * the file was known. The fixture below is named after a person and a document, and no
 * failure branch may repeat any of it at any level.
 */
it('keeps the asset path and user content out of every failure log', function (
    string $failureClass,
    ?string $stdout,
    int $exitCode,
) {
    $glb = damIdentifiableGlbFixture();

    if ($stdout === null) {
        damExiftoolSilent($exitCode);
    } else {
        damExiftoolStdout(str_replace('{PATH}', $glb, $stdout), $exitCode);
    }

    try {
        $result = damMetaService()->extractMetadata($glb, 'local', $glb, originalFileName: 'campus-model.glb');
    } finally {
        damForgetFixture($glb);
    }

    expect($result)->toBe([]);

    $failures = damFailureEvents($this->logHandler);
    expect($failures)->toHaveCount(1)
        ->and($failures[0]['ctx']['failure'])->toBe($failureClass)
        ->and($failures[0]['ctx']['context'])->toBe('model')
        ->and($failures[0]['ctx']['exit_code'])->toBe($exitCode)
        ->and($failures[0]['ctx']['extension'])->toBe('glb')
        ->and($failures[0]['loc'])->toBe(MetadataExtractionService::class);

    // Bounded context survives; the path and everything in it does not - checked against
    // every error-level line the run wrote, not just the structured failure event.
    expect(damErrorRecords($this->logHandler))->toHaveCount(1);
    $logged = damFailureLogText($this->logHandler);
    expect($logged)->not->toContain($glb)
        ->and($logged)->not->toContain(dirname($glb))
        ->and($logged)->not->toContain('Jane Doe')
        ->and($logged)->not->toContain('transcript')
        ->and($logged)->not->toContain('uploaded by jane.doe@example.edu');
})->with([
    'diagnostic only, non-zero exit' => [
        'no-usable-metadata',
        '[{"SourceFile":"{PATH}","Error":"Unknown file type for {PATH} (uploaded by jane.doe@example.edu)"}]',
        1,
    ],
    'empty object, non-zero exit' => ['no-usable-metadata', '[{}]', 1],
    'empty stdout, non-zero exit' => ['empty-output', null, 1],
    'empty stdout, exit zero'     => ['empty-output', null, 0],
    'unparseable, non-zero exit'  => [
        'unparseable-output',
        'Error: cannot read {PATH} uploaded by jane.doe@example.edu',
        1,
    ],
    'unparseable, exit zero' => [
        'unparseable-output',
        'Error: cannot read {PATH} uploaded by jane.doe@example.edu',
        0,
    ],
    'invalid shape, non-zero exit' => ['invalid-record-shape', '[["{PATH}"]]', 1],
    'invalid shape, exit zero'     => ['invalid-record-shape', '[["{PATH}"]]', 0],
]);

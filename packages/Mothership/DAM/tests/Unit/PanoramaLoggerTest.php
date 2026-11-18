<?php

use Mothership\DAM\Support\PanoramaLogger;

/**
 * Task 3 originally logged through Laravel's default Log facade, which
 * wraps every line in Monolog's LineFormatter prefix
 * (`[2026-...] local.INFO: {...} [] []`) — that is NOT valid JSON, so a
 * consumer doing json_decode() per line fails on every one of those lines.
 * PanoramaLogger is the fix: a bare-JSONL sink both Task 3's service and
 * Task 4's queue worker now write through. These tests prove each line
 * really is parseable JSON carrying all six required fields with the right
 * types, and that run_id is stable across multiple events instead of being
 * re-minted per line (Task 3's original defect: Str::uuid() fresh per call
 * meant run_id correlated nothing).
 */
// panoramaLogPath()/resetPanoramaLog()/readPanoramaLogLines() live in
// tests/Support/DamTestHelpers.php, required unconditionally by
// tests/Pest.php, so they are available here even when this file runs in
// isolation via a single-file verify.py invocation.

beforeEach(function () {
    resetPanoramaLog();
});

afterEach(function () {
    resetPanoramaLog();
});

it('writes a bare JSONL line that parses on its own, unlike a Monolog-prefixed line', function () {
    (new PanoramaLogger)->write('run-abc', 'info', 'panorama_job_started', 'GeneratePanoramaPreview', [
        'rendition_id' => 7,
        'asset_id'     => 3,
    ]);

    $lines = readPanoramaLogLines();

    expect($lines)->toHaveCount(1);

    // The whole line must be valid JSON top to bottom — a Monolog-prefixed
    // line (`[2026-...] local.INFO: {...} [] []`) would fail this decode.
    $decoded = json_decode($lines[0], true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded)->toBeArray();
});

it('carries all six required fields with the correct types', function () {
    (new PanoramaLogger)->write('run-abc', 'info', 'panorama_job_started', 'GeneratePanoramaPreview', [
        'rendition_id' => 7,
        'asset_id'     => 3,
        'stage'        => 'start',
    ]);

    $decoded = json_decode(readPanoramaLogLines()[0], true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKeys(['ts', 'run_id', 'level', 'event', 'loc', 'ctx'])
        ->and($decoded['ts'])->toBeString()
        ->and(strtotime($decoded['ts']))->not->toBeFalse()
        ->and($decoded['run_id'])->toBe('run-abc')
        ->and($decoded['level'])->toBe('info')
        ->and($decoded['event'])->toBe('panorama_job_started')
        ->and($decoded['loc'])->toBe('GeneratePanoramaPreview')
        ->and($decoded['ctx'])->toBeArray()
        ->and($decoded['ctx']['rendition_id'])->toBeInt()
        ->and($decoded['ctx']['rendition_id'])->toBe(7)
        ->and($decoded['ctx']['asset_id'])->toBe(3)
        ->and($decoded['ctx']['stage'])->toBeString();
});

it('keeps run_id stable across multiple events belonging to one operation', function () {
    $logger = new PanoramaLogger;

    $logger->write('run-shared', 'info', 'panorama_job_started', 'GeneratePanoramaPreview', ['rendition_id' => 9]);
    $logger->write('run-shared', 'info', 'panorama_progress', 'GeneratePanoramaPreview', ['rendition_id' => 9, 'percent' => 50]);
    $logger->write('run-shared', 'info', 'panorama_publish_done', 'GeneratePanoramaPreview', ['rendition_id' => 9]);

    $lines = readPanoramaLogLines();
    expect($lines)->toHaveCount(3);

    $runIds = array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR)['run_id'], $lines);

    expect(array_unique($runIds))->toBe(['run-shared']);
});

it('derives the same run_id for the same rendition id every time, and a fresh one when there is none', function () {
    expect(PanoramaLogger::runIdForRendition(42))->toBe(PanoramaLogger::runIdForRendition(42))
        ->and(PanoramaLogger::runIdForRendition(42))->not->toBe(PanoramaLogger::runIdForRendition(43))
        ->and(PanoramaLogger::runIdForRendition(null))->not->toBe(PanoramaLogger::runIdForRendition(null));
});

it('redacts private paths and raw output before they reach the log', function () {
    (new PanoramaLogger)->write('run-redact', 'error', 'panorama_job_failed', 'GeneratePanoramaPreview', [
        'rendition_id' => 1,
        'source_path'  => '/private/var/secret/original.osv',
        'output_path'  => '/tmp/mothership-panorama-1/output.mp4',
        'stderr'       => 'ffmpeg dumped raw internals here',
        'contents'     => 'binary-ish contents',
        'error_code'   => 'invalid_codec',
    ]);

    $ctx = json_decode(readPanoramaLogLines()[0], true, flags: JSON_THROW_ON_ERROR)['ctx'];

    expect($ctx)->not->toHaveKeys(['source_path', 'output_path', 'stderr', 'contents'])
        ->and($ctx['error_code'])->toBe('invalid_codec')
        ->and($ctx['rendition_id'])->toBe(1);
});

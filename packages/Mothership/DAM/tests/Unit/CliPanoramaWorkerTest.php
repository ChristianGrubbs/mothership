<?php

use Mothership\DAM\Media\CliPanoramaWorker;

/**
 * CliPanoramaWorker's Process/poll-loop machinery needs a real subprocess
 * to exercise honestly and is out of scope for unit coverage (see
 * AttemptDirectoryTest.php for the escape-check and cleanup-recursion
 * coverage extracted out of it). But the type-discipline fixes from the
 * Task 4 review — reject a malformed `percent` rather than silently
 * coercing it, and sanitize `stage`/error `code` down to a safe slug —
 * are pure, private, dependency-free parsing logic. Reflection is the
 * pragmatic way to exercise them directly without standing up a fake
 * subprocess just to get a JSONL line parsed.
 */
function invokeCliWorkerMethod(string $method, mixed ...$args): mixed
{
    $worker = new CliPanoramaWorker;
    $reflection = new ReflectionMethod($worker, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($worker, ...$args);
}

it('accepts a valid integer or float percent within range', function () {
    expect(invokeCliWorkerMethod('parsePercent', 0))->toBe(0)
        ->and(invokeCliWorkerMethod('parsePercent', 54))->toBe(54)
        ->and(invokeCliWorkerMethod('parsePercent', 100))->toBe(100)
        ->and(invokeCliWorkerMethod('parsePercent', 54.7))->toBe(54);
});

it('rejects a non-numeric percent instead of silently coercing it to a number', function () {
    // The bug this closes: blindly (int)-casting whatever the CLI sends
    // admits ANY malformed value as *some* number rather than rejecting
    // it — the fix is strict type + range checking, discarding the event
    // rather than trusting a coerced value.
    expect(invokeCliWorkerMethod('parsePercent', '54%'))->toBeNull()
        ->and(invokeCliWorkerMethod('parsePercent', 'fifty-four'))->toBeNull()
        ->and(invokeCliWorkerMethod('parsePercent', null))->toBeNull()
        ->and(invokeCliWorkerMethod('parsePercent', []))->toBeNull()
        ->and(invokeCliWorkerMethod('parsePercent', true))->toBeNull();
});

it('rejects a percent outside 0-100', function () {
    expect(invokeCliWorkerMethod('parsePercent', -1))->toBeNull()
        ->and(invokeCliWorkerMethod('parsePercent', 101))->toBeNull();
});

it('normalizes a well-formed stage label', function () {
    expect(invokeCliWorkerMethod('parseStage', 'Encoding'))->toBe('encoding')
        ->and(invokeCliWorkerMethod('parseStage', '  two_pass-1  '))->toBe('two_pass-1');
});

it('strips characters outside [a-z0-9_-], truncates, and never lets a path through', function () {
    expect(invokeCliWorkerMethod('parseStage', '/private/var/tmp/attempt-1/source.osv'))
        ->toBe('privatevartmpattempt-1sourceosv')
        ->and(invokeCliWorkerMethod('parseStage', str_repeat('a', 100)))
        ->toHaveLength(64)
        ->and(invokeCliWorkerMethod('parseStage', '!!!'))->toBe('unknown')
        ->and(invokeCliWorkerMethod('parseStage', ''))->toBe('unknown')
        ->and(invokeCliWorkerMethod('parseStage', 123))->toBe('unknown');
});

it('normalizes a well-formed error code', function () {
    expect(invokeCliWorkerMethod('parseErrorCode', 'ffmpeg_crashed'))->toBe('ffmpeg_crashed');
});

it('falls back to a safe default error code for anything malformed', function () {
    expect(invokeCliWorkerMethod('parseErrorCode', null))->toBe('worker_reported_error')
        ->and(invokeCliWorkerMethod('parseErrorCode', 123))->toBe('worker_reported_error')
        ->and(invokeCliWorkerMethod('parseErrorCode', '!!!'))->toBe('worker_reported_error');
});

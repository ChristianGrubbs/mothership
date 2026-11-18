<?php

use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Support\RequestId;

/** P1a: one request id from the edge to the worker's status row (§3.6.11). */
it('echoes a safe inbound X-Request-Id', function () {
    $this->get(route('admin.session.create'), ['X-Request-Id' => 'trace.Ab-12_34'])
        ->assertHeader('X-Request-Id', 'trace.Ab-12_34');
});

it('replaces an unsafe inbound X-Request-Id with a fresh uuid', function (string $inbound) {
    $echoed = $this->get(route('admin.session.create'), ['X-Request-Id' => $inbound])->headers->get('X-Request-Id');

    expect($echoed)->not->toBe($inbound)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
})->with([
    'too short'       => ['abc'],
    'markup'          => ['<script>alert(1)</script>'],
    'log injection'   => ["abcdefgh\nlevel=error"],
    'too long'        => [str_repeat('a', 65)],
]);

it('replaces an id that a trailing newline alone makes unsafe', function (string $inbound) {
    expect(RequestId::accept($inbound))->not->toBe($inbound)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
})->with([
    'trailing LF'          => ["abcdefgh\n"],
    '64 characters and LF' => [str_repeat('a', 64)."\n"],
]);

it('carries the request id into a queued job and onto the status row the worker writes', function () {
    $asset = Asset::factory()->create();
    Route::middleware('web')->get('/_p1a/ai-index/{id}', function (int $id) {
        AiIndexAsset::dispatch($id);

        return response()->noContent();
    });

    $this->get('/_p1a/ai-index/'.$asset->id, ['X-Request-Id' => 'queued-trace-0001'])->assertNoContent();

    // The worker is another process: it knows the id only from the job's payload.
    Context::flush();
    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->exists())->toBeFalse();

    app('queue.worker')->runNextJob('ai-index', 'ai-index', new WorkerOptions(maxTries: 1, sleep: 0));

    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('http_request_id'))->toBe('queued-trace-0001');
});

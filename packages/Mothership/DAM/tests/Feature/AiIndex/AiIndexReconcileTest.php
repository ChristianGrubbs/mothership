<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Console\Commands\AiIndexReconcile;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Jobs\AiReindexLexical;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;

it('re-queues done rows that never got a caption and only refreshes words for captioned ones', function () {
    config(['ai-index.index_enabled' => true]);
    $uncaptioned = Asset::factory()->create();
    $captioned = Asset::factory()->create();

    foreach ([[$uncaptioned->id, null], [$captioned->id, 'A falcon.']] as [$id, $caption]) {
        // Queueing the index already made the pending row.
        DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $id], ['status' => 'done', 'caption' => $caption, 'created_at' => now(), 'updated_at' => now()]);
    }

    Queue::fake();
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();

    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $uncaptioned->id);
    Queue::assertNotPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $captioned->id);
    Queue::assertPushed(AiReindexLexical::class, fn ($job) => $job->assetIds === [$captioned->id]);
});

it('stops retrying a failed row after twelve attempts across dispatches', function () {
    config(['ai-index.index_enabled' => true]);
    $exhausted = Asset::factory()->create();
    $retryable = Asset::factory()->create();

    foreach ([[$exhausted->id, 12], [$retryable->id, 4]] as [$id, $attempts]) {
        // Queueing the index already made the pending row.
        DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $id], ['status' => 'failed', 'attempts' => $attempts, 'caption' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    Queue::fake();
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();

    Queue::assertNotPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $exhausted->id);
    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $retryable->id);
});

it('bounds retries for a failed row whose original differs from the stored sha, until the content is replaced', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);
    config(['ai-index.index_enabled' => true]);
    $store = app(ContentStore::class);
    $staged = $store->stagingPath('jpg');
    Storage::disk($disk)->put($staged, 'real-original-bytes');
    $object = $store->adoptStored(disk: $disk, sourcePath: $staged, mimeType: 'image/jpeg', extension: 'jpg');
    $asset = (new Asset([
        'file_name' => 'photo.jpg', 'file_type' => 'image', 'file_size' => 19,
        'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'path' => 'assets/Root/photo.jpg',
    ]))->withGraphContent($object);
    $asset->save();
    // A failed first index never wrote its sha, so the original's sha always differs from content_sha256.
    // Queueing the index already made the pending row.
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'failed', 'attempts' => 12, 'content_sha256' => null, 'created_at' => now(), 'updated_at' => now()]);

    Queue::fake();
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();
    Queue::assertNotPushed(AiIndexAsset::class);

    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['attempts' => 4]);
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();
    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $asset->id);

    Queue::fake();
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['attempts' => 12]);
    AiIndexDispatcher::contentReplaced($asset->id);
    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('attempts'))->toBe(0);

    // A fresh fake, so the push below can only come from reconcile and not from contentReplaced() itself; and the
    // replaced content's run has failed again by then (a run still queued is left alone, see the live-run test).
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'failed', 'run_token' => null]);
    Queue::fake();
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();
    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $asset->id);
});

it('queues rows that were never processed', function () {
    config(['ai-index.index_enabled' => true]);
    $pending = Asset::factory()->create();
    // Queueing the index already made the pending row.
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $pending->id], ['status' => 'pending', 'run_token' => null, 'created_at' => now(), 'updated_at' => now()]);

    Queue::fake();
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();

    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $pending->id);
});

it('bounds retries for a row stuck running the same way as a failed one', function () {
    config(['ai-index.index_enabled' => true]);
    $stuck = Asset::factory()->create();
    $exhausted = Asset::factory()->create();

    foreach ([[$stuck->id, 11], [$exhausted->id, 12]] as [$id, $attempts]) {
        // Queueing the index already made the pending row.
        DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $id], ['status' => 'running', 'attempts' => $attempts, 'caption' => 'x', 'run_token' => null, 'claimed_at' => now()->subMinutes(30), 'created_at' => now(), 'updated_at' => now()->subMinutes(30)]);
    }

    Queue::fake();
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();

    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $stuck->id);
    Queue::assertNotPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $exhausted->id);
});

function transientRows(): array
{
    config(['ai-index.index_enabled' => true, 'ai-index.api' => 'http://bridge.test', 'ai-index.health_check' => true]);
    Cache::flush();
    $rows = [];

    foreach ([
        'outage'    => ['status' => 'failed', 'error' => 'AiBridgeUnavailable', 'attempts' => 4],
        'sampler'   => ['status' => 'failed', 'error' => 'AiSamplerUnavailable', 'attempts' => 4],
        'lost'      => ['status' => 'running', 'error' => null, 'attempts' => 1, 'claimed_at' => now()->subMinutes(30)],
        'content'   => ['status' => 'failed', 'error' => 'RuntimeException', 'attempts' => 4],
        'exhausted' => ['status' => 'failed', 'error' => 'AiBridgeUnavailable', 'attempts' => 12],
        'done'      => ['status' => 'done', 'error' => null, 'attempts' => 0],
    ] as $name => $row) {
        $rows[$name] = Asset::factory()->create();
        DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $rows[$name]->id], $row + ['run_token' => null, 'created_at' => now(), 'updated_at' => now()->subMinutes(30)]);
    }

    return $rows;
}

it('re-queues hourly only the runs an outage or a stopped worker lost, while the AI service answers', function () {
    $rows = transientRows();
    Http::fake(['bridge.test/health' => Http::response(['ok' => true])]);

    Queue::fake();
    $this->artisan('dam:ai-index:reconcile --transient')->assertSuccessful();

    foreach (['outage', 'sampler', 'lost'] as $name) {
        Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $rows[$name]->id);
    }

    foreach (['content', 'exhausted', 'done'] as $name) {
        Queue::assertNotPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $rows[$name]->id);
    }

    Queue::assertNotPushed(AiReindexLexical::class);
});

it('re-queues nothing hourly while the AI service is down, so an outage spends no attempts', function () {
    transientRows();
    Http::fake(['bridge.test/health' => Http::response([], 503)]);

    Queue::fake();
    $this->artisan('dam:ai-index:reconcile --transient')->assertSuccessful();

    Queue::assertNothingPushed();
});

/** The records ai-index:reconcile wrote since resetReconcileLog(), decoded one per line. */
function reconcileLog(): array
{
    $path = storage_path('logs/ai-index-reconcile.jsonl');

    return is_file($path) ? array_map(fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
}

function resetReconcileLog(): void
{
    $path = storage_path('logs/ai-index-reconcile.jsonl');

    if (is_dir($path)) {
        rmdir($path);
    } elseif (is_file($path)) {
        unlink($path);
    }
}

it('writes a start and one terminal record with the actual dispatch counts and nothing sensitive', function () {
    resetReconcileLog();
    config(['ai-index.index_enabled' => true]);
    $pending = Asset::factory()->create();
    $captioned = Asset::factory()->create();
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $pending->id], ['status' => 'pending', 'run_token' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $captioned->id], ['status' => 'done', 'caption' => 'Confidential falcon caption', 'created_at' => now(), 'updated_at' => now()]);

    Queue::fake();
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();

    [$start, $result] = reconcileLog();
    expect(reconcileLog())->toHaveCount(2)
        ->and($start['event'])->toBe('ai_index_reconcile.start')
        ->and($result['event'])->toBe('ai_index_reconcile.result')
        ->and($result['run_id'])->toBe($start['run_id'])
        ->and($result['loc'])->toBe(AiIndexReconcile::class)
        ->and($result['ctx'])->toMatchArray(['outcome' => 'completed', 'exit_code' => 0, 'mode' => 'full', 'skip_reason' => null, 'full_dispatched' => 1, 'lexical_dispatched' => 1, 'transient_dispatched' => 0])
        ->and($result['ctx']['pid'])->toBeInt()
        ->and($result['ctx']['duration_ms'])->toBeInt()
        ->and(file_get_contents(storage_path('logs/ai-index-reconcile.jsonl')))->not->toContain('Confidential')->not->toContain($pending->file_name);
});

it('records the disabled early return and the unhealthy transient early return with their reasons and one health call', function () {
    resetReconcileLog();
    config(['ai-index.index_enabled' => false]);
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();

    expect(reconcileLog()[1]['ctx'])->toMatchArray(['outcome' => 'completed', 'exit_code' => 0, 'skip_reason' => 'index_disabled', 'full_dispatched' => 0, 'lexical_dispatched' => null]);

    resetReconcileLog();
    transientRows();
    Http::fake(['bridge.test/health' => Http::response([], 503)]);
    Queue::fake();
    $this->artisan('dam:ai-index:reconcile --transient')->assertSuccessful();

    Http::assertSentCount(1);
    expect(reconcileLog()[1]['ctx'])->toMatchArray(['mode' => 'transient', 'outcome' => 'completed', 'skip_reason' => 'ai_service_not_answering', 'transient_dispatched' => 0]);
});

it('counts only the transient runs it actually queued', function () {
    resetReconcileLog();
    transientRows();
    Http::fake(['bridge.test/health' => Http::response(['ok' => true])]);
    Queue::fake();
    $this->artisan('dam:ai-index:reconcile --transient')->assertSuccessful();

    expect(reconcileLog()[1]['ctx'])->toMatchArray(['mode' => 'transient', 'skip_reason' => null, 'transient_dispatched' => 3, 'full_dispatched' => 0]);
});

it('records a failed result with the exception class only and rethrows the same exception', function () {
    resetReconcileLog();
    transientRows();
    $boom = new RuntimeException('secret-token abc123 https://example.test/private/photo.jpg alice@example.test');
    $this->mock(AiBridgeClient::class, fn ($mock) => $mock->shouldReceive('health')->once()->andThrow($boom));

    $caught = null;

    try {
        Artisan::call('dam:ai-index:reconcile', ['--transient' => true]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBe($boom);
    [, $result] = reconcileLog();
    expect($result['level'])->toBe('error')
        ->and($result['ctx'])->toMatchArray(['outcome' => 'failed', 'exception_class' => RuntimeException::class, 'exit_code' => null])
        ->and(file_get_contents(storage_path('logs/ai-index-reconcile.jsonl')))->not->toContain('secret-token')->not->toContain('photo.jpg')->not->toContain('alice');
});

it('starts every invocation with a fresh run id and fresh counts, and an unwritable sink changes nothing', function () {
    resetReconcileLog();
    config(['ai-index.index_enabled' => true]);
    $pending = Asset::factory()->create();
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $pending->id], ['status' => 'pending', 'run_token' => null, 'created_at' => now(), 'updated_at' => now()]);

    Queue::fake();
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();
    // The first run stamped the row as queued, so the second finds nothing to re-queue: counts must not carry over.
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();

    $log = reconcileLog();
    expect($log)->toHaveCount(4)
        ->and($log[1]['ctx']['full_dispatched'])->toBe(1)
        ->and($log[3]['ctx']['full_dispatched'])->toBe(0)
        ->and($log[2]['run_id'])->not->toBe($log[0]['run_id']);

    // A sink that cannot be written (a directory sits at its path) neither fails nor changes the command.
    resetReconcileLog();
    mkdir(storage_path('logs/ai-index-reconcile.jsonl'));
    DB::table('dam_ai_index')->where('asset_id', $pending->id)->update(['status' => 'pending', 'run_token' => null]);
    Queue::fake();

    try {
        $this->artisan('dam:ai-index:reconcile')->assertSuccessful();
        Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $pending->id);
    } finally {
        resetReconcileLog();
    }
});

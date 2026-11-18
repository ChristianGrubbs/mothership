<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadBatch;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\MetadataExtractionService;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

function makeStoredAsset(string $name = 'floral.png'): Asset
{
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/'.Str::random(6).'-'.$name;

    Storage::disk($disk)->put($path, (string) UploadedFile::fake()->image($name, 40, 40)->get());

    return Asset::factory()->create([
        'file_name' => $name,
        'file_type' => 'image',
        'path'      => $path,
        'mime_type' => 'image/png',
        'extension' => 'png',
        'meta_data' => null,
    ]);
}

it('starts an upload session tracker', function () {
    $directory = Directory::factory()->create(['name' => 'New']);
    $uuid = (string) Str::uuid();

    $this->postJson(route('admin.dam.assets.upload.tracker'), [
        'session_uuid' => $uuid,
        'directory_id' => $directory->id,
        'total'        => 3,
    ])
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('tracker.state', UploadTracker::STATE_PROCESSING)
        ->assertJsonPath('tracker.total_files', 3);

    $this->assertDatabaseHas($this->getFullTableName(UploadTracker::class), [
        'uuid'        => $uuid,
        'total_files' => 3,
        'state'       => UploadTracker::STATE_PROCESSING,
    ]);
});

it('validates the start session request', function () {
    $this->postJson(route('admin.dam.assets.upload.tracker'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['session_uuid', 'directory_id']);
});

it('rejects malformed upload session UUIDs before creating trackers or assets', function (string $endpoint) {
    $directory = Directory::factory()->create();
    $trackerCount = UploadTracker::count();
    $assetCount = Asset::count();
    $payload = [
        'session_uuid' => 'not-a-uuid',
        'directory_id' => $directory->id,
        'files'        => [UploadedFile::fake()->image('malformed.png')],
    ];

    if ($endpoint === 'admin.dam.assets.upload_folder') {
        $payload['relative_paths'] = ['malformed.png'];
    }

    $this->postJson(route($endpoint), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['session_uuid']);

    expect(UploadTracker::count())->toBe($trackerCount);
    expect(Asset::count())->toBe($assetCount);
})->with(['admin.dam.assets.upload', 'admin.dam.assets.upload_folder']);

it('keeps session ownership across accounts including administrators', function () {
    Queue::fake();
    $owner = auth('admin')->user();
    $directory = Directory::factory()->create(['name' => 'Owner uploads']);
    $uuid = (string) Str::uuid();
    $payload = ['session_uuid' => $uuid, 'directory_id' => $directory->id, 'total' => 2];

    $this->postJson(route('admin.dam.assets.upload.tracker'), $payload)->assertCreated();
    $tracker = UploadTracker::where('uuid', $uuid)->firstOrFail();
    $original = $tracker->getAttributes();

    $this->loginAsAdmin();
    $this->postJson(route('admin.dam.assets.upload.tracker'), $payload)->assertNotFound();
    $this->getJson(route('admin.dam.assets.upload.stats', $uuid))->assertNotFound();
    foreach (['pause', 'resume', 'cancel', 'retry', 'complete'] as $action) {
        $this->postJson(route('admin.dam.assets.upload.'.$action, $uuid))->assertNotFound();
    }
    $assetCount = Asset::count();
    $this->postJson(route('admin.dam.assets.upload'), [
        'session_uuid' => $uuid,
        'directory_id' => $directory->id,
        'files'        => [UploadedFile::fake()->image('other-account.png')],
    ])->assertNotFound();
    expect(Asset::count())->toBe($assetCount);
    expect($tracker->fresh()->getAttributes())->toBe($original);
    expect($tracker->batches()->count())->toBe(0);

    $this->loginAsAdmin($owner);
    $this->getJson(route('admin.dam.assets.upload.stats', $uuid))->assertOk();
    $this->postJson(route('admin.dam.assets.upload.pause', $uuid))->assertOk();
    $this->postJson(route('admin.dam.assets.upload.tracker'), $payload)
        ->assertCreated()->assertJsonPath('tracker.state', UploadTracker::STATE_PROCESSING);
    expect(UploadTracker::where('uuid', $uuid)->count())->toBe(1);
    expect($tracker->fresh()->user_id)->toBe($owner->id);
    $this->postJson(route('admin.dam.assets.upload'), [
        'session_uuid' => $uuid,
        'directory_id' => $directory->id,
        'files'        => [UploadedFile::fake()->image('owner.png')],
    ])->assertCreated();
    expect($tracker->batches()->count())->toBe(1);
    $this->postJson(route('admin.dam.assets.upload.complete', $uuid))->assertOk();
    $this->postJson(route('admin.dam.assets.upload.cancel', $uuid))->assertOk();
});

it('rejects re-registration and attachment outside the bound directory', function () {
    $directory = Directory::factory()->create();
    $other = Directory::factory()->create();
    $uuid = (string) Str::uuid();
    $this->postJson(route('admin.dam.assets.upload.tracker'), [
        'session_uuid' => $uuid, 'directory_id' => $directory->id, 'total' => 1,
    ])->assertCreated();
    $this->postJson(route('admin.dam.assets.upload.tracker'), [
        'session_uuid' => $uuid, 'directory_id' => $other->id, 'total' => 9,
    ])->assertNotFound();
    $assetCount = Asset::count();
    $this->postJson(route('admin.dam.assets.upload'), [
        'session_uuid' => $uuid,
        'directory_id' => $other->id,
        'files'        => [UploadedFile::fake()->image('wrong-directory.png')],
    ])->assertNotFound();
    expect(Asset::count())->toBe($assetCount);
    $tracker = UploadTracker::where('uuid', $uuid)->firstOrFail();
    expect((int) $tracker->directory_id)->toBe($directory->id);
    expect($tracker->total_files)->toBe(1);
    expect($tracker->batches()->count())->toBe(0);
});

it('never adopts an ownerless legacy tracker', function () {
    $directory = Directory::factory()->create();
    $tracker = UploadTracker::create([
        'uuid'  => (string) Str::uuid(), 'directory_id' => $directory->id,
        'state' => UploadTracker::STATE_PROCESSING,
    ]);
    $this->postJson(route('admin.dam.assets.upload.tracker'), [
        'session_uuid' => $tracker->uuid, 'directory_id' => $directory->id,
    ])->assertNotFound();
    $this->getJson(route('admin.dam.assets.upload.stats', $tracker->uuid))->assertNotFound();
    foreach (['pause', 'resume', 'cancel', 'retry', 'complete'] as $action) {
        $this->postJson(route('admin.dam.assets.upload.'.$action, $tracker->uuid))->assertNotFound();
    }
    $this->postJson(route('admin.dam.assets.upload'), [
        'session_uuid' => $tracker->uuid,
        'directory_id' => $directory->id,
        'files'        => [UploadedFile::fake()->image('legacy.png')],
    ])->assertNotFound();
    expect($tracker->fresh()->user_id)->toBeNull();
    expect($tracker->fresh()->state)->toBe(UploadTracker::STATE_PROCESSING);
    expect($tracker->batches()->count())->toBe(0);
});

it('recovers duplicate UUID creation without changing the session binding', function (string $endpoint) {
    Queue::fake();
    $owner = auth('admin')->user();
    $directory = Directory::factory()->create();
    $uuid = (string) Str::uuid();
    $tracker = UploadTracker::create([
        'uuid'         => $uuid,
        'user_id'      => $owner->id,
        'directory_id' => $directory->id,
        'state'        => UploadTracker::STATE_PROCESSING,
        'total_files'  => 1,
        'started_at'   => now()->subMinute(),
    ]);
    $startedAt = $tracker->started_at;
    $payload = ['session_uuid' => $uuid, 'directory_id' => $directory->id, 'total' => 1];
    if ($endpoint === 'admin.dam.assets.upload') {
        $payload['files'] = [UploadedFile::fake()->image('duplicate-uuid.png')];
    } elseif ($endpoint === 'admin.dam.assets.upload_folder') {
        $payload['files'] = [UploadedFile::fake()->image('duplicate-uuid.png')];
        $payload['relative_paths'] = ['Nested/duplicate-uuid.png'];
    }

    // The UUID already exists: firstOrCreate reuses its existing binding.
    $this->postJson(route($endpoint), $payload)->assertCreated();
    expect(UploadTracker::where('uuid', $uuid)->count())->toBe(1);
    expect($tracker->fresh()->started_at->equalTo($startedAt))->toBeTrue();
    expect((int) $tracker->fresh()->user_id)->toBe($owner->id);
    expect((int) $tracker->fresh()->directory_id)->toBe($directory->id);
    expect($tracker->batches()->count())->toBe(in_array($endpoint, ['admin.dam.assets.upload', 'admin.dam.assets.upload_folder'], true) ? 1 : 0);

    $this->loginAsAdmin();
    $this->postJson(route($endpoint), $payload)->assertNotFound();
    expect(UploadTracker::where('uuid', $uuid)->count())->toBe(1);
    expect((int) $tracker->fresh()->user_id)->toBe($owner->id);
})->with(['admin.dam.assets.upload.tracker', 'admin.dam.assets.upload', 'admin.dam.assets.upload_folder']);

it('recovers when a concurrent request creates the same UUID between lookup and insert', function (string $endpoint, string $racer) {
    Queue::fake();
    $owner = auth('admin')->user();
    $directory = Directory::factory()->create();
    $uuid = (string) Str::uuid();
    $payload = ['session_uuid' => $uuid, 'directory_id' => $directory->id, 'total' => 1];
    if ($endpoint !== 'admin.dam.assets.upload.tracker') {
        $payload['files'] = [UploadedFile::fake()->image('race.png')];
    }
    if ($endpoint === 'admin.dam.assets.upload_folder') {
        $payload['relative_paths'] = ['Nested/race.png'];
    }
    if ($racer === 'another admin') {
        $this->loginAsAdmin();
    }

    // The other request commits its tracker after this request's lookup missed and before its insert. It lands outside
    // createOrFirst's savepoint, so only the unique-violation fallback can recover it; a plain read-then-create throws.
    $armed = true;
    $competitor = null;
    DB::listen(function ($query) use (&$armed, &$competitor, $uuid, $owner, $directory): void {
        if (! $armed || ! str_contains($query->sql, 'upload_trackers') || stripos(ltrim($query->sql), 'select') !== 0) {
            return;
        }
        $armed = false;
        $competitor = UploadTracker::create([
            'uuid'         => $uuid,
            'user_id'      => $owner->id,
            'directory_id' => $directory->id,
            'state'        => UploadTracker::STATE_PROCESSING,
            'total_files'  => 1,
            'started_at'   => now()->subMinute(),
        ]);
    });
    $assetCount = Asset::count();

    $response = $this->postJson(route($endpoint), $payload);

    expect($competitor)->not->toBeNull();
    expect(UploadTracker::where('uuid', $uuid)->count())->toBe(1);
    expect((int) $competitor->fresh()->user_id)->toBe($owner->id);
    if ($racer === 'same admin') {
        $response->assertCreated();
        expect($competitor->fresh()->started_at->equalTo($competitor->started_at))->toBeTrue();
        expect($competitor->batches()->count())->toBe($endpoint === 'admin.dam.assets.upload.tracker' ? 0 : 1);
    } else {
        $response->assertNotFound();
        expect(Asset::count())->toBe($assetCount);
        expect($competitor->batches()->count())->toBe(0);
    }
})->with(['admin.dam.assets.upload.tracker', 'admin.dam.assets.upload', 'admin.dam.assets.upload_folder'])
    ->with(['same admin', 'another admin']);

it('rejects folder uploads before creating directories assets or batches', function (string $binding) {
    Queue::fake();
    $directory = Directory::factory()->create();
    $tracker = UploadTracker::create([
        'uuid'         => (string) Str::uuid(),
        'user_id'      => $binding === 'ownerless' ? null : auth('admin')->id(),
        'directory_id' => $directory->id,
        'state'        => UploadTracker::STATE_PROCESSING,
        'total_files'  => 1,
    ]);
    if ($binding === 'foreign') {
        $this->loginAsAdmin();
    } elseif ($binding === 'directory mismatch') {
        $directory = Directory::factory()->create();
    }
    $original = $tracker->refresh()->getAttributes();
    $assetCount = Asset::count();
    $directoryCount = Directory::count();
    $batchCount = UploadBatch::count();

    $this->postJson(route('admin.dam.assets.upload_folder'), [
        'session_uuid'   => $tracker->uuid,
        'directory_id'   => $directory->id,
        'files'          => [UploadedFile::fake()->image('nested.png')],
        'relative_paths' => ['New folder/Nested/nested.png'],
        'preserve_root'  => true,
    ])->assertNotFound();

    expect(Asset::count())->toBe($assetCount);
    expect(Directory::count())->toBe($directoryCount);
    expect(UploadBatch::count())->toBe($batchCount);
    expect($tracker->fresh()->getAttributes())->toBe($original);
    Queue::assertNothingPushed();
})->with(['foreign', 'ownerless', 'directory mismatch']);

it('attaches an upload to a session and finalises it in the background', function () {
    $disk = Directory::getAssetDisk();
    Storage::disk($disk)->makeDirectory('assets/New');
    $directory = Directory::factory()->create(['name' => 'New']);
    $uuid = (string) Str::uuid();

    $this->postJson(route('admin.dam.assets.upload.tracker'), [
        'session_uuid' => $uuid,
        'directory_id' => $directory->id,
        'total'        => 1,
    ])->assertStatus(201);

    $file = UploadedFile::fake()->image('graph-'.uniqid().'.png', 300, 300)->size(20);

    $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [$file],
        'directory_id' => $directory->id,
        'session_uuid' => $uuid,
    ])->assertStatus(201)->assertJsonPath('success', true);

    $tracker = UploadTracker::where('uuid', $uuid)->first();
    expect($tracker->batches()->count())->toBe(1);
    expect($tracker->batches()->first()->state)->toBe(UploadBatch::STATE_PROCESSED);
    expect($tracker->fresh()->processed_files)->toBe(1);
});

it('uploads without a session create no tracker batches', function () {
    Queue::fake();

    $disk = Directory::getAssetDisk();
    Storage::disk($disk)->makeDirectory('assets/New');
    $directory = Directory::factory()->create(['name' => 'New']);

    $file = UploadedFile::fake()->image('plain-'.uniqid().'.png', 200, 200)->size(15);

    $response = $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [$file],
        'directory_id' => $directory->id,
    ])->assertStatus(201);

    $assetId = $response->json('files.0.id');
    expect(UploadBatch::where('asset_id', $assetId)->exists())->toBeFalse();
    Queue::assertPushed(ProcessAssetUpload::class);
});

it('finalises the asset and completes the tracker when all batches settle', function () {
    $asset = makeStoredAsset();
    $tracker = UploadTracker::create([
        'uuid'        => (string) Str::uuid(),
        'user_id'     => auth()->id(),
        'state'       => UploadTracker::STATE_PROCESSING,
        'total_files' => 1,
    ]);
    $batch = UploadBatch::create([
        'upload_tracker_id' => $tracker->id,
        'asset_id'          => $asset->id,
        'state'             => UploadBatch::STATE_PENDING,
    ]);

    ProcessAssetUpload::dispatch($asset->id, $batch->id);

    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PROCESSED);
    expect($tracker->fresh()->processed_files)->toBe(1);
    expect($tracker->fresh()->state)->toBe(UploadTracker::STATE_COMPLETED);
});

it('reuses one durable batch for trackerless retries and recovers an expired processing claim', function () {
    $queue = Queue::fake();
    $asset = makeStoredAsset();
    $key = hash('sha256', 'creative-hub:'.(int) auth()->id().':capture-one');
    $publisher = app(AssetUploadPublisher::class);

    $publisher->ensureFinalizationQueued(asset: $asset, tracker: null, finalizationKey: $key);
    $publisher->ensureFinalizationQueued(asset: $asset, tracker: null, finalizationKey: $key);

    $batch = UploadBatch::query()->where('finalization_key', $key)->sole();
    expect($batch->upload_tracker_id)->toBeNull()
        ->and((int) $batch->asset_id)->toBe((int) $asset->id)
        ->and(UploadBatch::query()->where('finalization_key', $key)->count())->toBe(1);
    Queue::assertPushed(ProcessAssetUpload::class, 2);

    DB::table('dam_upload_batches')->where('id', $batch->id)->update(['state' => UploadBatch::STATE_PROCESSING, 'updated_at' => now()]);
    $busy = $queue->pushed(ProcessAssetUpload::class)->first()->withFakeQueueInteractions();
    $busy->handle(app(MetadataExtractionService::class));
    $busy->assertReleased(360);
    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PROCESSING);

    DB::table('dam_upload_batches')->where('id', $batch->id)->update(['updated_at' => now()->subSeconds(361)]);
    $recovered = $queue->pushed(ProcessAssetUpload::class)->last();
    $recovered->handle(app(MetadataExtractionService::class));
    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PROCESSED);

    $busy->handle(app(MetadataExtractionService::class));
    $publisher->ensureFinalizationQueued(asset: $asset, tracker: null, finalizationKey: $key);
    Queue::assertPushed(ProcessAssetUpload::class, 2);
    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PROCESSED);
});

it('settles a repeatedly abandoned processing claim once and lets an explicit keyed retry start fresh', function () {
    Queue::fake();
    $asset = makeStoredAsset();
    $tracker = UploadTracker::create([
        'uuid'        => (string) Str::uuid(),
        'state'       => UploadTracker::STATE_PROCESSING,
        'total_files' => 1,
    ]);
    $batch = UploadBatch::create([
        'upload_tracker_id' => $tracker->id,
        'asset_id'          => $asset->id,
        'state'             => UploadBatch::STATE_PENDING,
        'recovery_attempts' => 3,
        'last_recovered_at' => now()->subHour(),
    ]);

    // A killed worker cannot be reproduced in-process; claim each lease without entering handle().
    $job = new class($asset->id, $batch->id) extends ProcessAssetUpload
    {
        public function claim(UploadBatch $batch): bool
        {
            return $this->claimBatch($batch);
        }
    };
    $job->withFakeQueueInteractions();

    expect($job->claim($batch))->toBeTrue()
        ->and($batch->fresh()->claim_attempts)->toBe(1)
        ->and($batch->fresh()->recovery_attempts)->toBe(0)
        ->and($batch->fresh()->last_recovered_at)->toBeNull();
    $job->claim($batch);
    $job->assertReleased(360);

    foreach ([2, 3] as $attempt) {
        DB::table('dam_upload_batches')->where('id', $batch->id)->update(['updated_at' => now()->subSeconds(361)]);
        expect($job->claim($batch))->toBeTrue()
            ->and($batch->fresh()->claim_attempts)->toBe($attempt);
    }

    DB::table('dam_upload_batches')->where('id', $batch->id)->update(['updated_at' => now()->subSeconds(361)]);
    expect($job->claim($batch))->toBeFalse();
    expect($job->claim($batch))->toBeFalse();

    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_FAILED)
        ->and($batch->fresh()->claim_attempts)->toBe(3)
        ->and($tracker->fresh()->failed_files)->toBe(1)
        ->and($tracker->fresh()->state)->toBe(UploadTracker::STATE_COMPLETED)
        ->and($asset->fresh()->meta_data)->toBeNull();

    $key = hash('sha256', 'creative-hub:'.(int) auth()->id().':retry-after-failure');
    $directBatch = UploadBatch::create([
        'finalization_key'  => $key,
        'asset_id'          => $asset->id,
        'state'             => UploadBatch::STATE_FAILED,
        'claim_attempts'    => 3,
        'recovery_attempts' => 3,
        'last_recovered_at' => now()->subHour(),
        'error'             => 'Processing stopped after repeated worker failures.',
    ]);
    app(AssetUploadPublisher::class)->ensureFinalizationQueued(asset: $asset, tracker: null, finalizationKey: $key);

    expect($directBatch->fresh()->state)->toBe(UploadBatch::STATE_PENDING)
        ->and($directBatch->fresh()->error)->toBeNull()
        ->and($directBatch->fresh()->recovery_attempts)->toBe(0)
        ->and($directBatch->fresh()->last_recovered_at)->toBeNull();
    Queue::assertPushed(ProcessAssetUpload::class, 1);
});

it('keeps the worker retry limit for legacy jobs and durable claims for batch jobs', function () {
    $queue = app('queue')->connection('sync');
    $legacy = new ProcessAssetUpload(assetId: 1);
    $batched = new ProcessAssetUpload(assetId: 1, batchId: 1);
    $createPayload = new ReflectionMethod($queue, 'createObjectPayload');
    $legacyPayload = $createPayload->invoke($queue, $legacy, null);
    $batchPayload = $createPayload->invoke($queue, $batched, null);

    expect($queue->getJobTries($legacy))->toBeNull()
        ->and($legacyPayload['maxExceptions'])->toBe(3)
        ->and($queue->getJobBackoff($legacy))->toBeNull()
        ->and($queue->getJobTries($batched))->toBe(0)
        ->and($batchPayload['maxExceptions'])->toBe(3)
        ->and($queue->getJobBackoff($batched))->toBe('30,120,360');
});

it('settles a pre-claim exception once when Laravel exhausts the batch exception budget', function () {
    $asset = makeStoredAsset();
    $tracker = UploadTracker::create([
        'uuid'        => (string) Str::uuid(),
        'state'       => UploadTracker::STATE_PROCESSING,
        'total_files' => 1,
    ]);
    $batch = UploadBatch::create([
        'upload_tracker_id' => $tracker->id,
        'asset_id'          => $asset->id,
        'state'             => UploadBatch::STATE_PENDING,
    ]);
    $job = new ProcessAssetUpload(assetId: $asset->id, batchId: $batch->id);

    $job->failed(new RuntimeException('pre-claim database error'));
    $job->failed(new RuntimeException('duplicate failure callback'));

    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_FAILED)
        ->and($tracker->fresh()->failed_files)->toBe(1)
        ->and($tracker->fresh()->state)->toBe(UploadTracker::STATE_COMPLETED);
});

it('throttles recovery pushes without failing an unclaimed batch behind a queue backlog', function () {
    Queue::fake();
    $asset = makeStoredAsset();
    $tracker = UploadTracker::create([
        'uuid'        => (string) Str::uuid(),
        'state'       => UploadTracker::STATE_PROCESSING,
        'total_files' => 1,
    ]);
    $batch = UploadBatch::create([
        'upload_tracker_id' => $tracker->id,
        'asset_id'          => $asset->id,
        'state'             => UploadBatch::STATE_PENDING,
    ]);
    DB::table('dam_upload_batches')->where('id', $batch->id)->update(['updated_at' => now()->subSeconds(361)]);

    foreach ([1, 2, 3, 4] as $attempt) {
        Artisan::call('dam:uploads:reconcile');
        expect($batch->fresh()->recovery_attempts)->toBe($attempt)
            ->and($batch->fresh()->state)->toBe(UploadBatch::STATE_PENDING);
        Queue::assertPushed(ProcessAssetUpload::class, $attempt);
        Artisan::call('dam:uploads:reconcile');
        Queue::assertPushed(ProcessAssetUpload::class, $attempt);
        DB::table('dam_upload_batches')->where('id', $batch->id)->update(['last_recovered_at' => now()->subSeconds(1801)]);
    }

    $queuedJob = Queue::pushed(ProcessAssetUpload::class)->first();
    $queuedJob->handle(app(MetadataExtractionService::class));

    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PROCESSED)
        ->and($tracker->fresh()->failed_files)->toBe(0)
        ->and($tracker->fresh()->processed_files)->toBe(1);
});

it('skips paused and fresh claims and recovers a trackerless batch after a queue push error', function () {
    $queue = Queue::fake();
    $asset = makeStoredAsset();
    $paused = UploadTracker::create(['uuid' => (string) Str::uuid(), 'state' => UploadTracker::STATE_PAUSED, 'total_files' => 1]);
    $pausedBatch = UploadBatch::create(['upload_tracker_id' => $paused->id, 'asset_id' => $asset->id, 'state' => UploadBatch::STATE_PENDING]);
    $freshBatch = UploadBatch::create(['asset_id' => $asset->id, 'state' => UploadBatch::STATE_PROCESSING, 'claim_attempts' => 1]);
    $directBatch = UploadBatch::create([
        'finalization_key' => hash('sha256', 'creative-hub:recovery-filter'),
        'asset_id'         => $asset->id,
        'state'            => UploadBatch::STATE_PENDING,
    ]);
    DB::table('dam_upload_batches')->whereIn('id', [$pausedBatch->id, $directBatch->id])
        ->update(['updated_at' => now()->subSeconds(361)]);
    $queue->beforePushing(fn ($job) => $job instanceof ProcessAssetUpload ? throw new RuntimeException('queue unavailable') : null);

    expect(Artisan::call('dam:uploads:reconcile'))->toBe(1)
        ->and($pausedBatch->fresh()->recovery_attempts)->toBe(0)
        ->and($freshBatch->fresh()->recovery_attempts)->toBe(0)
        ->and($directBatch->fresh()->recovery_attempts)->toBe(1)
        ->and($directBatch->fresh()->state)->toBe(UploadBatch::STATE_PENDING);

    Queue::fake();
    DB::table('dam_upload_batches')->where('id', $directBatch->id)->update(['last_recovered_at' => now()->subSeconds(1801)]);
    expect(Artisan::call('dam:uploads:reconcile'))->toBe(0);
    Queue::assertPushed(ProcessAssetUpload::class, 1);
});

it('does not let a failed callback settle a fresh claim owned by a recovered job', function () {
    $queue = Queue::fake();
    $asset = makeStoredAsset();
    $tracker = UploadTracker::create([
        'uuid'        => (string) Str::uuid(),
        'state'       => UploadTracker::STATE_PROCESSING,
        'total_files' => 1,
    ]);
    $batch = UploadBatch::create([
        'upload_tracker_id' => $tracker->id,
        'asset_id'          => $asset->id,
        'state'             => UploadBatch::STATE_PROCESSING,
        'claim_attempts'    => 1,
    ]);
    $failedDelivery = new ProcessAssetUpload(assetId: $asset->id, batchId: $batch->id);

    $failedDelivery->failed(new RuntimeException('another delivery failed'));
    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PROCESSING);

    DB::table('dam_upload_batches')->where('id', $batch->id)->update(['updated_at' => now()->subSeconds(361)]);
    Artisan::call('dam:uploads:reconcile');
    $recovered = $queue->pushed(ProcessAssetUpload::class)->sole();
    $recovered->handle(app(MetadataExtractionService::class));
    $failedDelivery->failed(new RuntimeException('late duplicate failure callback'));

    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PROCESSED)
        ->and($tracker->fresh()->processed_files)->toBe(1)
        ->and($tracker->fresh()->failed_files)->toBe(0);
});

it('a paused tracker makes the job abort and leaves the batch pending', function () {
    $asset = makeStoredAsset();
    $tracker = UploadTracker::create([
        'uuid'        => (string) Str::uuid(),
        'state'       => UploadTracker::STATE_PAUSED,
        'total_files' => 1,
    ]);
    $batch = UploadBatch::create([
        'upload_tracker_id' => $tracker->id,
        'asset_id'          => $asset->id,
        'state'             => UploadBatch::STATE_PENDING,
    ]);

    ProcessAssetUpload::dispatch($asset->id, $batch->id);

    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PENDING);
    expect($tracker->fresh()->processed_files)->toBe(0);
});

it('pauses and resumes a session', function () {
    $tracker = UploadTracker::create([
        'uuid'    => (string) Str::uuid(),
        'user_id' => auth()->id(),
        'state'   => UploadTracker::STATE_PROCESSING,
    ]);

    $this->postJson(route('admin.dam.assets.upload.pause', $tracker->uuid))
        ->assertOk()
        ->assertJsonPath('tracker.state', UploadTracker::STATE_PAUSED);

    expect($tracker->fresh()->state)->toBe(UploadTracker::STATE_PAUSED);

    $this->postJson(route('admin.dam.assets.upload.resume', $tracker->uuid))
        ->assertOk()
        ->assertJsonPath('tracker.state', UploadTracker::STATE_PROCESSING);

    expect($tracker->fresh()->state)->toBe(UploadTracker::STATE_PROCESSING);
});

it('resumes a pending batch immediately after prior scheduled recovery pushes', function () {
    Queue::fake();
    $asset = makeStoredAsset();
    $tracker = UploadTracker::create([
        'uuid'        => (string) Str::uuid(),
        'user_id'     => auth()->id(),
        'state'       => UploadTracker::STATE_PAUSED,
        'total_files' => 1,
    ]);
    $batch = UploadBatch::create([
        'upload_tracker_id' => $tracker->id,
        'asset_id'          => $asset->id,
        'state'             => UploadBatch::STATE_PENDING,
        'recovery_attempts' => 3,
        'last_recovered_at' => now()->subHour(),
    ]);
    DB::table('dam_upload_batches')->where('id', $batch->id)->update(['updated_at' => now()->subHour()]);

    $this->postJson(route('admin.dam.assets.upload.resume', $tracker->uuid))->assertOk();

    expect($batch->fresh()->recovery_attempts)->toBe(0)
        ->and($batch->fresh()->last_recovered_at)->toBeNull()
        ->and($batch->fresh()->updated_at->greaterThan(now()->subMinute()))->toBeTrue();
    Queue::assertPushed(ProcessAssetUpload::class, 1);
    Artisan::call('dam:uploads:reconcile');
    Queue::assertPushed(ProcessAssetUpload::class, 1);
    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PENDING);
});

it('cancels a session and abandons pending batches', function () {
    $tracker = UploadTracker::create([
        'uuid'    => (string) Str::uuid(),
        'user_id' => auth()->id(),
        'state'   => UploadTracker::STATE_PROCESSING,
    ]);
    $batch = UploadBatch::create([
        'upload_tracker_id' => $tracker->id,
        'asset_id'          => makeStoredAsset()->id,
        'state'             => UploadBatch::STATE_PENDING,
    ]);

    $this->postJson(route('admin.dam.assets.upload.cancel', $tracker->uuid))
        ->assertOk()
        ->assertJsonPath('tracker.state', UploadTracker::STATE_CANCELLED);

    expect($tracker->fresh()->state)->toBe(UploadTracker::STATE_CANCELLED);
    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_CANCELLED);
});

it('retries only the failed batches', function () {
    $asset = makeStoredAsset();
    $tracker = UploadTracker::create([
        'uuid'         => (string) Str::uuid(),
        'user_id'      => auth()->id(),
        'state'        => UploadTracker::STATE_PROCESSING,
        'total_files'  => 1,
        'failed_files' => 1,
    ]);
    $batch = UploadBatch::create([
        'upload_tracker_id' => $tracker->id,
        'asset_id'          => $asset->id,
        'state'             => UploadBatch::STATE_FAILED,
        'recovery_attempts' => 3,
        'last_recovered_at' => now()->subHour(),
        'error'             => 'boom',
    ]);

    $this->postJson(route('admin.dam.assets.upload.retry', $tracker->uuid))
        ->assertOk()
        ->assertJsonPath('retried', 1);

    expect($batch->fresh()->state)->toBe(UploadBatch::STATE_PROCESSED);
    expect($batch->fresh()->recovery_attempts)->toBe(0)
        ->and($batch->fresh()->last_recovered_at)->toBeNull();
    expect($tracker->fresh()->failed_files)->toBe(0);
    expect($tracker->fresh()->processed_files)->toBe(1);
});

it('reports stats and 404s for an unknown session', function () {
    $tracker = UploadTracker::create([
        'uuid'    => (string) Str::uuid(),
        'user_id' => auth()->id(),
        'state'   => UploadTracker::STATE_PROCESSING,
    ]);

    $this->getJson(route('admin.dam.assets.upload.stats', $tracker->uuid))
        ->assertOk()
        ->assertJsonPath('tracker.uuid', $tracker->uuid);

    $this->getJson(route('admin.dam.assets.upload.stats', (string) Str::uuid()))
        ->assertStatus(404);
});

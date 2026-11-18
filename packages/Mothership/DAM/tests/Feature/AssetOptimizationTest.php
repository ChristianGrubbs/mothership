<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\OptimizeAsset;
use Mothership\DAM\Jobs\VectorizeImage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Optimization\Optimizer;
use Mothership\DAM\Optimization\Requests;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\LocalFile;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    Storage::fake('private');
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $this->person = Admin::factory()->create();
    $this->actingAs($this->person, 'admin');
    config(['optimization.api' => 'http://clop.test']);
    Storage::disk(Directory::getAssetDisk())->put('assets/source.png', 'original bytes');
    $this->source = Asset::factory()->create([
        'file_name' => 'source.png', 'path' => 'assets/source.png', 'extension' => 'png', 'file_type' => 'image',
    ]);
});

function optimizationOriginalObject($asset)
{
    return $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail()->fileObject;
}

it('denies an inaccessible source before queuing an optimization', function () {
    $permissions = Mockery::mock(DirectoryPermissionService::class);
    $permissions->shouldReceive('bypass')->andReturn(false);
    $permissions->shouldReceive('canAccess')->andReturn(false);
    app()->instance(DirectoryPermissionService::class, $permissions);
    $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertNotFound();
    Queue::assertNothingPushed();
});

it('404s a missing asset', function () {
    $this->postJson(route('admin.dam.assets.optimization.store', 999999), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertNotFound();
});

it('rejects an unsupported extension without queuing work', function () {
    $this->source->update(['extension' => 'gif']);
    $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertUnprocessable();
    Queue::assertNothingPushed();
});

it('rejects an invalid option value', function () {
    $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '0.9', 'adaptive' => '0',
    ])->assertUnprocessable();
    Queue::assertNothingPushed();
});

it('binds a request to the asset, type and options, replaying an identical retry and rejecting a mismatch', function () {
    $request = ['request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0'];
    $url = route('admin.dam.assets.optimization.store', $this->source->id);
    $one = $this->postJson($url, $request)->assertAccepted()->json('result.id');
    $this->postJson($url, $request)->assertAccepted()->assertJsonPath('result.id', $one);
    $this->postJson($url, array_replace($request, ['scale' => '0.5']))->assertConflict();
    Queue::assertPushed(OptimizeAsset::class, 1);
});

it('attaches an optimized copy exactly once when the job runs twice, leaving original bytes untouched', function () {
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('optimize')->once()->andReturnUsing(function () {
        $dir = sys_get_temp_dir().'/optimize-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/out.jpg', 'smaller bytes');

        return ['outcome' => 'optimized', 'path' => $dir.'/out.jpg', 'extension' => 'jpg', 'old_bytes' => 14, 'new_bytes' => 5, 'width' => 20, 'height' => 20];
    });
    app()->instance(Optimizer::class, $client);
    (new OptimizeAsset(id: $id))->handle();
    (new OptimizeAsset(id: $id))->handle();

    $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk()
        ->assertJsonPath('result.status', 'completed')->assertJsonPath('result.outcome', 'optimized');
    $files = $this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->with('fileObject')->get();
    expect($files)->toHaveCount(1)
        ->and($this->source->fresh()->path)->toBe('assets/source.png');
    Storage::disk($files->first()->fileObject->disk)->assertExists($files->first()->fileObject->path);
    expect(Storage::disk(Directory::getAssetDisk())->get('assets/source.png'))->toBe('original bytes');
});

it('attaches nothing and reports no_reduction when the copy is not smaller', function () {
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('optimize')->once()->andReturn(['outcome' => 'no_reduction', 'old_bytes' => 14]);
    app()->instance(Optimizer::class, $client);
    (new OptimizeAsset(id: $id))->handle();

    $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk()
        ->assertJsonPath('result.status', 'completed')->assertJsonPath('result.outcome', 'no_reduction');
    expect($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0);
});

it('expires without attaching when the original changed after submission', function () {
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    DB::table('dam_asset_files')->where('dam_asset_id', $this->source->id)->where('role', 'original')->delete();
    optimizationOriginalObjectFromBytes($this->source, 'a different set of bytes entirely');

    (new OptimizeAsset(id: $id))->handle();

    expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('expired')
        ->and($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0);
});

it('expires without attaching when the requesting admin loses access before settlement', function () {
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('optimize')->once()->andReturnUsing(function () {
        $dir = sys_get_temp_dir().'/optimize-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/out.jpg', 'smaller bytes');

        return ['outcome' => 'optimized', 'path' => $dir.'/out.jpg', 'extension' => 'jpg', 'old_bytes' => 14, 'new_bytes' => 5, 'width' => null, 'height' => null];
    });
    app()->instance(Optimizer::class, $client);
    $this->person->update(['status' => 0]);

    (new OptimizeAsset(id: $id))->handle();

    expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('expired')
        ->and($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0);
});

it('expires without attaching when the original changes while the job is talking to the optimizer', function () {
    // Distinct from "changed after submission" above: the job's own early sha check (before it ever
    // calls the client) still passes here — the change happens mid-flight, so only AssetOutputs::publish()'s
    // own recheck at settlement time can catch it (round-1 review finding, 2026-09-26).
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    $source = $this->source;
    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('optimize')->once()->andReturnUsing(function () use ($source) {
        DB::table('dam_asset_files')->where('dam_asset_id', $source->id)->where('role', 'original')->delete();
        optimizationOriginalObjectFromBytes($source, 'replaced while the optimizer was running');
        $dir = sys_get_temp_dir().'/optimize-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/out.jpg', 'smaller bytes');

        return ['outcome' => 'optimized', 'path' => $dir.'/out.jpg', 'extension' => 'jpg', 'old_bytes' => 14, 'new_bytes' => 5, 'width' => null, 'height' => null];
    });
    app()->instance(Optimizer::class, $client);

    (new OptimizeAsset(id: $id))->handle();

    expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('expired')
        ->and($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0);
});

it('expires without attaching when the admin loses real directory access before settlement', function () {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.files'],
    ]);
    $limited = Admin::factory()->create(['role_id' => $role->id]);
    $folder = Directory::create(['name' => 'Optimize access folder', 'parent_id' => null]);
    DB::table('dam_asset_directory')->insert(['asset_id' => $this->source->id, 'directory_id' => $folder->id]);
    $this->putJson('/admin/dam/directory/'.$folder->id.'/access', [
        'users' => [['id' => $limited->id, 'inherit_children' => false]], 'groups' => [],
    ])->assertOk();

    $this->actingAs($limited, 'admin');
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');

    // Revoke the grant this admin submitted under — a real access-list change, not a disabled account.
    $this->actingAs($this->person, 'admin');
    $this->putJson('/admin/dam/directory/'.$folder->id.'/access', ['users' => [], 'groups' => []])->assertOk();

    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('optimize')->once()->andReturnUsing(function () {
        $dir = sys_get_temp_dir().'/optimize-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/out.jpg', 'smaller bytes');

        return ['outcome' => 'optimized', 'path' => $dir.'/out.jpg', 'extension' => 'jpg', 'old_bytes' => 14, 'new_bytes' => 5, 'width' => null, 'height' => null];
    });
    app()->instance(Optimizer::class, $client);

    (new OptimizeAsset(id: $id))->handle();

    expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('expired')
        ->and($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0);
});

it('does not attach a late finish after the row already expired', function () {
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    DB::table('dam_optimizations')->where('id', $id)->update(['status' => 'processing']);
    app(Requests::class)->finish(id: $id, success: false, error: 'source_changed');
    expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('expired');

    $dir = sys_get_temp_dir().'/optimize-test-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/out.jpg', 'smaller bytes');
    app(Requests::class)->finish(
        id: $id, success: true, outcome: 'optimized', oldBytes: 14, newBytes: 5, outputExtension: 'jpg', outputPath: $dir.'/out.jpg',
    );

    expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('expired')
        ->and($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0);
});

it('does not let a stale expiry sweep change an already-completed row', function () {
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    DB::table('dam_optimizations')->where('id', $id)->update(['status' => 'processing']);
    app(Requests::class)->finish(id: $id, success: true, outcome: 'no_reduction', oldBytes: 14);
    expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('completed');

    // A late/duplicate expiry sweep must never downgrade a row that already settled successfully.
    app(Requests::class)->finish(id: $id, success: false, error: 'expired');

    expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('completed')
        ->and(DB::table('dam_optimizations')->where('id', $id)->value('outcome'))->toBe('no_reduction');
});

it('reloads the winner and replays or conflicts when two submits race the same request id', function () {
    $requestId = (string) Str::uuid();
    $injected = false;
    $sha = hash('sha256', 'original bytes');

    $listener = function ($query) use (&$injected, $requestId, $sha) {
        if ($injected || ! str_contains(strtolower($query->sql), 'select * from') || ! str_contains(strtolower($query->sql), 'dam_optimizations')) {
            return;
        }

        if (! in_array($requestId, $query->bindings, true)) {
            return;
        }

        $injected = true;
        // Simulate a concurrent request that landed first, with different options than ours below.
        DB::table('dam_optimizations')->insert([
            'id'            => (string) Str::uuid(), 'asset_id' => $this->source->id, 'admin_id' => $this->person->id,
            'request_id'    => $requestId, 'type' => 'image', 'options' => json_encode(['adaptive' => '1', 'scale' => '1']),
            'source_sha256' => $sha, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    DB::listen($listener);

    $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => $requestId, 'scale' => '0.5', 'adaptive' => '0',
    ])->assertConflict();

    expect($injected)->toBeTrue('the query listener never observed the read it needed to race against')
        ->and(DB::table('dam_optimizations')->where('request_id', $requestId)->count())->toBe(1);
});

it('replays the winner of a submit race when the payload actually matches', function () {
    $requestId = (string) Str::uuid();
    $injected = false;
    $sha = hash('sha256', 'original bytes');
    $winnerId = (string) Str::uuid();

    $listener = function ($query) use (&$injected, $requestId, $sha, $winnerId) {
        if ($injected || ! str_contains(strtolower($query->sql), 'select * from') || ! str_contains(strtolower($query->sql), 'dam_optimizations')) {
            return;
        }

        if (! in_array($requestId, $query->bindings, true)) {
            return;
        }

        $injected = true;
        DB::table('dam_optimizations')->insert([
            'id'            => $winnerId, 'asset_id' => $this->source->id, 'admin_id' => $this->person->id,
            'request_id'    => $requestId, 'type' => 'image', 'options' => json_encode(['adaptive' => '0', 'scale' => '0.5']),
            'source_sha256' => $sha, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    DB::listen($listener);

    $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => $requestId, 'scale' => '0.5', 'adaptive' => '0',
    ])->assertAccepted()->assertJsonPath('result.id', $winnerId);

    expect($injected)->toBeTrue();
});

it('keeps the vectorization queue connection retry window above every job timeout that shares it', function () {
    $retryAfter = (int) config('queue.connections.vectorization.retry_after');
    expect($retryAfter)->toBeGreaterThan((new OptimizeAsset(id: 'unused'))->timeout)
        ->and($retryAfter)->toBeGreaterThan((new VectorizeImage(id: 'unused'))->timeout);
});

it('fails the job without attaching when the client throws', function () {
    $id = $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('optimize')->once()->andThrow(new RuntimeException('Optimization provider unavailable.'));
    app()->instance(Optimizer::class, $client);

    (new OptimizeAsset(id: $id))->handle();

    expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('failed')
        ->and($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0);
});

function optimizationSubmit($test): string
{
    return $test->postJson(route('admin.dam.assets.optimization.store', $test->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
}

/** Bytes of the asset's original and of each stored derivative, read the way the worker reads them (ContentStore). */
function optimizationStoredBytes(Asset $asset): array
{
    $store = app(ContentStore::class);
    $read = function ($object, string $extension) use ($store): string {
        $staged = $store->localPath($object, $extension);

        try {
            return (string) file_get_contents($staged->path);
        } finally {
            $staged->release();
        }
    };
    $snapshot = ['original' => $read($store->current($asset), 'png')];

    foreach ($asset->files()->where('role', AssetFile::ROLE_OUTPUT)->with('fileObject')->orderBy('id')->get() as $file) {
        $snapshot['derivative:'.$file->id] = $read($file->fileObject, 'jpg');
    }

    return $snapshot;
}

/** A ContentStore that remembers every LocalFile it stages, so a test inspects exactly the staging the worker
 *  made (never a glob of the shared system temp directory, which a parallel test also writes to). Holding the
 *  references also stops LocalFile's destructor from masking a worker that forgot to release(). */
function optimizationRecordingContentStore(): ContentStore
{
    return new class extends ContentStore
    {
        /** @var list<LocalFile> */
        public array $staged = [];

        public function localPath(FileObject $object, ?string $extension = null, ?string $under = null, bool $copy = false): LocalFile
        {
            return $this->staged[] = parent::localPath($object, $extension, $under, $copy);
        }
    };
}

// Both failure kinds go through the real Optimizer, job and Requests (only the HTTP transport is faked), so
// the same preservation fixture proves neither path touches the original, an existing derivative or leaves
// staging behind.
it('settles a failed optimizer run with the right code and message, leaving the original and an existing derivative byte-identical', function (string $kind, string $code, string $message) {
    $staging = sys_get_temp_dir().'/optimize-failure-test-'.bin2hex(random_bytes(6));
    mkdir($staging, 0700);
    config(['optimization.staging_dir' => $staging]);
    $start = Carbon::parse('2026-10-12 12:00:00', 'UTC');
    Carbon::setTestNow($start);

    try {
        // An existing derivative, settled in its own second so the state endpoint's ordering is not a tie.
        [$existingId, $existing] = completedOptimization($this->source);
        $before = optimizationStoredBytes($this->source);
        expect($before)->toHaveKeys(['original', 'derivative:'.$existing->id])
            ->and($before['original'])->toBe('original bytes')
            ->and($before['derivative:'.$existing->id])->toBe('smaller bytes');

        Carbon::setTestNow($start->copy()->addSeconds(5));
        app()->forgetInstance(Optimizer::class);
        Http::fake(['clop.test/optimize*' => $kind === 'unreachable'
            ? fn () => throw new ConnectionException('cURL error 7', 0, new ConnectException(
                'cURL error 7', new Request('POST', 'http://clop.test/optimize'), null, ['errno' => 7, 'connect_time' => 0.0],
            ))
            : Http::response(['error' => 'boom'], 500)]);
        $id = optimizationSubmit($this);
        $recorder = optimizationRecordingContentStore();
        app()->instance(ContentStore::class, $recorder);

        (new OptimizeAsset(id: $id))->handle();

        $staged = $recorder->staged;
        app()->forgetInstance(ContentStore::class);
        $row = DB::table('dam_optimizations')->where('id', $id)->first();
        expect($row->status)->toBe('failed')
            ->and($row->error)->toBe($code)
            ->and($row->asset_file_id)->toBeNull()
            ->and($this->source->files()->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(1)
            ->and(optimizationStoredBytes($this->source))->toBe($before)
            ->and(Storage::disk(Directory::getAssetDisk())->get('assets/source.png'))->toBe('original bytes')
            ->and(glob($staging.'/*'))->toBe([], 'the Optimizer must leave no staging directory behind')
            ->and($staged)->toHaveCount(1, 'the worker stages the original exactly once')
            ->and(array_map(fn (LocalFile $file) => $file->isReleased() && ! is_dir(dirname($file->path)), $staged))->toBe([true], 'the worker must release its staged copy of the original');
        $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk()
            ->assertJsonPath('result.id', $id)
            ->assertJsonPath('result.status', 'failed')
            ->assertJsonPath('result.message', $message);
        expect($id)->not->toBe($existingId);
    } finally {
        Carbon::setTestNow();
        @rmdir($staging);
    }
})->with([
    'bridge unreachable' => ['unreachable', 'provider_unreachable', "The optimizer on the Studio isn't running, so no copy was made. Your original is unchanged. Try again later or tell an admin."],
    'real failure'       => ['failure', 'optimize_failed', Requests::MESSAGE_FAILED],
]);

it('sends when a row last changed as ISO 8601, so the UI can stop showing a week-old failure', function () {
    $id = optimizationSubmit($this);
    $settled = Carbon::parse('2026-10-05 08:30:00', 'UTC');
    DB::table('dam_optimizations')->where('id', $id)->update(['status' => 'failed', 'error' => 'optimize_failed', 'updated_at' => $settled->format('Y-m-d H:i:s')]);

    $payload = $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk()->json('result');

    expect($payload['updated_at'])->toBe(Carbon::parse('2026-10-05 08:30:00')->toIso8601String())
        ->and(Carbon::parse($payload['updated_at'])->equalTo(Carbon::parse('2026-10-05 08:30:00')))->toBeTrue();
});

it('marks a failed or expired row stale only when it settled more than 7 days ago by server time', function (string $status, int $ageSeconds, bool $stale) {
    $id = optimizationSubmit($this);
    $now = Carbon::parse('2026-10-12 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    try {
        DB::table('dam_optimizations')->where('id', $id)->update([
            'status'     => $status, 'error' => $status === 'failed' ? 'optimize_failed' : null,
            'updated_at' => $now->copy()->subSeconds($ageSeconds)->format('Y-m-d H:i:s'),
        ]);

        $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk()
            ->assertJsonPath('result.stale', $stale)
            ->assertJsonPath('result.status', $status);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'failed, a minute under 7 days'  => ['failed', 7 * 86400 - 60, false],
    'failed, exactly 7 days'         => ['failed', 7 * 86400, false],
    'failed, a second over 7 days'   => ['failed', 7 * 86400 + 1, true],
    'failed, a minute over 7 days'   => ['failed', 7 * 86400 + 60, true],
    'failed, fresh'                  => ['failed', 5, false],
    'expired, a minute under 7 days' => ['expired', 7 * 86400 - 60, false],
    'expired, a minute over 7 days'  => ['expired', 7 * 86400 + 60, true],
]);

it('keeps the failure message and reports not stale when updated_at is null or malformed', function (string $status, mixed $updatedAt) {
    $row = (object) [
        'id'        => (string) Str::uuid(), 'status' => $status, 'outcome' => null, 'type' => 'image', 'error' => $status === 'failed' ? 'provider_unreachable' : null,
        'old_bytes' => null, 'new_bytes' => null, 'output_extension' => null, 'created_at' => '2026-10-01 10:00:00', 'updated_at' => $updatedAt,
    ];

    $result = app(Requests::class)->publicResult(row: $row);

    expect($result['stale'])->toBeFalse()
        ->and($result['updated_at'])->toBeNull()
        ->and($result['status'])->toBe($status)
        ->and($result['message'])->toBe($status === 'failed' ? Requests::MESSAGE_PROVIDER_UNREACHABLE : Requests::MESSAGE_EXPIRED);
})->with([
    'failed, null'          => ['failed', null],
    'failed, empty string'  => ['failed', ''],
    'failed, malformed'     => ['failed', 'not a timestamp'],
    'failed, impossible'    => ['failed', '2026-13-45 99:99:99'],
    'expired, null'         => ['expired', null],
    'expired, malformed'    => ['expired', 'garbage'],
]);

it('shows no stalled notice, and does not fail, for a queued row with a null or malformed created_at', function (mixed $createdAt) {
    config(['optimization.queued_notice_after' => 120]);
    $row = (object) [
        'id'        => (string) Str::uuid(), 'status' => 'queued', 'outcome' => null, 'type' => 'image', 'error' => null,
        'old_bytes' => null, 'new_bytes' => null, 'output_extension' => null, 'created_at' => $createdAt, 'updated_at' => '2026-10-01 10:00:00',
    ];

    $result = app(Requests::class)->publicResult(row: $row);

    expect($result['status'])->toBe('queued')
        ->and($result['message'])->toBeNull()
        ->and($result['stale'])->toBeFalse();
})->with([
    'null'       => [null],
    'empty'      => [''],
    'malformed'  => ['not a timestamp'],
    'impossible' => ['2026-13-45 99:99:99'],
]);

it('still shows the stalled notice for a queued row with a valid old created_at', function () {
    config(['optimization.queued_notice_after' => 120]);
    $row = (object) [
        'id'        => (string) Str::uuid(), 'status' => 'queued', 'outcome' => null, 'type' => 'image', 'error' => null,
        'old_bytes' => null, 'new_bytes' => null, 'output_extension' => null, 'created_at' => now()->subSeconds(500)->format('Y-m-d H:i:s'), 'updated_at' => now()->format('Y-m-d H:i:s'),
    ];

    expect(app(Requests::class)->publicResult(row: $row)['message'])->toBe(Requests::MESSAGE_STALLED);
});

it('never marks a queued, processing or completed row stale, however old', function (string $status) {
    $id = optimizationSubmit($this);
    DB::table('dam_optimizations')->where('id', $id)->update(['status' => $status, 'updated_at' => now()->subDays(30)->format('Y-m-d H:i:s')]);

    $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk()
        ->assertJsonPath('result.stale', false);
})->with(['queued', 'processing', 'completed']);

it('maps every failure code to a plain message that never exposes the code', function (string $error, string $message) {
    $id = optimizationSubmit($this);
    DB::table('dam_optimizations')->where('id', $id)->update(['status' => 'failed', 'error' => $error]);

    $result = app(Requests::class)->publicResult(row: DB::table('dam_optimizations')->where('id', $id)->first());

    expect($result['message'])->toBe($message)->and(json_encode($result))->not->toContain($error);
})->with([
    'unreachable'         => ['provider_unreachable', Requests::MESSAGE_PROVIDER_UNREACHABLE],
    'worker crashed'      => ['worker_failed', Requests::MESSAGE_WORKER_FAILED],
    'source unreadable'   => ['source_unavailable', Requests::MESSAGE_SOURCE_UNAVAILABLE],
    'real failure'        => ['optimize_failed', Requests::MESSAGE_FAILED],
    'unknown future code' => ['something_new', Requests::MESSAGE_FAILED],
]);

it('reports ready false from the state endpoint when the optimizer is not configured, so the UI can show Optimize disabled', function () {
    config(['optimization.api' => null]);

    $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk()
        ->assertJsonPath('ready', false)->assertJsonPath('result', null);
    $this->postJson(route('admin.dam.assets.optimization.store', $this->source->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertStatus(503);
    Queue::assertNothingPushed();
});

it('shows the stalled notice only once a queued job has waited more than the threshold, on database-string timestamps', function (int $waited, bool $notice) {
    $id = optimizationSubmit($this);
    config(['optimization.queued_notice_after' => 120]);
    $now = Carbon::parse('2026-10-12 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    try {
        DB::table('dam_optimizations')->where('id', $id)->update(['created_at' => $now->copy()->subSeconds($waited)->format('Y-m-d H:i:s')]);

        $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk()
            ->assertJsonPath('result.status', 'queued')
            ->assertJsonPath('result.message', $notice ? Requests::MESSAGE_STALLED : null);
        expect(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('queued');
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'just under (119s)'       => [119, false],
    'at the threshold (120s)' => [120, false],
    'just over (121s)'        => [121, true],
    'a fresh row'             => [0, false],
]);

it('disables the stalled notice when the threshold is zero or negative', function (int $threshold) {
    $id = optimizationSubmit($this);
    config(['optimization.queued_notice_after' => $threshold]);
    DB::table('dam_optimizations')->where('id', $id)->update(['created_at' => now()->subHours(2)]);

    $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk()
        ->assertJsonPath('result.status', 'queued')->assertJsonPath('result.message', null);
})->with([[0], [-1]]);

it('drops the stalled notice once a worker claims the job, however old the row is', function () {
    $id = optimizationSubmit($this);
    DB::table('dam_optimizations')->where('id', $id)->update(['created_at' => now()->subHour()]);
    $state = fn () => $this->getJson(route('admin.dam.assets.optimization.state', $this->source->id))->assertOk();

    $state()->assertJsonPath('result.message', Requests::MESSAGE_STALLED);

    DB::table('dam_optimizations')->where('id', $id)->update(['status' => 'processing']);
    $state()->assertJsonPath('result.status', 'processing')->assertJsonPath('result.message', null);

    app(Requests::class)->finish(id: $id, success: true, outcome: 'no_reduction', oldBytes: 14);
    $state()->assertJsonPath('result.status', 'completed')->assertJsonPath('result.message', null);
});

// SQL Server holds asset_file_id as NO ACTION (error 1785), so these exercise the app-level clears
// there; the other drivers reach the same end state through SET NULL and the asset cascade.
it('clears the optimization link when its optimized copy is deleted', function () {
    [$id, $output] = completedOptimization($this->source);

    $this->deleteJson(route('admin.dam.assets.files.destroy', [$this->source->id, $output->id]))->assertOk();

    expect(AssetFile::whereKey($output->id)->exists())->toBeFalse()
        ->and(DB::table('dam_optimizations')->where('id', $id)->value('asset_file_id'))->toBeNull()
        ->and(DB::table('dam_optimizations')->where('id', $id)->value('status'))->toBe('completed');
});

it('clears the optimization link when the optimized asset is deleted', function () {
    [$id] = completedOptimization($this->source);

    app(AssetDeletionService::class)->delete($this->source);

    expect(Asset::whereKey($this->source->id)->exists())->toBeFalse()
        ->and(DB::table('dam_optimizations')->where('id', $id)->exists())->toBeFalse();
});

/**
 * @return array{0: string, 1: AssetFile}
 */
function completedOptimization(Asset $asset): array
{
    $id = test()->postJson(route('admin.dam.assets.optimization.store', $asset->id), [
        'request_id' => (string) Str::uuid(), 'scale' => '1', 'adaptive' => '0',
    ])->assertAccepted()->json('result.id');
    $client = Mockery::mock(Optimizer::class);
    $client->shouldReceive('ready')->andReturn(true);
    $client->shouldReceive('optimize')->once()->andReturnUsing(function () {
        $dir = sys_get_temp_dir().'/optimize-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/out.jpg', 'smaller bytes');

        return ['outcome' => 'optimized', 'path' => $dir.'/out.jpg', 'extension' => 'jpg', 'old_bytes' => 14, 'new_bytes' => 5, 'width' => 20, 'height' => 20];
    });
    app()->instance(Optimizer::class, $client);
    (new OptimizeAsset(id: $id))->handle();

    $output = $asset->files()->where('role', AssetFile::ROLE_OUTPUT)->firstOrFail();
    expect(DB::table('dam_optimizations')->where('id', $id)->value('asset_file_id'))->toEqual($output->id);

    return [$id, $output];
}

function optimizationOriginalObjectFromBytes($asset, string $bytes)
{
    $sha = hash('sha256', $bytes);
    $object = app(ContentStore::class)->putLocal(
        disk: Directory::getAssetDisk(),
        localPath: (function () use ($bytes) {
            $path = tempnam(sys_get_temp_dir(), 'reup-');
            file_put_contents($path, $bytes);

            return $path;
        })(),
        sha256: $sha,
        mimeType: 'image/png',
        extension: 'png',
    );
    AssetFile::create([
        'dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_ORIGINAL, 'sort' => 0,
    ]);

    return $object;
}

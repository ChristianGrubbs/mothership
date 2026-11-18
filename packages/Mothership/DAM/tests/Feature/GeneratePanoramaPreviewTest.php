<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GeneratePanoramaPreview;
use Mothership\DAM\Media\CliPanoramaWorker;
use Mothership\DAM\Media\PanoramaCancelled;
use Mothership\DAM\Media\PanoramaWorker;
use Mothership\DAM\Media\PanoramaWorkerResult;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\PanoramaPreviewService;
use Mothership\DAM\Services\RenditionPublisher;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    resetPanoramaLog();
});

afterEach(function () {
    resetPanoramaLog();
});

function panoramaQueuedRendition(): array
{
    $asset = Asset::factory()->create([
        'file_name' => 'flight.osv', 'file_type' => 'document',
        'mime_type' => 'application/octet-stream', 'extension' => 'osv',
        'path'      => 'assets/Root/flight.osv',
    ]);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, 'source-fixture');
    $object = FileObject::factory()->create([
        'sha256'    => hash('sha256', 'flight.osv'),
        // A read checks the blob holds exactly the recorded size (ContentStore::holdsBlob()).
        'size'      => strlen('source-fixture'),
        'disk'      => Directory::getAssetDisk(),
        // Suffix-less: this is what CompoundAssetObserver's putStored() actually records on first
        // ingest (it never passes an extension). The .osv suffix CliPanoramaWorker stages with
        // must come from AssetFile::extension() reading the owning asset's extension column.
        'path'      => storeFixtureBlob(hash('sha256', 'flight.osv'), 'source-fixture'),
        'mime_type' => 'application/octet-stream',
    ]);
    $source = AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $object->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
    ]);
    $rendition = Rendition::factory()->create([
        'dam_asset_id'         => $asset->id,
        'source_asset_file_id' => $source->id,
        'recipe'               => Rendition::RECIPE_PANORAMA_4K,
        'fingerprint'          => hash('sha256', $source->fileObject->sha256.'|pano360-h264-4k'),
        'parameters'           => PanoramaPreviewService::PROFILE,
        'worker_version'       => PanoramaPreviewService::WORKER_VERSION,
        'status'               => Rendition::STATUS_QUEUED,
    ]);

    return [$asset, $rendition];
}

function validPanoramaResultFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'pano-result-').'.mp4';
    file_put_contents($path, 'validated-mp4-fixture');

    return $path;
}

/**
 * A complete, otherwise-valid `technical` array — every field
 * RenditionPublisher::validate() currently requires — with $overrides
 * applied on top (or a key unset by passing null), so a negative test can
 * change or remove exactly the one field it's proving gets rejected
 * without repeating the other four every time.
 */
function validPanoramaTechnical(array $overrides = []): array
{
    $technical = [
        'width'   => 3840, 'height' => 1920, 'codec_name' => 'h264',
        'pix_fmt' => 'yuv420p', 'spherical' => true, 'faststart' => true, 'audio' => 'aac',
    ];

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($technical[$key]);
        } else {
            $technical[$key] = $value;
        }
    }

    return $technical;
}

final class FakePanoramaWorker implements PanoramaWorker
{
    public int $calls = 0;

    public function __construct(private PanoramaWorkerResult|Throwable $outcome, private array $progress = [12, 54, 100]) {}

    public function run(Rendition $rendition, Closure $progress, Closure $cancelled): PanoramaWorkerResult
    {
        $this->calls++;

        foreach ($this->progress as $value) {
            if ($cancelled()) {
                throw new PanoramaCancelled('cancel requested');
            }
            $progress($value, 'fixture');
        }

        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

/**
 * Cancels mid-run: unlike FakePanoramaWorker (which only observes a
 * cancellation requested by the caller before run() started), this writes
 * cancel_requested_at itself right after its first progress report, so the
 * job's live $cancelled() re-check (it re-queries the row on every call)
 * picks it up on the very next iteration — proving cancellation is honored
 * mid-run, not just at the start.
 */
final class MidRunCancellingWorker implements PanoramaWorker
{
    public function run(Rendition $rendition, Closure $progress, Closure $cancelled): PanoramaWorkerResult
    {
        $progress(40, 'half-way');

        Rendition::query()->whereKey($rendition->id)->update(['cancel_requested_at' => now()]);

        if ($cancelled()) {
            throw new PanoramaCancelled('cancel requested');
        }

        throw new RuntimeException('should not reach a result after cancellation was observed');
    }
}

it('publishes a validated proxy and completes the attempt', function () {
    [$asset, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    app()->instance(PanoramaWorker::class, new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', ['width' => 3840, 'height' => 1920, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p', 'spherical' => true, 'faststart' => true, 'audio' => 'aac'])
    ));

    (new GeneratePanoramaPreview($rendition->id))->handle(app(PanoramaWorker::class), app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_DONE)
        ->and($rendition->progress)->toBe(100)
        ->and($rendition->output_file_object_id)->not->toBeNull()
        ->and($asset->files()->where('role', AssetFile::ROLE_PROXY)
            ->where('file_object_id', $rendition->output_file_object_id)->exists())->toBeTrue();
});

it('fails a queued rendition whose worker version does not match the deployed implementation', function () {
    [$asset, $rendition] = panoramaQueuedRendition();
    $rendition->update(['worker_version' => 'panoforge-older']);
    $worker = new FakePanoramaWorker(new RuntimeException('stale rendition must not execute'));

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($worker->calls)->toBe(0)
        ->and($rendition->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('worker_version_mismatch');
});

it('never lets reported progress move backwards, even when the worker reports out of order', function () {
    [, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', ['width' => 3840, 'height' => 1920, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p', 'spherical' => true, 'faststart' => true, 'audio' => 'aac']),
        progress: [80, 30, 95],
    );

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    $percents = collect(readPanoramaLogLines())
        ->map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
        ->where('event', 'panorama_progress')
        ->where('ctx.rendition_id', $rendition->id)
        ->pluck('ctx.percent')
        ->values();

    expect($percents->all())->toBe([80, 80, 95]);

    for ($i = 1; $i < $percents->count(); $i++) {
        expect($percents[$i])->toBeGreaterThanOrEqual($percents[$i - 1]);
    }

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_DONE)
        ->and($rendition->progress)->toBe(100);
});

it('honors a cancellation requested before the worker ever starts', function () {
    [, $rendition] = panoramaQueuedRendition();
    $rendition->forceFill(['cancel_requested_at' => now()])->save();
    $worker = new FakePanoramaWorker(new RuntimeException('worker must not run'));

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($worker->calls)->toBe(0)
        ->and($rendition->refresh()->status)->toBe(Rendition::STATUS_CANCELLED)
        ->and($rendition->finished_at)->not->toBeNull();
});

it('honors a cancellation requested mid-run', function () {
    [, $rendition] = panoramaQueuedRendition();

    (new GeneratePanoramaPreview($rendition->id))->handle(new MidRunCancellingWorker, app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_CANCELLED)
        ->and($rendition->progress)->toBe(40)
        ->and($rendition->output_file_object_id)->toBeNull();
});

it('marks a worker exception failed with a redacted error code', function () {
    [, $rendition] = panoramaQueuedRendition();
    $worker = new FakePanoramaWorker(new RuntimeException('conversion crashed at /private/var/tmp/attempt-42/source.osv'));

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    $rendition->refresh();

    expect($rendition->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->not->toBeNull()
        ->and($rendition->error)->not->toContain('/private/var/tmp')
        ->and($rendition->error)->toContain('<path>');
});

it('refuses to publish a result with the wrong dimensions', function () {
    [, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', ['width' => 1920, 'height' => 960, 'codec_name' => 'h264'])
    );

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('invalid_dimensions')
        ->and($rendition->output_file_object_id)->toBeNull();
});

it('refuses to publish a result with a non-h264 codec', function () {
    [, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', ['width' => 3840, 'height' => 1920, 'codec_name' => 'hevc'])
    );

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('invalid_codec')
        ->and($rendition->output_file_object_id)->toBeNull();
});

it('refuses to publish a result with the wrong pixel format', function () {
    [, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', validPanoramaTechnical(['pix_fmt' => 'yuv444p']))
    );

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('invalid_pixel_format')
        ->and($rendition->output_file_object_id)->toBeNull();
});

it('refuses to publish a result reporting spherical: false', function () {
    [, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', validPanoramaTechnical(['spherical' => false]))
    );

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('missing_spherical_metadata')
        ->and($rendition->output_file_object_id)->toBeNull();
});

it('refuses to publish a result reporting faststart: false', function () {
    [, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', validPanoramaTechnical(['faststart' => false]))
    );

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('invalid_faststart')
        ->and($rendition->output_file_object_id)->toBeNull();
});

it('refuses to publish a result with an undecodable audio codec', function () {
    [, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', validPanoramaTechnical(['audio' => 'opus']))
    );

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('invalid_audio')
        ->and($rendition->output_file_object_id)->toBeNull();
});

it('accepts audio: none — 360 rigs and .osv sources are routinely silent', function () {
    [$asset, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', validPanoramaTechnical(['audio' => 'none']))
    );

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_DONE)
        ->and($asset->files()->where('role', AssetFile::ROLE_PROXY)
            ->where('file_object_id', $rendition->output_file_object_id)->exists())->toBeTrue();
});

it('refuses to publish a result missing any of the newly-required technical fields', function () {
    [, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', validPanoramaTechnical([
            'pix_fmt' => null, 'spherical' => null, 'faststart' => null, 'audio' => null,
        ]))
    );

    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($rendition->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('unreadable_technical_metadata')
        ->and($rendition->output_file_object_id)->toBeNull();
});

it('keeps the prior good proxy intact when a rebuild attempt fails', function () {
    [$asset, $first] = panoramaQueuedRendition();
    $goodPath = validPanoramaResultFile();
    (new GeneratePanoramaPreview($first->id))->handle(
        new FakePanoramaWorker(new PanoramaWorkerResult($goodPath, 'video/mp4', ['width' => 3840, 'height' => 1920, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p', 'spherical' => true, 'faststart' => true, 'audio' => 'aac'])),
        app(RenditionPublisher::class),
    );
    $first->refresh();
    expect($first->status)->toBe(Rendition::STATUS_DONE);
    $goodOutputId = $first->output_file_object_id;

    $second = Rendition::factory()->create([
        'dam_asset_id'         => $asset->id,
        'source_asset_file_id' => $first->source_asset_file_id,
        'recipe'               => Rendition::RECIPE_PANORAMA_4K,
        'fingerprint'          => $first->fingerprint,
        'parameters'           => PanoramaPreviewService::PROFILE,
        'worker_version'       => PanoramaPreviewService::WORKER_VERSION,
        'status'               => Rendition::STATUS_QUEUED,
    ]);

    (new GeneratePanoramaPreview($second->id))->handle(
        new FakePanoramaWorker(new RuntimeException('rebuild failed')),
        app(RenditionPublisher::class),
    );

    expect($second->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and($second->output_file_object_id)->toBeNull()
        ->and($first->refresh()->status)->toBe(Rendition::STATUS_DONE)
        ->and($first->output_file_object_id)->toBe($goodOutputId)
        ->and($asset->files()->where('role', AssetFile::ROLE_PROXY)->where('file_object_id', $goodOutputId)->exists())->toBeTrue();
});

it('is a no-op when handled again after already being done', function () {
    [$asset, $rendition] = panoramaQueuedRendition();
    $path = validPanoramaResultFile();
    (new GeneratePanoramaPreview($rendition->id))->handle(
        new FakePanoramaWorker(new PanoramaWorkerResult($path, 'video/mp4', ['width' => 3840, 'height' => 1920, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p', 'spherical' => true, 'faststart' => true, 'audio' => 'aac'])),
        app(RenditionPublisher::class),
    );
    $rendition->refresh();
    expect($rendition->status)->toBe(Rendition::STATUS_DONE);
    $outputId = $rendition->output_file_object_id;
    $proxyCountBefore = $asset->files()->where('role', AssetFile::ROLE_PROXY)->count();

    $worker = new FakePanoramaWorker(new RuntimeException('must not run on an already-done rendition'));
    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    expect($worker->calls)->toBe(0)
        ->and($rendition->refresh()->status)->toBe(Rendition::STATUS_DONE)
        ->and($rendition->output_file_object_id)->toBe($outputId)
        ->and($asset->files()->where('role', AssetFile::ROLE_PROXY)->count())->toBe($proxyCountBefore);
});

it('correlates the request-queued log line with the worker run log lines for the same rendition', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'flight.osv', 'file_type' => 'document',
        'mime_type' => 'application/octet-stream', 'extension' => 'osv',
        'path'      => 'assets/Root/flight.osv', 'asset_kind' => 'pano360',
    ]);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, 'source-fixture');
    $object = FileObject::factory()->create([
        'sha256' => hash('sha256', 'flight.osv'), 'disk' => Directory::getAssetDisk(),
        'path'   => storeFixtureBlob(hash('sha256', 'flight.osv'), 'source-fixture'), 'mime_type' => 'application/octet-stream',
    ]);
    AssetFile::factory()->create([
        'dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_ORIGINAL,
    ]);
    config(['mothership.panorama_processing_enabled' => true]);

    // QUEUE_CONNECTION=sync ignores ->afterCommit() and runs a dispatched
    // job immediately, inline — so without faking the bus here,
    // request()'s own dispatch would synchronously run the REAL
    // CliPanoramaWorker (shelling out to a nonexistent
    // tools/media/mothership_360.py in this test environment) before this
    // test's own explicit handle() call below even executes. Faking it
    // keeps this test isolated to exactly the one job run it drives itself
    // and avoids spawning a real, unpredictable subprocess in a feature
    // test. See PanoramaPreviewApiTest.php's beforeEach() for the fuller
    // diagnosis — the same mechanism broke four of its tests.
    Bus::fake();

    $rendition = app(PanoramaPreviewService::class)->request($asset);

    $path = validPanoramaResultFile();
    $worker = new FakePanoramaWorker(
        new PanoramaWorkerResult($path, 'video/mp4', ['width' => 3840, 'height' => 1920, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p', 'spherical' => true, 'faststart' => true, 'audio' => 'aac'])
    );
    (new GeneratePanoramaPreview($rendition->id))->handle($worker, app(RenditionPublisher::class));

    $events = collect(readPanoramaLogLines())
        ->map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
        ->where('ctx.rendition_id', $rendition->id);

    $eventNames = $events->pluck('event')->all();
    expect($eventNames)->toContain('panorama_request_queued')
        ->and($eventNames)->toContain('panorama_job_started')
        ->and($eventNames)->toContain('panorama_publish_done');

    $runIds = $events->pluck('run_id')->unique()->values()->all();
    expect($runIds)->toHaveCount(1);
});

it('stages the panorama source with the .osv suffix even though its blob is stored suffix-less', function () {
    [, $rendition] = panoramaQueuedRendition();
    $attemptDir = sys_get_temp_dir().'/panorama-stage-test-'.bin2hex(random_bytes(4));
    mkdir($attemptDir, 0700, true);

    $worker = new CliPanoramaWorker;
    $reflection = new ReflectionMethod($worker, 'stageSource');
    $reflection->setAccessible(true);

    try {
        $localPath = $reflection->invoke($worker, $rendition, $attemptDir);

        expect($localPath)->toEndWith('.osv')
            ->and(file_get_contents($localPath))->toBe('source-fixture');
    } finally {
        @unlink($localPath ?? '');
        @rmdir($attemptDir);
    }
});

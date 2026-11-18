<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GenerateVideoPreviewProxy;
use Mothership\DAM\Jobs\GenerateVideoThumbnail;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\VideoPreviewProfile;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

/**
 * A job whose two external seams are faked: ffmpeg "produces" a file by
 * writing an ftyp-headed MP4 payload (R18 — VideoProxyPublisher rejects
 * anything else at the container check), and ffprobe reports whatever the
 * test wants.
 *
 * $sourceProbe, when given, answers the FIRST call to probe() (the job's
 * R2 pre-check against the source file, made only when the asset's known
 * height is unknown); every subsequent call (the post-transcode probe of
 * the produced output) answers with $probe. When $sourceProbe is null,
 * every call answers with $probe — the shape all four original tests use.
 *
 * $force is forwarded to the job's constructor (R4). The returned instance
 * exposes public bool $ranFfmpeg so a test can assert the transcode never
 * ran at all (e.g. an admission guard blocked it before ffmpeg would fire).
 *
 * R33 observation hooks: $temporaryPaths lists every temp file the job asked
 * for (proxy output and any staged remote source), $probedPaths every path
 * handed to probe(), and $ffmpegInput / $ffmpegInputSha the `-i` path ffmpeg
 * was given and the sha256 of its bytes at that moment.
 *
 * R38 interleaving hooks: $onSourceProbe, when given, answers the FIRST
 * probe() call in place of $sourceProbe (use it with an unknown-height asset,
 * where that call is the pre-admission source probe) — it returns the probe
 * array or throws. $duringFfmpeg, when given, is called with the job inside
 * runFfmpeg(), after admission and before the fake output is written — the
 * window in which the attempt's rendition is RUNNING.
 */
function fakePreviewJob(
    int $assetId,
    array $probe,
    bool $ffmpegOk = true,
    ?array $sourceProbe = null,
    bool $force = false,
    ?Closure $onSourceProbe = null,
    ?Closure $duringFfmpeg = null,
): GenerateVideoPreviewProxy {
    return new class($assetId, $probe, $ffmpegOk, $sourceProbe, $force, $onSourceProbe, $duringFfmpeg) extends GenerateVideoPreviewProxy
    {
        private int $probeCalls = 0;

        public bool $ranFfmpeg = false;

        /** @var list<string> */
        public array $temporaryPaths = [];

        /** @var list<string> */
        public array $probedPaths = [];

        public ?string $ffmpegInput = null;

        public ?string $ffmpegInputSha = null;

        public function __construct(
            int $assetId,
            private array $probeResult,
            private bool $ffmpegOk,
            private ?array $sourceProbeResult,
            bool $force,
            private ?Closure $onSourceProbe = null,
            private ?Closure $duringFfmpeg = null,
        ) {
            parent::__construct($assetId, $force);
        }

        protected function temporaryFile(string $prefix = 'damvid_proxy_', string $extension = 'mp4'): string
        {
            $path = parent::temporaryFile($prefix, $extension);
            $this->temporaryPaths[] = $path;

            return $path;
        }

        protected function runFfmpeg(array $command): array
        {
            $this->ranFfmpeg = true;
            $this->ffmpegInput = $command[array_search('-i', $command, true) + 1];
            $this->ffmpegInputSha = is_file($this->ffmpegInput) ? hash_file('sha256', $this->ffmpegInput) : null;

            if ($this->duringFfmpeg !== null) {
                ($this->duringFfmpeg)($this);
            }

            if ($this->ffmpegOk) {
                // R18: ftyp-headed bytes so VideoProxyPublisher's container check passes.
                file_put_contents(
                    $command[count($command) - 1],
                    "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 100)
                );
            }

            return [$this->ffmpegOk, $this->ffmpegOk ? '' : 'fake failure'];
        }

        protected function probe(string $path): array
        {
            $this->probeCalls++;
            $this->probedPaths[] = $path;

            if ($this->probeCalls === 1 && $this->onSourceProbe !== null) {
                return ($this->onSourceProbe)($path);
            }

            if ($this->probeCalls === 1 && $this->sourceProbeResult !== null) {
                return $this->sourceProbeResult;
            }

            return $this->probeResult;
        }
    };
}

/**
 * @param  ?int  $height  Known height in `meta_data`, or null to model an
 *                        "unknown height" master (no ImageHeight key at all).
 * @param  string  $extension  File extension — also drives the file name/path.
 * @param  string  $mimeType  Stored mime_type.
 * @param  ?string  $codecName  When given, recorded in meta_data as `codec_name`
 *                              so Asset::originalBrowserPlaybackType() can see
 *                              it (used to model a non-browser-playable master,
 *                              e.g. ProRes in a .mov, per R2).
 * @param  string  $baseName  File name stem; distinct stems give distinct
 *                            paths when a test needs several assets.
 */
function makeVideoAsset(
    ?int $height = 2160,
    string $extension = 'mp4',
    string $mimeType = 'video/mp4',
    ?string $codecName = null,
    string $baseName = 'clip',
): Asset {
    $metaData = $height === null ? [] : [
        'ImageWidth'  => (int) round($height * 16 / 9),
        'ImageHeight' => $height,
    ];

    if ($codecName !== null) {
        $metaData['codec_name'] = $codecName;
    }

    $path = "assets/Root/{$baseName}.{$extension}";
    // Written before the row is created so the graph observer ingests it as the original
    // (ADR 0009), matching a real upload's write-then-create order.
    Storage::disk(Directory::getAssetDisk())->put($path, 'original-video');

    return Asset::factory()->create([
        'file_name' => "{$baseName}.{$extension}",
        'file_type' => 'video',
        'mime_type' => $mimeType,
        'extension' => $extension,
        'path'      => $path,
        'meta_data' => $metaData,
    ]);
}

/**
 * Exposes the job's protected command() and parseProbe() to tests through
 * the same anonymous-subclass pattern fakePreviewJob() uses — both stay
 * protected in production; only the test's own instance gains public
 * wrappers. A closure rather than a second global function.
 */
$exposedJob = fn (int $assetId = 1): GenerateVideoPreviewProxy => new class($assetId) extends GenerateVideoPreviewProxy
{
    public function exposedCommand(string $input, string $output): array
    {
        return $this->command($input, $output);
    }

    public function exposedParseProbe(string $videoJson, ?string $audioJson): array
    {
        return $this->parseProbe($videoJson, $audioJson);
    }
};

it('transcodes a 4k master into a published 1080p proxy', function () {
    $asset = makeVideoAsset();

    $job = fakePreviewJob($asset->id, [
        'width'      => 1920,
        'height'     => 1080,
        'codec_name' => 'h264',
        'pix_fmt'    => 'yuv420p',
    ]);

    $job->handle();

    $rendition = Rendition::where('dam_asset_id', $asset->id)
        ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
        ->first();

    expect($rendition)->not->toBeNull()
        ->and($rendition->status)->toBe(Rendition::STATUS_DONE)
        // R38: admission records this dispatch's attempt id beside the encode settings.
        ->and($rendition->parameters)->toHaveKeys(['max_width', 'encoder', 'crf', 'attempt'])
        ->and($rendition->parameters['attempt'])->toBe($job->attemptId);

    expect(AssetFile::where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_PROXY)->count())
        ->toBe(1);

    // Lineage (P3a S6): the rendition names the revision it was transcoded from and the proxy file it made.
    expect($rendition->source_asset_file_id)->toBe(AssetFile::where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_ORIGINAL)->value('id'))
        ->and($rendition->output_asset_file_id)->toBe(AssetFile::where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_PROXY)->value('id'));
});

it('skips a master that is already 1080p and creates no rendition', function () {
    $asset = makeVideoAsset(height: 1080);

    fakePreviewJob($asset->id, [
        'width'      => 1920,
        'height'     => 1080,
        'codec_name' => 'h264',
        'pix_fmt'    => 'yuv420p',
    ])->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(0);
});

it('records a failed rendition with a path-free error when ffmpeg fails', function () {
    $asset = makeVideoAsset();

    fakePreviewJob($asset->id, [], ffmpegOk: false)->handle();

    $rendition = Rendition::where('dam_asset_id', $asset->id)
        ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
        ->first();

    expect($rendition->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('ffmpeg_failed')
        ->and($rendition->error)->not->toContain('/');
});

it('does not create a second proxy when one is already done', function () {
    $asset = makeVideoAsset();
    $probe = ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'];

    fakePreviewJob($asset->id, $probe)->handle();
    fakePreviewJob($asset->id, $probe)->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)
        ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
        ->where('status', Rendition::STATUS_DONE)
        ->count())->toBe(1);
});

it('force-regenerates a proxy for a done rendition, deduping identical output bytes', function () {
    $asset = makeVideoAsset();
    $probe = ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'];

    fakePreviewJob($asset->id, $probe)->handle();

    $forced = new class($asset->id, $probe, true, null) extends GenerateVideoPreviewProxy
    {
        public function __construct(int $assetId, private array $probeResult, private bool $ffmpegOk, private ?array $sourceProbeResult)
        {
            parent::__construct($assetId, force: true);
        }

        protected function runFfmpeg(array $command): array
        {
            file_put_contents(
                $command[count($command) - 1],
                "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 100)
            );

            return [true, ''];
        }

        protected function probe(string $path): array
        {
            return $this->probeResult;
        }
    };

    $forced->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)
        ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
        ->where('status', Rendition::STATUS_DONE)
        ->count())->toBe(2);

    expect(AssetFile::where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_PROXY)->count())
        ->toBe(1);
});

it('probes an unknown-height source as 1080p and creates no rendition', function () {
    $asset = makeVideoAsset(height: null);

    fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        sourceProbe: ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
    )->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(0);
});

it('probes an unknown-height source as 4k and creates a done rendition', function () {
    $asset = makeVideoAsset(height: null);

    fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        sourceProbe: ['width' => 3840, 'height' => 2160, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
    )->handle();

    $rendition = Rendition::where('dam_asset_id', $asset->id)
        ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
        ->first();

    expect($rendition)->not->toBeNull()
        ->and($rendition->status)->toBe(Rendition::STATUS_DONE);
});

// R23 — encoder-specific ffmpeg arguments.

it('builds the libx264 command with the veryfast preset and crf', function () use ($exposedJob) {
    config([
        'mothership.video_preview.encoder'    => 'libx264',
        'mothership.video_preview.max_width'  => 1920,
        'mothership.video_preview.crf'        => 23,
        'mothership.video_preview.max_rate'   => '6M',
        'mothership.video_preview.buf_size'   => '12M',
        'mothership.video_preview.audio_rate' => '128k',
    ]);

    $command = $exposedJob()->exposedCommand('/tmp/in.mp4', '/tmp/out.mp4');

    expect($command)->toBe([
        'ffmpeg',
        '-y',
        '-i', '/tmp/in.mp4',
        '-vf', "scale='min(1920,iw)':-2",
        '-c:v', 'libx264',
        '-preset', 'veryfast',
        '-crf', '23',
        '-maxrate', '6M',
        '-bufsize', '12M',
        '-pix_fmt', 'yuv420p',
        '-c:a', 'aac',
        '-b:a', '128k',
        '-movflags', '+faststart',
        '/tmp/out.mp4',
    ]);

    $movflagsIndex = array_search('-movflags', $command, true);
    expect($command[$movflagsIndex + 1])->toBe('+faststart')
        ->and($command[count($command) - 1])->toBe('/tmp/out.mp4');
});

it('builds the h264_nvenc command with NVENC-specific quality controls', function () use ($exposedJob) {
    config([
        'mothership.video_preview.encoder'    => 'h264_nvenc',
        'mothership.video_preview.max_width'  => 1920,
        'mothership.video_preview.crf'        => 23,
        'mothership.video_preview.max_rate'   => '6M',
        'mothership.video_preview.buf_size'   => '12M',
        'mothership.video_preview.audio_rate' => '128k',
    ]);

    $command = $exposedJob()->exposedCommand('/tmp/in.mp4', '/tmp/out.mp4');

    expect($command)->toBe([
        'ffmpeg',
        '-y',
        '-i', '/tmp/in.mp4',
        '-vf', "scale='min(1920,iw)':-2",
        '-c:v', 'h264_nvenc',
        '-preset', 'p4',
        '-rc', 'vbr',
        '-cq', '23',
        '-maxrate', '6M',
        '-bufsize', '12M',
        '-pix_fmt', 'yuv420p',
        '-c:a', 'aac',
        '-b:a', '128k',
        '-movflags', '+faststart',
        '/tmp/out.mp4',
    ]);

    $movflagsIndex = array_search('-movflags', $command, true);
    expect($command[$movflagsIndex + 1])->toBe('+faststart')
        ->and($command[count($command) - 1])->toBe('/tmp/out.mp4');
});

// R25 — pure parseProbe() unit coverage (no real ffprobe invocation).

it('parses a video stream with AAC audio into audio => aac', function () use ($exposedJob) {
    $videoJson = json_encode(['streams' => [[
        'width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
    ]]]);
    $audioJson = json_encode(['streams' => [['codec_name' => 'AAC']]]);

    $result = $exposedJob()->exposedParseProbe($videoJson, $audioJson);

    expect($result)->toBe([
        'width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p', 'audio' => 'aac',
    ]);
});

it('parses a video stream with an empty audio stream list into audio => none', function () use ($exposedJob) {
    $videoJson = json_encode(['streams' => [[
        'width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
    ]]]);
    $audioJson = json_encode(['streams' => []]);

    $result = $exposedJob()->exposedParseProbe($videoJson, $audioJson);

    expect($result['audio'])->toBe('none');
});

it('omits the audio key entirely when the audio probe process failed', function () use ($exposedJob) {
    $videoJson = json_encode(['streams' => [[
        'width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
    ]]]);

    $result = $exposedJob()->exposedParseProbe($videoJson, null);

    expect($result)->not->toHaveKey('audio');
});

it('returns an empty array for malformed or empty video JSON', function () use ($exposedJob) {
    expect($exposedJob()->exposedParseProbe('', null))->toBe([])
        ->and($exposedJob()->exposedParseProbe('not json', null))->toBe([])
        ->and($exposedJob()->exposedParseProbe('{"streams":[]}', null))->toBe([]);
});

// errorCode() — path-free redaction vs. a preserved publisher short code.

it('reduces an exception message containing a filesystem path to a generic error code', function () {
    $asset = makeVideoAsset();

    $job = new class($asset->id) extends GenerateVideoPreviewProxy
    {
        protected function runFfmpeg(array $command): array
        {
            file_put_contents(
                $command[count($command) - 1],
                "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 100)
            );

            return [true, ''];
        }

        protected function probe(string $path): array
        {
            throw new RuntimeException('/var/www/storage/app/x.mp4: open failed');
        }
    };

    $job->handle();

    $rendition = Rendition::where('dam_asset_id', $asset->id)
        ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
        ->first();

    expect($rendition->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('transcode_failed');
});

it('preserves a publisher short error code as the persisted error', function () {
    $asset = makeVideoAsset();

    fakePreviewJob($asset->id, [
        'width' => 3840, 'height' => 2160, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
    ])->handle();

    $rendition = Rendition::where('dam_asset_id', $asset->id)
        ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
        ->first();

    expect($rendition->status)->toBe(Rendition::STATUS_FAILED)
        ->and($rendition->error)->toBe('proxy_too_large');
});

// R4 admission matrix — RUNNING always blocks (with or without force);
// FAILED/CANCELLED always admit a fresh attempt.

it('blocks admission over a RUNNING rendition regardless of force', function (bool $force) {
    $asset = makeVideoAsset();

    Rendition::create([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => Rendition::STATUS_RUNNING,
        'worker'       => 'ffmpeg',
        'progress'     => 0,
        'started_at'   => now(),
    ]);

    $job = fakePreviewJob($asset->id, [
        'width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
    ], force: $force);

    $job->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(1)
        ->and($job->ranFfmpeg)->toBeFalse();
})->with([
    'force disabled' => [false],
    'force enabled'  => [true],
]);

it('admits a new attempt over a FAILED or CANCELLED rendition', function (string $status) {
    $asset = makeVideoAsset();

    Rendition::create([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => $status,
        'worker'       => 'ffmpeg',
        'progress'     => 0,
        'started_at'   => now(),
        'finished_at'  => now(),
    ]);

    fakePreviewJob($asset->id, [
        'width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
    ])->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)
        ->where('status', Rendition::STATUS_DONE)
        ->count())->toBe(1);
})->with([
    'failed'    => [Rendition::STATUS_FAILED],
    'cancelled' => [Rendition::STATUS_CANCELLED],
]);

// R2 matrix — a failed/empty probe, a zero height, and an unknown-height
// non-browser-playable original must all PROCEED rather than skip.

it('proceeds when the unknown-height source probe fails or is empty', function () {
    $asset = makeVideoAsset(height: null);

    fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        sourceProbe: [],
    )->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)
        ->where('status', Rendition::STATUS_DONE)
        ->count())->toBe(1);
});

it('proceeds when the unknown-height source probe reports a zero height', function () {
    $asset = makeVideoAsset(height: null);

    fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        sourceProbe: ['width' => 0, 'height' => 0, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
    )->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)
        ->where('status', Rendition::STATUS_DONE)
        ->count())->toBe(1);
});

it('proceeds for an unknown-height, non-browser-playable ProRes MOV source at or below 1080p', function () {
    $asset = makeVideoAsset(height: null, extension: 'mov', mimeType: 'video/quicktime', codecName: 'prores');

    fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        sourceProbe: ['width' => 1920, 'height' => 1080, 'codec_name' => 'prores', 'pix_fmt' => 'yuv422p10le'],
    )->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)
        ->where('status', Rendition::STATUS_DONE)
        ->count())->toBe(1);
});

// R32 — the unknown-height skip judges playability on what the source probe
// read from the file, not only on empty stored metadata.

it('proceeds for an empty-metadata MOV whose source probe reports 1080p ProRes', function () {
    $asset = makeVideoAsset(height: null, extension: 'mov', mimeType: 'video/quicktime');

    fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        sourceProbe: ['width' => 1920, 'height' => 1080, 'codec_name' => 'prores', 'pix_fmt' => 'yuv422p10le'],
    )->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)
        ->where('status', Rendition::STATUS_DONE)
        ->count())->toBe(1);
});

it('proceeds for an empty-metadata MP4 whose source probe reports 1080p HEVC', function () {
    $asset = makeVideoAsset(height: null);

    fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        sourceProbe: ['width' => 1920, 'height' => 1080, 'codec_name' => 'hevc', 'pix_fmt' => 'yuv420p'],
    )->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)
        ->where('status', Rendition::STATUS_DONE)
        ->count())->toBe(1);
});

// An unknown-height source is probed once, not on every backfill run: what
// the probe read is recorded on the asset, so the backfill stops re-queuing a
// small playable master and a --limit batch can move past it.

it('records the probed dimensions of an unknown-height playable source without an audit row or timestamp bump', function () {
    $asset = makeVideoAsset(height: null);
    $probe = ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'];
    $auditsBefore = $asset->audits()->count();
    $updatedAt = $asset->fresh()->updated_at;

    // A bumped updated_at would be visible here — and would reorder the asset
    // grid, whose default sort is dam_assets.updated_at.
    $this->travel(5)->minutes();

    fakePreviewJob($asset->id, $probe, sourceProbe: $probe)->handle();

    $asset->refresh();

    expect($asset->meta_data)->toMatchArray([
        'ImageWidth'       => 1920,
        'ImageHeight'      => 1080,
        'codec_name'       => 'h264',
        'DimensionsSource' => 'ffprobe',
    ])
        ->and(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(0)
        ->and($asset->audits()->count())->toBe($auditsBefore)
        ->and($asset->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeFalse();
});

it('keeps a failed unknown-height source proxy-eligible after recording what the probe read', function (string $extension, string $mimeType, array $sourceProbe) {
    $asset = makeVideoAsset(height: null, extension: $extension, mimeType: $mimeType);

    fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        ffmpegOk: false,
        sourceProbe: $sourceProbe,
    )->handle();

    $asset->refresh();

    // Dimensions alone would read a 1080p ProRes/HEVC master as playable; the
    // recorded codec keeps this failed transcode retryable by the next backfill.
    expect($asset->meta_data)->toMatchArray([
        'ImageHeight' => $sourceProbe['height'],
        'codec_name'  => $sourceProbe['codec_name'],
    ])
        ->and(Rendition::where('dam_asset_id', $asset->id)->where('status', Rendition::STATUS_FAILED)->count())->toBe(1)
        ->and(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeTrue();
})->with([
    '1080p ProRes MOV' => ['mov', 'video/quicktime', ['width' => 1920, 'height' => 1080, 'codec_name' => 'prores', 'pix_fmt' => 'yuv422p10le']],
    '1080p HEVC MP4'   => ['mp4', 'video/mp4', ['width' => 1920, 'height' => 1080, 'codec_name' => 'hevc', 'pix_fmt' => 'yuv420p']],
    '4K H.264 MP4'     => ['mp4', 'video/mp4', ['width' => 3840, 'height' => 2160, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p']],
]);

it('transcodes an unknown-height source whose probe contradicts a stale stored codec, and keeps it proxy-eligible', function (string $extension, string $mimeType, string $probedCodec) {
    // Stored metadata claims H.264 but carries no height; the file itself is
    // ProRes/HEVC. The probe must win, both now and for later backfill runs.
    $asset = makeVideoAsset(height: null, extension: $extension, mimeType: $mimeType, codecName: 'h264');

    $job = fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        ffmpegOk: false,
        sourceProbe: ['width' => 1920, 'height' => 1080, 'codec_name' => $probedCodec, 'pix_fmt' => 'yuv420p'],
    );

    $job->handle();

    $asset->refresh();

    expect($job->ranFfmpeg)->toBeTrue()
        ->and($asset->meta_data['codec_name'])->toBe($probedCodec)
        ->and(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeTrue();
})->with([
    'ProRes MOV' => ['mov', 'video/quicktime', 'prores'],
    'HEVC MP4'   => ['mp4', 'video/mp4', 'hevc'],
]);

it('skips the ordinary transcode when the probe reveals an unknown-height source is a 2:1 panorama', function () {
    $asset = makeVideoAsset(height: null);

    $job = fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 960, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        sourceProbe: ['width' => 3840, 'height' => 1920, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
    );

    $job->handle();

    // Panoramas take no ordinary preview proxy (VideoPreviewProfile), and the
    // recorded dimensions are what make this asset one.
    expect($asset->fresh()->panoramaClassification()['is_panorama'])->toBeTrue()
        ->and($job->ranFfmpeg)->toBeFalse()
        ->and(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(0);
});

it('still transcodes a probed 2:1 unknown-height source that is forced flat', function () {
    $asset = makeVideoAsset(height: null);
    $asset->update(['panorama_mode' => Asset::PANORAMA_FORCE_FLAT]);

    $job = fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 960, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        sourceProbe: ['width' => 3840, 'height' => 1920, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
    );

    $job->handle();

    expect($job->ranFfmpeg)->toBeTrue()
        ->and(Rendition::where('dam_asset_id', $asset->id)->where('status', Rendition::STATUS_DONE)->count())->toBe(1);
});

it('stops the backfill re-queuing an unknown-height 1080p H.264 video after one job run', function () {
    Queue::fake();
    $asset = makeVideoAsset(height: null);
    $probe = ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'];

    Artisan::call('dam:generate-video-previews');
    Queue::assertPushed(GenerateVideoPreviewProxy::class, 1);

    fakePreviewJob($asset->id, $probe, sourceProbe: $probe)->handle();

    Artisan::call('dam:generate-video-previews');

    expect(Artisan::output())->toContain('queued 0');
    Queue::assertPushed(GenerateVideoPreviewProxy::class, 1);
});

it('lets a --limit=1 backfill progress past probed unknown-height videos to an oversized one', function () {
    Queue::fake();
    $probe = ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'];
    $first = makeVideoAsset(height: null, baseName: 'unknown-a');
    $second = makeVideoAsset(height: null, baseName: 'unknown-b');
    $oversized = makeVideoAsset(height: 2160, baseName: 'oversized');
    $queued = [];

    foreach (range(1, 3) as $run) {
        Artisan::call('dam:generate-video-previews', ['--limit' => 1]);

        $job = Queue::pushed(GenerateVideoPreviewProxy::class)->last();
        $queued[] = $assetId = (new ReflectionProperty($job, 'assetId'))->getValue($job);

        // The worker drains each batch before the next backfill run.
        fakePreviewJob($assetId, $probe, sourceProbe: $probe)->handle();
    }

    expect($queued)->toBe([$first->id, $second->id, $oversized->id]);
});

// R31 — a worker timeout must never leave the rendition RUNNING forever.

it('fails this asset\'s running preview rendition with a path-free worker_timeout from failed()', function () {
    $asset = makeVideoAsset();
    $other = makeVideoAsset(extension: 'mov', mimeType: 'video/quicktime');
    $dispatched = new GenerateVideoPreviewProxy($asset->id);
    $rendition = fn (Asset $for, string $status) => Rendition::create([
        'dam_asset_id' => $for->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => $status,
        'worker'       => 'ffmpeg',
        'progress'     => 0,
        'started_at'   => now()->subHours(2),
        'finished_at'  => $status === Rendition::STATUS_DONE ? now()->subHour() : null,
        // R38: admission records the dispatch's attempt id on its row.
        'parameters'   => ['attempt' => $dispatched->attemptId],
    ]);
    $done = $rendition($asset, Rendition::STATUS_DONE);
    $running = $rendition($asset, Rendition::STATUS_RUNNING);
    $otherRunning = $rendition($other, Rendition::STATUS_RUNNING);

    // The queue calls failed() on a job reconstructed from its serialized payload,
    // never the instance that ran handle().
    unserialize(serialize($dispatched))->failed(new RuntimeException('/var/www/storage/app/clip.mp4: worker killed'));

    $running->refresh();

    expect($running->status)->toBe(Rendition::STATUS_FAILED)
        ->and($running->error)->toBe('worker_timeout')
        ->and($running->error)->not->toContain('/')
        ->and($running->finished_at)->not->toBeNull()
        ->and($done->refresh()->status)->toBe(Rendition::STATUS_DONE)
        ->and($otherRunning->refresh()->status)->toBe(Rendition::STATUS_RUNNING);
});

// R38 — failed() may only fail the rendition its own attempt admitted.

it('leaves a live attempt running when a job whose source probe threw before admission is failed', function () {
    $asset = makeVideoAsset(height: null);
    $a = new GenerateVideoPreviewProxy($asset->id);
    $aRow = null;

    // B passes the early guard (no RUNNING row yet); while B probes the source,
    // A is admitted, then B's source probe throws — B never admits a row.
    $b = fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        onSourceProbe: function () use ($asset, $a, &$aRow): array {
            $aRow = Rendition::create([
                'dam_asset_id' => $asset->id,
                'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
                'status'       => Rendition::STATUS_RUNNING,
                'worker'       => 'ffmpeg',
                'progress'     => 0,
                'started_at'   => now(),
                'parameters'   => ['attempt' => $a->attemptId],
            ]);

            throw new RuntimeException('source probe timed out');
        },
    );

    $thrown = null;

    try {
        $b->handle();
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($b->attemptId)->not->toBe($a->attemptId);

    $b->failed($thrown);

    expect($aRow->refresh()->status)->toBe(Rendition::STATUS_RUNNING)
        ->and($aRow->finished_at)->toBeNull()
        ->and(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(1)
        ->and($b->ranFfmpeg)->toBeFalse();
});

it('ignores an old attempt\'s failure callback that arrives after its stale row was replaced', function () {
    $asset = makeVideoAsset();
    $a = new GenerateVideoPreviewProxy($asset->id);

    $aRow = Rendition::create([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => Rendition::STATUS_RUNNING,
        'worker'       => 'ffmpeg',
        'progress'     => 0,
        'started_at'   => now()->subHours(3),
        'parameters'   => ['attempt' => $a->attemptId],
    ]);

    $observed = null;

    // C's admission reclaims A's stale row; A's late callback lands while C transcodes.
    $c = fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        duringFfmpeg: function () use ($a, $asset, $aRow, &$observed): void {
            unserialize(serialize($a))->failed(new RuntimeException('worker killed'));

            $observed = Rendition::where('dam_asset_id', $asset->id)->whereKeyNot($aRow->id)->sole();
        },
    );

    $c->handle();

    expect($observed)->not->toBeNull()
        ->and($observed->status)->toBe(Rendition::STATUS_RUNNING)
        ->and($observed->parameters['attempt'])->toBe($c->attemptId);

    $aRow->refresh();

    expect($aRow->status)->toBe(Rendition::STATUS_FAILED)
        ->and($aRow->error)->toBe('stale_running')
        ->and($observed->refresh()->status)->toBe(Rendition::STATUS_DONE);
});

it('fails its own attempt from the reconstructed job and a fresh run then regenerates the proxy', function () {
    $asset = makeVideoAsset();
    $dispatched = new GenerateVideoPreviewProxy($asset->id);

    $own = Rendition::create([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => Rendition::STATUS_RUNNING,
        'worker'       => 'ffmpeg',
        'progress'     => 0,
        'started_at'   => now()->subHours(2),
        'parameters'   => [
            'max_width' => 1920,
            'encoder'   => 'libx264',
            'crf'       => 23,
            'attempt'   => $dispatched->attemptId,
        ],
    ]);

    $reconstructed = unserialize(serialize($dispatched));

    expect($reconstructed->attemptId)->toBe($dispatched->attemptId);

    $reconstructed->failed(new RuntimeException('/var/www/storage/app/clip.mp4: worker killed'));

    $own->refresh();

    expect($own->status)->toBe(Rendition::STATUS_FAILED)
        ->and($own->error)->toBe('worker_timeout')
        ->and($own->error)->not->toContain('/')
        ->and($own->finished_at)->not->toBeNull();

    $fresh = fakePreviewJob($asset->id, [
        'width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
    ]);

    $fresh->handle();

    $regenerated = Rendition::where('dam_asset_id', $asset->id)->whereKeyNot($own->id)->sole();

    expect($fresh->ranFfmpeg)->toBeTrue()
        ->and($regenerated->status)->toBe(Rendition::STATUS_DONE)
        ->and($regenerated->parameters['attempt'])->toBe($fresh->attemptId)
        ->and($fresh->attemptId)->not->toBe($dispatched->attemptId)
        ->and($own->refresh()->status)->toBe(Rendition::STATUS_FAILED)
        ->and(AssetFile::where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_PROXY)->count())->toBe(1);
});

it('reclaims a running rendition started past the stale window and admits a new attempt', function () {
    $asset = makeVideoAsset();

    $stale = Rendition::create([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => Rendition::STATUS_RUNNING,
        'worker'       => 'ffmpeg',
        'progress'     => 0,
        'started_at'   => now()->subHours(3),
    ]);

    fakePreviewJob($asset->id, [
        'width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
    ])->handle();

    $stale->refresh();

    expect($stale->status)->toBe(Rendition::STATUS_FAILED)
        ->and($stale->error)->toBe('stale_running')
        ->and($stale->finished_at)->not->toBeNull()
        ->and(Rendition::where('dam_asset_id', $asset->id)
            ->where('status', Rendition::STATUS_DONE)
            ->count())->toBe(1);
});

it('still blocks admission over a running rendition started a minute ago', function (bool $force) {
    $asset = makeVideoAsset();

    $live = Rendition::create([
        'dam_asset_id' => $asset->id,
        'recipe'       => Rendition::RECIPE_PREVIEW_1080P,
        'status'       => Rendition::STATUS_RUNNING,
        'worker'       => 'ffmpeg',
        'progress'     => 0,
        'started_at'   => now()->subMinute(),
    ]);

    $job = fakePreviewJob($asset->id, [
        'width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
    ], force: $force);

    $job->handle();

    expect(Rendition::where('dam_asset_id', $asset->id)->count())->toBe(1)
        ->and($live->refresh()->status)->toBe(Rendition::STATUS_RUNNING)
        ->and($job->ranFfmpeg)->toBeFalse();
})->with([
    'force disabled' => [false],
    'force enabled'  => [true],
]);

// R33 — the source is read through the content-addressed seam (ADR 0009), not the Library path;
// ContentStore::localPath() itself owns the local-symlink-vs-remote-download decision and is
// covered directly by ContentStoreTest. This proves the job reads its CURRENT blob (a re-ingest
// changes what ffmpeg sees) and always releases what it staged.

it('reads the source through the content-addressed blob, never the Library path, and releases every temp file', function (bool $ffmpegOk) {
    $asset = makeVideoAsset(height: null);
    $payload = str_repeat('large-video-chunk', 262_144);
    putAssetContent($asset, $payload);

    $job = fakePreviewJob(
        $asset->id,
        ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
        ffmpegOk: $ffmpegOk,
        sourceProbe: ['width' => 3840, 'height' => 2160, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
    );

    $job->handle();

    // The source probe and ffmpeg both read one staged copy of the CURRENT blob — never the
    // Library path — and it held the complete, re-ingested source bytes.
    expect($job->ffmpegInput)->not->toBeNull()
        ->and($job->ffmpegInput)->not->toBe(Storage::disk(Directory::getAssetDisk())->path($asset->path))
        ->and($job->probedPaths[0])->toBe($job->ffmpegInput)
        ->and($job->ffmpegInputSha)->toBe(hash('sha256', $payload))
        // The job's own output temp file is gone, and so is the seam's staged source copy.
        ->and(array_values(array_filter($job->temporaryPaths, 'file_exists')))->toBe([])
        ->and(file_exists($job->ffmpegInput))->toBeFalse();

    $rendition = Rendition::where('dam_asset_id', $asset->id)
        ->where('recipe', Rendition::RECIPE_PREVIEW_1080P)
        ->first();

    if ($ffmpegOk) {
        expect($rendition->status)->toBe(Rendition::STATUS_DONE)
            ->and(AssetFile::where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_PROXY)->count())->toBe(1);
    } else {
        expect($rendition->status)->toBe(Rendition::STATUS_FAILED)
            ->and($rendition->error)->toBe('ffmpeg_failed');
    }
})->with([
    'ffmpeg succeeds' => [true],
    'ffmpeg fails'    => [false],
]);

it('queues a preview proxy alongside the thumbnail when a video is uploaded', function () {
    Queue::fake();

    $asset = makeVideoAsset();

    (new class($asset) extends ProcessAssetUpload
    {
        public function __construct(private Asset $target)
        {
            parent::__construct($this->target->id);
        }

        public function exposeDispatch(): void
        {
            $this->dispatchThumbnailJob($this->target);
        }
    })->exposeDispatch();

    Queue::assertPushed(GenerateVideoThumbnail::class);
    Queue::assertPushed(GenerateVideoPreviewProxy::class);
});

it('stops serving a proxy of content the entry no longer stands for and admits one of the promoted file', function () {
    Queue::fake();
    $asset = makeVideoAsset();
    $probe = ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'];
    fakePreviewJob($asset->id, $probe)->handle();
    expect($asset->fresh()->playbackFile()['preview_proxy'])->toBeTrue();

    // P3a S6: a promote changes the current source revision; the old proxy names the one before it.
    $other = app(AttachmentLedger::class)->attach($asset, UploadedFile::fake()->createWithContent('other.mp4', 'other-video'), 'alternate', null);
    app(AttachmentLedger::class)->promote($asset, (int) $other->attachment_id);
    expect($asset->fresh()->playbackFile()['preview_proxy'])->toBeFalse();

    $job = fakePreviewJob($asset->id, $probe, sourceProbe: ['width' => 3840, 'height' => 2160, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p']);
    $job->handle();

    expect($job->ranFfmpeg)->toBeTrue()
        ->and(Rendition::where('dam_asset_id', $asset->id)->where('status', Rendition::STATUS_DONE)->latest('id')->value('source_asset_file_id'))->toBe($other->id)
        ->and($asset->fresh()->playbackFile()['preview_proxy'])->toBeTrue();
});

it('retires a proxy made before sources were recorded once the lineage backfill credits it and the entry changes', function () {
    Queue::fake();
    $asset = makeVideoAsset();
    $probe = ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'];
    fakePreviewJob($asset->id, $probe)->handle();
    // As main left every proxy: no source revision.
    Rendition::where('dam_asset_id', $asset->id)->update(['source_asset_file_id' => null]);
    $original = AssetFile::where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_ORIGINAL)->value('id');

    (require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_10_06_000500_add_dam_lineage.php'))->backfillProxySources();
    expect(Rendition::where('dam_asset_id', $asset->id)->value('source_asset_file_id'))->toBe($original)
        ->and($asset->fresh()->playbackFile()['preview_proxy'])->toBeTrue();

    $other = app(AttachmentLedger::class)->attach($asset, UploadedFile::fake()->createWithContent('other.mp4', 'other-video'), 'alternate', null);
    app(AttachmentLedger::class)->promote($asset, (int) $other->attachment_id);
    expect($asset->fresh()->playbackFile()['preview_proxy'])->toBeFalse();

    $job = fakePreviewJob($asset->id, $probe, sourceProbe: ['width' => 3840, 'height' => 2160, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p']);
    $job->handle();
    expect($job->ranFfmpeg)->toBeTrue()
        ->and($asset->fresh()->playbackFile()['preview_proxy'])->toBeTrue();
});

<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\GenerateVideoPreviewProxy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\Merge\AssetMergeService;
use Mothership\DAM\Services\VideoPreviewProfile;
use Mothership\User\Models\Admin;

/*
 * The Mothership feedback #6: "Stacks that contain videos only play the first item's video and also include the
 * 'preview' file as an asset in the stack". A stack's cover plays its own 1080p preview proxy, but every other video
 * member was handed its raw bytes (or nothing) and the generated proxy row itself was listed as a member. The files
 * payload now names which rows are stack members and, for each video member, the source that member plays: its own
 * proxy, its own file when a browser can decode it, or "processing" - never another member's proxy.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->cover = Asset::factory()->create([
        'file_name' => 'cover.mov', 'file_type' => 'video', 'mime_type' => 'video/quicktime', 'extension' => 'mov',
        'path'      => 'assets/Stack/cover.mov',
    ]);
    putAssetContent($this->cover, 'COVER');
    $this->coverFile = AssetFile::query()->where('dam_asset_id', $this->cover->id)->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();

    $this->files = fn (): array => collect($this->getJson(route('admin.dam.assets.files.index', $this->cover->id))->assertOk()->json('files'))->keyBy('id')->all();
    $this->previewRendition = fn (AssetFile $source, AssetFile $proxy) => Rendition::factory()->create([
        'dam_asset_id'          => $this->cover->id,
        'recipe'                => Rendition::RECIPE_PREVIEW_1080P,
        'status'                => Rendition::STATUS_DONE,
        'source_asset_file_id'  => $source->id,
        'output_file_object_id' => $proxy->file_object_id,
        'output_asset_file_id'  => $proxy->id,
        'finished_at'           => now(),
    ]);
});

it('leaves a generated preview proxy out of the stack members', function () {
    $second = attachPreviewFile($this->cover, 'assets/Stack/second.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'second.mp4', 1);
    $proxy = attachPreviewFile($this->cover, 'assets/Stack/cover-preview.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Preview (1080p)', 900, ['codec_name' => 'h264']);
    ($this->previewRendition)($this->coverFile, $proxy);

    $files = ($this->files)();

    expect($files[$this->coverFile->id]['stack_member'])->toBeTrue()
        ->and($files[$second->id]['stack_member'])->toBeTrue()
        ->and($files[$proxy->id]['stack_member'])->toBeFalse();
});

it('plays each video member from its own source and never from another member\'s proxy', function () {
    $second = attachPreviewFile($this->cover, 'assets/Stack/second.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'second.mp4', 1, ['codec_name' => 'h264']);
    $coverProxy = attachPreviewFile($this->cover, 'assets/Stack/cover-preview.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Preview (1080p)', 900, ['codec_name' => 'h264']);
    ($this->previewRendition)($this->coverFile, $coverProxy);

    $files = ($this->files)();
    $coverProxyUrl = route('admin.dam.file.asset-file', ['asset' => $this->cover->id, 'assetFile' => $coverProxy->id]);

    expect($files[$second->id]['playback'])->toMatchArray([
        'state'    => 'ready',
        'url'      => route('admin.dam.file.asset-file', ['asset' => $this->cover->id, 'assetFile' => $second->id]),
        'is_proxy' => false,
    ])->and($files[$second->id]['playback']['url'])->not->toBe($coverProxyUrl);
});

it('plays a member through the preview proxy made from that member', function () {
    $second = attachPreviewFile($this->cover, 'assets/Stack/second.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'second.mov', 1, ['codec_name' => 'hevc']);
    $coverProxy = attachPreviewFile($this->cover, 'assets/Stack/cover-preview.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Preview (1080p)', 900, ['codec_name' => 'h264']);
    $secondProxy = attachPreviewFile($this->cover, 'assets/Stack/second-preview.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Preview (1080p)', 900, ['codec_name' => 'h264']);
    ($this->previewRendition)($this->coverFile, $coverProxy);
    ($this->previewRendition)($second, $secondProxy);

    $files = ($this->files)();

    expect($files[$second->id]['playback'])->toMatchArray([
        'state'    => 'ready',
        'url'      => route('admin.dam.file.asset-file', ['asset' => $this->cover->id, 'assetFile' => $secondProxy->id]),
        'mime'     => 'video/mp4',
        'is_proxy' => true,
    ]);
});

it('reports preview processing for a member no browser can play and that has no proxy yet', function () {
    $second = attachPreviewFile($this->cover, 'assets/Stack/second.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'second.mov', 1, ['codec_name' => 'prores']);
    $coverProxy = attachPreviewFile($this->cover, 'assets/Stack/cover-preview.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Preview (1080p)', 900, ['codec_name' => 'h264']);
    ($this->previewRendition)($this->coverFile, $coverProxy);

    $files = ($this->files)();

    expect($files[$second->id]['playback'])->toMatchArray(['state' => 'processing', 'url' => null, 'is_proxy' => false]);
});

it('queues a preview proxy of its own for a video added to a stack, but not for a proxy or a sidecar', function () {
    config(['mothership.video_preview.enabled' => true]);
    Queue::fake();

    $video = app(AttachmentLedger::class)->attach($this->cover, UploadedFile::fake()->create('second.mp4', 10, 'video/mp4'), AssetFile::ROLE_OTHER, 'second.mp4');
    app(AttachmentLedger::class)->attach($this->cover, UploadedFile::fake()->createWithContent('notes.xmp', 'XMP'), AssetFile::ROLE_SIDECAR, 'notes.xmp');

    Queue::assertPushed(GenerateVideoPreviewProxy::class, 1);
    Queue::assertPushed(GenerateVideoPreviewProxy::class, fn ($job) => (new ReflectionProperty($job, 'memberFileId'))->getValue($job) === $video->id);
});

it('lists the stacked videos without a proxy of their own on a dry run and queues nothing', function () {
    config(['mothership.video_preview.enabled' => true]);
    Queue::fake();
    $bare = attachPreviewFile($this->cover, 'assets/Stack/bare.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'bare.mp4', 1, ['codec_name' => 'h264']);
    $made = attachPreviewFile($this->cover, 'assets/Stack/made.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'made.mov', 2);
    $madeProxy = attachPreviewFile($this->cover, 'assets/Stack/made-preview.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Preview (1080p)', 900, ['codec_name' => 'h264']);
    ($this->previewRendition)($made, $madeProxy);

    $this->artisan('dam:generate-video-previews', ['--members' => true, '--dry-run' => true])
        ->expectsOutputToContain('file '.$bare->id)
        ->doesntExpectOutputToContain('file '.$made->id.' ')
        ->expectsOutputToContain('would queue 1')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

/** The job in member mode with ffmpeg and ffprobe faked: the source probe answers $sourceProbe, the transcode's own probe $outputProbe. */
function memberProxyJob(AssetFile $member, array $sourceProbe, bool $force = false): GenerateVideoPreviewProxy
{
    return new class($member->dam_asset_id, $member->id, $sourceProbe, $force) extends GenerateVideoPreviewProxy
    {
        public bool $ranFfmpeg = false;

        public ?string $ffmpegInputSha = null;

        public function __construct(int $assetId, int $memberId, private array $sourceProbe, bool $force)
        {
            parent::__construct($assetId, $force, $memberId);
        }

        protected function runFfmpeg(array $command): array
        {
            $this->ranFfmpeg = true;
            $this->ffmpegInputSha = hash_file('sha256', $command[array_search('-i', $command, true) + 1]);
            file_put_contents($command[count($command) - 1], "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 100));

            return [true, ''];
        }

        protected function probe(string $path): array
        {
            return $this->ranFfmpeg
                ? ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p', 'audio' => 'aac']
                : $this->sourceProbe;
        }
    };
}

it('makes a stacked video its own proxy from its own bytes and leaves the cover untouched', function () {
    config(['mothership.video_preview.enabled' => true]);
    $second = attachPreviewFile($this->cover, 'assets/Stack/second.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'second.mov', 1, ['codec_name' => 'prores']);
    $coverMeta = $this->cover->fresh()->meta_data;
    $job = memberProxyJob($second, ['width' => 3840, 'height' => 2160, 'codec_name' => 'prores']);

    $job->handle();

    $rendition = Rendition::query()->where('dam_asset_id', $this->cover->id)->sole();
    $files = ($this->files)();
    expect($job->ranFfmpeg)->toBeTrue()
        // attachPreviewFile() stores 'preview-fixture' as the member's bytes; the cover's bytes are 'COVER'.
        ->and($job->ffmpegInputSha)->toBe(hash('sha256', 'preview-fixture'))
        ->and($rendition->status)->toBe(Rendition::STATUS_DONE)
        ->and((int) $rendition->source_asset_file_id)->toBe($second->id)
        ->and($files[$second->id]['playback'])->toMatchArray(['state' => 'ready', 'is_proxy' => true])
        ->and($files[$second->id]['playback']['url'])->toBe(route('admin.dam.file.asset-file', ['asset' => $this->cover->id, 'assetFile' => $rendition->output_asset_file_id]))
        ->and($this->cover->fresh()->meta_data)->toBe($coverMeta);
});

it('still makes a proxy for a member whose stored codec says no browser can play it, even if the file probes as small and playable', function () {
    config(['mothership.video_preview.enabled' => true]);
    $second = attachPreviewFile($this->cover, 'assets/Stack/second.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'second.mov', 1, ['codec_name' => 'prores']);
    $job = memberProxyJob($second, ['width' => 1280, 'height' => 720, 'codec_name' => 'h264']);

    $job->handle();

    expect($job->ranFfmpeg)->toBeTrue();
});

it('skips a small member a browser plays as it is, and a member that already has its own proxy', function () {
    config(['mothership.video_preview.enabled' => true]);
    $small = attachPreviewFile($this->cover, 'assets/Stack/small.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'small.mp4', 1, ['codec_name' => 'h264']);
    $made = attachPreviewFile($this->cover, 'assets/Stack/made.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'made.mov', 2, ['codec_name' => 'hevc']);
    $madeProxy = attachPreviewFile($this->cover, 'assets/Stack/made-preview.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Preview (1080p)', 900, ['codec_name' => 'h264']);
    ($this->previewRendition)($made, $madeProxy);
    $skipSmall = memberProxyJob($small, ['width' => 1280, 'height' => 720, 'codec_name' => 'h264']);
    $skipMade = memberProxyJob($made, ['width' => 3840, 'height' => 2160, 'codec_name' => 'hevc']);

    $skipSmall->handle();
    $skipMade->handle();

    expect($skipSmall->ranFfmpeg)->toBeFalse()
        ->and($skipMade->ranFfmpeg)->toBeFalse()
        ->and(Rendition::query()->where('dam_asset_id', $this->cover->id)->count())->toBe(1);
});

it('queues the proxy of a stacked video only once the merge that linked it in is final, so a split still takes it back', function () {
    config(['mothership.video_preview.enabled' => true]);
    Queue::fake();
    $source = Asset::factory()->create([
        'file_name' => 'linked.mp4', 'file_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'path' => 'assets/Stack/linked.mp4',
    ]);
    putAssetContent($source, 'LINKED');
    $admin = Admin::query()->firstOrFail();

    $merge = app(AssetMergeService::class)->merge($this->cover, [$source->id], true, $admin, 'library')->sole();
    Queue::assertNothingPushed();

    app(AssetMergeService::class)->finalize($merge, $admin, 'library');

    $linked = AssetFile::query()->where('dam_asset_id', $this->cover->id)->where('role', AssetFile::ROLE_OTHER)->where('is_current', true)->sole();
    Queue::assertPushed(GenerateVideoPreviewProxy::class, fn ($job) => (new ReflectionProperty($job, 'memberFileId'))->getValue($job) === $linked->id);
});

it('never plays a stacked member\'s proxy under a cover that has none of its own', function () {
    $this->cover->update(['meta_data' => ['codec_name' => 'prores']]);
    $second = attachPreviewFile($this->cover, 'assets/Stack/second.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'second.mov', 1, ['codec_name' => 'hevc']);
    $secondProxy = attachPreviewFile($this->cover, 'assets/Stack/second-preview.mp4', 'video/mp4', AssetFile::ROLE_PROXY, 'Preview (1080p)', 900, ['codec_name' => 'h264']);
    ($this->previewRendition)($second, $secondProxy);

    expect($this->cover->fresh()->playbackFile())->toBeNull();
});

it('makes no proxy for a linked video while its merge can still be split, so the split takes the slot back', function () {
    config(['mothership.video_preview.enabled' => true]);
    Queue::fake();
    $source = Asset::factory()->create([
        'file_name' => 'linked.mp4', 'file_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'path' => 'assets/Stack/linked.mp4',
    ]);
    putAssetContent($source, 'LINKED');
    $admin = Admin::query()->firstOrFail();
    $service = app(AssetMergeService::class);
    $merge = $service->merge($this->cover, [$source->id], true, $admin, 'library')->sole();
    $linked = AssetFile::query()->where('dam_asset_id', $this->cover->id)->where('role', AssetFile::ROLE_OTHER)->where('is_current', true)->sole();
    $oversized = ['width' => 3840, 'height' => 2160, 'codec_name' => 'h264'];

    $this->artisan('dam:generate-video-previews', ['--members' => true, '--dry-run' => true])->doesntExpectOutputToContain('file '.$linked->id)->assertSuccessful();
    $early = memberProxyJob($linked, $oversized);
    $early->handle();

    expect($early->ranFfmpeg)->toBeFalse()
        ->and(Rendition::query()->where('dam_asset_id', $this->cover->id)->count())->toBe(0);

    $service->split($merge, [], $admin, 'library');
    expect(AssetFile::query()->where('dam_asset_id', $this->cover->id)->where('role', AssetFile::ROLE_OTHER)->count())->toBe(0);

    // A second merge, finalized: the proxy is now allowed.
    $merge = $service->merge($this->cover, [$source->id], true, $admin, 'library')->sole();
    $service->finalize($merge, $admin, 'library');
    $linked = AssetFile::query()->where('dam_asset_id', $this->cover->id)->where('role', AssetFile::ROLE_OTHER)->where('is_current', true)->sole();
    $late = memberProxyJob($linked, $oversized);
    $late->handle();

    expect($late->ranFfmpeg)->toBeTrue();
});

/*
 * Follow-up to feedback #6 (QuickTime/HEVC): Chrome's canPlayType('video/quicktime') is '' and HEVC is not decoded
 * everywhere, so a .mov or HEVC member is never "playable as it is": it waits for its own proxy.
 */
it('holds a QuickTime member that has no stored codec on its own proxy instead of calling it playable', function () {
    $mov = attachPreviewFile($this->cover, 'assets/Stack/hevc.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'hevc.mov', 1);
    $mp4 = attachPreviewFile($this->cover, 'assets/Stack/plain.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'plain.mp4', 2);
    $hevcMp4 = attachPreviewFile($this->cover, 'assets/Stack/hevc.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'hevc.mp4', 3, ['codec_name' => 'hevc']);

    $files = ($this->files)();

    expect($files[$mov->id]['playback'])->toMatchArray(['state' => 'processing', 'url' => null, 'is_proxy' => false])
        ->and($files[$hevcMp4->id]['playback'])->toMatchArray(['state' => 'processing', 'url' => null])
        ->and($files[$mp4->id]['playback'])->toMatchArray(['state' => 'ready', 'is_proxy' => false]);
});

it('keeps playing a QuickTime member\'s own bytes when no proxy is coming: its merge can still split, or the transcode failed', function () {
    $failed = attachPreviewFile($this->cover, 'assets/Stack/failed.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'failed.mov', 1);
    Rendition::factory()->create([
        'dam_asset_id'         => $this->cover->id, 'recipe' => Rendition::RECIPE_PREVIEW_1080P, 'status' => Rendition::STATUS_FAILED,
        'source_asset_file_id' => $failed->id,
    ]);
    $source = Asset::factory()->create([
        'file_name' => 'linked.mov', 'file_type' => 'video', 'mime_type' => 'video/quicktime', 'extension' => 'mov', 'path' => 'assets/Stack/linked.mov',
    ]);
    putAssetContent($source, 'LINKED');
    app(AssetMergeService::class)->merge($this->cover, [$source->id], true, Admin::query()->firstOrFail(), 'library')->sole();
    $linked = AssetFile::query()->where('dam_asset_id', $this->cover->id)->where('role', AssetFile::ROLE_OTHER)->where('is_current', true)->where('id', '!=', $failed->id)->sole();

    $files = ($this->files)();

    expect($files[$failed->id]['playback'])->toMatchArray(['state' => 'ready', 'is_proxy' => false])
        ->and($files[$linked->id]['playback'])->toMatchArray(['state' => 'ready', 'is_proxy' => false])
        ->and($files[$linked->id]['playback']['url'])->toBe(route('admin.dam.file.asset-file', ['asset' => $this->cover->id, 'assetFile' => $linked->id]));
});

it('lists a QuickTime member with no stored codec on the backfill dry run, and not a plain H.264 MP4 that plays as it is', function () {
    config(['mothership.video_preview.enabled' => true]);
    Queue::fake();
    $mov = attachPreviewFile($this->cover, 'assets/Stack/hevc.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'hevc.mov', 1);

    $this->artisan('dam:generate-video-previews', ['--members' => true, '--dry-run' => true])
        ->expectsOutputToContain('file '.$mov->id)
        ->expectsOutputToContain('would queue 1')
        ->assertSuccessful();

    $this->artisan('dam:generate-video-previews', ['--members' => true])->assertSuccessful();
    Queue::assertPushed(GenerateVideoPreviewProxy::class, fn ($job) => (new ReflectionProperty($job, 'memberFileId'))->getValue($job) === $mov->id);
});

it('transcodes a small QuickTime member even when the probe says H.264, and an HEVC MP4 even when stored as plain', function () {
    config(['mothership.video_preview.enabled' => true]);
    $mov = attachPreviewFile($this->cover, 'assets/Stack/small.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'small.mov', 1);
    $hevc = attachPreviewFile($this->cover, 'assets/Stack/hevc.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'hevc.mp4', 2);
    $plain = attachPreviewFile($this->cover, 'assets/Stack/plain.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'plain.mp4', 3);
    $movJob = memberProxyJob($mov, ['width' => 1280, 'height' => 720, 'codec_name' => 'h264']);
    $hevcJob = memberProxyJob($hevc, ['width' => 1280, 'height' => 720, 'codec_name' => 'hevc']);
    $plainJob = memberProxyJob($plain, ['width' => 1280, 'height' => 720, 'codec_name' => 'h264']);

    $movJob->handle();
    $hevcJob->handle();
    $plainJob->handle();

    expect($movJob->ranFfmpeg)->toBeTrue()
        ->and($hevcJob->ranFfmpeg)->toBeTrue()
        ->and($plainJob->ranFfmpeg)->toBeFalse();
});

it('wants a proxy for a small QuickTime or HEVC cover, not for a small H.264 MP4', function () {
    $profile = app(VideoPreviewProfile::class);
    $make = fn (string $name, string $mime, string $ext, array $meta) => Asset::factory()->create([
        'file_name' => $name, 'file_type' => 'video', 'mime_type' => $mime, 'extension' => $ext, 'path' => 'assets/Stack/'.$name,
        'meta_data' => ['ImageHeight' => 720, ...$meta],
    ]);

    expect($profile->needsProxy($make('a.mov', 'video/quicktime', 'mov', [])))->toBeTrue()
        ->and($profile->needsProxy($make('b.mp4', 'video/mp4', 'mp4', ['codec_name' => 'hevc'])))->toBeTrue()
        ->and($profile->needsProxy($make('c.mp4', 'video/mp4', 'mp4', ['codec_name' => 'h264'])))->toBeFalse();
});

it('keeps an HEVC member\'s own bytes playing when no proxy is coming, and waits on its proxy while one is', function () {
    $held = attachPreviewFile($this->cover, 'assets/Stack/held.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'held.mov', 1, ['codec_name' => 'hevc']);
    $failedMp4 = attachPreviewFile($this->cover, 'assets/Stack/failed.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'failed.mp4', 2, ['codec_name' => 'hevc']);
    $retry = attachPreviewFile($this->cover, 'assets/Stack/retry.mp4', 'video/mp4', AssetFile::ROLE_OTHER, 'retry.mp4', 3, ['codec_name' => 'hevc']);
    $prores = attachPreviewFile($this->cover, 'assets/Stack/prores.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'prores.mov', 4, ['codec_name' => 'prores']);
    $osv = attachPreviewFile($this->cover, 'assets/Stack/dji.osv', 'video/mp4', AssetFile::ROLE_OTHER, 'dji.osv', 5, ['codec_name' => 'hevc']);
    foreach ([$failedMp4, $retry, $prores, $osv] as $file) {
        Rendition::factory()->create(['dam_asset_id' => $this->cover->id, 'recipe' => Rendition::RECIPE_PREVIEW_1080P, 'status' => Rendition::STATUS_FAILED, 'source_asset_file_id' => $file->id]);
    }
    Rendition::factory()->create(['dam_asset_id' => $this->cover->id, 'recipe' => Rendition::RECIPE_PREVIEW_1080P, 'status' => Rendition::STATUS_RUNNING, 'source_asset_file_id' => $retry->id]);
    Attachment::query()->whereKey($held->attachment_id)->update(['merge_id' => 1]);

    $files = ($this->files)();

    expect($files[$held->id]['playback'])->toMatchArray(['state' => 'ready', 'is_proxy' => false])
        ->and($files[$failedMp4->id]['playback'])->toMatchArray(['state' => 'ready', 'is_proxy' => false])
        ->and($files[$retry->id]['playback'])->toMatchArray(['state' => 'processing', 'url' => null])
        ->and($files[$prores->id]['playback'])->toMatchArray(['state' => 'processing', 'url' => null])
        ->and($files[$osv->id]['playback'])->toMatchArray(['state' => 'processing', 'url' => null]);
});

it('plays a QuickTime member\'s own bytes when proxy generation is switched off, since none will arrive', function () {
    config(['mothership.video_preview.enabled' => false]);
    $mov = attachPreviewFile($this->cover, 'assets/Stack/off.mov', 'video/quicktime', AssetFile::ROLE_OTHER, 'off.mov', 1, ['codec_name' => 'h264']);

    expect(($this->files)()[$mov->id]['playback'])->toMatchArray([
        'state'    => 'ready',
        'url'      => route('admin.dam.file.asset-file', ['asset' => $this->cover->id, 'assetFile' => $mov->id]),
        'is_proxy' => false,
    ]);
});

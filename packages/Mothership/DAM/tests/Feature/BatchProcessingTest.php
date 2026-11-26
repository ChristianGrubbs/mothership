<?php

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\ProcessBatchItem;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\LineageInput;
use Mothership\DAM\Models\ProcessingBatch;
use Mothership\DAM\Models\ProcessingBatchItem;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Processing\BatchRunner;
use Mothership\DAM\Processing\MediaTools;
use Mothership\DAM\Processing\RecipeFailed;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\Process\Process;

/*
 * P6a (D17, D27, D28): a batch runs one recipe over a selection, one job per item. Every item is decided through
 * the access seam for the requester when the batch starts and again when its job runs; an asset the requester
 * cannot see is skipped as `not_found`, whatever the reason. Outputs are derived files with lineage to the exact
 * source revision, and the original's bytes never change.
 *
 * The recipe cases shell out to the real ffmpeg, ffprobe and vips on fixtures this file generates under
 * storage/framework/testing (CI installs the tools in its media-tools step).
 */

function p6FixturePath(string $name): string
{
    $directory = storage_path('framework/testing/dam-batch-fixtures');
    $path = $directory.'/'.$name;

    if (is_file($path)) {
        return $path;
    }

    is_dir($directory) || mkdir($directory, 0755, true);
    // A 4 s 160x120 clip at 10 fps with a keyframe every second (-g 10) and a tone, so a clip from 1.0 s is
    // keyframe-exact and one from 1.3 s is not.
    $lavfi = ['-f', 'lavfi', '-i'];
    $args = match ($name) {
        'clip.mp4'   => [...$lavfi, 'testsrc=size=160x120:rate=10', ...$lavfi, 'sine=frequency=440:sample_rate=22050', '-t', '4',
            '-c:v', 'libx264', '-g', '10', '-keyint_min', '10', '-sc_threshold', '0', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '64k', '-shortest'],
        'silent.mp4' => [...$lavfi, 'testsrc=size=160x120:rate=10', '-t', '2', '-c:v', 'libx264', '-g', '10', '-pix_fmt', 'yuv420p'],
        'long.mp4'   => [...$lavfi, 'testsrc=size=96x64:rate=24', '-t', '34', '-c:v', 'libx264', '-pix_fmt', 'yuv420p'],
        'photo.png'  => [...$lavfi, 'testsrc=size=320x200', '-frames:v', '1'],
        'small.png'  => [...$lavfi, 'testsrc=size=100x100', '-frames:v', '1'],
        'logo.png'   => [...$lavfi, 'color=c=red:size=40x40', '-frames:v', '1'],
    };
    $process = new Process(['ffmpeg', '-hide_banner', '-nostdin', '-v', 'error', '-y', ...$args, $path]);
    $process->setTimeout(60)->mustRun();

    return $path;
}

/** An asset whose current original is one of the generated fixtures. */
function p6Asset(string $fixture, ?Directory $directory = null, ?string $fileName = null): Asset
{
    $extension = pathinfo($fixture, PATHINFO_EXTENSION);
    $fileName ??= pathinfo($fixture, PATHINFO_FILENAME).'-'.uniqid().'.'.$extension;
    $path = 'assets/Root/'.$fileName;
    Storage::disk(Directory::getAssetDisk())->put($path, file_get_contents(p6FixturePath($fixture)));
    $video = $extension === 'mp4';
    $asset = Asset::factory()->create([
        'file_name' => $fileName, 'path' => $path, 'extension' => $extension,
        'file_type' => $video ? 'video' : 'image', 'mime_type' => $video ? 'video/mp4' : 'image/png', 'title' => null,
    ]);
    ($directory ?? test()->directory)->assets()->attach($asset->id);

    return $asset;
}

function p6Original(Asset $asset): AssetFile
{
    return AssetFile::query()->where('dam_asset_id', $asset->id)->where('role', AssetFile::ROLE_ORIGINAL)->where('is_current', true)->with('fileObject')->firstOrFail();
}

/** sha256 of the asset's current original blob and of its Library path: neither may change. */
function p6Fingerprint(Asset $asset): array
{
    $disk = Storage::disk(Directory::getAssetDisk());
    $object = p6Original($asset)->fileObject;

    return ['blob' => hash('sha256', Storage::disk($object->disk)->get($object->path)), 'library' => hash('sha256', (string) $disk->get($asset->fresh()->path))];
}

/** The local path of an output file's bytes. */
function p6OutputPath(AssetFile $file): string
{
    $object = $file->fileObject()->firstOrFail();

    return Storage::disk($object->disk)->path($object->path);
}

/** ffprobe's streams and format for a file. */
function p6Probe(string $path, bool $countFrames = false): array
{
    $process = new Process(['ffprobe', '-v', 'error', ...($countFrames ? ['-count_frames'] : []), '-show_format', '-show_streams', '-of', 'json', $path]);
    $process->setTimeout(30)->mustRun();

    return json_decode($process->getOutput(), true);
}

function p6Stream(array $probe, string $type): ?array
{
    return collect($probe['streams'] ?? [])->firstWhere('codec_type', $type);
}

/** A member who sees only the folders granted to their role (grants model), with every DAM ACL key. */
function p6Member(Directory ...$granted): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => collect(config('acl'))->pluck('key')->unique()->values()->all()]);

    foreach ($granted as $directory) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();

    return Admin::factory()->create(['role_id' => $role->id]);
}

/** Every item of the batch, keyed by asset id, as the progress panel reads it. */
function p6Items(array $batch): array
{
    return collect($batch['items'])->keyBy('asset_id')->all();
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    // Upload side effects (thumbnails, proxies) stay queued; batch items run inline on the sync connection.
    Queue::fakeExcept([ProcessBatchItem::class]);
    config(['dam.batch_logging' => false]);
    $this->admin = $this->loginAsAdmin();
    $this->directory = Directory::factory()->create();
    $this->store = route('admin.dam.batches.store');
});

it('runs each recipe on a tiny fixture: a valid output, lineage to the source revision, the original unchanged', function (string $fixture, string $recipe, array $params, Closure $check) {
    $source = p6Asset($fixture);
    $revision = p6Original($source);
    $before = p6Fingerprint($source);
    $overlay = $recipe === 'watermark' ? p6Asset('logo.png') : null;
    $overlayBefore = $overlay === null ? null : p6Fingerprint($overlay);

    if ($overlay !== null) {
        $params['overlay_asset_id'] = $overlay->id;
    }

    $batch = $this->postJson($this->store, ['recipe' => $recipe, 'params' => $params, 'asset_ids' => [$source->id]])->assertCreated()->json('batch');
    $shown = $this->getJson(route('admin.dam.batches.show', $batch['id']))->assertOk()->json('batch');
    $item = $shown['items'][0];

    expect($shown['status'])->toBe('finished')
        ->and($item['status'])->toBe('done', json_encode($item))
        ->and($item['output']['asset_id'])->toBe($source->id);

    $output = AssetFile::query()->findOrFail($item['output']['asset_file_id']);
    $rendition = Rendition::query()->where('output_asset_file_id', $output->id)->firstOrFail();

    expect($output->role)->toBe(AssetFile::ROLE_OUTPUT)
        ->and($output->dam_asset_id)->toBe($source->id)
        ->and($output->id)->not->toBe($revision->id)
        ->and($rendition->recipe)->toBe($recipe)
        ->and($rendition->source_asset_file_id)->toBe($revision->id)
        ->and($rendition->status)->toBe(Rendition::STATUS_DONE)
        ->and($rendition->parameters['batch_id'])->toBe($batch['id']);

    $inputs = LineageInput::query()->where('rendition_id', $rendition->id)->get()->keyBy('role');
    expect($inputs[LineageInput::ROLE_SOURCE]->asset_file_id)->toBe($revision->id)
        ->and($inputs[LineageInput::ROLE_SOURCE]->sha256)->toBe($revision->fileObject->sha256);

    if ($overlay !== null) {
        expect($inputs[LineageInput::ROLE_OVERLAY]->asset_file_id)->toBe(p6Original($overlay)->id);
    }

    $check(p6OutputPath($output), $rendition);

    // Byte-identical originals: the source's blob and its Library file, and the overlay's.
    expect(p6Fingerprint($source))->toBe($before)
        ->and(p6Original($source)->id)->toBe($revision->id)
        ->and($overlay === null ? null : p6Fingerprint($overlay))->toBe($overlayBefore)
        ->and(glob(storage_path('app/dam-batch/*')) ?: [])->toBe([]);
})->with([
    'convert image to webp' => ['photo.png', 'convert', ['format' => 'webp', 'quality' => 80], function (string $path) {
        expect(getimagesize($path))->toMatchArray([0 => 320, 1 => 200, 'mime' => 'image/webp']);
    }],
    'convert image to jpg' => ['photo.png', 'convert', ['format' => 'jpg'], function (string $path) {
        expect(getimagesize($path))->toMatchArray([0 => 320, 1 => 200, 'mime' => 'image/jpeg']);
    }],
    'convert video to webm' => ['clip.mp4', 'convert', ['format' => 'webm'], function (string $path) {
        $probe = p6Probe($path);
        expect(p6Stream($probe, 'video')['codec_name'])->toBe('vp9')
            ->and((float) $probe['format']['duration'])->toEqualWithDelta(4.0, 0.2);
    }],
    'resize image to fit' => ['photo.png', 'resize', ['width' => 100], function (string $path) {
        [$width, $height] = getimagesize($path);
        expect($width)->toBe(100)->and($height)->toBeIn([62, 63]);
    }],
    'resize image to cover as png' => ['photo.png', 'resize', ['width' => 64, 'height' => 64, 'fit' => 'cover', 'format' => 'png'], function (string $path) {
        expect(getimagesize($path))->toMatchArray([0 => 64, 1 => 64, 'mime' => 'image/png']);
    }],
    'resize video' => ['clip.mp4', 'resize', ['width' => 80], function (string $path) {
        $video = p6Stream(p6Probe($path), 'video');
        expect([$video['width'], $video['height']])->toBe([80, 60]);
    }],
    'gif' => ['clip.mp4', 'gif', ['start' => 1, 'duration' => 1.5, 'fps' => 10, 'width' => 80], function (string $path) {
        $probe = p6Probe(path: $path, countFrames: true);
        expect(file_get_contents($path, length: 6))->toBe('GIF89a')
            ->and(p6Stream($probe, 'video')['width'])->toBe(80)
            ->and((float) $probe['format']['duration'])->toEqualWithDelta(1.5, 0.15)
            ->and((int) p6Stream($probe, 'video')['nb_read_frames'])->toBe(15);
    }],
    'gif defaults to the full video at 24 fps' => ['long.mp4', 'gif', [], function (string $path) {
        $probe = p6Probe(path: $path, countFrames: true);
        // GIF delays have centisecond precision, so check both the duration and the exact frame count.
        expect((float) $probe['format']['duration'])->toEqualWithDelta(34.0, 0.08)
            ->and((int) p6Stream($probe, 'video')['nb_read_frames'])->toBe(34 * 24);
    }],
    'gif with a start and blank duration runs to the end' => ['clip.mp4', 'gif', ['start' => 1, 'duration' => ''], function (string $path) {
        $probe = p6Probe(path: $path, countFrames: true);
        expect((float) $probe['format']['duration'])->toEqualWithDelta(3.0, 0.08)
            ->and((int) p6Stream($probe, 'video')['nb_read_frames'])->toBe(3 * 24);
    }],
    'still' => ['clip.mp4', 'still', ['time' => 2, 'format' => 'png'], function (string $path) {
        expect(getimagesize($path))->toMatchArray([0 => 160, 1 => 120, 'mime' => 'image/png']);
    }],
    'audio as m4a' => ['clip.mp4', 'audio', ['format' => 'm4a'], function (string $path) {
        $probe = p6Probe($path);
        expect(p6Stream($probe, 'audio')['codec_name'])->toBe('aac')->and(p6Stream($probe, 'video'))->toBeNull();
    }],
    'audio as mp3' => ['clip.mp4', 'audio', ['format' => 'mp3'], function (string $path) {
        expect(p6Stream(p6Probe($path), 'audio')['codec_name'])->toBe('mp3');
    }],
    'waveform' => ['clip.mp4', 'waveform', ['width' => 600, 'height' => 120], function (string $path) {
        expect(getimagesize($path))->toMatchArray([0 => 600, 1 => 120, 'mime' => 'image/png']);
    }],
    'web mp4' => ['clip.mp4', 'web_mp4', ['max_height' => '480'], function (string $path) {
        $probe = p6Probe($path);
        $bytes = file_get_contents($path);
        expect(p6Stream($probe, 'video')['codec_name'])->toBe('h264')
            ->and(p6Stream($probe, 'audio')['codec_name'])->toBe('aac')
            // faststart: the index comes before the media data.
            ->and(strpos($bytes, 'moov'))->toBeLessThan(strpos($bytes, 'mdat'));
    }],
    'clip on a keyframe (stream copy)' => ['clip.mp4', 'clip', ['start' => 1, 'end' => 3], function (string $path, Rendition $rendition) {
        // A copy starts on the keyframe exactly; its end may run long by the fixture's B-frame delay (2 frames).
        expect($rendition->parameters['clip_mode'])->toBe('copy')
            ->and((float) p6Probe($path)['format']['duration'])->toEqualWithDelta(2.1, 0.15);
    }],
    'clip between keyframes (re-encode)' => ['clip.mp4', 'clip', ['start' => 1.3, 'end' => 2.5], function (string $path, Rendition $rendition) {
        expect($rendition->parameters['clip_mode'])->toBe('reencode')
            ->and((float) p6Probe($path)['format']['duration'])->toEqualWithDelta(1.2, 0.15);
    }],
    'watermark an image' => ['photo.png', 'watermark', ['position' => 'top-left', 'scale' => 0.25, 'opacity' => 1], function (string $path) {
        expect(getimagesize($path))->toMatchArray([0 => 320, 1 => 200, 'mime' => 'image/png']);
        // The red mark sits in the top-left margin; the test pattern there is not pure red.
        $image = imagecreatefrompng($path);
        $pixel = imagecolorsforindex($image, imagecolorat($image, 30, 30));
        expect($pixel['red'])->toBeGreaterThan(240)->and($pixel['green'])->toBeLessThan(16)->and($pixel['blue'])->toBeLessThan(16);
    }],
    'watermark and trim a video' => ['clip.mp4', 'watermark', ['position' => 'center', 'start' => 1, 'end' => 3], function (string $path) {
        $probe = p6Probe($path);
        expect([p6Stream($probe, 'video')['width'], p6Stream($probe, 'video')['height']])->toBe([160, 120])
            ->and(p6Stream($probe, 'audio'))->not->toBeNull()
            ->and((float) $probe['format']['duration'])->toEqualWithDelta(2.0, 0.2);
    }],
]);

it('keeps the original and the overlay byte-identical even when a tool writes to the files it was given', function () {
    // Every staged input a tool is handed gets bytes appended before the tool runs, as a misbehaving tool would.
    app()->instance(MediaTools::class, new class extends MediaTools
    {
        public function mustRun(string $tool, array $arguments, string $workdir): string
        {
            foreach ($arguments as $argument) {
                if (str_starts_with($argument, $workdir.'/') && str_starts_with(basename($argument), 'source.') && is_file($argument)) {
                    file_put_contents($argument, 'overwritten', FILE_APPEND);
                }
            }

            return parent::mustRun($tool, $arguments, $workdir);
        }
    });
    $source = p6Asset('clip.mp4');
    $overlay = p6Asset('logo.png');
    $before = [p6Fingerprint($source), p6Fingerprint($overlay)];

    $batch = $this->postJson($this->store, ['recipe' => 'watermark', 'params' => ['overlay_asset_id' => $overlay->id, 'start' => 0, 'end' => 1], 'asset_ids' => [$source->id]])
        ->assertCreated()->json('batch');

    expect($batch['items'][0]['status'])->toBeIn(['done', 'failed'])
        ->and([p6Fingerprint($source), p6Fingerprint($overlay)])->toBe($before);
});

it('saves an output as a new entry in the source folder when asked, with lineage to the source revision', function () {
    $source = p6Asset('photo.png');
    $before = p6Fingerprint($source);

    $batch = $this->postJson($this->store, ['recipe' => 'resize', 'params' => ['width' => 100], 'asset_ids' => [$source->id], 'save_as' => 'new_asset'])
        ->assertCreated()->json('batch');
    $item = $batch['items'][0];
    $copy = Asset::query()->findOrFail($item['output']['asset_id']);
    $rendition = Rendition::query()->where('output_asset_id', $copy->id)->firstOrFail();

    expect($item['status'])->toBe('done')
        ->and($item['output']['url'])->toBe(route('admin.dam.assets.edit', $copy->id))
        ->and($copy->id)->not->toBe($source->id)
        ->and($copy->directories()->pluck('dam_directories.id')->all())->toBe([$this->directory->id])
        ->and($rendition->dam_asset_id)->toBe($source->id)
        ->and($rendition->source_asset_file_id)->toBe(p6Original($source)->id)
        ->and(getimagesize(p6OutputPath(p6Original($copy)))[0])->toBe(100)
        ->and(AssetFile::query()->where('dam_asset_id', $source->id)->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0)
        ->and(p6Fingerprint($source))->toBe($before);
});

it('exports a video\'s audio as a playable MP3 named .mp3 and served as audio/mpeg, the same request the viewer\'s MP3 audio entry sends', function () {
    $source = p6Asset('clip.mp4');

    $attached = $this->postJson($this->store, ['recipe' => 'audio', 'params' => ['format' => 'mp3'], 'asset_ids' => [$source->id]])->assertCreated()->json('batch');
    $item = $attached['items'][0];
    $output = AssetFile::query()->findOrFail($item['output']['asset_file_id']);
    $probe = p6Probe(p6OutputPath($output));

    expect($item['status'])->toBe('done')
        ->and($attached['output_label'])->toBe('Audio (MP3)')
        ->and($probe['format']['format_name'])->toBe('mp3')
        ->and(p6Stream($probe, 'audio')['codec_name'])->toBe('mp3')
        ->and(p6Stream($probe, 'video'))->toBeNull()
        ->and((float) $probe['format']['duration'])->toBeGreaterThan(3.0);

    $download = $this->get($item['output']['url'])->assertOk();
    expect($download->headers->get('Content-Type'))->toContain('audio/mpeg');

    $copy = $this->postJson($this->store, ['recipe' => 'audio', 'params' => ['format' => 'mp3'], 'asset_ids' => [$source->id], 'save_as' => 'new_asset'])->assertCreated()->json('batch');
    $asset = Asset::query()->findOrFail($copy['items'][0]['output']['asset_id']);

    expect($asset->file_name)->toEndWith('.mp3')
        ->and($asset->extension)->toBe('mp3')
        ->and($asset->mime_type)->toBe('audio/mpeg')
        ->and($asset->file_type)->toBe('audio');
});

it('fails an MP3 audio export of a video with no audio stream with the no-audio message and leaves no output', function () {
    $source = p6Asset('silent.mp4');
    $before = p6Fingerprint($source);

    $batch = $this->postJson($this->store, ['recipe' => 'audio', 'params' => ['format' => 'mp3'], 'asset_ids' => [$source->id]])->assertCreated()->json('batch');
    $item = $batch['items'][0];

    expect($item)->toMatchArray(['status' => 'failed', 'error' => 'no_audio_stream', 'output' => null, 'error_message' => 'This file has no audio.'])
        ->and(Rendition::query()->where('dam_asset_id', $source->id)->count())->toBe(0)
        ->and(AssetFile::query()->where('dam_asset_id', $source->id)->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0)
        ->and(p6Fingerprint($source))->toBe($before);

    $copy = $this->postJson($this->store, ['recipe' => 'audio', 'params' => ['format' => 'mp3'], 'asset_ids' => [$source->id], 'save_as' => 'new_asset'])->assertCreated()->json('batch');

    expect($copy['items'][0]['error'])->toBe('no_audio_stream')
        ->and(Asset::query()->count())->toBe(1);
});

it('makes the output from the revision fixed when the batch started, not one uploaded while it waited', function () {
    $source = p6Asset('photo.png');
    $first = p6Original($source);
    Queue::fake();
    $batch = $this->postJson($this->store, ['recipe' => 'resize', 'params' => ['width' => 160], 'asset_ids' => [$source->id]])->assertCreated()->json('batch');

    putAssetContent($source->fresh(), file_get_contents(p6FixturePath('small.png')));
    expect(p6Original($source)->id)->not->toBe($first->id);

    app(BatchRunner::class)->run($batch['items'][0]['id']);
    $item = ProcessingBatchItem::query()->findOrFail($batch['items'][0]['id']);
    $rendition = Rendition::query()->findOrFail($item->rendition_id);

    // 320x200 scaled to 160 wide is 100 high; the newer 100x100 upload would give 160x160.
    expect($item->status)->toBe('done')
        ->and($rendition->source_asset_file_id)->toBe($first->id)
        ->and(getimagesize(p6OutputPath(AssetFile::query()->findOrFail($item->output_asset_file_id)))[1])->toBe(100);
});

it('records each item of a batch of three, one failing with its reason, and retries just that one', function () {
    // The second tool run fails, once.
    app()->instance(MediaTools::class, new class extends MediaTools
    {
        public int $calls = 0;

        public function mustRun(string $tool, array $arguments, string $workdir): string
        {
            if (++$this->calls === 2) {
                throw new RecipeFailed(RecipeFailed::TOOL_FAILED, exitCode: 1);
            }

            return parent::mustRun($tool, $arguments, $workdir);
        }
    });
    $assets = [p6Asset('photo.png'), p6Asset('photo.png'), p6Asset('photo.png')];
    $before = array_map(fn (Asset $asset) => p6Fingerprint($asset), $assets);

    $batch = $this->postJson($this->store, ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => array_map(fn ($a) => $a->id, $assets)])
        ->assertCreated()->json('batch');
    $items = p6Items($batch);

    expect($batch['status'])->toBe('finished')
        ->and($batch['counts'])->toBe(['total' => 3, 'done' => 2, 'failed' => 1, 'skipped' => 0, 'cancelled' => 0])
        ->and($items[$assets[0]->id]['status'])->toBe('done')
        ->and($items[$assets[2]->id]['status'])->toBe('done')
        ->and($items[$assets[1]->id])->toMatchArray([
            'status'        => 'failed', 'error' => 'tool_failed', 'can_retry' => true, 'output' => null, 'attempts' => 1,
            'error_message' => trans('dam::app.admin.batch.errors.tool_failed'),
        ]);

    // Only failed items retry.
    $this->postJson(route('admin.dam.batches.items.retry', [$batch['id'], $items[$assets[0]->id]['id']]))->assertUnprocessable();

    $retried = $this->postJson(route('admin.dam.batches.items.retry', [$batch['id'], $items[$assets[1]->id]['id']]))->assertOk()->json('batch');
    $after = p6Items($retried);

    expect($retried['counts'])->toBe(['total' => 3, 'done' => 3, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0])
        ->and($retried['status'])->toBe('finished')
        ->and($after[$assets[1]->id])->toMatchArray(['status' => 'done', 'error' => null, 'attempts' => 2])
        ->and($after[$assets[0]->id]['attempts'])->toBe(1)
        ->and($after[$assets[2]->id]['attempts'])->toBe(1)
        ->and(AssetFile::query()->whereIn('dam_asset_id', array_map(fn ($a) => $a->id, $assets))->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(3)
        ->and(array_map(fn (Asset $asset) => p6Fingerprint($asset), $assets))->toBe($before);
});

it('skips hidden, trashed and missing assets at enqueue with the one reason a missing asset gets', function () {
    $granted = $this->directory;
    $elsewhere = Directory::factory()->create();
    $visible = p6Asset('photo.png', $granted);
    $hidden = p6Asset('photo.png', $elsewhere);
    $trashed = p6Asset('photo.png', $granted);
    $trashed->delete();
    $missing = Asset::withTrashed()->max('id') + 1000;
    $this->actingAs(p6Member($granted), 'admin');
    Queue::fake();

    $batch = $this->postJson($this->store, ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => [$visible->id, $hidden->id, $trashed->id, $missing]])
        ->assertCreated()->json('batch');
    $items = p6Items($batch);
    $strip = fn (array $item) => collect($item)->except(['id', 'position', 'asset_id'])->all();

    expect($items[$visible->id]['status'])->toBe('queued')
        ->and($batch['counts'])->toMatchArray(['total' => 4, 'skipped' => 3])
        ->and($items[$missing])->toMatchArray(['status' => 'skipped', 'error' => 'not_found', 'asset_name' => null, 'asset_url' => null])
        ->and($strip($items[$hidden->id]))->toBe($strip($items[$missing]))
        ->and($strip($items[$trashed->id]))->toBe($strip($items[$missing]));
    Queue::assertPushed(ProcessBatchItem::class, 1);
});

it('re-checks access when the job runs: an asset hidden or trashed since enqueue is skipped and left untouched', function () {
    $granted = $this->directory;
    $elsewhere = Directory::factory()->create();
    $toHide = p6Asset('photo.png', $granted);
    $toTrash = p6Asset('photo.png', $granted);
    $stays = p6Asset('photo.png', $granted);
    $member = p6Member($granted);
    $this->actingAs($member, 'admin');
    Queue::fake();

    $batch = $this->postJson($this->store, ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => [$toHide->id, $toTrash->id, $stays->id]])
        ->assertCreated()->json('batch');
    expect(collect($batch['items'])->pluck('status')->unique()->all())->toBe(['queued']);

    // While the items wait: one entry moves to a folder the member cannot see, another goes to the trash.
    $toHide->directories()->sync([$elsewhere->id]);
    app(DirectoryPermissionService::class)->flush();
    $before = p6Fingerprint($toHide);
    $toTrash->delete();

    foreach ($batch['items'] as $item) {
        app(BatchRunner::class)->run($item['id']);
    }

    $items = p6Items($this->getJson(route('admin.dam.batches.show', $batch['id']))->assertOk()->json('batch'));

    expect($items[$toHide->id])->toMatchArray(['status' => 'skipped', 'error' => 'not_found', 'output' => null, 'asset_name' => null])
        ->and($items[$toTrash->id])->toMatchArray(['status' => 'skipped', 'error' => 'not_found', 'output' => null, 'asset_name' => null])
        ->and($items[$stays->id]['status'])->toBe('done')
        ->and(Rendition::query()->whereIn('dam_asset_id', [$toHide->id, $toTrash->id])->count())->toBe(0)
        ->and(AssetFile::query()->whereIn('dam_asset_id', [$toHide->id, $toTrash->id])->where('role', AssetFile::ROLE_OUTPUT)->count())->toBe(0)
        ->and(p6Fingerprint($toHide))->toBe($before);
});

it('takes a watermark only from an image the requester can see, at enqueue and at run', function () {
    $granted = $this->directory;
    $elsewhere = Directory::factory()->create();
    $source = p6Asset('photo.png', $granted);
    $hiddenLogo = p6Asset('logo.png', $elsewhere);
    $visibleLogo = p6Asset('logo.png', $granted);
    $video = p6Asset('silent.mp4', $granted);
    $this->actingAs(p6Member($granted), 'admin');
    Queue::fake();
    $send = fn (int $overlay) => $this->postJson($this->store, ['recipe' => 'watermark', 'params' => ['overlay_asset_id' => $overlay], 'asset_ids' => [$source->id]]);

    // A hidden overlay answers exactly as a missing one; a video is no overlay either.
    $hidden = $send($hiddenLogo->id)->assertUnprocessable();
    $missing = $send(Asset::withTrashed()->max('id') + 1000)->assertUnprocessable();
    expect($hidden->json('errors'))->toBe($missing->json('errors'))
        ->and(array_keys($hidden->json('errors')))->toBe(['params.overlay_asset_id']);
    $send($video->id)->assertUnprocessable()->assertJsonValidationErrors('params.overlay_asset_id');
    expect(ProcessingBatch::query()->count())->toBe(0);

    // Visible at enqueue, hidden by the time the job runs: the item fails without an output.
    $batch = $send($visibleLogo->id)->assertCreated()->json('batch');
    $visibleLogo->directories()->sync([$elsewhere->id]);
    app(DirectoryPermissionService::class)->flush();
    app(BatchRunner::class)->run($batch['items'][0]['id']);

    expect(ProcessingBatchItem::query()->findOrFail($batch['items'][0]['id'])->only(['status', 'error']))->toBe(['status' => 'failed', 'error' => 'overlay_unavailable'])
        ->and(Rendition::query()->where('dam_asset_id', $source->id)->count())->toBe(0);
});

it('refuses parameters carrying shell metacharacters, and keeps a hostile file name inert', function () {
    $source = p6Asset('photo.png');

    foreach ([
        ['convert', ['format' => 'png; touch pwned']],
        ['convert', ['format'             => 'png', 'quality' => '80 && id']],
        ['resize', ['width'               => '100;rm -rf /']],
        ['resize', ['width'               => '$(id)']],
        ['gif', ['start'                  => '`id`']],
        ['clip', ['start'                 => '0', 'end' => '1|id']],
        ['watermark', ['overlay_asset_id' => '1 || true']],
        ['watermark', ['overlay_asset_id' => $source->id, 'position' => 'top-left:x=$(id)']],
        ['waveform', ['color'             => "blue'\nid"]],
    ] as [$recipe, $params]) {
        $this->postJson($this->store, ['recipe' => $recipe, 'params' => $params, 'asset_ids' => [$source->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['params.'.array_key_last($params)]);
    }

    $this->postJson($this->store, ['recipe' => 'convert; id', 'params' => ['format' => 'png'], 'asset_ids' => [$source->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['recipe']);
    expect(ProcessingBatch::query()->count())->toBe(0);

    // A file name is never an argument: the tools read a staged copy, and the new entry keeps the name as text.
    $hostile = p6Asset('photo.png', fileName: '$(touch pwned); `touch pwned` \'"; touch pwned.png');
    $batch = $this->postJson($this->store, ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => [$hostile->id], 'save_as' => 'new_asset'])
        ->assertCreated()->json('batch');
    $copy = Asset::query()->findOrFail($batch['items'][0]['output']['asset_id']);

    expect($batch['items'][0]['status'])->toBe('done')
        ->and($copy->file_name)->toStartWith('$(touch pwned); `touch pwned`')
        ->and(file_exists(base_path('pwned')))->toBeFalse()
        ->and(file_exists(public_path('pwned')))->toBeFalse()
        ->and(glob(storage_path('app/dam-batch/*')) ?: [])->toBe([]);
});

it('refuses a selection over the item limit whole', function () {
    config(['dam.batch.max_items' => 2]);
    $ids = [p6Asset('photo.png')->id, p6Asset('photo.png')->id, p6Asset('photo.png')->id];

    $this->postJson($this->store, ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => $ids])
        ->assertUnprocessable()->assertJsonValidationErrors(['asset_ids']);
    expect(ProcessingBatch::query()->count())->toBe(0)
        ->and($this->getJson(route('admin.dam.batches.recipes'))->assertOk()->json('max_items'))->toBe(2);
});

it('cancels the items not yet started, which then never run', function () {
    $assets = [p6Asset('photo.png'), p6Asset('photo.png'), p6Asset('photo.png')];
    Queue::fake();
    $batch = $this->postJson($this->store, ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => array_map(fn ($a) => $a->id, $assets)])
        ->assertCreated()->json('batch');

    // The first finishes before the cancel arrives.
    app(BatchRunner::class)->run($batch['items'][0]['id']);
    $cancelled = $this->postJson(route('admin.dam.batches.cancel', $batch['id']))->assertOk()->json('batch');

    expect($cancelled['status'])->toBe('cancelled')
        ->and($cancelled['counts'])->toBe(['total' => 3, 'done' => 1, 'failed' => 0, 'skipped' => 0, 'cancelled' => 2])
        ->and(collect($cancelled['items'])->pluck('status')->all())->toBe(['done', 'cancelled', 'cancelled']);

    // A job still in the queue for a cancelled item does nothing.
    app(BatchRunner::class)->run($batch['items'][1]['id']);
    expect(ProcessingBatchItem::query()->findOrFail($batch['items'][1]['id'])->only(['status', 'attempts']))->toBe(['status' => 'cancelled', 'attempts' => 0])
        ->and(Rendition::query()->where('dam_asset_id', $assets[1]->id)->count())->toBe(0);
    $this->postJson(route('admin.dam.batches.items.retry', [$batch['id'], $batch['items'][1]['id']]))->assertUnprocessable();
});

it('shows a batch only to the admin who started it; anyone else gets the missing answer', function () {
    $source = p6Asset('photo.png');
    Queue::fake();
    $id = $this->postJson($this->store, ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => [$source->id]])->assertCreated()->json('batch.id');
    $this->loginAsAdmin();
    config(['app.debug' => false]);

    $other = $this->getJson(route('admin.dam.batches.show', $id))->assertNotFound();
    $missing = $this->getJson(route('admin.dam.batches.show', $id + 1000))->assertNotFound();
    expect($other->getContent())->toBe($missing->getContent());
    $this->postJson(route('admin.dam.batches.cancel', $id))->assertNotFound();
});

it('answers REST like the Library: same recipes, same batch, same retry and cancel', function () {
    $headers = $this->getAuthenticationHeaders();
    $apiAdmin = Admin::query()->latest('id')->firstOrFail();
    $source = p6Asset('photo.png');
    $broken = p6Asset('photo.png');

    expect($this->getJson(route('admin.api.dam.batches.recipes'), $headers)->assertOk()->json('data'))
        ->toBe($this->getJson(route('admin.dam.batches.recipes'))->assertOk()->json());

    app()->instance(MediaTools::class, new class extends MediaTools
    {
        public int $calls = 0;

        public function mustRun(string $tool, array $arguments, string $workdir): string
        {
            if (++$this->calls === 2) {
                throw new RecipeFailed(RecipeFailed::TOOL_FAILED, exitCode: 1);
            }

            return parent::mustRun($tool, $arguments, $workdir);
        }
    });
    $created = $this->postJson(route('admin.api.dam.batches.store'), ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => [$source->id, $broken->id]], $headers)
        ->assertCreated()->assertJsonPath('success', true)->json('data.batch');
    $batch = ProcessingBatch::query()->findOrFail($created['id']);

    expect($batch->via)->toBe('api')
        ->and((int) $batch->requested_by)->toBe($apiAdmin->id)
        ->and(p6Items($created)[$broken->id]['status'])->toBe('failed');

    $shown = $this->getJson(route('admin.api.dam.batches.show', $batch->id), $headers)->assertOk()->json('data.batch');
    $this->actingAs($apiAdmin, 'admin');
    expect($shown)->toBe($this->getJson(route('admin.dam.batches.show', $batch->id))->assertOk()->json('batch'));

    $retried = $this->postJson(route('admin.api.dam.batches.items.retry', [$batch->id, p6Items($created)[$broken->id]['id']]), [], $headers)->assertOk()->json('data.batch');
    expect($retried['counts']['done'])->toBe(2);
    $this->postJson(route('admin.api.dam.batches.cancel', $batch->id), [], $headers)->assertOk()->assertJsonPath('data.batch.status', 'finished');

    // Validation and the item limit are the same service's.
    config(['dam.batch.max_items' => 1]);
    $this->postJson(route('admin.api.dam.batches.store'), ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => [$source->id, $broken->id]], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['asset_ids']);
    $this->getJson(route('admin.api.dam.batches.show', $batch->id + 1000), $headers)->assertNotFound();

    // A file trashed since is no longer named or linked.
    $source->delete();
    expect(p6Items($this->getJson(route('admin.api.dam.batches.show', $batch->id), $headers)->assertOk()->json('data.batch'))[$source->id])
        ->toMatchArray(['status' => 'done', 'asset_name' => null, 'asset_url' => null, 'output' => null]);
});

it('fails the queued items and answers 503 when the queue refuses the jobs, instead of leaving a running batch', function () {
    $asset = p6Asset('photo.png');
    $refusing = Mockery::mock(Dispatcher::class);
    $refusing->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    $refusing->shouldReceive('dispatchNow')->andThrow(new RuntimeException('queue down'));
    app()->instance(Dispatcher::class, $refusing);

    $this->postJson($this->store, ['recipe' => 'convert', 'params' => ['format' => 'webp'], 'asset_ids' => [$asset->id]])->assertStatus(503);

    $batch = ProcessingBatch::query()->latest('id')->first();
    expect($batch)->not->toBeNull()
        ->and($batch->items()->where('status', '!=', 'failed')->count())->toBe(0);
});

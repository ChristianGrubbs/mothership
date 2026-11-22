<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiBridgeUnavailable;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\AiIndex\AiSamplerUnavailable;
use Mothership\DAM\AiIndex\MediaSample;
use Mothership\DAM\AiIndex\MediaSampler;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Jobs\GenerateDngPreview;
use Mothership\DAM\Jobs\GenerateGameAssetThumbnail;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Support\DerivativeImages;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['ai-index.api' => 'http://bridge.test', 'ai-index.index_enabled' => false, 'ai-index.image_edge' => 100]);
});

/** An asset with a stored original of `$extension`; the sha is what its derivatives are keyed by. */
function assetWithOriginal(string $extension, string $fileType = 'image', ?string $bytes = null): array
{
    $disk = Directory::getAssetDisk();
    $store = app(ContentStore::class);
    $staged = $store->stagingPath($extension);
    Storage::disk($disk)->put($staged, $bytes ?? random_bytes(16));
    $object = $store->adoptStored(disk: $disk, sourcePath: $staged, mimeType: 'application/octet-stream', extension: $extension);

    $asset = (new Asset([
        'file_name' => "file.{$extension}", 'file_type' => $fileType, 'file_size' => 16,
        'mime_type' => 'application/octet-stream', 'extension' => $extension, 'path' => "assets/Root/file-{$extension}-".uniqid().".{$extension}",
    ]))->withGraphContent($object);
    $asset->save();

    return [$asset, $object->sha256];
}

/** A well-formed run id for a row, as the store would mint one. */
function runId(string $suffix): string
{
    return '00000000-0000-4000-8000-0000000000'.str_pad($suffix, 2, '0', STR_PAD_LEFT);
}

function jpegBytes(): string
{
    $image = imagecreatetruecolor(200, 120);
    imagefilledrectangle($image, 0, 0, 199, 119, imagecolorallocate($image, 30, 90, 200));
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function fakeBridge(): void
{
    $GLOBALS['ai_bridge_down'] = false;
    Http::fake([
        'bridge.test/caption' => Http::response(['caption' => 'A raw photo.', 'tags' => [], 'keywords' => ['photo'], 'visible_text' => '', 'model' => 'm', 'prompt_version' => 'p']),
        'bridge.test/embed'   => fn ($request) => Http::response(['vectors' => array_map(fn () => [1, 0, 0], $request['texts']), 'model' => 'e']),
        'bridge.test/health'  => fn () => $GLOBALS['ai_bridge_down'] ? Http::response('', 500) : Http::response(['ok' => true]),
    ]);
}

function runJob(int $assetId): void
{
    // As the queue would: a job that carries the token of the run it belongs to.
    (new AiIndexAsset($assetId, app(AiIndexStore::class)->stamp($assetId)))->handle(app(AiIndexStore::class), app(MediaSampler::class), app(AiBridgeClient::class));
}

it('samples the DNG preview and the 3D model capture a file has instead of finding nothing', function () {
    [$dng, $dngSha] = assetWithOriginal('dng');
    [$glb, $glbSha] = assetWithOriginal('glb', 'document');
    $sampler = app(MediaSampler::class);

    expect($sampler->sample($dng)->images)->toBe([])
        ->and($sampler->sample($glb)->images)->toBe([]);

    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::dngPreview($dngSha), jpegBytes());
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::modelCapture($glbSha, $glb->id), jpegBytes());

    expect($sampler->sample($dng)->images)->toHaveCount(1)
        ->and(getimagesizefromstring($sampler->sample($dng)->images[0])['mime'])->toBe('image/jpeg')
        ->and($sampler->sample($dng)->sha256)->toBe($dngSha)
        ->and($sampler->sample($glb)->images)->toHaveCount(1);
});

it('records why a file got no description instead of a silent done, and asks for the missing DNG preview', function () {
    fakeBridge();
    Queue::fake();
    [$dng] = assetWithOriginal('dng');

    runJob($dng->id);

    $row = DB::table('dam_ai_index')->where('asset_id', $dng->id)->first();
    expect($row->status)->toBe('done')->and($row->caption)->toBeNull()->and($row->error)->toBe(AiEligibility::NO_IMAGE);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/caption'));
    Queue::assertPushed(GenerateDngPreview::class, fn ($job) => true);
});

it('indexes a file again once, when its preview first appears after a run that found no image', function () {
    fakeBridge();
    [$dng, $sha] = assetWithOriginal('dng');
    Queue::fake();
    runJob($dng->id);
    config(['ai-index.index_enabled' => true]);

    // The preview lands: the stale "no image" row is queued again, once.
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::dngPreview($sha), jpegBytes());
    AiIndexDispatcher::derivativeReady($dng->id);
    AiIndexDispatcher::derivativeReady($dng->id);

    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1);
    expect(DB::table('dam_ai_index')->where('asset_id', $dng->id)->first())->status->toBe('pending')->error->toBeNull();

    Queue::fake();
    runJob($dng->id);
    expect(DB::table('dam_ai_index')->where('asset_id', $dng->id)->first())->status->toBe('done')->caption->toBe('A raw photo.');
    Queue::assertNothingPushed();
});

it('queues a run that may have missed a preview written while it worked, but never a finished described file', function () {
    Queue::fake();
    [$glb] = assetWithOriginal('glb', 'document');
    config(['ai-index.index_enabled' => true]);
    DB::table('dam_ai_index')->insert(['asset_id' => $glb->id, 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);

    AiIndexDispatcher::derivativeReady($glb->id);
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1);

    Queue::fake();
    DB::table('dam_ai_index')->where('asset_id', $glb->id)->update(['status' => 'done', 'caption' => 'A cube.']);
    AiIndexDispatcher::derivativeReady($glb->id);
    Queue::assertNothingPushed();
});

it('re-queues indexing when a 3D capture is stored', function () {
    [$glb] = assetWithOriginal('glb', 'document');
    config(['ai-index.index_enabled' => true]);
    DB::table('dam_ai_index')->insert(['asset_id' => $glb->id, 'status' => 'done', 'error' => AiEligibility::NO_IMAGE, 'created_at' => now(), 'updated_at' => now()]);
    Queue::fake();

    $object = app(ContentStore::class)->current($glb);
    $result = app(ModelFileThumbnailService::class)->store(object: $object, upload: UploadedFile::fake()->image('capture.png', 64, 64), assetId: $glb->id);

    expect($result['stored'])->toBeTrue();
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1);
    expect(AiEligibility::source($glb->fresh())['state'])->toBe(AiEligibility::ELIGIBLE);
});

it('creates a pending record the moment indexing is queued, once', function () {
    Queue::fake();
    config(['ai-index.index_enabled' => true]);

    $asset = Asset::factory()->create();

    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('status'))->toBe('pending');
    Queue::assertPushedOn('ai-index', AiIndexAsset::class, fn ($job) => $job->assetId === $asset->id);

    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'done', 'caption' => 'A caption.']);
    AiIndexDispatcher::full($asset->id);
    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('status'))->toBe('done');
});

it('names the state of a file: what the AI can describe, what waits for a preview, and what it cannot', function (string $extension, string $fileType, string $state, string $reason) {
    [$asset] = assetWithOriginal($extension, $fileType);

    $result = app(AiEligibility::class)->forAsset($asset);

    expect($result['state'])->toBe($state)->and($result['reason'])->toBe($reason)
        ->and($result['can_generate'])->toBe($state === AiEligibility::ELIGIBLE)
        ->and($result['can_rename'])->toBe($state === AiEligibility::ELIGIBLE);
    expect($result['message'] === null)->toBe($state === AiEligibility::ELIGIBLE);
})->with([
    'jpeg'      => ['jpg', 'image', 'eligible', 'original'],
    'video'     => ['mp4', 'video', 'eligible', 'original'],
    'pdf'       => ['pdf', 'document', 'eligible', 'original'],
    'dng'       => ['dng', 'image', 'awaiting_preview', 'dng_preview'],
    '3d model'  => ['glb', 'document', 'awaiting_preview', 'model_capture'],
    'blender'   => ['blend', 'document', 'awaiting_preview', 'embedded_thumbnail'],
    'svg'       => ['svg', 'image', 'unsupported', 'file_type'],
    'heic'      => ['heic', 'image', 'awaiting_preview', 'thumbnail'],
    'zip'       => ['zip', 'web', 'unsupported', 'file_type'],
    'audio'     => ['mp3', 'audio', 'unsupported', 'file_type'],
]);

it('moves a file through queued, running, failed and service-unavailable without calling any of them unsupported', function () {
    config(['ai-index.health_check' => true]);
    fakeBridge();
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    config(['ai-index.index_enabled' => true]);
    $state = function () use ($asset) {
        Cache::flush();

        return app(AiEligibility::class)->forAsset($asset);
    };

    DB::table('dam_ai_index')->where('asset_id', $asset->id)->delete();
    expect($state())->state->toBe('eligible')->can_generate->toBeTrue();

    DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'pending', 'run_token' => runId('01'), 'created_at' => now(), 'updated_at' => now()]);
    expect($state())->state->toBe('queued')->can_generate->toBeFalse()->message->toContain('being created');

    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'running']);
    expect($state())->state->toBe('running');

    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'failed', 'attempts' => 4, 'error' => 'AiBridgeUnavailable']);
    expect($state())->state->toBe('failed')->reason->toBe('unreachable')->will_retry->toBeTrue()->can_generate->toBeTrue()
        ->message->toContain('retry automatically');

    // Out of nightly tries, or indexing off: the text promises nothing.
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['attempts' => 12]);
    expect($state())->will_retry->toBeFalse()->message->not->toContain('automatically');

    // The bridge itself is down: temporary, no button that cannot work.
    $GLOBALS['ai_bridge_down'] = true;
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'done', 'error' => AiEligibility::NO_IMAGE]);
    expect($state())->state->toBe('service_unavailable')->reason->toBe('unreachable')->can_generate->toBeFalse()->can_rename->toBeFalse();

    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['caption' => 'A caption.']);
    expect($state())->state->toBe('eligible')->can_rename->toBeFalse();
});

it('probes the bridge health briefly and caches the answer', function () {
    config(['ai-index.health_check' => true]);
    Cache::flush();
    Http::fake(['bridge.test/health' => Http::response(['ok' => true])]);

    expect(app(AiBridgeClient::class)->health())->toBeTrue()
        ->and(app(AiBridgeClient::class)->health())->toBeTrue();
    Http::assertSentCount(1);

    config(['ai-index.api' => null]);
    expect(app(AiBridgeClient::class)->health())->toBeFalse();
});

it('reads a bridge whose Ollama lists no models as down, and a bridge that sends no model fields as up', function (array $body, bool $up) {
    config(['ai-index.health_check' => true]);
    Cache::flush();
    Http::fake(['bridge.test/health' => Http::response($body)]);

    expect(app(AiBridgeClient::class)->health())->toBe($up);
})->with([
    'both models listed'                         => [['ok' => true, 'ollama' => true, 'models' => ['caption' => true, 'embed' => true]], true],
    'Ollama lists no models (2026-11-22 outage)' => [['ok' => true, 'ollama' => true, 'models' => ['caption' => false, 'embed' => false]], false],
    'caption model missing'                      => [['ok' => true, 'ollama' => true, 'models' => ['caption' => false, 'embed' => true]], false],
    'embed model missing'                        => [['ok' => true, 'ollama' => true, 'models' => ['caption' => true, 'embed' => false]], false],
    'Ollama not answering, no model fields'      => [['ok' => true, 'ollama' => false], false],
    'older bridge without model fields'          => [['ok' => true], true],
]);

it('refuses to describe or rename a file the AI cannot see, and answers the bulk question per file', function () {
    $this->loginAsAdmin();
    fakeBridge();
    Queue::fake();
    [$svg] = assetWithOriginal('svg');
    [$dng] = assetWithOriginal('dng');
    [$jpg] = assetWithOriginal('jpg');

    $this->postJson(route('admin.dam.assets.ai-tags.describe', $svg->id))->assertStatus(422)
        ->assertJsonPath('eligibility.state', 'unsupported')->assertJsonPath('message', "AI descriptions aren't available for this file type.");
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $dng->id))->assertStatus(422)->assertJsonPath('eligibility.state', 'awaiting_preview');
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $svg->id])->assertStatus(422)->assertJsonPath('eligibility.state', 'unsupported');
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $dng->id])->assertStatus(422);
    Queue::assertNotPushed(AiIndexAsset::class, fn ($job) => in_array($job->assetId, [$svg->id, $dng->id], true));

    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $jpg->id])->assertStatus(409)->assertJsonPath('needs_description', true);
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $jpg->id))->assertOk();

    $bulk = $this->postJson(route('admin.dam.assets.ai-tags.eligibility'), ['ids' => [$svg->id, $dng->id, $jpg->id, 999999]])->assertOk()
        ->assertJsonPath('available', true);
    expect(collect($bulk->json('eligibility'))->map->state->all())->toBe([
        $svg->id => 'unsupported', $dng->id => 'awaiting_preview', $jpg->id => 'queued',
    ]);

    $card = $this->getJson(route('admin.dam.assets.ai-tags.show', $svg->id))->assertOk();
    expect($card->json('eligibility.state'))->toBe('unsupported')->and($card->json('eligibility.can_generate'))->toBeFalse();
});

it('does not count the type-only hint as eligibility', function () {
    expect(AiEligibility::typeMayBeDescribed('svg', 'image'))->toBeFalse()
        ->and(AiEligibility::typeMayBeDescribed('zip', 'web'))->toBeFalse()
        ->and(AiEligibility::typeMayBeDescribed('DNG', 'image'))->toBeTrue()
        ->and(AiEligibility::typeMayBeDescribed('mov', 'video'))->toBeTrue();
});

// --- Review round: provenance, listing hint, game-asset posters, no_image, claims, health, stacks, legacy grid ---

function putPicture(string $path, ?string $bytes = null): void
{
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes ?? jpegBytes());
}

it('does not take a placeholder or a corrupt thumbnail for a picture of the file', function () {
    $this->loginAsAdmin();
    fakeBridge();
    Queue::fake();
    [$zip] = assetWithOriginal('zip', 'document');
    [$glb, $glbSha] = assetWithOriginal('glb', 'document');
    [$dng, $dngSha] = assetWithOriginal('dng');

    // A valid image at a path the generators never write: a type icon, someone else's art.
    putPicture('thumbnails/placeholder.jpg');
    $zip->forceFill(['meta_data' => ['thumbnail_path' => 'thumbnails/placeholder.jpg']])->saveQuietly();
    $glb->forceFill(['meta_data' => ['thumbnail_path' => 'thumbnails/placeholder.jpg']])->saveQuietly();
    $eligibility = app(AiEligibility::class);

    expect($eligibility->forAsset($zip->fresh())['state'])->toBe('unsupported')
        ->and($eligibility->forAsset($glb->fresh())['state'])->toBe('awaiting_preview');
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $zip->id))->assertStatus(422)->assertJsonPath('eligibility.state', 'unsupported');
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $zip->id])->assertStatus(422);

    // The capture's own name, but not an image.
    $own = ModelThumbnailService::generatedPath($glb, $glbSha);
    putPicture($own, 'not an image');
    $glb->forceFill(['meta_data' => ['thumbnail_path' => $own]])->saveQuietly();
    expect($eligibility->forAsset($glb->fresh())['state'])->toBe('awaiting_preview');
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $glb->id))->assertStatus(422);

    putPicture($own);
    expect($eligibility->forAsset($glb->fresh())['state'])->toBe('eligible');

    putPicture(DerivativeImages::dngPreview($dngSha), 'corrupt');
    expect($eligibility->forAsset($dng)['state'])->toBe('awaiting_preview');
    putPicture(DerivativeImages::dngPreview($dngSha));
    expect($eligibility->forAsset($dng)['state'])->toBe('eligible');
});

it('lets a real grid thumbnail make a HEIC eligible and offers the listing hint for it', function () {
    [$heic, $sha] = assetWithOriginal('heic');

    expect(app(AiEligibility::class)->forAsset($heic))->state->toBe('awaiting_preview')->reason->toBe('thumbnail');

    putPicture(DerivativeImages::thumbnail($sha, 'heic'), imagewebp_bytes());
    expect(app(AiEligibility::class)->forAsset($heic))->state->toBe('eligible');
    expect(app(MediaSampler::class)->sample($heic)->images)->toHaveCount(1);

    // The cheap listing hint: uncertain is possible, only the certain cases are false.
    expect(AiEligibility::typeMayBeDescribed('heic', 'image'))->toBeTrue()
        ->and(AiEligibility::typeMayBeDescribed('tiff', 'image'))->toBeTrue()
        ->and(AiEligibility::typeMayBeDescribed('psd', 'image'))->toBeTrue()
        ->and(AiEligibility::typeMayBeDescribed('svg', 'image'))->toBeFalse()
        ->and(AiEligibility::typeMayBeDescribed('mp3', 'audio'))->toBeFalse();
});

function imagewebp_bytes(): string
{
    $image = imagecreatetruecolor(40, 40);
    ob_start();
    imagewebp($image);

    return (string) ob_get_clean();
}

it('carries the listing hint on Explorer and datagrid records', function () {
    $this->loginAsAdmin();
    [$heic] = assetWithOriginal('heic');
    [$svg] = assetWithOriginal('svg');
    $record = fn ($rows, $id) => collect($rows)->firstWhere('id', $id);

    $folder = Directory::factory()->create(['name' => 'Hints', 'parent_id' => null]);
    $heic->directories()->attach($folder->id);
    $svg->directories()->attach($folder->id);
    $assets = $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $folder->id]))->assertOk()->json('assets');

    expect($record($assets, $heic->id)['ai_possible'] ?? null)->toBeTrue()
        ->and($record($assets, $svg->id)['ai_possible'] ?? null)->toBeFalse();
});

it('tells a Blender poster that is gone from a file that has no embedded thumbnail', function () {
    fakeBridge();
    Queue::fake();
    [$blend, $sha] = assetWithOriginal('blend', 'document');
    $facts = fn (bool $has) => $blend->forceFill(['meta_data' => ['game_asset' => ['kind' => 'blender', 'sha256' => $sha, 'has_thumbnail' => $has]]])->saveQuietly();
    $eligibility = app(AiEligibility::class);

    $facts(false);
    expect($eligibility->forAsset($blend->fresh()))->state->toBe('unsupported')->reason->toBe('no_embedded_thumbnail');

    // Read, it has a thumbnail, and the poster was deleted: made again, and indexed once it lands.
    $facts(true);
    expect($eligibility->forAsset($blend->fresh()))->state->toBe('awaiting_preview')->reason->toBe('poster_missing');
    runJob($blend->id);
    Queue::assertPushed(GenerateGameAssetThumbnail::class, fn ($job) => true);

    putPicture(DerivativeImages::poster($sha));
    expect($eligibility->forAsset($blend->fresh()))->state->toBe('eligible');
});

it('reads a run that found no picture on these very bytes as final, and does not retry it every night', function () {
    fakeBridge();
    Queue::fake();
    [$jpg, $sha] = assetWithOriginal('jpg');   // random bytes: not a decodable image
    config(['ai-index.index_enabled' => true]);

    runJob($jpg->id);

    $row = DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first();
    expect($row->status)->toBe('done')->and($row->error)->toBe('no_image')->and($row->content_sha256)->toBe($sha);
    $state = app(AiEligibility::class)->forAsset($jpg);
    expect($state)->state->toBe('failed')->reason->toBe('no_image')->can_generate->toBeFalse()->can_rename->toBeFalse()->will_retry->toBeFalse();

    $attempts = DB::table('dam_ai_index')->where('asset_id', $jpg->id)->value('attempts');
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();
    Queue::assertNotPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $jpg->id);
    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->value('attempts'))->toBe($attempts);

    // A legacy done-without-caption row (no reason recorded) is looked at once more; new bytes are always looked at.
    DB::table('dam_ai_index')->where('asset_id', $jpg->id)->update(['error' => null]);
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();
    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $jpg->id);

    Queue::fake();
    DB::table('dam_ai_index')->where('asset_id', $jpg->id)->update(['status' => 'done', 'run_token' => null, 'error' => 'no_image', 'content_sha256' => str_repeat('0', 64)]);
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();
    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $jpg->id);
});

it('queues one run however many callers ask, and takes over a run whose job is gone', function () {
    $this->loginAsAdmin();
    fakeBridge();
    Queue::fake();
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    $store = app(AiIndexStore::class);

    // Queued already (the upload queued it): the bulk dialog's describe must not queue a second.
    DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'pending', 'run_token' => runId('01'), 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $asset->id))->assertOk()->assertJsonPath('ai.status', 'pending');
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $asset->id))->assertOk();
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(0);

    // Racing callers: the conditional update lets exactly one through, and it gets the job's token.
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'failed', 'run_token' => null]);
    $claims = [$store->claim($asset->id), $store->claim($asset->id), $store->claim($asset->id)];
    expect($claims[0])->toBeString()->and($claims[1])->toBeNull()->and($claims[2])->toBeNull()
        ->and(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('run_token'))->toBe($claims[0]);

    $other = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    expect($store->claim($other->id))->toBeString()->and($store->claim($other->id))->toBeNull();
});

it('does not take a run over because it waited in a long backlog, only when its job is gone', function () {
    $this->loginAsAdmin();
    fakeBridge();
    Queue::fake();
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    $store = app(AiIndexStore::class);
    $eligibility = app(AiEligibility::class);
    $token = runId('01');
    // Queued two hours ago behind a bulk backlog: older than any fixed bound, and the job is still in the queue.
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->delete();
    DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'pending', 'run_token' => $token, 'claimed_at' => now()->subHours(2), 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
    DB::table('jobs')->insert(['queue' => 'ai-index', 'payload' => json_encode(['displayName' => AiIndexAsset::class, 'data' => ['command' => 's:36:"token";s:36:"'.$token.'";']]), 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

    expect($eligibility->forAsset($asset))->state->toBe('queued');
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $asset->id))->assertOk();
    expect($store->claim($asset->id))->toBeNull()
        ->and(Queue::pushed(AiIndexAsset::class)->count())->toBe(0);

    // The job is gone (queue flushed): shown as stalled, and the next ask takes the row over with a new token.
    DB::table('jobs')->delete();
    expect($eligibility->forAsset($asset))->state->toBe('failed')->reason->toBe('stalled')->can_generate->toBeTrue();
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $asset->id))->assertOk();
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1)
        ->and(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('run_token'))->not->toBe($token)
        ->and($eligibility->forAsset($asset->fresh()))->state->toBe('queued');

    // A running job is lost only past its own timeout.
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'running', 'claimed_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)]);
    expect($eligibility->forAsset($asset))->state->toBe('running');
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['claimed_at' => now()->subMinutes(11), 'updated_at' => now()->subMinutes(11)]);
    expect($eligibility->forAsset($asset))->state->toBe('failed')->reason->toBe('stalled');
});

it('retires a queued run the moment a newer one takes the file, so it cannot run twice', function () {
    fakeBridge();
    Queue::fake();
    [$jpg] = assetWithOriginal('jpg');
    $store = app(AiIndexStore::class);
    config(['ai-index.index_enabled' => true]);

    $first = $store->stamp($jpg->id);
    $second = $store->stamp($jpg->id);
    $run = fn (string $token) => (new AiIndexAsset($jpg->id, $token))->handle($store, app(MediaSampler::class), app(AiBridgeClient::class));

    // The older job finds the token is not its own: it neither marks the row running nor calls the bridge.
    $run($first);
    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first())->status->toBe('pending')->attempts->toBe(0)->run_token->toBe($second);
    Http::assertNothingSent();

    // The newer one runs; its result ends the run and gives up the token, so a re-delivered copy of it is a no-op too.
    $run($second);
    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first())->status->toBe('done')->run_token->toBeNull();
    $before = DB::table('dam_ai_index')->where('asset_id', $jpg->id)->value('attempts');
    $run($second);
    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->value('attempts'))->toBe($before);
});

it('discards the result of a run that was superseded while the bridge worked', function () {
    Queue::fake();
    config(['ai-index.index_enabled' => true]);
    [$jpg] = assetWithOriginal('jpg', 'image', jpegBytes());
    $store = app(AiIndexStore::class);
    $old = $store->stamp($jpg->id);
    $new = null;

    // While the old run waits on the bridge, a newer run takes the file.
    Http::fake([
        'bridge.test/caption' => function () use ($store, $jpg, &$new) {
            $new = $store->stamp($jpg->id);

            return Http::response(['caption' => 'Old.', 'tags' => [], 'keywords' => [], 'visible_text' => '', 'model' => 'm', 'prompt_version' => 'p']);
        },
        'bridge.test/embed' => fn ($request) => Http::response(['vectors' => array_map(fn () => [1, 0, 0], $request['texts']), 'model' => 'e']),
    ]);
    (new AiIndexAsset($jpg->id, $old))->handle($store, app(MediaSampler::class), app(AiBridgeClient::class));

    $row = DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first();
    expect($new)->not->toBeNull()->and($row->run_token)->toBe($new)->and($row->caption)->toBeNull()->and($row->status)->not->toBe('done');
});

it('never makes concurrent requests wait on a cold or expired health probe, and never calls an unknown service up', function () {
    config(['ai-index.health_check' => true]);
    Cache::flush();
    Http::fake(['bridge.test/health' => Http::response('', 500)]);
    $bridge = app(AiBridgeClient::class);
    $key = 'dam.ai-bridge.reachable.'.md5('http://bridge.test');

    // Another request holds the probe lock and nothing has ever been probed: this one probes nothing and says "unknown".
    $held = Cache::lock($key.'.probe', 5);
    expect($held->get())->toBeTrue();
    expect($bridge->health())->toBeNull();
    Http::assertNothingSent();

    // The card does not read unknown as down (no failure text) nor as up (no claim of availability).
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    expect(app(AiEligibility::class)->forAsset($asset))->state->toBe('eligible')->can_generate->toBeTrue();

    // With a last known answer it gives that one instead.
    Cache::put($key.'.last', false, 600);
    expect($bridge->health())->toBeFalse();
    Http::assertNothingSent();
    $held->release();

    // The one that wins the lock probes once; everyone after reads its answer.
    expect($bridge->health())->toBeFalse()->and($bridge->health())->toBeFalse();
    Http::assertSentCount(1);

    // A caller that waited for the lock finds the answer the holder stored and does not probe again.
    Http::fake(['bridge.test/health' => Http::response('', 500)]);
    Cache::forget($key);
    $answered = false;
    Event::listen(CacheMissed::class, function (CacheMissed $miss) use ($key, &$answered) {
        // Another request finishes its probe between this one's first look and its winning the lock.
        if ($miss->key === $key && ! $answered) {
            $answered = true;
            Cache::put($key, true, 30);
        }
    });
    expect($bridge->health())->toBeTrue();
    Http::assertNothingSent();
});

it('judges a stack by its cover, never by the files stacked under it', function () {
    $this->loginAsAdmin();
    fakeBridge();
    Queue::fake();
    [$zip] = assetWithOriginal('zip', 'document');
    [, $jpgSha] = assetWithOriginal('jpg');
    $slot = app(AttachmentLedger::class)->attachObject($zip, FileObject::where('sha256', $jpgSha)->firstOrFail(), 'alternate', 'photo.jpg');
    $eligibility = app(AiEligibility::class);

    // A ZIP cover with a JPG stacked under it: the stack cannot be described, and says it is the cover that decides.
    expect($eligibility->forAsset($zip->fresh()))->state->toBe('unsupported')->scope->toBe('stack_cover');
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $zip->id))->assertStatus(422);
    $bulk = $this->postJson(route('admin.dam.assets.ai-tags.eligibility'), ['ids' => [$zip->id]])->assertOk();
    expect($bulk->json("eligibility.{$zip->id}.scope"))->toBe('stack_cover')->and($bulk->json("eligibility.{$zip->id}.can_rename"))->toBeFalse();
    expect($this->getJson(route('admin.dam.assets.ai-tags.show', $zip->id))->json('eligibility.scope'))->toBe('stack_cover');

    // The JPG made the cover: the stack is now described through it.
    app(AttachmentLedger::class)->promote($zip->fresh(), (int) $slot->attachment_id);
    expect($eligibility->forAsset($zip->fresh()))->state->toBe('eligible')->scope->toBe('stack_cover');

    // A DNG cover with a preview, and a single file.
    [$dng, $dngSha] = assetWithOriginal('dng');
    putPicture(DerivativeImages::dngPreview($dngSha));
    expect($eligibility->forAsset($dng))->state->toBe('eligible')->scope->toBe('file');
});

/** A PNG with a valid header and IHDR whose image data is cut off. */
function truncatedPng(): string
{
    $image = imagecreatetruecolor(200, 200);

    for ($x = 0; $x < 200; $x++) {
        for ($y = 0; $y < 200; $y++) {
            imagesetpixel($image, $x, $y, imagecolorallocate($image, ($x * 7 + $y) % 255, ($y * 5 + $x) % 255, ($x * $y) % 255));
        }
    }

    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    return substr($png, 0, (int) (strlen($png) * 0.6));
}

it('tells a tool that is not installed, which is the machine\'s doing, from a file with nothing to look at', function () {
    [$video] = assetWithOriginal('mp4', 'video');
    [$broken] = assetWithOriginal('png', 'image', truncatedPng());
    $sampler = app(MediaSampler::class);
    $path = getenv('PATH');

    try {
        putenv('PATH=/nonexistent-bin');
        $missing = $sampler->sample($video);
    } finally {
        putenv('PATH='.$path);
    }

    // No picture because ffprobe/ffmpeg could not run: not a verdict on the video.
    expect($missing->images)->toBe([])->and($missing->transient)->toBe('tool_missing');

    // A PNG that cannot be decoded is the file's own fault: a true "no picture", not retried as a failure.
    $corrupt = $sampler->sample($broken);
    expect($corrupt->images)->toBe([])->and($corrupt->transient)->toBeNull();
});

it('does not call a pending row queued when no job exists for it', function () {
    $this->loginAsAdmin();
    fakeBridge();
    Queue::fake();
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    $store = app(AiIndexStore::class);
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->delete();

    // A words-only refresh (AiReindexLexical, an edit) makes a pending row; no job was queued for it.
    $store->refreshLexical($asset);
    $row = DB::table('dam_ai_index')->where('asset_id', $asset->id)->first();
    expect($row->status)->toBe('pending')->and($row->run_token)->toBeNull();

    expect(app(AiEligibility::class)->forAsset($asset))->state->toBe('eligible')->can_generate->toBeTrue();

    // So the person's click takes it, and queues the one job.
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $asset->id))->assertOk();
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1)
        ->and(app(AiEligibility::class)->forAsset($asset->fresh()))->state->toBe('queued');
});

it('ends a run as failed, not pending, when the bridge is not configured, and offers it again once it is', function () {
    Queue::fake();
    config(['ai-index.index_enabled' => true]);
    [$jpg] = assetWithOriginal('jpg', 'image', jpegBytes());
    $store = app(AiIndexStore::class);
    $token = $store->stamp($jpg->id);
    config(['ai-index.api' => null]);

    (new AiIndexAsset($jpg->id, $token))->handle($store, app(MediaSampler::class), app(AiBridgeClient::class));

    $row = DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first();
    expect($row->status)->toBe('failed')->and($row->error)->toBe('AiBridgeUnavailable')->and($row->run_token)->toBeNull()
        ->and(app(AiEligibility::class)->forAsset($jpg))->state->toBe('service_unavailable')->reason->toBe('not_configured');

    config(['ai-index.api' => 'http://bridge.test']);
    expect(app(AiEligibility::class)->forAsset($jpg->fresh()))->state->toBe('failed')->reason->toBe('unreachable')->will_retry->toBeTrue()->can_generate->toBeTrue();
});

it('waits as pending, not running, between the tries of a run that threw', function () {
    config(['ai-index.index_enabled' => true]);
    Http::fake(['bridge.test/caption' => Http::response('', 500)]);
    [$jpg] = assetWithOriginal('jpg', 'image', jpegBytes());
    $store = app(AiIndexStore::class);
    $token = $store->stamp($jpg->id);

    expect(fn () => (new AiIndexAsset($jpg->id, $token))->handle($store, app(MediaSampler::class), app(AiBridgeClient::class)))->toThrow(AiBridgeUnavailable::class);

    // The backoff can be 15 minutes: a row left "running" would read as lost after ten. And it says why it waits.
    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first())->status->toBe('pending')->run_token->toBe($token)->attempts->toBe(1)->error->toBe('AiBridgeUnavailable');
    DB::table('dam_ai_index')->where('asset_id', $jpg->id)->update(['claimed_at' => now()->subMinutes(12), 'updated_at' => now()->subMinutes(12)]);
    $waiting = app(AiEligibility::class)->forAsset($jpg);
    expect($waiting)->state->toBe('service_unavailable')->reason->toBe('retrying')->will_retry->toBeTrue()->can_generate->toBeFalse()
        ->message->toContain("isn't responding")->message->toContain('keep trying automatically');

    // The next try runs, and when the last one fails the file offers Try again (the service may be back by then).
    $store->markRunning($jpg->id, $token);
    expect(app(AiEligibility::class)->forAsset($jpg))->state->toBe('running');
    (new AiIndexAsset($jpg->id, $token))->failed(new AiBridgeUnavailable('x'));
    expect(app(AiEligibility::class)->forAsset($jpg->fresh()))->state->toBe('failed')->reason->toBe('unreachable')->can_generate->toBeTrue();
});

it('settles a run for a file that is in the trash, so a restored file does not wait on it', function () {
    fakeBridge();
    Queue::fake();
    config(['ai-index.index_enabled' => true]);
    [$jpg] = assetWithOriginal('jpg', 'image', jpegBytes());
    $store = app(AiIndexStore::class);
    $token = $store->stamp($jpg->id);
    DB::table('dam_ai_index')->where('asset_id', $jpg->id)->update(['status' => 'running']);
    DB::table('dam_assets')->where('id', $jpg->id)->update(['deleted_at' => now()]);

    (new AiIndexAsset($jpg->id, $token))->handle($store, app(MediaSampler::class), app(AiBridgeClient::class));

    $row = DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first();
    expect($row->status)->toBe('failed')->and($row->error)->toBe('AssetUnavailable')->and($row->run_token)->toBeNull();

    // Restored: it is not "running" (nothing to poll), and can be asked for again.
    DB::table('dam_assets')->where('id', $jpg->id)->update(['deleted_at' => null]);
    expect(app(AiEligibility::class)->forAsset(Asset::find($jpg->id)))->state->toBe('failed')->can_generate->toBeTrue();
});

it('gives every file type either a caption through its derivative or a plain unsupported state, never a silent done', function (string $extension, string $fileType, string $state, string $reason, ?string $derivative) {
    fakeBridge();
    Queue::fake();
    config(['ai-index.index_enabled' => true]);
    [$asset, $sha] = assetWithOriginal($extension, $fileType);
    $eligibility = app(AiEligibility::class);

    runJob($asset->id);

    $row = DB::table('dam_ai_index')->where('asset_id', $asset->id)->first();
    $answer = $eligibility->forAsset($asset->fresh());
    expect($row->status)->toBe('done')->and($row->caption)->toBeNull()
        // The row always says why it has no description, and the card never offers what cannot work.
        ->and($row->error)->toBeIn(['no_image', 'unsupported'])
        ->and($answer)->state->toBe($state)->reason->toBe($reason)->can_generate->toBeFalse()->can_rename->toBeFalse()->can_retry->toBeFalse()
        ->and($answer['message'])->toBeString()->not->toBeEmpty();

    if ($derivative !== null) {
        // Its picture arrives: indexed once more, and described.
        Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::{$derivative}($sha, ...($derivative === 'thumbnail' ? [$extension] : [])), jpegBytes());
        AiIndexDispatcher::derivativeReady($asset->id);
        runJob($asset->id);
        expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('caption'))->toBe('A raw photo.')
            ->and($eligibility->forAsset($asset->fresh()))->state->toBe('eligible')->reason->toBe('described');
    } else {
        expect($row->error)->toBe('unsupported');
    }
})->with([
    'heic' => ['heic', 'image', 'awaiting_preview', 'thumbnail', 'thumbnail'],
    'tiff' => ['tiff', 'image', 'awaiting_preview', 'thumbnail', 'thumbnail'],
    'dng'  => ['dng', 'image', 'awaiting_preview', 'dng_preview', 'dngPreview'],
    'svg'  => ['svg', 'image', 'unsupported', 'file_type', null],
    'zip'  => ['zip', 'document', 'unsupported', 'file_type', null],
    'txt'  => ['txt', 'document', 'unsupported', 'file_type', null],
]);

it('leaves a captioned file eligible when the picture it was described from has been deleted', function () {
    fakeBridge();
    Queue::fake();
    [$blend, $sha] = assetWithOriginal('blend', 'document');
    $blend->forceFill(['meta_data' => ['game_asset' => ['kind' => 'blender', 'sha256' => $sha, 'has_thumbnail' => true]]])->saveQuietly();
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $blend->id], ['status' => 'done', 'caption' => 'A scene.', 'content_sha256' => $sha, 'created_at' => now(), 'updated_at' => now()]);

    // Poster gone: without a description the file would be awaiting its preview; with one it can still be renamed.
    expect(app(AiEligibility::class)->forAsset($blend->fresh()))->state->toBe('eligible')->reason->toBe('described')->can_rename->toBeTrue();

    DB::table('dam_ai_index')->where('asset_id', $blend->id)->update(['caption' => null]);
    expect(app(AiEligibility::class)->forAsset($blend->fresh()))->state->toBe('awaiting_preview')->reason->toBe('poster_missing');
});

it('keeps a retried-too-often or transient failure retryable, and records a missing tool as a failure to retry', function () {
    fakeBridge();
    Queue::fake();
    config(['ai-index.index_enabled' => true]);
    [$video] = assetWithOriginal('mp4', 'video');
    $store = app(AiIndexStore::class);
    $token = $store->stamp($video->id);
    $path = getenv('PATH');

    try {
        putenv('PATH=/nonexistent-bin');
        expect(fn () => (new AiIndexAsset($video->id, $token))->handle($store, app(MediaSampler::class), app(AiBridgeClient::class)))->toThrow(AiSamplerUnavailable::class);
    } finally {
        putenv('PATH='.$path);
    }

    // Not "done with no picture": that would be final. The queue tries again, and after the last try the job fails.
    expect(DB::table('dam_ai_index')->where('asset_id', $video->id)->first())->status->toBe('pending')->error->toBeNull();
    (new AiIndexAsset($video->id, $token))->failed(new AiSamplerUnavailable('x'));
    expect(app(AiEligibility::class)->forAsset($video))->state->toBe('failed')->reason->toBe('failed')->will_retry->toBeTrue()->can_generate->toBeTrue();
});

it('refuses to describe again a file the AI found no picture in, but allows one manual try for each content', function () {
    $this->loginAsAdmin();
    fakeBridge();
    Queue::fake();
    [$jpg, $sha] = assetWithOriginal('jpg');   // random bytes: nothing to look at
    config(['ai-index.index_enabled' => true]);
    runJob($jpg->id);
    $eligibility = app(AiEligibility::class);
    expect($eligibility->forAsset($jpg))->state->toBe('failed')->reason->toBe('no_image')->can_generate->toBeFalse()->can_retry->toBeTrue();

    // Without the explicit retry the server says no, and why.
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $jpg->id))->assertStatus(422)
        ->assertJsonPath('eligibility.reason', 'no_image');
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(0);

    // "Try again": queued once, and recorded against these bytes.
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $jpg->id), ['retry' => true])->assertOk();
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1)
        ->and(app(AiIndexStore::class)->retrySpent($jpg->id, $sha))->toBeTrue();

    // It found nothing again: no second manual try for the same bytes.
    runJob($jpg->id);
    DB::table('dam_ai_index')->where('asset_id', $jpg->id)->update(['status' => 'done', 'error' => 'no_image', 'run_token' => null]);
    expect($eligibility->forAsset($jpg->fresh()))->state->toBe('failed')->reason->toBe('no_image')->can_retry->toBeFalse();
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $jpg->id), ['retry' => true])->assertStatus(422);
    expect(Queue::pushed(AiIndexAsset::class)->count())->toBe(1);

    // New bytes get their own try.
    DB::table('dam_ai_index_retries')->where('asset_id', $jpg->id)->update(['content_sha256' => str_repeat('1', 64)]);
    expect($eligibility->forAsset($jpg->fresh()))->can_retry->toBeTrue();
});

it('does not take a truncated picture for a picture, and decodes each derivative once', function () {
    fakeBridge();
    [$dng, $sha] = assetWithOriginal('dng');
    $disk = Storage::disk(Directory::getAssetDisk());
    $path = DerivativeImages::dngPreview($sha);

    // Header and size readable, data cut off: getimagesize accepts it, a decode does not.
    $disk->put($path, truncatedPng());
    expect(getimagesizefromstring($disk->get($path)))->not->toBeFalse()
        ->and(AiEligibility::storedPicture($dng, $sha))->toBeNull()
        ->and(app(AiEligibility::class)->forAsset($dng))->state->toBe('awaiting_preview');

    // A JPEG cut short still opens (as grey): its missing end marker gives it away.
    $jpeg = jpegBytes();
    $disk->put($path, substr($jpeg, 0, (int) (strlen($jpeg) * 0.6)));
    expect(AiEligibility::storedPicture($dng, $sha))->toBeNull();

    $disk->put($path, jpegBytes());
    expect(AiEligibility::storedPicture($dng, $sha))->toBe($path);

    // The verdict is kept for that version of the file (sha, path, size, time) and a hit does not renew it...
    $verdict = 'dam.ai-eligibility.decodes.'.$sha.'.'.md5($path.'|'.$disk->size($path).'|'.$disk->lastModified($path));
    expect(Cache::get($verdict))->toBeTrue();
    expect(AiEligibility::storedPicture($dng, $sha))->toBe($path);

    // ...but a derivative that is rewritten is another version, judged again: what is no picture is not let through.
    $disk->put($path, 'not a picture any more');
    expect(AiEligibility::storedPicture($dng, $sha))->toBeNull();
});

it('leaves a run that is still queued or going alone when the nightly reconcile runs', function () {
    config(['ai-index.index_enabled' => true]);
    $queued = Asset::factory()->create();
    $running = Asset::factory()->create();
    $lost = Asset::factory()->create();
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $queued->id], ['status' => 'pending', 'run_token' => runId('q'), 'attempts' => 0, 'claimed_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $running->id], ['status' => 'running', 'run_token' => runId('r'), 'attempts' => 1, 'claimed_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $lost->id], ['status' => 'running', 'run_token' => runId('l'), 'attempts' => 1, 'claimed_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)]);
    Queue::fake();

    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();

    Queue::assertNotPushed(AiIndexAsset::class, fn ($job) => in_array($job->assetId, [$queued->id, $running->id], true));
    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $lost->id);
});

it('never lets a job without a token write over the run that owns the file', function () {
    fakeBridge();
    Queue::fake();
    config(['ai-index.index_enabled' => true]);
    [$jpg] = assetWithOriginal('jpg', 'image', jpegBytes());
    $store = app(AiIndexStore::class);
    $token = $store->stamp($jpg->id);
    DB::table('dam_ai_index')->where('asset_id', $jpg->id)->update(['caption' => 'Mine.', 'status' => 'running', 'claimed_at' => now(), 'lexical_text' => 'mine']);
    $before = DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first();

    // Both a plain tokenless job and one queued before tokens existed (no `token` in its payload at all).
    $legacy = unserialize(preg_replace(['/s:5:"token";N;/', '/(AiIndexAsset":)(\d+)(:\{)/'], ['', '${1}'.(count((array) new AiIndexAsset(1)) - 1).'${3}'], serialize(new AiIndexAsset($jpg->id))));
    expect($legacy->token)->toBeNull();

    foreach ([new AiIndexAsset($jpg->id), $legacy] as $job) {
        $job->handle($store, app(MediaSampler::class), app(AiBridgeClient::class));
        $job->failed(new RuntimeException('x'));
    }

    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first())->toEqual($before);
    Http::assertNothingSent();

    // And the markers a run ends with do nothing for a run that does not own the row.
    $store->markFailed($jpg->id, 'x', runId('99'));
    $store->markRetrying($jpg->id, runId('99'));
    expect($store->storeResult($jpg->id, null, null, [], 'm', null, runId('99')))->toBeFalse()
        ->and($store->clearStaleAi($jpg->id, runId('99')))->toBeFalse()
        ->and(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first())->toEqual($before);

    // A tokenless job that finds the file free takes it through the normal claim, and then owns it.
    DB::table('dam_ai_index')->where('asset_id', $jpg->id)->update(['status' => 'failed', 'run_token' => null, 'caption' => null]);
    (new AiIndexAsset($jpg->id))->handle($store, app(MediaSampler::class), app(AiBridgeClient::class));
    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first())->status->toBe('done')->caption->toBe('A raw photo.');
});

it('claims every file before the backfill queues it, and leaves a run in progress alone', function () {
    config(['ai-index.index_enabled' => false]);
    $queued = Asset::factory()->create();
    $free = Asset::factory()->create();
    $store = app(AiIndexStore::class);
    $mine = $store->stamp($queued->id);
    DB::table('dam_ai_index')->where('asset_id', $free->id)->delete();
    Queue::fake();

    $this->artisan('dam:ai-index:backfill')->assertSuccessful();

    Queue::assertNotPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $queued->id);
    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $free->id && $job->token === DB::table('dam_ai_index')->where('asset_id', $free->id)->value('run_token') && $job->token !== null);
    expect(DB::table('dam_ai_index')->where('asset_id', $queued->id)->value('run_token'))->toBe($mine);
});

it('does not let a stalled worker delete the description a newer run took the file over to write', function () {
    fakeBridge();
    Queue::fake();
    config(['ai-index.index_enabled' => true]);
    [$jpg, $sha] = assetWithOriginal('jpg', 'image', jpegBytes());
    $store = app(AiIndexStore::class);
    $old = $store->stamp($jpg->id);
    // What the previous content left, so this run (new bytes) will clear it first.
    DB::table('dam_ai_index')->where('asset_id', $jpg->id)->update(['caption' => 'Old.', 'content_sha256' => str_repeat('a', 64)]);

    // While worker A samples (its process pauses here), worker B takes the file over, finishes, and has its description in.
    $sampler = new class(fn () => null) extends MediaSampler
    {
        public $between;

        public function __construct($between)
        {
            $this->between = $between;
        }

        public function sample(Asset $asset): MediaSample
        {
            $sample = parent::sample($asset);
            ($this->between)();

            return $sample;
        }
    };
    $sampler->between = function () use ($jpg, $sha) {
        DB::table('dam_ai_index')->where('asset_id', $jpg->id)->update(['caption' => 'B wrote this.', 'status' => 'done', 'run_token' => null, 'content_sha256' => $sha]);
        DB::table('dam_ai_tags')->insert(['asset_id' => $jpg->id, 'term' => 'logo', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dam_ai_vectors')->insert(['asset_id' => $jpg->id, 'kind' => 'pdf_page', 'page' => 1, 'vector' => VectorCodec::encode([1, 0, 0]), 'text' => 'b page', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    };

    (new AiIndexAsset($jpg->id, $old))->handle($store, $sampler, app(AiBridgeClient::class));

    // A found the token gone before it wiped anything: B's caption, suggestion and page text are all still there.
    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first())->caption->toBe('B wrote this.')->status->toBe('done');
    expect(DB::table('dam_ai_tags')->where('asset_id', $jpg->id)->count())->toBe(1)
        ->and(DB::table('dam_ai_vectors')->where('asset_id', $jpg->id)->count())->toBe(1);
    Http::assertNothingSent();
});

it('allows one manual retry per file and content even when the file goes back to bytes it had, or two ask at once', function () {
    $store = app(AiIndexStore::class);
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    $a = str_repeat('a', 64);
    $b = str_repeat('b', 64);
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->delete();

    // Content A: the retry is taken once.
    expect($store->claim($asset->id, retrySha: $a))->toBeString();
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'done', 'run_token' => null]);
    expect($store->claim($asset->id, retrySha: $a))->toBeNull()->and($store->retrySpent($asset->id, $a))->toBeTrue();

    // Content B has its own; then back to A (the bytes were restored): A's is still spent.
    expect($store->claim($asset->id, retrySha: $b))->toBeString();
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'done', 'run_token' => null]);
    expect($store->claim($asset->id, retrySha: $a))->toBeNull();

    // A retry that could not be queued (a run holds the file) is not spent.
    $c = str_repeat('c', 64);
    $held = $store->claim($asset->id);
    expect($store->claim($asset->id, retrySha: $c))->toBeNull()->and($store->retrySpent($asset->id, $c))->toBeFalse();
    expect($held)->toBeString();

    // Two requests at once for a fresh content: the unique row lets exactly one through.
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['status' => 'done', 'run_token' => null]);
    $d = str_repeat('d', 64);
    $results = [];
    $seen = false;
    DB::listen(function ($query) use (&$seen, &$results, $store, $asset, $d) {
        // The first request has just spent the retry; the second arrives before it goes on.
        if (! $seen && str_contains($query->sql, 'dam_ai_index_retries') && str_starts_with(strtolower($query->sql), 'insert')) {
            $seen = true;
            $results[] = $store->claim($asset->id, retrySha: $d);
        }
    });
    $results[] = $store->claim($asset->id, retrySha: $d);
    $tokens = array_values(array_filter($results));

    expect($results)->toHaveCount(2)->and($tokens)->toHaveCount(1);
});

it('lets exactly one of two claims that read the same row win', function () {
    $store = app(AiIndexStore::class);
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'failed', 'run_token' => null, 'created_at' => now(), 'updated_at' => now()]);
    $nested = null;
    $entered = false;

    // Claim 1 has read the failed row; claim 2 runs completely (and wins) before claim 1 writes. Claim 1 must lose.
    DB::listen(function ($query) use (&$entered, &$nested, $store, $asset) {
        if (! $entered && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"dam_ai_index"') && ! str_contains($query->sql, 'exists')) {
            $entered = true;
            $nested = $store->claim($asset->id);
        }
    });
    $first = $store->claim($asset->id);

    expect($nested)->toBeString()->and($first)->toBeNull()
        ->and(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('run_token'))->toBe($nested);
});

it('changes nothing when only the token was replaced between the read and the update', function () {
    $store = app(AiIndexStore::class);
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    $claimed = now()->subHours(2)->format('Y-m-d H:i:s');
    // A pending run whose job is gone (no row in the queue): claimable. Same status and times throughout.
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'pending', 'run_token' => runId('11'), 'claimed_at' => $claimed, 'created_at' => $claimed, 'updated_at' => $claimed]);
    DB::table('jobs')->delete();
    $replaced = false;

    DB::listen(function ($query) use (&$replaced, $asset) {
        if (! $replaced && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"dam_ai_index"') && ! str_contains($query->sql, 'exists')) {
            $replaced = true;
            // Another claimant's token lands; status, updated_at and claimed_at are exactly what they were.
            DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['run_token' => runId('22')]);
        }
    });

    expect($store->claim($asset->id))->toBeNull();
    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->first())
        ->run_token->toBe(runId('22'))->status->toBe('pending')->attempts->toBe(0);
});

it('writes the search words only for the run that owns the row, and from the row as it is', function () {
    $store = app(AiIndexStore::class);
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image', 'file_name' => 'harvest-festival.jpg']);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'running', 'run_token' => runId('22'), 'caption' => 'Newer caption about tractors.', 'lexical_text' => 'newer words', 'created_at' => now(), 'updated_at' => now()]);

    // The stale run (token 11) writes nothing; the owner's words stay.
    $store->refreshLexical($asset, runId('11'));
    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('lexical_text'))->toBe('newer words');

    // The owner writes, built from the row it replaces; a refresh by anyone else (the run's last step) does the same.
    $store->refreshLexical($asset, runId('22'));
    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('lexical_text'))->toContain('tractors');
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['caption' => 'Changed again.']);
    $store->refreshLexical($asset);
    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('lexical_text'))->toContain('Changed again.')->not->toContain('tractors');
});

it('builds the words from the row as it is when another request made and finished it first', function () {
    $store = app(AiIndexStore::class);
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image', 'file_name' => 'harvest-festival.jpg']);
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->delete();
    $armed = true;

    // The first thing refreshLexical does to the table, another request finishes the row (this suite has one connection, so
    // two truly concurrent transactions cannot be run; this puts the finished row where the lost race would). The refresh
    // must read that row, not write words made from "no row" over it.
    DB::listen(function ($query) use (&$armed, $asset) {
        if ($armed && str_contains($query->sql, 'dam_ai_index')) {
            $armed = false;
            DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'done', 'caption' => 'Tractors lined up for the parade.', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $store->refreshLexical($asset);

    $row = DB::table('dam_ai_index')->where('asset_id', $asset->id)->first();
    expect($row->status)->toBe('done')->and($row->caption)->toContain('Tractors')->and($row->lexical_text)->toContain('Tractors');
});

it('writes nothing for an owner whose row is gone', function () {
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->delete();

    app(AiIndexStore::class)->refreshLexical($asset, runId('11'));

    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->exists())->toBeFalse();
});

it('has the rename dialog wording in every English locale, as the real translation', function (string $locale) {
    app()->setLocale($locale);

    expect(trans('dam::app.admin.explorer.ai-rename.unchanged'))->toBe('Name unchanged')
        ->and(trans('dam::app.admin.explorer.ai-rename.titled-one'))->toBe('Title updated.')
        ->and(trans('dam::app.admin.explorer.ai-rename.titled', ['count' => 3]))->toBe('Titles updated for 3 files.')
        ->and(trans('dam::app.admin.dam.asset.edit.ai.gone'))->toBe('This file is no longer available.')
        ->and(trans('dam::app.admin.dam.asset.edit.ai.no-access'))->toBe('You no longer have access to this file.');
})->with(['en_US', 'en_GB', 'en_AU', 'en_NZ'])->after(fn () => app()->setLocale('en'));

it('gives the retry back when the claim throws, so it is never spent without a run queued', function () {
    $store = app(AiIndexStore::class);
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    $sha = str_repeat('e', 64);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'done', 'error' => 'no_image', 'content_sha256' => $sha, 'created_at' => now(), 'updated_at' => now()]);
    $armed = true;

    // The claim's own update fails, after the retry was reserved.
    DB::listen(function ($query) use (&$armed) {
        if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, '"dam_ai_index"') && str_contains($query->sql, '"run_token"')) {
            throw new RuntimeException('injected');
        }
    });

    expect(fn () => $store->claim($asset->id, retrySha: $sha))->toThrow(RuntimeException::class);
    $armed = false;
    expect($store->retrySpent($asset->id, $sha))->toBeFalse()
        ->and(DB::table('dam_ai_index')->where('asset_id', $asset->id)->first())->status->toBe('done')->run_token->toBeNull();

    // So the person's one retry still works.
    expect($store->claim($asset->id, retrySha: $sha))->toBeString()->and($store->retrySpent($asset->id, $sha))->toBeTrue();
});

/** A PNG chunk, with its CRC. */
function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

function pngBytes(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 40, 90));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('judges a derivative by a real decode: whole pictures in, a PNG with no image data out', function () {
    [$dng, $sha] = assetWithOriginal('dng');
    $disk = Storage::disk(Directory::getAssetDisk());
    $path = DerivativeImages::dngPreview($sha);
    $image = imagecreatetruecolor(40, 30);
    ob_start();
    imagewebp($image);
    $webp = (string) ob_get_clean();

    // Valid PNG, JPEG and WebP derivatives are all pictures.
    foreach (['png' => pngBytes(40, 30), 'jpeg' => jpegBytes(), 'webp' => $webp] as $format => $bytes) {
        $disk->put($path, $bytes);
        expect(AiEligibility::storedPicture($dng, $sha))->toBe($path, "a valid {$format} is a picture");
    }

    // Signature, IHDR and IEND, but no IDAT: the header reads (and is under every budget), the picture does not exist.
    $hollow = hex2bin('89504e470d0a1a0a').pngChunk('IHDR', pack('NNCCCCC', 40, 30, 8, 2, 0, 0, 0)).pngChunk('IEND', '');
    $disk->put($path, $hollow);
    expect(getimagesizefromstring($hollow))->not->toBeFalse()
        ->and(AiEligibility::storedPicture($dng, $sha))->toBeNull();
});

it('accepts a picture exactly at the pixel and byte caps and refuses one past them', function () {
    [$dng, $sha] = assetWithOriginal('dng');
    $disk = Storage::disk(Directory::getAssetDisk());
    $path = DerivativeImages::dngPreview($sha);

    // Pixels: the budget is a count of w x h.
    config(['ai-index.gd_max_pixels' => 10_000]);
    $disk->put($path, pngBytes(100, 100));
    expect(AiEligibility::storedPicture($dng, $sha))->toBe($path);
    Cache::flush();   // a rewrite of the same size within the same second is the same version; the pixel cap is judged afresh
    $disk->put($path, pngBytes(101, 100));
    expect(AiEligibility::storedPicture($dng, $sha))->toBeNull();

    // Bytes: a PNG may carry anything after IEND, so it can be padded to the cap exactly.
    config(['ai-index.gd_max_pixels' => 16_000_000]);
    Cache::flush();
    $png = pngBytes(40, 30);
    $disk->put($path, $png.str_repeat(chr(0), AiEligibility::MAX_PICTURE_BYTES - strlen($png)));
    expect($disk->size($path))->toBe(AiEligibility::MAX_PICTURE_BYTES)->and(AiEligibility::storedPicture($dng, $sha))->toBe($path);
    $disk->put($path, $png.str_repeat(chr(0), AiEligibility::MAX_PICTURE_BYTES - strlen($png) + 1));
    expect(AiEligibility::storedPicture($dng, $sha))->toBeNull();

    // The sampler applies the same bounds where it reads the bytes.
    expect(AiEligibility::withinBounds($png))->toBeTrue()
        ->and(AiEligibility::withinBounds($png.str_repeat(chr(0), AiEligibility::MAX_PICTURE_BYTES)))->toBeFalse()
        ->and(AiEligibility::withinBounds(''))->toBeFalse();
    config(['ai-index.gd_max_pixels' => 1000]);
    expect(AiEligibility::withinBounds($png))->toBeFalse();
});

it('frees a file when a job that took it without a token throws, so its next try can take it again', function () {
    Queue::fake();
    config(['ai-index.index_enabled' => true]);
    $GLOBALS['caption_down'] = true;
    Http::fake([
        'bridge.test/caption' => fn () => $GLOBALS['caption_down'] ? Http::response('', 500) : Http::response(['caption' => 'A raw photo.', 'tags' => [], 'keywords' => [], 'visible_text' => '', 'model' => 'm', 'prompt_version' => 'p']),
        'bridge.test/embed'   => fn ($request) => Http::response(['vectors' => array_map(fn () => [1, 0, 0], $request['texts']), 'model' => 'e']),
    ]);
    [$jpg] = assetWithOriginal('jpg', 'image', jpegBytes());
    $store = app(AiIndexStore::class);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $jpg->id], ['status' => 'failed', 'run_token' => null, 'created_at' => now(), 'updated_at' => now()]);
    $job = fn () => new AiIndexAsset($jpg->id);

    // A pre-deploy job comes back with no token each time: its first try takes the file, throws, and does not leave a
    // pending row that its own next try would take for someone else's.
    expect(fn () => $job()->handle($store, app(MediaSampler::class), app(AiBridgeClient::class)))->toThrow(AiBridgeUnavailable::class);
    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first())->status->toBe('failed')->run_token->toBeNull()->error->toBe('AiBridgeUnavailable');

    $GLOBALS['caption_down'] = false;
    $job()->handle($store, app(MediaSampler::class), app(AiBridgeClient::class));
    expect(DB::table('dam_ai_index')->where('asset_id', $jpg->id)->first())->status->toBe('done')->caption->toBe('A raw photo.');
});

it('judges how long a job has waited by its own clock, not by a words refresh that touched the row', function () {
    fakeBridge();
    Queue::fake();
    $asset = Asset::factory()->create(['extension' => 'jpg', 'file_type' => 'image']);
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'pending', 'run_token' => runId('05'), 'claimed_at' => now()->subHours(2), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('jobs')->delete();

    // updated_at says "just now" (an edit refreshed the words); the run was queued two hours ago and its job is not in the queue.
    expect(app(AiEligibility::class)->forAsset($asset))->state->toBe('failed')->reason->toBe('stalled');

    // A queue table that cannot be read is not known, so nothing is called lost on a guess within the bound.
    config(['queue.connections.ai-index.table' => 'no_such_table']);
    DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['claimed_at' => now()->subHours(2)]);
    expect(app(AiEligibility::class)->forAsset($asset))->state->toBe('queued');
});

it('does not accept an over-budget picture on the strength of its header or end marker', function () {
    [$dng, $sha] = assetWithOriginal('dng');
    $disk = Storage::disk(Directory::getAssetDisk());
    $png = truncatedPng();
    // A header claiming 5000 x 5000 (25 MP, over the 16 MP budget), data cut off, closed with an IEND chunk anyway.
    $png = substr($png, 0, 16).pack('N', 5000).pack('N', 5000).substr($png, 24).hex2bin('0000000049454e44ae426082');
    $disk->put(DerivativeImages::dngPreview($sha), $png);

    expect(getimagesizefromstring($png)[0])->toBe(5000)
        ->and(AiEligibility::storedPicture($dng, $sha))->toBeNull()
        ->and(app(MediaSampler::class)->sample($dng)->images)->toBe([]);
});

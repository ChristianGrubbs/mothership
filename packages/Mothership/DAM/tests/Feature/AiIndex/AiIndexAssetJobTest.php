<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiBridgeUnavailable;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\AiIndex\MediaSample;
use Mothership\DAM\AiIndex\MediaSampler;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

beforeEach(function () {
    config(['ai-index.api' => 'http://bridge.test']);
    $this->asset = Asset::factory()->create(['file_name' => 'homecoming_program-2025.pdf', 'extension' => 'pdf', 'file_type' => 'document']);
    $this->asset->directories()->attach(Directory::factory()->create(['name' => 'Athletics', 'parent_id' => null])->id);
    $this->app->instance(MediaSampler::class, new class extends MediaSampler
    {
        public function sample(Asset $asset): MediaSample
        {
            return new MediaSample(images: ['jpeg'], pages: ['Welcome to homecoming', '', 'Parking map'], kind: 'pdf', sha256: str_repeat('a', 64));
        }
    });
    // Http::fake matches the first stub registered, so "bridge down" is a switch, not a second fake.
    $this->bridgeDown = false;
    Http::fake([
        'bridge.test/caption' => fn () => $this->bridgeDown ? Http::response(['error' => 'ollama_failed'], 502) : Http::response(['caption' => 'A crowd cheers at a game.', 'tags' => ['campus-event', 'basketball'], 'keywords' => ['crowd'], 'visible_text' => 'GO TEAM', 'model' => 'qwen3.5:9b', 'prompt_version' => '2026-10-01.1']),
        'bridge.test/embed'   => fn ($request) => $this->bridgeDown ? Http::response(['error' => 'ollama_failed'], 502) : Http::response(['vectors' => array_map(fn () => [1, 0, 0], $request['texts']), 'model' => 'qwen3-embedding:0.6b']),
    ]);
});

function runIndexJob(int $assetId): void
{
    (new AiIndexAsset($assetId))->handle(app(AiIndexStore::class), app(MediaSampler::class), app(AiBridgeClient::class));
}

it('stores caption, suggested tags, a summary vector and one vector per non-empty PDF page', function () {
    runIndexJob($this->asset->id);

    $row = DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->first();
    expect($row->status)->toBe('done')
        ->and($row->caption)->toBe('A crowd cheers at a game.')
        ->and($row->content_sha256)->toBe(str_repeat('a', 64))
        ->and($row->lexical_text)->toContain('homecoming program 2025')->toContain('Athletics')->toContain('Campus event');

    expect(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->orderBy('term')->pluck('term')->all())->toBe(['basketball', 'campus-event']);
    expect(DB::table('dam_tags')->count())->toBe(0);

    $vectors = DB::table('dam_ai_vectors')->where('asset_id', $this->asset->id)->orderBy('id')->get();
    expect($vectors->pluck('kind')->all())->toBe(['summary', 'pdf_page', 'pdf_page'])
        ->and($vectors->pluck('page')->all())->toBe([null, 1, 3])
        ->and(VectorCodec::decode($vectors[1]->vector)[0])->toEqualWithDelta(1.0, 1e-6);
});

it('keeps accepted and dismissed decisions on re-index', function () {
    DB::table('dam_ai_tags')->insert([
        ['asset_id' => $this->asset->id, 'term' => 'campus-event', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
        ['asset_id' => $this->asset->id, 'term' => 'basketball', 'status' => 'dismissed', 'created_at' => now(), 'updated_at' => now()],
        ['asset_id' => $this->asset->id, 'term' => 'logo', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()],
    ]);

    runIndexJob($this->asset->id);

    expect(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->orderBy('term')->pluck('status', 'term')->all())
        ->toBe(['basketball' => 'dismissed', 'campus-event' => 'accepted']);
});

it('fails the attempt when the bridge is down and marks failed after the last try', function () {
    $this->bridgeDown = true;
    $job = new AiIndexAsset($this->asset->id);

    expect(fn () => $job->handle(app(AiIndexStore::class), app(MediaSampler::class), app(AiBridgeClient::class)))
        ->toThrow(AiBridgeUnavailable::class);

    $job->failed(new RuntimeException('x'));
    expect(DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->value('status'))->toBe('failed')
        ->and(DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->value('lexical_text'))->toContain('Athletics')
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('keeps an existing caption and suggestions when sampling finds no images for the same content', function () {
    runIndexJob($this->asset->id);

    $this->app->instance(MediaSampler::class, new class extends MediaSampler
    {
        public function sample(Asset $asset): MediaSample
        {
            return new MediaSample(images: [], pages: [], kind: 'pdf', sha256: str_repeat('a', 64));
        }
    });

    runIndexJob($this->asset->id);

    $row = DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->first();
    expect($row->status)->toBe('done')
        ->and($row->content_sha256)->toBe(str_repeat('a', 64))
        ->and($row->caption)->toBe('A crowd cheers at a game.')
        ->and($row->visible_text)->toBe('GO TEAM')
        ->and(json_decode($row->keywords, true))->toBe(['crowd']);
    expect(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->where('status', 'suggested')->count())->toBe(2);
});

it('forgets the old caption and open suggestions when replaced content yields nothing, and reconcile retries it', function () {
    runIndexJob($this->asset->id);
    DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->where('term', 'basketball')->update(['status' => 'accepted']);
    DB::table('dam_ai_tags')->insert(['asset_id' => $this->asset->id, 'term' => 'logo', 'status' => 'dismissed', 'created_at' => now(), 'updated_at' => now()]);

    $this->app->instance(MediaSampler::class, new class extends MediaSampler
    {
        public function sample(Asset $asset): MediaSample
        {
            return new MediaSample(images: [], pages: [], kind: 'pdf', sha256: str_repeat('c', 64));
        }
    });

    runIndexJob($this->asset->id);

    $row = DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->first();
    expect($row->status)->toBe('done')
        ->and($row->content_sha256)->toBe(str_repeat('c', 64))
        ->and($row->caption)->toBeNull()
        ->and($row->visible_text)->toBeNull()
        ->and($row->keywords)->toBeNull()
        ->and($row->caption_model)->toBeNull()
        ->and($row->prompt_version)->toBeNull();
    expect(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->orderBy('term')->pluck('status', 'term')->all())
        ->toBe(['basketball' => 'accepted', 'logo' => 'dismissed']);

    config(['ai-index.index_enabled' => true]);
    Queue::fake();
    $this->artisan('dam:ai-index:reconcile')->assertSuccessful();
    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $this->asset->id);
});

it('counts attempts across dispatches and clears them on success', function () {
    $store = app(AiIndexStore::class);
    $store->refreshLexical($this->asset);

    $token = $store->claim($this->asset->id);
    $store->markRunning(assetId: $this->asset->id, token: $token);
    $store->markRunning(assetId: $this->asset->id, token: $token);
    expect(DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->value('attempts'))->toBe(2);

    (new AiIndexAsset($this->asset->id, $token))->handle(app(AiIndexStore::class), app(MediaSampler::class), app(AiBridgeClient::class));
    expect(DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->value('attempts'))->toBe(0);
});

it('drops the previous content text before building the new summary embedding', function () {
    DB::table('dam_ai_index')->insert([
        'asset_id'     => $this->asset->id, 'status' => 'done', 'caption' => 'A red tractor', 'keywords' => json_encode(['tractor']),
        'visible_text' => 'tractor sale', 'content_sha256' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('dam_ai_tags')->insert(['asset_id' => $this->asset->id, 'term' => 'logo', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_vectors')->insert(['asset_id' => $this->asset->id, 'kind' => 'pdf_page', 'page' => 1, 'vector' => VectorCodec::encode([1, 0, 0]), 'text' => 'tractor manual', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    app(AiIndexStore::class)->refreshLexical($this->asset);
    expect(DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->value('lexical_text'))->toContain('tractor');

    $this->app->instance(MediaSampler::class, new class extends MediaSampler
    {
        public function sample(Asset $asset): MediaSample
        {
            return new MediaSample(images: [], pages: [], kind: 'pdf', sha256: str_repeat('d', 64));
        }
    });

    runIndexJob($this->asset->id);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/embed'));
    Http::assertNotSent(fn ($request) => str_contains(json_encode($request->data()), 'tractor'));
    $vectors = DB::table('dam_ai_vectors')->where('asset_id', $this->asset->id)->get();
    expect($vectors->pluck('kind')->all())->toBe(['summary'])
        ->and($vectors[0]->text)->not->toContain('tractor');
    expect(DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->value('lexical_text'))->not->toContain('tractor');
});

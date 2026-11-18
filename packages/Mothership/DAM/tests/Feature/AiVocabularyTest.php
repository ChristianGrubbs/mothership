<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\AiIndex\MediaSample;
use Mothership\DAM\AiIndex\MediaSampler;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Jobs\AiReindexLexical;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Tag;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P5c (D8): the AI vocabulary is a table, seeded from config, managed under Settings, Library, AI vocabulary. The
 * bridge is asked only for enabled terms; disabling one stops new proposals and closes its open ones, while tags
 * people accepted stay. A term can map to an existing tag; labels and synonyms pass TagNameRule.
 */

function vocabRunIndex(int $assetId): void
{
    (new AiIndexAsset($assetId))->handle(app(AiIndexStore::class), app(MediaSampler::class), app(AiBridgeClient::class));
}

beforeEach(function () {
    config(['ai-index.api' => 'http://bridge.test', 'dam-tags.denylist' => ['badword']]);
    $this->asset = Asset::factory()->create(['file_name' => 'team-photo.jpg']);
    $this->app->instance(MediaSampler::class, new class extends MediaSampler
    {
        public function sample(Asset $asset): MediaSample
        {
            return new MediaSample(images: ['jpeg'], pages: [], kind: 'image', sha256: str_repeat('b', 64));
        }
    });
    $this->sent = [];
    // Http::fake matches the first stub registered, so a test changes the answer here rather than faking again.
    $this->answer = ['tags' => ['photo', 'screenshot'], 'keywords' => ['snapshot']];
    Http::fake([
        'bridge.test/caption' => function ($request) {
            $this->sent[] = $request['vocabulary'];

            return Http::response(['caption' => 'A team photo.', ...$this->answer, 'visible_text' => '', 'model' => 'qwen3.5:9b', 'prompt_version' => '2026-10-01.1']);
        },
        'bridge.test/embed' => fn ($request) => Http::response(['vectors' => array_map(fn () => [1, 0, 0], $request['texts']), 'model' => 'qwen3-embedding:0.6b']),
    ]);
    $this->loginAsAdmin();
});

it('is seeded from config and asks the bridge for enabled terms only', function () {
    expect(DB::table('dam_ai_vocabulary')->count())->toBe(count(config('ai-vocabulary.terms')))
        ->and(DB::table('dam_ai_vocabulary')->where('term', 'photo')->value('label'))->toBe('Photo');

    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'screenshot'), ['enabled' => false])->assertOk()->assertJsonPath('term.enabled', false);
    vocabRunIndex($this->asset->id);

    // The bridge never hears "screenshot", and its answer naming it is not stored.
    expect($this->sent[0])->toContain('photo')->not->toContain('screenshot')
        ->and(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->pluck('term')->all())->toBe(['photo']);
});

it('keeps accepted tags when a term is disabled, and drops its open suggestions from review', function () {
    vocabRunIndex($this->asset->id);
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo'])->assertOk();
    $queue = fn () => collect($this->getJson(route('admin.dam.ai-review.index'))->assertOk()->json('data'))->where('asset_id', $this->asset->id)->pluck('term')->all();
    expect($queue())->toBe(['screenshot']);

    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'photo'), ['enabled' => false])->assertOk();
    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'screenshot'), ['enabled' => false])->assertOk();

    expect($this->asset->fresh()->tags->pluck('name')->all())->toBe(['Photo'])
        ->and($queue())->toBe([])
        ->and(array_column($this->getJson(route('admin.dam.assets.info', $this->asset->id))->json('ai.tags'), 'term'))->toBe([])
        ->and($this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'screenshot'])->status())->toBe(422);

    // Re-enabled, the open suggestion is back (it was never deleted).
    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'screenshot'), ['enabled' => true])->assertOk();
    expect($queue())->toBe(['screenshot']);
});

it('accepts a mapped term as the tag it maps to, and a synonym keyword proposes its term', function () {
    $brand = Tag::create(['name' => 'Brand Photography']);
    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'photo'), ['maps_to_tag_id' => $brand->id, 'synonyms' => ['snapshot', 'Pic']])
        ->assertOk()->assertJsonPath('term.maps_to', ['id' => $brand->id, 'name' => 'Brand Photography'])->assertJsonPath('term.synonyms', ['snapshot', 'Pic']);
    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'diagram'), ['synonyms' => 'blueprint, schematic'])->assertOk();

    $this->answer = ['tags' => [], 'keywords' => ['Snapshot', ' BLUEPRINT ', 'photo']];
    vocabRunIndex($this->asset->id);
    expect(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->orderBy('term')->pluck('term')->all())->toBe(['diagram', 'photo']);

    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo'])->assertOk();
    expect($this->asset->fresh()->tags->pluck('id')->all())->toBe([$brand->id])
        ->and(Tag::where('name', 'Photo')->exists())->toBeFalse();
});

it('refuses an offensive label or synonym, a bad term, a missing tag and more than 200 enabled terms', function () {
    $store = route('admin.dam.configuration.ai-vocabulary.store');

    $this->postJson($store, ['term' => 'mascot', 'label' => 'Badword'])->assertUnprocessable()->assertJsonValidationErrors('label');
    $this->postJson($store, ['term' => 'mascot', 'label' => 'Mascot', 'synonyms' => ['badword']])->assertUnprocessable()->assertJsonValidationErrors('synonyms');
    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'photo'), ['label' => 'Badword'])->assertUnprocessable();
    $this->postJson($store, ['term' => 'Has Spaces', 'label' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('term');
    $this->postJson($store, ['term' => 'photo', 'label' => 'Photo'])->assertUnprocessable()->assertJsonValidationErrors('term');
    $this->postJson($store, ['term' => 'mascot', 'label' => 'Mascot', 'maps_to_tag_id' => 999999999])->assertUnprocessable()->assertJsonValidationErrors('maps_to_tag_id');
    expect(DB::table('dam_ai_vocabulary')->where('term', 'mascot')->exists())->toBeFalse()
        ->and(DB::table('dam_ai_vocabulary')->where('term', 'photo')->value('label'))->toBe('Photo');

    $this->postJson($store, ['term' => 'mascot', 'label' => '  team   Mascot '])->assertCreated()->assertJsonPath('term.label', 'team Mascot');

    $now = now();
    $enabled = DB::table('dam_ai_vocabulary')->where('enabled', true)->count();
    DB::table('dam_ai_vocabulary')->insert(array_map(fn ($i) => ['term' => "filler-{$i}", 'label' => "Filler {$i}", 'enabled' => true, 'created_at' => $now, 'updated_at' => $now], range(1, 200 - $enabled)));
    $this->postJson($store, ['term' => 'one-too-many', 'label' => 'One too many'])->assertUnprocessable()->assertJsonValidationErrors('enabled');
    $this->postJson($store, ['term' => 'one-disabled', 'label' => 'One disabled', 'enabled' => false])->assertCreated();
});

it('re-indexes the files a renamed term is suggested on, and needs dam.ai.vocabulary.manage', function () {
    config(['ai-index.index_enabled' => true]);
    DB::table('dam_ai_tags')->insert(['asset_id' => $this->asset->id, 'term' => 'photo', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);
    Queue::fake();

    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'photo'), ['label' => 'Photograph'])->assertOk();
    Queue::assertPushed(AiReindexLexical::class, fn ($job) => in_array($this->asset->id, $job->assetIds ?? [], true));
    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'photo'), ['synonyms' => ['pic']])->assertOk();
    Queue::assertPushed(AiReindexLexical::class, 1);

    $viewer = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.tag', 'dam.configuration', 'dam.configuration.index']])->id]);
    $this->actingAs($viewer, 'admin');
    $this->get(route('admin.dam.configuration.ai-vocabulary.index'))->assertForbidden();
    $this->putJson(route('admin.dam.configuration.ai-vocabulary.update', 'photo'), ['label' => 'X'])->assertForbidden();
    $this->get(route('admin.dam.configuration.index'))->assertOk()->assertDontSee('data-ai-vocabulary-link', false);
});

it('answers REST like the screen: list, add and change under api.dam.ai_vocabulary scopes', function () {
    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.ai_vocabulary', 'api.dam.ai_vocabulary.manage']);
    $web = $this->getJson(route('admin.dam.configuration.ai-vocabulary.index'))->assertOk()->json('terms');

    expect($this->withHeaders($headers)->getJson(route('admin.api.dam.ai-vocabulary.index'))->assertOk()->json('data.terms'))->toBe($web);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.ai-vocabulary.store'), ['term' => 'mascot', 'label' => 'Mascot'])->assertCreated()->assertJsonPath('data.term', 'mascot');
    $this->withHeaders($headers)->putJson(route('admin.api.dam.ai-vocabulary.update', 'mascot'), ['label' => 'Badword'])->assertUnprocessable();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.ai-vocabulary.update', 'nothing-here'), ['label' => 'X'])->assertNotFound();

    app('auth')->forgetGuards();
    $readOnly = $this->getAuthenticationHeaders('custom', ['api.dam.ai_vocabulary']);
    $this->withHeaders($readOnly)->getJson(route('admin.api.dam.ai-vocabulary.index'))->assertOk();
    $this->withHeaders($readOnly)->postJson(route('admin.api.dam.ai-vocabulary.store'), ['term' => 'other', 'label' => 'Other'])->assertForbidden();
});

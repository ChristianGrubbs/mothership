<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\AiIndex\MediaSample;
use Mothership\DAM\AiIndex\MediaSampler;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P5c (D8): the AI tag review queue. Open suggestions on files the reviewer may list, for reviewers holding
 * dam.asset.tag; grouped by term or by file; accepted or dismissed in bulk through the asset page's own path, so an
 * accepted tag carries `ai:<model>` and a dismissal survives re-indexing.
 */

function reviewAsset(Directory $folder, string $name, array $terms, ?string $model = 'qwen3.5:9b'): Asset
{
    $asset = Asset::factory()->create(['file_name' => $name, 'path' => 'assets/Root/'.$folder->name.'/'.$name]);
    $asset->directories()->attach($folder->id);
    DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'caption' => 'x', 'caption_model' => $model, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($terms as $term) {
        DB::table('dam_ai_tags')->insert(['asset_id' => $asset->id, 'term' => $term, 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $asset;
}

/** This test's rows of the queue, as "asset name/term". */
function reviewRows(object $test, array $query = [], array $headers = [], string $route = 'admin.dam.ai-review.index'): array
{
    $response = $test->withHeaders($headers)->getJson(route($route, $query))->assertOk();
    $data = $response->json('data.data') ?? $response->json('data');

    return collect($data)->filter(fn ($row) => str_starts_with($row['asset_name'], $test->prefix))
        ->map(fn ($row) => substr($row['asset_name'], strlen($test->prefix)).'/'.$row['term'])->values()->all();
}

beforeEach(function () {
    $this->loginAsAdmin();
    $this->prefix = Str::lower(Str::random(5)).'-';
    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);
    $this->folder = Directory::factory()->create(['name' => 'Review '.Str::random(4), 'parent_id' => $root->id]);
    $this->hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $root->id]);
});

it('lists only files the reviewer can see, needs dam.asset.tag, and groups by term or by file', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();
    $a = reviewAsset($this->folder, $this->prefix.'a.jpg', ['photo', 'logo']);
    $b = reviewAsset($this->folder, $this->prefix.'b.jpg', ['photo', 'chart']);
    reviewAsset($this->hiddenFolder, $this->prefix.'secret.jpg', ['photo', 'logo']);

    $keys = collect(config('acl'))->pluck('key');
    $tagger = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => $keys->all()])->id]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $tagger->role_id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($tagger, 'admin');
    app(DirectoryPermissionService::class)->flush();

    expect(reviewRows($this))->toBe(['b.jpg/chart', 'a.jpg/logo', 'a.jpg/photo', 'b.jpg/photo'])
        ->and(reviewRows($this, ['group' => 'asset']))->toBe(['a.jpg/logo', 'a.jpg/photo', 'b.jpg/chart', 'b.jpg/photo'])
        ->and(reviewRows($this, ['term' => 'photo']))->toBe(['a.jpg/photo', 'b.jpg/photo'])
        ->and(reviewRows($this, ['folder' => $this->folder->id]))->toHaveCount(4);
    // Counts per term cover visible files only.
    $page = $this->getJson(route('admin.dam.ai-review.index'))->json();
    expect(collect($page['terms'])->firstWhere('term', 'photo')['count'] ?? null)->toBe(2)
        ->and(json_encode($page))->not->toContain('secret.jpg')
        ->and($this->getJson(route('admin.dam.ai-review.index', ['folder' => $this->hiddenFolder->id]))->status())->toBe(404)
        ->and($this->getJson(route('admin.dam.ai-review.index', ['folder' => 999999999]))->status())->toBe(404);

    // Bulk on a hidden file answers not_found, exactly like a missing one, and changes nothing.
    $secretId = Asset::where('file_name', $this->prefix.'secret.jpg')->value('id');
    $result = $this->postJson(route('admin.dam.ai-review.bulk'), ['action' => 'accept', 'items' => [['asset_id' => $secretId, 'term' => 'photo'], ['asset_id' => 999999999, 'term' => 'photo']]])->assertOk()->json();
    expect(array_column($result['results'], 'outcome'))->toBe(['not_found', 'not_found'])
        ->and(DB::table('dam_ai_tags')->where('asset_id', $secretId)->where('status', 'suggested')->count())->toBe(2);

    $this->get(route('admin.dam.ai-review.index'))->assertOk()->assertSee('v-dam-ai-review', false);

    // Without dam.asset.tag: neither the page nor bulk.
    $viewer = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => $keys->reject(fn ($key) => $key === 'dam.asset.tag')->values()->all()])->id]);
    $this->actingAs($viewer, 'admin');
    $this->getJson(route('admin.dam.ai-review.index'))->assertForbidden();
    $this->postJson(route('admin.dam.ai-review.bulk'), ['action' => 'accept', 'items' => [['asset_id' => $a->id, 'term' => 'photo']]])->assertForbidden();
    expect(DB::table('dam_ai_tags')->where('asset_id', $b->id)->value('status'))->toBe('suggested');
});

it('bulk-accepts with source ai:<model> and keeps bulk dismissals through a re-index', function () {
    $a = reviewAsset($this->folder, $this->prefix.'a.jpg', ['photo', 'logo']);
    $b = reviewAsset($this->folder, $this->prefix.'b.jpg', ['photo'], model: null);

    $accepted = $this->postJson(route('admin.dam.ai-review.bulk'), ['action' => 'accept', 'items' => [['asset_id' => $a->id, 'term' => 'photo'], ['asset_id' => $b->id, 'term' => 'photo'], ['asset_id' => $a->id, 'term' => 'photo']]])
        ->assertOk()->json();
    expect($accepted['done'])->toBe(2)
        ->and(array_column($accepted['results'], 'outcome'))->toBe(['accepted', 'accepted', 'not_open'])
        ->and($accepted['message'])->toBe('Accepted 2 tags.')
        ->and(DB::table('dam_asset_tag')->where('asset_id', $a->id)->value('source'))->toBe('ai:qwen3.5:9b')
        ->and(DB::table('dam_asset_tag')->where('asset_id', $b->id)->value('source'))->toBe('ai');

    $this->postJson(route('admin.dam.ai-review.bulk'), ['action' => 'dismiss', 'items' => [['asset_id' => $a->id, 'term' => 'logo']]])->assertOk()->assertJson(['done' => 1]);
    expect(reviewRows($this))->toBe([]);

    // Re-index: the bridge proposes the same terms again, and neither decision is reopened.
    config(['ai-index.api' => 'http://bridge.test']);
    $this->app->instance(MediaSampler::class, new class extends MediaSampler
    {
        public function sample(Asset $asset): MediaSample
        {
            return new MediaSample(images: ['jpeg'], pages: [], kind: 'image', sha256: str_repeat('c', 64));
        }
    });
    Http::fake([
        'bridge.test/caption' => Http::response(['caption' => 'x', 'tags' => ['photo', 'logo'], 'keywords' => [], 'visible_text' => '', 'model' => 'qwen3.5:9b', 'prompt_version' => 'v']),
        'bridge.test/embed'   => fn ($request) => Http::response(['vectors' => array_map(fn () => [1, 0, 0], $request['texts']), 'model' => 'e']),
    ]);
    (new AiIndexAsset($a->id))->handle(app(AiIndexStore::class), app(MediaSampler::class), app(AiBridgeClient::class));

    expect(DB::table('dam_ai_tags')->where('asset_id', $a->id)->orderBy('term')->pluck('status', 'term')->all())->toBe(['logo' => 'dismissed', 'photo' => 'accepted'])
        ->and(reviewRows($this))->toBe([]);
});

it('answers REST like the Library under api.dam.ai_review scopes', function () {
    $a = reviewAsset($this->folder, $this->prefix.'a.jpg', ['photo', 'logo']);
    $web = reviewRows($this, ['group' => 'asset']);

    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.ai_review', 'api.dam.ai_review.bulk']);
    expect(reviewRows($this, ['group' => 'asset'], $headers, 'admin.api.dam.ai-review.index'))->toBe($web);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.ai-review.bulk'), ['action' => 'accept', 'items' => [['asset_id' => $a->id, 'term' => 'logo']]])
        ->assertOk()->assertJsonPath('data.done', 1);
    expect(DB::table('dam_asset_tag')->where('asset_id', $a->id)->value('source'))->toBe('ai:qwen3.5:9b');

    app('auth')->forgetGuards();
    $readOnly = $this->getAuthenticationHeaders('custom', ['api.dam.ai_review']);
    $this->withHeaders($readOnly)->postJson(route('admin.api.dam.ai-review.bulk'), ['action' => 'accept', 'items' => [['asset_id' => $a->id, 'term' => 'photo']]])->assertForbidden();
});

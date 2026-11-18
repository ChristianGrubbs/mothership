<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\Jobs\AiReindexLexical;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Tag;

/*
 * P4 D8: a global tag rename, merge or delete, and a folder bulk-tag, queue the lexical re-index of every affected
 * asset in chunks, and search reflects the change once that one queue cycle has run.
 */

beforeEach(function () {
    config(['ai-index.index_enabled' => true, 'ai-index.search_enabled' => true, 'ai-index.lexical' => 'like']);
    $this->loginAsAdmin();
    $this->library = Directory::factory()->create(['name' => 'Library', 'parent_id' => null]);
});

/** Asset ids the Library's smart search returns for a word. */
function tagReindexSearch(string $word): array
{
    return array_column(test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => $word]))->assertOk()->json('assets'), 'id');
}

/** Runs every queued lexical job once, as one queue cycle would, and returns the asset ids they covered. */
function tagReindexRunQueued(): array
{
    $covered = [];

    Queue::pushed(AiReindexLexical::class)->each(function (AiReindexLexical $job) use (&$covered) {
        $job->handle(app(AiIndexStore::class));
        $covered = array_merge($covered, $job->assetIds);
    });

    sort($covered);

    return $covered;
}

/** Files the assets in the Library folder (the explorer lists filed assets) and indexes their words. */
function tagReindexIndexed(Asset ...$assets): void
{
    foreach ($assets as $asset) {
        if (! $asset->directories()->exists()) {
            $asset->directories()->attach(test()->library->id);
        }

        app(AiIndexStore::class)->refreshLexical($asset->fresh());
    }
}

it('re-indexes every asset of a renamed tag, so search finds the new name and not the old', function () {
    $tag = Tag::create(['name' => 'zephyrfest']);
    $assets = Asset::factory()->count(3)->create();
    $other = Asset::factory()->create();
    $tag->assets()->attach($assets->pluck('id'));
    tagReindexIndexed(...$assets->all(), ...[$other]);
    expect(tagReindexSearch('zephyrfest'))->toHaveCount(3);

    Queue::fake([AiReindexLexical::class]);
    $this->putJson(route('admin.dam.tags.update', $tag->id), ['name' => 'quasarweek'])->assertOk();

    Queue::assertPushedOn('ai-index', AiReindexLexical::class);
    // Before the queue runs, the stale index still answers for the old name.
    expect(tagReindexSearch('zephyrfest'))->toHaveCount(3)
        ->and(tagReindexRunQueued())->toBe($assets->pluck('id')->sort()->values()->all())
        ->and(tagReindexSearch('zephyrfest'))->toBe([])
        ->and(tagReindexSearch('quasarweek'))->toHaveCount(3);
});

it('re-indexes the assets of a deleted tag, one at a time or in a mass delete, collected before the cascade', function () {
    $single = Tag::create(['name' => 'nebulagala']);
    $mass = Tag::create(['name' => 'cometball']);
    $a = Asset::factory()->create();
    $b = Asset::factory()->create();
    $single->assets()->attach($a->id);
    $mass->assets()->attach($b->id);
    tagReindexIndexed($a, $b);

    Queue::fake([AiReindexLexical::class]);
    $this->deleteJson(route('admin.dam.tags.destroy', $single->id))->assertOk();
    $this->postJson(route('admin.dam.tags.mass_delete'), ['indices' => [$mass->id]])->assertOk();

    expect(tagReindexSearch('nebulagala'))->toBe([$a->id])
        ->and(tagReindexRunQueued())->toBe([$a->id, $b->id])
        ->and(tagReindexSearch('nebulagala'))->toBe([])
        ->and(tagReindexSearch('cometball'))->toBe([]);
});

it('re-indexes the assets of a tag deleted over REST', function () {
    $headers = $this->getAuthenticationHeaders();
    $tag = Tag::create(['name' => 'auroranight']);
    $asset = Asset::factory()->create();
    $tag->assets()->attach($asset->id);
    tagReindexIndexed($asset);

    Queue::fake([AiReindexLexical::class]);
    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.tags.destroy', $tag->id))->assertOk();

    expect(tagReindexRunQueued())->toBe([$asset->id])
        ->and(tagReindexSearch('auroranight'))->toBe([]);
});

it('re-indexes every asset a folder bulk-tag reached, 200 per job, and search finds them', function () {
    $root = Directory::factory()->create(['name' => 'Gala', 'parent_id' => null]);
    $child = Directory::factory()->create(['name' => 'Night', 'parent_id' => $root->id]);
    $assets = Asset::factory()->count(205)->create();
    $assets->take(100)->each(fn (Asset $asset) => $asset->directories()->attach($root->id));
    $assets->skip(100)->each(fn (Asset $asset) => $asset->directories()->attach($child->id));
    tagReindexIndexed($assets->first(), $assets->last());

    Queue::fake([AiReindexLexical::class]);
    $this->postJson(route('admin.dam.assets.mass_assign_tags'), ['directory_ids' => [$root->id], 'tags' => ['pulsarparade']])
        ->assertOk()->assertJsonPath('count', 205);

    $jobs = Queue::pushed(AiReindexLexical::class);
    expect($jobs->map(fn ($job) => count($job->assetIds))->sort()->values()->all())->toBe([5, 200])
        ->and(tagReindexRunQueued())->toBe($assets->pluck('id')->sort()->values()->all())
        ->and(DB::table('dam_ai_index')->where('lexical_text', 'like', '%pulsarparade%')->count())->toBe(205);
});

it('merges one tag into another, keeps removals, and re-indexes both sets of assets', function () {
    $from = Tag::create(['name' => 'gammafair']);
    $into = Tag::create(['name' => 'deltafair']);
    [$a, $b, $c] = Asset::factory()->count(3)->create()->all();
    $from->assets()->attach([$a->id, $b->id]);
    $into->assets()->attach($c->id);
    // A person removed deltafair from $b: the merge must not put it back.
    DB::table('dam_asset_tag_tombstones')->insert(['asset_id' => $b->id, 'tag_id' => $into->id, 'removed_at' => now()]);
    tagReindexIndexed($a, $b, $c);

    $this->artisan('dam:tags:merge', ['from' => $from->id, 'into' => $into->id, '--dry-run' => true])->assertSuccessful();
    expect(Tag::query()->whereKey($from->id)->exists())->toBeTrue();

    Queue::fake([AiReindexLexical::class]);
    $this->artisan('dam:tags:merge', ['from' => $from->id, 'into' => $into->id])->assertSuccessful();

    expect(tagReindexRunQueued())->toBe([$a->id, $b->id, $c->id])
        ->and(Tag::query()->whereKey($from->id)->exists())->toBeFalse()
        ->and(tagReindexSearch('gammafair'))->toBe([])
        ->and(tagReindexSearch('deltafair'))->toEqualCanonicalizing([$a->id, $c->id]);
});

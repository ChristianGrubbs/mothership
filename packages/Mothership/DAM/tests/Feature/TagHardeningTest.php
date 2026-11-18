<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\Database\Backfill\TagSourceBackfill;
use Mothership\DAM\Jobs\AiReindexLexical;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Tags\AssetTagWriter;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P4 D8 tag hardening: `source`, removals that stick (tombstones), TagNameRule on every path, and dam.asset.tag.
 * Search here is the lexical index (dam_ai_index.lexical_text) that smart search ranks by, rebuilt synchronously.
 */

function tagHardeningLexical(Asset $asset): string
{
    app(AiIndexStore::class)->refreshLexical($asset->fresh());

    return mb_strtolower((string) DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('lexical_text'));
}

function tagHardeningTags(Asset $asset): array
{
    return DB::table('dam_asset_tag')->join('dam_tags', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
        ->where('dam_asset_tag.asset_id', $asset->id)
        ->pluck('dam_asset_tag.source', 'dam_tags.name')
        ->sortKeysUsing(fn ($a, $b) => strcasecmp((string) $a, (string) $b))->all();
}

beforeEach(function () {
    config(['dam-tags.denylist' => ['badword']]);
    $this->asset = Asset::factory()->create(['file_name' => 'mascot.jpg']);
});

it('records who put each tag on: manual by hand, ai from an accepted suggestion', function () {
    $this->loginAsAdmin();
    DB::table('dam_ai_tags')->insert(['asset_id' => $this->asset->id, 'term' => 'photo', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);

    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'homecoming'])->assertCreated();
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo'])->assertOk();

    expect(tagHardeningTags($this->asset))->toBe(['homecoming' => 'manual', 'Photo' => 'ai']);
});

it('keeps a removed tag off through re-index, AI accept, bulk add, folder bulk add, import and rules, and out of search', function () {
    $this->loginAsAdmin();
    $folder = Directory::factory()->create(['name' => 'Events', 'parent_id' => null]);
    $this->asset->directories()->attach($folder->id);
    DB::table('dam_ai_index')->insert(['asset_id' => $this->asset->id, 'status' => 'done', 'keywords' => json_encode(['photo', 'stadium']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_tags')->insert(['asset_id' => $this->asset->id, 'term' => 'photo', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);

    // Accepted from AI, then a person takes it off.
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo'])->assertOk();
    expect(tagHardeningLexical($this->asset))->toContain('photo');

    $this->postJson(route('admin.dam.assets.remove-tag'), ['asset_id' => $this->asset->id, 'tag' => 'Photo'])->assertCreated();
    $photo = Tag::query()->where('name', 'Photo')->firstOrFail();

    expect(DB::table('dam_asset_tag_tombstones')->where('asset_id', $this->asset->id)->where('tag_id', $photo->id)->value('removed_by'))
        ->toBe(auth('admin')->id());

    // Every machine or bulk writer leaves it off.
    (new AiReindexLexical([$this->asset->id]))->handle(app(AiIndexStore::class));
    $this->postJson(route('admin.dam.assets.mass_assign_tags'), ['indices' => [$this->asset->id], 'tags' => ['Photo']])->assertOk();
    $this->postJson(route('admin.dam.assets.mass_assign_tags'), ['directory_ids' => [$folder->id], 'tags' => ['photo']])->assertOk();
    $writer = app(AssetTagWriter::class);
    $writer->addRespectingRemovals(assetIds: [$this->asset->id], tagIds: [$photo->id], source: AssetTagWriter::SOURCE_IMPORT);
    $writer->addRespectingRemovals(assetIds: [$this->asset->id], tagIds: [$photo->id], source: AssetTagWriter::SOURCE_RULE);
    // A fresh AI suggestion of the same word is neither offered nor acceptable.
    DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->update(['status' => 'suggested']);
    expect(app(AiIndexStore::class)->suggestedTags($this->asset->id))->toBe([]);
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo'])->assertStatus(422);

    // Gone from every band: human tags, AI labels and AI keywords; other keywords stay.
    $lexical = tagHardeningLexical($this->asset);

    expect(tagHardeningTags($this->asset))->toBe([])
        ->and($lexical)->not->toContain('photo')
        ->and($lexical)->toContain('stadium');
});

it('lets a person put a removed tag back by hand on that asset, which clears the removal', function () {
    $this->loginAsAdmin();
    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'parade'])->assertCreated();
    $this->postJson(route('admin.dam.assets.remove-tag'), ['asset_id' => $this->asset->id, 'tag' => 'parade'])->assertCreated();
    expect(DB::table('dam_asset_tag_tombstones')->count())->toBe(1);

    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'Parade'])->assertCreated();

    expect(DB::table('dam_asset_tag_tombstones')->count())->toBe(0)
        ->and(tagHardeningTags($this->asset))->toBe(['parade' => 'manual'])
        ->and(tagHardeningLexical($this->asset))->toContain('parade');
});

it('records REST removals and re-adds the same way, including a whole-set replace', function () {
    $headers = $this->getAuthenticationHeaders();
    $keep = Tag::create(['name' => 'keep']);
    $drop = Tag::create(['name' => 'drop']);
    DB::table('dam_asset_tag')->insert([['asset_id' => $this->asset->id, 'tag_id' => $keep->id], ['asset_id' => $this->asset->id, 'tag_id' => $drop->id]]);

    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.update', $this->asset->id), ['tags' => [$keep->id]])->assertOk();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.tags.bulk_assign'), ['asset_ids' => [$this->asset->id], 'tags' => ['drop']])->assertOk();
    expect(tagHardeningTags($this->asset))->toBe(['keep' => 'manual']);

    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.tag.delete'), ['asset_id' => $this->asset->id, 'tag' => 'keep'])->assertCreated();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.tag.add'), ['asset_id' => $this->asset->id, 'tag' => 'drop'])->assertCreated();

    expect(tagHardeningTags($this->asset))->toBe(['drop' => 'manual'])
        ->and(DB::table('dam_asset_tag_tombstones')->where('asset_id', $this->asset->id)->pluck('tag_id')->all())->toBe([$keep->id]);
});

it('normalizes a tag name and refuses a denylisted one on every path with a neutral 422', function () {
    $this->loginAsAdmin();
    $headers = $this->getAuthenticationHeaders();
    $neutral = trans('dam::app.admin.dam.tag.name-refused');
    $existing = Tag::create(['name' => 'fine']);

    $refusals = [
        'web add'    => fn () => $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'Bad Word']),
        'web bulk'   => fn () => $this->postJson(route('admin.dam.assets.mass_assign_tags'), ['indices' => [$this->asset->id], 'tags' => ['ok', 'B4DWORD']]),
        'tags page'  => fn () => $this->postJson(route('admin.dam.tags.store'), ['name' => 'some badword here']),
        'rename'     => fn () => $this->putJson(route('admin.dam.tags.update', $existing->id), ['name' => 'b a d w o r d']),
        'REST add'   => fn () => $this->withHeaders($headers)->postJson(route('admin.api.dam.tag.add'), ['asset_id' => $this->asset->id, 'tag' => 'badword']),
        'REST bulk'  => fn () => $this->withHeaders($headers)->postJson(route('admin.api.dam.tags.bulk_assign'), ['asset_ids' => [$this->asset->id], 'tags' => ['Badword!']]),
    ];

    foreach ($refusals as $path => $request) {
        $response = $request();
        expect($response->status())->toBe(422, $path)
            ->and($response->json('message'))->toBe($neutral, $path)
            ->and($response->getContent())->not->toContain('adword', $path);
    }

    // AI accept: a vocabulary label the denylist covers (written past the vocabulary screen, which refuses it too).
    DB::table('dam_ai_vocabulary')->insert(['term' => 'rude', 'label' => 'Badword', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_tags')->insert(['asset_id' => $this->asset->id, 'term' => 'rude', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'rude'])->assertStatus(422)->assertJsonPath('message', $neutral);

    // Import (and any other machine writer) names tags through the writer's resolve().
    expect(fn () => app(AssetTagWriter::class)->resolve(['badword'], attribute: 'tags'))
        ->toThrow(ValidationException::class, $neutral);

    expect(Tag::query()->pluck('name')->all())->toBe(['fine'])
        ->and(DB::table('dam_asset_tag')->count())->toBe(0)
        ->and(DB::table('dam_ai_tags')->where('term', 'rude')->value('status'))->toBe('suggested')
        ->and($existing->fresh()->name)->toBe('fine');

    // Normalized, not refused: whitespace collapsed, and a whole word is needed ("badwording" is not "badword").
    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => "  Spring \t  Fling  "])->assertCreated();
    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'badwording'])->assertCreated();
    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => str_repeat('x', 101)])->assertStatus(422);

    expect(array_keys(tagHardeningTags($this->asset)))->toBe(['badwording', 'Spring Fling']);
});

it('ships a default denylist that refuses profanity, look-alike spellings included, and leaves place names alone', function () {
    config(['dam-tags.denylist' => (require base_path('packages/Mothership/DAM/src/Config/tags.php'))['denylist']]);
    $this->loginAsAdmin();

    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'Fucking great'])->assertStatus(422);
    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'sh1t'])->assertStatus(422);
    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'Scunthorpe'])->assertCreated();
});

it('needs dam.asset.tag to tag or untag, and not dam.asset.update', function () {
    $folder = Directory::factory()->create(['parent_id' => null]);
    $this->asset->directories()->attach($folder->id);
    $tag = Tag::create(['name' => 'kept']);
    DB::table('dam_asset_tag')->insert(['asset_id' => $this->asset->id, 'tag_id' => $tag->id]);
    DB::table('dam_ai_tags')->insert(['asset_id' => $this->asset->id, 'term' => 'photo', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);
    $role = function (array $extra) use ($folder) {
        $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => array_merge(['dam', 'dam.asset', 'dam.asset.view'], $extra)]);
        DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);

        return $role;
    };

    $editor = Admin::factory()->create(['role_id' => $role(['dam.asset.update', 'dam.asset.edit'])->id]);
    $this->actingAs($editor, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'new'])->assertForbidden();
    $this->postJson(route('admin.dam.assets.remove-tag'), ['asset_id' => $this->asset->id, 'tag' => 'kept'])->assertForbidden();
    $this->postJson(route('admin.dam.assets.mass_assign_tags'), ['indices' => [$this->asset->id], 'tags' => ['new']])->assertForbidden();
    $this->postJson(route('admin.dam.assets.ai-tags.accept', $this->asset->id), ['term' => 'photo'])->assertForbidden();
    $this->postJson(route('admin.dam.assets.ai-tags.dismiss', $this->asset->id), ['term' => 'photo'])->assertForbidden();
    expect(tagHardeningTags($this->asset))->toBe(['kept' => 'manual']);

    $tagger = Admin::factory()->create(['role_id' => $role(['dam.asset.tag'])->id]);
    $this->actingAs($tagger, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'new'])->assertCreated();
    $this->postJson(route('admin.dam.assets.remove-tag'), ['asset_id' => $this->asset->id, 'tag' => 'kept'])->assertCreated();

    expect(tagHardeningTags($this->asset))->toBe(['new' => 'manual']);
});

it('gives dam.asset.tag to every custom role that could edit assets, and takes it back on rollback', function () {
    $editor = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.update']]);
    $viewer = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']]);
    $migration = require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_10_07_001003_grant_dam_asset_tag_permission.php');

    $migration->up();
    $migration->up();

    expect($editor->fresh()->permissions)->toBe(['dam', 'dam.asset', 'dam.asset.update', 'dam.asset.tag'])
        ->and($viewer->fresh()->permissions)->toBe(['dam', 'dam.asset', 'dam.asset.view']);

    // The granted editor tags without any other change.
    $folder = Directory::factory()->create(['parent_id' => null]);
    $this->asset->directories()->attach($folder->id);
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $editor->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs(Admin::factory()->create(['role_id' => $editor->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $this->asset->id, 'tag' => 'granted'])->assertCreated();

    $migration->down();
    expect($editor->fresh()->permissions)->toBe(['dam', 'dam.asset', 'dam.asset.update']);
});

it('backfills source from accepted AI suggestions, reconciles, and keeps nothing on a dry run', function () {
    $photo = Tag::create(['name' => 'Photo']);
    $hand = Tag::create(['name' => 'by hand']);
    $other = Asset::factory()->create();
    DB::table('dam_asset_tag')->insert([
        ['asset_id' => $this->asset->id, 'tag_id' => $photo->id],
        ['asset_id' => $this->asset->id, 'tag_id' => $hand->id],
        ['asset_id' => $other->id, 'tag_id' => $photo->id],
    ]);
    DB::table('dam_ai_tags')->insert([
        ['asset_id' => $this->asset->id, 'term' => 'photo', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
        ['asset_id' => $other->id, 'term' => 'photo', 'status' => 'dismissed', 'created_at' => now(), 'updated_at' => now()],
        ['asset_id' => $other->id, 'term' => 'retired-term', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $dry = app(TagSourceBackfill::class)->run(dryRun: true);
    expect($dry['to_relabel'])->toBe(1)->and($dry['unmatched'])->toBe(1)->and($dry['violations'])->toBe([])
        ->and(DB::table('dam_asset_tag')->where('source', 'ai')->count())->toBe(0);

    $this->artisan('dam:tags:backfill-source')->assertSuccessful();
    $again = app(TagSourceBackfill::class)->run();

    expect(tagHardeningTags($this->asset))->toBe(['by hand' => 'manual', 'Photo' => 'ai'])
        ->and(tagHardeningTags($other))->toBe(['Photo' => 'manual'])
        ->and($again['relabelled'])->toBe(0)
        ->and($again['ai_after'] + $again['manual_after'])->toBe($again['rows_before']);
});

it('rolls the P5c tag source back to P5b\'s 64 characters, keeping a versioned rule source', function () {
    $migration = require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_10_07_001702_widen_dam_asset_tag_source.php');
    $rule = Tag::create(['name' => 'Rule written']);
    $model = Tag::create(['name' => 'Model written']);
    $ruleSource = 'rule:123456789012@v123456789';
    DB::table('dam_asset_tag')->insert([
        ['asset_id' => $this->asset->id, 'tag_id' => $rule->id, 'source' => $ruleSource],
        ['asset_id' => $this->asset->id, 'tag_id' => $model->id, 'source' => 'ai:'.str_repeat('m', 70)],
    ]);

    // On PostgreSQL a narrower column refuses the rule source, so the rollback itself fails.
    $migration->down();

    expect(tagHardeningTags($this->asset))->toBe(['Model written' => 'ai', 'Rule written' => $ruleSource]);

    if (DB::getDriverName() === 'pgsql') {
        $length = DB::table('information_schema.columns')->where('table_name', 'dam_asset_tag')->where('column_name', 'source')->value('character_maximum_length');
        expect((int) $length)->toBe(64);
    }

    $migration->up();
});

it('rolls P5c then P5b back to the 16-character tag source, folding every machine source first', function () {
    $p5c = require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_10_07_001702_widen_dam_asset_tag_source.php');
    $p5b = require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_10_07_001600_add_value_provenance_to_dam_values.php');
    $sources = [
        'Long rule'    => 'rule:123456789012@v123456789',
        'Long mapping' => 'mapping:'.str_repeat('9', 40),
        'Long model'   => 'ai:'.str_repeat('m', 70),
        'By hand'      => 'manual',
        'Plain ai'     => 'ai',
        'Plain rule'   => 'rule',
        'Imported'     => 'import',
    ];
    foreach ($sources as $name => $source) {
        DB::table('dam_asset_tag')->insert(['asset_id' => $this->asset->id, 'tag_id' => Tag::create(['name' => $name])->id, 'source' => $source]);
    }

    $p5c->down();
    $p5b->down();

    expect(tagHardeningTags($this->asset))->toBe([
        'By hand'   => 'manual', 'Imported' => 'import', 'Long mapping' => 'import', 'Long model' => 'ai',
        'Long rule' => 'rule', 'Plain ai' => 'ai', 'Plain rule' => 'rule',
    ]);

    if (DB::getDriverName() === 'pgsql') {
        $length = DB::table('information_schema.columns')->where('table_name', 'dam_asset_tag')->where('column_name', 'source')->value('character_maximum_length');
        expect((int) $length)->toBe(16);
    }

    $p5b->up();
    $p5c->up();
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\AiIndex\SmartSearch;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

// The only test that runs the full-text and trigram leg; every other search test forces the LIKE fallback.
it('finds by stemming, accents and typos through PostgreSQL full-text and trigram search', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Needs PostgreSQL (tsvector, pg_trgm, unaccent).');
    }

    // An 'all' role keeps the HTTP leg deterministic even on a database the installer never seeded.
    $this->loginWithPermissions(permissionType: 'all');
    config(['ai-index.search_enabled' => true, 'ai-index.api' => null, 'ai-index.lexical' => 'auto']);

    $directory = Directory::factory()->create(['name' => 'Library', 'parent_id' => null]);
    $band = Asset::factory()->create(['file_name' => 'IMG_0001.jpg']);
    $parking = Asset::factory()->create(['file_name' => 'IMG_0002.jpg']);
    $menu = Asset::factory()->create(['file_name' => 'Café-menu.pdf', 'extension' => 'pdf']);
    $graduate = Asset::factory()->create(['file_name' => 'IMG_0003.jpg']);
    $store = app(AiIndexStore::class);

    foreach ([[$band, 'The marching band plays for students at the homecoming game'], [$parking, 'An empty parking lot at dusk'], [$menu, null], [$graduate, 'A happy graduate holds a diploma']] as [$asset, $caption]) {
        $asset->directories()->attach($directory->id);
        DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'caption' => $caption, 'created_at' => now(), 'updated_at' => now()]);
        $store->refreshLexical($asset);
    }

    // Stemming must be what finds this one: trigram similarity and LIKE both miss it.
    $lexicalText = (string) DB::table('dam_ai_index')->where('asset_id', $graduate->id)->value('lexical_text');
    expect((float) DB::selectOne("SELECT word_similarity(unaccent('happiness'), ?) AS score", [$lexicalText])->score)->toBeLessThan(0.5)
        ->and(stripos($lexicalText, 'happiness'))->toBeFalse();

    // A short word must not match every word that merely shares its first letters ('map' vs 'marching' scores 0.5).
    expect((float) DB::selectOne("SELECT word_similarity('map', 'marching') AS score")->score)->toBeGreaterThanOrEqual(0.5)
        ->and((float) DB::selectOne("SELECT word_similarity('map', 'marching') AS score")->score)->toBeLessThan((float) config('ai-index.trigram_threshold'));

    $ids = fn (string $search) => array_column(
        test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 50, 'search' => $search]))->assertOk()->json('assets'),
        'id'
    );

    expect($ids('bands')[0] ?? null)->toBe($band->id)
        ->and($ids('cafe'))->toContain($menu->id)
        ->and($ids('marchng')[0] ?? null)->toBe($band->id)
        ->and($ids('happiness'))->toBe([$graduate->id])
        ->and($ids('map'))->toBe([])
        ->and($ids('nothingmatches'))->toBe([]);

    // Same ranking without the HTTP layer, so a redirecting environment still proves the SQL.
    $rank = fn (string $query) => array_column(app(SmartSearch::class)->rank(viewer: auth()->guard('admin')->user(), candidates: Asset::query(), query: $query), 'id');

    expect($rank('bands')[0] ?? null)->toBe($band->id)
        ->and($rank('cafe'))->toContain($menu->id)
        ->and($rank('marchng')[0] ?? null)->toBe($band->id)
        ->and($rank('happiness'))->toBe([$graduate->id])
        ->and($rank('map'))->toBe([])
        ->and($rank('nothingmatches'))->toBe([]);

    // The threshold is configuration, not a constant: at the old 0.5 (given as an env-style string) 'map' finds the band again.
    config(['ai-index.trigram_threshold' => '0.5']);
    expect($rank('map'))->toContain($band->id);
});

it('prioritizes exact PostgreSQL card and member filenames before strong semantic and trigram hits', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Needs PostgreSQL (tsvector, pg_trgm, unaccent).');
    }
    $this->loginWithPermissions(permissionType: 'all');
    config(['ai-index.search_enabled' => true, 'ai-index.api' => 'http://bridge.test', 'ai-index.lexical' => 'auto']);
    Http::fake(['bridge.test/embed' => Http::response(['vectors' => [[1, 0]], 'model' => 'm'])]);
    $semantic = Asset::factory()->create(['file_name' => 'semantic.jpg']);
    DB::table('dam_ai_index')->insert(['asset_id' => $semantic->id, 'status' => 'done', 'caption' => 'Exact-member.png report.pdf', 'created_at' => now(), 'updated_at' => now()]);
    app(AiIndexStore::class)->refreshLexical($semantic);
    DB::table('dam_ai_vectors')->insert(['asset_id' => $semantic->id, 'kind' => 'summary', 'vector' => VectorCodec::encode([1, 0]), 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    $exact = Asset::factory()->create(['file_name' => 'Report.PDF']);
    $owner = Asset::factory()->create(['file_name' => 'owner.png']);
    foreach (range(1, 2) as $i) {
        Asset::factory()->create(['file_name' => 'Exact-member.png', 'merged_into_id' => $owner->id]);
    }
    $candidates = Asset::query()->whereNull('merged_into_id');
    $rank = fn (string $query) => array_column(app(SmartSearch::class)->rank(viewer: auth()->guard('admin')->user(), candidates: $candidates, query: $query), 'id');
    expect($rank(' report.pdf '))->toBe([$exact->id, $semantic->id])
        ->and($rank(' EXACT-MEMBER.PNG '))->toBe([$owner->id, $semantic->id]);
});

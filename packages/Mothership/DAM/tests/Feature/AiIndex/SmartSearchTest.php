<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\AiIndex\LexicalDocument;
use Mothership\DAM\AiIndex\SmartSearch;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;

function smartIndexed(Asset $asset, string $lexical, array $vector, array $pages = []): void
{
    DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'lexical_text' => $lexical, 'caption' => $lexical, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'page' => null, 'vector' => VectorCodec::encode($vector), 'text' => $lexical, 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);

    foreach ($pages as $page => [$pageVector, $text]) {
        DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'pdf_page', 'page' => $page, 'vector' => VectorCodec::encode($pageVector), 'text' => $text, 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    }
}

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    $this->loginWithPermissions(permissionType: 'all');
    config(['ai-index.search_enabled' => true, 'ai-index.api' => 'http://bridge.test', 'ai-index.lexical' => 'like']);
    $this->dir = Directory::factory()->create(['name' => 'Library', 'parent_id' => null]);
    $this->mascot = Asset::factory()->create(['file_name' => 'IMG_0001.jpg']);
    $this->parking = Asset::factory()->create(['file_name' => 'IMG_0002.jpg']);
    $this->handbook = Asset::factory()->create(['file_name' => 'handbook.pdf']);
    // Start this fixture newest so a discarded backdate cannot pass via a timestamp tie.
    $this->plain = Asset::factory()->create(['file_name' => 'falcon-notes.txt', 'created_at' => now()->addDay()]);
    foreach ([$this->mascot, $this->parking, $this->handbook, $this->plain] as $asset) {
        $asset->directories()->attach($this->dir->id);
    }
    smartIndexed($this->mascot, 'A falcon mascot waves at the crowd', [1, 0, 0]);
    smartIndexed($this->parking, 'An empty parking lot', [0, 1, 0]);
    smartIndexed($this->handbook, 'Student handbook', [0, 0, 1], [7 => [[0.9, 0.1, 0], 'Mascot appearances are booked through Student Life.']]);
    // Http::fake matches the first stub registered, so "bridge down" is a switch, not a second fake.
    $this->bridgeDown = false;
    Http::fake(['bridge.test/embed' => fn () => $this->bridgeDown ? Http::response([], 500) : Http::response(['vectors' => [[1, 0, 0]], 'model' => 'm'])]);
});

function smartSearch(array $params = [])
{
    return test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 50, ...$params]));
}

it('finds by caption words and by meaning, best first, with AI and page hints', function () {
    $response = smartSearch(['search' => 'mascot'])->assertOk();
    $ids = array_column($response->json('assets'), 'id');

    expect($ids[0])->toBe($this->mascot->id)
        ->and($ids)->toContain($this->handbook->id)
        ->and($ids)->not->toContain($this->parking->id);

    $handbook = collect($response->json('assets'))->firstWhere('id', $this->handbook->id);
    expect($handbook['match']['page'])->toBe(7)
        ->and($handbook['match']['snippet'])->toContain('Student Life')
        ->and($handbook['match']['ai'])->toBeTrue();
});

it('finds an unindexed asset by file name with smart search on', function () {
    expect(array_column(smartSearch(['search' => 'falcon-notes'])->json('assets'), 'id'))->toContain($this->plain->id);
});

it('puts a trimmed case insensitive full filename ahead of semantic and lexical matches', function () {
    // The mascot and handbook both have stronger semantic hits; the plain file has no index row at all.
    $ids = array_column(smartSearch(['search' => '  FALCON-NOTES.TXT  '])->assertOk()->json('assets'), 'id');
    expect($ids[0])->toBe($this->plain->id)->and($ids)->toContain($this->mascot->id);

    // Name order is the database's collation order: SQLite and C compare bytes ("IMG_0001.jpg" before "falcon-notes.txt"),
    // PostgreSQL's en_US and MySQL's *_ci collations fold case (after it). Lower-casing the one capitalised fixture
    // keeps this assertion about the sort, not about which collation the test database was created with.
    $this->mascot->update(['file_name' => 'img_0001.jpg']);
    $byName = array_column(smartSearch(['search' => 'falcon-notes.txt', 'sort_by' => 'name', 'sort_order' => 'asc'])->assertOk()->json('assets'), 'id');
    expect($byName)->toBe([$this->plain->id, $this->handbook->id, $this->mascot->id]);
    // created_at is guarded against user input; fixture timestamps must bypass mass assignment.
    $this->plain->forceFill(['created_at' => now()->subYears(2)])->save();
    expect($this->plain->fresh()->created_at->equalTo(now()->subYears(2)))->toBeTrue();
    $byDate = array_column(smartSearch(['search' => 'falcon-notes.txt', 'sort_by' => 'created_at', 'sort_order' => 'desc'])->assertOk()->json('assets'), 'id');
    expect(end($byDate))->toBe($this->plain->id);
});

it('elevates the authorised owner once for exact member names even without an index', function () {
    $owner = Asset::factory()->create(['file_name' => 'stack-cover.jpg']);
    $owner->directories()->attach($this->dir->id);
    foreach (range(1, 2) as $i) {
        Asset::factory()->create(['file_name' => 'Exact-member.PNG', 'merged_into_id' => $owner->id]);
    }
    $ids = array_column(smartSearch(['search' => ' exact-MEMBER.png '])->assertOk()->json('assets'), 'id');
    expect($ids[0])->toBe($owner->id)->and(array_count_values($ids)[$owner->id])->toBe(1);
});

it('uses the same Unicode exact-name comparison for cards and members on every database', function () {
    $exact = Asset::factory()->create(['file_name' => 'Ärger.PNG']);
    $owner = Asset::factory()->create(['file_name' => 'unicode-cover.jpg']);
    foreach ([$exact, $owner] as $asset) {
        $asset->directories()->attach($this->dir->id);
    }
    Asset::factory()->create(['file_name' => 'CAFÉ-Ä.PNG', 'merged_into_id' => $owner->id]);

    expect(array_column(smartSearch(['search' => ' ärGER.png '])->assertOk()->json('assets'), 'id')[0])->toBe($exact->id)
        ->and(array_column(smartSearch(['search' => ' café-ä.png '])->assertOk()->json('assets'), 'id')[0])->toBe($owner->id);
});

it('ranks large stacks without expanding their member IDs into SQL bind parameters', function () {
    $owner = Asset::factory()->create(['file_name' => 'many-members.jpg']);
    $owner->directories()->attach($this->dir->id);
    $prototype = Asset::factory()->make(['merged_into_id' => $owner->id])->getAttributes();
    $rows = [];
    foreach (range(1, 1100) as $i) {
        $rows[] = [...$prototype, 'file_name' => $i > 1098 ? 'Ärger.PNG' : "member-{$i}.png", 'path' => "fixtures/task8-many-slot-{$i}.png"];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('dam_assets')->insert($chunk);
    }
    $members = Asset::query()->where('merged_into_id', $owner->id)->orderBy('id')->limit(2)->get();
    smartIndexed($members[0], 'slotonlylexicalphrase', [0, 1, 0]);
    smartIndexed($members[1], 'A caption unrelated to the query', [1, 0, 0]);

    $maximumBindings = 0;
    $queryCount = 0;
    DB::listen(function ($event) use (&$maximumBindings, &$queryCount) {
        $maximumBindings = max($maximumBindings, count($event->bindings));
        $queryCount++;
    });
    $started = hrtime(true);
    $ids = array_column(smartSearch(['search' => ' ärger.png '])->assertOk()->json('assets'), 'id');
    expect($ids[0])->toBe($owner->id)->and(array_count_values($ids)[$owner->id])->toBe(1);

    // With the bridge down, only the member's index text can find its owner.
    $this->bridgeDown = true;
    expect(array_column(smartSearch(['search' => 'slotonlylexicalphrase'])->assertOk()->json('assets'), 'id'))->toBe([$owner->id]);
    $this->bridgeDown = false;
    // With no matching words, the member's vector must still return the owner exactly once, never the slot.
    $semantic = smartSearch(['search' => 'semanticonlyphrase'])->assertOk()->json('assets');
    $semanticIds = array_column($semantic, 'id');
    expect($semanticIds)->toContain($owner->id)->not->toContain($members[0]->id, $members[1]->id)
        ->and(array_count_values($semanticIds)[$owner->id])->toBe(1)
        ->and(collect($semantic)->firstWhere('id', $owner->id)['match']['ai'])->toBeTrue()
        ->and($maximumBindings)->toBeLessThan(999);

    if (getenv('MOTHERSHIP_SEARCH_BENCHMARK') === '1') {
        fwrite(STDERR, json_encode(['ts' => gmdate('c'), 'run_id' => 'task8-large-stack', 'level' => 'info', 'event' => 'large_stack_search_benchmark', 'loc' => __FILE__, 'ctx' => ['driver' => DB::connection()->getDriverName(), 'members' => 1100, 'searches' => 3, 'queries' => $queryCount, 'maximum_bindings' => $maximumBindings, 'elapsed_ms' => (hrtime(true) - $started) / 1e6]], JSON_THROW_ON_ERROR).PHP_EOL);
    }
});

it('keeps only meaning matches near the best one, up to the limit', function () {
    $near = Asset::factory()->create(['file_name' => 'near.jpg']);
    $middling = Asset::factory()->create(['file_name' => 'middling.jpg']);
    $far = Asset::factory()->create(['file_name' => 'far.jpg']);
    foreach ([[$near, [0.97, 0.243, 0]], [$middling, [0.75, 0.661, 0]], [$far, [0.5, 0.866, 0]]] as [$asset, $vector]) {
        $asset->directories()->attach($this->dir->id);
        smartIndexed($asset, 'unrelated words', $vector);
    }

    // Query vector [1,0,0]: mascot 1.0, handbook p.7 0.99, near 0.97 sit inside the 0.12 window; 0.75 and 0.5 do not.
    $ids = array_column(smartSearch(['search' => 'zebra'])->json('assets'), 'id');

    expect($ids)->toBe([$this->mascot->id, $this->handbook->id, $near->id]);

    config(['ai-index.semantic_limit' => 2]);
    expect(array_column(smartSearch(['search' => 'zebra'])->json('assets'), 'id'))->toBe([$this->mascot->id, $this->handbook->id]);
});

it('drops weak meaning matches below the absolute floor even inside the window', function () {
    config(['ai-index.semantic_window' => 1.0]); // the floor alone decides
    $scores = [];
    foreach ([0.55, 0.51, 0.48] as $cosine) {
        $asset = Asset::factory()->create(['file_name' => "weak-{$cosine}.jpg"]);
        $asset->directories()->attach($this->dir->id);
        smartIndexed($asset, 'unrelated words', [$cosine, sqrt(1 - $cosine * $cosine), 0]);
        $scores[(string) $cosine] = $asset->id;
    }

    $ids = array_column(smartSearch(['search' => 'zebra'])->json('assets'), 'id');

    expect($ids)->toContain($scores['0.55'])->toContain($scores['0.51'])->not->toContain($scores['0.48']);
});

it('returns lexical results when the bridge is down', function () {
    test()->bridgeDown = true;

    expect(array_column(smartSearch(['search' => 'parking'])->assertOk()->json('assets'), 'id'))->toBe([$this->parking->id]);
});

it('keeps the smart result set under an explicit sort', function () {
    $ids = array_column(smartSearch(['search' => 'mascot', 'sort_by' => 'name', 'sort_order' => 'asc'])->json('assets'), 'id');

    expect($ids)->toContain($this->mascot->id)->not->toContain($this->parking->id);
});

it('is byte-for-byte the old search when the flag is off', function () {
    config(['ai-index.search_enabled' => false]);

    expect(array_column(smartSearch(['search' => 'mascot'])->json('assets'), 'id'))->toBe([]);

    $response = smartSearch(['search' => 'IMG_000', 'sort_by' => 'name', 'sort_order' => 'asc'])->assertOk();

    expect(array_column($response->json('assets'), 'id'))->toEqualCanonicalizing([$this->mascot->id, $this->parking->id]);
    foreach ($response->json('assets') as $row) {
        expect($row)->not->toHaveKey('match');
    }
});

it('refuses to rank without an authenticated viewer', function () {
    expect(fn () => app(SmartSearch::class)->rank(viewer: null, candidates: Asset::query(), query: 'x'))->toThrow(LogicException::class);
});

it('finds similar files by meaning without the seed, files only', function () {
    smartIndexed($this->plain, 'Another falcon mascot', [0.9, 0.1, 0]);

    $response = smartSearch(['similar_to' => $this->mascot->id])->assertOk();

    expect(array_column($response->json('assets'), 'id')[0])->toBe($this->plain->id)
        ->and(array_column($response->json('assets'), 'id'))->not->toContain($this->mascot->id)
        ->and($response->json('directories'))->toBe([]);
});

it('skips a corrupt stored vector instead of failing the search', function () {
    DB::table('dam_ai_vectors')->where('asset_id', $this->parking->id)->update(['vector' => 'not base64!']);

    $response = smartSearch(['search' => 'mascot'])->assertOk();

    expect(array_column($response->json('assets'), 'id'))->toContain($this->mascot->id, $this->handbook->id);
});

it('returns nothing for Find similar when the seed vector is corrupt', function () {
    DB::table('dam_ai_vectors')->where('asset_id', $this->mascot->id)->update(['vector' => 'not base64!']);

    $response = smartSearch(['similar_to' => $this->mascot->id])->assertOk();

    expect($response->json('assets'))->toBe([]);
});

it('ignores a corrupt candidate vector in Find similar', function () {
    DB::table('dam_ai_vectors')->where('asset_id', $this->parking->id)->update(['vector' => 'not base64!']);
    smartIndexed($this->plain, 'Another falcon mascot', [0.9, 0.1, 0]);

    $ids = array_column(smartSearch(['similar_to' => $this->mascot->id])->assertOk()->json('assets'), 'id');

    expect($ids)->toContain($this->plain->id)->not->toContain($this->parking->id);
});

it('answers Find similar like a missing seed when the flag is off', function () {
    config(['ai-index.search_enabled' => false]);

    $real = smartSearch(['similar_to' => $this->mascot->id]);
    $missing = smartSearch(['similar_to' => 999999]);

    expect($real->status())->toBe(404)->and($missing->status())->toBe(404)->and($real->json())->toBe($missing->json());
});

it('renders the Library with the Best match sort and the Find similar event wired', function () {
    config(['ai-index.search_enabled' => true, 'dam.explorer.enabled' => true]);

    $html = $this->get(route('admin.dam.assets.index'))->assertOk()->getContent();

    expect($html)->toContain('dam:find-similar')->toContain(trans('dam::app.admin.explorer.sort-relevance'))
        ->toContain('data-dam-find-similar')->toContain('data-dam-preview-find-similar');
});

it('leaves the Find similar menu items out of the Library when smart search is off', function () {
    config(['ai-index.search_enabled' => false, 'dam.explorer.enabled' => true]);

    $html = $this->get(route('admin.dam.assets.index'))->assertOk()->getContent();

    expect($html)->not->toContain('data-dam-find-similar')->not->toContain('data-dam-preview-find-similar');
});

it('does not offer preview Find similar where no explorer tab listens', function () {
    config(['ai-index.search_enabled' => true, 'dam.explorer.enabled' => true]);

    // The picker (asset fields, product bulk edit, relationships) reuses the Library lightbox without opting in.
    $picker = Blade::render("@include('dam::asset.picker-modal')\n@stack('scripts')");

    expect($picker)->toContain('v-dam-grid-preview-modal-template')->not->toContain('data-dam-preview-find-similar');
});

it('finds a word that only appears on a later PDF page, with the bridge down', function () {
    $report = Asset::factory()->create(['file_name' => 'annual-report.pdf', 'extension' => 'pdf']);
    $report->directories()->attach($this->dir->id);
    smartIndexed($report, 'Annual report', [0, 0, 1], [
        1 => [[0, 0, 1], 'Letter from the president'],
        3 => [[0, 0, 1], 'The zygomorphic flowers of the campus garden'],
    ]);
    app(AiIndexStore::class)->refreshLexical($report);
    test()->bridgeDown = true;

    expect(array_column(smartSearch(['search' => 'zygomorphic'])->assertOk()->json('assets'), 'id'))->toBe([$report->id]);
});

it('puts each property into band C as written, with no place-name expansion', function () {
    $campus = Asset::factory()->create(['file_name' => 'a.jpg']);
    $building = Asset::factory()->create(['file_name' => 'b.jpg']);
    $other = Asset::factory()->create(['file_name' => 'c.jpg']);
    foreach ([[$campus, 'Campus', 'NORTH'], [$building, 'building', 'B2'], [$other, 'Location', 'B2']] as [$asset, $name, $value]) {
        AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => $name, 'value' => $value]);
    }

    expect(LexicalDocument::fields(asset: $campus, aiRow: null)['c'])->toBe('Campus NORTH')
        ->and(LexicalDocument::fields(asset: $building, aiRow: null)['c'])->toBe('building B2')
        ->and(LexicalDocument::fields(asset: $other, aiRow: null)['c'])->toBe('Location B2')
        ->and(LexicalDocument::fields(asset: Asset::factory()->create(), aiRow: null)['c'])->toBe('');
});

it('finds a stack by a merged slot\'s description and lists the stack, never the slot', function () {
    $stack = Asset::factory()->create(['file_name' => 'DJI_0185.JPG']);
    $slot = Asset::factory()->create(['file_name' => 'DJI_0188.JPG']);
    $stack->directories()->attach($this->dir->id);
    $slot->directories()->attach($this->dir->id);
    smartIndexed($stack, 'Aerial view of a campus plaza with tents', [0, 1, 0]);
    smartIndexed($slot, 'A pickup truck parked beside the shop sign', [0.2, 0.98, 0]);
    DB::table('dam_assets')->where('id', $slot->id)->update(['merged_into_id' => $stack->id]);

    $ids = array_column(smartSearch(['search' => 'truck'])->json('assets'), 'id');

    expect($ids)->toContain($stack->id)->not->toContain($slot->id);
});

it('does not surface a stack the viewer cannot list through its merged slot', function () {
    $hidden = Directory::factory()->create(['name' => 'Elsewhere', 'parent_id' => null]);
    $stack = Asset::factory()->create(['file_name' => 'stack.jpg']);
    $slot = Asset::factory()->create(['file_name' => 'slot.jpg']);
    $stack->directories()->attach($hidden->id);
    $slot->directories()->attach($this->dir->id);
    smartIndexed($slot, 'A pickup truck parked beside the shop sign', [0, 1, 0]);
    DB::table('dam_assets')->where('id', $slot->id)->update(['merged_into_id' => $stack->id]);

    // Scoped to Library: the stack lives in another folder, so neither it nor its slot may be found here.
    $ids = array_column(test()->getJson(route('admin.dam.explorer.index', ['directory_id' => $this->dir->id, 'search' => 'truck', 'per_page' => 50]))->json('assets'), 'id');

    expect($ids)->not->toContain($stack->id)->not->toContain($slot->id);
});

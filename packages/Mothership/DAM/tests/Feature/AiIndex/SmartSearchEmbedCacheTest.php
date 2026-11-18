<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

beforeEach(function () {
    $this->loginWithPermissions(permissionType: 'all');
    config(['ai-index.search_enabled' => true, 'ai-index.api' => 'http://bridge.test', 'ai-index.lexical' => 'like']);
    Cache::flush();
    $dir = Directory::factory()->create(['name' => 'Library', 'parent_id' => null]);
    $asset = Asset::factory()->create(['file_name' => 'IMG_0001.jpg']);
    $asset->directories()->attach($dir->id);
    DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'lexical_text' => 'A falcon mascot', 'caption' => 'A falcon mascot', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'page' => null, 'vector' => VectorCodec::encode([1, 0, 0]), 'text' => 'A falcon mascot', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    // Semantic-only: neither its file name nor its indexed text matches the query words, so only the vector can surface it.
    $semantic = Asset::factory()->create(['file_name' => 'DSC_9999.jpg']);
    $semantic->directories()->attach($dir->id);
    DB::table('dam_ai_index')->insert(['asset_id' => $semantic->id, 'status' => 'done', 'lexical_text' => 'Quiet harbour at dusk', 'caption' => 'Quiet harbour at dusk', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_vectors')->insert(['asset_id' => $semantic->id, 'kind' => 'summary', 'page' => null, 'vector' => VectorCodec::encode([1, 0, 0]), 'text' => 'Quiet harbour at dusk', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    $this->semanticId = $semantic->id;
    $this->bridgeDown = false;
    Http::fake(['bridge.test/embed' => fn () => $this->bridgeDown ? Http::response([], 500) : Http::response(['vectors' => [[1, 0, 0]], 'model' => 'm'])]);
});

function embedCalls(): int
{
    return Http::recorded(fn ($request) => str_ends_with($request->url(), '/embed'))->count();
}

function embedSearch(array $params = [])
{
    return test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 50, 'search' => 'falcon mascot', ...$params]));
}

it('embeds a repeated search term once, across a second request, another page and a sort change', function () {
    embedSearch()->assertOk();
    embedSearch()->assertOk();
    embedSearch(['page' => 2])->assertOk();
    embedSearch(['sort_by' => 'name', 'sort_order' => 'desc'])->assertOk();

    expect(embedCalls())->toBe(1);
});

it('embeds a different term separately', function () {
    embedSearch()->assertOk();
    embedSearch(['search' => 'parking lot'])->assertOk();

    expect(embedCalls())->toBe(2);
});

it('does not cache an unavailable bridge, so the next request retries', function () {
    $this->bridgeDown = true;
    embedSearch()->assertOk();
    expect(embedCalls())->toBe(1);

    $this->bridgeDown = false;
    $ids = array_column(embedSearch()->assertOk()->json('assets'), 'id');
    expect(embedCalls())->toBe(2)->and($ids)->not->toBeEmpty();

    embedSearch()->assertOk();
    expect(embedCalls())->toBe(2);
});

it('keys the cache on a hash, never the raw query text', function () {
    $written = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written) {
        $written[] = ['key' => $event->key, 'seconds' => $event->seconds];
    });

    embedSearch(['search' => 'secret-query-text'])->assertOk();

    $expected = 'ai-index:embed:'.hash('sha256', implode("\0", [app(AiBridgeClient::class)->embedModel(), 'query', 'secret-query-text']));
    $embedKeys = array_values(array_filter($written, fn (array $w) => str_starts_with($w['key'], 'ai-index:embed:')));

    expect($embedKeys)->toHaveCount(1)
        ->and($embedKeys[0]['key'])->toBe($expected)
        ->and($embedKeys[0]['seconds'])->toBe(3600);

    foreach ($written as $entry) {
        expect($entry['key'])->not->toContain('secret-query-text');
    }
});

it('falls through to the bridge when the cache store fails', function () {
    Cache::extend('broken', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            return str_starts_with($key, 'ai-index:embed:') ? throw new RuntimeException('store down') : parent::get($key);
        }

        public function put($key, $value, $seconds)
        {
            return str_starts_with($key, 'ai-index:embed:') ? throw new RuntimeException('store down') : parent::put($key, $value, $seconds);
        }
    }));
    config(['cache.stores.broken' => ['driver' => 'broken'], 'cache.default' => 'broken']);

    $ids = array_column(embedSearch()->assertOk()->json('assets'), 'id');

    expect(embedCalls())->toBe(1)->and($ids)->not->toBeEmpty();
});

function embedKey(string $query = 'falcon mascot'): string
{
    return 'ai-index:embed:'.hash('sha256', implode("\0", [app(AiBridgeClient::class)->embedModel(), 'query', $query]));
}

it('treats a malformed cached value as a miss, replaces it and still returns the semantic hit', function (mixed $bad) {
    // The semantic-only asset matches no query word, so only the vector pass can return it.
    Cache::put(embedKey(), $bad, 3600);

    $ids = array_column(embedSearch()->assertOk()->json('assets'), 'id');

    expect(embedCalls())->toBe(1)->and($ids)->toContain($this->semanticId)
        ->and(Cache::get(embedKey()))->toBe([1.0, 0.0, 0.0]);
})->with([
    'empty'       => [[]],
    'non-numeric' => [['bad', 0, 0]],
    'all zero'    => [[0, 0, 0]],
    'NaN'         => [[NAN, 0, 0]],
    'INF'         => [[INF, 0, 0]],
    'associative' => [['a' => 1, 'b' => 0, 'c' => 0]],
    'string'      => ['not-a-vector'],
]);

it('replaces a cached vector of the wrong dimension with a bridge call, once', function () {
    Cache::put(embedKey(), [1.0, 0.0], 3600);

    $ids = array_column(embedSearch()->assertOk()->json('assets'), 'id');

    expect(embedCalls())->toBe(1)->and($ids)->toContain($this->semanticId)
        ->and(Cache::get(embedKey()))->toBe([1.0, 0.0, 0.0]);

    embedSearch()->assertOk();
    expect(embedCalls())->toBe(1);
});

it('falls back to the lexical match when the bridge is down for the wrong-dimension re-embed', function () {
    Cache::put(embedKey(), [1.0, 0.0], 3600);
    $this->bridgeDown = true;

    // The lexical pass still finds the caption; the semantic pass contributes nothing instead of failing the search.
    expect(array_column(embedSearch()->assertOk()->json('assets'), 'id'))->not->toBeEmpty();
});

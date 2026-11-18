<?php

use Illuminate\Support\Facades\DB;
use Mothership\AdminApi\Cache\StructureCache;
use Mothership\Attribute\Models\Attribute;
use Mothership\Core\Models\Currency;
use Mothership\Core\Models\Locale;
use Mothership\Core\Services\ChannelActivationSyncer;
use Mothership\Product\Models\Product;

/*
 * Versioned structure-response cache (attributes/families/channels/locales/
 * currencies listings) and the ETag conditional-request layer.
 */

it('serves the attribute listing from cache until a structure write bumps the version', function () {
    $headers = $this->getAuthenticationHeaders();

    $cached = Attribute::factory()->create(['code' => 'cache_seed_attr', 'type' => 'text']);

    $this->withHeaders($headers)
        ->json('GET', route('admin.api.attributes.index', ['limit' => 100]))
        ->assertOk()
        ->assertJsonFragment(['code' => $cached->code]);

    DB::table('attributes')->insert([
        'code'              => 'raw_inserted_attr',
        'type'              => 'text',
        'validation'        => null,
        'position'          => 999,
        'is_required'       => 0,
        'is_unique'         => 0,
        'value_per_locale'  => 0,
        'value_per_channel' => 0,
    ]);

    $stale = $this->withHeaders($headers)
        ->json('GET', route('admin.api.attributes.index', ['limit' => 100]))
        ->assertOk();

    expect(array_column($stale->json('data'), 'code'))->not->toContain('raw_inserted_attr');

    Attribute::factory()->create(['code' => 'bumping_attr', 'type' => 'text']);

    $fresh = $this->withHeaders($headers)
        ->json('GET', route('admin.api.attributes.index', ['limit' => 100]))
        ->assertOk();

    expect(array_column($fresh->json('data'), 'code'))
        ->toContain('raw_inserted_attr')
        ->toContain('bumping_attr');
});

it('invalidates the locale listing when a locale is saved through Eloquent', function () {
    $headers = $this->getAuthenticationHeaders();

    $this->withHeaders($headers)
        ->json('GET', route('admin.api.locales.index', ['limit' => 100]))
        ->assertOk();

    $locale = Locale::query()->where('status', 0)->first();
    $locale->status = 1;
    $locale->save();

    $response = $this->withHeaders($headers)
        ->json('GET', route('admin.api.locales.index', [
            'filters' => json_encode(['status' => [['operator' => '=', 'value' => 1]]]),
            'limit'   => 100,
        ]))
        ->assertOk();

    expect(array_column($response->json('data'), 'code'))->toContain($locale->code);
});

it('bypasses the cache entirely when disabled by config', function () {
    config(['api.structure_cache.enabled' => false]);

    $headers = $this->getAuthenticationHeaders();

    $this->withHeaders($headers)
        ->json('GET', route('admin.api.attributes.index', ['limit' => 100]))
        ->assertOk();

    DB::table('attributes')->insert([
        'code'              => 'uncached_attr',
        'type'              => 'text',
        'validation'        => null,
        'position'          => 998,
        'is_required'       => 0,
        'is_unique'         => 0,
        'value_per_locale'  => 0,
        'value_per_channel' => 0,
    ]);

    $response = $this->withHeaders($headers)
        ->json('GET', route('admin.api.attributes.index', ['limit' => 100]))
        ->assertOk();

    expect(array_column($response->json('data'), 'code'))->toContain('uncached_attr');
});

it('keeps product listings uncached', function () {
    $headers = $this->getAuthenticationHeaders();

    $this->withHeaders($headers)
        ->json('GET', route('admin.api.products.index', ['limit' => 100]))
        ->assertOk();

    $product = Product::factory()->simple()->create(['sku' => 'NEVER-CACHED']);

    DB::table('products')->where('id', $product->id)->update(['sku' => 'NEVER-CACHED-RAW']);

    $response = $this->withHeaders($headers)
        ->json('GET', route('admin.api.products.index', ['limit' => 100]))
        ->assertOk();

    expect(array_column($response->json('data'), 'sku'))->toContain('NEVER-CACHED-RAW');
});

it('returns an ETag and honors If-None-Match with 304', function () {
    $headers = $this->getAuthenticationHeaders();

    $first = $this->withHeaders($headers)
        ->json('GET', route('admin.api.channels.index', ['limit' => 100]))
        ->assertOk();

    $etag = $first->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->withHeaders($headers + ['If-None-Match' => $etag])
        ->json('GET', route('admin.api.channels.index', ['limit' => 100]))
        ->assertStatus(304);
});

it('marks API responses as private for shared caches', function () {
    $headers = $this->getAuthenticationHeaders();

    $response = $this->withHeaders($headers)
        ->json('GET', route('admin.api.locales.index'))
        ->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private');
});

it('serves fresh locale and currency status after channel sync flips it', function () {
    $headers = $this->getAuthenticationHeaders();
    $activeCodes = fn (string $route) => array_column($this->withHeaders($headers)
        ->json('GET', route($route, [
            'filters' => json_encode(['status' => [['operator' => '=', 'value' => 1]]]),
            'limit'   => 100,
        ]))
        ->assertOk()
        ->json('data'), 'code');

    $locale = Locale::query()->where('status', 0)->first();
    $currency = Currency::query()->where('status', 0)->first() ?? Currency::factory()->create(['status' => 0]);

    // Warm the repository and structure caches with the inactive state.
    $this->withHeaders($headers)->json('GET', route('admin.api.locales.get', $locale->code))
        ->assertOk()->assertJsonPath('status', 0);
    $this->withHeaders($headers)->json('GET', route('admin.api.currencies.get', $currency->code))
        ->assertOk()->assertJsonPath('status', 0);
    expect($activeCodes('admin.api.locales.index'))->not->toContain($locale->code);
    expect($activeCodes('admin.api.currencies.index'))->not->toContain($currency->code);

    $syncer = app(ChannelActivationSyncer::class);
    $syncer->syncLocales([$locale->id], []);
    $syncer->syncCurrencies([$currency->id], []);

    $this->withHeaders($headers)->json('GET', route('admin.api.locales.get', $locale->code))
        ->assertOk()->assertJsonPath('status', 1);
    $this->withHeaders($headers)->json('GET', route('admin.api.currencies.get', $currency->code))
        ->assertOk()->assertJsonPath('status', 1);
    expect($activeCodes('admin.api.locales.index'))->toContain($locale->code);
    expect($activeCodes('admin.api.currencies.index'))->toContain($currency->code);
});

it('never reuses a version whose responses are still cached once the version key expires', function () {
    $cache = app(StructureCache::class);
    $versionTtl = max($cache->ttl() * 2, 2592000);

    $cache->bump('locales');

    $this->travel($versionTtl - 60)->seconds();
    expect($cache->remember('locales', 'probe', fn () => 'before-write'))->toBe('before-write');

    $this->travel(120)->seconds();
    $cache->bump('locales');

    expect($cache->remember('locales', 'probe', fn () => 'after-write'))->toBe('after-write');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mothership\DAM\Models\Asset;

it('creates the AI tables and cascades them with the asset', function () {
    expect(Schema::hasTable('dam_ai_index'))->toBeTrue()
        ->and(Schema::hasTable('dam_ai_tags'))->toBeTrue()
        ->and(Schema::hasTable('dam_ai_vectors'))->toBeTrue();

    $asset = Asset::factory()->create();
    DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_tags')->insert(['asset_id' => $asset->id, 'term' => 'photo', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'vector' => 'AAAAAA==', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);

    DB::table('dam_assets')->where('id', $asset->id)->delete();

    expect(DB::table('dam_ai_index')->count() + DB::table('dam_ai_tags')->count() + DB::table('dam_ai_vectors')->count())->toBe(0);
});

it('exposes the approved vocabulary', function () {
    expect(config('ai-vocabulary.terms'))->toHaveCount(47)
        ->and(config('ai-vocabulary.terms.campus-event'))->toBe('Campus event')
        ->and(config('ai-index.search_enabled'))->toBeFalse();
});

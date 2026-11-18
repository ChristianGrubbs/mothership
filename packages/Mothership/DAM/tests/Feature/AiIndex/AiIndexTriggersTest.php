<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Jobs\AiReindexLexical;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;

it('dispatches nothing while indexing is off', function () {
    Queue::fake();
    config(['ai-index.index_enabled' => false]);

    Asset::factory()->create();

    Queue::assertNothingPushed();
});

it('indexes new content fully and a rename lexically, on the ai-index queue', function () {
    Queue::fake();
    config(['ai-index.index_enabled' => true]);

    $asset = Asset::factory()->create(['file_name' => 'a.jpg']);
    Queue::assertPushedOn('ai-index', AiIndexAsset::class, fn ($job) => $job->assetId === $asset->id);

    $asset->update(['file_name' => 'b.jpg']);
    Queue::assertPushed(AiReindexLexical::class, fn ($job) => $job->assetIds === [$asset->id]);
});

it('backfill refuses without a bridge and queues every asset with one', function () {
    Queue::fake();
    $ids = Asset::factory()->count(3)->create()->pluck('id')->all();

    config(['ai-index.api' => null]);
    $this->artisan('dam:ai-index:backfill')->assertFailed();

    config(['ai-index.api' => 'http://bridge.test']);
    $this->artisan('dam:ai-index:backfill')->assertSuccessful();
    Queue::assertPushed(AiIndexAsset::class, fn ($job) => in_array($job->assetId, $ids, true));
});

it('refreshes the words when a tag is added through the tag endpoint', function () {
    config(['ai-index.index_enabled' => true]);
    $this->loginAsAdmin();
    $asset = Asset::factory()->create();
    Queue::fake();

    $this->postJson(route('admin.dam.assets.tag'), ['asset_id' => $asset->id, 'tag' => 'homecoming'])->assertCreated();

    Queue::assertPushed(AiReindexLexical::class, fn ($job) => $job->assetIds === [$asset->id]);
});

it('re-indexes when the original is replaced by same-size, same-format bytes, and not when it is saved unchanged', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);
    config(['ai-index.index_enabled' => true]);
    $store = app(ContentStore::class);
    $adopt = function (string $bytes) use ($disk, $store) {
        $staged = $store->stagingPath('jpg');
        Storage::disk($disk)->put($staged, $bytes);

        return $store->adoptStored(disk: $disk, sourcePath: $staged, mimeType: 'image/jpeg', extension: 'jpg');
    };

    $asset = (new Asset([
        'file_name' => 'photo.jpg', 'file_type' => 'image', 'file_size' => 8,
        'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'path' => 'assets/Root/photo.jpg',
    ]))->withGraphContent($adopt('AAAAAAAA'));
    $asset->save();
    // Queueing the index already made the pending row.
    DB::table('dam_ai_index')->updateOrInsert(['asset_id' => $asset->id], ['status' => 'failed', 'attempts' => 12, 'created_at' => now(), 'updated_at' => now()]);

    Queue::fake();
    $asset->withGraphContent($adopt('BBBBBBBB'))->save();

    Queue::assertPushedOn('ai-index', AiIndexAsset::class, fn ($job) => $job->assetId === $asset->id);
    expect(DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('attempts'))->toBe(0);

    Queue::fake();
    $asset->withGraphContent($adopt('BBBBBBBB'))->save();

    Queue::assertNothingPushed();
});

it('refreshes the words when a property is created, edited or removed', function () {
    config(['ai-index.index_enabled' => true]);
    $asset = Asset::factory()->create();
    Queue::fake();

    $property = AssetProperty::factory()->create(['dam_asset_id' => $asset->id]);
    Queue::assertPushed(AiReindexLexical::class, fn ($job) => $job->assetIds === [$asset->id]);

    Queue::fake();
    $property->update(['value' => 'Homecoming 2025']);
    Queue::assertPushed(AiReindexLexical::class, fn ($job) => $job->assetIds === [$asset->id]);

    Queue::fake();
    $property->delete();
    Queue::assertPushed(AiReindexLexical::class, fn ($job) => $job->assetIds === [$asset->id]);
});

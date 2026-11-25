<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Duplicates\StackSuggestions;
use Mothership\DAM\Services\Merge\AssetMergeService;
use Mothership\DAM\Services\Similarity\DHash;
use Mothership\DAM\Services\Similarity\PerceptualHashes;
use Mothership\DAM\Services\Similarity\SimilarImages;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P5c (D16): near-duplicate images. A dHash of each asset's preview, a banded index that finds every pair within the
 * threshold without a scan, and one list of pairs (the Similar images page, the asset page's hint, the upload receipt
 * and REST) that shows a pair only when the viewer may list both sides. "Not duplicates" is the Suggested stacks
 * dismissal; Stack and Merge are the P3a and P3b-1 flows with their own checks.
 */

const SIM_FIXTURES = __DIR__.'/../../../../../tests/e2e-pw/assets';

function simEncode(GdImage $image, string $format, int $quality = 90): string
{
    ob_start();
    $format === 'jpg' ? imagejpeg($image, null, $quality) : imagepng($image);

    return (string) ob_get_clean();
}

function simResize(string $bytes, float $scale): GdImage
{
    $image = imagecreatefromstring($bytes);
    $width = max(1, (int) round(imagesx($image) * $scale));
    $height = max(1, (int) round(imagesy($image) * $scale));
    $out = imagecreatetruecolor($width, $height);
    imagecopyresampled($out, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

    return $out;
}

function simAsset(Directory $folder, string $name, array $attributes = []): Asset
{
    $asset = Asset::factory()->create(['file_name' => $name, 'path' => 'assets/Root/'.$folder->name.'/'.$name, ...$attributes]);
    $asset->directories()->attach($folder->id);

    return $asset;
}

/** Stores `$hash` as the asset's dHash and builds its pairs, as PerceptualHashes does after hashing a preview. */
function simHash(Asset $asset, int $hash, ?string $sha256 = null): void
{
    $bands = DHash::bands($hash);
    DB::table('dam_asset_phashes')->updateOrInsert(['asset_id' => $asset->id], [
        'sha256' => $sha256 ?? hash('sha256', 'asset-'.$asset->id), 'algorithm' => DHash::ALGORITHM, 'status' => PerceptualHashes::STATUS_HASHED,
        'dhash'  => $hash, 'band0' => $bands[0], 'band1' => $bands[1], 'band2' => $bands[2], 'band3' => $bands[3], 'computed_at' => now(),
    ]);
    app(PerceptualHashes::class)->rebuildPairs($asset->id);
}

function simAccessModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

/** A custom-role member who may open `$folder` only, with every Library key but those `$without` names. */
function simMember(Directory $folder, array $without = []): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => collect(config('acl'))->pluck('key')->reject(fn ($key) => in_array($key, $without, true))->values()->all()]);
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);

    return Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
}

function simActAs(object $test, Admin $admin): void
{
    $test->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();
    Cache::flush();
}

/** This test's pair keys from the web list (other tests' committed rows may pair too). */
function simKeys(object $test, array $ownIds, array $query = []): array
{
    $keys = collect($test->getJson(route('admin.dam.similar.index', [...$query, 'view' => 'pairs']))->assertOk()->json('data'))
        ->filter(fn ($pair) => array_diff(array_column($pair['assets'], 'id'), $ownIds) === [])
        ->pluck('pair_key')->values()->all();
    sort($keys);

    return $keys;
}

function simUpload(object $test, Directory $folder, string $name, string $bytes): TestResponse
{
    return $test->postJson(route('admin.dam.assets.upload'), ['directory_id' => $folder->id, 'files' => [UploadedFile::fake()->createWithContent($name, $bytes)]]);
}

beforeEach(function () {
    $this->loginAsAdmin();
    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);
    $this->folder = Directory::factory()->create(['name' => 'Shoot '.Str::random(4), 'parent_id' => $root->id]);
    // Random hashes far from anything other tests stored: pairs form only between this test's assets.
    $this->base = random_int(PHP_INT_MIN, PHP_INT_MAX);
});

it('keeps a re-encoded or resized image within the threshold and unrelated images beyond it', function () {
    $threshold = PerceptualHashes::threshold();
    $floral = (string) file_get_contents(SIM_FIXTURES.'/floral.jpg');
    $original = DHash::fromBytes($floral);
    expect($threshold)->toBe(4)->and($original['flat'])->toBeFalse();

    $variants = [
        'jpeg q50'      => simEncode(imagecreatefromstring($floral), 'jpg', 50),
        'png'           => simEncode(imagecreatefromstring($floral), 'png'),
        'half size'     => simEncode(simResize($floral, 0.5), 'jpg', 85),
        'double size'   => simEncode(simResize($floral, 2.0), 'jpg', 75),
        '300px preview' => simEncode(simResize($floral, 300 / imagesx(imagecreatefromstring($floral))), 'jpg', 85),
    ];

    foreach ($variants as $label => $bytes) {
        expect(DHash::distance($original['hash'], DHash::fromBytes($bytes)['hash']))->toBeLessThanOrEqual($threshold, $label);
    }

    foreach (['stripes.jpg', 'check.jpeg', 'john doe.jpeg', 'dotted.png'] as $other) {
        $hash = DHash::fromBytes((string) file_get_contents(SIM_FIXTURES.'/'.$other));
        expect(DHash::distance($original['hash'], $hash['hash']))->toBeGreaterThan($threshold, $other);
    }

    // A solid swatch has no gradient to describe: flat, never paired.
    $solid = imagecreatetruecolor(64, 64);
    imagefill($solid, 0, 0, 0x336699);
    expect(DHash::fromBytes(simEncode($solid, 'png'))['flat'])->toBeTrue();
});

it('finds through the bands exactly the pairs a brute-force scan finds, for every threshold', function () {
    mt_srand(20261007);
    $hashes = [];
    foreach (range(1, 120) as $i) {
        $hashes[] = (mt_rand(0, 0x7FFFFFFF) << 33) ^ (mt_rand(0, 0x7FFFFFFF) << 2) ^ mt_rand(0, 3) ^ (mt_rand(0, 1) ? PHP_INT_MIN : 0);
    }
    // Plus near neighbours of some of them, 1-12 random bits away, so every threshold has pairs to find.
    foreach (range(1, 180) as $i) {
        $near = $hashes[mt_rand(0, 119)];
        foreach (range(1, mt_rand(1, 12)) as $flip) {
            $near ^= 1 << mt_rand(0, 63);
        }
        $hashes[] = $near;
    }

    $byBand = [];
    foreach ($hashes as $id => $hash) {
        foreach (DHash::bands($hash) as $band => $value) {
            $byBand[$band][$value][] = $id;
        }
    }

    foreach (range(0, DHash::MAX_THRESHOLD) as $threshold) {
        $radius = DHash::radius($threshold);
        $brute = 0;
        $banded = 0;

        foreach ($hashes as $id => $hash) {
            $candidates = [];
            foreach (DHash::bands($hash) as $band => $value) {
                foreach (DHash::neighbours(band: $value, radius: $radius) as $neighbour) {
                    foreach ($byBand[$band][$neighbour] ?? [] as $other) {
                        $candidates[$other] = true;
                    }
                }
            }
            $found = array_keys(array_filter($candidates, fn ($yes, $other) => $other !== $id && DHash::distance($hash, $hashes[$other]) <= $threshold, ARRAY_FILTER_USE_BOTH));
            $expected = array_keys(array_filter($hashes, fn ($other, $otherId) => $otherId !== $id && DHash::distance($hash, $other) <= $threshold, ARRAY_FILTER_USE_BOTH));
            sort($found);
            expect($found)->toBe($expected, "threshold {$threshold}, hash {$id}");
            $brute += count($expected);
            $banded += count($found);
        }

        expect($banded)->toBe($brute);
    }

    // The neighbour lists are exact: C(16,0..r) distinct values, each within r bits.
    expect(count(array_unique(DHash::neighbours(band: 0xBEEF, radius: 2))))->toBe(1 + 16 + 120)
        ->and(collect(DHash::neighbours(band: 0xBEEF, radius: 2))->every(fn ($value) => DHash::distance($value, 0xBEEF) <= 2))->toBeTrue();
});

it('stores, through the SQL band index, the same pairs as a brute-force scan', function () {
    $assets = [];
    $hashes = [];
    mt_srand(7);
    foreach (range(0, 59) as $i) {
        $hash = $i % 3 === 0 ? $this->base : $hashes[$i - 1] ?? $this->base;
        foreach (range(1, mt_rand(0, 4)) as $flip) {
            $hash ^= 1 << mt_rand(0, 63);
        }
        $hashes[$i] = $i % 10 === 9 ? ~$this->base : $hash;
        $assets[$i] = simAsset($this->folder, "s{$i}.jpg");
        simHash($assets[$i], $hashes[$i]);
    }

    $ids = array_map(fn (Asset $asset) => $asset->id, $assets);
    $stored = DB::table('dam_asset_similar_pairs')->whereIn('asset_id', $ids)->whereIn('other_asset_id', $ids)->orderBy('pair_key')->pluck('distance', 'pair_key')->all();
    $expected = [];
    foreach ($assets as $i => $a) {
        foreach ($assets as $j => $b) {
            if ($a->id < $b->id && ($d = DHash::distance($hashes[$i], $hashes[$j])) <= PerceptualHashes::threshold()) {
                $expected[$a->id.':'.$b->id] = $d;
            }
        }
    }
    ksort($expected);
    ksort($stored);

    expect($stored)->toEqual($expected)->and(count($expected))->toBeGreaterThan(20);
});

it('lists, counts and hints a pair only when the viewer may see both sides', function () {
    simAccessModel(AccessModel::GRANTS);
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $this->folder->parent_id]);
    $hero = simAsset($this->folder, 'hero.jpg');
    $heroCopy = simAsset($this->folder, 'hero-copy.jpg');
    $secret = simAsset($hiddenFolder, 'hero-secret.jpg');
    $lonely = simAsset($this->folder, 'lonely.jpg');
    simHash($hero, $this->base);
    simHash($heroCopy, $this->base ^ 0b11);
    simHash($secret, $this->base ^ 0b1);
    simHash($lonely, ~$this->base);
    $own = [$hero->id, $heroCopy->id, $secret->id, $lonely->id];

    // A full admin sees all three pairs.
    expect(simKeys($this, $own))->toHaveCount(3);

    $member = simMember($this->folder);
    simActAs($this, $member);
    $similar = app(SimilarImages::class);
    $context = AccessContext::library(AccessContext::PURPOSE_LIST);

    $visibleKey = $hero->id.':'.$heroCopy->id;
    $page = $this->getJson(route('admin.dam.similar.index', ['view' => 'pairs']))->assertOk();
    expect(simKeys($this, $own))->toBe([$visibleKey])
        ->and(json_encode($page->json()))->not->toContain('hero-secret')
        ->and($similar->countFor(viewer: $member, context: $context, assetId: $hero->id))->toBe(1)
        ->and($similar->countFor(viewer: $member, context: $context, assetId: $lonely->id))->toBe(0)
        // The hidden asset as a filter answers like a missing one.
        ->and($this->getJson(route('admin.dam.similar.index', ['asset' => $secret->id]))->status())->toBe(404)
        ->and($this->getJson(route('admin.dam.similar.index', ['asset' => 999999999]))->status())->toBe(404);

    // The asset page: "1 similar image" for hero, nothing for a file whose only match is hidden.
    $this->get(route('admin.dam.assets.edit', $hero->id))->assertOk()->assertSee(':initial-count="1"', false);
    $secretOnly = simAsset($this->folder, 'only-hidden-match.jpg');
    simHash($secretOnly, ($this->base ^ 0b1) ^ (1 << 40));
    $this->get(route('admin.dam.assets.edit', $secretOnly->id))->assertOk()->assertSee(':initial-count="0"', false);

    // Trashed and merged-away sides drop the pair for everyone.
    simActAs($this, Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]));
    app(AssetTrashService::class)->trash(asset: $heroCopy);
    expect(simKeys($this, $own))->not->toContain($visibleKey);
    Asset::where('id', $secret->id)->update(['merged_into_id' => $hero->id]);
    expect(simKeys($this, $own))->toBe([]);
});

it('pairs still images only: a video hashed from its poster never appears beside a picture', function () {
    $photo = simAsset($this->folder, 'poster-look.jpg');
    $photoCopy = simAsset($this->folder, 'poster-look-copy.jpg');
    $clip = simAsset($this->folder, 'poster-look.mp4', ['file_type' => 'video', 'extension' => 'mp4']);
    simHash($photo, $this->base);
    simHash($photoCopy, $this->base ^ 0b11);
    simHash($clip, $this->base ^ 0b1);

    expect(simKeys($this, [$photo->id, $photoCopy->id, $clip->id]))->toBe([$photo->id.':'.$photoCopy->id]);
});

it('does not pair a PDF or video named .dng as a still image, but keeps a genuine or unidentified DNG', function () {
    $photo = simAsset($this->folder, 'raw-look.jpg');
    $realDng = simAsset($this->folder, 'raw-look.dng', ['file_type' => 'document', 'extension' => 'dng', 'mime_type' => 'image/tiff']);
    $unknownDng = simAsset($this->folder, 'raw-look-2.dng', ['file_type' => 'document', 'extension' => 'dng', 'mime_type' => 'application/octet-stream']);
    $pdfDng = simAsset($this->folder, 'raw-look-pdf.dng', ['file_type' => 'document', 'extension' => 'dng', 'mime_type' => 'application/pdf']);
    $videoDng = simAsset($this->folder, 'raw-look-video.dng', ['file_type' => 'document', 'extension' => 'dng', 'mime_type' => 'video/mp4']);
    simHash($photo, $this->base);
    simHash($realDng, $this->base ^ 0b1);
    simHash($unknownDng, $this->base ^ 0b10);
    simHash($pdfDng, $this->base ^ 0b100);
    simHash($videoDng, $this->base ^ 0b1000);

    $keys = simKeys($this, [$photo->id, $realDng->id, $unknownDng->id, $pdfDng->id, $videoDng->id]);

    $members = collect($keys)->flatMap(fn ($key) => array_map('intval', explode(':', $key)))->unique()->all();

    expect($members)->toContain($photo->id, $realDng->id, $unknownDng->id)
        ->not->toContain($pdfDng->id)
        ->not->toContain($videoDng->id);
});

it('Not duplicates hides the pair from the page and the Suggested stacks panel, for that viewer only, and stays', function () {
    $a = simAsset($this->folder, 'banner.jpg');
    $b = simAsset($this->folder, 'banner-print.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ (1 << 7));
    $key = $a->id.':'.$b->id;
    $inPanel = fn () => collect($this->getJson(route('admin.dam.stack-suggestions.index'))->assertOk()->json('suggestions'))
        ->contains(fn ($group) => $group['kind'] === 'near_duplicate' && $group['key'] === $key);

    Cache::flush();
    expect(simKeys($this, [$a->id, $b->id]))->toBe([$key])->and($inPanel())->toBeTrue();

    $this->postJson(route('admin.dam.similar.dismiss'), ['pairs' => [$key, '1:2', 'nonsense']])->assertOk()->assertJson(['dismissed' => 1]);

    // Re-hashing (the pair rebuilt) does not bring it back.
    simHash($b, $this->base ^ (1 << 7));
    expect(simKeys($this, [$a->id, $b->id]))->toBe([])->and($inPanel())->toBeFalse()
        ->and(DB::table('dam_stack_suggestion_dismissals')->where('kind', 'near_duplicate')->where('group_key', $key)->count())->toBe(1);

    simActAs($this, Admin::factory()->create(['status' => 1]));
    expect(simKeys($this, [$a->id, $b->id]))->toBe([$key]);
});

it('stacks a pair through the P3a flow and merges it through P3b-1, each with its own permission check', function () {
    $a = simAsset($this->folder, 'poster.jpg');
    $b = simAsset($this->folder, 'poster-web.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b101);
    $key = $a->id.':'.$b->id;

    // No dam.asset.edit: neither Stack nor the merge preview.
    simActAs($this, simMember($this->folder, without: ['dam.asset.edit']));
    $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'near_duplicate', 'group_key' => $key, 'basis' => 'campaign', 'key' => 'Poster'])->assertForbidden();
    $this->getJson(route('admin.dam.assets.merges.preview', ['asset' => $a->id, 'sources' => [$b->id]]))->assertForbidden();

    simActAs($this, Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]));
    $this->getJson(route('admin.dam.assets.merges.preview', ['asset' => $a->id, 'sources' => [$b->id]]))->assertOk();
    $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'near_duplicate', 'group_key' => $key, 'basis' => 'campaign', 'key' => 'Poster'])
        ->assertOk()->assertJson(['stacked' => 2, 'skipped' => 0]);

    // One stack now: the pair is settled and leaves the list; an unknown pair is refused like a gone group.
    expect(simKeys($this, [$a->id, $b->id]))->toBe([]);
    $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'near_duplicate', 'group_key' => '1:999999999', 'basis' => 'campaign', 'key' => 'x'])->assertUnprocessable();
});

it('names a near-duplicate on the upload receipt, never an exact or hidden one, and the backfill is idempotent', function () {
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    simAccessModel(AccessModel::GRANTS);
    $floral = (string) file_get_contents(SIM_FIXTURES.'/floral.jpg');
    $resized = simEncode(simResize($floral, 0.5), 'jpg', 80);
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $this->folder->parent_id]);

    $first = simUpload($this, $this->folder, 'floral.jpg', $floral)->assertCreated();
    expect($first->json('similar'))->toBe([]);
    $firstId = $first->json('files.0.id');

    // A resized copy names the original; the same bytes are an exact duplicate, not a near one.
    $second = simUpload($this, $this->folder, 'floral-small.jpg', $resized)->assertCreated();
    expect($second->json('similar.0.asset_id'))->toBe($second->json('files.0.id'))
        ->and($second->json('similar.0.kind'))->toBe('near_duplicate')
        ->and($second->json('similar.0.matches.0.id'))->toBe($firstId)
        ->and($second->json('similar.0.message'))->toContain('floral.jpg');
    $exact = simUpload($this, $this->folder, 'floral-again.jpg', $floral)->assertCreated();
    expect($exact->json('duplicates'))->toHaveCount(1)
        ->and(collect($exact->json('similar.0.matches') ?? [])->pluck('id')->all())->not->toContain($firstId);

    // A member who cannot open the folder holding the match hears nothing, in the same shape as no match.
    $hiddenId = simUpload($this, $hiddenFolder, 'portrait.jpg', (string) file_get_contents(SIM_FIXTURES.'/john doe.jpeg'))->assertCreated()->json('files.0.id');
    simActAs($this, simMember($this->folder));
    $hidden = simUpload($this, $this->folder, 'portrait-copy.jpg', simEncode(simResize((string) file_get_contents(SIM_FIXTURES.'/john doe.jpeg'), 0.5), 'jpg', 80))->assertCreated();
    $none = simUpload($this, $this->folder, 'check.jpeg', (string) file_get_contents(SIM_FIXTURES.'/check.jpeg'))->assertCreated();
    expect($hidden->json('similar'))->toBe([])->and($none->json('similar'))->toBe([])
        ->and(DB::table('dam_asset_similar_pairs')->where('pair_key', $hiddenId.':'.$hidden->json('files.0.id'))->exists())->toBeTrue();

    // Backfill: a second run changes nothing.
    $this->artisan('dam:phash:backfill', ['--after' => $firstId - 1])->assertSuccessful();
    $rows = DB::table('dam_asset_phashes')->where('asset_id', '>=', $firstId)->orderBy('asset_id')->get(['asset_id', 'dhash', 'computed_at'])->toArray();
    $this->artisan('dam:phash:backfill', ['--after' => $firstId - 1])->assertSuccessful()->expectsOutputToContain('"unchanged": '.count($rows));
    expect(DB::table('dam_asset_phashes')->where('asset_id', '>=', $firstId)->orderBy('asset_id')->get(['asset_id', 'dhash', 'computed_at'])->toArray())->toEqual($rows);
});

it('never reads an original over the byte cap, or in a format GD cannot decode, and settles the skip', function () {
    // 2026-11-10, asset 3865: a 250 MB PSD read whole ran the 512 MB queue worker out of memory, a fatal that stopped
    // every upload finalization behind it; its `error_*` row was retryable, so the retry did it again.
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $hashes = app(PerceptualHashes::class);
    $floral = (string) file_get_contents(SIM_FIXTURES.'/floral.jpg');
    $row = fn (Asset $asset) => DB::table('dam_asset_phashes')->where('asset_id', $asset->id)->first(['status', 'reason', 'dhash']);
    $thumbnail = fn (Asset $asset) => Storage::disk(Directory::getAssetDisk())->exists(
        DerivativeImages::thumbnail((string) app(ContentStore::class)->current($asset)->sha256, (string) $asset->extension)
    );

    // One byte over the cap: skipped as too_large. Read and decoded, this JPEG would be hashed and its thumbnail cached.
    config(['dam.similar.thumbnail_max_bytes' => strlen($floral) - 1]);
    $photo = Asset::query()->findOrFail(simUpload($this, $this->folder, 'floral.jpg', $floral)->assertCreated()->json('files.0.id'));

    expect($hashes->ensure($photo, force: true))->toBe(PerceptualHashes::STATUS_SKIPPED)
        ->and((array) $row($photo))->toBe(['status' => 'skipped', 'reason' => 'too_large', 'dhash' => null])
        ->and($thumbnail($photo))->toBeFalse()
        ->and(PerceptualHashes::retryable('too_large'))->toBeFalse()
        // Settled: the queued job's second try, and the backfill, leave the file alone.
        ->and($hashes->ensure($photo))->toBe('unchanged');

    // The cap is the only reason: at the file's own size the same bytes are hashed as before.
    config(['dam.similar.thumbnail_max_bytes' => strlen($floral)]);
    expect($hashes->ensure($photo, force: true))->toBe(PerceptualHashes::STATUS_HASHED)->and($thumbnail($photo))->toBeTrue();

    // A PSD stored as image/vnd.adobe.photoshop (as 3865 was) is named by its header and never read, whatever its size.
    $psd = '8BPS'.pack('nx6nNNnn', 1, 3, 64, 64, 8, 3).str_repeat("\0", 64);
    $layers = Asset::query()->findOrFail(simUpload($this, $this->folder, 'layers.psd', $psd)->assertCreated()->json('files.0.id'));
    app(ContentStore::class)->current($layers)->forceFill(['mime_type' => 'image/vnd.adobe.photoshop'])->save();

    expect($hashes->ensure($layers, force: true))->toBe(PerceptualHashes::STATUS_SKIPPED)
        ->and($row($layers)->reason)->toBe('unsupported')
        ->and(PerceptualHashes::retryable('unsupported'))->toBeFalse()
        ->and($hashes->ensure($layers))->toBe('unchanged');
});

it('answers REST like the Library: list, one asset and dismiss, under its scopes', function () {
    $a = simAsset($this->folder, 'mascot.jpg');
    $b = simAsset($this->folder, 'mascot-2.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ (1 << 60));
    $key = $a->id.':'.$b->id;
    $web = $this->getJson(route('admin.dam.similar.index', ['asset' => $a->id, 'view' => 'pairs']))->assertOk()->json();

    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.similar', 'api.dam.similar.dismiss']);
    $rest = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.similar', $a->id))->assertOk();
    expect($rest->json('data'))->toBe($web['data'])->and($rest->json('meta'))->toBe($web['meta'])
        ->and($this->withHeaders($headers)->getJson(route('admin.api.dam.assets.similar', 999999999))->status())->toBe(404);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.similar.dismiss'), ['pairs' => [$key]])->assertOk()->assertJson(['data' => ['dismissed' => 1]]);
    expect(collect($this->withHeaders($headers)->getJson(route('admin.api.dam.similar.index'))->assertOk()->json('data'))->pluck('pair_key'))->not->toContain($key);

    // A key whose admin cannot open the folder holding one side: no pair, and that asset answers like a missing one.
    simAccessModel(AccessModel::GRANTS);
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $this->folder->parent_id]);
    $secret = simAsset($hiddenFolder, 'mascot-secret.jpg');
    simHash($secret, $this->base ^ 0b1);
    Admin::latest('id')->first()->update(['role_id' => simMember($this->folder)->role_id]);
    app('auth')->forgetGuards();
    app(DirectoryPermissionService::class)->flush();
    $listed = json_encode($this->withHeaders($headers)->getJson(route('admin.api.dam.similar.index'))->assertOk()->json());
    expect($listed)->not->toContain('mascot-secret')
        ->and($this->withHeaders($headers)->getJson(route('admin.api.dam.assets.similar', $a->id))->assertOk()->json('meta.total'))->toBe(0)
        ->and($this->withHeaders($headers)->getJson(route('admin.api.dam.assets.similar', $secret->id))->status())->toBe(404);

    app('auth')->forgetGuards();
    $this->withHeaders($this->getAuthenticationHeaders('custom', ['api.dam.assets']))->getJson(route('admin.api.dam.similar.index'))->assertForbidden();
});

/*
 * Review groups: sets of images that are ALL within the threshold of each other, the Similar images page's and the
 * Suggested stacks panel's unit for three or more files. A chain (A-B and B-C but not A-C) is never one group: its pairs
 * stay reachable as overlapping smaller groups. A group action names the exact members the viewer reviewed and is refused
 * (409) rather than acting on a different set.
 */

/** @return list<int> the ids, sorted */
function simIds(Asset ...$assets): array
{
    $ids = array_map(fn (Asset $asset) => (int) $asset->id, $assets);
    sort($ids);

    return $ids;
}

/** This test's review groups from the web list (each as its sorted member ids), across all pages; other tests' rows are left out. */
function simGroups(object $test, array $ownIds, array $query = []): array
{
    $groups = [];
    $page = 1;

    do {
        $response = $test->getJson(route('admin.dam.similar.index', [...$query, 'page' => $page]))->assertOk();
        array_push($groups, ...array_filter($response->json('data'), fn ($group) => array_diff($group['members'], $ownIds) === []));
        $page++;
    } while ($page <= $response->json('meta.last_page'));

    $members = array_map(fn ($group) => $group['members'], $groups);
    usort($members, fn ($left, $right) => $left <=> $right);

    return $members;
}

/** The raw group entries of this test, for payload assertions. */
function simGroupEntries(object $test, array $ownIds, array $query = []): array
{
    return array_values(array_filter(
        $test->getJson(route('admin.dam.similar.index', $query))->assertOk()->json('data'),
        fn ($group) => array_diff($group['members'], $ownIds) === [],
    ));
}

/** This test's Suggested stacks panel entries (pairs by their legacy kind, three or more as near_group). */
function simPanel(object $test, array $ownIds): array
{
    Cache::flush();

    return array_values(array_filter(
        $test->getJson(route('admin.dam.stack-suggestions.index'))->assertOk()->json('suggestions'),
        fn ($group) => in_array($group['kind'], ['near_duplicate', 'near_group'], true) && array_diff(array_column($group['entries'], 'id'), $ownIds) === [],
    ));
}

function simApplyGroup(object $test, array $members, ?string $key = null, array $over = []): TestResponse
{
    return $test->postJson(route('admin.dam.stack-suggestions.apply'), [
        'kind' => 'near_group', 'group_key' => $key ?? SimilarImages::groupKey($members), 'members' => $members, 'basis' => 'campaign', 'key' => 'Hero shoot', ...$over,
    ]);
}

function simStackKey(Asset $asset): ?string
{
    return Asset::query()->whereKey($asset->id)->value('stack_key');
}

it('shows three mutually similar images as one group, in the page and the panel, and keeps the pair contracts', function () {
    $a = simAsset($this->folder, 'Sign-Letters-IMG_1406.jpeg');
    $b = simAsset($this->folder, 'Sign-Letters-IMG_1408.jpeg');
    $c = simAsset($this->folder, 'Sign-Letters-IMG_1410.jpeg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);
    $ids = simIds($a, $b, $c);

    // One group of three, not three pairs.
    expect(simGroups($this, $ids))->toBe([$ids]);
    [$group] = simGroupEntries($this, $ids);
    expect($group['count'])->toBe(3)->and($group['assets'])->toHaveCount(3)->and($group['group_key'])->toBe(SimilarImages::groupKey($ids))
        ->and($group['similarity'])->toBe('near_identical')->and($group['distance'])->toBe(2)
        ->and(array_column($group['assets'], 'name'))->toBe(['Sign-Letters-IMG_1406.jpeg', 'Sign-Letters-IMG_1408.jpeg', 'Sign-Letters-IMG_1410.jpeg']);

    // The panel names it once, with every member for the Create stack review.
    $panel = simPanel($this, $ids);
    expect($panel)->toHaveCount(1)->and($panel[0]['kind'])->toBe('near_group')->and($panel[0]['key'])->toBe(SimilarImages::groupKey($ids))
        ->and($panel[0]['count'])->toBe(3)->and(array_column($panel[0]['entries'], 'id'))->toBe($ids);

    // The legacy pair listing is unchanged (REST's default, the receipt hints' source): one row per pair, two assets each.
    expect(simKeys($this, $ids))->toHaveCount(3);
    $pairs = app(SimilarImages::class)->page(viewer: auth('admin')->user(), context: AccessContext::library(AccessContext::PURPOSE_LIST), assetId: $a->id);
    expect($pairs['meta']['total'])->toBe(2)->and($pairs['data'])->each(fn ($pair) => $pair->toHaveKeys(['pair_key', 'distance', 'similarity', 'assets']))
        ->and($pairs['data'][0]['assets'])->toHaveCount(2);
});

it('ranks common neighbours against every member already chosen, breaking ties by asset id', function () {
    // These overlapping groups change if a tie picks the larger id or distances only consider the seed pair.
    $pairs = [
        [1, 2, 0], [1, 3, 0], [1, 4, 1], [1, 5, 1], [1, 6, 2], [1, 7, 0],
        [2, 4, 2], [2, 5, 1], [2, 6, 1], [2, 7, 1], [3, 6, 0], [3, 7, 0],
        [4, 5, 0], [4, 7, 2], [5, 6, 1], [6, 7, 1],
    ];
    $edges = [];
    foreach ($pairs as $index => [$a, $b, $distance]) {
        $edges[] = ['id' => $index + 1, 'key' => $a.':'.$b, 'a' => $a, 'b' => $b, 'distance' => $distance];
    }

    expect(SimilarImages::buildGroups($edges))->toBe([
        ['ids' => [1, 2, 4, 5], 'distance' => 2],
        ['ids' => [1, 2, 4, 7], 'distance' => 2],
        ['ids' => [1, 2, 5, 6], 'distance' => 2],
        ['ids' => [1, 3, 6, 7], 'distance' => 2],
    ]);
});

it('keeps both pairs of a non-transitive chain reachable and never merges them into one group', function () {
    $a = simAsset($this->folder, 'chain-a.jpg');
    $b = simAsset($this->folder, 'chain-b.jpg');
    $c = simAsset($this->folder, 'chain-c.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1111);
    simHash($c, $this->base ^ 0xFF);
    $ids = simIds($a, $b, $c);

    // A-B = 4 and B-C = 4 are within the threshold, A-C = 8 is not.
    expect(DB::table('dam_asset_similar_pairs')->whereIn('asset_id', $ids)->whereIn('other_asset_id', $ids)->count())->toBe(2)
        ->and(simGroups($this, $ids))->toBe([simIds($a, $b), simIds($b, $c)]);

    $panel = collect(simPanel($this, $ids));
    expect($panel->pluck('kind')->unique()->all())->toBe(['near_duplicate'])
        ->and($panel->pluck('key')->sort()->values()->all())->toBe([$a->id.':'.$b->id, $b->id.':'.$c->id])
        // The middle image is in both; filtering by it finds both, filtering by an end finds its own pair only.
        ->and(simGroups($this, $ids, ['asset' => $b->id]))->toBe([simIds($a, $b), simIds($b, $c)])
        ->and(simGroups($this, $ids, ['asset' => $a->id]))->toBe([simIds($a, $b)]);
});

it('builds each group from every image the asset or folder filter reaches, not only the images that touch the filter', function () {
    $other = Directory::factory()->create(['name' => 'Other '.Str::random(4), 'parent_id' => $this->folder->parent_id]);
    $a = simAsset($this->folder, 'f-a.jpg');
    $b = simAsset($this->folder, 'f-b.jpg');
    $c = simAsset($other, 'f-c.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);
    $ids = simIds($a, $b, $c);

    // b and c are similar to each other, yet the group of `a` still names both: the pair b-c does not touch `a`.
    expect(simGroups($this, $ids, ['asset' => $a->id]))->toBe([$ids])
        ->and(simGroups($this, $ids, ['folder' => $other->id]))->toBe([$ids])
        ->and(simGroups($this, $ids, ['folder' => $this->folder->id]))->toBe([$ids]);
});

it('never names, counts or acts on a member the viewer cannot see', function () {
    simAccessModel(AccessModel::GRANTS);
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden '.Str::random(4), 'parent_id' => $this->folder->parent_id]);
    $hero = simAsset($this->folder, 'hero.jpg');
    $heroCopy = simAsset($this->folder, 'hero-copy.jpg');
    $secret = simAsset($hiddenFolder, 'hero-secret.jpg');
    simHash($hero, $this->base);
    simHash($heroCopy, $this->base ^ 0b11);
    simHash($secret, $this->base ^ 0b1);
    $all = simIds($hero, $heroCopy, $secret);
    $visible = simIds($hero, $heroCopy);

    expect(simGroups($this, $all))->toBe([$all]);

    $member = simMember($this->folder);
    simActAs($this, $member);

    // The member's group is the two images they may see, in the page and the panel, with nothing about the third.
    $page = $this->getJson(route('admin.dam.similar.index'))->assertOk();
    expect(simGroups($this, $all))->toBe([$visible])
        ->and(json_encode($page->json()))->not->toContain('hero-secret')->not->toContain((string) $secret->id.',')
        ->and(json_encode(simPanel($this, $all)))->not->toContain('hero-secret');

    // A request that names the hidden member is refused like any changed set, and nothing is stacked or dismissed.
    simApplyGroup($this, $all)->assertStatus(409);
    $this->postJson(route('admin.dam.similar.dismiss'), ['groups' => [['key' => SimilarImages::groupKey($all), 'members' => $all]]])->assertStatus(409);
    expect(simStackKey($hero))->toBeNull()->and(simStackKey($secret))->toBeNull()
        ->and(DB::table('dam_stack_suggestion_dismissals')->where('admin_id', $member->id)->count())->toBe(0);
});

it('keeps a partly stacked group discoverable, drops a fully stacked one, and stacks exactly the reviewed members', function () {
    $stacked = ['stack_basis' => 'campaign', 'stack_key' => 'Fall 26'];
    $a = simAsset($this->folder, 'p-a.jpg', $stacked);
    $b = simAsset($this->folder, 'p-b.jpg', $stacked);
    $c = simAsset($this->folder, 'p-c.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);
    $ids = simIds($a, $b, $c);

    // a and b already share a stack, c does not: the group of three is still there, showing each member's stack.
    [$group] = simGroupEntries($this, $ids);
    expect(simGroups($this, $ids))->toBe([$ids])
        ->and(array_column(array_column($group['assets'], 'stack'), 'key'))->toBe(['Fall 26', 'Fall 26', null])
        ->and(simPanel($this, $ids))->toHaveCount(1);

    // Two stacked files alone are settled.
    $d = simAsset($this->folder, 'p-d.jpg', ['stack_basis' => 'campaign', 'stack_key' => 'Winter']);
    $e = simAsset($this->folder, 'p-e.jpg', ['stack_basis' => 'campaign', 'stack_key' => 'Winter']);
    simHash($d, ~$this->base);
    simHash($e, ~$this->base ^ 0b1);
    expect(simGroups($this, simIds($d, $e)))->toBe([])->and(simPanel($this, simIds($d, $e)))->toBe([]);

    simApplyGroup($this, $ids, over: ['key' => 'Fall 26'])->assertOk()->assertJson(['stacked' => 3, 'skipped' => 0]);
    expect(simStackKey($c))->toBe('Fall 26')->and(simGroups($this, $ids))->toBe([]);
});

it('never lets a stale or altered action reach members it was not reviewed with', function () {
    $a = simAsset($this->folder, 's-a.jpg');
    $b = simAsset($this->folder, 's-b.jpg');
    $c = simAsset($this->folder, 's-c.jpg');
    $d = simAsset($this->folder, 's-d.jpg');
    $far = simAsset($this->folder, 's-far.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);
    simHash($far, ~$this->base);
    $abc = simIds($a, $b, $c);

    // The key must be the one of the members sent; a size, a member or an edge that is not there is refused.
    simApplyGroup($this, simIds($a, $b), SimilarImages::groupKey($abc))->assertStatus(409);
    simApplyGroup($this, $abc, SimilarImages::groupKey(simIds($a, $b)))->assertStatus(409);
    simApplyGroup($this, simIds($a, $b, $far))->assertStatus(409);
    // Repeated ids collapse to one member, which is no group; a lone id fails validation.
    simApplyGroup($this, [$a->id, $a->id])->assertStatus(409);
    simApplyGroup($this, [$a->id])->assertStatus(422);
    expect(simStackKey($a))->toBeNull()->and(simStackKey($far))->toBeNull();

    // `d` joins the cluster after the tab was opened: the tab still stacks exactly a, b and c, never d.
    simHash($d, $this->base ^ 0b11);
    simApplyGroup($this, $abc)->assertOk()->assertJson(['stacked' => 3]);
    expect(simStackKey($a))->toBe('Hero shoot')->and(simStackKey($c))->toBe('Hero shoot')->and(simStackKey($d))->toBeNull();

    // A member re-hashed away since the group was shown: refused, nothing stacked.
    $x = simAsset($this->folder, 's-x.jpg');
    $y = simAsset($this->folder, 's-y.jpg');
    $z = simAsset($this->folder, 's-z.jpg');
    $family = ~$this->base ^ (0xFFFF << 20);
    simHash($x, $family);
    simHash($y, $family ^ 0b1);
    simHash($z, $family ^ 0b10);
    $xyz = simIds($x, $y, $z);
    simHash($z, $family ^ (0xFFFF << 40));
    // The reason travels in the body (the Core handler gives a plain abort(409) only generic copy, which the page would then show).
    simApplyGroup($this, $xyz)->assertStatus(409)->assertJsonFragment(['message' => trans('dam::app.admin.dam.similar.group-changed'), 'reason' => 'group-changed']);

    // A pair dismissed since: refused as well.
    simHash($z, $family ^ 0b10);
    $this->postJson(route('admin.dam.similar.dismiss'), ['pairs' => [$x->id.':'.$y->id]])->assertOk();
    simApplyGroup($this, $xyz)->assertStatus(409);
    expect(simStackKey($x))->toBeNull()->and(simStackKey($y))->toBeNull()->and(simStackKey($z))->toBeNull();
});

it('keeps a legacy pair key acting on those two images only, even inside a larger group', function () {
    $a = simAsset($this->folder, 'l-a.jpg');
    $b = simAsset($this->folder, 'l-b.jpg');
    $c = simAsset($this->folder, 'l-c.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);

    $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'near_duplicate', 'group_key' => $a->id.':'.$b->id, 'basis' => 'campaign', 'key' => 'Pair only'])
        ->assertOk()->assertJson(['stacked' => 2]);
    expect(simStackKey($a))->toBe('Pair only')->and(simStackKey($b))->toBe('Pair only')->and(simStackKey($c))->toBeNull();

    // A near_group key is never resolved from the key alone: without its members the request is invalid.
    $this->postJson(route('admin.dam.stack-suggestions.apply'), ['kind' => 'near_group', 'group_key' => SimilarImages::groupKey(simIds($a, $b, $c)), 'basis' => 'campaign', 'key' => 'x'])->assertUnprocessable();
});

it('dismisses only the reviewed group\'s own pairs, for that viewer only, on both surfaces', function () {
    $a = simAsset($this->folder, 'd-a.jpg');
    $b = simAsset($this->folder, 'd-b.jpg');
    $c = simAsset($this->folder, 'd-c.jpg');
    $e = simAsset($this->folder, 'd-e.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);
    // `e` is within the threshold of c only (4 bits), 5 and 6 bits from a and b.
    simHash($e, $this->base ^ 0b10 ^ 0b11110000);
    $abc = simIds($a, $b, $c);
    $all = simIds($a, $b, $c, $e);
    $ce = simIds($c, $e);
    expect(simGroups($this, $all))->toBe([$ce, $abc]);

    // A changed set dismisses nothing.
    $this->postJson(route('admin.dam.similar.dismiss'), ['groups' => [['key' => SimilarImages::groupKey(simIds($a, $b, $e)), 'members' => simIds($a, $b, $e)]]])->assertStatus(409);
    expect(DB::table('dam_stack_suggestion_dismissals')->where('kind', 'near_duplicate')->count())->toBe(0);

    $this->postJson(route('admin.dam.similar.dismiss'), ['groups' => [['key' => SimilarImages::groupKey($abc), 'members' => $abc]]])
        ->assertOk()->assertJson(['groups' => 1, 'stale' => 0]);
    $dismissed = DB::table('dam_stack_suggestion_dismissals')->where('kind', 'near_duplicate')->pluck('group_key')->sort()->values()->all();
    expect($dismissed)->toBe([$a->id.':'.$b->id, $a->id.':'.$c->id, $b->id.':'.$c->id])
        // The pair c-e was not reviewed in that group and stays.
        ->and(simGroups($this, $all))->toBe([$ce])
        ->and(collect(simPanel($this, $all))->pluck('key')->all())->toBe([$c->id.':'.$e->id]);

    // Another viewer is unaffected.
    simActAs($this, Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]));
    expect(simGroups($this, $all))->toBe([$ce, $abc]);
});

it('hides a cached panel group as soon as one of its pairs is dismissed, and dismisses a panel group by its members', function () {
    $a = simAsset($this->folder, 'c-a.jpg');
    $b = simAsset($this->folder, 'c-b.jpg');
    $c = simAsset($this->folder, 'c-c.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);
    $ids = simIds($a, $b, $c);

    expect(simPanel($this, $ids))->toHaveCount(1);
    // The panel's own dismiss of a pair does not flush the cache; the cached group is re-checked on every read.
    $this->postJson(route('admin.dam.stack-suggestions.dismiss'), ['kind' => 'near_duplicate', 'group_key' => $a->id.':'.$b->id])->assertOk();
    $cached = array_values(array_filter($this->getJson(route('admin.dam.stack-suggestions.index'))->assertOk()->json('suggestions'), fn ($group) => $group['kind'] === 'near_group' && array_diff(array_column($group['entries'], 'id'), $ids) === []));
    expect($cached)->toBe([]);

    // Dismiss by members through the panel's route: a changed set is a 409, the reviewed one is dismissed.
    $d = simAsset($this->folder, 'c-d.jpg');
    $e = simAsset($this->folder, 'c-e.jpg');
    $f = simAsset($this->folder, 'c-f.jpg');
    $family = ~$this->base ^ (0xFFFF << 20);
    simHash($d, $family);
    simHash($e, $family ^ 0b1);
    simHash($f, $family ^ 0b10);
    $def = simIds($d, $e, $f);
    $this->postJson(route('admin.dam.stack-suggestions.dismiss'), ['kind' => 'near_group', 'group_key' => SimilarImages::groupKey(simIds($d, $e)), 'members' => $def])->assertStatus(409);
    $this->postJson(route('admin.dam.stack-suggestions.dismiss'), ['kind' => 'near_group', 'group_key' => SimilarImages::groupKey($def), 'members' => $def])->assertOk();
    expect(simPanel($this, $def))->toBe([])->and(simGroups($this, $def))->toBe([]);
});

it('pages groups, each exactly once and closest first, and counts groups not pairs', function () {
    $clique = [simAsset($this->folder, 'g1-a.jpg'), simAsset($this->folder, 'g1-b.jpg'), simAsset($this->folder, 'g1-c.jpg')];
    $pairA = [simAsset($this->folder, 'g2-a.jpg'), simAsset($this->folder, 'g2-b.jpg')];
    $pairB = [simAsset($this->folder, 'g3-a.jpg'), simAsset($this->folder, 'g3-b.jpg')];
    // Three families 32 or 64 bits apart from each other.
    $families = [$this->base, ~$this->base, $this->base ^ 0xFFFFFFFF];
    simHash($clique[0], $families[0]);
    simHash($clique[1], $families[0] ^ 0b1);
    simHash($clique[2], $families[0] ^ 0b10);
    simHash($pairA[0], $families[1]);
    simHash($pairA[1], $families[1] ^ 0b1);
    simHash($pairB[0], $families[2]);
    simHash($pairB[1], $families[2] ^ 0b111);
    $own = simIds(...$clique, ...$pairA, ...$pairB);

    $seen = [];
    $distances = [];
    $reported = 0;

    foreach (range(1, 20) as $page) {
        $response = $this->getJson(route('admin.dam.similar.index', ['per_page' => 2, 'page' => $page]))->assertOk();
        $reported = $response->json('meta.total');

        foreach ($response->json('data') as $group) {
            $distances[] = $group['distance'];

            if (array_diff($group['members'], $own) === []) {
                $seen[] = $group['members'];
            }
        }

        if ($page >= $response->json('meta.last_page')) {
            break;
        }
    }

    sort($seen);
    expect($seen)->toBe(collect([simIds(...$pairA), simIds(...$clique), simIds(...$pairB)])->sort()->values()->all())
        ->and(count($distances))->toBe($reported)
        ->and($distances)->toBe(collect($distances)->sort()->values()->all());
});

it('says so when the edge ceiling is reached, and never otherwise', function () {
    $a = simAsset($this->folder, 't-a.jpg');
    $b = simAsset($this->folder, 't-b.jpg');
    $c = simAsset($this->folder, 't-c.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);

    $meta = $this->getJson(route('admin.dam.similar.index'))->assertOk()->json('meta');
    expect($meta['truncated'])->toBeFalse();

    config(['dam.similar.group_edge_ceiling' => 2]);
    $meta = $this->getJson(route('admin.dam.similar.index'))->assertOk()->json('meta');
    expect($meta['truncated'])->toBeTrue()->and($meta['edge_ceiling'])->toBe(2);
    $this->get(route('admin.dam.similar.index'))->assertOk()->assertSee('data-dam-similar-truncated', false);
});

it('keeps REST on pairs unless groups are asked for, and refuses an unknown view', function () {
    $a = simAsset($this->folder, 'r-a.jpg');
    $b = simAsset($this->folder, 'r-b.jpg');
    $c = simAsset($this->folder, 'r-c.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);
    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.similar', 'api.dam.similar.dismiss']);

    $pairs = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.similar', $a->id))->assertOk();
    expect($pairs->json('data'))->toHaveCount(2)->and($pairs->json('data.0'))->toHaveKeys(['pair_key', 'assets'])->and($pairs->json('data.0.assets'))->toHaveCount(2);

    $groups = $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.similar', [$a->id, 'view' => 'groups']))->assertOk();
    expect($groups->json('data'))->toHaveCount(1)->and($groups->json('data.0.members'))->toBe(simIds($a, $b, $c))
        ->and($groups->json('data.0.group_key'))->toBe(SimilarImages::groupKey(simIds($a, $b, $c)));

    $this->withHeaders($headers)->getJson(route('admin.api.dam.similar.index', ['view' => 'nope']))->assertUnprocessable();
});

it('renders the Similar images page as group cards with a member grid and a stack action', function () {
    $a = simAsset($this->folder, 'u-a.jpg');
    $b = simAsset($this->folder, 'u-b.jpg');
    $c = simAsset($this->folder, 'u-c.jpg');
    simHash($a, $this->base);
    simHash($b, $this->base ^ 0b1);
    simHash($c, $this->base ^ 0b10);

    $page = $this->get(route('admin.dam.similar.index'))->assertOk();
    $page->assertSee('data-dam-similar-group', false)->assertSee('data-dam-similar-members', false)->assertSee('data-dam-similar-count', false)
        ->assertSee('review: { group_key: group.group_key, members: group.members }', false)->assertSee(SimilarImages::groupKey(simIds($a, $b, $c)), false);
});

/** Three similar images (a group) with the group key and members the panel hands to the Create stack review. */
function simReviewed(object $test, string $stem): array
{
    $a = simAsset($test->folder, $stem.'-a.jpg');
    $b = simAsset($test->folder, $stem.'-b.jpg');
    $c = simAsset($test->folder, $stem.'-c.jpg');
    simHash($a, $test->base);
    simHash($b, $test->base ^ 0b1);
    simHash($c, $test->base ^ 0b10);
    $members = simIds($a, $b, $c);

    return [$a, $b, $c, $members, ['group_key' => SimilarImages::groupKey($members), 'members' => $members]];
}

it('confirms a panel group through the merge dialog only while it is still the group that was reviewed', function () {
    [$a, $b, $c, $members, $review] = simReviewed($this, 'm');
    $query = ['sources' => [$b->id, $c->id], 'review_key' => $review['group_key'], 'review_members' => $members];

    $this->getJson(route('admin.dam.assets.merges.preview', ['asset' => $a->id, ...$query]))->assertOk();
    // Sources outside the reviewed members are refused, as is a key that is not the members'.
    $outsider = simAsset($this->folder, 'm-out.jpg');
    $this->getJson(route('admin.dam.assets.merges.preview', ['asset' => $a->id, ...$query, 'sources' => [$b->id, $outsider->id]]))->assertStatus(409);
    $this->getJson(route('admin.dam.assets.merges.preview', ['asset' => $a->id, ...$query, 'review_key' => SimilarImages::groupKey(simIds($a, $b))]))->assertStatus(409);
    $this->postJson(route('admin.dam.assets.merges.store', $a->id), ['sources' => [$b->id, $outsider->id], 'review' => $review])->assertStatus(409);

    // A pair dismissed since the panel was opened: the confirmation merges nothing.
    $this->postJson(route('admin.dam.similar.dismiss'), ['pairs' => [$b->id.':'.$c->id]])->assertOk();
    $this->getJson(route('admin.dam.assets.merges.preview', ['asset' => $a->id, ...$query]))->assertStatus(409);
    $this->postJson(route('admin.dam.assets.merges.store', $a->id), ['sources' => [$b->id, $c->id], 'review' => $review])
        ->assertStatus(409)->assertJsonFragment(['message' => trans('dam::app.admin.dam.similar.group-changed')]);
    expect(DB::table('dam_asset_merges')->whereIn('dest_id', [$a->id])->count())->toBe(0);

    // The dismissal of a subset (a, b) merges as reviewed once the group is intact again: pair-wise edges among merged files only.
    DB::table('dam_stack_suggestion_dismissals')->delete();
    $this->postJson(route('admin.dam.assets.merges.store', $a->id), ['sources' => [$b->id, $c->id], 'review' => $review])->assertCreated();
    expect(DB::table('dam_asset_merges')->where('dest_id', $a->id)->count())->toBe(2);
});

it('refuses a merge confirmation when a member changes between the preview and the row locks', function () {
    [$a, $b, $c, $members, $review] = simReviewed($this, 'ml');

    Event::listen(AssetMergeService::EVENT_LOCKING, fn () => simHash($c, ~$this->base));
    $this->postJson(route('admin.dam.assets.merges.store', $a->id), ['sources' => [$b->id, $c->id], 'review' => $review])->assertStatus(409);
    expect(DB::table('dam_asset_merges')->where('dest_id', $a->id)->count())->toBe(0);
});

it('refuses a group stack when a member is re-hashed or trashed between the check and the row locks', function () {
    [$a, $b, $c, $members] = simReviewed($this, 'sl');

    Event::listen(StackSuggestions::EVENT_LOCKING, fn () => simHash($c, ~$this->base));
    simApplyGroup($this, $members)->assertStatus(409);
    expect(simStackKey($a))->toBeNull()->and(simStackKey($c))->toBeNull();

    Event::forget(StackSuggestions::EVENT_LOCKING);
    simHash($c, $this->base ^ 0b10);
    Event::listen(StackSuggestions::EVENT_LOCKING, fn () => $b->delete());
    simApplyGroup($this, $members)->assertStatus(409);
    expect(simStackKey($a))->toBeNull()->and(simStackKey($c))->toBeNull();
});

it('dismisses a select-all of more groups than one page holds', function () {
    $groups = [];

    foreach (range(1, 60) as $i) {
        $hash = $i * 0x0101010101010101;
        $one = simAsset($this->folder, "x{$i}-a.jpg");
        $two = simAsset($this->folder, "x{$i}-b.jpg");
        simHash($one, $hash);
        simHash($two, $hash ^ 0b1);
        $ids = simIds($one, $two);
        $groups[] = ['key' => SimilarImages::groupKey($ids), 'members' => $ids];
    }

    $this->postJson(route('admin.dam.similar.dismiss'), ['groups' => $groups])->assertOk()->assertJson(['groups' => 60, 'stale' => 0]);
    expect(DB::table('dam_stack_suggestion_dismissals')->where('kind', 'near_duplicate')->count())->toBe(60);
    $this->postJson(route('admin.dam.similar.dismiss'), ['groups' => [...$groups, ...$groups, ...array_slice($groups, 0, 1)]])->assertUnprocessable();
});

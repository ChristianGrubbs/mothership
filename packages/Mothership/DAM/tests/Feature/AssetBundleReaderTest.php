<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\AssetBundleReader;
use Mothership\DataTransfer\Contracts\JobTrack as JobTrackContract;

/*
 * Reading a bundle has to reproduce the source DAM tree and stay idempotent: an import
 * re-run must not duplicate assets, nor overwrite a binary edited since the first run.
 */

beforeEach(function (): void {
    $this->damResetAssetTables();

    Storage::fake('private');
    Bus::fake();

    $this->bundle = function (array $entries, int $trackId = 41): object {
        $archive = Storage::disk('private')->path('imports/bundle.zip');

        if (! is_dir(dirname($archive))) {
            mkdir(dirname($archive), 0755, true);
        }

        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return new class($trackId) implements JobTrackContract
        {
            public string $file_path = 'imports/bundle.zip';

            public function __construct(public int $id) {}
        };
    };
});

it('returns the archive data file and recreates the asset tree', function () {
    $track = ($this->bundle)([
        'products.csv'                   => "sku,name\nsku-1,One",
        'assets/Root/Marketing/hero.jpg' => 'hero-bytes',
        'assets/Root/Docs/spec.pdf'      => 'spec-bytes',
    ]);

    $dataFile = (new AssetBundleReader)->prepare($track);

    expect($dataFile)->toBe('imports/bundles/41/products.csv');

    $root = Directory::where('name', 'Root')->whereNull('parent_id')->first();

    expect($root)->not->toBeNull()
        ->and(Directory::where('name', 'Marketing')->where('parent_id', $root->id)->exists())->toBeTrue()
        ->and(Directory::where('name', 'Docs')->where('parent_id', $root->id)->exists())->toBeTrue();

    $hero = Asset::where('path', 'assets/Root/Marketing/hero.jpg')->first();
    $store = app(ContentStore::class);
    $stream = $store->open($store->current($hero));

    expect($hero)->not->toBeNull()
        ->and($hero->file_name)->toBe('hero.jpg')
        ->and($hero->extension)->toBe('jpg')
        ->and(stream_get_contents($stream))->toBe('hero-bytes')
        ->and(Storage::disk('private')->exists('assets/Root/Marketing/hero.jpg'))->toBeFalse();
    fclose($stream);
});

it('stores a file type the assets table accepts', function () {
    ($this->bundle)([
        'products.csv'                   => "sku\nsku-1",
        'assets/Root/Marketing/hero.jpg' => 'hero-bytes',
        'assets/Root/Docs/spec.pdf'      => 'spec-bytes',
        'assets/Root/Docs/rates.xlsx'    => 'sheet-bytes',
        'assets/Root/Clips/promo.mp4'    => 'clip-bytes',
    ]);

    (new AssetBundleReader)->prepare(($this->bundle)([
        'products.csv'                   => "sku\nsku-1",
        'assets/Root/Marketing/hero.jpg' => 'hero-bytes',
        'assets/Root/Docs/spec.pdf'      => 'spec-bytes',
        'assets/Root/Docs/rates.xlsx'    => 'sheet-bytes',
        'assets/Root/Clips/promo.mp4'    => 'clip-bytes',
    ], 42));

    // Ordered explicitly: without ORDER BY, PostgreSQL returns rows in physical order, which an
    // update after insert changes (CI, 2026-09-23: promo.mp4 came back first).
    expect(Asset::orderBy('file_name')->pluck('file_type', 'file_name')->all())->toBe([
        'hero.jpg'   => 'image',
        'promo.mp4'  => 'video',
        'rates.xlsx' => 'document',
        'spec.pdf'   => 'document',
    ]);
});

it('queues metadata extraction for each ingested asset', function () {
    ($this->bundle)([
        'products.csv'                   => "sku\nsku-1",
        'assets/Root/Marketing/hero.jpg' => 'hero-bytes',
    ]);

    (new AssetBundleReader)->prepare(($this->bundle)([
        'products.csv'                   => "sku\nsku-1",
        'assets/Root/Marketing/hero.jpg' => 'hero-bytes',
    ]));

    Bus::assertDispatched(ProcessAssetUpload::class);
});

it('reuses an asset already present at the same path', function () {
    $entries = [
        'products.csv'                   => "sku\nsku-1",
        'assets/Root/Marketing/hero.jpg' => 'hero-bytes',
    ];

    (new AssetBundleReader)->prepare(($this->bundle)($entries, 41));

    $first = Asset::where('path', 'assets/Root/Marketing/hero.jpg')->firstOrFail();
    $firstFileObjectId = $first->files()->where('role', 'original')->value('file_object_id');

    (new AssetBundleReader)->prepare(($this->bundle)($entries, 42));

    // A repeated import neither duplicates the row nor re-ingests content someone since edited.
    expect(Asset::where('path', 'assets/Root/Marketing/hero.jpg')->count())->toBe(1)
        ->and(Asset::where('path', 'assets/Root/Marketing/hero.jpg')->value('id'))->toBe($first->id)
        ->and(Asset::find($first->id)->files()->where('role', 'original')->value('file_object_id'))->toBe($firstFileObjectId);
});

it('does not duplicate directories across repeated imports', function () {
    $entries = [
        'products.csv'                   => "sku\nsku-1",
        'assets/Root/Marketing/hero.jpg' => 'hero-bytes',
    ];

    (new AssetBundleReader)->prepare(($this->bundle)($entries, 41));
    (new AssetBundleReader)->prepare(($this->bundle)($entries, 42));

    expect(Directory::where('name', 'Root')->count())->toBe(1)
        ->and(Directory::where('name', 'Marketing')->count())->toBe(1);
});

it('fails when the archive carries no data file', function () {
    $track = ($this->bundle)([
        'assets/Root/Marketing/hero.jpg' => 'hero-bytes',
    ]);

    expect(fn () => (new AssetBundleReader)->prepare($track))
        ->toThrow(RuntimeException::class);
});

it('refuses an executable disguised inside the asset tree', function () {
    $track = ($this->bundle)([
        'products.csv'               => "sku\nsku-1",
        'assets/Root/payload.php'    => '<?php echo "owned";',
    ]);

    (new AssetBundleReader)->prepare($track);

    expect(Asset::where('path', 'assets/Root/payload.php')->exists())->toBeFalse();
});

it('refuses an entry that would land under a reserved namespace like .objects or .staging', function () {
    $track = ($this->bundle)([
        'products.csv'                => "sku\nsku-1",
        'assets/.objects/ab/deadbeef' => 'would-overwrite-a-live-blob',
        'assets/.staging/evil.png'    => 'staged-bytes',
    ]);

    (new AssetBundleReader)->prepare($track);

    expect(Asset::where('path', 'like', 'assets/.objects%')->exists())->toBeFalse()
        ->and(Asset::where('path', 'like', 'assets/.staging%')->exists())->toBeFalse()
        ->and(Directory::where('name', '.objects')->exists())->toBeFalse()
        ->and(Directory::where('name', '.staging')->exists())->toBeFalse();
});

it('imports an html asset as a web page', function () {
    ($this->bundle)([
        'products.csv'                => "sku\nsku-1",
        'assets/Root/Pages/page.html' => '<!doctype html><p>imported</p>',
    ]);

    (new AssetBundleReader)->prepare(($this->bundle)([
        'products.csv'                => "sku\nsku-1",
        'assets/Root/Pages/page.html' => '<!doctype html><p>imported</p>',
    ], 43));

    expect(Asset::where('file_name', 'page.html')->value('file_type'))->toBe('web');
    // The reader's own finalization runs ProcessAssetUpload, which dispatches the bundler.
    Bus::assertDispatched(ProcessAssetUpload::class);
});

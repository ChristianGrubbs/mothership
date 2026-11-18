<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Analytics\EventStore;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;

/*
 * P2 S3 through public links: availability and the window apply to links too. An asset link to an archived or
 * closed-window asset answers 410 (the expired-link page), to one whose window has not opened 410 with a neutral
 * "not available" page, to a draft or pending one 404; a folder link simply leaves such assets out of its gallery,
 * list, viewer, bytes and zip.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('dam_shares')->delete();
    Storage::fake(Directory::getAssetDisk());
});

function availabilityShareImage(array $attributes = []): Asset
{
    $asset = Asset::factory()->create($attributes + ['extension' => 'png', 'file_type' => 'image', 'mime_type' => 'image/png']);
    $image = UploadedFile::fake()->image('x.png', 4, 4);
    Storage::disk(Directory::getAssetDisk())->put($asset->path, file_get_contents($image->getRealPath()));
    $asset->markGraphContentChanged()->save();

    return $asset->refresh();
}

it('answers an asset link 410 when the asset is archived or outside its window, 404 when it is a draft or pending', function () {
    $cases = [
        'archived' => [['availability' => Asset::AVAILABILITY_ARCHIVED], 410],
        'not yet'  => [['available_from' => now()->addDay()], 410],
        'expired'  => [['available_until' => now()->subMinute()], 410],
        'draft'    => [['availability' => Asset::AVAILABILITY_DRAFT], 404],
        'pending'  => [['availability' => Asset::AVAILABILITY_PENDING_REVIEW], 404],
    ];

    foreach ($cases as $name => [$attributes, $status]) {
        $asset = availabilityShareImage($attributes);
        $share = Share::factory()->forAsset($asset->id)->create();

        foreach ([
            'show'      => route('dam.share.show', $share->urlToken()),
            'download'  => route('dam.share.download', $share->urlToken()),
            'thumbnail' => route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $asset->id]),
        ] as $route => $url) {
            expect($this->get($url)->getStatusCode())->toBe($status, "{$name} {$route}");
        }

        // A draft or pending asset's link reads exactly like an unknown token's, body and all.
        if ($status === 404) {
            foreach (['dam.share.show', 'dam.share.download'] as $route) {
                expect($this->get(route($route, $share->urlToken()))->getContent())
                    ->toBe($this->get(route($route, str_repeat('Z', 64)))->getContent(), "{$name} {$route} body");
            }
        }
    }

    $open = availabilityShareImage(['available_from' => now()->subDay(), 'available_until' => now()->addDay()]);
    $this->get(route('dam.share.show', Share::factory()->forAsset($open->id)->create()->urlToken()))->assertOk();
});

it('records no link visit for a gone or not-found asset link page, only for one that shows the file', function () {
    $visits = fn (Share $share) => DB::table(EventStore::TABLE)->where('name', 'share.link.visited')->where('share_id', $share->id)->count();
    $gone = Share::factory()->forAsset(availabilityShareImage(['availability' => Asset::AVAILABILITY_ARCHIVED])->id)->create();
    $missing = Share::factory()->forAsset(availabilityShareImage(['availability' => Asset::AVAILABILITY_DRAFT])->id)->create();
    $open = Share::factory()->forAsset(availabilityShareImage()->id)->create();

    $this->get(route('dam.share.show', $gone->urlToken()))->assertStatus(410);
    $this->get(route('dam.share.show', $missing->urlToken()))->assertNotFound();
    $this->get(route('dam.share.show', $open->urlToken()))->assertOk();

    expect($visits($gone))->toBe(0)
        ->and($visits($missing))->toBe(0)
        ->and($visits($open))->toBe(1);

    // A folder link counts its page too, an empty folder included; a HEAD never does.
    $folder = Share::factory()->forDirectory(Directory::factory()->create()->id)->create();
    $this->get(route('dam.share.show', $folder->urlToken()))->assertOk();
    $this->call('HEAD', route('dam.share.show', $folder->urlToken()));
    $this->call('HEAD', route('dam.share.show', $open->urlToken()));

    expect($visits($folder))->toBe(1)
        ->and($visits($open))->toBe(1);
});

it('leaves an unavailable asset out of a folder link everywhere, as if it were not in the folder', function () {
    $directory = Directory::factory()->create();
    $shown = availabilityShareImage(['file_name' => 'shown-file.png']);
    $draft = availabilityShareImage(['file_name' => 'draft-file.png', 'availability' => Asset::AVAILABILITY_DRAFT]);
    $expired = availabilityShareImage(['file_name' => 'expired-file.png', 'available_until' => now()->subMinute()]);
    $directory->assets()->attach([$shown->id, $draft->id, $expired->id]);
    $share = Share::factory()->forDirectory($directory->id)->create();

    $this->get(route('dam.share.show', $share->urlToken()))->assertOk()
        ->assertSeeText('shown-file.png')->assertDontSeeText('draft-file.png')->assertDontSeeText('expired-file.png');

    expect(collect($this->getJson(route('dam.share.list_assets', $share->urlToken()))->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$shown->id]);

    foreach ([$draft, $expired] as $hidden) {
        $this->get(route('dam.share.asset_view', ['token' => $share->urlToken(), 'assetId' => $hidden->id]))->assertNotFound();
        $this->get(route('dam.share.asset_download', ['token' => $share->urlToken(), 'assetId' => $hidden->id]))->assertNotFound();
        $this->get(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $hidden->id]))->assertNotFound();
    }

    $zip = $this->get(route('dam.share.download_zip', $share->urlToken()))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'share-zip');
    file_put_contents($path, $zip->streamedContent());
    $archive = new ZipArchive;
    $archive->open($path);
    $names = collect(range(0, $archive->numFiles - 1))->map(fn ($i) => basename($archive->getNameIndex($i)))->all();
    $archive->close();
    unlink($path);

    // Entries are named by storage path: only the available file's.
    expect($names)->toBe([basename($shown->path)]);
});

it('tells a visitor a not-yet-open file is not available, never that the link expired', function () {
    $scheduled = Share::factory()->forAsset(availabilityShareImage(['available_from' => now()->addDay()])->id)->create();
    $expired = Share::factory()->forAsset(availabilityShareImage(['available_until' => now()->subMinute()])->id)->create();
    $archived = Share::factory()->forAsset(availabilityShareImage(['availability' => Asset::AVAILABILITY_ARCHIVED])->id)->create();

    foreach (['dam.share.show', 'dam.share.download'] as $route) {
        $this->get(route($route, $scheduled->urlToken()))->assertStatus(410)
            ->assertSeeText(trans('dam::app.share.public.unavailable-title'))
            ->assertSeeText(trans('dam::app.share.public.unavailable-message'))
            ->assertDontSeeText(trans('dam::app.share.public.expired-title'))
            ->assertDontSeeText(trans('dam::app.share.public.expired-message'));

        foreach (['expired' => $expired, 'archived' => $archived] as $share) {
            $this->get(route($route, $share->urlToken()))->assertStatus(410)
                ->assertSeeText(trans('dam::app.share.public.expired-title'))
                ->assertDontSeeText(trans('dam::app.share.public.unavailable-title'));
        }
    }
});

it('follows the asset when its state changes after the link was made', function () {
    $asset = availabilityShareImage(['file_name' => 'changing-file.png']);
    $share = Share::factory()->forAsset($asset->id)->create();
    $show = fn () => $this->get(route('dam.share.show', $share->urlToken()));
    $download = fn () => $this->get(route('dam.share.download', $share->urlToken()));
    $unknown = fn (string $route) => $this->get(route($route, str_repeat('Z', 64)))->getContent();

    $show()->assertOk()->assertSeeText('changing-file.png');
    $download()->assertOk();

    // A draft: exactly an unknown link, body and all, and the file name is nowhere.
    $asset->update(['availability' => Asset::AVAILABILITY_DRAFT]);
    expect($show()->assertNotFound()->getContent())->toBe($unknown('dam.share.show'))
        ->and($download()->assertNotFound()->getContent())->toBe($unknown('dam.share.download'));

    // Its window not yet open: the neutral page, not the file.
    $asset->update(['availability' => Asset::AVAILABILITY_AVAILABLE, 'available_from' => now()->addHour()]);
    $show()->assertStatus(410)->assertSeeText(trans('dam::app.share.public.unavailable-title'))->assertDontSeeText('changing-file.png');

    // Its window closed: the expired page.
    $asset->update(['available_from' => null, 'available_until' => now()->subSecond()]);
    $show()->assertStatus(410)->assertSeeText(trans('dam::app.share.public.expired-title'))->assertDontSeeText('changing-file.png');
    $download()->assertStatus(410);

    // Available again: the same link works again.
    $asset->update(['available_until' => null]);
    $show()->assertOk()->assertSeeText('changing-file.png');
    $download()->assertOk();
});

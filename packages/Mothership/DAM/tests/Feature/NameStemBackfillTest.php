<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Database\Backfill\NameStemBackfill;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Support\FilenameStem;

/*
 * P3b-2 review: dam_assets.name_stem backfill (migration 2026_10_07_000601 and `dam:name-stems`). Rows written before
 * the column existed (null), rows with a stale stem and trashed rows all end up with FilenameStem's answer; a dry run
 * reconciles and writes nothing; a second run writes nothing. Run on SQLite here; PostgreSQL and SQL Server are not
 * exercised by this file.
 */

/** Legacy rows: created through the model, then the stem set back as an old row (or a stale rule) would have it. */
function stemBackfillRows(): array
{
    $tag = Str::lower(Str::random(5));
    $names = [
        'legacy'  => "Hero{$tag}_final-v3@2x (1).png",
        'unicode' => "Café Crème {$tag}-v2.JPG",
        'cjk'     => "日本語 ポスター{$tag} (1).jpg",
        'umlaut'  => "ÜBER_Plakat_{$tag}_final.png",
        'short'   => 'a.jpg',
        'digit'   => '1.png',
        'empty'   => '',
        'long'    => str_repeat('x', 240).$tag.'.jpg', // 249 chars: over the 191 stem cap, inside the 255 file_name column
        'version' => "v2{$tag}.jpg",
        'stale'   => "Banner{$tag} 1920x1080.jpg",
        'trashed' => "Poster{$tag}-v7.tif",
    ];

    $ids = [];
    foreach ($names as $key => $name) {
        $ids[$key] = Asset::factory()->create(['file_name' => $name, 'path' => "assets/Root/stem-{$tag}-{$key}"])->id;
    }

    DB::table('dam_assets')->whereIn('id', array_values($ids))->update(['name_stem' => null]);
    DB::table('dam_assets')->where('id', $ids['stale'])->update(['name_stem' => 'wrong']);
    DB::table('dam_assets')->where('id', $ids['trashed'])->update(['deleted_at' => now()]);

    return ['tag' => $tag, 'ids' => $ids];
}

function stemBackfillStored(array $ids): array
{
    return DB::table('dam_assets')->whereIn('id', array_values($ids))->pluck('name_stem', 'id')
        ->pipe(fn ($stems) => array_map(fn ($id) => $stems[$id], $ids));
}

it('derives stems from Unicode, short, empty, very long and suffix-heavy names', function () {
    expect(FilenameStem::of(null))->toBeNull()
        ->and(FilenameStem::of(''))->toBeNull()
        ->and(FilenameStem::of('a.jpg'))->toBeNull()
        ->and(FilenameStem::of('Hero_final-v3@2x (1).png'))->toBe('hero')
        ->and(FilenameStem::of('Café Crème-v2.JPG'))->toBe('café crème')
        ->and(FilenameStem::of('ÜBER_Plakat_final.png'))->toBe('über plakat')
        ->and(FilenameStem::of('日本語 ポスター (1).jpg'))->toBe('日本語 ポスター')
        ->and(FilenameStem::of('v2.jpg'))->toBe('v2')
        ->and(mb_strlen((string) FilenameStem::of(str_repeat('é', 400).'.png')))->toBe(FilenameStem::MAX_LENGTH);
});

it('reconciles a dry run and writes nothing', function () {
    ['ids' => $ids] = stemBackfillRows();
    $before = stemBackfillStored($ids);

    $report = app(NameStemBackfill::class)->run(dryRun: true);

    expect($report['dry_run'])->toBeTrue()
        ->and($report['rows_seen'])->toBe(DB::table('dam_assets')->count())
        ->and($report['rows_seen'])->toBe($report['rows_with_stem'] + $report['rows_without_stem'])
        ->and($report['mismatches'])->toBe([])
        // The legacy rows that will get a stem, and the stale one: at least these would be written.
        ->and($report['rows_written'])->toBeGreaterThanOrEqual(8)
        ->and(stemBackfillStored($ids))->toBe($before);

    // The command's dry run says so, and also writes nothing.
    expect(Artisan::call('dam:name-stems', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('nothing was written')
        ->and(stemBackfillStored($ids))->toBe($before);
});

it('fills legacy, stale and trashed rows, and a second run writes nothing', function () {
    ['tag' => $tag, 'ids' => $ids] = stemBackfillRows();

    $first = app(NameStemBackfill::class)->run();
    $stored = stemBackfillStored($ids);

    expect($first['mismatches'])->toBe([])
        ->and($stored)->toBe([
            'legacy'  => "hero{$tag}",
            'unicode' => "café crème {$tag}",
            'cjk'     => "日本語 ポスター{$tag}",
            'umlaut'  => "über plakat {$tag}",
            'short'   => null,
            'digit'   => null,
            'empty'   => null,
            'long'    => mb_substr(str_repeat('x', 240).$tag, 0, FilenameStem::MAX_LENGTH),
            'version' => "v2{$tag}",
            'stale'   => "banner{$tag}",
            'trashed' => "poster{$tag}",
        ]);

    $second = app(NameStemBackfill::class)->run();
    expect($second['rows_written'])->toBe(0)
        ->and($second['rows_seen'])->toBe($first['rows_seen'])
        ->and(stemBackfillStored($ids))->toBe($stored);
});

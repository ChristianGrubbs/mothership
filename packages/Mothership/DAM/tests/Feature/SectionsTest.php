<?php

require_once __DIR__.'/../Support/OrganizationFixtures.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Services\Organization\SectionService;
use Mothership\DAM\Support\OrganizationNameLock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * P4 (D3): sections are admin-defined views. A file is in a section when the section's asset-type rule matches it or
 * the section pins it. A section never changes who can open a file: it only narrows what the viewer may already list.
 */

beforeEach(function () {
    $this->open = Directory::factory()->create(['name' => 'Open']);
    $this->closed = Directory::factory()->create(['name' => 'Closed']);
    $this->member = organizeMember($this->open);
    $this->video = organizeAsset($this->open, 'clip', ['file_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4']);
    $this->pdf = organizeAsset($this->open, 'brochure', ['file_type' => 'document', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);
    $this->photo = organizeAsset($this->open, 'photo');
    $this->other = organizeAsset($this->open, 'other');
    $this->hiddenVideo = organizeAsset($this->closed, 'secret-clip', ['file_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4']);
});

function makeSection(array $attributes): int
{
    return (int) test()->postJson(route('admin.dam.sections.store'), $attributes)->assertCreated()->json('data.id');
}

it('lists the files its rule matches plus the files it pins', function () {
    $section = makeSection(['name' => 'Motion & print', 'rule' => ['media_types' => ['videos'], 'extensions' => ['.PDF']]]);
    expect(SavedSearch::query()->find($section)->definition)->toBe(['rule' => ['media_types' => ['videos'], 'extensions' => ['pdf']]]);

    expect(organizeListedIds(['section_id' => $section]))->toEqualCanonicalizing([$this->video->id, $this->pdf->id]);

    $this->postJson(route('admin.dam.sections.pins.store', $section), ['asset_ids' => [$this->photo->id]])->assertOk()->assertJsonPath('data.pinned', 1);
    $ids = organizeListedIds(['section_id' => $section], $response);

    expect($ids)->toEqualCanonicalizing([$this->video->id, $this->pdf->id, $this->photo->id])
        ->and($response->json('meta.organization'))->toMatchArray(['type' => 'section', 'id' => $section])
        ->and($response->json('directories'))->toBe([])
        ->and(collect($this->getJson(route('admin.dam.organize.index', ['asset_ids' => [$this->photo->id]]))->json('sections'))->firstWhere('id', $section))
        ->toMatchArray(['count' => 3, 'pins' => 1]);

    // A section with no rule is pins only; unpinning leaves the file in the Library.
    $pinsOnly = makeSection(['name' => 'Picks']);
    $this->postJson(route('admin.dam.sections.pins.store', $pinsOnly), ['asset_ids' => [$this->other->id]])->assertOk();
    expect(organizeListedIds(['section_id' => $pinsOnly]))->toBe([$this->other->id]);
    $this->deleteJson(route('admin.dam.sections.pins.destroy', $pinsOnly), ['asset_ids' => [$this->other->id]])->assertOk();
    expect(organizeListedIds(['section_id' => $pinsOnly]))->toBe([])
        ->and(organizeListedIds())->toContain($this->other->id);
});

it('never changes access: a hidden file stays out of the section, its count and its pins', function () {
    $grantsBefore = [DB::table('dam_directory_role')->count(), DB::table('dam_asset_user_access')->count(), DB::table('dam_directory_user_access')->count()];
    $section = makeSection(['name' => 'Video', 'rule' => ['media_types' => ['videos']]]);
    // Someone who can open the hidden clip pinned it.
    DB::table('dam_section_pins')->insert(['section_id' => $section, 'asset_id' => $this->hiddenVideo->id, 'position' => 0, 'created_at' => now()]);

    expect(organizeListedIds(['section_id' => $section]))->toBe([$this->video->id])
        ->and(collect($this->getJson(route('admin.dam.organize.index'))->json('sections'))->firstWhere('id', $section)['count'])->toBe(1)
        ->and(organizeListedIds(['section_id' => $section, 'sort_by' => 'position', 'sort_order' => 'asc']))->toBe([$this->video->id])
        ->and([DB::table('dam_directory_role')->count(), DB::table('dam_asset_user_access')->count(), DB::table('dam_directory_user_access')->count()])->toBe($grantsBefore);

    config(['app.debug' => false]);
    $missing = (int) Asset::max('id') + 100;
    organizeExpectSameAnswer(
        hidden: $this->postJson(route('admin.dam.sections.pins.store', $section), ['asset_ids' => [$this->hiddenVideo->id]]),
        missing: $this->postJson(route('admin.dam.sections.pins.store', $section), ['asset_ids' => [$missing]]),
        status: 404, what: 'pin',
    );
    organizeExpectSameAnswer(
        hidden: $this->putJson(route('admin.dam.sections.pins.update', [$section, $this->hiddenVideo->id]), ['position' => 0]),
        missing: $this->putJson(route('admin.dam.sections.pins.update', [$section, $missing]), ['position' => 0]),
        status: 404, what: 'move pin',
    );
    // Being in a section opened nothing: the Library still leaves the hidden clip out.
    expect(organizeListedIds())->not->toContain($this->hiddenVideo->id);
});

it('sorts by manual position: pins first in pin order, then the rest newest first', function () {
    $section = makeSection(['name' => 'Ordered', 'rule' => ['media_types' => ['videos'], 'extensions' => ['pdf']]]);
    $this->postJson(route('admin.dam.sections.pins.store', $section), ['asset_ids' => [$this->photo->id, $this->other->id]])->assertOk();
    $this->putJson(route('admin.dam.sections.pins.update', [$section, $this->other->id]), ['position' => 0])->assertOk();
    Asset::query()->whereKey($this->video->id)->update(['created_at' => now()->subDay()]);

    $ids = organizeListedIds(['section_id' => $section, 'sort_by' => 'position', 'sort_order' => 'asc'], $response);

    expect($ids)->toBe([$this->other->id, $this->photo->id, $this->pdf->id, $this->video->id])
        ->and(array_column($response->json('assets'), 'member_position'))->toBe([0, 1, null, null]);
});

it('uses the section\'s default sort until the viewer picks another', function () {
    $section = makeSection(['name' => 'By name', 'rule' => ['media_types' => ['videos'], 'extensions' => ['pdf', 'jpg']], 'default_sort' => 'name:asc']);
    $byName = Asset::query()->whereIn('id', [$this->video->id, $this->pdf->id, $this->photo->id, $this->other->id])->get()
        ->sortBy(fn (Asset $asset) => mb_strtolower($asset->display_name ?? $asset->file_name))->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

    organizeListedIds(['section_id' => $section], $response);
    expect(array_map('intval', array_column($response->json('assets'), 'id')))->toBe($byName)
        ->and($response->json('meta.sort'))->toBe('name:asc')
        ->and($response->json('meta.organization.default_sort'))->toBe('name:asc');

    organizeListedIds(['section_id' => $section, 'sort_by' => 'name', 'sort_order' => 'desc'], $response);
    expect(array_map('intval', array_column($response->json('assets'), 'id')))->toBe(array_reverse($byName));

    $this->putJson(route('admin.dam.sections.update', $section), ['default_sort' => 'bogus:asc'])->assertUnprocessable();
});

it('needs dam.sections.manage to change sections, while everyone browses them', function () {
    $section = makeSection(['name' => 'Managed', 'rule' => ['media_types' => ['videos']]]);

    organizeMember($this->open, without: ['dam.sections.manage']);
    $this->postJson(route('admin.dam.sections.store'), ['name' => 'Nope'])->assertForbidden();
    $this->putJson(route('admin.dam.sections.update', $section), ['name' => 'Nope'])->assertForbidden();
    $this->deleteJson(route('admin.dam.sections.destroy', $section))->assertForbidden();
    $this->postJson(route('admin.dam.sections.pins.store', $section), ['asset_ids' => [$this->photo->id]])->assertForbidden();

    expect(organizeListedIds(['section_id' => $section]))->toBe([$this->video->id])
        ->and(array_column($this->getJson(route('admin.dam.organize.index'))->json('sections'), 'name'))->toBe(['Managed']);
});

it('deletes a section and its pins without touching a file', function () {
    $section = makeSection(['name' => 'Gone']);
    $this->postJson(route('admin.dam.sections.pins.store', $section), ['asset_ids' => [$this->photo->id]])->assertOk();

    $this->deleteJson(route('admin.dam.sections.destroy', $section))->assertOk();

    expect(SavedSearch::query()->find($section))->toBeNull()
        ->and(DB::table('dam_section_pins')->count())->toBe(0)
        ->and(Asset::query()->whereKey($this->photo->id)->exists())->toBeTrue();
});

it('runs create and rename under the section lock, and refuses a clash case-insensitively', function () {
    $one = makeSection(['name' => 'Print']);
    $two = makeSection(['name' => 'Video']);
    $key = OrganizationNameLock::anchorKey('section');

    expect(DB::table('dam_configuration')->where('key', $key)->exists())->toBeTrue();
    DB::table('dam_configuration')->where('key', $key)->delete();

    $this->putJson(route('admin.dam.sections.update', $two), ['name' => 'PRINT'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->putJson(route('admin.dam.sections.update', $one), ['name' => 'Print 2'])->assertOk();
    expect(DB::table('dam_configuration')->where('key', $key)->exists())->toBeTrue();
});

it('applies an edit to the row as stored, not to the copy the request loaded before it waited', function () {
    $id = makeSection(['name' => 'Original']);
    $service = app(SectionService::class);
    $staleOne = SavedSearch::query()->findOrFail($id);
    $staleTwo = SavedSearch::query()->findOrFail($id);

    $service->update(section: $staleOne, attributes: ['name' => 'Changed'], viewerId: null);
    $service->update(section: $staleTwo, attributes: ['name' => 'Original'], viewerId: null);

    expect(SavedSearch::query()->find($id)->name)->toBe('Original');

    $gone = SavedSearch::query()->findOrFail($id);
    SavedSearch::query()->whereKey($id)->delete();
    expect(fn () => $service->update(section: $gone, attributes: ['name' => 'Back'], viewerId: null))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses a stale rename that now clashes with a name taken meanwhile', function () {
    $id = makeSection(['name' => 'Original']);
    $service = app(SectionService::class);
    $stale = SavedSearch::query()->findOrFail($id);

    $service->update(section: SavedSearch::query()->findOrFail($id), attributes: ['name' => 'Changed'], viewerId: null);
    makeSection(['name' => 'Original']);

    expect(fn () => $service->update(section: $stale, attributes: ['name' => 'original'], viewerId: null))
        ->toThrow(ValidationException::class);
    expect(SavedSearch::query()->find($id)->name)->toBe('Changed');
});

it('restores a field to the value it had when a stale copy was loaded, whatever changed it meanwhile', function (string $attribute, string $column, array $original, array $changed) {
    $id = makeSection(['name' => 'Stale '.$attribute] + $original);
    $service = app(SectionService::class);
    $initial = SavedSearch::query()->findOrFail($id)->{$column};
    $staleOne = SavedSearch::query()->findOrFail($id);
    $staleTwo = SavedSearch::query()->findOrFail($id);

    $service->update(section: $staleOne, attributes: $changed, viewerId: null);
    expect(SavedSearch::query()->findOrFail($id)->{$column})->not->toEqual($initial);

    $service->update(section: $staleTwo, attributes: $original, viewerId: null);

    expect(SavedSearch::query()->findOrFail($id)->{$column})->toEqual($initial);
})->with([
    'rule'         => ['rule', 'definition', ['rule' => ['media_types' => ['videos'], 'extensions' => ['mp4']]], ['rule' => ['media_types' => ['images'], 'extensions' => []]]],
    'default sort' => ['default_sort', 'default_sort', ['default_sort' => 'name:asc'], ['default_sort' => 'size:desc']],
]);

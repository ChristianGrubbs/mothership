<?php

require_once __DIR__.'/../../Support/CreativeHubFixtures.php';
require_once __DIR__.'/../../Support/HubResourceFixtures.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\HubResource;
use Mothership\DAM\Services\CreativeHub\HubResourceStore;
use Mothership\User\Repositories\AdminRepository;

/*
 * Uploading and sharing Hub resources over HTTP (ADR 0015): every refusal is a translated 422 with its reason code,
 * sharing needs a license attestation and a note, and deleting a person releases their fonts and brushes.
 */

beforeEach(function () {
    hubEnable();
    Storage::fake(Directory::getAssetDisk());
    $this->owner = hubResourceAdmin();
    $this->actingAs($this->owner, 'admin');
});

function hubUpload(string $bytes, string $name): TestResponse
{
    return test()->postJson(route('admin.dam.hub.resources.store'), ['file' => UploadedFile::fake()->createWithContent($name, $bytes)]);
}

/** A private resource of the signed-in owner, stored without the upload route (which shares a new upload). */
function hubStoredPrivate(string $bytes, string $name): HubResource
{
    return app(HubResourceStore::class)->store(path: hubResourceFile($bytes), originalName: $name, owner: auth()->guard('admin')->user())['resource'];
}

function hubUploaded(string $bytes, string $name): HubResource
{
    $uuid = hubUpload(bytes: $bytes, name: $name)->assertCreated()->json('resource.id');

    return HubResource::query()->where('uuid', $uuid)->firstOrFail();
}

it('uploads a font shared with Everyone, the upload as its attestation, and answers a repeat of the same bytes with the same one', function () {
    $first = hubUpload(bytes: hubResourceDejaVu(), name: 'DejaVu.ttf')->assertCreated();
    $font = HubResource::query()->where('uuid', $first->json('resource.id'))->firstOrFail();

    expect($first->json('resource'))->toMatchArray(['kind' => 'font', 'format' => 'ttf', 'family' => 'DejaVu Sans', 'visibility' => 'everyone', 'license_note' => 'Shared on upload', 'owned' => true])
        ->and($first->json('created'))->toBeTrue()
        ->and($font->attested_by)->toBe($this->owner->id)
        ->and($font->attested_at)->not->toBeNull();

    // Another person sees it at once.
    $this->actingAs(hubResourceAdmin(), 'admin');
    expect($this->getJson(route('admin.dam.hub.resources.list'))->assertOk()->json('resources.*.id'))->toBe([$font->uuid]);

    // A repeat of bytes the owner already holds keeps the sharing they chose since.
    $this->actingAs($this->owner, 'admin');
    app(HubResourceStore::class)->setVisibility(resource: $font, actor: $this->owner, visibility: HubResource::VISIBILITY_PRIVATE);
    hubUpload(bytes: hubResourceDejaVu(), name: 'again.ttf')->assertOk()
        ->assertJsonPath('created', false)->assertJsonPath('resource.id', $font->uuid)->assertJsonPath('resource.visibility', 'private');
});

it('refuses files the library will not take with a translated 422 and its reason', function () {
    hubUpload(bytes: hubResourceTtc(faces: 1), name: 'Family.ttc')->assertStatus(422)
        ->assertJsonPath('reason', 'collection')->assertJsonPath('message', trans('dam::app.admin.hub-resources.errors.collection'));
    hubUpload(bytes: 'not a font at all', name: 'notes.txt')->assertStatus(422)->assertJsonPath('reason', 'unknown_format');

    config(['dam.hub.resources.font_max_bytes' => 512 * 1024]);
    $tooLarge = hubUpload(bytes: hubResourceDejaVu(), name: 'DejaVu.ttf')->assertStatus(422)->assertJsonPath('reason', 'too_large');

    expect($tooLarge->json('message'))->toBe('The file is 0.6 MiB. The limit is 0.5 MiB.')
        ->and(HubResource::query()->count())->toBe(0)
        ->and(FileObject::query()->count())->toBe(0);
});

it('refuses a file over every resource cap early with the same too_large answer, storing nothing', function () {
    config(['dam.hub.resources.font_max_bytes' => 256 * 1024, 'dam.hub.resources.brush_max_bytes' => 512 * 1024]);

    $refused = hubUpload(bytes: hubResourceDejaVu(), name: 'DejaVu.ttf')->assertStatus(422)
        ->assertJsonPath('reason', 'too_large')
        ->assertJsonMissingPath('errors.file');

    expect($refused->json('message'))->toBe(trans('dam::app.admin.hub-resources.errors.too-large', ['size' => '0.6', 'limit' => '0.5']))
        ->and(HubResource::query()->count())->toBe(0)
        ->and(FileObject::query()->count())->toBe(0)
        ->and(Storage::disk(Directory::getAssetDisk())->allFiles())->toBe([]);
});

it('refuses an upload past the per-person byte quota with a 422 naming the limit', function () {
    expect(config('dam.hub.resources.per_user_max_bytes'))->toBe(5 * 1024 * 1024 * 1024)
        ->and(config('dam.hub.resources.font_max_bytes'))->toBe(20 * 1024 * 1024)
        ->and(config('dam.hub.resources.brush_max_bytes'))->toBe(50 * 1024 * 1024);

    config(['dam.hub.resources.per_user_max_bytes' => strlen(hubResourceDejaVu()) + 10]);
    hubUploaded(bytes: hubResourceDejaVu(), name: 'DejaVu.ttf');

    $refused = hubUpload(bytes: hubResourceAbr(version: 6), name: 'Inks.abr')->assertStatus(422)->assertJsonPath('reason', 'quota_bytes');

    expect($refused->json('message'))->toContain('GiB')
        ->and(HubResource::query()->where('owner_id', $this->owner->id)->count())->toBe(1);
});

it('refuses to share without a license attestation and a license note', function () {
    $font = hubStoredPrivate(bytes: hubResourceDejaVu(), name: 'DejaVu.ttf');
    $patch = fn (array $body) => $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $font->uuid]), $body);

    $patch(['visibility' => 'everyone', 'license_note' => 'OFL'])->assertStatus(422)->assertJsonPath('reason', 'license_required');
    $patch(['visibility' => 'everyone', 'license_attested' => true])->assertStatus(422)->assertJsonPath('reason', 'license_required');
    $patch(['visibility' => 'everyone', 'license_attested' => true, 'license_note' => '   '])->assertStatus(422)->assertJsonPath('reason', 'license_required');
    // A refused share keeps the whole request out, the rename included.
    $patch(['name' => 'Renamed.ttf', 'visibility' => 'everyone', 'license_attested' => false, 'license_note' => 'OFL'])->assertStatus(422);

    expect($font->fresh()->visibility)->toBe('private')
        ->and($font->fresh()->name)->toBe('DejaVu.ttf')
        ->and($font->fresh()->attested_at)->toBeNull();

    $patch(['visibility' => 'everyone', 'license_attested' => true, 'license_note' => 'SIL Open Font License 1.1'])->assertOk()
        ->assertJsonPath('resource.visibility', 'everyone')->assertJsonPath('resource.license_note', 'SIL Open Font License 1.1');

    expect($font->fresh()->attested_by)->toBe($this->owner->id)
        ->and($font->fresh()->attested_at)->not->toBeNull();

    // Editing a shared resource's note is a new share: attested again.
    $patch(['license_note' => 'Apache 2.0'])->assertStatus(422)->assertJsonPath('reason', 'license_required');
    $patch(['visibility' => 'private'])->assertOk()->assertJsonPath('resource.visibility', 'private');
});

it('decides a note change against the row as locked, so a share committed after the request read it keeps its note', function () {
    $font = hubStoredPrivate(bytes: hubResourceDejaVu(), name: 'DejaVu.ttf');
    $shared = false;

    // Another tab of the owner shares the resource right after this request first reads it (still private then).
    Event::listen('eloquent.retrieved: '.HubResource::class, function (HubResource $read) use ($font, &$shared): void {
        if ($shared || $read->id !== $font->id) {
            return;
        }

        $shared = true;
        app(HubResourceStore::class)->setVisibility(resource: $read, actor: $this->owner, visibility: 'everyone', licenseNote: 'SIL Open Font License 1.1', licenseAttested: true);
    });

    $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $font->uuid]), ['license_note' => null])
        ->assertStatus(422)->assertJsonPath('reason', 'license_required');

    expect($shared)->toBeTrue()
        ->and($font->fresh()->visibility)->toBe('everyone')
        ->and($font->fresh()->license_note)->toBe('SIL Open Font License 1.1');
});

it('shares with chosen groups only when every group exists', function () {
    $font = hubStoredPrivate(bytes: hubResourceDejaVu(), name: 'DejaVu.ttf');
    $group = (int) DB::table('dam_groups')->insertGetId(['name' => 'Type team '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(['group_id' => $group, 'admin_id' => $this->owner->id]);
    $share = fn (array $groupIds) => $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $font->uuid]), [
        'visibility' => 'groups', 'group_ids' => $groupIds, 'license_attested' => true, 'license_note' => 'OFL',
    ]);

    $share([$group, 999999])->assertStatus(422)->assertJsonPath('reason', 'bad_visibility');
    $share([])->assertStatus(422)->assertJsonPath('reason', 'bad_visibility');
    $share([$group])->assertOk()->assertJsonPath('resource.visibility', 'groups')->assertJsonPath('resource.group_ids', [$group]);
});

it('releases a deleted person\'s fonts and brushes, keeping a blob someone else still holds', function () {
    $font = hubUploaded(bytes: hubResourceDejaVu(), name: 'DejaVu.ttf');
    $brush = hubUploaded(bytes: hubResourceAbr(version: 6), name: 'Inks.abr');
    $fontObject = FileObject::query()->findOrFail($font->file_object_id);
    $brushObject = FileObject::query()->findOrFail($brush->file_object_id);

    $other = hubResourceAdmin();
    $this->actingAs($other, 'admin');
    $kept = hubUploaded(bytes: hubResourceAbr(version: 6), name: 'Same inks.abr');

    expect(app(AdminRepository::class)->delete($this->owner->id))->toBeTruthy();

    expect(DB::table('admins')->where('id', $this->owner->id)->exists())->toBeFalse()
        ->and(HubResource::query()->where('owner_id', $this->owner->id)->exists())->toBeFalse()
        ->and(FileObject::query()->whereKey($fontObject->id)->exists())->toBeFalse()
        ->and(Storage::disk($fontObject->disk)->exists($fontObject->path))->toBeFalse()
        ->and($kept->fresh()->file_object_id)->toBe($brushObject->id)
        ->and(Storage::disk($brushObject->disk)->exists($brushObject->path))->toBeTrue()
        ->and(FileObject::query()->get()->reject(fn (FileObject $object): bool => $object->isReferenced())->all())->toBe([]);
});

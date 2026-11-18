<?php

require_once __DIR__.'/../../Support/HubResourceFixtures.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\HubResource;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\CreativeHub\HubResourceRefused;
use Mothership\DAM\Services\CreativeHub\HubResourceStore;
use Mothership\User\Models\Admin;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->hubStore = app(HubResourceStore::class);
    $this->owner = hubResourceAdmin();
});

function hubResourceStoreFont(Admin $owner, ?string $bytes = null, string $name = 'DejaVu.ttf'): array
{
    return test()->hubStore->store(path: hubResourceFile($bytes ?? hubResourceDejaVu()), originalName: $name, owner: $owner);
}

function hubResourceGroup(string $name, array $members = []): int
{
    $id = (int) DB::table('dam_groups')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($members as $member) {
        DB::table('dam_group_members')->insert(['group_id' => $id, 'admin_id' => $member->id]);
    }

    return $id;
}

function hubResourceShare(HubResource $resource, Admin $actor, string $visibility, array $groupIds = []): HubResource
{
    return test()->hubStore->setVisibility(resource: $resource, actor: $actor, visibility: $visibility, groupIds: $groupIds, licenseNote: 'SIL Open Font License 1.1', licenseAttested: true);
}

it('stores a font as a private, content-addressed resource of its owner', function () {
    ['resource' => $resource, 'created' => $created] = hubResourceStoreFont($this->owner);
    $object = FileObject::query()->findOrFail($resource->file_object_id);

    expect($created)->toBeTrue()
        ->and($resource->visibility)->toBe(HubResource::VISIBILITY_PRIVATE)
        ->and($resource->owner_id)->toBe($this->owner->id)
        ->and($resource->family)->toBe('DejaVu Sans')
        ->and($object->sha256)->toBe(hash('sha256', hubResourceDejaVu()))
        ->and($object->mime_type)->toBe('font/ttf')
        ->and($object->isReferenced())->toBeTrue()
        ->and(Storage::disk($object->disk)->exists($object->path))->toBeTrue();
});

it('dedupes the same bytes per owner and shares one blob across owners', function () {
    $first = hubResourceStoreFont($this->owner)['resource'];
    $again = hubResourceStoreFont($this->owner, name: 'renamed copy.ttf');
    $other = hubResourceStoreFont(hubResourceAdmin())['resource'];

    expect($again['created'])->toBeFalse()
        ->and($again['resource']->id)->toBe($first->id)
        ->and(HubResource::query()->where('owner_id', $this->owner->id)->count())->toBe(1)
        ->and($other->id)->not->toBe($first->id)
        ->and($other->file_object_id)->toBe($first->file_object_id);
});

it('refuses an upload past the byte quota before writing it', function () {
    config(['dam.hub.resources.per_user_max_bytes' => strlen(hubResourceDejaVu()) + 10]);
    hubResourceStoreFont($this->owner);
    $objects = FileObject::query()->count();

    expect(fn () => hubResourceStoreFont($this->owner, bytes: hubResourceAbr(version: 6), name: 'Inks.abr'))
        ->toThrow(fn (HubResourceRefused $refused) => expect($refused->reason)->toBe(HubResourceRefused::QUOTA_BYTES))
        ->and(FileObject::query()->count())->toBe($objects)
        ->and(hubResourceStoreFont(hubResourceAdmin(), bytes: hubResourceAbr(version: 6), name: 'Inks.abr')['created'])->toBeTrue();
});

it('has no file-count limit and a 5 GiB byte quota by default', function () {
    expect(config('dam.hub.resources.per_user_max_bytes'))->toBe(5368709120)
        ->and(config('dam.hub.resources'))->not->toHaveKey('per_user_max_files');
});

it('lets the owner, Everyone, group members and full admins read, and nobody else', function () {
    $resource = hubResourceStoreFont($this->owner)['resource'];
    $member = hubResourceAdmin();
    $stranger = hubResourceAdmin();
    $fullAdmin = hubResourceAdmin(permissionType: 'all');
    $group = hubResourceGroup('Designers', [$member]);
    $readers = fn (): array => array_map(fn (Admin $admin): bool => $resource->isVisibleTo($admin), [$this->owner, $member, $stranger, $fullAdmin]);

    expect($readers())->toBe([true, false, false, true]);

    hubResourceShare($resource, $this->owner, HubResource::VISIBILITY_GROUPS, [$group]);
    expect($readers())->toBe([true, true, false, true])
        ->and($resource->fresh()->groupIds())->toBe([$group]);

    hubResourceShare($resource, $this->owner, HubResource::VISIBILITY_EVERYONE);
    expect($readers())->toBe([true, true, true, true])
        ->and($resource->fresh()->groupIds())->toBe([])
        ->and(HubResource::query()->visibleTo($stranger)->pluck('id')->all())->toBe([$resource->id])
        ->and($resource->isVisibleTo(hubResourceAdmin(status: 0)))->toBeFalse();
});

it('counts a share with the built-in Everyone group for every active admin', function () {
    $resource = hubResourceStoreFont($this->owner)['resource'];
    $everyone = (int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id');

    hubResourceShare($resource, $this->owner, HubResource::VISIBILITY_GROUPS, [$everyone]);

    expect($resource->isVisibleTo(hubResourceAdmin()))->toBeTrue();
});

it('shares only with a license attestation, recorded with who attested and when', function () {
    $resource = hubResourceStoreFont($this->owner)['resource'];

    expect(fn () => $this->hubStore->setVisibility(resource: $resource, actor: $this->owner, visibility: HubResource::VISIBILITY_EVERYONE, licenseNote: 'OFL', licenseAttested: false))
        ->toThrow(fn (HubResourceRefused $refused) => expect($refused->reason)->toBe(HubResourceRefused::LICENSE_REQUIRED))
        ->and(fn () => $this->hubStore->setVisibility(resource: $resource, actor: $this->owner, visibility: HubResource::VISIBILITY_EVERYONE, licenseNote: '  ', licenseAttested: true))
        ->toThrow(fn (HubResourceRefused $refused) => expect($refused->reason)->toBe(HubResourceRefused::LICENSE_REQUIRED))
        ->and(fn () => hubResourceShare($resource, hubResourceAdmin(), HubResource::VISIBILITY_EVERYONE))
        ->toThrow(fn (HubResourceRefused $refused) => expect($refused->reason)->toBe(HubResourceRefused::NOT_OWNER))
        ->and($resource->fresh()->visibility)->toBe(HubResource::VISIBILITY_PRIVATE);

    $shared = hubResourceShare($resource, $this->owner, HubResource::VISIBILITY_EVERYONE);

    expect($shared->license_note)->toBe('SIL Open Font License 1.1')
        ->and($shared->attested_by)->toBe($this->owner->id)
        ->and($shared->attested_at)->not->toBeNull();
});

it('deletes for the owner and releases the blob only when nothing else holds it', function () {
    $mine = hubResourceStoreFont($this->owner)['resource'];
    $theirs = hubResourceStoreFont(hubResourceAdmin())['resource'];
    $object = FileObject::query()->findOrFail($mine->file_object_id);
    $disk = Storage::disk($object->disk);

    expect(fn () => $this->hubStore->delete(resource: $mine, actor: hubResourceAdmin()))
        ->toThrow(fn (HubResourceRefused $refused) => expect($refused->reason)->toBe(HubResourceRefused::NOT_OWNER));

    $this->hubStore->delete(resource: $mine, actor: $this->owner);
    expect(HubResource::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and($disk->exists($object->path))->toBeTrue();

    $this->hubStore->delete(resource: $theirs, actor: hubResourceAdmin(permissionType: 'all'));
    expect(FileObject::query()->whereKey($object->id)->exists())->toBeFalse()
        ->and($disk->exists($object->path))->toBeFalse()
        ->and(DB::table('dam_file_deletion_tombstones')->where('path', $object->path)->exists())->toBeFalse();
});

it('releases every resource an admin owns before the admin goes', function () {
    hubResourceStoreFont($this->owner);
    hubResourceStoreFont($this->owner, bytes: hubResourceAbr(version: 2), name: 'Old.abr');

    expect($this->hubStore->releaseOwnedBy(ownerId: $this->owner->id))->toBe(2)
        ->and(HubResource::query()->where('owner_id', $this->owner->id)->exists())->toBeFalse()
        ->and(FileObject::query()->count())->toBe(0);
});

/** Every FileObject still names something, and every one of $paths is gone from the asset disk. */
function hubResourceNoLooseBlob(array $paths = []): void
{
    expect(FileObject::query()->get()->reject(fn (FileObject $object): bool => $object->isReferenced())->pluck('id')->all())->toBe([]);
    foreach ($paths as $path) {
        expect(Storage::disk(Directory::getAssetDisk())->exists($path))->toBeFalse("{$path} was left behind");
    }
}

// Review (PR #274): a failure after putLocal() other than a refusal left the FileObject unreferenced with no tombstone.
it('releases the ingested blob when the attach fails, and rethrows the failure', function () {
    $written = null;
    $this->hubStore->beforeAttach(function (FileObject $object) use (&$written): void {
        $written = $object->path;

        throw new RuntimeException('injected attach failure');
    });

    expect(fn () => hubResourceStoreFont($this->owner))->toThrow(RuntimeException::class, 'injected attach failure')
        ->and(HubResource::query()->count())->toBe(0)
        ->and(FileObject::query()->count())->toBe(0);
    hubResourceNoLooseBlob([$written]);
});

it('releases the ingested blob when the owner is gone by the time of the lock', function () {
    $written = null;
    $this->hubStore->beforeAttach(function (FileObject $object, int $ownerId) use (&$written): void {
        $written = $object->path;
        DB::table('admins')->where('id', $ownerId)->delete();
    });

    expect(fn () => hubResourceStoreFont($this->owner))->toThrow(RuntimeException::class, 'no longer exists')
        ->and(FileObject::query()->count())->toBe(0);
    hubResourceNoLooseBlob([$written]);
});

// Review (PR #274): the locked quota check, reached because a competing row takes the last bytes after the
// preliminary check passed. The loser's blob is released.
it('refuses at the locked quota check when a competing upload takes the last bytes, and releases the loser blob', function () {
    $font = strlen(hubResourceDejaVu());
    $brush = strlen(hubResourceAbr(version: 6));
    config(['dam.hub.resources.per_user_max_bytes' => $font + $brush]);
    hubResourceStoreFont($this->owner);
    $loser = null;

    $this->hubStore->beforeAttach(function (FileObject $object, int $ownerId) use (&$loser): void {
        $loser = $object->path;
        $competing = app(ContentStore::class)->putLocal(disk: Directory::getAssetDisk(), localPath: hubResourceFile(hubResourceAbr(version: 2)), mimeType: 'application/octet-stream', extension: 'abr');
        HubResource::query()->create(['owner_id' => $ownerId, 'kind' => 'brush', 'format' => 'abr', 'name' => 'Competing.abr', 'file_object_id' => $competing->id, 'size' => $competing->size]);
    });

    expect(fn () => hubResourceStoreFont($this->owner, bytes: hubResourceAbr(version: 6), name: 'Loser.abr'))
        ->toThrow(fn (HubResourceRefused $refused) => expect($refused->reason)->toBe(HubResourceRefused::QUOTA_BYTES))
        ->and(HubResource::query()->where('owner_id', $this->owner->id)->pluck('name')->sort()->values()->all())->toBe(['Competing.abr', 'DejaVu.ttf'])
        ->and(FileObject::query()->where('sha256', hash('sha256', hubResourceAbr(version: 6)))->exists())->toBeFalse();
    hubResourceNoLooseBlob([$loser]);
});

it('returns the competing row when the same owner stored the same bytes after the preliminary dedupe', function () {
    $this->hubStore->beforeAttach(function (FileObject $object, int $ownerId): void {
        HubResource::query()->create(['owner_id' => $ownerId, 'kind' => 'font', 'format' => 'ttf', 'name' => 'Winner.ttf', 'file_object_id' => $object->id, 'size' => $object->size]);
    });

    ['resource' => $resource, 'created' => $created] = hubResourceStoreFont($this->owner, name: 'Loser.ttf');

    expect($created)->toBeFalse()
        ->and($resource->name)->toBe('Winner.ttf')
        ->and(HubResource::query()->where('owner_id', $this->owner->id)->count())->toBe(1)
        ->and(Storage::disk(Directory::getAssetDisk())->exists(FileObject::query()->findOrFail($resource->file_object_id)->path))->toBeTrue();
    hubResourceNoLooseBlob();
});

it('releases the ingested blob when the insert inside the attach transaction throws', function () {
    Event::listen('eloquent.creating: '.HubResource::class, fn () => throw new RuntimeException('injected insert failure'));

    expect(fn () => hubResourceStoreFont($this->owner))->toThrow(RuntimeException::class, 'injected insert failure')
        ->and(FileObject::query()->count())->toBe(0);
    hubResourceNoLooseBlob([app(ContentStore::class)->objectPath(hash('sha256', hubResourceDejaVu()), 'ttf')]);
});

// Re-review (PR #274): the seam is inert in production: it cannot be set, and a hook set under tests never runs.
it('refuses to activate the beforeAttach seam outside unit tests, and never runs a set hook there', function () {
    $ran = false;
    $this->hubStore->beforeAttach(function () use (&$ran): void {
        $ran = true;
    });
    $environment = app()['env'];
    app()['env'] = 'production';

    try {
        expect(app()->runningUnitTests())->toBeFalse()
            ->and(fn () => $this->hubStore->beforeAttach(fn () => null))->toThrow(LogicException::class)
            ->and(hubResourceStoreFont($this->owner)['created'])->toBeTrue()
            ->and($ran)->toBeFalse();
    } finally {
        app()['env'] = $environment;
    }
});

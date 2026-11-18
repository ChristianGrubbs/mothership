<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Http\Middleware\ManageFolderAccess;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The folder access panel (grants model only) is full-admin-only through ManageFolderAccess, and full
 * admins see every folder. Its show/update still answer a folder the actor cannot see exactly like a
 * missing id, so widening that ACL later cannot turn the panel into a folder-id oracle or let a role
 * rewrite grants on a folder outside its tree.
 */

function folderAccessParityGrantsModel(): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

beforeEach(function () {
    folderAccessParityGrantsModel();
    config(['app.debug' => false]);

    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);

    // parent: in the member's tree as the granted folder's ancestor, but not openable. hidden: not in it at all.
    $this->parent = Directory::factory()->create(['name' => 'Access parity parent', 'parent_id' => $root->id]);
    $this->granted = Directory::factory()->create(['name' => 'Access parity granted', 'parent_id' => $this->parent->id]);
    $this->hidden = Directory::factory()->create(['name' => 'Access parity hidden', 'parent_id' => $root->id]);
    $this->missingId = Directory::max('id') + 1000;

    $this->member = Admin::factory()->create(['role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->unique()->values()->all(),
    ])->id]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->granted->id, 'role_id' => $this->member->role_id]);

    $this->url = fn (int $id): string => route('admin.dam.directory.access.show', $id);
    $this->grantBody = ['users' => [['id' => $this->member->id, 'inherit_children' => true]], 'groups' => []];
});

it('answers a hidden folder like a missing one even if the route ACL is widened', function () {
    $this->withoutMiddleware(ManageFolderAccess::class)->actingAs($this->member, 'admin');

    $permissions = app(DirectoryPermissionService::class);
    $permissions->flush();
    expect($permissions->roleBypass())->toBeFalse()
        ->and($permissions->canView($this->hidden->id))->toBeFalse()
        ->and($permissions->canView($this->parent->id))->toBeTrue()
        ->and($permissions->canAccess($this->parent->id))->toBeFalse();

    $requests = [
        'show'           => fn (int $id) => $this->getJson(($this->url)($id)),
        'update'         => fn (int $id) => $this->putJson(($this->url)($id), $this->grantBody),
        // The folder check runs before validation, so a bad body cannot tell the two apart either.
        'update.invalid' => fn (int $id) => $this->putJson(($this->url)($id), ['users' => 'probe']),
    ];

    foreach ($requests as $name => $send) {
        $hidden = $send($this->hidden->id);
        $missing = $send($this->missingId);

        expect($hidden->getStatusCode())->toBe(404, $name)
            ->and($missing->getStatusCode())->toBe(404, $name)
            ->and($hidden->getContent())->toBe($missing->getContent(), $name);
    }

    expect(DB::table('dam_directory_user')->where('directory_id', $this->hidden->id)->exists())->toBeFalse();

    // Visible is enough: managing a folder's grants is not opening it, so the in-tree ancestor still answers.
    $this->getJson(($this->url)($this->parent->id))->assertOk()->assertJsonPath('directory.id', $this->parent->id);
    $this->getJson(($this->url)($this->granted->id))->assertOk()->assertJsonPath('directory.id', $this->granted->id);
    $this->putJson(($this->url)($this->parent->id), $this->grantBody)->assertOk();
    expect(DB::table('dam_directory_user')->where('directory_id', $this->parent->id)->where('admin_id', $this->member->id)->exists())->toBeTrue();
});

it('refuses a role below full admin identically for a hidden and a missing folder', function () {
    $this->actingAs($this->member, 'admin');

    foreach (['show' => 'getJson', 'update' => 'putJson'] as $name => $method) {
        $hidden = $this->{$method}(($this->url)($this->hidden->id), $this->grantBody);
        $missing = $this->{$method}(($this->url)($this->missingId), $this->grantBody);

        expect($hidden->getStatusCode())->toBe(403, $name)
            ->and($hidden->getContent())->toBe($missing->getContent(), $name);
    }

    expect(DB::table('dam_directory_user')->where('directory_id', $this->hidden->id)->exists())->toBeFalse();
});

it('still serves every existing folder to a full admin and 404s a missing one', function () {
    $this->actingAs(Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]), 'admin');

    $this->getJson(($this->url)($this->hidden->id))->assertOk()->assertJsonPath('directory.id', $this->hidden->id);
    $this->putJson(($this->url)($this->hidden->id), $this->grantBody)->assertOk();
    expect(DB::table('dam_directory_user')->where('directory_id', $this->hidden->id)->where('admin_id', $this->member->id)->exists())->toBeTrue();

    $this->getJson(($this->url)($this->missingId))->assertNotFound();
    $this->putJson(($this->url)($this->missingId), $this->grantBody)->assertNotFound();
});

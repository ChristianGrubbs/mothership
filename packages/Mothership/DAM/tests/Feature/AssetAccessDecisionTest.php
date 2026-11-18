<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessDecision;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * P2 S2: one seam decides whether a viewer may see or use an asset. Every helper that used to decide on its own
 * now asks it, so these cases pin the seam's answers; the route tests (AssetHiddenEqualsMissingTest and the
 * controller suites) pin that the helpers still answer as before.
 */

function seamUseModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

function seamMember(): Admin
{
    return Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id]);
}

function seamStatus(callable $call): int
{
    try {
        $call();
    } catch (HttpException $exception) {
        return $exception->getStatusCode();
    }

    return 200;
}

beforeEach(function () {
    $this->seam = app(AssetAccessDecision::class);
    $this->folder = Directory::factory()->create();
    $this->visible = Asset::factory()->create();
    $this->hidden = Asset::factory()->create();
    $this->folder->assets()->attach([$this->visible->id, $this->hidden->id]);
});

it('reports missing, hidden and allowed under lists, and lists exactly what it shows', function () {
    $member = seamMember();
    seamUseModel(AccessModel::LISTS);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->visible->id, 'admin_id' => $member->id]);
    $this->actingAs($member, 'admin');
    $context = AccessContext::library();
    $answer = fn (AccessDecision $d): array => [$d->visible, $d->allowed, $d->reason];

    expect($answer($this->seam->for($context, Asset::max('id') + 1000)))->toBe([false, false, AccessDecision::REASON_MISSING])
        ->and($answer($this->seam->for($context, $this->hidden->id)))->toBe([false, false, AccessDecision::REASON_HIDDEN])
        ->and($answer($this->seam->for($context, $this->visible)))->toBe([true, true, null])
        // `lists` asks nothing more of a change: the file's own list decides (ADR 0010).
        ->and($this->seam->for(AccessContext::library(AccessContext::PURPOSE_EDIT), $this->visible)->allowed)->toBeTrue()
        ->and($this->seam->visible($context, $this->hidden->id))->toBeFalse()
        ->and(seamStatus(fn () => $this->seam->for($context, $this->hidden)->visibleOrFail()))->toBe(404)
        ->and(seamStatus(fn () => $this->seam->for($context, Asset::max('id') + 1000)->visibleOrFail()))->toBe(404);

    $query = Asset::query()->whereIn('dam_assets.id', [$this->visible->id, $this->hidden->id]);
    $this->seam->scopeListable($query, $context);
    expect($query->pluck('dam_assets.id')->all())->toBe([$this->visible->id]);
});

it('lets any granted folder show an asset under grants, but only its first folder allow a change', function () {
    $member = seamMember();
    seamUseModel(AccessModel::GRANTS);
    // First filed in a folder the member's role is not granted; a second folder is.
    $second = Directory::factory()->create();
    $this->hidden->directories()->attach($second->id);
    DB::table('dam_directory_role')->insert(['directory_id' => $second->id, 'role_id' => $member->role_id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');

    $preview = $this->seam->for(AccessContext::library(), $this->hidden);
    $edit = $this->seam->for(AccessContext::library(AccessContext::PURPOSE_EDIT), $this->hidden);

    expect([$preview->visible, $preview->allowed])->toBe([true, true])
        ->and([$edit->visible, $edit->allowed, $edit->reason])->toBe([true, false, AccessDecision::REASON_FORBIDDEN])
        ->and(seamStatus(fn () => $edit->allowedOrFail()))->toBe(403)
        ->and($this->seam->for(AccessContext::library(), $this->visible)->reason)->toBe(AccessDecision::REASON_HIDDEN);
});

it('lets a role that sees everything change anything, under either model', function () {
    $this->loginAsAdmin();

    foreach ([AccessModel::GRANTS, AccessModel::LISTS] as $model) {
        seamUseModel($model);

        expect($this->seam->for(AccessContext::library(AccessContext::PURPOSE_EDIT), $this->hidden)->allowed)->toBeTrue($model)
            // A missing id passes the bare rule, so the caller's own lookup answers 404 as it always has.
            ->and($this->seam->visible(AccessContext::library(), Asset::max('id') + 1000))->toBeTrue($model);
    }
});

it('answers for a named admin, and shows a disabled one nothing', function () {
    $member = seamMember();
    seamUseModel(AccessModel::LISTS);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->visible->id, 'admin_id' => $member->id]);
    // The session belongs to someone else: the named admin's own access decides.
    $this->loginAsAdmin();

    expect($this->seam->visible(AccessContext::forAdmin($member), $this->visible->id))->toBeTrue()
        ->and($this->seam->visible(AccessContext::forAdmin($member), $this->hidden->id))->toBeFalse()
        ->and(DirectoryPermissionService::adminCanAccessAsset($member->id, $this->visible->id))->toBeTrue();

    $member->update(['status' => 0]);
    $query = Asset::query();
    $this->seam->scopeListable($query, AccessContext::forAdmin($member->fresh()));

    expect($this->seam->visible(AccessContext::forAdmin($member->fresh()), $this->visible->id))->toBeFalse()
        ->and(DirectoryPermissionService::adminCanAccessAsset($member->id, $this->visible->id))->toBeFalse()
        ->and(DirectoryPermissionService::adminCanAccessAsset(Admin::max('id') + 1000, $this->visible->id))->toBeFalse()
        ->and($query->count())->toBe(0);
});

it('lists under grants exactly what it shows: any granted folder, never an unfiled asset, on any id column and for a named admin', function () {
    $member = seamMember();
    seamUseModel(AccessModel::GRANTS);
    // `hidden` is first filed in an ungranted folder and also in a granted one; `visible` only in the ungranted one.
    $second = Directory::factory()->create();
    $this->hidden->directories()->attach($second->id);
    $unfiled = Asset::factory()->create();
    DB::table('dam_directory_role')->insert(['directory_id' => $second->id, 'role_id' => $member->role_id]);
    app(DirectoryPermissionService::class)->flush();
    $ids = [$this->visible->id, $this->hidden->id, $unfiled->id];

    $listed = function (AccessContext $context) use ($ids): array {
        $assets = Asset::query()->whereIn('dam_assets.id', $ids);
        $this->seam->scopeListable($assets, $context);

        // The same rule over a query that is not dam_assets itself, through an aliased id column.
        $pivot = DB::table('dam_asset_directory as filed')->whereIn('filed.asset_id', $ids);
        $this->seam->scopeListable($pivot, $context, 'filed.asset_id');

        return [$assets->orderBy('dam_assets.id')->pluck('dam_assets.id')->all(), $pivot->distinct()->orderBy('filed.asset_id')->pluck('filed.asset_id')->all()];
    };
    $shown = fn (AccessContext $context): array => array_values(array_filter($ids, fn (int $id) => $this->seam->visible($context, $id)));

    $this->actingAs($member, 'admin');
    expect($listed(AccessContext::library()))->toBe([[$this->hidden->id], [$this->hidden->id]])
        ->and($shown(AccessContext::library()))->toBe([$this->hidden->id]);

    // Asked for the member while someone else is signed in, and again once the member is disabled.
    $this->loginAsAdmin();
    expect($listed(AccessContext::forAdmin($member)))->toBe([[$this->hidden->id], [$this->hidden->id]])
        ->and($shown(AccessContext::forAdmin($member)))->toBe([$this->hidden->id]);

    $member->update(['status' => 0]);
    expect($listed(AccessContext::forAdmin($member->fresh())))->toBe([[], []])
        ->and($shown(AccessContext::forAdmin($member->fresh())))->toBe([]);
});

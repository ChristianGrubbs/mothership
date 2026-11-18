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

/*
 * P2 S3: an asset that is not out for use (draft, pending review, archived, or outside its window) is seen only by
 * its owner, editors (the asset-update permission) and full admins. Everyone else gets what a missing asset gets.
 */

/** A member holding every ACL key except the one that makes an editor. */
function availabilityMember(bool $editor = false): Admin
{
    $keys = collect(config('acl'))->pluck('key')->unique()->reject(fn ($key) => ! $editor && $key === 'dam.asset.update');

    return Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => $keys->values()->all(),
    ])->id]);
}

function availabilityAccessModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

beforeEach(function () {
    availabilityAccessModel(AccessModel::GRANTS);
    $this->folder = Directory::factory()->create();
    $this->member = availabilityMember();
    $this->editor = availabilityMember(editor: true);

    foreach ([$this->member, $this->editor] as $admin) {
        DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $admin->role_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->filed = function (array $attributes = []): Asset {
        $asset = Asset::factory()->create($attributes);
        $this->folder->assets()->attach($asset->id);

        return $asset->refresh();
    };
});

/** What the seam says, and what a listing scoped by the seam holds, for one viewer and one asset. */
function availabilitySeenBy(Admin $viewer, Asset $asset, bool $includeUnavailable = true): array
{
    $context = AccessContext::forAdmin($viewer, AccessContext::PURPOSE_LIST);
    $context = $includeUnavailable ? $context : $context->withoutUnavailable();
    $seam = app(AssetAccessDecision::class);
    $listed = Asset::query()->whereKey($asset->id)->tap(fn ($q) => $seam->scopeListable($q->getQuery(), $context))->exists();
    $aliased = DB::table('dam_asset_directory as filed')->where('filed.asset_id', $asset->id)
        ->tap(fn ($q) => $seam->scopeListable($q, $context, 'filed.asset_id'))->exists();

    return [
        'visible' => $seam->visible($context, $asset->id),
        'reason'  => $seam->for($context, $asset->id)->reason,
        'listed'  => $listed,
        'aliased' => $aliased,
    ];
}

it('hides a draft, pending, archived, not-yet or expired asset from a member, as a missing one, in reads and listings', function () {
    $cases = [
        'draft'     => [['availability' => Asset::AVAILABILITY_DRAFT], AccessDecision::REASON_UNAVAILABLE],
        'pending'   => [['availability' => Asset::AVAILABILITY_PENDING_REVIEW], AccessDecision::REASON_UNAVAILABLE],
        'archived'  => [['availability' => Asset::AVAILABILITY_ARCHIVED], AccessDecision::REASON_UNAVAILABLE],
        'not yet'   => [['available_from' => now()->addDay()], AccessDecision::REASON_UNAVAILABLE],
        'expired'   => [['available_until' => now()->subMinute()], AccessDecision::REASON_EXPIRED],
    ];

    foreach ($cases as $name => [$attributes, $reason]) {
        $asset = ($this->filed)($attributes);

        expect(availabilitySeenBy($this->member, $asset))->toBe(['visible' => false, 'reason' => $reason, 'listed' => false, 'aliased' => false], $name);
    }

    $open = ($this->filed)(['available_from' => now()->subDay(), 'available_until' => now()->addDay()]);
    expect(availabilitySeenBy($this->member, $open))->toBe(['visible' => true, 'reason' => null, 'listed' => true, 'aliased' => true]);
});

it('shows an unavailable asset to its owner, editors and full admins, unless a listing leaves them all out', function () {
    $owned = ($this->filed)(['availability' => Asset::AVAILABILITY_DRAFT, 'created_by' => $this->member->id]);
    $draft = ($this->filed)(['availability' => Asset::AVAILABILITY_DRAFT]);
    $fullAdmin = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => []])->id]);
    $seen = ['visible' => true, 'reason' => null, 'listed' => true, 'aliased' => true];

    expect(availabilitySeenBy($this->member, $owned))->toBe($seen)
        ->and(availabilitySeenBy($this->editor, $draft))->toBe($seen)
        ->and(availabilitySeenBy($fullAdmin, $draft))->toBe($seen)
        ->and(availabilitySeenBy($this->member, $draft)['visible'])->toBeFalse();

    // "Include unavailable" off: the listing is what every viewer it is shared with sees; reads still open.
    foreach ([[$this->member, $owned], [$this->editor, $draft], [$fullAdmin, $draft]] as [$viewer, $asset]) {
        expect(availabilitySeenBy($viewer, $asset, includeUnavailable: false))->toMatchArray(['visible' => true, 'listed' => false, 'aliased' => false]);
    }
});

it('answers a member\'s request for a draft asset exactly as for a missing one, and opens it once available', function () {
    $draft = ($this->filed)(['availability' => Asset::AVAILABILITY_DRAFT]);
    $this->actingAs($this->member, 'admin');
    config(['app.debug' => false]);

    $hidden = $this->getJson(route('admin.dam.assets.show', $draft->id));
    $missing = $this->getJson(route('admin.dam.assets.show', $draft->id + 1000));

    expect($hidden->getStatusCode())->toBe(404)
        ->and($hidden->getContent())->toBe($missing->assertNotFound()->getContent());

    $draft->update(['availability' => Asset::AVAILABILITY_AVAILABLE]);
    $this->getJson(route('admin.dam.assets.show', $draft->id))->assertOk();
});

it('lets an editor set the state and window from the panel, records it in History, and refuses a member or a bad window', function () {
    config(['app.debug' => false]);
    $asset = ($this->filed)();
    $url = route('admin.dam.assets.availability.update', $asset->id);
    $window = ['availability' => Asset::AVAILABILITY_ARCHIVED, 'available_from' => '2026-01-01T09:00:00Z', 'available_until' => '2026-02-01T09:00:00Z'];

    $this->actingAs($this->member, 'admin')->putJson($url, $window)->assertForbidden();

    $this->actingAs($this->editor, 'admin');
    $this->putJson($url, ['availability' => Asset::AVAILABILITY_DRAFT, 'available_from' => '2026-03-01T00:00:00Z', 'available_until' => '2026-02-01T00:00:00Z'])
        ->assertUnprocessable()->assertJsonValidationErrors('available_until');
    $this->putJson($url, ['availability' => 'published', 'available_from' => null, 'available_until' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('availability');

    $this->putJson($url, $window)->assertOk()->assertJsonPath('availability.availability', Asset::AVAILABILITY_ARCHIVED)
        ->assertJsonPath('availability.unavailable', Asset::AVAILABILITY_ARCHIVED);

    expect($asset->refresh()->availability)->toBe(Asset::AVAILABILITY_ARCHIVED)
        ->and($asset->available_until->toIso8601String())->toBe('2026-02-01T09:00:00+00:00')
        ->and(DB::table('audits')->where('auditable_type', $asset->getMorphClass())->where('auditable_id', $asset->id)
            ->where('new_values', 'like', '%archived%')->exists())->toBeTrue();

    // A hidden asset answers like a missing one, before any edit right is asked.
    $hidden = Asset::factory()->create();
    $hiddenResponse = $this->putJson(route('admin.dam.assets.availability.update', $hidden->id), $window);
    $missingResponse = $this->putJson(route('admin.dam.assets.availability.update', $hidden->id + 1000), $window);
    expect($hiddenResponse->getStatusCode())->toBe(404)->and($hiddenResponse->getContent())->toBe($missingResponse->getContent());
});

it('refuses a window end outside 1970 to 2099 with a field error, never a 500 (UX eval G3-05)', function () {
    $asset = ($this->filed)();
    $url = route('admin.dam.assets.availability.update', $asset->id);
    $this->actingAs($this->editor, 'admin');

    // The date input's 12/31/9999 11:59 PM, sent as UTC, overflows into year 10000.
    foreach (['+010000-01-01T05:59:00.000Z', '0001-01-01T00:00:00.000Z'] as $end) {
        $this->putJson($url, ['availability' => Asset::AVAILABILITY_AVAILABLE, 'available_from' => null, 'available_until' => $end])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['available_until' => trans('dam::app.admin.dam.asset.availability.window-range')]);
    }

    expect($asset->refresh()->available_until)->toBeNull();

    $this->putJson($url, ['availability' => Asset::AVAILABILITY_AVAILABLE, 'available_from' => null, 'available_until' => '2099-06-01T00:00:00Z'])->assertOk();
});

it('reads and writes availability through the REST asset update', function () {
    $headers = $this->getAuthenticationHeaders();
    $asset = Asset::factory()->create();

    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.update', $asset->id), ['availability' => Asset::AVAILABILITY_PENDING_REVIEW])
        ->assertOk()->assertJsonPath('data.availability', Asset::AVAILABILITY_PENDING_REVIEW);
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.update', $asset->id), ['available_from' => '2026-05-01T00:00:00Z', 'available_until' => '2026-04-01T00:00:00Z'])
        ->assertUnprocessable();
    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.show', $asset->id))
        ->assertOk()->assertJsonPath('data.asset.availability', Asset::AVAILABILITY_PENDING_REVIEW);
});

it('badges an unavailable file in the Library for an editor, leaves it out for a member, and leaves it out for the editor on request', function () {
    $shown = ($this->filed)();
    $draft = ($this->filed)(['availability' => Asset::AVAILABILITY_DRAFT]);
    $listed = fn (array $query = []) => collect($this->getJson(route('admin.dam.explorer.index', ['directory_id' => $this->folder->id] + $query))->assertOk()->json('assets'))
        ->keyBy('id');

    $this->actingAs($this->editor, 'admin');
    $editorView = $listed();
    expect($editorView->keys()->sort()->values()->all())->toBe([$shown->id, $draft->id])
        ->and($editorView[$draft->id]['unavailable'])->toBe(Asset::AVAILABILITY_DRAFT)
        ->and($editorView[$draft->id]['unavailable_label'])->toBe(trans('dam::app.admin.dam.asset.availability.badges.draft'))
        ->and($editorView[$shown->id]['unavailable'])->toBeNull()
        ->and($listed(['include_unavailable' => 0])->keys()->all())->toBe([$shown->id]);

    $this->actingAs($this->member, 'admin');
    expect($listed()->keys()->all())->toBe([$shown->id]);
});

it('shows the owner the badge and the panel, a member nothing on an available file, and an editor the panel', function () {
    $draft = ($this->filed)(['availability' => Asset::AVAILABILITY_DRAFT, 'created_by' => $this->member->id]);
    $available = ($this->filed)();
    // The badge sits by the title on every tab; the panel is on the Properties tab (`?properties`).
    $properties = fn (Asset $asset) => route('admin.dam.assets.edit', $asset->id).'?properties';

    $this->actingAs($this->member, 'admin')->get(route('admin.dam.assets.edit', $draft->id))->assertOk()
        ->assertSee('data-dam-availability-badge="draft"', false);
    $this->get($properties($draft))->assertOk()->assertSee('data-dam-availability-panel', false);
    $this->get($properties($available))->assertOk()
        ->assertDontSee('data-dam-availability-panel', false);

    $this->actingAs($this->editor, 'admin')->get($properties($available))->assertOk()
        ->assertSee('data-dam-availability-panel', false);
});

it('stores a window sent as a UTC instant at that instant when the app runs in another zone', function () {
    $zone = date_default_timezone_get();
    config(['app.timezone' => 'Asia/Kolkata']);
    date_default_timezone_set('Asia/Kolkata');

    try {
        $asset = ($this->filed)();
        $this->actingAs($this->editor, 'admin')->putJson(route('admin.dam.assets.availability.update', $asset->id), [
            'availability' => Asset::AVAILABILITY_AVAILABLE, 'available_from' => '2026-12-01T09:00:00.000Z', 'available_until' => null,
        ])->assertOk()->assertJsonPath('availability.available_from', '2026-12-01T14:30:00+05:30');

        expect($asset->refresh()->available_from->utc()->toIso8601String())->toBe('2026-12-01T09:00:00+00:00');
    } finally {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }
});

it('badges an unavailable file in the default Library gallery and leaves it out on request', function () {
    $shown = ($this->filed)();
    $draft = ($this->filed)(['availability' => Asset::AVAILABILITY_DRAFT]);
    $records = fn (array $filters = []) => collect($this->getJson(route('admin.dam.assets.index', ['filters' => ['directory_id' => [$this->folder->id]] + $filters]), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->json('records'))->keyBy('id');

    $this->actingAs($this->editor, 'admin');
    $editorView = $records();
    expect($editorView->keys()->sort()->values()->all())->toBe([$shown->id, $draft->id])
        ->and($editorView[$draft->id]['unavailable_label'])->toBe(trans('dam::app.admin.dam.asset.availability.badges.draft'))
        ->and($editorView[$shown->id]['unavailable'])->toBeNull()
        ->and($records(['unavailable' => ['exclude']])->keys()->all())->toBe([$shown->id]);

    $this->actingAs($this->member, 'admin');
    expect($records()->keys()->all())->toBe([$shown->id]);
});

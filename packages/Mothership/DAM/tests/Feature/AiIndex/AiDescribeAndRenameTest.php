<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mothership\DAM\AiIndex\AiNameSuggester;
use Mothership\DAM\DataGrids\Asset\AssetDataGrid;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    config(['ai-index.api' => 'http://ai.test']);
    Cache::flush();
    $this->asset = Asset::factory()->create([
        'file_name' => 'IMG_0042.JPG',
        // Camera wall-clock time; nothing answers NamingContext, so the name carries no place.
        'meta_data' => ['DateTimeOriginal' => '2026:09:14 12:30:05'],
    ]);
    DB::table('dam_ai_index')->insert(['asset_id' => $this->asset->id, 'status' => 'done', 'caption' => 'Students gather around a welcome tent outside.', 'keywords' => json_encode(['tent']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_ai_tags')->insert([
        ['asset_id' => $this->asset->id, 'term' => 'campus-event', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()],
        ['asset_id' => $this->asset->id, 'term' => 'photo', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function fakeNamingServices(): void
{
    Http::fake([
        'ai.test/name' => Http::response(['subject' => 'Welcome Week Campus Tour', 'place' => '', 'title' => 'Campus Tour at Welcome Week', 'event' => 'Welcome Week Bash']),
    ]);
}

it('accepts every open AI suggestion at once and hands back the file tags', function () {
    $this->loginAsAdmin();

    $response = $this->postJson(route('admin.dam.assets.ai-tags.accept-all', $this->asset->id))->assertOk();

    expect($response->json('done'))->toBe(2)
        ->and(collect($response->json('tags'))->pluck('name')->sort()->values()->all())->toBe(['Campus event', 'Photo'])
        ->and($response->json('ai.tags'))->toBe([])
        ->and(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->pluck('status')->unique()->all())->toBe(['accepted']);
});

it('keeps accept-all and describe behind the tagging permission', function () {
    $viewer = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id]);
    $this->actingAs($viewer, 'admin');

    $this->postJson(route('admin.dam.assets.ai-tags.accept-all', $this->asset->id))->assertStatus(403);
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $this->asset->id))->assertStatus(403);

    $this->loginAsAdmin();
    $this->getJson(route('admin.dam.assets.ai-tags.show', $this->asset->id))->assertOk()
        ->assertJsonPath('can_review', true)->assertJsonPath('available', true)->assertJsonPath('ai.caption', 'Students gather around a welcome tent outside.');
});

it('queues a description for a file that has none, starting a failed one over', function () {
    Queue::fake();
    $this->loginAsAdmin();
    $fresh = Asset::factory()->create(['file_name' => 'DSC_1001.JPG']);
    DB::table('dam_ai_index')->insert(['asset_id' => $fresh->id, 'status' => 'failed', 'attempts' => 4, 'error' => 'AiBridgeUnavailable', 'created_at' => now(), 'updated_at' => now()]);

    $this->postJson(route('admin.dam.assets.ai-tags.describe', $fresh->id))->assertOk()->assertJsonPath('ai.status', 'pending');

    Queue::assertPushed(AiIndexAsset::class, fn ($job) => $job->assetId === $fresh->id);
    expect(DB::table('dam_ai_index')->where('asset_id', $fresh->id)->value('attempts'))->toBe(0);

    config(['ai-index.api' => null]);
    $this->postJson(route('admin.dam.assets.ai-tags.describe', $fresh->id))->assertStatus(503);
});

it('proposes a name from the capture time and the AI subject, with no place when nothing answers NamingContext', function () {
    fakeNamingServices();
    $this->loginAsAdmin();

    $response = $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->asset->id])->assertOk();

    expect($response->json('stem'))->toBe('2026-09-14_welcome-week-campus-tour')
        ->and($response->json('date_source'))->toBe('exif')
        ->and($response->json('building'))->toBe('')
        ->and($response->json('campus'))->toBe('')
        ->and($response->json('event'))->toBe('Welcome Week Bash');

    // The bridge is still asked with the context's shape, so an implementation bound over NullNamingContext fills it.
    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'http://ai.test/name') {
            return false;
        }

        return $request['events'] === [] && $request['campus'] === '' && $request['building'] === ''
            && $request['captured_at'] === '2026-09-14 12:30';
    });
});

it('asks for a description first and keeps rename suggestions behind the rename permission', function () {
    fakeNamingServices();
    $this->loginAsAdmin();
    $bare = Asset::factory()->create(['file_name' => 'GOPR0007.MP4']);

    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $bare->id])->assertStatus(409)->assertJsonPath('needs_description', true);

    $viewer = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id]);
    $this->actingAs($viewer, 'admin');
    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->asset->id])->assertStatus(403);
});

it('offers the legacy Library grid Rename with AI only with the rename right and a configured bridge', function () {
    $actions = function () {
        $grid = app(AssetDataGrid::class);
        $grid->prepareMassActions();

        return collect($grid->getMassActions())->pluck('options.actionType')->all();
    };

    $this->loginAsAdmin();
    expect($actions())->toContain('ai-rename');

    config(['ai-index.api' => '']);
    expect($actions())->not->toContain('ai-rename');

    config(['ai-index.api' => 'http://ai.test']);
    $viewer = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.tag']])->id]);
    $this->actingAs($viewer, 'admin');
    expect($actions())->not->toContain('ai-rename');
});

it('answers a hidden file exactly like a missing one on every new route, without touching it', function () {
    Http::fake();
    Queue::fake();
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
    $folder = Directory::factory()->create();
    $visible = Asset::factory()->create();
    $folder->assets()->attach([$visible->id, $this->asset->id]);
    $member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.tag', 'dam.asset.rename']])->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $visible->id, 'admin_id' => $member->id]);
    $this->actingAs($member, 'admin');

    config(['app.debug' => false]);
    $answers = fn (int $id): array => array_map(fn ($response) => [$response->status(), $response->json()], [
        $this->getJson(route('admin.dam.assets.ai-tags.show', $id)),
        $this->postJson(route('admin.dam.assets.ai-tags.describe', $id)),
        $this->postJson(route('admin.dam.assets.ai-tags.accept-all', $id)),
        $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $id]),
    ]);
    $hidden = $answers($this->asset->id);

    // Same status and same body: nothing tells a file the member may not see from one that does not exist.
    expect(array_column($hidden, 0))->toBe([404, 404, 404, 404])
        ->and($hidden)->toBe($answers(Asset::max('id') + 1000));

    $this->getJson(route('admin.dam.assets.ai-tags.show', $visible->id))->assertOk();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(DB::table('dam_ai_tags')->where('asset_id', $this->asset->id)->pluck('status')->unique()->all())->toBe(['suggested'])
        ->and(DB::table('dam_ai_index')->where('asset_id', $this->asset->id)->value('status'))->toBe('done');
});

it('tells the rename dialog an unchanged name from a taken one', function () {
    $this->loginAsAdmin();
    Asset::factory()->create(['file_name' => 'TAKEN.JPG', 'path' => dirname((string) $this->asset->path).'/TAKEN.JPG']);

    $this->postJson(route('admin.dam.assets.rename'), ['id' => $this->asset->id, 'file_name' => 'IMG_0042.JPG'])
        ->assertOk()->assertJsonPath('code', 'unchanged');
    $this->postJson(route('admin.dam.assets.rename'), ['id' => $this->asset->id, 'file_name' => 'TAKEN.JPG'])
        ->assertNotFound()->assertJsonPath('code', 'name_taken');
});

it('falls back to the upload date when the camera time is out of range', function () {
    fakeNamingServices();
    $this->loginAsAdmin();
    $this->asset->update(['meta_data' => ['DateTimeOriginal' => '2026:09:14 99:00:00']]);

    $this->postJson(route('admin.dam.assets.ai-rename.suggest'), ['id' => $this->asset->id])->assertOk()
        ->assertJsonPath('date_source', 'uploaded');
});

it('slugs to lowercase ascii words cut at a word boundary', function () {
    expect(AiNameSuggester::slug(text: 'Café  Night: Esports Arena!', maxLength: 60))->toBe('cafe-night-esports-arena')
        ->and(AiNameSuggester::slug(text: 'nursing pinning ceremony graduates', maxLength: 20))->toBe('nursing-pinning');
});

/** An admin whose role holds exactly these rights and may reach the asset's folder. */
function detailsPageAdmin(array $permissions, Asset ...$assets): Admin
{
    $directory = Directory::factory()->create();
    foreach ($assets as $asset) {
        $asset->directories()->attach($directory);
    }

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => array_merge(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit'], $permissions)]);
    DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    app(DirectoryPermissionService::class)->flush();

    return Admin::factory()->create(['role_id' => $role->id]);
}

it('mounts the Rename with AI modal and button on the details page only with the rename right and a configured bridge', function () {
    $this->loginAsAdmin();
    $edit = route('admin.dam.assets.edit', $this->asset->id);

    $this->get($edit)->assertOk()
        ->assertSee('<v-dam-ai-rename-modal>', false)
        ->assertSee('data-dam-rename-with-ai', false)
        ->assertSee('canRenameWithAi', false);

    config(['ai-index.api' => '']);
    $this->get($edit)->assertOk()
        ->assertDontSee('<v-dam-ai-rename-modal>', false)
        ->assertDontSee('data-dam-rename-with-ai', false);

    config(['ai-index.api' => 'http://ai.test']);
    $this->actingAs(detailsPageAdmin([], $this->asset), 'admin');
    $this->get($edit)->assertOk()
        ->assertDontSee('<v-dam-ai-rename-modal>', false)
        ->assertDontSee('data-dam-rename-with-ai', false);
});

it('offers Rename with AI on the details page to a role that may rename, and leaves what the AI can describe to the dialog', function () {
    $bare = Asset::factory()->create(['file_name' => 'GOPR0007.MP4']);
    $this->actingAs(detailsPageAdmin(['dam.asset.rename'], $this->asset, $bare), 'admin');

    // The dialog is mounted either way (the page can move to another file) and asks the server about the file on screen.
    foreach ([$this->asset, $bare] as $asset) {
        $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()
            ->assertSee('<v-dam-ai-rename-modal>', false)
            ->assertSee('data-dam-rename-with-ai', false)
            ->assertDontSee('hasDescription', false);
    }
});

it('renders the stack fan\'s Open details as a button, not a placeholder link', function () {
    $this->loginAsAdmin();
    config()->set('dam.explorer.enabled', true);

    $html = $this->get(route('admin.dam.assets.index'))->assertOk()->getContent();

    expect($html)->toMatch('/<button\s[^>]*data-dam-stack-fan-details/s')
        ->and($html)->not->toMatch('/<a\s[^>]*href="#"[^>]*data-dam-stack-fan-details/s');
});

it('tells a role that cannot list a stack\'s files so, and drops the dead Open Files tab link', function () {
    $edit = route('admin.dam.assets.edit', $this->asset->id);
    $this->actingAs(detailsPageAdmin(['dam.asset.files'], $this->asset), 'admin');

    $page = $this->get($edit)->assertOk()->assertSee('data-dam-strip-no-access', false);
    expect(substr_count($page->getContent(), 'preview-modal.open-files'))->toBe(0);
    $this->get($edit.'?files')->assertOk()->assertSee('data-dam-files-no-access', false);

    $this->loginAsAdmin();
    $this->get($edit.'?files')->assertOk()->assertDontSee('data-dam-files-no-access', false);
});

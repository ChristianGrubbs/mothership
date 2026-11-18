<?php

// The Plaque Maker's server side (docs/features/plaque-maker.md): saved sets follow the Library's access, and AI
// drafts come from the server-side Magic AI platform with the tool's own system prompt.

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\MagicAI\Models\MagicAIPlatform;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    MagicAIPlatform::query()->delete();
    config(['stylepress.title_model' => null]);
});

function plaqueAccount(array $permissions, ?int $directoryId = null): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    if ($directoryId !== null) {
        DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $directoryId]);
    }

    return Admin::factory()->create(['role_id' => $role->id]);
}

it('lists only plaque sets the account may see in the Library', function () {
    $allowed = Directory::factory()->create();
    $private = Directory::factory()->create();
    [$mine, $hidden, $other] = Asset::withoutEvents(fn () => [
        Asset::factory()->create(['file_name' => 'Tips.plaque.json', 'extension' => 'json']),
        Asset::factory()->create(['file_name' => 'Secret.plaque.json', 'extension' => 'json']),
        Asset::factory()->create(['file_name' => 'notes.json', 'extension' => 'json']),
    ]);
    $mine->directories()->attach($allowed->id);
    $hidden->directories()->attach($private->id);
    $other->directories()->attach($allowed->id);

    $this->actingAs(plaqueAccount(['dam', 'dam.assets'], $allowed->id), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->getJson(route('admin.home.plaque.sets'))->assertOk()
        ->assertJsonCount(1, 'sets')
        ->assertJsonPath('sets.0.id', $mine->id)
        ->assertJsonPath('sets.0.download', route('admin.dam.assets.download', $mine->id));
});

it('keeps the Library and AI from an account without the Library, and says so without an error', function () {
    MagicAIPlatform::create(['label' => 'OpenAI', 'provider' => 'openai', 'api_url' => null, 'api_key' => 'sk-test', 'models' => 'gpt-5.2', 'is_default' => true, 'status' => true]);
    $this->actingAs(plaqueAccount(['dashboard']), 'admin');

    $this->getJson(route('admin.home.plaque.context'))->assertOk()->assertExactJson(['library' => false, 'ai' => false]);
    $this->getJson(route('admin.home.plaque.sets'))->assertForbidden();
    $this->postJson(route('admin.home.plaque.generate'), ['description' => 'Weekly podcast'])->assertForbidden();
});

it('drafts a set with the server-side platform and its own system prompt', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->getJson(route('admin.home.plaque.context'))->assertOk()->assertExactJson(['library' => true, 'ai' => false]);
    $this->postJson(route('admin.home.plaque.generate'), ['description' => 'Weekly podcast'])->assertStatus(503);

    MagicAIPlatform::create(['label' => 'OpenAI', 'provider' => 'openai', 'api_url' => null, 'api_key' => 'sk-test', 'models' => 'gpt-5.2', 'is_default' => true, 'status' => true]);
    Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => '{"name":"Pod","plaques":[]}']]]])]);

    $this->getJson(route('admin.home.plaque.context'))->assertOk()->assertJsonPath('ai', true);
    $this->postJson(route('admin.home.plaque.generate'), ['description' => 'Weekly podcast', 'donation' => 2, 'tone' => 'warm'])
        ->assertOk()->assertJsonPath('content', '{"name":"Pod","plaques":[]}');
    Http::assertSent(function (ClientRequest $request) {
        $messages = $request['messages'];

        return $request['model'] === 'gpt-5.2'
            && str_starts_with($messages[0]['content'], 'You write sets of animated video overlay plaques.')
            && str_contains($messages[1]['content'], 'Weekly podcast') && str_contains($messages[1]['content'], '2 donation plaques');
    });
    $this->postJson(route('admin.home.plaque.generate'), ['description' => str_repeat('x', 2001)])->assertUnprocessable();
});
